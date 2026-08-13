<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الفاتورة: الضريبة + الليدجر
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ التيست ده بيحرس **أخطر قاعدة اتزرعت النهارده**:
 *
 *   `invoices.total` = صافي المبيعات قبل الضريبة
 *   `invoices.grand_total` = اللي العميل بيدفعه
 *   والليدجر بيتقيّد بـ **grand_total** مش total
 *
 * لو حد بدّل الاتنين، الرصيد هيقل بمقدار الضريبة على كل فاتورة
 * وهو خطأ بيتراكم من غير ما حد ياخد باله لحد المطابقة الشهرية.
 */
class InvoiceTaxLedgerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Client, 2: \App\Models\Product} */
    private function scene(bool $taxable = true): array
    {
        $channel = $this->makeChannel(0.0);
        $zone = $this->makeZone();

        $rep = $this->makeRep(['zone_id' => $zone->id, 'channel_id' => $channel->id]);

        // ⚠️ **الحضور قبل أي بيع** (حارس `RequireAttendance`، ٨/٨/٢٠٢٦).
        // من غيره `sell()` بترجّع 423 «مش مسجّل حضور» بدل 201.
        $this->punchIn($rep);

        $client = $this->makeClient([
            'zone_id' => $zone->id,
            'channel_id' => $channel->id,
            'taxable' => $taxable,
        ]);
        $product = $this->makeProduct(['cost' => 10, 'price_new' => 20]);

        $warehouse = $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-1',
            'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6),
            'qty_received' => 100,
            'qty_remaining' => 100,
            'cost' => 10,
        ]);

        $custody = Custody::create([
            'user_id' => $rep->id,
            'warehouse_id' => $warehouse->id,
            'date' => today(),
            'status' => 'open',
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'assigned' => 50,
            'sold' => 0,
        ]);

        return [$rep, $client, $product];
    }

    private function sell(array $scene, string $payment = 'credit', int $qty = 10)
    {
        [$rep, $client, $product] = $scene;

        // ⚠️ كاش/آجل بقت من **تعريف العميل** مش من البوست (قرار
        // المالك 2026-08-03) — بنثبّتها على العميل عشان السيناريو
        // يتنفذ بالطريقة اللي التيست قاصدها، والحقل في البوست بيتطنش.
        $client->update(['payment_terms' => $payment]);

        // ⚠️ التوكن مش actingAs — الـ API بيصادق بالـ Bearer.
        // و`sellApi` بتفتح الزيارة كمان: `visit_id` بقت `required`
        // على الإندبوينت (تدقيق ٨/٨/٢٠٢٦).
        return $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => $qty]],
            ['payment' => $payment]);
    }

    public function test_invoice_without_tax_keeps_total_equal_to_grand_total(): void
    {
        $scene = $this->scene(taxable: false);

        $this->sell($scene);

        $invoice = Invoice::latest('id')->first();

        $this->assertNotNull($invoice, 'الفاتورة مااتعملتش');
        $this->assertEqualsWithDelta(200.0, (float) $invoice->total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->tax_total, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $invoice->grand_total, 0.01);
        $this->assertEqualsWithDelta(200.0, $invoice->payable(), 0.01);
    }

    public function test_taxable_invoice_splits_net_and_tax_and_bills_the_grand_total(): void
    {
        $this->enableTax(14);
        $scene = $this->scene(taxable: true);

        $this->sell($scene);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);

        // 10 × 20 = 200 صافي، الضريبة 28، المستحق 228
        $this->assertEqualsWithDelta(200.0, (float) $invoice->total, 0.01,
            'total لازم يفضل صافي المبيعات قبل الضريبة');
        $this->assertEqualsWithDelta(28.0, (float) $invoice->tax_total, 0.01);
        $this->assertEqualsWithDelta(228.0, (float) $invoice->grand_total, 0.01);

        // ⚠️ الليدجر بالمستحق شامل الضريبة
        $sale = Transaction::where('client_id', $scene[1]->id)->where('kind', 'sale')->first();

        $this->assertNotNull($sale, 'مفيش قيد مبيعات');
        $this->assertEqualsWithDelta(228.0, (float) $sale->debit, 0.01,
            'القيد لازم يكون بالإجمالي شامل الضريبة مش بالصافي');
    }

    public function test_cash_invoice_leaves_a_zero_balance_after_tax(): void
    {
        $this->enableTax(14);
        $scene = $this->scene(taxable: true);

        $this->sell($scene, payment: 'cash');

        $client = $scene[1]->fresh();

        // ⚠️ لو التحصيل اتقيّد بالصافي والبيع بالإجمالي، الرصيد
        // هيفضل عليه فرق الضريبة والعميل هيبان مديون وهو دافع.
        $this->assertEqualsWithDelta(0.0, (float) $client->balance, 0.01,
            'فاتورة الكاش لازم ترجّع الرصيد صفر بالظبط');
    }

    public function test_profit_ignores_tax(): void
    {
        $this->enableTax(14);
        $scene = $this->scene(taxable: true);

        $this->sell($scene);

        $invoice = Invoice::latest('id')->first();

        // 200 مبيعات − 100 تكلفة = 100 ربح. الضريبة مالهاش دخل.
        $this->assertEqualsWithDelta(100.0, $invoice->profit(), 0.01,
            'الضريبة فلوس المصلحة — ممنوع تدخل في الربح');
    }

    public function test_invoice_lines_carry_their_own_tax(): void
    {
        $this->enableTax(14);
        $scene = $this->scene(taxable: true);

        $this->sell($scene);

        $invoice = Invoice::latest('id')->first();
        $line = $invoice->items->first();

        $this->assertEqualsWithDelta(0.14, (float) $line->tax_rate, 0.0001);
        $this->assertEqualsWithDelta(28.0, (float) $line->tax, 0.01);

        // مجموع ضريبة السطور = ضريبة الفاتورة
        $this->assertEqualsWithDelta(
            (float) $invoice->tax_total,
            (float) $invoice->items->sum('tax'),
            0.01,
            'مجموع ضريبة السطور لازم يساوي ضريبة الفاتورة بالمليم',
        );
    }

    public function test_line_totals_still_add_up_to_the_invoice_net(): void
    {
        $this->enableTax(14);
        $scene = $this->scene(taxable: true);

        $this->sell($scene);

        $invoice = Invoice::latest('id')->first();

        $this->assertEqualsWithDelta(
            (float) $invoice->total,
            (float) $invoice->items->sum('total'),
            0.01,
            'Σ invoice_items.total لازم تساوي invoices.total بالمليم',
        );
    }
}
