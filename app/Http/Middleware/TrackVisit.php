<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تسجيل فتح الصفحات (قرار المالك 2026-08-07: «كل حاجة حتى فتح الصفحات»)
 * + **كل أكشن** (٢٢ سبتمبر ٢٠٢٦: «عمل عهدة، عمل إيديت، عمل مسح، عمل أي شيء»).
 *
 * ⚠️ **GET = «فتح صفحة»، وأي حاجة تانية = «أكشن».** المراقب بيسجّل التعديل
 * على 12 موديل بس بتفاصيل الحقول — لكن تسليم عهدة، تحويل مخزني، اعتماد
 * أمر، قفل يوم، رفع شيت… كلها حفظات على موديلز مش متراقبة وكانت بتعدّي
 * من غير أثر. صف «أكشن» واحد لكل طلب بيقفل الفجوة دي: مين، أنهي زرار
 * (اسم الراوت)، اتقبل ولا اترفض (`status`)، وبالبيانات اللي بعتها.
 * صف المراقب بيفضل جنبه لما يكون موجود — ده «إيه اللي اتغير» وده «عمل إيه».
 *
 * ⚠️ **الطلبات الخلفية مستبعدة**: `live/data` بينزل كل 15 ثانية،
 * والملفات الساكنة والصور — دول كانوا هيعملوا آلاف الصفوف في الساعة
 * ويغرقوا السجل الحقيقي.
 *
 * ⚠️ **مانع تكرار**: نفس اليوزر + نفس الصفحة في أقل من 5 دقايق مش
 * بيتسجل تاني — الرفرش والرجوع بالسهم كانوا بيملوا الجدول.
 */
class TrackVisit
{
    /** مسارات مستبعدة — بادئات */
    private const SKIP = [
        'live/data', 'live/stream', 'img/', 'brand/', 'storage/',
        'up', 'favicon', 'notifications/latest',
    ];

    /** أكشنات خلفية من الأبلكيشن مش «حركة يوزر» */
    private const SKIP_ACTIONS = [
        'api/device-token', 'api/locale', 'api/geo/suggest', 'api/notifications/read',
        'notifications/read', 'locale',
    ];

    /** عمرها ما تتخزن */
    private const SECRET = ['password', 'password_confirmation', 'current_password', '_token', '_method', 'token', 'pin'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        rescue(function () use ($request, $response) {
            if (auth()->guest()) {
                return;
            }

            $path = $request->path();

            foreach (self::SKIP as $skip) {
                if (str_starts_with($path, $skip)) {
                    return;
                }
            }

            if (! $request->isMethod('GET')) {
                $this->action($request, $response, $path);

                return;
            }

            // الأبلكيشن بيسحب بياناته بـGET طول الوقت — ده مش «فتح صفحة»
            if (str_starts_with($path, 'api/')) {
                return;
            }

            // الصفحات اللي رجعت خطأ مش زيارة
            if ($response->getStatusCode() >= 400) {
                return;
            }

            $recent = ActivityLog::where('user_id', auth()->id())
                ->where('event', 'viewed')
                ->where('url', $path)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->exists();

            if ($recent) {
                return;
            }

            ActivityLog::record('viewed', [
                'title' => mb_substr((string) ($request->route()?->getName() ?? $path), 0, 180),
                'status' => $response->getStatusCode(),
            ]);
        }, null, false);

        return $response;
    }

    private function action(Request $request, Response $response, string $path): void
    {
        foreach (self::SKIP_ACTIONS as $skip) {
            if (str_starts_with($path, $skip)) {
                return;
            }
        }

        // الخروج ليه حدثه — وصف «أكشن» فوقه تكرار
        $name = (string) $request->route()?->getName();

        if (in_array($name, ['login', 'logout', 'login.attempt'], true) || in_array($path, ['login', 'logout'], true)) {
            return;
        }

        $code = $response->getStatusCode();

        // ⚠️ الفاليديشن في الويب بيرجّع 302 زي النجاح بالظبط — الفرق في
        // أخطاء السيشن. من غير الفحص ده الحفظة المرفوضة كانت هتبان ناجحة.
        $failed = $code >= 400
            || ($request->hasSession() && $request->session()->has('errors'));

        ActivityLog::record('action', [
            'title' => mb_substr($name !== '' ? $name : $path, 0, 180),
            'changes' => $this->payload($request) ?: null,
            'status' => $failed && $code < 400 ? 422 : $code,
        ]);
    }

    /**
     * اللي اليوزر بعته — مختصر: الحقول النصية بس، أول 25 حقل، و120 حرف
     * للقيمة. الجداول الكبيرة (بنود أمر، شيت) بتتلخّص في عددها.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $out = [];

        foreach ($request->except(self::SECRET) as $key => $value) {
            if (count($out) >= 25) {
                break;
            }

            if ($value === null || $value === '' || is_object($value)) {
                continue;
            }

            $out[$key] = is_array($value)
                ? '['.count($value, COUNT_RECURSIVE).']'
                : mb_substr((string) $value, 0, 120);
        }

        foreach (array_keys($request->allFiles()) as $key) {
            $out[$key] = '📎';
        }

        return $out;
    }
}
