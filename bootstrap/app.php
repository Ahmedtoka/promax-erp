<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => App\Http\Middleware\EnsureRole::class,
            'screen' => App\Http\Middleware\EnsureScreenAccess::class,
            'api.token' => App\Http\Middleware\AuthenticateApiToken::class,
            'api.role' => App\Http\Middleware\EnsureApiRole::class,
            'locale' => App\Http\Middleware\SetLocale::class,
            // مفيش شغل قبل تسجيل الحضور (HR 2026-08-08)
            'attendance' => App\Http\Middleware\RequireAttendance::class,
            // ⚠️ مفيش استلام بضاعة من غير دخول مخزن (2026-08-08)
            'in.warehouse' => App\Http\Middleware\RequireWarehouseVisit::class,
        ]);

        // لغة الواجهة لازم تتحدد على كل طلب ويب قبل ما أي فيو يترسم،
        // وبعدها تسجيل الزيارة (2026-08-07) — بيشتغل بعد الاستجابة
        // فمابيأخّرش الصفحة، ومحمي بـrescue فمابيقعش الطلب أبداً
        $middleware->web(append: [
            App\Http\Middleware\SetLocale::class,
            App\Http\Middleware\TrackVisit::class,
        ]);

        // نفس الحكاية للـ API — رسايل الأبلكيشن لازم تطلع بلغة اليوزر.
        // هنا بيمسك الديفولت لطلبات من غير توكن (اللوجين مثلاً)،
        // وبعد ما api.token يحدد اليوزر بينادى تاني في routes/api.php عشان
        // ياخد users.locale نفسه.
        $middleware->api(append: [
            App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
