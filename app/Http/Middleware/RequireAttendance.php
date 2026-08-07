<?php

namespace App\Http\Middleware;

use App\Services\Attendance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════
 * حارس الحضور — مفيش شغل قبل ما يسجّل حضور (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الحارس على السيرفر مش على الأبلكيشن.** الأبلكيشن بيخفي
 * الأزرار ويعرض بوب أب — بس ده تحسين شكل بس. النسخة القديمة من
 * الأبلكيشن، أو حد بيبعت ريكوست بإيده، بيعدّوا من هنا. القاعدة
 * بتتنفذ في نقطة واحدة وهي دي.
 *
 * ⚠️ **القراءة مفتوحة عن قصد** (قرار المالك 2026-08-08): الموظف
 * يقدر يتصفّح عهدته وخط سيره وعملاءه من غير حضور — الممنوع هو
 * **الأكشن** بس. لو قفلنا كل حاجة، اللي النت بيقطع عنده في الشارع
 * كان هيتحبس بره الأبلكيشن خالص.
 *
 * ⚠️ **البريك بيمنع زي الانصراف.** `Attendance::canWork()` بترجع
 * `true` للحالة `working` بس.
 *
 * الرد `423 Locked` بكود `attendance_required` — الأبلكيشن بيمسكه
 * ويفتح شاشة الحضور بدل ما يعرض رسالة خطأ عامة.
 */
class RequireAttendance
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // مفيش يوزر = مشكلة تانية، سيب الحارس اللي قبله يتعامل معاها
        if ($user === null) {
            return $next($request);
        }

        if (Attendance::canWork($user)) {
            return $next($request);
        }

        $state = Attendance::state($user);

        return response()->json([
            'code' => 'attendance_required',
            'state' => $state,
            // الرسالة بتفرّق بين «مابدأش» و«في بريك» — الحل مختلف
            'message' => $state === 'break'
                ? __('hr.blocked_break')
                : __('hr.blocked_off'),
        ], 423);
    }
}
