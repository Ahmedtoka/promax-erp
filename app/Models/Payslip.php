<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قسيمة مرتب موظف في مسير (Lane 7 — المهمة ٦).
 *
 * ⚠️ الثابت: `gross − insurance_employee − tax − advances − deductions == net`
 * بالقرش. `deductions` هنا = الاستقطاعات اللي **مش** جوه الإجمالي (صفر في
 * المعادلة الحالية، لأن الغياب/التأخير/الجزاء بيتطرحوا قبل الإجمالي) —
 * العمود محفوظ للعرض: مجموع الغياب والتأخير والإجازة بدون أجر والجزاء.
 */
class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'cost_center_id', 'pay_method', 'borne_by',
        'basic', 'gross', 'insurance_wage', 'insurance_employee', 'insurance_employer',
        'tax', 'advances', 'deductions', 'net', 'payroll_payment_id',
    ];

    protected function casts(): array
    {
        $c = [];
        foreach (['basic', 'gross', 'insurance_wage', 'insurance_employee', 'insurance_employer',
            'tax', 'advances', 'deductions', 'net'] as $k) {
            $c[$k] = 'decimal:2';
        }

        return $c;
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayrollPayment::class, 'payroll_payment_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('id');
    }

    /** مبلغ بند بالكود (مجموع لو متكرر) */
    public function amountOf(string $code): float
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return round((float) $lines->where('code', $code)->sum('amount'), 2);
    }
}
