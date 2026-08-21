<?php

namespace App\Support;

use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * مين بيشوف إيه — المصدر الوحيد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **السايدبار كان بيعرض كل اللينكات لكل اللي داخل.** ومعظم شاشات
 * العرض مالهاش `role:` middleware أصلاً — يعني مندوب معاه بيانات دخول
 * للويب كان بيفتح كشوف حساب العملاء وأوامر التجهيز وشاشة الفريق.
 * ماكانش باين لأن المناديب مابيدخلوش الويب عملياً، بس الباب كان مفتوح.
 *
 * دلوقتي مع دخول **المحاسب** و**أمين المخزن**، الفرق بقى شغل يومي:
 * المحاسب مالوش دعوة بأوامر التجهيز، وأمين المخزن مالوش دعوة بمديونيات
 * العملاء. فالخريطة دي بقت لازمة.
 *
 * ⚠️ **الملف ده هو المصدر الوحيد.** السايدبار بيرسم منه، والـmiddleware
 * بيمنع منه. لو اتفرقوا، بيبقى فيه لينك بيودّي لصفحة 403 — أو أسوأ،
 * صفحة شغالة مالهاش لينك وحد لقاها بالصدفة.
 */
class Access
{
    /**
     * بادئات أسماء الراوتس المسموحة لكل رول.
     *
     * ⚠️ **بادئات مش أسماء كاملة.** `erp.clients` بتغطي `erp.clients.show`
     * و`erp.clients.store` وكل مشتقاتها. لو كتبناها اسم اسم، أول راوت
     * جديد بيتنسى وبيرجع 403 لواحد المفروض يشوفه.
     *
     * ⚠️ الأدمن مالوش دخول هنا عن قصد — `allows()` بتخرج بـ`true` قبل
     * ما توصل للخريطة. أي محاولة نعدّد شاشات الأدمن هتنسى واحدة.
     *
     * @var array<string, list<string>>
     */
    public const SCREENS = [
        // ═══ Channel Manager — الشركة كلها ما عدا الإعدادات ═══
        'manager' => [
            // السايكل الجديدة (١٧/٨): الديفيجنز + الإعداد + رصيد العناوين
            'erp.divisions', 'erp.setup.chains', 'erp.setup.clients', 'erp.client_locations.credits',
            'erp.overview', 'erp.clients', 'erp.client_locations', 'erp.groups', 'erp.channels',
            'erp.contracts', 'erp.leads', 'erp.dues', 'erp.stock',
            'erp.batches', 'erp.reports', 'erp.team', 'erp.zones',
            'erp.geo', 'erp.products', 'erp.branches', 'erp.vehicles', 'erp.warehouses',
            'erp.prices', 'erp.govs', 'erp.families',
            // الحوافز: التارجتات ولوحة الأداء — شغل مدير القناة (2026-08-06)
            'erp.targets', 'erp.performance',
            // الحضور والانصراف — بيشوف فريقه ويعتمد ساعاتهم (2026-08-08)
            'erp.attendance',
            'erp.suppliers', 'erp.purchasing',
            // ⚠️ **`erp.clauses` مش تحت `erp.contracts`.** الراوتس
            // اتسمّت `erp.clauses.store` و`erp.clauses.destroy` بره
            // مقطع `contracts`، فبادئة العقود مابتغطّيهاش — والمدير
            // كان بيفتح العقد، يملا بنوده، يدوس حفظ، ويترمي على 403.
            'erp.clauses',
            // جرس الإشعارات + تحصيلات الميدان (2026-08-09)
            'notifications', 'erp.collections',
            'wh.', 'ops.',
            // ⚠️ **قرار الموافقة على أوامر الكي أكاونت شغل الحسابات**
            // (فلو 2026-08-04). المدير بيشوف الطابور (بيتوجّه له بعد
            // رفع الشيتات وبعد تعديل أمر) بس مايقررش — الراوتس
            // `ops.po.decide` و`ops.po.decide.all` عليها
            // `role:admin,accountant`، ومن غير الاستثناء ده بادئة
            // `ops.` كانت بتقول إنه مسموح والراوت يرفضه.
            '!ops.po.decide',
        ],

        // ═══ مدير الفرع — نفس المدير، بس البيانات مفلترة بفرعه ═══
        // ⚠️ الفلترة نفسها مش هنا. دي بتحصل في الكويري بـ`canSeeBranch`.
        // الخريطة بتقول «الشاشة دي مسموحة»، والكويري بتقول «الصفوف دي».
        'branch_manager' => [
            // الديفيجنز **قراءة** — الإعداد الجماعي مش هنا عن قصد:
            // كتابة على مئات العملاء مرة واحدة قرار أدمن/مدير قناة
            'erp.divisions',
            'erp.overview', 'erp.clients', 'erp.client_locations', 'erp.groups', 'erp.contracts',
            'erp.leads', 'erp.stock', 'erp.batches', 'erp.reports',
            'erp.team', 'erp.zones', 'erp.geo', 'erp.branches', 'erp.vehicles', 'erp.warehouses',
            'erp.prices',
            // ⚠️ **`erp.products` لازم مع `erp.stock`.** كود الصنف في
            // كل صف لينك لكارته (`erp.products.show`)، وكان بيرمي 403
            // لأن البادئة مش مغطّاة — نفس الغلطة بالظبط اللي الملف ده
            // اتعمل عشانها. الكتابة لسه محمية بـ`role:admin,manager`.
            'erp.products',
            'wh.',
            // ⚠️ **قايمة صريحة مش `ops.`** — خطط السير والتخصيص والشاشة
            // اللايف كلهم `role:admin,manager`. البادئة العامة كانت
            // بتوري مدير الفرع تلات لينكات في السايدبار بتاعه بترفضه
            // أول ما يدوس — وده بالظبط اللي الخريطة دي اتعملت تمنعه.
            // ⚠️ **مفيش `ops.handout`.** تسليم العهدة بيخرّج بضاعة من
            // المخزن فوراً، وده محصور في الأدمن ومدير القنوات وأمين
            // المخزن. البادئة كانت هنا فمدير الفرع بيشوف اللينك
            // والراوت بيرفضه بـ403.
            'ops.dashboard', 'ops.requests', 'ops.pos', 'ops.replenishments',
            // ⚠️ **`ops.invoices` بالجمع لازم تتكتب لوحدها.** قاعدة
            // البادئة بتطابق الاسم بالظبط أو `الاسم + نقطة` — و
            // `ops.invoices` مش `ops.invoice` ولا بتبدأ بـ`ops.invoice.`،
            // فكانت بتقع بين الاتنين ومدير الفرع بيفقد قايمة الفواتير.
            'ops.merch', 'ops.invoice', 'ops.invoices', 'ops.tracking', 'ops.rep',
            'notifications',
            // ⚠️ المرتجعات **عرض بس** لمدير الفرع — الإنشاء بيمس
            // دفتر العميل، وده قرار تجاري (`role:admin,manager,accountant`).
            'ops.returns', '!ops.returns.new', '!ops.returns.store',
        ],

        // ═══ المحاسب — الفلوس بس ═══
        // ⚠️ **مفيش `wh.` ولا `ops.` خالص.** المحاسب بيقفل حسابات،
        // مش بيوزّع شغل ولا بيحرّك بضاعة. ولو شاف زرار «نزّل أمر توريد»
        // هيدوس عليه يوم ويطلّع بضاعة محدش طلبها.
        'accountant' => [
            'erp.overview', 'erp.clients', 'erp.client_locations', 'erp.groups', 'erp.contracts',
            'erp.dues', 'erp.reports', 'erp.tax', 'erp.eta',
            // تصفية المناديب — ده شغل الحسابات الأساسي (2026-08-06)
            'erp.repclose',
            // قفل اليوم + لوحة الأداء (قراءة) — يومية الحسابات
            'erp.dayclose', 'erp.performance',
            // ⚠️ الحضور **قراءة بس** للمحاسب — الساعات بتروح للمرتبات
            // فلازم يشوفها، بس الاعتماد قرار إداري (`role:admin,manager`)
            'erp.attendance', '!erp.attendance.approve',
            // ⚠️ المحاسب بيشوف الموردين ويدفع لهم — المستحقات شغله.
            // أوامر الشراء نفسها (بضاعة) مش له، وتعريف المورد
            // والافتتاحي قرارات إدارة (`role:admin,manager` في الراوت).
            'erp.suppliers',
            '!erp.suppliers.store', '!erp.suppliers.update', '!erp.suppliers.opening',
            'ops.invoices', 'ops.invoice',
            // ⚠️ **المرتجع من الـERP شغل الحسابات** (٨/٨/٢٠٢٦):
            // مرتجع بييجي المخزن مباشرة أو باتفاق مع سلسلة مالوش
            // مندوب ولا زيارة، ومكانه الوحيد الشاشة دي.
            'ops.returns',
            // ⚠️ موافقات أوامر توريد الكي أكاونت — دي شغل الحسابات
            // الأساسي في الفلو الجديد (2026-08-04). الإنشاء نفسه
            // (`ops.po.handout`) فاضل لمدير القناة والأدمن.
            'ops.po.approvals', 'ops.po.decide',
            // مستند الأمر — بيطبع نسختين ويختمهم بعد الموافقة
            'ops.po.print',
            // صفحة الأمر الكاملة (١٢/٨) — عرض بس: المحاسب بيوصلها من
            // لينكات الطباعة والموافقات، ومن غيرها اللينك يرمي 403
            'ops.pos.show',
            // شيت الأمر الأصلي — المرجع اللي السلسلة بعتته
            'ops.po.sheet',
            // التعديل الكامل على أمر pending — قرار المالك 2026-08-04
            'ops.po.edit', 'ops.po.update',
            // ⚠️ **استثناء من بادئة `erp.clients`.** تفعيل العملاء
            // المستوردين قرار إداري (`role:admin,manager,branch_manager`)،
            // والبادئة كانت بتوري المحاسب اللينك والراوت يرفضه بـ403.
            '!erp.clients.activate',
            // ⚠️ وإيقاف العميل نفس القرار الإداري — البادئة نفسها
            // كانت بتغطيه (`role:admin,manager` في الراوت).
            '!erp.clients.deactivate',
            // جرس الإشعارات + تحصيلات الميدان بصور الإثبات (2026-08-09)
            'notifications', 'erp.collections',
            // ⚠️ بورد مبيعات المناديب (١٢/٨) — شاشة فلوس زي التحصيلات
            // بالظبط، والراوت نفسه `role:admin,manager,accountant`.
            // البورد المدموج (`ops.rep_board`) **مش** هنا عن قصد —
            // ده متابعة ميدان زي «عهد المناديب» (أدمن ومدير بس).
            'ops.sales',
        ],

        // ═══ أمين المخزن — البضاعة بس ═══
        // ⚠️ **مفيش `erp.clients` ولا `erp.dues`.** أمين المخزن مالوش
        // دعوة بمديونية العميل ولا بخصمه ولا بعقده. وشاشة المخزون بتوريه
        // كميات، والتكلفة والهامش بيتخبّوا عنه في الشاشة نفسها.
        'warehouse_keeper' => [
            // ⚠️ `erp.products` عرض بس — راوتس الحفظ والتعديل عليها
            // `role:admin,manager` جوه الراوت نفسه.
            'erp.stock', 'erp.batches', 'erp.warehouses', 'erp.products',
            'wh.',
            // ⚠️ تسليم العهدة بيخرّج بضاعة من مخزنه — ده شغله.
            'ops.pos', 'ops.handout',
            // ⚠️ **بس عرض الأوامر مش إنشاءها ولا تسكينها** (تدقيق
            // ٨/٨/٢٠٢٦). البادئة `ops.pos` كانت بتطابق `ops.pos.store`
            // و`ops.pos.assign` كمان — أمين المخزن مالوش قرار إن أمر
            // ينزل على مين، ده قرار تجاري.
            '!ops.pos.store', '!ops.pos.assign',
            // بيستلم بضاعة الموردين — عرض الأوامر والاستلام بس،
            // الإنشاء والفوترة والإلغاء قرارات إدارة.
            'erp.purchasing',
            '!erp.purchasing.new', '!erp.purchasing.store', '!erp.purchasing.invoice',
            '!erp.purchasing.cancel', '!erp.purchasing.close',
            // جرس الإشعارات — طلبات البضاعة وأوامر التجهيز بتوصله هنا
            'notifications',
            // ⚠️ **عرض بس** — إشعار «طلب بضاعة في السكة» لينكه بيفتح
            // الشاشة دي، ومن غيرها الجرس بياكل الإشعار ويرمي 403
            // (تدقيق ٩/٨). الموافقة والتنزيل والإلغاء قرارات تجارية
            // فاضلة للمدير في الراوت نفسه.
            'ops.replenishments',
            '!ops.replenishments.assign', '!ops.replenishments.cancel',
            '!ops.replenishments.update',
        ],
    ];

    /**
     * الشاشة اللي الرول بيهبط عليها بعد اللوجين.
     *
     * ⚠️ **لازم تكون شاشة مسموحة له.** الديفولت كان `erp.overview`
     * للكل — يعني أمين المخزن كان هيدخل ويترمي على 403 في وشه أول
     * ثانية، ويفتكر إن الحساب مش شغال.
     */
    public const HOME = [
        'admin' => 'erp.overview',
        'manager' => 'erp.overview',
        'branch_manager' => 'erp.overview',
        'accountant' => 'erp.overview',
        'warehouse_keeper' => 'wh.index',
    ];

    /** الرولز اللي بتدخل الويب أصلاً */
    public const WEB_ROLES = [
        'admin', 'manager', 'branch_manager', 'accountant', 'warehouse_keeper',
    ];

    /**
     * السايدبار — المجموعات ولينكاتها.
     *
     * ⚠️ **السايدبار بيترسم من هنا مش من HTML مكتوب بإيد.** لما كان
     * مكتوب، كل اللينكات كانت بتبان لكل اللي داخل وكان لازم تفتكر
     * تحط `@if` على كل واحد — وأول لينك تنساه بيبان لواحد المفروض
     * مايشوفوش. دلوقتي اللينك بيتفلتر بنفس الدالة اللي بتحرس الراوت.
     *
     * كل عنصر: [اسم الراوت، الأيقونة، مفتاح النص، نمط التفعيل، مفتاح العدّاد]
     *
     * @var array<string, list<array{0:string,1:string,2:string,3:string,4:?string}>>
     */
    /**
     * أيقونة كل مجموعة في السايدبار.
     *
     * ⚠️ **مفصولة عن `NAV` عن قصد.** مفتاح المجموعة في `NAV` هو
     * مفتاح الترجمة نفسه (`nav.group_x`)، فمفيش مكان أحط فيه أيقونة
     * من غير ما أحوّل المجموعة لأراي متداخلة وأكسر كل اللي بيقرا منها
     * (`PermissionController` بيمشي على `array_keys` و`array_merge`).
     *
     * ⚠️ **الأيقونة جنب الاسم مش بدله** — الاسم بيتقري، والأيقونة
     * بتخلّي العين تلاقي المجموعة من غير ما تقرا. مفتاح مش موجود
     * هنا بياخد `•` بدل ما الصف يقع.
     *
     * @var array<string, string>
     */
    public const GROUP_ICONS = [
        'nav.group_home' => '🏠',
        'nav.group_products' => '🏷️',
        'nav.group_wh' => '🏭',
        'nav.group_custody' => '🚐',
        'nav.group_ka' => '🚚',
        'nav.group_purchasing' => '🤝',
        'nav.group_clients' => '👥',
        'nav.group_field' => '🗺️',
        'nav.group_money' => '💰',
        'nav.group_hr' => '🕒',
        'nav.group_reports' => '📑',
        'nav.group_settings' => '⚙️',
    ];

    public const NAV = [
        // ⚠️ **الترتيب هو سايكل الشغل نفسه (قرار المالك 2026-08-04).**
        // الصنف يتعرّف بوحداته ويتسعّر → يتشري من المورد → يدخل
        // المخزن ويترصّف → العميل يتفتح له حساب وعقد → البضاعة تطلع
        // بمسارين: عهدة الكاش فان، أو أوامر توريد الكي أكاونت
        // (إنشاء ← موافقة ← متابعة) → الميدان يتحرك ويتتبع →
        // الفلوس تتحصّل → التقارير تلخّص → الإعدادات آخر حاجة.
        // اللي بيدوّر على شاشة بيمشي مع الشغل في دماغه فبيلاقيها.

        // ═══ الرئيسية ═══
        'nav.group_home' => [
            ['erp.overview', '📊', 'nav.overview', 'erp.overview', null],
        ],

        // ═══ ١. المنتجات والتسعير — أول السايكل: تعريف الصنف ═══
        'nav.group_products' => [
            ['erp.stock', '📦', 'nav.inventory', 'erp.stock', null],
            // العائلات والصلاحية (2026-08-06) — بتحكم مدة انتهاء منتجاتها
            ['erp.families', '🧬', 'nav.families', 'erp.families*', null],
            ['erp.prices', '🏷️', 'price.price_lists', 'erp.prices*', null],
        ],

        // ═══ ٢. المخزن — بترتيب دخول البضاعة ═══
        'nav.group_wh' => [
            ['wh.index', '🏭', 'nav.warehouse', 'wh.index', null],
            ['wh.receipts', '📥', 'nav.receipts', 'wh.receipt*', null],
            ['wh.locations', '🗄️', 'nav.shelves', 'wh.locations', null],
            ['erp.warehouses', '🏢', 'stock.warehouses', 'erp.warehouses*', null],
            ['wh.transfers', '🔁', 'nav.transfers', 'wh.transfers', 'transfers'],
            ['wh.counts', '📊', 'nav.stock_counts', 'wh.count*', null],
        ],

        // ═══ ٣. العهدة — فلو تحميل العربيات كامل في مكان واحد ═══
        // (قرار المالك 2026-08-03): طلب التسليم ← تجهيز الطلبات ←
        // تأكيد ← إشعار المندوب ← استلام من الأبلكيشن
        'nav.group_custody' => [
            // بورد المراجعة بنظرة واحدة (١٠/٨) — كل مندوب وعهدته وباقيه
            ['ops.vans', '🚐', 'nav.vans_board', 'ops.vans', null],
            // الموعود مقابل المتاح (١٥/٨) — تشخيص عجز العربيات قبل ما
            // المندوب يقف قدام العميل. جنب بورد العهد لأنه نفس السؤال.
            ['ops.commitments', '🚨', 'nav.commitments', 'ops.commitments', null],
            // بورد فلوس المناديب (١٢/٨) — كاش/آجل/تحصيلات لكل مندوب.
            // المحاسب شايفه من `ops.sales` في خريطته — مش من بادئة `ops.`
            ['ops.sales', '💵', 'nav.rep_sales', 'ops.sales', null],
            // البورد المدموج (١٢/٨): عهدة + مبيعات + حضور + حركة في صف واحد
            ['ops.rep_board', '📊', 'nav.rep_board', 'ops.rep_board', null],
            // المستند اليدوي (٢١/٨) — فاتورة/مرتجع/هدية باسم المندوب
            ['ops.manual', '✍️', 'nav.manual_doc', 'ops.manual*', null],
            ['ops.handout', '📤', 'field.handout', 'ops.handout*', null],
            ['wh.picks', '📋', 'nav.prep_orders', 'wh.picks*', 'picks'],
        ],

        // ═══ ٤. توريد الكي أكاونت — السايكل كامل في مكان واحد ═══
        // (ترتيب المالك 2026-08-06): القايمة ← PO للمندوب ← PO إكسيل
        // ← موافقات الحسابات (اللي بتعمل أمر التجهيز).
        'nav.group_ka' => [
            ['ops.pos', '🚚', 'nav.purchase_orders', 'ops.pos', null],
            ['ops.po.handout', '📦', 'nav.po_handout', 'ops.po.handout', null],
            ['ops.po.import', '📊', 'nav.po_import', 'ops.po.import*', null],
            ['ops.po.approvals', '🔏', 'nav.po_approvals', 'ops.po.approvals*', 'po_approvals'],
        ],

        // ═══ ٥. المشتريات — البضاعة داخلة ═══
        'nav.group_purchasing' => [
            ['erp.suppliers', '🤝', 'supplier.suppliers', 'erp.suppliers*', null],
            ['erp.purchasing', '🧺', 'supplier.purchase_orders', 'erp.purchasing*', null],
        ],

        // ═══ ٦. البيع والعملاء — محتمل ← عميل ← تفعيل ← عقد ═══
        'nav.group_clients' => [
            ['erp.leads', '✨', 'nav.leads', 'erp.leads', null],
            ['erp.clients', '👥', 'nav.clients', 'erp.clients', null],
            // ═══ السايكل الجديدة (١٧/٨) ═══
            //
            // ⚠️⚠️ **المنيو الرئيسي بيتبني من `Access::NAV` مش من
            // `$shortcutDefs` في اللايوت** — أول ما اتضافت الشاشات
            // الجديدة اتحطت في الشورتكاتس بس، والمنيو فضل فاضي منها
            // (بلاغ المالك). أي شاشة جديدة لازم تتسجل **هنا** عشان
            // تبان في المنيو، والشورتكاتس رفاهية فوقها.
            ['erp.divisions', '🗂️', 'client.divisions', 'erp.divisions', null],
            ['erp.setup.chains', '⚙️', 'client.setup_chains', 'erp.setup.chains', null],
            ['erp.setup.clients', '🧩', 'client.setup_clients', 'erp.setup.clients', null],
            // ⚠️ العدّاد `null` مش رولز — الخانة دي مفتاح عدّاد
            // (`?string`)، وحطّ أراي فيها كان بيرمي 500 على كل صفحة
            ['erp.client_locations', '📍', 'nav.client_locations', 'erp.client_locations', null],
            ['erp.client_locations.credits', '🧭', 'geo.rep_credits', 'erp.client_locations.credits', null],
            ['erp.clients.activate', '✅', 'client.activate_clients', 'erp.clients.activate*', null],
            ['erp.groups', '🏬', 'nav.chains', 'erp.groups*', null],
            ['erp.channels', '🎯', 'nav.channels', 'erp.channels', null],
            ['erp.contracts', '📜', 'nav.contracts', 'erp.contracts', null],
            ['erp.managers.clients', '🧑‍💼', 'perm.manager_clients', 'erp.managers*', null],
        ],

        // ═══ ٧. الميدان — إعداد ← تنفيذ ← متابعة ═══
        'nav.group_field' => [
            // ⚠️ **«الزيارات» جنب «الزيارات المفتوحة» عن قصد** (١٥/٨):
            // دي اللي حصلت، ودي اللي لسه مفتوحة — والمالك بيدور
            // عليهم في نفس اللحظة. النمط بالظبط `ops.visits` عشان
            // `ops.open_visits` ماينوّرش الاتنين مع بعض.
            ['ops.visits', '🚪', 'nav.visits', 'ops.visits', null],
            // مين عامل «إن» فين دلوقتي + الإخراج الإداري (١١/٨)
            ['ops.open_visits', '🔓', 'nav.open_visits', 'ops.open_visits', null],
            ['ops.assignments', '👥', 'nav.assignments', 'ops.assignments', null],
            ['ops.journeys', '🗺️', 'nav.journeys', 'ops.journeys', null],
            // الخريطة الجغرافية وخط السير (١٣/٨) — المحافظة ← المنطقة
            // ← المحل، والتخطيط من نفس الشاشة. النمط بالظبط `ops.geo`
            // عشان `ops.geo.zone/plan/unplan` مايلوّنوش اللينك لوحدهم.
            ['ops.geo', '🧭', 'nav.geo_planner', 'ops.geo', null],
            ['ops.requests', '✅', 'nav.client_requests', 'ops.requests', 'requests'],
            ['ops.replenishments', '📦', 'nav.replenishments', 'ops.replenishments', 'replenishments'],
            ['ops.merch', '🛒', 'nav.merch_visits', 'ops.merch', null],
            ['ops.dashboard', '🛰️', 'nav.ops_dashboard', 'ops.dashboard', null],
            ['ops.live', '📡', 'nav.live', 'ops.live', null],
            // الحوافز (2026-08-06): التارجتات الشهرية + لوحة الأداء
            // ⚠️ النمط بقى بالظبط مش `erp.targets*` — عشان صفحات
            // التارجيت السنوي (`erp.targets.annual.*`) ماتنوّرش
            // اللينكين مع بعض. راوتات POST بتاعة الشاشة دي
            // (`targets.save`/`targets.copy`) مش صفحات أصلاً.
            ['erp.targets', '🎯', 'nav.targets', 'erp.targets', null],
            // التارجيت السنوي الهرمي (١١/٨): شركة ← مديرين ← مناديب ← عملاء
            ['erp.targets.annual', '📈', 'nav.targets_annual', 'erp.targets.annual*', null],
            ['erp.performance', '🏆', 'nav.performance', 'erp.performance*', null],
        ],

        // ═══ ٨. الفلوس — بعد ما البيع حصل ═══
        'nav.group_money' => [
            ['ops.invoices', '🧾', 'nav.invoices', 'ops.invoice*', null],
            // ⚠️ **المرتجعات لازم يكون ليها مدخل** — اتلسعنا قبل كده
            // من شاشة اتبنت من غير أي زرار يوصّلها (`warehouse_visit`).
            // مكانها جنب الفواتير لأنها نفس الدفتر بالظبط، بالعكس.
            ['ops.returns', '📥', 'field.returns', 'ops.returns*', null],
            // تحصيلات الميدان — شيكات وتحويلات بصور إثباتها (2026-08-09)
            ['erp.collections', '🧾', 'nav.collections', 'erp.collections*', null],
            // تصفية المناديب — قفلة الحسابات اليومية (2026-08-06)
            ['erp.repclose', '🤝', 'nav.repclose', 'erp.repclose*', null],
            // قفل اليوم — سامري اليومية الشامل (2026-08-06)
            ['erp.dayclose', '📅', 'nav.dayclose', 'erp.dayclose*', null],
            ['erp.dues', '💸', 'nav.dues', 'erp.dues', 'dues'],
            ['erp.eta', '🏛️', 'nav.eta', 'erp.eta*', null],
        ],

        // ═══ ٦. التقارير — كلها في مكان واحد ═══
        'nav.group_reports' => [
            ['erp.reports', '📑', 'nav.reports', 'erp.reports', null],
            ['erp.batches', '🗓️', 'nav.batch_report', 'erp.batches', null],
            ['wh.expiry', '⏳', 'nav.expiry', 'wh.expiry', null],
            ['ops.tracking', '📍', 'nav.tracking', 'ops.tracking', null],
        ],

        // ═══ ٧. الإعدادات ═══
        // ═══ الحضور والانصراف — مجموعة مستقلة (قرار المالك ٩/٨) ═══
        //
        // ⚠️ **الخانة الخامسة مفتاح عدّاد مش رولز** (درس ٨/٨ الموثّق):
        // `system.blade.php` بتعمل `pluck(4)` و`in_array` — مصفوفة
        // رولز هنا بتوقع السايدبار كله. الرؤية من `SCREENS`.
        'nav.group_hr' => [
            ['erp.attendance', '📊', 'hr.today_board', 'erp.attendance', null],
            ['erp.attendance.log', '📋', 'hr.log', 'erp.attendance.log', null],
            ['erp.attendance.review', '⏰', 'hr.review', 'erp.attendance.review', 'attendance_review'],
        ],

        'nav.group_settings' => [
            ['erp.team', '🧑‍💼', 'nav.team', 'erp.team', null],
            ['erp.zones', '📍', 'team.zones_and_govs', 'erp.zones*', null],
            ['erp.branches', '🏢', 'nav.branches', 'erp.branches', null],
            ['erp.vehicles', '🚚', 'nav.vehicles', 'erp.vehicles*', null],
            ['erp.import', '📥', 'nav.import', 'erp.import*', null],
            ['erp.tax.settings', '⚙️', 'nav.tax', 'erp.tax*', null],
            // إعدادات الحوافز: شرايح العمولة وقيم النقاط ونطاق الليد
            ['erp.incentives', '🏅', 'nav.incentives', 'erp.incentives*', null],
            // ⚠️ الحضور والانصراف اتنقل لمجموعته المستقلة `group_hr`
            // (قرار المالك ٩/٨) — متحطهوش هنا تاني.
            // إصدار الأبلكيشن: رفع APK وإجبار التحديث
            ['erp.app_version', '📲', 'nav.app_version', 'erp.app_version*', null],
            ['erp.perms', '🔐', 'perm.permissions', 'erp.perms*', null],
            // سجل الحركة: مين عمل إيه وإمتى — أدمن بس
            ['erp.audit', '🧾', 'nav.audit', 'erp.audit*', null],
        ],
    ];

    /**
     * الأزرار الحساسة جوه الصفحات — للتحكم زرار زرار (قرار المالك 2026-08-05).
     *
     * كل عنصر: مفتاح الأكشن => [مفتاح الليبل، صفحة الأكشن، الرولز
     * الافتراضية (null = كل اللي شايف الصفحة، [] = الأدمن بس)،
     * الراوتس اللي الزرار بيغطيها].
     *
     * ⚠️ **منح الزرار بيفتح راوتاته.** لو الأدمن دّى المحاسب زرار
     * «تسليم عهدة»، الـPOST نفسه لازم يعدّي — allows() بتعتبر
     * أوفررايد الأكشن أوفررايد لراوتاته، وEnsureRole بيحترمه.
     *
     * @var array<string, array{0:string,1:string,2:?list<string>,3:list<string>}>
     */
    public const ACTIONS = [
        // ═══ النظرة العامة ═══
        // ⚠️ **الاتنين كانوا مفتاح واحد** (اتفصلوا 2026-08-07). يعني
        // اللي عايز يحمّل داتا ديمو كان لازم تديله صلاحية **المسح**
        // كمان — أخطر زرار في السيستم مقابل أخف حاجة فيه.
        'act.overview.wipe' => ['perm.act_overview_wipe', 'erp.overview', [], ['erp.wipe']],
        'act.overview.demo' => ['perm.act_overview_demo', 'erp.overview', [], ['erp.demo']],

        // ═══ العملاء ═══
        'act.clients.create' => ['perm.act_clients_create', 'erp.clients', ['manager', 'branch_manager'], ['erp.clients.new', 'erp.clients.store', 'erp.clients.clone']],
        'act.clients.edit' => ['perm.act_clients_edit', 'erp.clients', ['manager', 'branch_manager'], ['erp.clients.edit', 'erp.clients.update']],
        'act.clients.collect' => ['perm.act_clients_collect', 'erp.clients', ['manager', 'branch_manager', 'accountant'], ['erp.clients.collect', 'erp.clients.opening']],
        'act.clients.activate' => ['perm.act_clients_activate', 'erp.clients.activate', ['manager'], ['erp.clients.activate.do', 'erp.clients.deactivate']],
        'act.contracts.manage' => ['perm.act_contracts_manage', 'erp.contracts', ['manager'], ['erp.contracts.store', 'erp.contracts.link', 'erp.contracts.destroy', 'erp.clauses.store', 'erp.clauses.destroy']],
        'act.leads.manage' => ['perm.act_leads_manage', 'erp.leads', null, ['erp.leads.store', 'erp.leads.update', 'erp.leads.convert']],

        // ═══ المنتجات والتسعير ═══
        'act.products.edit' => ['perm.act_products_edit', 'erp.stock', ['manager'], ['erp.products.store', 'erp.products.update']],
        'act.prices.edit' => ['perm.act_prices_edit', 'erp.prices', null, ['erp.prices.store', 'erp.prices.update', 'erp.prices.save', 'erp.prices.bulk']],
        'act.prices.activate' => ['perm.act_prices_activate', 'erp.prices', null, ['erp.prices.activate', 'erp.prices.deactivate', 'erp.prices.default']],

        // ═══ المخزن ═══
        'act.wh.receive' => ['perm.act_wh_receive', 'wh.receipts', null, ['wh.receipts.store', 'wh.receipts.import', 'wh.batch.update']],
        'act.wh.putaway' => ['perm.act_wh_putaway', 'wh.locations', null, ['wh.putaway', 'wh.receipt.putaway', 'wh.move', 'wh.locations.store']],
        'act.wh.transfer' => ['perm.act_wh_transfer', 'wh.transfers', null, ['wh.transfers.store', 'wh.transfers.receive', 'wh.transfers.new']],
        // ═══ تحويل من عربية مندوب (١٤/٨) ═══
        // ⚠️ **أكشن مستقل عن تحويل المخازن عن قصد.** ده بيسحب بضاعة
        // من عهدة مندوب ويغيّر أرقام تصفيته — قرار إداري مش شغل مخزن
        // يومي. `['manager']` = الأدمن والمدير، والأدمن يقدر يمنحه
        // لأمين مخزن عشان يستقبل في مخزنه هو (`guardWarehouse`).
        'act.wh.van_transfer' => ['perm.act_wh_van_transfer', 'wh.transfers', ['manager'], ['wh.transfers.van', 'wh.transfers.van.store']],
        'act.wh.count' => ['perm.act_wh_count', 'wh.counts', null, ['wh.counts.store', 'wh.count.record', 'wh.count.approve', 'wh.count.cancel']],
        'act.wh.pick' => ['perm.act_wh_pick', 'wh.picks', null, ['wh.picks.start', 'wh.picks.ready', 'wh.picks.update', 'wh.picks.cancel', 'wh.picks.po', 'wh.picks.rpl']],
        'act.warehouses.manage' => ['perm.act_warehouses_manage', 'erp.warehouses', ['manager'], ['erp.warehouses.store', 'erp.warehouses.update', 'erp.warehouses.stock.save']],

        // ═══ العهدة وتوريد الكي أكاونت ═══
        'act.custody.handout' => ['perm.act_custody_handout', 'ops.handout', null, ['ops.handout.store']],
        // تصحيح إداري للعهدة (١٢/٨) — `[]` = أدمن بس، والأدمن يقدر
        // يمنحه لحد بعينه. بيحرّك العهدة والأرفف مع بعض.
        'act.custody.adjust' => ['perm.act_custody_adjust', 'ops.vans', [], ['ops.rep.adjust']],
        'act.ka.create' => ['perm.act_ka_create', 'ops.po.handout', ['manager'], ['ops.pos.store', 'ops.pos.assign', 'ops.po.import', 'ops.po.import.preview', 'ops.po.import.store', 'ops.po.import.one']],
        'act.ka.decide' => ['perm.act_ka_decide', 'ops.po.approvals', ['accountant'], ['ops.po.decide', 'ops.po.decide.all']],
        'act.ka.edit' => ['perm.act_ka_edit', 'ops.po.approvals', ['manager', 'accountant'], ['ops.po.edit', 'ops.po.update']],

        // ═══ الميدان ═══
        // ⚠️ كتابة الخريطة الجغرافية (`ops.geo.plan/unplan`) تحت نفس
        // الأكشن — هي نفس القرار بالظبط (مين بيزور مين وإمتى) من شاشة
        // تانية. أكشن منفصل كان معناه إن الأدمن يمنع الزرار في شاشة
        // ويسيبه مفتوح في التانية من غير ما ياخد باله.
        'act.field.plan' => ['perm.act_field_plan', 'ops.assignments', null, ['ops.assignments.assign', 'ops.assignments.unassign', 'ops.journeys.store', 'ops.journeys.destroy', 'ops.journeys.reorder', 'ops.geo.plan', 'ops.geo.unplan', 'ops.geo.distribute', 'ops.geo.assign']],
        'act.field.decide' => ['perm.act_field_decide', 'ops.requests', ['manager'], ['ops.requests.decide', 'ops.replenishments.assign', 'ops.replenishments.cancel', 'ops.replenishments.update', 'ops.rep.close']],

        // ═══ الموردين والمشتريات ═══
        'act.suppliers.manage' => ['perm.act_suppliers_manage', 'erp.suppliers', ['manager'], ['erp.suppliers.store', 'erp.suppliers.update', 'erp.suppliers.opening']],
        'act.suppliers.pay' => ['perm.act_suppliers_pay', 'erp.suppliers', ['manager', 'accountant'], ['erp.suppliers.pay']],
        'act.purchasing.manage' => ['perm.act_purchasing_manage', 'erp.purchasing', ['manager'], ['erp.purchasing.new', 'erp.purchasing.store', 'erp.purchasing.invoice', 'erp.purchasing.cancel', 'erp.purchasing.close']],
        'act.purchasing.receive' => ['perm.act_purchasing_receive', 'erp.purchasing', null, ['erp.purchasing.receive']],

        // ═══ الفلوس ═══
        'act.money.dues' => ['perm.act_money_dues', 'erp.dues', ['manager', 'accountant'], ['erp.dues.generate', 'erp.dues.settle', 'erp.dues.waive']],
        'act.money.eta' => ['perm.act_money_eta', 'erp.eta', ['accountant'], ['erp.eta.export', 'erp.eta.submitted', 'erp.tax.settings.save']],

        // ═══ الإعدادات ═══
        'act.team.manage' => ['perm.act_team_manage', 'erp.team', [], ['erp.team.store', 'erp.team.update', 'erp.team.password']],
        'act.org.manage' => ['perm.act_org_manage', 'erp.zones', ['manager', 'branch_manager'], ['erp.zones.store', 'erp.zones.update', 'erp.govs.store', 'erp.govs.update', 'erp.branches.store', 'erp.branches.update', 'erp.vehicles.store', 'erp.vehicles.update', 'erp.groups.store', 'erp.groups.update', 'erp.groups.destroy', 'erp.groups.attach', 'erp.channels.update', 'erp.channels.manager']],
        'act.import.run' => ['perm.act_import_run', 'erp.import', [], ['erp.import.upload', 'erp.import.apply']],
    ];

    /**
     * السايدبار بعد الفلترة — المجموعة الفاضية بتختفي.
     *
     * ⚠️ **المجموعة الفاضية لازم تختفي.** عنوان «المخزن» فوق فراغ
     * بيخلّي المحاسب يفتكر إن فيه حاجة بتحمّل ومش ظاهرة.
     *
     * @return array<string, list<array{0:string,1:string,2:string,3:string,4:?string}>>
     */
    public static function navFor(?User $user): array
    {
        $out = [];

        foreach (self::NAV as $group => $links) {
            $allowed = array_values(array_filter(
                $links,
                fn (array $l) => self::allows($user, $l[0])
            ));

            if ($allowed !== []) {
                $out[$group] = $allowed;
            }
        }

        return $out;
    }

    /**
     * الرول ده بيوصل للراوت ده؟
     *
     * الترتيب (قرار المالك 2026-08-05): استثناءات اليوزر الأول —
     * إخفاء قسم بيمنع كل صفحاته، وبعده استثناء الصفحة نفسها، وبعده
     * أكشن بيغطي الراوت — وآخر حاجة افتراضي الرول من SCREENS.
     */
    public static function allows(?User $user, string $routeName): bool
    {
        if ($user === null) {
            return false;
        }

        // ⚠️ الأدمن بيعدّي من غير ما يبص على الخريطة ولا الاستثناءات —
        // «الأدمن معاه كل أوبشن». أي تعداد لشاشاته هيبقى ناقص.
        if ($user->role === 'admin') {
            return true;
        }

        $decision = self::userOverride($user, $routeName);

        if ($decision !== null) {
            return $decision;
        }

        return self::roleDefault($user->role, $routeName);
    }

    /**
     * استثناء اليوزر للراوت ده — null يعني «وراثة من الرول».
     *
     * ⚠️ **بيستخدمها كمان `EnsureRole`.** المنح الصريح لازم يعدّي
     * من بوابة الرول — لو الأدمن دّى المحاسب صفحة تسليم العهدة،
     * `role:admin,manager` على الراوت ماينفعش يرفضه.
     */
    public static function userOverride(?User $user, string $routeName): ?bool
    {
        if ($user === null || $user->role === 'admin') {
            return null;
        }

        $map = $user->permMap();

        if ($map === []) {
            return null;
        }

        // ١. القسم كله متقفل؟ الإخفاء بيغلب أي حاجة تانية
        $group = self::groupOf($routeName);

        if ($group !== null && ($map[$group] ?? null) === false) {
            return false;
        }

        // ٢. الصفحة/الراوت نفسه — أطول بادئة مطابقة بتكسب
        // (منع `erp.clients.activate` بيغلب منح `erp.clients`)
        $best = null;
        $len = -1;

        foreach ($map as $perm => $allow) {
            if (str_starts_with($perm, 'nav.') || str_starts_with($perm, 'act.')) {
                continue;
            }
            if (self::matches($routeName, $perm) && strlen($perm) > $len) {
                $best = $allow;
                $len = strlen($perm);
            }
        }

        if ($best !== null) {
            return $best;
        }

        // ٣. أكشن متظبط عليه استثناء وبيغطي الراوت ده
        foreach (self::ACTIONS as $key => $def) {
            if (! array_key_exists($key, $map)) {
                continue;
            }
            foreach ($def[3] as $covered) {
                if (self::matches($routeName, $covered)) {
                    return $map[$key];
                }
            }
        }

        // ٤. القسم كله ممنوح صراحةً
        if ($group !== null && ($map[$group] ?? null) === true) {
            return true;
        }

        return null;
    }

    /** افتراضي الرول من خريطة SCREENS — من غير استثناءات اليوزر */
    public static function roleDefault(string $role, string $routeName): bool
    {
        $prefixes = self::SCREENS[$role] ?? [];

        // ⚠️ **الاستثناءات الأول.** `!erp.clients.activate` بتغلب بادئة
        // `erp.clients` — من غيرها البادئة الواسعة بتوري لينكات راوتها
        // بيرفض الرول، والسايدبار يرجع يكدب على الميدل وير.
        foreach ($prefixes as $prefix) {
            // ⚠️ `str_starts_with` مش `$prefix[0]` — عنصر فاضي بالغلط
            // كان هيرمي ErrorException في السايدبار = 500 في كل صفحة.
            if (str_starts_with($prefix, '!') && self::matches($routeName, substr($prefix, 1))) {
                return false;
            }
        }

        foreach ($prefixes as $prefix) {
            if (! str_starts_with($prefix, '!') && self::matches($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * اليوزر ده شايف الزرار ده؟ — للبليدات: أزرار الإنشاء والقرارات.
     *
     * الافتراضي: الرولز المكتوبة في ACTIONS (والأدمن دايماً)، و`null`
     * يعني اللي شايف الصفحة شايف زرارها. استثناء اليوزر بيغلب الكل.
     */
    public static function action(?User $user, string $key): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        $map = $user->permMap();

        if (array_key_exists($key, $map)) {
            return $map[$key];
        }

        $def = self::ACTIONS[$key] ?? null;

        if ($def === null) {
            return false;   // مفتاح مش متسجل = زرار محدش يشوفه — سجّله الأول
        }

        [, $page, $roles] = $def;

        if ($roles === []) {
            return false;   // أدمن بس
        }

        if ($roles !== null) {
            return in_array($user->role, $roles, true) && self::allows($user, $page);
        }

        return self::allows($user, $page);
    }

    /** القسم اللي الراوت ده تابع له في المنيو — null لو مش في المنيو */
    public static function groupOf(string $routeName): ?string
    {
        if (array_key_exists($routeName, self::$groupCache)) {
            return self::$groupCache[$routeName];
        }

        $found = null;

        foreach (self::NAV as $group => $links) {
            foreach ($links as $link) {
                if (self::matches($routeName, $link[0])) {
                    $found = $group;
                    break 2;
                }
            }
        }

        return self::$groupCache[$routeName] = $found;
    }

    /** @var array<string, ?string> */
    private static array $groupCache = [];

    /** الاسم بيطابق البادئة؟ (بالظبط أو `البادئة.`) */
    private static function matches(string $routeName, string $prefix): bool
    {
        return $routeName === $prefix
            || str_starts_with($routeName, rtrim($prefix, '.').'.');
    }

    /** فين يروح بعد اللوجين */
    public static function home(?User $user): string
    {
        return self::HOME[$user?->role ?? ''] ?? 'erp.overview';
    }

    /** الرول ده بيدخل الويب أصلاً؟ */
    public static function isWebRole(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::WEB_ROLES, true);
    }
}
