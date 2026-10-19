<?php

namespace App\Services\Costing;

use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductTransferPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * كارت تكلفة المنتج التام (الخط ٩، مهمة ٧) — خامات + تعبئة + تحميل للوحدة،
 * بالطريقتين جنب بعض، والسعر المقترح للتحويل = التكلفة الرسمية × (١ + الهامش).
 *
 * افتراض موثّق: الكمية المعيارية للوحدة = `Bom::stdFor($line, 1000) / 1000`
 * يعني سطور «لكل باتش» بتتوزع على ١٠٠٠ وحدة، وسطور «لكل قطعة» بتاخد
 * الهالك. آخر سعر للخامة = تكلفة آخر باتش أصل (مش توأم تحويل، تكلفته > 0)
 * وإلا `products.cost`؛ المتوسط = `Valuation::wavg`.
 * التحميل للوحدة: آخر أمر مقفول (`overhead_cost ÷ output_qty`) ← وإلا كجم/وحدة
 * × سعر الكيلو الافتراضي ← وإلا صفر.
 */
final class CostCard
{
    /**
     * @return array{product:Product, bom:?Bom, raw:float, packaging:float, overhead:float, overhead_basis:string,
     *   unit_batch:float, unit_wavg:float, unit:float, markup_pct:float, suggested:float,
     *   tp:?ProductTransferPrice, status:string, lines:list<array{product:Product, qty:float, last_price:float, avg_price:float, value:float}>}
     */
    public static function for(Product $p): array
    {
        $today = today();
        $bom = Bom::approvedFor($p);
        $method = CostingSettings::method();
        $markup = CostingSettings::markupPct();

        $lines = [];
        $rawB = $rawW = $packB = $packW = 0.0;
        $overhead = 0.0;
        $basis = 'none';

        if ($bom === null) {
            $unitB = $unitW = round((float) $p->cost, 4);
        } else {
            foreach ($bom->lines()->with('product')->get() as $l) {
                $m = $l->product;
                if ($m === null) {
                    continue;
                }
                $qty = $bom->stdFor($l, 1000) / 1000;
                $last = self::lastPrice($m);
                $avg = Valuation::wavg($m, $today);
                $lines[] = ['product' => $m, 'qty' => $qty, 'last_price' => $last, 'avg_price' => $avg,
                    'value' => round($qty * ($method === 'wavg' ? $avg : $last), 4)];
                if ($m->kind === 'packaging') {
                    $packB += $qty * $last;
                    $packW += $qty * $avg;
                } else {
                    $rawB += $qty * $last;
                    $rawW += $qty * $avg;
                }
            }
            [$overhead, $basis] = self::overheadPerUnit($p);
            $unitB = round($rawB + $packB + $overhead, 4);
            $unitW = round($rawW + $packW + $overhead, 4);
        }

        $official = $method === 'wavg' ? 'wavg' : 'batch';
        $unit = $official === 'wavg' ? $unitW : $unitB;
        $tp = TransferPrice::effective($p->id, $today);

        return [
            'product' => $p,
            'bom' => $bom,
            'raw' => round($method === 'wavg' ? $rawW : $rawB, 4),
            'packaging' => round($method === 'wavg' ? $packW : $packB, 4),
            'overhead' => round($overhead, 4),
            'overhead_basis' => $basis,
            'unit_batch' => $unitB,
            'unit_wavg' => $unitW,
            'unit' => $unit,
            'markup_pct' => $markup,
            'suggested' => round($unit * (1 + $markup / 100), 2),
            'tp' => $tp,
            'status' => TransferPrice::alertLevel($unit, $tp),
            'lines' => $lines,
        ];
    }

    /** @return Collection<int, array> كروت المنتجات التامة النشطة */
    public static function all(): Collection
    {
        return Product::query()->where('kind', Product::KIND_FINISHED)->where('active', true)
            ->orderBy('name')->get()
            ->map(fn (Product $p) => self::for($p));
    }

    /** تكلفة آخر باتش أصل للخامة (نفس تعريف Valuation) وإلا تكلفة الصنف */
    private static function lastPrice(Product $m): float
    {
        $cost = DB::table('batches as b')
            ->leftJoin('goods_receipts as g', 'g.id', '=', 'b.goods_receipt_id')
            ->where('b.product_id', $m->id)
            ->where('b.cost', '>', 0)
            ->where('b.qty_received', '>', 0)
            ->whereNull('g.source_warehouse_id')
            ->orderByRaw('COALESCE(g.received_on, DATE(b.created_at)) DESC')
            ->orderByDesc('b.id')
            ->value('b.cost');

        return round((float) ($cost ?? $m->cost), 4);
    }

    /** @return array{0:float, 1:string} */
    private static function overheadPerUnit(Product $p): array
    {
        $o = ProductionOrder::query()->where('product_id', $p->id)->where('status', 'closed')
            ->where('output_qty', '>', 0)->orderByDesc('closed_at')->orderByDesc('id')->first();
        if ($o !== null) {
            return [round((float) $o->overhead_cost / (int) $o->output_qty, 4), 'last_order'];
        }
        $rate = CostingSettings::defaultKgRate();
        if ($rate !== null) {
            return [round(Overhead::kgFor($p, 1)['kg'] * $rate, 4), 'kg_default'];
        }

        return [0.0, 'none'];
    }
}
