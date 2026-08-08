<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PickOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * أوامر التجهيز في الأبلكيشن:
 * المندوب بيشوف الأوامر الجاهزة → يفتح الأمر يشوف البضاعة →
 * يعدّ ويأكد → البضاعة تنزل عهدته.
 */
class PickApiController extends Controller
{
    /**
     * GET /api/picks — أوامر التجهيز الخاصة بالمندوب.
     *
     * `?history=1` — **كل** استلامات العهدة اللي تمّت (المُسلَّمة)
     * بتواريخها وبنودها الكاملة، الأحدث الأول. المندوب بيراجع منها
     * «استلمت إيه وإمتى» في أي وقت.
     */
    public function index(Request $request): JsonResponse
    {
        $history = $request->boolean('history');

        $orders = PickOrder::forRep($request->user()->id)
            ->with(['warehouse', 'items.product', 'items.batch', 'items.location',
                'purchaseOrder:id,client_id,due_at', 'purchaseOrder.client:id,name,name_en'])
            ->when(
                $history,
                fn ($q) => $q->where('status', 'handed')->latest('handed_at')->take(60),
                fn ($q) => $q->where(fn ($w) => $w->open()->orWhereDate('handed_at', today()))
                    ->latest()->take(30),
            )
            ->get();

        return response()->json([
            // الهيستوري بالبنود كاملة — الشاشة بتفردها من غير ريكوست لكل أمر
            'picks' => $orders->map(fn (PickOrder $o) => $this->payload($o, full: $history))->values(),
            'ready_count' => $history ? 0 : $orders->where('status', 'ready')->count(),
        ]);
    }

    /** GET /api/picks/{pick} */
    public function show(Request $request, PickOrder $pick): JsonResponse
    {
        if ($pick->assigned_to !== $request->user()->id) {
            return response()->json(['message' => __('stock.pick_not_yours')], 403);
        }

        $pick->load(['warehouse', 'items.product', 'items.batch', 'items.location']);

        return response()->json(['pick' => $this->payload($pick, full: true)]);
    }

    /**
     * POST /api/picks/{pick}/receive — المندوب استلم.
     * بيقدر يعدّل الكميات، والفرق بيرجع للرف والمدير بيوصله إشعار.
     */
    public function receive(Request $request, PickOrder $pick): JsonResponse
    {
        // ⚠️ **الفحص ده كان ناقص في الكنترولر** (تدقيق ٨/٨/٢٠٢٦).
        // الإندبوينت كان آمن **بالصدفة** لأن `PickOrder::handOver`
        // بتفحص جوّه — أي إعادة ترتيب هناك كانت هتفتح استلام عهدة
        // مندوب تاني من غير ما حد ياخد باله. الحارس صريح هنا دلوقتي.
        if ((int) $pick->assigned_to !== (int) $request->user()->id) {
            return response()->json(['message' => __('stock.pick_not_yours')], 403);
        }

        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $received = null;
        if (! empty($data['items'])) {
            $received = collect($data['items'])->pluck('qty', 'id')->all();
        }

        $error = $pick->handOver($request->user(), $received, $data['note'] ?? null);

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $pick->refresh()->load(['items.product', 'items.batch', 'warehouse']);

        return response()->json([
            'pick' => $this->payload($pick, full: true),
            'message' => __('stock.pick_received_ok', [
                'qty' => $pick->qtyReceived(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(PickOrder $o, bool $full = false): array
    {
        $base = [
            'id' => $o->id,
            'number' => $o->number,
            'warehouse' => $o->warehouse?->displayName(),
            'status' => $o->status,
            'status_label' => $o->statusLabel(),
            'purpose' => $o->purpose,
            'purpose_label' => $o->purposeLabel(),
            'qty_requested' => $o->qtyRequested(),
            'qty_picked' => $o->qtyPicked(),
            'qty_received' => $o->qtyReceived(),
            'gift_total' => (int) $o->items->sum('gift_qty'),
            'can_receive' => $o->status === 'ready',
            'has_variance' => (bool) $o->has_variance,
            'needed_on' => $o->needed_on?->toDateString(),
            // ⚠️ **موعد وصول المندوب المخزن** (2026-08-08) —
            // يوم وساعة. `needed_on` فولباك للأوامر القديمة.
            'pickup_at' => $o->pickup_at?->toIso8601String(),
            // وللتوريد: الفرع ومعاد تسليمه — المندوب لازم يعرف
            // البضاعة دي رايحة فين قبل ما يستلمها
            'po_client' => $o->purchaseOrder?->client?->displayName(),
            'po_due_at' => $o->purchaseOrder?->due_at?->toIso8601String(),
            'ready_at' => $o->ready_at?->toIso8601String(),
            'handed_at' => $o->handed_at?->toIso8601String(),
            'time' => $o->created_at->toIso8601String(),
            'notes' => $o->notes,
        ];

        if (! $full && $o->status !== 'ready') {
            return $base;
        }

        // البنود بتظهر للمندوب بس لما الأمر يبقى جاهز — قبل كده مالوش لازمة
        $base['items'] = $o->items->map(fn ($i) => [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'name' => $i->product?->displayName(),
            'code' => $i->product?->code,
            'unit' => $i->product?->unitLabel(),
            // الصورة — المندوب بيعدّ بضاعة حقيقية قدامه، الصورة بتأمّن العد
            'image' => $i->product?->imageSrc(),
            'batch_no' => $i->batchNo(),
            'expires_on' => $i->expiresOn(),
            'location' => $i->locationCode(),
            'qty_requested' => (int) $i->qty_requested,
            'qty_picked' => (int) ($i->qty_picked ?? 0),
            'qty_received' => $i->qty_received === null ? null : (int) $i->qty_received,
            // ⚠️ **الهدية بتبان للمندوب قبل ما يستلم.** لو مابانتش،
            // هو بيستلم الكمية كلها وهو فاكرها كلها للبيع، وبعدين
            // بيلاقي عهدته فيها كمية «مجانية» مش عارف مصدرها.
            'gift_qty' => (int) ($i->gift_qty ?? 0),
        ])->values();

        $base['gift_total'] = (int) $o->items->sum('gift_qty');

        return $base;
    }
}
