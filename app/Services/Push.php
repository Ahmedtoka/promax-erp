<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════
 * إشعارات فاير بيز (FCM HTTP v1) — 2026-08-07
 * ═══════════════════════════════════════════════════════════════
 *
 * الإرسال بيحصل من `AppNotification::send` أوتوماتيك — مفيش نداء
 * يدوي في أي كنترولر. أي إشعار بيتسجّل في السيستم بيوصل التليفون.
 *
 * ⚠️ **بيتخطى بأمان لو الإعداد ناقص.** لحد ما مشروع فاير بيز
 * يتعمل ومفتاح الخدمة يتحط في `.env`، الدالة بترجع 0 من غير أي
 * خطأ — الإشعارات الداخلية (جرس الأبلكيشن) بتفضل شغالة عادي.
 *
 * ⚠️ **الشبكة ممنوع توقّف العملية.** الإرسال ملفوف `rescue` —
 * فاتورة أو أمر توريد عمره ما يفشل لأن جوجل مش بيرد.
 *
 * الإعداد في `.env`:
 *   FCM_PROJECT_ID=promax-xxxxx
 *   FCM_CREDENTIALS=/home/master/applications/xxx/private/promax-fcm.json
 */
final class Push
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** كاش التوكن في الذاكرة — صالح ساعة، وإحنا في نفس الطلب */
    private static ?array $access = null;

    /** الإعداد كامل؟ — الشاشات بتستخدمها تقول «الإشعارات مش مفعّلة» */
    public static function configured(): bool
    {
        $file = (string) config('services.fcm.credentials');

        return config('services.fcm.project') && $file !== '' && is_file($file);
    }

    /**
     * إرسال لكل أجهزة يوزر.
     *
     * @param  array<string, string>  $data  حمولة إضافية (نوع الإشعار، اللينك)
     * @return int عدد الأجهزة اللي اتبعتلها
     */
    public static function toUser(?User $user, string $title, ?string $body, array $data = []): int
    {
        if ($user === null || ! self::configured()) {
            return 0;
        }

        $tokens = DeviceToken::where('user_id', $user->id)->pluck('token')->all();

        return self::toTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     */
    public static function toTokens(array $tokens, string $title, ?string $body, array $data = []): int
    {
        if ($tokens === [] || ! self::configured()) {
            return 0;
        }

        $access = self::accessToken();

        if ($access === null) {
            return 0;
        }

        $sent = 0;
        $url = 'https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project').'/messages:send';

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => array_filter([
                        'title' => $title,
                        'body' => $body,
                    ]),
                    // ⚠️ FCM بيقبل نصوص بس في data — أي رقم لازم يتحول
                    'data' => array_map(fn ($v) => (string) $v, $data),
                    'android' => [
                        'priority' => 'high',
                        'notification' => ['channel_id' => 'promax_default', 'sound' => 'default'],
                    ],
                ],
            ];

            $ok = rescue(function () use ($url, $payload, $access, $token) {
                $ctx = stream_context_create(['http' => [
                    'method' => 'POST',
                    'timeout' => 8,
                    'ignore_errors' => true,
                    'header' => "Authorization: Bearer $access\r\nContent-Type: application/json\r\n",
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]]);

                $res = file_get_contents($url, false, $ctx);

                // ⚠️ `$http_response_header` بيتعرّف في نطاق الدالة اللي
                // نادت file_get_contents — لازم يتقرا هنا جوه الكلوجر
                // نفسه، وقراءته من بره كانت بترجع صفر دايماً وتخلي كل
                // إرسال يبان ناجح حتى لو فشل.
                $headers = $http_response_header ?? [];
                $code = (int) (explode(' ', $headers[0] ?? 'HTTP/1.1 000')[1] ?? 0);

                // ⚠️ توكن ميت (الأبلكيشن اتشال) بيرجع 404/403 — بيتمسح
                // فوراً، وإلا الجدول بيمتلي توكنز مش بتوصل حاجة
                if (in_array($code, [403, 404], true)) {
                    DeviceToken::where('token', $token)->delete();

                    return false;
                }

                if ($code >= 400) {
                    Log::warning('FCM failed', ['code' => $code, 'res' => mb_substr((string) $res, 0, 300)]);

                    return false;
                }

                return true;
            }, false, false);

            if ($ok) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * توكن وصول من ملف حساب الخدمة (JWT موقّع RS256).
     *
     * ⚠️ **من غير باكدج جوجل.** إضافة `google/apiclient` كانت هتجر
     * عشرات الاعتماديات على سيرفر مشترك — التوقيع بـ`openssl_sign`
     * اللي موجود أصلاً في PHP كفاية للغرض ده.
     */
    private static function accessToken(): ?string
    {
        if (self::$access !== null && self::$access['exp'] > time() + 60) {
            return self::$access['token'];
        }

        return rescue(function () {
            $json = json_decode((string) file_get_contents((string) config('services.fcm.credentials')), true);

            if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
                return null;
            }

            $now = time();
            $claim = [
                'iss' => $json['client_email'],
                'scope' => self::SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $b64 = fn ($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
            $unsigned = $b64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$b64($claim);

            openssl_sign($unsigned, $signature, $json['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]),
            ]]);

            $res = json_decode((string) file_get_contents('https://oauth2.googleapis.com/token', false, $ctx), true);

            if (! is_array($res) || empty($res['access_token'])) {
                Log::warning('FCM auth failed', ['res' => $res]);

                return null;
            }

            self::$access = ['token' => $res['access_token'], 'exp' => $now + 3500];

            return $res['access_token'];
        }, null, false);
    }
}
