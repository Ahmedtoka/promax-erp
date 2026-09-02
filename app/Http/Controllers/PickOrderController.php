<?php

namespace App\Http\Controllers;

use App\Models\Custody;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;

/**
 * أوامر التجهيز — الوصلة بين المخزن والعربية.
 *
 * ثلاث سيناريوهات بتوصل هنا:
 *  1. المدير عايز يحمّل مندوب عهدة (van_load)
 *  2. أمر توريد لعميل/سلسلة (customer_po) — لو العهدة مش كفاية
 *  3. طلب ريفيل من بروموتر (replenishment) — نفس القاعدة
 */
class PickOrderController extends Controller
{
    public function index(Request $request)
    {
        // ⚠️ `purchaseOrder.client` لازم تتحمّل مع القايمة — العمود
        // بيعرض اسم الفرع لأوامر التوريد، ومن غيرها بيبقى
        // كويري لكل صف (N+1) على صفحة فيها 25 أمر.
        $q = PickOrder::with([
            'warehouse', 'rep', 'requester', 'items.product',
            'purchaseOrder:id,number,client_id,due_at', 'purchaseOrder.client:id,name,name_en',
        ])
            // ⚠️ أوامر الأونلاين ليها صفحتها المستقلة «تجهيز الأونلاين»
            // (قرار المالك ٣/٩: «في مكان لوحده») — هنا فلو العهدة بس،
            // وأكشنات الصفحة دي (تسليم لمندوب) مالهاش معنى لأوردر شحن
            ->where('purpose', '!=', PickOrder::PURPOSE_ONLINE);

        if ($status = $request->string('status')->value()) {
            $status === 'open' ? $q->open() : $q->where('status', $status);
        }
        if ($rep = $request->integer('rep')) {
            $q->where('assigned_to', $rep);
        }
        if ($wh = $request->integer('warehouse')) {
            $q->where('warehouse_id', $wh);
        }

        return view('wh.picks', [
            'orders' => $q->latest()->paginate(25)->withQueryString(),
            'warehouses' => Warehouse::where('active', true)->orderBy('type')->get(),
            'reps' => User::whereIn('role', User::FIELD_ROLES)->where('active', true)
                ->orderBy('name')->get(),
            'filters' => $request->only(['status', 'rep', 'warehouse']),
            'openCount' => PickOrder::open()->count(),
        ]);
    }

    // ⚠️ **`create()` و`store()` اتشالوا** (قرار المالك 2026-08-08).
    //
    // كان فيه تلات أماكن بتعمل أمر تجهيز: الشاشة دي، و«تسليم عهدة»،
    // وموافقة الحسابات على أمر توريد. التلاتة بيكتبوا في نفس الجدول
    // بأغراض مختلفة — والشاشة دي كانت بتحط `van_load` دايماً حتى لو
    // البضاعة رايحة لفرع كي أكاونت، فمحدش يعرف الأمر ده عهدة ولا توريد.
    //
    // دلوقتي: **العهدة** من `CustodyHandoutController` · **التوريد**
    // من موافقة الحسابات في `OpsController`. والمخزن هنا بينفّذ بس:
    // `start` → `ready` → المندوب يستلم من الأبلكيشن.

    public function show(PickOrder $pick)
    {
        // أوامر الأونلاين ليها صفحتها — حتى العرض، عشان زرايره
        // (تسليم/تعديل) مايتقدّموش لأمر مش بتاع الفلو ده
        abort_if($pick->purpose === PickOrder::PURPOSE_ONLINE, 404);

        $pick->load([
            'warehouse', 'rep', 'requester', 'picker', 'custody',
            'items.product', 'items.batch', 'items.location',
            'purchaseOrder.client', 'replenishmentRequest.client',
        ]);

        return view('wh.pick', [
            'o' => $pick,
            // كتالوج الأصناف لمنتقي «إضافة صنف» في تعديل إذن الصرف (١٠/٨)
            'products' => Product::where('active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'name_en']),
        ]);
    }

    /**
     * حارس المخزن — الأمين بيجهّز أوامر **مخازنه** بس.
     *
     * ⚠️ **الويب كان من غير السكوب ده خالص** (تدقيق ٩/٨) — أمين
     * مخزن المعادي كان يقدر يبدأ ويقفل أوامر مخزن تاني من المتصفح،
     * بينما توأمه في الـAPI (`KeeperApiController::guard`) بيرفض.
     * الأدمن والمدير بيعدّوا — دول بيديروا كل المخازن.
     */
    private function guardKeeperWarehouse(\App\Models\User $user, PickOrder $pick): bool
    {
        if (in_array($user->role, ['admin', 'manager'], true)) {
            return true;
        }

        return (int) $pick->warehouse?->manager_id === (int) $user->id
            || (int) $pick->warehouse_id === (int) $user->warehouse_id;
    }

    /**
     * ⚠️ حارس أوامر الأونلاين (٣/٩): أكشنات الشاشة دي لفلو العهدة —
     * markReady من هنا كان بيطلّع البضاعة والأوردر الأونلاين بيفضل
     * «جاري التجهيز» للأبد (مفيش ready ولا cost_total)، والإلغاء
     * بعدها بيضيّع البضاعة. أوامر ON- ليها صفحتها وأكشناتها.
     */
    private function rejectOnline(PickOrder $pick): void
    {
        abort_if($pick->purpose === PickOrder::PURPOSE_ONLINE, 404);
    }

    /** أمين المخزن بدأ يجمع من الأرفف */
    public function startPicking(Request $request, PickOrder $pick)
    {
        $this->rejectOnline($pick);
        abort_unless($this->guardKeeperWarehouse($request->user(), $pick), 403);

        if ($error = $pick->startPicking($request->user())) {
            return back()->withErrors(['status' => $error]);
        }

        return back()->with('ok', __('stock.pick_started'));
    }

    /** "جاهز" — البضاعة بتخرج من الأرفف وتتحجز للمندوب */
    public function markReady(Request $request, PickOrder $pick)
    {
        $this->rejectOnline($pick);
        abort_unless($this->guardKeeperWarehouse($request->user(), $pick), 403);

        $data = $request->validate([
            'picked' => ['nullable', 'array'],
            'picked.*' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($error = $pick->markReady($request->user(), $data['picked'] ?? null)) {
            return back()->withErrors(['status' => $error]);
        }

        return back()->with('ok', __('stock.pick_ready_done', ['number' => $pick->number]));
    }

    public function cancel(PickOrder $pick)
    {
        $this->rejectOnline($pick);

        if ($error = $pick->cancel()) {
            return back()->withErrors(['status' => $error]);
        }

        return back()->with('ok', __('stock.pick_cancelled'));
    }

    /**
     * تعديل أمر التجهيز قبل «جاهز» (١٠ أغسطس ٢٠٢٦) — الأمين يصلّح
     * كمية أو يشيل/يضيف صنف. حارس المخزن نفسه، والموديل بيرفض لو
     * الأمر اتجهّز أو اتسلّم.
     */
    public function update(Request $request, PickOrder $pick)
    {
        abort_unless($this->guardKeeperWarehouse($request->user(), $pick), 403);

        $data = $request->validate([
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'array'],
            'unit.*' => ['nullable', 'in:piece,box,case'],
        ]);

        // وحدة الإدخال → قطع في السيرفر (نفس قاعدة الإنشاء والفاتورة)
        $qty = [];
        foreach ($data['qty'] as $productId => $q) {
            $q = (int) $q;
            if ($q <= 0) {
                continue;
            }

            $unit = $request->input("unit.$productId", 'piece');
            if ($unit !== 'piece') {
                $factor = Product::find($productId)?->unitFactor($unit);
                if ($factor === null) {
                    return back()->withErrors([
                        'qty' => __('stock.unit_not_for_product', [
                            'name' => Product::find($productId)?->displayName() ?? $productId,
                        ]),
                    ])->withInput();
                }
                $q *= $factor;
            }

            $qty[(int) $productId] = $q;
        }

        // ═══ مزامنة أمر التوريد المرتبط (١٢ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **تعديل التجهيز كان بيسيب الأمر بكمياته القديمة** — فالسواق
        // يوصل الفرع ببضاعة مختلفة عن الورقة: الزيادة بيرفضها التسليم
        // (`po_over_delivery`)، والصنف الجديد «مش في الأمر»، والنقص
        // بيبان عجز وهمي. دلوقتي بنود الأمر بتتظبط مع التجهيز — بنفس
        // أسعار السطور المخزّنة (نفس حساب تعديل الحسابات في
        // `decidePoApproval` بالحرف)، ومفيش إعادة تسعير.
        //
        // ⚠️ **صنف جديد مش في الأمر = مرفوض.** إضافة صنف لأمر معتمد
        // قرار تجاري بيتسعّر — مساره تعديل الأمر نفسه (اللي بيرجّعه
        // لطابور الحسابات)، مش شاشة المخزن.
        $po = $pick->purchase_order_id
            ? $pick->purchaseOrder()->with(['items.product', 'client'])->first()
            : null;

        if ($po !== null) {
            $poProducts = $po->items->pluck('product_id')->map(fn ($i) => (int) $i)->all();

            foreach (array_keys($qty) as $pid) {
                if (! in_array((int) $pid, $poProducts, true)) {
                    return back()->withErrors(['qty' => __('stock.pick_edit_not_in_po', [
                        'name' => Product::find($pid)?->displayName() ?? '#'.$pid,
                    ])])->withInput();
                }
            }

            // ⚠️ **أمر معتمد = الكميات تنزل بس، ماتزيدش.** التسليم
            // الجزئي مسموح أصلاً من غير رجوع للحسابات (القيد بالمسلَّم
            // فعلاً)، فالنقص من نفس الجنس. أما الزيادة فبتكبّر مديونية
            // الفرع فوق اللي الحسابات اعتمدته — ومسارها الوحيد تعديل
            // الأمر نفسه (`ops.po.edit` — بيرجّعه لطابور الحسابات).
            if ($po->approval_status === 'approved') {
                foreach ($po->items as $item) {
                    if ((int) ($qty[(int) $item->product_id] ?? 0) > (int) $item->qty) {
                        return back()->withErrors(['qty' => __('stock.pick_edit_no_increase', [
                            'name' => $item->product?->displayName() ?? '#'.$item->product_id,
                            'max' => (int) $item->qty,
                        ])])->withInput();
                    }
                }
            }
        }

        $changes = [];

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($pick, $qty, $po, $request, &$changes) {
                if ($error = $pick->editItems($qty)) {
                    throw new \App\Exceptions\Rejected($error);
                }

                if ($po !== null) {
                    $changes = $this->syncPoItems($po, $qty, $request->user());
                }
            });
        } catch (\App\Exceptions\Rejected $e) {
            return back()->withErrors(['qty' => $e->getMessage()])->withInput();
        }

        // صاحب الأمر + الحسابات (لو أمر بموافقة) يعرفوا إيه اللي اتغير —
        // نفس مفاتيح إشعار «الأمر اتعدل» الموجودة
        if ($po !== null && $changes !== []) {
            $targets = collect([$po->created_by ? User::find($po->created_by) : null]);

            if ($po->needsApproval()) {
                $targets = $targets->merge(
                    User::where('role', 'accountant')->where('active', true)->get()
                );
            }

            foreach ($targets->filter()->unique('id') as $t) {
                \App\Models\AppNotification::send(
                    $t,
                    fn () => __('field.notif_po_edited_title', ['number' => $po->number]),
                    fn () => implode(' · ', $changes),
                    false,
                );
            }
        }

        $ok = __('stock.pick_edited', ['number' => $pick->number]);

        if ($po !== null && $changes !== []) {
            $ok .= ' — '.__('stock.pick_po_synced', ['number' => $po->number]);
        }

        return back()->with('ok', $ok);
    }

    /**
     * ظبط بنود أمر التوريد على كميات التجهيز الجديدة — جوه ترانزاكشن بس.
     *
     * ⚠️ نفس حساب تعديل الحسابات (`decidePoApproval`) بالحرف: السعر
     * والضريبة ثابتين من وقت الإنشاء، الكمية بس اللي بتتغير — وصفر
     * بيشيل البند. الإجماليات من `Tax::totals` زي كل مسارات الأوامر.
     *
     * @param  array<int, int>  $qtyByProduct
     * @return list<string> ملخص التغيير «صنف: قديم ← جديد»
     */
    private function syncPoItems(PurchaseOrder $po, array $qtyByProduct, User $actor): array
    {
        $changes = [];

        foreach ($po->items as $item) {
            $newQty = (int) ($qtyByProduct[(int) $item->product_id] ?? 0);

            if ($newQty === (int) $item->qty) {
                continue;
            }

            $changes[] = ($item->product?->displayName() ?? '#'.$item->product_id)
                .': '.$item->qty.' ← '.$newQty;

            if ($newQty === 0) {
                $item->delete();

                continue;
            }

            $lineTotal = round($newQty * (float) $item->price, 2);
            $item->update([
                'qty' => $newQty,
                'total' => $lineTotal,
                'tax' => round($lineTotal * (float) ($item->tax_rate ?? 0), 2),
            ]);
        }

        if ($changes === []) {
            return [];
        }

        $po->load('items');

        if ($po->items->isEmpty()) {
            throw new \App\Exceptions\Rejected(__('ops.po_no_items_left'));
        }

        $rows = $po->items
            ->map(fn ($i) => ['total' => (float) $i->total, 'tax' => (float) $i->tax])
            ->all();
        $sums = \App\Services\Tax::totals($rows);

        $po->update([
            'total' => $sums['net'],
            'tax_total' => $sums['tax'],
            'grand_total' => $sums['grand'],
            'was_edited' => true,
            'edited_by' => $actor->id,
            'edited_at' => now(),
        ]);

        return $changes;
    }

    // ==================== سيناريو 2 و 3 ====================

    /**
     * فحص: هل عهدة المندوب تكفي الكميات دي؟
     * بيتنادى قبل الموافقة على PO أو طلب ريفيل.
     *
     * @param  array<int, int>  $qtyByProduct
     * @return array{ok: bool, message: ?string, custody: ?Custody}
     */
    public static function checkVanStock(User $rep, array $qtyByProduct, ?int $exceptPoId = null): array
    {
        $custody = $rep->currentCustody();

        if ($custody === null) {
            return [
                'ok' => false,
                'message' => __('field.no_custody_today'),
                'custody' => null,
            ];
        }

        $check = $custody->canCover($qtyByProduct, $exceptPoId);

        if ($check['ok']) {
            return ['ok' => true, 'message' => null, 'custody' => $custody];
        }

        // ⚠️ الرسالة بتفرّق بين «مش معاه» و«معاه بس محجوز لأمر تاني»
        // — الاتنين بيمنعوا الوعد، بس الحل مختلف تماماً، والمدير لازم
        // يعرف إن البضاعة موجودة فعلاً بس متوعّد بيها لعميل تاني.
        $lines = collect($check['short'])
            ->map(fn ($s) => ($s['committed'] ?? 0) > 0
                ? __('stock.van_short_committed', $s)
                : __('stock.van_short_for', $s))
            ->join(' ');

        return [
            'ok' => false,
            'message' => __('stock.van_cannot_cover').' '.$lines,
            'custody' => $custody,
        ];
    }

    /**
     * تنزيل أمر توريد على مندوب — من عهدته لو كافية، وإلا أمر تجهيز.
     * ده المكان الوحيد اللي بيقرر «من العهدة ولا من المخزن».
     *
     * @return array{mode: string, pick: ?PickOrder, error: ?string}
     */
    public static function fulfil(
        User $rep,
        array $qtyByProduct,
        string $purpose,
        array $extra = [],
        ?User $requestedBy = null,
        ?Warehouse $warehouse = null,
    ): array {
        // ⚠️ الأمر اللي بنجهّزه دلوقتي مايحجزش ضد نفسه — `assignTo`
        // بتنده `fulfil` بعد `PurchaseOrder::create` على طول، فالأمر
        // بقى `pending` وهيتحسب في المحجوز لو ماستثنيناهوش.
        $van = self::checkVanStock(
            $rep,
            $qtyByProduct,
            isset($extra['purchase_order_id']) ? (int) $extra['purchase_order_id'] : null,
        );

        if ($van['ok']) {
            return ['mode' => 'van', 'pick' => null, 'error' => null];
        }

        $warehouse ??= $rep->currentCustody()?->warehouse ?? Warehouse::defaultBranch();

        if ($warehouse === null) {
            return ['mode' => 'none', 'pick' => null, 'error' => __('stock.no_warehouse')];
        }

        $result = PickOrder::raise(
            $warehouse, $rep, $qtyByProduct, $purpose, $requestedBy, $extra,
        );

        if ($result['error']) {
            // لا العهدة كفاية ولا المخزن — بنرجّع السببين
            return [
                'mode' => 'none',
                'pick' => null,
                'error' => $van['message'].' '.$result['error'],
            ];
        }

        return ['mode' => 'warehouse', 'pick' => $result['order'], 'error' => null];
    }

    /** تجهيز أمر توريد موجود — بيقرر من العهدة ولا من المخزن */
    public function fulfilPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->loadMissing(['items', 'courier']);

        if ($purchaseOrder->assigned_to === null) {
            return back()->withErrors(['status' => __('stock.po_no_rep')]);
        }

        $qty = $purchaseOrder->items->pluck('qty', 'product_id')->all();

        $result = self::fulfil(
            $purchaseOrder->courier,
            $qty,
            PickOrder::PURPOSE_CUSTOMER_PO,
            ['purchase_order_id' => $purchaseOrder->id, 'needed_on' => $purchaseOrder->due_date],
            $request->user(),
        );

        if ($result['error']) {
            return back()->withErrors(['status' => $result['error']]);
        }

        return $result['mode'] === 'van'
            ? back()->with('ok', __('stock.po_from_van'))
            : redirect()->route('wh.picks.show', $result['pick'])
                ->with('ok', __('stock.po_needs_pick', ['number' => $result['pick']->number]));
    }

    /** نفس الفحص لطلب الريفيل */
    public function fulfilReplenishment(Request $request, ReplenishmentRequest $replenishmentRequest)
    {
        $replenishmentRequest->loadMissing(['items', 'assignee', 'pickOrder']);

        if ($replenishmentRequest->assigned_to === null) {
            return back()->withErrors(['status' => __('stock.rpl_no_rep')]);
        }

        // ⚠️ **الموافقة بقت هي اللي بترفع أمر التجهيز** (فلو ١٥/٨)،
        // فالراوت ده لو اتنده على طلب متوافق عليه كان هيرفع أمر
        // **تاني** لنفس الكميات — بضاعة تخرج من الرف مرتين. الراوت
        // مالوش زرار في أي شاشة، بس مفتوح، فالحارس هنا مش في الفيو.
        if ($replenishmentRequest->pickOrder !== null) {
            return redirect()->route('wh.picks.show', $replenishmentRequest->pickOrder)
                ->with('ok', __('stock.rpl_already_picked', [
                    'number' => $replenishmentRequest->pickOrder->number,
                ]));
        }

        $qty = $replenishmentRequest->items->pluck('qty', 'product_id')->all();

        // ⚠️ **الأمر المتولّد من الطلب لازم يستثنى من الحجز.** الزرار ده
        // بيتضغط على طلب **متنزّل بالفعل**، يعني له أمر توريد `pending`.
        // من غير الاستثناء، الأمر بيحجز ضد نفسه فالفحص يقول «العربية مش
        // كفاية» ويرفع أمر تجهيز مالوش لزوم — بضاعة تخرج من الرف مرتين.
        $result = self::fulfil(
            $replenishmentRequest->assignee,
            $qty,
            PickOrder::PURPOSE_REPLENISHMENT,
            [
                'replenishment_request_id' => $replenishmentRequest->id,
                'purchase_order_id' => $replenishmentRequest->purchase_order_id,
            ],
            $request->user(),
        );

        if ($result['error']) {
            return back()->withErrors(['status' => $result['error']]);
        }

        return $result['mode'] === 'van'
            ? back()->with('ok', __('stock.rpl_from_van'))
            : redirect()->route('wh.picks.show', $result['pick'])
                ->with('ok', __('stock.rpl_needs_pick', ['number' => $result['pick']->number]));
    }
}
