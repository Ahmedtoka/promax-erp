<?php
// tests/Feature/Gl/GlSchemaTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_builds_the_five_roots_and_every_system_key_and_is_idempotent(): void
    {
        $this->seed(GlSeeder::class);
        $this->seed(GlSeeder::class);

        $this->assertSame(['1', '2', '3', '4', '5'], GlAccount::whereNull('parent_id')->orderBy('code')->pluck('code')->all());
        foreach (GlAccount::SYSTEM_KEYS as $key) {
            $acc = GlAccount::findKey($key);
            $this->assertTrue($acc->is_system, $key);
            $this->assertTrue($acc->is_postable || $key === 'rep_cash', $key.' postable');
        }
        $this->assertSame(1, GlAccount::where('system_key', 'receivables')->count(), 'no duplicates on re-seed');
        $this->assertGreaterThanOrEqual(18, GlPostingRule::count());
        $this->assertSame('receivables', GlPostingRule::forKey('tx.sale')->debit_key);
    }

    public function test_a_rep_cash_account_is_created_per_field_user_under_the_parent(): void
    {
        $this->seed(GlSeeder::class);
        $rep = $this->makeRep(['code' => 'SLS-777']);

        $acc = GlAccount::repCash($rep);
        $again = GlAccount::repCash($rep);

        $this->assertSame('1110.SLS-777', $acc->code);
        $this->assertSame($acc->id, $again->id);
        $this->assertSame(GlAccount::findKey('rep_cash')->id, $acc->parent_id);
        $this->assertSame('asset', $acc->type);
        $this->assertSame('debit', $acc->normal_side);
    }
}
