<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الضريبة
 * ═══════════════════════════════════════════════════════════════
 *
 * الضريبة بتمسّ كل فاتورة وكل قيد، وأي غلط فيها بلاغ مصلحة ضرائب
 * مش باج. التيستات دي بتحرس القواعد التلاتة:
 *   1. مقفولة افتراضياً
 *   2. الشروط التلاتة (السيستم + العميل + الصنف) لازم كلها تتحقق
 *   3. الأساس هو الصافي بعد الخصم، والجمع سطر بسطر
 */
class TaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_is_off_by_default(): void
    {
        $client = $this->makeClient(['taxable' => true, 'tax_rate' => 0.14]);
        $product = $this->makeProduct();

        $this->assertFalse(Tax::enabled());
        $this->assertSame(0.0, Tax::rate($client, $product));
        $this->assertSame(0.0, Tax::on(1000, $client, $product));
    }

    public function test_non_taxable_client_pays_nothing_even_when_tax_is_on(): void
    {
        $this->enableTax(14);

        $client = $this->makeClient(['taxable' => false]);
        $product = $this->makeProduct();

        $this->assertSame(0.0, Tax::rate($client, $product));
        $this->assertSame(0.0, Tax::on(1000, $client, $product));
    }

    public function test_exempt_product_pays_nothing_for_a_taxable_client(): void
    {
        $this->enableTax(14);

        $client = $this->makeClient(['taxable' => true]);
        $exempt = $this->makeProduct(['taxable' => false]);

        $this->assertSame(0.0, Tax::rate($client, $exempt));
    }

    public function test_general_rate_applies_when_nothing_more_specific_is_set(): void
    {
        $this->enableTax(14);

        $client = $this->makeClient(['taxable' => true, 'tax_rate' => 0]);
        $product = $this->makeProduct(['tax_rate' => 0]);

        $this->assertSame(0.14, Tax::rate($client, $product));
        $this->assertSame(140.0, Tax::on(1000, $client, $product));
    }

    public function test_product_rate_beats_client_rate_which_beats_the_general_rate(): void
    {
        $this->enableTax(14);

        // العميل بنسبة خاصة 10%
        $client = $this->makeClient(['taxable' => true, 'tax_rate' => 0.10]);

        // صنف من غير نسبة → ياخد نسبة العميل
        $plain = $this->makeProduct(['tax_rate' => 0]);
        $this->assertSame(0.10, Tax::rate($client, $plain));

        // صنف بنسبته الخاصة 5% → الأخص بيكسب
        $special = $this->makeProduct(['tax_rate' => 0.05]);
        $this->assertSame(0.05, Tax::rate($client, $special));
    }

    public function test_totals_sum_line_by_line_not_by_multiplying_the_grand_total(): void
    {
        // ⚠️ ده جوهر الحساب: فاتورة فيها صنف خاضع وصنف معفى.
        // ضرب الإجمالي في النسبة بيدي 280، والصح 140.
        $rows = [
            ['total' => 1000.0, 'tax' => 140.0],   // خاضع 14%
            ['total' => 1000.0, 'tax' => 0.0],     // معفى
        ];

        $sums = Tax::totals($rows);

        $this->assertSame(2000.0, $sums['net']);
        $this->assertSame(140.0, $sums['tax']);
        $this->assertSame(2140.0, $sums['grand']);
    }

    public function test_rounding_stays_at_two_decimals(): void
    {
        $this->enableTax(14);

        $client = $this->makeClient(['taxable' => true]);
        $product = $this->makeProduct();

        // 33.33 × 14% = 4.6662 → 4.67
        $this->assertSame(4.67, Tax::on(33.33, $client, $product));
    }

    public function test_settings_cache_is_cleared_on_write(): void
    {
        // ⚠️ الإعدادات متكاشة "للأبد". من غير مسح الكاش عند الحفظ،
        // تفعيل الضريبة مايبقاش له أثر لحد ما الكاش يتمسح يدوي.
        $this->assertFalse(Tax::enabled());

        Setting::write('tax_enabled', '1');

        $this->assertTrue(Tax::enabled());
    }

    public function test_label_drops_trailing_zeros(): void
    {
        $this->assertSame('14%', Tax::label(0.14));
        $this->assertSame('5%', Tax::label(0.05));
        $this->assertSame('12.5%', Tax::label(0.125));
    }
}
