<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Safe;
use App\Models\Setting;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * سجل الخزن والبنوك (خطة القفل ١٩/١٠، التصميم §٣.٢). `cash_main` القديم
 * = الخزنة الرئيسية للمعادي، و`bank` = أول حساب بنك، و`rep_cash` فضل
 * عهدة المندوب (مش خزنة).
 */
class SafeTest extends TestCase
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

    private function safe(string $code, string $kind, ?GlAccount $acc = null): Safe
    {
        return Safe::create(['code' => $code, 'name' => $code, 'name_en' => $code, 'kind' => $kind, 'gl_account_id' => $acc?->id]);
    }

    public function test_for_legacy_maps_cash_main_and_bank_and_not_rep_cash(): void
    {
        $main = $this->safe('MAADI-MAIN', 'cash');
        $bank = $this->safe('BANK-1', 'bank');

        $this->assertSame($main->id, Safe::forLegacy('cash_main')?->id);
        $this->assertSame($bank->id, Safe::forLegacy('bank')?->id);
        $this->assertNull(Safe::forLegacy('rep_cash'));
        $this->assertNull(Safe::forLegacy('nonsense'));
        $this->assertSame('EGP', $main->fresh()->currency);
        $this->assertTrue($main->fresh()->active);
    }

    public function test_balance_sums_gl_lines_up_to_the_date(): void
    {
        $cash = GlAccount::findKey('cash_main');
        $safe = $this->safe('MAADI-MAIN', 'cash', $cash);
        $admin = $this->makeAdmin();
        $ledger = app(Ledger::class);
        $ledger->manual(Carbon::parse('2026-09-01'), 'إيداع', [
            ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('suspense')->id, 'debit' => 0, 'credit' => 1000],
        ], $admin);
        $ledger->manual(Carbon::parse('2026-09-10'), 'مصروف', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 300],
        ], $admin);

        $this->assertSame(1000.0, $safe->balance(Carbon::parse('2026-09-05')));
        $this->assertSame(700.0, $safe->balance());
    }

    public function test_balance_is_zero_without_a_gl_account(): void
    {
        $this->assertSame(0.0, $this->safe('BANK-1', 'bank')->balance());
    }
}
