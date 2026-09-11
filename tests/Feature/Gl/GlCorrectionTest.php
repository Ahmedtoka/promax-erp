<?php
// tests/Feature/Gl/GlCorrectionTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLineOverride;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\Ledger;
use App\Services\Gl\UnbalancedEntry;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
    }

    public function test_a_manual_entry_must_balance(): void
    {
        $admin = $this->makeAdmin();
        $cash = GlAccount::findKey('cash_main');
        $rent = GlAccount::findKey('expense_rent');

        $this->expectException(UnbalancedEntry::class);
        app(Ledger::class)->manual(today(), 'إيجار', [
            ['account_id' => $rent->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 900],
        ], $admin);
    }

    public function test_a_balanced_manual_entry_is_numbered_and_stored(): void
    {
        $admin = $this->makeAdmin();
        $e = app(Ledger::class)->manual(today(), 'إيجار', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ], $admin);

        $this->assertStringStartsWith('JV-', $e->number);
        $this->assertSame('manual', $e->origin);
        $this->assertSame($admin->id, $e->created_by);
        $this->assertSame(1000.0, GlAccount::findKey('expense_rent')->balanceBetween(null, null));
        $this->assertSame(-1000.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }

    public function test_override_in_an_open_period_edits_in_place_and_logs_the_audit(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $entry = app(Ledger::class)->autoEntryFor($tx);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $result = app(Ledger::class)->overrideAccount($line, GlAccount::findKey('bank'), $admin, 'كان تحويل');

        $this->assertSame($entry->id, $result->id, 'same entry, edited in place');
        $line->refresh();
        $this->assertSame(GlAccount::findKey('bank')->id, $line->account_id);
        $this->assertTrue($line->overridden);
        $this->assertSame(GlAccount::findKey('cash_main')->id, $line->rule_account_id);
        $this->assertSame(1, GlLineOverride::where('line_id', $line->id)->count());
        $this->assertNotNull($entry->fresh()->edited_at);
    }

    public function test_override_in_a_closed_period_reverses_and_reposts_today(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $old = Carbon::parse('2026-07-15');
        $tx = Transaction::create(['client_id' => $client->id, 'date' => $old, 'memo' => 'x', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $entry = app(Ledger::class)->autoEntryFor($tx);
        GlPeriod::close('2026-07', $admin);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $fixed = app(Ledger::class)->overrideAccount($line, GlAccount::findKey('bank'), $admin);

        $this->assertSame(3, GlEntry::count(), 'original + reversal + corrected');
        $reversal = GlEntry::where('origin', 'reversal')->first();
        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertSame(today()->toDateString(), $reversal->date->toDateString());
        $this->assertNotSame($entry->id, $fixed->id);
        // الأصل ماتلمسش
        $this->assertSame(GlAccount::findKey('cash_main')->id, $line->fresh()->account_id);
        // الصافي: الخزنة صفر، البنك 300، العملاء −300
        $this->assertSame(0.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
        $this->assertSame(300.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(-300.0, GlAccount::findKey('receivables')->balanceBetween(null, null));
    }

    public function test_manual_entries_dated_inside_a_closed_period_are_refused(): void
    {
        $admin = $this->makeAdmin();
        GlPeriod::close('2026-07', $admin);

        $this->expectException(ClosedPeriod::class);
        app(Ledger::class)->manual(Carbon::parse('2026-07-20'), 'x', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 10, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 10],
        ], $admin);
    }

    // ═══ حكم إضافي: حساب العملاء/الموردين ممنوع من قيد يدوي أو تحويل ═══
    // السبب: ثابت الاعتماد (invariant) بيقارن الحسابين دول بكشف حساب
    // العميل وكشف حساب المورد — قيد يدوي عليهم هيكسر التوافق من الأساس.
    public function test_a_manual_entry_touching_receivables_is_refused(): void
    {
        $admin = $this->makeAdmin();

        $this->expectException(\InvalidArgumentException::class);
        app(Ledger::class)->manual(today(), 'x', [
            ['account_id' => GlAccount::findKey('receivables')->id, 'debit' => 10, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 10],
        ], $admin);
    }

    public function test_overriding_a_line_to_receivables_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $entry = app(Ledger::class)->autoEntryFor($tx);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $this->expectException(\InvalidArgumentException::class);
        app(Ledger::class)->overrideAccount($line, GlAccount::findKey('receivables'), $admin);
    }
}
