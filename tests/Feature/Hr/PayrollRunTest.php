<?php

namespace Tests\Feature\Hr;

use App\Exceptions\Rejected;
use App\Models\AttendanceDay;
use App\Models\CommissionTier;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\HrPenalty;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Safe;
use App\Models\User;
use App\Services\Hr\HrSettings;
use App\Services\Hr\Payroll;
use App\Services\Hr\PayrollMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مسير المرتبات (Lane 7 — المهمة ٦). الإعدادات ديفولت، الويك إند الجمعة،
 * الشهر 2026-09 (٣٠ يوم، ٢٦ يوم شغل).
 */
class PayrollRunTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function emp(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'code' => Employee::nextCode(), 'name' => 'موظف', 'basic_salary' => 8000, 'allowances' => 2000,
            'hire_date' => '2026-01-01', 'attendance_required' => false,
        ], $attrs));
    }

    private function slip(PayrollRun $run, Employee $e): Payslip
    {
        return Payslip::query()->where('payroll_run_id', $run->id)->where('employee_id', $e->id)->with('lines')->firstOrFail();
    }

    private function tax(float $taxable): float
    {
        return PayrollMath::monthlyTax($taxable, HrSettings::json('hr_tax_brackets'),
            HrSettings::json('hr_tax_elimination'), HrSettings::num('hr_tax_exemption'));
    }

    private function assertBalanced(Payslip $s): void
    {
        $this->assertEqualsWithDelta(
            (float) $s->net,
            round((float) $s->gross - (float) $s->insurance_employee - (float) $s->tax - (float) $s->advances, 2),
            0.001
        );
    }

    private function cashSafe(): Safe
    {
        return Safe::create(['code' => 'C'.uniqid(), 'name' => 'خزنة', 'kind' => 'cash']);
    }

    public function test_draft_basic_employee_without_attendance_tracking(): void
    {
        $e = $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        $s = $this->slip($run, $e);

        $this->assertSame('draft', $run->status);
        $this->assertSame('2026-09-30', $run->date->toDateString());
        $this->assertEqualsWithDelta(10000.00, (float) $s->gross, 0.001);
        $this->assertEqualsWithDelta(1100.00, (float) $s->insurance_employee, 0.001);
        $this->assertEqualsWithDelta(1875.00, (float) $s->insurance_employer, 0.001);
        $tax = $this->tax(8900);
        $this->assertEqualsWithDelta($tax, (float) $s->tax, 0.001);
        $this->assertEqualsWithDelta(10000 - 1100 - $tax, (float) $s->net, 0.001);
        $this->assertBalanced($s);
        $this->assertEqualsWithDelta((float) $s->net, (float) $run->net, 0.001);
        $this->assertEqualsWithDelta(11875.00, (float) $run->employer_cost, 0.001);
    }

    public function test_absence_and_lateness_from_attendance(): void
    {
        $e = $this->emp(['attendance_required' => true]);

        for ($d = \Carbon\Carbon::parse('2026-09-01'); $d->lte(\Carbon\Carbon::parse('2026-09-30')); $d->addDay()) {
            if ($d->isFriday() || $d->toDateString() === '2026-09-14') {
                continue;   // الاتنين ١٤ غياب
            }
            $in = $d->toDateString() === '2026-09-15' ? '09:40' : '09:00';
            AttendanceDay::create([
                'employee_id' => $e->id, 'date' => $d->toDateString(), 'sessions' => 1,
                'first_in_at' => $d->toDateString().' '.$in.':00', 'worked_minutes' => 480, 'status' => 'closed',
            ]);
        }

        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertEqualsWithDelta(round(10000 / 30, 2), $s->amountOf('absence'), 0.001);
        $this->assertEqualsWithDelta(round(40 * 10000 / 30 / 480, 2), $s->amountOf('lateness'), 0.001);
        $this->assertSame(0.0, $s->amountOf('overtime'));
        $this->assertBalanced($s);
    }

    public function test_approved_paid_leave_is_not_absence_but_unpaid_is_deducted(): void
    {
        $e = $this->emp(['attendance_required' => true]);

        for ($d = \Carbon\Carbon::parse('2026-09-01'); $d->lte(\Carbon\Carbon::parse('2026-09-30')); $d->addDay()) {
            if ($d->isFriday() || in_array($d->toDateString(), ['2026-09-08', '2026-09-09'], true)) {
                continue;
            }
            AttendanceDay::create([
                'employee_id' => $e->id, 'date' => $d->toDateString(), 'sessions' => 1,
                'first_in_at' => $d->toDateString().' 08:55:00', 'worked_minutes' => 480, 'status' => 'closed',
            ]);
        }
        LeaveRequest::create(['employee_id' => $e->id, 'type' => 'annual', 'from' => '2026-09-08', 'to' => '2026-09-08', 'days' => 1, 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $e->id, 'type' => 'unpaid', 'from' => '2026-09-09', 'to' => '2026-09-09', 'days' => 1, 'status' => 'approved']);

        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertSame(0.0, $s->amountOf('absence'));
        $this->assertEqualsWithDelta(round(10000 / 30, 2), $s->amountOf('unpaid_leave'), 0.001);
    }

    public function test_overtime_paid_at_the_overtime_rate(): void
    {
        $e = $this->emp(['attendance_required' => true]);

        for ($d = \Carbon\Carbon::parse('2026-09-01'); $d->lte(\Carbon\Carbon::parse('2026-09-30')); $d->addDay()) {
            if ($d->isFriday()) {
                continue;
            }
            AttendanceDay::create([
                'employee_id' => $e->id, 'date' => $d->toDateString(), 'sessions' => 1,
                'first_in_at' => $d->toDateString().' 09:00:00',
                'worked_minutes' => $d->toDateString() === '2026-09-02' ? 540 : 480, 'status' => 'closed',
            ]);
        }

        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertEqualsWithDelta(round(60 * 10000 / 30 / 480 * 1.35, 2), $s->amountOf('overtime'), 0.001);
    }

    public function test_mid_month_hire_is_prorated(): void
    {
        $e = $this->emp(['hire_date' => '2026-09-16']);
        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertEqualsWithDelta(4000.00, $s->amountOf('basic'), 0.001);
        $this->assertEqualsWithDelta(1000.00, $s->amountOf('allowances'), 0.001);
        $this->assertBalanced($s);
    }

    public function test_employee_hired_after_the_period_is_left_out(): void
    {
        $this->emp(['hire_date' => '2026-10-02']);
        $run = Payroll::draft('2026-09', $this->admin);

        $this->assertSame(0, $run->payslips()->count());
    }

    public function test_company_borne_net_equals_earned(): void
    {
        $e = $this->emp(['employee_share_borne_by' => 'company']);
        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertEqualsWithDelta(10000.00, (float) $s->net, 0.011);
        $this->assertGreaterThan(10000, (float) $s->gross);
        $this->assertEqualsWithDelta((float) $s->gross - 10000, $s->amountOf('company_borne'), 0.001);
        $this->assertEqualsWithDelta($this->tax((float) $s->gross - 1100), (float) $s->tax, 0.001);
        $this->assertBalanced($s);
    }

    public function test_insured_off_and_taxable_off(): void
    {
        $e = $this->emp(['insured' => false, 'taxable' => false]);
        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertSame(0.0, $s->amountOf('insurance'));
        $this->assertSame(0.0, $s->amountOf('tax'));
        $this->assertSame(0.0, $s->amountOf('insurance_employer'));
        $this->assertEqualsWithDelta(10000.00, (float) $s->net, 0.001);
    }

    public function test_insurance_on_capped_wage(): void
    {
        $e = $this->emp(['basic_salary' => 25000, 'allowances' => 0]);
        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertEqualsWithDelta(16700.00, (float) $s->insurance_wage, 0.001);
        $this->assertEqualsWithDelta(1837.00, (float) $s->insurance_employee, 0.001);
        $this->assertEqualsWithDelta(3131.25, (float) $s->insurance_employer, 0.001);
    }

    public function test_file_without_salary_gets_no_insurance(): void
    {
        $e = $this->emp(['basic_salary' => 0, 'allowances' => 0]);
        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertSame(0.0, (float) $s->insurance_employee);
        $this->assertSame(0.0, (float) $s->net);
    }

    public function test_negative_net_blocks_approval(): void
    {
        // غياب يدوي كبير: المستحق أقل من تأمينات الشهر
        $e = $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::setLine($this->slip($run, $e), 'absence', 9500, null, $this->admin);
        $this->assertLessThan(0, (float) $this->slip($run, $e)->net);

        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/approve")->assertSessionHasErrors('run');
        $this->assertSame('draft', $run->fresh()->status);
    }

    public function test_commission_for_field_employee(): void
    {
        $rep = $this->makeRep();
        $e = $this->emp(['user_id' => $rep->id]);
        CommissionTier::create(['min_pct' => 0, 'rate' => 0.02]);
        $inv = Invoice::create([
            'number' => 'INV-77001', 'client_id' => $this->makeClient()->id, 'user_id' => $rep->id,
            'payment' => 'credit', 'subtotal' => 50000, 'discount' => 0, 'total' => 50000, 'tax_total' => 0, 'grand_total' => 50000,
        ]);
        Invoice::query()->whereKey($inv->id)->update(['created_at' => '2026-09-12 11:00:00']);

        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertEqualsWithDelta(1000.00, $s->amountOf('commission'), 0.001);
        $this->assertEqualsWithDelta(11000.00, (float) $s->gross, 0.001);
    }

    public function test_manual_line_survives_redraft(): void
    {
        $e = $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::setLine($this->slip($run, $e), 'allowances', 2500, 'بدل انتقال', $this->admin);

        $run = Payroll::draft('2026-09', $this->admin);
        $s = $this->slip($run, $e);

        $this->assertEqualsWithDelta(2500.00, $s->amountOf('allowances'), 0.001);
        $this->assertTrue((bool) $s->lines->firstWhere('code', 'allowances')->manual);
        $this->assertSame(1, $s->lines->where('code', 'allowances')->count());
        $this->assertEqualsWithDelta(10500.00, (float) $s->gross, 0.001);
    }

    public function test_tax_line_cannot_be_edited_by_hand(): void
    {
        $e = $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);

        $this->expectException(Rejected::class);
        Payroll::setLine($this->slip($run, $e), 'tax', 0, null, $this->admin);
    }

    public function test_cannot_redraft_an_approved_run(): void
    {
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);

        $this->expectException(Rejected::class);
        Payroll::draft('2026-09', $this->admin);
    }

    public function test_pay_links_advances_and_penalties(): void
    {
        $e = $this->emp();
        $adv = EmployeeAdvance::create([
            'number' => 'ADV-1001', 'employee_id' => $e->id, 'date' => '2026-08-20', 'amount' => 3000,
            'installments' => 3, 'installment_amount' => 1000, 'start_period' => '2026-09',
        ]);
        $pen = HrPenalty::create(['employee_id' => $e->id, 'date' => '2026-09-10', 'amount' => 150, 'reason' => 'تأخير']);

        $run = Payroll::draft('2026-09', $this->admin);
        $s = $this->slip($run, $e);
        $this->assertEqualsWithDelta(1000.00, (float) $s->advances, 0.001);
        $this->assertEqualsWithDelta(150.00, $s->amountOf('penalty'), 0.001);
        $this->assertEqualsWithDelta(9850.00, (float) $s->gross, 0.001);
        $this->assertBalanced($s);

        $safe = $this->cashSafe();
        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/pay", [
            'date' => '2026-10-01', 'cash_safe_id' => $safe->id,
        ])->assertSessionHasNoErrors();

        $run->refresh();
        $this->assertSame('paid', $run->status);
        $this->assertEqualsWithDelta(1000.00, (float) $adv->fresh()->recovered, 0.001);
        $this->assertSame('open', $adv->fresh()->status);
        $this->assertSame($s->id, (int) $pen->fresh()->payslip_id);
        $p = PayrollPayment::firstOrFail();
        $this->assertEqualsWithDelta((float) $run->net, (float) $p->amount, 0.001);
        $this->assertSame($p->id, (int) $s->fresh()->payroll_payment_id);
    }

    public function test_review_guards_on_advances_and_penalties(): void
    {
        $e = $this->emp();
        $adv = EmployeeAdvance::create([
            'number' => 'ADV-1001', 'employee_id' => $e->id, 'date' => '2026-08-20', 'amount' => 2000,
            'installments' => 2, 'installment_amount' => 1000, 'start_period' => '2026-09',
        ]);
        $run = Payroll::draft('2026-09', $this->admin);

        // جزاء اتسجل بعد المسودة = القسيمة قديمة والاعتماد مرفوض لحد إعادة الحساب
        HrPenalty::create(['employee_id' => $e->id, 'date' => '2026-09-20', 'amount' => 100, 'reason' => 'x']);
        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/approve")->assertSessionHasErrors('run');
        Payroll::draft('2026-09', $this->admin);
        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/approve")->assertSessionHasNoErrors();

        // السلفة في مسير معتمد: الإلغاء مرفوض، والجزاء/السلفة في شهر معتمد مرفوضين
        $hr = $this->makeAdmin(['role' => 'hr']);
        $this->actingAs($hr)->post("/hr/advances/{$adv->id}/void")->assertSessionHasErrors();
        $this->assertSame('open', $adv->fresh()->status);
        $this->actingAs($hr)->post('/hr/penalties', [
            'employee_id' => $e->id, 'date' => '2026-09-25', 'amount' => 50, 'reason' => 'y',
        ])->assertSessionHasErrors('date');
        $this->actingAs($hr)->post('/hr/advances', [
            'employee_id' => $e->id, 'date' => '2026-09-25', 'amount' => 500, 'installments' => 1,
            'start_period' => '2026-09', 'safe_id' => $this->cashSafe()->id,
        ])->assertSessionHasErrors('start_period');

        // قسط يدوي أكبر من المتبقي على السلف المفتوحة مرفوض (مسودة شهر تاني)
        $run10 = Payroll::draft('2026-10', $this->admin);
        $this->expectException(Rejected::class);
        Payroll::setLine($this->slip($run10, $e), 'advance', 5000, null, $this->admin);
    }

    public function test_paying_twice_is_refused(): void
    {
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);
        $safe = $this->cashSafe();

        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/pay", ['date' => '2026-10-01', 'cash_safe_id' => $safe->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/pay", ['date' => '2026-10-01', 'cash_safe_id' => $safe->id])
            ->assertSessionHasErrors('run');

        $this->assertSame(1, PayrollPayment::count());
    }

    public function test_pay_requires_bank_safe_when_bank_slips_exist(): void
    {
        $this->emp();
        $this->emp(['pay_method' => 'bank']);
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);

        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/pay", ['date' => '2026-10-01', 'cash_safe_id' => $this->cashSafe()->id])
            ->assertSessionHasErrors('run');

        $this->assertSame('approved', $run->fresh()->status);
        $this->assertSame(0, PayrollPayment::count());
    }

    public function test_pay_before_approval_is_refused(): void
    {
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);

        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/pay", ['date' => '2026-10-01', 'cash_safe_id' => $this->cashSafe()->id])
            ->assertSessionHasErrors('run');
        $this->assertSame('draft', $run->fresh()->status);
    }

    public function test_hr_cannot_approve(): void
    {
        $hr = $this->makeAdmin(['role' => 'hr']);
        $acc = $this->makeAdmin(['role' => 'accountant']);
        $e = $this->emp();

        $this->actingAs($hr)->post('/hr/payroll', ['period' => '2026-09'])->assertSessionHasNoErrors();
        $run = PayrollRun::firstOrFail();

        $this->actingAs($hr)->post("/hr/payroll/{$run->id}/approve")->assertForbidden();
        $this->assertSame('draft', $run->fresh()->status);

        $this->actingAs($acc)->get("/hr/payroll/{$run->id}")->assertOk();
        $this->actingAs($acc)->get('/hr/payroll/slips/'.$this->slip($run, $e)->id.'/print')->assertOk();
        $this->actingAs($acc)->post('/hr/payroll', ['period' => '2026-09'])->assertForbidden();
    }

    /** قرار المالك (١٩/١٠): الصرف للمحاسب أو الأدمن، والاعتماد للأدمن بس */
    public function test_accountant_can_pay_but_not_approve(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant']);
        $rep = $this->makeRep();
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);

        $this->actingAs($acc)->post("/hr/payroll/{$run->id}/approve")->assertForbidden();
        Payroll::approve($run, $this->admin);

        $this->actingAs($acc)->get("/hr/payroll/{$run->id}")->assertOk()->assertSee('dlgPay', false);
        $this->actingAs($rep)->post("/hr/payroll/{$run->id}/pay", ['date' => '2026-10-01', 'cash_safe_id' => $this->cashSafe()->id])
            ->assertForbidden();
        $this->assertSame('approved', $run->fresh()->status);

        $this->actingAs($acc)->post("/hr/payroll/{$run->id}/pay", ['date' => '2026-10-01', 'cash_safe_id' => $this->cashSafe()->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', $run->fresh()->status);
        $this->assertSame(1, PayrollPayment::count());
    }

    /** قرار المالك (١٩/١٠): شغل يوم الإجازة الأسبوعية مش بيتحسب إضافي */
    public function test_weekend_work_is_not_overtime(): void
    {
        $e = $this->emp(['attendance_required' => true]);

        for ($d = \Carbon\Carbon::parse('2026-09-01'); $d->lte(\Carbon\Carbon::parse('2026-09-30')); $d->addDay()) {
            AttendanceDay::create([
                'employee_id' => $e->id, 'date' => $d->toDateString(), 'sessions' => 1,
                'first_in_at' => $d->toDateString().' 09:00:00',
                // الجمعة ٤ سبتمبر: ١٠ ساعات شغل في يوم الإجازة
                'worked_minutes' => $d->isFriday() ? 600 : 480, 'status' => 'closed',
            ]);
        }

        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->assertSame(0.0, $s->amountOf('overtime'));
        $this->assertSame(0.0, $s->amountOf('absence'));
    }

    /** قرار المالك (١٩/١٠): شهر التعيين لو بعد يوم ١ مالوش تأمينات — من الشهر اللي بعده كامل */
    public function test_no_insurance_in_a_partial_hire_month(): void
    {
        $late = $this->emp(['hire_date' => '2026-09-20', 'basic_salary' => 3000, 'allowances' => 0]);
        $first = $this->emp(['hire_date' => '2026-09-01']);

        $run = Payroll::draft('2026-09', $this->admin);
        $s = $this->slip($run, $late);
        $this->assertSame(0.0, (float) $s->insurance_employee);
        $this->assertSame(0.0, (float) $s->insurance_employer);
        $this->assertSame(0.0, $s->amountOf('insurance'));
        $this->assertGreaterThanOrEqual(0, (float) $s->net);
        $this->assertBalanced($s);

        $f = $this->slip($run, $first);
        $this->assertEqualsWithDelta(1100.00, (float) $f->insurance_employee, 0.001);
        $this->assertEqualsWithDelta(1875.00, (float) $f->insurance_employer, 0.001);

        // الشهر الكامل اللي بعده: تأمين كامل
        $next = $this->slip(Payroll::draft('2026-10', $this->admin), $late);
        $this->assertEqualsWithDelta(330.00, (float) $next->insurance_employee, 0.001);   // 3000 × 11%

        // والاعتماد مابقاش بيتقفل بسبب صافي بالسالب
        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/approve")->assertSessionHasNoErrors();
    }

    public function test_payslip_print_shows_net(): void
    {
        $e = $this->emp(['name' => 'سامي الطباعة']);
        $s = $this->slip(Payroll::draft('2026-09', $this->admin), $e);

        $this->actingAs($this->admin)->get("/hr/payroll/slips/{$s->id}/print")
            ->assertOk()
            ->assertSee(number_format((float) $s->net, 2))
            ->assertSee('سامي الطباعة');
    }

    public function test_index_and_run_export(): void
    {
        $this->emp(['code' => 'EMP-5555']);
        $run = Payroll::draft('2026-09', $this->admin);

        $this->actingAs($this->admin)->get('/hr/payroll')->assertOk()->assertSee('2026-09');
        $csv = $this->actingAs($this->admin)->get("/hr/payroll/{$run->id}?export=1")->streamedContent();
        $this->assertStringContainsString('EMP-5555', $csv);
        $list = $this->actingAs($this->admin)->get('/hr/payroll?export=1&from=2026-10-01&to=2026-10-31')->streamedContent();
        $this->assertStringNotContainsString('2026-09', $list);
    }
}
