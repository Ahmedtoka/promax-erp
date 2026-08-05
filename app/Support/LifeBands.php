<?php

namespace App\Support;

use App\Models\Batch;
use App\Models\Location;

/**
 * نطاقات عمر البلوكات (FEFO بالمكان — قرار المالك 2026-08-06):
 *
 * المخزن بلوكات مش شيلفات — بلوك كامل كراتين بنفس مدى الصلاحية.
 * كل بلوك ليه «نطاق عمر»، والبضاعة بتترصّف في البلوك المطابق
 * لعمرها المتبقي وقت الترصيف — **منع نهائي** لو النطاق غلط:
 *
 *   شهر    (M) ≤ 30 يوم        بروماكس شهر
 *   3 شهور (Q) 31 – 90         بروماكس 3 شهور
 *   6 شهور (H) 91 – 180        بروماكس 6 شهور
 *   سنة    (Y) أكتر من 180     بروماكس سنة
 *
 * الصرف نفسه FEFO بالباتش زي ما هو (planDeduction) — البلوكات
 * بتنظّم المكان عشان الساحب يروح على طول للبلوك اللي قرب يخلص.
 * البضاعة اللي بتقعد وعمرها بيقل: تقرير النقل في تقرير الصلاحية
 * بيطلّع اللي محتاج ينزل نطاق، والنقل بضغطة (moveTo بيسمح بالنزول).
 *
 * ⚠️ الرف اللي مالوش نطاق (`life_band = null`) رف حر — بيقبل أي
 * حاجة، عشان الأرفف القديمة (A01...) تفضل شغالة زي ما هي.
 */
class LifeBands
{
    /** الترتيب من الأقرب انتهاءً — والحد الأعلى بالأيام (null = مفتوح) */
    public const BANDS = [
        'month' => 30,
        'quarter' => 90,
        'half' => 180,
        'year' => null,
    ];

    /** بادئة كود البلوك: M01 / Q01 / H01 / Y01 */
    public const PREFIX = [
        'month' => 'M',
        'quarter' => 'Q',
        'half' => 'H',
        'year' => 'Y',
    ];

    /** النطاق المناسب لعمر متبقي بالأيام — null لبضاعة من غير صلاحية */
    public static function bandFor(?int $daysLeft): ?string
    {
        if ($daysLeft === null) {
            return null;
        }

        foreach (self::BANDS as $band => $max) {
            if ($max === null || $daysLeft <= $max) {
                return $band;
            }
        }

        return 'year';
    }

    /** نطاق الباتش ده النهارده — من تاريخ انتهائه */
    public static function bandForBatch(Batch $batch): ?string
    {
        return $batch->expires_on === null ? null : self::bandFor($batch->daysLeft());
    }

    public static function label(?string $band): string
    {
        return $band === null ? __('stock.band_free') : __('stock.band_'.$band);
    }

    public static function badgeClass(?string $band): string
    {
        return match ($band) {
            'month' => 'b-red',
            'quarter' => 'b-orange',
            'half' => 'b-blue',
            'year' => 'b-green',
            default => 'b-gray',
        };
    }

    /** للسيلكتات — بالترتيب من الأقرب */
    public static function options(): array
    {
        return array_combine(
            array_keys(self::BANDS),
            array_map(fn ($b) => self::label($b), array_keys(self::BANDS)),
        );
    }

    /**
     * الحارس المركزي — ترصيف باتش على رف مسموح؟
     *
     * بترجع null لو تمام، أو رسالة الرفض. **منع نهائي مفيش تجاوز**:
     * - رف حر (من غير نطاق) بيقبل أي حاجة.
     * - باتش من غير تاريخ انتهاء بيتقبل في أي حتة.
     * - غير كده: نطاق الباتش لازم يساوي نطاق البلوك بالظبط.
     */
    public static function guard(Location $location, Batch $batch): ?string
    {
        $locBand = $location->life_band;

        if ($locBand === null || $batch->expires_on === null) {
            return null;
        }

        $need = self::bandForBatch($batch);

        if ($need === $locBand) {
            return null;
        }

        $suggest = self::suggest($location->warehouse_id, $batch);

        return __('stock.band_mismatch', [
            'location' => $location->code,
            'band' => self::label($locBand),
            'days' => max($batch->daysLeft(), 0),
            'need' => self::label($need),
            'suggest' => $suggest ? ' → '.$suggest->code : '',
        ]);
    }

    /** البلوك المقترح للباتش ده في المخزن ده — أول بلوك نشط من نطاقه */
    public static function suggest(int $warehouseId, Batch $batch): ?Location
    {
        $need = self::bandForBatch($batch);

        if ($need === null) {
            return null;
        }

        return Location::where('warehouse_id', $warehouseId)
            ->where('life_band', $need)
            ->where('active', true)
            ->orderBy('code')
            ->first();
    }
}
