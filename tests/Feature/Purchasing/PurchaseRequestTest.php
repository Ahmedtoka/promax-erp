<?php

namespace Tests\Feature\Purchasing;

use App\Models\CostCenter;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseRequestTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(string $en = 'Sup'): Supplier
    {
        return Supplier::create(['code' => Supplier::nextCode(), 'name' => 'مورد '.$en, 'name_en' => $en, 'active' => true]);
    }

    private function request(?int $by = null, ?CostCenter $cc = null, string $status = 'open'): PurchaseRequest
    {
        return PurchaseRequest::create([
            'number' => PurchaseRequest::nextNumber(),
            'product_id' => $this->makeProduct(['kind' => 'raw'])->id,
            'qty' => 100,
            'requested_by' => $by ?? $this->makeAdmin(['role' => 'warehouse_keeper'])->id,
            'cost_center_id' => $cc?->id,
            'status' => $status,
        ]);
    }

    private function quote(PurchaseRequest $r, Supplier $s, string $price, ?int $lead = null): PurchaseQuote
    {
        return PurchaseQuote::create([
            'purchase_request_id' => $r->id, 'supplier_id' => $s->id,
            'unit_price' => $price, 'lead_days' => $lead, 'status' => 'pending',
        ]);
    }

    public function test_keeper_raises_a_request_for_a_raw_material_with_cost_center(): void
    {
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper']);
        $cc = CostCenter::company();
        $p = $this->makeProduct(['kind' => 'raw']);

        $this->actingAs($keeper)->get(route('erp.purchase_requests'))->assertOk();
        $this->actingAs($keeper)->post(route('erp.purchase_requests.store'), [
            'product_id' => $p->id, 'qty' => 250, 'needed_by' => '2026-11-01',
            'cost_center_id' => $cc->id, 'notes' => 'urgent',
        ])->assertRedirect();

        $r = PurchaseRequest::firstOrFail();
        $this->assertSame('PR-1001', $r->number);
        $this->assertSame('open', $r->status);
        $this->assertSame($keeper->id, $r->requested_by);
        $this->assertSame($cc->id, $r->cost_center_id);
        $this->assertSame(250, (int) $r->qty);

        $this->actingAs($keeper)->get(route('erp.purchase_requests.show', $r))->assertOk()->assertSee('PR-1001');
    }

    public function test_manager_adds_three_quotes_and_comparison_shows_them_side_by_side(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $r = $this->request();
        foreach ([['Alpha', '12.00', 9], ['Beta', '10.50', 5], ['Gamma', '11.00', 2]] as [$n, $price, $lead]) {
            $this->actingAs($manager)->post(route('erp.purchase_requests.quotes.store', $r), [
                'supplier_id' => $this->supplier($n)->id, 'unit_price' => $price, 'lead_days' => $lead,
                'payment_terms' => '30 days',
            ])->assertRedirect();
        }

        $this->assertSame('quoting', $r->fresh()->status);
        $this->assertSame(3, $r->quotes()->count());

        $res = $this->actingAs($manager)->get(route('erp.purchase_requests.show', $r))->assertOk();
        $res->assertSee('Alpha')->assertSee('Beta')->assertSee('Gamma');
        $res->assertSee(__('supplier.cheapest'))->assertSee(__('supplier.fastest'));
        // 100 x 10.50 line total of the cheapest
        $res->assertSee('1,050.00');
    }

    public function test_admin_approves_a_quote_and_an_order_is_created_at_the_quote_price(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $cc = CostCenter::company();
        $r = $this->request(null, $cc);
        $q1 = $this->quote($r, $this->supplier('A'), '12.00', 4);
        $q2 = $this->quote($r, $this->supplier('B'), '10.50');
        $r->update(['status' => 'quoting']);

        $this->actingAs($admin)->post(route('erp.purchase_quotes.approve', $q2), ['warehouse_id' => $wh->id])
            ->assertRedirect();

        $o = SupplierOrder::firstOrFail();
        $this->assertSame('open', $o->status);
        $this->assertSame('approved', $o->approval_status);
        $this->assertSame($admin->id, (int) $o->approved_by);
        $this->assertNull($o->approval_reasons);
        $this->assertSame($q2->supplier_id, (int) $o->supplier_id);
        $this->assertSame($wh->id, (int) $o->warehouse_id);
        $this->assertSame($cc->id, (int) $o->cost_center_id);
        $this->assertSame($r->id, (int) $o->purchase_request_id);
        $this->assertSame($q2->id, (int) $o->purchase_quote_id);
        $this->assertSame('1050.00', (string) $o->total);
        $item = $o->items()->firstOrFail();
        $this->assertSame('10.50', (string) $item->unit_cost);
        $this->assertSame(100, (int) $item->qty);

        $this->assertSame('approved', $q2->fresh()->status);
        $this->assertSame('rejected', $q1->fresh()->status);
        $r->refresh();
        $this->assertSame('ordered', $r->status);
        $this->assertSame($o->id, (int) $r->supplier_order_id);

        $this->actingAs($admin)->get(route('erp.purchasing.show', $o))->assertOk()->assertSee($r->number);
    }

    public function test_quote_lead_days_set_expected_date(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $r = $this->request();
        $q = $this->quote($r, $this->supplier('A'), '5.00', 6);

        $this->actingAs($admin)->post(route('erp.purchase_quotes.approve', $q), ['warehouse_id' => $wh->id]);

        $this->assertSame(now()->addDays(6)->toDateString(), SupplierOrder::firstOrFail()->expected_on->toDateString());
    }

    public function test_approving_twice_creates_one_order(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $r = $this->request();
        $q1 = $this->quote($r, $this->supplier('A'), '12.00');
        $q2 = $this->quote($r, $this->supplier('B'), '10.50');

        $this->actingAs($admin)->post(route('erp.purchase_quotes.approve', $q1), ['warehouse_id' => $wh->id]);
        $this->actingAs($admin)->post(route('erp.purchase_quotes.approve', $q1), ['warehouse_id' => $wh->id])
            ->assertSessionHasErrors();
        $this->actingAs($admin)->post(route('erp.purchase_quotes.approve', $q2), ['warehouse_id' => $wh->id])
            ->assertSessionHasErrors();

        $this->assertSame(1, SupplierOrder::count());
    }

    public function test_manager_cannot_approve_a_quote(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $wh = $this->makeWarehouse();
        $r = $this->request();
        $q = $this->quote($r, $this->supplier('A'), '12.00');

        $this->actingAs($manager)->post(route('erp.purchase_quotes.approve', $q), ['warehouse_id' => $wh->id])
            ->assertForbidden();
        $this->assertSame(0, SupplierOrder::count());
    }

    public function test_keeper_cannot_add_quotes_and_accountant_is_locked_out(): void
    {
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper']);
        $acct = $this->makeAdmin(['role' => 'accountant']);
        $bm = $this->makeAdmin(['role' => 'branch_manager']);
        $r = $this->request();

        $this->actingAs($keeper)->post(route('erp.purchase_requests.quotes.store', $r), [
            'supplier_id' => $this->supplier()->id, 'unit_price' => 5,
        ])->assertForbidden();
        $this->actingAs($acct)->get(route('erp.purchase_requests'))->assertForbidden();
        $this->actingAs($bm)->get(route('erp.purchase_requests.show', $r))->assertForbidden();
    }

    public function test_duplicate_supplier_quote_is_rejected(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $r = $this->request();
        $s = $this->supplier();
        $this->quote($r, $s, '9.00');

        $this->actingAs($manager)->post(route('erp.purchase_requests.quotes.store', $r), [
            'supplier_id' => $s->id, 'unit_price' => 8,
        ])->assertSessionHasErrors();
        $this->assertSame(1, $r->quotes()->count());
    }

    public function test_quote_attachment_is_stored(): void
    {
        Storage::fake('public');
        $manager = $this->makeAdmin(['role' => 'manager']);
        $r = $this->request();

        $this->actingAs($manager)->post(route('erp.purchase_requests.quotes.store', $r), [
            'supplier_id' => $this->supplier()->id, 'unit_price' => 8,
            'attachment' => UploadedFile::fake()->create('q.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $q = PurchaseQuote::firstOrFail();
        $this->assertNotNull($q->attachment_path);
        Storage::disk('public')->assertExists($q->attachment_path);
    }

    public function test_quotes_only_while_open_or_quoting(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $r = $this->request(null, null, 'ordered');

        $this->actingAs($manager)->post(route('erp.purchase_requests.quotes.store', $r), [
            'supplier_id' => $this->supplier()->id, 'unit_price' => 8,
        ])->assertSessionHasErrors();
        $this->assertSame(0, $r->quotes()->count());
    }

    public function test_cancel_rules(): void
    {
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper']);
        $other = $this->makeAdmin(['role' => 'warehouse_keeper']);
        $manager = $this->makeAdmin(['role' => 'manager']);

        $mine = $this->request($keeper->id);
        $this->actingAs($other)->post(route('erp.purchase_requests.cancel', $mine))->assertForbidden();
        $this->assertSame('open', $mine->fresh()->status);
        $this->actingAs($keeper)->post(route('erp.purchase_requests.cancel', $mine))->assertRedirect();
        $this->assertSame('cancelled', $mine->fresh()->status);

        $theirs = $this->request($keeper->id);
        $this->actingAs($manager)->post(route('erp.purchase_requests.cancel', $theirs))->assertRedirect();
        $this->assertSame('cancelled', $theirs->fresh()->status);

        $done = $this->request($keeper->id, null, 'ordered');
        $this->actingAs($manager)->post(route('erp.purchase_requests.cancel', $done))->assertSessionHasErrors();
        $this->assertSame('ordered', $done->fresh()->status);
    }

    public function test_requests_list_exports_csv(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $r = $this->request();

        $res = $this->actingAs($manager)->get(route('erp.purchase_requests', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
        $this->assertStringContainsString($r->number, $res->streamedContent());
    }
}
