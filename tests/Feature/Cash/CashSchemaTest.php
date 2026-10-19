<?php

namespace Tests\Feature\Cash;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\Safe;
use App\Services\Cash\CashGlSeed;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lane 4 Task 1: جداول الخزن والمصروفات والأصول، وزرع حسابات النظام
 * الجديدة بالمفتاح مش بالكود (L4-D13).
 */
class CashSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $tables = [
            'expense_categories' => ['code', 'name', 'name_en', 'type', 'account_id', 'life_months', 'links_vehicle', 'active'],
            'safe_counts' => ['number', 'safe_id', 'date', 'expected', 'counted', 'difference', 'note', 'status', 'voided_at', 'voided_by', 'created_by'],
            'bank_statements' => ['number', 'safe_id', 'date', 'date_from', 'date_to', 'opening_balance', 'closing_balance', 'status', 'closed_at', 'closed_by', 'created_by'],
            'bank_statement_marks' => ['statement_id', 'line_key', 'gl_line_id', 'amount', 'line_date', 'marked_by', 'marked_at'],
            'fixed_assets' => ['code', 'name', 'name_en', 'category_id', 'expense_id', 'cost', 'salvage', 'life_months', 'start_date', 'cost_center_id', 'asset_account_id', 'opening_accum', 'opening_as_of', 'status', 'disposed_at', 'created_by'],
            'fixed_asset_depreciations' => ['fixed_asset_id', 'period_key', 'date', 'amount', 'created_by'],
            'expenses' => ['category_id', 'safe_id', 'cost_center_id', 'rep_id', 'vehicle_id', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'decision_note', 'paid_by', 'paid_at', 'asset_name', 'asset_life_months', 'asset_start_date'],
            'cash_movements' => ['from_safe_id', 'to_safe_id', 'cost_center_id'],
        ];
        foreach ($tables as $table => $cols) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $cols), $table.' columns');
        }

        // kind واسع كفاية لـ safe_transfer / bank_transfer (13 حرف)
        $kindType = (string) collect(Schema::getColumns('cash_movements'))->firstWhere('name', 'kind')['type'];
        $this->assertMatchesRegularExpression('/\((\d+)\)/', $kindType);
        preg_match('/\((\d+)\)/', $kindType, $m);
        $this->assertGreaterThanOrEqual(13, (int) $m[1]);
        $this->assertSame(['deposit', 'withdraw', 'rep_advance', 'rep_return', 'safe_transfer', 'bank_transfer'], CashMovement::KINDS);
        $this->assertSame(['pending', 'approved', 'rejected', 'posted', 'void'], Expense::STATUSES);
        $this->assertSame(['operating', 'asset', 'payroll', 'factory'], ExpenseCategory::TYPES);
    }

    public function test_cash_setup_is_idempotent_and_never_takes_an_existing_code(): void
    {
        // شجرة محاسب مستوردة: الكود 1401 متاخد بحساب من غير مفتاح
        $root = GlAccount::create(['code' => '1', 'name' => 'الأصول', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => false, 'active' => true]);
        $taken = GlAccount::create(['code' => '1401', 'name' => 'سيارات المحاسب', 'parent_id' => $root->id, 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'active' => true]);

        $this->seed(GlSeeder::class); // بيشغّل CashGlSeed في الآخر

        $fa = GlAccount::where('system_key', 'fixed_assets')->first();
        $this->assertNotNull($fa);
        $this->assertNotSame($taken->id, $fa->id);
        $this->assertSame('1401.L4', $fa->code);
        $fresh = $taken->fresh();
        $this->assertSame('1401', $fresh->code);
        $this->assertNull($fresh->system_key);
        $this->assertSame('سيارات المحاسب', $fresh->name);

        foreach (['fixed_assets', 'accum_depreciation', 'expense_depreciation', 'cash_over_short', 'expense_factory'] as $key) {
            $this->assertContains($key, GlAccount::SYSTEM_KEYS);
            $this->assertNotNull(GlAccount::where('system_key', $key)->first(), $key);
        }
        $this->assertSame('5108', GlAccount::findKey('expense_depreciation')->code);
        $this->assertSame('expense', GlAccount::findKey('cash_over_short')->type);
        $this->assertSame('asset', GlAccount::findKey('accum_depreciation')->type);
        $this->assertSame('14', GlAccount::findKey('accum_depreciation')->parent->code);
        foreach (['cash.safe_transfer', 'cash.bank_transfer', 'cash.count', 'asset.depreciation'] as $key) {
            $this->assertNotNull(GlPostingRule::forKey($key), $key);
        }
        $this->assertSame('expense_depreciation', GlPostingRule::forKey('asset.depreciation')->debit_key);
        $this->assertSame('accum_depreciation', GlPostingRule::forKey('asset.depreciation')->credit_key);

        $accounts = GlAccount::count();
        $second = CashGlSeed::run();
        $this->assertSame(0, $second['accounts_created']);
        $this->assertSame(0, $second['rules_created']);
        $this->assertSame(0, $second['categories_created']);
        $this->assertSame($accounts, GlAccount::count());
    }

    public function test_cash_setup_refuses_an_empty_chart_of_accounts(): void
    {
        $this->assertSame(0, GlAccount::count());
        $this->artisan('promax:cash-setup')->assertFailed();
        $this->assertSame(0, GlAccount::count());
        $this->assertSame(0, GlPostingRule::count());
        $this->assertSame(0, ExpenseCategory::count());
        $this->artisan('promax:cash-setup', ['--dry-run' => true])->assertSuccessful();
    }

    public function test_cash_setup_dry_run_writes_nothing(): void
    {
        $root = GlAccount::create(['code' => '1', 'name' => 'الأصول', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => false, 'active' => true]);
        GlAccount::create(['code' => '5', 'name' => 'المصروفات', 'type' => 'expense', 'normal_side' => 'debit', 'is_postable' => false, 'active' => true]);
        $accounts = GlAccount::count();
        $rules = GlPostingRule::count();

        $r = CashGlSeed::run(true);

        $this->assertSame(6, $r['accounts_created']); // group 13 + 5 accounts
        $this->assertSame(4, $r['rules_created']);
        $this->assertSame(13, $r['categories_created']);
        $this->assertSame($accounts, GlAccount::count());
        $this->assertSame($rules, GlPostingRule::count());
        $this->assertSame(0, ExpenseCategory::count());
        $this->assertNotNull($root->fresh());

        $this->artisan('promax:cash-setup', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($accounts, GlAccount::count());
        $this->assertSame(0, ExpenseCategory::count());
    }

    public function test_default_categories_are_seeded_once(): void
    {
        $this->seed(GlSeeder::class);
        $this->assertSame(13, ExpenseCategory::count());

        $fuel = ExpenseCategory::where('code', 'FUEL')->first();
        $this->assertSame('operating', $fuel->type);
        $this->assertTrue($fuel->links_vehicle);
        $this->assertSame(GlAccount::findKey('expense_fuel')->id, $fuel->account_id);
        $laptop = ExpenseCategory::where('code', 'LAPTOP')->first();
        $this->assertSame('asset', $laptop->type);
        $this->assertSame(36, $laptop->life_months);
        $this->assertSame(GlAccount::findKey('fixed_assets')->id, $laptop->account_id);
        $this->assertSame(60, ExpenseCategory::where('code', 'FURNITURE')->value('life_months'));
        $this->assertSame(GlAccount::findKey('expense_factory')->id, ExpenseCategory::where('code', 'GAS')->value('account_id'));
        $this->assertSame('payroll', ExpenseCategory::where('code', 'SALARIES')->value('type'));

        // المالك غيّر اسم بند → التشغيل التاني مايدوسش عليه ومايكررش
        ExpenseCategory::where('code', 'BUFFET')->update(['name' => 'بوفيه المكتب']);
        $this->seed(GlSeeder::class);
        $r = CashGlSeed::run();
        $this->assertSame(0, $r['categories_created']);
        $this->assertSame(13, ExpenseCategory::count());
        $this->assertSame('بوفيه المكتب', ExpenseCategory::where('code', 'BUFFET')->value('name'));
    }

    public function test_expense_resolved_safe_falls_back_to_legacy(): void
    {
        $this->seed(GlSeeder::class);
        $main = Safe::create(['code' => 'MAADI-MAIN', 'name' => 'MAADI-MAIN', 'kind' => 'cash']);
        $other = Safe::create(['code' => 'SAFE-2', 'name' => 'SAFE-2', 'kind' => 'cash']);
        $acc = GlAccount::findKey('expense_other');

        $base = ['date' => '2026-10-01', 'account_id' => $acc->id, 'amount' => 50, 'status' => 'pending'];
        $legacy = Expense::create($base + ['number' => 'EXP-1001', 'paid_from' => 'cash_main']);
        $this->assertSame($main->id, $legacy->resolvedSafe()?->id);

        $named = Expense::create($base + ['number' => 'EXP-1002', 'paid_from' => 'cash_main', 'safe_id' => $other->id]);
        $this->assertSame($other->id, $named->fresh()->resolvedSafe()?->id);
        $this->assertSame($other->id, $named->safe->id);

        $rep = Expense::create($base + ['number' => 'EXP-1003', 'paid_from' => 'rep_cash']);
        $this->assertNull($rep->resolvedSafe());
    }
}
