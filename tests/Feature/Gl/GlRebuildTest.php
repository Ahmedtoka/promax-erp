<?php
// tests/Feature/Gl/GlRebuildTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlRebuildTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $mk = fn (array $a) => Transaction::create(array_merge(['client_id' => $client->id, 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $a));
        $mk(['date' => '2026-07-10', 'kind' => 'sale', 'debit' => 5000]);            // قبل البداية — مايتقيدش
        $mk(['date' => '2026-08-05', 'kind' => 'sale', 'debit' => 1140, 'tax' => 140]);
        $coll = $mk(['date' => '2026-08-20', 'kind' => 'collection', 'credit' => 600, 'method' => 'cash']);
        $mk(['date' => '2026-09-02', 'kind' => 'return', 'credit' => 114, 'tax' => 14]);
        $client->recalculate();

        return [$admin, $client, $coll];
    }

    private function balances(): array
    {
        return GlAccount::where('is_postable', true)->get()
            ->mapWithKeys(fn ($a) => [$a->code => $a->balanceBetween(null, null)])->all();
    }

    public function test_rebuild_reproduces_the_same_balances_and_the_invariant_holds(): void
    {
        [$admin] = $this->world();
        $before = $this->balances();
        $this->assertSame(3, GlEntry::count(), 'the July row is before the start date');

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);

        $this->assertTrue($report->ok);
        $this->assertSame(3, $report->deleted);
        $this->assertSame(3, $report->created);
        $this->assertSame($before, $this->balances());
        $this->assertTrue($report->invariants['receivables']['ok']);
        $this->assertSame(1140.0 - 600.0 - 114.0, $report->invariants['receivables']['gl']);
    }

    public function test_dry_run_reports_the_effect_of_a_changed_rule_without_writing(): void
    {
        [$admin] = $this->world();
        GlPostingRule::where('key', 'tx.collection.office_cash')->update(['debit_key' => 'bank']);
        GlPostingRule::flush();
        $before = $this->balances();

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, true, $admin);

        $this->assertSame($before, $this->balances(), 'dry run writes nothing');
        // تحصيل كاش مكتبي = مدين خزنة (موجب) — راجع GlPostingTest::
        // test_a_bank_transfer_collection_and_a_direct_office_cash_collection
        $this->assertSame(['before' => 600.0, 'after' => 0.0], $report->accountDiff['1101']);
        $this->assertSame(['before' => 0.0, 'after' => 600.0], $report->accountDiff['1102']);
    }

    public function test_rebuild_keeps_manual_overrides_unless_asked_to_drop_them(): void
    {
        [$admin, , $coll] = $this->world();
        $ledger = app(Ledger::class);
        $entry = $ledger->autoEntryFor($coll);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);
        $ledger->overrideAccount($line, GlAccount::findKey('bank'), $admin);

        $kept = $ledger->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);
        $this->assertSame(1, $kept->overridesKept);
        $this->assertSame(600.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertTrue($ledger->autoEntryFor($coll)->lines->firstWhere('slot', 'dr')->overridden);

        $clean = $ledger->rebuild(Carbon::parse('2026-08-01'), false, false, $admin);
        $this->assertSame(1, $clean->overridesDropped);
        $this->assertSame(0.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(600.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }

    public function test_manual_and_opening_entries_survive_a_rebuild(): void
    {
        [$admin] = $this->world();
        $ledger = app(Ledger::class);
        $ledger->manual(Carbon::parse('2026-08-01'), 'افتتاحي', [
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('opening_equity')->id, 'debit' => 0, 'credit' => 10000],
        ], $admin, 'opening');

        $ledger->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);

        $this->assertSame(1, GlEntry::where('origin', 'opening')->count());
        // القيد الافتتاحي مدين خزنة 10000 + تحصيل كاش مكتبي 600 (مدين برضه)
        $this->assertSame(10000.0 + 600.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }
}
