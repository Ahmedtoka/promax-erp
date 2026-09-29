<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * مين مسموحله يستخدم مساعد الذكاء الاصطناعي جوه السيستم (٢٩/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * قرار المالك: «اقفل على كله وسيب جاد بس». الفحص بكود الموظف مش
 * بالرول — أدمن تاني (سعد، عمار، سهيلة) مش مسموح.
 *
 * ⚠️ القايمة قابلة للتغيير من غير نشر: Setting `ai_allowed_codes`
 * (أكواد مفصولة بفاصلة). فاضي = الافتراضي (جاد).
 *
 * ⚠️ ده بيقفل المساعد اللي **جوه** السيستم بس. إضافات المتصفح اللي
 * بتتحكم في الصفحة (Claude in Chrome وغيرها) بتشتغل بجلسة الموظف
 * نفسه ومفيش موقع يقدر يمنعها منع مضمون — حمايتها الصلاحيات ومركز
 * النشاط (كل اللي بيتعمل متسجل باسم صاحب الحساب).
 */
final class AiGate
{
    /** جاد — المدير التنفيذي */
    public const DEFAULT_CODES = ['ADM-001'];

    public static function allows(?User $user): bool
    {
        if ($user === null || ! $user->active) {
            return false;
        }

        return in_array((string) $user->code, self::codes(), true);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        $raw = (string) Setting::read('ai_allowed_codes', implode(',', self::DEFAULT_CODES));

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($c) => $c !== ''));
    }
}
