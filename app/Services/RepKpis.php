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

        // ═══ أوامر التوريد المسلَّمة — من القيود (إصلاح ١١/٨ مساءً) ═══
        //
        // ⚠️ العقيدة: **مبيعات المندوب = فواتيره (user_id) + أوامر
        // التوريد المسلَّمة (assigned_to)؛ مبيعات العميل = قيوده؛
        // التارجيت بالعميل.** الخدمة دي كانت بتقرا الفواتير بس —
        // فالآجل المسلَّم بأمر توريد (طلب بضاعة اتحوّل PO واتسلّم)
        // كان صفر هنا وهو ظاهر في التصفية، والعمولة بتضيع على
        // المندوب. نفس منطق `RepSettlementController::openFigures`:
        // **من `transactions` مش من `purchase_orders`** — القيد هو
        // مصدر الحقيقة، وقيد الـ`collection` هو اللي بيفرّق الكاش
        // من الآجل (الصافي = `debit − tax` زي قيود المرتجع تحت).
        $po = Transaction::where('source_type', PurchaseOrder::class)
            ->whereIn('source_id', PurchaseOrder::where('assigned_to', $rep->id)->select('id'))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'sale' THEN debit - tax ELSE 0 END),0) as net,
                         COALESCE(SUM(CASE WHEN kind = 'sale' THEN debit ELSE 0 END),0) as gross,
                         COALESCE(SUM(CASE WHEN kind = 'collection' THEN credit ELSE 0 END),0) as cash")
            ->first();

        $poCash = round((float) $po->cash, 2);
        $poCredit = round(max(0, (float) $po->gross - $poCash), 2);

        // مرتجعات المندوب — قيود return على زياراته، صافي بعد طرح الضريبة
        $returns = Transaction::where('kind', 'return')
            ->where('source_type', Visit::class)
            ->whereIn('source_id', Visit::where('user_id', $rep->id)->select('id'))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(credit - tax),0) as net')
            ->value('net');

        // الصافي = فواتير + أوامر مسلَّمة − مرتجعات — والعمولة عليه،
        // فالسواق والسيلز اللي بيسلّم POs بقى بيتحاسب على شغله كله
        $netSales = round((float) $inv->net + (float) $po->net - (float) $returns, 2);

        // ═══ القطع — بنود فواتيره ═══
        // ⚠️ عن قصد من الفواتير بس (قرار ١١/٨): قطع أوامر التوريد
        // بتتحاسب في مطابقة العهدة، وضمها هنا كان هيحرّك نقاط
        // وتارجيت القطع بأثر رجعي من غير قرار من المالك. الفلوس
        // فوق اتوحّدت — العدّ لأ.
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

        // ═══ عناوين العملاء المتأكّدة — ١٧ أغسطس ٢٠٢٦ ═══
        //
        // طلب المالك: «مع كل مثلاً ٥ عناوين تأكيد ياخد نقطة».
        //
        // ⚠️⚠️ **العدّ على `location_confirmed_at` مش على الإرسال.**
        // لو حسبنا الإرسال، المندوب بياخد نقط على مجرد إنه بعت نقطة
        // — يقدر يبعت لعشرين عميل في ساعة من غير ما يتحرك من مكانه.
        // النقطة بتتحسب لما **الأدمن يراجع ويأكّد**، فالمكافأة على
        // عنوان صح مش على ضغطة زرار.
        //
        // ⚠️ **الشهر من تاريخ التأكيد.** المندوب اللي بعت الشهر اللي
        // فات والأدمن أكّد الشهر ده، النقطة تتحسب في شهر التأكيد —
        // وإلا كنا هنعدّل نقط شهر مقفول كل ما الأدمن يفضّي طابور.
        $locationsConfirmed = Client::where('location_submitted_by', $rep->id)
            ->whereNotNull('location_confirmed_at')
            ->whereBetween('location_confirmed_at', [$start, $end])
            ->count();

        // ⚠️ **القسمة على صفر محروسة.** الإعداد بيتكتب من شاشة
        // الحوافز، و«٠ عنوان لكل نقطة» قيمة يقدر حد يكتبها.
        $perPoint = max(1, (int) Setting::read('locations_per_point', '5'));
        $locationPoints = intdiv($locationsConfirmed, $perPoint)
            * (int) Setting::read('pts_per_locations', '1');

        // ═══ النقاط: أوتوماتيك مشتقة + يدوي مخزّن ═══
        $autoPoints = $visitCount * (int) Setting::read('pts_per_visit', '1')
            + $newClients * (int) Setting::read('pts_per_new_client', '10')
            + intdiv($pieces, 100) * (int) Setting::read('pts_per_100_pieces', '1')
            + $locationPoints;
        $manualPoints = (int) RepPoint::where('user_id', $rep->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->sum('points');
        $points = $autoPoints + $manualPoints;
        $pointValue = (float) Setting::read('point_value', '5');

        $pct = fn (float $done, float $t) => $t > 0 ? round($done / $t * 100, 1) : 0.0;

        return [
            'target' => $target,
            'net_sales' => $netSales,
            // الكاش/الآجل بالإجمالي المدفوع (`grand_total`) — فواتيره
            // + أوامره: كاش الأمر من قيد التحصيل، وآجله الباقي
            'cash_sales' => round((float) $inv->cash + $poCash, 2),
            'credit_sales' => round((float) $inv->credit + $poCredit, 2),
            'returns_net' => round((float) $returns, 2),
            'invoices' => (int) $inv->n,
            'clients_sold' => (int) $inv->clients,
            'pieces' => $pieces,
            'visits' => $visitCount,
            'avg_visit_minutes' => $avgMinutes,
            'new_clients' => $newClients,
            // العناوين المتأكّدة ونقطها — الشاشة بتوري الاتنين عشان
            // المندوب يعرف فاضله كام عنوان للنقطة الجاية
            'locations_confirmed' => $locationsConfirmed,
            'location_points' => $locationPoints,
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
     * كيلومترات يوم واحد — مجموع المسافات بين نقاط التراك المتتالية
     * بعد فلتر الشوشرة (`cleanKm`).
     */
    public static function kmForDay(User $rep, Carbon $day): float
    {
        $points = TrackEvent::where('user_id', $rep->id)
            ->whereDate('happened_at', $day->toDateString())
            ->whereNotNull('lat')->whereNotNull('lng')
            ->orderBy('happened_at')
            ->get(['lat', 'lng', 'happened_at']);

        return self::cleanKm($points->map(fn ($p) => [
            'lat' => (float) $p->lat,
            'lng' => (float) $p->lng,
            'at' => $p->happened_at,
        ])->all());
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
