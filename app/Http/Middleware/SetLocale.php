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
        // ⚠️ **الويب والأبلكيشن لغتين منفصلتين تماماً** (2026-08-27).
        //
        // كان `users.locale` بيغلب السيشن في الويب، والأبلكيشن هو
        // اللي بيكتب في `users.locale` (POST /api/locale) — فنفس
        // الأكاونت شغال ويب عربي وأبلكيشن إنجليزي كان أي سبمت أو
        // فلتر في الـERP بيقلب اللغة فجأة. دلوقتي:
        //   - طلب ويب (فيه سيشن): session ← web_locale ← locale ← الديفولت
        //     السيشن الأول عشان التبديل يلزق فوراً، وweb_locale عشان
        //     يتبعه على متصفح/جهاز جديد — والأبلكيشن مايلمسوش أبداً.
        //   - طلب API (بلا سيشن): هيدر X-App-Locale ← users.locale
        //     زي ما كان (درس 2026-08-08: لغة الأبلكيشن تغلب لغة اليوزر
        //     عشان النصوص المتولدة عند السيرفر جوه شاشات الأبلكيشن).
        if ($request->hasSession()) {
            $locale = $request->session()->get('locale')
                ?? $request->user()?->web_locale
                ?? $request->user()?->locale
                ?? config('app.locale');
        } else {
            $fromApp = $request->header('X-App-Locale');

            $locale = ($fromApp !== null && array_key_exists($fromApp, User::LOCALES) ? $fromApp : null)
                ?? $request->user()?->locale
                ?? config('app.locale');
        }

        if (! array_key_exists($locale, User::LOCALES)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
