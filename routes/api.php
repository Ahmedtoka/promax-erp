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
    // صورة الموظف (٩/٨) — لكل الرولز، بتظهر في التراكينج والحضور
    Route::post('/me/avatar', [AuthApiController::class, 'uploadAvatar']);

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
    // ⚠️ **`admin,manager` اتشالوا من المجموعة دي** (تدقيق ٨/٨/٢٠٢٦):
    // توكن مدير كان يقدر يعمل `POST /invoices` و`/returns` — يعني
    // نافذة كتابة مفتوحة على دفتر أي عميل من غير عهدة ولا زيارة.
    //
    // ═══ ورجع `manager` **بحساب** (قرار المالك ١١ أغسطس ٢٠٢٦) ═══
    // «عاوز التشانل مانجر زي المندوب بالظبط: ينزل يروح محلات، يفتح
    // أكاونتات من غير موافقة، يعمل خط سير لنفسه، ويسلّم أوردرات.»
    // فالمدير في المجموعة دي للزيارات وأوامر التوريد والمخزن وطلبات
    // العملاء — لكن **الفاتورة والمرتجع فاضلين لفريق الشارع بس**
    // (المجموعة المتداخلة تحت): دول مربوطين بتصفية المندوب، والمدير
    // مالوش تصفية. وحارس `attendance` شغال عليه زي أي مندوب.
    Route::middleware(['api.role:sales_agent,driver,promoter,manager', 'attendance'])->group(function () {
        // الزيارات — `ownsClient` هي اللي بتحدد عملاء مين: للمدير
        // عملاؤه هم المتسكّنين له (`clients.manager_id`)
        Route::post('/visits/check-in', [FieldApiController::class, 'checkIn']);
        Route::post('/visits/{visit}/check-out', [FieldApiController::class, 'checkOut']);

        // ═══ الفاتورة والمرتجع — والمدير كمان (قرار المالك ١١/٨ مساءً) ═══
        // «الشركة لسه صغيرة — المدير هيبيع ويتصفّى زي المندوب بالظبط،
        // وإحنا فاصلين كل واحد بمبيعاته ومرتجعاته وفريقه». المدير بقى
        // بياخد عهدة ويبيع منها وبيدخل قايمة التصفية زي أي مندوب.
        Route::middleware('api.role:sales_agent,driver,promoter,manager')->group(function () {
            Route::post('/invoices', [FieldApiController::class, 'storeInvoice']);

            // مرتجع من العميل — قيد دائن + بضاعة مفصولة في العهدة
            Route::post('/returns', [FieldApiController::class, 'storeReturn']);
        });

        // ═══ ٣ أوبشنات الزيارة الجديدة (2026-08-09) ═══
        // كلهم مرساتهم **زيارة مفتوحة** — نفس دوكترين الفاتورة والمرتجع.
        //
        // ⚠️ **للسيلز إيجينت والمدير بس** (قرار ٩/٨ مساءً + ١١/٨):
        // - البروموتر: تصفيته مش بتتحسب، وكاش يحصّله بينزل الدفتر
        //   ومايتحاسبش عليه حد. شغله رفوف الكي أكاونت من `merch_visits`.
        // - السواق: مالوش فلو زيارة في الأبلكيشن أصلاً — صلاحية
        //   مفتوحة لأكشن مالوش شاشة باب مفتوح وبس. لو المالك قرر
        //   السواق يحصّل من الزيارات، ضيف `driver` هنا **وابني له
        //   مدخل العميل في الأبلكيشن** في نفس اليوم.
        // - المدير (١١/٨ مساءً): «زي المندوب بالظبط» — بقى له تصفية
        //   زي أي مندوب (قايمة تصفية المناديب بتشمله)، وتحصيله
        //   النقدي بيدخل «المتوقع» فيها بنفس مرساة الزيارة.
        Route::middleware('api.role:sales_agent,manager')->group(function () {
            Route::post('/visits/{visit}/collect', [FieldApiController::class, 'collect']);
            Route::post('/visits/{visit}/shelf-photo', [FieldApiController::class, 'shelfPhoto']);
        });

        // ═══ طلب البضاعة لكل رولز الشغل الميداني (قرار المالك ١١/٨ مساءً) ═══
        // «غيري الكلام بتاع البروموتر ده خليه مناديب عادي» — طلب البضاعة
        // من عند العميل بقى لكل اللي بينزل الشارع (سيلز/سواق/بروموتر/مدير)،
        // مش للسيلز والمدير بس. التحصيل والرف فاضلين فوق زي ما هم:
        // التحصيل مربوط بالتصفية والبروموتر مالوش تصفية.
        // المرساة جوّه `storeGoodsRequest` زي ما هي: زيارة **مفتوحة بتاعته**.
        Route::post('/goods-requests', [FieldApiController::class, 'storeGoodsRequest']);

        // ═══ لوكيشن العميل من الأبلكيشن (١٤ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **جوّه المجموعة دي عن قصد** — نفس رولز شغل الشارع
        // (`FIELD_WORK_ROLES` بالحرف) ونفس حارس الحضور. الفشل بيبقى
        // **بدري**: أول ما المندوب يسحب النقطة، نداء الجيوكود بيرجّع
        // 423 وبوابة الحضور بتفتح — بدل ما يملا الشاشة كلها ويكتشف
        // عند الحفظ إنه مش مسجّل حضور.
        //
        // ⚠️ **مافيش `in.warehouse` ولا زيارة مفتوحة** — المندوب واقف
        // قدام المحل، والحارس الوحيد المطلوب هو «العميل ده بتاعه»
        // وهو جوّه `guardClient` في الكنترولر.
        Route::post('/clients/{client}/geocode', [FieldApiController::class, 'geocodeClient']);
        Route::post('/clients/{client}/location', [FieldApiController::class, 'saveClientLocation']);

        // المحافظات والمناطق من غير نقطة — شاشة اللوكيشن بتحمّلها
        // أول ما تفتح عشان المندوب يقدر يختار يدوي حتى قبل السحب
        // أو لو السحب فشل (إصلاح ١٥/٨).
        Route::get('/geo/options', [FieldApiController::class, 'geoOptions']);
        // اقتراح عنوان/محافظة/منطقة من نقطة — لشاشة تسجيل عميل جديد
        Route::post('/geo/suggest', [FieldApiController::class, 'geoSuggest']);
        // حركة صنف في عهدة المندوب — شاشة تفاصيل الصنف (من/إلى)
        Route::get('/custody/products/{product}/movements', [FieldApiController::class, 'custodyProductMovements']);

        // أوامر التوريد — والمدير بيسلّم بنفسه (١١/٨)
        Route::post('/pos/{purchaseOrder}/arrive', [FieldApiController::class, 'arrive']);
        Route::post('/pos/{purchaseOrder}/deliver', [FieldApiController::class, 'deliver']);
        // إلغاء التسليم بسبب إجباري — الأمر بيرجع «مستني» (١١/٨ مساءً)
        Route::post('/pos/{purchaseOrder}/cancel-arrival', [FieldApiController::class, 'cancelArrival']);

        // ═══ دخول وخروج المخزن (2026-08-08) ═══
        // ⚠️ **بره حارس `in.warehouse` طبعاً** — دي الحاجة اللي
        // بتفتحه أصلاً. حطها جوّاه كان هيعمل دايرة مقفولة: مايقدرش
        // يدخل المخزن لأنه مش داخل المخزن.
        Route::post('/warehouse-visits', [\App\Http\Controllers\Api\WarehouseVisitApiController::class, 'checkIn']);
        Route::post('/warehouse-visits/out', [\App\Http\Controllers\Api\WarehouseVisitApiController::class, 'checkOut']);

        // طلبات العملاء الجدد
        Route::post('/client-requests', [FieldApiController::class, 'storeClientRequest']);

        // ═══ اللي لازم يكون جوّه المخزن عشان يعمله (2026-08-08) ═══
        //
        // ⚠️ **الاستلام بس مش التسليم.** المندوب بيستلم العهدة والـPO
        // **من المخزن**، لكن بيسلّم الـPO **عند العميل** — فلو حطينا
        // `deliver` هنا كان الحارس هيمنعه يسلّم وهو واقف عند العميل.
        Route::middleware('in.warehouse')->group(function () {
            // المندوب بيستلم عهدته من المخزن
            Route::post('/picks/{pick}/receive', [PickApiController::class, 'receive']);
        });

        // ═══ الليدز: القرار أكشن (القراءة بره الحارس تحت) ═══
        // ⚠️ فريق الشارع بس — الليدز والحوافز نظام المناديب، والمدير
        // دخوله المجموعة (١١/٨) ماكانش المقصود بيه ده.
        Route::middleware('api.role:sales_agent,driver,promoter')->group(function () {
            Route::post('/leads/{lead}/action', [\App\Http\Controllers\Api\IncentiveApiController::class, 'leadAction']);
        });
    });

    // ═══════════════════════════════════════════════════════════
    // بره حارس الحضور بقصد — دي **مش أكشنز** (تصحيح 2026-08-08)
    // ═══════════════════════════════════════════════════════════
    //
    // ⚠️ **الحاجات دي بينده عليها الأبلكيشن لوحده عند الفتح وكل شوية**،
    // مش لما الموظف يدوس حاجة. وهي كانت جوّه الحارس — فأول ما
    // الأبلكيشن يفتح، `app-open` و`device-token` و`leads/nearby`
    // يرجعوا 423 والبوابة تفتح بوب أب «سجّل حضورك» على شاشة
    // اللودينج، قبل ما الموظف يلمس أي حاجة. البوب أب المفروض
    // يطلع **على الأكشن بس** (قرار المالك 2026-08-08).
    //
    // ⚠️ ومفيش أي منهم بيغيّر داتا شغل: بينج عدّاد، تسجيل توكن
    // إشعارات، وقراءتين. فمنعهم قبل الحضور ماكانش بيحمي حاجة.
    Route::middleware('api.role:sales_agent,driver,promoter,admin,manager')->group(function () {
        // خط سير النهارده — قراءة، بيجي مع /bootstrap كمان
        Route::get('/journey', [FieldApiController::class, 'journey']);

        // ⚠️ **قراءة، فبره حارس الحضور** — شاشة البيع بتنده عليها أول
        // ما المندوب يختار العميل عشان تعرض سعره هو مش سعر قائمة.
        Route::get('/clients/{client}/prices', [FieldApiController::class, 'clientPrices']);

        // المتاح للرد + السياسات المسموحة للعميل — شاشة المرتجع
        // بتنده عليها قبل ما المندوب يكتب أي كمية.
        Route::get('/clients/{client}/returnable', [FieldApiController::class, 'returnable']);

        // الكتالوج الكامل بأسعار العميل — شاشة «طلب بضاعة» بتنده
        // عليها عند الفتح. قراءة، فبره حارس الحضور زي `prices`.
        Route::get('/clients/{client}/catalog', [FieldApiController::class, 'catalog']);

        // ═══ تاريخ العميل (١٦ أغسطس ٢٠٢٦) ═══
        //
        // قراءة بحتة، فبره حارس الحضور: المندوب بيراجع تاريخ العميل
        // وهو في الطريق قبل ما يعمل تشيك إن.
        //
        // ⚠️ **الحارس هو `guardClient` جوّه الميثود** — نفس مرساة
        // العلاقة اللي بتحكم كل إندبوينت فيه `{client}`. من غيرها أي
        // توكن ميداني كان يقرا تاريخ **أي** عميل في الشركة.
        Route::get('/clients/{client}/history', [FieldApiController::class, 'clientHistory']);

        // كارت العميل — أرقام شاشة التشيك إن (موك أب ٢١/٨). قراءة،
        // والمندوب بيراجعها **قبل** التشيك إن فبره حارس الحضور،
        // و`guardClient` جوّه الميثود زي التاريخ بالظبط.
        Route::get('/clients/{client}/card', [FieldApiController::class, 'clientCard']);

        // ⚠️ **النوع محصور في الراوت** — من غير `whereIn` أي نص
        // بيوصل للميثود ويعدّي على `match`، والراوت بيبقى مفتوح
        // لقيم مالهاش معنى.
        Route::get('/clients/{client}/history/{type}', [FieldApiController::class, 'clientHistoryList'])
            ->whereIn('type', ['sales', 'collections', 'returns', 'gifts', 'shelf']);

        // طلبات البضاعة بتاعتي — شاشة «طلباتي» (2026-08-09). قراءة.
        Route::get('/my-goods-requests', [FieldApiController::class, 'myGoodsRequests']);

        // بينج فتح الأبلكيشن — عدّاد في لوحة الأداء
        Route::post('/app-open', [\App\Http\Controllers\Api\IncentiveApiController::class, 'appOpen']);

        Route::get('/leads/nearby', [\App\Http\Controllers\Api\IncentiveApiController::class, 'nearbyLeads']);

        // ═══ تاب العملاء المحتملين (بايبلاين ٢٦/٨) — ليدات المندوب
        // بالمناطق + تأكيد البيانات من الميدان + تحديث الحالة ═══
        Route::get('/leads/mine', [\App\Http\Controllers\Api\LeadApiController::class, 'mine']);
        Route::post('/leads/{lead}/confirm', [\App\Http\Controllers\Api\LeadApiController::class, 'confirm']);
        Route::post('/leads/{lead}/status', [\App\Http\Controllers\Api\LeadApiController::class, 'setStatus']);
        // فتح أكاونت فوري بعد التأكيد — بلا موافقة (فلو الليد المطور ٢٦/٨)
        Route::post('/leads/{lead}/open-account', [\App\Http\Controllers\Api\LeadApiController::class, 'openAccount']);
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

    // ===== أمين المخزن — أوامر التجهيز على الموبايل (2026-08-09) =====
    //
    // ⚠️ **مش `PickApiController`** — ده بيرجّع أوامر المندوب اللي
    // بيستلمها. الأمين بيشوف أوامر **مخازنه** وبيجهّزها. والأكشنات
    // (ابدأ/جاهز) بنفس ميثودز الموديل اللي الويب بينده عليها.
    // ⚠️ من غير حارس `attendance` — الأمين بيسجّل حضوره من نفس
    // الأبلكيشن، وأكشناته متسجّلة بالاسم والوقت على الأمر نفسه.
    Route::prefix('keeper')->middleware('api.role:warehouse_keeper,admin')->group(function () {
        Route::get('/picks', [\App\Http\Controllers\Api\KeeperApiController::class, 'index']);
        Route::get('/picks/{pick}', [\App\Http\Controllers\Api\KeeperApiController::class, 'show']);
        Route::post('/picks/{pick}/start', [\App\Http\Controllers\Api\KeeperApiController::class, 'start']);
        Route::post('/picks/{pick}/ready', [\App\Http\Controllers\Api\KeeperApiController::class, 'ready']);
    });

    // ===== المحاسب — تحصيلات الميدان قراءة بس (2026-08-09) =====
    // ⚠️ عشان إشعار «تحصيل غير نقدي» يفتح على حاجة — التسجيل
    // والاعتماد فاضلين للويب عن قصد.
    Route::prefix('accountant')->middleware('api.role:accountant,admin')->group(function () {
        Route::get('/collections', [\App\Http\Controllers\Api\AccountantApiController::class, 'collections']);
    });

    // ===== الأدمن / الـ Channel Manager =====
    Route::prefix('manager')->middleware('api.role:admin,manager')->group(function () {
        Route::get('/bootstrap', [ManagerApiController::class, 'bootstrap']);
        Route::get('/reps/{user}', [ManagerApiController::class, 'rep']);
        Route::post('/requests/{clientRequest}/decide', [ManagerApiController::class, 'decide'])
            ->middleware('attendance');

        // طلبات الريفيل بتاعت البروموتر — موافقة وتنزيل على مندوب
        Route::get('/replenishments', [ManagerApiController::class, 'replenishments']);
        // ⚠️ **`attendance` مطلوب هنا زي `requests/decide`** — الأكشن
        // ده بيعمل PO ويحجز بضاعة، يعني قرار تشغيلي بيتحاسب عليه
        // الفريق. كان الوحيد في المجموعة اللي بلا حارس حضور.
        Route::post('/replenishments/{replenishmentRequest}/assign',
            [ManagerApiController::class, 'assignReplenishment'])
            ->middleware('attendance');
        Route::post('/replenishments/{replenishmentRequest}/cancel',
            [ManagerApiController::class, 'cancelReplenishment']);
    });
});
