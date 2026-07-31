<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند ريفيل: نقل صنف من مخزن الفرع للرف
 */
class ShelfRefill extends Model
{
    use HasFactory;

    protected $fillable = [
        'merch_visit_id', 'product_id',
        'shelf_before', 'store_qty', 'moved_qty', 'out_of_stock',
    ];

    protected function casts(): array
    {
        return ['out_of_stock' => 'boolean'];
    }

    public function merchVisit(): BelongsTo
    {
        return $this->belongsTo(MerchVisit::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** الكمية على الرف بعد الريفيل */
    public function shelfAfter(): int
    {
        return $this->shelf_before + $this->moved_qty;
    }
}
