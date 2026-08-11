<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\RepSettlement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصفية المناديب — قفلة الحسابات اليومية (2026-08-06)
 * ═══════════════════════════════════════════════════════════════
 *
 * المحاسب بيقعد مع المندوب آخر اليوم:
 *  1. السيستم مطلّع كل فواتيره **من آخر تصفية** — كاش وآجل.
 *  2. النقدية المتوقعة = فواتير الكاش (بالإجمالي شامل الضريبة —
 *     نفس اللي العميل دفعه) − مرتجعات الكاش اللي ردّها نقدي.
 *  3. المحاسب بيستلم المبلغ ويسجّله — والفرق بيترحّل رصيد:
 *     موجب = عليه (مدين) · سالب = ليه (دائن).
 *
 * ⚠️ **مفيش لمس لليدجر بتاع العملاء هنا.** فلوس العملاء اتقيدت وقت
 * الفاتورة (sale + collection) — دي تصفية **نقدية المندوب** مع
 * الخزنة، مش حسابات عملاء. النافذة الزمنية (من آخر تصفية لحد لحظة
 * القفل) بتضمن إن مفيش فاتورة بتتحسب مرتين ولا بتضيع.
 */
class RepSettlementController extends Controller
{
    /** المناديب بأرصدتهم وأرقام الفترة المفتوحة — نظرة واحدة */
    public function index()
    {
        $reps = User::whereIn('role', ['sales_agent', 'driver', 'manager']) // المدير بيتصفّى كمان (١١/٨ مساءً)
            ->where('active', true)->orderBy('name')->get();

        $rows = $reps->map(function (User $rep) {
            $figures = $this->openFigures($rep);

            return ['rep' => $rep] + $figures;
        });

        return view('erp.repclose', [
            'rows' => $rows,
            'recent' => RepSettlement::with(['user', 'creator'])
                ->latest('to_at')->limit(15)->get(),
        ]);
    }

    /** شاشة تصفية مندوب واحد — الفواتير بالتفصيل للمطابقة قدام المحاسب */
    public function show(User $user)
    {
        // ⚠️ المدير بيتصفّى كمان (١١/٨) — نفس قايمة الأدوار الميدانية
        abort_unless(in_array($user->role, User::FIELD_WORK_ROLES, true), 404);

        $figures = $this->openFigures($user);

        return view('erp.repclose_show', [
            'rep' => $user,
            'invoices' => $figures['invoices'],
            'refundRows' => $figures['refund_rows'],
            // ⚠️ «الفلوس دي لمين» — المحاسب بيسأل السؤال ده في كل
            // تصفية، والإجابة كانت بتتطلع بالعين من 14 سطر فاتورة
            'cashByClient' => $this->byClient($figures['invoices'], 'cash'),
            'creditByClient' => $this->byClient($figures['invoices'], 'credit'),
        ] + $figures);
    }

    /** قفل التصفية — الأرقام بتتجمد والرصيد بيترحّل */
    public function store(Request $request, User $user)
    {
        abort_unless(in_array($user->role, User::FIELD_WORK_ROLES, true), 404);

        $data = $request->validate([
            'received' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:500'],
            // ⚠️ قفل العهدة مع التصفية (طلب المالك ١١/٨) — التصفية كانت
            // بتقفل الفلوس وتسيب العهدة مفتوحة، فالمندوب يتحبس عند
            // الانصراف («عهدتك لسه مفتوحة») والمالك مش لاقي إيه الناقص.
            'close_custody' => ['nullable', 'boolean'],
        ]);

        $settlement = DB::transaction(function () use ($user, $data, $request) {
            // ⚠️ الأرقام بتتحسب جوه الترانزاكشن — فاتورة بتتسجل في نفس
            // اللحظة يا إما جوه النافذة يا إما في التصفية الجاية.
            $f = $this->openFigures($user);

            $received = round((float) $data['received'], 2);
            $balance = round($f['prev_balance'] + $f['expected'] - $received, 2);

            // ⚠️ **قفل العهدة مع التصفية** (١١/٨): التصفية بتقفل الفلوس،
            // والعهدة قفلتها كانت زرار منفصل في «عهد المناديب» — المحاسب
            // بينسى، والمندوب يتحبس عند الانصراف بـ«عهدتك لسه مفتوحة».
            // التشيك بوكس متعلّم افتراضياً، ويتشال لو العربية هتكمل بكرة.
            if ($request->boolean('close_custody')) {
                $user->currentCustody()?->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                ]);
            }

            return RepSettlement::create([
                'number' => RepSettlement::nextNumber(),
                'user_id' => $user->id,
                'from_at' => $f['from_at'],
                'to_at' => $f['to_at'],
                'invoices_count' => $f['invoices']->count(),
                'cash_sales' => $f['cash_sales'],
                'credit_sales' => $f['credit_sales'],
                'cash_refunds' => $f['cash_refunds'],
                'cash_collections' => $f['cash_collections'],
                'expected' => $f['expected'],
                'prev_balance' => $f['prev_balance'],
                'received' => $received,
                'balance' => $balance,
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,

                // ⚠️ لقطة التحصيلات — الشيك اللي المندوب مضى إنه
                // سلّمه لازم يفضل على الورقة بنفس أرقام لحظتها
                'collections_json' => $f['collection_rows']->map(fn ($t) => [
                    'client' => $t->client?->fullName() ?? '—',
                    'amount' => (float) $t->credit,
                    'method' => $t->method,
                    'method_label' => $t->methodLabel(),
                    'reference' => $t->reference,
                    'cheque_bank' => $t->cheque_bank,
                    'cheque_due' => $t->cheque_due?->toDateString(),
                    'at' => $t->created_at->format('m-d h:i A'),
                ])->values()->all(),

                // ⚠️ **لقطة البضاعة بتتجمّد مع الأرقام** (2026-08-08).
                // الورقة المطبوعة مستند بيتمضي — ولو قريناها من
                // العهدة الحية، فتحها بعد أسبوع كان بيوري أرقام
                // اليوم مش أرقام لحظة التوقيع.
                'goods_json' => $f['goods']['lines']->map(fn ($l) => [
                    'name' => $l['product']?->displayName() ?? '—',
                    // ⚠️ **المحمَّل الكامل** (عادي + هدايا) — نفس الرقم
                    // اللي المعادلة بتقفل عليه في الشاشة
                    'assigned' => $l['loaded'],
                    'cash_qty' => $l['cash_qty'], 'cash_value' => $l['cash_value'],
                    'credit_qty' => $l['credit_qty'], 'credit_value' => $l['credit_value'],
                    'po_qty' => $l['po_qty'],
                    'gift' => $l['gift_given'],
                    'gift_left' => $l['gift_left'],
                    'returned_wh' => $l['returned'],
                    'returned_in' => $l['returned_in'],
                    'damaged_in' => $l['damaged_in'],
                    'remaining' => $l['remaining'],
                    'diff' => $l['diff'],
                ])->all(),
            ]);
        });

        return redirect()->route('erp.repclose.doc', $settlement)
            ->with('ok', __('settle.closed_ok', [
                'number' => $settlement->number,
                'balance' => number_format(abs((float) $settlement->balance), 2),
            ]));
    }

    /** ورقة التصفية — بتتطبع وتتمضي من المندوب والمحاسب */
    public function doc(RepSettlement $settlement)
    {
        $settlement->load(['user', 'creator']);

        return view('erp.repclose_doc', ['s' => $settlement]);
    }

    /**
     * أرقام الفترة المفتوحة لمندوب: من آخر تصفية لحد دلوقتي.
     *
     * النقدية المتوقعة = Σ فواتير كاش (grand_total — نفس عقيدة
     * الليدجر: اللي العميل دفعه فعلاً) − Σ قيود `refund` (مرتجع كاش
     * اتردّ نقدي) على زيارات المندوب في نفس النافذة.
     */
    private function openFigures(User $rep): array
    {
        $last = RepSettlement::lastFor($rep->id);
        $from = $last?->to_at;
        $now = now();

        $invoices = Invoice::with('client')
            ->where('user_id', $rep->id)
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->orderBy('created_at')
            ->get();

        $cashSales = round((float) $invoices->where('payment', 'cash')->sum('grand_total'), 2);
        $creditSales = round((float) $invoices->where('payment', '!=', 'cash')->sum('grand_total'), 2);

        // ═══ أوامر التوريد المسلَّمة في النافذة (تدقيق ٨/٨/٢٠٢٦) ═══
        //
        // ⚠️ **كانت بره التصفية خالص.** السواق بيسلّم أمر توريد لعميل
        // كاش، بيقبض الفلوس في إيده، والتصفية ماكانتش شايفة الفلوس
        // دي — فبيمشي بكاش مش متسجّل، والعميل عليه مديونية وهمية.
        // (القيد المقابل بقى بيتكتب في `FieldApiController::deliver`.)
        //
        // ⚠️ **من القيود مش من `purchase_orders`** — القيد هو اللي
        // بيعرف اتحصّل ولا لأ، وهو مصدر الحقيقة للفلوس. قراية
        // `grand_total` من الأمر كانت هتعدّ الأمانة كمان.
        $poCash = round((float) Transaction::where('kind', 'collection')
            ->where('source_type', \App\Models\PurchaseOrder::class)
            ->whereIn('source_id', \App\Models\PurchaseOrder::where('assigned_to', $rep->id)->select('id'))
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->sum('credit'), 2);

        $poCredit = round((float) Transaction::where('kind', 'sale')
            ->where('source_type', \App\Models\PurchaseOrder::class)
            ->whereIn('source_id', \App\Models\PurchaseOrder::where('assigned_to', $rep->id)->select('id'))
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->sum('debit'), 2);

        // الآجل = مدين الأوامر ناقص اللي اتحصّل منه نقدي في نفس اللحظة
        $cashSales = round($cashSales + $poCash, 2);
        $creditSales = round($creditSales + max(0, $poCredit - $poCash), 2);

        // ═══ تفاصيل أوامر التوريد المسلَّمة — للعرض (طلب المالك ١١/٨) ═══
        //
        // ⚠️ **الرقم كان بيبان والتفصيلة لأ:** «مبيعات آجل 20,727»
        // في الكارت بس الجدول «مفيش» لأنه بيقرا `invoices` بس —
        // والآجل ده جاي من أوامر التوريد المسلَّمة. المحاسب بيسأل
        // «الفلوس دي لمين» فلازم يشوف الأوامر بأسماء عملائها.
        $poRows = \App\Models\PurchaseOrder::where('assigned_to', $rep->id)
            ->where('status', 'delivered')
            ->when($from, fn ($q) => $q->where('delivered_at', '>', $from))
            ->where('delivered_at', '<=', $now)
            ->with('client')
            ->orderByDesc('delivered_at')
            ->get();

        // ═══ مستندات المرتجع في النافذة (٨ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **المرتجع بقى مستند ليه بنود وحالة.** المحاسب لازم يشوف
        // رجّع إيه بالظبط، وكام منه سليم وكام تالف — لأن السليم
        // بيرجع للبيع والتالف بيتسلّم للمخزن لوحده. قبل كده كان
        // قيد دائن في كشف الحساب وبس.
        // ⚠️ `client.group` محمّلة — `fullName()` بتعرض «السلسلة —
        // الفرع»، ومن غيرها كويري لكل صف.
        $returns = \App\Models\ClientReturn::with(['client.group'])
            ->where('user_id', $rep->id)
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->latest()->get();

        // مرتجعات الكاش اللي المندوب ردّ قيمتها نقدي — قيود refund
        // مصدرها زيارات المندوب ده في النافذة
        $refundRows = Transaction::where('kind', 'refund')
            ->where('source_type', Visit::class)
            ->whereIn('source_id', Visit::where('user_id', $rep->id)->select('id'))
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->with('client')
            ->get();
        // ⚠️ **ومرتجعات الكاش اللي اتسجّلت من الـERP على المندوب ده**
        // (تدقيق ٨/٨). مستند الـERP مالوش زيارة، فقيد الـ`refund`
        // بتاعه مصدره `ClientReturn` مش `Visit` — والفلتر فوق كان
        // بيفوّته، فالمندوب بيتحاسب على كاش هو ردّه فعلاً.
        $erpRefunds = Transaction::where('kind', 'refund')
            ->where('source_type', \App\Models\ClientReturn::class)
            ->whereIn('source_id', \App\Models\ClientReturn::where('user_id', $rep->id)->select('id'))
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->with('client')
            ->get();

        $refundRows = $refundRows->concat($erpRefunds);
        $cashRefunds = round((float) $refundRows->sum('debit'), 2);

        // ═══ التحصيلات الميدانية (٩ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **المندوب بقى بيحصّل من المديونية أثناء الزيارة** — قيود
        // `collection` مصدرها زياراته (نفس مرساة الـ`refund` فوق).
        //
        // ⚠️⚠️ **الكاش بس هو اللي بيدخل «المتوقع».** الشيك والتحويل
        // والكارت فلوس **ماوصلتش إيده** — دخلت البنك أو في الدرج
        // كورقة. حسابها عليه كان معناه إن كل شيك يستلمه يطلع عجز
        // نقدي بنفس قيمته في تصفيته. غير الكاش بيتعرض قايمة مستقلة
        // بمرجعها وصورة إثباتها — تسليم مستندات مش فلوس.
        $collectionRows = Transaction::where('kind', 'collection')
            ->where('source_type', Visit::class)
            ->whereIn('source_id', Visit::where('user_id', $rep->id)->select('id'))
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            // ⚠️ `client.group` مش `client` — الشاشة بتعرض `fullName()`
            // اللي بيلمس السلسلة، ومن غيرها كويري لكل صف
            ->with(['client.group'])
            ->get();

        $cashCollections = round((float) $collectionRows
            ->where('method', Transaction::METHOD_CASH)->sum('credit'), 2);
        $otherCollections = $collectionRows
            ->where('method', '!=', Transaction::METHOD_CASH)->values();

        $expected = round($cashSales + $cashCollections - $cashRefunds, 2);
        $prev = round((float) ($last?->balance ?? 0), 2);

        return [
            'from_at' => $from,
            'to_at' => $now,
            'invoices' => $invoices,
            'returns' => $returns,
            'returns_good' => (int) $returns->sum('good_units'),
            'returns_damaged' => (int) $returns->sum('damaged_units'),
            'returns_value' => round((float) $returns->sum('grand_total'), 2),
            'refund_rows' => $refundRows,
            'cash_sales' => $cashSales,
            'credit_sales' => $creditSales,
            'po_rows' => $poRows,
            'po_cash' => $poCash,
            'po_credit' => round(max(0, $poCredit - $poCash), 2),
            'cash_refunds' => $cashRefunds,
            'collection_rows' => $collectionRows,
            'cash_collections' => $cashCollections,
            'other_collections' => $otherCollections,
            'other_collections_value' => round((float) $otherCollections->sum('credit'), 2),
            'expected' => $expected,
            'prev_balance' => $prev,
            'due_total' => round($prev + $expected, 2),
            'last' => $last,

            // ═══ الجانب التاني من التصفية: البضاعة (2026-08-08) ═══
            'goods' => $this->goodsReconciliation($rep, $from, $now, $invoices),
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * مطابقة العهدة — بالقطع مش بالفلوس
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **التصفية كانت بتقفل الفلوس وتسيب البضاعة.** المحاسب كان
     * بيستلم كاش ويمضي، والعربية فيها بضاعة محدش عدّها — يعني
     * العجز مابيظهرش غير في الجرد الشهري، وساعتها محدش يعرف حصل
     * إمتى ولا مع مين.
     *
     * ═══════════════════════════════════════════════════════════
     * المعادلة — اتصلحت في تدقيق ٨/٨/٢٠٢٦
     * ═══════════════════════════════════════════════════════════
     *
     *     المحمَّل (عادي + هدايا)
     *       = مباع كاش + مباع آجل + مسلَّم بأوامر توريد + هدايا اتوزّعت
     *       + مرجّع للمخزن + الباقي العادي + هدايا لسه في العربية
     *
     * **المعادلة القديمة كانت غلطانة في أربع حدود، وكل مندوب بيسلّم
     * PO أو بيدّي هدية أو بياخد مرتجع كان بيطلع بعجز وهمي:**
     *
     * 1. ⚠️ **الهدايا في عمود لوحدها.** `gift_assigned` مش جوّه
     *    `assigned` خالص — فعدّ `gift_given` في طرف «المصروف» من غير
     *    ما يزوّد `gift_assigned` في «المحمَّل» كان بيخلّي كل هدية
     *    تتوزّع تبان زيادة، وكل هدية لسه في العربية تبان عجز.
     * 2. ⚠️ **تسليم أمر التوريد بيزوّد `sold` من غير فاتورة.**
     *    `remaining()` بتطرحه، والمباع بيتقرا من `invoice_items` —
     *    فالقطع دي كانت بتختفي من الطرفين وتبان عجز صافي.
     * 3. ⚠️ **المرتجع للمخزن (`returned`) ماكانش في المعادلة أصلاً**
     *    رغم إن `remaining()` بتطرحه.
     * 4. ⚠️ **`returned_in` (مرتجع العملاء) ماكانش ينفع يبقى طرف في
     *    المعادلة.** البضاعة دي مالهاش أصل في المحمَّل — دخلت
     *    العربية من العميل. حطها في طرف «الموجود» كان بيخلي كل
     *    مرتجع يبان **زيادة**. مكانها الصح: خانة مستقلة «بضاعة
     *    مرتجعة لازم تتسلّم مع التصفية».
     *
     * ⚠️ **الفرق ≠ صفر معناه عجز حقيقي.** مش خطأ حسابي: بضاعة خرجت
     * من العربية من غير فاتورة ولا أمر توريد ولا هدية ولا مرتجع.
     *
     * @return array{lines: \Illuminate\Support\Collection, ...}
     */
    private function goodsReconciliation(User $rep, $from, $now, $invoices): array
    {
        // ⚠️ **كل عهد الفترة مش عهدة النهارده.** المندوب اللي مااتصفّاش
        // من 3 أيام عنده 3 عهد، وقراية الأخيرة بس كانت بتخفي بضاعة
        // يومين.
        $custodies = \App\Models\Custody::with(['items.product'])
            ->where('user_id', $rep->id)
            ->when($from, fn ($q) => $q->where('created_at', '>', $from))
            ->where('created_at', '<=', $now)
            ->get();

        // ═══ 1. المحمَّل والباقي — من بنود العهدة ═══
        $rows = [];

        foreach ($custodies as $c) {
            foreach ($c->items as $it) {
                $pid = $it->product_id;

                $rows[$pid] ??= self::emptyRow($it->product);

                // ⚠️ **المحمَّل = العادي + الهدايا.** عمودين مختلفين في
                // الداتابيز، وحدة اقتصادية واحدة على العربية.
                $rows[$pid]['assigned'] += (int) $it->assigned;
                $rows[$pid]['gift_assigned'] += (int) $it->gift_assigned;
                $rows[$pid]['gift_given'] += (int) $it->gift_given;
                $rows[$pid]['gift_left'] += $it->giftLeft();

                $rows[$pid]['remaining'] += $it->remaining();
                $rows[$pid]['returned'] += (int) $it->returned;

                // ⚠️ بره المعادلة بقصد — دي بضاعة العملاء اللي في
                // العربية، مالهاش أصل في المحمَّل. بتتسلّم مع التصفية.
                //
                // ⚠️ **والتالف في خانة لوحده.** السليم بيرجع للبيع
                // والتالف بيتسلّم للمخزن منفصل — رقم واحد للاتنين
                // كان بيخلّي المحاسب يمضي على بضاعة نصها مش صالحة.
                $rows[$pid]['returned_in'] += (int) $it->returned_in;
                $rows[$pid]['damaged_in'] += (int) $it->damaged_in;
            }
        }

        // ═══ 1ب. المسلَّم بأوامر التوريد — من عهدة نفس المندوب ═══
        //
        // ⚠️ **الحد الناقص اللي كان بيبلع القطع.** `deliver` بينده
        // `$custody->deduct()` فبيزوّد `sold`، لكن مفيش `invoice_items`
        // للأمر — فالقطع كانت بتخرج من `remaining()` ومابتدخلش
        // «المباع»، وتبان عجز.
        //
        // ⚠️ `delivered_qty` مش `qty` — التسليم الجزئي مسموح، والخصم
        // من العهدة بيحصل بالمسلَّم فعلاً.
        $poItems = \App\Models\PurchaseOrderItem::whereIn(
            'purchase_order_id',
            \App\Models\PurchaseOrder::where('assigned_to', $rep->id)
                ->where('status', 'delivered')
                ->when($from, fn ($q) => $q->where('delivered_at', '>', $from))
                ->where('delivered_at', '<=', $now)
                ->select('id'),
        )->with('product')->get();

        foreach ($poItems as $pi) {
            $rows[$pi->product_id] ??= self::emptyRow($pi->product);
            $rows[$pi->product_id]['po_qty'] += (int) $pi->delivered_qty;
        }

        // ═══ 2. المباع — من بنود الفواتير، مقسوم كاش/آجل ═══
        //
        // ⚠️ **من `invoice_items` مش من `custody_items.sold`.**
        // العمود `sold` بيجمع الاتنين مع بعض، والمحاسب محتاج يعرف
        // أنهي قطع خرجت بفلوس في إيده وأنهي خرجت مديونية.
        $items = \App\Models\InvoiceItem::whereIn('invoice_id', $invoices->pluck('id'))
            ->get()
            ->groupBy('invoice_id');

        $payOf = $invoices->pluck('payment', 'id');

        foreach ($items as $invoiceId => $lines) {
            $cash = ($payOf[$invoiceId] ?? 'cash') === 'cash';

            foreach ($lines as $l) {
                $pid = $l->product_id;

                // ⚠️ صنف اتباع ومش في العهدة = حالة شاذة لازم تبان،
                // مش تتبلع — بنعمله صف بمحمَّل صفر فالفرق بيطلع سالب
                $rows[$pid] ??= self::emptyRow($l->product ?? \App\Models\Product::find($pid));

                $rows[$pid][$cash ? 'cash_qty' : 'credit_qty'] += (int) $l->qty;
                $rows[$pid][$cash ? 'cash_value' : 'credit_value'] +=
                    (float) $l->total + (float) $l->tax;
            }
        }

        // ═══ 3. الفرق لكل صنف ═══
        $lines = collect($rows)->map(function (array $r) {
            // المحمَّل = عادي + هدايا
            $r['loaded'] = $r['assigned'] + $r['gift_assigned'];

            // المصروف والموجود — **من غير `returned_in`**
            $accounted = $r['cash_qty'] + $r['credit_qty'] + $r['po_qty']
                + $r['gift_given'] + $r['returned']
                + $r['remaining'] + $r['gift_left'];

            $r['accounted'] = $accounted;
            $r['diff'] = $r['loaded'] - $accounted;
            $r['cash_value'] = round($r['cash_value'], 2);
            $r['credit_value'] = round($r['credit_value'], 2);

            return $r;
        })->sortByDesc('loaded')->values();

        return [
            'lines' => $lines,
            // ⚠️ `assigned` في الرد = **المحمَّل الكامل** (عادي + هدايا)
            // عشان الفيوز والمستند المطبوع يفضلوا على نفس المفتاح
            // والرقم يبقى هو اللي المعادلة بتقفل عليه.
            'assigned' => (int) $lines->sum('loaded'),
            'assigned_plain' => (int) $lines->sum('assigned'),
            'gift_assigned' => (int) $lines->sum('gift_assigned'),
            'cash_qty' => (int) $lines->sum('cash_qty'),
            'credit_qty' => (int) $lines->sum('credit_qty'),
            'po_qty' => (int) $lines->sum('po_qty'),
            'gift_qty' => (int) $lines->sum('gift_given'),
            'gift_left_qty' => (int) $lines->sum('gift_left'),
            'returned_wh_qty' => (int) $lines->sum('returned'),
            // ⚠️ بره المعادلة — بضاعة العملاء اللي لازم تتسلّم
            'returned_qty' => (int) $lines->sum('returned_in'),
            'damaged_qty' => (int) $lines->sum('damaged_in'),
            'remaining_qty' => (int) $lines->sum('remaining'),
            'diff_qty' => (int) $lines->sum('diff'),
            // ⚠️ القيم دي **شاملة الضريبة** — نفس عقيدة الليدجر
            // واللي العميل دفعه فعلاً، عشان تطابق أرقام الفلوس فوق
            'cash_value' => round($lines->sum('cash_value'), 2),
            'credit_value' => round($lines->sum('credit_value'), 2),
        ];
    }

    /**
     * صف مطابقة فاضي — مصدر واحد لشكل الصف.
     *
     * ⚠️ كان متكرر ٣ مرات بالإيد، وإضافة عمود جديد لواحد بس منهم
     * كانت بترمي «Undefined array key» في المعادلة.
     */
    private static function emptyRow($product): array
    {
        return [
            'product' => $product,
            'assigned' => 0, 'gift_assigned' => 0,
            'remaining' => 0, 'returned' => 0, 'returned_in' => 0, 'damaged_in' => 0,
            'gift_given' => 0, 'gift_left' => 0, 'po_qty' => 0,
            'cash_qty' => 0, 'cash_value' => 0.0,
            'credit_qty' => 0, 'credit_value' => 0.0,
        ];
    }

    /**
     * تفصيلة «الفلوس دي لمين» — لكل عميل: كام فاتورة وكام قطعة وبكام.
     *
     * ⚠️ **مجمّعة بالعميل مش بالفاتورة.** المحاسب بيسأل «الـ2,590
     * آجل دول على مين؟» — وقايمة 14 فاتورة مابتجاوبش، بينما 3 عملاء
     * بأرقامهم بتجاوب في ثانية.
     */
    private function byClient($invoices, string $payment): \Illuminate\Support\Collection
    {
        $rows = $invoices->filter(fn ($i) => $payment === 'cash'
            ? $i->payment === 'cash'
            : $i->payment !== 'cash');

        $qtyOf = \App\Models\InvoiceItem::whereIn('invoice_id', $rows->pluck('id'))
            ->selectRaw('invoice_id, SUM(qty) q')
            ->groupBy('invoice_id')
            ->pluck('q', 'invoice_id');

        return $rows->groupBy('client_id')->map(fn ($g) => [
            'client' => $g->first()->client,
            'count' => $g->count(),
            'qty' => (int) $g->sum(fn ($i) => $qtyOf[$i->id] ?? 0),
            'total' => round((float) $g->sum('grand_total'), 2),
        ])->sortByDesc('total')->values();
    }
}
