<?php

namespace App\Http\Controllers\Mfg;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * أوامر الإنتاج (التصنيع ١٩/١٠، spec §3.5) — القايمة.
 * الإنشاء والبدء والصرف والقفل بيتزوّدوا مع مهامهم.
 *
 * ⚠️ الفلتر على `planned_on` (العمود التجاري)، والتصدير من نفس الكويري
 * المفلترة قبل الباجينيشن (promax-system §7).
 */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        // قيمة مش معروفة = من غير فلتر (مفيش 422 على شاشة GET)
        $picked = $request->string('status')->value();
        $filters = ['status' => in_array($picked, ProductionOrder::STATUSES, true) ? $picked : null];

        $q = ProductionOrder::query()->with(['product', 'line']);

        if ($status = $filters['status'] ?? null) {
            $q->where('status', $status);
        }

        $range = DateRange::fromRequest($request);
        $range->apply($q, 'planned_on');

        $q->orderByDesc('planned_on')->orderByDesc('id');

        if ($request->boolean('export')) {
            $rows = (clone $q)->get();

            return Csv::download('production-orders-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_number'), __('mfg.col_product'), __('mfg.col_line'), __('mfg.col_batch_no'),
                __('mfg.col_planned_on'), __('mfg.col_planned_qty'), __('mfg.col_output_qty'), __('mfg.col_status'),
            ], $rows->map(fn (ProductionOrder $o) => [
                $o->number, $o->product?->displayName() ?? '', $o->line?->displayName() ?? '', $o->batch_no,
                $o->planned_on?->format('Y-m-d') ?? '', $o->planned_qty, $o->output_qty ?? '', $o->statusLabel(),
            ]), null, Csv::meta(__('mfg.orders_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.orders', [
            'orders' => $q->paginate(50)->withQueryString(),
            'filters' => $filters,
            'range' => $range,
        ]);
    }
}
