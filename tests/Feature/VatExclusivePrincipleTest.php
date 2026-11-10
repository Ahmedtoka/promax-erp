<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Client;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══ مبدأ «السعر غير شامل دايماً» (قرار المالك ١٠/١١/٢٠٢٦) ═══
 *
 *   • السعر المتخزّن غير شامل، والضريبة بتتضاف فوقه **مرة واحدة**.
 *   • العميل الجديد خاضع افتراضياً — «معفى» استثناء صريح.
 *   • الأبلكيشن بياخد سطور الفاتورة بالشامل المخزّن (صافي + ضريبة)
 *     عشان يعرضه زي ما هو من غير ما يضرب في النسبة تاني.
 *   • الويب: أعمدة «الضريبة | الشامل» لكل سطر ورا مربع اختيار، والشامل
 *     = صافي السطر + ضريبته المخزّنة.
 */
class VatExclusivePrincipleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_client_is_taxable_unless_told_otherwise(): void
    {
        // مسار زي تحويل ليد/طلب عميل: مابيبعتش `taxable` خالص
        $c = Client::create([
            'code' => 'CL-T1', 'name' => 'جديد', 'category' => 'ok', 'status' => 'active',
        ]);

        $this->assertTrue((bool) $c->fresh()->taxable, 'العميل الجديد لازم ينزل خاضع');

        // والمعفى بيفضل ممكن بشكل صريح
        $e = Client::create([
            'code' => 'CL-T2', 'name' => 'معفى', 'category' => 'ok', 'status' => 'active', 'taxable' => false,
        ]);
        $this->assertFalse((bool) $e->fresh()->taxable);
    }

    /** @return array{0: \App\Models\User, 1: Client, 2: \App\Models\Product} */
    private function scene(): array
    {
        $this->enableTax(14);
        $channel = $this->makeChannel(0.0);
        $zone = $this->makeZone();
        $rep = $this->makeRep(['zone_id' => $zone->id, 'channel_id' => $channel->id]);
        $this->punchIn($rep);

        $client = $this->makeClient(['zone_id' => $zone->id, 'channel_id' => $channel->id, 'taxable' => true]);
        // سعر القايمة غير شامل: 78.95 (= 90 شامل)
        $product = $this->makeProduct(['cost' => 10, 'price_new' => 78.95]);
        $warehouse = $this->makeWarehouse();
        $batch = Batch::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => 'B-1',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 100, 'qty_remaining' => 100, 'cost' => 10,
        ]);
        $custody = Custody::create(['user_id' => $rep->id, 'warehouse_id' => $warehouse->id, 'date' => today(), 'status' => 'open']);
        CustodyItem::create(['custody_id' => $custody->id, 'product_id' => $product->id, 'batch_id' => $batch->id, 'assigned' => 50, 'sold' => 0]);

        return [$rep, $client, $product];
    }

    public function test_the_app_gets_each_line_incl_as_stored_net_plus_tax_once(): void
    {
        [$rep, $client, $product] = $this->scene();

        $res = $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 3]]);
        $res->assertStatus(201);

        // 3 × 78.95 = 236.85 صافي، ضريبة 33.16، شامل 270.01
        $line = $res->json('invoice.items.0');
        $this->assertNotNull($line, 'الرد لازم يرجّع سطور الفاتورة');
        $this->assertEqualsWithDelta(236.85, $line['net'], 0.001);
        $this->assertEqualsWithDelta(33.16, $line['tax'], 0.001);
        $this->assertEqualsWithDelta(270.01, $line['incl'], 0.001);

        // ⚠️ الضريبة مرة واحدة: مجموع الشامل = grand_total
        $this->assertEqualsWithDelta((float) $res->json('invoice.grand_total'), $line['incl'], 0.001);
    }

    public function test_invoice_page_offers_vat_per_line_with_incl_equal_net_plus_tax(): void
    {
        [$rep, $client, $product] = $this->scene();
        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 3]])->assertStatus(201);
        $inv = Invoice::latest('id')->first();

        $page = $this->actingAs($this->makeAdmin())->get(route('ops.invoice', $inv));
        $page->assertOk();
        $page->assertSee('vatLinesToggle', false);
        $page->assertSee('c-vat', false);
        $page->assertSee('270.01');             // شامل السطر = 236.85 + 33.16
        // فاتورة المندوب (من زيارة) بتفتح والأعمدة ظاهرة
        $this->assertMatchesRegularExpression('/id="vatLinesToggle"\s+checked/', $page->getContent());
    }
}
