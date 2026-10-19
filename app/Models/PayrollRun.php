<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مسير مرتبات شهر واحد (Lane 7 — المهمة ٦). صف واحد لكل `period` (YYYY-MM).
 * draft → approved (أدمن) → paid (صرف من خزنة/بنك). الأرقام تجميع من القسايم
 * (`Payroll::recompute`) — ممنوع تتكتب بإيد.
 */
class PayrollRun extends Model
{
    public const STATUSES = ['draft', 'approved', 'paid'];

    protected $fillable = [
        'period', 'date', 'status', 'gross', 'net', 'employer_cost',
        'approved_by', 'approved_at', 'paid_at', 'created_by',
    ];

    protected $attributes = ['status' => 'draft', 'gross' => 0, 'net' => 0, 'employer_cost' => 0];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'gross' => 'decimal:2',
            'net' => 'decimal:2',
            'employer_cost' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollPayment::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return __('hrm.payroll_status_'.$this->status);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'paid' => 'b-green',
            'approved' => 'b-blue',
            default => 'b-orange',
        };
    }
}
