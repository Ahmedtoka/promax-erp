<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** بند أوردر أونلاين — سطر من أوردر شوبيفاي زي ما جه */
class OnlineOrderItem extends Model
{
    protected $fillable = [
        'online_order_id', 'shopify_line_id', 'shopify_variant_id',
        'sku', 'title', 'product_id', 'qty', 'returned_qty', 'units_per', 'price', 'total',
    ];

    /** عدد القطع الفعلي — الكمية × قطع الباك (فاريانت الـ12 قطعة) */
    public function pieces(): int
    {
        return (int) $this->qty * max((int) $this->units_per, 1);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'online_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
