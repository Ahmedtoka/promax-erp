<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\ProductionOrder;
use App\Services\Mfg\Orders;
use App\Support\Idempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * أوامر الإنتاج على الأبلكيشن (التصنيع ١٩/١٠ — الخط ٦، مهمة ٨)
 * ═══════════════════════════════════════════════════════════════
 *
 * عقد الخط ٨ (أبلكيشن 2.6.0+13): مدير الإنتاج بيشوف أوامر خطوطه
 * المفتوحة وبيدوس «ابدأ» / «خلص» من على الخط.
 *
 *   GET  /api/production/orders?line=&status=  → {orders:[row], lines:[{id,name}]}
 *   POST /api/production/orders/{order}/start  {idem_key}             → {message, order}
 *   POST /api/production/orders/{order}/finish {idem_key, output_qty} → {message, order}
 *
 * ⚠️ الأكشنات هي نفسها `Mfg\Orders::start/finish` اللي الويب بينده
 * عليها — مفيش فلو تاني. والـ`idem_key` بيرجّع نفس الأمر لو الشبكة
 * وقعت والأبلكيشن عاد المحاولة.
 * ⚠️ أي رول تاني بياخد 403 من `api.role` — الأبلكيشن بيقراها «الميزة مقفولة».
 */
class ProductionApiController extends Controller
{
    /** من غير فلتر حالة = الشغل اللي لسه على الخط */
    private const DEFAULT_STATUSES = ['planned', 'in_progress', 'finished'];

    private const MAX_ROWS = 100;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'line' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(ProductionOrder::STATUSES)],
        ]);

        $orders = ProductionOrder::query()->with(['product', 'line'])
            ->when($data['line'] ?? null, fn ($q, $line) => $q->where('line_id', (int) $line))
            ->when(
                $data['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', self::DEFAULT_STATUSES),
            )
            // الشغل المفتوح الأقدم الأول؛ التاريخ (مقفول/ملغي) الأحدث الأول
            ->when(
                in_array($data['status'] ?? null, ['closed', 'cancelled'], true),
                fn ($q) => $q->orderByDesc('planned_on')->orderByDesc('id'),
                fn ($q) => $q->orderBy('planned_on')->orderBy('id'),
            )
            ->limit(self::MAX_ROWS)
            ->get();

        $lines = CostCenter::query()->where('kind', 'line')->where('active', true)
            ->orderBy('code')->get();

        return response()->json([
            'orders' => $orders->map(fn (ProductionOrder $o) => self::row($o))->values(),
            'lines' => $lines->map(fn (CostCenter $l) => ['id' => $l->id, 'name' => $l->displayName()])->values(),
        ]);
    }

    public function start(Request $request, ProductionOrder $order): JsonResponse
    {
        $data = $request->validate(['idem_key' => Idempotency::RULES]);

        try {
            $done = Orders::start($order, $request->user(), $data['idem_key'] ?? null);
        } catch (Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('mfg.flash_order_started'),
            'order' => self::row($done->load(['product', 'line'])),
        ]);
    }

    public function finish(Request $request, ProductionOrder $order): JsonResponse
    {
        $data = $request->validate([
            'idem_key' => Idempotency::RULES,
            // الأبلكيشن بيبعتها عشري — التام قطع صحيحة (D2)
            'output_qty' => ['required', 'numeric', 'min:1', 'max:10000000'],
        ]);

        $qty = (float) $data['output_qty'];
        if (abs($qty - round($qty)) > 1e-9) {
            return response()->json(['message' => __('mfg.output_whole')], 422);
        }

        try {
            $done = Orders::finish($order, $request->user(), (int) round($qty), $data['idem_key'] ?? null);
        } catch (Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('mfg.flash_order_finished'),
            'order' => self::row($done->load(['product', 'line'])),
        ]);
    }

    /** شكل الصف ثابت من خطة الخط ٨ — المفاتيح الزيادة مسموحة */
    public static function row(ProductionOrder $o): array
    {
        return [
            'id' => $o->id,
            'code' => $o->number,
            'product_name' => $o->product?->displayName(),
            'line_id' => $o->line_id,
            'line_name' => $o->line?->displayName(),
            'status' => $o->status,
            'status_label' => $o->statusLabel(),
            'planned_qty' => (int) $o->planned_qty,
            'output_qty' => $o->output_qty === null ? null : (int) $o->output_qty,
            'unit' => $o->product?->unitLabel(),
            'started_at' => $o->started_at?->toIso8601String(),
            'finished_at' => $o->finished_at?->toIso8601String(),
            'batch_no' => $o->batch_no,
            'planned_on' => $o->planned_on?->format('Y-m-d'),
            'hours' => $o->hours === null ? null : (float) $o->hours,
        ];
    }
}
