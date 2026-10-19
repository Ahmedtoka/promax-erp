<?php

namespace App\Services\Hr;

use App\Models\EmployeeAdvance;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Safe;
use App\Services\Gl\Ledger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * قيود المرتبات في الدفتر العام (Lane 7 — المهمة ٧). مفيش كتابة هنا —
 * `lines()` بترجّع نفس شكل `Rules::linesFor` و`App\Services\Gl\Ledger`
 * هو اللي بيكتب (`post`). كل سطر عليه مركز تكلفة القسيمة.
 *
 * المسير (عند الاعتماد):
 *   مدين رواتب وأجور (الإجمالي − العمولة) · مدين عمولات وحوافز (العمولة + الحافز)
 *   · مدين حصة الشركة في التأمينات ← دائن مرتبات مستحقة (الإجمالي)
 *   · دائن تأمينات مستحقة (الحصتين) · دائن ضريبة كسب عمل · دائن سلف العاملين،
 *   والصافي بيفضل دائن على «مرتبات مستحقة» لحد الصرف.
 * الصرف: مدين مرتبات مستحقة / دائن حساب الخزنة أو البنك.
 * السلفة: مدين سلف العاملين / دائن حساب الخزنة.
 *
 * ⚠️ حساب ناقص أو موقوف أو مجموعة → «الحساب المعلّق» + `needs_review`
 * (نفس قاعدة `Rules::safeAccount`) — الترحيل مايوقفش صرف مرتبات.
 */
final class PayrollGl
{
    /** [code, name, name_en, parentCode, system_key] — كلها بتقبل ترحيل */
    public const ACCOUNTS = [
        ['1203', 'سلف العاملين', 'Employee advances', '12', 'employee_advances'],
        ['2102', 'مرتبات مستحقة', 'Salaries payable', '21', 'salaries_payable'],
        ['2202', 'تأمينات اجتماعية مستحقة', 'Social insurance payable', '22', 'social_insurance_payable'],
        ['2203', 'ضريبة كسب العمل مستحقة', 'Payroll tax payable', '22', 'payroll_tax_payable'],
        ['5108', 'حصة الشركة في التأمينات', 'Employer social insurance', '5', 'expense_social_insurance'],
    ];

    /** [key, label, debit_key, credit_key] — `safe` = حساب الخزنة من المستند */
    public const RULES = [
        ['hr.payroll', 'مسير مرتبات — الرواتب', 'expense_salaries', 'salaries_payable'],
        ['hr.payroll.commission', 'مسير مرتبات — العمولات والحوافز', 'expense_commissions', 'salaries_payable'],
        ['hr.payroll.employer_si', 'مسير مرتبات — حصة الشركة في التأمينات', 'expense_social_insurance', 'social_insurance_payable'],
        ['hr.payroll.employee_si', 'مسير مرتبات — تأمينات الموظف', 'salaries_payable', 'social_insurance_payable'],
        ['hr.payroll.tax', 'مسير مرتبات — ضريبة كسب العمل', 'salaries_payable', 'payroll_tax_payable'],
        ['hr.payroll.advance', 'مسير مرتبات — استرداد السلف', 'salaries_payable', 'employee_advances'],
        ['hr.payroll.pay', 'صرف المرتبات', 'salaries_payable', 'safe'],
        ['hr.advance', 'سلفة موظف', 'employee_advances', 'safe'],
    ];

    /** @return array{created:int, rules:int} */
    public static function ensureAccounts(bool $dryRun = false): array
    {
        $created = 0;
        $rules = 0;

        foreach (self::ACCOUNTS as [$code, $name, $en, $parentCode, $key]) {
            if (GlAccount::where('system_key', $key)->exists()) {
                continue;
            }
            $created++;
            if ($dryRun) {
                continue;
            }

            $parent = GlAccount::where('code', $parentCode)->first()
                ?? GlAccount::where('code', $code[0])->first();
            $finalCode = GlAccount::where('code', $code)->exists() ? $code.'-HR' : $code;
            $type = GlAccount::ROOT_TYPES[$code[0]];

            GlAccount::create([
                'code' => $finalCode, 'name' => $name, 'name_en' => $en,
                'parent_id' => $parent?->id, 'type' => $type,
                'normal_side' => GlAccount::normalSideFor($type),
                'is_system' => true, 'system_key' => $key, 'is_postable' => true, 'active' => true,
            ]);
        }

        foreach (self::RULES as [$key, $label, $dr, $cr]) {
            if (GlPostingRule::where('key', $key)->exists()) {
                continue;
            }
            $rules++;
            if (! $dryRun) {
                GlPostingRule::create(['key' => $key, 'label' => $label, 'debit_key' => $dr, 'credit_key' => $cr, 'active' => true]);
            }
        }
        if (! $dryRun) {
            GlPostingRule::flush();
        }

        return ['created' => $created, 'rules' => $rules];
    }

    public static function handles(Model $m): bool
    {
        return ($m instanceof PayrollRun && in_array($m->status, ['approved', 'paid'], true))
            || $m instanceof PayrollPayment
            || ($m instanceof EmployeeAdvance && $m->status !== 'void');
    }

    /** @return array{rule:string, memo:string, needs_review:bool, lines:list<array>} */
    public static function lines(Model $m): array
    {
        return match (true) {
            $m instanceof PayrollRun => self::run($m),
            $m instanceof PayrollPayment => self::payment($m),
            $m instanceof EmployeeAdvance => self::advance($m),
            default => ['rule' => '', 'memo' => '', 'needs_review' => false, 'lines' => []],
        };
    }

    /** المستندات اللي بتولّد قيود من تاريخ معيّن — لـ`Ledger::sources` */
    public static function sources(string $fromDate): iterable
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('payroll_runs')) {
            return collect();
        }

        return collect()
            ->concat(PayrollRun::whereIn('status', ['approved', 'paid'])->whereDate('date', '>=', $fromDate)->orderBy('date')->cursor())
            ->concat(PayrollPayment::whereDate('date', '>=', $fromDate)->orderBy('date')->cursor())
            ->concat(EmployeeAdvance::where('status', '!=', 'void')->whereDate('date', '>=', $fromDate)->orderBy('date')->cursor());
    }

    /** ترحيل بعد الـcommit — فشل الدفتر مايرجّعش صرف حصل فعلاً (بيتسجّل ويتصلّح بإعادة البناء) */
    public static function post(Model $m): void
    {
        try {
            app(Ledger::class)->post($m);
        } catch (\Throwable $e) {
            Log::error('PayrollGl post failed', ['type' => $m::class, 'id' => $m->getKey(), 'error' => $e->getMessage()]);
        }
    }

    public static function unpost(Model $m): void
    {
        try {
            app(Ledger::class)->unpost($m);
        } catch (\Throwable $e) {
            Log::error('PayrollGl unpost failed', ['type' => $m::class, 'id' => $m->getKey(), 'error' => $e->getMessage()]);
        }
    }

    // ═══════════════════════════ السطور ═══════════════════════════

    private static function run(PayrollRun $run): array
    {
        $review = false;
        $acc = [];
        $res = function (string $ruleKey, string $side) use (&$review, &$acc): int {
            $k = $ruleKey.'|'.$side;

            return $acc[$k] ??= self::account(self::ruleKey($ruleKey, $side), $review);
        };

        // [account_id, cost_center_id, side] => amount
        $sum = [];
        $put = function (string $rule, float $amount, ?int $cc) use (&$sum, $res) {
            $amount = round($amount, 2);
            if ($amount <= 0) {
                return;
            }
            foreach (['debit', 'credit'] as $side) {
                $k = $res($rule, $side).'|'.($cc ?? '').'|'.$side;
                $sum[$k] = round(($sum[$k] ?? 0) + $amount, 2);
            }
        };

        $slips = Payslip::query()->where('payroll_run_id', $run->id)->with('lines')->get();
        foreach ($slips as $s) {
            $cc = $s->cost_center_id;
            $commission = round($s->amountOf('commission') + $s->amountOf('incentive'), 2);
            $put('hr.payroll.commission', min($commission, (float) $s->gross), $cc);
            $put('hr.payroll', (float) $s->gross - min($commission, (float) $s->gross), $cc);
            $put('hr.payroll.employer_si', (float) $s->insurance_employer, $cc);
            $put('hr.payroll.employee_si', (float) $s->insurance_employee, $cc);
            $put('hr.payroll.tax', (float) $s->tax, $cc);
            $put('hr.payroll.advance', (float) $s->advances, $cc);
        }

        $lines = [];
        foreach ($sum as $k => $amount) {
            [$accountId, $cc, $side] = explode('|', $k);
            $lines[] = [
                'slot' => $side === 'debit' ? 'dr' : 'cr',
                'account_id' => (int) $accountId,
                'cost_center_id' => $cc === '' ? null : (int) $cc,
                'debit' => $side === 'debit' ? $amount : 0.0,
                'credit' => $side === 'credit' ? $amount : 0.0,
            ];
        }

        // تجميع الطرفين: مدين وداين على نفس الحساب والمركز بيتصافوا لسطر واحد
        $lines = self::net($lines);

        return [
            'rule' => 'hr.payroll', 'needs_review' => $review,
            'memo' => __('hrm.gl_payroll_memo', ['period' => $run->period]),
            'lines' => $lines,
        ];
    }

    private static function payment(PayrollPayment $p): array
    {
        $review = false;
        $safe = $p->safe;
        $amount = round((float) $p->amount, 2);
        $cc = $safe?->cost_center_id;
        $dr = self::account(self::ruleKey('hr.payroll.pay', 'debit'), $review);
        $cr = self::safeAccount($safe, $p->kind, $review);

        return [
            'rule' => 'hr.payroll.pay', 'needs_review' => $review,
            'memo' => __('hrm.gl_payroll_pay_memo', ['period' => $p->run?->period]),
            'lines' => [
                ['slot' => 'dr', 'account_id' => $dr, 'cost_center_id' => $cc, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $cr, 'cost_center_id' => $cc, 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }

    private static function advance(EmployeeAdvance $a): array
    {
        $review = false;
        $safe = $a->safe;
        $amount = round((float) $a->amount, 2);
        $dr = self::account(self::ruleKey('hr.advance', 'debit'), $review);
        $cr = self::safeAccount($safe, $safe?->kind ?? 'cash', $review);

        return [
            'rule' => 'hr.advance', 'needs_review' => $review,
            'memo' => __('hrm.gl_advance_memo', ['number' => $a->number, 'name' => $a->employee?->displayName() ?? '']),
            'lines' => [
                ['slot' => 'dr', 'account_id' => $dr, 'cost_center_id' => $a->employee?->cost_center_id, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $cr, 'cost_center_id' => $safe?->cost_center_id, 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }

    // ═══════════════════════════ مساعدات ═══════════════════════════

    /** مفتاح الحساب من القاعدة (الجدول أولاً، وإلا الديفولت هنا) */
    private static function ruleKey(string $key, string $side): ?string
    {
        $rule = GlPostingRule::forKey($key);
        if ($rule !== null && $rule->active) {
            return $side === 'debit' ? $rule->debit_key : $rule->credit_key;
        }
        foreach (self::RULES as [$k, , $dr, $cr]) {
            if ($k === $key) {
                return $side === 'debit' ? $dr : $cr;
            }
        }

        return null;
    }

    private static function account(?string $systemKey, bool &$review): int
    {
        $acc = $systemKey ? GlAccount::where('system_key', $systemKey)->first() : null;
        if ($acc !== null && $acc->is_postable && $acc->active) {
            return $acc->id;
        }
        $review = true;

        return GlAccount::findKey('suspense')->id;
    }

    /** حساب الخزنة نفسها؛ من غيره `cash_main`/`bank` حسب النوع بعلامة مراجعة */
    private static function safeAccount(?Safe $safe, string $kind, bool &$review): int
    {
        $acc = $safe?->gl_account_id ? GlAccount::find($safe->gl_account_id) : null;
        if ($acc !== null && $acc->is_postable && $acc->active) {
            return $acc->id;
        }
        $review = true;

        return self::account($kind === 'bank' ? 'bank' : 'cash_main', $review);
    }

    /** @param list<array> $lines */
    private static function net(array $lines): array
    {
        $by = [];
        foreach ($lines as $l) {
            $k = $l['account_id'].'|'.($l['cost_center_id'] ?? '');
            $by[$k] = round(($by[$k] ?? 0) + $l['debit'] - $l['credit'], 2);
        }
        $out = [];
        foreach ($by as $k => $bal) {
            if (round($bal, 2) == 0.0) {
                continue;
            }
            [$a, $cc] = explode('|', $k);
            $out[] = [
                'slot' => $bal > 0 ? 'dr' : 'cr',
                'account_id' => (int) $a,
                'cost_center_id' => $cc === '' ? null : (int) $cc,
                'debit' => $bal > 0 ? $bal : 0.0,
                'credit' => $bal < 0 ? -$bal : 0.0,
            ];
        }
        // المدين الأول — اليومية بتتقرا كده
        usort($out, fn ($x, $y) => [$x['debit'] > 0 ? 0 : 1, $x['account_id'], $x['cost_center_id'] ?? 0]
            <=> [$y['debit'] > 0 ? 0 : 1, $y['account_id'], $y['cost_center_id'] ?? 0]);

        return $out;
    }
}
