<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Gl\GlPeriod;
use App\Models\HrPenalty;
use App\Models\Safe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * السلف والجزاءات (Lane 7 — المهمة ٥). القيد المحاسبي في المهمة ٧.
 */
class AdvancesTest extends TestCase
{
    use RefreshDatabase;

    private function hrUser(): User
    {
        return $this->makeAdmin(['role' => 'hr', 'email' => 'hr'.uniqid().'@promax.test']);
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge(['code' => Employee::nextCode(), 'name' => 'موظف'], $attrs));
    }

    private function safe(array $attrs = []): Safe
    {
        return Safe::create(array_merge(['code' => 'S'.uniqid(), 'name' => 'خزنة', 'kind' => 'cash'], $attrs));
    }

    private function advance(array $attrs = []): EmployeeAdvance
    {
        return EmployeeAdvance::create(array_merge([
            'number' => 'ADV-'.random_int(2000, 9999),
            'employee_id' => $this->employee()->id,
            'date' => '2026-07-10',
            'amount' => 3000,
            'installments' => 3,
            'installment_amount' => 1000,
            'start_period' => '2026-08',
            'recovered' => 0,
            'status' => 'open',
        ], $attrs));
    }

    public function test_advance_installment_amount_is_amount_over_installments(): void
    {
        $emp = $this->employee();
        $safe = $this->safe();

        $this->actingAs($this->hrUser())
            ->post('/hr/advances', [
                'employee_id' => $emp->id, 'date' => '2026-07-10', 'amount' => 3000,
                'installments' => 3, 'start_period' => '2026-08', 'safe_id' => $safe->id,
            ])->assertStatus(302)->assertSessionHasNoErrors();

        $a = EmployeeAdvance::firstOrFail();
        $this->assertSame('ADV-1001', $a->number);
        $this->assertSame('1000.00', (string) $a->installment_amount);
        $this->assertSame('open', $a->status);
    }

    public function test_installment_never_exceeds_remaining(): void
    {
        $a = $this->advance(['recovered' => 2500]);

        $this->assertEqualsWithDelta(500.0, $a->remaining(), 0.001);
        $this->assertEqualsWithDelta(500.0, $a->installmentFor('2026-10'), 0.001);
    }

    public function test_last_installment_takes_the_rounding_remainder(): void
    {
        $a = $this->advance(['amount' => 1000, 'installments' => 3, 'installment_amount' => 333.33]);

        $this->assertEqualsWithDelta(333.33, $a->installmentFor('2026-08'), 0.001);
        $a->recovered = 666.66;
        $this->assertEqualsWithDelta(333.34, $a->installmentFor('2026-10'), 0.001);
    }

    public function test_installment_waits_for_start_period(): void
    {
        $a = $this->advance();

        $this->assertSame(0.0, $a->installmentFor('2026-07'));
        $this->assertEqualsWithDelta(1000.0, $a->installmentFor('2026-08'), 0.001);
        $this->assertEqualsWithDelta(1000.0, $a->installmentFor('2026-09'), 0.001);

        $a->status = 'void';
        $this->assertSame(0.0, $a->installmentFor('2026-09'));
    }

    public function test_void_only_before_recovery(): void
    {
        $hr = $this->hrUser();
        $fresh = $this->advance();
        $paid = $this->advance(['recovered' => 1000]);

        $this->actingAs($hr)->post("/hr/advances/{$fresh->id}/void")->assertSessionHasNoErrors();
        $this->assertSame('void', $fresh->fresh()->status);

        $this->actingAs($hr)->post("/hr/advances/{$paid->id}/void")->assertSessionHasErrors();
        $this->assertSame('open', $paid->fresh()->status);
    }

    public function test_penalty_store_and_delete_until_deducted(): void
    {
        $hr = $this->hrUser();
        $emp = $this->employee();

        $this->actingAs($hr)->post('/hr/penalties', [
            'employee_id' => $emp->id, 'date' => '2026-07-12', 'amount' => 150, 'reason' => 'تأخير متكرر',
        ])->assertStatus(302)->assertSessionHasNoErrors();

        $p = HrPenalty::firstOrFail();
        $this->actingAs($hr)->post("/hr/penalties/{$p->id}/delete")->assertSessionHasNoErrors();
        $this->assertSame(0, HrPenalty::count());

        $deducted = HrPenalty::create([
            'employee_id' => $emp->id, 'date' => '2026-07-12', 'amount' => 50, 'reason' => 'x', 'payslip_id' => 99,
        ]);
        $this->actingAs($hr)->post("/hr/penalties/{$deducted->id}/delete")->assertSessionHasErrors();
        $this->assertSame(1, HrPenalty::count());
    }

    public function test_advances_export_respects_range(): void
    {
        $this->advance(['number' => 'ADV-3001', 'date' => '2026-07-10']);
        $this->advance(['number' => 'ADV-3002', 'date' => '2026-09-10']);

        $res = $this->actingAs($this->hrUser())
            ->get('/hr/advances?export=1&from=2026-07-01&to=2026-07-31');

        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('ADV-3001', $csv);
        $this->assertStringNotContainsString('ADV-3002', $csv);
    }

    public function test_closed_gl_month_rejects_advance(): void
    {
        $admin = $this->makeAdmin();
        GlPeriod::close('2026-07', $admin);
        $emp = $this->employee();
        $safe = $this->safe();

        $this->actingAs($this->hrUser())
            ->post('/hr/advances', [
                'employee_id' => $emp->id, 'date' => '2026-07-10', 'amount' => 600,
                'installments' => 2, 'start_period' => '2026-08', 'safe_id' => $safe->id,
            ])->assertSessionHasErrors();

        $this->assertSame(0, EmployeeAdvance::count());
    }

    public function test_employee_outside_scope_and_inactive_safe_rejected(): void
    {
        $emp = $this->employee(['active' => false]);
        $safe = $this->safe(['active' => false]);
        $hr = $this->hrUser();

        $this->actingAs($hr)->post('/hr/advances', [
            'employee_id' => $emp->id, 'date' => '2026-07-10', 'amount' => 600,
            'installments' => 2, 'start_period' => '2026-08', 'safe_id' => $this->safe()->id,
        ])->assertSessionHasErrors('employee_id');

        $this->actingAs($hr)->post('/hr/advances', [
            'employee_id' => $this->employee()->id, 'date' => '2026-07-10', 'amount' => 600,
            'installments' => 2, 'start_period' => '2026-08', 'safe_id' => $safe->id,
        ])->assertSessionHasErrors('safe_id');
    }
}
