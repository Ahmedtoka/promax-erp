<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ملف الموظف (Lane 7). مستقل عن `User`: اللوجين اختياري، والمرتب والتأمين
 * ومركز التكلفة هنا. `CostCenterResolver` بيقرا `cost_center_id` لأي مستند
 * عليه `employee_id`.
 *
 * ⚠️ الرقم القومي ورقم التأمين بيظهروا في فورم التعديل بس — مش في القايمة
 * ولا التصدير.
 */
class Employee extends Model
{
    use HasBilingualName;

    public const PAY_METHODS = ['cash', 'bank'];

    public const BORNE_BY = ['employee', 'company'];

    /** الرولز اللي بتشوف كل الموظفين */
    public const SEE_ALL_ROLES = ['admin', 'hr', 'accountant'];

    /** الرولز اللي بتدير أي موظف */
    public const MANAGE_ALL_ROLES = ['admin', 'hr'];

    protected $fillable = [
        'code', 'user_id', 'name', 'name_en', 'cost_center_id', 'department', 'job_title',
        'manager_id', 'hire_date', 'end_date', 'contract_path', 'national_id', 'insurance_no',
        'pay_method', 'bank_account', 'basic_salary', 'allowances', 'insurable_wage',
        'insured', 'taxable', 'employee_share_borne_by', 'attendance_required', 'active',
    ];

    /** ⚠️ الديفولتس هنا كمان — `create()` من غيرها بيرجّع موديل خاصيته null لحد `fresh()` */
    protected $attributes = [
        'pay_method' => 'cash',
        'basic_salary' => 0,
        'allowances' => 0,
        'insured' => true,
        'taxable' => true,
        'employee_share_borne_by' => 'employee',
        'attendance_required' => true,
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'end_date' => 'date',
            'basic_salary' => 'decimal:2',
            'allowances' => 'decimal:2',
            'insurable_wage' => 'decimal:2',
            'insured' => 'boolean',
            'taxable' => 'boolean',
            'attendance_required' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /** ملف الموظف بتاع اليوزر ده — أو null (أدمن من غير ملف، أو مفيش يوزر) */
    public static function forUser(?User $u): ?self
    {
        if ($u === null || $u->id === null) {
            return null;
        }

        return static::query()->where('user_id', $u->id)->first();
    }

    /** الأدمن والموارد البشرية أي موظف، وغيرهم المدير المباشر بس */
    public function isManagedBy(User $u): bool
    {
        if (in_array($u->role, self::MANAGE_ALL_ROLES, true)) {
            return true;
        }

        $mine = self::forUser($u);

        return $mine !== null && $this->manager_id !== null && $mine->id === (int) $this->manager_id;
    }

    /** الأدمن والموارد البشرية والمحاسب الكل، وغيرهم نفسه ومرؤوسيه المباشرين */
    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        if (in_array($u->role, self::SEE_ALL_ROLES, true)) {
            return $q;
        }

        $mine = self::forUser($u);

        if ($mine === null) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(fn ($w) => $w->whereKey($mine->id)->orWhere('manager_id', $mine->id));
    }

    /** EMP-1001، EMP-1002… — feature-dev §2 (الأرقام بس، من غير إشارة) */
    public static function nextCode(): string
    {
        $max = 1000;

        foreach (static::query()->where('code', 'like', 'EMP-%')->pluck('code') as $code) {
            $n = (int) preg_replace('/\D+/', '', (string) $code);
            $max = max($max, $n);
        }

        return 'EMP-'.($max + 1);
    }
}
