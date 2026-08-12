<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ErpController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OpsController;
use App\Http\Controllers\PickOrderController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

// ================= الدخول =================
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])
    // ⚠️ **من غير `throttle` اللوجين مفتوح للتخمين بلا حدود.**
    // إيميلات الفريق شكلها متوقّع (`الاسم@promax.com`)، فالباقي
    // تجربة باسوردات بسرعة الشبكة. 5 محاولات في الدقيقة لكل
    // (إيميل + IP) بتخلّي التخمين مستحيل عملياً من غير ما تزعج
    // اللي بيغلط في الكتابة مرة أو اتنين.
    ->middleware('throttle:5,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ⚠️ **مش `erp.overview` مباشرة.** أمين المخزن اللي بيكتب الدومين
// لوحده أو بيفتح بوكمارك كان بيترمي على شاشة ممنوعة عليه — 403 على
// الصفحة الأولى خالص.
Route::get('/', fn () => redirect()->route(
    \App\Support\Access::home(auth()->user())
));

// تبديل اللغة — بره الـ auth عشان صفحة اللوجين نفسها تقدر تبدّل
Route::post('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// ⚠️ **`screen` بيتركّب على المجموعة كلها.** قبل كده كانت شاشات
// العرض مفتوحة لأي حد مسجّل دخول — `role:` كان على التعديل بس. مع
// دخول المحاسب وأمين المخزن ده بقى فرق حقيقي: المحاسب مالوش دعوة
// بأوامر التجهيز، وأمين المخزن مالوش دعوة بمديونيات العملاء.
// الميدل وير بيسأل `App\Support\Access` — نفس المصدر اللي السايدبار
// بيرسم منه، فمستحيل اللينك يبان لواحد والصفحة ترفضه.
Route::middleware(['auth', 'screen'])->group(function () {

    // ═══ جرس الإشعارات في الداش بورد (2026-08-09) ═══
    //
    // ⚠️ **نفس صفوف `app_notifications` بتاعة الموبايل** — مصدر واحد،
    // فالإشعار اللي بيوصل للتليفون بيبان في الداش بورد والعكس.
    // «go» بيعلّم مقروء ويحوّل لصفحة الويب المقابلة للوجهة القصيرة.
    Route::post('/notifications/read-all', function () {
        auth()->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    })->name('notifications.read');

    Route::get('/notifications/{note}/go', function (\App\Models\AppNotification $note) {
        abort_unless((int) $note->user_id === (int) auth()->id(), 403);
        $note->update(['read_at' => now()]);

        return redirect($note->webUrl() ?? url()->previous());
    })->name('notifications.go');

    // ================= الـ ERP =================
    Route::prefix('erp')->name('erp.')->group(function () {
        Route::get('/', [ErpController::class, 'overview'])->name('overview');

        // ═══ التفضية والديمو (2026-08-04) — أدمن بس، بتأكيد مكتوب ═══
        Route::post('/wipe-transactions', [ErpController::class, 'wipeTransactions'])
            ->middleware('role:admin')->name('wipe');
        Route::post('/demo-data', [ErpController::class, 'demoData'])
            ->middleware('role:admin')->name('demo');

        Route::get('/clients', [ErpController::class, 'clients'])->name('clients');
        // ⚠️ **مدير الفرع مسموح له هنا.** الاتفاق إنه «معاه صلاحيات كل
        // حاجة تخص فرعه»، وعملاء فرعه منها. الحماية مش في الميدلوير —
        // هي في `canSeeBranch()` جوه الكنترولر، اللي بيمنعه يفتح أو
        // يعدّل عميل فرع تاني.
        Route::post('/clients', [ErpController::class, 'storeClient'])
            ->middleware('role:admin,manager,branch_manager')->name('clients.store');

        // ═══ تفعيل العملاء المستوردين ═══
        // ⚠️ **قبل `/clients/{client}`.** لو `{client}` اتعرّف الأول،
        // لارافيل هيحاول يلاقي عميل كوده «activate» ويرمي 404.
        Route::get('/clients/activate', [\App\Http\Controllers\ClientActivationController::class, 'index'])
            ->middleware('role:admin,manager,branch_manager')->name('clients.activate');
        Route::post('/clients/activate', [\App\Http\Controllers\ClientActivationController::class, 'activate'])
            ->middleware('role:admin,manager')->name('clients.activate.do');
        Route::post('/clients/{client}/deactivate', [\App\Http\Controllers\ClientActivationController::class, 'deactivate'])
            ->middleware('role:admin,manager')->name('clients.deactivate');

        // ⚠️ **قبل `/clients/{client}` بالظبط.** لارافيل بيطابق بالترتيب،
        // ولو `{client}` اتعرّف الأول كان هيحاول يلاقي عميل كوده «new»
        // ويرمي 404 على صفحة إضافة عميل.
        Route::get('/clients/new', [ErpController::class, 'newClient'])
            ->middleware('role:admin,manager,branch_manager')->name('clients.new');
        Route::get('/clients/{client}/clone', [ErpController::class, 'cloneClient'])
            ->middleware('role:admin,manager,branch_manager')->name('clients.clone');
        // ⚠️ **التعديل بقى نفس ويزارد الإنشاء** بدل المودال القديم —
        // العقد وبنوده والتسعير مالهمش أي واجهة تعديل في المودال.
        Route::get('/clients/{client}/edit', [ErpController::class, 'editClient'])
            ->middleware('role:admin,manager,branch_manager')->name('clients.edit');

        Route::get('/clients/{client}', [ErpController::class, 'client'])->name('clients.show');
        Route::put('/clients/{client}', [ErpController::class, 'updateClient'])
            ->middleware('role:admin,manager,branch_manager')->name('clients.update');
        Route::post('/clients/{client}/collect', [OpsController::class, 'collect'])
            // ⚠️ **المحاسب هنا.** التحصيل شغله الأساسي — لو مش مسموح
            // له، هيقعد يقول للمدير «سجّلي التحصيل ده» كل يوم.
            ->middleware('role:admin,manager,accountant,branch_manager')->name('clients.collect');

        // ⚠️ الاتنين دول بيرجّعوا JSON وبيتنادوا من جوه فورم العميل
        // بـ fetch. الغرض إن المستخدم مايسيبش الصفحة ويفقد اللي كتبه.
        Route::post('/zones/quick', [ErpController::class, 'quickZone'])
            ->middleware('role:admin,manager,branch_manager')->name('zones.quick');
        // المحافظات — داتابيز قابلة للتعديل والإضافة (2026-08-05)
        Route::post('/governorates', [ErpController::class, 'storeGovernorate'])
            ->middleware('role:admin,manager')->name('govs.store');
        Route::put('/governorates/{governorate}', [ErpController::class, 'updateGovernorate'])
            ->middleware('role:admin,manager')->name('govs.update');
        Route::post('/groups/quick', [ErpController::class, 'quickGroup'])
            ->middleware('role:admin,manager,branch_manager')->name('groups.quick');
        Route::post('/geo/resolve', [ErpController::class, 'resolveLocation'])
            ->middleware('role:admin,manager,branch_manager')->name('geo.resolve');

        // رصيد أول المدة — بداية الشغل على السيستم
        Route::post('/clients/{client}/opening', [ErpController::class, 'openingBalance'])
            ->middleware('role:admin,manager,branch_manager,accountant')->name('clients.opening');

        // ═══ قوايم الأسعار ═══
        // ⚠️ **العرض للمديرين، والتعديل للأدمن ومدير القنوات.**
        // السعر اللي بيتكتب هنا هو اللي بيتحاسب بيه العميل في كل
        // فاتورة — مش شاشة إعدادات، دي شاشة فلوس.
        Route::get('/prices', [\App\Http\Controllers\PriceListController::class, 'index'])
            ->middleware('role:admin,manager,branch_manager')->name('prices');
        Route::get('/prices/{priceList}', [\App\Http\Controllers\PriceListController::class, 'show'])
            ->middleware('role:admin,manager,branch_manager')->name('prices.show');

        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/prices', [\App\Http\Controllers\PriceListController::class, 'store'])
                ->name('prices.store');
            Route::put('/prices/{priceList}', [\App\Http\Controllers\PriceListController::class, 'update'])
                ->name('prices.update');
            Route::post('/prices/{priceList}/save', [\App\Http\Controllers\PriceListController::class, 'savePrices'])
                ->name('prices.save');
            Route::post('/prices/{priceList}/bulk', [\App\Http\Controllers\PriceListController::class, 'bulk'])
                ->name('prices.bulk');
            Route::post('/prices/{priceList}/activate', [\App\Http\Controllers\PriceListController::class, 'activate'])
                ->name('prices.activate');
            Route::post('/prices/{priceList}/deactivate', [\App\Http\Controllers\PriceListController::class, 'deactivate'])
                ->name('prices.deactivate');
            Route::post('/prices/{priceList}/default', [\App\Http\Controllers\PriceListController::class, 'makeDefault'])
                ->name('prices.default');
        });

        // ═══ الموردين والمشتريات ═══
        // ⚠️ **`erp.purchasing` مش `purchase_orders`** — الاسم التاني
        // محجوز لطلبيات العملاء اللي السواقين بيوصّلوها.
        Route::middleware('role:admin,manager,accountant')->group(function () {
            Route::get('/suppliers', [\App\Http\Controllers\SupplierController::class, 'index'])
                ->name('suppliers');
            Route::get('/suppliers/{supplier}', [\App\Http\Controllers\SupplierController::class, 'show'])
                ->name('suppliers.show');
            // ⚠️ المحاسب بيدفع — ده شغله، زي تحصيل العملاء بالظبط
            Route::post('/suppliers/{supplier}/pay', [\App\Http\Controllers\SupplierController::class, 'pay'])
                ->name('suppliers.pay');
        });

        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/suppliers', [\App\Http\Controllers\SupplierController::class, 'store'])
                ->name('suppliers.store');
            Route::put('/suppliers/{supplier}', [\App\Http\Controllers\SupplierController::class, 'update'])
                ->name('suppliers.update');
            Route::post('/suppliers/{supplier}/opening', [\App\Http\Controllers\SupplierController::class, 'opening'])
                ->name('suppliers.opening');
        });

        // أوامر الشراء — العرض للإدارة وأمين المخزن (هو اللي بيستلم)
        Route::middleware('role:admin,manager,warehouse_keeper')->group(function () {
            Route::get('/purchasing', [\App\Http\Controllers\SupplierController::class, 'orders'])
                ->name('purchasing');
            // ⚠️ **`/purchasing/new` قبل `/purchasing/{supplierOrder}`** —
            // لارافيل بيطابق بالترتيب و«new» كانت هتتقري كـid.
            Route::get('/purchasing/new', [\App\Http\Controllers\SupplierController::class, 'createOrder'])
                ->middleware('role:admin,manager')->name('purchasing.new');
            Route::get('/purchasing/{supplierOrder}', [\App\Http\Controllers\SupplierController::class, 'order'])
                ->name('purchasing.show');
            // الاستلام بيدخل بضاعة للمخزن — شغل أمين المخزن
            Route::post('/purchasing/{supplierOrder}/receive', [\App\Http\Controllers\SupplierController::class, 'receiveOrder'])
                ->name('purchasing.receive');
        });

        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/purchasing', [\App\Http\Controllers\SupplierController::class, 'storeOrder'])
                ->name('purchasing.store');
            Route::post('/purchasing/{supplierOrder}/invoice', [\App\Http\Controllers\SupplierController::class, 'invoiceOrder'])
                ->name('purchasing.invoice');
            Route::post('/purchasing/{supplierOrder}/cancel', [\App\Http\Controllers\SupplierController::class, 'cancelOrder'])
                ->name('purchasing.cancel');
            Route::post('/purchasing/{supplierOrder}/close', [\App\Http\Controllers\SupplierController::class, 'closeOrder'])
                ->name('purchasing.close');
        });

        Route::get('/contracts', [ErpController::class, 'contracts'])->name('contracts');
        // صفحة عقد العميل — كل عقد لوحده
        Route::get('/contracts/{contract}', [ErpController::class, 'contract'])->name('contracts.show');
        Route::post('/contracts', [ErpController::class, 'storeContract'])
            ->middleware('role:admin,manager')->name('contracts.store');
        // ربط عقد يتيم بسلسلة أو عميل — للعقود اللي المطابقة
        // التلقائية ماعرفتلهاش طريق
        Route::post('/contracts/{contract}/link', [ErpController::class, 'linkContract'])
            ->middleware('role:admin,manager')->name('contracts.link');
        Route::delete('/contracts/{contract}', [ErpController::class, 'destroyContract'])
            ->middleware('role:admin,manager')->name('contracts.destroy');

        // بنود العقد — إضافة/تعديل/حذف. أي تغيير بيعيد حساب نسب العقد.
        Route::post('/contracts/{contract}/clauses', [ErpController::class, 'storeClause'])
            ->middleware('role:admin,manager')->name('clauses.store');
        Route::delete('/contracts/{contract}/clauses', [ErpController::class, 'destroyClause'])
            ->middleware('role:admin,manager')->name('clauses.destroy');

        // ===== استيراد الداتا — أدمن بس =====
        Route::prefix('import')->name('import')->middleware('role:admin')->group(function () {
            Route::get('/', [\App\Http\Controllers\ImportController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\ImportController::class, 'upload'])->name('.upload');
            Route::get('/{import}', [\App\Http\Controllers\ImportController::class, 'preview'])->name('.preview');
            Route::post('/{import}/apply', [\App\Http\Controllers\ImportController::class, 'apply'])->name('.apply');
        });
        // ═══ تأكيد لوكيشن العملاء من زيارات المناديب (2026-08-08) ═══
        //
        // ⚠️ **`suggest` بره صلاحية التأكيد عن قصد.** هي بتقرا من
        // الخريطة ومابتكتبش أي حاجة — وتقييدها كان بيمنع المدير من
        // يشوف الاقتراح قبل ما يقرر يوصّله لمين.
        Route::get('/client-locations', [\App\Http\Controllers\ClientLocationController::class, 'index'])
            ->name('client_locations');
        Route::post('/client-locations/suggest', [\App\Http\Controllers\ClientLocationController::class, 'suggest'])
            ->name('client_locations.suggest');
        Route::post('/client-locations/{client}', [\App\Http\Controllers\ClientLocationController::class, 'confirm'])
            ->middleware('role:admin,manager,branch_manager')
            ->name('client_locations.confirm');

        Route::get('/import-template/{kind}', [\App\Http\Controllers\ImportController::class, 'template'])
            ->middleware('role:admin')->name('import.template');

        // ===== الصلاحيات وتسكين عملاء المديرين — أدمن بس (2026-08-05) =====
        Route::get('/permissions', [\App\Http\Controllers\PermissionController::class, 'index'])
            ->middleware('role:admin')->name('perms');
        Route::post('/permissions/{user}', [\App\Http\Controllers\PermissionController::class, 'save'])
            ->middleware('role:admin')->name('perms.save');
        Route::get('/manager-clients', [\App\Http\Controllers\ManagerClientController::class, 'index'])
            ->middleware('role:admin')->name('managers.clients');
        Route::post('/manager-clients', [\App\Http\Controllers\ManagerClientController::class, 'assign'])
            ->middleware('role:admin')->name('managers.assign');
        Route::delete('/manager-clients/{client}', [\App\Http\Controllers\ManagerClientController::class, 'unassign'])
            ->middleware('role:admin')->name('managers.unassign');
        // تسكين فريق الميدان (مناديب/سواقين) للمدير — 2026-08-05
        Route::post('/manager-team', [\App\Http\Controllers\ManagerClientController::class, 'assignTeam'])
            ->middleware('role:admin')->name('managers.team.assign');
        Route::delete('/manager-team/{user}', [\App\Http\Controllers\ManagerClientController::class, 'unassignTeam'])
            ->middleware('role:admin')->name('managers.team.unassign');

        // ===== الفروع والعربيات =====
        // ⚠️ **العرض للمديرين بس** — الشاشات بتوري أرقام كل فرع.
        // والتعديل للأدمن ومدير القنوات: مدير الفرع لو قدر يعدّل
        // الفروع يقدر ينقل نفسه لفرع تاني ويشوف بياناته.
        Route::middleware('role:admin,manager,branch_manager')->group(function () {
            Route::get('/branches', [\App\Http\Controllers\BranchController::class, 'index'])
                ->name('branches');
            Route::get('/vehicles', [\App\Http\Controllers\BranchController::class, 'vehicles'])
                ->name('vehicles');
        });

        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/branches', [\App\Http\Controllers\BranchController::class, 'store'])
                ->name('branches.store');
            Route::put('/branches/{branch}', [\App\Http\Controllers\BranchController::class, 'update'])
                ->name('branches.update');
            Route::post('/vehicles', [\App\Http\Controllers\BranchController::class, 'storeVehicle'])
                ->name('vehicles.store');
            Route::put('/vehicles/{vehicle}', [\App\Http\Controllers\BranchController::class, 'updateVehicle'])
                ->name('vehicles.update');
        });

        // ===== العملاء المحتملين =====
        // ⚠️ العرض والتسجيل مفتوحين (المندوب بيسجّل من الشارع)،
        // والتحويل لعميل بينشئ كيان تجاري فهو للأدمن والمدير بس.
        Route::get('/leads', [\App\Http\Controllers\LeadController::class, 'index'])->name('leads');
        Route::post('/leads', [\App\Http\Controllers\LeadController::class, 'store'])->name('leads.store');
        Route::put('/leads/{lead}', [\App\Http\Controllers\LeadController::class, 'update'])->name('leads.update');
        Route::post('/leads/{lead}/convert', [\App\Http\Controllers\LeadController::class, 'convert'])
            ->middleware('role:admin,manager')->name('leads.convert');

        // ===== الضريبة والفاتورة الإلكترونية — أدمن ومحاسب =====
        // ⚠️ الرقم الضريبي والنسبة بيأثروا على كل فاتورة جديدة،
        // ومدير القناة مالوش حق فيهم.
        // ⚠️ **المحاسب هنا.** ده شغله الأساسي — الإقرارات ورفع
        // الفواتير للمصلحة. من غيره الرول كله مالوش لازمة، وسهيلة
        // هتقعد تقول للأدمن «ارفعلي الفواتير» كل شهر.
        Route::middleware('role:admin,accountant')->group(function () {
            Route::get('/tax', [\App\Http\Controllers\TaxController::class, 'settings'])
                ->name('tax.settings');
            Route::post('/tax', [\App\Http\Controllers\TaxController::class, 'saveSettings'])
                ->name('tax.settings.save');

            Route::get('/eta', [\App\Http\Controllers\TaxController::class, 'eta'])->name('eta');
            Route::post('/eta/export', [\App\Http\Controllers\TaxController::class, 'export'])
                ->name('eta.export');
            Route::post('/eta/submitted', [\App\Http\Controllers\TaxController::class, 'markSubmitted'])
                ->name('eta.submitted');
        });

        // ===== مستحقات العقود =====
        // ═════ الحوافز (2026-08-06): تارجتات + أداء + إعدادات ═════
        Route::get('/targets', [\App\Http\Controllers\IncentiveController::class, 'targets'])
            ->middleware('role:admin,manager')->name('targets');
        Route::post('/targets', [\App\Http\Controllers\IncentiveController::class, 'saveTargets'])
            ->middleware('role:admin,manager')->name('targets.save');
        Route::post('/targets/copy', [\App\Http\Controllers\IncentiveController::class, 'copyTargets'])
            ->middleware('role:admin,manager')->name('targets.copy');

        // ═════ التارجيت السنوي الهرمي (١١ أغسطس ٢٠٢٦) ═════
        // شركة ← مديرين ← مناديب ← عملاء — غير تارجتات الحوافز اللي فوق.
        // ⚠️ العرض للأدمن والمدير (المدير بيشوف شجرته بس جوّه
        // الكنترولر)، وقرارات الشركة والمديرين واليدوي أدمن بس.
        Route::get('/targets/annual', [\App\Http\Controllers\TargetPlanController::class, 'annual'])
            ->middleware('role:admin,manager')->name('targets.annual');
        Route::get('/targets/rep/{user}', [\App\Http\Controllers\TargetPlanController::class, 'repClients'])
            ->middleware('role:admin,manager')->name('targets.annual.rep');
        Route::post('/targets/annual/company', [\App\Http\Controllers\TargetPlanController::class, 'createCompany'])
            ->middleware('role:admin')->name('targets.annual.company');
        Route::post('/targets/annual/managers', [\App\Http\Controllers\TargetPlanController::class, 'saveManagers'])
            ->middleware('role:admin')->name('targets.annual.managers');
        Route::post('/targets/annual/{target}/rebalance', [\App\Http\Controllers\TargetPlanController::class, 'rebalance'])
            ->middleware('role:admin,manager')->name('targets.annual.rebalance');
        Route::post('/targets/annual/{target}/manual', [\App\Http\Controllers\TargetPlanController::class, 'saveManual'])
            ->middleware('role:admin')->name('targets.annual.manual');
        Route::post('/targets/annual/{target}/reps', [\App\Http\Controllers\TargetPlanController::class, 'saveReps'])
            ->middleware('role:admin,manager')->name('targets.annual.reps');
        Route::post('/targets/annual/{target}/clients', [\App\Http\Controllers\TargetPlanController::class, 'saveClients'])
            ->middleware('role:admin,manager')->name('targets.annual.clients');
        Route::get('/performance', [\App\Http\Controllers\IncentiveController::class, 'performance'])
            ->middleware('role:admin,manager,accountant')->name('performance');
        Route::post('/performance/points', [\App\Http\Controllers\IncentiveController::class, 'storePoints'])
            ->middleware('role:admin,manager')->name('performance.points');
        Route::get('/incentives', [\App\Http\Controllers\IncentiveController::class, 'settings'])
            ->middleware('role:admin')->name('incentives');
        Route::post('/incentives', [\App\Http\Controllers\IncentiveController::class, 'saveSettings'])
            ->middleware('role:admin')->name('incentives.save');

        // ═════ الحضور والانصراف — أول موديول HR (2026-08-08) ═════
        // ⚠️ المحاسب داخل معاهم: الساعات دي بتروح للمرتبات
        Route::get('/attendance', [\App\Http\Controllers\AttendanceController::class, 'board'])
            ->middleware('role:admin,manager,accountant')->name('attendance');
        Route::get('/attendance/log', [\App\Http\Controllers\AttendanceController::class, 'log'])
            ->middleware('role:admin,manager,accountant')->name('attendance.log');
        // تصدير السجل إكسيل — موظف واحد أو الكل، بنفس فلاتر الشاشة
        Route::get('/attendance/export', [\App\Http\Controllers\AttendanceController::class, 'export'])
            ->middleware('role:admin,manager,accountant')->name('attendance.export');
        Route::get('/attendance/review', [\App\Http\Controllers\AttendanceController::class, 'review'])
            ->middleware('role:admin,manager,accountant')->name('attendance.review');
        // ⚠️ الاعتماد للأدمن والمدير بس — المحاسب بيقرا مايعتمدش
        Route::post('/attendance/{day}/approve', [\App\Http\Controllers\AttendanceController::class, 'approve'])
            ->middleware('role:admin,manager')->name('attendance.approve');

        // ═════ إصدار الأبلكيشن (2026-08-07) — أدمن بس ═════
        // ⚠️ رفع APK بيعدّي على الفورم، فالـPOST لازم يكون multipart
        Route::get('/app-version', [\App\Http\Controllers\AppVersionController::class, 'edit'])
            ->middleware('role:admin')->name('app_version');
        Route::post('/app-version', [\App\Http\Controllers\AppVersionController::class, 'save'])
            ->middleware('role:admin')->name('app_version.save');
        // رفع الـAPK بالقطع (١١/٨) — كل قطعة ٤ ميجا ريكوست مستقل،
        // عشان الرفع الكبير مايموتش على مهلة البروكسي
        Route::post('/app-version/chunk', [\App\Http\Controllers\AppVersionController::class, 'uploadChunk'])
            ->middleware('role:admin')->name('app_version.chunk');

        // ═════ سجل حركة اليوزرات (2026-08-07) — أدمن بس، قراءة فقط ═════
        Route::get('/audit', [\App\Http\Controllers\AuditController::class, 'index'])
            ->middleware('role:admin')->name('audit');

        // ═════ قفل اليوم — يومية الحسابات (2026-08-06) ═════
        Route::get('/day-close', [\App\Http\Controllers\DayCloseController::class, 'index'])
            ->middleware('role:admin,accountant')->name('dayclose');
        Route::post('/day-close', [\App\Http\Controllers\DayCloseController::class, 'store'])
            ->middleware('role:admin,accountant')->name('dayclose.store');

        // ═════ تحصيلات الميدان (2026-08-09) — للمطابقة، عرض بس ═════
        Route::get('/collections', [\App\Http\Controllers\CollectionController::class, 'index'])
            ->middleware('role:admin,manager,accountant')->name('collections');

        // ═════ تصفية المناديب (2026-08-06) — قفلة الحسابات اليومية ═════
        Route::get('/rep-close', [\App\Http\Controllers\RepSettlementController::class, 'index'])
            ->middleware('role:admin,accountant')->name('repclose');
        // ⚠️ doc قبل {user} — «doc» ماينفعش يتفسر كـid مندوب
        Route::get('/rep-close/doc/{settlement}', [\App\Http\Controllers\RepSettlementController::class, 'doc'])
            ->middleware('role:admin,accountant')->name('repclose.doc');
        // ⚠️ details قبل {user} كمان — نفس فخ doc بالظبط (١١/٨ مساءً)
        Route::get('/rep-close/details/{settlement}', [\App\Http\Controllers\RepSettlementController::class, 'details'])
            ->middleware('role:admin,accountant')->name('repclose.details');
        Route::get('/rep-close/{user}', [\App\Http\Controllers\RepSettlementController::class, 'show'])
            ->middleware('role:admin,accountant')->name('repclose.show');
        Route::post('/rep-close/{user}', [\App\Http\Controllers\RepSettlementController::class, 'store'])
            ->middleware('role:admin,accountant')->name('repclose.store');
        // مسح تصفية — أدمن بس، وآخر واحدة للمندوب بس (سلامة سلسلة الأرصدة)
        Route::delete('/rep-close/doc/{settlement}', [\App\Http\Controllers\RepSettlementController::class, 'destroy'])
            ->middleware('role:admin')->name('repclose.destroy');

        Route::get('/dues', [\App\Http\Controllers\DuesController::class, 'index'])->name('dues');
        Route::post('/dues/generate', [\App\Http\Controllers\DuesController::class, 'generate'])
            ->middleware('role:admin,manager,accountant')->name('dues.generate');
        Route::post('/dues/{due}/settle', [\App\Http\Controllers\DuesController::class, 'settle'])
            ->middleware('role:admin,manager,accountant')->name('dues.settle');
        Route::post('/dues/{due}/waive', [\App\Http\Controllers\DuesController::class, 'waive'])
            ->middleware('role:admin,manager,accountant')->name('dues.waive');

        Route::get('/stock', [ErpController::class, 'stock'])->name('stock');
        // ⚠️ **قبل `/stock/{product}` بالظبط مايجيش راوت ثابت بعده.**
        // لارافيل بيطابق بالترتيب، وأي مسار ثابت تحت `/stock/` هيتقرا
        // كـid صنف.
        Route::get('/stock/{product}', [ErpController::class, 'product'])->name('products.show');
        Route::post('/stock', [ErpController::class, 'storeProduct'])
            ->middleware('role:admin,manager')->name('products.store');
        Route::put('/stock/{product}', [ErpController::class, 'updateProduct'])
            ->middleware('role:admin,manager')->name('products.update');

        // ═════ العائلات والصلاحية (2026-08-06) — العائلة بتحكم مدة
        // الانتهاء، والحفظ بيعيد حساب انتهاء كل الباتشات ═════
        Route::get('/families', [\App\Http\Controllers\ProductFamilyController::class, 'index'])
            ->middleware('role:admin,manager')->name('families');
        Route::post('/families', [\App\Http\Controllers\ProductFamilyController::class, 'save'])
            ->middleware('role:admin,manager')->name('families.save');
        Route::post('/families/assign', [\App\Http\Controllers\ProductFamilyController::class, 'assign'])
            ->middleware('role:admin,manager')->name('families.assign');

        Route::get('/reports', [ErpController::class, 'reports'])->name('reports');

        // ═════ إدارة المخازن — التعريف والأرصدة اليدوية =════
        // ⚠️ **منفصل عن `wh.` عن قصد.** `wh.` شغل يومي (استلام،
        // ترصيف، تحويل، جرد) وأمين المخزن بيدخله. ده تعريف مخازن
        // وتعديل أرصدة بالإيد — قرار إدارة مش تشغيل.
        Route::get('/warehouses', [\App\Http\Controllers\WarehouseAdminController::class, 'index'])
            ->name('warehouses');
        Route::get('/warehouses/{warehouse}/stock',
            [\App\Http\Controllers\WarehouseAdminController::class, 'stock'])->name('warehouses.stock');

        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/warehouses', [\App\Http\Controllers\WarehouseAdminController::class, 'store'])
                ->name('warehouses.store');
            Route::put('/warehouses/{warehouse}', [\App\Http\Controllers\WarehouseAdminController::class, 'update'])
                ->name('warehouses.update');
            // ⚠️ تعديل الأرصدة بالإيد **مايعملش حركة مخزون** — بيكتب
            // الرقم مباشرةً. للتأسيس والتصحيح بس، فمقفول على الأدمن
            // والمدير حتى لو أمين المخزن بيشوف الشاشة.
            Route::post('/warehouses/{warehouse}/stock',
                [\App\Http\Controllers\WarehouseAdminController::class, 'saveStock'])
                ->name('warehouses.stock.save');
        });
        Route::get('/team', [ErpController::class, 'team'])
            ->middleware('role:admin,manager,branch_manager')->name('team');
        // ⚠️ **تغيير الباسورد للأدمن بس.** مدير القنوات ومدير الفرع
        // بيشوفوا الشاشة — لو التغيير كان مفتوح ليهم، أي مدير يغيّر
        // باسورد الأدمن ويستلم السيستم.
        Route::post('/team/{user}/password', [ErpController::class, 'setPassword'])
            ->middleware('role:admin')->name('team.password');
        // ⚠️ **إضافة وتعديل اليوزرات للأدمن بس.** مدير بيقدر يعمل
        // يوزر برول أدمن = بيقدر يستلم السيستم.
        Route::post('/team', [ErpController::class, 'storeUser'])
            ->middleware('role:admin')->name('team.store');
        Route::put('/team/{user}', [ErpController::class, 'updateUser'])
            ->middleware('role:admin')->name('team.update');
        // ═══ إدارة المناطق والمحافظات ═══
        Route::get('/zones', [ErpController::class, 'zones'])
            ->middleware('role:admin,manager,branch_manager')->name('zones');
        Route::post('/zones', [ErpController::class, 'storeZone'])
            ->middleware('role:admin,manager,branch_manager')->name('zones.store');
        Route::put('/zones/{zone}', [ErpController::class, 'updateZone'])
            ->middleware('role:admin,manager,branch_manager')->name('zones.update');

        // ===== السلاسل =====
        Route::get('/groups', [GroupController::class, 'index'])->name('groups');
        Route::get('/groups/{group}', [GroupController::class, 'show'])->name('groups.show');
        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
            Route::put('/groups/{group}', [GroupController::class, 'update'])->name('groups.update');
            Route::delete('/groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');
            Route::post('/groups/{group}/attach', [GroupController::class, 'attach'])->name('groups.attach');
        });

        // ===== القنوات =====
        Route::get('/channels', [ChannelController::class, 'index'])->name('channels');
        Route::put('/channels/{channel}', [ChannelController::class, 'update'])
            ->middleware('role:admin,manager')->name('channels.update');
        Route::post('/channels/manager/{user}', [ChannelController::class, 'assignManager'])
            ->middleware('role:admin')->name('channels.manager');
    });

    // ⚠️ ملفات العقود جوه storage مش public — لازم تعدي على اللوجين.
    // عقود موقّعة فيها أسعار وشروط، ممنوع تبقى متاحة بلينك مباشر.
    // ⚠️ **الراوت ده كان مفتوح لأي حد عامل لوجين.** كان جوه `auth` بس
    // من غير `role:`، يعني مندوب أو سواق معاه بيانات دخول للويب يعدّي
    // على `/erp/contracts/1/file` لحد 22 وينزّل كل العقود الموقّعة
    // بأسعارها وشروطها التجارية.
    // ⚠️ **المحاسب هنا.** بيحسب الرباتات والمستحقات من بنود العقد،
    // ومن غير أصل العقد بيقعد يطلبه من المدير كل مرة.
    Route::get('/erp/contracts/{contract}/file', [ErpController::class, 'contractFile'])
        ->middleware('role:admin,manager,branch_manager,accountant')
        ->name('erp.contracts.file');

    // تقرير البضاعة والصلاحيات — مقروء من ملف الجرد، مش من الداتابيز
    Route::get('/erp/batches', [\App\Http\Controllers\BatchReportController::class, 'index'])
        ->name('erp.batches');

    // ================= المخازن والأرفف =================
    Route::prefix('wh')->name('wh.')->group(function () {
        Route::get('/', [WarehouseController::class, 'index'])->name('index');
        Route::get('/receipts', [WarehouseController::class, 'receipts'])->name('receipts');
        Route::get('/receipts/{receipt}', [WarehouseController::class, 'receipt'])->name('receipt');
        // باك أب الإذن CSV — بنفس أعمدة مستورد المخزون فيرجع يتسحب
        Route::get('/receipts/{receipt}/export', [WarehouseController::class, 'exportReceipt'])->name('receipt.export');
        Route::get('/shelves', [WarehouseController::class, 'locations'])->name('locations');
        Route::get('/expiry', [WarehouseController::class, 'expiryReport'])->name('expiry');
        Route::get('/transfers', [WarehouseController::class, 'transfers'])->name('transfers');
        // ⚠️ قبل `{transfer}` — وإلا «new» بتتفسر كـid ياخد 404
        Route::get('/transfers/new', [WarehouseController::class, 'newTransfer'])
            ->middleware('role:admin,manager')->name('transfers.new');
        // ⚠️ **العرض والطباعة مفتوحين لكل اللي بيدخل شاشة المخزن.**
        // أمين المخزن المستقبِل لازم يفتح الشحنة ويطبع الورقة اللي
        // هيمضي عليها — لو محتاج المدير يفتحها له، الورقة مش هتتطبع
        // والعربية هتمشي من غير إثبات.
        Route::get('/transfers/{transfer}', [WarehouseController::class, 'transfer'])->name('transfers.show');
        Route::get('/transfers/{transfer}/print', [WarehouseController::class, 'printTransfer'])
            ->name('transfers.print');
        Route::get('/transfers/{transfer}/receipt-print', [WarehouseController::class, 'printTransferReceipt'])
            ->name('transfers.receipt_print');

        // ===== الجرد الفعلي =====
        // ⚠️ العرض والعد مفتوحين للمسجّلين (أمين المخزن بيدخّل الأرقام)،
        // والاعتماد بيحرّك مخزون فهو للأدمن والمدير بس.
        Route::get('/counts', [\App\Http\Controllers\StockCountController::class, 'index'])->name('counts');
        Route::get('/counts/{stockCount}', [\App\Http\Controllers\StockCountController::class, 'show'])->name('count');
        // ⚠️ إدخال الأرقام **مش مفتوح للكل**. الرقم اللي بيتكتب هنا
        // بيتحوّل لحركة مخزون حقيقية عند الاعتماد، ومندوب أو سواق
        // مالوش دعوة بجرد المخزن.
        // ⚠️ **أمين المخزن هو اللي بيعدّ.** الاعتماد بس هو اللي بيفضل
        // للمدير، لأن الاعتماد بيحرّك المخزون ويثبّت الفرق.
        Route::post('/counts/{stockCount}/record',
            [\App\Http\Controllers\StockCountController::class, 'record'])
            ->middleware('role:admin,manager,warehouse_keeper')->name('count.record');

        // ===== أوامر التجهيز =====
        Route::get('/picks', [PickOrderController::class, 'index'])->name('picks');
        // ⚠️ **شاشة «أمر تجهيز جديد» اتشالت** (قرار المالك 2026-08-08).
        // كان فيه تلات أماكن بتعمل أمر تجهيز: الشاشة دي، و«تسليم
        // عهدة»، وموافقة الحسابات على أمر توريد — والتلاتة بيكتبوا
        // في نفس الجدول بأغراض مختلفة، فمحدش عارف الأمر ده عهدة
        // ولا توريد. دلوقتي الإنشاء من مكانين بس حسب النوع،
        // والمخزن **بينفّذ** مش بينشئ.
        Route::get('/picks/{pick}', [PickOrderController::class, 'show'])->name('picks.show');

        // ═════ شغل المخزن اليومي — أمين المخزن بيعمله بنفسه ═════
        // ⚠️ **الاستلام والترصيف والتجهيز شغله هو.** لو محتاج المدير
        // يدوس عنه، المخزن بيقف كل يوم لحد ما المدير يفضى — والنتيجة
        // إن حد بيديله باسورد المدير وخلاص، والصلاحيات كلها بتبقى ورق.
        Route::middleware('role:admin,manager,warehouse_keeper')->group(function () {
            Route::post('/receipts', [WarehouseController::class, 'storeReceipt'])->name('receipts.store');
            // إذن استلام من شيت — نفس أعمدة ملف التصدير (2026-08-05)
            Route::post('/receipts/import', [WarehouseController::class, 'importReceipt'])->name('receipts.import');
            Route::post('/batches/{batch}/put-away', [WarehouseController::class, 'putAway'])->name('putaway');
            // ترصيف الإذن كله بضغطة — كل باتش في بلوكه حسب التاريخ (2026-08-06)
            Route::post('/receipts/{receipt}/put-away', [WarehouseController::class, 'putAwayReceipt'])->name('receipt.putaway');
            // تعديل تواريخ/رقم/تكلفة الباتش من صفحة الإذن — الكميات لأ
            Route::post('/batches/{batch}/update', [WarehouseController::class, 'updateBatch'])->name('batch.update');
            // الترصيف الجماعي من شاشة عمليات المخزن — نفس صلاحية putaway
            // (بادئة `wh.putaway` في ACTIONS بتغطي `wh.putaway.bulk`)
            Route::post('/put-away-bulk', [WarehouseController::class, 'putAwayBulk'])->name('putaway.bulk');
            Route::post('/shelf-stock/{batchLocation}/move', [WarehouseController::class, 'moveStock'])->name('move');
            Route::post('/shelves', [WarehouseController::class, 'storeLocation'])->name('locations.store');
            Route::post('/transfers/{transfer}/receive', [WarehouseController::class, 'receiveTransfer'])
                ->name('transfers.receive');

            Route::post('/picks/{pick}/start', [PickOrderController::class, 'startPicking'])->name('picks.start');
            Route::post('/picks/{pick}/ready', [PickOrderController::class, 'markReady'])->name('picks.ready');
            // تعديل إذن الصرف قبل «جاهز» (١٠/٨) — نفس حارس المخزن جوه الأكشن
            Route::post('/picks/{pick}/update', [PickOrderController::class, 'update'])->name('picks.update');
        });

        // ═════ القرارات — للمدير بس ═════
        // ⚠️ الحاجات دي بتخلق شغل أو بتثبّت فروق: تحويل بين مخزنين،
        // اعتماد جرد بيحرّك المخزون، إلغاء أمر تجهيز. أمين المخزن
        // بينفّذ، والمدير بيقرر.
        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/transfers', [WarehouseController::class, 'storeTransfer'])->name('transfers.store');

            Route::post('/counts', [\App\Http\Controllers\StockCountController::class, 'store'])->name('counts.store');
            Route::post('/counts/{stockCount}/approve',
                [\App\Http\Controllers\StockCountController::class, 'approve'])->name('count.approve');
            Route::post('/counts/{stockCount}/cancel',
                [\App\Http\Controllers\StockCountController::class, 'cancel'])->name('count.cancel');

            Route::post('/picks/{pick}/cancel', [PickOrderController::class, 'cancel'])->name('picks.cancel');
            Route::post('/po/{purchaseOrder}/fulfil', [PickOrderController::class, 'fulfilPurchaseOrder'])
                ->name('picks.po');
            Route::post('/rpl/{replenishmentRequest}/fulfil', [PickOrderController::class, 'fulfilReplenishment'])
                ->name('picks.rpl');
        });
    });

    // ================= العمليات الميدانية =================
    Route::prefix('ops')->name('ops.')->group(function () {
        Route::get('/', [OpsController::class, 'dashboard'])->name('dashboard');

        Route::get('/reps/{user}', [OpsController::class, 'rep'])->name('rep');
        // ⚠️ راوت التحميل المباشر اتشال (2026-08-03) — التحميل بيمرّ
        // بفلو تسليم العهدة: طلب ← تجهيز ← تأكيد ← استلام المندوب
        Route::post('/reps/{user}/close', [OpsController::class, 'closeCustody'])
            ->middleware('role:admin,manager')->name('rep.close');
        // ═══ تصحيح إداري للعهدة (١٢ أغسطس ٢٠٢٦) ═══
        // ⚠️ `role:admin` عن قصد — بيحرّك العهدة **والأرفف** مع بعض.
        // الزرار محكوم بأكشن `act.custody.adjust` (والأدمن يقدر يمنحه
        // لحد بعينه من شاشة الصلاحيات — EnsureRole بيحترم المنح).
        Route::post('/reps/{user}/custody/adjust', [OpsController::class, 'adjustCustody'])
            ->middleware('role:admin')->name('rep.adjust');

        // ═══ الزيارات المفتوحة + الإخراج الإداري (١١ أغسطس ٢٠٢٦) ═══
        Route::get('/open-visits', [OpsController::class, 'openVisits'])
            ->middleware('role:admin,manager')->name('open_visits');
        Route::post('/open-visits/{visit}/out', [OpsController::class, 'forceCheckOut'])
            ->middleware('role:admin,manager')->name('open_visits.out');
        Route::post('/open-wh-visits/{whVisit}/out', [OpsController::class, 'forceWarehouseOut'])
            ->middleware('role:admin,manager')->name('open_visits.wh_out');
        // الانصراف الإداري بساعات محددة (١١/٨ مساءً) — نفس الشاشة
        Route::post('/attendance/{user}/force-out', [OpsController::class, 'forceAttendanceOut'])
            ->middleware('role:admin,manager')->name('att.force_out');

        // ═══ عهد المناديب — بورد المراجعة بنظرة واحدة (١٠ أغسطس ٢٠٢٦) ═══
        // ⚠️ بيانات إدارة: قيمة عهدة كل مندوب — أدمن ومدير بس، والمدير
        // بيشوف فريقه من fieldVisibleTo جوه الكنترولر.
        Route::get('/vans', [OpsController::class, 'vans'])
            ->middleware('role:admin,manager')->name('vans');

        // ═══ مبيعات المناديب — بورد فلوس كل مندوب (١٢ أغسطس ٢٠٢٦) ═══
        // ⚠️ شاشة فلوس: نفس رولز «تحصيلات الميدان» (`erp.collections`) —
        // الأدمن والمدير (فريقه من fieldVisibleTo) + المحاسب للمطابقة.
        Route::get('/rep-sales', [OpsController::class, 'repSales'])
            ->middleware('role:admin,manager,accountant')->name('sales');

        // ═══ بورد المناديب — عهدة + مبيعات + حركة في نظرة (١٢ أغسطس ٢٠٢٦) ═══
        // ⚠️ نفس رولز «عهد المناديب» بالظبط — بيانات إدارة ميدانية.
        Route::get('/rep-board', [OpsController::class, 'repBoard'])
            ->middleware('role:admin,manager')->name('rep_board');

        Route::get('/pos', [OpsController::class, 'purchaseOrders'])->name('pos');
        Route::post('/pos', [OpsController::class, 'storePurchaseOrder'])
            ->middleware('role:admin,manager')->name('pos.store');
        Route::post('/pos/{purchaseOrder}/assign', [OpsController::class, 'assignPurchaseOrder'])
            ->middleware('role:admin,manager')->name('pos.assign');
        // ═══ صفحة الأمر الكاملة — عرض + تعديل (١٢ أغسطس ٢٠٢٦) ═══
        // العرض لكل اللي شايف اللوحة (سكوب المدير جوه الكنترولر)،
        // والتعديل نفسه فاضل على راوتاته (`ops.po.edit` بشرط poEditable)
        Route::get('/pos/{purchaseOrder}', [OpsController::class, 'showPo'])->name('pos.show');

        // ═══ أوامر توريد الكي أكاونت (2026-08-04) ═══
        // الإنشاء لمدير القناة، والقرار للحسابات — والاتنين للأدمن
        Route::get('/po-handout', [OpsController::class, 'poHandout'])
            ->middleware('role:admin,manager')->name('po.handout');
        // التعديل مفتوح لصاحب الأمر (أدمن/مدير قناة) وللحسابات — طالما pending
        Route::get('/po-handout/{purchaseOrder}/edit', [OpsController::class, 'editPo'])
            ->middleware('role:admin,manager,accountant')->name('po.edit');
        Route::post('/po-handout/{purchaseOrder}', [OpsController::class, 'updatePo'])
            ->middleware('role:admin,manager,accountant')->name('po.update');
        // رفع POs من شيتات السلاسل — bulk (2026-08-05)
        Route::get('/po-import', [OpsController::class, 'poImport'])
            ->middleware('role:admin,manager')->name('po.import');
        Route::post('/po-import/preview', [OpsController::class, 'poImportPreview'])
            ->middleware('role:admin,manager')->name('po.import.preview');
        Route::post('/po-import', [OpsController::class, 'poImportStore'])
            ->middleware('role:admin,manager')->name('po.import.store');
        // إنشاء أمر واحد من المعاينة — AJAX البروجريس (2026-08-06)
        Route::post('/po-import/one', [OpsController::class, 'poImportStoreOne'])
            ->middleware('role:admin,manager')->name('po.import.one');
        // مستند الأمر للطباعة — الحسابات بتطبع نسختين وتختمهم
        Route::get('/pos/{purchaseOrder}/print', [OpsController::class, 'printPo'])
            ->middleware('role:admin,manager,accountant')->name('po.print');
        // طباعة مجمعة: ?ids=1,2,3 — أمر في صفحة (2026-08-06)
        Route::get('/pos-print-batch', [OpsController::class, 'printPoBatch'])
            ->middleware('role:admin,manager,accountant')->name('po.print.batch');
        // تنزيل شيت الأمر الأصلي — المرجع المحفوظ وقت الرفع
        Route::get('/pos/{purchaseOrder}/sheet', [OpsController::class, 'downloadPoSheet'])
            ->middleware('role:admin,manager,accountant')->name('po.sheet');
        Route::get('/po-approvals', [OpsController::class, 'poApprovals'])
            ->middleware('role:admin,accountant')->name('po.approvals');
        // ⚠️ البلك قبل الراوت البارامتري — /po-approvals-bulk مساره مختلف أصلاً
        Route::post('/po-approvals-bulk', [OpsController::class, 'decideAllPoApprovals'])
            ->middleware('role:admin,accountant')->name('po.decide.all');
        Route::post('/po-approvals/{purchaseOrder}', [OpsController::class, 'decidePoApproval'])
            ->middleware('role:admin,accountant')->name('po.decide');

        // ═══ المرتجعات من الـERP (٨ أغسطس ٢٠٢٦) ═══
        // ⚠️ العرض للكل، والإنشاء قرار تجاري بيمس دفتر العميل —
        // فمقفول على الأدمن ومدير القناة والمحاسب.
        Route::get('/returns', [\App\Http\Controllers\ReturnController::class, 'index'])
            ->name('returns');
        Route::get('/returns/new', [\App\Http\Controllers\ReturnController::class, 'create'])
            ->middleware('role:admin,manager,accountant')->name('returns.new');
        Route::post('/returns', [\App\Http\Controllers\ReturnController::class, 'store'])
            ->middleware('role:admin,manager,accountant')->name('returns.store');
        // ⚠️ **بعد `/returns/new`** — لو قبله كان `new` هيتفسّر كـid
        Route::get('/returns/{return}', [\App\Http\Controllers\ReturnController::class, 'show'])
            ->name('returns.show');

        Route::get('/requests', [OpsController::class, 'requests'])->name('requests');
        Route::post('/requests/{clientRequest}/decide', [OpsController::class, 'decideRequest'])
            ->middleware('role:admin,manager')->name('requests.decide');

        Route::get('/invoices', [OpsController::class, 'invoices'])->name('invoices');
        Route::get('/invoices/{invoice}', [OpsController::class, 'invoice'])->name('invoice');

        Route::get('/tracking', [OpsController::class, 'tracking'])->name('tracking');

        // ===== خطط السير والتخصيص والشاشة اللايف =====
        // ⚠️ **العرض كمان للأدمن والمدير بس.** الشاشات دي بتوري
        // موقع كل مندوب لايف، وقيمة عهدته، وكل عميل من غير مسؤول
        // بتليفونه ورصيده. مندوب بيشوفها يقدر ياخد عملاء زمايله
        // ويعرف تحركاتهم — دي بيانات إدارة مش بيانات ميدان.
        // ═══ تسليم العهدة ═══
        // ⚠️ **بره مجموعة `role:admin,manager` عن قصد.** لارافيل بيدمج
        // ميدل وير المجموعة مع ميدل وير الراوت — مابيستبدلوش. الراوتس
        // دي كانت جوه المجموعة وعليها `role:...,warehouse_keeper`،
        // فالفحصين كانوا بيشتغلوا ورا بعض والأول بيرفض أمين المخزن
        // بـ403 — واللينك في السايدبار بتاعه لأن `Access` بتسمحله.
        // تسليم العهدة شغله الأساسي: هو اللي بيحمّل العربية.
        Route::middleware('role:admin,manager,warehouse_keeper')->group(function () {
            Route::get('/handout', [\App\Http\Controllers\CustodyHandoutController::class, 'index'])
                ->name('handout');
            Route::post('/handout', [\App\Http\Controllers\CustodyHandoutController::class, 'store'])
                ->name('handout.store');
            Route::get('/handout/{pick}/print', [\App\Http\Controllers\CustodyHandoutController::class, 'print'])
                ->name('handout.print');
        });

        Route::middleware('role:admin,manager')->group(function () {

            Route::get('/journeys', [\App\Http\Controllers\JourneyController::class, 'index'])
                ->name('journeys');
            Route::get('/assignments', [\App\Http\Controllers\JourneyController::class, 'assignments'])
                ->name('assignments');
            Route::get('/live', [\App\Http\Controllers\JourneyController::class, 'live'])->name('live');
            // داتا التيرمينال JSON — فولباك البولينج لو الـSSE مش شغال (2026-08-06)
            Route::get('/live/data', [\App\Http\Controllers\JourneyController::class, 'liveData'])->name('live.data');
            // لايف فوري بـSSE — تدفق مفتوح بيبعت الحمولة كل 3 ثواني (2026-08-07)
            Route::get('/live/stream', [\App\Http\Controllers\JourneyController::class, 'liveStream'])->name('live.stream');
            Route::get('/live/{user}', [\App\Http\Controllers\JourneyController::class, 'repDay'])
                ->name('rep_day');

            Route::post('/journeys', [\App\Http\Controllers\JourneyController::class, 'store'])
                ->name('journeys.store');
            // مسح شامل لخطوط السير — «أعملها من أول وجديد» (١١/٨)
            Route::post('/journeys/wipe', [\App\Http\Controllers\JourneyController::class, 'wipe'])
                ->name('journeys.wipe');
            Route::delete('/journeys/{journeyPlan}', [\App\Http\Controllers\JourneyController::class, 'destroy'])
                ->name('journeys.destroy');
            Route::post('/journeys/reorder', [\App\Http\Controllers\JourneyController::class, 'reorder'])
                ->name('journeys.reorder');

            Route::post('/assignments', [\App\Http\Controllers\JourneyController::class, 'assign'])
                ->name('assignments.assign');
            Route::delete('/assignments/{client}', [\App\Http\Controllers\JourneyController::class, 'unassign'])
                ->name('assignments.unassign');
        });

        // ===== شغل البروموتر =====
        Route::get('/merch', [ChannelController::class, 'merchVisits'])->name('merch');
        Route::get('/replenishments', [ChannelController::class, 'replenishments'])
            ->name('replenishments');
        Route::post('/replenishments/{replenishmentRequest}/assign',
            [ChannelController::class, 'assignReplenishment'])
            ->middleware('role:admin,manager')->name('replenishments.assign');
        Route::post('/replenishments/{replenishmentRequest}/cancel',
            [ChannelController::class, 'cancelReplenishment'])
            ->middleware('role:admin,manager')->name('replenishments.cancel');
        // تعديل أصناف/كميات الطلب المستني — قبل التنزيل بس (١٢/٨)
        Route::post('/replenishments/{replenishmentRequest}/update',
            [ChannelController::class, 'updateReplenishment'])
            ->middleware('role:admin,manager')->name('replenishments.update');
    });
});
