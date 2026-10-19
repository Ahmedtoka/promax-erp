<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر قايمة مواد — خامة أو تعبئة بكمية لكل قطعة أو لكل باتش.
 */
class BomLine extends Model
{
    public const BASES = ['per_unit', 'per_batch'];

    protected $fillable = ['bom_id', 'product_id', 'qty', 'basis', 'sort'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'sort' => 'integer',
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** الكمية من غير هالك: لكل قطعة = الكمية × الإنتاج، لكل باتش = الكمية */
    public function baseFor(int $outputQty): float
    {
        return $this->basis === 'per_batch'
            ? (float) $this->qty
            : (float) $this->qty * $outputQty;
    }
}
