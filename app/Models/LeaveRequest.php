<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلب إجازة (Lane 7 — المهمة ٣). الكتابة كلها عبر `App\Services\Hr\Leave`.
 *
 * ⚠️ `apiRow()` عقد Lane 8 — المفاتيح بالترتيب ده ومقفولة في `ApiContractTest`.
 */
class LeaveRequest extends Model
{
    public const TYPES = ['annual', 'casual', 'sick', 'unpaid'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $fillable = [
        'employee_id', 'type', 'from', 'to', 'days', 'note', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'from' => 'date',
            'to' => 'date',
            'days' => 'decimal:1',
            'decided_at' => 'datetime',
        ];
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

    public function typeLabel(): string
    {
        return __('hrm.leave_type_'.$this->type);
    }

    public function statusLabel(): string
    {
        return __('hrm.leave_status_'.$this->status);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'approved' => 'b-green',
            'rejected' => 'b-red',
            'pending' => 'b-orange',
            default => 'b-gray',
        };
    }

    /** @return array<string, mixed> */
    public function apiRow(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'days' => (float) $this->days,
            'note' => $this->note,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'decision_note' => $this->decision_note,
            'employee_name' => $this->employee?->displayName() ?? '',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
