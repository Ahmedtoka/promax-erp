<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إذن بالساعة (٧/١١/٢٠٢٦): تأخير الصبح · انصراف بدري · خروج في النص.
 * المعتمد منه بيتشال من دقايق التأخير في المرتبات (`Payroll::attendance`)
 * وبيبان في شاشة الحضور اليومية بدل «متأخر».
 */
class HrPermission extends Model
{
    public const KINDS = ['late_in', 'early_out', 'during'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [
        'employee_id', 'date', 'kind', 'minutes', 'reason', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'decided_at' => 'datetime', 'minutes' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function kindLabel(): string
    {
        return __('fp.perm_kind_'.$this->kind);
    }

    public function statusLabel(): string
    {
        return __('fp.perm_status_'.$this->status);
    }
}
