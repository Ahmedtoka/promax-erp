<?php

namespace Tests\Feature\Purchasing;

use App\Models\Batch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierContract;
use App\Models\SupplierOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(): Supplier
    {
        return Supplier::create(['code' => Supplier::nextCode(), 'name' => 'مورد', 'name_en' => 'Sup', 'active' => true]);
    }

    private function contract(Supplier $s, Product $p, float $price, string $from, string $to): SupplierContract
    {
        $c = SupplierContract::create([
            'number' => SupplierContract::nextNumber(), 'supplier_id' => $s->id,
            'starts_on' => $from, 'ends_on' => $to, 'active' => true,
        ]);
        $c->items()->create(['product_id' => $p->id, 'unit_price' => $price]);

        return $c;
    }

    /** an earlier received order makes supplier+product "known" */
    private function history(Supplier $s, Product $p, string $status = 'received'): SupplierOrder
    {
        $wh = $this->makeWarehouse();
        $o = SupplierOrder::create([
            'number' => SupplierOrder::nextNumber(), 'supplier_id' => $s->id, 'warehouse_id' => $wh->id,
            'status' => $status, 'ordered_on' => '2025-01-01', 'total' => 100, 'created_by' => $this->makeAdmin()->id,
        ]);
        $o->items()->create(['product_id' => $p->id, 'qty' => 10, 'unit_cost' => 10, 'received_qty' => 10]);

        return $o;
    }

    private function place($user, Supplier $s, Product $p, float $cost, string $on = '2026-05-10')
    {
        $wh = $this->makeWarehouse();

        return $this->actingAs($user)->post(route('erp.purchasing.store'), [
            'supplier_id' => $s->id, 'warehouse_id' => $wh->id, 'ordered_on' => $on,
            'qty' => [$p->id => 5], 'cost' => [$p->id => $cost],
        ]);
    }

    private function last(): SupplierOrder
    {
        return SupplierOrder::latest('id')->firstOrFail();
    }

    public function test_known_supplier_known_material_without_contract_keeps_direct_flow(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $p = $this->makeProduct();
        $this->history($s, $p);

        $this->place($manager, $s, $p, 12)->assertRedirect();
        $o = $this->last();
        $this->assertSame('open', $o->status);
        $this->assertNull($o->approval_status);
        $this->assertNull($o->approval_reasons);
        $this->assertNull($o->supplier_contract_id);
    }

    public function test_contract_price_within_validity_needs_no_approval(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $p = $this->makeProduct();
        $c = $this->contract($s, $p, 10.00, '2026-01-01', '2026-12-31');

        $this->place($manager, $s, $p, 10.00)->assertRedirect();
        $o = $this->last();
        $this->assertSame('open', $o->status);
        $this->assertNull($o->approval_status);
        $this->assertSame($c->id, $o->supplier_contract_id);
        $this->assertSame('10.00', $o->items()->first()->contract_price);
    }

    public function test_price_above_contract_waits_for_admin_and_cannot_be_received(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $p = $this->makeProduct();
        $this->contract($s, $p, 10.00, '2026-01-01', '2026-12-31');

        $this->place($manager, $s, $p, 10.01)->assertRedirect();
        $o = $this->last();
        $this->assertSame('pending', $o->status);
        $this->assertSame('pending', $o->approval_status);
        $this->assertSame('above_contract', $o->approval_reasons[0]['code']);

        $this->actingAs($manager)->post(route('erp.purchasing.receive', $o), [
            'lines' => [$p->id => ['qty' => 5, 'batch_no' => 'B1']],
        ])->assertSessionHasErrors(['lines' => __('supplier.order_not_open')]);
        $this->assertSame(0, Batch::count());
    }

    public function test_expired_contract_waits_for_admin(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $p = $this->makeProduct();
        $this->contract($s, $p, 10.00, '2025-01-01', '2025-12-31');
        $this->history($s, $p);

        $this->place($manager, $s, $p, 10)->assertRedirect();
        $o = $this->last();
        $this->assertSame('pending', $o->status);
        $this->assertSame('contract_expired', $o->approval_reasons[0]['code']);
    }

    public function test_new_supplier_waits_for_admin(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $p = $this->makeProduct();

        $this->place($manager, $s, $p, 10)->assertRedirect();
        $o = $this->last();
        $this->assertSame('pending', $o->status);
        $this->assertContains('new_supplier', array_column($o->approval_reasons, 'code'));
    }

    public function test_new_material_for_known_supplier_waits_for_admin(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $old = $this->makeProduct();
        $new = $this->makeProduct();
        $this->history($s, $old);

        $this->place($manager, $s, $new, 10)->assertRedirect();
        $o = $this->last();
        $this->assertSame('pending', $o->status);
        $this->assertSame(['new_material'], array_column($o->approval_reasons, 'code'));
    }

    public function test_admin_created_order_is_approved_on_creation_with_reasons_logged(): void
    {
        $admin = $this->makeAdmin();
        $s = $this->supplier();
        $p = $this->makeProduct();

        $this->place($admin, $s, $p, 10)->assertRedirect();
        $o = $this->last();
        $this->assertSame('open', $o->status);
        $this->assertSame('approved', $o->approval_status);
        $this->assertSame($admin->id, $o->approved_by);
        $this->assertNotNull($o->approved_at);
        $this->assertContains('new_supplier', array_column($o->approval_reasons, 'code'));
    }

    private function pendingOrder(): array
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $p = $this->makeProduct();
        $this->place($manager, $s, $p, 10);

        return [$this->last(), $p, $manager];
    }

    public function test_admin_approves_then_it_can_be_received(): void
    {
        [$o, $p, $manager] = $this->pendingOrder();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.purchasing.approve', $o))->assertRedirect();
        $o->refresh();
        $this->assertSame('open', $o->status);
        $this->assertSame('approved', $o->approval_status);
        $this->assertSame($admin->id, $o->approved_by);

        $this->actingAs($manager)->post(route('erp.purchasing.receive', $o), [
            'lines' => [$p->id => ['qty' => 5, 'batch_no' => 'B1']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Batch::count());

        // no longer pending
        $this->actingAs($admin)->post(route('erp.purchasing.approve', $o))->assertSessionHasErrors();
    }

    public function test_admin_rejects_it_becomes_cancelled(): void
    {
        [$o] = $this->pendingOrder();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.purchasing.reject', $o), ['note' => 'too dear'])->assertRedirect();
        $o->refresh();
        $this->assertSame('cancelled', $o->status);
        $this->assertSame('rejected', $o->approval_status);
        $this->assertStringContainsString('too dear', (string) $o->notes);

        // كارت الموافقة بيقول «اترفض» مش «اتوافق»
        $res = $this->actingAs($admin)->get(route('erp.purchasing.show', $o))->assertOk();
        $res->assertSee(trim(explode(':name', __('supplier.rejected_by_on'))[0]));
        $res->assertDontSee(trim(explode(':name', __('supplier.approved_by_on'))[0]));
    }

    public function test_reject_note_goes_on_a_new_line_after_existing_notes(): void
    {
        [$o] = $this->pendingOrder();
        $o->update(['notes' => 'first line']);

        $this->actingAs($this->makeAdmin())->post(route('erp.purchasing.reject', $o), ['note' => 'too dear']);

        $notes = (string) $o->fresh()->notes;
        $this->assertStringStartsWith("first line\n", $notes);
        $this->assertStringContainsString('too dear', $notes);
    }

    public function test_manager_cannot_approve(): void
    {
        [$o, , $manager] = $this->pendingOrder();

        $this->actingAs($manager)->post(route('erp.purchasing.approve', $o))->assertForbidden();
        $this->actingAs($manager)->post(route('erp.purchasing.reject', $o))->assertForbidden();
        $this->assertSame('pending', $o->fresh()->status);
    }

    public function test_pending_order_cannot_be_cancelled_or_closed_by_manager(): void
    {
        [$o, , $manager] = $this->pendingOrder();
        $this->actingAs($manager)->post(route('erp.purchasing.cancel', $o))->assertSessionHasErrors();
        $this->actingAs($manager)->post(route('erp.purchasing.close', $o))->assertSessionHasErrors();
        $this->assertSame('pending', $o->fresh()->status);
    }

    public function test_orders_list_filters_pending(): void
    {
        [$o, $p, $manager] = $this->pendingOrder();
        $s2 = $this->supplier();
        $this->history($s2, $p);
        $this->place($manager, $s2, $p, 10);
        $direct = $this->last();
        $this->assertSame('open', $direct->status);

        $res = $this->actingAs($manager)->get(route('erp.purchasing', ['approval' => 'pending']))->assertOk();
        $ids = $res->viewData('orders')->pluck('id')->all();
        $this->assertSame([$o->id], $ids);
    }

    public function test_order_page_shows_reasons_and_admin_buttons(): void
    {
        [$o, , $manager] = $this->pendingOrder();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('erp.purchasing.show', $o))->assertOk()
            ->assertSee(route('erp.purchasing.approve', $o), false);
        $this->actingAs($manager)->get(route('erp.purchasing.show', $o))->assertOk()
            ->assertDontSee(route('erp.purchasing.approve', $o), false);
    }
}
