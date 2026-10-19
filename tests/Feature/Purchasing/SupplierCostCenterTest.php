<?php

namespace Tests\Feature\Purchasing;

use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierPayment;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use App\Services\Gl\CostCenterResolver;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierCostCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['code' => Supplier::nextCode(), 'name' => 'مورد', 'name_en' => 'Sup', 'active' => true]);
    }

    private function center(string $code): CostCenter
    {
        return CostCenter::create(['code' => $code, 'name' => $code, 'name_en' => $code, 'kind' => 'branch', 'active' => true]);
    }

    private function receivedOrder(?int $centerId = null): SupplierOrder
    {
        $p = $this->makeProduct();
        $wh = $this->makeWarehouse();
        $o = SupplierOrder::create([
            'number' => SupplierOrder::nextNumber(), 'supplier_id' => $this->supplier()->id, 'warehouse_id' => $wh->id,
            'status' => 'open', 'ordered_on' => today(), 'total' => 100, 'created_by' => $this->makeAdmin()->id,
            'cost_center_id' => $centerId,
        ]);
        $o->items()->create(['product_id' => $p->id, 'qty' => 10, 'unit_cost' => 10, 'received_qty' => 10]);

        return $o;
    }

    private function entryLines(string $kind): array
    {
        $st = SupplierTransaction::where('kind', $kind)->latest('id')->firstOrFail();
        $entry = GlEntry::where('source_type', $st->getMorphClass())->where('source_id', $st->id)->firstOrFail();

        return $entry->lines->all();
    }

    public function test_order_takes_its_warehouse_center_when_none_is_chosen(): void
    {
        $wh = $this->makeWarehouse();
        $cc = $this->center('WH1');
        $wh->update(['cost_center_id' => $cc->id]);
        $p = $this->makeProduct();

        $this->actingAs($this->makeAdmin())->post(route('erp.purchasing.store'), [
            'supplier_id' => $this->supplier()->id, 'warehouse_id' => $wh->id, 'ordered_on' => '2026-05-10',
            'qty' => [$p->id => 5], 'cost' => [$p->id => 10],
        ])->assertSessionHasNoErrors();

        $this->assertSame($cc->id, SupplierOrder::latest('id')->first()->cost_center_id);
    }

    public function test_order_takes_the_chosen_center(): void
    {
        $wh = $this->makeWarehouse();
        $chosen = $this->center('CH1');
        $p = $this->makeProduct();

        $this->actingAs($this->makeAdmin())->post(route('erp.purchasing.store'), [
            'supplier_id' => $this->supplier()->id, 'warehouse_id' => $wh->id, 'ordered_on' => '2026-05-10',
            'cost_center_id' => $chosen->id, 'qty' => [$p->id => 5], 'cost' => [$p->id => 10],
        ])->assertSessionHasNoErrors();

        $this->assertSame($chosen->id, SupplierOrder::latest('id')->first()->cost_center_id);
    }

    public function test_invoice_posts_gl_lines_with_the_order_center(): void
    {
        $cc = $this->center('ORD');
        $o = $this->receivedOrder($cc->id);

        $this->actingAs($this->makeAdmin())->post(route('erp.purchasing.invoice', $o), [
            'invoice_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($cc->id, SupplierInvoice::firstOrFail()->cost_center_id);
        $lines = $this->entryLines('invoice');
        $this->assertCount(2, $lines);
        foreach ($lines as $l) {
            $this->assertSame($cc->id, $l->cost_center_id);
        }
    }

    public function test_invoice_falls_back_to_the_order_warehouse_center(): void
    {
        $o = $this->receivedOrder(null);
        $cc = $this->center('WHX');
        $o->warehouse->update(['cost_center_id' => $cc->id]);

        $this->actingAs($this->makeAdmin())->post(route('erp.purchasing.invoice', $o), [
            'invoice_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($cc->id, SupplierInvoice::firstOrFail()->cost_center_id);
    }

    public function test_payment_posts_with_chosen_center_or_company(): void
    {
        $s = $this->supplier();
        $admin = $this->makeAdmin();
        $chosen = $this->center('PAY');
        $body = ['amount' => 50, 'paid_on' => today()->toDateString(), 'method' => 'cash'];

        $this->actingAs($admin)->post(route('erp.suppliers.pay', $s), $body + ['cost_center_id' => $chosen->id])
            ->assertSessionHasNoErrors();
        foreach ($this->entryLines('payment') as $l) {
            $this->assertSame($chosen->id, $l->cost_center_id);
        }

        $this->actingAs($admin)->post(route('erp.suppliers.pay', $s), $body)->assertSessionHasNoErrors();
        $this->assertSame(CostCenter::company()->id, SupplierPayment::latest('id')->first()->cost_center_id);
        foreach ($this->entryLines('payment') as $l) {
            $this->assertSame(CostCenter::company()->id, $l->cost_center_id);
        }
    }

    public function test_supplier_invoice_and_payment_still_post_to_payables(): void
    {
        $o = $this->receivedOrder($this->center('INV')->id);
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post(route('erp.purchasing.invoice', $o), ['invoice_date' => today()->toDateString()]);
        $this->actingAs($admin)->post(route('erp.suppliers.pay', $o->supplier), [
            'amount' => 30, 'paid_on' => today()->toDateString(), 'method' => 'cash',
        ]);

        $this->assertSame(70.0, GlAccount::findKey('payables')->balanceBetween(null, null));
        $this->assertTrue(app(Ledger::class)->invariants()['payables']['ok']);
    }

    public function test_resolver_ignores_sources_without_the_column(): void
    {
        $client = $this->makeClient();
        $t = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 100, 'credit' => 0, 'kind' => 'sale']);

        $this->assertSame(CostCenter::maadi()->id, CostCenterResolver::for($t));
    }

    public function test_resolver_reads_a_ledger_rows_source_document(): void
    {
        $cc = $this->center('SRC');
        $s = $this->supplier();
        $inv = SupplierInvoice::record([
            'supplier_id' => $s->id, 'invoice_date' => today(), 'subtotal' => 10, 'tax' => 0, 'total' => 10,
            'cost_center_id' => $cc->id, 'created_by' => $this->makeAdmin()->id,
        ]);
        $st = SupplierTransaction::where('source_id', $inv->id)->firstOrFail();

        $this->assertSame($cc->id, CostCenterResolver::for($st));
    }
}
