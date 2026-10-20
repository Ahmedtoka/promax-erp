<?php

namespace App\Services\Costing;

use App\Models\Setting;
use App\Models\User;

/** إعدادات التكاليف (الخط ٩) — كلها عبر `Setting::read` */
class CostingSettings
{
    public const KEYS = [
        'cost_method', 'cost_default_kg_rate', 'cost_unit_kg_fallback',
        'cost_markup_pct', 'cost_alert_pct', 'cost_branch_alloc',
    ];

    public const METHODS = ['batch', 'wavg'];

    public const ALLOC = ['sales', 'qty'];

    /** طريقة التكلفة الرسمية: batch (الافتراضي) | wavg */
    public static function method(): string
    {
        $m = Setting::read('cost_method');

        return in_array($m, self::METHODS, true) ? $m : 'batch';
    }

    /** سعر الكيلو الافتراضي للتحميل لما مفيش ساعات — null لو فاضي أو ≤ 0 */
    public static function defaultKgRate(): ?float
    {
        $v = Setting::read('cost_default_kg_rate');

        return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
    }

    /** وزن الوحدة بالكيلو لما مفيش مواصفة ولا محتوى صافي */
    public static function unitKgFallback(): float
    {
        $v = Setting::read('cost_unit_kg_fallback');

        return is_numeric($v) && (float) $v > 0 ? (float) $v : 0.1;
    }

    public static function markupPct(): float
    {
        $v = Setting::read('cost_markup_pct');

        return is_numeric($v) ? (float) $v : 20.0;
    }

    public static function alertPct(): float
    {
        $v = Setting::read('cost_alert_pct');

        return is_numeric($v) && (float) $v > 0 ? (float) $v : 90.0;
    }

    /** أساس توزيع تكاليف الفرع: sales | qty */
    public static function allocBasis(): string
    {
        $v = Setting::read('cost_branch_alloc');

        return in_array($v, self::ALLOC, true) ? $v : 'sales';
    }

    /**
     * @param  array<string, mixed>  $values  أي مفتاح من `KEYS` (الباقي بيتجاهل)
     */
    public static function save(array $values, User $by): void
    {
        $pairs = [];
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $values)) {
                $v = $values[$k];
                $pairs[$k] = $v === null ? null : (string) $v;
            }
        }
        $pairs['cost_settings_saved_at'] = now()->toDateTimeString();
        $pairs['cost_settings_saved_by'] = (string) $by->id;
        Setting::writeMany($pairs);
    }
}
