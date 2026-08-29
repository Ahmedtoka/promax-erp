<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use App\Services\Kpi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * داشبورد المدير/الأدمن على الموبايل (٢٨/٨/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * **مرآة معادلة `ErpController::overview` بالظبط** — نفس الكويريز
 * ونفس عقيدة الأرقام (مبيعات = `grand_total` الفواتير + توريدات
 * اتسلمت، تحصيل = قيود `collection` الدائنة بمصادرها، المديونية
 * سنابشوت مش بتتفلتر بالفترة). ⚠️ أي تعديل في حسبة الويب لازم
 * يتعكس هنا — الشاشتين لازم يطلعوا نفس الرقم للبيسترة
 * (promax-numbers).
 *
 * السكوب:
 *   - manager → فريقه غصب عنه (`manager_id` بيتاعه مهما بعت إيه)
 *   - admin   → الشركة كلها، أو «بعيون مدير» عبر ?manager_id=
 */
class ManagerDashApiController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $u = $request->user();

        try {
            $from = $request->filled('from') ? Carbon::parse($request->input('from')) : today()->startOfMonth();
        } catch (\Throwable) {
            $from = today()->startOfMonth();
        }
        try {
            $to = $request->filled('to') ? Carbon::parse($request->input('to')) : today();
        } catch (\Throwable) {
            $to = today();
        }
        [$a, $b] = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        // المدير مقفول على نفسه — الأدمن حر
        $mgrId = $u->role === 'manager' ? $u->id : ($request->integer('manager_id') ?: null);

        $repIds = null;
        if ($mgrId) {
            $repIds = User::whereIn('role', User::FIELD_WORK_ROLES)
                ->where('manager_id', $mgrId)->pluck('id')->push($mgrId)->all();
        }

        // ⚠️ الأعمدة مؤهّلة بـ`invoices.` — نفس درس ٢٣/٨ (جوينات ambiguous)
        $invQ = fn () => Invoice::whereBetween('invoices.created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('invoices.user_id', $repIds));

        $inv = $invQ()->selectRaw("COUNT(*) n, COALESCE(SUM(grand_total),0) g,
            COALESCE(SUM(tax_total),0) tax,
            COALESCE(SUM(CASE WHEN payment='cash' THEN grand_total ELSE 0 END),0) cash_g,
            COALESCE(SUM(CASE WHEN tax_total > 0 THEN grand_total ELSE 0 END),0) billed_g")->first();

        $posDelivered = PurchaseOrder::where('status', 'delivered')
            ->whereBetween('delivered_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('assigned_to', $repIds))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(grand_total),0) g')->first();

        // التحصيل مقسوم بالمصدر — كاش فواتير / ميداني / توريدات
        $collRows = Transaction::where('kind', 'collection')
            ->whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->where('source_type', Invoice::class)
                    ->whereIn('source_id', Invoice::whereIn('user_id', $repIds)->select('id')))
                ->orWhere(fn ($x) => $x->where('source_type', Visit::class)
                    ->whereIn('source_id', Visit::whereIn('user_id', $repIds)->select('id')))
                ->orWhere(fn ($x) => $x->where('source_type', PurchaseOrder::class)
                    ->whereIn('source_id', PurchaseOrder::whereIn('assigned_to', $repIds)->select('id')))))
            ->selectRaw('source_type, COALESCE(SUM(credit),0) v')
            ->groupBy('source_type')
            ->pluck('v', 'source_type');

        $collSplit = [
            'invoice' => (float) ($collRows[Invoice::class] ?? 0),
            'visit' => (float) ($collRows[Visit::class] ?? 0),
            'po' => (float) ($collRows[PurchaseOrder::class] ?? 0),
        ];
        $collSplit['other'] = round((float) $collRows->sum() - array_sum($collSplit), 2);
        $coll = (float) $collRows->sum();

        $rets = ClientReturn::whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('user_id', $repIds))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(grand_total),0) g')->first();

        $visitsN = Visit::whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('user_id', $repIds))->count();

        $newClientsN = Client::visibleTo(Client::whereBetween('created_at', [$a, $b]), $u)
            ->when($mgrId, fn ($q) => $q->where('manager_id', $mgrId))->count();

        // المديونية — سنابشوت حالي
        $debt = Client::visibleTo(Client::where('balance', '>', 0), $u)
            ->when($mgrId, fn ($q) => $q->where('manager_id', $mgrId))
            ->selectRaw('COALESCE(SUM(balance),0) g, COUNT(*) n')->first();

        // العهدة في الشارع دلوقتي — بسعر المستهلك
        $street = DB::table('custody_items')
            ->join('custodies', 'custodies.id', '=', 'custody_items.custody_id')
            ->join('products', 'products.id', '=', 'custody_items.product_id')
            ->where('custodies.status', '!=', 'closed')
            ->when($repIds, fn ($q) => $q->whereIn('custodies.user_id', $repIds))
            ->selectRaw('COUNT(DISTINCT custodies.id) vans,
                COALESCE(SUM(custody_items.assigned + custody_items.gift_assigned
                    - custody_items.sold - custody_items.returned
                    - custody_items.transferred_out - custody_items.gift_given), 0) units,
                COALESCE(SUM((custody_items.assigned + custody_items.gift_assigned
                    - custody_items.sold - custody_items.returned
                    - custody_items.transferred_out - custody_items.gift_given)
                    * products.price_new), 0) val')
            ->first();

        // أفضل المناديب بالفترة
        $topRows = $invQ()->selectRaw('user_id, COUNT(*) n, SUM(grand_total) v')
            ->groupBy('user_id')->orderByDesc('v')->take(8)->get();
        $topUsers = User::whereIn('id', $topRows->pluck('user_id'))->get()->keyBy('id');
        $topReps = $topRows->map(fn ($r) => [
            'id' => $r->user_id,
            'name' => $topUsers->get($r->user_id)?->displayName() ?? '#'.$r->user_id,
            'avatar_url' => $topUsers->get($r->user_id)?->avatarUrl(),
            'n' => (int) $r->n,
            'v' => (float) $r->v,
        ])->values();

        // السلسلة الزمنية مبيعات/تحصيل — يومي ≤35 يوم وإلا شهري
        $daily = $a->diffInDays($b) <= 35;
        $fmt = $daily ? '%Y-%m-%d' : '%Y-%m';
        $salesSeries = $invQ()->selectRaw("DATE_FORMAT(created_at, '$fmt') k, SUM(grand_total) v")
            ->groupBy('k')->pluck('v', 'k');
        $collSeries = Transaction::where('kind', 'collection')
            ->whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->where('source_type', Invoice::class)
                    ->whereIn('source_id', Invoice::whereIn('user_id', $repIds)->select('id')))
                ->orWhere(fn ($x) => $x->where('source_type', Visit::class)
                    ->whereIn('source_id', Visit::whereIn('user_id', $repIds)->select('id')))))
            ->selectRaw("DATE_FORMAT(created_at, '$fmt') k, SUM(credit) v")
            ->groupBy('k')->pluck('v', 'k');

        $series = [];
        $cursor = $a->copy();
        while ($cursor <= $b) {
            $k = $cursor->format($daily ? 'Y-m-d' : 'Y-m');
            $series[] = [
                'k' => $k,
                'sales' => (float) ($salesSeries[$k] ?? 0),
                'coll' => (float) ($collSeries[$k] ?? 0),
            ];
            $daily ? $cursor->addDay() : $cursor->addMonthNoOverflow()->startOfMonth();
        }

        $salesTotal = (float) $inv->g + (float) $posDelivered->g;

        return response()->json([
            'from' => $a->toDateString(),
            'to' => $to->toDateString(),
            'manager_id' => $mgrId,
            // فلتر «بعيون مدير» — للأدمن بس، المدير بياخد قايمة فاضية
            'managers' => $u->role === 'manager' ? [] :
                User::whereIn('role', User::ASSIGNABLE_MANAGER_ROLES)
                    ->where('active', true)->orderBy('name')->get()
                    ->map(fn ($m) => ['id' => $m->id, 'name' => $m->displayName()])->values(),

            // ═══ المعادلة: مبيعات − تحصيل − مرتجعات = صافي الحركة ═══
            'sales' => [
                'total' => $salesTotal,
                'cash' => (float) $inv->cash_g,
                'credit' => (float) $inv->g - (float) $inv->cash_g,
                'pos' => (float) $posDelivered->g,
                'invoices_n' => (int) $inv->n,
                'pos_n' => (int) $posDelivered->n,
                'billed' => (float) $inv->billed_g,
                'unbilled' => (float) $inv->g - (float) $inv->billed_g,
            ],
            'collections' => [
                'total' => $coll,
                'invoice' => $collSplit['invoice'],
                'visit' => $collSplit['visit'],
                'po' => $collSplit['po'],
                'other' => $collSplit['other'],
            ],
            'returns' => ['total' => (float) $rets->g, 'n' => (int) $rets->n],
            'net_move' => round($salesTotal - $coll - (float) $rets->g, 2),
            'debt' => ['total' => (float) $debt->g, 'clients' => (int) $debt->n],
            'street' => [
                'vans' => (int) $street->vans,
                'units' => (int) $street->units,
                'value' => (float) $street->val,
            ],
            'visits_n' => $visitsN,
            'new_clients_n' => $newClientsN,
            'top_reps' => $topReps,
            'series' => $series,
        ]);
    }

    /**
     * متابعة ليدات الفريق (٢٨/٨) — «الحركة جمب بحركة» على الموبايل:
     * لكل مندوب في الفريق: مجدولين النهارده + أرقام أسبوعه
     * (اتجدوله/راح/فايتله/كسبهم — نفس حسبة `LeadController::week`:
     * «راح فعلاً» = الليد اتأكد في نفس يوم خطته) + آخر الأكاونتات
     * اللي اتفتحت من الميدان.
     *
     * ⚠️ **مقصوص على الفريق** — شاشة الويب للأدمن فبتعرض الكل، هنا
     * المدير بيشوف مناديبه بس (fieldVisibleTo).
     */
    public function leads(Request $request): JsonResponse
    {
        $u = $request->user();

        $reps = User::fieldVisibleTo(
            User::whereIn('role', User::FIELD_ROLES)->where('active', true), $u)
            ->orderBy('name')->get();
        $repIds = $reps->pluck('id')->all();

        $start = today()->copy()->startOfWeek(\Carbon\CarbonInterface::SATURDAY);
        $end = $start->copy()->addDays(6);

        $plans = \App\Models\LeadPlan::with('lead')
            ->whereIn('user_id', $repIds)
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('plan_date')->orderBy('sort')
            ->get()
            ->filter(fn ($p) => $p->lead !== null);

        $visited = fn ($p) => $p->lead->confirmed_at !== null
            && $p->lead->confirmed_at->isSameDay($p->plan_date);

        $byRep = $plans->groupBy('user_id');

        // ليدات مفتوحة متوزعة على كل مندوب (بره الجدولة)
        $openByRep = \App\Models\Lead::whereIn('assigned_to', $repIds)
            ->whereIn('status', \App\Models\Lead::OPEN_STATUSES)
            ->selectRaw('assigned_to, COUNT(*) n')
            ->groupBy('assigned_to')->pluck('n', 'assigned_to');

        $rows = $reps->map(function (User $rep) use ($byRep, $openByRep, $visited) {
            $g = $byRep->get($rep->id, collect());
            $todayPlans = $g->filter(fn ($p) => $p->plan_date->isToday())->values();

            return [
                'id' => $rep->id,
                'name' => $rep->displayName(),
                'avatar_url' => $rep->avatarUrl(),
                'open' => (int) ($openByRep[$rep->id] ?? 0),
                'planned' => $g->count(),
                'visited' => $g->filter($visited)->count(),
                'missed' => $g->filter(fn ($p) => $p->plan_date->lt(today()) && ! $visited($p))->count(),
                'won' => $g->filter(fn ($p) => $p->lead->status === 'won')->count(),
                'today' => $todayPlans->map(fn ($p) => [
                    'id' => $p->lead->id,
                    'name' => $p->lead->name,
                    'status' => $p->lead->status,
                    'confirmed' => $p->lead->confirmed_at !== null
                        && $p->lead->confirmed_at->isToday(),
                ])->values(),
            ];
        })->sortByDesc(fn ($r) => $r['missed'])->values();

        // أكاونتات اتفتحت من الميدان — آخر ١٤ يوم
        $opened = \App\Models\Lead::with(['assignee:id,name', 'client:id,code,name'])
            ->whereIn('assigned_to', $repIds)
            ->where('status', 'won')->whereNotNull('client_id')
            ->where('converted_at', '>=', now()->subDays(14))
            ->orderByDesc('converted_at')->take(15)->get()
            ->map(fn ($l) => [
                'lead' => $l->name,
                'client_code' => $l->client?->code,
                'by' => $l->assignee?->name,
                'at' => $l->converted_at?->format('d/m h:i A'),
            ])->values();

        return response()->json([
            'week_of' => $start->toDateString(),
            'reps' => $rows,
            'opened' => $opened,
        ]);
    }

    /**
     * KPI والعمولات — **قراءة بس** (قرار المالك ٢٨/٨).
     * المدير بيشوف قنواته بس، والأدمن الكل — نفس سكوب شاشة `/erp/kpi`.
     */
    public function kpi(Request $request): JsonResponse
    {
        $u = $request->user();
        $period = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('period'))
            ? $request->input('period') : now()->format('Y-m');

        $calc = Kpi::calculate($period);

        $myChannels = $u->role === 'manager'
            ? $u->channels()->pluck('channels.id')->all()
            : null;

        $channels = [];
        foreach ($calc['channels'] as $row) {
            $ch = $row['channel'];
            if ($myChannels !== null && ! in_array($ch->id, $myChannels)) {
                continue;
            }

            // ⚠️ المفاتيح دي من `Kpi::repRow`/`leaderRow` بالحرف —
            // rep موديل كامل، والأرقام النهائية في `final`
            $channels[] = [
                'id' => $ch->id,
                'name' => $ch->displayName(),
                'reps' => collect($row['reps'])->map(fn ($r) => [
                    'name' => $r['rep']->displayName(),
                    'avatar_url' => $r['rep']->avatarUrl(),
                    'collections' => round((float) $r['data']['collections'], 2),
                    'achievement' => round((float) $r['achievement'] * 100, 1),
                    'cleared' => (bool) $r['cleared'],
                    'score' => (float) $r['score'],
                    'base_value' => (float) $r['after_perf'],
                    'kpi_earned' => (float) $r['kpi_earned'],
                    'final' => (float) $r['final'],
                ])->values(),
                'manager' => isset($row['manager']) ? [
                    'collections' => round((float) $row['manager']['collections'], 2),
                    'achievement' => round((float) $row['manager']['achievement'] * 100, 1),
                    'cleared' => (bool) $row['manager']['cleared'],
                    'score' => (float) $row['manager']['score'],
                    'final' => (float) $row['manager']['final'],
                ] : null,
            ];
        }

        return response()->json(['period' => $period, 'channels' => $channels]);
    }
}
