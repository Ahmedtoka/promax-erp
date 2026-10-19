<?php

namespace Tests\Feature\Cash;

use App\Models\CashMovement;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Safe;
use App\Models\Setting;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lane 4 Task 3: مستندات النقدية عبر الخزن (L4-D3/L4-D4) — تحويل خزنة
 * لخزنة، إيداع بنك من خزنة، تحويل بنك لبنك. حساب الخزنة بيحل محل مفتاح
 * القاعدة، وكل سطر عليه مركز خزنته.
 */
class CashDocumentsTest extends TestCase
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

    private function center(string $code): CostCenter
    {
        return CostCenter::create(['code' => $code, 'name' => 'مركز '.$code, 'name_en' => 'Center '.$code,
            'kind' => 'branch', 'parent_id' => CostCenter::company()->id, 'active' => true]);
    }

    private function safe(string $code, string $kind, ?CostCenter $center = null): Safe
    {
        $safe = Safe::create(['code' => $code, 'name' => 'خزنة '.$code, 'name_en' => 'Safe '.$code,
            'kind' => $kind, 'cost_center_id' => $center?->id]);
        $safe->ensureGlAccount();

        return $safe->fresh();
    }

    private function entryFor(CashMovement $m): GlEntry
    {
        return app(Ledger::class)->autoEntryFor($m) ?? $this->fail('no GL entry for '.$m->number);
    }

    private function postCash(array $data)
    {
        return $this->actingAs($this->makeAdmin())->post(route('gl.cash.store'),
            array_merge(['date' => today()->toDateString()], $data));
    }

    public function test_safe_to_safe_transfer_moves_balance_between_safes_with_centers_on_lines(): void
    {
        $ca = $this->center('CC-A');
        $cb = $this->center('CC-B');
        $a = $this->safe('SA', 'cash', $ca);
        $b = $this->safe('SB', 'cash', $cb);

        $this->postCash(['kind' => 'safe_transfer', 'amount' => 1500, 'from_safe_id' => $a->id, 'to_safe_id' => $b->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('gl.cash'));

        $m = CashMovement::firstOrFail();
        $this->assertSame($a->id, $m->from_safe_id);
        $this->assertSame($b->id, $m->to_safe_id);
        $this->assertSame($ca->id, $m->cost_center_id, 'document center = the from safe center');

        $this->assertSame(-1500.0, $a->balance());
        $this->assertSame(1500.0, $b->balance());

        $entry = $this->entryFor($m);
        $this->assertFalse((bool) $entry->needs_review);
        $lines = $entry->lines()->get()->keyBy('account_id');
        $this->assertCount(2, $lines);
        $this->assertSame($cb->id, $lines[$b->gl_account_id]->cost_center_id);
        $this->assertSame(1500.0, (float) $lines[$b->gl_account_id]->debit);
        $this->assertSame($ca->id, $lines[$a->gl_account_id]->cost_center_id);
        $this->assertSame(1500.0, (float) $lines[$a->gl_account_id]->credit);

        // الشاشة والتصدير بيعرضوا الخزنتين
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('gl.cash'))->assertOk()
            ->assertSee('name="from_safe_id"', false)->assertSee($a->displayName())->assertSee($b->displayName());
        $csv = $this->actingAs($admin)->get(route('gl.cash', ['export' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString($a->displayName(), $csv);
        $this->assertStringContainsString($b->displayName(), $csv);
    }

    public function test_bank_deposit_from_a_safe(): void
    {
        $ca = $this->center('CC-A');
        $cash = $this->safe('SA', 'cash', $ca);
        $bank = $this->safe('BK', 'bank');

        $this->postCash(['kind' => 'deposit', 'amount' => 4000, 'from_safe_id' => $cash->id, 'to_safe_id' => $bank->id])
            ->assertSessionHasNoErrors();

        $m = CashMovement::firstOrFail();
        $this->assertSame(-4000.0, $cash->balance());
        $this->assertSame(4000.0, $bank->balance());
        // حساب `bank` و`cash_main` العامين ماتحركوش — الخزنتين حلّوا محلّهم
        $this->assertSame(0.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(0.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));

        $entry = $this->entryFor($m);
        $lines = $entry->lines()->get();
        $this->assertCount(2, $lines);
        // البنك من غير مركز → مركز المستند (مركز خزنة المصدر)
        foreach ($lines as $l) {
            $this->assertSame($ca->id, $l->cost_center_id);
        }
    }

    public function test_bank_to_bank_transfer(): void
    {
        $c1 = $this->center('CC-1');
        $c2 = $this->center('CC-2');
        $b1 = $this->safe('BK1', 'bank', $c1);
        $b2 = $this->safe('BK2', 'bank', $c2);

        $this->postCash(['kind' => 'bank_transfer', 'amount' => 250.5, 'from_safe_id' => $b1->id, 'to_safe_id' => $b2->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(-250.5, $b1->balance());
        $this->assertSame(250.5, $b2->balance());

        $lines = $this->entryFor(CashMovement::firstOrFail())->lines()->get()->keyBy('account_id');
        $this->assertSame($c2->id, $lines[$b2->gl_account_id]->cost_center_id);
        $this->assertSame($c1->id, $lines[$b1->gl_account_id]->cost_center_id);
    }

    public function test_kind_must_match_safe_kinds(): void
    {
        $bank = $this->safe('BK', 'bank');
        $bank2 = $this->safe('BK2', 'bank');
        $cash = $this->safe('SA', 'cash');

        // إيداع من بنك → غلط على خانة المصدر
        $this->postCash(['kind' => 'deposit', 'amount' => 100, 'from_safe_id' => $bank->id, 'to_safe_id' => $bank2->id])
            ->assertSessionHasErrors('from_safe_id');
        // تحويل خزنة لخزنة لبنك → غلط على خانة الوجهة
        $this->postCash(['kind' => 'safe_transfer', 'amount' => 100, 'from_safe_id' => $cash->id, 'to_safe_id' => $bank->id])
            ->assertSessionHasErrors('to_safe_id');
        // التحويل من غير خزن مرفوض
        $this->postCash(['kind' => 'bank_transfer', 'amount' => 100])
            ->assertSessionHasErrors(['from_safe_id', 'to_safe_id']);

        $this->assertSame(0, CashMovement::count());
    }

    public function test_same_safe_on_both_sides_is_refused(): void
    {
        $a = $this->safe('SA', 'cash');

        $this->postCash(['kind' => 'safe_transfer', 'amount' => 100, 'from_safe_id' => $a->id, 'to_safe_id' => $a->id])
            ->assertSessionHasErrors('to_safe_id');

        $this->assertSame(0, CashMovement::count());
    }

    public function test_old_deposit_without_safes_posts_as_before(): void
    {
        $this->postCash(['kind' => 'deposit', 'amount' => 4000, 'reference' => 'DEP-1'])->assertSessionHasNoErrors();

        $m = CashMovement::firstOrFail();
        $this->assertNull($m->from_safe_id);
        $this->assertNull($m->to_safe_id);
        $this->assertNull($m->cost_center_id);

        $entry = $this->entryFor($m);
        $this->assertFalse((bool) $entry->needs_review);
        $lines = $entry->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(GlAccount::findKey('bank')->id, $lines[0]->account_id);
        $this->assertSame(4000.0, (float) $lines[0]->debit);
        $this->assertSame(GlAccount::findKey('cash_main')->id, $lines[1]->account_id);
        $this->assertSame(4000.0, (float) $lines[1]->credit);
        foreach ($lines as $l) {
            $this->assertNull($l->cost_center_id);
        }
        $this->assertSame(4000.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(-4000.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }
}
