<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\CommissionTier;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
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
        return self::forMonthMany(collect([$rep]), $month)[$rep->id];
    }

    /**
     * ═══ نفس `forMonth` لمجموعة مناديب مرة واحدة (١٧/١٠) ═══
     *
     * شاشة الأداء كانت بتنادي `forMonth` لكل مندوب: ~13 كويري × المناديب
     * (103 كويري على نسخة اللايف). هنا كل مؤشر كويري واحدة `GROUP BY`
     * المندوب — **نفس الفلاتر بالحرف**، و`forMonth` بقت بتنادي الدالة دي،
     * فمفيش نسختين من المعادلة. الشرح الكامل لكل رقم في `compose()` وتحت.
     *
     * @param  iterable<User>  $reps
     * @return array<int, array> بالـ`id`
     */
    public static function forMonthMany(iterable $reps, Carbon $month): array
    {
        $reps = collect($reps)->values();
        $ids = $reps->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->endOfDay();

        // نفس `RepTarget::for` — أول صف للمندوب في الشهر
        $targets = RepTarget::whereIn('user_id', $ids)
            ->whereDate('month', $start->toDateString())
            ->get()->groupBy('user_id')->map(fn ($g) => $g->first());

        // ═══ المبيعات — الصافي قبل الضريبة (عقيدة الأرقام الثلاثة) ═══
        $inv = Invoice::whereIn('user_id', $ids)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('user_id, COUNT(*) as n, COALESCE(SUM(total),0) as net,
                         COALESCE(SUM(CASE WHEN payment = "cash" THEN grand_total ELSE 0 END),0) as cash,
                         COALESCE(SUM(CASE WHEN payment != "cash" THEN grand_total ELSE 0 END),0) as credit,
                         COUNT(DISTINCT client_id) as clients')
            ->groupBy('user_id')->get()->keyBy('user_id');

        // ═══ أوامر التوريد المسلَّمة — من القيود (إصلاح ١١/٨ مساءً) ═══
        //
        // ⚠️ العقيدة: **مبيعات المندوب = فواتيره (user_id) + أوامر
        // التوريد المسلَّمة (assigned_to)؛ مبيعات العميل = قيوده؛
        // التارجيت بالعميل.** نفس منطق `RepSettlementController::openFigures`:
        // **من `transactions` مش من `purchase_orders`** — القيد هو مصدر
        // الحقيقة، وقيد الـ`collection` هو اللي بيفرّق الكاش من الآجل.
        // الجوين على الأمر بدل `whereIn(select)` عشان التجميع بالمندوب.
        $po = DB::table('transactions')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'transactions.source_id')
            ->where('transactions.source_type', PurchaseOrder::class)
            ->whereIn('purchase_orders.assigned_to', $ids)
            ->whereBetween('transactions.created_at', [$start, $end])
            ->selectRaw("purchase_orders.assigned_to as uid,
                         COALESCE(SUM(CASE WHEN transactions.kind = 'sale' THEN transactions.debit - transactions.tax ELSE 0 END),0) as net,
                         COALESCE(SUM(CASE WHEN transactions.kind = 'sale' THEN transactions.debit ELSE 0 END),0) as gross,
                         COALESCE(SUM(CASE WHEN transactions.kind = 'collection' THEN transactions.credit ELSE 0 END),0) as cash")
            ->groupBy('purchase_orders.assigned_to')->get()->keyBy('uid');

        // مرتجعات المندوب — قيود return على زياراته، صافي بعد طرح الضريبة
        $returns = DB::table('transactions')
            ->join('visits', 'visits.id', '=', 'transactions.source_id')
            ->where('transactions.kind', 'return')
            ->where('transactions.source_type', Visit::class)
            ->whereIn('visits.user_id', $ids)
            ->whereBetween('transactions.created_at', [$start, $end])
            ->selectRaw('visits.user_id as uid, COALESCE(SUM(transactions.credit - transactions.tax),0) as net')
            ->groupBy('visits.user_id')->pluck('net', 'uid');

        // ═══ القطع — بنود فواتيره ═══
        // ⚠️ عن قصد من الفواتير بس (قرار ١١/٨): قطع أوامر التوريد
        // بتتحاسب في مطابقة العهدة، وضمها هنا كان هيحرّك نقاط
        // وتارجيت القطع بأثر رجعي من غير قرار من المالك.
        $pieces = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoices.user_id', $ids)
            ->whereBetween('invoices.created_at', [$start, $end])
            ->selectRaw('invoices.user_id as uid, SUM(invoice_items.qty) as q')
            ->groupBy('invoices.user_id')->pluck('q', 'uid');

        // ═══ الزيارات (العدد ومتوسط القعدة في كويري واحدة) والعملاء الجداد ═══
        $visits = Visit::whereIn('user_id', $ids)
            ->whereNotNull('checked_out_at')
            ->whereBetween('checked_in_at', [$start, $end])
            ->selectRaw('user_id, COUNT(*) as n,
                         COALESCE(AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at)),0) as m')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $newClients = ClientRequest::whereIn('created_by', $ids)
            ->where('status', 'approved')
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw('created_by, COUNT(*) as n')
            ->groupBy('created_by')->pluck('n', 'created_by');

        // ═══ النشاط من التراك إيفنتس ═══
        $events = TrackEvent::whereIn('user_id', $ids)
            ->whereBetween('happened_at', [$start, $end])
            ->selectRaw("user_id, SUM(type = 'open') as opens, SUM(type = 'start') as starts,
                         SUM(type = 'check_in') as ins, SUM(type = 'check_out') as outs")
            ->groupBy('user_id')->get()->keyBy('user_id');

        // ═══ عناوين العملاء المتأكّدة — ١٧ أغسطس ٢٠٢٦ ═══
        // ⚠️⚠️ **العدّ على `location_confirmed_at` مش على الإرسال**، والشهر
        // من تاريخ التأكيد — المكافأة على عنوان صح الأدمن راجعه، مش على ضغطة زرار.
        $locations = Client::whereIn('location_submitted_by', $ids)
            ->whereNotNull('location_confirmed_at')
            ->whereBetween('location_confirmed_at', [$start, $end])
            ->selectRaw('location_submitted_by, COUNT(*) as n')
            ->groupBy('location_submitted_by')->pluck('n', 'location_submitted_by');

        $manual = RepPoint::whereIn('user_id', $ids)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('user_id, SUM(points) as p')
            ->groupBy('user_id')->pluck('p', 'user_id');

        // الشرايح مرة واحدة — نفس `CommissionTier::rateFor`: أعلى `min_pct` ≤ التحقيق
        $tiers = CommissionTier::orderByDesc('min_pct')->get(['min_pct', 'rate']);
        $rateFor = fn (float $ach) => (float) ($tiers->first(fn ($t) => (float) $t->min_pct <= $ach)?->rate ?? 0);

        $out = [];
        foreach ($reps as $rep) {
            $id = $rep->id;
            $i = $inv->get($id);
            $p = $po->get($id);
            $v = $visits->get($id);
            $e = $events->get($id);

            $out[$id] = self::compose($targets->get($id), [
                'inv_net' => (float) ($i->net ?? 0), 'inv_cash' => (float) ($i->cash ?? 0),
                'inv_credit' => (float) ($i->credit ?? 0), 'inv_n' => (int) ($i->n ?? 0),
                'inv_clients' => (int) ($i->clients ?? 0),
                'po_net' => (float) ($p->net ?? 0), 'po_gross' => (float) ($p->gross ?? 0),
                'po_cash' => (float) ($p->cash ?? 0),
                'returns' => (float) ($returns[$id] ?? 0),
                'pieces' => (int) ($pieces[$id] ?? 0),
                'visits' => (int) ($v->n ?? 0), 'avg_minutes' => (int) round((float) ($v->m ?? 0)),
                'new_clients' => (int) ($newClients[$id] ?? 0),
                'opens' => (int) ($e->opens ?? 0), 'starts' => (int) ($e->starts ?? 0),
                'ins' => (int) ($e->ins ?? 0), 'outs' => (int) ($e->outs ?? 0),
                'locations' => (int) ($locations[$id] ?? 0),
                'manual' => (int) ($manual[$id] ?? 0),
            ], $rateFor);
        }

        return $out;
    }

    /** المعادلات — مكان واحد بعد ما الأرقام الخام اتجمعت */
    private static function compose(?RepTarget $target, array $raw, \Closure $rateFor): array
    {
        $poCash = round($raw['po_cash'], 2);
        $poCredit = round(max(0, $raw['po_gross'] - $poCash), 2);

        // الصافي = فواتير + أوامر مسلَّمة − مرتجعات — والعمولة عليه،
        // فالسواق والسيلز اللي بيسلّم POs بقى بيتحاسب على شغله كله
        $netSales = round($raw['inv_net'] + $raw['po_net'] - $raw['returns'], 2);
        $visitCount = $raw['visits'];
        $newClients = $raw['new_clients'];
        $pieces = $raw['pieces'];

        // ═══ التحقيق والعمولة ═══
        $moneyTarget = (float) ($target?->money_target ?? 0);
        $achievement = $moneyTarget > 0 ? round($netSales / $moneyTarget * 100, 1) : 0.0;
        $rate = $rateFor($achievement);
        $commission = round($netSales * $rate, 2);

        // ⚠️ **القسمة على صفر محروسة.** الإعداد بيتكتب من شاشة
        // الحوافز، و«٠ عنوان لكل نقطة» قيمة يقدر حد يكتبها.
        $perPoint = max(1, (int) Setting::read('locations_per_point', '5'));
        $locationPoints = intdiv($raw['locations'], $perPoint)
            * (int) Setting::read('pts_per_locations', '1');

        // ═══ النقاط: أوتوماتيك مشتقة + يدوي مخزّن ═══
        $autoPoints = $visitCount * (int) Setting::read('pts_per_visit', '1')
            + $newClients * (int) Setting::read('pts_per_new_client', '10')
            + intdiv($pieces, 100) * (int) Setting::read('pts_per_100_pieces', '1')
            + $locationPoints;
        $manualPoints = $raw['manual'];
        $points = $autoPoints + $manualPoints;
        $pointValue = (float) Setting::read('point_value', '5');

        $pct = fn (float $done, float $t) => $t > 0 ? round($done / $t * 100, 1) : 0.0;

        return [
            'target' => $target,
            'net_sales' => $netSales,
            // الكاش/الآجل بالإجمالي المدفوع (`grand_total`) — فواتيره
            // + أوامره: كاش الأمر من قيد التحصيل، وآجله الباقي
            'cash_sales' => round($raw['inv_cash'] + $poCash, 2),
            'credit_sales' => round($raw['inv_credit'] + $poCredit, 2),
            'returns_net' => round($raw['returns'], 2),
            'invoices' => $raw['inv_n'],
            'clients_sold' => $raw['inv_clients'],
            'pieces' => $pieces,
            'visits' => $visitCount,
            'avg_visit_minutes' => $raw['avg_minutes'],
            'new_clients' => $newClients,
            // العناوين المتأكّدة ونقطها — الشاشة بتوري الاتنين عشان
            // المندوب يعرف فاضله كام عنوان للنقطة الجاية
            'locations_confirmed' => $raw['locations'],
            'location_points' => $locationPoints,
            'app_opens' => $raw['opens'] + $raw['starts'],
            'check_ins' => $raw['ins'],
            'check_outs' => $raw['outs'],
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
     * كيلومترات يوم واحد — مجموع المسافات بين نقاط التراك المتتالية
     * بعد فلتر الشوشرة (`cleanKm`).
     */
    public static function kmForDay(User $rep, Carbon $day): float
    {
        return self::kmForDayMany(collect([$rep]), $day)[$rep->id];
    }

    /**
     * نفس `kmForDay` لمجموعة مناديب بكويري واحدة (١٧/١٠) — شاشة الأداء
     *
     * @param  iterable<User>  $reps
     * @return array<int, float> بالـ`id`
     */
    public static function kmForDayMany(iterable $reps, Carbon $day): array
    {
        $ids = collect($reps)->pluck('id')->all();
        $byUser = $ids === [] ? collect() : TrackEvent::whereIn('user_id', $ids)
            ->whereDate('happened_at', $day->toDateString())
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderBy('happened_at')
            ->get(['user_id', 'lat', 'lng', 'happened_at'])
            ->groupBy('user_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = self::cleanKm(($byUser->get($id) ?? collect())->map(fn ($p) => [
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'at' => $p->happened_at,
            ])->values()->all());
        }

        return $out;
    }

    /**
     * ═══ فلتر شوشرة الـGPS (بلاغ المالك ١٢/٨: «حسابات الكيلو غلط») ═══
     *
     * الجمع الخام كان بيحسب رعشة الجهاز وهو واقف مشاوير، وقفزات
     * الإحداثيات سرعات خرافية. القطعة بين كل نقطتين متتاليتين بتتداس لو:
     *
     *   • أقصر من 15 متر — رعشة GPS مش حركة عربية
     *   • سرعتها المحسوبة أكبر من 120 كم/س — قفزة إحداثيات مش مشوار
     *   • أطول من 5 كم — تيليبورت (الحارس القديم، فاضل زي ما هو)
     *   • فرق الوقت صفر — نقطتين بنفس الطابع الزمني
     *   • خارج نافذة الشغل لو اتبعتت (`$from`/`$to`) — الشاشة اللايف
     *     بتبعت أول حضور وآخر انصراف، فمشوار ما قبل التشيك إن وما
     *     بعد الانصراف مش بيتحسب شغل
     *
     * ⚠️ **عرض فقط** — الرقم مؤشر أداء ومابيدخلش في أي قيد أو تصفية.
     *
     * @param  list<array{lat: float, lng: float, at: \Carbon\CarbonInterface}>  $points  ترتيب زمني تصاعدي
     */
    public static function cleanKm(array $points, ?\Carbon\CarbonInterface $from = null, ?\Carbon\CarbonInterface $to = null): float
    {
        $km = 0.0;
        $prev = null;

        foreach ($points as $p) {
            if ($from !== null && $p['at']->lt($from)) {
                continue;
            }

            if ($to !== null && $p['at']->gt($to)) {
                break;
            }

            if ($prev === null) {
                $prev = $p;

                continue;
            }

            $d = self::haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
            // ⚠️ Carbon 3: الفرق ممكن يرجع سالب — القيمة المطلقة دايماً
            $secs = abs($prev['at']->diffInSeconds($p['at']));

            if ($secs <= 0) {
                // نفس الطابع الزمني — نقطة مكررة، بنتخطاها من غير
                // ما نحرك المرساة
                continue;
            }

            if ($d > 5 || ($d / ($secs / 3600)) > 120) {
                // قفزة إحداثيات — بندوسها **وبنعيد المرساة** عند
                // النقطة الجديدة، وإلا كل القطع الجاية هتتقاس من
                // مكان غلط وتترفض للأبد
                $prev = $p;

                continue;
            }

            if ($d < 0.015) {
                // ⚠️ رعشة — بنتخطى **من غير ما نحرك المرساة**: حركة
                // بطيئة حقيقية (زحمة، مشي) خطواتها الصغيرة بتتراكم
                // لحد ما تعدي الـ15 متر فتتحسب — مش بتتداس واحدة واحدة
                continue;
            }

            $km += $d;
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
