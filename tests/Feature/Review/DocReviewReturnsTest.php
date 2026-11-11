<?php

namespace Tests\Feature\Review;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\DocReviewEvent;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\ReviewCheck;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Review\DocQueue;
use App\Services\Review\DocReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** (١٢/١١ — أحمد) المرتجعات نوع تالت في المراجعة مستند مستند */
class DocReviewReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $rep;

    private Client $client;

    private Product $product;

    private Product $product2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');

        $this->enableTax(14);
        $this->accountant = $this->makeAdmin(['role' => 'accountant']);
        $this->rep = $this->makeRep();
        $list = PriceList::create(['code' => 'la', 'name' => 'قايمة أ', 'name_en' => 'List A', 'active' => true, 'is_default' => false]);
        $this->product = $this->makeProduct(['cost' => 5]);
        $this->product2 = $this->makeProduct(['cost' => 3]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->product->id, 'price' => 10]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->product2->id, 'price' => 20]);
        $this->client = $this->makeClient(['price_list_id' => $list->id, 'taxable' => true, 'payment_terms' => 'credit']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** فاتورة ٥ قطع بسعر ٩ (أقل من القايمة ١٠ — سعر يوم البيع) */
    private function invoice(): Invoice
    {
        $inv = Invoice::create([
            'number' => 'INV-'.random_int(10000, 99999), 'paper_ref' => '500',
            'client_id' => $this->client->id, 'user_id' => $this->rep->id, 'payment' => 'credit', 'price_list' => 'la',
            'subtotal' => 50, 'discount_pct' => 0, 'discount' => 5, 'total' => 45, 'tax_total' => 6.3, 'grand_total' => 51.3,
            'eta_status' => 'ready', 'cost_total' => 25,
        ]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $this->product->id, 'qty' => 5, 'list_price' => 10, 'price' => 9,
            'unit_cost' => 5, 'total' => 45, 'tax_rate' => 0.14, 'tax' => 6.3]);
        Transaction::create(['client_id' => $this->client->id, 'date' => '2026-10-20', 'memo' => 'بيع', 'debit' => 51.3, 'credit' => 0,
            'tax' => 6.3, 'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        $inv->forceFill(['created_at' => Carbon::parse('2026-10-20 12:00')])->save();

        return $inv->fresh();
    }

    /** مرتجع قطعتين من الفاتورة — بسعر `$price` (٩ = صح، غيره = غلط) */
    private function returnDoc(Invoice $inv, float $price = 9, string $policy = 'account'): ClientReturn
    {
        $line = $inv->items()->first();
        $net = round(2 * $price, 2);
        $tax = round($net * 0.14, 2);
        $ret = ClientReturn::create([
            'number' => ClientReturn::nextNumber(), 'client_id' => $this->client->id, 'user_id' => $this->rep->id,
            'source' => 'erp', 'policy' => $policy, 'subtotal' => 20, 'discount' => round(20 - $net, 2), 'total' => $net,
            'tax_total' => $tax, 'grand_total' => round($net + $tax, 2), 'good_units' => 2, 'damaged_units' => 0,
        ]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $this->product->id, 'invoice_id' => $inv->id, 'invoice_item_id' => $line->id,
            'qty' => 2, 'condition' => 'good', 'list_price' => 10, 'price' => $price, 'total' => $net, 'tax_rate' => 0.14, 'tax' => $tax]);
        $credit = Transaction::create(['client_id' => $this->client->id, 'date' => '2026-10-25', 'memo' => 'مرتجع', 'debit' => 0, 'credit' => round($net + $tax, 2),
            'tax' => $tax, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);
        $refund = $policy === 'cash' ? Transaction::create(['client_id' => $this->client->id, 'date' => '2026-10-25', 'memo' => 'رد', 'debit' => round($net + $tax, 2),
            'credit' => 0, 'tax' => $tax, 'kind' => 'refund', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]) : null;
        $ret->forceFill(['transaction_id' => $credit->id, 'refund_transaction_id' => $refund?->id, 'created_at' => Carbon::parse('2026-10-25 11:00')])->save();
        $this->client->recalculate();

        return $ret->fresh();
    }

    private function url(string $r, ClientReturn $ret): string
    {
        return route('erp.review.docs.'.$r, ['type' => 'return', 'id' => $ret->id]);
    }

    private function assertBalanceMatchesLedger(): void
    {
        $c = $this->client->fresh();
        $rows = Transaction::where('client_id', $c->id)->get();
        $this->assertEqualsWithDelta((float) $rows->sum('debit') - (float) $rows->sum('credit'), (float) $c->balance, 0.001);
    }

    public function test_returns_join_the_queue_linked_to_their_invoice_with_serial_and_signature(): void
    {
        $inv = $this->invoice();
        $ret = $this->returnDoc($inv);

        $row = DocQueue::all($this->accountant, 'ret-'.$ret->id)->first();
        $this->assertSame('return', $row['type']);
        $this->assertSame([['id' => $inv->id, 'number' => $inv->number]], $row['links']);
        $this->assertEqualsWithDelta(20.52, $row['grand'], 0.001);
        $this->assertContains('noserial', $row['flags']);
        $this->assertContains('noproof', $row['flags']);
        $this->assertNotContains('ret_price', $row['flags']);   // نفس سعر البيع

        // شيب النوع + البحث برقم الفاتورة الأصلية
        $page = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['type' => 'return', 'status' => 'all']))->assertOk()
            ->assertSee(__('review.type_returns'));
        $this->assertSame(1, $page->viewData('counts')['all']);
        $this->assertSame([$ret->id], DocQueue::filter(DocQueue::all($this->accountant), ['q' => $inv->number, 'type' => 'return'])->pluck('id')->all());

        // اللوحة: الفاتورة الأصلية، رقم الإذن، إمضاء العميل، سعر البيع
        $this->actingAs($this->accountant)->get($this->url('pane', $ret))->assertOk()
            ->assertSee(__('review.ret_orig'))->assertSee('data-goto="inv-'.$inv->id.'"', false)
            ->assertSee(__('review.ret_signed'))->assertSee(__('review.col_sale_price'))->assertSee('class="bad" data-chk="proof"', false);

        // ✓ من غير رقم إذن = ممنوع
        $this->actingAs($this->accountant)->postJson($this->url('mark', $ret), ['status' => 'ok'])->assertStatus(422)
            ->assertJsonPath('message', __('review.serial_required'));

        // رقم الإذن: دفتر لوحده — نفس رقم الفاتورة «500» مش مكرر
        $this->actingAs($this->accountant)->postJson($this->url('serial', $ret), ['paper_ref' => '٥٠٠'])->assertOk()
            ->assertJsonPath('row.paper_ref', '500')->assertJsonPath('row.dup', false)->assertJsonMissingPath('warn');
        $ret2 = $this->returnDoc($inv);
        $this->actingAs($this->accountant)->postJson($this->url('serial', $ret2), ['paper_ref' => '500'])->assertOk()
            ->assertJsonPath('row.dup', true)->assertJsonPath('dup_keys', ['ret-'.$ret->id]);

        // إمضاء العميل (مفتاح 2) — بيتحفظ ويتسجل، والعلامة «إمضاء/ختم»
        $before = ActivityLog::max('id') ?? 0;
        $this->actingAs($this->accountant)->postJson($this->url('proof', $ret), ['receipt_note' => 0, 'paper_signed' => 1])->assertOk()
            ->assertJsonPath('checks.proof.ok', true)->assertJsonPath('row.tags', ['signed']);
        $this->assertTrue($ret->fresh()->paper_signed);
        $log = ActivityLog::where('id', '>', $before)->where('event', 'action')->sole();
        $this->assertSame('ClientReturn', $log->subject_type);
        $this->assertSame([__('review.no'), __('review.yes')], $log->changes[__('review.proof_signed')]);
        $this->assertArrayNotHasKey(__('review.proof_receipt'), $log->changes);

        // ✓ بقى مسموح — والإنجاز بيعدّ المرتجعات
        $this->actingAs($this->accountant)->postJson($this->url('mark', $ret), ['status' => 'ok'])->assertOk()
            ->assertJsonPath('row.state', 'ok')->assertJsonPath('overall.returns', 2)->assertJsonPath('overall.ok_ret', 1);
        $this->assertSame(1, ReviewCheck::where(['entity_type' => 'return', 'entity_id' => $ret->id])->count());
    }

    public function test_line_edit_reprices_from_the_original_sale_and_fixes_credit_and_balance_without_stock(): void
    {
        $inv = $this->invoice();
        $ret = $this->returnDoc($inv, 10);   // اتسعّر غلط بالقايمة (١٠) مش بسعر البيع (٩)
        $this->assertContains('ret_price', DocQueue::all($this->accountant, 'ret-'.$ret->id)->first()['flags']);
        $this->actingAs($this->accountant)->get($this->url('pane', $ret))->assertOk()
            ->assertSee(__('review.chk_ret_prices_off', ['n' => 1]));
        DocReview::setSerial($this->accountant, $ret, 'R1');
        DocReview::mark($this->accountant, $ret->fresh(), 'ok', null);

        // المعاينة بسعر البيع
        $pv = $this->actingAs($this->accountant)->postJson($this->url('preview', $ret), ['qty' => [$this->product->id => 3]])->assertOk();
        $this->assertEqualsWithDelta(27.0, (float) $pv->json('net'), 0.001);
        $this->assertEqualsWithDelta(30.78, (float) $pv->json('grand'), 0.001);

        // ٣ قطع بدل ٢ + صنف جديد (من قايمة العميل ٢٠)
        $this->actingAs($this->accountant)->postJson($this->url('lines', $ret), ['qty' => [$this->product->id => 3, $this->product2->id => 1]])
            ->assertOk()->assertJsonPath('row.state', 'pending');   // تعديل بيرجّع ✓ «لسه»
        $ret->refresh()->load('items');
        $this->assertEqualsWithDelta(47.0, (float) $ret->total, 0.001);        // 27 + 20
        $this->assertEqualsWithDelta(53.58, (float) $ret->grand_total, 0.001);  // 30.78 + 22.8
        $this->assertSame(4, (int) $ret->good_units);
        $this->assertEqualsWithDelta(9.0, (float) $ret->items->firstWhere('product_id', $this->product->id)->price, 0.0001);
        $this->assertNull($ret->items->firstWhere('product_id', $this->product2->id)->invoice_item_id);
        $this->assertEqualsWithDelta(53.58, (float) Transaction::find($ret->transaction_id)->credit, 0.001);
        $this->assertNotContains('ret_price', DocQueue::all($this->accountant, 'ret-'.$ret->id)->first()['flags']);
        // رصيد العميل = مبيعات − مرتجعات
        $this->assertEqualsWithDelta(51.3 - 53.58, (float) $this->client->fresh()->balance, 0.001);
        $this->assertBalanceMatchesLedger();

        $ev = DocReviewEvent::where(['doc_type' => 'return', 'doc_id' => $ret->id, 'action' => 'lines'])->sole();
        $this->assertEqualsWithDelta(53.58 - 22.8, (float) $ev->grand_delta, 0.001);
        $this->assertTrue($ev->after['stock_untouched']);
    }

    public function test_cash_return_refund_follows_the_credit_and_date_and_rep_move_the_entries(): void
    {
        $inv = $this->invoice();
        $ret = $this->returnDoc($inv, 9, 'cash');
        $balance = (float) $this->client->fresh()->balance;

        $this->actingAs($this->accountant)->postJson($this->url('lines', $ret), ['qty' => [$this->product->id => 1]])->assertOk();
        $ret->refresh();
        $this->assertEqualsWithDelta(10.26, (float) $ret->grand_total, 0.001);
        $this->assertEqualsWithDelta(10.26, (float) Transaction::find($ret->transaction_id)->credit, 0.001);
        $this->assertEqualsWithDelta(10.26, (float) Transaction::find($ret->refund_transaction_id)->debit, 0.001);
        // الكاش: الرصيد مايتغيّرش (اترد في الإيد)
        $this->assertEqualsWithDelta($balance, (float) $this->client->fresh()->balance, 0.001);

        $this->actingAs($this->accountant)->postJson($this->url('date', $ret), ['date' => '2026-10-27'])->assertOk();
        $this->assertSame('2026-10-27', $ret->fresh()->created_at->toDateString());
        $this->assertSame(['2026-10-27'], Transaction::whereIn('id', [$ret->transaction_id, $ret->refund_transaction_id])->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all());

        $rep2 = $this->makeRep();
        $this->actingAs($this->accountant)->postJson($this->url('rep', $ret), ['rep_id' => $rep2->id])->assertOk();
        $this->assertSame($rep2->id, $ret->fresh()->user_id);
        $this->assertBalanceMatchesLedger();
    }

    public function test_money_is_sales_minus_returns_and_export_dashboard_and_switch(): void
    {
        $inv = $this->invoice();
        $ret = $this->returnDoc($inv);
        DocReview::setSerial($this->accountant, $inv, '500');
        DocReview::mark($this->accountant, $inv->fresh(), 'ok', null);

        $m = DocQueue::moneySum(DocQueue::moneyByClient([$this->client->id]), [$this->client->id]);
        $this->assertSame(2, $m['docs']);
        $this->assertSame(1, $m['ret_docs']);
        $this->assertEqualsWithDelta(51.3, $m['sales'], 0.001);
        $this->assertEqualsWithDelta(20.52, $m['ret'], 0.001);
        $this->assertEqualsWithDelta(30.78, $m['net'], 0.001);
        $this->assertEqualsWithDelta(51.3, $m['ok_net'], 0.001);   // المرتجع لسه ماتراجعش
        $this->assertEqualsWithDelta((float) $this->client->fresh()->balance, $m['net'], 0.001);

        $st = DocQueue::stats(DocQueue::all($this->accountant));
        $this->assertSame(1, $st['returns']);
        $this->assertEqualsWithDelta(30.78, $st['grand'], 0.001);
        $this->assertEqualsWithDelta(20.52, $st['ret_grand'], 0.001);

        $this->actingAs($this->accountant)->get(route('erp.review.money', ['show' => 'all']))->assertOk()->assertSee(__('review.m_sales_ret'));
        $this->actingAs($this->accountant)->get(route('erp.review'))->assertOk()->assertSee(__('review.docs_ret_progress', ['ok' => 0, 'n' => 1]));
        $ctx = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['client' => $this->client->id]))->assertOk()->viewData('context');
        $this->assertEqualsWithDelta(30.78, $ctx['net'], 0.001);

        $body = $this->actingAs($this->accountant)->get(route('erp.review.docs.export', ['status' => 'all', 'type' => 'return']))->streamedContent();
        $this->assertStringContainsString(__('review.type_return'), $body);
        $this->assertStringContainsString($inv->number, $body);

        // المفتاح: إمضاء العميل شرط ✓ لو `review.return_proof_required`
        config(['review.return_proof_required' => true]);
        DocReview::setSerial($this->accountant, $ret, '9');
        $this->actingAs($this->accountant)->postJson($this->url('mark', $ret), ['status' => 'ok'])->assertStatus(422)
            ->assertJsonPath('message', __('review.ret_proof_required'));
        $this->assertMatchesRegularExpression('/data-chk="proof"\s+data-hard="1"/', $this->actingAs($this->accountant)->get($this->url('pane', $ret))->getContent());
        DocReview::setProof($this->accountant, $ret->fresh(), false, null, true);
        $this->actingAs($this->accountant)->postJson($this->url('mark', $ret), ['status' => 'ok'])->assertOk()->assertJsonPath('row.state', 'ok');
    }
}
