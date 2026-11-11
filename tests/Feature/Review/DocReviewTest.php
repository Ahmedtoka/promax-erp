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

    private int $serialSeq = 0;

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
            'number' => 'INV-'.random_int(10000, 99999), 'paper_ref' => 'T-'.(++$this->serialSeq),
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
        $this->assertTrue(ActivityLog::where('subject_type', 'Invoice')->where('subject_id', $inv->id)->where('event', 'action')->exists());

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
        $inv = $this->invoice(['paper_ref' => 'OLD-1']);
        DocReview::mark($this->accountant, $inv, 'ok', null);

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.serial', ['type' => 'invoice', 'id' => $inv->id]), ['paper_ref' => ' 1145 '])
            ->assertOk()->assertJsonPath('row.paper_ref', '1145')->assertJsonPath('row.dup', true)
            ->assertJsonPath('row.state', 'pending')
            ->assertJsonFragment(['warn' => __('review.serial_dup', ['docs' => $other->number])]);

        $this->assertSame('1145', $inv->fresh()->paper_ref);
        $ev = DocReviewEvent::where('action', 'serial')->sole();
        $this->assertSame('OLD-1', $ev->before['paper_ref']);
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
        $b = $this->invoice(['paper_ref' => null]);
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

    /** (١٢/١١) «السجل والاسم»: سطر واحد مقروء — نوع قصير، رقم المستند والعميل، قبل ← بعد بالعربي */
    public function test_activity_log_row_is_readable_and_not_duplicated(): void
    {
        $inv = $this->invoice(['paper_ref' => null]);
        $before = ActivityLog::max('id') ?? 0;

        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.serial', ['type' => 'invoice', 'id' => $inv->id]), ['paper_ref' => 'P-55'])->assertOk();
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv->id]), ['status' => 'ok'])->assertOk();

        $rows = ActivityLog::where('id', '>', $before)->where('event', 'action')->orderBy('id')->get();
        $this->assertSame(['Invoice', 'Invoice'], $rows->pluck('subject_type')->all(), 'no route-level duplicate rows');
        $ok = $rows->last();
        $this->assertSame($this->accountant->name, $ok->user_name);
        $this->assertStringContainsString($inv->number, $ok->title);
        $this->assertStringContainsString($this->client->displayName(), $ok->title);
        $this->assertSame([__('review.st_pending'), __('review.st_ok')], $ok->changes[__('review.log_state')]);
        $this->assertSame([null, 'P-55'], $rows->first()->changes[__('review.serial')]);
        // علامة المستند مابتطلعش «ReviewCheck #5» في السجل
        $this->assertFalse(ActivityLog::where('id', '>', $before)->where('subject_type', 'ReviewCheck')->exists());
        // ومركز النشاط بيفتح من غير أخطاء
        $this->actingAs($this->admin)->get(route('erp.activity', ['tab' => 'log']))->assertOk()->assertSee($inv->number);
    }

    /** (١٢/١١) الحسابات ← المستندات: الرصيد ومتراجع منه قد إيه، ولينك للطابور ورجوع */
    public function test_money_tab_shows_reviewed_share_and_links_to_the_queue(): void
    {
        $a = $this->invoice();
        $this->invoice();
        DocReview::mark($this->accountant, $a, 'ok', null);

        $m = DocQueue::moneyByClient([$this->client->id])[$this->client->id];
        $this->assertSame(2, $m['docs']);
        $this->assertSame(1, $m['ok']);
        $this->assertEqualsWithDelta(34.2, $m['ok_grand'], 0.001);
        $this->assertEqualsWithDelta(68.4, $m['grand'], 0.001);
        $this->assertSame(['docs' => 2, 'ok' => 1], $m['months']['2026-10']);

        $docsUrl = route('erp.review.docs', ['client' => $this->client->id, 'status' => 'all']);
        $this->actingAs($this->accountant)->get(route('erp.review.money', ['show' => 'all']))->assertOk()
            ->assertSee(__('review.m_docs_n', ['ok' => 1, 'n' => 2]))
            ->assertSee(e($docsUrl), false);

        // شريط العميل فوق الطابور: رصيده ونسبة المتراجع ورجوع للحسابات
        $this->actingAs($this->accountant)->get($docsUrl)->assertOk()
            ->assertSee(__('review.ctx_back_money'))
            ->assertSee('50%')
            ->assertSee(number_format((float) $this->client->fresh()->balance, 2));
    }

    /** (١٢/١١) الترقيم + نسبة السلسلة من كل مستنداتها مش من اللي ظاهر بفلتر الحالة */
    public function test_queue_pages_and_group_progress_counts_all_states(): void
    {
        $a = $this->invoice();
        $this->invoice();
        $this->invoice();
        DocReview::mark($this->accountant, $a, 'ok', null);

        $res = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['per' => 25]))->assertOk();
        $res->assertSee('data-total="3" data-ok="1"', false);
        $res->assertSee(__('review.pager_info', ['from' => '<b>1</b>', 'to' => '<b>2</b>', 'n' => '<b>2</b>']), false);

        // مقاس صفحة مش في القايمة ← الافتراضي
        $this->actingAs($this->accountant)->get(route('erp.review.docs', ['per' => 7, 'status' => 'all']))->assertOk()->assertViewHas('per', 50);
    }

    /** (١٢/١١) التنبيهات الأوتوماتيك + فلترها + العينة */
    public function test_auto_flags_filters_and_spot_check_sample(): void
    {
        $other = $this->makeRep();
        $this->client->update(['rep_id' => $other->id]);
        $a = $this->invoice(['paper_ref' => 'S-1']);
        $b = $this->invoice(['paper_ref' => null]);
        $b->forceFill(['created_at' => Carbon::parse('2026-12-01 10:00')])->save();
        ActivityLog::create(['user_id' => $this->admin->id, 'event' => 'updated', 'subject_type' => 'Invoice', 'subject_id' => $a->id,
            'title' => $a->number, 'changes' => ['grand_total' => ['30.00', 34.2]], 'created_at' => '2026-11-10 22:00:00']);

        $rows = DocQueue::all($this->admin)->keyBy('id');
        $this->assertEqualsCanonicalizing(['rep_off', 'repriced'], $rows[$a->id]['flags']);
        $this->assertEqualsCanonicalizing(['noserial', 'odd_date', 'rep_off'], $rows[$b->id]['flags']);
        $all = DocQueue::all($this->admin);
        $this->assertSame([$a->id], DocQueue::filter($all, ['flag' => 'repriced'])->pluck('id')->all());
        $this->assertSame(2, DocQueue::filter($all, ['flag' => 'any'])->count());

        DocReview::mark($this->accountant, $a, 'ok', null);
        $b->update(['paper_ref' => 'S-2']);
        DocReview::mark($this->accountant, $b->fresh(), 'ok', null);
        $all = DocQueue::all($this->admin);
        $s1 = DocQueue::filter($all, ['by' => $this->accountant->id, 'day' => '2026-11-11', 'sample' => 1]);
        $this->assertCount(1, $s1);
        $this->assertSame($s1->pluck('id')->all(), DocQueue::filter($all, ['by' => $this->accountant->id, 'day' => '2026-11-11', 'sample' => 1])->pluck('id')->all(), 'same sample every time');
        $this->assertCount(0, DocQueue::filter($all, ['by' => $this->admin->id]));

        $this->actingAs($this->admin)->get(route('erp.review.docs', ['status' => 'ok', 'by' => $this->accountant->id, 'day' => '2026-11-11', 'sample' => 1]))
            ->assertOk()->assertSee(__('review.sample_banner', ['n' => 1, 'who' => $this->accountant->displayName(), 'day' => '2026-11-11']));
        // الفحص الأوتوماتيك في اللوحة
        $this->actingAs($this->accountant)->get(route('erp.review.docs.pane', ['type' => 'invoice', 'id' => $a->id]))
            ->assertOk()->assertSee(__('review.chk_rep_off', ['rep' => $other->displayName()]))->assertSee(__('review.chk_repriced'));
    }

    /** (١٢/١١) التصدير + الداشبورد (المشاكل المفتوحة والسرعة) */
    public function test_exports_and_dashboard_issue_list(): void
    {
        $a = $this->invoice(['paper_ref' => 'E-9']);
        DocReview::mark($this->accountant, $a, 'issue', 'الكمية غلط');

        $csv = $this->actingAs($this->accountant)->get(route('erp.review.docs.export', ['status' => 'all']));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringStartsWith(chr(0xEF).chr(0xBB).chr(0xBF), $body);
        $this->assertStringContainsString($a->number, $body);
        $this->assertStringContainsString('الكمية غلط', $body);

        $this->actingAs($this->accountant)->get(route('erp.review.docs.people.export'))->assertStatus(403);
        $trail = $this->actingAs($this->admin)->get(route('erp.review.docs.people.export', ['from' => '2026-11-11', 'to' => '2026-11-11']));
        $trail->assertOk();
        $this->assertStringContainsString($a->number, $trail->streamedContent());

        $this->actingAs($this->accountant)->get(route('erp.review'))->assertOk()
            ->assertSee(__('review.dash_issues', ['n' => 1]))->assertSee('الكمية غلط')->assertSee(__('review.dash_pace'));
    }

    /** (١٢/١١) مفاتيح config/review.php: منع «طابق الشهر» قبل مراجعة مستنداته، وتأكيد الأحمر */
    public function test_review_switches_month_block_and_red_confirm(): void
    {
        $a = $this->invoice();
        $mark = fn () => $this->actingAs($this->accountant)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $this->client->id, 'items' => ['m:2026-10'], 'status' => 'ok',
        ]);

        config(['review.month_needs_all_docs' => true]);
        $mark()->assertStatus(422)->assertJsonPath('message', __('review.month_docs_block', ['ok' => 0, 'n' => 1]));
        DocReview::mark($this->accountant, $a, 'ok', null);
        $mark()->assertOk();

        config(['review.month_needs_all_docs' => false]);
        $this->invoice();   // مستند جديد في نفس الشهر لسه ماتراجعش — تحذير بس، مش منع
        $mark()->assertOk();

        config(['review.confirm_red_checks' => false]);
        $this->actingAs($this->accountant)->get(route('erp.review.docs'))->assertOk()->assertSee('const CONFIRM_RED = false;', false);
    }

    /** (١٢/١١) «فلتر السيريال فيه مشكلة»: أرقام عربي/مسافات، المكرر، البحث، العدادات، والتزامن بعد الحفظ */
    public function test_serial_filters_search_counts_and_sync(): void
    {
        $this->assertSame('1374', DocReview::normSerial(' ١٣ ٧٤ '));
        $this->assertSame('A-0776', DocReview::normSerial('a-0776'));
        $this->assertNull(DocReview::normSerial('  '));

        $legacy = $this->invoice(['paper_ref' => '١٣٧٤', 'number' => 'INV-90001']);   // اتكتب قبل التوحيد بكيبورد عربي
        $b = $this->invoice(['number' => 'INV-90002', 'paper_ref' => null]);
        $c = $this->invoice(['number' => 'INV-90003', 'paper_ref' => null]);
        $po = $this->deliveredPo();

        // الحفظ بيوحّد، والمكرر بيتمسك مع القديم المكتوب بأرقام عربي
        $res = $this->actingAs($this->accountant)->postJson(route('erp.review.docs.serial', ['type' => 'invoice', 'id' => $b->id]), ['paper_ref' => '13 74'])->assertOk();
        $this->assertSame('1374', $b->fresh()->paper_ref);
        $res->assertJsonPath('row.paper_ref', '1374')->assertJsonPath('dup_keys', ['inv-'.$legacy->id]);
        $this->assertNotContains('noserial', $res->json('row.flags'));
        $this->assertContains('dup', $res->json('row.flags'));

        $all = DocQueue::all($this->admin);
        $this->assertEqualsCanonicalizing([$legacy->id, $b->id], DocQueue::filter($all, ['flag' => 'dup'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['inv-'.$c->id, 'po-'.$po->id], DocQueue::filter($all, ['flag' => 'noserial'])->pluck('key')->all());
        // البحث بالسيريال بأرقام عربي أو إنجليزي
        $this->assertEqualsCanonicalizing([$legacy->id, $b->id], DocQueue::filter($all, ['q' => '١٣٧٤'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$legacy->id, $b->id], DocQueue::filter($all, ['q' => '1374'])->pluck('id')->all());

        // عدد الشيب = اللي هيظهر: مع تاب الحالة ومع فلتر النوع
        DocReview::mark($this->accountant, $legacy->fresh(), 'ok', null);
        $page = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['status' => 'pending']))->assertOk();
        $this->assertSame(2, $page->viewData('flagCounts')['noserial']);   // c + الأمر
        $this->assertSame(1, $page->viewData('flagCounts')['dup']);        // b بس — legacy اتراجع
        $all2 = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['status' => 'all', 'type' => 'invoice']))->assertOk();
        $this->assertSame(1, $all2->viewData('flagCounts')['noserial']);   // c من غير الأمر
        $this->assertSame(
            DocQueue::byStatus(DocQueue::filter(DocQueue::all($this->admin), ['flag' => 'noserial', 'type' => 'invoice']), 'all')->count(),
            $all2->viewData('flagCounts')['noserial']);

        // الترقيم بيحتفظ بالفلتر
        $res = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['status' => 'all', 'flag' => 'noserial', 'per' => 25]))->assertOk();
        parse_str((string) parse_url($res->viewData('page')->url(1), PHP_URL_QUERY), $qq);
        $this->assertSame('noserial', $qq['flag'] ?? null);
    }

    /** (١٢/١١ — أحمد) السيريال شرط ✓: مرفوض حتى لو اتأكد، وبعد حفظه الفحص بيخضر و✓ واحدة تعدّي */
    public function test_missing_serial_blocks_ok_and_checks_refresh_after_save(): void
    {
        $po = $this->deliveredPo();   // من غير سيريال
        $mark = fn () => $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'po', 'id' => $po->id]), ['status' => 'ok']);

        $mark()->assertStatus(422)->assertJsonPath('message', __('review.serial_required'));
        $mark()->assertStatus(422);   // «الضغطة التانية» مالهاش أي معنى عند السيرفر
        $this->assertSame(0, ReviewCheck::where('entity_type', 'po')->count());
        // ✗ مسموح من غير سيريال (الورقة نفسها ممكن تكون ضايعة)
        // اللوحة بتقول السيريال أحمر وإنه شرط
        $this->actingAs($this->accountant)->get(route('erp.review.docs.pane', ['type' => 'po', 'id' => $po->id]))
            ->assertOk()->assertSee('data-hard="1"', false)->assertSee('class="bad" data-chk="serial"', false);

        // حفظ السيريال ← الرد فيه الفحص الجديد أخضر، و✓ واحدة تعدّي
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.serial', ['type' => 'po', 'id' => $po->id]), ['paper_ref' => '٥٥٠١'])
            ->assertOk()->assertJsonPath('checks.serial.ok', true)->assertJsonPath('row.paper_ref', '5501');
        $mark()->assertOk()->assertJsonPath('row.state', 'ok');

        $inv = $this->invoice(['paper_ref' => null]);
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv->id]), ['status' => 'issue', 'note' => 'الورقة ضايعة'])
            ->assertOk()->assertJsonPath('row.state', 'issue');
    }
}
