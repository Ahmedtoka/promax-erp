<?php

namespace Tests\Feature\Gl;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Safe;
use App\Models\Warehouse;
use App\Services\Gl\FoundationSeed;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan promax:cost-centers-seed` — الشركة والمصنع والمعادي +
 * الخزنتين. بيضيف بس: تعديلات المالك بتعيش، والتشغيل التاني مابيعملش حاجة.
 */
class CostCentersSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
    }

    private function wh(string $code, string $type): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => $code, 'name_en' => $code, 'type' => $type, 'active' => true]);
    }

    public function test_seed_creates_company_factory_maadi_and_two_safes(): void
    {
        $r = FoundationSeed::run();

        $co = CostCenter::where('code', 'CO')->first();
        $this->assertSame('company', $co->kind);
        $this->assertSame(['factory', $co->id], [CostCenter::where('code', 'FAC')->value('kind'), (int) CostCenter::where('code', 'FAC')->value('parent_id')]);
        $this->assertSame(['branch', $co->id], [CostCenter::where('code', 'MAADI')->value('kind'), (int) CostCenter::where('code', 'MAADI')->value('parent_id')]);

        $main = Safe::where('code', 'MAADI-MAIN')->first();
        $bank = Safe::where('code', 'BANK-1')->first();
        $this->assertSame(['cash', 'bank'], [$main->kind, $bank->kind]);
        $this->assertSame(GlAccount::findKey('cash_main')->id, $main->gl_account_id);
        $this->assertSame(GlAccount::findKey('bank')->id, $bank->gl_account_id);
        $this->assertSame(CostCenter::where('code', 'MAADI')->value('id'), $main->cost_center_id);
        $this->assertEqualsCanonicalizing(['CO', 'FAC', 'MAADI', 'MAADI-MAIN', 'BANK-1'], $r['created']);
    }

    public function test_seed_links_factory_warehouses_to_fac_and_maadi_warehouse_to_maadi(): void
    {
        $f1 = $this->wh('TENTH', 'factory');
        $f2 = $this->wh('RAW', 'factory');
        $maadi = $this->wh('MAADI', 'branch');
        $br = Branch::create(['code' => 'MAADI', 'name' => 'المعادي', 'active' => true]);

        FoundationSeed::run();

        $fac = CostCenter::where('code', 'FAC')->value('id');
        $m = CostCenter::where('code', 'MAADI')->first();
        $this->assertSame([$fac, $fac], [$f1->fresh()->cost_center_id, $f2->fresh()->cost_center_id]);
        $this->assertSame($m->id, $maadi->fresh()->cost_center_id);
        $this->assertSame([$maadi->id, $br->id], [$m->warehouse_id, $m->branch_id]);
        $this->assertSame($m->id, CostCenter::forWarehouse($maadi->fresh())->id);
        $this->assertSame($br->id, Safe::where('code', 'MAADI-MAIN')->value('branch_id'));
    }

    public function test_seed_twice_creates_nothing_the_second_time(): void
    {
        $this->wh('TENTH', 'factory');
        FoundationSeed::run();
        $counts = [CostCenter::count(), Safe::count()];

        $r = FoundationSeed::run();

        $this->assertSame([], $r['created']);
        $this->assertSame([], $r['linked']);
        $this->assertSame($counts, [CostCenter::count(), Safe::count()]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->wh('TENTH', 'factory');

        $this->artisan('promax:cost-centers-seed', ['--dry-run' => true])
            ->expectsOutputToContain('CO')
            ->assertSuccessful();

        $this->assertSame(0, CostCenter::count());
        $this->assertSame(0, Safe::count());
        $this->assertNull(Warehouse::where('code', 'TENTH')->value('cost_center_id'));
    }

    public function test_seed_keeps_owner_edits(): void
    {
        $w = $this->wh('TENTH', 'factory');
        FoundationSeed::run();
        CostCenter::where('code', 'FAC')->update(['name' => 'مصنع العاشر']);
        $other = CostCenter::create(['code' => 'L1', 'name' => 'خط', 'kind' => 'line', 'parent_id' => CostCenter::where('code', 'FAC')->value('id')]);
        $w->update(['cost_center_id' => $other->id]);
        Safe::where('code', 'BANK-1')->update(['bank_name' => 'CIB', 'gl_account_id' => null]);

        FoundationSeed::run();

        $this->assertSame('مصنع العاشر', CostCenter::where('code', 'FAC')->value('name'));
        $this->assertSame($other->id, $w->fresh()->cost_center_id);
        $this->assertSame('CIB', Safe::where('code', 'BANK-1')->value('bank_name'));
        // الفاضي بس بيتملى
        $this->assertSame(GlAccount::findKey('bank')->id, Safe::where('code', 'BANK-1')->value('gl_account_id'));
    }

    public function test_seed_without_a_maadi_warehouse_creates_the_center_unlinked(): void
    {
        $this->wh('WHK', 'factory');

        $r = FoundationSeed::run();

        $this->assertNull(CostCenter::where('code', 'MAADI')->value('warehouse_id'));
        $this->assertNotEmpty(array_filter($r['unlinked'], fn ($l) => str_contains($l, 'MAADI')));

        $this->artisan('promax:cost-centers-seed', ['--dry-run' => true])
            ->expectsOutputToContain('MAADI')
            ->assertSuccessful();
    }

    public function test_maadi_warehouse_option_links_that_warehouse(): void
    {
        $fac = $this->wh('TENTH', 'factory');
        $whk = $this->wh('WHK', 'factory');

        $this->artisan('promax:cost-centers-seed', ['--maadi-warehouse' => 'WHK'])->assertSuccessful();

        $m = CostCenter::where('code', 'MAADI')->first();
        $this->assertSame($whk->id, $m->warehouse_id);
        $this->assertSame($m->id, $whk->fresh()->cost_center_id);
        $this->assertSame(CostCenter::where('code', 'FAC')->value('id'), $fac->fresh()->cost_center_id);
    }

    public function test_factory_option_limits_the_factory_warehouses(): void
    {
        $a = $this->wh('TENTH', 'factory');
        $b = $this->wh('ONLINE', 'factory');

        $this->artisan('promax:cost-centers-seed', ['--factory' => 'TENTH'])->assertSuccessful();

        $this->assertNotNull($a->fresh()->cost_center_id);
        $this->assertNull($b->fresh()->cost_center_id);
    }

    public function test_unknown_warehouse_code_fails_without_writing(): void
    {
        $this->artisan('promax:cost-centers-seed', ['--maadi-warehouse' => 'NOPE'])->assertFailed();
        $this->assertSame(0, CostCenter::count());
    }
}
