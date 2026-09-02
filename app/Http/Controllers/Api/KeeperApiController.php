<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PickOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * أوامر التجهيز لأمين المخزن على الموبايل (٩ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ده مش `PickApiController`.** التاني بيرجّع
 * `PickOrder::forRep()` — الأوامر المسنودة **لمندوب** بيستلمها.
 * أمين المخزن مابيتسندلوش أمر، هو اللي **بيجهّز** — فالسكوب هنا
 * أوامر **مخازنه** (`warehouse_id` بتاعه أو المخازن اللي هو
 * مديرها). الخلط بينهم هو بالظبط اللي خلّى شاشة الأمين الأولى
 * تتشال: قايمة فاضية للأبد وهو فاكر السيستم بايظ.
 *
 * الأكشنات نفسها موديل-سايد (`startPicking` / `markReady`) —
 * نفس اللي شاشة الويب بتنده عليه، فمفيش فلو تاني يتفرّع.
 */
class KeeperApiController extends Controller
{
    /** مخازن الأمين — اللي هو مسجّل عليها أو مديرها */
    private function warehouseIds(User $user): array
    {
        if ($user->role === 'admin') {
            return Warehouse::where('active', true)->pluck('id')->all();
        }

        $ids = Warehouse::where('manager_id', $user->id)->pluck('id')->all();

        if ($user->warehouse_id !== null) {
            $ids[] = (int) $user->warehouse_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * GET /api/keeper/picks — أوامر التجهيز المفتوحة على مخازنه.
     * `?history=1` — المُسلَّم من آخر أسبوع.
     */
    public function index(Request $request): JsonResponse
    {
        $ids = $this->warehouseIds($request->user());
        $history = $request->boolean('history');

        $orders = PickOrder::whereIn('warehouse_id', $ids)
            // ⚠️ أوامر الأونلاين بتتجهز من شاشة «تجهيز الأونلاين» في
            // الداشبورد — فلو الموبايل بينتهي بتسليم عهدة لمندوب،
            // وده مالوش وجود في أوردر شحن (٣/٩)
            ->where('purpose', '!=', PickOrder::PURPOSE_ONLINE)
            ->with(['warehouse', 'rep:id,name,code', 'items.product', 'items.batch', 'items.location',
                'purchaseOrder:id,client_id,due_at', 'purchaseOrder.client:id,name,name_en'])
            ->when(
                $history,
                fn ($q) => $q->whereIn('status', ['handed', 'cancelled'])->latest()->take(40),
                fn ($q) => $q->open()->oldest('created_at')->take(40),
            )
            ->get();

        return response()->json([
            'picks' => $orders->map(fn (PickOrder $o) => $this->payload($o))->values(),
            // للشارة على التاب — اللي مستني يبدأ
            'waiting_count' => $history ? 0 : $orders->where('status', 'requested')->count(),
        ]);
    }

    /** GET /api/keeper/picks/{pick} */
    public function show(Request $request, PickOrder $pick): JsonResponse
    {
        if ($err = $this->guard($request->user(), $pick)) {
            return $err;
        }

        $pick->load(['warehouse', 'rep:id,name,code', 'items.product', 'items.batch', 'items.location',
            'purchaseOrder:id,client_id,due_at', 'purchaseOrder.client:id,name,name_en']);

        return response()->json(['pick' => $this->payload($pick)]);
    }

    /** POST /api/keeper/picks/{pick}/start — «ابدأ التجهيز» */
    public function start(Request $request, PickOrder $pick): JsonResponse
    {
        if ($err = $this->guard($request->user(), $pick)) {
            return $err;
        }

        if ($msg = $pick->startPicking($request->user())) {
            return response()->json(['message' => $msg], 422);
        }

        return response()->json(['pick' => $this->payload($pick->refresh()->load('items.product'))]);
    }

    /**
     * POST /api/keeper/picks/{pick}/ready — «جاهز للاستلام».
     * { items?: [{id, qty}] } لو عدّل الكميات (نقص على الرف).
     *
     * ⚠️ البضاعة بتخرج من الأرفف هنا فعلاً (`markReady` بيسحب) —
     * والسيرفر بيرفض من غير «ابدأ» الأول، نفس قاعدة الويب.
     */
    public function ready(Request $request, PickOrder $pick): JsonResponse
    {
        if ($err = $this->guard($request->user(), $pick)) {
            return $err;
        }

        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:0'],
        ]);

        $picked = empty($data['items'])
            ? null
            : collect($data['items'])->pluck('qty', 'id')->all();

        if ($msg = $pick->markReady($request->user(), $picked)) {
            return response()->json(['message' => $msg], 422);
        }

        return response()->json([
            'pick' => $this->payload($pick->refresh()->load(['items.product', 'items.batch'])),
            'message' => __('stock.pick_ready_ok'),
        ]);
    }

    private function guard(User $user, PickOrder $pick): ?JsonResponse
    {
        return in_array((int) $pick->warehouse_id, $this->warehouseIds($user), true)
            ? null
            : response()->json(['message' => __('stock.pick_not_your_warehouse')], 403);
    }

    /** @return array<string, mixed> */
    private function payload(PickOrder $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'warehouse' => $o->warehouse?->displayName(),
            'status' => $o->status,
            'status_label' => $o->statusLabel(),
            'purpose' => $o->purpose,
            'purpose_label' => $o->purposeLabel(),
            'rep' => $o->rep?->name,
            'qty_requested' => $o->qtyRequested(),
            'qty_picked' => $o->qtyPicked(),
            'can_start' => $o->status === 'requested',
            'can_ready' => $o->status === 'picking',
            'prep_minutes' => $o->prepMinutes(),
            'pickup_at' => $o->pickup_at?->toIso8601String(),
            'needed_on' => $o->needed_on?->toDateString(),
            'po_client' => $o->purchaseOrder?->client?->displayName(),
            'po_due_at' => $o->purchaseOrder?->due_at?->toIso8601String(),
            'time' => $o->created_at?->toIso8601String(),
            'notes' => $o->notes,
            // البنود دايماً كاملة — الأمين بيجهّز منها، مش زي المندوب
            // اللي مايشوفهاش غير عند الجاهز
            'items' => $o->items->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->product?->displayName(),
                'code' => $i->product?->code,
                'image' => $i->product?->imageSrc(),
                'batch_no' => $i->batchNo(),
                'expires_on' => $i->expiresOn(),
                'location' => $i->locationCode(),
                'qty_requested' => (int) $i->qty_requested,
                'qty_picked' => $i->qty_picked === null ? null : (int) $i->qty_picked,
                'gift_qty' => (int) $i->gift_qty,
            ])->values()->all(),
        ];
    }
}
