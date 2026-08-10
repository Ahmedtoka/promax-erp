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
        ]);

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
        $pick->load([
            'warehouse', 'rep', 'requester', 'picker', 'custody',
            'items.product', 'items.batch', 'items.location',
            'purchaseOrder.client', 'replenishmentRequest.client',
        ]);

        return view('wh.pick', ['o' => $pick]);
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

    /** أمين المخزن بدأ يجمع من الأرفف */
    public function startPicking(Request $request, PickOrder $pick)
    {
        abort_unless($this->guardKeeperWarehouse($request->user(), $pick), 403);

        if ($error = $pick->startPicking($request->user())) {
            return back()->withErrors(['status' => $error]);
        }

        return back()->with('ok', __('stock.pick_started'));
    }

    /** "جاهز" — البضاعة بتخرج من الأرفف وتتحجز للمندوب */
    public function markReady(Request $request, PickOrder $pick)
    {
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
        if ($error = $pick->cancel()) {
            return back()->withErrors(['status' => $error]);
        }

        return back()->with('ok', __('stock.pick_cancelled'));
    }

    // ==================== سيناريو 2 و 3 ====================

    /**
     * فحص: هل عهدة المندوب تكفي الكميات دي؟
     * بيتنادى قبل الموافقة على PO أو طلب ريفيل.
     *
     * @param  array<int, int>  $qtyByProduct
     * @return array{ok: bool, message: ?string, custody: ?Custody}
     */
    public static function checkVanStock(User $rep, array $qtyByProduct): array
    {
        $custody = $rep->currentCustody();

        if ($custody === null) {
            return [
                'ok' => false,
                'message' => __('field.no_custody_today'),
                'custody' => null,
            ];
        }

        $check = $custody->canCover($qtyByProduct);

        if ($check['ok']) {
            return ['ok' => true, 'message' => null, 'custody' => $custody];
        }

        $lines = collect($check['short'])
            ->map(fn ($s) => __('stock.van_short_for', $s))
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
        $van = self::checkVanStock($rep, $qtyByProduct);

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
        $replenishmentRequest->loadMissing(['items', 'assignee']);

        if ($replenishmentRequest->assigned_to === null) {
            return back()->withErrors(['status' => __('stock.rpl_no_rep')]);
        }

        $qty = $replenishmentRequest->items->pluck('qty', 'product_id')->all();

        $result = self::fulfil(
            $replenishmentRequest->assignee,
            $qty,
            PickOrder::PURPOSE_REPLENISHMENT,
            ['replenishment_request_id' => $replenishmentRequest->id],
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
