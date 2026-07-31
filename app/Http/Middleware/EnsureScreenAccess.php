<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════
 * الشاشة دي مسموحة للرول ده؟
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **معظم شاشات العرض ماكانش عليها أي حراسة.** الـ`role:` middleware
 * كان مركّب على التعديل بس — `store` و`update` و`destroy` — أما
 * `GET /erp/clients` و`GET /wh/picks` و`GET /erp/team` فكانوا مفتوحين
 * لأي حد مسجّل دخول.
 *
 * ماكانش باين لأن اللي بيدخل الويب كان أدمن أو مدير. دلوقتي مع
 * **المحاسب** و**أمين المخزن**، الباب المفتوح ده بقى شغل يومي:
 * أمين المخزن هيفتح كشف حساب عميل، والمحاسب هيفتح أوامر التجهيز.
 *
 * الميدل وير ده بيتركّب على **مجموعة الويب كلها** ويسأل `Access` —
 * نفس المصدر اللي السايدبار بيرسم منه، فمستحيل يتفرقوا.
 */
class EnsureScreenAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $route = $request->route()?->getName();

        // ⚠️ الراوت من غير اسم بيعدّي. كل شاشات السيستم ليها أسماء،
        // واللي من غير اسم هي راوتس لارافيل الداخلية.
        if ($route === null) {
            return $next($request);
        }

        // ⚠️ **المندوب والسواق والبروموتر مالهمش ويب أصلاً.** بياخدوا
        // صفحة واضحة تقول «شغلك على الأبلكيشن» بدل 403 جافة تخلّيهم
        // يفتكروا إن الحساب باظ ويكلّموا الأدمن.
        if (! Access::isWebRole($user)) {
            abort(403, __('common.field_user_web'));
        }

        if (! Access::allows($user, $route)) {
            abort(403, __('common.forbidden'));
        }

        return $next($request);
    }
}
