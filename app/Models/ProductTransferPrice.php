<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سعر تحويل داخلي معتمد (رقم إداري مش سعر بيع) — تاريخ كامل بتاريخ سريان (D10) */
class ProductTransferPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'unit_cost' => 'decimal:4', 'markup_pct' => 'decimal:2',
            'effective_from' => 'date', 'approved_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
