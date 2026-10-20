<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سطر تكلفة منتج داخل قيد تكلفة (الخط ٩) */
class CostLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3', 'revenue' => 'decimal:2',
            'unit_batch' => 'decimal:4', 'unit_wavg' => 'decimal:4', 'unit_transfer' => 'decimal:4',
            'amount_batch' => 'decimal:2', 'amount_wavg' => 'decimal:2', 'amount_transfer' => 'decimal:2',
            'zero_cost' => 'bool',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CostEntry::class, 'cost_entry_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
