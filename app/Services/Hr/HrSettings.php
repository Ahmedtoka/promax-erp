<?php

namespace App\Services\Hr;

use App\Models\Setting;

/**
 * إعدادات الموارد البشرية (Lane 7) — مكان واحد للمفاتيح وديفولتاتها.
 * القيم كلها نصوص (`Setting::read`)، والقوايم JSON.
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

        // ═══ المرتبات (المهمة ٤) ═══
        // ⚠️ دي ديفولتات قابلة للتعديل ولازم المالك يراجعها قبل أول مرتب —
        // مش قيم قانونية مضمونة. التأمينات: قانون ١٤٨ لسنة ٢٠١٩ وحدود أجر
        // الاشتراك لسنة ٢٠٢٦ (بتتغير كل يناير). الضريبة: قانون ٧ لسنة ٢٠٢٤
        // (الشرايح وإلغاء الشرايح الأقل لأصحاب الدخل العالي). الشاشة بتعرض
        // تنبيه لحد ما الأدمن يحفظها (`hr_rates_verified_at`).
        'hr_si_employee_rate' => '0.11',
        'hr_si_employer_rate' => '0.1875',
        'hr_si_min_wage' => '2700',
        'hr_si_max_wage' => '16700',
        // الإعفاء الشخصي السنوي
        'hr_tax_exemption' => '20000',
        // `[from, to, rate]` على الوعاء السنوي بعد الإعفاء — `to=null` يعني مالوش سقف
        'hr_tax_brackets' => '[[0,40000,0],[40000,55000,0.10],[55000,70000,0.15],[70000,200000,0.20],[200000,400000,0.225],[400000,1200000,0.25],[1200000,null,0.275]]',
        // `[over, upto_bracket_index]` — لما الوعاء يعدّي `over` كل الدخل لحد آخر
        // الشريحة `upto` بيتحسب بسعرها (أعلى `over` منطبق هو اللي بيكسب)
        'hr_tax_elimination' => '[[600000,1],[700000,2],[800000,3],[900000,4],[1200000,5]]',
        // الوقت: الشهر ٣٠ يوم واليوم ٤٨٠ دقيقة، والإضافي بـ ١٫٣٥
        'hr_month_days' => '30',
        'hr_day_minutes' => '480',
        'hr_shift_start' => '09:00',
        'hr_late_grace_minutes' => '15',
        'hr_overtime_rate' => '1.35',
        // فاضي = الديفولتات لسه ماتراجعتش (الحفظ من الشاشة بيختمه)
        'hr_rates_verified_at' => '',
    ];

    public static function get(string $key): string
    {
        return (string) Setting::read($key, self::DEFAULTS[$key] ?? '');
    }

    public static function num(string $key): float
    {
        return (float) self::get($key);
    }

    /** القايمة متخزّنة JSON — لو اتبوظت بيرجع للديفولت. */
    public static function json(string $key): array
    {
        $v = json_decode(self::get($key), true);
        if (is_array($v)) {
            return $v;
        }
        $d = json_decode(self::DEFAULTS[$key] ?? '[]', true);

        return is_array($d) ? $d : [];
    }

    /** @return array<string, string> كل المفاتيح بقيمها الحالية */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            $out[$k] = self::get($k);
        }

        return $out;
    }
}
