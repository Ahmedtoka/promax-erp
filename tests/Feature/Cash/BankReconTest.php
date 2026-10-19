<?php

namespace Tests\Feature\Cash;

use App\Models\BankStatement;
use App\Models\BankStatementMark;
use App\Models\Safe;
use App\Models\Setting;
use App\Services\Cash\BankRecon;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lane 4 Task 5: تسوية البنك (L4-D6) — العلامات على `line_key` ثابت في
 * `bank_statement_marks`، مفيش كتابة في `gl_*`.
 */
class BankReconTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();
        $this->admin = $this->makeAdmin();
    }

    private function safe(string $code, string $kind): Safe
    {
        $safe = Safe::create(['code' => $code, 'name' => 'خزنة '.$code, 'name_en' => 'Safe '.$code, 'kind' => $kind,
            'bank_name' => $kind === 'bank' ? 'CIB' : null]);
        $safe->ensureGlAccount();

        return $safe->fresh();
    }

    /** إيداع من الخزنة للبنك بتاريخ — قيد آلي على حساب البنك */
    private function deposit(Safe $cash, Safe $bank, float $amount, string $date): void
    {
        $this->actingAs($this->admin)->post(route('gl.cash.store'), [
            'kind' => 'deposit', 'amount' => $amount, 'date' => $date,
            'from_safe_id' => $cash->id, 'to_safe_id' => $bank->id,
        ])->assertSessionHasNoErrors();
    }

    private function statement(Safe $bank, array $over = []): BankStatement
    {
        $this->actingAs($this->admin)->post(route('gl.recon.store'), array_merge([
            'safe_id' => $bank->id, 'date' => '2026-09-30', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
            'opening_balance' => 0, 'closing_balance' => 0,
        ], $over))->assertSessionHasNoErrors();

        return BankStatement::latest('id')->firstOrFail();
    }

    private function world(): array
    {
        $cash = $this->safe('SA', 'cash');
        $bank = $this->safe('BK', 'bank');
        $this->deposit($cash, $bank, 700, '2026-08-20');   // قبل الكشف — بند معلّق
        $this->deposit($cash, $bank, 1000, '2026-09-05');
        $this->deposit($cash, $bank, 2500, '2026-09-18');
        $this->deposit($cash, $bank, 400, '2026-10-02');   // بعد الكشف

        return [$cash, $bank];
    }

    private function keys(BankStatement $s): array
    {
        return app(BankRecon::class)->lines($s->safe, $s->date_from, $s->date_to)->pluck('line_key')->all();
    }

    public function test_statement_lists_the_bank_lines_in_its_window(): void
    {
        [, $bank] = $this->world();
        $lines = app(BankRecon::class)->lines($bank, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $this->assertSame([1000.0, 2500.0], $lines->pluck('amount')->all());
        $this->assertStringStartsWith('CashMovement|', $lines->first()->line_key);
        $this->assertNull($lines->first()->statement_id);

        $s = $this->statement($bank, ['closing_balance' => 3500]);
        $this->assertStringStartsWith('BST-', $s->number);
        // الشاشة: سطور الفترة + المعلّق قبلها (700)، ومن غير اللي بعدها (400)
        $this->actingAs($this->admin)->get(route('gl.recon.show', $s))->assertOk()
            ->assertSee('1,000.00')->assertSee('2,500.00')->assertSee('700.00')->assertDontSee('400.00')
            ->assertSee(__('cash.recon_carried'));
        $csv = $this->actingAs($this->admin)->get(route('gl.recon.show', [$s, 'export' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-09-18', $csv);
        $this->assertStringContainsString('2026-08-20', $csv);
        $this->actingAs($this->admin)->get(route('gl.recon', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk()->assertSee($s->number);
        $this->assertStringContainsString($s->number,
            $this->actingAs($this->admin)->get(route('gl.recon', ['export' => 1, 'from' => '2026-09-01', 'to' => '2026-09-30']))->streamedContent());
    }

    public function test_marking_lines_moves_them_to_cleared_and_difference_reaches_zero(): void
    {
        [, $bank] = $this->world();
        $s = $this->statement($bank, ['opening_balance' => 700, 'closing_balance' => 4200]);
        $recon = app(BankRecon::class);
        $this->assertSame(3500.0, $recon->summary($s)['difference']);

        // الإغلاق مرفوض والفرق مش صفر
        $this->actingAs($this->admin)->post(route('gl.recon.close', $s))->assertSessionHasErrors('close');

        $keys = $this->keys($s);
        $this->actingAs($this->admin)->post(route('gl.recon.mark', $s), ['line_keys' => [$keys[0]]])->assertSessionHasNoErrors();
        $sum = $recon->summary($s->fresh());
        $this->assertSame(1700.0, $sum['cleared']);
        $this->assertSame(2500.0, $sum['difference']);

        $this->actingAs($this->admin)->post(route('gl.recon.mark', $s), ['line_keys' => $keys])->assertSessionHasNoErrors();
        $sum = $recon->summary($s->fresh());
        $this->assertSame(['book' => 4200.0, 'cleared' => 4200.0, 'statement' => 4200.0, 'difference' => 0.0], $sum);

        // شيل علامة = تتمسح
        $this->actingAs($this->admin)->post(route('gl.recon.mark', $s), ['line_keys' => [$keys[1]]])->assertSessionHasNoErrors();
        $this->assertSame(1, BankStatementMark::where('statement_id', $s->id)->count());
        $this->actingAs($this->admin)->post(route('gl.recon.mark', $s), ['line_keys' => $keys]);

        $this->actingAs($this->admin)->post(route('gl.recon.close', $s))->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertSame('closed', $s->status);
        $this->assertSame($this->admin->id, $s->closed_by);
        $this->assertNotNull($s->closed_at);
    }

    public function test_marks_survive_a_rebuild(): void
    {
        [, $bank] = $this->world();
        $s = $this->statement($bank, ['closing_balance' => 3500]);
        $recon = app(BankRecon::class);
        $before = $recon->lines($bank, $s->date_from, $s->date_to);
        $this->actingAs($this->admin)->post(route('gl.recon.mark', $s), ['line_keys' => $before->pluck('line_key')->all()])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, $recon->summary($s->fresh())['difference']);

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-01-01'), true, false, $this->admin);
        $this->assertTrue($report->ok);

        $after = $recon->lines($bank, $s->date_from, $s->date_to);
        $this->assertNotEquals($before->pluck('id')->all(), $after->pluck('id')->all(), 'rebuild recreated the lines');
        $this->assertSame($before->pluck('line_key')->all(), $after->pluck('line_key')->all());
        foreach ($after as $l) {
            $this->assertSame($s->id, $l->statement_id);
        }
        $this->assertSame(0.0, $recon->summary($s->fresh())['difference']);
    }

    public function test_a_line_cannot_be_marked_on_two_statements(): void
    {
        [, $bank] = $this->world();
        $a = $this->statement($bank);
        $b = $this->statement($bank, ['date_from' => '2026-09-15']);
        $k = app(BankRecon::class)->lines($bank, Carbon::parse('2026-09-15'), Carbon::parse('2026-09-30'))->first()->line_key;

        $this->actingAs($this->admin)->post(route('gl.recon.mark', $a), ['line_keys' => [$k]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('gl.recon.mark', $b), ['line_keys' => [$k]])
            ->assertSessionHasErrors('line_keys');
        $this->assertSame(0, BankStatementMark::where('statement_id', $b->id)->count());
        $this->assertSame($a->id, BankStatementMark::where('line_key', $k)->value('statement_id'));

        // مفتاح مش من سطور البنك ده مرفوض
        $this->actingAs($this->admin)->post(route('gl.recon.mark', $b), ['line_keys' => ['Bogus|1|dr']])
            ->assertSessionHasErrors('line_keys');
    }

    public function test_closed_statement_refuses_marks(): void
    {
        [, $bank] = $this->world();
        $s = $this->statement($bank, ['opening_balance' => 0, 'closing_balance' => 0]);
        $this->actingAs($this->admin)->post(route('gl.recon.close', $s))->assertSessionHasNoErrors();
        $this->assertSame('closed', $s->fresh()->status);

        $this->actingAs($this->admin)->post(route('gl.recon.mark', $s), ['line_keys' => $this->keys($s)])
            ->assertSessionHasErrors('line_keys');
        $this->assertSame(0, BankStatementMark::count());
    }

    public function test_only_bank_safes(): void
    {
        $cash = $this->safe('SA', 'cash');
        $off = $this->safe('BK2', 'bank');
        $off->update(['active' => false]);
        $bank = $this->safe('BK', 'bank');

        foreach ([$cash, $off] as $sf) {
            $this->actingAs($this->admin)->post(route('gl.recon.store'), [
                'safe_id' => $sf->id, 'date' => '2026-09-30', 'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
                'opening_balance' => 0, 'closing_balance' => 0,
            ])->assertSessionHasErrors('safe_id');
        }
        $this->actingAs($this->admin)->post(route('gl.recon.store'), [
            'safe_id' => $bank->id, 'date' => '2026-09-30', 'date_from' => '2026-09-10', 'date_to' => '2026-09-01',
            'opening_balance' => 0, 'closing_balance' => 0,
        ])->assertSessionHasErrors('date_to');
        $this->assertSame(0, BankStatement::count());
    }
}
