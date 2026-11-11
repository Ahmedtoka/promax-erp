<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\JourneyPlan;
use App\Models\MerchVisit;
use App\Models\User;
use App\Models\Visit;
use App\Models\Zone;
use App\Services\Coverage;
use App\Services\RoutePool;
use App\Support\Access;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * راسم خط السير + متابعة الأسبوع للعملاء الحقيقيين (١١/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «الفلو بتاع المحتملين … عاوز انقله هو هو للعملاء
 * الحقيقيين». نفس شاشات `LeadController::routePlanner/week` بالظبط —
 * بس التخزين فضل `journey_plans` (نمط أسبوعي بتردد) عشان الأبلكيشن
 * واللايف ويوم المندوب والـKPIs كلهم بيقروه من `Journeys::forDay`.
 * السبيك: docs/superpowers/specs/2026-11-11-client-route-planner-design.md
 */
class RoutePlannerController extends Controller
{
    /** أقصى نقط على الخريطة — زي المحتملين بس أوسع شوية */
    public const MAP_CAP = 1500;

    /** أقصى محطات في يوم واحد من الراسم */
    public const DAY_CAP = 150;

    // ═══════════════════════ ١. راسم خط السير ═══════════════════════

    public function builder(Request $request)
    {
        $viewer = $request->user();
        $reps = RoutePool::reps($viewer);
        $repIds = $reps->pluck('id');

        $f = [
            'search' => trim((string) $request->input('search', '')),
            'zone' => $request->integer('zone') ?: null,
            'gov' => (string) $request->input('gov', ''),
            'channel' => $request->integer('channel') ?: null,
            'chain' => $request->integer('chain') ?: null,
            'owner' => $request->integer('owner') ?: null,
            'only' => (string) $request->input('only', ''),
        ];

        $base = fn () => Branch::scope(Client::visibleTo(Client::query(), $viewer))
            ->where('status', 'active');

        // كل خطط مناديب الشاشة — الراسم بيحمّل يوم أي مندوب في الجافاسكربت
        $plans = $repIds->isEmpty() ? collect() : JourneyPlan::query()
            ->whereIn('user_id', $repIds->all())
            ->where('active', true)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'user_id', 'client_id', 'weekday', 'every_weeks', 'starts_on', 'visit_at', 'sort']);

        // خطط كل العملاء المرئيين (حتى بتوع فرق تانية) — عشان 🔒 والتولتيب
        $q = $base()->with(['zone', 'group', 'channel', 'rep:id,name,name_en']);

        if ($f['search'] !== '') {
            Client::search($q, $f['search']);
        }
        if ($f['zone']) {
            $q->where('zone_id', $f['zone']);
        }
        if ($f['gov'] !== '') {
            $q->where('governorate', $f['gov']);
        }
        if ($f['channel']) {
            $q->where('channel_id', $f['channel']);
        }
        if ($f['chain']) {
            $q->where('group_id', $f['chain']);
        }
        if ($f['owner']) {
            $q->where('rep_id', $f['owner']);
        }
        if ($f['only'] === 'unplanned') {
            $q->whereNotIn('id', JourneyPlan::where('active', true)->select('client_id'));
        }
        if ($f['only'] === 'late') {
            $q->whereNotIn('id', Visit::where('checked_in_at', '>=', now()->subDays(14))->select('client_id'));
        }

        $clients = $q->orderBy('name')->limit(self::MAP_CAP)->get();

        // ⚠️ عملاء خطط المناديب لازم يبقوا موجودين حتى لو الفلتر شالهم —
        // وإلا تحميل يوم مندوب بيطلع ناقص
        $missing = $plans->pluck('client_id')->unique()->diff($clients->pluck('id'));
        if ($missing->isNotEmpty()) {
            $clients = $clients->concat(
                $base()->with(['zone', 'group', 'channel', 'rep:id,name,name_en'])
                    ->whereIn('id', $missing->all())->get()
                    ->each(fn ($c) => $c->setAttribute('_extra', true))
            );
        }

        $allPlans = JourneyPlan::with('user:id,name,name_en')
            ->whereIn('client_id', $clients->pluck('id')->all())
            ->where('active', true)
            ->get(['id', 'user_id', 'client_id', 'weekday', 'every_weeks']);

        $last = RoutePool::lastVisits($clients->pluck('id'));

        $rows = $clients->map(function (Client $c) use ($last, $allPlans) {
            $r = RoutePool::row($c, $last);
            $r['rep'] = $c->rep?->displayName();
            $r['extra'] = (bool) $c->getAttribute('_extra');
            $r['plans'] = $allPlans->where('client_id', $c->id)->map(fn ($p) => [
                'u' => (int) $p->user_id,
                'd' => (int) $p->weekday,
                'f' => (int) $p->every_weeks,
                'n' => $p->user?->displayName(),
            ])->values()->all();

            return $r;
        })->values();

        $dayMap = [];
        foreach ($plans as $p) {
            $dayMap[$p->user_id][$p->weekday][] = (int) $p->client_id;
        }

        // ═══ كروت الأعلى (من الشاشة الجغرافية القديمة) ═══
        $kpi = [
            'clients' => $base()->count(),
            'unplanned' => $base()->whereNotIn('id', JourneyPlan::where('active', true)->select('client_id'))->count(),
            'norep' => $base()->whereNull('rep_id')->count(),
            'noloc' => $base()->where(fn ($w) => $w->whereNull('lat')->orWhereNull('lng'))->count(),
        ];

        $zoneCounts = $base()->whereNotNull('zone_id')
            ->selectRaw('zone_id, COUNT(*) n')->groupBy('zone_id')->pluck('n', 'zone_id');
        $zones = Zone::whereIn('id', $zoneCounts->keys()->all())->get()
            ->sortByDesc(fn ($z) => $zoneCounts[$z->id] ?? 0)->values();
        $govs = $base()->whereNotNull('governorate')->where('governorate', '!=', '')
            ->selectRaw('governorate, COUNT(*) n')->groupBy('governorate')->orderByDesc('n')
            ->pluck('n', 'governorate');
        $channelCounts = $base()->whereNotNull('channel_id')
            ->selectRaw('channel_id, COUNT(*) n')->groupBy('channel_id')->pluck('n', 'channel_id');
        $channels = Channel::whereIn('id', $channelCounts->keys()->all())->get()
            ->sortByDesc(fn ($c) => $channelCounts[$c->id] ?? 0)->values();
        $chainCounts = $base()->whereNotNull('group_id')
            ->selectRaw('group_id, COUNT(*) n')->groupBy('group_id')->pluck('n', 'group_id');
        $chains = ClientGroup::whereIn('id', $chainCounts->keys()->all())->orderBy('name')->get();
        $owners = User::whereIn('id', $base()->whereNotNull('rep_id')->distinct()->pluck('rep_id')->all())
            ->orderBy('name')->get();

        return view('ops.route_builder', [
            'reps' => $reps,
            'rows' => $rows,
            'dayMap' => $dayMap,
            'kpi' => $kpi,
            'filters' => $f,
            'zones' => $zones, 'zoneCounts' => $zoneCounts,
            'govs' => $govs,
            'channels' => $channels, 'channelCounts' => $channelCounts,
            'chains' => $chains, 'chainCounts' => $chainCounts,
            'owners' => $owners,
            'capped' => $clients->count() >= self::MAP_CAP,
            'canPlan' => Access::action($viewer, 'act.field.plan'),
            'selRep' => $request->integer('rep') ?: null,
            'selDay' => $request->filled('day') ? (int) $request->input('day') : null,
        ]);
    }

    /**
     * ═══ حفظ يوم من الراسم ═══
     *
     * اليوم (مندوب × يوم أسبوع) بيبقى **بالظبط** التسلسل اللي اتبعت:
     * الموجود بيتحدّث ترتيبه (وبيحتفظ بتردده ووقته)، الجديد بياخد تردد
     * البار، واللي اتشال من التسلسل بيتمسح. عميل على نفس اليوم مع مندوب
     * تاني **المستخدم متحكم فيه** بيتنقل، وعميل فريق تاني بيتتخطى
     * **وبيتسمّى** في الرسالة — مفيش تخطي ساكت (ثغرة المحتملين).
     */
    public function builderSave(Request $request)
    {
        $data = $request->validate([
            'rep_id' => ['required', 'integer', 'exists:users,id'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'every_weeks' => ['required', 'integer', 'in:1,2,4'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'visit_at' => ['nullable', 'date_format:H:i'],
            'ids' => ['nullable', 'array', 'max:'.self::DAY_CAP],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $viewer = $request->user();
        $rep = User::with('zones')->findOrFail($data['rep_id']);

        Scope::assertRep($viewer, $rep);

        $weekday = (int) $data['weekday'];
        $startsOn = $data['starts_on'] ?? null;

        // ⚠️ أول زيارة بتحدد اليوم — نفس قاعدة الشاشة الجغرافية القديمة
        if ($startsOn) {
            $weekday = Carbon::createFromFormat('Y-m-d', $startsOn)->dayOfWeek;
        }

        $ids = collect($data['ids'] ?? [])->map(fn ($v) => (int) $v)->values();
        $clients = Client::whereIn('id', $ids->all())->get()->keyBy('id');

        $skipped = [];
        $keep = collect();

        foreach ($ids as $id) {
            $c = $clients->get($id);

            if ($c === null || ! Scope::canClient($viewer, $c)
                || ! Scope::sameTeam($rep, $c) || ! Scope::inZone($rep, $c)) {
                $skipped[] = $c?->fullName() ?? '#'.$id;

                continue;
            }

            $keep->push($id);
        }

        // خطط تانية على نفس اليوم للعملاء دول (مندوب تاني)
        $others = JourneyPlan::with('user')
            ->whereIn('client_id', $keep->all())
            ->where('weekday', $weekday)
            ->where('user_id', '!=', $rep->id)
            ->get()
            ->groupBy('client_id');

        $moveIds = [];

        foreach ($keep->all() as $i => $id) {
            $theirs = $others->get($id, collect());

            if ($theirs->contains(fn ($p) => ! Scope::canRep($viewer, $p->user))) {
                $skipped[] = $clients[$id]->fullName();
                $keep->forget($i);

                continue;
            }

            foreach ($theirs as $p) {
                $moveIds[] = $p->id;
            }
        }

        $keep = $keep->values();
        $stats = ['saved' => $keep->count(), 'added' => 0, 'moved' => 0, 'removed' => 0];

        DB::transaction(function () use ($rep, $weekday, $keep, $moveIds, $data, $startsOn, &$stats) {
            $stats['moved'] = $moveIds ? (int) JourneyPlan::whereIn('id', $moveIds)->delete() : 0;

            $stats['removed'] = (int) JourneyPlan::where('user_id', $rep->id)
                ->where('weekday', $weekday)
                ->where('active', true)
                ->whereNotIn('client_id', $keep->all() ?: [0])
                ->delete();

            $existing = JourneyPlan::where('user_id', $rep->id)
                ->where('weekday', $weekday)->get()->keyBy('client_id');

            foreach ($keep as $i => $id) {
                $plan = $existing->get($id);

                if ($plan !== null) {
                    $plan->update(['sort' => $i + 1, 'active' => true]);

                    continue;
                }

                JourneyPlan::create([
                    'user_id' => $rep->id,
                    'client_id' => $id,
                    'weekday' => $weekday,
                    'every_weeks' => (int) $data['every_weeks'],
                    'starts_on' => $startsOn,
                    'visit_at' => $data['visit_at'] ?? null,
                    'sort' => $i + 1,
                    'active' => true,
                ]);
                $stats['added']++;
            }

            // ⚠️ المندوب الأساسي بيتحط **بس لو العميل من غيره** (قرار §10.3)
            if (in_array($rep->role, ['sales_agent', 'driver'], true) && $keep->isNotEmpty()) {
                $orphans = Client::whereIn('id', $keep->all())->whereNull('rep_id')->get();

                foreach ($orphans as $c) {
                    $c->update(['rep_id' => $rep->id]);
                    Coverage::sync($c->fresh());
                }
            }
        });

        $msg = __('journey.rb_saved', [
            'n' => $stats['saved'],
            'rep' => $rep->displayName(),
            'day' => __('journey.day_'.$weekday),
            'added' => $stats['added'],
            'moved' => $stats['moved'],
            'removed' => $stats['removed'],
        ]);

        $back = redirect()->route('ops.geo', array_filter([
            'rep' => $rep->id,
            'day' => $weekday,
        ] + $request->only(['search', 'zone', 'gov', 'channel', 'chain', 'owner', 'only']),
            fn ($v) => $v !== null && $v !== ''))->with('ok', $msg);

        return $skipped
            ? $back->with('warn', __('journey.rb_skipped', [
                'n' => count($skipped),
                'names' => implode('، ', array_slice($skipped, 0, 12)).(count($skipped) > 12 ? ' …' : ''),
            ]))
            : $back;
    }

    // ═══════════════════════ ٢. متابعة الأسبوع ═══════════════════════

    public function week(Request $request)
    {
        $viewer = $request->user();
        $reps = RoutePool::reps($viewer);

        try {
            $start = $request->filled('week')
                ? Carbon::parse((string) $request->input('week'))->startOfWeek(Carbon::SATURDAY)
                : today()->startOfWeek(Carbon::SATURDAY);
        } catch (\Throwable) {
            $start = today()->startOfWeek(Carbon::SATURDAY);
        }

        $days = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i));
        $only = $request->integer('rep') ?: null;
        $shown = $only ? $reps->where('id', $only)->values() : $reps;

        $cards = self::follow($shown, $days)
            ->filter(fn ($c) => $c['planned'] > 0 || $c['off'] > 0)
            ->sortByDesc('missed')->values();

        return view('ops.journeys_week', [
            'reps' => $reps,
            'cards' => $cards,
            'days' => $days,
            'start' => $start,
            'only' => $only,
        ]);
    }

    /**
     * متابعة أسبوع لمجموعة مناديب — كويريات مجمّعة (مش كويري لكل يوم).
     *
     * «اتزار» = زيارة **مقفولة** للمندوب المخطط نفسه للعميل ده في نفس
     * اليوم (المنسق: `merch_visits`). المفتوحة = «في الزيارة». النهارده
     * اللي ماخلصش = لسه، مش فات (قرار §10.10).
     *
     * @return Collection<int, array>
     */
    public static function follow(Collection $reps, Collection $days): Collection
    {
        if ($reps->isEmpty()) {
            return collect();
        }

        $ids = $reps->pluck('id')->all();
        $from = $days->first()->copy()->startOfDay();
        $to = $days->last()->copy()->endOfDay();

        $plans = JourneyPlan::with('client:id,code,name,name_en,group_id', 'client.group')
            ->whereIn('user_id', $ids)->where('active', true)
            ->orderBy('sort')->orderBy('id')->get()->groupBy('user_id');

        $visits = Visit::whereIn('user_id', $ids)->whereBetween('created_at', [$from, $to])
            ->orderBy('id')->get(['id', 'user_id', 'client_id', 'created_at', 'checked_out_at']);

        $merch = MerchVisit::whereIn('user_id', $ids)->whereBetween('checked_in_at', [$from, $to])
            ->orderBy('id')->get();

        $today = today()->toDateString();

        return $reps->map(function (User $rep) use ($plans, $visits, $merch, $days, $today) {
            $mine = $plans->get($rep->id, collect());
            $isPromoter = $rep->role === 'promoter';

            // آخر زيارة لكل (يوم × عميل)
            $vis = [];
            foreach ($visits->where('user_id', $rep->id) as $v) {
                $vis[$v->created_at->toDateString()][$v->client_id] = $v->checked_out_at === null ? 'in_visit' : 'done';
            }
            if ($isPromoter) {
                foreach ($merch->where('user_id', $rep->id) as $m) {
                    $vis[Carbon::parse($m->checked_in_at)->toDateString()][$m->client_id] = $m->isOpen() ? 'in_visit' : 'done';
                }
            }

            $grid = [];
            $t = ['planned' => 0, 'done' => 0, 'missed' => 0, 'off' => 0];

            foreach ($days as $d) {
                $key = $d->toDateString();
                // ⚠️ خطة اتعملت النهارده ماتتحسبش «فاتت» على أيام قبل ما تتعمل
                $due = $mine->filter(fn (JourneyPlan $p) => $p->dueOn($d->copy())
                    && ($p->created_at === null || $p->created_at->copy()->startOfDay()->lte($d)));
                $dueIds = $due->pluck('client_id')->all();

                $items = $due->map(function (JourneyPlan $p) use ($vis, $key, $today) {
                    $st = $vis[$key][$p->client_id] ?? null;
                    $state = $st ?? ($key < $today ? 'missed' : 'pending');

                    return [
                        'cid' => $p->client_id,
                        'name' => $p->client?->fullName() ?? '—',
                        'code' => $p->client?->code,
                        'state' => $state,
                    ];
                })->values()->all();

                $off = collect($vis[$key] ?? [])->keys()->diff($dueIds)->count();

                $t['planned'] += count($items);
                $t['done'] += collect($items)->where('state', 'done')->count();
                $t['missed'] += collect($items)->where('state', 'missed')->count();
                $t['off'] += $off;

                $grid[$key] = ['items' => $items, 'off' => $off];
            }

            return $t + [
                'rep' => $rep,
                'grid' => $grid,
                'pct' => $t['planned'] > 0 ? (int) round($t['done'] / $t['planned'] * 100) : 0,
            ];
        });
    }
}
