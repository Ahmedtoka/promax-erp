<?php
// tests/Feature/Gl/GlReportsTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Visit;
use App\Services\Gl\Ledger;
use App\Services\Gl\Reports;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlReportsTest extends TestCase
{
    use RefreshDatabase;

    private function world(): void
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        app(Ledger::class)->manual(Carbon::parse('2026-08-01'), 'افتتاحي', [
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('opening_equity')->id, 'debit' => 0, 'credit' => 10000],
        ], $admin, 'opening');
        Transaction::create(['client_id' => $client->id, 'date' => '2026-08-10', 'memo' => 'x', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);
        Transaction::create(['client_id' => $client->id, 'date' => '2026-09-05', 'memo' => 'x', 'debit' => 0, 'credit' => 500, 'kind' => 'collection', 'method' => 'cash']);
        app(Ledger::class)->manual(Carbon::parse('2026-09-06'), 'إيجار', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 300],
        ], $admin);
    }

    public function test_the_trial_balance_balances_and_carries_openings(): void
    {
        $this->world();
        $tb = app(Reports::class)->trialBalance(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
        $cash = collect($tb['rows'])->first(fn ($r) => $r['account']->system_key === 'cash_main');
        $this->assertSame(10000.0, $cash['opening']);
        $this->assertSame(500.0, $cash['debit']);
        $this->assertSame(300.0, $cash['credit']);
        $this->assertSame(10200.0, $cash['closing']);
    }

    public function test_the_statement_runs_a_balance_and_the_income_statement_nets_revenue_and_expenses(): void
    {
        $this->world();
        $st = app(Reports::class)->statement(GlAccount::findKey('cash_main'), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $this->assertSame(10000.0, $st['opening']);
        $this->assertSame([10500.0, 10200.0], array_map(fn ($r) => $r['running'], $st['rows']));
        $this->assertSame(10200.0, $st['closing']);

        $inc = app(Reports::class)->income(Carbon::parse('2026-08-01'), Carbon::parse('2026-09-30'));
        $this->assertSame(1000.0, $inc['total_revenue']);
        $this->assertSame(300.0, $inc['total_expenses']);
        $this->assertSame(700.0, $inc['net']);
    }

    public function test_the_balance_sheet_balances_with_retained_earnings(): void
    {
        $this->world();
        $bs = app(Reports::class)->balanceSheet(Carbon::parse('2026-09-30'));

        $this->assertTrue($bs['balanced'], json_encode([$bs['total_assets'], $bs['total_liabilities_equity']]));
        $this->assertSame(700.0, $bs['retained']);
        $this->assertSame(10200.0 + 640.0, $bs['total_assets']); // خزنة 10200 + عملاء 640
    }

    public function test_the_statement_rolls_up_a_non_postable_parent_over_its_subtree(): void
    {
        // `rep_cash` (1110) نفسه مش postable — الحساب الفعلي اللي بيتحرك
        // هو ابنه الديناميكي `1110.{code}` بتاع المندوب؛ الكشف على الأب
        // لازم يلمّ حركة الابن عن طريق subtreeIds()
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $visit = Visit::create(['user_id' => $rep->id, 'client_id' => $client->id, 'checked_in_at' => now()]);
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحصيل', 'debit' => 0, 'credit' => 500, 'kind' => 'collection', 'method' => 'cash', 'source_type' => Visit::class, 'source_id' => $visit->id]);

        $st = app(Reports::class)->statement(GlAccount::findKey('rep_cash'), null, null);
        $this->assertSame([500.0], array_map(fn ($r) => $r['running'], $st['rows']));
        $this->assertSame(500.0, $st['closing']);

        $tb = app(Reports::class)->trialBalance(null, null);
        $this->assertNull(collect($tb['rows'])->first(fn ($r) => $r['account']->system_key === 'rep_cash'), 'non-postable parent must not appear in the trial balance');
    }

    public function test_income_shows_contra_revenue_as_negative_and_leaves_the_tax_line_out(): void
    {
        $this->world();
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => '2026-09-10', 'memo' => 'مرتجع', 'debit' => 0, 'credit' => 228, 'tax' => 28, 'kind' => 'return']);

        $inc = app(Reports::class)->income(Carbon::parse('2026-08-01'), Carbon::parse('2026-09-30'));
        $returns = collect($inc['revenue'])->first(fn ($r) => $r['account']->system_key === 'sales_returns');
        $this->assertNotNull($returns);
        $this->assertSame(-200.0, $returns['amount']);
        $this->assertNull(collect($inc['revenue'])->first(fn ($r) => $r['account']->system_key === 'vat_output'), 'vat_output is a liability, not revenue');
        $this->assertSame(800.0, $inc['total_revenue']); // مبيعات 1000 - مرتجع 200
    }
}
