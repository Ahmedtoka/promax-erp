<?php

namespace Tests\Feature\Gl;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Gl\CostCenterResolver;
use App\Services\Gl\Ledger;
use App\Services\Gl\Rules;
use Database\Seeders\GlSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * مراكز التكلفة (خطة القفل ١٩/١٠) — بُعد على كل سطر قيد، مش حسابات
 * فرعية. الشركة ← المصنع ← الخطوط، والشركة ← الفروع.
 */
class CostCenterTest extends TestCase
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

    private function center(string $code, string $kind, ?CostCenter $parent = null, array $attrs = []): CostCenter
    {
        return CostCenter::create(array_merge([
            'code' => $code, 'name' => $code, 'name_en' => $code, 'kind' => $kind,
            'parent_id' => $parent?->id, 'active' => true,
        ], $attrs));
    }

    public function test_company_is_a_single_root(): void
    {
        $a = CostCenter::company();
        $b = CostCenter::company();

        $this->assertSame($a->id, $b->id);
        $this->assertNull($a->parent_id);
        $this->assertSame('company', $a->kind);
        $this->assertSame(1, CostCenter::where('code', 'CO')->count());
    }

    public function test_maadi_is_a_branch_under_company(): void
    {
        $m = CostCenter::maadi();

        $this->assertSame('MAADI', $m->code);
        $this->assertSame('branch', $m->kind);
        $this->assertSame(CostCenter::company()->id, $m->parent_id);
        $this->assertSame($m->id, CostCenter::maadi()->id);
    }

    public function test_for_warehouse_prefers_own_then_center_row_then_branch(): void
    {
        $fac = $this->center('FAC', 'factory', CostCenter::company());
        $branch = Branch::create(['code' => 'BR-X', 'name' => 'فرع', 'active' => true]);
        $brCenter = $this->center('BRX', 'branch', CostCenter::company(), ['branch_id' => $branch->id]);

        $own = $this->makeWarehouse();
        $own->update(['cost_center_id' => $fac->id]);

        $byRow = $this->makeWarehouse();
        $rowCenter = $this->center('WHC', 'branch', CostCenter::company(), ['warehouse_id' => $byRow->id]);

        $byBranch = $this->makeWarehouse();
        $byBranch->update(['branch_id' => $branch->id]);

        $this->assertSame($fac->id, CostCenter::forWarehouse($own->fresh())?->id);
        $this->assertSame($rowCenter->id, CostCenter::forWarehouse($byRow)?->id);
        $this->assertSame($brCenter->id, CostCenter::forWarehouse($byBranch->fresh())?->id);
        $this->assertNull(CostCenter::forWarehouse($this->makeWarehouse()));
        $this->assertNull(CostCenter::forWarehouse(null));
    }

    public function test_lines_are_line_children_only(): void
    {
        $fac = $this->center('FAC', 'factory', CostCenter::company());
        $l1 = $this->center('FAC-L1', 'line', $fac);
        $this->center('FAC-X', 'branch', $fac);
        $this->center('OTHER-L', 'line', CostCenter::company());

        $this->assertSame([$l1->id], $fac->lines()->pluck('id')->all());
    }

    public function test_post_persists_cost_center_from_rule_lines(): void
    {
        $fac = $this->center('FAC', 'factory', CostCenter::company());
        $cash = GlAccount::findKey('cash_main');
        $rent = GlAccount::findKey('expense_rent');

        $this->app->instance(Rules::class, new class($rent->id, $cash->id, $fac->id) extends Rules
        {
            public function __construct(private int $dr, private int $cr, private int $cc) {}

            public function linesFor(Model $source): array
            {
                return ['rule' => 'test', 'memo' => 'm', 'needs_review' => false, 'lines' => [
                    ['account_id' => $this->dr, 'debit' => 50.0, 'credit' => 0.0, 'cost_center_id' => $this->cc],
                    ['account_id' => $this->cr, 'debit' => 0.0, 'credit' => 50.0],
                ]];
            }
        });
        $this->app->forgetInstance(Ledger::class);

        $source = $this->makeClient();
        $entry = app(Ledger::class)->post($source);

        $this->assertSame($fac->id, $entry->lines->firstWhere('account_id', $rent->id)->cost_center_id);
        $this->assertNull($entry->lines->firstWhere('account_id', $cash->id)->cost_center_id);
    }

    public function test_manual_entry_persists_cost_center(): void
    {
        $fac = $this->center('FAC', 'factory', CostCenter::company());

        $e = app(Ledger::class)->manual(today(), 'إيجار', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0, 'cost_center_id' => $fac->id],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ], $this->makeAdmin());

        $this->assertSame([$fac->id, null], $e->lines->sortBy('id')->pluck('cost_center_id')->all());
    }

    public function test_reverse_keeps_cost_center(): void
    {
        $fac = $this->center('FAC', 'factory', CostCenter::company());
        $admin = $this->makeAdmin();
        $e = app(Ledger::class)->manual(today(), 'إيجار', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0, 'cost_center_id' => $fac->id],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ], $admin);

        $rev = app(Ledger::class)->reverse($e, $admin);

        $rentLine = $rev->lines->firstWhere('account_id', GlAccount::findKey('expense_rent')->id);
        $this->assertSame($fac->id, $rentLine->cost_center_id);
        $this->assertSame(1000.0, (float) $rentLine->credit);
    }

    public function test_closed_period_correction_keeps_cost_center(): void
    {
        $fac = $this->center('FAC', 'factory', CostCenter::company());
        $admin = $this->makeAdmin();
        $e = app(Ledger::class)->manual(Carbon::parse('2026-07-15'), 'إيجار', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0, 'cost_center_id' => $fac->id],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ], $admin);
        GlPeriod::close('2026-07', $admin);
        $cashLine = $e->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $fixed = app(Ledger::class)->overrideAccount($cashLine, GlAccount::findKey('bank'), $admin);

        $this->assertSame(3, GlEntry::count());
        $this->assertSame($fac->id, $fixed->lines->firstWhere('account_id', GlAccount::findKey('expense_rent')->id)->cost_center_id);
    }

    // ═══════════════ CostCenterResolver ═══════════════

    /** @return array{0: Warehouse, 1: CostCenter} */
    private function factoryWarehouse(): array
    {
        $fac = $this->center('FAC', 'factory', CostCenter::company());
        $wh = $this->makeWarehouse();
        $wh->update(['cost_center_id' => $fac->id]);

        return [$wh->fresh(), $fac];
    }

    /** @return array{0: Warehouse, 1: CostCenter} */
    private function branchWarehouse(): array
    {
        $br = $this->center('NASR', 'branch', CostCenter::company());
        $wh = $this->makeWarehouse();
        $wh->update(['cost_center_id' => $br->id]);

        return [$wh->fresh(), $br];
    }

    public function test_resolver_uses_the_source_warehouse_center(): void
    {
        [$wh, $br] = $this->branchWarehouse();
        $po = \App\Models\PurchaseOrder::create([
            'number' => 'PO-CC-1', 'client_id' => $this->makeClient()->id, 'status' => 'pending',
            'warehouse_id' => $wh->id, 'total' => 0, 'tax_total' => 0, 'grand_total' => 0,
        ]);

        $this->assertSame($br->id, CostCenterResolver::for($po));
    }

    public function test_a_sale_out_of_a_factory_warehouse_still_counts_for_maadi(): void
    {
        // التصميم §٣.١.٢: البيع للمعادي دلوقتي — المصنع بيبيع داخلي بس (Lane 9)
        [$wh] = $this->factoryWarehouse();
        $po = \App\Models\PurchaseOrder::create([
            'number' => 'PO-CC-F', 'client_id' => $this->makeClient()->id, 'status' => 'pending',
            'warehouse_id' => $wh->id, 'total' => 0, 'tax_total' => 0, 'grand_total' => 0,
        ]);

        $this->assertSame(CostCenter::maadi()->id, CostCenterResolver::for($po));
    }

    public function test_a_return_follows_its_custody_warehouse(): void
    {
        [$wh, $br] = $this->branchWarehouse();
        $rep = $this->makeRep();
        $custody = \App\Models\Custody::create(['user_id' => $rep->id, 'warehouse_id' => $wh->id, 'date' => today(), 'status' => 'open']);
        $ret = new \App\Models\ClientReturn(['custody_id' => $custody->id]);

        $this->assertSame($br->id, CostCenterResolver::for($ret));
    }

    public function test_a_transaction_pointing_at_a_missing_class_does_not_throw(): void
    {
        $tx = new \App\Models\Transaction(['kind' => 'sale']);
        $tx->forceFill(['source_type' => 'App\Models\GoneModel', 'source_id' => 5]);

        $this->assertSame(CostCenter::maadi()->id, CostCenterResolver::for($tx));
    }

    public function test_resolver_follows_invoice_batch_warehouse(): void
    {
        [$wh, $fac] = $this->branchWarehouse();
        $p = $this->makeProduct();
        $batch = \App\Models\Batch::create([
            'product_id' => $p->id, 'warehouse_id' => $wh->id, 'batch_no' => 'B-CC', 'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6), 'qty_received' => 5, 'qty_remaining' => 5, 'cost' => 10,
        ]);
        $inv = \App\Models\Invoice::create([
            'number' => 'INV-88001', 'client_id' => $this->makeClient()->id, 'user_id' => $this->makeRep()->id,
            'payment' => 'credit', 'subtotal' => 20, 'discount' => 0, 'total' => 20, 'tax_total' => 0, 'grand_total' => 20,
        ]);
        \App\Models\InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $p->id, 'batch_id' => $batch->id, 'qty' => 1,
            'list_price' => 20, 'price' => 20, 'unit_cost' => 10, 'total' => 20, 'tax_rate' => 0, 'tax' => 0]);

        $this->assertSame($fac->id, CostCenterResolver::for($inv));
    }

    public function test_resolver_follows_transaction_source(): void
    {
        [$wh, $fac] = $this->branchWarehouse();
        $client = $this->makeClient();
        $po = \App\Models\PurchaseOrder::create([
            'number' => 'PO-CC-2', 'client_id' => $client->id, 'status' => 'delivered',
            'warehouse_id' => $wh->id, 'total' => 100, 'tax_total' => 0, 'grand_total' => 100,
        ]);
        $tx = \App\Models\Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'PO', 'debit' => 100,
            'credit' => 0, 'kind' => 'sale', 'source_type' => $po->getMorphClass(), 'source_id' => $po->id]);

        $this->assertSame($fac->id, CostCenterResolver::for($tx));
    }

    public function test_sales_without_a_warehouse_fall_back_to_maadi(): void
    {
        $client = $this->makeClient();
        $sale = \App\Models\Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 's', 'debit' => 10, 'credit' => 0, 'kind' => 'sale']);
        $coll = \App\Models\Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'c', 'debit' => 0, 'credit' => 10, 'kind' => 'collection', 'method' => 'cash']);

        $this->assertSame(CostCenter::maadi()->id, CostCenterResolver::for($sale));
        $this->assertSame(CostCenter::maadi()->id, CostCenterResolver::for($coll));
    }

    public function test_supplier_and_expense_without_warehouse_resolve_null(): void
    {
        $sup = \App\Models\Supplier::create(['code' => 'SUP-CC', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $st = $sup->post('invoice', today()->toDateString(), 0, 500, 'فاتورة');
        $exp = new \App\Models\Expense(['amount' => 10, 'paid_from' => 'cash_main']);

        $this->assertNull(CostCenterResolver::for($st));
        $this->assertNull(CostCenterResolver::for($exp));
    }
}
