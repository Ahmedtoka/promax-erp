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

    protected $fillable = [
        'number', 'date', 'account_id', 'amount', 'paid_from', 'paid_from_user_id',
        'payee_type', 'payee_supplier_id', 'payee_user_id', 'payee_name',
        'reference', 'note', 'attachment_path', 'status', 'voided_at', 'voided_by', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'voided_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('EXP-', 1001);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'account_id');
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
