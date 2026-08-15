<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\ClientRequest;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * العمليات الميدانية — المناديب، العهدة، أوامر التوريد، موافقات العملاء، التراكينج
 */
class OpsController extends Controller
{
    // ================= لوحة العمليات =================

    public function dashboard()
    {
        // ⚠️ سكوب الفرع على لوحة العمليات
        $field = \App\Models\Branch::scope(
            User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES)->with('zone')),
        )->get();

        // ⚠️ أرقام اللوحة من نفس الفريق المعروض — للمدير ده فريقه بس،
        // وللأدمن كل الميدان. رقم فوق وكروت تحت من نطاقين = شاشة بتكدب.
        $teamIds = $field->pluck('id');

        // ⚠️ `grand_total` مش `total` (توحيد ١١/٨ مساءً): الكروت دي
        // «باع/سلّم بكام» — نفس عقيدة اللايف وعهد المناديب (اللي
        // العميل بيدفعه). قبل كده كانت صافي قبل الضريبة، فمجموع
        // صفوف المناديب تحت ماكانش بيطابق الكارت فوق.
        return view('ops.dashboard', [
            'field' => $field->map(fn ($u) => $this->userStats($u)),
            'todaySales' => Invoice::whereIn('user_id', $teamIds)->whereDate('created_at', today())->sum('grand_total'),
            'todayPos' => PurchaseOrder::whereIn('assigned_to', $teamIds)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->sum('grand_total'),
            'openRequests' => ClientRequest::whereIn('created_by', $teamIds)->whereIn('status', ['pending', 'review'])->count(),
            'visitsDone' => DB::table('visits')->whereIn('user_id', $teamIds)->whereDate('created_at', today())
                ->whereNotNull('checked_out_at')->count(),
            'events' => TrackEvent::with('user')->whereIn('user_id', $teamIds)->whereDate('happened_at', today())
                ->orderByDesc('happened_at')->take(30)->get(),
        ]);
    }

    private function userStats(User $u): array
    {
        $custody = $u->currentCustody();
        $custody?->load('items.product');

        // ⚠️ العقيدة: **مبيعات المندوب = فواتيره (user_id) + أوامر
        // التوريد المسلَّمة (assigned_to)؛ مبيعات العميل = قيوده؛
        // التارجيت بالعميل.** (إصلاح ١١/٨ مساءً) — `sales` كانت
        // فواتير بس وبالصافي، فالسيلز اللي طلب بضاعته اتحوّلت PO
        // واتسلّمت كان «أداء النهارده» بتاعه ناقص الآجل ده، والكارت
        // كان بيتفرّع سواق/غيره فمبيعات جنب فاتورة بتختفي. الرقم
        // الموحّد بالـ`grand_total` زي اللايف وعهد المناديب.
        $posValue = (float) PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
            ->whereDate('delivered_at', today())->sum('grand_total');

        return [
            'user' => $u,
            'custody' => $custody,
            'remaining' => $custody?->remainingUnits() ?? 0,
            'remainingValue' => $custody?->remainingValue($u->isDriver() ? 'old' : 'new') ?? 0,
            'sales' => round((float) Invoice::where('user_id', $u->id)
                ->whereDate('created_at', today())->sum('grand_total') + $posValue, 2),
            'visits' => $u->visits()->whereDate('created_at', today())->count(),
            'visitsDone' => $u->visits()->whereDate('created_at', today())->whereNotNull('checked_out_at')->count(),
            'pos' => PurchaseOrder::where('assigned_to', $u->id)->whereDate('created_at', today())->count(),
            'posDone' => PurchaseOrder::where('assigned_to', $u->id)->where('status', 'delivered')
                ->whereDate('delivered_at', today())->count(),
            // ⚠️ «قيمة التسليمات» = اللي السواق حصّله فعلاً، فبالإجمالي
            // شامل الضريبة. الصافي مكانه تقارير المبيعات.
            'posValue' => $posValue,
            'openVisit' => $u->openVisit(),
        ];
    }

    /** عدد صفوف كل جدول حركة في كارت المندوب — والباقي على «كل ...» */
    private const REP_ROWS = 15;

    /**
     * ═══════════════════════════════════════════════════════════════
     * كارت المندوب — كل حاجة عن شخص ميداني واحد (١٥ أغسطس ٢٠٢٦)
     * ═══════════════════════════════════════════════════════════════
     *
     * بلاغ المالك: «المباع 156 قطعة وأنا مش لاقي باعهم فين» + «خلي
     * الأرقام كليك-إبل: أدوس على المباع أعرف اتباعوا فين، وأدوس على
     * المحمَّل أعرف العهد بتاعتهم».
     *
     * ⚠️⚠️ **إجابة السؤال في `Custody::deduct`**: العمود
     * `custody_items.sold` بيتزوّد من **مكانين بس** —
     * `FieldApiController::storeInvoice` (فاتورة) و`::deliver`
     * (تسليم أمر توريد). يعني «المباع» **مش** «المتفوتر»: القطع
     * اللي خرجت بأمر توريد بتزوّد `sold` من غير أي سطر في
     * `invoice_items`، وده بالظبط سبب «باع 156 ومش لاقيهم».
     * الدريل داون بيفصل الاتنين — «مباع بفاتورة» و«مسلَّم بأمر
     * توريد» — زي مطابقة العهدة في التصفية بالحرف
     * (`RepSettlementController::goodsReconciliation`).
     *
     * ⚠️ **الشاشة دي بتتفتح باستمرار** — كل داتاسِت كويري مجمّعة
     * واحدة بمفتاح id، ومفيش كويري جوه أي لوب.
     *
     * ⚠️ **العهدة لايف والفلوس بالفترة.** العهدة من `currentCustody()`
     * (عقيدة ١٠/٨ — القفل بينهيها مش منتصف الليل)، والدريل داون
     * بتاعها بنافذة العهدة نفسها (`created_at` ← `closed_at`/دلوقتي).
     * فلاتر «من/إلى» بتحرّك أقسام الفلوس والزيارات بس، والليبل بيقول كده.
     */
    public function rep(Request $request, User $user)
    {
        // ⚠️ نفس القاعدة: الشاشة بتوري عهدة المندوب وفواتيره وتحركاته
        abort_unless($request->user()->canSeeBranch($user->branch_id), 403);
        // ⚠️ وسكوب التشانل مانجر — مندوب مش من فريقه مايتفتحش بالـid
        abort_unless($request->user()->role !== 'manager'
            || (int) $user->manager_id === (int) $request->user()->id, 403);

        [$fromD, $toD] = $this->boardWindow($request);
        $from = \Illuminate\Support\Carbon::parse($fromD)->startOfDay();
        $to = \Illuminate\Support\Carbon::parse($toD)->endOfDay();

        $user->loadMissing(['zone', 'branch', 'teamManager', 'channel']);

        $custody = $user->currentCustody();
        $custody?->load(['items.product', 'items.batch', 'warehouse', 'vehicle']);

        // ═══ العهدة + كل الدريل داونز — كويريز مجمّعة (تحت) ═══
        $drill = $this->custodyDrill($user, $custody);

        // ⚠️ نفس شرط الزرار في الفيو بالحرف — كتالوج الأصناف بيتحمّل
        // للديالوج بس، مش لكل واحد بيفتح الكارت
        $canAdjust = $custody !== null && $custody->status === 'open'
            && \App\Support\Access::action($request->user(), 'act.custody.adjust');

        // ═══════════ الفلوس في الفترة ═══════════
        // ⚠️ نفس عقيدة ١١/٨: **مبيعات المندوب = فواتيره (`user_id`)
        // + أوامر التوريد المسلَّمة (`assigned_to`)**، وبالـ`grand_total`
        // (اللي العميل بيدفعه). و`payment` الفاضية بتتحسب آجل — نفس
        // فلتر التصفية بالحرف.
        $invQ = Invoice::where('user_id', $user->id)->whereBetween('created_at', [$from, $to]);
        $invAgg = (clone $invQ)->selectRaw("COUNT(*) AS n,
            COALESCE(SUM(grand_total), 0) AS grand,
            COALESCE(SUM(CASE WHEN payment = 'cash' THEN grand_total ELSE 0 END), 0) AS cash,
            COALESCE(SUM(CASE WHEN payment = 'cash' THEN 0 ELSE grand_total END), 0) AS credit")
            ->first();
        $invoices = (clone $invQ)->with('client')->latest()->take(self::REP_ROWS)->get();

        $poQ = PurchaseOrder::where('assigned_to', $user->id)
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$from, $to]);
        $poAgg = (clone $poQ)->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS grand')->first();
        $pos = (clone $poQ)->with('client')->orderByDesc('delivered_at')->take(self::REP_ROWS)->get();

        // التحصيلات الميدانية — قيود `collection` بمرساة زيارات المندوب
        // (عقيدة ٩/٨: المرساة `source_type = Visit` مش عمود على القيد)
        $collQ = Transaction::where('kind', 'collection')
            ->where('source_type', \App\Models\Visit::class)
            ->whereIn('source_id', \App\Models\Visit::where('user_id', $user->id)->select('id'))
            ->whereBetween('created_at', [$from, $to]);
        $collAgg = (clone $collQ)->selectRaw("COUNT(*) AS n,
            COALESCE(SUM(credit), 0) AS total,
            COALESCE(SUM(CASE WHEN method = 'cash' THEN credit ELSE 0 END), 0) AS cash")
            ->first();
        $collections = (clone $collQ)->with('client')->latest()->take(self::REP_ROWS)->get();

        $retQ = \App\Models\ClientReturn::where('user_id', $user->id)
            ->whereBetween('created_at', [$from, $to]);
        $retAgg = (clone $retQ)->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS grand,
            COALESCE(SUM(good_units), 0) AS good, COALESCE(SUM(damaged_units), 0) AS damaged')->first();
        $returns = (clone $retQ)->with('client')->latest()->take(self::REP_ROWS)->get();

        // ═══════════ الميدان في الفترة ═══════════
        $visitQ = \App\Models\Visit::where('user_id', $user->id)
            ->whereBetween('checked_in_at', [$from, $to]);
        $visitAgg = (clone $visitQ)->selectRaw('COUNT(*) AS n,
            COALESCE(SUM(CASE WHEN checked_out_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS done,
            COUNT(DISTINCT client_id) AS clients')->first();
        $visits = (clone $visitQ)->with('client')->latest('checked_in_at')->take(self::REP_ROWS)->get();

        // نقاط التراك مرة واحدة — التايم لاين والكيلومترات من نفس القراية
        $points = TrackEvent::where('user_id', $user->id)
            ->whereBetween('happened_at', [$from, $to])
            ->orderBy('happened_at')
            ->get(['id', 'type', 'title', 'subtitle', 'lat', 'lng', 'happened_at']);

        // ⚠️ `cleanKm` هي المصدر الوحيد للكيلومترات (فلتر شوشرة الـGPS)
        $km = \App\Services\RepKpis::cleanKm(
            $points->filter(fn ($p) => $p->lat !== null && $p->lng !== null)
                ->map(fn ($p) => [
                    'lat' => (float) $p->lat,
                    'lng' => (float) $p->lng,
                    'at' => $p->happened_at,
                ])->values()->all(),
        );

        return view('ops.rep', [
            'u' => $user,
            'from' => $fromD,
            'to' => $toD,

            // ═══ الهيدر ═══
            'att' => \App\Models\AttendanceDay::with('punches')
                ->where('user_id', $user->id)->whereDate('date', today())->first(),
            'openVisit' => $user->openVisit(),

            // ═══ العهدة ═══
            'custody' => $custody,
            'drill' => $drill,
            // عرض فقط (١٢/٨): قيمة الباقي بكل قايمة مفعّلة + قايمة
            // المندوب المعتمدة (السواق قديمة والسيلز جديدة) لديالوج التعديل
            'custodyValues' => \App\Support\CustodyValue::remainingTotals($custody),
            'repList' => \App\Support\CustodyValue::listForRep($user),
            'products' => $canAdjust ? Product::orderBy('code')->get() : collect(),

            // ═══ الحركة ═══
            'invoices' => $invoices,
            'invAgg' => $invAgg,
            'pos' => $pos,
            'poAgg' => $poAgg,
            'collections' => $collections,
            'collAgg' => $collAgg,
            'returns' => $returns,
            'retAgg' => $retAgg,
            'transfers' => \App\Models\StockTransfer::with([
                'items.product', 'fromUser', 'toUser', 'toWarehouse', 'fromWarehouse',
            ])
                ->whereIn('kind', ['rep_wh', 'rep_rep'])
                ->where(fn ($q) => $q->where('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id))
                ->whereBetween('created_at', [$from, $to])
                ->latest()->take(self::REP_ROWS)->get(),
            'goods' => \App\Models\ReplenishmentRequest::with(['client', 'purchaseOrder'])
                ->where('requested_by', $user->id)
                ->whereBetween('created_at', [$from, $to])
                ->latest()->take(self::REP_ROWS)->get(),

            // ═══ الميدان ═══
            'visits' => $visits,
            'visitAgg' => $visitAgg,
            'outcomes' => \App\Support\VisitOutcomes::map($visits->pluck('id')->all()),
            'events' => $points->sortByDesc('happened_at')->take(10)->values(),
            'km' => $km,
            'plan' => \App\Services\Journeys::summary($user),
            'merch' => $user->isPromoter()
                ? \App\Models\MerchVisit::with('client')->where('user_id', $user->id)
                    ->whereBetween('created_at', [$from, $to])
                    ->latest()->take(self::REP_ROWS)->get()
                : collect(),

            // ═══ الأداء والتصفيات ═══
            // ⚠️ التارجيت شهري — الشهر بتاع «إلى»، والليبل بيقوله
            'perf' => \App\Services\RepKpis::forMonth($user, \Illuminate\Support\Carbon::parse($toD)),
            'perfMonth' => \Illuminate\Support\Carbon::parse($toD)->format('Y-m'),
            // ⚠️ سكوب العملاء دايماً `visibleTo` — حتى في عدّاد
            'myClients' => Client::visibleTo(
                Client::where('rep_id', $user->id), $request->user(),
            )->count(),
            'settlements' => \App\Models\RepSettlement::where('user_id', $user->id)
                ->orderByDesc('to_at')->take(5)->get(),

            // ═══ مدير بيشتغل ميدان — فريقه كمان ═══
            'team' => $user->isManager()
                ? User::fieldVisibleTo(User::where('manager_id', $user->id)
                    ->where('active', true), $request->user())
                    ->orderBy('name')->get()
                : collect(),
        ]);
    }

    /**
     * ═══════════════════════════════════════════════════════════════
     * دريل داون العهدة — كل رقم في الجدول بيرجّع مستنداته
     * ═══════════════════════════════════════════════════════════════
     *
     * بيرجّع كوليكشنات جاهزة للعرض، كل صف فيها `pid` (الصنف) عشان
     * المودال يفلتر عليه في الفرونت من غير أي كويري تانية.
     *
     * ⚠️ **النافذة = نافذة العهدة نفسها** (من `created_at` لحد
     * `closed_at` أو دلوقتي) — مش «النهارده». العهدة المفتوحة من
     * تلات أيام باعت على مدار التلاتة، وقصر النافذة على النهارده كان
     * هيخلّي «المباع 156» يقابله سطرين بس والفرق يبان سحر.
     *
     * ⚠️ **مفيش إعادة حساب لأي رقم عنده خدمة**: الكميات من
     * `custody_items`، فلوس الفواتير من `invoice_items`، فلوس الأوامر
     * من بنود الأمر المخزّنة (`price`/`total`/`tax` وقت التسعير)،
     * وقيمة الباقي من `CustodyValue` (اللي بيقرا `Pricing`).
     *
     * @return array<string, mixed>
     */
    private function custodyDrill(User $rep, ?\App\Models\Custody $custody): array
    {
        $blank = [
            'from' => null, 'to' => null,
            'rows' => collect(),
            'loaded' => collect(), 'sold_inv' => collect(), 'sold_po' => collect(),
            'batches' => collect(), 'gifts' => collect(),
            'returns_wh' => collect(), 'returns_in' => collect(), 'transfers' => collect(),
            'totals' => [
                'assigned' => 0, 'gift_assigned' => 0, 'loaded' => 0, 'sold' => 0,
                'inv_qty' => 0, 'po_qty' => 0, 'gift_given' => 0, 'gift_left' => 0,
                'returned' => 0, 'transferred_out' => 0, 'remaining' => 0,
                'returned_in' => 0, 'damaged_in' => 0, 'diff' => 0, 'sold_gap' => 0,
            ],
        ];

        if ($custody === null) {
            return $blank;
        }

        $cFrom = $custody->created_at ?? $custody->date?->copy()->startOfDay() ?? today()->startOfDay();
        $cTo = $custody->closed_at ?? now();

        // ═══════════ ١. المحمَّل — أوامر التجهيز اللي حمّلت العربية ═══════════
        // ⚠️ `pick_orders.custody_id` بيتكتب في `handOver` — فده الرابط
        // المباشر بين البضاعة اللي في العربية والورقة اللي طلعتها من
        // الرف. و`purchase_order_id` هو اللي بيحدد المصدر (نفس فيصل
        // `handOver` بالحرف — طلب الريفيل بيتحوّل PO كمان).
        $picks = PickOrder::with(['items.product', 'items.batch', 'warehouse', 'purchaseOrder', 'picker'])
            ->where('custody_id', $custody->id)
            ->orderBy('handed_at')
            ->get();

        // ⚠️ **أرقام المستندات المرجعية دفعة واحدة** — `CustodySource`
        // بتعمل تلات كويريز ثابتة للعهدة كلها بدل `find()` لكل بند
        // (٤٠ صنف = ٤٠ كويري في لوب).
        //
        // ⚠️ وهي كمان اللي بتصلّح بلاغ المالك (١٥ أغسطس): «مكتوب
        // المصدر مش معروف وده أصلاً استلامه بريفرنس في العهد».
        // البنود الأقدم من عمود `source` كانت بتتقال `legacy`، مع إن
        // `pick_orders.custody_id` رابطها بإذن التسليم من زمان —
        // فالكلاس بيرجّع رقم الإذن الحقيقي (PCK-) بدل «غير محدد»،
        // وبيضيفه كمان للعهدة العادية الجديدة اللي `source_ref_id`
        // بتاعها صفر.
        $srcMap = \App\Support\CustodySource::forCustody($custody, $custody->items);

        $refOf = fn (\App\Models\CustodyItem $i) => $srcMap->refFor($i);

        // البضاعة اللي جت بتحويل من زميل — بند العهدة بيحمل رقم المستند
        $inRefs = $custody->items
            ->filter(fn ($i) => $i->sourceKey() === 'transfer')
            ->pluck('source_ref_id')->filter()->unique()->values()->all();

        $inTransfers = $inRefs === []
            ? collect()
            : \App\Models\StockTransfer::with(['items.product', 'fromUser', 'fromWarehouse'])
                ->whereIn('id', $inRefs)->orderBy('id')->get();

        $loaded = collect();

        foreach ($picks as $pick) {
            $src = $pick->purchase_order_id ? 'purchase_order' : 'custody';

            foreach ($pick->items as $it) {
                $got = (int) ($it->qty_received ?? 0);

                if ($got <= 0) {
                    continue;
                }

                // نفس قسمة `handOver`: الهدية بتتقص من المستلم فعلاً
                $gift = min((int) ($it->gift_qty ?? 0), $got);

                $loaded->push([
                    'pid' => (int) $it->product_id,
                    'product' => $it->product?->displayName(),
                    'doc' => $pick->number,
                    'kind' => 'pick',
                    'id' => $pick->id,
                    'ref' => $pick->purchaseOrder?->number,
                    'at' => $pick->handed_at ?? $pick->created_at,
                    'qty' => $got - $gift,
                    'gift' => $gift,
                    'batch' => $it->batch?->batch_no,
                    'expires' => $it->batch?->expires_on,
                    'place' => $pick->warehouse?->displayName(),
                    'by' => $pick->picker?->displayName(),
                    'source' => $src,
                ]);
            }
        }

        foreach ($inTransfers as $t) {
            foreach ($t->items as $it) {
                $loaded->push([
                    'pid' => (int) $it->product_id,
                    'product' => $it->product?->displayName(),
                    'doc' => $t->number,
                    'kind' => 'transfer',
                    'id' => $t->id,
                    'ref' => null,
                    'at' => $t->created_at,
                    'qty' => (int) $it->qty_sent,
                    'gift' => 0,
                    'batch' => $it->batch_no,
                    'expires' => $it->expires_on,
                    'place' => $t->fromUser?->displayName() ?? $t->fromWarehouse?->displayName(),
                    'by' => $t->fromUser?->displayName(),
                    'source' => 'transfer',
                ]);
            }
        }

        // ═══════════ ٢. المباع — الفواتير + تسليمات أوامر التوريد ═══════════
        // ⚠️⚠️ **دي الإجابة على «باع 156 فين».** `Custody::deduct`
        // بتزوّد `sold` في الحالتين، لكن الفاتورة بس هي اللي بتسيب
        // سطر في `invoice_items` — فتسليم الأمر كان بيختفي من أي شاشة
        // بتقرا الفواتير وحدها (نفس الحد الناقص اللي كسر معادلة
        // التصفية قبل تدقيق ٨/٨).
        $invoices = Invoice::with('client')
            ->where('user_id', $rep->id)
            ->whereBetween('created_at', [$cFrom, $cTo])
            ->orderBy('id')
            ->get();

        $soldInv = collect();

        if ($invoices->isNotEmpty()) {
            $byId = $invoices->keyBy('id');

            foreach (\App\Models\InvoiceItem::whereIn('invoice_id', $invoices->pluck('id'))
                ->orderBy('id')->get() as $l) {
                $inv = $byId->get($l->invoice_id);

                $soldInv->push([
                    'pid' => (int) $l->product_id,
                    'doc' => $inv?->number,
                    'id' => (int) $l->invoice_id,
                    'at' => $inv?->created_at,
                    'client' => $inv?->client?->displayName(),
                    'client_id' => $inv?->client_id,
                    'cash' => $inv?->payment === 'cash',
                    'qty' => (int) $l->qty,
                    'price' => (float) $l->price,
                    // ⚠️ اللي العميل دفعه على السطر = الصافي + ضريبته
                    'total' => round((float) $l->total + (float) $l->tax, 2),
                ]);
            }
        }

        $soldPo = collect();

        $poDocs = PurchaseOrder::with(['client', 'items.product'])
            ->where('assigned_to', $rep->id)
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$cFrom, $cTo])
            ->orderBy('delivered_at')
            ->get();

        foreach ($poDocs as $po) {
            foreach ($po->items as $it) {
                // ⚠️ `delivered_qty` مش `qty` — التسليم الجزئي مسموح،
                // والخصم من العهدة بيحصل بالمسلَّم فعلاً
                $dq = (int) $it->delivered_qty;

                if ($dq <= 0) {
                    continue;
                }

                $full = $dq === (int) $it->qty;
                $net = $full ? (float) $it->total : round($dq * (float) $it->price, 2);
                $tax = $full
                    ? (float) $it->tax
                    : round($net * (float) ($it->tax_rate ?? 0), 2);

                $soldPo->push([
                    'pid' => (int) $it->product_id,
                    'doc' => $po->number,
                    'id' => $po->id,
                    'at' => $po->delivered_at,
                    'client' => $po->client?->displayName(),
                    'client_id' => $po->client_id,
                    'qty' => $dq,
                    'ordered' => (int) $it->qty,
                    'price' => (float) $it->price,
                    'total' => round($net + $tax, 2),
                ]);
            }
        }

        // ═══════════ ٣. الباقي — الباتشات وقيمتها بكل قايمة ═══════════
        $lists = \App\Support\CustodyValue::lists();

        $batches = $custody->items
            ->filter(fn ($i) => $i->remaining() > 0)
            ->map(fn ($i) => [
                'pid' => (int) $i->product_id,
                'batch' => $i->batchLabel(),
                'expires' => $i->batch?->expires_on,
                'days' => $i->batch?->daysLeft(),
                'state' => $i->expiryState(),
                'qty' => $i->remaining(),
                'source' => $srcMap->keyFor($i),
                'source_ref' => $refOf($i),
                'values' => $lists->mapWithKeys(fn ($l) => [
                    $l->id => round($i->remaining() * \App\Support\CustodyValue::priceIn($l, $i->product), 2),
                ])->all(),
            ])->values();

        // ═══════════ ٤. الهدايا — اللوج اللي كان مخفي ═══════════
        $gifts = \App\Models\GiftHandout::with(['product', 'client', 'clientRequest'])
            ->where('custody_id', $custody->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($g) => [
                'pid' => (int) $g->product_id,
                'at' => $g->created_at,
                'client' => $g->recipientName(),
                'client_id' => $g->client_id,
                'qty' => (int) $g->qty,
                'reason' => $g->reason,
                'note' => $g->note,
            ]);

        // ═══════════ ٥. التحويلات — رايح وجاي ═══════════
        $transfers = \App\Models\StockTransfer::with([
            'items.product', 'fromUser', 'toUser', 'toWarehouse', 'fromWarehouse',
        ])
            ->whereIn('kind', ['rep_wh', 'rep_rep'])
            ->where(fn ($q) => $q->where('from_user_id', $rep->id)->orWhere('to_user_id', $rep->id))
            ->whereBetween('created_at', [$cFrom, $cTo])
            ->orderBy('id')
            ->get();

        $trRows = collect();
        $retWh = collect();

        foreach ($transfers as $t) {
            $out = (int) $t->from_user_id === (int) $rep->id;
            $toWh = $t->kindKey() === 'rep_wh';

            foreach ($t->items as $it) {
                $row = [
                    'pid' => (int) $it->product_id,
                    'doc' => $t->number,
                    'id' => $t->id,
                    'at' => $t->created_at,
                    'out' => $out,
                    'kind' => $t->kindKey(),
                    'party' => $out
                        ? ($toWh ? $t->toWarehouse?->displayName() : $t->toUser?->displayName())
                        : ($t->fromUser?->displayName() ?? $t->fromWarehouse?->displayName()),
                    'qty' => (int) $it->qty_sent,
                    'batch' => $it->batch_no,
                    'reason' => $t->reason,
                ];

                $trRows->push($row);

                // «مرجّع للمخزن» = خانة `returned` بالظبط (`sendFromCustody`)
                if ($out && $toWh) {
                    $retWh->push($row);
                }
            }
        }

        // ═══════════ ٦. مرتجعات العملاء — بره المعادلة، جوه العربية ═══════════
        // ⚠️ `returned_in`/`damaged_in` مالهمش أصل في المحمَّل: دي
        // بضاعة العملاء اللي دخلت العربية وبتتسلّم مع التصفية.
        $retIn = collect();

        $retDocs = \App\Models\ClientReturn::with(['client', 'items.product'])
            ->where(fn ($q) => $q->where('custody_id', $custody->id)
                ->orWhere(fn ($w) => $w->where('user_id', $rep->id)
                    ->whereBetween('created_at', [$cFrom, $cTo])))
            ->orderBy('id')
            ->get();

        foreach ($retDocs as $doc) {
            foreach ($doc->items as $it) {
                $retIn->push([
                    'pid' => (int) $it->product_id,
                    'doc' => $doc->number,
                    'id' => $doc->id,
                    'at' => $doc->created_at,
                    'client' => $doc->client?->displayName(),
                    'client_id' => $doc->client_id,
                    'qty' => (int) $it->qty,
                    'condition' => $it->condition,
                    'total' => round((float) $it->total + (float) $it->tax, 2),
                ]);
            }
        }

        // ═══════════ ٧. صف المطابقة لكل صنف ═══════════
        // ⚠️ نفس معادلة `goodsReconciliation` بالحرف:
        //   المحمَّل (عادي + هدايا) = مباع + هدايا موزّعة + مرجّع للمخزن
        //                            + محوَّل + الباقي + هدايا فاضلة
        $rows = [];

        foreach ($custody->items as $it) {
            $pid = (int) $it->product_id;

            $rows[$pid] ??= [
                'pid' => $pid,
                'product' => $it->product,
                'sources' => [],
                'assigned' => 0, 'gift_assigned' => 0, 'sold' => 0,
                'returned' => 0, 'transferred_out' => 0, 'remaining' => 0,
                'gift_given' => 0, 'gift_left' => 0,
                'returned_in' => 0, 'damaged_in' => 0,
                'inv_qty' => 0, 'po_qty' => 0,
                'values' => [],
            ];

            $rows[$pid]['product'] ??= $it->product;
            $rows[$pid]['assigned'] += (int) $it->assigned;
            $rows[$pid]['gift_assigned'] += (int) $it->gift_assigned;
            $rows[$pid]['sold'] += (int) $it->sold;
            $rows[$pid]['returned'] += (int) $it->returned;
            $rows[$pid]['transferred_out'] += (int) $it->transferred_out;
            $rows[$pid]['remaining'] += $it->remaining();
            $rows[$pid]['gift_given'] += (int) $it->gift_given;
            $rows[$pid]['gift_left'] += $it->giftLeft();
            $rows[$pid]['returned_in'] += (int) $it->returned_in;
            $rows[$pid]['damaged_in'] += (int) $it->damaged_in;

            // شارة مصدر لكل مصدر موجود بالصنف ده، بكميته.
            // ⚠️ المفتاح من `CustodySource` مش من البند: البند القديم
            // بيقول `legacy` والخريطة بترقّيه لـ`custody` لما تلاقي
            // إذن تسليمه — والشارة لازم تتجمّع على المفتاح المعروض.
            $key = $srcMap->keyFor($it);
            $rows[$pid]['sources'][$key] ??= [
                'key' => $key,
                'class' => $srcMap->classFor($it),
                'label' => $srcMap->labelFor($it),
                'refs' => [],
                'qty' => 0,
            ];
            $rows[$pid]['sources'][$key]['qty'] += (int) $it->assigned + (int) $it->gift_assigned;

            // ⚠️ **المراجع بتتجمّع مش بتاخد أول واحد.** الصنف الواحد
            // ممكن يكون له أكتر من باتش، وكل باتش جه على إذن مختلف —
            // عرض أول إذن بس كان هيخفي الباقي. المفتاح رقم المستند
            // نفسه فالتكرار بيتشال لوحده.
            foreach ($srcMap->linksFor($it) as $lnk) {
                $rows[$pid]['sources'][$key]['refs'][$lnk['text']] ??= $lnk['url'];
            }
        }

        foreach ($soldInv as $l) {
            if (isset($rows[$l['pid']])) {
                $rows[$l['pid']]['inv_qty'] += $l['qty'];
            }
        }

        foreach ($soldPo as $l) {
            if (isset($rows[$l['pid']])) {
                $rows[$l['pid']]['po_qty'] += $l['qty'];
            }
        }

        $totals = $blank['totals'];

        foreach ($rows as $pid => $r) {
            $r['loaded'] = $r['assigned'] + $r['gift_assigned'];
            $r['accounted'] = $r['sold'] + $r['gift_given'] + $r['returned']
                + $r['transferred_out'] + $r['remaining'] + $r['gift_left'];
            $r['diff'] = $r['loaded'] - $r['accounted'];
            // ⚠️ **الفجوة اللي المالك سأل عنها**: المباع في العهدة
            // ناقص اللي لاقينا له مستند في نافذة العهدة. المفروض صفر —
            // وأي رقم هنا معناه مستند بره النافذة أو خصم بلا مستند.
            $r['sold_gap'] = $r['sold'] - $r['inv_qty'] - $r['po_qty'];

            $r['values'] = $lists->mapWithKeys(fn ($l) => [
                $l->id => round($r['remaining'] * \App\Support\CustodyValue::priceIn($l, $r['product']), 2),
            ])->all();

            $rows[$pid] = $r;

            foreach (['assigned', 'gift_assigned', 'loaded', 'sold', 'inv_qty', 'po_qty',
                'gift_given', 'gift_left', 'returned', 'transferred_out', 'remaining',
                'returned_in', 'damaged_in', 'diff', 'sold_gap'] as $k) {
                $totals[$k] += $r[$k];
            }
        }

        return [
            'from' => $cFrom,
            'to' => $cTo,
            'rows' => collect($rows)->sortByDesc('loaded')->values(),
            'loaded' => $loaded,
            'sold_inv' => $soldInv,
            'sold_po' => $soldPo,
            'batches' => $batches,
            'gifts' => $gifts,
            'returns_wh' => $retWh,
            'returns_in' => $retIn,
            'transfers' => $trRows,
            'totals' => $totals,
        ];
    }

    // ================= العهدة =================

    // ⚠️ `loadVan` (التحميل المباشر) **اتشال** (قرار المالك 2026-08-03):
    // كان بيجهّز ويسلّم في نفس الثانية من غير استلام المندوب من
    // الأبلكيشن — التحميل الرسمي بقى من فلو تسليم العهدة:
    // CustodyHandoutController::store ← تجهيز الطلبات ← تأكيد ← استلام.

    /**
     * ═══ عهد المناديب — بورد المراجعة بنظرة واحدة (١٠ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «كل المناديب وكل واحد معاه عهدة كام وحالتها دلوقتي
     * وباقي قد إيه — وأراجع ورا كل مندوب بنظرة واحدة».
     *
     * ⚠️ العهدة من `currentCustody()` — نفس عقيدة ١٠/٨: المفتوحة من
     * امبارح لسه شغالة، مش «عهدة النهارده» بس.
     * ⚠️ قيمة البضاعة بسعر البيع: السواق بقايمة `old` والسيلز بـ`new`
     * — نفس قاعدة كل الشاشات.
     */
    public function vans(Request $request)
    {
        $reps = User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))
            ->where('active', true)
            ->with('zone')
            ->orderBy('name')
            ->get();

        // ═══ «باع بكام النهارده» (إصلاح ١١/٨ مساءً) ═══
        // ⚠️ العقيدة: **مبيعات المندوب = فواتيره (user_id) + أوامر
        // التوريد المسلَّمة (assigned_to)؛ مبيعات العميل = قيوده؛
        // التارجيت بالعميل.** العمود هنا كان بيقرا الفواتير بس —
        // فالآجل اللي اتسلّم بأمر توريد (طلب بضاعة اتحوّل PO) كان
        // بيختفي من البورد ده وهو ظاهر في التصفية واللايف. نفس
        // إصلاح `liveRows` بالحرف، وبالـ`grand_total` (اللي العميل
        // بيدفعه — عقيدة الأرقام).
        $invToday = Invoice::whereIn('user_id', $reps->pluck('id'))
            ->whereDate('created_at', today())
            ->selectRaw('user_id, COALESCE(SUM(grand_total),0) as s')
            ->groupBy('user_id')->pluck('s', 'user_id');

        $poToday = PurchaseOrder::whereIn('assigned_to', $reps->pluck('id'))
            ->where('status', 'delivered')
            ->whereDate('delivered_at', today())
            ->selectRaw('assigned_to, COALESCE(SUM(grand_total),0) as s')
            ->groupBy('assigned_to')->pluck('s', 'assigned_to');

        $rows = $reps->map(function (User $u) use ($invToday, $poToday) {
            $c = $u->currentCustody();
            $c?->load(['items.product', 'items.batch', 'vehicle']);
            $mode = $u->isDriver() ? 'old' : 'new';

            $assigned = (int) ($c?->items->sum('assigned') ?? 0);
            $remaining = (int) ($c?->remainingUnits() ?? 0);
            $openVisit = $u->openVisit();

            return [
                'user' => $u,
                'custody' => $c,
                'state' => $c === null ? 'none' : ($c->status === 'closed' ? 'closed' : 'open'),
                'assigned' => $assigned,
                'assigned_value' => round($c?->assignedValue($mode) ?? 0, 2),
                'sold' => (int) ($c?->items->sum('sold') ?? 0),
                'returned' => (int) ($c?->items->sum('returned') ?? 0),
                // اتحوّل لعربية مندوب تاني (١٤/٨) — خرج من العربية زي
                // المرجّع بالظبط، ولو مابانش الصف بيقول «الباقي أقل»
                // من غير سبب ظاهر
                'transferred_out' => (int) ($c?->items->sum('transferred_out') ?? 0),
                // هدايا لسه معاه — الموزّع بيتخصم من المخصص
                'gifts_left' => (int) ($c?->items->sum(fn ($i) => max((int) $i->gift_assigned - (int) $i->gift_given, 0)) ?? 0),
                'remaining' => $remaining,
                'remaining_value' => round($c?->remainingValue($mode) ?? 0, 2),
                // ⚠️ عرض فقط (طلب المالك ١٢/٨): قيمة الباقي بكل قايمة
                // مفعّلة — «لو بالقديمة بكده ولو بالجديدة بكده».
                // القوايم ميمو للريكوست كله (CustodyValue) — مش كويري لكل صف.
                'values' => \App\Support\CustodyValue::remainingTotals($c),
                // نسبة التصريف — المخلَّص من المحمّل (بيع + مرتجع للمخزن)
                'pct' => $assigned > 0 ? (int) round(($assigned - $remaining) / $assigned * 100) : 0,
                'expiring' => $c?->expiringItems(30)->count() ?? 0,
                'active_client' => $openVisit?->client?->displayName(),
                'att' => \App\Services\Attendance::state($u),
                // فواتيره + أوامره المسلَّمة — من الكويريز المجمّعة فوق
                'sales_today' => round((float) ($invToday[$u->id] ?? 0)
                    + (float) ($poToday[$u->id] ?? 0), 2),
            ];
        });

        return view('ops.vans', [
            'rows' => $rows,
            'openCount' => $rows->where('state', 'open')->count(),
            'noneCount' => $rows->where('state', 'none')->count(),
            'streetValue' => $rows->where('state', 'open')->sum('remaining_value'),
            // تفصيلة الكارت: نفس القيمة بكل قايمة مفعّلة — عرض فقط
            'streetValues' => \App\Support\CustodyValue::merge($rows->where('state', 'open')->pluck('values')),
            'unitsLeft' => $rows->where('state', 'open')->sum('remaining'),
        ]);
    }

    /**
     * ═══════════════════════════════════════════════════════════════
     * الموعود مقابل المتاح  ·  ١٥ أغسطس ٢٠٢٦
     * ═══════════════════════════════════════════════════════════════
     *
     * طلب المالك بعد إصلاح الحجز: «المناديب اللي رصيدهم بايظ محتاجين
     * تسوية — عاوز أشوف مين متورّط قبل ما يقف قدام العميل».
     *
     * ═══ ليه الشاشة دي موجودة ═══
     *
     * حجز البضاعة (`Custody::committedFor`) بيمنع التورّط **من هنا
     * ورايح** — بس مابيصلّحش أمر اتوعد بيه فعلاً قبل الإصلاح. الشاشة
     * دي بتطلّع الفجوة القديمة: كل مندوب، كل صنف، الموجود في العربية
     * مقابل المتوعّد بيه لأوامر لسه مفتوحة.
     *
     * ═══ المعادلة ═══
     *
     *   المتاح   = المحمَّل − المباع − المرجَّع − المحوَّل   (`remaining()`)
     *   الموعود  = Σ (كمية البند − المسلَّم) لأوامر `pending`/`arrived`
     *   العجز    = الموعود − المتاح   ← لو موجب، ده اللي هيقف عند العميل
     *
     * ⚠️ **كل الأرقام بكويريز مجمّعة، مش لوب على المناديب.** النسخة
     * السهلة (`$rep->currentCustody()->committedFor()` جوه لوب) كانت
     * هتعمل كويريتين لكل صنف لكل مندوب — على ٢٠ مندوب × ٤٠ صنف دي
     * ١٦٠٠ كويري في صفحة واحدة.
     *
     * ⚠️ **العهدة المفتوحة بس** — نفس عقيدة `currentCustody()`: العهدة
     * بتعيش عبر الأيام والقفل هو اللي بينهيها، مش منتصف الليل. عهدة
     * مقفولة معناها المندوب صفّى، فمافيش رصيد يتوعد بيه.
     *
     * ⚠️ الصنف اللي **مالوش أي بند عهدة** بيظهر بمتاح صفر مش بيختفي —
     * ده أسوأ حالة أصلاً (اتوعد بحاجة مش معاه منها ولا قطعة).
     */
    public function commitments(Request $request)
    {
        $reps = User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))
            ->where('active', true)
            ->with('zone')
            ->orderBy('name')
            ->get();

        $repIds = $reps->pluck('id');

        if ($repIds->isEmpty()) {
            return view('ops.commitments', [
                'rows' => collect(), 'clean' => collect(),
                'repsAtRisk' => 0, 'unitsShort' => 0, 'ordersAtRisk' => 0,
            ]);
        }

        // ═══ ١. العهد المفتوحة — واحدة لكل مندوب (الأحدث) ═══
        $custodies = \App\Models\Custody::whereIn('user_id', $repIds)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'closed'))
            ->orderByDesc('date')->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        // ═══ ٢. المتاح: كويري واحدة لكل بنود العهد دي ═══
        // ⚠️ الباتش المنتهي **مستبعد** — نفس `Custody::availableFor`.
        // بضاعة منتهية الصلاحية مش رصيد يتوعد بيه عميل.
        $available = [];   // [user_id][product_id] => qty

        if ($custodies->isNotEmpty()) {
            // خريطة عهدة ← مندوب مرة واحدة، مش `firstWhere` جوه لوب
            // البنود (بتمشي على الكوليكشن كلها لكل بند).
            $ownerOf = $custodies->mapWithKeys(
                fn ($c) => [(int) $c->id => (int) $c->user_id],
            )->all();

            \App\Models\CustodyItem::with('batch')
                ->whereIn('custody_id', array_keys($ownerOf))
                ->get()
                ->each(function ($it) use ($ownerOf, &$available) {
                    if ($it->batch !== null && $it->batch->isExpired()) {
                        return;
                    }

                    $uid = $ownerOf[(int) $it->custody_id] ?? 0;

                    if ($uid <= 0) {
                        return;
                    }

                    $pid = (int) $it->product_id;
                    $available[$uid][$pid] = ($available[$uid][$pid] ?? 0) + $it->remaining();
                });
        }

        // ═══ ٣. الموعود: بنود الأوامر المفتوحة، كويري واحدة ═══
        $openPos = PurchaseOrder::with(['items', 'client'])
            ->whereIn('assigned_to', $repIds)
            ->whereIn('status', ['pending', 'arrived'])
            ->orderBy('due_at')
            ->get();

        $promised = [];    // [user_id][product_id] => qty
        $byProduct = [];   // [user_id][product_id] => [الأوامر المسببة]

        foreach ($openPos as $po) {
            $uid = (int) $po->assigned_to;

            foreach ($po->items as $it) {
                $left = max((int) $it->qty - (int) $it->delivered_qty, 0);

                if ($left <= 0) {
                    continue;
                }

                $pid = (int) $it->product_id;
                $promised[$uid][$pid] = ($promised[$uid][$pid] ?? 0) + $left;
                $byProduct[$uid][$pid][] = [
                    'id' => (int) $po->id,
                    'number' => (string) $po->number,
                    'client' => $po->client?->displayName(),
                    'qty' => $left,
                    'due' => $po->due_at,
                ];
            }
        }

        // ═══ ٤. الفجوة ═══
        $products = \App\Models\Product::whereIn(
            'id',
            collect($promised)->flatMap(fn ($p) => array_keys($p))->unique()->values(),
        )->get()->keyBy('id');

        $rows = collect();
        $clean = collect();

        foreach ($reps as $rep) {
            $uid = (int) $rep->id;
            $mine = $promised[$uid] ?? [];

            if ($mine === []) {
                continue;   // مالوش أوامر مفتوحة — مش موضوع الشاشة
            }

            $short = [];
            $okCount = 0;

            foreach ($mine as $pid => $need) {
                $have = (int) ($available[$uid][$pid] ?? 0);
                $gap = $need - $have;

                if ($gap <= 0) {
                    $okCount++;

                    continue;
                }

                $short[] = [
                    'product' => $products[$pid] ?? null,
                    'pid' => $pid,
                    'need' => $need,
                    'have' => $have,
                    'gap' => $gap,
                    'orders' => $byProduct[$uid][$pid] ?? [],
                ];
            }

            usort($short, fn ($a, $b) => $b['gap'] <=> $a['gap']);

            $entry = [
                'rep' => $rep,
                'custody' => $custodies[$uid] ?? null,
                'short' => $short,
                'ok_lines' => $okCount,
                'gap_units' => array_sum(array_column($short, 'gap')),
                'orders' => collect($short)->flatMap(fn ($s) => array_column($s['orders'], 'number'))
                    ->unique()->values()->all(),
            ];

            $short === [] ? $clean->push($entry) : $rows->push($entry);
        }

        $rows = $rows->sortByDesc('gap_units')->values();

        return view('ops.commitments', [
            'rows' => $rows,
            'clean' => $clean->values(),
            'repsAtRisk' => $rows->count(),
            'unitsShort' => $rows->sum('gap_units'),
            'ordersAtRisk' => $rows->flatMap(fn ($r) => $r['orders'])->unique()->count(),
        ]);
    }

    /**
     * ═══ مبيعات المناديب — بورد فلوس كل مندوب (١٢ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «زي عهد المناديب بالظبط بس للفلوس — كل مندوب باع
     * بكام كاش وآجل وحصّل بكام وبأنهي طريقة».
     *
     * ⚠️ العقيدة (١١/٨): **مبيعات المندوب = فواتيره (user_id) + أوامر
     * التوريد المسلَّمة (assigned_to)؛ مبيعات العميل = قيوده؛ التارجيت
     * بالعميل.** وفلوس الأوامر **من القيود مش من الأمر** — نفس كويريز
     * `RepSettlementController::figuresBetween` بالحرف: قيد `collection`
     * على الأمر = كاش، وقيد `sale` ناقص الكاش ده = آجل. قراية
     * `grand_total` من الأمر كانت هتعدّ الأمانة كمان.
     *
     * ⚠️ الكاش والآجل معروضين بتفصيلة «منها أوامر توريد: X» — نفس
     * اللبس اللي حصل في محضر مريم (31,767 = 29,045 فواتير + 2,722
     * أوامر): الرقم من غير التفصيلة بيبان متناقض مع شاشات الفواتير.
     */
    public function repSales(Request $request)
    {
        [$fromD, $toD] = $this->boardWindow($request);

        // ⚠️ نفس سكوب «عهد المناديب»: المدير بيشوف فريقه، والمحاسب
        // (fieldVisibleTo بتعدّيه من غير فلترة) بيشوف كل الميدان
        $reps = User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $m = $this->repMoneyMaps($reps->pluck('id'), $fromD, $toD);

        $rows = $reps->map(function (User $u) use ($m) {
            $inv = $m['inv'][$u->id] ?? null;
            $poCash = round((float) ($m['po_cash'][$u->id] ?? 0), 2);
            // الآجل = مدين الأوامر ناقص اللي اتحصّل نقدي وقت التسليم —
            // نفس معادلة التصفية بالظبط
            $poCredit = round(max(0, (float) ($m['po_sale'][$u->id] ?? 0) - $poCash), 2);

            $coll = $m['coll'][$u->id]
                ?? ['total' => 0.0, 'cash' => 0.0, 'transfer' => 0.0, 'cheque_card' => 0.0];
            $refunds = round((float) ($m['refund'][$u->id] ?? 0), 2);

            $cash = round((float) ($inv->cash ?? 0) + $poCash, 2);
            $credit = round((float) ($inv->credit ?? 0) + $poCredit, 2);

            return [
                'user' => $u,
                'inv_count' => (int) ($inv->cnt ?? 0),
                'cash' => $cash,
                'po_cash' => $poCash,
                'credit' => $credit,
                'po_credit' => $poCredit,
                'coll_cash' => $coll['cash'],
                'coll_transfer' => $coll['transfer'],
                'coll_cheque_card' => $coll['cheque_card'],
                // ⚠️ «غير النقدي» = الإجمالي − الكاش مش مجموع الطرق
                // المعروضة — طريقة شاذة ماتضيّعش فلوس من الكارت
                'coll_other' => round($coll['total'] - $coll['cash'], 2),
                'refunds' => $refunds,
                // نفس معادلة «المتوقع» في التصفية: كاش + تحصيل نقدي − مرتجعات كاش
                'net' => round($cash + $coll['cash'] - $refunds, 2),
            ];
        });

        return view('ops.rep_sales', [
            'rows' => $rows,
            'from' => $fromD,
            'to' => $toD,
            // ⚠️ الكروت من نفس كوليكشن الجدول — نطاق واحد، والفوتر
            // بيتحسب في البليد من نفس الصفوف
            'kpi' => [
                'cash' => round($rows->sum('cash'), 2),
                'credit' => round($rows->sum('credit'), 2),
                'coll_cash' => round($rows->sum('coll_cash'), 2),
                'coll_other' => round($rows->sum('coll_other'), 2),
                'refunds' => round($rows->sum('refunds'), 2),
            ],
        ]);
    }

    /**
     * ═══ بورد المناديب — عهدة + فلوس + حركة في نظرة (١٢ أغسطس ٢٠٢٦) ═══
     *
     * الدمج بين «عهد المناديب» و«مبيعات المناديب» واللايف: كل مندوب
     * صف واحد فيه حضوره، عهدته وباقيها ونسبة تصريفه، مبيعاته
     * (بنفس عقيدة ١١/٨)، تحصيلاته، زياراته، وآخر حركة له.
     *
     * ⚠️ **كل الداتاسِتس مجمّعة كويري واحدة** ومترجمة لخرايط بالـid —
     * البورد بيرسم كل الفريق، وكويري لكل صف كانت هتضرب الصفحة
     * بعشرات الكويريز.
     */
    public function repBoard(Request $request)
    {
        [$fromD, $toD] = $this->boardWindow($request);

        $reps = User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $repIds = $reps->pluck('id');
        $m = $this->repMoneyMaps($repIds, $fromD, $toD);

        // ═══ العهدة — النسخة المجمّعة من `currentCustody()` (عقيدة ١٠/٨) ═══
        // نفس الاختيار بالحرف: عهدة النهارده لو موجودة (حتى المقفولة —
        // يومه اتقفل)، وإلا آخر عهدة لسه مفتوحة من الأيام اللي فاتت.
        // ⚠️ `status != 'closed'` في MySQL بتستبعد NULL — الـwhereNull صريحة.
        $custodyByUser = \App\Models\Custody::with(['items.product'])
            ->whereIn('user_id', $repIds)
            ->where(fn ($q) => $q
                ->whereDate('date', today())
                ->orWhereNull('status')
                ->orWhere('status', '<>', 'closed'))
            ->get()
            ->groupBy('user_id')
            ->map(fn ($g) => $g->first(fn ($c) => $c->date->isToday())
                ?? $g->filter(fn ($c) => $c->status !== 'closed')
                    ->sortByDesc('date')->first());

        // ═══ الحضور — دفعة واحدة مش `Attendance::state` لكل صف ═══
        // ⚠️ `state()` بتعمل `firstOrCreate` + كويري بانشات لكل موظف —
        // على بورد بيرسم الفريق كله دي N+1 **بكتابة** كمان. بنقرا
        // أيام النهارده الموجودة بس، واللي مالوش يوم = off.
        $attDays = \App\Models\AttendanceDay::with('punches')
            ->whereDate('date', today())
            ->whereIn('user_id', $repIds)
            ->get()->keyBy('user_id');

        $attOf = function (int $uid) use ($attDays): string {
            // آخر بانش بالوقت ثم الـid — نفس ترتيب `lastPunch()`
            $last = $attDays->get($uid)?->punches
                ->sortBy([['at', 'asc'], ['id', 'asc']])->last();

            return match ($last?->type) {
                \App\Models\AttendancePunch::IN,
                \App\Models\AttendancePunch::BACK => 'working',
                \App\Models\AttendancePunch::BREAK => 'break',
                default => 'off',
            };
        };

        // زيارات النافذة: الكل + اللي اتقفلت — كويري واحدة مجمّعة
        $visitAgg = DB::table('visits')
            ->whereIn('user_id', $repIds)
            ->whereDate('created_at', '>=', $fromD)
            ->whereDate('created_at', '<=', $toD)
            ->selectRaw('user_id, COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN checked_out_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS done')
            ->groupBy('user_id')->get()->keyBy('user_id');

        // آخر حركة تراكينج في النافذة — MAX واحدة للكل
        $lastEvent = TrackEvent::whereIn('user_id', $repIds)
            ->whereDate('happened_at', '>=', $fromD)
            ->whereDate('happened_at', '<=', $toD)
            ->selectRaw('user_id, MAX(happened_at) AS t')
            ->groupBy('user_id')->pluck('t', 'user_id');

        $rows = $reps->map(function (User $u) use ($m, $custodyByUser, $attOf, $visitAgg, $lastEvent) {
            $c = $custodyByUser->get($u->id);
            // ⚠️ قيمة البضاعة بسعر البيع: السواق بقايمة `old` والسيلز
            // بـ`new` — نفس قاعدة «عهد المناديب» بالحرف
            $mode = $u->isDriver() ? 'old' : 'new';

            $assigned = (int) ($c?->items->sum('assigned') ?? 0);
            $remaining = (int) ($c?->remainingUnits() ?? 0);

            $inv = $m['inv'][$u->id] ?? null;
            $poCash = round((float) ($m['po_cash'][$u->id] ?? 0), 2);
            $poCredit = round(max(0, (float) ($m['po_sale'][$u->id] ?? 0) - $poCash), 2);
            $cash = round((float) ($inv->cash ?? 0) + $poCash, 2);
            $credit = round((float) ($inv->credit ?? 0) + $poCredit, 2);

            $coll = $m['coll'][$u->id]
                ?? ['total' => 0.0, 'cash' => 0.0, 'transfer' => 0.0, 'cheque_card' => 0.0];
            $v = $visitAgg->get($u->id);
            $t = $lastEvent->get($u->id);

            return [
                'user' => $u,
                'att' => $attOf($u->id),
                'custody' => $c,
                'state' => $c === null ? 'none' : ($c->status === 'closed' ? 'closed' : 'open'),
                'remaining' => $remaining,
                'remaining_value' => round($c?->remainingValue($mode) ?? 0, 2),
                // عرض فقط: قيمة الباقي بكل قايمة مفعّلة (طلب المالك ١٢/٨)
                'values' => \App\Support\CustodyValue::remainingTotals($c),
                // نسبة التصريف — المخلَّص من المحمّل، نفس معادلة «عهد المناديب»
                'pct' => $assigned > 0 ? (int) round(($assigned - $remaining) / $assigned * 100) : 0,
                'cash' => $cash,
                'credit' => $credit,
                'sales' => round($cash + $credit, 2),
                'coll_cash' => $coll['cash'],
                'coll_other' => round($coll['total'] - $coll['cash'], 2),
                'coll_total' => $coll['total'],
                'visits_done' => (int) ($v->done ?? 0),
                'visits_total' => (int) ($v->total ?? 0),
                'last_at' => $t ? \Illuminate\Support\Carbon::parse($t) : null,
            ];
        });

        return view('ops.rep_board', [
            'rows' => $rows,
            'from' => $fromD,
            'to' => $toD,
            // ⚠️ الكروت من نفس كوليكشن الجدول — نطاق واحد
            'kpi' => [
                'working' => $rows->where('att', 'working')->count(),
                'open_vans' => $rows->where('state', 'open')->count(),
                'sales' => round($rows->sum('sales'), 2),
                'collections' => round($rows->sum('coll_total'), 2),
            ],
        ]);
    }

    /** نافذة البوردات من الريكوست — من/إلى، والافتراضي النهارده */
    private function boardWindow(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = ($data['from'] ?? null) ? \Illuminate\Support\Carbon::parse($data['from']) : today();
        $to = ($data['to'] ?? null) ? \Illuminate\Support\Carbon::parse($data['to']) : today();

        // «إلى» قبل «من» = نقلبهم بدل جدول فاضي محيّر
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * فلوس المناديب دفعة واحدة — خرايط بالـid لكل داتاسِت.
     *
     * ⚠️ نفس مصادر `RepSettlementController::figuresBetween` بالحرف،
     * بس `GROUP BY` على المندوب عشان البوردات بترسم الفريق كله.
     *
     * - فواتير المندوب: كاش/آجل بالـ`grand_total` (اللي العميل بيدفعه)
     * - أوامر التوريد: **من القيود** — `collection` على الأمر = كاش،
     *   و`sale` = مدين الأمر (الآجل بيتحسب عند العرض: مدين − كاش)
     * - التحصيلات الميدانية: قيود `collection` بمرساة زيارات المندوب
     *   مقسومة بالطريقة (`METHOD_*`)
     * - مرتجعات الكاش: قيود `refund` بالمرساتين بتوع التصفية —
     *   زيارة المندوب (مستند الأبلكيشن) + `ClientReturn.user_id`
     *   (مستند الـERP اللي مالوش زيارة)
     */
    private function repMoneyMaps($repIds, string $fromD, string $toD): array
    {
        // فواتيره — NULL بيتحسب آجل زي فلتر التصفية (`payment !== 'cash'`)
        $inv = Invoice::whereIn('user_id', $repIds)
            ->whereDate('created_at', '>=', $fromD)
            ->whereDate('created_at', '<=', $toD)
            ->selectRaw("user_id, COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN payment = 'cash' THEN grand_total ELSE 0 END), 0) AS cash,
                COALESCE(SUM(CASE WHEN payment = 'cash' THEN 0 ELSE grand_total END), 0) AS credit")
            ->groupBy('user_id')->get()->keyBy('user_id');

        // قيود أوامر التوريد — مجمّعة على مندوب الأمر (assigned_to)
        $poBase = fn (string $kind) => Transaction::where('transactions.kind', $kind)
            ->where('transactions.source_type', PurchaseOrder::class)
            ->join('purchase_orders', 'purchase_orders.id', '=', 'transactions.source_id')
            ->whereIn('purchase_orders.assigned_to', $repIds)
            ->whereDate('transactions.created_at', '>=', $fromD)
            ->whereDate('transactions.created_at', '<=', $toD)
            ->groupBy('purchase_orders.assigned_to');

        $poCash = $poBase('collection')
            ->selectRaw('purchase_orders.assigned_to AS uid, COALESCE(SUM(transactions.credit), 0) AS v')
            ->pluck('v', 'uid');

        $poSale = $poBase('sale')
            ->selectRaw('purchase_orders.assigned_to AS uid, COALESCE(SUM(transactions.debit), 0) AS v')
            ->pluck('v', 'uid');

        // التحصيلات الميدانية بمرساة الزيارة — مقسومة بالطريقة
        $collRows = Transaction::where('transactions.kind', 'collection')
            ->where('transactions.source_type', \App\Models\Visit::class)
            ->join('visits', 'visits.id', '=', 'transactions.source_id')
            ->whereIn('visits.user_id', $repIds)
            ->whereDate('transactions.created_at', '>=', $fromD)
            ->whereDate('transactions.created_at', '<=', $toD)
            ->selectRaw('visits.user_id AS uid, transactions.method AS m,
                COALESCE(SUM(transactions.credit), 0) AS v')
            ->groupBy('visits.user_id', 'transactions.method')
            ->get();

        $coll = [];

        foreach ($collRows as $r) {
            $uid = (int) $r->uid;
            $coll[$uid] ??= ['total' => 0.0, 'cash' => 0.0, 'transfer' => 0.0, 'cheque_card' => 0.0];
            $v = round((float) $r->v, 2);

            $coll[$uid]['total'] = round($coll[$uid]['total'] + $v, 2);

            if ($r->m === Transaction::METHOD_CASH) {
                $coll[$uid]['cash'] = round($coll[$uid]['cash'] + $v, 2);
            } elseif ($r->m === Transaction::METHOD_TRANSFER) {
                $coll[$uid]['transfer'] = round($coll[$uid]['transfer'] + $v, 2);
            } elseif (in_array($r->m, [Transaction::METHOD_CHEQUE, Transaction::METHOD_CARD], true)) {
                $coll[$uid]['cheque_card'] = round($coll[$uid]['cheque_card'] + $v, 2);
            }
        }

        // مرتجعات الكاش — مرساة الزيارة (مستند الأبلكيشن)
        $refVisit = Transaction::where('transactions.kind', 'refund')
            ->where('transactions.source_type', \App\Models\Visit::class)
            ->join('visits', 'visits.id', '=', 'transactions.source_id')
            ->whereIn('visits.user_id', $repIds)
            ->whereDate('transactions.created_at', '>=', $fromD)
            ->whereDate('transactions.created_at', '<=', $toD)
            ->selectRaw('visits.user_id AS uid, COALESCE(SUM(transactions.debit), 0) AS v')
            ->groupBy('visits.user_id')->pluck('v', 'uid');

        // ⚠️ ومرتجعات الكاش اللي اتسجّلت من الـERP على المندوب —
        // مستند الـERP مالوش زيارة فقيده مرساته `ClientReturn` (تدقيق
        // ٨/٨ في التصفية). والجدول اسمه `returns` — كلمة محجوزة:
        // الـbuilder بيحط الباك تيكس في join/groupBy، والـselectRaw
        // متكتبة بالباك تيكس بإيدنا.
        $refErp = Transaction::where('transactions.kind', 'refund')
            ->where('transactions.source_type', \App\Models\ClientReturn::class)
            ->join('returns', 'returns.id', '=', 'transactions.source_id')
            ->whereIn('returns.user_id', $repIds)
            ->whereDate('transactions.created_at', '>=', $fromD)
            ->whereDate('transactions.created_at', '<=', $toD)
            ->selectRaw('`returns`.user_id AS uid, COALESCE(SUM(transactions.debit), 0) AS v')
            ->groupBy('returns.user_id')->pluck('v', 'uid');

        $refund = [];

        foreach ($refVisit as $uid => $v) {
            $refund[(int) $uid] = round((float) $v, 2);
        }

        foreach ($refErp as $uid => $v) {
            $refund[(int) $uid] = round(($refund[(int) $uid] ?? 0) + (float) $v, 2);
        }

        return [
            'inv' => $inv,
            'po_cash' => $poCash,
            'po_sale' => $poSale,
            'coll' => $coll,
            'refund' => $refund,
        ];
    }

    /**
     * ═══ الزيارات المفتوحة — مين عامل «إن» فين دلوقتي (١١ أغسطس ٢٠٢٦) ═══
     *
     * طلب المالك: «أشوف المندوب عامل إن فين وواقف فين، وأقدر أعمله
     * Out من الداش بورد». كل زيارة عميل مفتوحة (أياً كان يومها) +
     * زيارات المخزن المفتوحة — بزرار إخراج إداري لكل واحدة.
     */
    public function openVisits(Request $request)
    {
        $teamIds = User::fieldVisibleTo(User::query(), $request->user())->select('id');

        // ⏱ اللي لسه مسجّلين حضور (١١/٨ مساءً) — يوم النهارده المفتوح
        // بحالة working/break. ⚠️ الحضور مش للميدان بس (المكتب بيبصم
        // كمان)، فالسكوب على **كل** الموظفين النشطين — والمدير بيشوف
        // فريقه من fieldVisibleTo زي باقي الشاشة.
        $attRows = \App\Models\AttendanceDay::whereDate('date', today())
            ->where('status', \App\Models\AttendanceDay::STATUS_OPEN)
            ->whereIn('user_id', User::fieldVisibleTo(
                User::where('active', true), $request->user(),
            )->select('id'))
            ->with('user')
            ->get()
            ->filter(fn ($d) => $d->user !== null
                && in_array($d->state(), ['working', 'break'], true))
            ->map(fn ($d) => [
                'day' => $d,
                'user' => $d->user,
                'state' => $d->state(),
                // الشغل المفتوح بيتعرض كتنبيه بس — الانصراف الإداري
                // بيعدّي من غير الحارس، وقفل الشغل نفسه من الكروت فوق
                'open' => \App\Services\Attendance::openWork($d->user),
            ])
            ->values();

        return view('ops.open_visits', [
            'visits' => \App\Models\Visit::whereNull('checked_out_at')
                ->whereIn('user_id', $teamIds)
                ->with(['user', 'client'])
                ->orderBy('checked_in_at')
                ->get(),
            'whVisits' => \App\Models\WarehouseVisit::whereNull('checked_out_at')
                ->whereIn('user_id', $teamIds)
                ->with(['user', 'warehouse'])
                ->orderBy('checked_in_at')
                ->get(),
            'attRows' => $attRows,
        ]);
    }

    /**
     * إخراج إداري من زيارة عميل — بيقفلها دلوقتي، بيسجل في التراكينج
     * إن القفل إداري ومين اللي قفل، وبيبلّغ المندوب بإشعار.
     */
    public function forceCheckOut(Request $request, \App\Models\Visit $visit)
    {
        abort_unless($visit->checked_out_at === null, 404);
        // ⚠️ مدير القناة بيخرّج **فريقه** بس — نفس حارس كل الشاشات
        Scope::assertStaff($request->user(), $visit->user);

        // ⚠️ **بنقفل كل الزيارات المفتوحة للمندوب مش الصف ده بس**
        // (إصلاح ١١/٨): لو حصل ازدواج (زيارتين لنفس اليوم من ريتراي
        // على شبكة ضعيفة)، إخراج واحدة كان بيسيب التانية والزيارة
        // «تطلع تاني» بعد الريفريش. القفلة الجماعية بتخلص الحالة كلها.
        \App\Models\Visit::where('user_id', $visit->user_id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        \App\Models\TrackEvent::log($visit->user, 'check_out',
            __('field.event_check_out', ['client' => $visit->client?->displayName() ?? '—']),
            __('ops.forced_out_by', ['by' => $request->user()->displayName()]),
            $visit->lat ?? $visit->client?->lat,
            $visit->lng ?? $visit->client?->lng);

        // المندوب لازم يعرف — شاشته هتتغير فجأة ومن غير الإشعار
        // هيفتكر الأبلكيشن باظ
        AppNotification::send(
            $visit->user,
            fn () => __('field.notif_forced_out_title'),
            fn () => __('field.notif_forced_out_body', [
                'client' => $visit->client?->displayName() ?? '—',
                'by' => $request->user()->displayName(),
            ]),
            good: false,
        );

        return back()->with('ok', __('ops.ov_closed_ok', [
            'rep' => $visit->user?->displayName() ?? '—',
        ]));
    }

    /** إخراج إداري من زيارة مخزن — نفس الفكرة */
    public function forceWarehouseOut(Request $request, \App\Models\WarehouseVisit $whVisit)
    {
        abort_unless($whVisit->checked_out_at === null, 404);
        Scope::assertStaff($request->user(), $whVisit->user);

        \App\Services\WarehouseVisits::close($whVisit);

        return back()->with('ok', __('ops.ov_closed_ok', [
            'rep' => $whVisit->user?->displayName() ?? '—',
        ]));
    }

    /**
     * ═══ الانصراف الإداري — تشيك أوت للشغل نفسه (١١ أغسطس ٢٠٢٦ مساءً) ═══
     *
     * طلب المالك: «أعمل للناس من عندي تشيك أوت للشغل زي ما عملنا في
     * الزيارات المفتوحة — وأنا بخرّجه بحدد عدد ساعات العمل».
     *
     * الساعات بتتعتمد على اليوم (`approved_minutes`) فهي اللي بتروح
     * المرتبات — والبانش نفسه بيتعلّم `forced_by` فالسجل بيفضل شاهد.
     * البانش المباشر بيعدّي من غير حارس `openWork` عن قصد (قرار
     * إداري زي `autoClose`) — والشغل المفتوح باين في نفس الصف كتنبيه.
     */
    public function forceAttendanceOut(Request $request, User $user)
    {
        $data = $request->validate([
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        // ⚠️ نفس حارس شاشة الحضور — الساعات بتتحول فلوس، والمدير
        // بيخرّج **فريقه** بس (والحضور بيشمل موظفين مكتب: assertStaff)
        Scope::assertStaff($request->user(), $user);

        $minutes = (int) round((float) $data['hours'] * 60);

        $err = \App\Services\Attendance::forceOut(
            $user, $request->user(), $minutes, $data['note'] ?? null,
        );

        if ($err !== null) {
            return back()->withErrors($err);
        }

        // نفس فيد التتبع بتاع أي انصراف — بس معلّم إنه إداري
        TrackEvent::log($user, 'shift_out', __('hr.punch_out'),
            __('ops.forced_out_by', ['by' => $request->user()->displayName()]));

        // الموظف لازم يعرف — عدّاده هيقف فجأة ومن غير الإشعار
        // هيفتكر الأبلكيشن باظ
        AppNotification::send(
            $user,
            fn () => __('hr.notif_forced_att_title'),
            fn () => __('hr.notif_forced_att_body', [
                't' => \App\Models\AttendanceDay::hhmm($minutes),
                'by' => $request->user()->displayName(),
            ]),
            good: false,
        );

        return back()->with('ok', __('ops.att_forced_ok', [
            'rep' => $user->displayName(),
            't' => \App\Models\AttendanceDay::hhmm($minutes),
        ]));
    }

    public function closeCustody(Request $request, User $user)
    {
        // ⚠️ **كان بلا حارس** — أي مدير بيقفل يوم أي مندوب في الشركة،
        // والمندوب بيلاقي عهدته اتقفلت وهو لسه في الشارع.
        Scope::assertRep($request->user(), $user);

        $custody = $user->currentCustody();
        $custody?->update(['status' => 'closed', 'closed_at' => now()]);

        return back()->with('ok', __('flash.van_closed'));
    }

    /**
     * ═══ تصحيح إداري لعهدة مندوب — «التحميل اتسجّل غلط» (١٢/٨/٢٠٢٦) ═══
     *
     * الراوت `role:admin` — قرار المالك: التصحيح ده بيحرّك مخزون حقيقي
     * (العهدة والأرفف مع بعض)، فمش مفتوح للمديرين افتراضياً. الزرار
     * نفسه محكوم بـ`act.custody.adjust` (أدمن بس، والأدمن يقدر يمنحه).
     *
     * كل الحركة في `Custody::adjustTo` — الأرضية (مباع + مرجّع) هناك،
     * والمخزن بيتظبط بنفس مسارات التحميل والإرجاع الموجودة. هنا بس:
     * فاليديشن + سبب إجباري + تسجيل الحدث + إشعار المندوب.
     */
    public function adjustCustody(Request $request, User $user)
    {
        // ⚠️ الراوت `role:admin`، بس المنح الصريح للأكشن بيفتحه —
        // فالحارس لازم يفضل: لو الأدمن منح الأكشن لمدير، يظبط عهد
        // **فريقه** بس مش أي مندوب في الشركة (الأدمن بيعدّي دايماً).
        Scope::assertRep($request->user(), $user);

        $custody = $user->currentCustody();

        if ($custody === null || $custody->status === 'closed') {
            return back()->withErrors(['adjust' => __('field.custody_adjust_none')]);
        }

        $data = $request->validate([
            // ⚠️ السبب إجباري — التصحيح بيغيّر أرقام تصفية المندوب،
            // ومن غير سبب مكتوب مفيش طريقة نفهم بعدها ليه الرقم اتغيّر
            'reason' => ['required', 'string', 'max:300'],
            'assigned' => ['required', 'array'],
            'assigned.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'gift' => ['nullable', 'array'],
            'gift.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $custody->load(['items.product', 'items.batch']);

        // الأهداف — الخانة الفاضية معناها «ماتلمسش الصنف ده»
        $assigned = [];
        foreach ($data['assigned'] as $pid => $v) {
            if ($v !== null && $v !== '') {
                $assigned[(int) $pid] = (int) $v;
            }
        }

        $gift = [];
        foreach ($data['gift'] ?? [] as $pid => $v) {
            if ($v !== null && $v !== '') {
                $gift[(int) $pid] = (int) $v;
            }
        }

        // ملخص التغيير «قديم ← جديد» — للحدث وإشعار المندوب
        $changes = [];
        $curOf = fn (int $pid, string $col) => (int) $custody->items->where('product_id', $pid)->sum($col);

        foreach ($assigned as $pid => $v) {
            if ($v !== $curOf($pid, 'assigned')) {
                $changes[] = (Product::find($pid)?->displayName() ?? '#'.$pid).': '.$curOf($pid, 'assigned').' ← '.$v;
            }
        }
        foreach ($gift as $pid => $v) {
            if ($v !== $curOf($pid, 'gift_assigned')) {
                $changes[] = '🎁 '.(Product::find($pid)?->displayName() ?? '#'.$pid).': '.$curOf($pid, 'gift_assigned').' ← '.$v;
            }
        }

        if ($changes === []) {
            return back()->withErrors(['adjust' => __('field.custody_adjust_no_change')]);
        }

        if ($err = $custody->adjustTo($assigned, $gift, $request->user(), $data['reason'])) {
            return back()->withErrors(['adjust' => $err]);
        }

        // الحدث على تايم لاين المندوب — النوع في TYPES و enums.track
        TrackEvent::log(
            $user,
            'custody_adjust',
            __('field.event_custody_adjust'),
            $data['reason'].' — '.implode(' · ', $changes),
        );

        AppNotification::send(
            $user,
            fn () => __('field.notif_custody_adjusted_title'),
            fn () => __('field.notif_custody_adjusted_body', [
                'by' => $request->user()->displayName(),
                'reason' => $data['reason'],
            ]),
            false,
        );

        return back()->with('ok', __('flash.custody_adjusted'));
    }

    // ================= أوامر التوريد =================

    /**
     * لوحة التوريد (كي أكاونت + أونلاين) — 2026-08-05: KPIs بحالات
     * الموافقة والتسليم والتأخير + فلاتر (بحث بالفرع، قناة، سلسلة،
     * موافقة، حالة، تواريخ) — والـKPIs بنفس فلاتر القايمة (نطاق واحد).
     */
    public function purchaseOrders(Request $request)
    {
        // ⚠️ سكوب التشانل مانجر: أوامر عملائه بس
        $u = auth()->user();

        // الفلاتر المشتركة (من غير الحالة) — الأساس للقايمة والـKPIs
        $base = fn () => PurchaseOrder::query()
            ->when($u?->role === 'manager',
                fn ($q2) => $q2->whereIn('client_id', Client::visibleTo(Client::query(), $u)->select('id')))
            ->when($request->string('q')->trim()->value(), function ($q2, $s) {
                $q2->where(fn ($w) => $w->where('number', 'like', "%$s%")
                    ->orWhere('source', 'like', "%$s%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%")
                        ->orWhere('name_en', 'like', "%$s%")
                        ->orWhere('code', 'like', "%$s%")));
            })
            ->when($request->integer('channel'),
                fn ($q2, $ch) => $q2->whereHas('client', fn ($c) => $c->where('channel_id', $ch)))
            ->when($request->integer('group'),
                fn ($q2, $g) => $q2->whereHas('client', fn ($c) => $c->where('group_id', $g)))
            ->when($request->string('from')->value(), fn ($q2, $d) => $q2->whereDate('created_at', '>=', $d))
            ->when($request->string('to')->value(), fn ($q2, $d) => $q2->whereDate('created_at', '<=', $d));

        // «متأخر» = عدّى معاده ولسه ماتسلمش (والمرفوض مش متأخر — اتقفل)
        $lateScope = fn ($q2) => $q2->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->where('status', '!=', 'delivered')
            ->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', 'rejected'));

        // ⚠️ `replenishmentRequest.requester` في الإيجر لودينج: العمود
        // بيعرض «جاي من أنهي طلب ومين طلبه» لكل صف (إصلاح ١٥/٨)، ومن
        // غيرها دي كويريتين لكل أمر في الصفحة.
        $q = $base()->with([
            'client.channel', 'courier', 'items', 'creator', 'approvedBy', 'editor',
            'replenishmentRequest.requester',
        ]);

        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }
        if ($approval = $request->string('approval')->value()) {
            $q->where('approval_status', $approval);
        }
        if ($request->boolean('late')) {
            $lateScope($q);
        }

        return view('ops.pos', [
            'pos' => $q->latest()->paginate(30)->withQueryString(),
            // ⚠️ كل الأرقام من نفس الأساس المفلتر — رقم فوق وجدول تحت
            // من نطاقين = شاشة بتكدب
            'kpi' => [
                'total' => $base()->count(),
                'pending' => $base()->where('approval_status', 'pending')->count(),
                'approved' => $base()->where('approval_status', 'approved')->count(),
                'rejected' => $base()->where('approval_status', 'rejected')->count(),
                'delivered' => $base()->where('status', 'delivered')->count(),
                'late' => $lateScope($base())->count(),
                'value' => (float) $base()->where(fn ($w) => $w->whereNull('approval_status')
                    ->orWhere('approval_status', '!=', 'rejected'))->sum('grand_total'),
            ],
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::whereHas('clients')->orderBy('name')->get(['id', 'name', 'name_en']),
            // دايالوج الأساين بس — دايالوج الإنشاء اليدوي اتشال (2026-08-06)
            'couriers' => User::fieldVisibleTo(User::where('role', 'driver'))->get(),
            'filters' => $request->only(['status', 'approval', 'late', 'q', 'channel', 'group', 'from', 'to']),
        ]);
    }

    /**
     * ═══ صفحة الأمر الكاملة — عرض + تعديل من مكان واحد (١٢/٨/٢٠٢٦) ═══
     *
     * طلب المالك: «لما أدخل على أوامر التوريد يتفتحلي العرض والتعديل».
     * الصف في اللوحة بيفتح الصفحة دي: البنود بأسعارها، العميل، خط
     * زمني للحالة، المستندات (صورة الأمر/الشيت)، وأزرار التعديل بنفس
     * شرط `poEditable` بتاع اللوحة.
     */
    public function showPo(PurchaseOrder $purchaseOrder)
    {
        // ⚠️ سكوب التشانل مانجر — نفس حارس editPo بالحرف
        abort_unless($purchaseOrder->client?->visibleBy(auth()->user()) ?? true, 403);

        $purchaseOrder->load([
            'client.channel', 'client.group', 'courier', 'warehouse',
            'items.product', 'creator', 'approvedBy', 'editor', 'pickOrder',
            'replenishmentRequest.requester',
        ]);

        return view('ops.po_show', [
            'po' => $purchaseOrder,
            // الأمر المتولّد من طلب ريفيل — لينك راجع للطلب الأصلي
            'replenishment' => \App\Models\ReplenishmentRequest::where('purchase_order_id', $purchaseOrder->id)->first(),
            'editable' => $this->poEditable($purchaseOrder),
        ]);
    }

    public function storePurchaseOrder(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'source' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:190'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'price_mode' => ['required', 'in:channel,old,new'],
            'due_date' => ['nullable', 'date'],
            // ═══ فلو الكي أكاونت (2026-08-04): موعد بالساعة + مخزن
            // التجهيز + فلاج «محتاج موافقة الحسابات» ═══
            // ⚠️ **معادين مختلفين — الخلط بينهم بيوقّف المندوب.**
            // `due_at` = البضاعة توصل **الفرع** إمتى.
            // `pickup_at` = المندوب ييجي **المخزن** ياخدها إمتى.
            // ممكن يبقى بينهم أيام (خد بكره، سلّم بعد 3 أيام).
            'due_at' => ['nullable', 'date'],
            'pickup_at' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            // ⚠️ **صورة أمر الشراء الأصلي** (طلب المالك ٨/٨/٢٠٢٦) —
            // صورة أو PDF من ورقة السلسلة. `max` بالكيلوبايت.
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'approval' => ['nullable', 'boolean'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // ⚠️ فلو الموافقة لازم له مندوب ومخزن — التجهيز بيتعمل منهم
        // وقت موافقة الحسابات، مش وقت الإنشاء.
        if ($request->boolean('approval') && (empty($data['assigned_to']) || empty($data['warehouse_id']))) {
            return back()->withErrors(['assigned_to' => __('ops.po_needs_rep_wh')])->withInput();
        }

        // ⚠️ **وحدة الإدخال بتتضرب هنا مش في الجافاسكريبت.** «5 كراتين»
        // بتتخزن 60 قطعة على بنود الأمر — والتسعير بالقطعة زي ما هو.
        // وحدة مش معرّفة للصنف = رفض الأمر كله، مش افتراض قطعة.
        foreach ($request->input('unit', []) as $productId => $unit) {
            if (! $unit || $unit === 'piece' || empty($data['qty'][$productId])) {
                continue;
            }

            $factor = Product::find($productId)?->unitFactor($unit);

            if ($factor === null) {
                return back()->withErrors([
                    'qty' => __('stock.unit_not_for_product', ['name' => Product::find($productId)?->displayName() ?? $productId]),
                ])->withInput();
            }

            $data['qty'][$productId] = (int) $data['qty'][$productId] * $factor;
        }

        $needsApproval = $request->boolean('approval');

        // ⚠️ **الرفع قبل الترانزاكشن** — كتابة الملف على الديسك مش
        // جزء من ترانزاكشن الداتابيز، ولو حصلت جواها وحصل rollback
        // بيفضل ملف يتيم. ولو الرفع نفسه فشل، مايتعملش أمر أصلاً.
        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('po-images', 'public')
            : null;

        try {
        // ⚠️ `$imagePath` لازم في الـ`use` — الرفع بيحصل قبل الترانزاكشن
        // (عشان الـrollback مايسيبش ملف يتيم)، والكلوجر من غيره بترمي
        // «Undefined variable $imagePath» **بس لما يكون فيه صورة فعلاً**
        // — عشان كده الباج عدّى من كل التيستات اللي من غير صورة.
        DB::transaction(function () use ($data, $request, $needsApproval, $imagePath) {
            // العميل محتاجينه عشان نحسب تسعيرته لو الوضع channel
            $client = Client::findOrFail($data['client_id']);

            // ⚠️ سكوب التشانل مانجر — مايعملش أمر لعميل مش بتاعه حتى
            // لو عرف الـid (القايمة في الشاشة مفلترة، وده حارس الراوت)
            //
            // ⚠️ **والمندوب كمان.** `exists:users,id` كانت بتخلّي أي
            // أمر ينزل على أي يوزر في الشركة — محاسب، مندوب مدير
            // تاني، أو حساب موقوف. `Scope::assertRep` بتفحص الرول
            // والنشاط والفرع والمدير، واتساق العميل مع المندوب.
            //
            // ⚠️ الأمر ممكن يتعمل **من غير** مندوب (`assigned_to`
            // nullable) — ساعتها بنفحص العميل بس، والمندوب بيتفحص
            // وقت التسكين في `assignPurchaseOrder`.
            Scope::assertClient($request->user(), $client);

            if (! empty($data['assigned_to'])) {
                Scope::assertRep($request->user(), User::find($data['assigned_to']), $client);
            }

            $po = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(),
                'client_id' => $client->id,
                'source' => $data['source'] ?? null,
                'address' => $data['address'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                // ⚠️ channel معناها إن السطور اتسعّرت بتسعيرة العميل (بخصمه)،
                // فالـ PO نفسه بيتسجل بالقائمة اللي العميل عليها عشان
                // مايعيدش الحساب بسعر قائمة عند التسليم.
                'price_mode' => $data['price_mode'] === 'channel'
                    ? $client->priceList()
                    : $data['price_mode'],
                'due_date' => $data['due_date'] ?? null,
                // ═══ فلو الكي أكاونت: مستني الحسابات ═══
                'approval_status' => $needsApproval ? 'pending' : null,
                'due_at' => $data['due_at'] ?? null,
                // ⚠️ بيتخزن هنا وبيتنسخ لأمر التجهيز وقت موافقة
                // الحسابات — أمر التجهيز مابيتعملش قبلها
                'pickup_at' => $data['pickup_at'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'image_path' => $imagePath,
                'created_by' => $request->user()->id,
                'status' => 'pending',
                'total' => 0,
            ]);

            $this->fillPoItems($po, $client, $data['qty'], $data['price_mode']);

            // ⚠️ **في فلو الموافقة المندوب مايتبلغش هنا** — بيتبلغ لما
            // المخزن يجهّز بعد موافقة الحسابات. إشعار بدري = مندوب
            // بيستنى بضاعة الحسابات ممكن ترفضها أصلاً.
            if ($po->assigned_to && ! $needsApproval) {
                AppNotification::send(
                    User::find($po->assigned_to),
                    fn () => __('field.notif_po_new_title', ['number' => $po->number]),
                    fn () => __('field.notif_po_new_body', [
                        'client' => $po->client->displayName(),
                        // ⚠️ المبلغ اللي السواق هيحصّله، مش الصافي
                        'amount' => number_format($po->fresh()->payable()),
                    ]),
                    // ⚠️ الوجهة = الأمر نفسه. من غيرها المندوب بيدوس
                    // على الإشعار ويقع على الرئيسية ويدوّر بإيده.
                    link: AppNotification::poLink($po->id),
                );
            }
        });
        } catch (\App\Exceptions\Rejected $e) {
            // صنف مش متسعّر — الأمر كله بيترفض بدل ما يدخل بسطر بصفر
            return back()->withErrors(['qty' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', $needsApproval ? __('flash.po_sent_accounting') : __('flash.po_created'));
    }

    /**
     * بناء بنود الأمر وتسعيرها وإجمالياته — مشترك بين الإنشاء والتعديل.
     *
     * ⚠️ channel = سعر العميل بخصمه (زي الفاتورة). old/new = سعر قائمة
     * بدون خصم — مقصود لسلاسل بتتحاسب بسعر صافي متفق عليه.
     *
     * ⚠️ **سعر صفر = رفض الأمر كله** (نفس دوكترين الفاتورة «الصنف مش
     * متسعّر») — أمر PO-2001 دخل فعلاً بصنف بسعر 0.00 وكان هيتقيّد
     * على الفرع ناقص (اتشاف 2026-08-04).
     */
    private function fillPoItems(PurchaseOrder $po, Client $client, array $qtyByProduct, string $priceMode): void
    {
        $rows = [];

        foreach ($qtyByProduct as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }
            $product = Product::find($productId);
            if (! $product) {
                continue;
            }

            $price = $priceMode === 'channel'
                ? $client->priceFor($product)
                : $product->priceFor($priceMode);

            if ((float) $price <= 0) {
                throw new \App\Exceptions\Rejected(
                    __('stock.po_not_priced', ['name' => $product->displayName()])
                );
            }

            // ⚠️ **الخصم بيتسجّل على السطر مش بيتقرا وقت الطباعة.**
            // خصم العميل بيتغيّر (عقد بينتهي، نسبة بتتظبط)، والورقة
            // اللي الفرع بيمضي عليها لازم تفضل شاهدة على اللحظة اللي
            // الأمر اتسعّر فيها. `list_price` قبل الخصم، و`price`
            // فضل بعد الخصم زي ما هو عشان `total` مايتحركش.
            //
            // ⚠️ وضع `old`/`new` **مالوش خصم أصلاً** — ده سعر قايمة
            // صافي متفق عليه مع السلسلة. اشتقاق النسبة من الفرق
            // بين القايمتين كان هيطبع «خصم 8%» على أمر محدش اتفق
            // فيه على خصم.
            //
            // ⚠️ **النسبة بتتاخد من العميل مباشرة، مش بتتعكس من السعر.**
            // `Pricing::unitPrice` بتقرّب لقرشين قبل ما نقسم، فعكسها
            // (`1 - price/list`) كان بيطلّع 9.98% لعقد 10% على صنف
            // سعره 13.33 — ومستند بيتمضى عليه بيقول نسبة مختلفة عن
            // العقد وعن فاتورة نفس العميل (اللي بتخزّن الكسر الصح).
            $discountPct = $priceMode === 'channel' ? $client->effectiveDiscount() : 0.0;

            $listPrice = $priceMode === 'channel'
                ? \App\Services\Pricing::listPriceFor($client, $product)
                : (float) $price;

            // القايمة الناقصة بترجّع 0 — ساعتها السعر المطبوع هو المتاح
            if ($listPrice <= 0) {
                $listPrice = (float) $price;
            }

            $lineTotal = round($qty * $price, 2);

            // الضريبة سطر بسطر من `Tax` — نفس قاعدة الفاتورة بالظبط
            $taxRate = \App\Services\Tax::rate($client, $product);
            $lineTax = \App\Services\Tax::on($lineTotal, $client, $product);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'price' => $price,
                'list_price' => $listPrice,
                'discount_pct' => $discountPct,
                'total' => $lineTotal,
                'tax_rate' => $taxRate,
                'tax' => $lineTax,
            ]);

            $rows[] = ['total' => $lineTotal, 'tax' => $lineTax];
        }

        // `total` صافي المبيعات، و`grand_total` اللي بيتقيّد عند التسليم
        $sums = \App\Services\Tax::totals($rows);

        $po->update([
            'total' => $sums['net'],
            'tax_total' => $sums['tax'],
            'grand_total' => $sums['grand'],
        ]);
    }

    // ═══════════ رفع POs من شيتات السلاسل — 2026-08-05 ═══════════

    /**
     * شاشة الرفع: قناة + مخزن + مندوب + معاد + ملفات PO متعددة.
     * كل ملف = أمر لفرع — صيغة شيتات رابت وأمثالها (Store ID/Name
     * + Order Nr + جدول أصناف بالباركود والكمية بالقطع).
     */
    public function poImport()
    {
        // السلاسل ومين منها في أنهي قناة — عشان سيلكت «العميل الأساسي»
        // يتفلتر بالقناة المختارة في الشاشة
        $groupChannels = Client::visibleTo(Client::query())
            ->whereNotNull('group_id')->whereNotNull('channel_id')
            ->distinct()->get(['group_id', 'channel_id']);

        return view('ops.po_import', [
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::whereIn('id', $groupChannels->pluck('group_id')->unique())
                ->orderBy('name')->get(['id', 'name', 'name_en']),
            'groupChannels' => $groupChannels,
            'warehouses' => Warehouse::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'reps' => User::fieldVisibleTo(User::whereIn('role', ['sales_agent', 'driver', 'manager'])
                ->where('active', true))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** معاينة: بارس كل ملف + أوتوماتش الفرع — مفيش كتابة هنا */
    public function poImportPreview(Request $request)
    {
        $data = $request->validate([
            'channel_id' => ['required', 'exists:channels,id'],
            // السلسلة (العميل الأساسي) — اختيارية: بتحصر الديتكشن
            // والقوايم في فروعها بدل القناة كلها
            'group_id' => ['nullable', 'exists:client_groups,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'due_at' => ['required', 'date'],
            'files' => ['required', 'array', 'min:1', 'max:30'],
            'files.*' => ['file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        // فروع القناة (أو السلسلة المختارة منها) — وبسكوب التشانل مانجر
        $clients = Client::visibleTo(Client::where('channel_id', $data['channel_id'])
            ->when($data['group_id'] ?? null, fn ($q, $g) => $q->where('group_id', $g))
            ->where('status', 'active'))->orderBy('name')
            ->get(['id', 'name', 'name_en', 'code']);

        if ($clients->isEmpty()) {
            return back()->withErrors(['group_id' => __('ops.po_no_branches')])->withInput();
        }

        $entries = [];

        foreach ($request->file('files') as $file) {
            $parsed = $this->parsePoSheet($file->getRealPath());

            // أوتوماتش الفرع: بالاسم زي ما هو، وإلا بكود بيخلص بـStore ID
            $store = mb_strtolower(trim((string) ($parsed['store_name'] ?? '')));
            $sid = preg_replace('/\D+/', '', (string) ($parsed['store_id'] ?? ''));
            $guess = $clients->first(fn ($c) => mb_strtolower(trim($c->name)) === $store
                    || ($c->name_en && mb_strtolower(trim($c->name_en)) === $store))
                ?? ($sid !== '' ? $clients->first(fn ($c) => preg_match('/-0*'.$sid.'$/', $c->code)) : null);

            // ⚠️ الشيت بيتحفظ هنا (مش وقت الإنشاء) — الإنشاء AJAX من غير
            // الملفات. المسار بيمشي مع الصف وبيتسجل على الأمر كمرجع.
            // نفس نمط العقود: storage/app مش public — الشيتات فيها أسعار.
            $origName = $file->getClientOriginalName();
            $safe = uniqid().'_'.preg_replace('/[^\w.\-\x{0600}-\x{06FF}]+/u', '_', $origName);
            $dir = 'po-sheets/'.now()->format('Y-m');
            $file->move(storage_path('app/'.$dir), $safe);

            // ⚠️ منع التكرار (2026-08-06): نفس رقم PO السلسلة اترفع قبل
            // كده لفرع من فروع الاختيار؟ الملف بيتعلم «مكرر» وبيتعمله
            // skip أوتوماتيك — والمرفوض/الملغي مش بيمنع إعادة الرفع.
            $dup = null;

            if ($parsed['po_no']) {
                // ⚠️ `!= 'rejected'` لوحدها بتستبعد الـNULL (الفلو القديم) في SQL
                $dup = PurchaseOrder::where('source', $parsed['po_no'])
                    ->whereIn('client_id', $clients->pluck('id'))
                    ->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', 'rejected'))
                    ->where('status', '!=', 'cancelled')
                    ->first();
            }

            $entries[] = [
                'file' => $origName,
                'sheet_path' => $dir.'/'.$safe,
                'po_no' => $parsed['po_no'],
                'store_name' => $parsed['store_name'],
                'store_id' => $parsed['store_id'],
                'client_id' => $guess?->id,
                'items' => $parsed['items'],
                'qty_total' => array_sum(array_column($parsed['items'], 'qty')),
                'unknown' => $parsed['unknown'],
                'dup' => $dup?->number,
            ];
        }

        return view('ops.po_import_preview', [
            'entries' => $entries,
            'clients' => $clients,
            'batch' => [
                'channel_id' => (int) $data['channel_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'assigned_to' => (int) $data['assigned_to'],
                'due_at' => $data['due_at'],
            ],
        ]);
    }

    /**
     * التنفيذ بعد التأكيد: أمر لكل ملف — **نفس فلو الإنشاء اليدوي
     * بالظبط**: pending للحسابات، تسعير قايمة العميل، رفض الصنف
     * الغير متسعّر. أمر واقع مايوقّعش الباقي — أخطاؤه بتتجمع.
     */
    public function poImportStore(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'due_at' => ['required', 'date'],
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.client_id' => ['nullable', 'exists:clients,id'],
            'orders.*.source' => ['nullable', 'string', 'max:40'],
            'orders.*.items' => ['required', 'string'],
            'orders.*.skip' => ['nullable', 'boolean'],
        ]);

        $created = 0;
        $errors = [];

        foreach ($data['orders'] as $order) {
            if (! empty($order['skip']) || empty($order['client_id'])) {
                continue;
            }

            // نفس حارس التكرار بتاع المسار المتتابع (2026-08-06)
            if ($dupErr = $this->duplicatePoError((int) $order['client_id'], $order['source'] ?? null)) {
                $errors[] = ($order['source'] ?: '—').': '.$dupErr;

                continue;
            }

            $qty = json_decode($order['items'], true);

            if (! is_array($qty) || $qty === []) {
                continue;
            }

            // أرقام صحيحة بس — أي حاجة تانية في الـJSON بتتداس
            $qty = collect($qty)->mapWithKeys(fn ($q, $pid) => [(int) $pid => (int) $q])
                ->filter(fn ($q, $pid) => $pid > 0 && $q > 0)->all();

            try {
                DB::transaction(function () use ($order, $qty, $data, $request) {
                    $client = Client::findOrFail($order['client_id']);

                    abort_unless($client->visibleBy($request->user()), 403);

                    $po = PurchaseOrder::create([
                        'number' => PurchaseOrder::nextNumber(),
                        'client_id' => $client->id,
                        // رقم أمر السلسلة (Order Nr) — بيتطبع على المستند
                        'source' => $order['source'] ?? null,
                        'assigned_to' => $data['assigned_to'],
                        'price_mode' => $client->priceList(),
                        'approval_status' => 'pending',
                        'due_at' => $data['due_at'],
                        'warehouse_id' => $data['warehouse_id'],
                        'created_by' => $request->user()->id,
                        'status' => 'pending',
                        'total' => 0,
                    ]);

                    $this->fillPoItems($po, $client, $qty, 'channel');
                });

                $created++;
            } catch (\App\Exceptions\Rejected $e) {
                $errors[] = ($order['source'] ?: '—').': '.$e->getMessage();
            }
        }

        $resp = redirect()->route('ops.po.approvals')
            ->with('ok', __('flash.pos_imported', ['count' => $created]));

        return $errors === [] ? $resp : $resp->withErrors($errors);
    }

    /**
     * إنشاء أمر واحد من المعاينة — AJAX (2026-08-06): الشاشة بتنشئ
     * الأوامر واحد ورا الثاني ببروجريس بار، فكل نداء بيرجع JSON
     * برقم الأمر أو رسالة الرفض. نفس منطق `poImportStore` بالظبط.
     */
    public function poImportStoreOne(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            // معاد التوريد بقى لكل أمر لوحده (2026-08-06) — الصف بيبعت بتاعه
            'due_at' => ['required', 'date'],
            'client_id' => ['required', 'exists:clients,id'],
            'source' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'string'],
            'sheet_path' => ['nullable', 'string', 'max:255'],
            'sheet_name' => ['nullable', 'string', 'max:190'],
        ]);

        // ⚠️ المسار جاي من hidden input — نتأكد إنه جوه po-sheets فعلاً
        // وموجود، وإلا بيتداس. حارس ضد التسلل بالمسار.
        $sheet = (string) ($data['sheet_path'] ?? '');

        if ($sheet !== '') {
            $real = realpath(storage_path('app/'.$sheet));
            $root = realpath(storage_path('app/po-sheets'));

            if ($root === false || $real === false || ! str_starts_with($real, $root) || ! is_file($real)) {
                $sheet = '';
            }
        }

        // أرقام صحيحة بس — أي حاجة تانية في الـJSON بتتداس
        $qty = collect(json_decode($data['items'], true) ?: [])
            ->mapWithKeys(fn ($q, $pid) => [(int) $pid => (int) $q])
            ->filter(fn ($q, $pid) => $pid > 0 && $q > 0)->all();

        if ($qty === []) {
            return response()->json(['message' => __('ops.po_no_items')], 422);
        }

        // ⚠️ منع التكرار (2026-08-06): نفس رقم PO السلسلة لنفس الفرع
        // مايتعملوش مرتين — الشيت اللي بيترفع تاني بيترفض برقم الأمر
        // الموجود. المرفوض/الملغي مش بيمنع إعادة الرفع.
        if ($dupErr = $this->duplicatePoError($data['client_id'], $data['source'] ?? null)) {
            return response()->json(['message' => $dupErr], 422);
        }

        try {
            $po = DB::transaction(function () use ($data, $qty, $sheet, $request) {
                $client = Client::findOrFail($data['client_id']);

                // ⚠️ سكوب التشانل مانجر — حارس الراوت زي الرفع الجماعي
                abort_unless($client->visibleBy($request->user()), 403);

                $po = PurchaseOrder::create([
                    'number' => PurchaseOrder::nextNumber(),
                    'client_id' => $client->id,
                    'source' => $data['source'] ?? null,
                    'sheet_path' => $sheet !== '' ? $sheet : null,
                    'sheet_name' => $sheet !== '' ? ($data['sheet_name'] ?? null) : null,
                    'assigned_to' => $data['assigned_to'],
                    'price_mode' => $client->priceList(),
                    'approval_status' => 'pending',
                    'due_at' => $data['due_at'],
                    'warehouse_id' => $data['warehouse_id'],
                    'created_by' => $request->user()->id,
                    'status' => 'pending',
                    'total' => 0,
                ]);

                $this->fillPoItems($po, $client, $qty, 'channel');

                return $po;
            });
        } catch (\App\Exceptions\Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'id' => $po->id, 'number' => $po->number]);
    }

    /**
     * فيه أمر شغّال بنفس رقم PO السلسلة لنفس الفرع؟ بترجع رسالة
     * الرفض برقم الأمر الموجود، أو null لو مفيش تكرار.
     *
     * ⚠️ المرفوض والملغي مش بيمنعوا — رفض الحسابات لازم يسمح
     * بإعادة رفع الشيت بعد التصحيح.
     */
    private function duplicatePoError(int $clientId, ?string $source): ?string
    {
        $source = trim((string) $source);

        if ($source === '') {
            return null;
        }

        $dup = PurchaseOrder::where('client_id', $clientId)
            ->where('source', $source)
            // ⚠️ `!= 'rejected'` لوحدها بتستبعد الـNULL (الفلو القديم) في SQL
            ->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', 'rejected'))
            ->where('status', '!=', 'cancelled')
            ->first();

        return $dup ? __('ops.po_dup_reject', ['number' => $dup->number]) : null;
    }

    /**
     * بارس شيت PO واحد — صيغة رابت: أزواج (ليبل، قيمة) في الهيدر،
     * وبعدين جدول أصناف عموده الفاصل «Barcode»، ولحد صف «Total».
     *
     * @return array{po_no: ?string, store_id: ?string, store_name: ?string,
     *               items: list<array{product_id: int, name: string, qty: int}>,
     *               unknown: list<string>}
     */
    private function parsePoSheet(string $path): array
    {
        $rows = \App\Services\Sheet::rows($path);

        $meta = ['po_no' => null, 'store_id' => null, 'store_name' => null];
        $items = [];
        $unknown = [];
        $cols = null;

        foreach ($rows as $row) {
            $cells = array_map(fn ($c) => trim((string) $c), $row);

            // الهيدر: الليبل وجنبه القيمة — الليبلات بأسماء رابت الحرفية
            foreach ($cells as $i => $cell) {
                $next = $cells[$i + 1] ?? null;
                if ($next === null || $next === '') {
                    continue;
                }
                match (mb_strtolower($cell)) {
                    'order nr', 'po nr', 'po #' => $meta['po_no'] = $meta['po_no'] ?? $next,
                    'store id' => $meta['store_id'] = $meta['store_id'] ?? $next,
                    'store name' => $meta['store_name'] = $meta['store_name'] ?? $next,
                    default => null,
                };
            }

            // صف عناوين الجدول — بيحدد أماكن الأعمدة
            $lower = array_map('mb_strtolower', $cells);
            if (in_array('barcode', $lower, true)) {
                $cols = [
                    'barcode' => array_search('barcode', $lower, true),
                    'sku' => array_search('sku', $lower, true),
                    'qty' => array_search('total pc', $lower, true),
                ];

                continue;
            }

            if ($cols === null) {
                continue;
            }

            // نهاية الجدول
            if (in_array('total', $lower, true) && trim((string) ($cells[$cols['barcode']] ?? '')) === '') {
                break;
            }

            $barcode = preg_replace('/\D+/', '', (string) ($cells[$cols['barcode']] ?? ''));
            $qty = (int) \App\Services\Sheet::number($cells[$cols['qty']] ?? null);

            if ($barcode === '' || $qty <= 0) {
                continue;
            }

            // الباركود الأول (المصدر الأدق) وبعدين SKU ككود صنف
            $product = Product::findByBarcode($barcode)
                ?? ($cols['sku'] !== false ? Product::where('code', $cells[$cols['sku']] ?? '')->first() : null);

            if ($product === null) {
                $unknown[] = $barcode;

                continue;
            }

            // نفس الصنف في سطرين — الكميات بتتجمع
            if (isset($items[$product->id])) {
                $items[$product->id]['qty'] += $qty;
            } else {
                $items[$product->id] = [
                    'product_id' => $product->id,
                    'name' => $product->displayName(),
                    'qty' => $qty,
                ];
            }
        }

        return $meta + ['items' => array_values($items), 'unknown' => $unknown];
    }

    /** مستند أمر التوريد للطباعة — نسخ الحسابات المختومة */
    public function printPo(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['client.group', 'client.zone', 'courier', 'items.product', 'warehouse', 'creator']);

        return view('ops.po_print', ['po' => $purchaseOrder]);
    }

    /**
     * تنزيل شيت الأمر الأصلي (2026-08-06) — المرجع اللي السلسلة بعتته.
     * نفس حراسة ملفات العقود: realpath جوه po-sheets وبس.
     */
    public function downloadPoSheet(PurchaseOrder $purchaseOrder)
    {
        $path = (string) $purchaseOrder->sheet_path;
        $real = $path !== '' ? realpath(storage_path('app/'.$path)) : false;
        $root = realpath(storage_path('app/po-sheets'));

        if ($real === false || $root === false || ! str_starts_with($real, $root) || ! is_file($real)) {
            abort(404);
        }

        $name = $purchaseOrder->number.' - '.($purchaseOrder->sheet_name ?: basename($real));

        return response()->download($real, $name);
    }

    /**
     * طباعة مجمعة (2026-08-06) — كل الأوامر المطلوبة في مستند واحد،
     * أمر في صفحة A4 لوحده. الحسابات بتطبع دفعة السلسلة كلها بضغطة.
     */
    public function printPoBatch(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) $v)->filter()->unique()->take(100);

        $pos = PurchaseOrder::with(['client.group', 'client.zone', 'courier', 'items.product', 'warehouse', 'creator'])
            ->whereIn('id', $ids)->orderBy('id')->get();

        abort_if($pos->isEmpty(), 404);

        return view('ops.po_print_batch', ['pos' => $pos]);
    }

    /**
     * موافقة جماعية (2026-08-06) — كل أمر في ترانزاكشن لوحده:
     * أمر واقع (عجز رف مثلاً) مايوقّعش باقي الدفعة، وبيتبلغ عنه
     * بالاسم. مفيش تعديل كميات هنا — التعديل من صف الأمر نفسه.
     */
    public function decideAllPoApprovals(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $done = 0;
        $errors = [];

        $orders = PurchaseOrder::with(['items.product', 'warehouse', 'courier'])
            ->whereIn('id', $data['ids'])
            ->where('approval_status', 'pending')
            ->get();

        foreach ($orders as $po) {
            if ($po->warehouse === null || $po->courier === null) {
                $errors[] = $po->number.': '.__('ops.po_needs_rep_wh');

                continue;
            }

            try {
                DB::transaction(function () use ($po, $request) {
                    // ⚠️ **أمر التجهيز بيتعمل هنا** — نفس فلو الموافقة
                    // الفردية بالظبط: requested بينزل شاشة التجهيز،
                    // وتأكيد المخزن هو اللي بيخصم ويبلغ المندوب.
                    $result = \App\Models\PickOrder::raise(
                        $po->warehouse,
                        $po->courier,
                        $po->items->pluck('qty', 'product_id')->all(),
                        \App\Models\PickOrder::PURPOSE_CUSTOMER_PO,
                        $request->user(),
                        [
                            // ⚠️ **الأمر ده لأمر توريد مش عهدة** — الغرض
                            // كان متسجّل `van_load` غلط، فالمندوب وأمين
                            // المخزن مكانش عندهم أي طريقة يفرّقوا.
                            'purchase_order_id' => $po->id,
                            // موعد وصول المندوب المخزن — اتحدد وقت
                            // إنشاء الأمر واستنى لحد الموافقة
                            'pickup_at' => $po->pickup_at,
                        ],
                    );

                    if ($result['error']) {
                        throw new \App\Exceptions\Rejected($result['error']);
                    }

                    $po->update([
                        'approval_status' => 'approved',
                        'approved_by' => $request->user()->id,
                        'approved_at' => now(),
                        'pick_order_id' => $result['order']->id,
                    ]);
                });

                $done++;
            } catch (\App\Exceptions\Rejected $e) {
                $errors[] = $po->number.': '.$e->getMessage();
            }
        }

        $resp = back()->with('ok', __('flash.pos_bulk_approved', ['count' => $done]));

        return $errors === [] ? $resp : $resp->withErrors($errors);
    }

    // ═══════════ أوامر توريد الكي أكاونت — 2026-08-04 ═══════════

    /** فتح أمر pending للتعديل — نفس شاشة الإنشاء متملية بالبيانات */
    public function editPo(PurchaseOrder $purchaseOrder)
    {
        // ⚠️ سكوب التشانل مانجر — مايعدّلش أمر عميل مش بتاعه
        abort_unless($purchaseOrder->client?->visibleBy(auth()->user()) ?? true, 403);

        if (! $this->poEditable($purchaseOrder)) {
            return redirect()->route('ops.po.approvals')
                ->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        $data = $this->poHandout()->getData();
        $data['editing'] = $purchaseOrder->load(['items', 'client']);

        return view('ops.po_handout', $data);
    }

    /**
     * حفظ تعديل أمر pending — للحسابات ولصاحب الأمر (أدمن/مدير قناة).
     *
     * ⚠️ **البنود بتتبني من الأول بأسعار النهارده** — التعديل مش
     * بيرقّع، بيعيد التسعير كأنه أمر جديد بنفس الرقم. والقرار لسه
     * عند الحسابات: التعديل مابيوافقش، بيرجّع الأمر للطابور.
     */
    /**
     * الأمر يتعدّل امتى؟ (توسيع ١٠ أغسطس ٢٠٢٦ بقرار المالك)
     *
     * - **مستني الحسابات** → يتعدّل عادي (السلوك القديم).
     * - **معتمد** → يتعدّل طالما التسليم مابدأش (`status = pending`)
     *   والمندوب ماستلمش بضاعته من المخزن (`pick != handed`) —
     *   والتعديل **بيرجّعه لطابور الحسابات** (شوف updatePo).
     * - وصل/اتسلم/اتلغى أو البضاعة خرجت → مفيش تعديل.
     */
    private function poEditable(PurchaseOrder $purchaseOrder): bool
    {
        if ($purchaseOrder->approval_status === 'pending') {
            return true;
        }

        return $purchaseOrder->approval_status === 'approved'
            && $purchaseOrder->status === 'pending'
            && $purchaseOrder->pickOrder?->status !== 'handed';
    }

    public function updatePo(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (! $this->poEditable($purchaseOrder)) {
            return back()->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        // بنمسكها قبل أي كتابة — دي اللي بتحدد فلو «الرجوع للحسابات»
        $wasApproved = $purchaseOrder->approval_status === 'approved';

        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'assigned_to' => ['required', 'exists:users,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'due_at' => ['required', 'date'],
            // ⚠️ **الفورم بيبعتهم في وضع التعديل كمان** (نفس الفيو)
            // وكانوا بيتبلعوا في صمت: المستخدم يستبدل صورة الأمر أو
            // يصلّح موعد الاستلام، يدوس حفظ، ومايحصلش حاجة ومفيش خطأ.
            'pickup_at' => ['nullable', 'date'],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'source' => ['nullable', 'string', 'max:40'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // وحدة الإدخال → قطع، في السيرفر — نفس قاعدة الإنشاء
        foreach ($request->input('unit', []) as $productId => $unit) {
            if (! $unit || $unit === 'piece' || empty($data['qty'][$productId])) {
                continue;
            }

            $factor = Product::find($productId)?->unitFactor($unit);

            if ($factor === null) {
                return back()->withErrors([
                    'qty' => __('stock.unit_not_for_product', ['name' => Product::find($productId)?->displayName() ?? $productId]),
                ])->withInput();
            }

            $data['qty'][$productId] = (int) $data['qty'][$productId] * $factor;
        }

        // ⚠️ الرفع قبل الترانزاكشن — كتابة الملف على الديسك مش جزء
        // من ترانزاكشن الداتابيز. نفس قاعدة `storePurchaseOrder`.
        $imagePath = $request->hasFile('image')
            ? $request->file('image')->store('po-images', 'public')
            : null;

        try {
            DB::transaction(function () use ($purchaseOrder, $data, $imagePath) {
                $client = Client::findOrFail($data['client_id']);

                // ⚠️ سكوب التشانل مانجر — نفس حارس storePurchaseOrder
                //
                // ⚠️ **والمستلم كمان.** التعديل بيسمح بتبديل
                // `client_id` و`assigned_to` مع بعض، والمحاسب معاه
                // الراوت ده — من غير الحارس ينفع أمر يتنقل لعميل
                // ومندوب بره نطاق المعدِّل في نداء واحد.
                Scope::assertRep(auth()->user(), User::find($data['assigned_to']), $client);

                $purchaseOrder->update([
                    'client_id' => $client->id,
                    'assigned_to' => $data['assigned_to'],
                    'warehouse_id' => $data['warehouse_id'],
                    'due_at' => $data['due_at'],
                    'pickup_at' => $data['pickup_at'] ?? $purchaseOrder->pickup_at,
                    // ⚠️ **الصورة القديمة بتفضل لو مارفعش جديدة** —
                    // `null` هنا كان هيمسحها مع أي تعديل تاني.
                    'image_path' => $imagePath ?? $purchaseOrder->image_path,
                    // ⚠️ **مايتمسحش لو الفورم بعته فاضي** — الأمر
                    // المتولّد من طلب ريفيل بيتعرف بـ`source`،
                    // ومسحه بيقطع `fromReplenishment()` في صمت.
                    'source' => $data['source'] ?? $purchaseOrder->source,
                    'price_mode' => $client->priceList(),
                    // تراك التعديل: مين وإمتى (2026-08-05)
                    'was_edited' => true,
                    'edited_by' => auth()->id(),
                    'edited_at' => now(),
                ]);

                // بنود جديدة بالكامل — التعديل إعادة بناء مش ترقيع
                $purchaseOrder->items()->delete();
                $this->fillPoItems($purchaseOrder, $client, $data['qty'], 'channel');
            });

            // ═══ تعديل أمر معتمد → يرجع لطابور الحسابات (١٠ أغسطس ٢٠٢٦) ═══
            //
            // قرار المالك: التعديل مايكملش من غير موافقة الحسابات من
            // جديد. أمر التجهيز اللي اتفتح بالموافقة القديمة بيتلغي
            // (cancel بترجّع أي كميات اتلمّت للرف)، والموافقة الجاية
            // هتفتح تجهيز جديد بالكميات الجديدة — مفيش تجهيزين لنفس
            // الأمر ولا تجهيز بكميات قديمة.
            if ($wasApproved) {
                DB::transaction(function () use ($purchaseOrder) {
                    $pick = $purchaseOrder->pickOrder;

                    if ($pick !== null && ! in_array($pick->status, ['cancelled', 'handed'], true)) {
                        if ($err = $pick->cancel()) {
                            throw new \App\Exceptions\Rejected($err);
                        }
                    }

                    $purchaseOrder->update([
                        'approval_status' => 'pending',
                        'approved_by' => null,
                        'approved_at' => null,
                        'pick_order_id' => null,
                    ]);
                });

                // المحاسبين ياخدوا بالهم إن فيه أمر رجع الطابور —
                // ⚠️ من غير لينك: شاشة الطابور مش من وجهات الأبلكيشن،
                // ولينك مقفول أسوأ من مفيش (درس أمين المخزن الموثّق).
                foreach (User::where('role', 'accountant')->where('active', true)->get() as $acc) {
                    AppNotification::send(
                        $acc,
                        fn () => __('field.notif_po_reapproval_title', ['number' => $purchaseOrder->number]),
                        fn () => __('field.notif_po_reapproval_body', [
                            'client' => $purchaseOrder->client->displayName(),
                            'by' => auth()->user()->displayName(),
                        ]),
                    );
                }

                return redirect()->route('ops.po.approvals')
                    ->with('ok', __('flash.po_back_to_accounting'));
            }
        } catch (\App\Exceptions\Rejected $e) {
            return back()->withErrors(['qty' => $e->getMessage()])->withInput();
        }

        return redirect()->route('ops.po.approvals')->with('ok', __('flash.po_updated'));
    }

    /** شاشة «تسليم PO للمندوب»: سلسلة ← فرع ← مندوب ← معاد ← أصناف بالوحدات */
    /**
     * المتاح للتجهيز لكل (مخزن، صنف) — **نفس مصدر الحجز بالظبط**
     * (`Warehouse::availableFor`: المرصوف السليم على الأرفف).
     *
     * ⚠️ الشاشة كانت بتعرض إجمالي `stocks` (كل المخازن + غير المرصوف)
     * فالمدير يشوف 120 والموافقة ترفض بـ«المتاح 0» — رقمين من مصدرين
     * (اتشاف 2026-08-05). دلوقتي المعروض هو اللي هيتحجز فعلاً.
     *
     * @return array<int, array<int, int>>  [warehouse_id][product_id] => qty
     */
    private function shelfAvailability(): array
    {
        $rows = \App\Models\BatchLocation::query()
            ->join('locations', 'locations.id', '=', 'batch_locations.location_id')
            ->join('batches', 'batches.id', '=', 'batch_locations.batch_id')
            ->where('batch_locations.qty', '>', 0)
            ->where('batches.blocked', false)
            ->where('batches.qty_remaining', '>', 0)
            ->whereDate('batches.expires_on', '>=', now()->toDateString())
            ->selectRaw('locations.warehouse_id as wid, batches.product_id as pid, SUM(batch_locations.qty) as q')
            ->groupBy('locations.warehouse_id', 'batches.product_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->wid][(int) $r->pid] = (int) $r->q;
        }

        return $out;
    }

    public function poHandout()
    {
        // السلاسل ومين منها في أنهي قناة — نفس كاسكيد شاشة رفع الشيتات:
        // القناة ← السلسلة ← الفرع (طلب المالك 2026-08-06)
        $groupChannels = Client::visibleTo(Client::query())
            ->whereNotNull('group_id')->whereNotNull('channel_id')
            ->distinct()->get(['group_id', 'channel_id']);

        // ═══ المدير الميداني (١١ أغسطس ٢٠٢٦): بيسلّم أوردرات بنفسه ═══
        // بيضيف **نفسه هو بس** لقايمة المستلمين — `Scope::assertRep`
        // في الحفظ والتسكين بتسمح بالمدير على نفسه (أو من الأدمن)
        // ومدير تاني لأ. قايمة فريق الشارع نفسها زي ما هي.
        $reps = User::fieldVisibleTo(User::whereIn('role', ['sales_agent', 'driver', 'manager']))
            ->where('active', true)->orderBy('name')->get(['id', 'name']);

        if (auth()->user()?->role === 'manager' && ! $reps->contains('id', auth()->id())) {
            $reps->push(auth()->user());
        }

        return view('ops.po_handout', [
            'shelfAvail' => $this->shelfAvailability(),
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::orderBy('name')->get(),
            'groupChannels' => $groupChannels,
            // الفروع بتتفلتر بالقناة والسلسلة في الجافاسكريبت — فبنبعت الكل
            // ⚠️ العميل حالته عمود `status` نصي ('active') مش بوليان `active`
            // العلاقات دي عشان Pricing::listRowFor تشتغل من الميموري —
            // البحث بيفلتر الأصناف بسعر قايمة الفرع المختار
            // ⚠️ وسكوب التشانل مانجر: مايعملش أمر غير لعملائه (2026-08-05)
            'clients' => Client::visibleTo(Client::with(['group.contract.priceListRow', 'contract.priceListRow', 'priceListRow'])
                ->where('status', 'active'))->orderBy('name')
                ->get(['id', 'name', 'name_en', 'group_id', 'channel_id', 'balance', 'price_list', 'price_list_id']),
            'reps' => $reps,
            'warehouses' => \App\Models\Warehouse::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
            'products' => Product::where('active', true)->orderBy('code')->get(),
        ]);
    }

    /**
     * طابور الحسابات — جدول كولابس (2026-08-06): كل أمر صف، الضغط
     * عليه بيفتح تفاصيله. الفلاتر والترتيب سيرفر سايد، و«آخر
     * القرارات» اتشالت — المتقرر فيه مكانه صفحة أوامر التوريد.
     */
    public function poApprovals(Request $request)
    {
        $q = PurchaseOrder::with(['client.group', 'client.channel', 'courier', 'items.product', 'creator', 'warehouse',
            'replenishmentRequest.requester'])
            ->where('approval_status', 'pending')
            // ⚠️ **سكوب مدير القناة (١٣ أغسطس ٢٠٢٦).** الشاشة بقت
            // مفتوحة للمدير عشان الريدايركتات بتوديه هنا — فلازم
            // يشوف أوامر عملائه هو بس. الأدمن والمحاسب بيشوفوا الكل
            // (`Client::visibleTo` مابتضيّقش غير على `manager`، بس
            // الشرط الصريح بيخلّي النية مقروءة ومايلمسش الكويري لهم).
            ->when(auth()->user()?->role === 'manager',
                fn ($q2) => $q2->whereHas('client', fn ($c) => Client::visibleTo($c)))
            ->when($request->string('q')->trim()->value(), function ($q2, $s) {
                $q2->where(fn ($w) => $w->where('number', 'like', "%$s%")
                    ->orWhere('source', 'like', "%$s%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%")
                        ->orWhere('name_en', 'like', "%$s%")
                        ->orWhere('code', 'like', "%$s%")));
            })
            ->when($request->integer('group'),
                fn ($q2, $g) => $q2->whereHas('client', fn ($c) => $c->where('group_id', $g)))
            ->when($request->string('from')->value(), fn ($q2, $d) => $q2->whereDate('due_at', '>=', $d))
            ->when($request->string('to')->value(), fn ($q2, $d) => $q2->whereDate('due_at', '<=', $d));

        // الترتيب: الافتراضي أقرب معاد توريد — القرار المستعجل الأول
        match ($request->string('sort')->value()) {
            'value' => $q->orderByDesc('grand_total'),
            'newest' => $q->latest(),
            default => $q->orderByRaw('due_at IS NULL')->orderBy('due_at'),
        };

        return view('ops.po_approvals', [
            'pending' => $q->get(),
            // سلاسل الأوامر المستنية بس — سيلكت الفلتر
            'groups' => \App\Models\ClientGroup::whereHas('clients',
                fn ($c) => Client::visibleTo($c)->whereHas('purchaseOrders',
                    fn ($p) => $p->where('approval_status', 'pending')))
                ->orderBy('name')->get(['id', 'name', 'name_en']),
            // المتاح على أرفف كل مخزن — الحسابات تشوف العجز **قبل**
            // ما تدوس موافقة بدل ما الرفض يفاجئها (2026-08-05)
            'shelfAvail' => $this->shelfAvailability(),
            'filters' => $request->only(['q', 'group', 'from', 'to', 'sort']),
        ]);
    }

    /**
     * قرار الحسابات: موافقة / تعديل كميات + موافقة / رفض.
     *
     * ⚠️ **الموافقة هي اللي بتعمل أمر التجهيز** — قبلها البضاعة
     * ماتتحجزش. والرفض/التعديل بيتبلغ بيه صاحب الأمر (مدير القناة).
     */
    public function decidePoApproval(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            // ⚠️ الرفض من غير سبب ممنوع — مدير القناة لازم يعرف ليه
            'note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:500'],
            // تعديل الحسابات بالقطع — الشاشة بتوري التجميعة جنب الخانة
            'qty_edit' => ['nullable', 'array'],
            'qty_edit.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ], [
            'note.required_if' => __('ops.po_note_required_reject'),
        ]);

        // ⚠️ قرار واحد بس — ضغطتين متتاليتين مايعملوش أمري تجهيز
        if ($purchaseOrder->approval_status !== 'pending') {
            return back()->withErrors(['decision' => __('ops.po_already_decided')]);
        }

        // ⚠️ تعديل كميات من غير ملحوظة ممنوع برضه — التعديل بيتبلغ بيه
        // مدير القناة، والإشعار من غير سبب مايتفهمش (2026-08-06)
        if ($data['decision'] === 'approved' && blank($data['note'] ?? null)) {
            foreach ($data['qty_edit'] ?? [] as $itemId => $newQty) {
                if ($newQty === null || $newQty === '') {
                    continue;
                }

                $item = $purchaseOrder->items->firstWhere('id', (int) $itemId);

                if ($item && (int) $newQty !== (int) $item->qty) {
                    return back()->withErrors(['note' => __('ops.po_note_required_edit')])->withInput();
                }
            }
        }

        // ⚠️ المندوب أو المخزن اتشالوا بعد الإنشاء (nullOnDelete)؟
        // الموافقة بتعمل أمر تجهيز منهم — من غيرهم مفيش قرار.
        if ($data['decision'] === 'approved'
            && ($purchaseOrder->warehouse === null || $purchaseOrder->courier === null)) {
            return back()->withErrors(['decision' => __('ops.po_needs_rep_wh')]);
        }

        if ($data['decision'] === 'rejected') {
            $purchaseOrder->update([
                'approval_status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_note' => $data['note'] ?? null,
                'status' => 'cancelled',
            ]);

            // مدير القناة يعرف إن أمره اترفض وليه
            if ($purchaseOrder->created_by) {
                AppNotification::send(
                    User::find($purchaseOrder->created_by),
                    fn () => __('field.notif_po_rejected_title', ['number' => $purchaseOrder->number]),
                    fn () => ($data['note'] ?? null) ?: $purchaseOrder->client->displayName(),
                    false,
                );
            }

            return back()->with('ok', __('flash.po_rejected'));
        }

        // ═══ موافقة (مع تعديل اختياري) ═══
        try {
            DB::transaction(function () use ($purchaseOrder, $data, $request) {
                $changes = [];

                // ⚠️ التعديل بيعيد حساب السطر بنفس سعره وضريبته —
                // السعر ثابت من وقت الإنشاء، الكمية بس اللي بتتغير.
                foreach ($data['qty_edit'] ?? [] as $itemId => $newQty) {
                    if ($newQty === null || $newQty === '') {
                        continue;
                    }

                    $item = $purchaseOrder->items->firstWhere('id', (int) $itemId);

                    if (! $item || (int) $newQty === (int) $item->qty) {
                        continue;
                    }

                    $changes[] = $item->product->displayName().': '.$item->qty.' ← '.(int) $newQty;

                    if ((int) $newQty === 0) {
                        $item->delete();

                        continue;
                    }

                    $lineTotal = round((int) $newQty * (float) $item->price, 2);
                    $item->update([
                        'qty' => (int) $newQty,
                        'total' => $lineTotal,
                        'tax' => round($lineTotal * (float) ($item->tax_rate ?? 0), 2),
                    ]);
                }

                $purchaseOrder->load('items');

                if ($purchaseOrder->items->isEmpty()) {
                    throw new \App\Exceptions\Rejected(__('ops.po_no_items_left'));
                }

                if ($changes !== []) {
                    $rows = $purchaseOrder->items
                        ->map(fn ($i) => ['total' => (float) $i->total, 'tax' => (float) $i->tax])
                        ->all();
                    $sums = \App\Services\Tax::totals($rows);

                    $purchaseOrder->update([
                        'total' => $sums['net'],
                        'tax_total' => $sums['tax'],
                        'grand_total' => $sums['grand'],
                        'was_edited' => true,
                        // تراك: مين عدّل الكميات وإمتى (2026-08-05)
                        'edited_by' => $request->user()->id,
                        'edited_at' => now(),
                    ]);
                }

                // ⚠️ **أمر التجهيز بيتعمل هنا** — طلب (requested) بينزل
                // شاشة «تجهيز الطلبات»، وتأكيد التجهيز هناك هو اللي
                // بيخصم ويبعت إشعار للمندوب (نفس فلو العهدة بالظبط).
                $result = \App\Models\PickOrder::raise(
                    $purchaseOrder->warehouse,
                    $purchaseOrder->courier,
                    $purchaseOrder->items->pluck('qty', 'product_id')->all(),
                    \App\Models\PickOrder::PURPOSE_CUSTOMER_PO,
                    $request->user(),
                    [
                        'purchase_order_id' => $purchaseOrder->id,
                        'pickup_at' => $purchaseOrder->pickup_at,
                    ],
                );

                if ($result['error']) {
                    throw new \App\Exceptions\Rejected($result['error']);
                }

                $purchaseOrder->update([
                    'approval_status' => 'approved',
                    'approved_by' => $request->user()->id,
                    'approved_at' => now(),
                    'approval_note' => $data['note'] ?? null,
                    'pick_order_id' => $result['order']->id,
                    // ⚠️ **بداية عدّاد تجهيز الأمر** — من لحظة ما
                    // الحسابات توافق وأمر التجهيز ينزل المخزن.
                    // من غيرها `PurchaseOrder::prepMinutes()` بترجّع
                    // `null` دايماً والعمود يبقى ميت.
                    'prep_started_at' => $purchaseOrder->prep_started_at ?? now(),
                ]);

                // مدير القناة يعرف إن أمره اتعدل وإيه اللي اتغير
                if ($changes !== [] && $purchaseOrder->created_by) {
                    AppNotification::send(
                        User::find($purchaseOrder->created_by),
                        fn () => __('field.notif_po_edited_title', ['number' => $purchaseOrder->number]),
                        fn () => implode(' · ', $changes),
                        false,
                    );
                }
            });
        } catch (\App\Exceptions\Rejected $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return back()->with('ok', __('flash.po_approved'));
    }

    public function assignPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);

        // ⚠️ **الميثود دي كانت بلا أي حارس إطلاقاً** (تدقيق ٨/٨/٢٠٢٦):
        // أي مدير كان يعيد تسكين أي أمر في الشركة على أي يوزر. الحارس
        // بيفحص الأمر نفسه (عميله في نطاق الفاعل) والمستلم الجديد.
        Scope::assertRep(
            $request->user(),
            User::find($data['assigned_to']),
            $purchaseOrder->client,
        );

        $purchaseOrder->update($data);

        AppNotification::send(
            User::find($data['assigned_to']),
            fn () => __('field.notif_po_assigned_title', ['number' => $purchaseOrder->number]),
            fn () => $purchaseOrder->client->displayName(),
            link: AppNotification::poLink($purchaseOrder->id),
        );

        return back()->with('ok', __('flash.po_assigned'));
    }

    // ================= موافقات العملاء الجدد =================

    public function requests(Request $request)
    {
        // ⚠️ **سكوب الفريق** (تدقيق ٨/٨/٢٠٢٦): القايمة كانت على مستوى
        // الشركة، فمدير بيشوف — ويقرر في — طلبات مناديب مدير تاني.
        // الفلترة على المندوب صاحب الطلب، مش على القناة، عشان تتطابق
        // مع `decideRequest` تحت.
        // ⚠️ الفلتر **للمدير بس** مش لكل حد. `whereIn(created_by, ...)`
        // على الأدمن كان هيخفي أي طلب `created_by` بتاعه null — وده
        // بيخبّي طلبات بدل ما يحميها.
        $q = ClientRequest::with(['rep', 'zone', 'client'])
            ->when(
                $request->user()?->role === 'manager',
                fn ($w) => $w->whereIn('created_by',
                    User::fieldVisibleTo(User::query(), $request->user())->select('id')),
            );

        if ($status = $request->string('status')->value()) {
            $q->where('status', $status);
        }

        $requests = $q->latest()->paginate(30)->withQueryString();

        // ═══ تشابه مع عملاء موجودين (١٥ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **الشاشة دي آخر فرصة نمسك فيها التكرار.** المندوب ممكن
        // يكون عدّى حارس الأبلكيشن بـ«متأكد إنه مختلف»، وممكن يكون
        // العميل الشبيه اتعمل من الويب بعد ما هو بعت طلبه. المعتمِد
        // لازم يشوف التشابه قبل ما يدوس «اعتماد» — بعدها بيبقى فيه
        // حسابين لنفس المحل ومحدش يعرف يدمجهم.
        //
        // ⚠️ للطلبات المفتوحة بس — المقفولة اتقرر فيها خلاص، وفحصها
        // بيضيف 30 كويري في الصفحة على معلومة محدش هيتصرف فيها.
        $dupes = [];

        foreach ($requests as $r) {
            if ($r->isOpen()) {
                $dupes[$r->id] = \App\Support\Dupes::matches([
                    'name' => $r->name,
                    'phone' => $r->phone,
                    'zone_id' => $r->zone_id,
                ], $r->client_id, $request->user());
            }
        }

        return view('ops.requests', [
            'requests' => $requests,
            'dupes' => $dupes,
            'zones' => Zone::orderBy('code')->get(['id', 'code', 'name', 'name_en', 'governorate']),
            'filters' => $request->only('status'),
            // ═══ داتا فورم الاعتماد الغني (١١ أغسطس ٢٠٢٦) ═══
            // نفس مصادر `ErpController::clientFormData` عشان العميل
            // المعتمد يطلع متسق مع اللي بيتعمل من شاشة العميل.
            'channels' => Channel::orderBy('id')->get(),
            'priceLists' => PriceList::where('active', true)->orderBy('id')->get(),
            'groups' => ClientGroup::where('active', true)->orderBy('name')->get(),
            'governorates' => \App\Support\Governorates::options(),
        ]);
    }

    public function decideRequest(Request $request, ClientRequest $clientRequest)
    {
        // ⚠️ كل الحقول اختيارية إلا القرار — «مراجعة» و«رفض» بيبعتوا
        // ملاحظة بس، والاعتماد بيملا الباقي (كلها مبدئية والمدير يعدّل
        // من كارت العميل بعدين).
        $data = $request->validate([
            'decision' => ['required', 'in:approved,review,rejected'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'governorate' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:190'],
            'address_ar' => ['nullable', 'string', 'max:190'],
            'channel_id' => ['nullable', 'exists:channels,id'],
            'sub_channel' => ['nullable', 'in:'.implode(',', array_keys(Channel::SUB_CHANNELS))],
            'price_list_id' => ['nullable', 'exists:price_lists,id'],
            'group_id' => ['nullable', 'exists:client_groups,id'],
            'has_contract' => ['nullable', 'boolean'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            // تجاوز واعٍ لحارس التكرار — تشيك بوكس في المودال
            'confirm_duplicate' => ['nullable', 'boolean'],
        ]);

        // ⚠️ **التوأم في الأبلكيشن بيرجّع 403 والويب كان بيعدّي** —
        // مسار الويب كان مفيهوش حارس خالص، فمدير بيعتمد عميل مندوب
        // مدير تاني. الحارس على المندوب صاحب الطلب.
        Scope::assertRep($request->user(), $clientRequest->rep);

        // ═══ حارس التكرار قبل ما العميل يتخلق (١٥ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **الاعتماد كان بيعمل `Client::create` من غير أي فحص.**
        // شاشة الـERP بتفحص من ٦ أغسطس، والاستيراد بيفحص — والمسار
        // ده، اللي بيولّد أغلب العملاء الجداد فعلاً، ماكانش بيفحص.
        // نفس `Dupes::matches` بتاعة الشاشة والأبلكيشن بالحرف.
        //
        // ⚠️ **مش قبل الترانزاكشن بمسافة** — لازم يكون آخر حاجة قبل
        // ما نكتب. وبيتخطّى لو المعتمِد علّم «متأكد إنه مختلف».
        if ($data['decision'] === 'approved' && empty($data['confirm_duplicate'])) {
            $dupes = \App\Support\Dupes::matches([
                'name' => $clientRequest->name,
                'phone' => $clientRequest->phone,
                'zone_id' => $data['zone_id'] ?? $clientRequest->zone_id,
                'group_id' => $data['group_id'] ?? null,
            ], $clientRequest->client_id, $request->user());

            if ($dupes !== []) {
                return back()->withInput()->withErrors([
                    'decision' => __('ops.dup_blocked', [
                        'names' => collect($dupes)->take(3)
                            ->map(fn ($d) => $d['name'].' ('.$d['code'].')')
                            ->implode(' · '),
                    ]),
                ]);
            }
        }

        DB::transaction(function () use ($data, $clientRequest, $request) {
            $clientRequest->status = $data['decision'];
            $clientRequest->decided_by = $request->user()->id;
            $clientRequest->decided_at = now();
            $clientRequest->decision_note = $data['note'] ?? null;

            if ($data['decision'] === 'approved') {
                // ⚠️ **العميل المعتمد كان بيتولد يتيم** (تدقيق ٨/٨):
                // بلا قناة ولا مندوب ولا مدير ولا فرع — فمابيظهرش في
                // `Client::visibleTo` لأي حد، والمندوب اللي فتحه
                // مابيلاقيهوش في الزون. الوراثة من المندوب صاحب الطلب.
                $rep = $clientRequest->rep;

                $discount = (float) ($data['discount'] ?? 0);

                // ⚠️ **العقد الكامل مش هنا.** الاعتماد بيسجّل خصم +
                // قائمة سعر مباشرة على العميل (خصم العميل يغلب حسب
                // الترتيب المقدّس). الـ٢٢ بند بيتظبّطوا من شاشة تعديل
                // العميل. لو المدير علّم «عليه عقد»، بنسيبله ملاحظة
                // يفكّره يكمّله من هناك — من غير ما نعمل عقد فاضي.
                // ⚠️ ولو فرع سلسلة (`group_id`)، عقد السلسلة هو اللي
                // بيحكم — فمابنقترحش عقد فردي (دوكترين promax-clients).
                $wantsContract = ! empty($data['has_contract']) && empty($data['group_id']);

                $client = Client::create([
                    'code' => Client::nextCode(),
                    'name' => $clientRequest->name,
                    'phone' => $clientRequest->phone,
                    // العنوان الإنجليزي والعربي والنقطة من الفورم
                    // (المدير راجعها/كشفها) وإلا من الطلب نفسه.
                    'address' => $data['address'] ?? $clientRequest->address,
                    'address_ar' => $data['address_ar'] ?? $clientRequest->address_ar,
                    'lat' => $clientRequest->lat,
                    'lng' => $clientRequest->lng,
                    'governorate' => $data['governorate'] ?? null,
                    'zone_id' => $data['zone_id'] ?? $clientRequest->zone_id ?? $rep?->zone_id,
                    'rep_id' => $rep?->id,
                    // القناة من الفورم وإلا قناة المندوب. `sub_channel`
                    // للكي أكاونت بس — `Client::booted()` بيصفّيها لو
                    // القناة مش كده.
                    'channel_id' => $data['channel_id'] ?? $rep?->channel_id,
                    'sub_channel' => $data['sub_channel'] ?? null,
                    'price_list_id' => $data['price_list_id'] ?? null,
                    'group_id' => $data['group_id'] ?? null,
                    // ⚠️ المدير بييجي من تسكين المندوب مش من الفاعل —
                    // الأدمن بيعتمد لمناديب مديرين مختلفين.
                    'manager_id' => $rep?->manager_id,
                    'branch_id' => $rep?->branch_id,
                    'category' => 'grow',
                    'status' => 'active',
                    'discount' => $discount / 100,
                    // ⚠️ **توحيد مع التوأم في الـAPI** — الويب ماكانش
                    // بيكتب العمود ده، فنفس الطلب كان بيطلّع عميل
                    // بإعداد خصم مختلف حسب اتعتمد من الويب ولا من
                    // الأبلكيشن.
                    'uses_channel_discount' => $discount <= 0,
                    'notes' => $wantsContract ? __('ops.contract_pending_note') : null,
                    'is_new' => true,
                    'has_docs' => $clientRequest->has_docs,
                    'photo_path' => $clientRequest->photo_path,
                    'docs_path' => $clientRequest->docs_path,
                    'docs_type' => $clientRequest->docs_type,
                    'created_by' => $clientRequest->created_by,
                ]);
                $clientRequest->client_id = $client->id;

                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_approved_title', ['name' => $clientRequest->name]),
                    fn () => __('field.notif_client_approved_body'),
                    link: AppNotification::requestLink($clientRequest->id),
                );
            } elseif ($data['decision'] === 'review') {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_review_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_review_body'),
                    link: AppNotification::requestLink($clientRequest->id),
                );
            } else {
                AppNotification::send(
                    $clientRequest->rep,
                    fn () => __('field.notif_client_rejected_title', ['name' => $clientRequest->name]),
                    fn () => $data['note'] ?? __('field.notif_client_rejected_body'),
                    false,
                    link: AppNotification::requestLink($clientRequest->id),
                );
            }

            $clientRequest->save();
        });

        return back()->with('ok', __('flash.decision_recorded'));
    }

    // ================= التراكينج =================

    public function tracking(Request $request)
    {
        $userId = $request->integer('user');
        $date = $request->date('date') ?? today();

        $q = TrackEvent::with('user')->whereDate('happened_at', $date);
        if ($userId) {
            $q->where('user_id', $userId);
        }

        // ⚠️ سكوب الفريق: أحداث مناديب مدير تاني متابعة فردية مش
        // «رقم مجمّع» — نفس دوكترين fieldVisibleTo في كل الشاشات.
        $visibleIds = User::fieldVisibleTo(User::query())->pluck('id');
        $events = $q->whereIn('user_id', $visibleIds)
            ->orderByDesc('happened_at')->get()
            ->filter(fn ($e) => $e->user !== null)->values();

        // ═══ إعادة بناء ٩ أغسطس: كل المناديب مرة واحدة ═══
        // لون ثابت لكل مندوب في العرض ده — الماركرز والمسار وحد
        // التايم لاين كلهم بنفس اللون، عشان العين تفرز من غير قراءة.
        $palette = [
            '#12399B', '#B00020', '#0F766E', '#B45309', '#602D90',
            '#DB2777', '#2563EB', '#059669', '#7C2D12', '#4338CA',
        ];
        $reps = $events->pluck('user')->unique('id')->values();
        $colors = [];
        foreach ($reps as $i => $u) {
            $colors[$u->id] = $palette[$i % count($palette)];
        }

        return view('ops.tracking', [
            'events' => $events,
            'reps' => $reps,
            'colors' => $colors,
            'field' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))->get(),
            'userId' => $userId,
            'date' => $date->toDateString(),
        ]);
    }

    // ================= الفواتير =================

    public function invoices(Request $request)
    {
        // ⚠️ سكوب التشانل مانجر (2026-08-05): فواتير عملائه بس —
        // والإجمالي من نفس الكويري فمفيش نطاقين مختلطين.
        $u = auth()->user();
        $q = Invoice::with(['client', 'user'])
            ->when($u?->role === 'manager',
                fn ($q2) => $q2->whereIn('client_id', Client::visibleTo(Client::query(), $u)->select('id')));
        if ($userId = $request->integer('user')) {
            $q->where('user_id', $userId);
        }
        if ($from = $request->string('from')->value()) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->string('to')->value()) {
            $q->whereDate('created_at', '<=', $to);
        }

        return view('ops.invoices', [
            'invoices' => $q->latest()->paginate(40)->withQueryString(),
            'field' => User::fieldVisibleTo(User::whereIn('role', User::FIELD_WORK_ROLES))->get(),
            'filters' => $request->only(['user', 'from', 'to']),
            'sum' => (clone $q)->sum('total'),
        ]);
    }

    public function invoice(Invoice $invoice)
    {
        abort_unless(
            request()->user()->canSeeBranch($invoice->client->branch_id), 403,
        );
        // ⚠️ سكوب التشانل مانجر — فاتورة عميل مش بتاعه ماتتفتحش بالـid
        abort_unless($invoice->client->visibleBy(request()->user()), 403);

        $invoice->load(['items.product', 'client', 'user', 'visit']);

        // ⚠️ الفاتورة ممكن تجمع نسب مختلفة (صنف بـ 14% وصنف معفى).
        // لو النسب اتعددت مانكتبش نسبة واحدة جنب سطر الضريبة — كتابة
        // نسبة واحدة على فاتورة مختلطة رقم غلط على مستند رسمي.
        $rates = $invoice->items
            ->filter(fn ($i) => (float) $i->tax > 0)
            ->pluck('tax_rate')->map(fn ($r) => round((float) $r, 4))->unique();

        return view('ops.invoice', [
            'inv' => $invoice,
            'taxRateLabel' => $rates->count() === 1 ? \App\Services\Tax::label($rates->first()) : '',
            'companyTaxId' => \App\Models\Setting::read('company_tax_id'),
        ]);
    }

    /** تسجيل تحصيل نقدي من عميل */
    public function collect(Request $request, Client $client)
    {
        // ⚠️ سكوب التشانل مانجر — مايحصّلش من عميل مش بتاعه
        abort_unless($client->visibleBy($request->user()), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'memo' => ['nullable', 'string', 'max:190'],
            'date' => ['nullable', 'date'],
            // ═══ طريقة التحصيل (قرار المالك ٨/٨/٢٠٢٦) ═══
            'method' => ['required', \Illuminate\Validation\Rule::in(Transaction::METHODS)],
            // ⚠️ **إجباري لغير النقدي** — `required_unless` مش
            // `nullable`: من غير ريفرنس المطابقة مع البنك أو الماكينة
            // مستحيلة، والفرق بيتكتشف آخر الشهر ومحدش يعرف مصدره.
            'reference' => ['nullable', 'required_unless:method,cash', 'string', 'max:100'],
            // بيانات الشيك — إجبارية للشيك بس
            'cheque_bank' => ['nullable', 'required_if:method,cheque', 'string', 'max:120'],
            'cheque_due' => ['nullable', 'required_if:method,cheque', 'date'],
        ]);

        // ⚠️ **جوّه ترانزاكشن** (تدقيق ٨/٨/٢٠٢٦). القيد و`recalculate()`
        // كانوا سطرين مكشوفين — ولو الطلب اتقطع بينهم، القيد بيتكتب
        // والأعمدة المجمّعة مابتتحدّثش. **ده السبب رقم ١ الموثّق
        // لـ«رصيد العميل ≠ كشف حسابه»**، وكان بيتصلح بإعادة حساب
        // يدوية من غير ما حد يعرف مصدره.
        DB::transaction(function () use ($client, $data) {
            Transaction::create([
                'client_id' => $client->id,
                'date' => $data['date'] ?? today(),
                'memo' => $data['memo'] ?? __('flash.memo_cash_collection'),
                'debit' => 0,
                'credit' => $data['amount'],
                'kind' => 'collection',
                // ⚠️ **الشيك قيده زي الكاش بالظبط** (قرار المالك) —
                // بيدخل حساب العميل فوراً، والفرق في البيانات
                // المرفقة مش في المحاسبة.
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'cheque_bank' => $data['method'] === Transaction::METHOD_CHEQUE
                    ? ($data['cheque_bank'] ?? null) : null,
                'cheque_due' => $data['method'] === Transaction::METHOD_CHEQUE
                    ? ($data['cheque_due'] ?? null) : null,
            ]);

            $client->recalculate();
        });

        return back()->with('ok', __('flash.collection_recorded'));
    }
}
