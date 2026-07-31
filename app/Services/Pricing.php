<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Client;
use App\Models\Product;

/**
 * ═══════════════════════════════════════════════════════════════
 * دوكترين التسعير — المكان الوحيد اللي بيحسب سعر بيع في السيستم
 * Pricing doctrine — the ONLY place a selling price is computed.
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ ممنوع أي شاشة أو كنترولر يحسب سعر بنفسه. لو محتاج سعر
 * نادِ على Pricing::quote() أو Pricing::listPrice().
 *
 * الترتيب:
 *   1. قائمة السعر  → العقد يثبّتها، وإلا العميل، وإلا الجديد
 *   2. سعر القائمة  → products.price_old أو products.price_new
 *   3. الخصم        → العقد → خصم خاص → السلسلة → القناة
 *   4. التكلفة      → تكلفة الباتش لو معروف، وإلا تكلفة المنتج
 */
class Pricing
{
    public const LIST_OLD = 'old';
    public const LIST_NEW = 'new';

    public const LISTS = [self::LIST_OLD, self::LIST_NEW];

    /**
     * قائمة السعر المعتمدة للعميل.
     * العقد بيغلب لأنه اتفاق مكتوب.
     */
    public static function listFor(Client $client): string
    {
        // ⚠️ liveContract() هي المصدر الوحيد للعقد: بتاع العميل لو موجود
        // وسارٍ، وإلا بتاع سلسلته. وبتتأكد إنه active ومش منتهي — لولا كده
        // كان ممكن العميل ياخد قائمة سعر من عقد ميت.
        $contract = $client->liveContract();

        $fromContract = $contract?->price_list;
        if (in_array($fromContract, self::LISTS, true)) {
            return $fromContract;
        }

        return in_array($client->price_list, self::LISTS, true)
            ? $client->price_list
            : self::LIST_NEW;
    }

    /** سعر القائمة قبل أي خصم */
    public static function listPrice(Product $product, string $list = self::LIST_NEW): float
    {
        return (float) ($list === self::LIST_OLD ? $product->price_old : $product->price_new);
    }

    /**
     * التكلفة المعتمدة. تكلفة الباتش أدق لأنها فعلية،
     * وتكلفة المنتج قياسية بتستخدم لو الباتش مش معروف.
     */
    public static function costFor(Product $product, ?Batch $batch = null): float
    {
        $batchCost = $batch?->cost === null ? 0.0 : (float) $batch->cost;

        return $batchCost > 0 ? $batchCost : (float) $product->cost;
    }

    /**
     * التسعير الكامل لصنف على عميل.
     *
     * @return array{
     *   list: string, list_price: float, discount_pct: float, discount_source: string,
     *   unit_price: float, unit_cost: float, margin: float, margin_pct: float
     * }
     */
    public static function quote(
        Client $client,
        Product $product,
        ?Batch $batch = null,
        int $qty = 1,
    ): array {
        $list = self::listFor($client);
        $listPrice = self::listPrice($product, $list);
        $discountPct = $client->effectiveDiscount();

        $unitPrice = round($listPrice * (1 - $discountPct), 2);
        $unitCost = self::costFor($product, $batch);

        $margin = round($unitPrice - $unitCost, 2);

        return [
            'list' => $list,
            'list_price' => $listPrice,
            'discount_pct' => $discountPct,
            'discount_source' => $client->discountSourceKey(),
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'margin' => $margin,
            'margin_pct' => $unitPrice > 0 ? round($margin / $unitPrice, 4) : 0.0,
            'qty' => $qty,
            'line_total' => round($unitPrice * $qty, 2),
            'line_cost' => round($unitCost * $qty, 2),
        ];
    }

    /** سعر الوحدة للعميل بعد الخصم — الاستخدام السريع */
    public static function unitPrice(Client $client, Product $product): float
    {
        $list = self::listFor($client);

        return round(self::listPrice($product, $list) * (1 - $client->effectiveDiscount()), 2);
    }

    /**
     * سعر بقائمة معيّنة من غير عميل — للمخزون وأوامر التوريد.
     * ⚠️ ده سعر قائمة، مش سعر عميل. مفيش خصم فيه.
     */
    public static function byList(Product $product, string $list): float
    {
        return self::listPrice($product, in_array($list, self::LISTS, true) ? $list : self::LIST_NEW);
    }

    /** هامش الربح على سعر القائمة — لشاشة المخزون */
    public static function marginPct(Product $product, string $list = self::LIST_NEW): float
    {
        $price = self::listPrice($product, $list);

        return $price > 0 ? round(($price - (float) $product->cost) / $price, 4) : 0.0;
    }

    public static function listLabel(string $list): string
    {
        return __('stock.price_list_'.(in_array($list, self::LISTS, true) ? $list : self::LIST_NEW));
    }
}
