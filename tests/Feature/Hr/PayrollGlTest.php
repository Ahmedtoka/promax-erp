<?php

namespace Tests\Feature\Hr;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Safe;
use App\Models\Setting;
use App\Models\User;
use App\Services\Gl\Ledger;
use App\Services\Hr\Payroll;
use App\Services\Hr\PayrollCost;
use App\Services\Hr\PayrollGl;
use Illuminate\Support\Carbon;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** قيود المرتبات لكل مركز تكلفة + تكلفة الشهر لـLane 9 (Lane 7 — المهمة ٧). */
class PayrollGlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CostCenter $fac;

    private CostCenter $maadi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        PayrollGl::ensureAccounts();
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();

        $this->admin = $this->makeAdmin();
        $this->fac = CostCenter::create(['code' => 'FAC-T', 'name' => 'المصنع', 'kind' => 'factory']);
        $this->maadi = CostCenter::create(['code' => 'MAADI-T', 'name' => 'المعادي', 'kind' => 'branch']);
    }

    private function emp(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'code' => Employee::nextCode(), 'name' => 'موظف', 'basic_salary' => 8000, 'allowances' => 2000,
            'hire_date' => '2026-01-01', 'attendance_required' => false, 'cost_center_id' => $this->fac->id,
        ], $attrs));
    }

    private function entry($source): GlEntry
    {
        $e = GlEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)
            ->where('origin', 'auto')->with('lines')->first();
        $this->assertNotNull($e, 'entry posted');
        $this->assertEqualsWithDelta((float) $e->lines->sum('debit'), (float) $e->lines->sum('credit'), 0.001, 'balanced');

        return $e;
    }

    private function sumOn(GlEntry $e, string $key, string $side, int|false|null $cc = false): float
    {
        $id = GlAccount::findKey($key)->id;

        return round((float) $e->lines
            ->filter(fn ($l) => $l->account_id === $id && ($cc === false || $l->cost_center_id === $cc))
            ->sum($side), 2);
    }

    private function safe(): Safe
    {
        return Safe::create([
            'code' => 'S'.uniqid(), 'name' => 'خزنة', 'kind' => 'cash',
            'gl_account_id' => GlAccount::findKey('cash_main')->id, 'cost_center_id' => $this->maadi->id,
        ]);
    }

    public function test_approve_posts_a_balanced_entry_per_cost_center(): void
    {
        $a = $this->emp();
        $b = $this->emp(['cost_center_id' => $this->maadi->id, 'basic_salary' => 5000, 'allowances' => 0]);
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);

        $e = $this->entry($run->fresh());
        $sa = Payslip::where('employee_id', $a->id)->first();
        $sb = Payslip::where('employee_id', $b->id)->first();

        $this->assertSame('hr.payroll', $e->rule_key);
        $this->assertSame('2026-09-30', $e->date->toDateString());
        $this->assertEqualsWithDelta((float) $sa->gross, $this->sumOn($e, 'expense_salaries', 'debit', $this->fac->id), 0.001);
        $this->assertEqualsWithDelta((float) $sb->gross, $this->sumOn($e, 'expense_salaries', 'debit', $this->maadi->id), 0.001);
        $this->assertEqualsWithDelta(
            (float) ($sa->insurance_employee + $sa->insurance_employer + $sb->insurance_employee + $sb->insurance_employer),
            $this->sumOn($e, 'social_insurance_payable', 'credit'), 0.001);
        $this->assertEqualsWithDelta((float) ($sa->tax + $sb->tax), $this->sumOn($e, 'payroll_tax_payable', 'credit'), 0.001);
        $this->assertEqualsWithDelta((float) ($sa->insurance_employer + $sb->insurance_employer),
            $this->sumOn($e, 'expense_social_insurance', 'debit'), 0.001);
        $this->assertEqualsWithDelta((float) $sa->net, $this->sumOn($e, 'salaries_payable', 'credit', $this->fac->id), 0.001);
        $this->assertFalse((bool) $e->needs_review);
    }

    public function test_company_borne_entry_balances(): void
    {
        $this->emp(['employee_share_borne_by' => 'company']);
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);

        $e = $this->entry($run->fresh());
        $s = Payslip::firstOrFail();
        $this->assertEqualsWithDelta((float) $s->gross, $this->sumOn($e, 'expense_salaries', 'debit'), 0.001);
        $this->assertEqualsWithDelta((float) $s->net, $this->sumOn($e, 'salaries_payable', 'credit'), 0.001);
    }

    public function test_commission_goes_to_its_own_account(): void
    {
        $e = $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::setLine(Payslip::firstOrFail(), 'commission', 750, null, $this->admin);
        Payroll::approve($run, $this->admin);

        $entry = $this->entry($run->fresh());
        $this->assertEqualsWithDelta(750.0, $this->sumOn($entry, 'expense_commissions', 'debit'), 0.001);
        $this->assertEqualsWithDelta(10000.0, $this->sumOn($entry, 'expense_salaries', 'debit'), 0.001);
        unset($e);
    }

    public function test_payment_posts_safe_credit(): void
    {
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);
        $safe = $this->safe();
        Payroll::pay($run, $safe, null, Carbon::parse('2026-10-02'), $this->admin);

        $p = PayrollPayment::firstOrFail();
        $e = $this->entry($p);
        $this->assertSame('hr.payroll.pay', $e->rule_key);
        $this->assertEqualsWithDelta((float) $run->fresh()->net, $this->sumOn($e, 'salaries_payable', 'debit'), 0.001);
        $this->assertEqualsWithDelta((float) $run->fresh()->net, $this->sumOn($e, 'cash_main', 'credit', $this->maadi->id), 0.001);

        // مرتبات مستحقة بعد الاعتماد والصرف = صفر
        $acc = GlAccount::findKey('salaries_payable');
        $this->assertEqualsWithDelta(0.0, $acc->balanceBetween(null, null), 0.001);
    }

    public function test_salaries_payable_clears_per_cost_center(): void
    {
        $this->emp();
        $this->emp(['cost_center_id' => $this->maadi->id, 'basic_salary' => 5000, 'allowances' => 0]);
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);
        Payroll::pay($run, $this->safe(), null, Carbon::parse('2026-10-02'), $this->admin);

        $acc = GlAccount::findKey('salaries_payable')->id;
        $byCc = \App\Models\Gl\GlLine::query()->where('account_id', $acc)
            ->selectRaw('cost_center_id, SUM(debit - credit) b')->groupBy('cost_center_id')->pluck('b', 'cost_center_id');
        foreach ($byCc as $b) {
            $this->assertEqualsWithDelta(0.0, (float) $b, 0.001);
        }
        $this->assertCount(2, $byCc);

        // كل سطر بسلوت مختلف — تعديل حساب سطر مايترميش على الباقي بعد إعادة البناء
        $e = $this->entry($run->fresh());
        $this->assertSame($e->lines->count(), $e->lines->pluck('slot')->unique()->count());
    }

    public function test_advance_posts_and_void_unposts(): void
    {
        $e = $this->emp();
        $safe = $this->safe();
        $hr = $this->makeAdmin(['role' => 'hr']);

        $this->actingAs($hr)->post('/hr/advances', [
            'employee_id' => $e->id, 'date' => '2026-09-05', 'amount' => 1200, 'installments' => 2,
            'start_period' => '2026-10', 'safe_id' => $safe->id,
        ])->assertSessionHasNoErrors();

        $adv = EmployeeAdvance::firstOrFail();
        $entry = $this->entry($adv);
        $this->assertEqualsWithDelta(1200.0, $this->sumOn($entry, 'employee_advances', 'debit', $this->fac->id), 0.001);
        $this->assertEqualsWithDelta(1200.0, $this->sumOn($entry, 'cash_main', 'credit', $this->maadi->id), 0.001);

        $this->actingAs($hr)->post("/hr/advances/{$adv->id}/void")->assertSessionHasNoErrors();
        $this->assertSame(0, GlEntry::where('source_type', $adv->getMorphClass())->where('source_id', $adv->id)->count());
    }

    public function test_rebuild_keeps_payroll_entries(): void
    {
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        Payroll::approve($run, $this->admin);
        Payroll::pay($run, $this->safe(), null, Carbon::parse('2026-10-02'), $this->admin);

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-01-01'), true, false, $this->admin);

        $this->assertTrue($report->ok);
        $this->entry($run->fresh());
        $this->entry(PayrollPayment::firstOrFail());
    }

    public function test_approve_in_closed_period_is_refused(): void
    {
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);
        GlPeriod::close('2026-09', $this->admin);

        $this->actingAs($this->admin)->post("/hr/payroll/{$run->id}/approve")
            ->assertSessionHasErrors('run');

        $this->assertSame('draft', $run->fresh()->status);
        $this->assertStringContainsString('2026-09', session('errors')->first('run'));
    }

    public function test_payroll_cost_for_month_by_center(): void
    {
        $this->emp();
        $this->emp(['cost_center_id' => $this->maadi->id]);
        $this->emp(['cost_center_id' => $this->maadi->id]);
        $run = Payroll::draft('2026-09', $this->admin);

        $this->assertSame([], PayrollCost::forMonth(Carbon::parse('2026-09-01')), 'draft is not a cost yet');

        Payroll::approve($run, $this->admin);
        $cost = PayrollCost::forMonth(Carbon::parse('2026-09-15'));

        $this->assertEqualsCanonicalizing([$this->fac->id, $this->maadi->id], array_keys($cost));
        $this->assertSame(2, $cost[$this->maadi->id]['employees']);
        $this->assertEqualsWithDelta(20000.0, $cost[$this->maadi->id]['gross'], 0.001);
        $this->assertEqualsWithDelta(23750.0, $cost[$this->maadi->id]['total'], 0.001);

        $gl = PayrollCost::glForMonth(Carbon::parse('2026-09-01'));
        $this->assertEqualsWithDelta($cost[$this->maadi->id]['total'], $gl[$this->maadi->id], 0.001);
    }

    public function test_ensure_accounts_is_idempotent(): void
    {
        $again = PayrollGl::ensureAccounts();

        $this->assertSame(['created' => 0, 'rules' => 0], $again);
        $this->assertSame(1, GlAccount::where('system_key', 'salaries_payable')->count());
    }

    public function test_draft_run_has_no_entry(): void
    {
        $this->emp();
        $run = Payroll::draft('2026-09', $this->admin);

        $this->assertSame(0, GlEntry::where('source_type', (new PayrollRun)->getMorphClass())->count());
        $this->assertFalse(PayrollGl::handles($run));
    }
}
