<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\JourneyPlan;
use App\Models\User;
use App\Models\Visit;
use App\Models\Zone;
use App\Services\Coverage;
use App\Services\RoutePool;
use App\Support\Access;
use App\Support\Csv;
use App\Support\DateRange;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * ✨ محفظة العملاء الحاليين (١١/١١/٢٠٢٦ — «زي المحتملين بالظبط»)
 * ═══════════════════════════════════════════════════════════════
 *
 * نفس `LeadController::index` كارت بكارت: ملخّص بيفلتر القايمة ← فلاتر
 * ← خريطة بشيبس المناطق ← توزيعة المناطق («وزّع N من المنطقة دي») ←
 * قايمة بتسكين جماعي ← تصدير الكل. الفرق إن «التسكين» هنا = المندوب
 * الأساسي للعميل (`clients.rep_id`) بنفس حراس شاشة التسكين.
 */
class RoutePortfolioController extends Controller
{
    /** «متأخر» = مااتزارش من أكتر من كده (نفس شيب البورد) */
    public const LATE_DAYS = 14;

    public const FOCUSES = ['active', 'planned', 'late', 'assigned', 'owes', 'new'];

    /** عملاء الفيوور + كل فلاتر الشاشة — مصدر واحد للقايمة والكروت والتسكين الجماعي */
    private function filteredQuery(Request $request, ?User $viewer)
    {
        $planned = fn () => JourneyPlan::where('active', true)->select('client_id');
        $recent = fn () => Visit::where('checked_in_at', '>=', now()->subDays(self::LATE_DAYS))->select('client_id');

        $q = Branch::scope(Client::visibleTo(Client::query(), $viewer));

        if ($request->filled('search')) {
            Client::search($q, (string) $request->input('search'));
        }

        return $q
            ->when($request->filled('status'), fn ($x) => $x->where('clients.status', $request->input('status')))
            ->when($request->filled('zone'), fn ($x) => $x->where('clients.zone_id', $request->integer('zone')))
            ->when($request->filled('rep'), fn ($x) => $x->where('clients.rep_id', $request->integer('rep')))
            ->when($request->filled('channel'), fn ($x) => $x->where('clients.channel_id', $request->integer('channel')))
            ->when($request->filled('chain'), fn ($x) => $x->where('clients.group_id', $request->integer('chain')))
            ->when($request->boolean('unassigned'), fn ($x) => $x->whereNull('clients.rep_id'))
            // نفس تعريف كل كارت بالحرف — عدد الصفوف = رقم الكارت
            ->when($request->input('focus') === 'active', fn ($x) => $x->where('clients.status', 'active'))
            ->when($request->input('focus') === 'planned', fn ($x) => $x->whereIn('clients.id', $planned()))
            ->when($request->input('focus') === 'late', fn ($x) => $x->where('clients.status', 'active')
                ->whereNotIn('clients.id', $recent()))
            ->when($request->input('focus') === 'assigned', fn ($x) => $x->whereNotNull('clients.rep_id'))
            ->when($request->input('focus') === 'owes', fn ($x) => $x->where('clients.balance', '>', 0))
            ->when($request->input('focus') === 'new', fn ($x) => $x->where('clients.created_at', '>=', now()->startOfMonth()))
            ->tap(fn ($x) => DateRange::fromRequest($request)->apply($x, 'clients.created_at'));
    }

    public function index(Request $request)
    {
        $viewer = $request->user();
        $q = $this->filteredQuery($request, $viewer)->with(['zone', 'channel', 'group', 'rep', 'manager']);

        $planned = JourneyPlan::where('active', true)->select('client_id');
        $recent = Visit::where('checked_in_at', '>=', now()->subDays(self::LATE_DAYS))->select('client_id');

        // ⚠️ العدّادات قبل الترتيب (ONLY_FULL_GROUP_BY — نفس فخ المحتملين)
        $counts = (clone $q)->reorder()->selectRaw('clients.status, COUNT(*) n')->groupBy('clients.status')
            ->pluck('n', 'status');
        $stats = [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts['active'] ?? 0),
            'parts' => $counts->map(fn ($n) => (int) $n)->all(),
            'planned' => (clone $q)->reorder()->whereIn('clients.id', $planned)->count(),
            'late' => (clone $q)->reorder()->where('clients.status', 'active')->whereNotIn('clients.id', $recent)->count(),
            'new' => (clone $q)->reorder()->where('clients.created_at', '>=', now()->startOfMonth())->count(),
            'owes_n' => (clone $q)->reorder()->where('clients.balance', '>', 0)->count(),
            'balance' => (float) (clone $q)->reorder()->sum('clients.balance'),
            'assigned' => (clone $q)->reorder()->whereNotNull('clients.rep_id')->count(),
        ];

        $sort = in_array($request->input('sort'), ['balance', 'recent'], true) ? $request->input('sort') : 'name';
        $q->when($sort === 'name', fn ($x) => $x->orderBy('clients.name')->orderBy('clients.id'))
            ->when($sort === 'balance', fn ($x) => $x->orderByDesc('clients.balance')->orderBy('clients.id'))
            ->when($sort === 'recent', fn ($x) => $x->orderByDesc('clients.id'));

        // ═══ تصدير كل النتيجة المفلترة — نفس الكويري ونفس الترتيب ═══
        if ($request->boolean('export')) {
            $rows = (clone $q)->get();
            $last = RoutePool::lastVisits($rows->pluck('id'));
            $days = $this->planDays($rows->pluck('id'));
            $range = DateRange::fromRequest($request);

            return Csv::download('route-portfolio-'.now()->format('Y-m-d-Hi').'.csv', [
                __('common.code'), __('journey.pf_last_visit'), __('common.name'), __('common.phone'), __('client.zone'),
                __('journey.pf_f_chain'), __('journey.pf_f_channel'), __('journey.pf_rep'), __('journey.pf_balance'),
                __('journey.pf_plan_days'), __('common.status'), __('common.date'),
            ], $rows->map(fn (Client $c) => [
                $c->code, $this->daysLabel($last->get($c->id)), $c->fullName(), $c->phone ?? '',
                $c->zone?->displayName() ?? '', $c->group?->displayName() ?? '', $c->channel?->displayName() ?? '',
                $c->rep?->displayName() ?? '', Csv::money($c->balance), $days[$c->id] ?? '',
                __('journey.pf_status_'.$c->status), $c->created_at?->format('Y-m-d') ?? '',
            ]), [
                __('common.total'), '', $rows->count(), '', '', '', '', '', Csv::money($rows->sum('balance')), '', '', '',
            ], Csv::meta(__('journey.pf_portfolio_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        // ═══ خريطة المحفظة — من نفس الكويري المفلترة، سقف 1500 ═══
        $mapRows = (clone $q)->reorder()->whereNotNull('clients.lat')->whereNotNull('clients.lng')
            ->orderByDesc('clients.balance')->limit(1500)
            ->get(['clients.id', 'clients.name', 'clients.name_en', 'clients.group_id', 'clients.lat', 'clients.lng',
                'clients.zone_id', 'clients.rep_id', 'clients.balance', 'clients.status']);
        $mapLast = RoutePool::lastVisits($mapRows->pluck('id'));
        $mapPoints = $mapRows->map(fn ($c) => [
            'name' => $c->fullName(),
            'lat' => (float) $c->lat,
            'lng' => (float) $c->lng,
            'zone' => $c->zone_id,
            'rep' => $c->rep_id !== null,
            'st' => $c->status !== 'active' ? 'off' : $this->recency($mapLast->get($c->id)),
            'days' => $this->daysLabel($mapLast->get($c->id)),
            'bal' => (float) $c->balance,
        ])->values();

        // ═══ توزيعة المناطق — «وزّع N من المنطقة دي» ═══
        $zoneRows = (clone $q)->reorder()->selectRaw("
                clients.zone_id,
                COUNT(*) as total,
                COALESCE(SUM(clients.status = 'active'), 0) as active_n,
                COALESCE(SUM(clients.rep_id IS NULL AND clients.status = 'active'), 0) as unassigned,
                COALESCE(SUM(clients.id NOT IN (SELECT client_id FROM journey_plans WHERE active = 1)), 0) as unplanned
            ")->groupBy('clients.zone_id')->orderByDesc('total')->get();

        // ⚠️ من غير join — سكوب المدير (`visibleTo`) بيكتب `manager_id` من غير
        // اسم الجدول، والـjoin مع users بيخلّيه ambiguous (1052) للمدير بس
        $zr = (clone $q)->reorder()->whereNotNull('clients.rep_id')
            ->selectRaw('clients.zone_id, clients.rep_id, COUNT(*) as n')
            ->groupBy('clients.zone_id', 'clients.rep_id')->get();
        $repNames = User::whereIn('id', $zr->pluck('rep_id')->unique()->all())->get()
            ->mapWithKeys(fn ($u) => [$u->id => $u->displayName()]);
        $zoneReps = $zr->map(fn ($r) => (object) [
            'zone_id' => $r->zone_id, 'rep_name' => $repNames[$r->rep_id] ?? '—', 'n' => (int) $r->n,
        ])->groupBy('zone_id');

        $chains = (clone $q)->reorder()->whereNotNull('clients.group_id')
            ->selectRaw('clients.group_id, COUNT(*) as n')->groupBy('clients.group_id')->orderByDesc('n')
            ->pluck('n', 'group_id');

        $per = in_array((int) $request->input('per'), [30, 60, 100], true)
            ? (int) $request->input('per')
            : ($request->input('per') === 'all' ? 'all' : 30);
        $pageSize = $per === 'all' ? max(1, min($stats['total'], 2000)) : $per;

        $page = $q->paginate($pageSize)->withQueryString();
        $ids = collect($page->items())->pluck('id');

        return view('ops.route_portfolio', [
            'clients' => $page,
            'per' => $per,
            'mapPoints' => $mapPoints,
            'zoneRows' => $zoneRows,
            'zoneReps' => $zoneReps,
            'chainCounts' => $chains,
            'chains' => ClientGroup::whereIn('id', $chains->keys()->all())->get()->sortByDesc(fn ($g) => $chains[$g->id] ?? 0)->values(),
            'stats' => $stats,
            'lastVisit' => RoutePool::lastVisits($ids),
            'planDays' => $this->planDays($ids),
            'zones' => Zone::orderBy('code')->get(),
            'channels' => Channel::orderBy('name')->get(),
            'reps' => RoutePool::reps($viewer)->whereIn('role', ['sales_agent', 'driver'])->values(),
            'statuses' => array_keys($stats['parts']) ?: ['active'],
            'sort' => $sort,
            'filters' => $request->only(['status', 'zone', 'rep', 'channel', 'chain', 'search', 'sort', 'unassigned', 'per', 'from', 'to', 'focus']),
            'range' => DateRange::fromRequest($request),
            'canAssign' => Access::action($viewer, 'act.field.plan'),
        ]);
    }

    /**
     * ═══ «وزّع N من المنطقة دي» — زي `LeadController::bulkAssign` ═══
     * أول N عميل نشط من غير مندوب في المنطقة (بأعلى مديونية) ← المندوب.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            'target' => ['required', 'string', 'regex:/^r-\d+$/'],
            'count' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $viewer = $request->user();
        $rep = User::with('zones')->findOrFail((int) substr($data['target'], 2));
        Scope::assertRep($viewer, $rep);

        $clients = Branch::scope(Client::visibleTo(Client::query(), $viewer))
            ->where('status', 'active')->whereNull('rep_id')->where('zone_id', $data['zone_id'])
            ->orderByDesc('balance')->orderBy('id')
            ->limit((int) $data['count'])->get();

        [$done, $skipped] = $this->assignTo($rep, $clients);

        return back()->with('ok', __('journey.pf_assigned', ['n' => $done, 'rep' => $rep->displayName(), 'skipped' => $skipped]));
    }

    /** تسكين المحدد (أو كل نتايج الفلتر) على مندوب — زي `LeadController::bulkSet` */
    public function bulkSet(Request $request)
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'regex:/^r-\d+$/'],
            'ids' => ['nullable', 'array', 'max:2000'],
            'ids.*' => ['integer'],
            'all_filtered' => ['nullable'],
        ]);

        $viewer = $request->user();
        $rep = User::with('zones')->findOrFail((int) substr($data['target'], 2));
        Scope::assertRep($viewer, $rep);

        $clients = $request->boolean('all_filtered')
            ? $this->filteredQuery($request, $viewer)->limit(2000)->get()
            : Branch::scope(Client::visibleTo(Client::query(), $viewer))
                ->whereIn('id', $data['ids'] ?? [])->get();

        abort_if($clients->isEmpty(), 422, __('journey.pf_none_selected'));

        [$done, $skipped] = $this->assignTo($rep, $clients);

        return back()->with('ok', __('journey.pf_assigned', ['n' => $done, 'rep' => $rep->displayName(), 'skipped' => $skipped]));
    }

    /**
     * المندوب الأساسي لمجموعة عملاء — بحراس شاشة التسكين: نفس الفريق
     * وجوه زون المندوب، والعميل اللي مايعدّيش بيتعدّ «اتتخطى» مش بيوقّع الكل.
     *
     * @return array{0:int,1:int}
     */
    private function assignTo(User $rep, $clients): array
    {
        $done = 0;
        $skipped = 0;

        DB::transaction(function () use ($rep, $clients, &$done, &$skipped) {
            foreach ($clients as $c) {
                if (! Scope::sameTeam($rep, $c) || ! Scope::inZone($rep, $c)) {
                    $skipped++;

                    continue;
                }

                if ((int) $c->rep_id !== (int) $rep->id) {
                    $c->update(['rep_id' => $rep->id]);
                    Coverage::sync($c->fresh());
                }
                $done++;
            }
        });

        return [$done, $skipped];
    }

    /** أيام خط السير لكل عميل: «الأحد · الخميس (كل أسبوعين)» */
    private function planDays($ids): array
    {
        if (collect($ids)->isEmpty()) {
            return [];
        }

        return JourneyPlan::where('active', true)->whereIn('client_id', collect($ids)->all())
            ->orderBy('weekday')->get(['client_id', 'weekday', 'every_weeks'])
            ->groupBy('client_id')
            ->map(fn ($g) => $g->map(fn ($p) => __('journey.day_'.$p->weekday)
                .($p->every_weeks > 1 ? ' ('.__('journey.freq_'.$p->every_weeks).')' : ''))->implode(' · '))
            ->all();
    }

    /** لون النقطة بحداثة آخر زيارة */
    private function recency($t): string
    {
        if ($t === null) {
            return 'never';
        }

        $d = (int) \Illuminate\Support\Carbon::parse($t)->diffInDays(now());

        return $d <= 7 ? 'week' : ($d <= self::LATE_DAYS ? 'fortnight' : 'late');
    }

    private function daysLabel($t): string
    {
        return $t === null ? '—' : (string) (int) \Illuminate\Support\Carbon::parse($t)->diffInDays(now());
    }
}
