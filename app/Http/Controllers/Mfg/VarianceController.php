<?php

namespace App\Http\Controllers\Mfg;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Mfg\Variance;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * تقرير الفروق (التصنيع ١٩/١٠، مهمة ٧) — شهري بالمنتج + شهر الإنتاج،
 * الأوامر المقفولة بس. الضغط على صف (`product_id` + `month` في الـquery
 * string) بيفتح التفصيل بالخامة (`Variance::lines`).
 *
 * ⚠️ «من — إلى» على `produced_on`، والتصدير من نفس الفلتر.
 */
class VarianceController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $productId = $request->integer('product_id') ?: null;
        $rows = Variance::monthly($range, $productId);

        $month = $request->string('month')->value();
        $drill = null;
        if ($productId !== null && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $drill = [
                'product' => Product::find($productId),
                'month' => $month,
                'lines' => Variance::lines($range, $productId, $month),
            ];
        }

        if ($request->boolean('export')) {
            return Csv::download('mfg-variance-'.now()->format('Y-m-d-Hi').'.csv', [
                __('mfg.col_month'), __('mfg.col_product_code'), __('mfg.col_product'), __('mfg.col_orders'),
                __('mfg.col_planned_qty'), __('mfg.col_output_qty'), __('mfg.col_std_material_cost'),
                __('mfg.col_material_cost'), __('mfg.col_variance_value'), __('mfg.col_waste_pct'),
            ], $rows->map(fn (array $r) => [
                $r['month'], $r['product']->code, $r['product']->displayName(), $r['orders'],
                $r['planned_qty'], $r['output_qty'], Csv::money($r['std_value']),
                Csv::money($r['actual_value']), Csv::money($r['variance_value']),
                $r['waste_pct'] ?? '',
            ]), [
                __('common.total'), '', '', $rows->sum('orders'), $rows->sum('planned_qty'), $rows->sum('output_qty'),
                Csv::money($rows->sum('std_value')), Csv::money($rows->sum('actual_value')),
                Csv::money($rows->sum('variance_value')), '',
            ], Csv::meta(__('mfg.variance_title'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('mfg.variance', [
            'rows' => $rows,
            'range' => $range,
            'productId' => $productId,
            'drill' => $drill,
            'products' => Product::query()->where('kind', Product::KIND_FINISHED)
                ->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
        ]);
    }
}
