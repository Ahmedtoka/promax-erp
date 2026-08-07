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

// ═══ إصدار الأبلكيشن — **من غير توكن عن قصد** (2026-08-07) ═══
// شاشة «لازم تحدّث» بتظهر قبل اللوجين كمان: المندوب اللي نسخته
// قديمة ممكن يكون خارج الجلسة، ولو الإندبوينت محمي هيتقفل عليه
// الأبلكيشن من غير ما يعرف السبب ولا يقدر يحدّث. مفيش أي داتا
// حساسة هنا — أرقام إصدار ورابط تنزيل عام.
Route::get('/app-version', [\App\Http\Controllers\AppVersionController::class, 'api']);

// 'locale' بيتنادى تاني بعد api.token عشان اليوزر يكون اتحدد
// وناخد users.locale — مجموعة الـ api في bootstrap بتشتغل قبل التوكن
Route::middleware(['api.token', 'locale'])->group(function () {
    Route::get('/me', [AuthApiController::class, 'me']);
    Route::post('/logout', [AuthApiController::class, 'logout']);
    Route::post('/locale', [AuthApiController::class, 'setLocale']);

    // كل اللي الأبلكيشن محتاجه في ريكوست واحد
    Route::get('/bootstrap', [FieldApiController::class, 'bootstrap']);

    // ═══ الحضور والانصراف — HR (2026-08-08) ═══
    // ⚠️ **بره حارس `attendance` عن قصد.** لو الحارس اتحط عليها،
    // الموظف اللي لسه ما حضرش مش هيقدر يسجّل حضور — مصيدة مقفولة
    // على نفسها. ودي الراوتس الوحيدة المسموحة قبل الحضور مع القراءة.
    Route::get('/attendance', [\App\Http\Controllers\Api\AttendanceApiController::class, 'show']);
    Route::post('/attendance/punch', [\App\Http\Controllers\Api\AttendanceApiController::class, 'punch']);

    // ═════════ شغل الشارع — المناديب والسواقين بس ═════════
    // ⚠️ **الراوتس دي كانت مفتوحة لأي توكن.** مع دخول المحاسب وأمين
    // المخزن على الأبلكيشن، ده بقى ثغرة حقيقية: محاسب معاه توكن كان
    // يقدر يبعت `POST /invoices` ويعمل فاتورة بيع باسمه، تخصم من عهدة
    // مش بتاعته وتنزل قيد على حساب عميل. مافيش شاشة بتوريله الزرار —
    // بس الـAPI مابتسألش عن الشاشة.
    // ⚠️ **`attendance` مع `api.role`** — كل أكشن في المجموعة دي
    // بيتوقف لو الموظف مش مسجّل حضور (أو في بريك). القراءة اللي بره
    // المجموعة بتفضل مفتوحة: يتصفّح عهدته وخط سيره عادي.
    Route::middleware(['api.role:sales_agent,driver,promoter,admin,manager', 'attendance'])->group(function () {
        // الزيارات
        Route::post('/visits/check-in', [FieldApiController::class, 'checkIn']);
        Route::post('/visits/{visit}/check-out', [FieldApiController::class, 'checkOut']);

        // خط سير النهارده — بيجي مع /bootstrap كمان، ده للريفريش
        Route::get('/journey', [FieldApiController::class, 'journey']);

        Route::post('/invoices', [FieldApiController::class, 'storeInvoice']);

        // مرتجع من العميل — قيد دائن + بضاعة مفصولة في العهدة
        Route::post('/returns', [FieldApiController::class, 'storeReturn']);

        // أوامر التوريد
        Route::post('/pos/{purchaseOrder}/arrive', [FieldApiController::class, 'arrive']);
        Route::post('/pos/{purchaseOrder}/deliver', [FieldApiController::class, 'deliver']);

        // طلبات العملاء الجدد
        Route::post('/client-requests', [FieldApiController::class, 'storeClientRequest']);

        // المندوب بيستلم عهدته من المخزن
        Route::post('/picks/{pick}/receive', [PickApiController::class, 'receive']);

        // ═══ الحوافز والليدز — الأبديت الكبير (2026-08-06) ═══
        Route::post('/app-open', [\App\Http\Controllers\Api\IncentiveApiController::class, 'appOpen']);
        Route::get('/leads/nearby', [\App\Http\Controllers\Api\IncentiveApiController::class, 'nearbyLeads']);
        Route::post('/leads/{lead}/action', [\App\Http\Controllers\Api\IncentiveApiController::class, 'leadAction']);
        Route::get('/my-incentives', [\App\Http\Controllers\Api\IncentiveApiController::class, 'myIncentives']);

        // ═══ توكن الجهاز لإشعارات فاير بيز (2026-08-07) ═══
        // ⚠️ المسح بيتنده عند الخروج — تليفون موظف خرج عمره ما ياخد
        // إشعارات شغل، وخصوصاً لو التليفون اتسلّم لحد تاني.
        Route::post('/device-token', [\App\Http\Controllers\Api\DeviceTokenApiController::class, 'store']);
        // ⚠️ **مسار POST كمان للمسح** — كلاس `Api` في الأبلكيشن مافيهوش
        // ميثود DELETE عامة، وإضافة واحدة عشان نداء واحد أكبر من اللزوم.
        Route::match(['post', 'delete'], '/device-token/forget',
            [\App\Http\Controllers\Api\DeviceTokenApiController::class, 'destroy']);
        Route::delete('/device-token', [\App\Http\Controllers\Api\DeviceTokenApiController::class, 'destroy']);
    });

    // ⚠️ **العرض بس** — والكنترولر بيفلتر بالمستخدم أصلاً، فالمحاسب
    // بيشوف اللي يخصّه. أمين المخزن بيشوف أوامر التجهيز عشان دي شغله.
    Route::get('/invoices', [FieldApiController::class, 'invoices']);
    // ═══ الهدايا — المندوب بيسجّل اداها لمين ═══
    // ⚠️ من غير التسجيل ده، «صرفنا 200 عينة» رقم مالوش تفصيل.
    Route::get('/gifts', [\App\Http\Controllers\Api\GiftApiController::class, 'index']);
    Route::post('/gifts', [\App\Http\Controllers\Api\GiftApiController::class, 'store'])
        ->middleware('attendance');

    // ═══ البلس — بصمة الحالة، الأبلكيشن بينده عليها كل 10 ثواني ═══
    // ⚠️ لازم تفضل رخيصة. أي حاجة تتضاف هنا بتتضرب في عدد المناديب
    // × 6 مرات في الدقيقة — مافيش `with()` ولا payload هنا أبداً.
    Route::get('/pulse', [FieldApiController::class, 'pulse']);

    Route::get('/picks', [PickApiController::class, 'index']);
    Route::get('/picks/{pick}', [PickApiController::class, 'show']);

    // الإشعارات
    Route::post('/notifications/read', [FieldApiController::class, 'readNotifications']);

    // ===== البروموتر =====
    // ⚠️ الحضور بينطبق على البروموتر برضه — القراءة مفتوحة والأكشن لأ
    Route::prefix('promoter')->middleware('api.role:promoter,admin,manager')->group(function () {
        Route::get('/bootstrap', [PromoterApiController::class, 'bootstrap']);
        Route::middleware('attendance')->group(function () {
        Route::post('/visits', [PromoterApiController::class, 'startVisit']);
        Route::post('/visits/{merchVisit}/photo', [PromoterApiController::class, 'uploadPhoto']);
        Route::post('/visits/{merchVisit}/refill', [PromoterApiController::class, 'saveRefill']);
        Route::post('/visits/{merchVisit}/replenishment',
            [PromoterApiController::class, 'requestReplenishment']);
        Route::post('/visits/{merchVisit}/close', [PromoterApiController::class, 'closeVisit']);
        });
    });

    // ===== الأدمن / الـ Channel Manager =====
    Route::prefix('manager')->middleware('api.role:admin,manager')->group(function () {
        Route::get('/bootstrap', [ManagerApiController::class, 'bootstrap']);
        Route::get('/reps/{user}', [ManagerApiController::class, 'rep']);
        Route::post('/requests/{clientRequest}/decide', [ManagerApiController::class, 'decide'])
            ->middleware('attendance');

        // طلبات الريفيل بتاعت البروموتر — موافقة وتنزيل على مندوب
        Route::get('/replenishments', [ManagerApiController::class, 'replenishments']);
        Route::post('/replenishments/{replenishmentRequest}/assign',
            [ManagerApiController::class, 'assignReplenishment']);
        Route::post('/replenishments/{replenishmentRequest}/cancel',
            [ManagerApiController::class, 'cancelReplenishment']);
    });
});
