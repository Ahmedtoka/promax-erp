<?php

namespace App\Support;

use App\Models\Zone;

/**
 * ═══════════════════════════════════════════════════════════════
 * اقتراح العنوان والمحافظة والمنطقة من نقطة (2026-08-14)
 * ═══════════════════════════════════════════════════════════════
 *
 * **المنطق ده كان جوّه `ClientLocationController::suggest`** — عكس
 * الجيوكودينج + مطابقة المحافظة + أقرب منطقة، كلهم مكتوبين في
 * الكنترولر. أول ما الأبلكيشن احتاج نفس الشغل (شاشة «ضيف لوكيشن
 * العميل»، ١٤ أغسطس ٢٠٢٦) كان في طريقين:
 *   • ننسخ الكود في `FieldApiController` → **جيوكودرين** بسقف مسافة
 *     مختلف وفلتر محافظة مختلف، والداشبورد والأبلكيشن يقترحوا منطقتين
 *     مختلفتين لنفس النقطة.
 *   • نطلّعه هنا وينادوا الاتنين عليه.
 *
 * فالكلاس ده هو **المكان الوحيد** اللي بيحوّل نقطة لاقتراح عنوان.
 *
 * ⚠️ **اقتراح مش حقيقة.** OSM بتدي أقرب معلَم مش عنوان المحل —
 * الاتنين (الداشبورد والأبلكيشن) بيحطوا الرد في خانات **قابلة
 * للتعديل** واللي قدام الشاشة بيصحّح. مفيش مسار بيحفظ الرد مباشرة.
 *
 * ⚠️ **بيرجّع صف كامل دايماً — و`matched` بتقول العنوان جه ولا لأ.**
 * الشبكة بتقع، وNominatim بتحظر اللي بيضرب بسرعة؛ ساعتها العنوان
 * بيرجع `null` **لكن المنطقة بترجع عادي** لأنها بتتحسب من الداتابيز
 * بتاعتنا مش من الإنترنت. المندوب في الشارع بشبكة ضعيفة بياخد
 * المنطقة على الأقل بدل شاشة فاضية.
 */
final class GeoSuggest
{
    /**
     * ⚠️ **٢٥ كم سقف لأقرب منطقة.** من غيره أي نقطة هتلاقي «أقرب»
     * منطقة حتى لو على بعد محافظتين — والتسكين على منطقة غلط أسوأ
     * من خانة فاضية، لأنه بيبان صح.
     */
    private const MAX_ZONE_KM = 25.0;

    /**
     * اقتراح كامل للنقطة.
     *
     * @return array{
     *     matched: bool,
     *     address_en: ?string,
     *     address_ar: ?string,
     *     governorate: ?string,
     *     governorate_label: ?string,
     *     zone_id: ?int,
     *     zone_name: ?string
     * }
     */
    public static function forPoint(float $lat, float $lng): array
    {
        $hit = Geocoder::reverse($lat, $lng);

        // ⚠️ اسم المحافظة اللي OSM بترجّعه نص حر («محافظة القاهرة» أو
        // «Cairo Governorate» حسب اللغة) — `match()` بتحوّله لكود
        // السيستم، ونفس الدالة اللي المستورد بيستخدمها.
        $gov = $hit === null
            ? null
            : Governorates::match((string) ($hit['governorate'] ?? ''));

        $zoneId = self::nearestZone($lat, $lng, $gov);
        $zone = $zoneId === null ? null : Zone::find($zoneId);

        return [
            'matched' => $hit !== null,
            // ⚠️ `?: null` مش `??` — Nominatim بترجّع سترنج فاضية لما
            // اللغة الواحدة تفشل، وخانة فيها '' في الأبلكيشن بتبان
            // «اتملت» وهي فاضية.
            'address_en' => $hit === null ? null : (trim((string) $hit['en']) ?: null),
            'address_ar' => $hit === null ? null : (trim((string) $hit['ar']) ?: null),
            'governorate' => $gov,
            'governorate_label' => $gov === null ? null : Governorates::label($gov),
            'zone_id' => $zoneId,
            'zone_name' => $zone?->displayName(),
        ];
    }

    /**
     * أقرب منطقة للنقطة — أو `null` لو مفيش حاجة قريبة كفاية.
     *
     * ⚠️ **بيتفلتر بالمحافظة الأول لو معروفة.** أقرب منطقة جغرافياً
     * ممكن تكون في محافظة تانية على الحدود — والتسكين على منطقة في
     * محافظة غلط أسوأ من خانة فاضية، لأنه بيبان صح.
     *
     * ⚠️ المناطق اللي مالهاش إحداثيات بتتستبعد (`whereNotNull`).
     *
     * ⚠️ **هافرساين بالكيلومتر** — الفخ المعروف في المشروع إن
     * الدالة بترجّع كيلومترات والكود بيفتكرها أمتار.
     */
    public static function nearestZone(float $lat, float $lng, ?string $gov): ?int
    {
        $zones = self::zoneRows($gov);

        // ⚠️ المحافظة مالهاش مناطق بإحداثيات؟ نجرب من غير فلترها بدل
        // ما نرجّع فاضي — الاقتراح بيتعدّل بالإيد على أي حال.
        if ($zones->isEmpty() && $gov !== null) {
            $zones = self::zoneRows(null);
        }

        $best = null;
        $bestKm = self::MAX_ZONE_KM;

        foreach ($zones as $z) {
            $km = self::haversineKm($lat, $lng, (float) $z->lat, (float) $z->lng);

            if ($km < $bestKm) {
                $bestKm = $km;
                $best = (int) $z->id;
            }
        }

        return $best;
    }

    /** @return \Illuminate\Support\Collection<int, Zone> */
    private static function zoneRows(?string $gov)
    {
        return Zone::query()
            ->where('active', true)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->when($gov, fn ($q) => $q->where('governorate', $gov))
            ->get(['id', 'lat', 'lng']);
    }

    /** المسافة بالكيلومتر بين نقطتين */
    private static function haversineKm(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $r = 6371.0;
        $dLat = deg2rad($bLat - $aLat);
        $dLng = deg2rad($bLng - $aLng);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($dLng / 2) ** 2;

        return $r * 2 * asin(min(1.0, sqrt($h)));
    }

    /**
     * قايمة المحافظات للدروب داون في الأبلكيشن.
     *
     * ⚠️ **بترجع مع رد الجيوكود مش في البوت ستراب.** البوت ستراب
     * بيتنده كل ما البلس يتغيّر — يعني عشرات المرات في اليوم لكل
     * مندوب. ليستة ثابتة مابتتغيرش شهور مالهاش لازمة تتحمّل مع كل
     * مزامنة؛ الشاشة الوحيدة اللي محتاجاها بتنده الجيوكود أصلاً.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function governorateOptions(): array
    {
        $out = [];

        foreach (Governorates::options() as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }

    /**
     * قايمة المناطق للدروب داون في الأبلكيشن — **كل** المناطق النشطة
     * مش مناطق المندوب.
     *
     * ⚠️ العميل اللي المندوب واقف قدامه ممكن يكون في منطقة مش
     * متسكّنة له (بول الفريق بيدي عملاء بره زوناته) — وقفل الليستة
     * على زوناته كان هيخلّي الاقتراح نفسه مش موجود في الدروب داون.
     *
     * @return list<array{id: int, name: string, governorate: ?string}>
     */
    public static function zoneOptions(): array
    {
        return Zone::where('active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'name_en', 'governorate'])
            ->map(fn (Zone $z) => [
                'id' => (int) $z->id,
                'name' => $z->displayName(),
                'governorate' => $z->governorate,
            ])
            ->values()->all();
    }
}
