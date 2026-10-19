<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Gl\GlAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سند مصروف — بيولّد قيد آلي: مدين حساب المصروف / دائن الخزنة أو البنك أو نقدية المندوب */
class Expense extends Model
{
    use HasDocumentNumber;

    public const PAID_FROM = ['cash_main', 'bank', 'rep_cash'];

    public const PAYEE_TYPES = ['supplier', 'employee', 'other'];

    /** pending → approved → posted (مدفوع)، أو rejected، أو void (L4-D7) */
    public const STATUSES = ['pending', 'approved', 'rejected', 'posted', 'void'];

    protected $fillable = [
        'number', 'date', 'account_id', 'amount', 'paid_from', 'paid_from_user_id',
        'payee_type', 'payee_supplier_id', 'payee_user_id', 'payee_name',
        'reference', 'note', 'attachment_path', 'status', 'voided_at', 'voided_by', 'created_by',
        'category_id', 'safe_id', 'cost_center_id', 'rep_id', 'vehicle_id',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'decision_note',
        'paid_by', 'paid_at', 'asset_name', 'asset_life_months', 'asset_start_date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'voided_at' => 'datetime',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'paid_at' => 'datetime',
            'asset_life_months' => 'integer', 'asset_start_date' => 'date',
        ];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('EXP-', 1001);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * الخزنة اللي المصروف طالع منها: `safe_id` لو متحدد، وإلا الخزنة
     * المقابلة لـ`paid_from` القديم (L4-D9). `rep_cash` = null (عهدة مندوب).
     */
    public function resolvedSafe(): ?Safe
    {
        return $this->safe ?? ($this->paid_from ? Safe::forLegacy($this->paid_from) : null);
    }

    public function paidFromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_from_user_id');
    }

    public function payeeSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'payee_supplier_id');
    }

    public function payeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function payeeLabel(): string
    {
        return match ($this->payee_type) {
            'supplier' => $this->payeeSupplier?->displayName() ?? '—',
            'employee' => $this->payeeUser?->displayName() ?? '—',
            default => (string) ($this->payee_name ?: '—'),
        };
    }
}
