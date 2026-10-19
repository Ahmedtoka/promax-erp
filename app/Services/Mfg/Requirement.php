<?php

namespace App\Services\Mfg;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Support\DateRange;
use Illuminate\Support\Collection;

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
     * المتاح بيتجمع **مرة واحدة لكل مخزن خامات** — أمرين على نفس المخزن
     * بيتنافسوا على نفس الرصيد، مش كل واحد له رصيده.
     *
     * @return Collection<int, array{product:Product, orders:int, std_qty:float, issued_net:float, remaining:float, available:float, shortage:float}>
     */
    public static function forOpenOrders(?DateRange $range = null): Collection
    {
        $q = ProductionOrder::query()->whereIn('status', ['planned', 'in_progress'])
            ->with(['lines.product', 'rawWarehouse']);

        $range?->apply($q, 'planned_on');

        $agg = [];

        foreach ($q->get() as $o) {
            foreach (self::forOrder($o) as $r) {
                $pid = $r['product']?->id;
                if ($pid === null) {
                    continue;
                }

                $agg[$pid] ??= ['product' => $r['product'], 'orders' => 0, 'std_qty' => 0.0,
                    'issued_net' => 0.0, 'remaining' => 0.0, 'warehouses' => []];
                $agg[$pid]['orders']++;
                $agg[$pid]['std_qty'] += $r['std_qty'];
                $agg[$pid]['issued_net'] += $r['issued_net'];
                $agg[$pid]['remaining'] += $r['remaining'];
                $agg[$pid]['warehouses'][$o->raw_warehouse_id] = $r['available'];
            }
        }

        return collect($agg)->map(function (array $a) {
            $available = round(array_sum($a['warehouses']), 3);
            $remaining = round($a['remaining'], 3);

            return [
                'product' => $a['product'],
                'orders' => $a['orders'],
                'std_qty' => round($a['std_qty'], 3),
                'issued_net' => round($a['issued_net'], 3),
                'remaining' => $remaining,
                'available' => $available,
                'shortage' => max(0.0, round($remaining - $available, 3)),
            ];
        })->sortBy(fn (array $r) => [-$r['shortage'], $r['product']->name])->values();
    }

    /** رصيد الخامة الصالح للصرف في المخزن (مش موقوف، فيه باقي، مش منتهي) */
    public static function available(Warehouse $w, Product $p): float
    {
        return round((float) Batch::query()
            ->where('warehouse_id', $w->id)->where('product_id', $p->id)
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
