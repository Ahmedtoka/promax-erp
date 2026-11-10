<?php

namespace Tests\Feature\Review;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocReviewEvent;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReviewCheck;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Review\DocQueue;
use App\Services\Review\DocReview;
use App\Services\Review\ReviewerStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** المراجعة مستند مستند (١١/١١/٢٠٢٦) */
class DocReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        $this->admin = $this->makeAdmin();
        $this->accountant = $this->makeAdmin(['role' => 'accountant']);
        $this->rep = $this->makeRep();
        $list = PriceList::create(['code' => 'la', 'name' => 'قايمة أ', 'name_en' => 'List A', 'active' => true, 'is_default' => false]);
        $this->product = $this->makeProduct(['cost' => 5]);
        $this->product2 = $this->makeProduct(['cost' => 3]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->product->id, 'price' => 10]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->product2->id, 'price' => 20]);
        $this->client = $this->makeClient(['price_list_id' => $list->id, 'taxable' => true, 'payment_terms' => 'credit', 'phone' => '01055550123']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** فاتورة ٣ قطع بسعر ١٠ صح (٣٠ + ٤٫٢ ضريبة) + قيد البيع */
    private function invoice(array $attrs = []): Invoice
    {
        $inv = Invoice::create(array_merge([
            'number' => 'INV-'.random_int(10000, 99999), 'paper_ref' => null,
            'client_id' => $this->client->id, 'user_id' => $this->rep->id, 'payment' => 'credit', 'price_list' => 'la',
            'subtotal' => 30, 'discount_pct' => 0, 'discount' => 0, 'total' => 30, 'tax_total' => 4.2, 'grand_total' => 34.2,
            'eta_status' => 'ready', 'cost_total' => 15,
        ], $attrs));
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $this->product->id, 'qty' => 3, 'list_price' => 10, 'price' => 10,
            'unit_cost' => 5, 'total' => 30, 'tax_rate' => 0.14, 'tax' => 4.2]);
        Transaction::create(['client_id' => $this->client->id, 'date' => '2026-10-20', 'memo' => 'بيع', 'debit' => 34.2, 'credit' => 0,
            'tax' => 4.2, 'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        $inv->forceFill(['created_at' => Carbon::parse('2026-10-20 12:00')])->save();
        $this->client->recalculate();

        return $inv->fresh();
    }

    private function deliveredPo(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'number' => 'PO-'.random_int(10000, 99999), 'client_id' => $this->client->id, 'status' => 'delivered',
            'approval_status' => 'approved', 'assigned_to' => $this->rep->id, 'price_mode' => 'la',
            'total' => 30, 'tax_total' => 4.2, 'grand_total' => 34.2,
        ]);
        $po->forceFill(['delivered_at' => Carbon::parse('2026-10-21 09:00')])->save();
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->product->id, 'qty' => 3, 'delivered_qty' => 3,
            'price' => 10, 'list_price' => 10, 'discount_pct' => 0, 'total' => 30, 'tax_rate' => 0.14, 'tax' => 4.2]);
        Transaction::create(['client_id' => $this->client->id, 'date' => '2026-10-21', 'memo' => 'تسليم', 'debit' => 34.2, 'credit' => 0,
            'tax' => 4.2, 'kind' => 'sale', 'source_type' => PurchaseOrder::class, 'source_id' => $po->id]);
        $this->client->recalculate();

        return $po->fresh();
    }

    private function assertBalanceMatchesLedger(): void
    {
        $c = $this->client->fresh();
        $rows = Transaction::where('client_id', $c->id)->get();
        $this->assertEqualsWithDelta((float) $rows->sum('debit') - (float) $rows->sum('credit'), (float) $c->balance, 0.001);
    }

    public function test_screen_opens_for_accountant_and_admin_only(): void
    {
        $inv = $this->invoice();

        foreach ([$this->admin, $this->accountant] as $u) {
            $this->actingAs($u)->get(route('erp.review.docs', ['status' => 'all']))->assertOk()->assertSee($inv->number);
            $this->actingAs($u)->get(route('erp.review.docs.pane', ['type' => 'invoice', 'id' => $inv->id]))->assertOk()->assertSee($inv->number);
        }
        foreach (['manager', 'branch_manager'] as $role) {
            $this->actingAs($this->makeAdmin(['role' => $role]))->get(route('erp.review.docs'))->assertStatus(403);
        }
        $this->actingAs($this->rep)->get(route('erp.review.docs'))->assertStatus(403);

        // التحليل وفتح ✓ تاني أدمن بس
        $this->actingAs($this->accountant)->get(route('erp.review.docs.people'))->assertStatus(403);
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.reopen', ['type' => 'invoice', 'id' => $inv->id]))->assertStatus(403);
        $this->actingAs($this->admin)->get(route('erp.review.docs.people'))->assertOk();
        // الداشبورد بيعرض كارت المرحلة ٤
        $this->actingAs($this->accountant)->get(route('erp.review'))->assertOk()->assertSee(__('review.dash_docs'));
    }

    public function test_mark_ok_logs_event_and_activity_and_cannot_be_marked_twice(): void
    {
        $inv = $this->invoice();

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv->id]), [
            'status' => 'ok', 'open_ms' => 12000,
        ])->assertOk()->assertJsonPath('row.state', 'ok')->assertJsonPath('overall.ok', 1);

        $chk = ReviewCheck::where(['entity_type' => 'invoice', 'entity_id' => $inv->id, 'item' => 'doc'])->sole();
        $this->assertSame('ok', $chk->status);
        $this->assertSame($this->accountant->id, (int) $chk->user_id);
        $this->assertEqualsWithDelta(34.2, (float) $chk->system_balance, 0.001);

        $ev = DocReviewEvent::sole();
        $this->assertSame('ok', $ev->action);
        $this->assertSame(12000, (int) $ev->open_ms);
        $this->assertTrue(ActivityLog::where('subject_type', Invoice::class)->where('subject_id', $inv->id)->where('event', 'action')->exists());

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv->id]), ['status' => 'ok'])
            ->assertStatus(422)->assertJsonPath('message', __('review.doc_already_ok'));

        // الأدمن بيفتحها تاني
        $this->actingAs($this->admin)->postJson(route('erp.review.docs.reopen', ['type' => 'invoice', 'id' => $inv->id]), ['note' => 'تاني'])->assertOk();
        $this->assertSame(0, ReviewCheck::where('entity_type', 'invoice')->count());
        $this->assertSame(['ok', 'reopen'], DocReviewEvent::orderBy('id')->pluck('action')->all());

        $this->assertThrows(fn () => DocReviewEvent::first()->update(['note' => 'x']), \LogicException::class);
    }

    public function test_issue_needs_a_note(): void
    {
        $inv = $this->invoice();
        $url = route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv->id]);

        $this->actingAs($this->accountant)->postJson($url, ['status' => 'issue'])->assertStatus(422);
        $this->actingAs($this->accountant)->postJson($url, ['status' => 'issue', 'note' => 'السعر مختلف'])->assertOk()->assertJsonPath('row.state', 'issue');
        // بعد التصليح يتعلّم ✓ عادي
        $this->actingAs($this->accountant)->postJson($url, ['status' => 'ok'])->assertOk()->assertJsonPath('row.state', 'ok');
    }

    public function test_total_changed_after_review_shows_changed_not_done(): void
    {
        $inv = $this->invoice();
        DocReview::mark($this->accountant, $inv, 'ok', null);

        $inv->update(['grand_total' => 40]);
        $row = DocQueue::all($this->admin)->firstWhere('key', 'inv-'.$inv->id);
        $this->assertSame('changed', $row['state']);
        $this->assertSame(0, DocQueue::stats(DocQueue::all($this->admin))['ok']);
    }

    public function test_serial_saves_warns_on_duplicate_and_clears_review(): void
    {
        $other = $this->invoice(['paper_ref' => '1145']);
        $inv = $this->invoice();
        DocReview::mark($this->accountant, $inv, 'ok', null);

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.serial', ['type' => 'invoice', 'id' => $inv->id]), ['paper_ref' => ' 1145 '])
            ->assertOk()->assertJsonPath('row.paper_ref', '1145')->assertJsonPath('row.dup', true)
            ->assertJsonPath('row.state', 'pending')
            ->assertJsonFragment(['warn' => __('review.serial_dup', ['docs' => $other->number])]);

        $this->assertSame('1145', $inv->fresh()->paper_ref);
        $ev = DocReviewEvent::where('action', 'serial')->sole();
        $this->assertNull($ev->before['paper_ref']);
        $this->assertTrue($ev->after['review_cleared']);

        // الأمر كمان ليه سيريال
        $po = $this->deliveredPo();
        DocReview::setSerial($this->accountant, $po, 'D-77');
        $this->assertSame('D-77', $po->fresh()->paper_ref);
    }

    public function test_rep_and_date_change(): void
    {
        $inv = $this->invoice();
        $rep2 = $this->makeRep();

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.rep', ['type' => 'invoice', 'id' => $inv->id]), ['rep_id' => $rep2->id])->assertOk();
        $this->assertSame($rep2->id, (int) $inv->fresh()->user_id);
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.rep', ['type' => 'invoice', 'id' => $inv->id]), ['rep_id' => $this->accountant->id])->assertStatus(422);

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.date', ['type' => 'invoice', 'id' => $inv->id]), ['date' => '2026-10-18'])->assertOk();
        $this->assertSame('2026-10-18 12:00:00', $inv->fresh()->created_at->toDateTimeString());
        $this->assertSame('2026-10-18', Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->value('date')->toDateString());
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.date', ['type' => 'invoice', 'id' => $inv->id]), ['date' => '2026-11-20'])->assertStatus(422);

        $po = $this->deliveredPo();
        DocReview::setRep($this->accountant, $po, $rep2->id);
        DocReview::setDate($this->accountant, $po, '2026-10-25');
        $po->refresh();
        $this->assertSame($rep2->id, (int) $po->assigned_to);
        $this->assertSame('2026-10-25', $po->delivered_at->toDateString());
        $this->assertSame('2026-10-25', Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->value('date')->toDateString());
    }

    public function test_invoice_lines_edit_reprices_from_list_moves_money_not_stock(): void
    {
        $inv = $this->invoice();
        DocReview::mark($this->accountant, $inv, 'ok', null);

        // preview من غير كتابة
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.preview', ['type' => 'invoice', 'id' => $inv->id]), [
            'qty' => [$this->product->id => 5, $this->product2->id => 2],
        ])->assertOk()->assertJsonPath('net', 90)->assertJsonPath('before.grand', 34.2);
        $this->assertSame(1, InvoiceItem::where('invoice_id', $inv->id)->count());

        // ٥ × ١٠ + ٢ × ٢٠ = ٩٠ + ١٢٫٦ ضريبة
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.lines', ['type' => 'invoice', 'id' => $inv->id]), [
            'qty' => [$this->product->id => 5, $this->product2->id => 2], 'note' => 'الورقة ٥ قطع',
        ])->assertOk()->assertJsonPath('row.state', 'pending');

        $inv->refresh();
        $this->assertEqualsWithDelta(90, (float) $inv->total, 0.001);
        $this->assertEqualsWithDelta(12.6, (float) $inv->tax_total, 0.001);
        $this->assertEqualsWithDelta(102.6, (float) $inv->grand_total, 0.001);
        $this->assertEqualsWithDelta(31, (float) $inv->cost_total, 0.001);
        $this->assertEqualsWithDelta(20, (float) InvoiceItem::where('invoice_id', $inv->id)->where('product_id', $this->product2->id)->value('price'), 0.0001);
        $this->assertEqualsWithDelta(102.6, (float) Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'sale')->value('debit'), 0.001);
        $this->assertBalanceMatchesLedger();
        $this->assertSame(0, ReviewCheck::where('entity_type', 'invoice')->count(), 'the edit clears the ✓');

        $ev = DocReviewEvent::where('action', 'lines')->sole();
        $this->assertEqualsWithDelta(68.4, (float) $ev->grand_delta, 0.001);
        $this->assertEqualsWithDelta(60, (float) $ev->net_delta, 0.001);
        $this->assertSame(3, $ev->before['lines'][0]['qty']);
        $this->assertTrue($ev->after['stock_untouched']);
        $this->assertSame('الورقة ٥ قطع', $ev->note);

        // حذف صنف + تقليل
        DocReview::saveLines($this->accountant, $inv->fresh(), [$this->product->id => 1]);
        $this->assertSame([$this->product->id], InvoiceItem::where('invoice_id', $inv->id)->pluck('product_id')->all());
        $this->assertEqualsWithDelta(11.4, (float) $inv->fresh()->grand_total, 0.001);

        // مايفضلش فاضي
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.lines', ['type' => 'invoice', 'id' => $inv->id]), ['qty' => [$this->product->id => 0]])
            ->assertStatus(422);
    }

    public function test_delivered_po_lines_update_delivered_qty_and_sale_entry(): void
    {
        $po = $this->deliveredPo();

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.lines', ['type' => 'po', 'id' => $po->id]), [
            'qty' => [$this->product->id => 4],
        ])->assertOk();

        $po->refresh();
        $item = $po->items()->sole();
        $this->assertSame(4, (int) $item->qty);
        $this->assertSame(4, (int) $item->delivered_qty);
        $this->assertEqualsWithDelta(45.6, (float) $po->grand_total, 0.001);
        $this->assertEqualsWithDelta(45.6, (float) Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->where('kind', 'sale')->value('debit'), 0.001);
        $this->assertBalanceMatchesLedger();
    }

    public function test_locked_documents_refuse_money_edits_but_take_serial_and_marks(): void
    {
        $inv = $this->invoice(['eta_status' => 'submitted']);

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.lines', ['type' => 'invoice', 'id' => $inv->id]), ['qty' => [$this->product->id => 9]])
            ->assertStatus(422)->assertJsonPath('message', __('review.lock_eta'));
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.rep', ['type' => 'invoice', 'id' => $inv->id]), ['rep_id' => $this->makeRep()->id])->assertStatus(422);
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.serial', ['type' => 'invoice', 'id' => $inv->id]), ['paper_ref' => '900'])->assertOk();
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv->id]), ['status' => 'ok'])->assertOk();
        $this->assertSame(3, (int) InvoiceItem::where('invoice_id', $inv->id)->value('qty'));
    }

    public function test_fast_streaks_are_flagged(): void
    {
        $docs = collect(range(1, 7))->map(fn () => $this->invoice());

        // أول اتنين بفرق ٤٠ ثانية، وبعدين ٥ ورا بعض كل ٣ ثواني
        $t = Carbon::parse('2026-11-11 10:00:00');
        foreach ($docs as $i => $d) {
            $t = $t->copy()->addSeconds($i < 2 ? 40 : 3);
            Carbon::setTestNow($t);
            DocReview::mark($this->accountant, $d, 'ok', null);
        }

        $day = ReviewerStats::days(Carbon::parse('2026-11-11'), Carbon::parse('2026-11-11'))[0];
        $this->assertSame(7, $day['reviews']);
        $this->assertSame(5, $day['fast']);
        $this->assertTrue($day['flag']);
        $this->assertSame(5, $day['streaks'][0]['n']);

        $this->actingAs($this->admin)->get(route('erp.review.docs.people', ['from' => '2026-11-11', 'to' => '2026-11-11', 'user' => $this->accountant->id, 'day' => '2026-11-11']))
            ->assertOk()->assertSee('🚩');
    }

    public function test_search_and_filters_and_progress(): void
    {
        $a = $this->invoice(['paper_ref' => '7781']);
        $b = $this->invoice();
        $po = $this->deliveredPo();
        DocReview::mark($this->accountant, $a, 'ok', null);

        $all = DocQueue::all($this->admin);
        $this->assertSame(3, $all->count());
        $this->assertSame([$a->id], DocQueue::filter($all, ['q' => '7781'])->pluck('id')->all());
        $this->assertSame(3, DocQueue::filter($all, ['q' => '01055550123'])->count());
        $this->assertSame([$po->id], DocQueue::filter($all, ['type' => 'po'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['inv-'.$b->id, 'po-'.$po->id], DocQueue::filter($all, ['flag' => 'noserial'])->pluck('key')->all());

        $s = DocQueue::stats($all);
        $this->assertSame(1, $s['ok']);
        $this->assertSame(33, $s['pct']);
        $this->assertSame(9, $s['pcs']);

        $this->actingAs($this->accountant)->get(route('erp.review.docs', ['q' => '7781', 'status' => 'all']))
            ->assertOk()->assertSee($a->number)->assertDontSee($b->number);
        $this->actingAs($this->accountant)->get(route('erp.review.docs'))->assertOk()->assertDontSee($a->number)->assertSee($b->number);
    }
}
