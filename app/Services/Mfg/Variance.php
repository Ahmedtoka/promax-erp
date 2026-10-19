<?php

namespace App\Services\Mfg;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderLine;
use App\Support\DateRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تقرير الفروق (التصنيع ١٩/١٠، spec §3.5 — مهمة ٧): المعياري مقابل
 * الفعلي للأوامر **المقفولة بس**، مجمّعة بالمنتج + شهر `produced_on`.
 *
 * ⚠️ الأرقام من اللي اتسجل وقت القفل (`Mfg\Closing`) — مفيش إعادة حساب هنا.
 * الهالك الشهري على القيم: Σ(waste_pct × الفعلي) / Σ الفعلي — بيساوي
 * (الفعلي − الأساس) / الفعلي بالظبط لأن waste_pct لكل أمر محسوب كده (D9).
 */
class Variance
{
    /**
     * @return Collection<int, array{product: Product, month: string, orders: int, planned_qty: int, output_qty: int,
     *     std_value: float, actual_value: float, variance_value: float, waste_pct: float|null}>
     */
    public static function monthly(DateRange $range, ?int $productId): Collection
    {
        $q = ProductionOrder::query()->where('status', 'closed')->whereNotNull('produced_on')
            ->when($productId, fn ($q) => $q->where('product_id', $productId));
        $range->apply($q, 'produced_on');

        $rows = $q->groupBy('product_id', DB::raw("DATE_FORMAT(produced_on, '%Y-%m')"))
            ->select('product_id', DB::raw("DATE_FORMAT(produced_on, '%Y-%m') AS ym"),
                DB::raw('COUNT(*) AS orders'),
                DB::raw('SUM(planned_qty) AS planned'),
                DB::raw('SUM(COALESCE(output_qty, 0)) AS output'),
                DB::raw('SUM(COALESCE(std_material_cost, 0)) AS std_value'),
                DB::raw('SUM(COALESCE(material_cost, 0)) AS actual_value'),
                DB::raw('SUM(CASE WHEN waste_pct IS NULL THEN 0 ELSE waste_pct * material_cost END) AS waste_w'),
                DB::raw('SUM(CASE WHEN waste_pct IS NULL THEN 0 ELSE material_cost END) AS waste_base'))
            ->toBase()->get();

        $products = Product::query()->whereIn('id', $rows->pluck('product_id')->unique())->get()->keyBy('id');

        return $rows->map(fn ($r) => isset($products[$r->product_id]) ? [
            'product' => $products[$r->product_id],
            'month' => (string) $r->ym,
            'orders' => (int) $r->orders,
            'planned_qty' => (int) $r->planned,
            'output_qty' => (int) $r->output,
            'std_value' => round((float) $r->std_value, 2),
            'actual_value' => round((float) $r->actual_value, 2),
            'variance_value' => round((float) $r->actual_value - (float) $r->std_value, 2),
            'waste_pct' => (float) $r->waste_base > 0.005 ? round((float) $r->waste_w / (float) $r->waste_base, 2) : null,
        ] : null)->filter()
            ->sortBy([['month', 'desc'], fn ($a, $b) => strcmp((string) $a['product']->code, (string) $b['product']->code)])
            ->values();
    }

    /**
     * تفصيل صف (منتج + شهر) بالخامة — نفس الفلتر بتاع `monthly`.
     *
     * @return Collection<int, array{material: Product, base_qty: float, std_qty: float, actual_qty: float,
     *     variance_qty: float, std_value: float, actual_value: float, variance_value: float, waste_pct: float|null}>
     */
    public static function lines(DateRange $range, int $productId, string $month): Collection
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return collect();
        }

        $orders = ProductionOrder::query()->where('status', 'closed')->where('product_id', $productId)
            ->whereRaw("DATE_FORMAT(produced_on, '%Y-%m') = ?", [$month]);
        $range->apply($orders, 'produced_on');

        $rows = ProductionOrderLine::query()->whereIn('production_order_id', $orders->select('id'))
            ->groupBy('product_id')
            ->select('product_id',
                DB::raw('SUM(COALESCE(base_qty_actual, 0)) AS base_qty'),
                DB::raw('SUM(COALESCE(std_qty_actual, 0)) AS std_qty'),
                DB::raw('SUM(COALESCE(actual_qty, 0)) AS actual_qty'),
                DB::raw('SUM(COALESCE(std_cost, 0)) AS std_value'),
                DB::raw('SUM(COALESCE(actual_cost, 0)) AS actual_value'))
            ->toBase()->get();

        $materials = Product::query()->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => isset($materials[$r->product_id]) ? [
            'material' => $materials[$r->product_id],
            'base_qty' => round((float) $r->base_qty, 3),
            'std_qty' => round((float) $r->std_qty, 3),
            'actual_qty' => round((float) $r->actual_qty, 3),
            'variance_qty' => round((float) $r->actual_qty - (float) $r->std_qty, 3),
            'std_value' => round((float) $r->std_value, 2),
            'actual_value' => round((float) $r->actual_value, 2),
            'variance_value' => round((float) $r->actual_value - (float) $r->std_value, 2),
            'waste_pct' => (float) $r->actual_qty > 0.0005
                ? round(((float) $r->actual_qty - (float) $r->base_qty) / (float) $r->actual_qty * 100, 2) : null,
        ] : null)->filter()
            ->sortByDesc(fn ($l) => abs($l['variance_value']))
            ->values();
    }
}
