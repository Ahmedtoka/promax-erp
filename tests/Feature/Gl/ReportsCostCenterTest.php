<?php

namespace Tests\Feature\Gl;

use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use App\Services\Gl\Reports;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** التقارير المالية بفلتر مركز التكلفة (الخط ٣ — المركز + أحفاده) */
class ReportsCostCenterTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        $admin = $this->makeAdmin();
        $co = CostCenter::company();
        $maadi = CostCenter::maadi();
        $fac = CostCenter::create(['code' => 'FAC', 'name' => 'المصنع', 'kind' => 'factory', 'parent_id' => $co->id, 'active' => true]);

        // بيع = سطور على المعادي (Rules + CostCenterResolver)
        Transaction::create(['client_id' => $this->makeClient()->id, 'date' => '2026-08-05', 'memo' => 'sale',
            'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);
        // قيد يدوي على المصنع
        app(Ledger::class)->manual(Carbon::parse('2026-08-06'), 'مصروف مصنع', [
            ['account_id' => GlAccount::findKey('expense_purchases')->id, 'debit' => 300, 'credit' => 0, 'cost_center_id' => $fac->id],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 300, 'cost_center_id' => $fac->id],
        ], $admin);

        return [$admin, $co, $maadi, $fac];
    }

    public function test_trial_balance_filtered_by_center_shows_only_its_lines(): void
    {
        [, , $maadi, $fac] = $this->world();
        $r = app(Reports::class);
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-08-31');

        $all = $r->trialBalance($from, $to);
        $this->assertSame(1440.0, $all['totals']['debit']);

        $onFac = $r->trialBalance($from, $to, $fac->id);
        $this->assertSame(300.0, $onFac['totals']['debit']);
        $this->assertSame(300.0, $onFac['totals']['credit']);
        $this->assertCount(2, $onFac['rows']);

        $onMaadi = $r->trialBalance($from, $to, $maadi->id);
        $this->assertSame(1140.0, $onMaadi['totals']['debit']);
    }

    public function test_company_center_includes_children(): void
    {
        [, $co, , $fac] = $this->world();
        $r = app(Reports::class);
        $ids = $r->centerIds($co->id);
        $this->assertContains($fac->id, $ids);
        $this->assertContains(CostCenter::maadi()->id, $ids);

        $tb = $r->trialBalance(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $co->id);
        $this->assertSame(1440.0, $tb['totals']['debit']);
    }

    public function test_income_and_balance_sheet_accept_the_filter_and_export(): void
    {
        [$admin, , $maadi, $fac] = $this->world();
        $r = app(Reports::class);
        $inc = $r->income(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $fac->id);
        $this->assertSame(0.0, $inc['total_revenue']);
        $this->assertSame(300.0, $inc['total_expenses']);
        $incM = $r->income(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $maadi->id);
        $this->assertSame(1000.0, $incM['total_revenue']);

        $bs = $r->balanceSheet(Carbon::parse('2026-08-31'), $fac->id);
        $this->assertSame(-300.0, $bs['total_assets']);
        $this->assertSame(-300.0, $bs['retained']);

        $this->actingAs($admin)->get(route('gl.trial_balance', ['from' => '2026-08-01', 'to' => '2026-08-31', 'cost_center_id' => $fac->id]))
            ->assertOk()->assertSee(__('gl.all_centers'))->assertSee('FAC');
        $this->actingAs($admin)->get(route('gl.income', ['from' => '2026-08-01', 'to' => '2026-08-31', 'cost_center_id' => $fac->id]))
            ->assertOk()->assertSee(__('gl.cost_center'));
        $this->actingAs($admin)->get(route('gl.balance_sheet', ['to' => '2026-08-31', 'cost_center_id' => $fac->id]))
            ->assertOk()->assertSee(__('gl.cost_center'));

        $csv = $this->actingAs($admin)->get(route('gl.trial_balance', ['from' => '2026-08-01', 'to' => '2026-08-31', 'cost_center_id' => $fac->id, 'export' => 1]));
        $csv->assertOk();
        $this->assertStringContainsString('gl-trial-balance-FAC-', (string) $csv->headers->get('content-disposition'));
        $this->actingAs($admin)->get(route('gl.income', ['cost_center_id' => $fac->id, 'export' => 1]))->assertOk();
        $this->actingAs($admin)->get(route('gl.balance_sheet', ['cost_center_id' => $fac->id, 'export' => 1]))->assertOk();
    }
}
