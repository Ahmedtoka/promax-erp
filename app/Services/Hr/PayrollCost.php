<?php

namespace App\Services\Hr;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تكلفة المرتبات لكل مركز تكلفة في شهر — واجهة Lane 9 (التكاليف وقايمة الدخل).
 *
 * `forMonth` من القسايم نفسها (بيشتغل والدفتر مقفول `gl_enabled=0`)،
 * و`glForMonth` من سطور الدفتر للمطابقة لما الدفتر شغّال.
 */
final class PayrollCost
{
    /**
     * @return array<int|string, array{cost_center_id:?int, gross:float, employer_insurance:float, total:float, employees:int}>
     *   بالمفتاح `cost_center_id` (و`'none'` للموظف اللي مالوش مركز)
     */
    public static function forMonth(Carbon $month): array
    {
        $run = PayrollRun::query()->where('period', $month->format('Y-m'))
            ->whereIn('status', ['approved', 'paid'])->first();

        if ($run === null) {
            return [];
        }

        $rows = Payslip::query()->where('payroll_run_id', $run->id)
            ->selectRaw('cost_center_id, COALESCE(SUM(gross),0) g, COALESCE(SUM(insurance_employer),0) er, COUNT(*) n')
            ->groupBy('cost_center_id')->get();

        $out = [];
        foreach ($rows as $r) {
            $g = round((float) $r->g, 2);
            $er = round((float) $r->er, 2);
            $out[$r->cost_center_id ?? 'none'] = [
                'cost_center_id' => $r->cost_center_id === null ? null : (int) $r->cost_center_id,
                'gross' => $g,
                'employer_insurance' => $er,
                'total' => round($g + $er, 2),
                'employees' => (int) $r->n,
            ];
        }

        return $out;
    }

    /**
     * نفس التكلفة من الدفتر: صافي مدين حسابات الرواتب والعمولات وحصة الشركة
     * في التأمينات على قيود المسير، بالمركز.
     *
     * @return array<int|string, float>
     */
    public static function glForMonth(Carbon $month): array
    {
        $run = PayrollRun::query()->where('period', $month->format('Y-m'))->first();
        if ($run === null) {
            return [];
        }

        $accounts = GlAccount::query()
            ->whereIn('system_key', ['expense_salaries', 'expense_commissions', 'expense_social_insurance'])
            ->pluck('id');

        $entryIds = GlEntry::query()->where('source_type', $run->getMorphClass())->where('source_id', $run->id)->pluck('id');

        return DB::table('gl_lines')->whereIn('entry_id', $entryIds)->whereIn('account_id', $accounts)
            ->selectRaw('cost_center_id, COALESCE(SUM(debit - credit),0) amt')
            ->groupBy('cost_center_id')->get()
            ->mapWithKeys(fn ($r) => [($r->cost_center_id ?? 'none') => round((float) $r->amt, 2)])
            ->all();
    }
}
