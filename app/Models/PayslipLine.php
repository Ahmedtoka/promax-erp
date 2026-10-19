<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند في قسيمة مرتب (Lane 7 — المهمة ٦). `manual` = اتعدّل بإيد الموارد
 * البشرية وبيعيش بعد إعادة حساب المسودة.
 */
class PayslipLine extends Model
{
    public const EARNINGS = ['basic', 'allowances', 'commission', 'incentive', 'overtime', 'company_borne'];

    public const DEDUCTIONS = ['absence', 'lateness', 'unpaid_leave', 'penalty', 'advance', 'insurance', 'tax'];

    public const EMPLOYER = ['insurance_employer'];

    /** البنود اللي الموارد البشرية تقدر تعدّلها بإيد (الباقي محسوب من القانون) */
    public const EDITABLE = [
        'basic', 'allowances', 'commission', 'incentive', 'overtime',
        'absence', 'lateness', 'unpaid_leave', 'penalty', 'advance',
    ];

    protected $fillable = ['payslip_id', 'code', 'kind', 'qty', 'amount', 'manual', 'note'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:2', 'amount' => 'decimal:2', 'manual' => 'boolean'];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public static function kindOf(string $code): string
    {
        return match (true) {
            in_array($code, self::EARNINGS, true) => 'earning',
            in_array($code, self::EMPLOYER, true) => 'employer',
            default => 'deduction',
        };
    }

    public function label(): string
    {
        return __('hrm.line_'.$this->code);
    }
}
