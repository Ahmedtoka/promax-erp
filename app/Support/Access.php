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
            'erp.overview', 'erp.clients', 'erp.groups', 'erp.channels',
            'erp.contracts', 'erp.leads', 'erp.dues', 'erp.stock',
            'erp.batches', 'erp.reports', 'erp.team', 'erp.zones',
            'erp.geo', 'erp.products', 'erp.branches', 'erp.vehicles', 'erp.warehouses',
            'erp.prices',
            'erp.suppliers', 'erp.purchasing',
            // ⚠️ **`erp.clauses` مش تحت `erp.contracts`.** الراوتس
            // اتسمّت `erp.clauses.store` و`erp.clauses.destroy` بره
            // مقطع `contracts`، فبادئة العقود مابتغطّيهاش — والمدير
            // كان بيفتح العقد، يملا بنوده، يدوس حفظ، ويترمي على 403.
            'erp.clauses',
            'wh.', 'ops.',
        ],

        // ═══ مدير الفرع — نفس المدير، بس البيانات مفلترة بفرعه ═══
        // ⚠️ الفلترة نفسها مش هنا. دي بتحصل في الكويري بـ`canSeeBranch`.
        // الخريطة بتقول «الشاشة دي مسموحة»، والكويري بتقول «الصفوف دي».
        'branch_manager' => [
            'erp.overview', 'erp.clients', 'erp.groups', 'erp.contracts',
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
        ],

        // ═══ المحاسب — الفلوس بس ═══
        // ⚠️ **مفيش `wh.` ولا `ops.` خالص.** المحاسب بيقفل حسابات،
        // مش بيوزّع شغل ولا بيحرّك بضاعة. ولو شاف زرار «نزّل أمر توريد»
        // هيدوس عليه يوم ويطلّع بضاعة محدش طلبها.
        'accountant' => [
            'erp.overview', 'erp.clients', 'erp.groups', 'erp.contracts',
            'erp.dues', 'erp.reports', 'erp.tax', 'erp.eta',
            // ⚠️ المحاسب بيشوف الموردين ويدفع لهم — المستحقات شغله.
            // أوامر الشراء نفسها (بضاعة) مش له، وتعريف المورد
            // والافتتاحي قرارات إدارة (`role:admin,manager` في الراوت).
            'erp.suppliers',
            '!erp.suppliers.store', '!erp.suppliers.update', '!erp.suppliers.opening',
            'ops.invoices', 'ops.invoice',
            // ⚠️ **استثناء من بادئة `erp.clients`.** تفعيل العملاء
            // المستوردين قرار إداري (`role:admin,manager,branch_manager`)،
            // والبادئة كانت بتوري المحاسب اللينك والراوت يرفضه بـ403.
            '!erp.clients.activate',
            // ⚠️ وإيقاف العميل نفس القرار الإداري — البادئة نفسها
            // كانت بتغطيه (`role:admin,manager` في الراوت).
            '!erp.clients.deactivate',
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
            // بيستلم بضاعة الموردين — عرض الأوامر والاستلام بس،
            // الإنشاء والفوترة والإلغاء قرارات إدارة.
            'erp.purchasing',
            '!erp.purchasing.new', '!erp.purchasing.store', '!erp.purchasing.invoice',
            '!erp.purchasing.cancel', '!erp.purchasing.close',
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
    public const NAV = [
        // ⚠️ **الترتيب هو ترتيب يوم الشغل مش ترتيب بناء السيستم.**
        // المنيو القديمة كانت متقسومة «إدارة/مخزن/عمليات» — قسمة
        // المطوّر مش قسمة المستخدم: «الإدارة» كان فيها 12 لينك من
        // العملاء للتسعير للتقارير، والتقارير نفسها كانت مبعثرة في
        // تلات مجموعات (أعمار الديون هنا، الصلاحية هناك، التراكينج
        // في العمليات). دلوقتي: البيع → الفلوس → البضاعة → الميدان →
        // التقارير كلها مع بعض → الإعدادات.

        // ═══ العملاء والبيع — اللي بنبيع لمين وبكام ═══
        'nav.group_clients' => [
            ['erp.overview', '📊', 'nav.overview', 'erp.overview', null],
            ['erp.clients', '👥', 'nav.clients', 'erp.clients', null],
            ['erp.clients.activate', '✅', 'client.activate_clients', 'erp.clients.activate*', null],
            ['erp.groups', '🏬', 'nav.chains', 'erp.groups*', null],
            ['erp.channels', '🎯', 'nav.channels', 'erp.channels', null],
            ['erp.contracts', '📜', 'nav.contracts', 'erp.contracts', null],
            ['erp.leads', '✨', 'nav.leads', 'erp.leads', null],
            ['erp.prices', '🏷️', 'price.price_lists', 'erp.prices*', null],
        ],

        // ═══ الفلوس — شاشات المحاسب ═══
        'nav.group_money' => [
            ['erp.dues', '💸', 'nav.dues', 'erp.dues', 'dues'],
            ['erp.suppliers', '🏭', 'supplier.suppliers', 'erp.suppliers*', null],
            ['ops.invoices', '🧾', 'nav.invoices', 'ops.invoice*', null],
            ['erp.eta', '🏛️', 'nav.eta', 'erp.eta*', null],
        ],

        // ═══ المخزون — البضاعة وحركتها ═══
        'nav.group_inventory' => [
            ['erp.stock', '📦', 'nav.inventory', 'erp.stock', null],
            ['erp.purchasing', '📥', 'supplier.purchase_orders', 'erp.purchasing*', null],
            ['erp.warehouses', '🏢', 'stock.warehouses', 'erp.warehouses*', null],
            ['wh.index', '🏭', 'nav.warehouse', 'wh.index', null],
            ['wh.receipts', '📥', 'nav.receipts', 'wh.receipt*', null],
            ['wh.locations', '🗄️', 'nav.shelves', 'wh.locations', null],
            ['wh.transfers', '🔁', 'nav.transfers', 'wh.transfers', null],
            ['wh.picks', '📋', 'nav.pick_orders', 'wh.picks*', null],
            ['wh.counts', '📊', 'nav.stock_counts', 'wh.count*', null],
        ],

        // ═══ الميدان — الفريق والعربيات والزيارات ═══
        'nav.group_field' => [
            ['ops.dashboard', '🛰️', 'nav.ops_dashboard', 'ops.dashboard', null],
            ['ops.requests', '✅', 'nav.client_requests', 'ops.requests', 'requests'],
            ['ops.handout', '📤', 'field.handout', 'ops.handout*', null],
            ['ops.pos', '🚚', 'nav.purchase_orders', 'ops.pos', null],
            ['ops.replenishments', '📦', 'nav.replenishments', 'ops.replenishments', 'replenishments'],
            ['ops.merch', '🛒', 'nav.merch_visits', 'ops.merch', null],
            ['ops.journeys', '🗺️', 'nav.journeys', 'ops.journeys', null],
            ['ops.assignments', '👥', 'nav.assignments', 'ops.assignments', null],
            ['ops.live', '📡', 'nav.live', 'ops.live', null],
        ],

        // ═══ التقارير — كلها في مكان واحد ═══
        // ⚠️ التقرير = شاشة قراءة بتجاوب على سؤال، مش شاشة بتعمل
        // حاجة. أعمار الديون والصلاحية والتراكينج كانوا في تلات
        // مجموعات مختلفة واللي بيدوّر على «التقارير» مش بيلاقيهم.
        'nav.group_reports' => [
            ['erp.reports', '📑', 'nav.reports', 'erp.reports', null],
            ['erp.batches', '🗓️', 'nav.batch_report', 'erp.batches', null],
            ['wh.expiry', '⏳', 'nav.expiry', 'wh.expiry', null],
            ['ops.tracking', '📍', 'nav.tracking', 'ops.tracking', null],
        ],

        // ═══ الإعدادات ═══
        'nav.group_settings' => [
            ['erp.team', '🧑‍💼', 'nav.team', 'erp.team', null],
            ['erp.branches', '🏢', 'nav.branches', 'erp.branches', null],
            ['erp.vehicles', '🚚', 'nav.vehicles', 'erp.vehicles*', null],
            ['erp.import', '📥', 'nav.import', 'erp.import*', null],
            ['erp.tax.settings', '⚙️', 'nav.tax', 'erp.tax*', null],
        ],
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

    /** الرول ده بيوصل للراوت ده؟ */
    public static function allows(?User $user, string $routeName): bool
    {
        if ($user === null) {
            return false;
        }

        // ⚠️ الأدمن بيعدّي من غير ما يبص على الخريطة. أي تعداد لشاشاته
        // هيبقى ناقص أول ما نضيف شاشة جديدة.
        if ($user->role === 'admin') {
            return true;
        }

        $prefixes = self::SCREENS[$user->role] ?? [];

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
