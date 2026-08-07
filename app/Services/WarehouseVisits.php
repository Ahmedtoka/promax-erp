<?php

namespace App\Services;

use App\Models\TrackEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseVisit;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * دخول وخروج المخزن — كل المنطق في مكان واحد (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **مفيش أي كود تاني بيفتح أو يقفل زيارة مخزن.** الزيارة المفتوحة
 * هي إذن الاستلام (`RequireWarehouseVisit`) — ولو حد فتحها أو قفلها
 * من بره هنا، الإذن بيتوزّع أو بيتسحب من غير ما التراك يسجّل، ومحدش
 * يعرف مين استلم من فين.
 *
 * **الفلو:**
 *
 *   حضور (HR) ──► دخول المخزن ──► استلام عهدة / تسليم PO ──► خروج
 *                       │                                      ▲
 *                       └──────── قفل تلقائي مع الانصراف ───────┘
 *
 * ⚠️ **الحضور شرط قبل الدخول.** المخزن جزء من يوم شغل، والدخول
 * لمخزن من غير حضور مسجّل بيدي وقت شغل مالوش يوم يتحسب عليه.
 */
final class WarehouseVisits
{
    /** الزيارة المفتوحة — المصدر الوحيد لإذن الاستلام */
    public static function open(User $user): ?WarehouseVisit
    {
        return WarehouseVisit::openFor($user);
    }

    public static function isInside(User $user): bool
    {
        return static::open($user) !== null;
    }

    /**
     * دخول مخزن. بترجّع `[الخطأ، الزيارة]`.
     *
     * @return array{0: ?string, 1: ?WarehouseVisit}
     */
    public static function checkIn(
        User $user,
        Warehouse $warehouse,
        ?float $lat = null,
        ?float $lng = null,
    ): array {
        if (! Attendance::canWork($user)) {
            return [__('warehouse.need_attendance'), null];
        }

        $current = static::open($user);

        // ⚠️ **نفس المخزن = مفيش زيارة جديدة.** المندوب بيدوس دخول
        // تاني بالغلط (أو الشاشة بتعيد الطلب على شبكة ضعيفة)، ولو
        // فتحنا زيارة كل مرة كان اليوم بيطلع 6 زيارات لنفس المخزن
        // مدة كل واحدة دقيقتين — ومجموع الوقت غلط.
        if ($current !== null && $current->warehouse_id === $warehouse->id) {
            return [null, $current];
        }

        // ⚠️ **مخزن تاني = الأول بيتقفل.** الموظف مايكونش في مخزنين
        // في نفس اللحظة؛ وسيبان الأولى مفتوحة كان بيخلّي الاتنين
        // بيعدّوا وقت في نفس الوقت.
        return [null, DB::transaction(function () use ($user, $warehouse, $lat, $lng, $current) {
            if ($current !== null) {
                static::close($current, auto: true);
            }

            $visit = WarehouseVisit::create([
                'user_id' => $user->id,
                'warehouse_id' => $warehouse->id,
                'checked_in_at' => now(),
                'lat' => $lat,
                'lng' => $lng,
            ]);

            // ⚠️ التوقيع مواضع مش أراي: (يوزر، نوع، عنوان، تفصيلة، lat، lng)
            TrackEvent::log(
                $user,
                'wh_in',
                __('warehouse.event_in', ['warehouse' => $warehouse->displayName()]),
                null,
                $lat,
                $lng,
            );

            return $visit->load('warehouse');
        })];
    }

    /**
     * خروج من المخزن.
     *
     * ⚠️ **الدقايق بتتخزن هنا مرة واحدة للأبد.** الزيارة المقفولة
     * حقيقة تاريخية — حسابها وقت العرض كان بيتغيّر لو التوقيت الصيفي
     * اتحرك، وتقرير الشهر يطلع أرقام مختلفة كل ما تفتحه.
     */
    public static function close(
        WarehouseVisit $visit,
        ?float $lat = null,
        ?float $lng = null,
        bool $auto = false,
    ): WarehouseVisit {
        if (! $visit->isOpen()) {
            return $visit;
        }

        $out = now();

        $visit->forceFill([
            'checked_out_at' => $out,
            'out_lat' => $lat,
            'out_lng' => $lng,
            'minutes' => max((int) $visit->checked_in_at->diffInMinutes($out, absolute: false), 0),
            'auto_closed' => $auto,
        ])->save();

        if (! $auto) {
            TrackEvent::log(
                $visit->user,
                'wh_out',
                __('warehouse.event_out', [
                    'warehouse' => $visit->warehouse?->displayName() ?? '—',
                    'mins' => $visit->minutes,
                ]),
                null,
                $lat,
                $lng,
            );
        }

        return $visit;
    }

    /**
     * قفل أي زيارة مفتوحة للموظف — بيتنادى مع الانصراف ومع قفل اليوم.
     *
     * ⚠️ **الانصراف بيقفلها عن قصد.** الموظف اللي انصرف مش في المخزن
     * بالتعريف؛ وسيبانها مفتوحة كان معناه إن إذن الاستلام بيفضل معاه
     * بعد ما يمشي — ويقدر يستلم من بيته.
     */
    public static function closeOpenFor(User $user, bool $auto = true): bool
    {
        $visit = static::open($user);

        if ($visit === null) {
            return false;
        }

        static::close($visit, auto: $auto);

        return true;
    }
}
