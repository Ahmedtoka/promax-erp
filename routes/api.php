<?php

use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\FieldApiController;
use App\Http\Controllers\Api\ManagerApiController;
use App\Http\Controllers\Api\PickApiController;
use App\Http\Controllers\Api\PromoterApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API — الموبايل أبلكيشن (كاش فان / كورير)
|--------------------------------------------------------------------------
| المصادقة: Authorization: Bearer <token>
*/

Route::get('/ping', fn () => response()->json([
    'app' => 'PROMAX ERP API',
    'status' => 'ok',
    'time' => now()->toDateTimeString(),
]));

Route::post('/login', [AuthApiController::class, 'login'])
    // ⚠️ نفس السبب — ومجموعة `api` في لارافيل 12 مش متسرّعة
    // افتراضياً، فالراوت ده كان مفتوح تماماً للتخمين.
    ->middleware('throttle:5,1');

// 'locale' بيتنادى تاني بعد api.token عشان اليوزر يكون اتحدد
// وناخد users.locale — مجموعة الـ api في bootstrap بتشتغل قبل التوكن
Route::middleware(['api.token', 'locale'])->group(function () {
    Route::get('/me', [AuthApiController::class, 'me']);
    Route::post('/logout', [AuthApiController::class, 'logout']);
    Route::post('/locale', [AuthApiController::class, 'setLocale']);

    // كل اللي الأبلكيشن محتاجه في ريكوست واحد
    Route::get('/bootstrap', [FieldApiController::class, 'bootstrap']);

    // ═════════ شغل الشارع — المناديب والسواقين بس ═════════
    // ⚠️ **الراوتس دي كانت مفتوحة لأي توكن.** مع دخول المحاسب وأمين
    // المخزن على الأبلكيشن، ده بقى ثغرة حقيقية: محاسب معاه توكن كان
    // يقدر يبعت `POST /invoices` ويعمل فاتورة بيع باسمه، تخصم من عهدة
    // مش بتاعته وتنزل قيد على حساب عميل. مافيش شاشة بتوريله الزرار —
    // بس الـAPI مابتسألش عن الشاشة.
    Route::middleware('api.role:sales_agent,driver,promoter,admin,manager')->group(function () {
        // الزيارات
        Route::post('/visits/check-in', [FieldApiController::class, 'checkIn']);
        Route::post('/visits/{visit}/check-out', [FieldApiController::class, 'checkOut']);

        // خط سير النهارده — بيجي مع /bootstrap كمان، ده للريفريش
        Route::get('/journey', [FieldApiController::class, 'journey']);

        Route::post('/invoices', [FieldApiController::class, 'storeInvoice']);

        // أوامر التوريد
        Route::post('/pos/{purchaseOrder}/arrive', [FieldApiController::class, 'arrive']);
        Route::post('/pos/{purchaseOrder}/deliver', [FieldApiController::class, 'deliver']);

        // طلبات العملاء الجدد
        Route::post('/client-requests', [FieldApiController::class, 'storeClientRequest']);

        // المندوب بيستلم عهدته من المخزن
        Route::post('/picks/{pick}/receive', [PickApiController::class, 'receive']);
    });

    // ⚠️ **العرض بس** — والكنترولر بيفلتر بالمستخدم أصلاً، فالمحاسب
    // بيشوف اللي يخصّه. أمين المخزن بيشوف أوامر التجهيز عشان دي شغله.
    Route::get('/invoices', [FieldApiController::class, 'invoices']);
    Route::get('/picks', [PickApiController::class, 'index']);
    Route::get('/picks/{pick}', [PickApiController::class, 'show']);

    // الإشعارات
    Route::post('/notifications/read', [FieldApiController::class, 'readNotifications']);

    // ===== البروموتر =====
    Route::prefix('promoter')->middleware('api.role:promoter,admin,manager')->group(function () {
        Route::get('/bootstrap', [PromoterApiController::class, 'bootstrap']);
        Route::post('/visits', [PromoterApiController::class, 'startVisit']);
        Route::post('/visits/{merchVisit}/photo', [PromoterApiController::class, 'uploadPhoto']);
        Route::post('/visits/{merchVisit}/refill', [PromoterApiController::class, 'saveRefill']);
        Route::post('/visits/{merchVisit}/replenishment',
            [PromoterApiController::class, 'requestReplenishment']);
        Route::post('/visits/{merchVisit}/close', [PromoterApiController::class, 'closeVisit']);
    });

    // ===== الأدمن / الـ Channel Manager =====
    Route::prefix('manager')->middleware('api.role:admin,manager')->group(function () {
        Route::get('/bootstrap', [ManagerApiController::class, 'bootstrap']);
        Route::get('/reps/{user}', [ManagerApiController::class, 'rep']);
        Route::post('/requests/{clientRequest}/decide', [ManagerApiController::class, 'decide']);

        // طلبات الريفيل بتاعت البروموتر — موافقة وتنزيل على مندوب
        Route::get('/replenishments', [ManagerApiController::class, 'replenishments']);
        Route::post('/replenishments/{replenishmentRequest}/assign',
            [ManagerApiController::class, 'assignReplenishment']);
        Route::post('/replenishments/{replenishmentRequest}/cancel',
            [ManagerApiController::class, 'cancelReplenishment']);
    });
});
