<?php

namespace App\Http\Controllers\Mfg;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\ProductSpec;
use App\Services\Mfg\Qc;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * الجودة والإفراج (التصنيع ١٩/١٠، spec §3.5) — الأوامر المقفولة اللي
 * باتشها التام عليه حالة جودة، وقرار الإفراج/الرفض (`Mfg\Qc::decide`).
 * اللي في الحجر الأول دايماً — دول المستنيين قرار.
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

        // الحجر الأول (مستني قرار)، وبعدين الأحدث إنتاجاً
        $q->orderByRaw("CASE WHEN qc_status = 'quarantine' THEN 0 ELSE 1 END")
            ->orderByDesc('produced_on')->orderByDesc('id');

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

        $orders = $q->paginate(50)->withQueryString();

        // حدود الجودة من المواصفة المعتمدة لكل منتج في الصفحة (للي في الحجر بس)
        $limits = [];
        foreach ($orders->getCollection()->where('qc_status', 'quarantine')->pluck('product')->filter()->unique('id') as $p) {
            $limits[$p->id] = Qc::limits(ProductSpec::approvedFor($p));
        }

        return view('mfg.qc', [
            'orders' => $orders,
            'filters' => $filters,
            'range' => $range,
            'limits' => $limits,
        ]);
    }

    /**
     * إفراج أو رفض باتش في الحجر. النتايج بتيجي بترتيب بنود حدود المواصفة
     * (`results[i]`) وبتتحول هنا لـ param ⇒ القيمة.
     */
    public function decide(Request $request, ProductionOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:'.implode(',', Qc::DECISIONS)],
            'note' => ['required', 'string', 'max:2000'],
            'results' => ['nullable', 'array'],
            'results.*' => ['nullable', 'numeric'],
        ]);

        $order->loadMissing('product');
        $results = [];
        foreach (Qc::limits($order->product ? ProductSpec::approvedFor($order->product) : null) as $i => $limit) {
            $results[$limit['param']] = $data['results'][$i] ?? null;
        }

        try {
            Qc::decide($order, $data['decision'], $data['note'], $request->user(), $results);
        } catch (Rejected $e) {
            return back()->withErrors(['qc' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('mfg.flash_qc_'.$data['decision'], ['batch' => $order->batch_no]));
    }
}
