<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Services\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══ السطر لازم يطابق الورق (قرار المالك ١٠/١١/٢٠٢٦) ═══
 *
 * الورق والأبلكيشن بيجمعوا **الكمية × السعر الشامل**، والسعر الشامل دايماً
 * ربع جنيه. السعر المتخزّن غير شامل بـ٤ أرقام، والسطر مترسي على الشامل
 * (`Tax::line`) — فـ١٠٠ × ٦٣ = ٦٣٠٠ بالظبط مش ٦٣٠٠٫٧٨.
 */
class PaperExactLineTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: float, 1: int}> */
    public static function papers(): array
    {
        return [[63.00, 7], [63.00, 100], [63.00, 13], [101.25, 3], [101.25, 17], [58.50, 9], [58.50, 101], [276.25, 11], [42.00, 37]];
    }

    public function test_line_total_equals_qty_times_incl_for_every_paper_price(): void
    {
        foreach (self::papers() as [$incl, $qty]) {
            $excl = round($incl / 1.14, 4);              // اللي بيتخزّن
            $line = Tax::line($excl, $qty, 0.14);

            $this->assertEqualsWithDelta($incl, $line['unit_incl'], 0.0001, "سعر الوحدة شامل {$incl}");
            $this->assertEqualsWithDelta(round($incl * $qty, 2), $line['incl'], 0.0001, "{$qty} × {$incl}");
            $this->assertEqualsWithDelta($line['incl'], round($line['net'] + $line['tax'], 2), 0.0001, 'صافي + ضريبة = شامل (مفيش تقريب مرتين)');
            $this->assertEqualsWithDelta($line['net'] * 0.14, $line['tax'], 0.0101, 'الضريبة ≈ ١٤٪ من الصافي');
        }
    }

    public function test_discount_from_a_four_decimal_list_lands_on_the_paper_price(): void
    {
        // سعر المستهلك 90 → القايمة 78.9474، خصم ٣٠٪ → الورق 63
        $unit = round(round(90 / 1.14, 4) * (1 - 0.30), 4);
        $this->assertEqualsWithDelta(6300.00, Tax::line($unit, 100, 0.14)['incl'], 0.0001);
        // 425 × 0.65 = 276.25
        $unit = round(round(425 / 1.14, 4) * (1 - 0.35), 4);
        $this->assertEqualsWithDelta(276.25 * 11, Tax::line($unit, 11, 0.14)['incl'], 0.0001);
    }

    public function test_exempt_line_keeps_the_old_two_decimal_math(): void
    {
        $line = Tax::line(55.2632, 7, 0.0);
        $this->assertEqualsWithDelta(386.82, $line['net'], 0.0001); // 55.26 × 7
        $this->assertEqualsWithDelta(0.0, $line['tax'], 0.0001);
    }

    public function test_an_app_invoice_grand_total_equals_qty_times_incl(): void
    {
        $this->enableTax(14);
        $channel = $this->makeChannel(0.0);
        $zone = $this->makeZone();
        $rep = $this->makeRep(['zone_id' => $zone->id, 'channel_id' => $channel->id]);
        $this->punchIn($rep);
        $client = $this->makeClient(['zone_id' => $zone->id, 'channel_id' => $channel->id, 'taxable' => true, 'discount' => 0.30]);
        $product = $this->makeProduct(['cost' => 10, 'price_new' => round(90 / 1.14, 4)]);
        $wh = $this->makeWarehouse();
        $batch = Batch::create(['product_id' => $product->id, 'warehouse_id' => $wh->id, 'batch_no' => 'B', 'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6), 'qty_received' => 500, 'qty_remaining' => 500, 'cost' => 10]);
        $cu = Custody::create(['user_id' => $rep->id, 'warehouse_id' => $wh->id, 'date' => today(), 'status' => 'open']);
        CustodyItem::create(['custody_id' => $cu->id, 'product_id' => $product->id, 'batch_id' => $batch->id, 'assigned' => 200, 'sold' => 0]);

        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 13]])->assertStatus(201);

        $inv = Invoice::latest('id')->first();
        $this->assertEqualsWithDelta(13 * 63.00, (float) $inv->grand_total, 0.0001, 'الفاتورة = ١٣ × ٦٣ بالظبط');
        $this->assertEqualsWithDelta(55.2632, (float) $inv->items->first()->price, 0.00005, 'سعر الوحدة غير شامل بـ٤ أرقام');
    }
}
