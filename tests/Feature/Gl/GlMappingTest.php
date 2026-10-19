<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\CoaDraftAccount;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Visit;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\GlMapping;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الربط (D1): مفاتيح النظام بتتنقل على حسابات الشجرة المعتمدة، وخانة القاعدة
 * ممكن تتثبت على حساب بعينه. بعد الاعتماد (D5) القاعدة الناقصة بتوقف إعادة البناء.
 */
class GlMappingTest extends TestCase
{
    use RefreshDatabase;

    private int $sort = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();
    }

    private function row(array $attrs = []): CoaDraftAccount
    {
        return CoaDraftAccount::create(array_merge([
            'parent_id' => null, 'sort' => ++$this->sort, 'code' => (string) (7000 + $this->sort),
            'name' => 'Account '.$this->sort, 'name_ar' => 'حساب '.$this->sort,
            'qb_type' => 'Bank', 'balance' => 10,
        ], $attrs));
    }

    private function adopted(CoaDraftAccount $d): GlAccount
    {
        return GlAccount::where('coa_draft_id', $d->id)->firstOrFail();
    }

    private function sale(float $total = 1140, float $tax = 140): Transaction
    {
        return Transaction::create([
            'client_id' => $this->makeClient()->id, 'date' => '2026-09-01', 'memo' => 'فاتورة',
            'debit' => $total, 'credit' => 0, 'tax' => $tax, 'kind' => 'sale',
        ]);
    }

    private function entryFor(Transaction $tx): GlEntry
    {
        return GlEntry::where('source_type', $tx->getMorphClass())->where('source_id', $tx->id)
            ->where('origin', 'auto')->with('lines')->firstOrFail();
    }

    public function test_before_adoption_mapping_never_blocks(): void
    {
        $this->assertFalse(CoaAdopter::adopted());
        GlMapping::assertComplete();   // no throw

        $this->assertSame([], GlMapping::problems());
        foreach (GlMapping::keys() as $k) {
            $this->assertTrue($k['ok'], $k['key'].' ok on the seeded tree: '.($k['why'] ?? ''));
        }
        $this->assertSame(GlMapping::REQUIRED_KEYS, array_column(GlMapping::keys(), 'key'));
    }

    public function test_after_adoption_unmapped_key_blocks_rebuild_preview(): void
    {
        $this->sale();
        $this->row(['code' => '7100']);
        CoaAdopter::adopt($this->makeAdmin());
        $problems = GlMapping::problems();
        $this->assertNotEmpty($problems);

        $entries = GlEntry::count();
        $lines = \App\Models\Gl\GlLine::count();

        $this->actingAs($this->makeAdmin())->post(route('gl.rebuild.preview'))
            ->assertOk()->assertSee($problems[0]);
        $this->actingAs($this->makeAdmin())->post(route('gl.rebuild'))
            ->assertOk()->assertSee($problems[0]);

        $this->assertSame($entries, GlEntry::count());
        $this->assertSame($lines, \App\Models\Gl\GlLine::count());

        try {
            GlMapping::assertComplete();
            $this->fail('UnmappedRules expected');
        } catch (\App\Services\Gl\UnmappedRules $e) {
            $this->assertSame($problems, $e->problems);
        }
    }

    public function test_assign_key_moves_the_system_key(): void
    {
        $d = $this->row(['code' => '7201', 'qb_type' => 'Accounts Receivable', 'name' => 'Clients AR']);
        $admin = $this->makeAdmin();
        CoaAdopter::adopt($admin);
        $ar = $this->adopted($d);
        $old = GlAccount::findKey('receivables');

        GlMapping::assignKey('receivables', $ar, $admin);

        $this->assertNull($old->fresh()->system_key);
        $this->assertSame('receivables', $ar->fresh()->system_key);
        $row = collect(GlMapping::keys())->firstWhere('key', 'receivables');
        $this->assertTrue($row['ok']);
        $this->assertSame($ar->id, $row['account']->id);

        $e = $this->entryFor($this->sale());
        $this->assertSame(1140.0, (float) $e->lines->firstWhere('account_id', $ar->id)->debit);
        $this->assertNull($e->lines->firstWhere('account_id', $old->id));

        // إعادة السيدر مابترجّعش المفتاح للحساب القديم ولا بتلخبط الحساب المعتمد
        $this->seed(GlSeeder::class);
        $this->assertSame(1, GlAccount::where('system_key', 'receivables')->count());
        $this->assertSame($ar->id, GlAccount::where('system_key', 'receivables')->value('id'));
        $this->assertFalse((bool) $ar->fresh()->is_system);

        // حساب شايل مفتاح تاني مايتاخدش
        $this->expectException(\InvalidArgumentException::class);
        GlMapping::assignKey('payables', $ar->fresh(), $admin);
    }

    public function test_rep_cash_assignment_reparents_rep_accounts(): void
    {
        $rep = $this->makeRep(['code' => 'R1']);
        $rep2 = $this->makeRep(['code' => 'R2']);
        $repAcc = GlAccount::repCash($rep);
        $repAcc2 = GlAccount::repCash($rep2);
        $this->assertSame('1110.R1', $repAcc->code);
        $oldGroup = GlAccount::findKey('rep_cash');

        $d = $this->row(['code' => '7110', 'name' => 'Cash with reps']);
        $admin = $this->makeAdmin();
        CoaAdopter::adopt($admin);
        $to = $this->adopted($d);
        // كود متاخد ⇒ يتزوّد عليه .2
        GlAccount::create(['code' => '7110.R2', 'name' => 'x', 'type' => 'asset', 'normal_side' => 'debit', 'is_postable' => true, 'active' => true]);

        GlMapping::assignKey('rep_cash', $to, $admin);

        $to->refresh();
        $this->assertFalse($to->is_postable);
        $this->assertSame('rep_cash', $to->system_key);
        $this->assertNull($oldGroup->fresh()->system_key);
        $repAcc->refresh();
        $this->assertSame($to->id, $repAcc->parent_id);
        $this->assertSame('7110.R1', $repAcc->code);
        $this->assertSame($to->id, $repAcc2->fresh()->parent_id);
        $this->assertSame('7110.R2.2', $repAcc2->fresh()->code);

        $row = collect(GlMapping::keys())->firstWhere('key', 'rep_cash');
        $this->assertTrue($row['ok'], (string) $row['why']);

        // تحصيل كاش بمندوب بيروح نفس حساب المندوب (مش حساب جديد)
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $visit = Visit::create(['user_id' => $rep->id, 'client_id' => $client->id, 'checked_in_at' => now()]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحصيل', 'debit' => 0, 'credit' => 500, 'kind' => 'collection', 'method' => 'cash', 'source_type' => Visit::class, 'source_id' => $visit->id]);
        $this->assertSame(500.0, (float) $this->entryFor($tx)->lines->firstWhere('account_id', $repAcc->id)->debit);
        $this->assertSame(2, GlAccount::whereNotNull('user_id')->count());

        // حساب عليه سطور مايتحولش مجموعة
        $d2 = $this->row(['code' => '7120', 'name' => 'Busy']);
        CoaAdopter::adopt($admin);
        // إعادة الاعتماد مابترجّعش مجموعة المناديب حساب ترحيل
        $this->assertFalse($to->fresh()->is_postable);
        $this->assertSame($to->id, $repAcc->fresh()->parent_id);
        $busy = $this->adopted($d2);
        GlMapping::assignKey('cash_main', $busy, $admin);
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'كاش مكتب', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $this->assertTrue($busy->lines()->exists());
        GlAccount::where('id', $busy->id)->update(['system_key' => null]);
        $this->expectException(\InvalidArgumentException::class);
        GlMapping::assignKey('rep_cash', $busy->fresh(), $admin);
    }

    public function test_pinned_rule_slot_wins_over_the_key(): void
    {
        $d = $this->row(['code' => '7401', 'qb_type' => 'Income', 'name' => 'Sales Van']);
        CoaAdopter::adopt($this->makeAdmin());
        $van = $this->adopted($d);
        $rule = GlPostingRule::forKey('tx.sale');

        GlMapping::pin($rule, 'credit', $van);
        $this->assertSame($van->id, GlPostingRule::forKey('tx.sale')->credit_account_id);

        $e = $this->entryFor($this->sale());
        $this->assertSame(1000.0, (float) $e->lines->firstWhere('account_id', $van->id)->credit);
        $this->assertSame(1140.0, (float) $e->lines->firstWhere('account_id', GlAccount::findKey('receivables')->id)->debit);
        $this->assertSame(140.0, (float) $e->lines->firstWhere('account_id', GlAccount::findKey('vat_output')->id)->credit);
        $this->assertNull($e->lines->firstWhere('account_id', GlAccount::findKey('sales')->id));
        $this->assertFalse((bool) $e->needs_review);

        $slot = collect(GlMapping::rules())->first(fn ($r) => $r['rule']->key === 'tx.sale')['slots']['credit'];
        $this->assertTrue($slot['pinned']);
        $this->assertSame($van->id, $slot['account']->id);

        // حساب مثبّت اتقفل ⇒ المعلّق + مراجعة
        $van->update(['active' => false]);
        $e2 = $this->entryFor($this->sale());
        $this->assertSame(1000.0, (float) $e2->lines->firstWhere('account_id', GlAccount::findKey('suspense')->id)->credit);
        $this->assertTrue((bool) $e2->needs_review);

        // تثبيت على حساب موقوف / خانة rep_cash / مدين المصروف مرفوض
        foreach ([
            fn () => GlMapping::pin($rule, 'credit', $van->fresh()),
            fn () => GlMapping::pin(GlPostingRule::forKey('tx.collection.rep_cash'), 'debit', GlAccount::findKey('cash_main')),
            fn () => GlMapping::pin(GlPostingRule::forKey('expense.bank'), 'debit', GlAccount::findKey('expense_other')),
        ] as $i => $bad) {
            try {
                $bad();
                $this->fail('refusal expected #'.$i);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        GlMapping::pin($rule, 'credit', null);
        $this->assertNull(GlPostingRule::forKey('tx.sale')->credit_account_id);
    }

    public function test_mapping_screen_lists_every_rule_and_is_admin_write_only(): void
    {
        $admin = $this->makeAdmin();
        $page = $this->actingAs($admin)->get(route('gl.mapping'))->assertOk()
            ->assertSee(route('gl.mapping.keys'), false)
            ->assertSee(route('gl.mapping.rules'), false);
        foreach (GlPostingRule::pluck('key') as $key) {
            $page->assertSee($key);
        }

        $accountant = $this->makeAdmin(['role' => 'accountant']);
        $this->actingAs($accountant)->get(route('gl.mapping'))->assertOk()
            ->assertDontSee(route('gl.mapping.keys'), false)
            ->assertDontSee(route('gl.mapping.rules'), false);
        $this->actingAs($accountant)->post(route('gl.mapping.keys'), ['keys' => []])->assertForbidden();
        $this->actingAs($accountant)->post(route('gl.mapping.rules'), ['pins' => []])->assertForbidden();

        // كتابة الأدمن: الكل أو ولا حاجة
        $d = $this->row(['code' => '7401', 'qb_type' => 'Income', 'name' => 'Sales Van']);
        CoaAdopter::adopt($admin);
        $van = $this->adopted($d);
        $group = GlAccount::where('code', '4')->firstOrFail();

        $this->actingAs($admin)->post(route('gl.mapping.rules'), ['pins' => [
            'tx.sale' => ['credit' => $van->id],
            'tx.return' => ['debit' => $group->id],   // مجموعة ⇒ مرفوض
        ]])->assertSessionHasErrors();
        $this->assertNull(GlPostingRule::forKey('tx.sale')->credit_account_id);

        $this->actingAs($admin)->post(route('gl.mapping.rules'), ['pins' => [
            'tx.sale' => ['credit' => $van->id, 'debit' => ''],
        ]])->assertRedirect(route('gl.mapping'));
        $this->assertSame($van->id, GlPostingRule::forKey('tx.sale')->credit_account_id);

        $this->actingAs($admin)->post(route('gl.mapping.keys'), ['keys' => [
            'sales' => $van->id,
            'payables' => $group->id,   // مجموعة ⇒ مرفوض
        ]])->assertSessionHasErrors();
        $this->assertNotSame($van->id, GlAccount::findKey('sales')->id);

        $this->actingAs($admin)->post(route('gl.mapping.keys'), ['keys' => ['sales' => $van->id]])
            ->assertRedirect(route('gl.mapping'));
        $this->assertSame($van->id, GlAccount::findKey('sales')->id);
    }
}
