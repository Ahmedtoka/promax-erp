<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * الاستخدام: ->middleware('role:admin,manager')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->route('login');
        }

        // ⚠️ **استثناء اليوزر بيغلب بوابة الرول** (قرار المالك 2026-08-05).
        // الأدمن لو دّى المحاسب زرار «تسليم عهدة»، الـPOST لازم يعدّي
        // رغم إن `role:admin,manager` — والعكس: المنع الصريح بيقفل
        // حتى لو الرول أصلاً مسموح.
        $routeName = $request->route()?->getName();

        // ⚠️ المقفول (٩/١١): مفيش صف استثناء يعدّي `role:` على الصلاحيات
        // والرولز ووقت الشغل والمساعد — البوابة نفسها بس
        $overridable = $routeName !== null && ! \App\Support\Access::isLocked($routeName);

        // ⚠️ راوت عليه أكشن (قرار ٦، ٩/١١): القرار كله من `Access::allows` —
        // صف الراوت/الأكشن (أي طبقة) وإلا افتراضي الأكشن. منح الصفحة مابيعدّيش
        // `role:` هنا، ومنح الأكشن بيعدّيه. افتراضي الأكشن = `role:` (RoleAccessTest).
        if ($overridable && \App\Support\Access::actionKeyFor($routeName) !== null) {
            if (! \App\Support\Access::allows($user, $routeName)) {
                abort(403, __('common.forbidden'));
            }

            return $next($request);
        }

        if ($overridable
            && ($override = \App\Support\Access::userOverride($user, $routeName)) !== null) {
            if ($override === false) {
                abort(403, __('common.forbidden'));
            }

            return $next($request);
        }

        // الرول المخصص (٩/١١) — بين اليوزر والرول الأساسي، بنفس المنطق
        if ($overridable && ! $user->isAdmin()
            && ($customOverride = \App\Support\Access::customOverride($user, $routeName)) !== null) {
            if ($customOverride === false) {
                abort(403, __('common.forbidden'));
            }

            return $next($request);
        }

        // ⚠️ **واستثناء الرول بنفس المنطق (٢٣/٨).** الأدمن لو دّى رول
        // المديرين شاشة من تاب الرولز، بوابة `role:` على الراوت لازم
        // تعدّيهم — والمنع الصريح للرول بيقفل حتى لو الراوت سامح.
        if ($overridable && ! $user->isAdmin()
            && ($roleOverride = \App\Support\Access::roleOverride($user->role, $routeName)) !== null) {
            if ($roleOverride === false) {
                abort(403, __('common.forbidden'));
            }

            return $next($request);
        }

        if ($roles && ! in_array($user->role, $roles, true) && ! $user->isAdmin()) {
            abort(403, __('common.forbidden'));
        }

        return $next($request);
    }
}
