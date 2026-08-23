<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\KpiBand;
use App\Models\KpiChannel;
use App\Models\KpiInput;
use App\Models\KpiMetric;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * محرك العمولات والـKPI (٢٣ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ترجمة **حرفية** لنموذج ProMax_Commission_KPI_Calculator:
 *
 *   للمندوب (شيت Rep_Calculator):
 *     التحقيق = تحصيله ÷ تارجت قناته
 *     البوابة = التحقيق ≥ عتبة البوابة (80%)
 *     النسبة الأساسية = شريحة التحقيق بالقناة (0 تحت البوابة)
 *     القيمة الأساسية = التحصيل × النسبة
 *     درجة الـKPI = Σ نقاط الـ13 مؤشر (من 100)
 *     المعامل = شريحة الدرجة (0.7 / 0.8 / 0.9 / 1)
 *     بعد الأداء = بوابة متحققة ? الأساسية × المعامل : 0
 *     وعاء الحافز = التحصيل × نسبة KPI (منفصلة)
 *     مستحق الحافز = (الدرجة ≥ 75 و(البوابة أو الاشتراط مقفول))
 *                    ? الوعاء × الدرجة ÷ 100 : 0
 *     الإجمالي = بعد الأداء + مستحق الحافز
 *
 *   للمدير/المدير العام (Manager/Director_Calculator): نفس الميكانيكا
 *   على **إجمالي تحصيل القناة** بنسبة أساسية ثابتة من صف القناة
 *   و12 مؤشر قيادي (أداء الفريق = متوسط درجات مناديبه).
 *
 * ⚠️ **مصادر الأرقام من السيستم** (بديل شيت Sales_Data) موثقة عند كل
 * دالة — التحصيل بعقيدة الأرقام: كاش الفواتير + التحصيل الميداني
 * + تحصيل أوامر التوريد، كله من `transactions`.
 */
class Kpi
{
    /** السياسة من settings — بقيم الإكسيل كديفولت */
    public static function policy(): array
    {
        return [
            'rep_rate' => (float) Setting::read('kpi_rep_rate', '0.01'),
            'manager_rate' => (float) Setting::read('kpi_manager_rate', '0.01'),
            'director_rate' => (float) Setting::read('kpi_director_rate', '0.01'),
            'min_score' => (float) Setting::read('kpi_min_score', '75'),
            'require_gate' => Setting::read('kpi_require_gate', '1') === '1',
            'gate' => (float) Setting::read('kpi_gate_threshold', '0.8'),
        ];
    }

    /** الشهر ← [بداية، نهاية، بداية اللي قبله، نهايته] */
    private static function bounds(string $period): array
    {
        $a = Carbon::parse($period.'-01')->startOfDay();
        $b = $a->copy()->endOfMonth()->endOfDay();
        $pa = $a->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
        $pb = $pa->copy()->endOfMonth()->endOfDay();

        return [$a, $b, $pa, $pb];
    }

    /**
     * ═══ داتا المندوب من السيستم — بديل صف Sales_Data ═══
     *
     * التعريفات المعتمدة (موثقة في الشاشة كمان):
     *   التحصيل        = فواتيره كاش + تحصيله الميداني + تحصيل POs كاش بتاعته
     *   المتأخر >60    = أعمار مديونية عملائه (الشرائح فوق 60 يوم)
     *   المديونية      = أرصدة عملائه الموجبة
     *   عملاء جدد      = عملاء اتضافوا ليه في الشهر
     *   نشط سابق/مستمر = عميل ليه فاتورة الشهر اللي فات / ولسه بيشتري
     *   كرر الطلب      = عملاؤه اللي ليهم أكتر من فاتورة في الشهر
     *   أصناف التركيز  = مبيعات المنتجات المعلّمة is_focus
     *   التغطية        = مناطق زارها ÷ مناطقه المتعلّمة
     *   المتابعة       = زيارات متقفلة ÷ إجمالي زياراته
     *   SLA            = POs اتسلمت في معادها ÷ POs اتسلمت
     *   FIFO/التصريف   = الكمية المباعة ÷ المحمّلة عليه في الشهر
     *   العيوب         = التالف الراجع ÷ المحمّل عليه
     *   المرتجعات      = كمية مرتجعاته ÷ الكمية المباعة
     */
    public static function repData(User $rep, string $period): array
    {
        [$a, $b, $pa, $pb] = self::bounds($period);

        // ═══ التحصيل — عقيدة الأرقام: قيود collection مصدرها شغله ═══
        $cashInv = Invoice::where('user_id', $rep->id)
            ->whereBetween('created_at', [$a, $b])
            ->where('payment', 'cash')->sum('grand_total');

        $fieldColl = Transaction::where('kind', 'collection')
            ->where('source_type', Visit::class)
            ->whereBetween('transactions.created_at', [$a, $b])
            ->whereIn('source_id', Visit::where('user_id', $rep->id)->select('id'))
            ->sum('credit');

        $poColl = Transaction::where('kind', 'collection')
            ->where('source_type', PurchaseOrder::class)
            ->whereBetween('transactions.created_at', [$a, $b])
            ->whereIn('source_id', PurchaseOrder::where('assigned_to', $rep->id)->select('id'))
            ->sum('credit');

        $collections = (float) $cashInv + (float) $fieldColl + (float) $poColl;

        // ═══ عملاؤه — بالتسكين الأساسي ═══
        $clientIds = Client::where('rep_id', $rep->id)->where('status', 'active')->pluck('id');

        $ledger = (float) Client::whereIn('id', $clientIds)->where('balance', '>', 0)->sum('balance');

        // المتأخر >60 يوم — من أعمار الـFIFO (a90 + a180 + a180p)
        $overdue = 0.0;
        Client::whereIn('id', $clientIds)->where('balance', '>', 0)
            ->with(['transactions' => fn ($q) => $q->where('debit', '>', 0)])
            ->chunk(100, function ($chunk) use (&$overdue) {
                foreach ($chunk as $c) {
                    $ag = $c->aging();
                    $overdue += ($ag['a90'] ?? 0) + ($ag['a180'] ?? 0) + ($ag['a180p'] ?? 0);
                }
            });

        $newAccounts = Client::where('rep_id', $rep->id)
            ->whereBetween('created_at', [$a, $b])->count();

        // النشاط — عملاء بفواتير (أي دفع) في الشهرين
        $curBuyers = Invoice::where('user_id', $rep->id)->whereBetween('created_at', [$a, $b])
            ->distinct()->pluck('client_id');
        $prevBuyers = Invoice::where('user_id', $rep->id)->whereBetween('created_at', [$pa, $pb])
            ->distinct()->pluck('client_id');
        $retained = $curBuyers->intersect($prevBuyers)->count();

        $reordered = Invoice::where('user_id', $rep->id)->whereBetween('created_at', [$a, $b])
            ->selectRaw('client_id, COUNT(*) n')->groupBy('client_id')
            ->having('n', '>', 1)->get()->count();

        // أصناف التركيز — مبيعات is_focus من فواتيره
        $focus = (float) DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->where('invoices.user_id', $rep->id)
            ->whereBetween('invoices.created_at', [$a, $b])
            ->where('products.is_focus', true)
            ->sum('invoice_items.total');

        // التغطية — مناطق زارها ÷ المتعلّمة له
        $assignedZones = $rep->zones()->count() ?: ($rep->zone_id ? 1 : 0);
        $coveredZones = Visit::where('visits.user_id', $rep->id)
            ->whereBetween('visits.created_at', [$a, $b])
            ->join('clients', 'clients.id', '=', 'visits.client_id')
            ->whereNotNull('clients.zone_id')
            ->distinct()->count('clients.zone_id');

        // المتابعة — زيارات اتقفلت صح ÷ إجمالي زياراته
        $visitsAll = Visit::where('user_id', $rep->id)->whereBetween('created_at', [$a, $b])->count();
        $visitsDone = Visit::where('user_id', $rep->id)->whereBetween('created_at', [$a, $b])
            ->whereNotNull('checked_out_at')->count();

        // SLA — أوامر اتسلمت في معادها
        $posDelivered = PurchaseOrder::where('assigned_to', $rep->id)
            ->where('status', 'delivered')->whereBetween('delivered_at', [$a, $b])->get(['due_at', 'delivered_at']);
        $slaEligible = $posDelivered->count();
        $slaOk = $posDelivered->filter(fn ($p) => $p->due_at === null || $p->delivered_at <= $p->due_at)->count();

        // الكميات — من عهدته في الشهر
        $received = (int) DB::table('custody_items')
            ->join('custodies', 'custodies.id', '=', 'custody_items.custody_id')
            ->where('custodies.user_id', $rep->id)
            ->whereBetween('custody_items.created_at', [$a, $b])
            ->sum(DB::raw('custody_items.assigned + custody_items.gift_assigned'));

        $defect = (int) DB::table('custody_items')
            ->join('custodies', 'custodies.id', '=', 'custody_items.custody_id')
            ->where('custodies.user_id', $rep->id)
            ->whereBetween('custody_items.created_at', [$a, $b])
            ->sum('damaged_in');

        $soldQty = (int) DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.user_id', $rep->id)
            ->whereBetween('invoices.created_at', [$a, $b])
            ->sum('invoice_items.qty');

        $returnQty = (int) DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('returns.user_id', $rep->id)
            ->whereBetween('returns.created_at', [$a, $b])
            ->sum('return_items.qty');

        // مبيعات الشهر السابق لكل عميل — لمعدل النمو
        $prevColl = (float) Invoice::where('user_id', $rep->id)
            ->whereBetween('created_at', [$pa, $pb])->sum('grand_total');
        $prevPerAccount = $prevBuyers->count() > 0 ? $prevColl / $prevBuyers->count() : 0.0;

        return [
            'collections' => $collections,
            'overdue' => $overdue, 'ledger' => $ledger,
            'new_accounts' => $newAccounts,
            'prior_active' => $prevBuyers->count(),
            'retained' => $retained,
            'current_active' => $curBuyers->count(),
            'reordered' => $reordered,
            'focus' => $focus,
            'assigned_zones' => $assignedZones, 'covered_zones' => $coveredZones,
            'prev_per_account' => $prevPerAccount,
            'followups_due' => $visitsAll, 'followups_done' => $visitsDone,
            'sla_eligible' => $slaEligible, 'sla_ok' => $slaOk,
            'ageing_due' => $received, 'ageing_cleared' => $soldQty,
            'received' => $received, 'defect' => $defect,
            'sold_qty' => $soldQty, 'return_qty' => $returnQty,
        ];
    }

    /** النسب الـ13 للمندوب — أعمدة G..S في Rep_Calculator بالحرف */
    public static function repRatios(array $d): array
    {
        $div = fn ($x, $y) => $y > 0 ? $x / $y : null;

        return [
            'stability' => $div($d['retained'], $d['prior_active']),
            'growth' => ($d['current_active'] > 0 && $d['prev_per_account'] > 0)
                ? ($d['collections'] / $d['current_active']) / $d['prev_per_account'] - 1 : null,
            'coverage' => $div($d['covered_zones'], $d['assigned_zones']),
            'salesPerAccount' => $div($d['collections'], $d['current_active']),
            'mix' => $div($d['focus'], $d['collections']),
            'newAccounts' => (float) $d['new_accounts'],
            'followup' => $div($d['followups_done'], $d['followups_due']),
            'sla' => $div($d['sla_ok'], $d['sla_eligible']),
            'fifo' => $div($d['ageing_cleared'], $d['ageing_due']),
            'reorder' => $div($d['reordered'], $d['prior_active']),
            'collectionQuality' => $div($d['overdue'], $d['ledger']),
            'defectRate' => $d['received'] > 0 ? $d['defect'] / $d['received'] : null,
            'returnRate' => $d['sold_qty'] > 0 ? $d['return_qty'] / $d['sold_qty'] : null,
        ];
    }

    /** حساب مندوب واحد كامل — صف Rep_Calculator */
    public static function repRow(User $rep, KpiChannel $ch, string $period, array $policy, $metrics): array
    {
        $d = self::repData($rep, $period);
        $ratios = self::repRatios($d);

        $points = [];
        $score = 0.0;
        foreach ($metrics as $m) {
            $v = $ratios[$m->key] ?? null;
            $p = $m->points($v === null ? null : (float) $v, $ch->id);
            $points[$m->key] = $p;
            $score += $p;
        }

        $gate = $ch->rep_gate > 0 ? $d['collections'] / $ch->rep_gate : 0.0;
        $cleared = $gate >= $policy['gate'];

        // النسبة الأساسية — شريحة التحقيق بالقناة (صفر تحت البوابة)
        $baseRate = $gate < $policy['gate'] ? 0.0 : KpiBand::lookup('rate', $ch->id, $gate);
        $baseValue = $d['collections'] * $baseRate;
        $multiplier = KpiBand::lookup('multiplier', null, $score);
        $afterPerf = $cleared ? $baseValue * $multiplier : 0.0;

        $pool = $d['collections'] * $policy['rep_rate'];
        $eligible = $score >= $policy['min_score'] && (! $policy['require_gate'] || $cleared);
        $kpiEarned = $eligible ? $pool * $score / 100 : 0.0;

        return [
            'rep' => $rep, 'channel' => $ch, 'data' => $d, 'ratios' => $ratios,
            'points' => $points, 'score' => round($score, 1),
            'achievement' => $gate, 'cleared' => $cleared,
            'base_rate' => $baseRate, 'base_value' => round($baseValue, 2),
            'multiplier' => $multiplier, 'after_perf' => round($afterPerf, 2),
            'reduction' => round($baseValue - $afterPerf, 2),
            'pool' => round($pool, 2), 'eligible' => $eligible,
            'kpi_earned' => round($kpiEarned, 2),
            'final' => round($afterPerf + $kpiEarned, 2),
            'actual_rate' => $d['collections'] > 0 ? ($afterPerf + $kpiEarned) / $d['collections'] : 0.0,
        ];
    }

    /**
     * حساب قائد (مدير قناة أو مدير عام) — Manager/Director_Calculator.
     * $role: manager | director. القياس على مجاميع صفوف مناديب القناة.
     */
    public static function leaderRow(KpiChannel $ch, string $role, string $period, array $policy, $metrics, array $repRows): array
    {
        $rows = collect($repRows);
        $sum = fn (string $k) => (float) $rows->sum(fn ($r) => $r['data'][$k]);

        $collections = $sum('collections');
        $gateTarget = $role === 'manager' ? $ch->manager_gate : $ch->director_gate;
        $baseRate = $role === 'manager' ? $ch->manager_rate : $ch->director_rate;
        $kpiRate = $role === 'manager' ? $policy['manager_rate'] : $policy['director_rate'];

        $input = KpiInput::firstOrNew([
            'period' => $period, 'role' => $role, 'kpi_channel_id' => $ch->id,
        ]);

        $div = fn ($x, $y) => $y > 0 ? $x / $y : null;
        $gate = $gateTarget > 0 ? $collections / $gateTarget : 0.0;

        // متوسط درجات الفريق — teamPerformance للمدير، وللمدير العام
        // درجة مدير القناة نفسها بتتبعت من بره (نمررها بالراتيوز)
        $teamAvg = $rows->count() ? $rows->avg('score') / 100 : null;

        $ratios = [
            'salesTarget' => $gate,
            'forecastAccuracy' => ($collections > 0 && $input->forecast > 0)
                ? max(0, 1 - abs($input->forecast - $collections) / $collections) : null,
            'collectionQuality' => $div($sum('overdue'), $sum('ledger')),
            'newCustomers' => $input->new_target > 0 ? $sum('new_accounts') / $input->new_target : null,
            'reorder' => $div($sum('reordered'), $sum('prior_active')),
            'loyalty' => $div($sum('retained'), $sum('prior_active')),
            'mix' => $div($sum('focus'), $collections),
            'teamPerformance' => $teamAvg,
            'followup' => $div($sum('followups_done'), $sum('followups_due')),
            'reporting' => (float) ($input->reporting ?: 0.95),
            'defectRate' => $sum('received') > 0 ? $sum('defect') / $sum('received') : null,
            'returnRate' => $sum('sold_qty') > 0 ? $sum('return_qty') / $sum('sold_qty') : null,
        ];

        $points = [];
        $score = 0.0;
        foreach ($metrics as $m) {
            $v = $ratios[$m->key] ?? null;
            $p = $m->points($v === null ? null : (float) $v, null);
            $points[$m->key] = $p;
            $score += $p;
        }

        $cleared = $gate >= $policy['gate'];
        $baseValue = $collections * $baseRate;
        $multiplier = KpiBand::lookup('multiplier', null, $score);
        $afterPerf = $cleared ? $baseValue * $multiplier : 0.0;
        $pool = $collections * $kpiRate;
        $eligible = $score >= $policy['min_score'] && (! $policy['require_gate'] || $cleared);
        $kpiEarned = $eligible ? $pool * $score / 100 : 0.0;

        return [
            'channel' => $ch, 'role' => $role, 'input' => $input,
            'collections' => $collections, 'ratios' => $ratios, 'points' => $points,
            'score' => round($score, 1), 'achievement' => $gate, 'cleared' => $cleared,
            'base_rate' => (float) $baseRate, 'base_value' => round($baseValue, 2),
            'multiplier' => $multiplier, 'after_perf' => round($afterPerf, 2),
            'pool' => round($pool, 2), 'eligible' => $eligible,
            'kpi_earned' => round($kpiEarned, 2),
            'final' => round($afterPerf + $kpiEarned, 2),
            'actual_rate' => $collections > 0 ? ($afterPerf + $kpiEarned) / $collections : 0.0,
        ];
    }

    /** الحساب الكامل لشهر — كل القنوات بمناديبها ومديريها والمدير العام */
    public static function calculate(string $period): array
    {
        $policy = self::policy();
        $repMetrics = KpiMetric::where('scope', 'rep')->where('active', true)->orderBy('sort')->get();
        $leaderMetrics = KpiMetric::where('scope', 'leader')->where('active', true)->orderBy('sort')->get();

        $out = ['channels' => [], 'policy' => $policy,
            'rep_metrics' => $repMetrics, 'leader_metrics' => $leaderMetrics];

        foreach (KpiChannel::where('active', true)->get() as $ch) {
            $repRows = [];
            foreach ($ch->reps() as $rep) {
                $repRows[] = self::repRow($rep, $ch, $period, $policy, $repMetrics);
            }

            $managerRow = self::leaderRow($ch, 'manager', $period, $policy, $leaderMetrics, $repRows);

            // المدير العام: teamPerformance = درجة مدير القناة نفسه
            $directorRow = self::leaderRow($ch, 'director', $period, $policy, $leaderMetrics, $repRows);
            $directorRow['ratios']['teamPerformance'] = $managerRow['score'] / 100;
            // إعادة حساب نقطة أداء الفريق للمدير العام بالقيمة الصح
            $tm = $leaderMetrics->firstWhere('key', 'teamPerformance');
            if ($tm !== null) {
                $old = $directorRow['points']['teamPerformance'] ?? 0;
                $new = $tm->points($managerRow['score'] / 100, null);
                $directorRow['points']['teamPerformance'] = $new;
                $directorRow['score'] = round($directorRow['score'] - $old + $new, 1);
                // الدرجة اتغيرت ← المعامل والحوافز بتتعاد
                $directorRow = self::rescoreLeader($directorRow, $policy);
            }

            $out['channels'][] = [
                'channel' => $ch,
                'reps' => $repRows,
                'manager' => $managerRow,
                'director' => $directorRow,
            ];
        }

        return $out;
    }

    /** إعادة اشتقاق نواتج قائد بعد تعديل الدرجة — نفس المعادلات */
    private static function rescoreLeader(array $r, array $policy): array
    {
        $r['multiplier'] = KpiBand::lookup('multiplier', null, $r['score']);
        $r['after_perf'] = $r['cleared'] ? round($r['base_value'] * $r['multiplier'], 2) : 0.0;
        $eligible = $r['score'] >= $policy['min_score'] && (! $policy['require_gate'] || $r['cleared']);
        $r['eligible'] = $eligible;
        $r['kpi_earned'] = $eligible ? round($r['pool'] * $r['score'] / 100, 2) : 0.0;
        $r['final'] = round($r['after_perf'] + $r['kpi_earned'], 2);
        $r['actual_rate'] = $r['collections'] > 0 ? $r['final'] / $r['collections'] : 0.0;

        return $r;
    }

    /** فحوصات النموذج — شيت Checks بالحرف */
    public static function checks(): array
    {
        $repW = (float) KpiMetric::where('scope', 'rep')->where('active', true)->sum('weight');
        $leadW = (float) KpiMetric::where('scope', 'leader')->where('active', true)->sum('weight');

        $checks = [
            ['key' => 'rep_weights', 'value' => $repW, 'expected' => 100.0,
                'pass' => abs($repW - 100) < 0.001],
            ['key' => 'leader_weights', 'value' => $leadW, 'expected' => 100.0,
                'pass' => abs($leadW - 100) < 0.001],
        ];

        foreach (KpiChannel::where('active', true)->get() as $ch) {
            $cost = $ch->maxBaseCost();
            $checks[] = ['key' => 'channel_cost', 'channel' => $ch->displayName(),
                'value' => $cost, 'expected' => 0.03, 'pass' => $cost <= 0.030001];
        }

        return $checks;
    }
}
