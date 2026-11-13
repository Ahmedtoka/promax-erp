<?php

namespace Tests\Feature;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ManualCollection;
use App\Services\Settlement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** (١٣/١١ — أحمد: «حل للتكرار مرة واحدة») سداد المستندات من مصدر واحد + قيد فريد في الداتابيز */
class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->admin = $this->makeAdmin();
        $this->rep = $this->makeRep();
        $this->client = $this->makeClient(['payment_terms' => 'credit']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(float $grand = 100, string $date = '2026-10-01'): Invoice
    {
        $inv = Invoice::create(['number' => 'INV-'.random_int(10000, 99999), 'client_id' => $this->client->id, 'user_id' => $this->rep->id,
            'payment' => 'credit', 'price_list' => 'x', 'subtotal' => $grand, 'discount_pct' => 0, 'discount' => 0, 'total' => $grand,
            'tax_total' => 0, 'grand_total' => $grand, 'eta_status' => 'ready', 'cost_total' => 0]);
        $inv->forceFill(['created_at' => Carbon::parse($date.' 12:00')])->save();
        Transaction::create(['client_id' => $this->client->id, 'date' => $date, 'memo' => 'بيع', 'debit' => $grand, 'credit' => 0,
            'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        $this->client->recalculate();

        return $inv->fresh();
    }

    private function fieldCollection(float $amount, string $date = '2026-10-05'): void
    {
        Transaction::create(['client_id' => $this->client->id, 'date' => $date, 'memo' => 'ميداني', 'debit' => 0, 'credit' => $amount,
            'kind' => 'collection', 'source_type' => 'App\\Models\\Visit', 'source_id' => random_int(1000, 9999)]);
        $this->client->recalculate();
    }

    public function test_db_refuses_a_second_linked_row_of_the_same_kind_for_a_document(): void
    {
        $inv = $this->invoice();
        $row = ['client_id' => $this->client->id, 'date' => '2026-10-01', 'memo' => 'كاش', 'debit' => 0, 'credit' => 100,
            'kind' => 'collection', 'source_type' => Invoice::class, 'source_id' => $inv->id];
        Transaction::create($row);
        $this->expectException(QueryException::class);
        Transaction::create($row);
    }

    public function test_return_credit_and_cash_refund_are_unique_per_return_too(): void
    {
        $ret = ClientReturn::create(['number' => 'RET-9001', 'client_id' => $this->client->id, 'source' => 'erp', 'policy' => 'cash', 'grand_total' => 50]);
        $row = ['client_id' => $this->client->id, 'date' => '2026-10-02', 'memo' => 'رد', 'debit' => 50, 'credit' => 0,
            'kind' => 'refund', 'source_type' => ClientReturn::class, 'source_id' => $ret->id];
        Transaction::create($row);
        $this->expectException(QueryException::class);
        Transaction::create($row);
    }

    public function test_field_collections_on_visits_stay_unrestricted_by_the_constraint(): void
    {
        $this->fieldCollection(10);
        $this->fieldCollection(10);
        $this->assertSame(2, Transaction::where('kind', 'collection')->count());
    }

    public function test_link_cash_is_idempotent_and_never_exceeds_the_document(): void
    {
        $inv = $this->invoice(100);
        $a = Settlement::linkCash($inv);
        $b = Settlement::linkCash($inv);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Transaction::where('source_id', $inv->id)->where('source_type', Invoice::class)->where('kind', 'collection')->count());
        $this->assertEqualsWithDelta(0.0, (float) Settlement::state($inv)['open'], 0.001);

        $inv2 = $this->invoice(100);
        $this->expectException(Rejected::class);
        Settlement::linkCash($inv2, amount: 150);
    }

    public function test_coverage_is_fifo_with_linked_cash_first(): void
    {
        $a = $this->invoice(100, '2026-10-01');
        $b = $this->invoice(80, '2026-10-02');
        $c = $this->invoice(50, '2026-10-03');
        Settlement::linkCash($c);            // كاش مع الفاتورة بتاعته بس
        $this->fieldCollection(130);          // ← الأقدم: أ كلها + ٣٠ من ب

        $cov = Settlement::coverage($this->client->id);
        $this->assertSame('paid', $cov[Invoice::class.'#'.$a->id]['state']);
        $this->assertSame('partial', $cov[Invoice::class.'#'.$b->id]['state']);
        $this->assertEqualsWithDelta(50.0, $cov[Invoice::class.'#'.$b->id]['open'], 0.001);
        $this->assertSame('paid', $cov[Invoice::class.'#'.$c->id]['state']);
    }

    public function test_retro_cash_is_refused_for_paid_or_partly_paid_invoices_and_allowed_for_open_ones(): void
    {
        $paid = $this->invoice(100, '2026-10-01');
        $partly = $this->invoice(100, '2026-10-02');
        $open = $this->invoice(100, '2026-10-03');
        $this->fieldCollection(140);   // أ كلها + ٤٠ من ب

        foreach ([$paid, $partly] as $inv) {
            $this->actingAs($this->admin)->post(route('ops.invoices.payment', $inv))->assertSessionHasErrors('payment');
            $this->assertSame('credit', $inv->fresh()->payment);
        }
        $this->actingAs($this->admin)->post(route('ops.invoices.payment', $open))->assertSessionHasNoErrors();
        $this->assertSame('cash', $open->fresh()->payment);
        // ودوسة تانية بترجّعها آجل — وتالتة كاش تاني من غير صف زيادة
        $this->actingAs($this->admin)->post(route('ops.invoices.payment', $open));
        $this->actingAs($this->admin)->post(route('ops.invoices.payment', $open));
        $this->assertSame(1, Transaction::where('source_type', Invoice::class)->where('source_id', $open->id)->where('kind', 'collection')->count());
        $this->assertEqualsWithDelta(300 - 140 - 100, (float) $this->client->fresh()->balance, 0.001);
    }

    public function test_po_cash_link_goes_through_the_same_service(): void
    {
        $po = PurchaseOrder::create(['number' => 'PO-'.random_int(10000, 99999), 'client_id' => $this->client->id, 'status' => 'delivered',
            'approval_status' => 'approved', 'total' => 60, 'tax_total' => 0, 'grand_total' => 60]);
        Settlement::linkCash($po, amount: 60, tax: 0.0);
        Settlement::linkCash($po, amount: 60, tax: 0.0);
        $this->assertSame(1, Transaction::where('source_type', PurchaseOrder::class)->where('source_id', $po->id)->where('kind', 'collection')->count());
    }

    public function test_manual_collection_refuses_duplicates_and_over_collection_unless_confirmed(): void
    {
        $this->invoice(100);
        $date = Carbon::parse('2026-11-13 12:00');
        ManualCollection::record(actor: $this->admin, rep: null, client: $this->client, date: $date, amount: 40, method: Transaction::METHOD_CASH);

        try {
            ManualCollection::record(actor: $this->admin, rep: null, client: $this->client, date: $date, amount: 40, method: Transaction::METHOD_CASH);
            $this->fail('duplicate accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        try {
            ManualCollection::record(actor: $this->admin, rep: null, client: $this->client, date: $date, amount: 500, method: Transaction::METHOD_CASH);
            $this->fail('over-collection accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('60.00', $e->errors()['amount'][0]);
        }
        // بالتأكيد الصريح بيتسجّل
        ManualCollection::record(actor: $this->admin, rep: null, client: $this->client, date: $date, amount: 40, method: Transaction::METHOD_CASH, allowDuplicate: true);
        ManualCollection::record(actor: $this->admin, rep: null, client: $this->client, date: $date->copy()->addDay(), amount: 500, method: Transaction::METHOD_CASH, allowAdvance: true);
        $this->assertSame(3, Transaction::where('kind', 'collection')->count());

        // من الشاشة: رسالة خطأ في الفورم مش ٥٠٠
        $this->actingAs($this->admin)->post(route('erp.clients.collect', $this->client), ['amount' => 40, 'method' => 'cash', 'date' => '2026-11-13'])
            ->assertSessionHasErrors('amount');
    }
}
