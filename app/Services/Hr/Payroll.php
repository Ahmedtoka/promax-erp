<?php

namespace App\Services\Hr;

use App\Exceptions\Rejected;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\HrPenalty;
use App\Models\LeaveRequest;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Safe;
use App\Models\User;
use App\Services\Gl\Ledger;
use App\Services\RepKpis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * دورة مسير المرتبات الشهري (Lane 7 — المهمة ٦):
 * `draft()` → `setLine()` (تعديل يدوي) → `approve()` → `pay()`.
 *
 * ⚠️ العقيدة:
 * • الحساب الصافي في `PayrollMath` والنِسَب من `HrSettings` — مفيش رقم قانوني هنا.
 * • `gross − insurance_employee − tax − advances == net` بالقرش في كل قسيمة.
 * • الصرف ذرّي (`DB::transaction` + `lockForUpdate` على المسير) — مرتين = رفض.
 * • الشهر المقفول في الدفتر بيرفض الاعتماد والصرف (`Ledger::assertOpen`).
 * • القيود (المهمة ٧) بتتكتب بعد الـcommit عن طريق `PayrollGl::post`.
 */
final class Payroll
{
    /** البنود اللي `recompute` بيكتبها — مش من المسودة ومش يدوية */
    private const COMPUTED = ['insurance', 'tax', 'insurance_employer', 'company_borne'];

    /** الاستقطاعات اللي بتتطرح قبل الإجمالي (بتقلّل الوعاء) */
    private const PRE_GROSS = ['absence', 'lateness', 'unpaid_leave', 'penalty'];

    public static function periodStart(string $period): Carbon
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new Rejected(__('hrm.payroll_bad_period'));
        }

        return Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
    }

    /**
     * ينشئ مسودة الشهر أو يعيد حسابها. البنود اليدوية بتعيش، والباقي بيتبني
     * من الملف والحضور والإجازات والسلف والجزاءات والعمولة.
     */
    public static function draft(string $period, User $by): PayrollRun
    {
        $start = self::periodStart($period);
        $end = $start->copy()->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($period, $start, $end, $by) {
            $run = PayrollRun::query()->where('period', $period)->lockForUpdate()->first();

            if ($run !== null && $run->status !== 'draft') {
                throw new Rejected(__('hrm.payroll_not_draft'));
            }

            $run ??= PayrollRun::create([
                'period' => $period,
                'date' => $end->toDateString(),
                'created_by' => $by->id,
            ]);

            $employees = Employee::query()
                ->where('active', true)
                ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $end->toDateString()))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start->toDateString()))
                ->with('user')
                ->orderBy('id')->get();

            // موظف خرج من النطاق (اتوقف/اتعدل تاريخه) — قسيمته تتشال من المسودة
            $run->payslips()->whereNotIn('employee_id', $employees->pluck('id')->all() ?: [0])->get()
                ->each(fn (Payslip $s) => $s->delete());

            $settings = self::settings();

            foreach ($employees as $e) {
                $slip = Payslip::query()->firstOrNew(['payroll_run_id' => $run->id, 'employee_id' => $e->id]);
                $slip->fill([
                    'cost_center_id' => $e->cost_center_id,
                    'pay_method' => $e->pay_method ?: 'cash',
                    'borne_by' => $e->employee_share_borne_by ?: 'employee',
                ])->save();

                $manual = $slip->lines()->where('manual', true)->pluck('code')->all();
                $slip->lines()->where('manual', false)->delete();

                foreach (self::buildLines($e, $period, $start, $end, $settings) as $line) {
                    if (in_array($line['code'], $manual, true) || in_array($line['code'], self::COMPUTED, true)) {
                        continue;
                    }
                    $slip->lines()->create($line + ['kind' => PayslipLine::kindOf($line['code']), 'manual' => false]);
                }

                self::recomputeSlip($slip->fresh(), $settings);
            }

            self::rollUp($run);

            return $run->fresh();
        });
    }

    /**
     * سطور القسيمة المحسوبة لموظف في شهر (من غير التأمين والضريبة).
     *
     * @return list<array{code:string, qty:?float, amount:float, note:?string}>
     */
    public static function buildLines(Employee $e, string $period, Carbon $start, Carbon $end, ?array $settings = null): array
    {
        $s = $settings ?? self::settings();
        $lines = [];
        $add = function (string $code, float $amount, ?float $qty = null, ?string $note = null) use (&$lines) {
            $amount = round($amount, 2);
            if ($amount > 0) {
                $lines[] = ['code' => $code, 'qty' => $qty === null ? null : round($qty, 2), 'amount' => $amount, 'note' => $note];
            }
        };

        $monthDays = $start->daysInMonth;
        $from = $e->hire_date && $e->hire_date->gt($start) ? $e->hire_date->copy()->startOfDay() : $start->copy();
        $to = $e->end_date && $e->end_date->lt($end) ? $e->end_date->copy()->startOfDay() : $end->copy();
        $employedDays = $to->lt($from) ? 0 : (int) $from->diffInDays($to, true) + 1;
        $ratio = $employedDays >= $monthDays ? 1.0 : $employedDays / $monthDays;

        $basic = (float) $e->basic_salary;
        $allow = (float) $e->allowances;
        $add('basic', $basic * $ratio, $ratio < 1 ? $employedDays : null);
        $add('allowances', $allow * $ratio, $ratio < 1 ? $employedDays : null);

        // ═══ الحضور — للموظف اللي عليه حضور بس ═══
        $daily = ($basic + $allow) / max($s['month_days'], 1);
        $minute = $daily / max($s['day_minutes'], 1);

        if ($e->attendance_required && $employedDays > 0) {
            $att = self::attendance($e, $from, $to->lt(Carbon::today()) ? $to : Carbon::today());
            [$absent, $unpaid, $lateMin, $otMin] = $att;

            $add('absence', $absent * $daily, $absent);
            $add('unpaid_leave', $unpaid * $daily, $unpaid);
            $add('lateness', $lateMin * $minute, $lateMin);
            $add('overtime', $otMin * $minute * $s['overtime_rate'], $otMin);
        }

        // ═══ العمولة والحافز — لموظف عنده لوجين ميداني ═══
        $u = $e->user;
        if ($u !== null && in_array($u->role, User::FIELD_WORK_ROLES, true)) {
            $k = RepKpis::forMonth($u, $start->copy());
            $add('commission', (float) ($k['commission'] ?? 0));
            $add('incentive', (float) ($k['points_money'] ?? 0));
        }

        // ═══ الجزاءات اللي لسه ماتخصمتش في الشهر ═══
        $pen = (float) HrPenalty::query()->where('employee_id', $e->id)->whereNull('payslip_id')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])->sum('amount');
        $add('penalty', $pen);

        // ═══ أقساط السلف ═══
        $adv = EmployeeAdvance::query()->where('employee_id', $e->id)->where('status', 'open')->get()
            ->sum(fn (EmployeeAdvance $a) => $a->installmentFor($period));
        $add('advance', (float) $adv);

        return $lines;
    }

    /**
     * أيام الغياب، أيام الإجازة بدون أجر، دقايق التأخير، دقايق الإضافي —
     * على أيام الشغل (مش الويك إند) من `$from` لـ`$to`.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function attendance(Employee $e, Carbon $from, Carbon $to): array
    {
        if ($to->lt($from)) {
            return [0, 0, 0, 0];
        }

        $s = self::settings();
        $weekend = Leave::weekendDays();

        $days = AttendanceDay::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($q) use ($e) {
                $q->where('employee_id', $e->id);
                if ($e->user_id) {
                    $q->orWhere('user_id', $e->user_id);
                }
            })
            ->get()
            ->groupBy(fn (AttendanceDay $d) => $d->date->toDateString());

        $leaves = LeaveRequest::query()->where('employee_id', $e->id)->where('status', 'approved')
            ->whereDate('from', '<=', $to->toDateString())->whereDate('to', '>=', $from->toDateString())
            ->get();

        [$sh, $sm] = array_map('intval', explode(':', $s['shift_start'].':0'));
        $absent = $unpaid = $late = $ot = 0;

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if (in_array($d->dayOfWeek, $weekend, true)) {
                continue;
            }

            $leave = $leaves->first(fn (LeaveRequest $l) => $l->from->lte($d) && $l->to->gte($d));
            if ($leave !== null) {
                if ($leave->type === 'unpaid') {
                    $unpaid++;
                }

                continue;
            }

            /** @var Collection<int, AttendanceDay>|null $rows */
            $rows = $days->get($d->toDateString());
            $day = $rows?->first(fn (AttendanceDay $x) => (int) $x->sessions > 0);

            if ($day === null) {
                $absent++;

                continue;
            }

            if ($day->first_in_at !== null) {
                $shift = $d->copy()->setTime($sh, $sm);
                $mins = (int) round(($day->first_in_at->getTimestamp() - $shift->getTimestamp()) / 60);
                if ($mins > $s['grace']) {
                    $late += $mins;
                }
            }

            $payable = (int) ($day->approved_minutes ?? $day->worked_minutes);
            if ($payable > $s['day_minutes']) {
                $ot += $payable - (int) $s['day_minutes'];
            }
        }

        return [$absent, $unpaid, $late, $ot];
    }

    /** إعادة حساب قسيمة واحدة + إجماليات مسيرها */
    public static function recompute(Payslip $s): Payslip
    {
        return DB::transaction(function () use ($s) {
            $slip = self::recomputeSlip($s, self::settings());
            self::rollUp($slip->run);

            return $slip;
        });
    }

    private static function recomputeSlip(Payslip $slip, array $set): Payslip
    {
        $slip->load('employee', 'lines');
        $e = $slip->employee;
        $lines = $slip->lines;

        $sum = fn (array $codes) => round((float) $lines->whereIn('code', $codes)->sum('amount'), 2);

        $earnings = $sum(array_values(array_diff(PayslipLine::EARNINGS, ['company_borne'])));
        $pre = $sum(self::PRE_GROSS);
        $earned = round(max(0.0, $earnings - $pre), 2);

        $ins = ['wage' => 0.0, 'employee' => 0.0, 'employer' => 0.0];
        if ($e->insured) {
            $wage = $e->insurable_wage !== null ? (float) $e->insurable_wage : (float) $e->basic_salary + (float) $e->allowances;
            $ins = PayrollMath::insurance($wage, $set['si_emp'], $set['si_er'], $set['si_min'], $set['si_max']);
        }
        $insE = $ins['employee'];

        $taxOf = fn (float $taxable) => PayrollMath::monthlyTax(max($taxable, 0), $set['brackets'], $set['elimination'], $set['exemption']);

        if ($slip->borne_by === 'company') {
            $gross = $e->taxable
                ? PayrollMath::grossUp($earned, $insE, $taxOf, PayrollMath::taxBreaks($set['elimination'], $set['exemption']))
                : round($earned + $insE, 2);
        } else {
            $gross = $earned;
        }
        $tax = $e->taxable ? $taxOf($gross - $insE) : 0.0;
        $companyBorne = round($gross - $earned, 2);

        // ⚠️ القسط مايعدّيش الصافي — الباقي يتأجل للشهر الجاي
        $advLine = $lines->firstWhere('code', 'advance');
        $adv = $sum(['advance']);
        $room = round(max(0.0, $gross - $insE - $tax), 2);
        if ($adv > $room && $advLine !== null) {
            $advLine->amount = $room;
            $advLine->save();
            $adv = $room;
        }

        $net = round($gross - $insE - $tax - $adv, 2);

        self::putComputed($slip, 'insurance', $insE);
        self::putComputed($slip, 'tax', $tax);
        self::putComputed($slip, 'insurance_employer', $ins['employer']);
        self::putComputed($slip, 'company_borne', $companyBorne);

        $slip->fill([
            'basic' => $sum(['basic']),
            'gross' => $gross,
            'insurance_wage' => $ins['wage'],
            'insurance_employee' => $insE,
            'insurance_employer' => $ins['employer'],
            'tax' => $tax,
            'advances' => $adv,
            'deductions' => $pre,
            'net' => $net,
        ])->save();

        return $slip->fresh(['lines', 'employee']);
    }

    private static function putComputed(Payslip $slip, string $code, float $amount): void
    {
        $slip->lines()->where('code', $code)->delete();
        if (round($amount, 2) > 0) {
            $slip->lines()->create([
                'code' => $code, 'kind' => PayslipLine::kindOf($code), 'amount' => round($amount, 2), 'manual' => false,
            ]);
        }
    }

    private static function rollUp(PayrollRun $run): void
    {
        $t = Payslip::query()->where('payroll_run_id', $run->id)
            ->selectRaw('COALESCE(SUM(gross),0) g, COALESCE(SUM(net),0) n, COALESCE(SUM(insurance_employer),0) er')
            ->first();

        $run->forceFill([
            'gross' => round((float) $t->g, 2),
            'net' => round((float) $t->n, 2),
            'employer_cost' => round((float) $t->g + (float) $t->er, 2),
        ])->save();
    }

    /** تعديل يدوي لبند في مسودة — بيتعلّم `manual` ويعيش بعد إعادة الحساب */
    public static function setLine(Payslip $s, string $code, float $amount, ?string $note, User $by): Payslip
    {
        if (! in_array($code, PayslipLine::EDITABLE, true)) {
            throw new Rejected(__('hrm.payroll_line_locked'));
        }
        if ($amount < 0) {
            throw new Rejected(__('hrm.payroll_line_negative'));
        }

        return DB::transaction(function () use ($s, $code, $amount, $note) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($s->payroll_run_id);
            if ($run->status !== 'draft') {
                throw new Rejected(__('hrm.payroll_not_draft'));
            }

            $s->lines()->where('code', $code)->delete();
            $s->lines()->create([
                'code' => $code, 'kind' => PayslipLine::kindOf($code), 'amount' => round($amount, 2),
                'manual' => true, 'note' => $note,
            ]);

            $slip = self::recomputeSlip($s->fresh(), self::settings());
            self::rollUp($run);

            return $slip;
        });
    }

    /** اعتماد الأدمن: مسودة → معتمد. القيد في المهمة ٧ بعد الـcommit. */
    public static function approve(PayrollRun $r, User $by): PayrollRun
    {
        $run = DB::transaction(function () use ($r, $by) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($r->id);
            if ($run->status !== 'draft') {
                throw new Rejected(__('hrm.payroll_not_draft'));
            }
            app(Ledger::class)->assertOpen($run->date->copy());
            if (! $run->payslips()->exists()) {
                throw new Rejected(__('hrm.payroll_empty'));
            }

            $run->forceFill(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()])->save();

            return $run;
        });

        PayrollGl::post($run);

        return $run->fresh();
    }

    /**
     * صرف المسير: دفعة لكل طريقة عليها قسايم (كاش من خزنة، بنك من حساب بنك)،
     * ربط القسايم والجزاءات، وتحصيل أقساط السلف.
     */
    public static function pay(PayrollRun $r, ?Safe $cash, ?Safe $bank, \Carbon\CarbonInterface $date, User $by): PayrollRun
    {
        $date = Carbon::instance($date);
        $payments = DB::transaction(function () use ($r, $cash, $bank, $date, $by) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($r->id);

            if ($run->status === 'paid') {
                throw new Rejected(__('hrm.payroll_already_paid'));
            }
            if ($run->status !== 'approved') {
                throw new Rejected(__('hrm.payroll_not_approved'));
            }
            app(Ledger::class)->assertOpen($date->copy());

            $slips = $run->payslips()->with('lines')->get();
            $made = [];

            foreach ($slips->groupBy(fn (Payslip $s) => $s->pay_method === 'bank' ? 'bank' : 'cash') as $kind => $group) {
                $safe = $kind === 'bank' ? $bank : $cash;
                if ($safe === null || ! $safe->active) {
                    throw new Rejected(__('hrm.payroll_safe_missing_'.$kind));
                }

                $payment = PayrollPayment::create([
                    'payroll_run_id' => $run->id,
                    'safe_id' => $safe->id,
                    'kind' => $kind,
                    'date' => $date->toDateString(),
                    'amount' => round((float) $group->sum('net'), 2),
                    'paid_by' => $by->id,
                ]);
                $made[] = $payment;

                Payslip::query()->whereIn('id', $group->pluck('id'))->update(['payroll_payment_id' => $payment->id]);
            }

            $start = self::periodStart($run->period);
            $end = $start->copy()->endOfMonth();

            foreach ($slips as $slip) {
                if ($slip->lines->contains('code', 'penalty')) {
                    HrPenalty::query()->where('employee_id', $slip->employee_id)->whereNull('payslip_id')
                        ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                        ->update(['payslip_id' => $slip->id]);
                }

                self::recoverAdvances($slip, $run->period);
            }

            $run->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            return $made;
        });

        foreach ($payments as $p) {
            PayrollGl::post($p);
        }

        return $r->fresh();
    }

    /** القسط بيتوزع على السلف: قسط كل سلفة الأول، وأي زيادة يدوية على الأقدم */
    private static function recoverAdvances(Payslip $slip, string $period): void
    {
        $left = round((float) $slip->advances, 2);
        if ($left <= 0) {
            return;
        }

        $advances = EmployeeAdvance::query()->where('employee_id', $slip->employee_id)->where('status', 'open')
            ->where('start_period', '<=', $period)->orderBy('date')->orderBy('id')->lockForUpdate()->get();

        $take = [];
        foreach ($advances as $a) {
            $t = min($a->installmentFor($period), $left);
            $take[$a->id] = $t;
            $left = round($left - $t, 2);
        }
        foreach ($advances as $a) {
            if ($left <= 0) {
                break;
            }
            $t = min(round($a->remaining() - $take[$a->id], 2), $left);
            $take[$a->id] += $t;
            $left = round($left - $t, 2);
        }

        foreach ($advances as $a) {
            if (($take[$a->id] ?? 0) <= 0) {
                continue;
            }
            $a->recovered = round((float) $a->recovered + $take[$a->id], 2);
            if ($a->remaining() <= 0) {
                $a->status = 'closed';
            }
            $a->save();
        }
    }

    /** الإعدادات مرة واحدة كأرقام */
    public static function settings(): array
    {
        return [
            'month_days' => HrSettings::num('hr_month_days') ?: 30,
            'day_minutes' => HrSettings::num('hr_day_minutes') ?: 480,
            'shift_start' => HrSettings::get('hr_shift_start') ?: '09:00',
            'grace' => HrSettings::num('hr_late_grace_minutes'),
            'overtime_rate' => HrSettings::num('hr_overtime_rate'),
            'si_emp' => HrSettings::num('hr_si_employee_rate'),
            'si_er' => HrSettings::num('hr_si_employer_rate'),
            'si_min' => HrSettings::num('hr_si_min_wage'),
            'si_max' => HrSettings::num('hr_si_max_wage'),
            'exemption' => HrSettings::num('hr_tax_exemption'),
            'brackets' => HrSettings::json('hr_tax_brackets'),
            'elimination' => HrSettings::json('hr_tax_elimination'),
        ];
    }
}
