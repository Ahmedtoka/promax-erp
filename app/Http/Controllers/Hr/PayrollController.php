<?php

namespace App\Http\Controllers\Hr;

use App\Exceptions\Rejected;
use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Safe;
use App\Services\Gl\ClosedPeriod;
use App\Services\Hr\Payroll;
use App\Support\Access;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * مسير المرتبات الشهري (Lane 7 — المهمة ٦).
 *
 * • القراءة: أدمن + موارد بشرية + محاسب.
 * • المسودة وتعديل البنود: `role:admin,hr` + `act.hr.payroll`.
 * • الاعتماد: أدمن بس (`act.hr.payroll_approve`). الصرف: المحاسب أو الأدمن (`act.hr.payroll_pay`، قرار المالك ١٩/١٠).
 * • الحساب كله في `App\Services\Hr\Payroll` — الكنترولر بيمرّر ويرجّع رسالة.
 */
class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = PayrollRun::query()->withCount('payslips');
        $range->apply($q, 'date');

        if ($request->boolean('export')) {
            $all = (clone $q)->orderBy('date')->limit(5000)->get();

            return Csv::download('payroll-runs-'.now()->format('Y-m-d-Hi').'.csv',
                [__('hrm.payroll_period'), __('hrm.status'), __('hrm.payroll_employees'), __('hrm.payroll_gross'),
                    __('hrm.payroll_net'), __('hrm.payroll_employer_cost')],
                $all->map(fn (PayrollRun $r) => [
                    $r->period, $r->statusLabel(), $r->payslips_count, Csv::money($r->gross),
                    Csv::money($r->net), Csv::money($r->employer_cost),
                ]),
                null,
                Csv::meta(__('hrm.nav_payroll'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('hr.payroll', [
            'range' => $range,
            'runs' => (clone $q)->orderByDesc('period')->paginate(24)->withQueryString(),
            'canDraft' => Access::action($request->user(), 'act.hr.payroll'),
            'defaultPeriod' => now()->subMonthNoOverflow()->format('Y-m'),
        ]);
    }

    public function draft(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);

        try {
            $run = Payroll::draft($data['period'], $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['period' => $e->getMessage()])->withInput();
        }

        return redirect()->route('hr.payroll.show', $run)->with('ok', __('hrm.payroll_drafted', ['period' => $run->period]));
    }

    public function show(Request $request, PayrollRun $run)
    {
        $user = $request->user();
        $slips = $run->payslips()->with(['employee:id,code,name,name_en', 'costCenter:id,code,name,name_en', 'lines'])
            ->get()->sortBy(fn (Payslip $s) => $s->employee?->code)->values();

        if ($request->boolean('export')) {
            $codes = array_merge(PayslipLine::EARNINGS, PayslipLine::DEDUCTIONS, PayslipLine::EMPLOYER);

            return Csv::download('payroll-'.$run->period.'.csv',
                array_merge(
                    [__('hrm.emp_code'), __('hr.employee'), __('hrm.cost_center'), __('hrm.pay_method')],
                    array_map(fn ($c) => __('hrm.line_'.$c), $codes),
                    [__('hrm.payroll_gross'), __('hrm.payroll_net')]
                ),
                $slips->map(fn (Payslip $s) => array_merge(
                    [$s->employee?->code ?? '', $s->employee?->displayName() ?? '', $s->costCenter?->code ?? '',
                        __('hrm.pay_'.$s->pay_method)],
                    array_map(fn ($c) => Csv::money($s->amountOf($c)), $codes),
                    [Csv::money($s->gross), Csv::money($s->net)]
                )),
                null,
                Csv::meta(__('hrm.nav_payroll').' '.$run->period, null, null));
        }

        $isDraft = $run->status === 'draft';

        return view('hr.payroll_run', [
            'run' => $run->load('approver:id,name', 'payments.safe'),
            'slips' => $slips,
            'canEdit' => $isDraft && Access::action($user, 'act.hr.payroll'),
            'canApprove' => $isDraft && Access::action($user, 'act.hr.payroll_approve'),
            'canPay' => $run->status === 'approved' && Access::action($user, 'act.hr.payroll_pay'),
            'cashSafes' => Safe::query()->where('active', true)->where('kind', 'cash')->orderBy('code')->get(),
            'bankSafes' => Safe::query()->where('active', true)->where('kind', 'bank')->orderBy('code')->get(),
            'editable' => PayslipLine::EDITABLE,
        ]);
    }

    public function line(Request $request, Payslip $payslip)
    {
        $data = $request->validate([
            'code' => ['required', Rule::in(PayslipLine::EDITABLE)],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            Payroll::setLine($payslip, $data['code'], (float) $data['amount'], $data['note'] ?? null, $request->user());
        } catch (Rejected $e) {
            return back()->withErrors(['line' => $e->getMessage()]);
        }

        return back()->with('ok', __('hrm.payroll_line_saved'));
    }

    public function approve(Request $request, PayrollRun $run)
    {
        abort_unless(Access::action($request->user(), 'act.hr.payroll_approve'), 403);

        try {
            Payroll::approve($run, $request->user());
        } catch (ClosedPeriod|Rejected $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('ok', __('hrm.payroll_approved'));
    }

    public function pay(Request $request, PayrollRun $run)
    {
        abort_unless(Access::action($request->user(), 'act.hr.payroll_pay'), 403);

        $data = $request->validate([
            'date' => ['required', 'date'],
            'cash_safe_id' => ['nullable', 'integer', Rule::exists('safes', 'id')->where('active', true)->where('kind', 'cash')],
            'bank_safe_id' => ['nullable', 'integer', Rule::exists('safes', 'id')->where('active', true)->where('kind', 'bank')],
        ]);

        try {
            Payroll::pay(
                $run,
                isset($data['cash_safe_id']) ? Safe::find($data['cash_safe_id']) : null,
                isset($data['bank_safe_id']) ? Safe::find($data['bank_safe_id']) : null,
                Carbon::parse($data['date']),
                $request->user()
            );
        } catch (ClosedPeriod|Rejected $e) {
            return back()->withErrors(['run' => $e->getMessage()])->withInput();
        }

        return back()->with('ok', __('hrm.payroll_paid'));
    }

    public function print(Request $request, Payslip $payslip)
    {
        $payslip->load(['employee', 'costCenter', 'lines', 'run', 'payment.safe']);

        return view('hr.payslip_print', ['s' => $payslip]);
    }
}
