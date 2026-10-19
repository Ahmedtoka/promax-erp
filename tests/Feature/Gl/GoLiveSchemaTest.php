<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlGoLiveStep;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * جداول معالج التشغيل (خطة القفل ١٩/١٠ Lane 3، المهمة ١).
 */
class GoLiveSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('gl_accounts', 'coa_draft_id'));
        foreach (['debit_account_id', 'credit_account_id', 'tax_account_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('gl_posting_rules', $col), $col);
        }
        $this->assertTrue(Schema::hasColumns('coa_check_accepts', ['id', 'check', 'draft_account_id', 'accepted_by', 'accepted_at']));
        $this->assertTrue(Schema::hasColumns('gl_opening_lines', ['id', 'account_id', 'cost_center_id', 'debit', 'credit', 'note', 'created_by', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('gl_rebuild_runs', [
            'id', 'mode', 'from_date', 'status', 'summary', 'rows', 'checks', 'error',
            'preview_id', 'approved_at', 'approved_by', 'created_by', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('gl_golive_steps', ['id', 'key', 'status', 'data', 'done_at', 'done_by', 'created_at', 'updated_at']));
    }

    public function test_opening_difference_account_is_created_once_under_equity(): void
    {
        $this->seed(GlSeeder::class);

        $a = GlAccount::openingDifference();
        $b = GlAccount::openingDifference();

        $this->assertSame($a->id, $b->id);
        $this->assertSame('equity', $a->type);
        $this->assertSame('credit', $a->normal_side);
        $this->assertSame('opening_difference', $a->system_key);
        $this->assertSame('3199', $a->code);
        $this->assertTrue($a->is_system);
        $this->assertTrue($a->is_postable);
        $this->assertSame(GlAccount::where('code', '3')->whereNull('parent_id')->value('id'), $a->parent_id);
        $this->assertSame(1, GlAccount::where('system_key', 'opening_difference')->count());
        $this->assertSame($a->id, GlAccount::findKey('opening_difference')->id);
    }

    public function test_opening_difference_uses_fallback_code_when_3199_is_taken(): void
    {
        $this->seed(GlSeeder::class);
        $equity = GlAccount::where('code', '3')->first();
        GlAccount::create([
            'code' => '3199', 'name' => 'حر', 'name_en' => 'Free', 'parent_id' => $equity->id,
            'type' => 'equity', 'normal_side' => 'credit', 'is_postable' => true, 'active' => true,
        ]);

        $this->assertSame('3199.1', GlAccount::openingDifference()->code);
    }

    public function test_step_row_is_created_on_first_read(): void
    {
        $this->assertSame(0, GlGoLiveStep::count());

        $step = GlGoLiveStep::for('coa_checks');
        $again = GlGoLiveStep::for('coa_checks');

        $this->assertSame($step->id, $again->id);
        $this->assertSame('todo', $step->fresh()->status);
        $this->assertSame(1, GlGoLiveStep::count());
    }
}
