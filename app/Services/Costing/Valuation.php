<?php

namespace App\Services\Costing;

use App\Models\Batch;
use App\Models\Product;
use App\Services\Pricing;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * التكلفة الحقيقية R للوحدة (الخط ٩ — D5).
 *
 * • طريقة الباتش: تكلفة الباتش لو > 0 وإلا تكلفة الصنف (`Pricing::costFor`).
 * • المتوسط المرجّح: Σ(الكمية المستلمة × التكلفة) ÷ Σ الكمية المستلمة على
 *   **باتشات الأصل** بس — الباتش اللي إذنه بيستلم من مخزن تاني
 *   (`goods_receipts.source_warehouse_id` مش فاضي) توأم تحويل ومايتعدّش —
 *   بتكلفة > 0 ومستلمة في التاريخ أو قبله (تاريخ الإذن، وإلا `DATE(created_at)`
 *   للباتش). مفيش باتشات = تكلفة الصنف. تراكمي وثابت فبيتعاد بناؤه بنفس الرقم.
 *
 * الحفظ في الذاكرة لكل (صنف، يوم) طول الريكوست — `flush()` قبل أي مزامنة.
 */
final class Valuation
{
    /** @var array<string, float> */
    private static array $memo = [];

    public static function wavg(Product|int $product, CarbonInterface $date): float
    {
        $pid = $product instanceof Product ? (int) $product->id : $product;
        $day = $date->toDateString();
        $key = $pid.'|'.$day;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $row = DB::table('batches as b')
            ->leftJoin('goods_receipts as g', 'g.id', '=', 'b.goods_receipt_id')
            ->where('b.product_id', $pid)
            ->where('b.cost', '>', 0)
            ->where('b.qty_received', '>', 0)
            ->whereNull('g.source_warehouse_id')
            ->whereRaw('COALESCE(g.received_on, DATE(b.created_at)) <= ?', [$day])
            ->selectRaw('COALESCE(SUM(b.qty_received * b.cost), 0) AS v, COALESCE(SUM(b.qty_received), 0) AS q')
            ->first();

        $q = (float) ($row->q ?? 0);
        if ($q > 0) {
            $unit = round((float) $row->v / $q, 4);
        } else {
            $cost = $product instanceof Product ? $product->cost : Product::whereKey($pid)->value('cost');
            $unit = round((float) $cost, 4);
        }

        return self::$memo[$key] = $unit;
    }

    /** تكلفة الباتش للوحدة — نفس `Pricing::costFor` بالظبط */
    public static function batchUnit(?Batch $b, Product $p): float
    {
        return Pricing::costFor($p, $b);
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
