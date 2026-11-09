<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * رول مخصص (٩/١١) — اسم + رول أساسي (`base_role`) + صلاحيات من المصفوفة.
 *
 * ⚠️ الكاش مش رفاهية: `User::activeCustomRole()` بتتنادى في كل طلب.
 * أي حفظ/مسح بيعمل flush().
 */
class Role extends Model
{
    private const CACHE_KEY = 'custom_roles_map';

    protected $fillable = [
        'name_ar', 'name_en', 'base_role', 'cloned_from', 'web_login', 'app_login',
        'description_ar', 'description_en', 'active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'web_login' => 'boolean',
            'app_login' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // ⚠️ بعد الكوميت (مراجعة ٩/١١): المسح جوه الترانزاكشن كان بيسيب ريكوست
        // متزامن يكاش الصفوف القديمة للأبد. بره ترانزاكشن بيتنفذ على طول.
        static::saved(fn () => DB::afterCommit(fn () => static::flush()));
        static::deleted(fn () => DB::afterCommit(fn () => static::flush()));
    }

    /** الرولز الأساسية المسموحة (كل رولز اليوزر ماعدا الأدمن) */
    public static function BASE_ROLES(): array
    {
        return array_values(array_diff(array_keys(User::ROLES), ['admin']));
    }

    public function key(): string
    {
        return 'c:'.$this->id;
    }

    public function displayName(): string
    {
        $ar = (string) $this->name_ar;
        $en = (string) $this->name_en;

        return app()->getLocale() === 'ar'
            ? ($ar !== '' ? $ar : $en)
            : ($en !== '' ? $en : $ar);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'custom_role_id');
    }

    /** @return array<int, Role> */
    public static function maps(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                return static::query()->get()->keyBy('id')->all();
            } catch (\Throwable) {
                return []; // أول رفع قبل المايجريشن
            }
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function fromKey(string $k): ?Role
    {
        if (! preg_match('/^c:(\d+)$/', $k, $m)) {
            return null;
        }

        return static::maps()[(int) $m[1]] ?? null;
    }
}
