<?php

namespace Tests\Feature\Gl;

use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlGoLiveStep;
use App\Models\Gl\GlOpeningLine;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlRebuildRun;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\GoLive\GoLiveWizard;
use App\Services\Gl\GoLive\Step;
use App\Services\Gl\GoLive\StepState;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** معالج تشغيل الدفتر — ٩ خطوات، مكمّل من مكان ما وقف (الخط ٣) */
class GoLiveWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
    }

    protected function tearDown(): void
    {
        GoLiveWizard::forget(7);
        parent::tearDown();
    }

    public function test_wizard_opens_on_the_current_step_and_lists_nine_steps(): void
    {
        $admin = $this->makeAdmin();
        $this->assertCount(9, GoLiveWizard::steps());
        $this->assertSame(range(1, 9), array_keys(GoLiveWizard::steps()));

        $this->actingAs($admin)->get(route('gl.golive.index'))->assertRedirect(route('gl.golive.show', 1));

        $page = $this->actingAs($admin)->get(route('gl.golive.show', 1))->assertOk();
        foreach (GoLiveWizard::steps() as $s) {
            $page->assertSee(__($s->titleKey()));
        }
        $this->actingAs($admin)->get(route('gl.golive.show', 10))->assertNotFound();
    }

    public function test_step_with_red_cannot_be_confirmed(): void
    {
        $admin = $this->makeAdmin();
        $this->assertSame('red', GoLiveWizard::step(1)->state()->level);

        $this->actingAs($admin)->post(route('gl.golive.confirm', 1))->assertSessionHasErrors('golive');

        $this->assertSame('todo', GlGoLiveStep::for('start')->status);
        $this->assertSame(1, GoLiveWizard::current());
    }

    public function test_confirm_persists_and_resumes(): void
    {
        $admin = $this->makeAdmin();
        // قفل الشهور قبل التشغيل الفعلي مرفوض (كانت هتتجمّد فاضية)
        $this->actingAs($admin)->post(route('gl.golive.start'), [
            'gl_start_date' => '2026-08-01', 'gl_close_policy' => 'manual', 'close_through' => '2026-08',
        ])->assertSessionHasErrors('close_through');
        $this->assertSame(0, GlPeriod::count());

        $this->actingAs($admin)->post(route('gl.golive.start'), [
            'gl_start_date' => '2026-08-01', 'gl_close_policy' => 'manual', 'gl_close_after_days' => 10,
        ])->assertRedirect(route('gl.golive.show', 1));
        Setting::flushCache();
        $this->assertSame('2026-08-01', Setting::read('gl_start_date'));

        // بعد تشغيل ناجح: بيقفل من شهر البداية بس، ومش الشهر الحالي
        GlRebuildRun::create(['mode' => 'execute', 'from_date' => '2026-08-01', 'status' => 'ok']);
        $this->actingAs($admin)->post(route('gl.golive.start'), [
            'gl_start_date' => '2026-08-01', 'gl_close_policy' => 'manual', 'close_through' => today()->format('Y-m'),
        ])->assertSessionHasErrors('close_through');
        $this->actingAs($admin)->post(route('gl.golive.start'), [
            'gl_start_date' => '2026-08-01', 'gl_close_policy' => 'manual', 'close_through' => '2026-08',
        ])->assertRedirect(route('gl.golive.show', 1));
        $this->assertSame('closed', GlPeriod::where('key', '2026-08')->value('status'));
        $this->assertNull(GlPeriod::where('key', '2026-07')->first(), 'never closes a month before the start');
        $this->assertSame('green', GoLiveWizard::step(1)->state()->level);

        $this->actingAs($admin)->post(route('gl.golive.confirm', 1))->assertRedirect(route('gl.golive.show', 2));
        Setting::flushCache();
        $this->assertSame('done', GlGoLiveStep::for('start')->status);
        $this->assertSame(2, GoLiveWizard::current());
        $this->actingAs($admin)->get(route('gl.golive.index'))->assertRedirect(route('gl.golive.show', 2));

        // الحالة حية: البداية اتشالت ⇒ الخطوة اللي اتأكدت تبان حمرا تاني
        Setting::write('gl_start_date', null);
        Setting::flushCache();
        $this->assertSame('red', GoLiveWizard::step(1)->state()->level);
        $this->assertTrue(GoLiveWizard::done(GoLiveWizard::step(1)));
    }

    public function test_lane9_can_replace_a_placeholder_step(): void
    {
        GoLiveWizard::register(new class implements Step
        {
            public function number(): int
            {
                return 7;
            }

            public function key(): string
            {
                return 'costing';
            }

            public function titleKey(): string
            {
                return 'Fake costing step';
            }

            public function state(): StepState
            {
                return new StepState('green');
            }

            public function view(): string
            {
                return 'gl.golive.steps.placeholder';
            }

            public function data(): array
            {
                return ['note' => 'lane nine body'];
            }
        });

        $this->actingAs($this->makeAdmin())->get(route('gl.golive.show', 7))
            ->assertOk()->assertSee('Fake costing step')->assertSee('lane nine body');
        $this->assertCount(9, GoLiveWizard::steps());
    }

    public function test_wizard_write_routes_are_admin_only(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant']);
        $this->actingAs($acc)->get(route('gl.golive.show', 1))->assertOk()->assertDontSee(__('golive.confirm_step'));
        $this->actingAs($acc)->post(route('gl.golive.confirm', 1))->assertForbidden();
        $this->actingAs($acc)->post(route('gl.golive.start'), ['gl_start_date' => '2026-08-01', 'gl_close_policy' => 'manual'])->assertForbidden();
        $this->actingAs($acc)->post(route('gl.golive.opening.post'))->assertForbidden();
        $this->actingAs($acc)->post(route('gl.golive.preview'))->assertForbidden();

        $rep = $this->makeRep();
        $this->actingAs($rep)->get(route('gl.golive.show', 1))->assertForbidden();
    }

    public function test_opening_step_posts_the_entry_and_shows_the_difference(): void
    {
        $admin = $this->makeAdmin();
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        $client = $this->makeClient();

        $this->actingAs($admin)->post(route('gl.golive.opening.lines.add'), [
            'account_id' => GlAccount::findKey('cash_main')->id, 'side' => 'debit', 'amount' => 1000,
        ])->assertRedirect(route('gl.golive.show', 6));
        $this->assertSame(1, GlOpeningLine::count());
        // حساب العملاء ممنوع يدوي
        $this->actingAs($admin)->post(route('gl.golive.opening.lines.add'), [
            'account_id' => GlAccount::findKey('receivables')->id, 'side' => 'debit', 'amount' => 5,
        ])->assertSessionHasErrors('golive');

        $this->actingAs($admin)->post(route('gl.golive.opening.client'), ['client_id' => $client->id, 'amount' => 250])
            ->assertRedirect(route('gl.golive.show', 6));
        $this->assertEquals(250, $client->fresh()->balance);
        $this->assertSame('2026-07-31', Transaction::where('idem_key', 'golive-opening:client:'.$client->id)->sole()->date->toDateString());

        $this->assertSame('yellow', GoLiveWizard::step(6)->state()->level);
        $this->actingAs($admin)->post(route('gl.golive.opening.post'))->assertRedirect(route('gl.golive.show', 6));

        $entry = GlEntry::where('rule_key', 'golive.opening')->sole();
        $this->assertSame('2026-07-31', $entry->date->toDateString());
        // كاش 1000 + عملاء 250 مدين = فرق 1250 دائن
        $this->assertEquals(1250, $entry->lines->firstWhere('account_id', GlAccount::openingDifference()->id)->credit);

        $page = $this->actingAs($admin)->get(route('gl.golive.show', 6))->assertOk();
        $page->assertSee(__('golive.s6_difference', ['amount' => number_format(1250, 2)]));
        $this->assertSame('yellow', GoLiveWizard::step(6)->state()->level);

        // سطر حقوق ملكية يقفل الفرق ⇒ أخضر
        $this->actingAs($admin)->post(route('gl.golive.opening.lines.add'), [
            'account_id' => GlAccount::findKey('opening_equity')->id, 'side' => 'credit', 'amount' => 1250,
        ]);
        $this->actingAs($admin)->post(route('gl.golive.opening.post'));
        $this->assertSame(1, GlEntry::where('rule_key', 'golive.opening')->count());
        $this->assertSame('green', GoLiveWizard::step(6)->state()->level);

        // البحث بالاسم
        $this->actingAs($admin)->get(route('gl.golive.show', ['n' => 6, 'q' => mb_substr($client->name, 0, 4)]))
            ->assertOk()->assertSee($client->displayName());
    }

    public function test_preview_step_runs_and_approves(): void
    {
        $admin = $this->makeAdmin();
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        CostCenter::maadi();
        Transaction::create(['client_id' => $this->makeClient()->id, 'date' => '2026-08-05', 'memo' => 'sale',
            'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);
        $entries = GlEntry::count();

        $this->actingAs($admin)->post(route('gl.golive.preview'))->assertRedirect(route('gl.golive.show', 8));
        $run = GlRebuildRun::where('mode', 'preview')->sole();
        $this->assertSame('ok', $run->status, (string) $run->error);
        $this->assertSame($entries, GlEntry::count());
        $this->assertSame('yellow', GoLiveWizard::step(8)->state()->level);

        $this->actingAs($admin)->get(route('gl.golive.show', 8))->assertOk()->assertSee(__('golive.s8_approve'));
        $csv = $this->actingAs($admin)->get(route('gl.golive.show', ['n' => 8, 'export' => 1]));
        $csv->assertOk();

        $this->actingAs($admin)->post(route('gl.golive.preview.approve', $run))->assertRedirect(route('gl.golive.show', 8));
        $this->assertNotNull($run->fresh()->approved_at);
        $this->assertSame('green', GoLiveWizard::step(8)->state()->level);
        $this->actingAs($admin)->get(route('gl.golive.show', 9))->assertOk()->assertSee(__('golive.s9_note_ready'));
    }

    public function test_start_cannot_move_past_a_closed_month_with_entries(): void
    {
        $admin = $this->makeAdmin();
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        Transaction::create(['client_id' => $this->makeClient()->id, 'date' => '2026-08-05', 'memo' => 'sale',
            'debit' => 100, 'credit' => 0, 'tax' => 0, 'kind' => 'sale']);
        GlPeriod::close('2026-08', $admin);
        $entries = GlEntry::count();

        $this->actingAs($admin)->post(route('gl.golive.start'), ['gl_start_date' => '2026-09-01', 'gl_close_policy' => 'manual'])
            ->assertSessionHasErrors('gl_start_date');
        $this->actingAs($admin)->post(route('gl.settings.general'), ['gl_start_date' => '2026-09-01', 'gl_enabled' => 1])
            ->assertSessionHasErrors('gl_start_date');

        Setting::flushCache();
        $this->assertSame('2026-08-01', Setting::read('gl_start_date'));
        $this->assertSame($entries, GlEntry::count());
    }
}
