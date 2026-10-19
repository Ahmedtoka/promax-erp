<?php

namespace App\Http\Controllers\Mfg;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Product;
use App\Services\Mfg\Genealogy;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * تتبع اللوطات والسحب (التصنيع ١٩/١٠، مهمة ٧) — بحث برقم الباتش
 * (بالظبط أو بادئة):
 *   • باتش تام ← لوطات الخام + العملاء اللي استلموا + اللي في العربيات.
 *   • لوط خام ← الباتشات التامة اللي دخل فيها، وتحت كل واحد عملاؤه.
 *
 * ⚠️ التوائم (نفس المنتج + رقم الباتش في مخازن تانية) نتيجة واحدة.
 * ⚠️ الشاشة على مستوى الشركة: الرولز اللي عليها (admin, production_manager,
 * quality) مالهاش نطاق عملاء، فكل العملاء بيظهروا — السحب لازم يوصل للكل.
 * ⚠️ فلتر «من — إلى» على تاريخ الاستلام (الفاتورة / التسليم)، والتصدير
 * = نفس العملاء المعروضين.
 */
class TraceController extends Controller
{
    private const MAX_MATCHES = 30;

    public function index(Request $request)
    {
        $term = trim($request->string('q')->value());
        $range = DateRange::fromRequest($request);
        $results = $term === '' ? collect() : $this->search($term, $range);

        if ($request->boolean('export') && $term !== '') {
            return $this->export($term, $results, $range);
        }

        return view('mfg.trace', [
            'term' => $term,
            'range' => $range,
            'results' => $results,
        ]);
    }

    /** نتيجة لكل لوط (منتج + رقم باتش)، تام أو خام */
    private function search(string $term, DateRange $range): Collection
    {
        $like = addcslashes($term, '%_\\').'%';

        $batches = Batch::query()->with(['product', 'warehouse'])
            ->where(fn ($q) => $q->where('batch_no', $term)->orWhere('batch_no', 'like', $like))
            ->orderByRaw('CASE WHEN batch_no = ? THEN 0 ELSE 1 END', [$term])
            ->orderBy('batch_no')->orderBy('id')
            ->limit(self::MAX_MATCHES * 3)
            ->get();

        return $batches->groupBy(fn (Batch $b) => $b->product_id.'|'.$b->batch_no)
            ->take(self::MAX_MATCHES)
            ->map(function (Collection $twins) use ($range) {
                $b = $twins->first();
                $kind = $b->product?->kind ?? Product::KIND_FINISHED;

                if ($kind === Product::KIND_FINISHED) {
                    return [
                        'batch' => $b, 'twins' => $twins, 'kind' => $kind,
                        'raw' => Genealogy::rawLotsFor($b),
                        'recipients' => Genealogy::recipientsFor($b, $range),
                        'vans' => Genealogy::inVans($b),
                    ];
                }

                return [
                    'batch' => $b, 'twins' => $twins, 'kind' => $kind,
                    'finished' => Genealogy::finishedFor($b)->map(fn ($o) => [
                        'order' => $o,
                        'recipients' => $o->fgBatch ? Genealogy::recipientsFor($o->fgBatch, $range) : collect(),
                        'vans' => $o->fgBatch ? Genealogy::inVans($o->fgBatch) : collect(),
                    ]),
                ];
            })->values();
    }

    /** عملاء كل النتايج — سطر لكل استلام، ومعاه اللوط اللي اتدوّر عليه والباتش التام */
    private function export(string $term, Collection $results, DateRange $range)
    {
        $rows = [];
        $line = fn (array $res, $fg, array $r) => [
            $res['batch']->batch_no,
            $res['batch']->product?->displayName() ?? '',
            $fg?->batch_no ?? '',
            $r['client']->code ?? '',
            $r['client']->displayName(),
            __('mfg.via_'.$r['via']),
            $r['ref'],
            $r['qty'],
            $r['date']?->format('Y-m-d') ?? '',
        ];

        foreach ($results as $res) {
            if ($res['kind'] === Product::KIND_FINISHED) {
                foreach ($res['recipients'] as $r) {
                    $rows[] = $line($res, $res['batch'], $r);
                }

                continue;
            }

            foreach ($res['finished'] as $f) {
                foreach ($f['recipients'] as $r) {
                    $rows[] = $line($res, $f['order']->fgBatch, $r);
                }
            }
        }

        return Csv::download('lot-trace-'.now()->format('Y-m-d-Hi').'.csv', [
            __('mfg.col_searched_lot'), __('mfg.col_product'), __('mfg.col_fg_batch'), __('mfg.col_client_code'),
            __('mfg.col_client'), __('mfg.col_via'), __('mfg.col_ref'), __('mfg.col_qty'), __('mfg.col_received_on'),
        ], $rows, null, array_merge(
            Csv::meta(__('mfg.trace_title'), $range->fromValue() ?: null, $range->toValue() ?: null),
            [[__('mfg.trace_search'), $term]],
        ));
    }
}
