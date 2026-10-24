<?php

namespace Tests\Feature\Reprice;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Gl\GlPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reprice\InvoiceRepricer;
use App\Services\Reprice\PoRepricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعادة تسعير فاتورة واحدة / أمر توريد واحد — تيستات توصيف
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ التيستات دي اتكتبت **على الكود قبل الاستخراج** ونجحت عليه —
 * وظيفتها إنها تثبت إن نقل الجوهر لـ`App\Services\Reprice` ماغيّرش
 * ولا رقم ولا رسالة ولا ترتيب حارس. لو واحد منهم وقع بعد تعديل في
 * الخدمات، الخدمة هي الغلط مش التيست.
 */
class SingleRepriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Client $client;

    private Product $product;

    private PriceList $list;

    /** عميل على قايمة مسمّاة سعر الصنف فيها 10، وضريبة 14% */
    private function scene(string $payment = 'credit'): void
    {
        $this->enableTax(14);

        $this->admin = $this->makeAdmin();
        $this->rep = $this->makeRep();
        $this->list = PriceList::create([
            'code' => 'la', 'name' => 'قايمة أ', 'name_en' => 'List A',
            'active' => true, 'is_default' => false,
        ]);
        $this->product = $this->makeProduct(['cost' => 5]);
        PriceListItem::create(['price_list_id' => $this->list->id, 'product_id' => $this->product->id, 'price' => 10]);

        $this->client = $this->makeClient([
            'price_list_id' => $this->list->id,
            'taxable' => true,
            'payment_terms' => $payment,
        ]);
    }

    /** فاتورة 3 قطع بسعر 8 (القديم) + قيودها */
    private function invoice(string $payment = 'credit', array $attrs = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'number' => 'INV-'.random_int(10000, 99999),
            'client_id' => $this->client->id,
            'user_id' => $this->rep->id,
            'payment' => $payment,
            'price_list' => 'la',
            'subtotal' => 24,
            'discount_pct' => 0,
            'discount' => 0,
            'total' => 24,
            'tax_total' => 3.36,
            'grand_total' => 27.36,
            'eta_status' => 'ready',
            'cost_total' => 15,
        ], $attrs));

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'qty' => 3,
            'list_price' => 8,
            'price' => 8,
            'unit_cost' => 5,
            'total' => 24,
            'tax_rate' => 0.14,
            'tax' => 3.36,
        ]);

        Transaction::create([
            'client_id' => $this->client->id, 'date' => today(), 'memo' => 'بيع',
            'debit' => 27.36, 'credit' => 0, 'tax' => 3.36, 'kind' => 'sale',
            'source_type' => Invoice::class, 'source_id' => $invoice->id,
        ]);

        if ($payment === 'cash') {
            Transaction::create([
                'client_id' => $this->client->id, 'date' => today(), 'memo' => 'تحصيل',
                'debit' => 0, 'credit' => 27.36, 'tax' => 3.36, 'kind' => 'collection',
                'source_type' => Invoice::class, 'source_id' => $invoice->id,
            ]);
        }

        $this->client->recalculate();

        return $invoice;
    }

    private function setListPrice(float $price): void
    {
        PriceListItem::where('price_list_id', $this->list->id)
            ->where('product_id', $this->product->id)
            ->update(['price' => $price]);
        PriceList::flushCache();
    }

    private function assertBalanceMatchesLedger(): void
    {
        $c = $this->client->fresh();
        $rows = Transaction::where('client_id', $c->id)->get();

        $this->assertEqualsWithDelta(
            (float) $rows->sum('debit') - (float) $rows->sum('credit'),
            (float) $c->balance,
            0.001,
            'الرصيد لازم يساوي Σمدين − Σدائن بالظبط',
        );
    }

    private function reprice(Invoice $invoice)
    {
        return $this->actingAs($this->admin)
            ->from(route('ops.invoice', $invoice))
            ->post(route('ops.invoices.reprice', $invoice));
    }

    // ═══════════════ الفاتورة ═══════════════

    public function test_credit_invoice_reprice_updates_items_totals_and_sale_entry(): void
    {
        $this->scene();
        $invoice = $this->invoice();
        $this->setListPrice(12);

        $this->reprice($invoice)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ok', __('ops.repriced', [
                'number' => $invoice->number, 'old' => '27.36', 'new' => '41.04',
            ]));

        $invoice->refresh();
        $item = $invoice->items()->first();

        $this->assertEqualsWithDelta(12.0, (float) $item->price, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $item->list_price, 0.001);
        $this->assertEqualsWithDelta(36.0, (float) $item->total, 0.001);
        $this->assertEqualsWithDelta(5.04, (float) $item->tax, 0.001);
        // ⚠️ التكلفة لقطة يوم البيع
        $this->assertEqualsWithDelta(5.0, (float) $item->unit_cost, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $invoice->cost_total, 0.001);

        $this->assertEqualsWithDelta(36.0, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(36.0, (float) $invoice->total, 0.001);
        $this->assertEqualsWithDelta(5.04, (float) $invoice->tax_total, 0.001);
        $this->assertEqualsWithDelta(41.04, (float) $invoice->grand_total, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->discount, 0.001);
        $this->assertSame('ready', $invoice->eta_status);
        $this->assertSame('la', $invoice->price_list);

        $sale = Transaction::where('source_type', Invoice::class)->where('source_id', $invoice->id)->where('kind', 'sale')->first();
        $this->assertEqualsWithDelta(41.04, (float) $sale->debit, 0.001);
        $this->assertEqualsWithDelta(5.04, (float) $sale->tax, 0.001);

        $this->assertEqualsWithDelta(41.04, (float) $this->client->fresh()->balance, 0.001);
        $this->assertBalanceMatchesLedger();
    }

    public function test_reprice_applies_client_discount_on_lines_and_header(): void
    {
        $this->scene();
        $this->client->update(['discount' => 0.10]);
        $invoice = $this->invoice();
        $this->setListPrice(12);

        $this->reprice($invoice)->assertSessionHasNoErrors();

        $invoice->refresh();
        $item = $invoice->items()->first();

        // 12 × 0.9 = 10.80 × 3 = 32.40 · ضريبة 4.54 (4.536)
        $this->assertEqualsWithDelta(12.0, (float) $item->list_price, 0.001);
        $this->assertEqualsWithDelta(10.80, (float) $item->price, 0.001);
        $this->assertEqualsWithDelta(32.40, (float) $item->total, 0.001);
        $this->assertEqualsWithDelta(36.0, (float) $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(3.60, (float) $invoice->discount, 0.001);
        $this->assertEqualsWithDelta(0.10, (float) $invoice->discount_pct, 0.0001);
        $this->assertSame('custom_discount', $invoice->discount_source);
        $this->assertEqualsWithDelta(32.40, (float) $invoice->total, 0.001);
        $this->assertEqualsWithDelta(
            (float) $invoice->total + (float) $invoice->tax_total,
            (float) $invoice->grand_total,
            0.001,
        );
        $this->assertBalanceMatchesLedger();
    }

    public function test_cash_invoice_single_reprice_moves_collection(): void
    {
        $this->scene('cash');
        $invoice = $this->invoice('cash');
        $this->setListPrice(12);

        $this->reprice($invoice)->assertSessionHasNoErrors();

        $collection = Transaction::where('source_type', Invoice::class)->where('source_id', $invoice->id)->where('kind', 'collection')->first();
        $this->assertEqualsWithDelta(41.04, (float) $collection->credit, 0.001);
        $this->assertEqualsWithDelta(5.04, (float) $collection->tax, 0.001);

        $this->assertEqualsWithDelta(0.0, (float) $this->client->fresh()->balance, 0.001);
        $this->assertBalanceMatchesLedger();
    }

    public function test_reprice_refused_for_eta_exported_consignment_and_return(): void
    {
        $this->scene();
        $this->setListPrice(12);

        // ١. متصدّرة للضرائب
        $eta = $this->invoice('credit', ['eta_status' => 'exported']);
        $this->reprice($eta)->assertSessionHasErrors(['reprice' => __('ops.reprice_eta_locked')]);

        // ٢. أمانة
        $cons = $this->invoice();
        Transaction::create([
            'client_id' => $this->client->id, 'date' => today(), 'memo' => 'أمانة',
            'debit' => 0, 'credit' => 0, 'kind' => 'consignment',
            'source_type' => Invoice::class, 'source_id' => $cons->id,
        ]);
        $this->reprice($cons)->assertSessionHasErrors(['reprice' => __('ops.reprice_consignment_blocked')]);

        // ٣. عليها مرتجع مربوط
        $ret = $this->invoice();
        $doc = ClientReturn::create([
            'number' => 'RET-'.random_int(10000, 99999),
            'client_id' => $this->client->id, 'user_id' => $this->rep->id,
            'source' => 'office', 'policy' => 'credit',
            'subtotal' => 8, 'discount' => 0, 'total' => 8, 'tax_total' => 0, 'grand_total' => 8,
        ]);
        ReturnItem::create([
            'return_id' => $doc->id, 'product_id' => $this->product->id,
            'invoice_id' => $ret->id, 'qty' => 1, 'condition' => 'good',
            'list_price' => 8, 'price' => 8, 'total' => 8, 'tax_rate' => 0, 'tax' => 0,
        ]);
        $this->reprice($ret)->assertSessionHasErrors(['reprice' => __('ops.reprice_has_return')]);

        foreach ([$eta, $cons, $ret] as $inv) {
            $fresh = $inv->fresh();
            $this->assertEqualsWithDelta(27.36, (float) $fresh->grand_total, 0.001);
            $this->assertEqualsWithDelta(8.0, (float) $fresh->items()->first()->price, 0.001);
            $sale = Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'sale')->first();
            $this->assertEqualsWithDelta(27.36, (float) $sale->debit, 0.001);
        }
    }

    public function test_guard_order_eta_then_closed_period_then_consignment(): void
    {
        $this->scene();
        $this->setListPrice(12);
        GlPeriod::close(now()->format('Y-m'), $this->admin);

        // متصدّرة + شهر مقفول → رسالة الضرايب (الحارس الأول) من غير period
        $eta = $this->invoice('credit', ['eta_status' => 'exported']);
        $this->reprice($eta)
            ->assertSessionHasErrors(['reprice' => __('ops.reprice_eta_locked')])
            ->assertSessionDoesntHaveErrors('period');

        // أمانة + شهر مقفول → الشهر المقفول يسبق الأمانة
        $cons = $this->invoice();
        Transaction::create([
            'client_id' => $this->client->id, 'date' => today(), 'memo' => 'أمانة',
            'debit' => 0, 'credit' => 0, 'kind' => 'consignment',
            'source_type' => Invoice::class, 'source_id' => $cons->id,
        ]);
        $this->reprice($cons)->assertSessionHasErrors('period');

        $this->assertEqualsWithDelta(27.36, (float) $cons->fresh()->grand_total, 0.001);
    }

    public function test_reprice_refused_when_item_not_priced_and_nothing_written(): void
    {
        $this->scene();
        $invoice = $this->invoice();
        PriceListItem::where('price_list_id', $this->list->id)->delete();
        PriceList::flushCache();

        $this->reprice($invoice)->assertSessionHasErrors(['reprice' => __('ops.reprice_not_priced', [
            'product' => $this->product->displayName(),
        ])]);

        $this->assertEqualsWithDelta(27.36, (float) $invoice->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $invoice->items()->first()->price, 0.001);
    }

    public function test_plan_reports_unchanged_when_prices_equal(): void
    {
        $this->scene();
        $invoice = $this->invoice();
        $this->setListPrice(12);

        $before = InvoiceRepricer::plan(Invoice::find($invoice->id));
        $this->assertTrue($before['changed']);
        $this->assertNull($before['not_priced']);
        $this->assertEqualsWithDelta(27.36, $before['before']['grand'], 0.001);
        $this->assertEqualsWithDelta(41.04, $before['after']['grand'], 0.001);
        // ⚠️ plan قراءة بس
        $this->assertEqualsWithDelta(27.36, (float) $invoice->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $invoice->items()->first()->price, 0.001);

        $this->reprice($invoice)->assertSessionHasNoErrors();

        $after = InvoiceRepricer::plan(Invoice::find($invoice->id));
        $this->assertFalse($after['changed']);
        $this->assertNull(InvoiceRepricer::blocker(Invoice::find($invoice->id)));
    }

    // ═══════════════ أمر التوريد ═══════════════

    private function po(string $status = 'pending', array $attrs = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'number' => 'PO-'.random_int(10000, 99999),
            'client_id' => $this->client->id,
            'status' => $status,
            'price_mode' => 'la',
            'total' => 24,
            'tax_total' => 3.36,
            'grand_total' => 27.36,
        ], $attrs));

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'qty' => 3,
            'price' => 8,
            'list_price' => 8,
            'discount_pct' => 0,
            'total' => 24,
            'tax_rate' => 0.14,
            'tax' => 3.36,
        ]);

        return $po;
    }

    public function test_single_po_reprice_pending_and_refused_when_delivered(): void
    {
        $this->scene();
        $this->setListPrice(12);

        $pending = $this->po();
        $this->actingAs($this->admin)->from('/')
            ->post(route('ops.pos.reprice', $pending))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ok', __('ops.po_repriced', [
                'number' => $pending->number, 'old' => '27.36', 'new' => '41.04',
            ]));

        $pending->refresh();
        $this->assertEqualsWithDelta(36.0, (float) $pending->total, 0.001);
        $this->assertEqualsWithDelta(5.04, (float) $pending->tax_total, 0.001);
        $this->assertEqualsWithDelta(41.04, (float) $pending->grand_total, 0.001);
        $this->assertTrue((bool) $pending->was_edited);
        $this->assertSame($this->admin->id, (int) $pending->edited_by);
        $this->assertSame('la', $pending->price_mode);
        // ⚠️ إعادة التسعير ماترجّعش الأمر لأي طابور
        $this->assertSame('pending', $pending->status);
        $line = $pending->items()->first();
        $this->assertSame(3, (int) $line->qty);
        $this->assertEqualsWithDelta(12.0, (float) $line->price, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $line->list_price, 0.001);

        foreach (['delivered', 'cancelled'] as $status) {
            $locked = $this->po($status);
            $this->actingAs($this->admin)->from('/')
                ->post(route('ops.pos.reprice', $locked))
                ->assertSessionHasErrors(['reprice' => __('ops.po_reprice_locked')]);

            $this->assertEqualsWithDelta(27.36, (float) $locked->fresh()->grand_total, 0.001);
            $this->assertEqualsWithDelta(8.0, (float) $locked->items()->first()->price, 0.001);
        }
    }

    public function test_po_reprice_refused_when_not_priced(): void
    {
        $this->scene();
        $po = $this->po();
        PriceListItem::where('price_list_id', $this->list->id)->delete();
        PriceList::flushCache();

        $this->actingAs($this->admin)->from('/')
            ->post(route('ops.pos.reprice', $po))
            ->assertSessionHasErrors(['reprice' => __('stock.po_not_priced', ['name' => $this->product->displayName()])]);

        $this->assertEqualsWithDelta(27.36, (float) $po->fresh()->grand_total, 0.001);
        $this->assertSame(1, $po->items()->count());
    }

    public function test_po_plan_reports_unchanged_when_prices_equal(): void
    {
        $this->scene();
        $po = $this->po();
        $this->setListPrice(12);

        $plan = PoRepricer::plan(PurchaseOrder::find($po->id));
        $this->assertTrue($plan['changed']);
        $this->assertEqualsWithDelta(41.04, $plan['after']['grand'], 0.001);
        $this->assertEqualsWithDelta(27.36, (float) $po->fresh()->grand_total, 0.001);

        $this->actingAs($this->admin)->from('/')->post(route('ops.pos.reprice', $po))->assertSessionHasNoErrors();

        $this->assertFalse(PoRepricer::plan(PurchaseOrder::find($po->id))['changed']);
        $this->assertNull(PoRepricer::blocker(PurchaseOrder::find($po->id)));
    }

    public function test_po_blockers(): void
    {
        $this->scene();

        $this->assertSame('po_cancelled', PoRepricer::blocker($this->po('cancelled'))['key']);
        $this->assertSame('po_delivered', PoRepricer::blocker($this->po('delivered'))['key']);
        $this->assertSame('po_fixed_price', PoRepricer::blocker($this->po('pending', ['price_mode' => 'old']))['key']);
        // العمود NOT NULL — الحالة دي بتيجي من صف قديم/عميل اتمسح، فبنحاكيها في الذاكرة
        $orphan = $this->po();
        $orphan->client_id = null;
        $orphan->unsetRelation('client');
        $this->assertSame('no_client', PoRepricer::blocker($orphan)['key']);
        $this->assertNull(PoRepricer::blocker($this->po()));
    }
}
