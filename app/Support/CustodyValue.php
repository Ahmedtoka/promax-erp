<?php

namespace App\Support;

use App\Models\Custody;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════
 * تقييم بضاعة العهدة بكل قوايم الأسعار — عرض فقط (١٢ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «أي حتة فيها كميات عهدة عاوز شايف قيمتها كمان —
 * لو على القايمة القديمة بكده، ولو على الجديدة بكده».
 *
 * ⚠️ **عرض فقط.** التصفية ومطابقة العهدة بيتحاسبوا **بالقطع** —
 * الأرقام دي استرشادية ومابتدخلش في أي قيد ولا معادلة.
 *
 * ⚠️ **مفيش حساب سعر جديد هنا.** السعر من `price_list_items`
 * (نفس مصدر `Pricing::listPrice` بالحرف، بما فيه احتياطي عمودي
 * `old`/`new` للداتابيز اللي لسه ماهاجرتش، وصفر للقايمة المسمّاة
 * الناقصة — مش سقوط صامت لسعر قايمة تانية).
 *
 * ⚠️ **دفعة واحدة.** البوردات بترسم كل الفريق — القوايم وبنودها
 * بيتحمّلوا مرة واحدة للريكوست كله (ميمو استاتيك زي
 * `PriceList::default()`)، مش كويري لكل صف.
 */
class CustodyValue
{
    /** @var Collection<int, PriceList>|null القوايم المفعّلة ببنودها — ميمو للريكوست */
    protected static ?Collection $lists = null;

    /** @var array<int, array<int, float>> [list_id][product_id] => price — من البنود المحمّلة */
    protected static array $priceMaps = [];

    /** القوايم المفعّلة بترتيب ثابت — كل الشاشات بتعرضها بنفس الترتيب */
    public static function lists(): Collection
    {
        return self::$lists ??= PriceList::with('items')
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * قايمة المندوب بقاعدة السيستم — السواق بالقديمة والسيلز بالجديدة.
     * نفس قاعدة كل الشاشات (`isDriver() ? 'old' : 'new'`) في مكان واحد.
     */
    public static function listForRep(?User $rep): ?PriceList
    {
        $code = $rep?->isDriver() ? Pricing::LIST_OLD : Pricing::LIST_NEW;

        return self::lists()->firstWhere('code', $code) ?? PriceList::default();
    }

    /**
     * سعر صنف في قايمة — من البنود المحمّلة (من غير كويري لكل صنف).
     *
     * ⚠️ نفس دلالة `Pricing::listPrice(product, PriceList)` بالحرف:
     * سعر البند لو موجود، وإلا عمود `price_old`/`price_new` للقايمتين
     * المهاجرتين، وإلا **صفر** — القايمة المسمّاة الناقصة مابتسقطش
     * في صمت لسعر قايمة تانية (قرار المالك 2026-08-02).
     */
    public static function priceIn(?PriceList $list, ?Product $product): float
    {
        if ($list === null || $product === null) {
            return 0.0;
        }

        self::$priceMaps[$list->id] ??= $list->items
            ->pluck('price', 'product_id')
            ->map(fn ($p) => (float) $p)
            ->all();

        $price = self::$priceMaps[$list->id][$product->id] ?? 0.0;

        if ($price > 0) {
            return $price;
        }

        return in_array($list->code, Pricing::LISTS, true)
            ? Pricing::listPrice($product, $list->code)
            : 0.0;
    }

    /**
     * إجمالي قيمة صفوف (صنف + كمية) في **كل** قايمة مفعّلة.
     *
     * @param  iterable<array{product: ?Product, qty: int}>  $rows
     * @return array<int, array{list: PriceList, total: float}> بمفتاح list_id
     */
    public static function totals(iterable $rows): array
    {
        $out = [];

        foreach (self::lists() as $list) {
            $out[$list->id] = ['list' => $list, 'total' => 0.0];
        }

        foreach ($rows as $row) {
            $product = $row['product'] ?? null;
            $qty = (int) ($row['qty'] ?? 0);

            if ($product === null || $qty <= 0) {
                continue;
            }

            foreach (self::lists() as $list) {
                $out[$list->id]['total'] += $qty * self::priceIn($list, $product);
            }
        }

        foreach ($out as &$t) {
            $t['total'] = round($t['total'], 2);
        }

        return $out;
    }

    /**
     * قيمة **الباقي** في عهدة بكل قايمة — نفس تعريف `remainingValue`:
     * `remaining()` من غير الهدايا (الهدايا مش بضاعة بيع).
     */
    public static function remainingTotals(?Custody $custody): array
    {
        if ($custody === null) {
            return [];
        }

        return self::totals($custody->items->map(fn ($i) => [
            'product' => $i->product,
            'qty' => (int) $i->remaining(),
        ]));
    }

    /**
     * جمع نواتج `totals` لأكتر من مندوب — لكروت الـKPI على البوردات.
     *
     * @param  iterable<array<int, array{list: PriceList, total: float}>>  $many
     */
    public static function merge(iterable $many): array
    {
        $out = [];

        foreach (self::lists() as $list) {
            $out[$list->id] = ['list' => $list, 'total' => 0.0];
        }

        foreach ($many as $totals) {
            foreach ($totals as $listId => $t) {
                if (isset($out[$listId])) {
                    $out[$listId]['total'] += (float) ($t['total'] ?? 0);
                }
            }
        }

        foreach ($out as &$t) {
            $t['total'] = round($t['total'], 2);
        }

        return $out;
    }
}
