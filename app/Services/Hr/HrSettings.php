<?php

namespace App\Services\Hr;

use App\Models\Setting;

/**
 * إعدادات الموارد البشرية (Lane 7) — مكان واحد للمفاتيح وديفولتاتها.
 * المهمة ٤ بتزوّد مفاتيح المرتبات وأدوات num/json/all.
 */
final class HrSettings
{
    public const DEFAULTS = [
        // رصيد الإجازة السنوي لكل نوع — ⚠️ `unpaid=0` يعني من غير فحص رصيد
        'hr_leave_annual' => '21',
        'hr_leave_casual' => '6',
        'hr_leave_sick' => '30',
        'hr_leave_unpaid' => '0',
        // أيام الإجازة الأسبوعية: أرقام `Carbon::dayOfWeek` بفاصلة (5 = الجمعة)
        'hr_weekend_days' => '5',
    ];

    public static function get(string $key): string
    {
        return (string) Setting::read($key, self::DEFAULTS[$key] ?? '');
    }
}
