<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * استثناء صلاحية على مستوى الرول (2026-08-23).
 *
 * نفس فكرة UserPermission بالظبط بس للرول كله: المفتاح اسم راوت أو
 * بادئة أو قسم منيو (nav.group_x) أو زرار (act.x) — والقيمة سماح/منع.
 * مفيش صف = وراثة من افتراضي الكود (Access::SCREENS / ACTIONS).
 *
 * ⚠️ **الكاش مش رفاهية.** Access::allows بتتنادى لكل لينك في السايدبار
 * في كل صفحة — من غير rememberForever كانت هتبقى كويري لكل لينك.
 * أي حفظ من شاشة الصلاحيات بينده flush().
 */
class RolePermission extends Model
{
    private const CACHE_KEY = 'role_perms_map';

    protected $fillable = ['role', 'perm', 'allow'];

    /**
     * كل الخرايط مرة واحدة: role => [perm => allow].
     *
     * ⚠️ الـtry/catch مش زيادة — أول رفع على اللايف السايدبار بيترسم
     * **قبل** ما المايجريشن تتشغّل، ومن غيره كل صفحة كانة هترمي 500
     * بسبب جدول لسه مش موجود.
     *
     * @return array<string, array<string, bool>>
     */
    public static function maps(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                $rows = static::query()->get(['role', 'perm', 'allow']);
            } catch (\Throwable) {
                return [];
            }

            $out = [];

            foreach ($rows as $r) {
                $out[$r->role][$r->perm] = (bool) $r->allow;
            }

            return $out;
        });
    }

    /** @return array<string, bool> */
    public static function mapFor(string $role): array
    {
        return self::maps()[$role] ?? [];
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
