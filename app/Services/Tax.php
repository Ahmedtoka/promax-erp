<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\Setting;

/**
 * ═══════════════════════════════════════════════════════════════
 * الضريبة — المكان الوحيد اللي بتتحسب فيه
 * ═══════════════════════════════════════════════════════════════
 *
 * زي `Pricing` بالظبط: ممنوع أي كنترولر أو فيو أو سيدر يحسب ضريبة
 * بإيده. لو الحساب اتكرر في مكانين، أول تعديل في النسبة هيخلّي
 * الفاتورة والتصدير الضريبي مختلفين، وده بلاغ مصلحة ضرائب مش باج.
 *
 * ═══ القاعدة ═══
 *
 * الضريبة بتتحسب على السطر **بعد الخصم**، وبتتجمع على الفاتورة.
 * مابتنطبقش غير لما التلاتة يتحققوا:
 *
 *   1. الضريبة **مفعّلة** في إعدادات الشركة (`tax_enabled`)
 *   2. **العميل** خاضع (`clients.taxable`) — العميل المسجّل ضريبياً بس
 *   3. **الصنف** خاضع (`products.taxable`) — في سلع غذائية معفاة
 *
 * والنسبة بترتيب: نسبة الصنف ← نسبة العميل ← النسبة العامة.
 * (الصنف أخص من العميل، والعميل أخص من العام.)
 *
 * ⚠️ **الأساس هو الصافي بعد الخصم**، مش سعر القائمة. حساب الضريبة على
 * سعر القائمة بيحصّل من العميل ضريبة على خصم مااتحصلش — وده بيخلّي
 * الفاتورة أعلى من الواقع.
 */
class Tax
{
    /** هل الضريبة مشغّلة أصلاً في السيستم */
    public static function enabled(): bool
    {
        return Setting::read('tax_enabled', '0') === '1';
    }

    /** النسبة العامة ككسر (0.14) — الإعداد متخزّن كنسبة مئوية */
    public static function defaultRate(): float
    {
        return round(((float) Setting::read('tax_rate', '0')) / 100, 4);
    }

    /**
     * نسبة الضريبة لسطر معيّن — كسر (0.14 = 14%).
     *
     * بترجع صفر لو الضريبة مقفولة أو العميل معفى أو الصنف معفى.
     */
    public static function rate(Client $client, ?Product $product = null): float
    {
        if (! self::enabled() || ! $client->taxable) {
            return 0.0;
        }

        if ($product !== null) {
            $taxable = $product->getAttribute('taxable');

            // ⚠️ `null` معناها العمود لسه مااتعملش (مايجريشن مااتشغّلش).
            // بنعتبرها **خاضعة** مش معفاة: الإعفاء استثناء، ولو عاملناها
            // إعفاء هتقفل الضريبة على السيستم كله في صمت.
            if ($taxable !== null && ! $taxable) {
                return 0.0;
            }
        }

        $productRate = (float) ($product?->getAttribute('tax_rate') ?? 0);
        if ($productRate > 0) {
            return round($productRate, 4);
        }

        $clientRate = (float) $client->tax_rate;
        if ($clientRate > 0) {
            return round($clientRate, 4);
        }

        return self::defaultRate();
    }

    /**
     * ضريبة سطر واحد.
     *
     * @param  float  $net  إجمالي السطر **بعد الخصم**
     */
    public static function on(float $net, Client $client, ?Product $product = null): float
    {
        return round($net * self::rate($client, $product), 2);
    }

    /**
     * ملخص فاتورة من سطورها.
     *
     * كل سطر لازم يكون فيه `total` (صافي بعد الخصم) و `tax`.
     * الجمع بيتم على الضريبة **المحسوبة سطر بسطر** مش على الإجمالي —
     * لأن السطور ممكن تكون بنسب مختلفة (صنف معفى وصنف خاضع في نفس
     * الفاتورة)، وضرب الإجمالي في نسبة واحدة بيطلع رقم غلط.
     *
     * @param  array<int, array{total: float|int, tax: float|int}>  $rows
     * @return array{net: float, tax: float, grand: float}
     */
    public static function totals(array $rows): array
    {
        $net = 0.0;
        $tax = 0.0;

        foreach ($rows as $r) {
            $net += (float) ($r['total'] ?? 0);
            $tax += (float) ($r['tax'] ?? 0);
        }

        $net = round($net, 2);
        $tax = round($tax, 2);

        return ['net' => $net, 'tax' => $tax, 'grand' => round($net + $tax, 2)];
    }

    /** النسبة كنص للعرض: 14% */
    public static function label(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2), '0'), '.').'%';
    }
}
