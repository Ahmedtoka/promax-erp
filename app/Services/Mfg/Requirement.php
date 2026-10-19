<?php

namespace App\Services\Mfg;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductionIssue;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Support\DateRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * احتياج الخامات (التصنيع ١٩/١٠، spec §3.5) — المعياري قصاد المصروف قصاد الرصيد.
 *
 * كل صف: `{product, std_qty, issued_net, remaining, available, shortage}`
 *   - `std_qty` المعياري للكمية المخططة بالهالك (لقطة سطر الأمر)
 *   - `issued_net` صرف + صرف زيادة − مرتجع
 *   - `remaining` = max(0, المعياري − المصروف)
 *   - `available` رصيد الخامة الصالح في مخزن الخامات
 *   - `shortage` = max(0, الباقي − المتاح)
 *
 * ⚠️ الرصيد المتاح = باتشات مش موقوفة وفيها باقي ومش منتهية — نفس شروط
 * `RawStock::issue` بالظبط، وإلا الشاشة تقول «متاح» والصرف يترفض.
 */
class Requirement
{
    /** @return Collection<int, array{product:Product, std_qty:float, issued_net:float, remaining:float, available:float, shortage:float}> */
    public static function forOrder(ProductionOrder $o): Collection
    {
        $o->loadMissing(['lines.product', 'rawWarehouse']);
        $issued = self::issuedNet($o);

        return $o->lines->map(function ($l) use ($o, $issued) {
            $std = round((float) $l->std_qty, 3);
            $net = round($issued[$l->product_id] ?? 0.0, 3);
            $remaining = max(0.0, round($std - $net, 3));
            $available = $o->rawWarehouse && $l->product ? self::available($o->rawWarehouse, $l->product) : 0.0;

            return [
                'product' => $l->product,
                'std_qty' => $std,
                'issued_net' => $net,
                'remaining' => $remaining,
                'available' => $available,
                'shortage' => max(0.0, round($remaining - $available, 3)),
            ];
        })->values();
    }

    /**
     * نفس الصفوف مجمّعة لكل خامة على الأوامر المخططة والجارية.
     *
     * ⚠️ **العجز بيتحسب لكل مخزن خامات لوحده** ثم يتجمع:
     * `shortage = Σ_w max(0, الباقي_w − المتاح_w)` — فايض في مخزن مابيغطّيش
     * عجز في مخزن تاني (الصرف بيسحب من مخزن الأمر بس). أمرين على نفس المخزن
     * بيتنافسوا على نفس الرصيد. `available` المعروض = مجموع المتاح في المخازن.
     * ⚠️ كويري واحدة للصافي المصروف لكل الأوامر، والمتاح متخزّن لكل (مخزن، خامة).
     *
     * @return Collection<int, array{product:Product, orders:int, std_qty:float, issued_net:float, remaining:float, available:float, shortage:float}>
     */
    public static function forOpenOrders(?DateRange $range = null): Collection
    {
        $q = ProductionOrder::query()->whereIn('status', ['planned', 'in_progress'])
            ->with(['lines.product']);

        $range?->apply($q, 'planned_on');

        $orders = $q->get();

        // order_id ⇒ product_id ⇒ صافي (صرف + زيادة − مرتجع)
        $issued = [];
        if ($orders->isNotEmpty()) {
            ProductionIssue::query()->whereIn('production_order_id', $orders->modelKeys())
                ->groupBy('production_order_id', 'product_id')
                ->select('production_order_id', 'product_id',
                    DB::raw("SUM(CASE WHEN kind = 'return' THEN -qty ELSE qty END) AS net"))
                ->get()
                ->each(function ($r) use (&$issued) {
                    $issued[(int) $r->production_order_id][(int) $r->product_id] = round((float) $r->net, 3);
                });
        }

        $avail = [];
        $agg = [];

        foreach ($orders as $o) {
            $wid = (int) $o->raw_warehouse_id;

            foreach ($o->lines as $l) {
                if ($l->product === null) {
                    continue;
                }

                $pid = $l->product->id;
                $std = round((float) $l->std_qty, 3);
                $net = $issued[$o->id][$pid] ?? 0.0;

                $agg[$pid] ??= ['product' => $l->product, 'orders' => 0, 'std_qty' => 0.0,
                    'issued_net' => 0.0, 'remaining' => []];
                $agg[$pid]['orders']++;
                $agg[$pid]['std_qty'] += $std;
                $agg[$pid]['issued_net'] += $net;
                $agg[$pid]['remaining'][$wid] = ($agg[$pid]['remaining'][$wid] ?? 0.0) + max(0.0, round($std - $net, 3));

                $avail[$wid][$pid] ??= $wid > 0 ? self::availableIn($wid, $pid) : 0.0;
            }
        }

        return collect($agg)->map(function (array $a, int $pid) use ($avail) {
            $available = 0.0;
            $shortage = 0.0;

            foreach ($a['remaining'] as $wid => $rem) {
                $have = $avail[$wid][$pid] ?? 0.0;
                $available += $have;
                $shortage += max(0.0, round($rem - $have, 3));
            }

            return [
                'product' => $a['product'],
                'orders' => $a['orders'],
                'std_qty' => round($a['std_qty'], 3),
                'issued_net' => round($a['issued_net'], 3),
                'remaining' => round(array_sum($a['remaining']), 3),
                'available' => round($available, 3),
                'shortage' => round($shortage, 3),
            ];
        })->sortBy(fn (array $r) => [-$r['shortage'], $r['product']->name])->values();
    }

    /** رصيد الخامة الصالح للصرف في المخزن (مش موقوف، فيه باقي، مش منتهي) */
    public static function available(Warehouse $w, Product $p): float
    {
        return self::availableIn($w->id, $p->id);
    }

    private static function availableIn(int $warehouseId, int $productId): float
    {
        return round((float) Batch::query()
            ->where('warehouse_id', $warehouseId)->where('product_id', $productId)
            ->where('blocked', false)->where('qty_remaining', '>', 0)
            ->whereDate('expires_on', '>=', today()->toDateString())
            ->sum('qty_remaining'), 3);
    }

    /**
     * صافي المصروف للأمر لكل خامة: صرف + زيادة − مرتجع.
     *
     * @return array<int, float> product_id ⇒ qty
     */
    public static function issuedNet(ProductionOrder $o): array
    {
        // ⚠️ مصدر واحد للصافي (`ProductionOrder::netIssuedAll`)
        return $o->netIssuedAll();
    }
}
