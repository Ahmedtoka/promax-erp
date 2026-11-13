<?php

namespace Tests\Feature\Review;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\DocReviewEvent;
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
use App\Services\Reprice\FullResync;
use App\Services\Review\DocQueue;
use App\Services\Review\DocReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * (١٣/١١ — ملاحظات سهيلة على اللايف) أدوات حل المشاكل من لوحة المراجعة:
 * المسلَّم في أمر اتسلّم، إلغاء المكرر، سعر بند المرتجع، وإلغاء أمر ماتسلّمش.
 */
class DocReviewResolveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $accountant;

    private User $rep;

    private Client $client;

    private Product $product;

    private Product $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->enableTax(14);
        $this->admin = $this->makeAdmin();
        $this->accountant = $this->makeAdmin(['role' => 'accountant']);
        $this->rep = $this->makeRep();
        $list = PriceList::create(['code' => 'la', 'name' => 'قايمة أ', 'name_en' => 'List A', 'active' => true, 'is_default' => false]);
        $this->product = $this->makeProduct(['cost' => 5]);
        $this->other = $this->makeProduct(['cost' => 5]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->product->id, 'price' => 10]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->other->id, 'price' => 20]);
        $this->client = $this->makeClient(['name' => 'العبور', 'price_list_id' => $list->id, 'taxable' => true, 'payment_terms' => 'credit']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** أمر اتسلّم بالظبط زي PO-2016: المطلوب ٣٦ + ٦، المسلَّم ٢٤ + ٦ — والقيد على المسلَّم */
    private function deliveredPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create(['number' => 'PO-'.random_int(10000, 99999), 'paper_ref' => '777', 'client_id' => $this->client->id, 'status' => 'delivered',
            'approval_status' => 'approved', 'assigned_to' => $this->rep->id, 'price_mode' => 'la', 'total' => 480, 'tax_total' => 67.2, 'grand_total' => 547.2]);
        $po->forceFill(['delivered_at' => Carbon::parse('2026-08-11 10:00')])->save();
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->product->id, 'qty' => 36, 'delivered_qty' => 24,
            'price' => 10, 'list_price' => 10, 'discount_pct' => 0, 'total' => 360, 'tax_rate' => 0.14, 'tax' => 50.4]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->other->id, 'qty' => 6, 'delivered_qty' => 6,
            'price' => 20, 'list_price' => 20, 'discount_pct' => 0, 'total' => 120, 'tax_rate' => 0.14, 'tax' => 16.8]);
        // ٢٤×١٠ + ٦×٢٠ = ٣٦٠ صافي + ٥٠٫٤ = ٤١٠٫٤
        Transaction::create(['client_id' => $this->client->id, 'date' => '2026-08-11', 'memo' => 'توريد', 'debit' => 410.4, 'credit' => 0, 'tax' => 50.4,
            'kind' => 'sale', 'source_type' => PurchaseOrder::class, 'source_id' => $po->id]);
        $this->client->recalculate();

        return $po->fresh('items');
    }

    private function invoice(string $created = '2026-08-18 12:00', ?Client $client = null): Invoice
    {
        $client ??= $this->client;
        $inv = Invoice::create(['number' => 'INV-'.random_int(10000, 99999), 'paper_ref' => (string) random_int(100, 9999), 'client_id' => $client->id,
            'user_id' => $this->rep->id, 'payment' => 'credit', 'price_list' => 'la', 'subtotal' => 30, 'discount_pct' => 0, 'discount' => 0,
            'total' => 30, 'tax_total' => 4.2, 'grand_total' => 34.2, 'eta_status' => 'ready', 'cost_total' => 15]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $this->product->id, 'qty' => 3, 'list_price' => 10, 'price' => 10,
            'unit_cost' => 5, 'total' => 30, 'tax_rate' => 0.14, 'tax' => 4.2]);
        Transaction::create(['client_id' => $client->id, 'date' => substr($created, 0, 10), 'memo' => 'بيع', 'debit' => 34.2, 'credit' => 0, 'tax' => 4.2,
            'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        $inv->forceFill(['created_at' => Carbon::parse($created)])->save();
        $client->recalculate();

        return $inv->fresh();
    }

    private function ret(float $price = 10, int $qty = 2): ClientReturn
    {
        $calc = \App\Services\Tax::line($price, $qty, 0.14);
        $ret = ClientReturn::create(['number' => ClientReturn::nextNumber(), 'client_id' => $this->client->id, 'user_id' => $this->rep->id,
            'source' => 'erp', 'policy' => 'account', 'subtotal' => $calc['net'], 'discount' => 0, 'total' => $calc['net'], 'tax_total' => $calc['tax'],
            'grand_total' => round($calc['net'] + $calc['tax'], 2), 'good_units' => $qty, 'damaged_units' => 0]);
        $ret->forceFill(['created_at' => Carbon::parse('2026-08-19 12:00')])->save();
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $this->product->id, 'qty' => $qty, 'condition' => 'good',
            'list_price' => $price, 'price' => $price, 'total' => $calc['net'], 'tax_rate' => 0.14, 'tax' => $calc['tax']]);
        Transaction::create(['client_id' => $this->client->id, 'date' => '2026-08-19', 'memo' => 'مرتجع', 'debit' => 0, 'credit' => round($calc['net'] + $calc['tax'], 2),
            'tax' => $calc['tax'], 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);
        $this->client->recalculate();

        return $ret->fresh('items');
    }

    private function balance(): float
    {
        return (float) $this->client->fresh()->balance;
    }

    // ═══ B) المسلَّم ═══

    public function test_delivered_po_shows_the_delivered_value_everywhere_not_the_ordered(): void
    {
        $po = $this->deliveredPo();
        $row = DocQueue::all($this->accountant, 'po-'.$po->id)->first();
        $this->assertEqualsWithDelta(410.4, $row['grand'], 0.001);
        $this->assertEqualsWithDelta(547.2, $row['ordered_grand'], 0.001);
        $this->assertEqualsWithDelta(410.4, DocQueue::moneyByClient([$this->client->id])[$this->client->id]['grand'], 0.001);

        $this->actingAs($this->accountant)->get(route('erp.review.docs.pane', ['type' => 'po', 'id' => $po->id]))->assertOk()
            ->assertSee('410.40')->assertSee(__('review.dlv_vs_ordered', ['ordered' => '547.20']))->assertSee(__('review.dlv_btn'));
    }

    public function test_editing_delivered_qty_moves_the_sale_entry_and_keeps_the_ordered_qty(): void
    {
        $po = $this->deliveredPo();
        $coffee = $po->items->firstWhere('product_id', $this->other->id);

        // «صنف القهوة لم يسلم والكمية ٠ ومحسوب» — نصفّره
        $res = $this->actingAs($this->accountant)->postJson(route('erp.review.docs.delivered', ['type' => 'po', 'id' => $po->id]),
            ['dq' => [$coffee->id => 0], 'note' => 'الورقة'])->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('273.60', $res->json('message'));

        $coffee->refresh();
        $this->assertSame(6, (int) $coffee->qty);
        $this->assertSame(0, (int) $coffee->delivered_qty);
        $sale = Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->where('kind', 'sale')->first();
        $this->assertEqualsWithDelta(273.6, (float) $sale->debit, 0.001);   // ٢٤×١٠×١٫١٤
        $this->assertEqualsWithDelta(273.6, $this->balance(), 0.001);
        $ev = DocReviewEvent::where(['doc_type' => 'po', 'doc_id' => $po->id, 'action' => 'delivered'])->firstOrFail();
        $this->assertEqualsWithDelta(-136.8, (float) $ev->grand_delta, 0.001);

        // أكتر من المطلوب / كله صفر = مرفوض
        $this->expectException(Rejected::class);
        DocReview::saveDelivered($this->accountant, $po->fresh(), [$coffee->id => 7]);
    }

    public function test_all_zero_delivered_is_refused(): void
    {
        $po = $this->deliveredPo();
        $this->expectException(Rejected::class);
        DocReview::saveDelivered($this->accountant, $po, $po->items->mapWithKeys(fn ($i) => [$i->id => 0])->all());
    }

    // ═══ A) المكرر ═══

    public function test_admin_voids_a_duplicate_invoice_with_full_reversal_and_link_to_the_original(): void
    {
        $orig = $this->invoice();
        $dup = $this->invoice('2026-08-18 15:06');
        $this->assertEqualsWithDelta(68.4, $this->balance(), 0.001);

        // المحاسب مايقدرش — أدمن بس
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.void', ['type' => 'invoice', 'id' => $dup->id]), ['original' => $orig->number])->assertForbidden();

        $this->actingAs($this->admin)->postJson(route('erp.review.docs.void', ['type' => 'invoice', 'id' => $dup->id]), ['original' => $orig->number, 'note' => 'نفس الورقة'])
            ->assertOk()->assertJson(['ok' => true, 'goto' => 'inv-'.$orig->id, 'removed' => 'inv-'.$dup->id]);

        $this->assertNull(Invoice::find($dup->id));
        $this->assertSame(0, Transaction::where('source_type', Invoice::class)->where('source_id', $dup->id)->count());
        $this->assertSame(0, InvoiceItem::where('invoice_id', $dup->id)->count());
        $this->assertEqualsWithDelta(34.2, $this->balance(), 0.001);
        $void = DocReviewEvent::where(['doc_type' => 'invoice', 'doc_id' => $dup->id, 'action' => 'void_dup'])->firstOrFail();
        $this->assertSame($orig->number, $void->after['original']);
        $this->assertFalse($void->after['custody_reversed']);   // أغسطس — قبل تصفير ٧/١١
        $this->assertCount(1, $void->before['ledger']);
        $this->assertTrue(DocReviewEvent::where(['doc_type' => 'invoice', 'doc_id' => $orig->id, 'action' => 'dup_voided'])->exists());
    }

    public function test_void_refuses_another_clients_original_and_the_same_document(): void
    {
        $other = $this->makeClient(['name' => 'تاني']);
        $orig = $this->invoice('2026-08-18 12:00', $other);
        $dup = $this->invoice();
        foreach ([$orig->number, $dup->number, 'INV-NOPE'] as $n) {
            try {
                DocReview::voidDuplicate($this->admin, $dup, $n);
                $this->fail('accepted '.$n);
            } catch (Rejected) {
            }
        }
        $this->assertNotNull(Invoice::find($dup->id));
    }

    public function test_admin_voids_a_duplicate_return_and_its_credit(): void
    {
        $orig = $this->ret();
        $dup = $this->ret();
        $this->assertEqualsWithDelta(-45.6, $this->balance(), 0.001);
        DocReview::voidDuplicate($this->admin, $dup, $orig->number);
        $this->assertNull(ClientReturn::find($dup->id));
        $this->assertSame(0, Transaction::where('source_type', ClientReturn::class)->where('source_id', $dup->id)->count());
        $this->assertEqualsWithDelta(-22.8, $this->balance(), 0.001);
    }

    // ═══ D) سعر بند المرتجع ═══

    public function test_manual_return_price_with_reason_moves_the_credit_and_survives_a_resync(): void
    {
        $ret = $this->ret(10, 2);
        $line = $ret->items->first();

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.retprice', ['type' => 'return', 'id' => $ret->id]),
            ['item_id' => $line->id, 'mode' => 'manual', 'price' => 8])->assertStatus(422);   // السبب إجباري

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.retprice', ['type' => 'return', 'id' => $ret->id]),
            ['item_id' => $line->id, 'mode' => 'manual', 'price' => 8, 'reason' => 'سعر الورقة'])->assertOk()->assertJson(['ok' => true]);

        $line->refresh();
        $this->assertEqualsWithDelta(8, (float) $line->price, 0.0001);
        $this->assertSame('سعر الورقة', $line->manual_reason);
        $this->assertEqualsWithDelta(18.24, (float) $ret->fresh()->grand_total, 0.001);   // ٢×٨×١٫١٤
        $credit = Transaction::where('source_type', ClientReturn::class)->where('source_id', $ret->id)->where('kind', 'return')->first();
        $this->assertEqualsWithDelta(18.24, (float) $credit->credit, 0.001);
        $this->assertEqualsWithDelta(-18.24, $this->balance(), 0.001);

        // إعادة تسعير عامة بعد كده ماتدوسش على اليدوي
        FullResync::clientReturn($ret->fresh());
        $this->assertEqualsWithDelta(8, (float) $line->fresh()->price, 0.0001);

        // ورجوع للقايمة
        DocReview::setReturnPrice($this->accountant, $ret->fresh(), $line->id, 'list');
        $this->assertEqualsWithDelta(10, (float) $line->fresh()->price, 0.0001);
        $this->assertNull($line->fresh()->manual_price);
    }

    public function test_return_line_takes_the_price_of_a_linked_client_invoice_and_respects_what_was_sold(): void
    {
        $inv = $this->invoice('2026-08-10 12:00');
        $src = $inv->items->first();
        $src->forceFill(['price' => 9.5, 'list_price' => 10])->save();
        $ret = $this->ret(10, 2);
        $line = $ret->items->first();

        $pane = $this->actingAs($this->accountant)->get(route('erp.review.docs.pane', ['type' => 'return', 'id' => $ret->id]))->assertOk();
        $pane->assertSee(__('review.retp_btn'))->assertSee($inv->number);

        DocReview::setReturnPrice($this->accountant, $ret, $line->id, 'invoice', $src->id);
        $line->refresh();
        $this->assertSame($src->id, (int) $line->invoice_item_id);
        $this->assertEqualsWithDelta(9.5, (float) $line->price, 0.0001);

        // فاتورة عميل تاني مرفوضة، وأكتر من المباع مرفوض
        $foreign = $this->invoice('2026-08-10 12:00', $this->makeClient(['name' => 'غريب']));
        $ret2 = $this->ret(10, 2);
        foreach ([[$ret2, $foreign->items->first()->id], [$ret2, $src->id]] as [$r, $iid]) {
            try {
                DocReview::setReturnPrice($this->accountant, $r, $r->items->first()->id, 'invoice', $iid);
                $this->fail('accepted invoice line '.$iid);
            } catch (Rejected) {
            }
        }
    }

    // ═══ C) أمر ماتسلّمش ═══

    public function test_undelivered_po_pane_offers_cancel_to_admin_and_a_hint_to_the_accountant(): void
    {
        $po = PurchaseOrder::create(['number' => 'PO-'.random_int(10000, 99999), 'client_id' => $this->client->id, 'status' => 'pending',
            'approval_status' => 'approved', 'assigned_to' => $this->rep->id, 'price_mode' => 'la', 'total' => 120, 'tax_total' => 16.8, 'grand_total' => 136.8]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->product->id, 'qty' => 12, 'delivered_qty' => 0,
            'price' => 10, 'list_price' => 10, 'discount_pct' => 0, 'total' => 120, 'tax_rate' => 0.14, 'tax' => 16.8]);
        $url = route('erp.review.docs.pane', ['type' => 'po', 'id' => $po->id]);

        // (١٣/١١ — أحمد) مش مستند بيع: بره الطابور والإجماليات والنسبة — إلا شيبة «لسه ماتسلّمش»
        $delivered = $this->deliveredPo();
        $all = DocQueue::all($this->accountant);
        $this->assertSame([$delivered->id], DocQueue::filter($all, [])->where('type', 'po')->pluck('id')->all());
        $this->assertSame([$po->id], DocQueue::filter($all, ['flag' => DocQueue::UNDELIVERED])->pluck('id')->all());
        $this->assertSame(1, DocQueue::overall($this->accountant)['pos']);
        $this->assertSame(1, DocQueue::moneyByClient([$this->client->id])[$this->client->id]['docs']);
        $page = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['status' => 'all']))->assertOk();
        $this->assertSame(1, $page->viewData('flagCounts')['undelivered']);
        $this->assertSame(1, $page->viewData('scoped')['pos']);
        $this->assertSame([$po->id], $this->actingAs($this->accountant)->get(route('erp.review.docs', ['status' => 'all', 'flag' => 'undelivered']))
            ->viewData('groups')[0]['rows']->pluck('id')->all());

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee(route('ops.pos.cancel', $po), false)->assertSee(__('review.poc_btn'));
        $this->actingAs($this->accountant)->get($url)->assertOk()->assertDontSee(route('ops.pos.cancel', $po), false)->assertSee(__('review.poc_ask_admin'));

        $this->actingAs($this->admin)->post(route('ops.pos.cancel', $po), ['reason' => 'رفض الفرع الاستلام', 'mode' => 'custody'])->assertRedirect();
        $this->assertSame('cancelled', $po->fresh()->status);
        $this->assertNull(DocQueue::all($this->accountant, 'po-'.$po->id)->first());
    }
}
