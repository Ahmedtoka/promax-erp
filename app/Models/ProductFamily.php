<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * عائلة منتجات — الكيان اللي بيتحكم في مدة الصلاحية (2026-08-06).
 *
 * `shelf_life_months` هي **مصدر الحقيقة لمدة صلاحية منتجات العائلة**:
 * الاستلام بيحسب منها الانتهاء لو ماتكتبش، وحفظ شاشة العائلات بيعيد
 * حساب انتهاء كل الباتشات من تاريخ إنتاجها + مدة عائلتها.
 *
 * ⚠️ المفتاح `key` ثابت — المنتجات متخزن عليها المفتاح، والأسماء
 * هي اللي بتتعدل (نفس دوكترين المحافظات بالظبط).
 */
class ProductFamily extends Model
{
    use HasBilingualName;

    protected $fillable = ['key', 'name', 'name_en', 'shelf_life_months'];

    /** كاش الصفوف — الشاشات بتسأل عن المسمى لكل صف منتج */
    private static ?array $cache = null;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'family', 'key');
    }

    /** كل العائلات مفهرسة بالمفتاح — من الكاش */
    public static function rows(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('product_families')) {
                return static::$cache = [];
            }

            return static::$cache = static::query()->orderBy('id')->get()->keyBy('key')->all();
        } catch (\Throwable) {
            return static::$cache = [];
        }
    }

    public static function flush(): void
    {
        static::$cache = null;
    }

    /** مسمى العائلة باللغة الحالية — و fallback للثوابت القديمة */
    public static function label(?string $key): string
    {
        if ($key === null || $key === '') {
            return '—';
        }

        $row = static::rows()[$key] ?? null;

        if ($row) {
            return $row->displayName();
        }

        $langKey = 'enums.family.'.$key;

        return \Illuminate\Support\Facades\Lang::has($langKey)
            ? __($langKey)
            : (Product::FAMILIES[$key] ?? $key);
    }

    /** مدة صلاحية العائلة بالشهور — null لو ماتحددتش */
    public static function monthsFor(?string $key): ?int
    {
        $row = $key ? (static::rows()[$key] ?? null) : null;

        return $row?->shelf_life_months ? (int) $row->shelf_life_months : null;
    }

    /** للسيلكتات: key => label */
    public static function options(): array
    {
        $rows = static::rows();

        if ($rows === []) {
            return Product::FAMILIES;
        }

        return collect($rows)->map(fn ($f) => $f->displayName())->all();
    }
}
