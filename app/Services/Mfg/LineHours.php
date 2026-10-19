<?php

namespace App\Services\Mfg;

use App\Models\CostCenter;
use App\Models\ProductionOrder;
use Carbon\CarbonInterface;

/**
 * ساعات تشغيل خطوط الإنتاج — من `production_orders.hours` (بتتسجل عند «انتهى»).
 *
 * ⚠️ واجهة للخط ٩ (التكاليف): `forOrder()` = null يعني مفيش ساعات
 * متسجلة، فالخط ٩ بيحمّل بالكيلو ويعلّم الأمر.
 */
class LineHours
{
    public static function forOrder(ProductionOrder $o): ?float
    {
        return $o->hours === null ? null : round((float) $o->hours, 2);
    }

    /** مجموع ساعات أوامر الخط اللي انتهت في الشهر ده */
    public static function forMonth(CostCenter $line, CarbonInterface $month): float
    {
        return self::forRange($line, $month->copy()->startOfMonth(), $month->copy()->endOfMonth());
    }

    /** مجموع ساعات أوامر الخط اللي `finished_at` بتاعها في الفترة (الطرفين شاملين) */
    public static function forRange(CostCenter $line, CarbonInterface $from, CarbonInterface $to): float
    {
        return round((float) ProductionOrder::query()
            ->where('line_id', $line->id)->whereNotNull('hours')
            ->whereBetween('finished_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('hours'), 2);
    }
}
