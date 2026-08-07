<?php

namespace App\Support;

/**
 * ═══════════════════════════════════════════════════════════════
 * جيوكودينج العناوين — Nominatim (OpenStreetMap)
 * ═══════════════════════════════════════════════════════════════
 *
 * للصفوف اللي جاية من الشيتات من غير إحداثيات ولا لينك — بنجرب نطلّع
 * الإحداثيات من نص العنوان. نفس مصدر الخرايط المستخدم في السيستم كله
 * (OSM)، من غير مفتاح API.
 *
 * ⚠️ **النتيجة تقريبية بطبيعتها** — عنوان مكتوب بإيد مندوب مش دايماً
 * بيتفهم. اللي بيرجع بيتخزن كنقطة بداية، وزرار «اكتشف» في كارت
 * العميل موجود للتصحيح. مفيش إحداثي بيتأكد إلا بزيارة فعلية.
 *
 * ⚠️ **شروط استخدام Nominatim**: طلب واحد في الثانية و User-Agent
 * واضح — الـ sleep مقصود ومانفعش يتشال، السيرفر بتاعهم بيحظر اللي
 * بيضرب أسرع من كده.
 */
final class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    private const REVERSE = 'https://nominatim.openstreetmap.org/reverse';

    /** آخر طلب اتبعت إمتى — لضمان فاصل ثانية بين الطلبات */
    private static float $lastCall = 0.0;

    /**
     * إحداثيات من نص عنوان — Egypt-only.
     *
     * بنجرب من الأخص للأعم: العنوان كامل، وبعدين المنطقة والمحافظة بس —
     * العناوين التفصيلية («محل رقم ٥ أمام عمارات رامو») غالباً مش
     * بتتفهم، لكن «مدينة نصر، القاهرة» بترجع مركز المنطقة وده أحسن
     * من مفيش.
     *
     * @return array{lat: float, lng: float, precision: string}|null
     */
    public static function search(string $address, string $zone = '', string $governorate = ''): ?array
    {
        $attempts = [];

        if (trim($address) !== '') {
            $attempts['address'] = implode('، ', array_filter([trim($address), trim($zone), trim($governorate), 'مصر']));
        }

        if (trim($zone) !== '' || trim($governorate) !== '') {
            $attempts['zone'] = implode('، ', array_filter([trim($zone), trim($governorate), 'مصر']));
        }

        foreach ($attempts as $precision => $q) {
            if (($pt = self::query($q)) !== null) {
                return $pt + ['precision' => $precision];
            }
        }

        return null;
    }

    /** @return array{lat: float, lng: float}|null */
    private static function query(string $q): ?array
    {
        // فاصل ثانية بين الطلبات — شرط Nominatim
        $wait = self::$lastCall + 1.1 - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        self::$lastCall = microtime(true);

        $url = self::ENDPOINT.'?'.http_build_query([
            'q' => $q,
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'eg',
            'accept-language' => 'ar',
        ]);

        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "User-Agent: PROMAX-ERP (erp.promaxfoods.com)\r\n",
        ]]);

        // ⚠️ rescue — الشبكة بتقع، والاستيراد مايقعش عشان عنوان مش لاقي
        $body = rescue(fn () => file_get_contents($url, false, $ctx), null, false);

        if (! is_string($body)) {
            return null;
        }

        $json = json_decode($body, true);
        $hit = is_array($json) ? ($json[0] ?? null) : null;

        if (! is_array($hit) || ! isset($hit['lat'], $hit['lon'])) {
            return null;
        }

        $lat = (float) $hit['lat'];
        $lng = (float) $hit['lon'];

        return MapLink::valid($lat, $lng) ? ['lat' => round($lat, 7), 'lng' => round($lng, 7)] : null;
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * عكس الجيوكودينج — من إحداثيات لعنوان (2026-08-08)
     * ═══════════════════════════════════════════════════════════
     *
     * المندوب بيعمل تشيك إن عند العميل، والسيستم بياخد نقطته. الشاشة
     * بتاعت تأكيد اللوكيشن بتستخدم دي عشان تملا العنوان بدل ما اللي
     * بيراجع يكتب 300 عنوان بإيده.
     *
     * ⚠️ **OSM مش جوجل.** السيستم كله شغال على OpenStreetMap من غير
     * أي مفتاح API (نفس الخرايط في كل الشاشات) — جوجل بتحتاج حساب
     * فوترة ومفتاح ينتهي، وبتمنع تخزين نتايجها في قواعد بيانات، وده
     * بالظبط اللي إحنا بنعمله هنا.
     *
     * ⚠️ **بيرجّع اللغتين في نداءين.** Nominatim بترجّع لغة واحدة لكل
     * طلب (`accept-language`)، والشاشة محتاجة الاتنين — فبنسأل مرتين
     * بفاصل الثانية المفروض عليهم.
     *
     * ⚠️ **النتيجة اقتراح مش حقيقة.** OSM بتدي أقرب معلَم مش عنوان
     * المحل — واللي بيراجع بيصلّح بإيده. عشان كده الشاشة بتحط النص
     * في خانة قابلة للتعديل مش بتحفظه على طول.
     *
     * @return array{ar: string, en: string, governorate: ?string}|null
     */
    public static function reverse(float $lat, float $lng): ?array
    {
        if (! MapLink::valid($lat, $lng)) {
            return null;
        }

        $ar = self::reverseIn($lat, $lng, 'ar');
        $en = self::reverseIn($lat, $lng, 'en');

        if ($ar === null && $en === null) {
            return null;
        }

        return [
            // ⚠️ لو لغة وقعت، بنحط التانية مكانها — نص بلغة غلط أحسن
            // من خانة فاضية والمستخدم مش عارف إذا كان الزرار اشتغل
            'ar' => $ar['label'] ?? $en['label'] ?? '',
            'en' => $en['label'] ?? $ar['label'] ?? '',
            'governorate' => $ar['governorate'] ?? $en['governorate'] ?? null,
        ];
    }

    /** @return array{label: string, governorate: ?string}|null */
    private static function reverseIn(float $lat, float $lng, string $lang): ?array
    {
        $wait = self::$lastCall + 1.1 - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        self::$lastCall = microtime(true);

        $url = self::REVERSE.'?'.http_build_query([
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'json',
            // ⚠️ **18 = مستوى المبنى.** الافتراضي بيرجّع المدينة كلها،
            // وعنوان «القاهرة» على عميل مالوش أي فايدة.
            'zoom' => 18,
            'addressdetails' => 1,
            'accept-language' => $lang,
        ]);

        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "User-Agent: PROMAX-ERP (erp.promaxfoods.com)\r\n",
        ]]);

        $body = rescue(fn () => file_get_contents($url, false, $ctx), null, false);

        if (! is_string($body)) {
            return null;
        }

        $json = json_decode($body, true);

        if (! is_array($json) || ! isset($json['display_name'])) {
            return null;
        }

        $a = $json['address'] ?? [];

        // ⚠️ **بنبني السطر بنفسنا مش بناخد `display_name`.** الأخيرة
        // بترجّع 8 أجزاء آخرهم «مصر» و«الشرق الأوسط» والرقم البريدي —
        // سطر مالوش لازمة على ورقة فاتورة.
        $parts = array_filter([
            $a['house_number'] ?? null,
            $a['road'] ?? null,
            $a['neighbourhood'] ?? $a['suburb'] ?? null,
            $a['city_district'] ?? null,
        ]);

        $label = $parts !== []
            ? implode('، ', $parts)
            : (string) $json['display_name'];

        return [
            'label' => $label,
            'governorate' => $a['state'] ?? $a['governorate'] ?? null,
        ];
    }
}
