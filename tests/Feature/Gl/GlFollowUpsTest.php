<?php

namespace Tests\Feature\Gl;

use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\RepSettlement;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use App\Services\Gl\Rules;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** متابعات مراجعة ١٢/٩: سبب المراجعة · جهة المندوب من القاعدة · كويري واحدة للحساب */
class GlFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        GlPostingRule::flush();
    }

    private function sale(string $date): Transaction
    {
        $client = $this->makeClient();

        return Transaction::create(['client_id' => $client->id, 'date' => $date, 'memo' => 'x', 'kind' => 'sale', 'debit' => 100, 'credit' => 0, 'tax' => 0]);
    }

    public function test_review_reason_is_stored_at_posting_and_the_badge_does_not_change_when_the_period_closes(): void
    {
        $admin = $this->makeAdmin();
        // حساب المبيعات موقوف → السطر يروح المعلّق والقيد محتاج مراجعة بسبب القاعدة
        GlAccount::where('system_key', 'sales')->update(['active' => false]);
        $tx = $this->sale(today()->toDateString());
        $entry = app(Ledger::class)->autoEntryFor($tx);
        $this->assertTrue($entry->needs_review);
        $this->assertSame('rule', $entry->review_reason);

        GlPeriod::close(today()->format('Y-m'), $admin);

        $this->actingAs($admin)->get(route('gl.entries'))->assertOk()
            ->assertSee(__('gl.needs_review'), false)
            ->assertDontSee(__('gl.needs_review_closed'), false);
    }

    public function test_an_entry_posted_into_a_closed_month_records_that_reason(): void
    {
        $admin = $this->makeAdmin();
        GlPeriod::close('2026-08', $admin);
        $tx = $this->sale('2026-08-10');

        $entry = app(Ledger::class)->autoEntryFor($tx);
        $this->assertTrue($entry->needs_review);
        $this->assertSame('closed_period', $entry->review_reason);
    }

    public function test_settlement_received_follows_the_rule_on_both_sides(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        // الافتراضي: الخزنة مدين، نقدية المندوب دائن
        $s1 = RepSettlement::create([
            'number' => 'SET-7001', 'user_id' => $rep->id, 'from_at' => now()->subDay(), 'to_at' => now(),
            'invoices_count' => 0, 'expected' => 0, 'received' => 300, 'balance' => 0, 'created_by' => $admin->id,
        ]);
        $this->assertSame(-300.0, GlAccount::repCash($rep)->balanceBetween(null, null));
        $this->assertNotNull(app(Ledger::class)->autoEntryFor($s1));

        // القاعدة اتغيّرت → الترحيل بيتبعها (كان الدائن مكتوب في الكود)
        GlPostingRule::where('key', 'settle.received')->update(['debit_key' => 'bank', 'credit_key' => 'suspense']);
        GlPostingRule::flush();
        RepSettlement::create([
            'number' => 'SET-7002', 'user_id' => $rep->id, 'from_at' => now()->subDay(), 'to_at' => now(),
            'invoices_count' => 0, 'expected' => 0, 'received' => 50, 'balance' => 0, 'created_by' => $admin->id,
        ]);
        $this->assertSame(-300.0, GlAccount::repCash($rep)->balanceBetween(null, null), 'rep side untouched');
        $this->assertSame(50.0, GlAccount::findKey('bank')->balanceBetween(null, null));
    }

    public function test_rep_cash_expense_credit_comes_from_the_rule(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        $mk = fn (int $amount) => Expense::create([
            'number' => Expense::nextNumber(), 'date' => today(),
            'account_id' => GlAccount::findKey('expense_fuel')->id, 'amount' => $amount,
            'paid_from' => 'rep_cash', 'paid_from_user_id' => $rep->id,
            'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'posted', 'created_by' => $admin->id,
        ]);

        $mk(180);
        $this->assertSame(-180.0, GlAccount::repCash($rep)->balanceBetween(null, null));

        GlPostingRule::where('key', 'expense.rep_cash')->update(['credit_key' => 'cash_main']);
        GlPostingRule::flush();
        $mk(20);
        $this->assertSame(-180.0, GlAccount::repCash($rep)->balanceBetween(null, null));
        $this->assertSame(-20.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }

    public function test_resolving_a_rule_key_costs_one_query_per_account(): void
    {
        $tx = Transaction::withoutEvents(fn () => $this->sale('2026-08-10'));
        GlPostingRule::forKey('tx.sale'); // كاش القواعد

        DB::enableQueryLog();
        $spec = app(Rules::class)->linesFor($tx);
        $accountQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'gl_accounts'))->count();
        DB::disableQueryLog();

        $this->assertCount(2, $spec['lines']);
        $this->assertSame(2, $accountQueries, 'receivables + sales, one query each');
    }
}
