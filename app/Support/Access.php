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
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            // السايكل الجديدة (١٧/٨): الديفيجنز + الإعداد + رصيد العناوين
            'erp.divisions', 'erp.setup.chains', 'erp.setup.clients', 'erp.client_locations.credits',
            // مراجعة الحسابات (٢٨/٨) — مدير القناة بيراجع عملاءه
            // ⚠️ بالاسم مش بالبادئة: `erp.audit` لوحدها سجل النظام (`role:admin`).
            // الحفظ والتأكيد ومسح الكشف `role:admin,manager` — كانوا ناقصين (٨/٩)
            // فالفورم بيبان والخريطة ترفضه
            'erp.audit.chains', 'erp.audit.clients', 'erp.audit.report',
            'erp.audit.save', 'erp.audit.confirm', 'erp.audit.statement',
            'erp.overview', 'erp.clients', 'erp.client_locations', 'erp.groups', 'erp.mother_companies', 'erp.channels',
            // مراجعة إعادة التشغيل (٥/١١) — التصفير وإدخال الأرصدة أدمن بس
            'erp.review', '!erp.review.stock.zero', '!erp.review.stock.batch',
            // مراجعة المستندات مستند مستند (١١/١١) — للمحاسب والأدمن بس
            '!erp.review.docs',
            'erp.contracts', 'erp.leads', 'erp.dues', 'erp.stock',
            'erp.batches', 'erp.reports', 'erp.kpi', 'erp.team', 'erp.zones',
            'erp.geo', 'erp.products', 'erp.branches', 'erp.vehicles', 'erp.warehouses',
            'erp.prices', 'erp.govs', 'erp.families',
            // ⚠️ تطبيق تعديل الأسعار على المستندات وإعادة المزامنة أدمن بس (Lane 13) —
            // المدير يقدر يحفظ للبيع الجديد بس، والبادئة `erp.prices` كانت هتفتحهم له
            '!erp.prices.change.apply', '!erp.prices.change.resync',
            // الحوافز: التارجتات ولوحة الأداء — شغل مدير القناة (2026-08-06)
            'erp.targets', 'erp.performance',
            // الحضور والانصراف — بيشوف فريقه ويعتمد ساعاتهم (2026-08-08)
            'erp.attendance',
            // Lane 7: زرار البانش في الهيدر + بصمة المشرف لعماله + تقرير البانشات
            'attendance.punch', 'hr.attendance.supervisor', 'hr.attendance.punches',
            // كشف الحضور اليومي والشهري (٧/١١ — مكنة البصمة)
            'hr.attendance.daily', 'hr.attendance.monthly',
            // Lane 7: الإجازات — طلباتي واعتماد فريقي؛ الأرصدة للموارد البشرية
            'hr.leave', '!hr.leave.balances',
            // الأذونات بالساعة (٧/١١) — نفس رولز الإجازات
            'hr.permissions',
            'erp.suppliers', 'erp.purchasing', 'erp.supplier_contracts',
            // طلبات الشراء وعروض الأسعار — اعتماد العرض أدمن بس (لين ٥، D10)
            'erp.purchase_requests',
            // موافقة/رفض أمر الشراء المستني قرار الأدمن بس (لين ٥، D10)
            '!erp.purchasing.approve', '!erp.purchasing.reject',
            // ⚠️ **`erp.clauses` مش تحت `erp.contracts`.** الراوتس
            // اتسمّت `erp.clauses.store` و`erp.clauses.destroy` بره
            // مقطع `contracts`، فبادئة العقود مابتغطّيهاش — والمدير
            // كان بيفتح العقد، يملا بنوده، يدوس حفظ، ويترمي على 403.
            'erp.clauses',
            // جرس الإشعارات + تحصيلات الميدان (2026-08-09)
            'notifications', 'erp.collections',
            // الكاش المتوقع (١٥/٩) — كالندر استحقاق مديونية فريقه
            'erp.cashflow',
            // إدارة المهام (٢٦/٨) — البادئة بتغطي show/comment/submit...
            'erp.tasks',
            'wh.', 'ops.',
            // موديول الأونلاين كامل (٣/٩) — التيم اللي بيأكد ويشحن
            'online.',
            // ⚠️ **قرار الموافقة على أوامر الكي أكاونت شغل الحسابات**
            // (فلو 2026-08-04). المدير بيشوف الطابور (بيتوجّه له بعد
            // رفع الشيتات وبعد تعديل أمر) بس مايقررش — الراوتس
            // `ops.po.decide` و`ops.po.decide.all` عليها
            // `role:admin,accountant`، ومن غير الاستثناء ده بادئة
            // `ops.` كانت بتقول إنه مسموح والراوت يرفضه.
            '!ops.po.decide',
            // إعدادات العمولات `role:admin` — الحاسبة نفسها مفتوحة للمدير
            '!erp.kpi.setup',
        ],

        // ═══ مدير الفرع — نفس المدير، بس البيانات مفلترة بفرعه ═══
        // ⚠️ الفلترة نفسها مش هنا. دي بتحصل في الكويري بـ`canSeeBranch`.
        // الخريطة بتقول «الشاشة دي مسموحة»، والكويري بتقول «الصفوف دي».
        'branch_manager' => [
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            // الديفيجنز **قراءة** — الإعداد الجماعي مش هنا عن قصد:
            // كتابة على مئات العملاء مرة واحدة قرار أدمن/مدير قناة
            'erp.divisions', '!erp.divisions.assign',
            'erp.overview', 'erp.clients', 'erp.client_locations', 'erp.groups', 'erp.mother_companies', 'erp.contracts',
            'erp.review', '!erp.review.stock.zero', '!erp.review.stock.batch',
            // مراجعة المستندات مستند مستند (١١/١١) — للمحاسب والأدمن بس
            '!erp.review.docs',
            // عقد السلسلة `role:admin,manager` (تدقيق ١٥/٩)
            '!erp.groups.contract',
            // ⚠️ **محفظة الليدز قراءة بس.** الجدولة وخط السير والحصاد
            // كلهم `role:admin,manager` على الراوت — والبادئة الواسعة
            // `erp.leads` كانت بتوريهم في سايدبار مدير الفرع وبترفضه
            // أول ما يدوس. نفس غلطة `ops.` و`erp.products` المسجّلة تحت.
            'erp.leads', '!erp.leads.route', '!erp.leads.planner', '!erp.leads.week',
            'erp.stock', 'erp.batches', 'erp.reports',
            // إدارة المهام (٢٦/٨)
            'erp.tasks',
            // Lane 7: زرار البانش + بصمة المشرف لعماله + تقرير البانشات (فرعه)
            'attendance.punch', 'hr.attendance.supervisor', 'hr.attendance.punches',
            // كشف الحضور اليومي والشهري (٧/١١ — مكنة البصمة)
            'hr.attendance.daily', 'hr.attendance.monthly',
            // Lane 7: الإجازات — طلباتي واعتماد فريقي؛ الأرصدة للموارد البشرية
            'hr.leave', '!hr.leave.balances',
            // الأذونات بالساعة (٧/١١) — نفس رولز الإجازات
            'hr.permissions',
            'erp.team', 'erp.zones', 'erp.geo', 'erp.branches', 'erp.vehicles', 'erp.warehouses',
            'erp.prices',
            // نفس استثناء المدير: تطبيق/إعادة مزامنة الأسعار على المستندات أدمن بس (Lane 13)
            '!erp.prices.change.apply', '!erp.prices.change.resync',
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
            // الطلب مقابل المخزون (٩/١١) — نفس اللي شايف `ops.pos`
            'ops.po.shortage',
            // ⚠️ **`ops.invoices` بالجمع لازم تتكتب لوحدها.** قاعدة
            // البادئة بتطابق الاسم بالظبط أو `الاسم + نقطة` — و
            // `ops.invoices` مش `ops.invoice` ولا بتبدأ بـ`ops.invoice.`،
            // فكانت بتقع بين الاتنين ومدير الفرع بيفقد قايمة الفواتير.
            'ops.merch', 'ops.invoice', 'ops.invoices', 'ops.tracking', 'ops.rep',
            // طباعة أمر التوريد قراءة — مفتوحة لمدير الفرع (٨/٩)
            'ops.po.print',
            'notifications',
            // ⚠️ المرتجعات **عرض بس** لمدير الفرع — الإنشاء بيمس
            // دفتر العميل، وده قرار تجاري (`role:admin,manager,accountant`).
            'ops.returns', '!ops.returns.new', '!ops.returns.store',
            // إعادة تسعير أوامر التوريد `role:admin,manager` (٨/٩)
            '!ops.pos.reprice',
            // وإلغاء الأمر `role:admin,manager` (تدقيق ١٥/٩)
            '!ops.pos.cancel',
        ],

        // ═══ المحاسب — الفلوس بس ═══
        // ⚠️ **مفيش `wh.` ولا `ops.` خالص.** المحاسب بيقفل حسابات،
        // مش بيوزّع شغل ولا بيحرّك بضاعة. ولو شاف زرار «نزّل أمر توريد»
        // هيدوس عليه يوم ويطلّع بضاعة محدش طلبها.
        'accountant' => [
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            'erp.overview', 'erp.clients', 'erp.client_locations', 'erp.groups', 'erp.mother_companies', 'erp.contracts',
            'erp.review', '!erp.review.stock.zero', '!erp.review.stock.batch',
            // مراجعة المستندات (١١/١١): فتح ✓ تاني وتحليل المراجِعين أدمن بس
            '!erp.review.docs.reopen', '!erp.review.docs.people', '!erp.review.docs.void',
            // ⚠️ تأكيد اللوكيشن وعقد السلسلة قرارات إدارة (`role:admin,manager[,branch_manager]`)
            // — البادئة كانت بتوري المحاسب الزرار والراوت يرفضه (تدقيق ١٥/٩)
            '!erp.client_locations.confirm', '!erp.groups.contract',
            'erp.dues', 'erp.reports', 'erp.tax', 'erp.eta',
            // سجل تعديلات الأسعار — قراءة (Lane 13)
            'erp.price_changes',
            // إدارة المهام (٢٦/٨)
            'erp.tasks',
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
            // الأونلاين (٣/٩): التحصيل والحسابات والمتابعة — فلوس مش
            // تشغيل. السينك والتأكيد والشحن والربط قرارات تشغيلية مش له.
            'online.collections', 'online.collect', 'online.accounts',
            'online.orders', 'online.pickups', 'online.pickup', 'online.returns', 'online.view',
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
            // الكاش المتوقع (١٥/٩) — شاشة فلوس زي التحصيلات بالظبط
            'erp.cashflow',
            // ⚠️ بورد مبيعات المناديب (١٢/٨) — شاشة فلوس زي التحصيلات
            // بالظبط، والراوت نفسه `role:admin,manager,accountant`.
            // البورد المدموج (`ops.rep_board`) **مش** هنا عن قصد —
            // ده متابعة ميدان زي «عهد المناديب» (أدمن ومدير بس).
            'ops.sales',
            // ═══ الحسابات العامة (١١/٩/٢٠٢٦) — بادئة واحدة لكل الموديول ═══
            // ⚠️ الشجرة واليومية والمصروفات وحركة النقدية شغل المحاسب
            // وحده، والراوتس كلها `role:admin,accountant` أصلاً — فالبادئة
            // مابتوسّعش على حد تاني.
            'gl.',
            // الخط ٩: التكاليف — الراوتس `role:admin,accountant` والكتابة أدمن بس
            'costing.',
            // ═══ ملف الموظف (Lane 7) — قراءة: المرتبات من هنا. الإضافة والتعديل
            // `act.hr.employees` (الموارد البشرية)، وفورم التعديل فيه الرقم القومي ═══
            'hr.employees', '!hr.employees.edit',
            // Lane 7: زرار البانش + تقرير البانشات (قراءة — الساعات بتروح المرتبات)
            'attendance.punch', 'hr.attendance.punches',
            // كشف الحضور اليومي والشهري (٧/١١ — مكنة البصمة)
            'hr.attendance.daily', 'hr.attendance.monthly',
            // Lane 7: الإجازات — طلباتي واعتماد فريقي؛ الأرصدة للموارد البشرية
            'hr.leave', '!hr.leave.balances',
            // الأذونات بالساعة (٧/١١) — نفس رولز الإجازات
            'hr.permissions',
            // Lane 7: السلف — قراءة بس (الإضافة والإلغاء للموارد البشرية)
            'hr.advances', '!hr.advances.store', '!hr.advances.void',
            // Lane 7: مسير المرتبات — قراءة وطباعة القسايم والصرف (الاعتماد للأدمن)
            // ⚠️ الصرف للمحاسب كمان (قرار المالك ١٩/١٠) — الاعتماد للأدمن بس
            'hr.payroll', '!hr.payroll.draft', '!hr.payroll.line', '!hr.payroll.approve',
        ],

        // ═══ أمين المخزن — البضاعة بس ═══
        // ⚠️ **مفيش `erp.clients` ولا `erp.dues`.** أمين المخزن مالوش
        // دعوة بمديونية العميل ولا بخصمه ولا بعقده. وشاشة المخزون بتوريه
        // كميات، والتكلفة والهامش بيتخبّوا عنه في الشاشة نفسها.
        'warehouse_keeper' => [
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            // ⚠️ `erp.products` عرض بس — راوتس الحفظ والتعديل عليها
            // `role:admin,manager` جوه الراوت نفسه.
            'erp.stock', 'erp.batches', 'erp.warehouses', 'erp.products',
            'wh.',
            // تجهيز أوردرات الأونلاين + طباعة فاتورتها (٣/٩) —
            // ده شغل المخزن؛ السينك والشحن والتحصيل مش له
            'online.prep', 'online.invoice', 'online.view',
            // ⚠️ تسليم العهدة بيخرّج بضاعة من مخزنه — ده شغله.
            'ops.pos', 'ops.handout',
            // الطلب مقابل المخزون (٩/١١) — يجهّز للعجز قبل ما يحصل
            'ops.po.shortage',
            // ورقة الأمر بتتطبع مع التجهيز (٨/٩)
            'ops.po.print',
            // ⚠️ **بس عرض الأوامر مش إنشاءها ولا تسكينها** (تدقيق
            // ٨/٨/٢٠٢٦). البادئة `ops.pos` كانت بتطابق `ops.pos.store`
            // و`ops.pos.assign` كمان — أمين المخزن مالوش قرار إن أمر
            // ينزل على مين، ده قرار تجاري.
            '!ops.pos.store', '!ops.pos.assign',
            // وإلغاء الأمر قرار إدارة كمان (تدقيق ١٥/٩) — بيعكس عهدة مسلّمة
            '!ops.pos.cancel',
            // إعادة التسعير والتحويل لعميل تاني قرار إدارة (٨/٩)
            '!ops.pos.reprice', '!ops.pos.reassign',
            // بيستلم بضاعة الموردين — عرض الأوامر والاستلام بس،
            // الإنشاء والفوترة والإلغاء قرارات إدارة.
            'erp.purchasing',
            '!erp.purchasing.new', '!erp.purchasing.store', '!erp.purchasing.invoice',
            '!erp.purchasing.cancel', '!erp.purchasing.close',
            '!erp.purchasing.approve', '!erp.purchasing.reject',
            // بيطلب خامة ويشوف طلباته؛ العروض والمقارنة قرار الإدارة
            'erp.purchase_requests', '!erp.purchase_requests.quotes',
            // جرس الإشعارات — طلبات البضاعة وأوامر التجهيز بتوصله هنا
            'notifications',
            // ⚠️ **عرض بس** — إشعار «طلب بضاعة في السكة» لينكه بيفتح
            // الشاشة دي، ومن غيرها الجرس بياكل الإشعار ويرمي 403
            // (تدقيق ٩/٨). الموافقة والتنزيل والإلغاء قرارات تجارية
            // فاضلة للمدير في الراوت نفسه.
            'ops.replenishments',
            '!ops.replenishments.assign', '!ops.replenishments.cancel',
            '!ops.replenishments.update',
            // التصنيع (١٩/١٠): بيشوف أوامر الإنتاج عشان يصرف لها خامات من مخزنه،
            // واحتياج الخامات. ⚠️ الاستثناء بالاسم مش بالبادئة — صرف الخامات
            // (`mfg.orders.issue*` / `mfg.orders.return`، مهمة ٥) شغله.
            'mfg.orders', 'mfg.requirements',
            '!mfg.orders.store', '!mfg.orders.start', '!mfg.orders.finish',
            '!mfg.orders.cancel', '!mfg.orders.shortage', '!mfg.orders.close',
            // Lane 7: زرار البانش + بصمة المشرف لعمال المخزن اللي مالهمش لوجين
            'attendance.punch', 'hr.attendance.supervisor',
            // Lane 7: الإجازات — طلباتي واعتماد فريقي؛ الأرصدة للموارد البشرية
            'hr.leave', '!hr.leave.balances',
            // الأذونات بالساعة (٧/١١) — نفس رولز الإجازات
            'hr.permissions',
        ],

        // ═══ مستلم النظام — شاشات التسليم بس (الإغلاق ١٩/١٠، spec §4.1) ═══
        // ⚠️ **ولا شاشة تانية خالص** — لوجين الشركة المستلمة بيعلّم على
        // الشاشات ويشوف النسب. حالة التيست واللايف والديدلاين قرار المالك
        // (`handover.index.meta` `role:admin` + `act.handover.meta`).
        'handover_reviewer' => [
            'handover.', '!handover.index.meta',
        ],

        // ═══ التصنيع (الإغلاق ١٩/١٠، spec §4) — شاشات `mfg.*` بس ═══
        // ⚠️ الاعتمادات (المواصفة والـBOM) أدمن بس — الرولز دي بتشوف وتشتغل،
        // والكتابة بتتحرس بـ`role:` على الراوت + `act.mfg.*`.
        // ⚠️ المواصفات: الإنتاج والجودة بيشوفوا بس (الكتابة `role:admin,rnd`)،
        // والبحث والتطوير بيكتب ويقدّم ومايعتمدش (`role:admin`).
        'production_manager' => [
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            'mfg.orders', 'mfg.qc', 'mfg.specs', 'mfg.boms', 'notifications',
            'mfg.lines', 'mfg.requirements', 'mfg.trace', 'mfg.variance',
            '!mfg.lines.settings', '!mfg.qc.decide',
            '!mfg.specs.store', '!mfg.specs.update', '!mfg.specs.submit',
            '!mfg.specs.approve', '!mfg.specs.send_back',
            '!mfg.boms.store', '!mfg.boms.lines', '!mfg.boms.approve',
        ],
        'quality' => [
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            'mfg.qc', 'mfg.specs', 'mfg.orders', 'mfg.trace', 'notifications',
            '!mfg.specs.store', '!mfg.specs.update', '!mfg.specs.submit',
            '!mfg.specs.approve', '!mfg.specs.send_back',
            '!mfg.orders.store', '!mfg.orders.start', '!mfg.orders.finish',
            '!mfg.orders.cancel', '!mfg.orders.shortage', '!mfg.orders.close',
            '!mfg.orders.issue', '!mfg.orders.issue_standard', '!mfg.orders.return',
        ],
        'rnd' => [
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            'mfg.specs', 'mfg.boms', 'notifications',
            '!mfg.specs.approve', '!mfg.specs.send_back',
            '!mfg.boms.approve',
        ],
        // ═══ الموارد البشرية (Lane 7، الإغلاق ١٩/١٠) — ملفات الموظفين والحضور ═══
        // ⚠️ اعتماد ساعات الحضور فاضل للأدمن والمدير (`role:admin,manager`)
        // زي المحاسب بالظبط — والمرتبات: الاعتماد والصرف وحفظ الإعدادات أدمن.
        'hr' => [
            // 📖 الدليل (٢١/١٠) — كل رولز الويب ما عدا مستلم النظام
            'docs',
            'hr.', 'erp.attendance', 'attendance.punch',
            '!erp.attendance.approve',
            '!hr.payroll.approve', '!hr.payroll.pay', '!hr.settings.save',
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
        'handover_reviewer' => 'handover.index',
        'production_manager' => 'mfg.orders',
        'quality' => 'mfg.qc',
        'rnd' => 'mfg.specs',
        'hr' => 'hr.employees',
    ];

    /**
     * مفاتيح مقفولة على المصفوفة (٩/١١) — بادئات. أي صف استثناء (يوزر/
     * رول مخصص/رول أساسي) عليها **بيتجاهل**: الصلاحيات والرولز بـ`role:admin`
     * بس، ووقت الشغل بـ`WorkTimeGate`، والمساعد بـ`AiGate`. `erp.agent` (سجل
     * تشغيلات المساعد، فيه محادثات جاد) أدمن بس — مراجعة ٩/١١.
     */
    public const LOCKED = ['erp.perms', 'erp.roles', 'erp.worktime', 'agent', 'erp.agent'];

    /** راوت صفحة «مفيش شاشات متحددة لك» — آخر محطة لـ`home()` */
    public const NO_SCREENS = 'no_screens';

    /** مجموعة المصفوفة الوهمية للشاشات اللي بره السايدبار (٩/١١) */
    public const PUBLIC_GROUP = 'nav.group_public';

    /**
     * «شاشات عامة» (٩/١١) — راوتس (أو بادئات) بره NAV بيوصلها اليوزر من
     * الهيدر أو من لينك جوه صفحة. من غيرها مكانهم مش موجود في المصفوفة.
     * ⚠️ أي بادئة هنا مابتطابقش صفحة NAV (التيست بيمسكها).
     *
     * @var list<string>
     */
    public const PUBLIC_PAGES = [
        'notifications', 'attendance.punch', 'docs.file',
        'erp.products', 'erp.reports',
        'ops.invoice', 'ops.rep', 'ops.rep_day', 'ops.po.print', 'ops.po.sheet',
        'online.view', 'online.invoice', 'online.pickup',
        'wh.receipt', 'wh.count',
        'gl.golive.show', 'gl.golive.run.progress',
    ];

    /**
     * راوتس كتابة مالهاش زرار في المصفوفة عن قصد — اليوزر بيشتغل على
     * حاجته هو، والكنترولر بيفحص الملكية. الراوت ⇒ السبب.
     *
     * @var array<string, string>
     */
    public const ACTION_EXEMPT = [
        'attendance.punch' => 'own punch in/out from the header',
        'notifications.read' => 'marks the user\'s own notifications as read',
        'hr.leave.store' => 'own leave request',
        'hr.leave.cancel' => 'cancels the user\'s own leave request (controller checks owner)',
        'hr.permissions.store' => 'own hourly permission request',
        'erp.tasks.comment' => 'party to the task only (controller checks creator/assignee)',
        'erp.tasks.submit' => 'assignee submits own task (controller checks assignee)',
        'erp.tasks.approve' => 'task creator approves own task (controller checks creator)',
        'erp.tasks.reject' => 'task creator rejects own task (controller checks creator)',
        'erp.client_locations.suggest' => 'read-only map suggestion, writes nothing',
        'erp.geo.resolve' => 'read-only map-link to coordinates lookup, writes nothing',
    ];

    /** الرولز اللي بتدخل الويب أصلاً */
    public const WEB_ROLES = [
        'admin', 'manager', 'branch_manager', 'accountant', 'warehouse_keeper',
        'handover_reviewer',
        'production_manager', 'quality', 'rnd',
        'hr',
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
        'nav.group_online' => '🛒',
        'nav.group_clients' => '👥',
        'nav.group_leads' => '🧲',
        'nav.group_routes' => '🗺️',
        'nav.group_tasks' => '✅',
        'nav.group_review' => '🧾',
        'nav.group_field' => '🗺️',
        'nav.group_money' => '💰',
        'nav.group_gl' => '📒',
        'nav.group_costing' => '💹',
        'nav.group_hr' => '🕒',
        'nav.group_targets' => '🎯',
        'nav.group_reports' => '📑',
        'nav.group_settings' => '⚙️',
        'nav.group_handover' => '🎓',
        'nav.group_mfg' => '🧪',
        'nav.group_docs' => '📖',
    ];

    public const NAV = [
        // ⚠️ **الترتيب هنا هو ترتيب السايدبار** (٢٢/٩): بسير الشغل — العميل، الميدان،
        // العهدة والتوريد، الفلوس، البضاعة، وبعدين المتابعة والإعدادات.

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

        // ═══ ٦. البيع والعملاء — عميل ← تفعيل ← عقد ═══
        'nav.group_clients' => [
            ['erp.clients', '👥', 'nav.clients', 'erp.clients', null],
            // ═══ السايكل الجديدة (١٧/٨) ═══
            //
            // ⚠️⚠️ **المنيو الرئيسي بيتبني من `Access::NAV` مش من
            // `$shortcutDefs` في اللايوت** — أول ما اتضافت الشاشات
            // الجديدة اتحطت في الشورتكاتس بس، والمنيو فضل فاضي منها
            // (بلاغ المالك). أي شاشة جديدة لازم تتسجل **هنا** عشان
            // تبان في المنيو، والشورتكاتس رفاهية فوقها.
            ['erp.divisions', '🗂️', 'client.divisions', 'erp.divisions', null],
            // ⚠️ **شاشتا الإعداد اتنقلوا لمجموعة «مراجعة العملاء»**
            // (٢٨/٨ — طلب المالك): هما وصفحتَي مراجعة الحسابات بقوا
            // تاب واحد، لأن الشغل عليهم بيتعمل ورا بعض
            // ⚠️ العدّاد `null` مش رولز — الخانة دي مفتاح عدّاد
            // (`?string`)، وحطّ أراي فيها كان بيرمي 500 على كل صفحة
            ['erp.client_locations', '📍', 'nav.client_locations', 'erp.client_locations', null],
            ['erp.client_locations.credits', '🧭', 'geo.rep_credits', 'erp.client_locations.credits', null],
            ['erp.clients.activate', '✅', 'client.activate_clients', 'erp.clients.activate*', null],
            ['erp.mother_companies', '🏢', 'nav.mother_companies', 'erp.mother_companies*', null],
            ['erp.groups', '🏬', 'nav.chains', 'erp.groups*', null],
            ['erp.groups.unlinked', '🔗', 'nav.unlinked_branches', 'erp.groups.unlinked', null],
            ['erp.channels', '🎯', 'nav.channels', 'erp.channels', null],
            ['erp.contracts', '📜', 'nav.contracts', 'erp.contracts', null],
            ['erp.managers.clients', '🧑‍💼', 'perm.manager_clients', 'erp.managers*', null],
        ],

        // ═══ ٦ب. مراجعة العملاء (٢٨ أغسطس ٢٠٢٦ — طلب المالك) ═══
        //
        // «تاب جديد اسمه مراجعة العملاء: فيه إعداد العملاء وإعداد
        // السلاسل والصفحتين الجداد». الترتيب = ترتيب الشغل: تظبط
        // الإعداد الأول، وبعدين تمشي على الحسابات كيان كيان.
        'nav.group_review' => [
            // (٥/١١) مراجعة إعادة التشغيل — أول الجروب لأنها خطة الشغل كلها
            ['erp.review', '🧭', 'nav.review_golive', 'erp.review*', null],
            ['erp.setup.chains', '⚙️', 'client.setup_chains', 'erp.setup.chains', null],
            ['erp.setup.clients', '🧩', 'client.setup_clients', 'erp.setup.clients', null],
            ['erp.audit.chains', '🧾', 'audit.chains_title', 'erp.audit.chains', null],
            ['erp.audit.clients', '👤', 'audit.clients_title', 'erp.audit.clients', null],
            ['erp.audit.report', '📊', 'audit.report_title', 'erp.audit.report', null],
        ],

        // ═══ سكشن العملاء المحتملين (٢٦/٨ — طلب المالك): المحفظة ←
        // الجدولة ← المتابعة. اتفصل من مجموعة العملاء لما بقى
        // بايبلاين كامل بخطة أسبوعية وحصاد. ═══
        'nav.group_leads' => [
            ['erp.leads', '✨', 'nav.leads_portfolio', 'erp.leads', null],
            // راسم خط السير التفاعلي (٦/٩) — ترقيم المحطات على الخريطة
            ['erp.leads.route', '🧭', 'nav.leads_route', 'erp.leads.route', null],
            ['erp.leads.planner', '📅', 'nav.leads_planner', 'erp.leads.planner', null],
            ['erp.leads.week', '👁', 'nav.leads_week', 'erp.leads.week', null],
        ],

        // ═══ العملاء الحاليين — خطوط السير (١١/١١ — «زي المحتملين بالظبط») ═══
        // نفس الأربع تابات بنفس الأيقونات والأسماء والترتيب. `ops.geo` و
        // `ops.journeys` أسماء الراوتات القديمة عن قصد (الصلاحيات المتخزنة عليها).
        'nav.group_routes' => [
            ['ops.portfolio', '✨', 'nav.leads_portfolio', 'ops.portfolio', null],
            ['ops.geo', '🧭', 'nav.leads_route', 'ops.geo', null],
            ['ops.journeys', '📅', 'nav.leads_planner', 'ops.journeys', null],
            ['ops.journeys.week', '👁', 'nav.leads_week', 'ops.journeys.week', null],
        ],

        // ═══ ٧. الميدان — إعداد ← تنفيذ ← متابعة ═══
        'nav.group_field' => [
            ['ops.dashboard', '🛰️', 'nav.ops_dashboard', 'ops.dashboard', null],
            ['ops.live', '📡', 'nav.live', 'ops.live', null],
            // ⚠️ **«الزيارات» جنب «الزيارات المفتوحة» عن قصد** (١٥/٨):
            // دي اللي حصلت، ودي اللي لسه مفتوحة — والمالك بيدور
            // عليهم في نفس اللحظة. النمط بالظبط `ops.visits` عشان
            // `ops.open_visits` ماينوّرش الاتنين مع بعض.
            ['ops.visits', '🚪', 'nav.visits', 'ops.visits', null],
            // مين عامل «إن» فين دلوقتي + الإخراج الإداري (١١/٨)
            ['ops.open_visits', '🔓', 'nav.open_visits', 'ops.open_visits', null],
            ['ops.assignments', '👥', 'nav.assignments', 'ops.assignments', null],
            // خطوط السير (الراسم/الجدولة/المتابعة) اتنقلت لمجموعة
            // `nav.group_routes` (١١/١١) — نفس سكشن المحتملين.
            ['ops.requests', '✅', 'nav.client_requests', 'ops.requests', 'requests'],
            ['ops.merch', '🛒', 'nav.merch_visits', 'ops.merch', null],
            ['ops.tracking', '📍', 'nav.tracking', 'ops.tracking', null],
            // أجهزة تتبع العربيات — iTrack (٢٦/٨)
            ['ops.gps', '🛰️', 'nav.gps', 'ops.gps', null],
        ],

        // ═══ ٣. العهدة — فلو تحميل العربيات كامل في مكان واحد ═══
        // (قرار المالك 2026-08-03): طلب التسليم ← تجهيز الطلبات ←
        // تأكيد ← إشعار المندوب ← استلام من الأبلكيشن
        'nav.group_custody' => [
            // بورد المراجعة بنظرة واحدة (١٠/٨) — كل مندوب وعهدته وباقيه
            ['ops.vans', '🚐', 'nav.vans_board', 'ops.vans', null],
            // «الموعود مقابل المتاح» اتشالت (قرار المالك ٢٢/٨) — فلو
            // الريفيل الجديد بيرفع أمر تجهيز فوراً فمفيش «وعود» معلقة
            // بورد فلوس المناديب (١٢/٨) — كاش/آجل/تحصيلات لكل مندوب.
            // المحاسب شايفه من `ops.sales` في خريطته — مش من بادئة `ops.`
            ['ops.sales', '💵', 'nav.rep_sales', 'ops.sales', null],
            // البورد المدموج (١٢/٨): عهدة + مبيعات + حضور + حركة في صف واحد
            ['ops.rep_board', '📊', 'nav.rep_board', 'ops.rep_board', null],
            ['ops.handout', '📤', 'field.handout', 'ops.handout*', null],
            ['wh.picks', '📋', 'nav.prep_orders', 'wh.picks*', 'picks'],
            ['ops.replenishments', '📦', 'nav.replenishments', 'ops.replenishments', 'replenishments'],
        ],

        // ═══ ٤. توريد الكي أكاونت — السايكل كامل في مكان واحد ═══
        // (ترتيب المالك 2026-08-06): القايمة ← PO للمندوب ← PO إكسيل
        // ← موافقات الحسابات (اللي بتعمل أمر التجهيز).
        'nav.group_ka' => [
            ['ops.pos', '🚚', 'nav.purchase_orders', 'ops.pos', null],
            ['ops.po.handout', '📦', 'nav.po_handout', 'ops.po.handout', null],
            ['ops.po.import', '📊', 'nav.po_import', 'ops.po.import*', null],
            ['ops.po.approvals', '🔏', 'nav.po_approvals', 'ops.po.approvals*', 'po_approvals'],
            // الطلب مقابل المخزون (٩/١١) — نواقص الأوامر المفتوحة
            ['ops.po.shortage', '⚖️', 'nav.po_shortage', 'ops.po.shortage', null],
        ],

        // ═══ ٨. الفلوس — بعد ما البيع حصل ═══
        'nav.group_money' => [
            ['ops.invoices', '🧾', 'nav.invoices', 'ops.invoice*', null],
            // المستند اليدوي (نقل ٢٢/٨ بطلب المالك) — مكانه الطبيعي مع
            // الحسابات: بيعمل فواتير ومرتجعات وقيود، مش شغل عهدة
            ['ops.manual', '✍️', 'nav.manual_doc', 'ops.manual*', null],
            // ⚠️ **المرتجعات لازم يكون ليها مدخل** — اتلسعنا قبل كده
            // من شاشة اتبنت من غير أي زرار يوصّلها (`warehouse_visit`).
            // مكانها جنب الفواتير لأنها نفس الدفتر بالظبط، بالعكس.
            ['ops.returns', '📥', 'field.returns', 'ops.returns*', null],
            // تحصيلات الميدان — شيكات وتحويلات بصور إثباتها (2026-08-09)
            ['erp.collections', '🧾', 'nav.collections', 'erp.collections', null],
            // التحصيلات المباشرة (٩/٩) — تحويل/شيك من العميل بلا مندوب،
            // بعمود الضرايب المخصومة تحت الحساب
            ['erp.collections.direct', '🏦', 'nav.collections_direct', 'erp.collections.direct*', null],
            // الكاش المتوقع (١٥/٩) — كالندر استحقاق المديونية: كام هيدخل وإمتى
            ['erp.cashflow', '📆', 'nav.cashflow', 'erp.cashflow', null],
            // تصفية المناديب — قفلة الحسابات اليومية (2026-08-06)
            ['erp.repclose', '🤝', 'nav.repclose', 'erp.repclose*', null],
            // قفل اليوم — سامري اليومية الشامل (2026-08-06)
            ['erp.dayclose', '📅', 'nav.dayclose', 'erp.dayclose*', null],
            ['erp.dues', '💸', 'nav.dues', 'erp.dues', 'dues'],
            ['erp.eta', '🏛️', 'nav.eta', 'erp.eta*', null],
            // عروض الأسعار (نقل ٢٢/٨ بطلب المالك) — مكانها الطبيعي مع
            // المنتجات والتسعير: العرض بيتبني من الكتالوج والأسعار
            ['erp.reports.quotations', '📄', 'nav.quotations', 'erp.reports.quotation*', null],
        ],

        // ═══ ٨ب. الحسابات العامة — دفتر الأستاذ (١١/٩/٢٠٢٦) ═══
        // ⚠️ مجموعة لوحدها مش تحت «الفلوس»: دي قيود الشجرة (مصروف،
        // إيداع، عهدة نقدية) — شغل المحاسب وحده، وشاشات «الفلوس»
        // فوقيها بيشوفها المدير كمان.
        // الطبقة دي **مشتقة**: `transactions` لسه مصدر الحقيقة للمديونية،
        // و`App\Services\Gl\Ledger` هو الكاتب الوحيد في `gl_*`. الشاشات
        // كلها قراءة + سندات؛ اللي بيغيّر الأرصدة فعلاً هو المستند الأصلي.
        // `act.gl.post` = أدمن + محاسب · `act.gl.admin` = أدمن بس.
        // الدوكترين الكامل: `promax-accounting` §12.
        'nav.group_gl' => [
            // ⚠️ الترتيب = ترتيب الشغل: الشجرة الأول (هي المرجع)، بعدين
            // اليومية، بعدين التقارير التلاتة، وبعدين السندات، والإعدادات
            // آخر حاجة — مش أبجدي ولا بترتيب ما اتكتبوا.
            // (١٩/١٠) معالج التشغيل — أول حاجة: بيوصّل لكل الخطوات اللي تحت بالترتيب
            ['gl.golive.index', '🚀', 'nav.gl_golive', 'gl.golive*', null],
            ['gl.accounts', '🌳', 'nav.gl_accounts', 'gl.accounts*', null],
            // (٢٢/٩) مسودة شجرة العميل — ورقة شغل قبل اعتماد الشجرة وربطها
            ['gl.coa', '📝', 'coa.page', 'gl.coa*', null],
            // (١٩/١٠) ربط مفاتيح النظام وخانات القواعد بالشجرة المعتمدة
            ['gl.mapping', '🔗', 'nav.gl_mapping', 'gl.mapping*', null],
            ['gl.entries', '📒', 'nav.gl_entries', 'gl.entries*', null],
            ['gl.trial_balance', '⚖️', 'nav.gl_trial_balance', 'gl.trial_balance*', null],
            ['gl.income', '📈', 'nav.gl_income', 'gl.income*', null],
            ['gl.balance_sheet', '🏛️', 'nav.gl_balance_sheet', 'gl.balance_sheet*', null],
            ['gl.expenses', '🧾', 'nav.gl_expenses', 'gl.expenses*', null],
            ['gl.expense_categories', '🏷️', 'nav.gl_expense_categories', 'gl.expense_categories*', null],
            ['gl.safes', '💰', 'nav.gl_safes', 'gl.safes*', null],
            ['gl.recon', '🧮', 'nav.gl_recon', 'gl.recon*', null],
            ['gl.assets', '🖥️', 'nav.gl_assets', 'gl.assets*', null],
            ['gl.cash', '🏦', 'nav.gl_cash', 'gl.cash*', null],
            ['gl.settings', '⚙️', 'nav.gl_settings', 'gl.settings*', null],
        ],

        // ═══ التكاليف (الخط ٩) — بعد الحسابات العامة مباشرة ═══
        'nav.group_costing' => [
            ['costing.pnl', '📊', 'nav.costing_pnl', 'costing.pnl*', null],
            ['costing.overhead', '🏭', 'nav.costing_overhead', 'costing.overhead*', null],
            ['costing.cards', '🧮', 'nav.costing_cards', 'costing.cards*', null],
        ],

        // ═══ ١. المنتجات والتسعير — أول السايكل: تعريف الصنف ═══
        'nav.group_products' => [
            ['erp.stock', '📦', 'nav.inventory', 'erp.stock', null],
            // العائلات والصلاحية (2026-08-06) — بتحكم مدة انتهاء منتجاتها
            ['erp.families', '🧬', 'nav.families', 'erp.families*', null],
            ['erp.prices', '🏷️', 'price.price_lists', 'erp.prices*', null],
            ['erp.price_changes', '🧾', 'reprice.log_title', 'erp.price_changes*', null],
        ],

        // ═══ ٢. المخزن — بترتيب دخول البضاعة ═══
        'nav.group_wh' => [
            ['wh.index', '🏭', 'nav.warehouse', 'wh.index', null],
            ['wh.receipts', '📥', 'nav.receipts', 'wh.receipt*', null],
            ['wh.locations', '🗄️', 'nav.shelves', 'wh.locations', null],
            ['erp.warehouses', '🏢', 'stock.warehouses', 'erp.warehouses*', null],
            ['wh.transfers', '🔁', 'nav.transfers', 'wh.transfers', 'transfers'],
            ['wh.counts', '📊', 'nav.stock_counts', 'wh.count*', null],
            // (عروض الأسعار اتنقلت لمجموعة المنتجات والأسعار — ٢٢/٨)
            ['erp.batches', '🗓️', 'nav.batch_report', 'erp.batches', null],
            ['wh.expiry', '⏳', 'nav.expiry', 'wh.expiry', null],
            ['wh.raw', '🧪', 'nav.raw_store', 'wh.raw', null],
        ],

        // ═══ التصنيع (الإغلاق ١٩/١٠، spec §3.5) — مواصفة ← BOM ← أمر ← صرف ←
        // قفل ← جودة. بنود الشاشات التانية بتتزوّد مع مهامها. ═══
        'nav.group_mfg' => [
            ['mfg.orders', '📋', 'nav.mfg_orders', 'mfg.orders*', null],
            ['mfg.qc', '🔬', 'nav.mfg_qc', 'mfg.qc*', null],
            ['mfg.specs', '📄', 'nav.mfg_specs', 'mfg.specs*', null],
            ['mfg.boms', '🧾', 'nav.mfg_boms', 'mfg.boms*', null],
            ['mfg.lines', '🛤️', 'nav.mfg_lines', 'mfg.lines*', null],
            ['mfg.requirements', '🧮', 'nav.mfg_requirements', 'mfg.requirements*', null],
            ['mfg.trace', '🔎', 'nav.mfg_trace', 'mfg.trace*', null],
            ['mfg.variance', '📉', 'nav.mfg_variance', 'mfg.variance*', null],
        ],

        // ═══ ٥. المشتريات — البضاعة داخلة ═══
        'nav.group_purchasing' => [
            ['erp.purchase_requests', '📝', 'supplier.purchase_requests', 'erp.purchase_requests*', null],
            ['erp.suppliers', '🤝', 'supplier.suppliers', 'erp.suppliers*', null],
            ['erp.purchasing', '🧺', 'supplier.purchase_orders', 'erp.purchasing*', null],
            ['erp.purchasing.returns', '↩️', 'supplier.supplier_returns', 'erp.purchasing.returns*', null],
            ['erp.supplier_contracts', '📜', 'supplier.contracts', 'erp.supplier_contracts*', null],
        ],

        // ═══ الأونلاين — أوردرات شوبيفاي (٣/٩): الترتيب هو الفلو
        // نفسه: سينك ← تجهيز ← جاهز ← بيك اب ← تحصيل ← الكل ←
        // حسابات ← ربط المنتجات ═══
        'nav.group_online' => [
            ['online.sync', '🔄', 'online.nav_sync', 'online.sync*', null],
            ['online.prep', '📦', 'online.nav_prep', 'online.prep*', null],
            ['online.ready', '🚚', 'online.nav_ready', 'online.ready', null],
            ['online.pickups', '📋', 'online.nav_pickups', 'online.pickup*', null],
            ['online.collections', '💰', 'online.nav_collections', 'online.collections', null],
            ['online.returns', '↩️', 'online.nav_returns', 'online.returns', null],
            ['online.orders', '🧾', 'online.nav_orders', 'online.orders', null],
            ['online.accounts', '🧮', 'online.nav_accounts', 'online.accounts', null],
            ['online.products', '🔗', 'online.nav_products', 'online.products*', null],
        ],

        // ═══ التارجت والحوافز (ترتيب المنيو ٢٢/٩) ═══
        // كانوا متفرقين: التارجت ولوحة الأداء تحت «الميدان»، الـKPI تحت «التقارير»،
        // وإعدادات الحوافز تحت «الإعدادات» — اللي بيظبط تارجت بيلفّ على 3 أقسام.
        'nav.group_targets' => [
            // الحوافز (2026-08-06): التارجتات الشهرية + لوحة الأداء
            // ⚠️ النمط بقى بالظبط مش `erp.targets*` — عشان صفحات
            // التارجيت السنوي (`erp.targets.annual.*`) ماتنوّرش
            // اللينكين مع بعض. راوتات POST بتاعة الشاشة دي
            // (`targets.save`/`targets.copy`) مش صفحات أصلاً.
            ['erp.targets', '🎯', 'nav.targets', 'erp.targets', null],
            // التارجيت السنوي الهرمي (١١/٨): شركة ← مديرين ← مناديب ← عملاء
            ['erp.targets.annual', '📈', 'nav.targets_annual', 'erp.targets.annual*', null],
            ['erp.performance', '🏆', 'nav.performance', 'erp.performance*', null],
            // مركز التقارير الجديد (٢١/٨) هو المدخل — والمالية القديمة
            // (الأعمار وسيركل كيه...) لينكات جواه
            // العمولات والـKPI (٢٣/٨) — حاسبة الشهر + إعدادات النسب
            ['erp.kpi', '🎯', 'nav.kpi', 'erp.kpi*', null],
            // إعدادات الحوافز: شرايح العمولة وقيم النقاط ونطاق الليد
            ['erp.incentives', '🏅', 'nav.incentives', 'erp.incentives*', null],
        ],

        // ═══ ٦. التقارير — كلها في مكان واحد ═══
        'nav.group_reports' => [
            ['erp.reports.hub', '📑', 'nav.reports', 'erp.reports*', null],
        ],

        // ═══ إدارة المهام (٢٦/٨) — تاب مستقل (طلب المالك)، رولز
        // الداش بورد بس: الرؤية من SCREENS والراوت بيمنع الباقي ═══
        'nav.group_tasks' => [
            ['erp.tasks', '📋', 'nav.tasks', 'erp.tasks', null],
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
            // ملف الموظف (Lane 7) — باقي شاشات الموارد البشرية بتتزوّد مع مهامها
            ['hr.employees', '🪪', 'hrm.nav_employees', 'hr.employees*', null],
            ['hr.attendance.supervisor', '👷', 'hrm.nav_supervisor', 'hr.attendance.supervisor*', null],
            ['hr.attendance.punches', '📍', 'hrm.nav_punches', 'hr.attendance.punches*', null],
            // مكنة البصمة (٧/١١): كشف اليوم والشهر، الأذونات بالساعة، وأجهزة البصمة
            ['hr.attendance.daily', '🗓️', 'fp.nav_daily', 'hr.attendance.daily*', null],
            ['hr.attendance.monthly', '📆', 'fp.nav_monthly', 'hr.attendance.monthly*', null],
            ['hr.leave', '🌴', 'hrm.nav_leave', 'hr.leave*', null],
            ['hr.permissions', '⏱️', 'fp.nav_permissions', 'hr.permissions*', null],
            ['hr.fingerprint', '🖐️', 'fp.nav_devices', 'hr.fingerprint*', null],
            ['hr.advances', '💵', 'hrm.nav_advances', 'hr.advances*', null],
            ['hr.payroll', '🧾', 'hrm.nav_payroll', 'hr.payroll*', null],
            ['hr.settings', '⚙️', 'hrm.nav_settings', 'hr.settings*', null],
        ],

        'nav.group_settings' => [
            ['erp.team', '🧑‍💼', 'nav.team', 'erp.team', null],
            ['erp.zones', '📍', 'team.zones_and_govs', 'erp.zones*', null],
            ['erp.branches', '🏢', 'nav.branches', 'erp.branches', null],
            ['erp.vehicles', '🚚', 'nav.vehicles', 'erp.vehicles*', null],
            ['erp.import', '📥', 'nav.import', 'erp.import*', null],
            ['erp.tax.settings', '⚙️', 'nav.tax', 'erp.tax*', null],
            // ⚠️ الحضور والانصراف اتنقل لمجموعته المستقلة `group_hr`
            // (قرار المالك ٩/٨) — متحطهوش هنا تاني.
            // إصدار الأبلكيشن: رفع APK وإجبار التحديث
            ['erp.app_version', '📲', 'nav.app_version', 'erp.app_version*', null],
            ['erp.perms', '🔐', 'perm.permissions', 'erp.perms*', null],
            // مراجعة مساعد بروماكس (٧/٩) — أدمن بس (الراوت role:admin
            // والرؤية بتختفي لباقي الرولز من SCREENS تلقائياً)
            ['erp.agent.runs', '🤖', 'nav.agent_runs', 'erp.agent*', null],
            // (٢٢/٩) مركز نشاط المستخدمين — بدل «سجل الحركة» القديم. `erp.audit` لسه
            // راوت شغال بيحوّل هنا، ومش في المنيو عشان بادئته ماتمسكش `erp.audit.chains`
            ['erp.activity', '🟢', 'activity.page', 'erp.activity*', null],
            // (٥/١١) وقت الشغل الفعلي — جاد بس (`WorkTimeGate`)
            ['erp.worktime', '⏱', 'worktime.page', 'erp.worktime*', null],
        ],

        // ═══ تسليم النظام (الإغلاق ١٩/١٠) — الأدمن ومستلم النظام بس ═══
        // ⚠️ المجموعة دي نفسها بتتولّد موديول في الأداة (`promax:handover-seed`).
        'nav.group_handover' => [
            ['handover.index', '✅', 'nav.handover_tracker', 'handover*', null],
        ],

        // ═══ 📖 الدليل (٢١/١٠/٢٠٢٦) — كتاب واحد آخر المنيو لكل رولز الويب ═══
        // موقع التدريب والتسليم الساكن ورا اللوجين (`DocsController`). بيفتح في
        // نفس التاب، وزرار الرجوع بيرجّع للشاشة. زرار مساعدة كل شاشة بعدين:
        // `/docs#/screen/<S-id>`. ⚠️ مستلم النظام مالوش الدليل عن قصد —
        // خريطته «شاشات التسليم بس»، والصور فيها أسامي عملاء حقيقية.
        'nav.group_docs' => [
            ['docs.index', '📖', 'nav.docs', 'docs.*', null],
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
        'act.clients.create' => ['perm.act_clients_create', 'erp.clients', ['manager', 'branch_manager'], ['erp.clients.new', 'erp.clients.store', 'erp.clients.clone', 'erp.clients.dupes']],
        'act.clients.edit' => ['perm.act_clients_edit', 'erp.clients', ['manager', 'branch_manager'], ['erp.clients.edit', 'erp.clients.update']],
        'act.clients.collect' => ['perm.act_clients_collect', 'erp.clients', ['manager', 'branch_manager', 'accountant'], ['erp.clients.collect', 'erp.clients.opening']],
        'act.clients.activate' => ['perm.act_clients_activate', 'erp.clients.activate', ['manager'], ['erp.clients.activate.do', 'erp.clients.deactivate']],
        'act.contracts.manage' => ['perm.act_contracts_manage', 'erp.contracts', ['manager'], ['erp.contracts.store', 'erp.contracts.link', 'erp.contracts.destroy', 'erp.clauses.store', 'erp.clauses.destroy', 'erp.groups.contract']],
        // ⚠️ `null` كان بيوري مدير الفرع زراير التحويل والمسح والتوزيع
        // وكلها `role:admin,manager` — 403 أول ما يدوس (زحف ٨/٩)
        'act.leads.manage' => ['perm.act_leads_manage', 'erp.leads', ['manager'], ['erp.leads.store', 'erp.leads.update', 'erp.leads.convert', 'erp.leads.delete', 'erp.leads.dupcheck', 'erp.leads.dupdecide', 'erp.leads.bulkset', 'erp.leads.bulk']],

        // ═══ المنتجات والتسعير ═══
        'act.products.edit' => ['perm.act_products_edit', 'erp.stock', ['manager'], ['erp.products.store', 'erp.products.update']],
        'act.prices.edit' => ['perm.act_prices_edit', 'erp.prices', ['manager'], ['erp.prices.store', 'erp.prices.update', 'erp.prices.save', 'erp.prices.bulk']],
        // تطبيق تعديل الأسعار على الفواتير وأوامر التوريد + إعادة المزامنة — أدمن بس (Lane 13)
        'act.prices.propagate' => ['perm.act_prices_propagate', 'erp.prices', [], ['erp.prices.change.apply', 'erp.prices.change.resync']],
        'act.prices.activate' => ['perm.act_prices_activate', 'erp.prices', ['manager'], ['erp.prices.activate', 'erp.prices.deactivate', 'erp.prices.default']],

        // ═══ المخزن ═══
        'act.wh.receive' => ['perm.act_wh_receive', 'wh.receipts', ['manager', 'warehouse_keeper'], ['wh.receipts.store', 'wh.receipts.import', 'wh.batch.update']],
        'act.wh.putaway' => ['perm.act_wh_putaway', 'wh.locations', ['manager', 'warehouse_keeper'], ['wh.putaway', 'wh.putaway.bulk', 'wh.receipt.putaway', 'wh.receipt.putaway.save', 'wh.move', 'wh.locations.store']],
        // ⚠️ إنشاء التحويل `role:admin,manager`، والاستلام مفتوح لأمين المخزن كمان —
        // كانوا مفتاح واحد بـ`null` فمدير الفرع كان شايف «تحويل جديد» وبيترفض
        'act.wh.transfer' => ['perm.act_wh_transfer', 'wh.transfers', ['manager'], ['wh.transfers.store', 'wh.transfers.new']],
        'act.wh.transfer_receive' => ['perm.act_wh_transfer_receive', 'wh.transfers', ['manager', 'warehouse_keeper'], ['wh.transfers.receive']],
        // ═══ تحويل من عربية مندوب (١٤/٨) ═══
        // ⚠️ **أكشن مستقل عن تحويل المخازن عن قصد.** ده بيسحب بضاعة
        // من عهدة مندوب ويغيّر أرقام تصفيته — قرار إداري مش شغل مخزن
        // يومي. `['manager']` = الأدمن والمدير، والأدمن يقدر يمنحه
        // لأمين مخزن عشان يستقبل في مخزنه هو (`guardWarehouse`).
        'act.wh.van_transfer' => ['perm.act_wh_van_transfer', 'wh.transfers', ['manager'], ['wh.transfers.van', 'wh.transfers.van.store']],
        // أمين المخزن بيعدّ (`record`)؛ فتح الجرد واعتماده وإلغاؤه `role:admin,manager`
        'act.wh.count' => ['perm.act_wh_count', 'wh.counts', ['manager', 'warehouse_keeper'], ['wh.count.record']],
        'act.wh.count_manage' => ['perm.act_wh_count_manage', 'wh.counts', ['manager'], ['wh.counts.store', 'wh.count.approve', 'wh.count.cancel']],
        // أمين المخزن بيجهّز؛ رفع أمر تجهيز من PO/ريفيل وإلغاؤه `role:admin,manager`
        'act.wh.pick' => ['perm.act_wh_pick', 'wh.picks', ['manager', 'warehouse_keeper'], ['wh.picks.start', 'wh.picks.ready', 'wh.picks.update']],
        'act.wh.pick_raise' => ['perm.act_wh_pick_raise', 'wh.picks', ['manager'], ['wh.picks.cancel', 'wh.picks.po', 'wh.picks.rpl']],
        'act.warehouses.manage' => ['perm.act_warehouses_manage', 'erp.warehouses', ['manager'], ['erp.warehouses.store', 'erp.warehouses.update', 'erp.warehouses.stock.save']],

        // ═══ العهدة وتوريد الكي أكاونت ═══
        'act.custody.handout' => ['perm.act_custody_handout', 'ops.handout', null, ['ops.handout.store']],
        // تصحيح إداري للعهدة (١٢/٨) — `[]` = أدمن بس، والأدمن يقدر
        // يمنحه لحد بعينه. بيحرّك العهدة والأرفف مع بعض.
        'act.custody.adjust' => ['perm.act_custody_adjust', 'ops.vans', [], ['ops.rep.adjust']],
        'act.ka.create' => ['perm.act_ka_create', 'ops.po.handout', ['manager'], ['ops.pos.store', 'ops.pos.assign', 'ops.pos.reassign', 'ops.pos.cancel', 'ops.po.import', 'ops.po.import.preview', 'ops.po.import.store', 'ops.po.import.one']],
        'act.ka.decide' => ['perm.act_ka_decide', 'ops.po.approvals', ['accountant'], ['ops.po.decide', 'ops.po.decide.all']],
        'act.ka.edit' => ['perm.act_ka_edit', 'ops.po.approvals', ['manager', 'accountant'], ['ops.po.edit', 'ops.po.update']],

        // ═══ الميدان ═══
        // ⚠️ كتابة الخريطة الجغرافية (`ops.geo.plan/unplan`) تحت نفس
        // الأكشن — هي نفس القرار بالظبط (مين بيزور مين وإمتى) من شاشة
        // تانية. أكشن منفصل كان معناه إن الأدمن يمنع الزرار في شاشة
        // ويسيبه مفتوح في التانية من غير ما ياخد باله.
        'act.field.plan' => ['perm.act_field_plan', 'ops.assignments', null, ['ops.assignments.assign', 'ops.assignments.unassign', 'ops.geo.save', 'ops.portfolio.bulk', 'ops.portfolio.bulkset', 'ops.geo.distribute', 'ops.geo.assign', 'ops.assignments.apply', 'ops.assignments.auto', 'ops.journeys.copy', 'ops.journeys.sync']],
        // مسح خطوط السير كلها (فريقه بس لغير الأدمن) — مفتاح لوحده لأنه هدّام.
        // ⚠️ `['manager']` مش `null`: مايتورثش من مجرد رؤية الصفحة، لازم منح صريح
        'act.field.wipe' => ['perm.act_field_wipe', 'ops.journeys', ['manager'], ['ops.journeys.wipe']],
        'act.field.decide' => ['perm.act_field_decide', 'ops.requests', ['manager'], ['ops.requests.decide', 'ops.replenishments.assign', 'ops.replenishments.cancel', 'ops.replenishments.update', 'ops.rep.close', 'ops.requests.revise']],

        // ═══ الموردين والمشتريات ═══
        'act.suppliers.manage' => ['perm.act_suppliers_manage', 'erp.suppliers', ['manager'], ['erp.suppliers.store', 'erp.suppliers.update', 'erp.suppliers.opening']],
        'act.suppliers.pay' => ['perm.act_suppliers_pay', 'erp.suppliers', ['manager', 'accountant'], ['erp.suppliers.pay']],
        'act.purchasing.manage' => ['perm.act_purchasing_manage', 'erp.purchasing', ['manager'], ['erp.purchasing.new', 'erp.purchasing.store', 'erp.purchasing.invoice', 'erp.purchasing.cancel', 'erp.purchasing.close']],
        'act.supplier_contracts.manage' => ['perm.act_supplier_contracts_manage', 'erp.supplier_contracts', ['manager'], ['erp.supplier_contracts.store', 'erp.supplier_contracts.update', 'erp.supplier_contracts.items']],
        'act.purchasing.approve' => ['perm.act_purchasing_approve', 'erp.purchasing', [], ['erp.purchasing.approve', 'erp.purchasing.reject']],
        'act.purchase_requests.create' => ['perm.act_purchase_requests_create', 'erp.purchase_requests', ['manager', 'warehouse_keeper'], ['erp.purchase_requests.store', 'erp.purchase_requests.cancel']],
        'act.purchase_requests.quote' => ['perm.act_purchase_requests_quote', 'erp.purchase_requests', ['manager'], ['erp.purchase_requests.quotes.store']],
        'act.purchase_quotes.approve' => ['perm.act_purchase_quotes_approve', 'erp.purchase_requests', [], ['erp.purchase_quotes.approve']],
        'act.purchasing.receive' => ['perm.act_purchasing_receive', 'erp.purchasing', null, ['erp.purchasing.receive']],

        // ═══ الفلوس ═══
        'act.money.dues' => ['perm.act_money_dues', 'erp.dues', ['manager', 'accountant'], ['erp.dues.generate', 'erp.dues.settle', 'erp.dues.waive']],
        'act.money.eta' => ['perm.act_money_eta', 'erp.eta', ['accountant'], ['erp.eta.export', 'erp.eta.submitted', 'erp.tax.settings.save']],
        // ═══ الحسابات العامة (١١/٩/٢٠٢٦) ═══
        // ⚠️ مفتاح واحد لكل الكتابة في الدفتر: سند مصروف، حركة نقدية،
        // وإلغاؤهم. منع المفتاح بيخلي الشاشة **قراءة** من غير ما نخفيها —
        // المحاسب الجديد يشوف السندات قبل ما ياخد حق تسجيلها.
        // (١٢/٩) اتزوّد عليه: القيد اليدوي، تحويل حساب السطر (✎)، إضافة
        // وتعديل حساب في الشجرة، وقفل الفترة — كلها كتابة في نفس الدفتر.
        'act.gl.post' => ['perm.act_gl_post', 'gl.expenses', ['accountant'], [
            'gl.expenses.store', 'gl.expenses.void', 'gl.cash.store', 'gl.cash.void',
            'gl.entries.store', 'gl.entries.override',
            'gl.accounts.store', 'gl.accounts.update', 'gl.periods.close',
            // Lane 4: سجل الخزن والبنوك
            'gl.safes.store', 'gl.safes.update', 'gl.safes.counts.store', 'gl.safes.counts.void',
            'gl.recon.store', 'gl.recon.mark', 'gl.recon.close',
            // L4-D12: أصل افتتاحي يدوي (صف من غير قيد)
            'gl.assets.store',
            // L4-D7: دفع سند معتمد
            'gl.expenses.pay',
        ]],
        // L4-D7: اعتماد/رفض المصروف — أدمن بس (`[]`)، وبيتمنح ليوزر أو
        // رول من شاشة الصلاحيات (أصحاب الاعتماد بيتحددوا من غير كود)
        'act.gl.expense_approve' => ['perm.act_gl_expense_approve', 'gl.expenses', [], ['gl.expenses.approve', 'gl.expenses.reject']],
        // ⚠️ **أدمن بس** (`[]`): القواعد بتحدد كل قيد جاي، وفتح فترة
        // مقفولة بيرجّع شهر معتمد للتعديل، وإعادة البناء بتمسح كل القيود
        // الآلية وتولّدها تاني. الشاشة نفسها (`gl.settings`) مفتوحة
        // للمحاسب يقرا منها — الكتابة هي اللي مقفولة.
        'act.gl.admin' => ['perm.act_gl_admin', 'gl.settings', [], [
            'gl.settings.rules', 'gl.settings.general', 'gl.periods.reopen',
            'gl.rebuild.preview', 'gl.rebuild', 'gl.coa.accept', 'gl.coa.adopt',
            'gl.mapping.keys', 'gl.mapping.rules',
            // معالج التشغيل (الخط ٣)
            'gl.golive.confirm', 'gl.golive.start', 'gl.golive.centers.add', 'gl.golive.centers.seed',
            'gl.golive.safes.link', 'gl.golive.opening.lines.add', 'gl.golive.opening.lines.delete',
            'gl.golive.opening.client', 'gl.golive.opening.supplier', 'gl.golive.opening.post',
            'gl.golive.preview', 'gl.golive.preview.approve',
            // الخط ٩: خطوة ٧ والتشغيل الفعلي
            'gl.golive.costing.save', 'gl.golive.costing.seed', 'gl.golive.run',
            // L4-D8: بنود المصروف
            'gl.expense_categories.store', 'gl.expense_categories.update',
            // L4-D11: تشغيل الإهلاك الشهري
            'gl.assets.depreciate',
        ]],

        // الخط ٩: قفل شهر التكاليف وإعادة حساب الطبقة — أدمن بس
        'act.costing.admin' => ['perm.act_costing_admin', 'costing.overhead', [], ['costing.overhead.close', 'costing.sync', 'costing.cards.approve']],

        // ═══ الإعدادات ═══
        'act.team.manage' => ['perm.act_team_manage', 'erp.team', [], ['erp.team.store', 'erp.team.update', 'erp.team.password', 'erp.channels.manager']],
        // ⚠️ اتقسم (٨/٩): مدير الفرع بيدير **مناطق** فرعه (`role:…,branch_manager`)
        // بس المحافظات والفروع والعربيات والسلاسل والقنوات `role:admin,manager` —
        // المفتاح الواحد كان بيوريه فورم «محافظة جديدة» و«سلسلة جديدة» وبيترفض
        'act.org.manage' => ['perm.act_org_manage', 'erp.zones', ['manager', 'branch_manager'], ['erp.zones.store', 'erp.zones.update', 'erp.groups.quick', 'erp.zones.quick']],
        'act.org.structure' => ['perm.act_org_structure', 'erp.zones', ['manager'], ['erp.govs.store', 'erp.govs.update', 'erp.branches.store', 'erp.branches.update', 'erp.vehicles.store', 'erp.vehicles.update', 'erp.groups.store', 'erp.groups.update', 'erp.groups.destroy', 'erp.groups.attach', 'erp.mother_companies.store', 'erp.mother_companies.update', 'erp.mother_companies.destroy', 'erp.mother_companies.attach', 'erp.channels.update']],
        'act.import.run' => ['perm.act_import_run', 'erp.import', [], ['erp.import.upload', 'erp.import.apply']],

        // ═══ تسليم النظام (١٩/١٠) — حالة التيست واللايف والديدلاين والملاحظات قرار المالك ═══
        'act.handover.meta' => ['perm.act_handover_meta', 'handover.index', [], ['handover.index.meta']],

        // ═══ التصنيع (١٩/١٠) — المواصفات: البحث والتطوير بيكتب، الاعتماد أدمن بس (D1) ═══
        'act.mfg.specs_edit' => ['perm.act_mfg_specs_edit', 'mfg.specs', ['rnd'], ['mfg.specs.store', 'mfg.specs.update', 'mfg.specs.submit']],
        'act.mfg.specs_approve' => ['perm.act_mfg_specs_approve', 'mfg.specs', [], ['mfg.specs.approve', 'mfg.specs.send_back']],
        // ═══ التصنيع — قوايم المواد: البحث والتطوير بيكتب، الاعتماد أدمن بس (D1) ═══
        'act.mfg.boms_edit' => ['perm.act_mfg_boms_edit', 'mfg.boms', ['rnd'], ['mfg.boms.store', 'mfg.boms.lines']],
        'act.mfg.boms_approve' => ['perm.act_mfg_boms_approve', 'mfg.boms', [], ['mfg.boms.approve']],
        // ═══ التصنيع — خطوط الإنتاج وأوامر الإنتاج: مدير الإنتاج؛ مخازن التصنيع الافتراضية أدمن بس ═══
        'act.mfg.lines_manage' => ['perm.act_mfg_lines_manage', 'mfg.lines', ['production_manager'], ['mfg.lines.store', 'mfg.lines.update']],
        'act.mfg.lines_settings' => ['perm.act_mfg_lines_settings', 'mfg.lines', [], ['mfg.lines.settings']],
        'act.mfg.orders_manage' => ['perm.act_mfg_orders_manage', 'mfg.orders', ['production_manager'], ['mfg.orders.store', 'mfg.orders.start', 'mfg.orders.finish', 'mfg.orders.cancel', 'mfg.orders.shortage', 'mfg.orders.close']],
        // ═══ التصنيع — صرف الخامات والمرتجع: مدير الإنتاج وأمين المخزن ═══
        'act.mfg.issue' => ['perm.act_mfg_issue', 'mfg.orders', ['production_manager', 'warehouse_keeper'], ['mfg.orders.issue', 'mfg.orders.issue_standard', 'mfg.orders.return']],
        // ═══ التصنيع — قرار الجودة على باتش التام (إفراج/رفض): الجودة بس (D7) ═══
        'act.mfg.qc_decide' => ['perm.act_mfg_qc_decide', 'mfg.qc', ['quality'], ['mfg.qc.decide']],
        // ═══ الموارد البشرية (Lane 7) ═══
        'act.hr.employees' => ['perm.act_hr_employees', 'hr.employees', ['hr'], ['hr.employees.store', 'hr.employees.update']],
        'act.hr.leave_balances' => ['perm.act_hr_leave_balances', 'hr.leave.balances', ['hr'], ['hr.leave.balances.update', 'hr.leave.balances.seed']],
        'act.hr.advances' => ['perm.act_hr_advances', 'hr.advances', ['hr'], ['hr.advances.store', 'hr.advances.void', 'hr.penalties.store', 'hr.penalties.delete']],
        'act.hr.payroll' => ['perm.act_hr_payroll', 'hr.payroll', ['hr'], ['hr.payroll.draft', 'hr.payroll.line']],
        // ⚠️ أدمن بس: الاعتماد بيرحّل قيد المرتبات
        'act.hr.payroll_approve' => ['perm.act_hr_payroll_approve', 'hr.payroll', [], ['hr.payroll.approve']],
        // الصرف: المحاسب أو الأدمن (قرار المالك ١٩/١٠)
        'act.hr.payroll_pay' => ['perm.act_hr_payroll_pay', 'hr.payroll', ['accountant'], ['hr.payroll.pay']],
        // ⚠️ أدمن بس: نسب التأمينات وشرايح الضريبة بتأثر على كل مرتب
        'act.hr.settings' => ['perm.act_hr_settings', 'hr.settings', [], ['hr.settings.save']],

        // ═══ جرد الأزرار (٩/١١) — كل راوت كتابة تحت صفحة بقى له مفتاح ═══
        // ⚠️ الافتراضي منسوخ من `role:` اللي على الراوت بالظبط (`[]` = أدمن بس،
        // `null` = نفس جمهور الصفحة) — من غير أي استثناء محدش سلوكه بيتغير.
        // العملاء والمراجعة
        'act.clients.delete' => ['perm.act_clients_delete', 'erp.clients', [], ['erp.clients.destroy', 'erp.clients.visits.purge']],
        'act.clients.geo' => ['perm.act_clients_geo', 'erp.clients.activate', null, ['erp.clients.geo']],
        'act.locations.confirm' => ['perm.act_locations_confirm', 'erp.client_locations', ['manager', 'branch_manager'], ['erp.client_locations.confirm']],
        'act.divisions.assign' => ['perm.act_divisions_assign', 'erp.divisions', ['manager'], ['erp.divisions.assign']],
        'act.managers.assign' => ['perm.act_managers_assign', 'erp.managers.clients', [], ['erp.managers.assign', 'erp.managers.unassign', 'erp.managers.team.assign', 'erp.managers.team.unassign', 'erp.managers.clients.repair']],
        'act.setup.save' => ['perm.act_setup_save', 'erp.setup.clients', null, ['erp.setup.chains.save', 'erp.setup.clients.save']],
        'act.audit.review' => ['perm.act_audit_review', 'erp.audit.clients', null, ['erp.audit.save', 'erp.audit.confirm', 'erp.audit.statement.delete']],
        'act.review.mark' => ['perm.act_review_mark', 'erp.review', null, ['erp.review.mark', 'erp.review.opening']],
        'act.review.stock_reset' => ['perm.act_review_stock_reset', 'erp.review', [], ['erp.review.stock.zero', 'erp.review.stock.batch']],
        // المراجعة مستند مستند (١١/١١): العلامة · التصليح في المكان (فلوس) · فتح ✓ تاني (أدمن)
        'act.review.docs' => ['perm.act_review_docs', 'erp.review.docs', null, ['erp.review.docs.mark', 'erp.review.docs.undo']],
        'act.review.docs_edit' => ['perm.act_review_docs_edit', 'erp.review.docs', null, ['erp.review.docs.serial', 'erp.review.docs.proof', 'erp.review.docs.rep', 'erp.review.docs.date', 'erp.review.docs.preview', 'erp.review.docs.lines', 'erp.review.docs.delivered', 'erp.review.docs.retprice']],
        'act.review.docs_reopen' => ['perm.act_review_docs_reopen', 'erp.review.docs', [], ['erp.review.docs.reopen']],
        'act.review.docs_void' => ['perm.act_review_docs_void', 'erp.review.docs', [], ['erp.review.docs.void']],
        'act.leads.clear_assign' => ['perm.act_leads_clear_assign', 'erp.leads', [], ['erp.leads.clearassign']],
        'act.leads.plan' => ['perm.act_leads_plan', 'erp.leads.planner', null, ['erp.leads.planner.save', 'erp.leads.route.save']],
        'act.tasks.create' => ['perm.act_tasks_create', 'erp.tasks', null, ['erp.tasks.store']],
        // المنتجات والأسعار
        'act.products.delete' => ['perm.act_products_delete', 'erp.stock', [], ['erp.products.destroy']],
        'act.families.manage' => ['perm.act_families_manage', 'erp.families', null, ['erp.families.save', 'erp.families.assign']],
        // مسودة تعديل الأسعار: الكنترولر بيفحص صاحب المسودة
        'act.prices.change' => ['perm.act_prices_change', 'erp.prices', null, ['erp.prices.change.refresh', 'erp.prices.change.master', 'erp.prices.change.cancel']],
        'act.quotations.save' => ['perm.act_quotations_save', 'erp.reports.quotations', null, ['erp.reports.quotation', 'erp.reports.quotation.print', 'erp.reports.quotations.edit', 'erp.reports.quotations.update']],
        // الميدان والعهدة والتوريد
        'act.field.force_out' => ['perm.act_field_force_out', 'ops.open_visits', null, ['ops.open_visits.out', 'ops.open_visits.wh_out', 'ops.att.force_out']],
        'act.field.request_delete' => ['perm.act_field_request_delete', 'ops.requests', [], ['ops.requests.destroy']],
        'act.field.gps' => ['perm.act_field_gps', 'ops.gps', [], ['ops.gps.credentials', 'ops.gps.poll', 'ops.gps.save', 'ops.gps.sync']],
        'act.custody.clear' => ['perm.act_custody_clear', 'ops.vans', [], ['ops.rep.clear']],
        // تصحيح كمية باتش بعد الاستلام — أدمن بس (`role:admin` فوق بوابة المخزن)
        'act.wh.fix_qty' => ['perm.act_wh_fix_qty', 'wh.receipts', [], ['wh.batch.fixqty']],
        'act.ka.reprice' => ['perm.act_ka_reprice', 'ops.pos', ['manager'], ['ops.pos.reprice', 'ops.pos.reprice.check', 'ops.pos.reprice.bulk']],
        // الفلوس
        'act.invoices.edit' => ['perm.act_invoices_edit', 'ops.invoices', [], ['ops.invoices.renumber', 'ops.invoices.items', 'ops.invoices.paper', 'ops.invoices.payment', 'ops.invoices.reassign', 'ops.invoices.redate', 'ops.invoices.reprice']],
        'act.invoices.delete' => ['perm.act_invoices_delete', 'ops.invoices', [], ['ops.invoices.destroy']],
        'act.returns.create' => ['perm.act_returns_create', 'ops.returns', ['manager', 'accountant'], ['ops.returns.new', 'ops.returns.store']],
        'act.returns.delete' => ['perm.act_returns_delete', 'ops.returns', [], ['ops.returns.destroy']],
        'act.money.manual' => ['perm.act_money_manual', 'ops.manual', null, ['ops.manual.invoice', 'ops.manual.return', 'ops.manual.collection', 'ops.manual.gift']],
        'act.repclose.close' => ['perm.act_repclose_close', 'erp.repclose', null, ['erp.repclose.store']],
        'act.repclose.delete' => ['perm.act_repclose_delete', 'erp.repclose', [], ['erp.repclose.destroy']],
        'act.dayclose.close' => ['perm.act_dayclose_close', 'erp.dayclose', null, ['erp.dayclose.store']],
        'act.gl.coa_draft' => ['perm.act_gl_coa_draft', 'gl.coa', null, ['gl.coa.store', 'gl.coa.import', 'gl.coa.update', 'gl.coa.destroy', 'gl.coa.move']],
        // الأونلاين
        'act.online.sync' => ['perm.act_online_sync', 'online.sync', null, ['online.sync.run']],
        'act.online.sync_settings' => ['perm.act_online_sync_settings', 'online.sync', [], ['online.sync.settings', 'online.reset']],
        'act.online.prep' => ['perm.act_online_prep', 'online.prep', null, ['online.prep.start', 'online.prep.review', 'online.prep.done']],
        'act.online.orders' => ['perm.act_online_orders', 'online.orders', ['manager'], ['online.confirm', 'online.ship', 'online.cancel', 'online.postpone', 'online.reship', 'online.return', 'online.relink', 'online.pickup.push', 'online.couriers.store', 'online.item.link']],
        'act.online.manual_ship' => ['perm.act_online_manual_ship', 'online.orders', [], ['online.manualship']],
        'act.online.collect' => ['perm.act_online_collect', 'online.collections', null, ['online.collect']],
        'act.online.products' => ['perm.act_online_products', 'online.products', null, ['online.products.fetch', 'online.products.save', 'online.products.bundle', 'online.products.delete', 'online.products.prune']],
        // التارجت والحوافز
        'act.targets.edit' => ['perm.act_targets_edit', 'erp.targets', null, ['erp.targets.save', 'erp.targets.copy']],
        'act.targets.annual' => ['perm.act_targets_annual', 'erp.targets.annual', null, ['erp.targets.annual.clients', 'erp.targets.annual.reps', 'erp.targets.annual.rebalance']],
        'act.targets.annual_company' => ['perm.act_targets_annual_company', 'erp.targets.annual', [], ['erp.targets.annual.company', 'erp.targets.annual.managers', 'erp.targets.annual.manual']],
        'act.performance.points' => ['perm.act_performance_points', 'erp.performance', ['manager'], ['erp.performance.points']],
        'act.kpi.inputs' => ['perm.act_kpi_inputs', 'erp.kpi', null, ['erp.kpi.inputs']],
        // ⚠️ بيغطي شاشة الإعداد نفسها كمان — منح الحفظ من غير الشاشة مالوش معنى
        'act.kpi.setup' => ['perm.act_kpi_setup', 'erp.kpi', [], ['erp.kpi.setup', 'erp.kpi.setup.save']],
        'act.incentives.manage' => ['perm.act_incentives_manage', 'erp.incentives', [], ['erp.incentives.save']],
        // الحضور والموارد البشرية
        'act.attendance.approve' => ['perm.act_attendance_approve', 'erp.attendance.review', ['manager'], ['erp.attendance.approve']],
        'act.hr.supervisor_punch' => ['perm.act_hr_supervisor_punch', 'hr.attendance.supervisor', null, ['hr.attendance.supervisor.punch']],
        // اعتماد إجازات وأذونات الفريق — الكنترولر بيفحص `isManagedBy`
        'act.hr.leave_decide' => ['perm.act_hr_leave_decide', 'hr.leave', null, ['hr.leave.decide', 'hr.permissions.decide']],
        'act.hr.fingerprint' => ['perm.act_hr_fingerprint', 'hr.fingerprint', null, ['hr.fingerprint.store', 'hr.fingerprint.update', 'hr.fingerprint.command', 'hr.fingerprint.map', 'hr.fingerprint.settings']],
        // الإعدادات والتسليم
        'act.app_version.manage' => ['perm.act_app_version_manage', 'erp.app_version', [], ['erp.app_version.save', 'erp.app_version.chunk']],
        'act.handover.mark' => ['perm.act_handover_mark', 'handover.index', null, ['handover.index.mark', 'handover.index.unmark', 'handover.index.mark_all', 'handover.index.unmark_all']],
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

        // ⚠️ (٥/١١) «وقت الشغل الفعلي» لجاد بس — قبل خروج الأدمن عن قصد،
        // فلا أدمن تاني يشوف اللينك ولا شاشة الصلاحيات تقدر تمنحها
        if (WorkTimeGate::covers($routeName)) {
            return WorkTimeGate::allows($user);
        }

        // صفحة «مفيش شاشات» (٩/١١) — آخر محطة لـ`home()`، لازم تفتح لأي حد
        if ($routeName === self::NO_SCREENS) {
            return true;
        }

        // ⚠️ الأدمن بيعدّي من غير ما يبص على الخريطة ولا الاستثناءات —
        // «الأدمن معاه كل أوبشن». أي تعداد لشاشاته هيبقى ناقص.
        if ($user->role === 'admin') {
            return true;
        }

        // ⚠️ المقفول (٩/١١): مفيش صف استثناء يفتحه — افتراضي الكود بس
        if (self::isLocked($routeName)) {
            return self::roleDefault($user->role, $routeName);
        }

        $decision = self::userOverride($user, $routeName);

        if ($decision !== null) {
            return $decision;
        }

        // ═══ الرول المخصص (٩/١١) — بين اليوزر والرول الأساسي ═══
        $decision = self::customOverride($user, $routeName);

        if ($decision !== null) {
            return $decision;
        }

        // ═══ استثناء الرول (٢٣/٨) — بين اليوزر والكود ═══
        // «المديرين يشوفوا عروض الأسعار» بتتقال مرة واحدة من شاشة
        // الصلاحيات بدل ما تتكرر على كل مدير.
        $decision = self::roleOverride($user->role, $routeName);

        if ($decision !== null) {
            return $decision;
        }

        // الراوت المغطّي بأكشن: افتراضي الأكشن (زي `action()` من غير صفوف)
        return self::baseDefault($user->role, $routeName, fn (string $page) => self::allows($user, $page));
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

        return self::resolveMap($user->permMap(), $routeName);
    }

    /**
     * استثناء الرول للراوت ده — نفس فكرة استثناء اليوزر بالظبط بس
     * من جدول `role_permissions` (شاشة الصلاحيات → تاب الرولز، ٢٣/٨).
     * `null` يعني «وراثة من الكود».
     *
     * ⚠️ **بيستخدمها كمان `EnsureRole`** — منح شاشة لرول من الشاشة
     * لازم يعدّي من بوابة `role:` اللي على الراوت، زي منح اليوزر.
     */
    public static function roleOverride(string $role, string $routeName): ?bool
    {
        if ($role === 'admin') {
            return null;
        }

        return self::resolveMap(\App\Models\RolePermission::mapFor($role), $routeName);
    }

    /** افتراضي الرول **بعد** استثناءات الرول — ده اللي اليوزر بيورثه */
    public static function roleEffective(string $role, string $routeName): bool
    {
        return self::roleOverride($role, $routeName)
            ?? self::baseDefault($role, $routeName, fn (string $page) => self::roleEffective($role, $page));
    }

    /**
     * استثناء الرول المخصص (`c:<id>`) لليوزر ده — null لو مالوش رول
     * مخصص فعّال أو مفيش صف. **بيستخدمها كمان `EnsureRole` و`LinkGuard`.**
     */
    public static function customOverride(?User $user, string $routeName): ?bool
    {
        if ($user === null || $user->role === 'admin') {
            return null;
        }

        $key = $user->permRoleKey();

        if ($key === null) {
            return null;
        }

        return self::resolveMap(\App\Models\RolePermission::mapFor($key), $routeName);
    }

    /**
     * الرول (أساسي أو `c:<id>`) بيوصل للراوت؟ — من غير طبقة اليوزر.
     * للمصفوفة والشيت والمقارنة: المخصص = خريطته ?? فعلي الرول الأساسي.
     */
    public static function effectiveFor(string $roleKey, string $routeName): bool
    {
        if (! str_starts_with($roleKey, 'c:')) {
            return self::roleEffective($roleKey, $routeName);
        }

        $custom = \App\Models\Role::fromKey($roleKey);

        if ($custom === null) {
            return false;
        }

        $base = (string) $custom->base_role;

        return self::resolveMap(\App\Models\RolePermission::mapFor($roleKey), $routeName)
            ?? self::roleOverride($base, $routeName)
            ?? self::baseDefault($base, $routeName, fn (string $page) => self::effectiveFor($roleKey, $page));
    }

    /** نفس `effectiveFor` للزراير — مخصص ← رول أساسي ← افتراضي الكود */
    public static function actionFor(string $roleKey, string $key): bool
    {
        if ($roleKey === 'admin') {
            return true;
        }

        $custom = str_starts_with($roleKey, 'c:') ? \App\Models\Role::fromKey($roleKey) : null;

        if (str_starts_with($roleKey, 'c:') && $custom === null) {
            return false;
        }

        $base = $custom !== null ? (string) $custom->base_role : $roleKey;

        if (! self::isLocked($key)) {
            if ($custom !== null) {
                $map = \App\Models\RolePermission::mapFor($roleKey);

                if (array_key_exists($key, $map)) {
                    return $map[$key];
                }
            }

            $map = \App\Models\RolePermission::mapFor($base);

            if (array_key_exists($key, $map)) {
                return $map[$key];
            }
        }

        return self::actionDefault($base, $key, fn (string $page) => self::effectiveFor($roleKey, $page));
    }

    /**
     * افتراضي الكود للزرار من ACTIONS — `$sees` بتقول الرول شايف صفحته ولا لأ.
     *
     * @param \Closure(string): bool $sees
     */
    private static function actionDefault(string $role, string $key, \Closure $sees): bool
    {
        $def = self::ACTIONS[$key] ?? null;

        if ($def === null) {
            return false;   // مفتاح مش متسجل = زرار محدش يشوفه — سجّله الأول
        }

        [, $page, $roles] = $def;

        if ($roles === []) {
            return false;   // أدمن بس
        }

        if ($roles !== null && ! in_array($role, $roles, true)) {
            return false;
        }

        return $sees($page);
    }

    /**
     * المفتاح ده مقفول على المصفوفة؟ — راوت تحت بادئة في LOCKED، أو
     * `act.` كل راوتاته مقفولة. `nav.` عمره ما يتقفل.
     */
    public static function isLocked(string $key): bool
    {
        if (str_starts_with($key, 'nav.')) {
            return false;
        }

        if (str_starts_with($key, 'act.')) {
            $covered = self::ACTIONS[$key][3] ?? [];

            if ($covered === []) {
                return false;
            }

            foreach ($covered as $route) {
                if (! self::isLocked($route)) {
                    return false;
                }
            }

            return true;
        }

        foreach (self::LOCKED as $prefix) {
            if (self::matches($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * حل خريطة استثناءات (يوزر أو رول) لراوت — المنطق الواحد المشترك.
     *
     * الترتيب (قرار المالك 2026-08-05): إخفاء القسم بيغلب الكل ←
     * أطول بادئة صفحة مطابقة ← منح القسم كله. الراوت المغطّي بأكشن
     * (قرار ٦، ٩/١١): إخفاء القسم ← صف الراوت بالظبط ← صف الأكشن.
     *
     * @param array<string, bool> $map
     */
    private static function resolveMap(array $map, string $routeName): ?bool
    {
        // ⚠️ المقفول مالوش استثناء من أي طبقة (يوزر/مخصص/رول) — هنا عشان
        // كل اللي بينادي الدالة (allows/EnsureRole/LinkGuard/المصفوفة) يلتزم
        if ($map === [] || self::isLocked($routeName)) {
            return null;
        }

        // ١. القسم كله متقفل؟ الإخفاء بيغلب أي حاجة تانية
        $group = self::groupOf($routeName);

        if ($group !== null && ($map[$group] ?? null) === false) {
            return false;
        }

        // ⚠️ راوت عليه أكشن (قرار ٦، ٩/١١): صف الراوت بالظبط أو صف الأكشن بس —
        // منح/منع الصفحة أو القسم مابيلمسوش، وإلا «الصفحة من غير الزرار» مستحيلة
        if (($action = self::actionKeyFor($routeName)) !== null) {
            if (array_key_exists($routeName, $map)) {
                return $map[$routeName];
            }

            return array_key_exists($action, $map) ? $map[$action] : null;
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

        // ٣. القسم كله ممنوح صراحةً
        if ($group !== null && ($map[$group] ?? null) === true) {
            return true;
        }

        return null;
    }

    /**
     * قرار طبقة واحدة (خريطة يوزر أو رول أساسي/مخصص) على مفتاح — قراءة بس،
     * لبادج المصدر في المصفوفة. نفس اللي `allows`/`action` بيبصّوا عليه في
     * الطبقة دي بالظبط؛ null = الطبقة مالهاش رأي (وراثة). القسم = صفه هو.
     *
     * @param array<string, bool> $map
     */
    public static function layerDecision(array $map, string $key): ?bool
    {
        if (str_starts_with($key, 'act.')) {
            return ! self::isLocked($key) && array_key_exists($key, $map) ? $map[$key] : null;
        }

        if (str_starts_with($key, 'nav.')) {
            return array_key_exists($key, $map) ? $map[$key] : null;
        }

        return self::resolveMap($map, $key);
    }

    /**
     * الأكشن اللي بيغطي الراوت ده (نفس مطابقة البادئة) — null لو مالوش.
     * لو أكتر من واحد: أطول بادئة (التيست بيضمن إن مفيش تداخل أصلاً).
     * ده اللي بيحدد الصفوف (الراوت بالظبط ← الأكشن) و`screensOf` كمان.
     */
    public static function actionKeyFor(string $routeName): ?string
    {
        if (array_key_exists($routeName, self::$actionCache)) {
            return self::$actionCache[$routeName];
        }

        $found = null;
        $len = -1;

        foreach (self::ACTIONS as $key => $def) {
            foreach ($def[3] as $covered) {
                if (self::matches($routeName, $covered) && strlen($covered) > $len) {
                    $found = $key;
                    $len = strlen($covered);
                }
            }
        }

        return self::$actionCache[$routeName] = $found;
    }

    /** @var array<string, ?string> */
    private static array $actionCache = [];

    /**
     * الراوت المغطّي بياخد **افتراضي الأكشن** لما مفيش صف؟ — الاسم بالظبط في
     * ACTIONS أو راوت كتابة. ⚠️ شاشة GET تحت البادئة من غير ما تتكتب بالاسم
     * (`gl.golive.run.progress`، `wh.receipt.putaway.form`) صفوف الأكشن بتوصلها
     * (منع الزرار بيقفل الفورم)، بس افتراضيها افتراضي صفحتها — وإلا المحاسب
     * يفقد شاشة التقدّم ومدير الفرع فورم الترصيف من غير أي صف.
     */
    public static function actionDefaulted(string $routeName): bool
    {
        if (self::actionKeyFor($routeName) === null) {
            return false;
        }

        foreach (self::ACTIONS as $def) {
            if (in_array($routeName, $def[3], true)) {
                return true;
            }
        }

        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);

        // اسم مش راوت: يتعامل ككتابة (الأضيق) — من غير كاش
        return $route === null || ! in_array('GET', $route->methods(), true);
    }

    /**
     * افتراضي الكود للراوت (من غير أي صف): الراوت المغطّي بأكشن بيتبع افتراضي
     * الأكشن، والباقي خريطة SCREENS. `$sees` = الرول شايف صفحة الأكشن؟
     *
     * @param \Closure(string): bool $sees
     */
    private static function baseDefault(string $role, string $routeName, \Closure $sees): bool
    {
        $action = self::isLocked($routeName) || ! self::actionDefaulted($routeName)
            ? null
            : self::actionKeyFor($routeName);

        return $action !== null
            ? self::actionDefault($role, $action, $sees)
            : self::roleDefault($role, $routeName);
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

        // ⚠️ زرار كل راوتاته مقفولة: مفيش صف يمنحه — افتراضي الكود بس
        if (! self::isLocked($key)) {
            $map = $user->permMap();

            if (array_key_exists($key, $map)) {
                return $map[$key];
            }

            // الرول المخصص (٩/١١) — بين اليوزر والرول الأساسي
            if (($customKey = $user->permRoleKey()) !== null) {
                $customMap = \App\Models\RolePermission::mapFor($customKey);

                if (array_key_exists($key, $customMap)) {
                    return $customMap[$key];
                }
            }

            // استثناء الرول على الزرار (٢٣/٨) — بعد اليوزر وقبل الافتراضي
            $roleMap = \App\Models\RolePermission::mapFor($user->role);

            if (array_key_exists($key, $roleMap)) {
                return $roleMap[$key];
            }
        }

        return self::actionDefault($user->role, $key, fn (string $page) => self::allows($user, $page));
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

    /**
     * شاشات الصفحة (مستوى المصفوفة التالت، ٩/١١): راوتس GET المتسمّية تحت
     * بادئة الصفحة — مش مقفولة ومش مغطّية بأكشن. مترتّبة.
     *
     * ⚠️ الراوت بيتبع **أطول** صفحة (NAV أو عامة) بتطابقه — `erp.clients.activate`
     * صفحة لوحدها مش شاشة تحت `erp.clients`، فمفيش صف بيتكرر في المصفوفة.
     *
     * @return list<string>
     */
    public static function screensOf(string $page): array
    {
        if (self::$screenCache === null) {
            $pages = self::PUBLIC_PAGES;

            foreach (self::NAV as $links) {
                foreach ($links as $link) {
                    $pages[] = $link[0];
                }
            }


            $map = [];

            foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
                $name = $route->getName();

                if ($name === null || ! in_array('GET', $route->methods(), true) || self::isLocked($name)) {
                    continue;
                }

                // ⚠️ نفس `actionKeyFor` اللي `resolveMap` بيغطي بيها وقت التشغيل
                if (self::actionKeyFor($name) !== null) {
                    continue;
                }

                $owner = null;

                foreach ($pages as $p) {
                    if (self::matches($name, $p) && strlen($p) > strlen((string) $owner)) {
                        $owner = $p;
                    }
                }

                if ($owner !== null && $owner !== $name) {
                    $map[$owner][] = $name;
                }
            }

            foreach ($map as &$list) {
                sort($list);
            }
            unset($list);

            self::$screenCache = $map;
        }

        return self::$screenCache[$page] ?? [];
    }

    /** @var array<string, list<string>>|null */
    private static ?array $screenCache = null;

    /** الاسم بيطابق البادئة؟ (بالظبط أو `البادئة.`) */
    private static function matches(string $routeName, string $prefix): bool
    {
        return $routeName === $prefix
            || str_starts_with($routeName, rtrim($prefix, '.').'.');
    }

    /**
     * فين يروح بعد اللوجين — HOME الرول لو مسموح له، وإلا أول لينك في
     * السايدبار، وإلا صفحة «مفيش شاشات» (٩/١١). عمره ما يهبط على 403.
     *
     * ⚠️ اليوزر اللي مش ويب بيفضل على القديم — `EnsureScreenAccess` بيوريه
     * رسالة «شغلك على الأبلكيشن» بدل صفحة «مفيش شاشات».
     */
    public static function home(?User $user): string
    {
        $base = self::HOME[$user?->role ?? ''] ?? 'erp.overview';

        if ($user === null || ! self::isWebRole($user) || self::allows($user, $base)) {
            return $base;
        }

        foreach (self::navFor($user) as $links) {
            return $links[0][0];
        }

        return self::NO_SCREENS;
    }

    /** الرول ده بيدخل الويب أصلاً؟ — الرول المخصص بيقرر بـ`web_login` */
    public static function isWebRole(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->role !== 'admin' && ($custom = $user->activeCustomRole()) !== null) {
            return (bool) $custom->web_login;
        }

        return in_array($user->role, self::WEB_ROLES, true);
    }
}
