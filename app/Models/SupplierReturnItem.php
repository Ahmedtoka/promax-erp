<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnItem extends Model
{
    protected $fillable = [
        'supplier_return_id', 'supplier_order_item_id', 'product_id', 'qty', 'unit_cost', 'note', 'photo_path',
    ];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotal(): float
    {
        return round($this->qty * (float) $this->unit_cost, 2);
    }
}
