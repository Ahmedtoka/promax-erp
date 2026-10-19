<?php

namespace App\Http\Controllers\Mfg;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * الجودة والإفراج (التصنيع ١٩/١٠، spec §3.5) — الأوامر المقفولة اللي
 * باتشها التام عليه حالة جودة. قرار الإفراج/الرفض بيتزوّد مع مهمته.
 *
 * ⚠️ الفلتر على `produced_on` (تاريخ إنتاج الباتش)، والتصدير من نفس الكويري.
 */
class QcController extends Controller
{
    public function index(Request $request)
    {
        // قيمة مش معروفة = من غير فلتر (مفيش 422 على شاشة GET)
        $picked = $request->string('qc_status')->value();
        $filters = ['qc_status' => in_array($picked, ProductionOrder::QC, true) ? $picked : null];

        $q = ProductionOrder::query()->whereNotNull('qc_status')->with(['product', 'line']);

        if ($status = $filters['qc_status'] ?? null) {
            $q->where('qc_status', $status);
        }

        $range = DateRange::fromRequest($request);
        $range->apply($q, 'produced_on');

        $q->orderByDesc('produced_on')->orderByDesc('id');

        if ($request->boolean('export')) {
            $rows = (clone $q)->get();

            return Csv::download('qc-release-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_number'), __('mfg.col_product'), __('mfg.col_batch_no'), __('mfg.col_produced_on'),
                __('mfg.col_expires_on'), __('mfg.col_output_qty'), __('mfg.col_qc_status'), __('mfg.col_qc_at'),
            ], $rows->map(fn (ProductionOrder $o) => [
                $o->number, $o->product?->displayName() ?? '', $o->batch_no, $o->produced_on?->format('Y-m-d') ?? '',
                $o->expires_on?->format('Y-m-d') ?? '', $o->output_qty ?? '', $o->qcLabel(), $o->qc_at?->format('Y-m-d H:i') ?? '',
            ]), null, Csv::meta(__('mfg.qc_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.qc', [
            'orders' => $q->paginate(50)->withQueryString(),
            'filters' => $filters,
            'range' => $range,
        ]);
    }
}
