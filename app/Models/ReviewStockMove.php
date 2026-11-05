<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تصفير أو رصيد افتتاحي بالباتش من شاشة المراجعة — للأثر والتراجع */
class ReviewStockMove extends Model
{
    protected $fillable = [
        'kind', 'warehouse_id', 'product_id', 'batch_id', 'batch_no', 'expires_on', 'qty', 'cost', 'user_id',
    ];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'cost' => 'decimal:4'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
