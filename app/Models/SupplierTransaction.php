<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * قيد في دفتر مورد.
 *
 * ⚠️ **الكتابة من `Supplier::post()` وبس** — هي اللي بتضمن إن الرصيد
 * يتعاد حسابه مع كل قيد. القيد اليتيم = رصيد مش مطابق لدفتره.
 *
 * دائن = فاتورة علينا · مدين = دفعة مننا.
 */
class SupplierTransaction extends Model
{
    public const KINDS = ['invoice', 'payment', 'opening', 'adjust'];

    protected $fillable = [
        'supplier_id', 'date', 'kind', 'debit', 'credit', 'memo',
        'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function kindLabel(): string
    {
        return __('supplier.txn_'.$this->kind);
    }
}
