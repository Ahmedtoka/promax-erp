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
        'sku', 'image', 'product_id', 'units', 'bundle', 'sku_pushed_at',
    ];

    protected function casts(): array
    {
        return ['sku_pushed_at' => 'datetime', 'bundle' => 'array'];
    }

    /**
     * مكونات الربط: باندل = كذا منتج، وإلا منتج واحد بقطع الباك.
     *
     * ⚠️ في الباندل `product_id`/`units` = أول مكوّن — عشان «مربوط ولا
     * لأ» (`whereNull('product_id')`) يفضل صح في كل الشاشات القديمة.
     *
     * @return list<array{product_id: int, units: int}>
     */
    public function components(): array
    {
        return self::parts($this->bundle, $this->product_id, $this->units);
    }

    public function isBundle(): bool
    {
        return count(self::normalize($this->bundle)) > 1;
    }

    /**
     * تنضيف قايمة مكونات جاية من فورم أو JSON: منتج مكرر بيتجمع،
     * القطع أقل من ١ بتتشال.
     *
     * @return list<array{product_id: int, units: int}>
     */
    public static function normalize(mixed $bundle): array
    {
        $out = [];

        foreach (is_array($bundle) ? $bundle : [] as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $units = (int) ($row['units'] ?? 0);

            if ($pid > 0 && $units > 0) {
                $out[$pid] = ($out[$pid] ?? 0) + $units;
            }
        }

        return array_map(fn ($pid, $u) => ['product_id' => (int) $pid, 'units' => (int) $u],
            array_keys($out), array_values($out));
    }

    /**
     * @return list<array{product_id: int, units: int}>
     */
    public static function parts(mixed $bundle, mixed $productId, mixed $units): array
    {
        $b = self::normalize($bundle);

        if (count($b) > 1) {
            return $b;
        }

        return $productId !== null
            ? [['product_id' => (int) $productId, 'units' => max((int) $units, 1)]]
            : [];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
