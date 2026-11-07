<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بصمة خام من المكنة — ⚠️ **مابتتعدلش** غير `result` و`attendance_punch_id` لما تتطبّق.
 *
 * `result`:
 *   applied   = اتسجلت بانش في الحضور
 *   duplicate = الموظف كان في نفس الحالة (بصم حضور وهو حاضر من الأبلكيشن مثلاً) أو بصم مرتين ورا بعض
 *   unmapped  = رقم المكنة مش مربوط بموظف — بتتطبّق أول ما يتربط
 *   old       = قبل `apply_from` بتاع المكنة (التاريخ القديم اللي اترفع أول مرة)
 *   inactive  = الموظف موقوف
 */
class FpLog extends Model
{
    public const UPDATED_AT = null;

    public const APPLIED = 'applied';
    public const DUPLICATE = 'duplicate';
    public const UNMAPPED = 'unmapped';
    public const OLD = 'old';
    public const INACTIVE = 'inactive';

    /** حالة المكنة (زرار الحضور/الانصراف) ⇒ نوع البانش في PROMAX */
    public const STATUS_MAP = [
        0 => AttendancePunch::IN,      // Check-In
        1 => AttendancePunch::OUT,     // Check-Out
        2 => AttendancePunch::BREAK,   // Break-Out  (خروج إذن / بريك)
        3 => AttendancePunch::BACK,    // Break-In   (رجوع)
        4 => AttendancePunch::IN,      // OT-In
        5 => AttendancePunch::OUT,     // OT-Out
    ];

    protected $fillable = [
        'fp_device_id', 'pin', 'punched_at', 'status', 'verify', 'workcode',
        'employee_id', 'attendance_punch_id', 'punch_type', 'result',
    ];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(FpDevice::class, 'fp_device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function punch(): BelongsTo
    {
        return $this->belongsTo(AttendancePunch::class, 'attendance_punch_id');
    }

    public function verifyLabel(): string
    {
        return __('fp.verify_'.match ((int) $this->verify) {
            1 => 'finger', 0, 3 => 'password', 4 => 'card', 15 => 'face', default => 'other',
        });
    }
}
