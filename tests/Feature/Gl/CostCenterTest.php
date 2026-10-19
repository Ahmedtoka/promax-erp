<?php

namespace Tests\Feature\Gl;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\Warehouse;
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
}
