<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشات دفتر الأستاذ — الشجرة واليومية والكشف والتقارير والإعدادات
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ الشاشات دي هي الواجهة الوحيدة اللي المحاسب بيشوف بيها الدفتر —
 * لو شاشة وقعت أو زرار رفض، الدفتر «مش موجود» بالنسبة له حتى لو
 * القيود كلها متظبطة في الداتابيز.
 */
class GlScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        $this->acc = $this->makeAdmin(['role' => 'accountant', 'email' => 'acc.screens@test.local']);
    }

    public function test_every_gl_screen_renders_for_the_accountant_with_export(): void
    {
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'فاتورة تيست', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);

        foreach (['gl.accounts', 'gl.entries', 'gl.trial_balance', 'gl.income', 'gl.balance_sheet', 'gl.settings'] as $r) {
            $this->actingAs($this->acc)->get(route($r))->assertOk();
        }
        $this->actingAs($this->acc)->get(route('gl.entries'))->assertSee('فاتورة تيست')->assertSee('JV-1001');
        $res = $this->actingAs($this->acc)->get(route('gl.trial_balance', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->actingAs($this->acc)->get(route('gl.accounts.show', GlAccount::findKey('receivables')))->assertOk()->assertSee('1,140.00');
    }

    public function test_the_accountant_can_add_a_free_account_and_the_admin_edits_a_system_name_only(): void
    {
        $this->actingAs($this->acc)->post(route('gl.accounts.store'), [
            'parent_id' => GlAccount::where('code', '5')->first()->id, 'code' => '5108', 'name' => 'اتصالات', 'name_en' => 'Telecom',
        ])->assertSessionHasNoErrors();
        $acc = GlAccount::where('code', '5108')->first();
        $this->assertSame('expense', $acc->type);
        $this->assertFalse($acc->is_system);

        $sys = GlAccount::findKey('bank');
        $this->actingAs($this->makeAdmin())->post(route('gl.accounts.update', $sys), ['name' => 'بنك CIB', 'name_en' => 'CIB', 'code' => '9999'])->assertSessionHasNoErrors();
        $sys->refresh();
        $this->assertSame('بنك CIB', $sys->name);
        $this->assertSame('1102', $sys->code, 'system code never changes');
    }

    public function test_a_manual_entry_from_the_screen_and_an_unbalanced_one_is_refused(): void
    {
        $lines = [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ];
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'إيجار', 'lines' => $lines])->assertSessionHasNoErrors();
        $this->assertSame(1, GlEntry::where('origin', 'manual')->count());

        $lines[1]['credit'] = 999;
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'x', 'lines' => $lines])->assertSessionHasErrors('lines');
    }

    public function test_a_manual_entry_on_a_control_account_is_refused(): void
    {
        // حساب العملاء بيتحرّك من مستنداته بس — الشاشة لازم ترجّع
        // الرسالة على خانة السطور مش 500
        $lines = [
            ['account_id' => GlAccount::findKey('receivables')->id, 'debit' => 50, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 50],
        ];
        $this->actingAs($this->acc)->post(route('gl.entries.store'), [
            'date' => today()->toDateString(), 'memo' => 'تحصيل يدوي', 'lines' => $lines,
        ])->assertSessionHasErrors('lines');
        $this->assertSame(0, GlEntry::where('origin', 'manual')->count());
    }

    public function test_the_override_button_changes_the_account_and_the_rebuild_preview_shows_a_rule_change(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $line = GlEntry::first()->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $this->actingAs($this->acc)->post(route('gl.entries.override', $line), ['account_id' => GlAccount::findKey('bank')->id, 'note' => 'تحويل'])->assertSessionHasNoErrors();
        $this->assertSame(GlAccount::findKey('bank')->id, $line->fresh()->account_id);

        GlPostingRule::where('key', 'tx.collection.office_cash')->update(['debit_key' => 'bank']);
        $this->actingAs($admin)->post(route('gl.rebuild.preview'), ['keep_overrides' => 1])->assertOk()->assertSee('1102');
        $this->assertSame(1, GlEntry::count(), 'preview writes nothing');

        $this->actingAs($this->acc)->post(route('gl.rebuild.preview'))->assertForbidden();
    }

    public function test_closing_a_period_blocks_manual_entries_inside_it(): void
    {
        $this->actingAs($this->acc)->post(route('gl.periods.close', '2026-07'))->assertRedirect();
        $this->assertTrue(GlPeriod::isClosed(\Illuminate\Support\Carbon::parse('2026-07-15')));

        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => '2026-07-15', 'memo' => 'x', 'lines' => [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1],
        ]])->assertSessionHasErrors('lines');
    }

    public function test_the_accountant_cannot_touch_the_admin_only_settings(): void
    {
        // الإعدادات نفسها شاشة محاسب — الكتابة فيها (القواعد، السويتش،
        // فتح فترة مقفولة، إعادة البناء) قرار أدمن
        $this->actingAs($this->acc)->post(route('gl.settings.general'), ['gl_enabled' => '0'])->assertForbidden();
        $this->actingAs($this->acc)->post(route('gl.periods.reopen', '2026-07'))->assertForbidden();
        $this->assertSame('1', Setting::read('gl_enabled'));
    }
}
