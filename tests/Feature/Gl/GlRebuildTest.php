<?php
// tests/Feature/Gl/GlRebuildTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLineOverride;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\RepSettlement;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\Ledger;
use App\Services\Gl\RebuildFailed;
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
        $this->assertSame(0, $report->invariants['entries']['missing']);
        $this->assertSame($before, $this->balances());
        $this->assertTrue($report->invariants['receivables']['ok']);
        $this->assertSame(1140.0 - 600.0 - 114.0, $report->invariants['receivables']['gl']);
    }

    /**
     * أول إعادة بناء بعد تفعيل الدفتر على شجرة فاضية — deleted=0, created=N —
     * لازم تعدّي: فحص `entries` بقى بالمفتاح (source_type|source_id) مش
     * بمقارنة الأعداد، فمفيش مصدر "ضاع" هنا أصلاً (مفيش حاجة اتمسحت).
     */
    public function test_the_first_rebuild_on_virgin_books_succeeds(): void
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '0');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $mk = fn (array $a) => Transaction::create(array_merge(['client_id' => $client->id, 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $a));
        $mk(['date' => '2026-08-05', 'kind' => 'sale', 'debit' => 1140, 'tax' => 140]);
        $mk(['date' => '2026-08-20', 'kind' => 'collection', 'credit' => 600, 'method' => 'cash']);
        $mk(['date' => '2026-09-02', 'kind' => 'return', 'credit' => 114, 'tax' => 14]);
        $client->recalculate();

        $this->assertSame(0, GlEntry::count(), 'switch was off — nothing auto-posted');

        Setting::write('gl_enabled', '1');
        Setting::flushCache();

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);

        $this->assertTrue($report->ok);
        $this->assertSame(0, $report->deleted);
        $this->assertSame(3, $report->created);
        $this->assertSame(0, $report->invariants['entries']['missing']);
        $this->assertTrue($report->invariants['entries']['ok']);
        $this->assertSame(3, GlEntry::count());
        $this->assertTrue($report->invariants['receivables']['ok']);
    }

    public function test_dry_run_reports_the_effect_of_a_changed_rule_without_writing(): void
    {
        [$admin] = $this->world();
        GlPostingRule::where('key', 'tx.collection.office_cash')->update(['debit_key' => 'bank']);
        GlPostingRule::flush();
        $before = $this->balances();

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, true, $admin);

        $this->assertSame($before, $this->balances(), 'dry run writes nothing');
        $this->assertTrue($report->dryRun);
        $this->assertSame(3, $report->deleted);
        $this->assertSame(3, $report->created);
        // تحصيل كاش مكتبي = مدين خزنة (موجب) — راجع GlPostingTest::
        // test_a_bank_transfer_collection_and_a_direct_office_cash_collection
        $this->assertSame(['before' => 600.0, 'after' => 0.0], $report->accountDiff['1101']);
        $this->assertSame(['before' => 0.0, 'after' => 600.0], $report->accountDiff['1102']);
        // مفاتيح المصفوفة الرقمية زي '1101' بتتحول لـint تلقائي في PHP
        $this->assertSame([1101, 1102], array_keys($report->accountDiff));
    }

    public function test_rebuild_keeps_manual_overrides_unless_asked_to_drop_them(): void
    {
        [$admin, , $coll] = $this->world();
        $ledger = app(Ledger::class);
        $entry = $ledger->autoEntryFor($coll);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);
        $ledger->overrideAccount($line, GlAccount::findKey('bank'), $admin, 'اتفاق مع العميل');

        $kept = $ledger->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);
        $this->assertSame(1, $kept->overridesKept);
        $this->assertSame(600.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $newLine = $ledger->autoEntryFor($coll)->lines->firstWhere('slot', 'dr');
        $this->assertTrue($newLine->overridden);

        // أثر التدقيق (مين/إمتى/ليه) لازم يتسجل على السطر الجديد برضه —
        // مش بس الحساب المحفوظ؛ السطر القديم اتمسح بالكاسكيد مع القيد
        $ov = GlLineOverride::where('line_id', $newLine->id)->latest('id')->first();
        $this->assertNotNull($ov);
        $this->assertSame($admin->id, $ov->user_id);
        $this->assertSame('اتفاق مع العميل', $ov->note);
        $this->assertSame(GlAccount::findKey('cash_main')->id, $ov->from_account_id);
        $this->assertSame(GlAccount::findKey('bank')->id, $ov->to_account_id);

        $clean = $ledger->rebuild(Carbon::parse('2026-08-01'), false, false, $admin);
        $this->assertSame(1, $clean->overridesDropped);
        $this->assertSame(0.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(600.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }

    /**
     * ⚠️ **repost كان بيرمي التحويل اليدوي.** تعديل تاريخ فاتورة أو
     * إعادة ترقيمها بتنادي `repost()` — وكانت بتمسح القيد وتولّده
     * بالقاعدة، يعني السطر اللي المحاسب حوّله للبنك بيرجع للخزنة من
     * غير أي أثر. نفس التقاط `rebuild()` بالظبط (نفس الهيلبرز).
     */
    public function test_repost_keeps_a_manual_override_and_writes_its_audit_row_again(): void
    {
        [$admin, , $coll] = $this->world();
        $ledger = app(Ledger::class);
        $cash = GlAccount::findKey('cash_main');
        $bank = GlAccount::findKey('bank');

        $line = $ledger->autoEntryFor($coll)->lines->firstWhere('account_id', $cash->id);
        $ledger->overrideAccount($line, $bank, $admin, 'اتفاق مع العميل');

        $entry = $ledger->repost($coll, $admin);

        $this->assertNotNull($entry);
        $newLine = $entry->lines->firstWhere('slot', 'dr');
        $this->assertSame($bank->id, $newLine->account_id, 'التحويل اليدوي لازم يعيش الـrepost');
        $this->assertTrue($newLine->overridden);
        $this->assertSame($cash->id, $newLine->rule_account_id, 'حساب القاعدة الأصلي متسجّل على السطر');
        $this->assertSame(600.0, $bank->balanceBetween(null, null));
        $this->assertSame(0.0, $cash->balanceBetween(null, null));

        // وأثر التدقيق (مين/إمتى/ليه) اتكتب تاني على السطر الجديد
        $ov = GlLineOverride::where('line_id', $newLine->id)->latest('id')->first();
        $this->assertNotNull($ov, 'صف التدقيق لازم يتكتب على السطر الجديد');
        $this->assertSame($admin->id, $ov->user_id);
        $this->assertSame('اتفاق مع العميل', $ov->note);
        $this->assertSame($cash->id, $ov->from_account_id);
        $this->assertSame($bank->id, $ov->to_account_id);
    }

    public function test_overrides_dropped_when_the_source_no_longer_posts_at_all(): void
    {
        [$admin, , $coll] = $this->world();
        $ledger = app(Ledger::class);
        $entry = $ledger->autoEntryFor($coll);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);
        $ledger->overrideAccount($line, GlAccount::findKey('bank'), $admin);

        // القاعدة بتاعت المصدر اتوقفت — القيد القديم (بتعديله) هيتمسح
        // ومفيش بديل هيتولد له خالص؛ التعديل المحفوظ يبقى "اتشال" مش "اتحافظ عليه"
        GlPostingRule::where('key', 'tx.collection.office_cash')->update(['active' => false]);
        GlPostingRule::flush();

        $report = $ledger->rebuild(Carbon::parse('2026-08-01'), true, true, $admin);

        $this->assertSame(0, $report->overridesKept);
        $this->assertSame(1, $report->overridesDropped);
        $this->assertFalse($report->invariants['entries']['ok'], 'a source that stopped posting must trip the entries invariant');
        $this->assertSame(1, $report->invariants['entries']['missing']);
        $this->assertCount(1, $report->missingSources);
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

    public function test_a_real_run_throws_and_rolls_back_when_the_invariant_fails(): void
    {
        [$admin] = $this->world();
        $before = $this->balances();
        $beforeEntryCount = GlEntry::count();

        // إيقاف قاعدة المبيعات — الفاتورة (1140) هتضيع من غير بديل، فرصيد
        // العملاء في الشجرة هيختلف عن صافي قيود العملاء، وعدد القيود
        // هيختلف عن اللي اتمسح كمان
        GlPostingRule::where('key', 'tx.sale')->update(['active' => false]);
        GlPostingRule::flush();

        $ledger = app(Ledger::class);
        try {
            $ledger->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);
            $this->fail('expected RebuildFailed to be thrown');
        } catch (RebuildFailed $e) {
            $this->assertFalse($e->report->ok);
            $this->assertFalse($e->report->invariants['receivables']['ok']);
            $this->assertFalse($e->report->invariants['entries']['ok']);
            $this->assertSame(1, $e->report->invariants['entries']['missing']);
            $this->assertTrue($e->report->invariants['payables']['ok'], 'payables untouched by this break');
            // الرسالة مبنية من أسماء الفحوصات اللي فشلت — entries وreceivables هنا
            $this->assertStringContainsString('entries', $e->getMessage());
            $this->assertStringContainsString('receivables', $e->getMessage());
        }

        $this->assertSame($before, $this->balances(), 'rolled back — balances unchanged');
        $this->assertSame($beforeEntryCount, GlEntry::count(), 'rolled back — entry count unchanged');
    }

    public function test_a_dry_run_returns_the_report_without_throwing_when_the_invariant_fails(): void
    {
        [$admin] = $this->world();
        $before = $this->balances();

        GlPostingRule::where('key', 'tx.sale')->update(['active' => false]);
        GlPostingRule::flush();

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, true, $admin);

        $this->assertFalse($report->ok);
        $this->assertFalse($report->invariants['receivables']['ok']);
        $this->assertFalse($report->invariants['entries']['ok']);
        $this->assertSame(1, $report->invariants['entries']['missing']);
        $this->assertTrue($report->invariants['payables']['ok']);
        $this->assertSame($before, $this->balances(), 'dry run never writes, even on failure');
    }

    public function test_rebuild_refuses_to_run_across_a_closed_period(): void
    {
        [$admin] = $this->world();
        GlPeriod::close('2026-08', $admin);

        $this->expectException(ClosedPeriod::class);
        app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);
    }

    public function test_a_settlement_with_no_to_at_is_not_lost_during_rebuild(): void
    {
        [$admin] = $this->world();
        $rep = $this->makeRep();
        $settlement = RepSettlement::create([
            'number' => 'RS-NULLTO', 'user_id' => $rep->id, 'from_at' => null, 'to_at' => null,
            'invoices_count' => 0, 'expected' => 0, 'received' => 500, 'balance' => 0, 'created_by' => $admin->id,
        ]);
        $ledger = app(Ledger::class);
        $ledger->post($settlement, $admin);
        $this->assertNotNull($ledger->autoEntryFor($settlement), 'sanity: it posts directly once');

        $report = $ledger->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);

        $this->assertTrue($report->ok);
        $this->assertSame(4, $report->deleted);
        $this->assertSame(4, $report->created);
        $this->assertNotNull($ledger->autoEntryFor($settlement), 'must survive the rebuild despite a null to_at');
    }
}
