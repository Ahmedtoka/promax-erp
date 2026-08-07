<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * بيحدد لغة الطلب. الترتيب:
 *   1. لغة اليوزر المسجّل (users.locale)
 *   2. اللي متخزن في السيشن (للزوار قبل اللوجين)
 *   3. الافتراضي من config/app.php
 *
 * ملاحظة: طلبات الـ API بتبقى stateless (مفيش سيشن) —
 * عشان كده بنسأل hasSession() الأول قبل ما نقرأ منها.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // ⚠️ **لغة الأبلكيشن تغلب لغة اليوزر** (2026-08-08).
        //
        // فيه نصوص بتتولد عند السيرفر وبتتعرض جوه شاشة الأبلكيشن —
        // ليبل البانش في حركة اليوم، أسماء الحالات، رسايل الأخطاء.
        // المندوب اللي بدّل **الأبلكيشن** لعربي كان لسه بياخدها
        // إنجليزي لأن `users.locale` بتاعه إنجليزي، فالشاشة بتطلع
        // نصها عربي ونصها إنجليزي.
        //
        // ⚠️ والهيدر بيتقرا **بس لو فيه هيدر** — الويب مابيبعتوش،
        // فلغة الجلسة في الـERP مابتتأثرش خالص.
        $fromApp = $request->header('X-App-Locale');

        $locale = ($fromApp !== null && array_key_exists($fromApp, User::LOCALES) ? $fromApp : null)
            ?? $request->user()?->locale
            ?? ($request->hasSession() ? $request->session()->get('locale') : null)
            ?? config('app.locale');

        if (! array_key_exists($locale, User::LOCALES)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
