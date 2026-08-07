<?php

namespace App\Support;

/**
 * ═══════════════════════════════════════════════════════════════
 * استخراج الإحداثيات من رابط خرايط
 * ═══════════════════════════════════════════════════════════════
 *
 * الروابط اللي بتوصلنا من المناديب بأشكال كتير:
 *   https://www.google.com/maps/@30.0566,31.3450,17z
 *   https://www.google.com/maps/place/X/@30.0566,31.3450,17z/data=!3d30.05!4d31.34
 *   https://maps.google.com/?q=30.0566,31.3450
 *   https://www.google.com/maps/search/?api=1&query=30.0566%2C31.3450
 *   https://maps.app.goo.gl/AbCdEf   ← مختصر، لازم يتفك من السيرفر
 *
 * ⚠️ **الترتيب مهم.** `!3d…!4d…` هي إحداثيات **المكان** نفسه، و`@…`
 * هي مركز الكاميرا وقت ما اتاخد اللينك. الاتنين بيختلفوا لما اليوزر
 * يحرّك الخريطة قبل ما ينسخ — و`!3d` هي الصح.
 */
final class MapLink
{
    /** الدومينات المسموح نفك اختصارها من السيرفر */
    private const SHORT_HOSTS = [
        'maps.app.goo.gl', 'goo.gl', 'g.co', 'maps.google.com', 'www.google.com',
    ];

    /**
     * قراءة الإحداثيات من نص الرابط من غير أي اتصال بالشبكة.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $url = urldecode($url);

        $patterns = [
            // إحداثيات المكان نفسه — الأدق
            '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',
            // مركز الكاميرا
            '/@(-?\d+\.\d+),\s*(-?\d+\.\d+)/',
            // q= أو query= أو ll=
            '/[?&](?:q|query|ll|center|daddr|sll)=(-?\d+\.\d+),\s*(-?\d+\.\d+)/i',
            // إحداثيات عارية في آخر المسار
            '#/(-?\d+\.\d+),\s*(-?\d+\.\d+)(?:[/?#]|$)#',
        ];

        foreach ($patterns as $re) {
            if (preg_match($re, $url, $m)) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];

                // ⚠️ فحص المدى إجباري. رقم زي `17z` (مستوى التكبير) كان
                // بيتقرا كإحداثي وبيحط دبوس في نص المحيط الأطلنطي.
                if (self::valid($lat, $lng)) {
                    return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
                }
            }
        }

        return null;
    }

    public static function valid(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
            && ! ($lat === 0.0 && $lng === 0.0);
    }

    /**
     * النقطة جوه مصر؟ (2026-08-08)
     *
     * ⚠️ **`valid()` كانت بتقبل أي نقطة على الكوكب** — والرسالة اللي
     * بتظهر للمستخدم بتقول «بره حدود مصر»، فالاتنين مكانوش بيتطابقوا.
     *
     * ⚠️ **الحاجة اللي بتحصل فعلاً: الإميوليتر.** أندرويد إميوليتر
     * لوكيشنه الافتراضي مقر جوجل في كاليفورنيا (37.42, -122.08)، فأي
     * تشيك إن من جهاز تيست بيكتب نقطة في أمريكا. وبتفضل في
     * `visits` وتطلع بعدين كـ«نقطة مقترحة» في شاشة تأكيد اللوكيشن.
     *
     * ⚠️ والحدود واسعة شوية عن قصد (حلايب وشلاتين وسيناء داخلين) —
     * ضيّقها بيقفل على مناطق حقيقية، وواسعة بتقفل على القارات التانية
     * وده كل المطلوب.
     */
    public static function inEgypt(float $lat, float $lng): bool
    {
        return self::valid($lat, $lng)
            && $lat >= 21.5 && $lat <= 32.0
            && $lng >= 24.5 && $lng <= 37.5;
    }

    /** الرابط ده مختصر ومحتاج يتفك؟ */
    public static function isShort(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::SHORT_HOSTS, true);
    }

    /**
     * فك الرابط المختصر بمتابعة إعادة التوجيه.
     *
     * ⚠️ **الدومين بيتفحص قبل أي اتصال.** من غير الفحص ده، اليوزر
     * بيلزق أي رابط والسيرفر بتاعنا بيروح يطلبه — ده SSRF: رابط على
     * `http://127.0.0.1` أو على شبكة داخلية بيتفتح من جوه السيرفر.
     *
     * ⚠️ مهلة قصيرة ومحاولات محدودة — الطلب ده بيحصل والمستخدم مستني
     * قدام الشاشة.
     */
    public static function expand(string $url): ?string
    {
        if (! self::isShort($url) || ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'HEAD',
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 6,
            'ignore_errors' => true,
            'header' => "User-Agent: PROMAX-ERP\r\n",
        ]]);

        // ⚠️ `rescue` — الشبكة بتقع، والدالة دي مالازمش ترمي 500 على
        // شاشة تعريف عميل عشان لينك مش راضي يفتح.
        $headers = rescue(fn () => get_headers($url, true, $ctx), null, false);

        if (! is_array($headers)) {
            return null;
        }

        // `Location` ممكن تبقى مصفوفة لو فيه أكتر من تحويلة
        $location = $headers['Location'] ?? $headers['location'] ?? null;

        if (is_array($location)) {
            $location = end($location);
        }

        return is_string($location) && $location !== '' ? $location : null;
    }

    /**
     * المحاولة الكاملة: اقرا من النص، وإن فشل فك الاختصار واقرا تاني.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function resolve(string $url): ?array
    {
        if ($found = self::parse($url)) {
            return $found;
        }

        $expanded = self::expand($url);

        return $expanded ? self::parse($expanded) : null;
    }
}
