<?php

namespace Tests\Feature\Costing;

use App\Models\CostEntry;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostingSettings;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Lane 9 Task 1: schema, settings and the costing GL seed (D1-D3) */
class CostingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $tables = [
            'cost_entries' => ['kind', 'origin_type', 'origin_id', 'date', 'cost_center_id', 'other_center_id', 'qty', 'revenue', 'amount_batch', 'amount_wavg', 'amount_transfer', 'materials', 'overhead', 'flags'],
            'cost_lines' => ['cost_entry_id', 'product_id', 'batch_id', 'qty', 'revenue', 'unit_batch', 'unit_wavg', 'unit_transfer', 'amount_batch', 'amount_wavg', 'amount_transfer', 'zero_cost'],
            'cost_months' => ['period', 'status', 'closed_by', 'closed_at', 'summary'],
            'cost_month_lines' => ['cost_month_id', 'line_id', 'direct', 'shared', 'payroll', 'depreciation', 'pool', 'applied', 'variance', 'hours', 'output_kg', 'rate'],
            'product_transfer_prices' => ['product_id', 'price', 'unit_cost', 'markup_pct', 'effective_from', 'approved_by', 'approved_at', 'note'],
            'production_orders' => ['overhead_rate', 'overhead_kg'],
        ];
        foreach ($tables as $table => $cols) {
            $this->assertTrue(Schema::hasTable($table), $table);
            foreach ($cols as $col) {
                $this->assertTrue(Schema::hasColumn($table, $col), "{$table}.{$col}");
            }
        }
        $this->assertSame(['sale', 'return', 'transfer', 'production', 'variance'], CostEntry::KINDS);
    }

    public function test_cost_entry_helpers(): void
    {
        $e = new CostEntry(['amount_batch' => '10.00', 'amount_wavg' => '12.50', 'flags' => 'zero_cost,no_transfer_price']);
        $this->assertSame(10.0, $e->amount('batch'));
        $this->assertSame(12.5, $e->amount('wavg'));
        $this->assertSame(10.0, $e->official());
        $this->assertTrue($e->hasFlag('zero_cost'));
        $this->assertTrue($e->hasFlag('no_transfer_price'));
        $this->assertFalse($e->hasFlag('transfer'));
    }

    public function test_settings_defaults_and_save(): void
    {
        Setting::flushCache();
        $this->assertSame('batch', CostingSettings::method());
        $this->assertSame(20.0, CostingSettings::markupPct());
        $this->assertSame(90.0, CostingSettings::alertPct());
        $this->assertNull(CostingSettings::defaultKgRate());
        $this->assertSame(0.1, CostingSettings::unitKgFallback());
        $this->assertSame('sales', CostingSettings::allocBasis());

        CostingSettings::save(['cost_method' => 'wavg', 'cost_default_kg_rate' => 12.5, 'cost_alloc_ignored' => 'x'], $this->makeAdmin());
        Setting::flushCache();
        $this->assertSame('wavg', CostingSettings::method());
        $this->assertSame(12.5, CostingSettings::defaultKgRate());
        $this->assertNotNull(Setting::read('cost_settings_saved_at'));

        CostingSettings::save(['cost_default_kg_rate' => ''], $this->makeAdmin());
        Setting::flushCache();
        $this->assertNull(CostingSettings::defaultKgRate());
    }

    public function test_costing_seed_creates_accounts_and_rules_once(): void
    {
        $this->seed(GlSeeder::class);
        $this->assertFalse(CostingGlSeed::ready());

        $first = CostingGlSeed::run();
        $this->assertSame(6, $first['accounts_created']);
        $this->assertSame(9, $first['rules_created']);

        $types = ['internal_sales' => 'revenue', 'cogs' => 'expense', 'internal_cogs' => 'expense', 'internal_elim' => 'expense', 'overhead_applied' => 'expense', 'production_variance' => 'expense'];
        foreach ($types as $key => $type) {
            $acc = GlAccount::findKey($key);
            $this->assertSame($type, $acc->type, $key);
            $this->assertTrue($acc->is_system && $acc->is_postable, $key);
            $this->assertContains($key, GlAccount::COSTING_KEYS);
        }
        $this->assertSame('credit', GlAccount::findKey('overhead_applied')->normal_side);
        $this->assertSame('4', GlAccount::findKey('internal_sales')->parent->code);
        $this->assertSame('5', GlAccount::findKey('cogs')->parent->code);
        $this->assertSame('5150', GlAccount::findKey('cogs')->code);
        $this->assertSame('cogs', GlPostingRule::forKey('cost.sale')->debit_key);
        $this->assertSame('inventory', GlPostingRule::forKey('sup.invoice.stock')->debit_key);
        $this->assertSame('payables', GlPostingRule::forKey('sup.invoice.stock')->credit_key);
        $this->assertTrue(CostingGlSeed::ready());

        $second = CostingGlSeed::run();
        $this->assertSame(0, $second['accounts_created']);
        $this->assertSame(0, $second['rules_created']);
        $this->assertTrue(CostingGlSeed::ready());

        GlPostingRule::where('key', 'cost.sale')->update(['active' => false]);
        GlPostingRule::flush();
        $this->assertFalse(CostingGlSeed::ready());
    }

    public function test_a_taken_code_gets_the_l9_suffix(): void
    {
        $this->seed(GlSeeder::class);
        $root = GlAccount::where('code', '5')->first();
        GlAccount::create(['code' => '5150', 'name' => 'حساب المحاسب', 'parent_id' => $root->id, 'type' => 'expense', 'normal_side' => 'debit', 'is_postable' => true, 'active' => true]);

        $r = CostingGlSeed::run();
        $this->assertSame('5150.L9', GlAccount::findKey('cogs')->code);
        $this->assertSame('5150', GlAccount::where('name', 'حساب المحاسب')->value('code'));
        $this->assertSame([__('costing.seed_note_code_taken', ['key' => 'cogs', 'code' => '5150', 'alt' => '5150.L9'])], $r['notes']);
    }

    public function test_seed_notes_follow_the_locale(): void
    {
        $this->seed(GlSeeder::class);
        $root = GlAccount::where('code', '5')->first();
        GlAccount::create(['code' => '5151', 'name' => 'حساب المحاسب', 'parent_id' => $root->id, 'type' => 'expense', 'normal_side' => 'debit', 'is_postable' => true, 'active' => true]);

        // خطوة ٧ بالعربي: الملاحظة بالعربي مش إنجليزي ثابت
        app()->setLocale('ar');
        $this->assertSame(['internal_cogs: الكود 5151 متاخد بحساب تاني — اتعمل بالكود 5151.L9.'], CostingGlSeed::run(true)['notes']);
        app()->setLocale('en');
        $this->assertSame(['internal_cogs: code 5151 is taken by another account - created as 5151.L9.'], CostingGlSeed::run(true)['notes']);
    }

    public function test_seed_dry_run_writes_nothing(): void
    {
        $this->seed(GlSeeder::class);
        $accounts = GlAccount::count();
        $rules = GlPostingRule::count();

        $r = CostingGlSeed::run(true);
        $this->assertSame(6, $r['accounts_created']);
        $this->assertSame(9, $r['rules_created']);
        $this->assertSame($accounts, GlAccount::count());
        $this->assertSame($rules, GlPostingRule::count());
        $this->assertNull(GlPostingRule::forKey('cost.sale'));

        $this->artisan('promax:costing-gl-seed', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($accounts, GlAccount::count());
    }

    public function test_seed_refuses_empty_tree(): void
    {
        $this->assertSame(0, GlAccount::count());
        $this->artisan('promax:costing-gl-seed')->assertFailed();
        $this->assertSame(0, GlAccount::count());
        $this->assertSame(0, GlPostingRule::count());
        $this->artisan('promax:costing-gl-seed', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, GlAccount::count());

        $this->expectException(\RuntimeException::class);
        CostingGlSeed::run();
    }

    public function test_seed_refuses_a_tree_without_inventory(): void
    {
        $this->seed(GlSeeder::class);
        GlAccount::where('system_key', 'inventory')->update(['system_key' => null]);
        $this->artisan('promax:costing-gl-seed')->assertFailed();
        $this->assertNull(GlAccount::where('system_key', 'cogs')->first());
    }

    public function test_gl_seeder_alone_does_not_create_costing_rules(): void
    {
        $this->seed(GlSeeder::class);
        $this->assertNull(GlPostingRule::forKey('cost.sale'));
        $this->assertNull(GlPostingRule::forKey('sup.invoice.stock'));
        $this->assertNull(GlAccount::where('system_key', 'cogs')->first());
    }
}
