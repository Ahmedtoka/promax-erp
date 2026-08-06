<?php

namespace App\Services;

use App\Models\ClientRequest;
use App\Models\CommissionTier;
use App\Models\Invoice;
use App\Models\RepPoint;
use App\Models\RepTarget;
use App\Models\Setting;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مؤشرات أداء المناديب — المصدر الوحيد (2026-08-06)
 * ═══════════════════════════════════════════════════════════════
 *
 * كل رقم أداء لمندوب (ERP أو أبلكيشن) بيطلع من هنا:
 *
 * - **المبيعات صافي** (عقيدة الأرقام: `invoices.total` قبل الضريبة)
 *   − المرتجعات الصافية (credit − tax على زيارات المندوب).
 * - **العمولة**: نسبة التحقيق من تارجت الفلوس ← شريحة من
 *   `commission_tiers` ← النسبة × الصافي.
 * - **النقاط**: أوتوماتيك مشتقة (زيارات × نقطة + عملاء جداد × نقاط
 *   + قطع/100 × نقطة — القيم من الإعدادات) + اليدوي من `rep_points`.
 * - **النشاط**: فتح أبلكيشن/تشيك إن/تشيك أوت/متوسط القعدة/كيلومترات
 *   من `track_events` و`visits`.
 */
class RepKpis
{
    /** كل مؤشرات مندوب لشهر — للتقارير وشاشة التشجيع في الأبلكيشن */
    public static function forMonth(User $rep, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->endOfDay();

        $target = RepTarget::for($rep->id, $start);

        // ═══ المبيعات — الصافي قبل الضريبة (عقيدة الأرقام الثلاثة) ═══
        $inv = Invoice::where('user_id', $rep->id)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(total),0) as net,
                         COALESCE(SUM(CASE WHEN payment = "cash" THEN grand_total ELSE 0 END),0) as cash,
                         COALESCE(SUM(CASE WHEN payment != "cash" THEN grand_total ELSE 0 END),0) as credit,
                         COUNT(DISTINCT client_id) as clients')
            ->first();

        // مرتجعات المندوب — قيود return على زياراته، صافي بعد طرح الضريبة
        $returns = Transaction::where('kind', 'return')
            ->where('source_type', Visit::class)
            ->whereIn('source_id', Visit::where('user_id', $rep->id)->select('id'))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(credit - tax),0) as net')
            ->value('net');

        $netSales = round((float) $inv->net - (float) $returns, 2);

        // ═══ القطع — بنود فواتيره ═══
        $pieces = (int) DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.user_id', $rep->id)
            ->whereBetween('invoices.created_at', [$start, $end])
            ->sum('invoice_items.qty');

        // ═══ الزيارات والعملاء الجداد ═══
        $visits = Visit::where('user_id', $rep->id)
            ->whereNotNull('checked_out_at')
            ->whereBetween('checked_in_at', [$start, $end]);
        $visitCount = (clone $visits)->count();
        $avgMinutes = (int) round((clone $visits)
            ->selectRaw('COALESCE(AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at)),0) as m')
            ->value('m'));

        $newClients = ClientRequest::where('created_by', $rep->id)
            ->where('status', 'approved')
            ->whereBetween('updated_at', [$start, $end])
            ->count();

        // ═══ النشاط من التراك إيفنتس ═══
        $events = TrackEvent::where('user_id', $rep->id)
            ->whereBetween('happened_at', [$start, $end])
            ->selectRaw("SUM(type = 'open') as opens, SUM(type = 'start') as starts,
                         SUM(type = 'check_in') as ins, SUM(type = 'check_out') as outs")
            ->first();

        // ═══ التحقيق والعمولة ═══
        $moneyTarget = (float) ($target?->money_target ?? 0);
        $achievement = $moneyTarget > 0 ? round($netSales / $moneyTarget * 100, 1) : 0.0;
        $rate = CommissionTier::rateFor($achievement);
        $commission = round($netSales * $rate, 2);

        // ═══ النقاط: أوتوماتيك مشتقة + يدوي مخزّن ═══
        $autoPoints = $visitCount * (int) Setting::read('pts_per_visit', '1')
            + $newClients * (int) Setting::read('pts_per_new_client', '10')
            + intdiv($pieces, 100) * (int) Setting::read('pts_per_100_pieces', '1');
        $manualPoints = (int) RepPoint::where('user_id', $rep->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('points');
        $points = $autoPoints + $manualPoints;
        $pointValue = (float) Setting::read('point_value', '5');

        $pct = fn (float $done, float $t) => $t > 0 ? round($done / $t * 100, 1) : 0.0;

        return [
            'target' => $target,
            'net_sales' => $netSales,
            'cash_sales' => round((float) $inv->cash, 2),
            'credit_sales' => round((float) $inv->credit, 2),
            'returns_net' => round((float) $returns, 2),
            'invoices' => (int) $inv->n,
            'clients_sold' => (int) $inv->clients,
            'pieces' => $pieces,
            'visits' => $visitCount,
            'avg_visit_minutes' => $avgMinutes,
            'new_clients' => $newClients,
            'app_opens' => (int) ($events->opens ?? 0) + (int) ($events->starts ?? 0),
            'check_ins' => (int) ($events->ins ?? 0),
            'check_outs' => (int) ($events->outs ?? 0),
            'money_pct' => $achievement,
            'visits_pct' => $pct($visitCount, (float) ($target?->visits_target ?? 0)),
            'clients_pct' => $pct($newClients, (float) ($target?->new_clients_target ?? 0)),
            'pieces_pct' => $pct($pieces, (float) ($target?->pieces_target ?? 0)),
            'commission_rate' => $rate,
            'commission' => $commission,
            'auto_points' => $autoPoints,
            'manual_points' => $manualPoints,
            'points' => $points,
            'points_money' => round($points * $pointValue, 2),
        ];
    }

    /**
     * كيلومترات يوم واحد — مجموع المسافات بين نقاط التراك المتتالية.
     * القفزات الأكبر من 5 كم بين نقطتين بتتداس (GPS شارد).
     */
    public static function kmForDay(User $rep, Carbon $day): float
    {
        $points = TrackEvent::where('user_id', $rep->id)
            ->whereDate('happened_at', $day->toDateString())
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderBy('happened_at')
            ->get(['lat', 'lng']);

        $km = 0.0;
        $prev = null;

        foreach ($points as $p) {
            if ($prev !== null) {
                $d = self::haversine((float) $prev->lat, (float) $prev->lng, (float) $p->lat, (float) $p->lng);

                if ($d <= 5) {
                    $km += $d;
                }
            }

            $prev = $p;
        }

        return round($km, 1);
    }

    /** مسافة بين نقطتين بالكيلومتر */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
