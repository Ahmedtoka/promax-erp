<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط فاريانت شوبيفاي بمنتج السيستم.
 *
 * المصدر الأول للمطابقة وقت السينك؛ لو الفاريانت مش متربط بنجرب
 * الـSKU على products.code. الحفظ من شاشة الربط بيكتب كود المنتج
 * كـSKU في شوبيفاي كمان (قرار المالك ٣/٩) — sku_pushed_at بتوثّق
 * إن الكتابة وصلت فعلاً.
 */
class ShopifyProductLink extends Model
{
    protected $fillable = [
        'shopify_variant_id', 'shopify_product_id', 'title', 'variant_title',
        'sku', 'image', 'product_id', 'units', 'sku_pushed_at',
    ];

    protected function casts(): array
    {
        return ['sku_pushed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
