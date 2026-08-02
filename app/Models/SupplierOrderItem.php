<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند أمر شراء لمورد — الكمية بسعر الشراء المتفاوض عليه */
class SupplierOrderItem extends Model
{
    protected $fillable = [
        'supplier_order_id', 'product_id', 'qty', 'unit_cost', 'received_qty',
    ];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
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
