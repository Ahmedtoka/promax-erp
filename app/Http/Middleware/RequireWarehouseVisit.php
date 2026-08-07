<?php

namespace App\Http\Middleware;

use App\Services\WarehouseVisits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ═══════════════════════════════════════════════════════════════
 * مفيش استلام من غير دخول المخزن (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الحارس على السيرفر مش على الشاشة.** الاستلام كان بيحصل من أي
 * مكان — المندوب يدوس «استلمت» وهو في الشارع، والبضاعة تخرج من
 * المخزن على الورق من غير ما حد يشوفها. إخفاء الزرار في الأبلكيشن
 * مابيمنعش توكن معدّل ولا نسخة قديمة من الأبلكيشن.
 *
 * ⚠️ **بيرجّع 423 بنفس نمط `RequireAttendance`.** الأبلكيشن عنده
 * بالفعل بوابة بتسمع `Api.blocked` وبتوري بوب أب — الفرق في `code`
 * بس، وهو اللي بيخلّي البوب أب يودّي لشاشة المخزن بدل شاشة الحضور.
 *
 * ⚠️ **اللوكيشن مابيتفحصش هنا** (قرار صريح 2026-08-08). بيتخزن لحظة
 * الدخول عشان التدقيق، والقرار في فرق المسافة يتاخد على داتا حقيقية
 * بعد ما نشوف GPS جوّه المخازن بيضرب بكام.
 */
class RequireWarehouseVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! WarehouseVisits::isInside($user)) {
            return response()->json([
                'message' => __('warehouse.required_hint'),
                'code' => 'warehouse_required',
            ], 423);
        }

        return $next($request);
    }
}
