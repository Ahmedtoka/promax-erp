<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\CoaDraftAccount;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLineOverride;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlRebuildRun;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\Ledger;
use App\Services\Gl\RebuildDiff;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إعادة البناء من المعالج (D8/D9): معاينة بفروق لكل شهر × نوع مصدر،
 * موافقة، تنفيذ. الشهور المقفولة بتتجمّد بدل ما ترفض.
 */
class RebuildDiffTest extends TestCase
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
        $sale = $mk(['date' => '2026-08-05', 'kind' => 'sale', 'debit' => 1140, 'tax' => 140]);
        $coll = $mk(['date' => '2026-08-20', 'kind' => 'collection', 'credit' => 600, 'method' => 'cash']);
        $mk(['date' => '2026-09-02', 'kind' => 'return', 'credit' => 114, 'tax' => 14]);
        $client->recalculate();

        return [$admin, $client, $sale, $coll];
    }

    private function approvedRun($admin): GlRebuildRun
    {
        $svc = app(RebuildDiff::class);
        $p = $svc->preview($admin);
        $this->assertSame('ok', $p->status, (string) $p->error);
        $svc->approve($p, $admin);

        return $svc->execute($p->fresh(), $admin);
    }

    public function test_rebuild_twice_without_change_gives_an_empty_diff_and_keeps_manual_entries_and_overrides(): void
    {
        [$admin, , , $coll] = $this->world();
        $ledger = app(Ledger::class);
        $line = $ledger->autoEntryFor($coll)->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);
        $ledger->overrideAccount($line, GlAccount::findKey('bank'), $admin, 'اتفاق');
        $manual = $ledger->manual(Carbon::parse('2026-08-10'), 'يدوي', [
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 50, 'credit' => 0],
            ['account_id' => GlAccount::findKey('opening_equity')->id, 'debit' => 0, 'credit' => 50],
        ], $admin);

        $first = $this->approvedRun($admin);
        $this->assertSame('execute', $first->mode);
        $this->assertSame('ok', $first->status, (string) $first->error);

        $second = $this->approvedRun($admin);
        $this->assertSame(0, $second->summary['created']);
        $this->assertSame(0, $second->summary['deleted']);
        $this->assertSame(0, $second->summary['changed']);
        $this->assertSame([], $second->rows);
        $this->assertSame(1, $second->summary['overrides_kept']);

        $this->assertNotNull(GlEntry::find($manual->id));
        $this->assertSame(600.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $newLine = $ledger->autoEntryFor($coll)->lines->firstWhere('slot', 'dr');
        $this->assertTrue($newLine->overridden);
        $this->assertNotNull(GlLineOverride::where('line_id', $newLine->id)->first());
    }

    public function test_changed_amount_is_reported_per_month_and_source(): void
    {
        [$admin, , $sale] = $this->world();
        // تعديل من ورا الدفتر — القيد لسه بالمبلغ القديم
        Transaction::whereKey($sale->id)->update(['debit' => 1240]);

        $run = app(RebuildDiff::class)->preview($admin);

        $this->assertSame('ok', $run->status);
        $this->assertSame(1, $run->summary['changed']);
        $this->assertSame(100.0, (float) $run->summary['changed_delta']);
        $row = collect($run->rows)->firstWhere('period', '2026-08');
        $this->assertSame('Transaction', $row['source']);
        $this->assertSame(1, $row['changed']);
        $this->assertSame(100.0, (float) $row['changed_delta']);
        $this->assertSame($sale->getMorphClass().'|'.$sale->id, $run->summary['samples'][0]['key']);
        // معاينة = ولا كتابة
        $this->assertEquals(1140, GlEntry::where('source_id', $sale->id)->where('origin', 'auto')->first()->lines->sum('debit'));
    }

    public function test_new_document_is_created_and_deleted_source_is_deleted_in_the_diff(): void
    {
        [$admin, $client, , $coll] = $this->world();
        // مستند من غير قيد (اتكتب من ورا الأوبزرفر) + مستند اتمسح وقيده فاضل
        DB::table('transactions')->insert(['client_id' => $client->id, 'date' => '2026-09-10', 'memo' => 'late',
            'debit' => 300, 'credit' => 0, 'tax' => 0, 'kind' => 'sale', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('transactions')->where('id', $coll->id)->delete();

        $run = app(RebuildDiff::class)->preview($admin);

        $this->assertSame(1, $run->summary['created']);
        $this->assertSame(300.0, (float) $run->summary['created_amount']);
        $this->assertSame(1, $run->summary['deleted']);
        $this->assertSame(600.0, (float) $run->summary['deleted_amount']);
        $this->assertSame(1, collect($run->rows)->firstWhere('period', '2026-09')['created']);
        $this->assertSame(1, collect($run->rows)->firstWhere('period', '2026-08')['deleted']);
    }

    public function test_execute_requires_an_approved_ok_preview(): void
    {
        [$admin] = $this->world();
        $svc = app(RebuildDiff::class);
        $p = $svc->preview($admin);

        try {
            $svc->execute($p, $admin);
            $this->fail('execute without approval');
        } catch (\InvalidArgumentException) {
        }

        // معاينة أقدم من ٢٤ ساعة مابتتوافقش
        GlRebuildRun::whereKey($p->id)->update(['created_at' => now()->subDays(2)]);
        try {
            $svc->approve($p->fresh(), $admin);
            $this->fail('stale preview approved');
        } catch (\InvalidArgumentException) {
        }

        $blocked = GlRebuildRun::create(['mode' => 'preview', 'from_date' => '2026-08-01', 'status' => 'blocked', 'created_by' => $admin->id]);
        try {
            $svc->approve($blocked, $admin);
            $this->fail('blocked preview approved');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, GlRebuildRun::where('mode', 'execute')->count());
    }

    public function test_blocked_when_mapping_incomplete_after_adoption(): void
    {
        [$admin] = $this->world();
        CoaDraftAccount::create(['parent_id' => null, 'sort' => 1, 'code' => '7100', 'name' => 'Bank X',
            'name_ar' => 'بنك', 'qb_type' => 'Bank', 'balance' => 10]);
        CoaAdopter::adopt($admin);
        $entries = GlEntry::count();

        $run = app(RebuildDiff::class)->preview($admin);

        $this->assertSame('blocked', $run->status);
        $this->assertNotEmpty($run->error);
        $this->assertSame($entries, GlEntry::count());
    }

    public function test_closed_month_is_frozen_not_refused(): void
    {
        [$admin, , $sale] = $this->world();
        GlPeriod::close('2026-08', $admin);
        $aug = GlEntry::where('period_key', '2026-08')->where('origin', 'auto')->pluck('id')->sort()->values()->all();
        $sep = GlEntry::where('period_key', '2026-09')->where('origin', 'auto')->pluck('id')->all();
        $this->assertCount(2, $aug);

        // الإعدادات القديمة بترفض — المعالج بيجمّد
        try {
            app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, true, $admin);
            $this->fail('strict rebuild must refuse');
        } catch (\App\Services\Gl\ClosedPeriod) {
        }

        $run = app(RebuildDiff::class)->preview($admin);
        $this->assertSame('ok', $run->status, (string) $run->error);
        $this->assertSame(['2026-08'], $run->summary['frozen']);
        $this->assertSame(0, $run->summary['changed']);

        app(RebuildDiff::class)->approve($run, $admin);
        $exec = app(RebuildDiff::class)->execute($run->fresh(), $admin);
        $this->assertSame('ok', $exec->status, (string) $exec->error);
        // قيود أغسطس هي هي (نفس الـids) — سبتمبر اتولّد من جديد
        $this->assertSame($aug, GlEntry::where('period_key', '2026-08')->where('origin', 'auto')->pluck('id')->sort()->values()->all());
        $this->assertNotSame($sep, GlEntry::where('period_key', '2026-09')->where('origin', 'auto')->pluck('id')->all());
    }

    public function test_checks_report_receivables_against_client_balances(): void
    {
        [$admin, $client] = $this->world();
        $run = app(RebuildDiff::class)->preview($admin);
        $recv = collect($run->checks)->firstWhere('key', 'receivables_total');
        $this->assertTrue($recv['ok']);
        $this->assertSame(426.0, (float) $recv['expected']);
        $this->assertTrue(collect($run->checks)->firstWhere('key', 'trial_balanced')['ok']);

        // مديونية قبل البداية من غير قيد افتتاح = الدفتر أقل من أرصدة العملاء
        Transaction::create(['client_id' => $client->id, 'date' => '2026-07-10', 'memo' => 'old',
            'debit' => 5000, 'credit' => 0, 'tax' => 0, 'kind' => 'sale']);
        $run = app(RebuildDiff::class)->preview($admin);
        $recv = collect($run->checks)->firstWhere('key', 'receivables_total');
        $this->assertFalse($recv['ok']);
        $this->assertSame(5426.0, (float) $recv['expected']);
        $this->assertSame(426.0, (float) $recv['actual']);

        RebuildDiff::addCheck('fake', fn () => ['label_key' => 'golive.check_trial_balanced', 'expected' => 1, 'actual' => 1, 'ok' => true]);
        $this->assertNotNull(collect(app(RebuildDiff::class)->checks())->firstWhere('key', 'fake'));
        RebuildDiff::removeCheck('fake');
    }

    public function test_only_the_latest_preview_runs_once_and_failed_checks_roll_back(): void
    {
        [$admin, $client] = $this->world();
        $svc = app(RebuildDiff::class);
        $old = $svc->preview($admin);
        $new = $svc->preview($admin);

        try {
            $svc->approve($old, $admin);
            $this->fail('an older preview was approved');
        } catch (\InvalidArgumentException) {
        }

        $svc->approve($new, $admin);
        $this->assertSame('ok', $svc->execute($new->fresh(), $admin)->status);
        try {
            $svc->execute($new->fresh(), $admin);
            $this->fail('the same preview ran twice');
        } catch (\InvalidArgumentException) {
        }

        // الداتا اتغيرت بعد الموافقة: مديونية قبل البداية من غير افتتاح ⇒ فحص العملاء يفشل وقت التنفيذ
        $p = $svc->preview($admin);
        $svc->approve($p, $admin);
        Transaction::create(['client_id' => $client->id, 'date' => '2026-07-10', 'memo' => 'old',
            'debit' => 5000, 'credit' => 0, 'tax' => 0, 'kind' => 'sale']);
        $ids = GlEntry::where('origin', 'auto')->pluck('id')->sort()->values()->all();

        $exec = $svc->execute($p->fresh(), $admin);

        $this->assertSame('failed', $exec->status);
        $this->assertFalse(collect($exec->checks)->firstWhere('key', 'receivables_total')['ok']);
        $this->assertSame($ids, GlEntry::where('origin', 'auto')->pluck('id')->sort()->values()->all(), 'rolled back');
    }
}
