<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * مين يشوف شاشة «وقت الشغل الفعلي» (٥ نوفمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * قرار المالك: «محدش يشوفها غير حساب مستر جاد» — لا أدمن تاني ولا
 * مديرين. الفحص بكود الموظف مش بالرول، زي `AiGate` بالظبط.
 *
 * ⚠️ إعداد منفصل عن المساعد (`worktime_allowed_codes`) عن قصد: لو حد
 * اتسمحله بالمساعد بعدين، المراقبة ماتتفتحلوش معاه. فاضي = جاد.
 *
 * ⚠️ `Access::allows` بيسأل هنا قبل خروج الأدمن المبكر — فاللينك
 * بيختفي من منيو أي أدمن تاني، وشاشة الصلاحيات ماتقدرش تمنحها.
 * والراوت نفسه عليه `WorkTimeOnly` — الإخفاء لوحده مش حماية.
 */
final class WorkTimeGate
{
    /** جاد — المدير التنفيذي */
    public const DEFAULT_CODES = ['ADM-001'];

    /** بادئة راوتات الشاشة */
    public const PREFIX = 'erp.worktime';

    public static function allows(?User $user): bool
    {
        if ($user === null || ! $user->active) {
            return false;
        }

        return in_array((string) $user->code, self::codes(), true);
    }

    public static function covers(string $routeName): bool
    {
        return $routeName === self::PREFIX || str_starts_with($routeName, self::PREFIX.'.');
    }

    /** @return list<string> */
    public static function codes(): array
    {
        $raw = (string) Setting::read('worktime_allowed_codes', implode(',', self::DEFAULT_CODES));

        $codes = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($c) => $c !== ''));

        return $codes ?: self::DEFAULT_CODES;
    }
}
