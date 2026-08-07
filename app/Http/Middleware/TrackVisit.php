<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تسجيل فتح الصفحات (قرار المالك 2026-08-07: «كل حاجة حتى فتح الصفحات»).
 *
 * ⚠️ **GET بس ولليوزر الداخل بس** — الـPOST بيتسجل أصلاً من المراقب
 * بتفاصيل أدق (إيه اللي اتغير)، وتسجيله هنا كمان بيعمل صفين لكل حفظة.
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
        'live/data', 'live/stream', 'api/', 'img/', 'brand/', 'storage/',
        'up', 'favicon',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        rescue(function () use ($request, $response) {
            if (! $request->isMethod('GET') || auth()->guest()) {
                return;
            }

            // الصفحات اللي رجعت خطأ مش زيارة
            if ($response->getStatusCode() >= 400) {
                return;
            }

            $path = $request->path();

            foreach (self::SKIP as $skip) {
                if (str_starts_with($path, $skip)) {
                    return;
                }
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
            ]);
        }, null, false);

        return $response;
    }
}
