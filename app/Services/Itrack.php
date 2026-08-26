<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * ═══════════════════════════════════════════════════════════════
 * iTrack — منصة أجهزة تتبع العربيات (٢٦ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الدوكيومنتيشن: https://www.itrack.top/api.jsp — البيز:
 * http://api.itrack.top/api/... وكل رد فيه code (0 = نجاح) و record.
 *
 * ⚠️ عقيدة التوكن (من الدوكيومنتيشن نفسها):
 *   • صالح ساعتين، وبنجدده لو فاضل أقل من نص ساعة.
 *   • **طلب توكن جديد بيلغي القديم فوراً** — عشان كده التوكن متخزن
 *     مركزي في Settings ومفيش أي كود تاني يطلب توكن لنفسه.
 *   • التوقيع: md5(md5(password) + time) حروف صغيرة.
 *
 * ⚠️ الباسورد بيتخزن في Settings كـ**md5 بس** (`itrack_password_md5`)
 *   — البلين تكست مش محتاجينه أصلاً والتسريب أخف.
 *
 * الحدود المرعية هنا: track/mileage حد ١٠٠ IMEI للطلب · playback
 * حد ١٠٠٠ نقطة والاستكمال بآخر gpstime · وأخطاء 1001x بتجدد
 * التوكن وتعيد المحاولة مرة واحدة.
 */
class Itrack
{
    private const BASE = 'http://api.itrack.top/api/';

    /** رسايل أشهر أكواد الأخطاء — للشاشة والـCLI */
    public const ERRORS = [
        10004 => 'parameter error', 10005 => 'missing parameter',
        10007 => 'permission denied', 10009 => 'too frequent',
        10010 => 'token missing', 10011 => 'token invalid',
        10012 => 'token expired', 10013 => 'IMEI not authorized',
        10014 => 'request time error', 10016 => 'account blocked',
        20001 => 'account/password wrong', 20005 => 'target not found',
        20023 => 'no data', 20046 => 'target expired',
    ];

    public static function enabled(): bool
    {
        return trim((string) Setting::read('itrack_account')) !== ''
            && trim((string) Setting::read('itrack_password_md5')) !== '';
    }

    /**
     * حفظ بيانات الحساب من الشاشة — الباسورد بيتحول md5 فوراً
     * (ولو المدخل 32-hex فهو md5 جاهز ومابنلمسوش)، والتوكن بيتصفّر
     * عشان أول نداء يجيب واحد جديد بالحساب الجديد.
     */
    public static function saveCredentials(string $account, ?string $password): void
    {
        $pairs = ['itrack_account' => trim($account)];

        if ($password !== null && trim($password) !== '') {
            $p = trim($password);
            $pairs['itrack_password_md5'] = preg_match('/^[a-f0-9]{32}$/i', $p)
                ? strtolower($p) : md5($p);
        }

        $pairs['itrack_token'] = null;
        $pairs['itrack_token_exp'] = null;
        Setting::writeMany($pairs);
    }

    /** التوكن الساري — من الكاش أو بطلب جديد. null = فشل (السبب في itrack_last_error) */
    public static function token(): ?string
    {
        $tok = Setting::read('itrack_token');
        $exp = (int) Setting::read('itrack_token_exp');

        // فاضل أكتر من نص ساعة؟ استعمله — التجديد المبكر بيلغي القديم ببلاش
        if ($tok && $exp > time() + 1800) {
            return $tok;
        }

        return self::refreshToken();
    }

    private static function refreshToken(): ?string
    {
        $time = time();
        $sig = md5(Setting::read('itrack_password_md5').$time);

        $r = self::raw('authorization', [
            'time' => $time,
            'account' => Setting::read('itrack_account'),
            'signature' => $sig,
        ]);

        if (! $r['ok']) {
            Setting::write('itrack_last_error', $r['error']);

            return null;
        }

        $tok = $r['record']['access_token'] ?? null;
        $ttl = (int) ($r['record']['expires_in'] ?? 7200);

        if (! $tok) {
            Setting::write('itrack_last_error', 'empty token');

            return null;
        }

        Setting::writeMany([
            'itrack_token' => $tok,
            'itrack_token_exp' => (string) ($time + $ttl),
            'itrack_last_error' => null,
        ]);

        return $tok;
    }

    /**
     * نداء موقّع بالتوكن — لو التوكن باظ (1001x) بيجدد ويعيد مرة واحدة.
     *
     * @return array{ok: bool, record: mixed, code: int, error: string}
     */
    public static function call(string $path, array $params = []): array
    {
        $tok = self::token();
        if ($tok === null) {
            return ['ok' => false, 'record' => null, 'code' => -1,
                'error' => 'no token: '.Setting::read('itrack_last_error')];
        }

        $r = self::raw($path, $params + ['access_token' => $tok]);

        if (! $r['ok'] && in_array($r['code'], [10010, 10011, 10012], true)) {
            Setting::writeMany(['itrack_token' => null, 'itrack_token_exp' => null]);
            $tok = self::token();
            if ($tok !== null) {
                $r = self::raw($path, $params + ['access_token' => $tok]);
            }
        }

        if (! $r['ok']) {
            Setting::write('itrack_last_error', $r['error']);
        }

        return $r;
    }

    /** الطلب الخام — بيرجع النجاح/الفشل بشكل موحّد ومن غير ما يرمي أبداً */
    private static function raw(string $path, array $params): array
    {
        try {
            $res = Http::timeout(20)->get(self::BASE.$path, $params);
            $body = $res->json();

            if (! is_array($body)) {
                return ['ok' => false, 'record' => null, 'code' => -1,
                    'error' => 'HTTP '.$res->status().' — non-JSON'];
            }

            $code = (int) ($body['code'] ?? -1);
            if ($code !== 0) {
                $msg = self::ERRORS[$code] ?? ($body['message'] ?? 'unknown');

                return ['ok' => false, 'record' => null, 'code' => $code,
                    'error' => "code {$code}: {$msg}"];
            }

            return ['ok' => true, 'record' => $body['record'] ?? null, 'code' => 0, 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'record' => null, 'code' => -1, 'error' => $e->getMessage()];
        }
    }

    /** آخر موقع لمجموعة أجهزة — بيقسم ١٠٠/طلب (حد المنصة) */
    public static function track(array $imeis): array
    {
        $out = [];
        foreach (array_chunk($imeis, 100) as $chunk) {
            $r = self::call('track', ['imeis' => implode(',', $chunk)]);
            if ($r['ok'] && is_array($r['record'])) {
                $out = array_merge($out, $r['record']);
            }
        }

        return $out;
    }

    /**
     * سحب قايمة الأجهزة من المنصة وتسجيل الجديد منها —
     * **إضافة وتحديث ميتاداتا بس، مفيش مسح** (نفس روح Coverage).
     *
     * @return array{added: int, updated: int, error: ?string}
     */
    public static function syncDevices(): array
    {
        $r = self::call('device/list');
        if (! $r['ok']) {
            return ['added' => 0, 'updated' => 0, 'error' => $r['error']];
        }

        $added = $updated = 0;
        foreach ((array) $r['record'] as $d) {
            $imei = trim((string) ($d['imei'] ?? ''));
            if ($imei === '') {
                continue;
            }

            $row = GpsDevice::firstOrNew(['imei' => $imei]);
            $isNew = ! $row->exists;
            $row->name = $d['devicename'] ?? $row->name;
            $row->sim = $d['simcard'] ?? $row->sim;
            // اللوحة من المنصة لو متسجلة هناك — بس ماتدوسش على لوحة اتكتبت عندنا
            $row->plate = $row->plate ?: ($d['platenumber'] ?? null);
            $row->platform_expiry = isset($d['platformduetime']) && $d['platformduetime']
                ? self::ts($d['platformduetime']) : $row->platform_expiry;
            $row->save();
            $isNew ? $added++ : $updated++;
        }

        return ['added' => $added, 'updated' => $updated, 'error' => null];
    }

    /**
     * دورة بولينج واحدة: آخر موقع لكل الأجهزة المفعّلة → الأعمدة.
     * بيشتغل من الأمر المجدول ومن زرار «حدّث دلوقتي» — نفس المنطق.
     *
     * @return array{updated: int, nofix: int, error: ?string}
     */
    public static function pollOnce(): array
    {
        if (! self::enabled()) {
            return ['updated' => 0, 'nofix' => 0, 'error' => 'not configured'];
        }

        $devices = GpsDevice::where('active', true)->get()->keyBy('imei');
        if ($devices->isEmpty()) {
            return ['updated' => 0, 'nofix' => 0, 'error' => null];
        }

        $records = self::track($devices->keys()->all());
        $updated = $nofix = 0;

        foreach ($records as $rec) {
            $dev = $devices->get((string) ($rec['imei'] ?? ''));
            if ($dev === null) {
                continue;
            }

            $lat = (float) ($rec['latitude'] ?? 0);
            $lng = (float) ($rec['longitude'] ?? 0);

            $dev->fill([
                'speed' => max(0, (int) ($rec['speed'] ?? 0)),
                'course' => (int) ($rec['course'] ?? 0),
                'acc' => (int) ($rec['accstatus'] ?? -1),
                'datastatus' => (int) ($rec['datastatus'] ?? 0),
                'battery' => (int) ($rec['battery'] ?? -1),
                'today_km' => ($rec['todaymileage'] ?? -1) >= 0
                    ? round(((int) $rec['todaymileage']) / 1000, 2) : null,
                'heart_time' => self::ts($rec['hearttime'] ?? 0),
                'fetched_at' => now(),
            ]);

            // ⚠️ (0,0) = مفيش فيكس — ماتدوسش على آخر موقع حقيقي بنقطة
            // في وسط المحيط (نفس درس lat-بلا-lng في اللايف ١٢/٨)
            if (abs($lat) > 0.0001 || abs($lng) > 0.0001) {
                $dev->lat = $lat;
                $dev->lng = $lng;
                $dev->gps_time = self::ts($rec['gpstime'] ?? 0);
            } else {
                $nofix++;
            }

            $dev->save();
            $updated++;
        }

        return ['updated' => $updated, 'nofix' => $nofix,
            'error' => $updated === 0 && $records === [] ? Setting::read('itrack_last_error') : null];
    }

    /** unix → Carbon بتوقيت القاهرة — 0/فاضي = null */
    private static function ts($unix): ?Carbon
    {
        $unix = (int) $unix;

        return $unix > 0 ? Carbon::createFromTimestamp($unix, 'Africa/Cairo') : null;
    }

    /**
     * المسافة بالكيلومتر بين نقطتين (هافرساين) — لإنذار التباعد
     * بين تليفون المندوب وعربيته.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }
}
