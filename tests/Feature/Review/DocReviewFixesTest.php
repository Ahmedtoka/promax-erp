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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** (١٣/١١ — أحمد على اللايف) تراجع ✓، باج الكاش المكرر، إثبات الفواتير، البحث، وفحص `review:audit` */
class DocReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $accountant;

    private User $rep;

    private Client $client;

    private Product $product;

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
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->product->id, 'price' => 10]);
        $this->client = $this->makeClient(['name' => 'سوبر جيت', 'name_en' => 'super jet', 'price_list_id' => $list->id, 'taxable' => true,
            'payment_terms' => 'credit', 'phone' => '01055550123']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(array $attrs = []): Invoice
    {
        $inv = Invoice::create(array_merge([
            'number' => 'INV-'.random_int(10000, 99999), 'paper_ref' => 'T-'.random_int(100, 999),
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

    /** تحصيل ميداني دفع الفاتورة بعد البيع (زي Visit#212 مع INV-1017) */
    private function fieldCollection(float $amount): void
    {
        Transaction::create(['client_id' => $this->client->id, 'date' => '2026-10-22', 'memo' => 'تحصيل ميداني أثناء زيارة', 'debit' => 0,
            'credit' => $amount, 'tax' => 0, 'kind' => 'collection', 'source_type' => 'App\\Models\\Visit', 'source_id' => 999]);
        $this->client->recalculate();
    }

    // ═══ ٢. باج الكاش المكرر (INV-1017 / ورقي 560) ═══

    public function test_cash_toggle_refuses_an_invoice_already_collected_in_the_field(): void
    {
        $inv = $this->invoice(['paper_ref' => '0560']);
        $this->fieldCollection(34.2);

        $this->actingAs($this->admin)->post(route('ops.invoices.payment', $inv))->assertRedirect()->assertSessionHasErrors('payment');
        $this->assertSame('credit', $inv->fresh()->payment);
        $this->assertSame(0, Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'collection')->count());
        $this->assertEqualsWithDelta(0.0, (float) $this->client->fresh()->balance, 0.001);

        // فاتورة لسه مفتوحة: التحويل لكاش شغال عادي (بيع + تحصيل مربوط = رصيد صفر)
        $open = $this->invoice();
        $this->actingAs($this->admin)->post(route('ops.invoices.payment', $open))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cash', $open->fresh()->payment);
        $this->assertSame(1, Transaction::where('source_type', Invoice::class)->where('source_id', $open->id)->where('kind', 'collection')->count());
        $this->assertEqualsWithDelta(0.0, (float) $this->client->fresh()->balance, 0.001);
    }

    public function test_review_audit_reports_the_double_collection_and_fix_reverts_it_only_with_apply(): void
    {
        Storage::fake('local');
        // الداتا زي ما الباج القديم سابها: آجل اتحصّل ميداني، وبعدين اتحول كاش فاتضاف تحصيل تاني
        $inv = $this->invoice(['paper_ref' => '0560']);
        $this->fieldCollection(34.2);
        Transaction::create(['client_id' => $this->client->id, 'date' => '2026-10-20', 'memo' => 'تحصيل نقدي مع فاتورة '.$inv->number, 'debit' => 0,
            'credit' => 34.2, 'tax' => 4.2, 'kind' => 'collection', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        $inv->forceFill(['payment' => 'cash'])->saveQuietly();
        ActivityLog::create(['user_id' => $this->admin->id, 'event' => 'updated', 'subject_type' => 'Invoice', 'subject_id' => $inv->id,
            'title' => $inv->number, 'changes' => ['payment' => ['credit', 'cash']], 'route' => 'ops.invoices.payment']);
        $this->client->recalculate();
        $this->assertEqualsWithDelta(-34.2, (float) $this->client->fresh()->balance, 0.001);

        // التقرير بس: مفيش كتابة
        $this->assertSame(0, Artisan::call('review:audit', ['--fix' => true]));
        $this->assertStringContainsString($inv->number, Artisan::output());
        $this->assertSame('cash', $inv->fresh()->payment);
        $files = Storage::disk('local')->files('review-audit');
        $json = collect($files)->first(fn ($f) => preg_match('/audit-\d{8}-\d{6}\.json$/', $f));
        $report = json_decode(Storage::disk('local')->get($json), true)['report'];
        $this->assertSame('YES', $report['double_collected'][0]['likely_double']);
        $this->assertSame([], $report['balance_vs_ledger']);

        // --apply: يرجع آجل ويتشال التحصيل الزيادة، بلقطة
        Artisan::call('review:audit', ['--fix' => true, '--apply' => true]);
        $this->assertSame('credit', $inv->fresh()->payment);
        $this->assertSame(0, Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'collection')->count());
        $this->assertEqualsWithDelta(0.0, (float) $this->client->fresh()->balance, 0.001);
        $this->assertNotEmpty(collect(Storage::disk('local')->files('review-audit'))->filter(fn ($f) => str_contains($f, 'fix-snapshot')));
    }

    // ═══ ١. التراجع عن ✓ ═══

    public function test_reviewer_undoes_own_mark_same_day_only_and_it_is_logged(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv->id]), ['status' => 'ok'])->assertOk();
        $this->actingAs($this->accountant)->get(route('erp.review.docs.pane', ['type' => 'invoice', 'id' => $inv->id]))->assertOk()
            ->assertSee(__('review.btn_undo'));

        // حد تاني مايقدرش يتراجع عن علامتي
        $other = $this->makeAdmin(['role' => 'accountant']);
        $this->actingAs($other)->postJson(route('erp.review.docs.undo', ['type' => 'invoice', 'id' => $inv->id]))->assertStatus(422)
            ->assertJsonPath('message', __('review.undo_denied'));

        $before = ActivityLog::max('id') ?? 0;
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.undo', ['type' => 'invoice', 'id' => $inv->id]))->assertOk()
            ->assertJsonPath('row.state', 'pending')->assertJsonPath('message', __('review.undo_done'));
        $this->assertSame(0, ReviewCheck::where(['entity_type' => 'invoice', 'entity_id' => $inv->id])->count());
        $this->assertSame(1, DocReviewEvent::where(['doc_type' => 'invoice', 'doc_id' => $inv->id, 'action' => 'undo'])->count());
        $log = ActivityLog::where('id', '>', $before)->where('event', 'action')->sole();
        $this->assertSame([__('review.st_ok'), __('review.st_pending')], $log->changes[__('review.log_state')]);

        // اليوم اللي بعده: الأدمن بس (فتح)
        DocReview::mark($this->accountant, $inv->fresh(), 'ok', null);
        Carbon::setTestNow('2026-11-14 09:00:00');
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.undo', ['type' => 'invoice', 'id' => $inv->id]))->assertStatus(422);
        $this->actingAs($this->admin)->postJson(route('erp.review.docs.reopen', ['type' => 'invoice', 'id' => $inv->id]))->assertOk();
    }

    // ═══ ٤. إثبات الاستلام على الفواتير ═══

    public function test_invoice_proof_checkboxes_flags_and_sales_stats(): void
    {
        $inv = $this->invoice();
        $url = fn (string $r) => route('erp.review.docs.'.$r, ['type' => 'invoice', 'id' => $inv->id]);
        $this->actingAs($this->accountant)->get($url('pane'))->assertOk()
            ->assertSee('class="bad" data-chk="proof"', false)->assertSee('dp-receipt', false)->assertSee('dp-signed', false);
        $this->assertContains('noproof', DocQueue::all($this->accountant, 'inv-'.$inv->id)->first()['flags']);

        $this->actingAs($this->accountant)->postJson($url('proof'), ['receipt_note' => 0, 'receipt_note_no' => '٣٣', 'paper_signed' => 0])->assertOk()
            ->assertJsonPath('checks.proof.ok', true)->assertJsonPath('row.tags', ['receipt']);
        $this->assertSame('33', $inv->fresh()->receipt_note_no);

        // إحصائيات الإثبات = فواتير + أوامر متسلّمة (مستندات بيع)، وفلتر «sales»
        $po = PurchaseOrder::create(['number' => 'PO-'.random_int(10000, 99999), 'client_id' => $this->client->id, 'status' => 'delivered',
            'approval_status' => 'approved', 'assigned_to' => $this->rep->id, 'price_mode' => 'la', 'total' => 30, 'tax_total' => 4.2, 'grand_total' => 34.2]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->product->id, 'qty' => 3, 'delivered_qty' => 3,
            'price' => 10, 'list_price' => 10, 'discount_pct' => 0, 'total' => 30, 'tax_rate' => 0.14, 'tax' => 4.2]);
        $st = DocQueue::proofStats(DocQueue::all($this->accountant))['total'];
        $this->assertSame(2, $st['delivered']);
        $this->assertSame(1, $st['invoices']);
        $this->assertSame(1, $st['pos']);
        $this->assertSame(1, $st['unproven']);
        $this->assertSame(['po'], DocQueue::filter(DocQueue::all($this->accountant), ['type' => 'sales', 'flag' => 'noproof'])->pluck('type')->all());

        // المفتاح
        config(['review.invoice_proof_required' => true]);
        $inv2 = $this->invoice();
        $this->actingAs($this->accountant)->postJson(route('erp.review.docs.mark', ['type' => 'invoice', 'id' => $inv2->id]), ['status' => 'ok'])
            ->assertStatus(422)->assertJsonPath('message', __('review.proof_required'));
    }

    // ═══ ٥. البحث ═══

    public function test_search_finds_documents_by_number_paper_serial_and_arabic_digits(): void
    {
        $inv = $this->invoice(['number' => 'INV-1017', 'paper_ref' => '0560']);
        $q = fn (string $s) => Client::search(Client::query(), $s)->pluck('id')->all();
        foreach (['INV-1017', 'inv-1017', '1017', '560', '0560', '٠٥٦٠', 'super jet', 'سوبر', '01055550123', '٠١٠٥٥٥٥٠١٢٣'] as $s) {
            $this->assertSame([$this->client->id], $q($s), "clients search «{$s}»");
        }
        $this->assertSame([], $q('9999'));

        // شاشة العملاء
        $this->actingAs($this->admin)->get(route('erp.clients', ['q' => 'INV-1017']))->assertOk()->assertSee($this->client->code);

        // طابور المراجعة: بحث عن مستند متراجع وانت على «لسه» ← يروح «الكل»
        DocReview::mark($this->accountant, $inv, 'ok', null);
        $this->actingAs($this->accountant)->get(route('erp.review.docs', ['q' => '1017']))->assertRedirect();
        $page = $this->actingAs($this->accountant)->get(route('erp.review.docs', ['q' => '560', 'status' => 'all']))->assertOk();
        $this->assertSame(1, $page->viewData('count'));
        // الفورم فيه زرار إرسال حقيقي (Enter بيبعت)
        $page->assertSee('class="dq-go"', false);
    }
}
