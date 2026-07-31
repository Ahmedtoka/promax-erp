<?php

namespace App\Support;

/**
 * ═══════════════════════════════════════════════════════════════
 * فريق PROMAX الحقيقي — المصدر الوحيد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الفريق هنا مش في السيدر.** السيدر بينزل مع `db:seed` اللي
 * بيرحّل الداتا التاريخية كمان، والأمر `promax:team` محتاج نفس القايمة
 * من غير الداتا. لما كانت مكتوبة في الاتنين، أول تعديل على واحدة
 * كان بيخلّي الأمرين يعملوا فريقين مختلفين.
 *
 * ⚠️ **الأكواد مابتتغيرش.** `SLS-014` بتتخزن على العهدة والزيارة
 * والفاتورة. تغييرها معناه إن الشغل القديم بيبقى منسوب لكود مالوش
 * صاحب — وده حصل قبل كده وفضّى سيدر كامل.
 */
class Roster
{
    /**
     * كل صف: الإيميل ← بياناته.
     *
     * `zones` أسماء مناطق بالعربي — الأمر بيدوّر عليها وبيعمل
     * اللي مش موجود. `warehouse` كود مخزن. `manages` أكواد قنوات
     * أو `'all'`.
     *
     * ⚠️ الأسماء العربية مستنتجة من الإيميلات. أول ما تتأكد من
     * الأسماء الرسمية، عدّلها هنا وشغّل `promax:team` تاني — الأمر
     * بيحدّث الموجود بالإيميل مش بيعمل نسخة تانية.
     *
     * @var list<array<string, mixed>>
     */
    public const TEAM = [
        // ═══════════════ الأدمنز ═══════════════
        [
            'email' => 'jad@promax.com', 'name' => 'جاد', 'name_en' => 'Jad',
            'role' => 'admin', 'code' => 'ADM-001', 'manages' => 'all',
        ],
        [
            'email' => 'saad@promax.com', 'name' => 'سعد', 'name_en' => 'Saad',
            'role' => 'admin', 'code' => 'ADM-002', 'manages' => 'all',
        ],
        [
            'email' => 'ammar@promax.com', 'name' => 'عمار', 'name_en' => 'Ammar',
            'role' => 'admin', 'code' => 'ADM-003', 'manages' => 'all',
        ],

        // ═══════════════ Channel Manager ═══════════════
        [
            'email' => 'amrhassan@promax.com', 'name' => 'عمرو حسن', 'name_en' => 'Amr Hassan',
            'role' => 'manager', 'code' => 'CHM-001', 'manages' => 'all',
        ],

        // ═══════════════ المحاسب ═══════════════
        [
            'email' => 'suhaila@promax.com', 'name' => 'سهيلة', 'name_en' => 'Suhaila',
            'role' => 'accountant', 'code' => 'ACC-001',
        ],

        // ═══════════════ أمناء المخازن ═══════════════
        [
            'email' => 'mohamedwarehouse@promax.com', 'name' => 'محمد — مخزن المعادي',
            'name_en' => 'Mohamed — Maadi Store',
            'role' => 'warehouse_keeper', 'code' => 'WHK-001', 'warehouse' => 'MAADI',
        ],
        [
            'email' => 'masna3@promax.com', 'name' => 'أمين المخزن الرئيسي — المصنع',
            'name_en' => 'Main Store Keeper — Factory',
            'role' => 'warehouse_keeper', 'code' => 'WHK-002', 'warehouse' => 'FAC',
        ],

        // ═══════════════ السيلز إيجينت — مودرن تريد ═══════════════
        // التوزيع من شيت «مناديب مودرن تريد»: 18 منطقة على 3 مناديب.
        //
        // ⚠️ **المناطق بالأكواد مش بالأسماء.** الشيت مكتوب فيه «اكتوبر»
        // و«مصر الجديده» من غير همزات، والزونز في الداتابيز «أكتوبر»
        // و«مصر الجديدة» بالهمزات. المطابقة بالاسم كانت هتفشل على نص
        // المناطق والمندوب يطلع من غير خط سير — والكود `MT-13` ثابت
        // مهما اتكتب الاسم إزاي.
        [
            'email' => 'mohamedkhatab@promax.com', 'name' => 'محمد خطاب', 'name_en' => 'Mohamed Khatab',
            'role' => 'sales_agent', 'code' => 'SLS-001', 'channel' => 'cash_van',
            // أكتوبر · الشيخ زايد · حدائق الأهرام · المقطم · المعادي · الهرم وفيصل
            'zones' => ['MT-13', 'MT-14', 'MT-15', 'MT-16', 'MT-17', 'MT-18'],
        ],
        [
            'email' => 'mariam@promax.com', 'name' => 'مريم', 'name_en' => 'Mariam',
            'role' => 'sales_agent', 'code' => 'SLS-002', 'channel' => 'cash_van',
            // التجمع الخامس · الرحاب والتجمع الأول · مدينتي · الشروق والمستقبل
            // · العاشر من رمضان · العبور
            'zones' => ['MT-07', 'MT-08', 'MT-09', 'MT-10', 'MT-11', 'MT-12'],
        ],
        [
            // ⚠️ **سامح سائق ومندوب في نفس الوقت** — زي ما الشيت بيقول.
            // رولّه `sales_agent` لأن ده اللي بيحدد شاشته وصلاحياته،
            // والعربية بتتسكّن عليه كسواق كمان (`assign()` بتقبل
            // المندوب زي السواق عن قصد).
            'email' => 'sameh@promax.com', 'name' => 'سامح عبدالله', 'name_en' => 'Sameh Abdallah',
            'role' => 'sales_agent', 'code' => 'SLS-003', 'channel' => 'cash_van',
            // مصر الجديدة · مدينة نصر · شبرا ووسط البلد · المهندسين · الدقي · الزمالك
            'zones' => ['MT-01', 'MT-02', 'MT-03', 'MT-04', 'MT-05', 'MT-06'],
        ],

        // ═══════════════ السواقين ═══════════════
        [
            'email' => 'sobhimohamed@promax.com', 'name' => 'صبحي محمد', 'name_en' => 'Sobhi Mohamed',
            'role' => 'driver', 'code' => 'DRV-001',
        ],
        [
            'email' => 'mohamedsowilam@promax.com', 'name' => 'محمد سويلم', 'name_en' => 'Mohamed Sowilam',
            'role' => 'driver', 'code' => 'DRV-002',
        ],

        // ═══════════════ البروموترز ═══════════════
        [
            'email' => 'salah@promax.com', 'name' => 'صلاح', 'name_en' => 'Salah',
            // ⚠️ البروموترز من غير مناطق لحد ما توزيعهم يوصل. البروموتر
            // من غير مناطق بيشتغل عادي — بس خط سيره بيطلع فاضي، وده
            // بيبان كتحذير في `promax:team:setup` مش كخطأ صامت.
            'role' => 'promoter', 'code' => 'PRM-001', 'channel' => 'key_account', 'zones' => [],
        ],
        [
            'email' => 'mohamedx@promax.com', 'name' => 'محمد', 'name_en' => 'Mohamed',
            'role' => 'promoter', 'code' => 'PRM-002', 'channel' => 'key_account', 'zones' => [],
        ],
    ];

    /**
     * العربيات وسواقينها.
     *
     * ⚠️ **أرقام اللوحات مكتوبة زي ما هي في `ModernTradeSeeder`.**
     * الشيت بيعرضها `ا رج 9161` (ترتيب العرض من اليمين) والداتابيز
     * فيها `رج ا 9161`. لو كتبناها زي العرض، `updateOrCreate` على
     * `plate` مش هتلاقي العربية الموجودة وهتعمل **عربية تانية** بنفس
     * السيارة — والعهدة تتقسم على اتنين.
     *
     * ⚠️ **العداد فاضي — الشيت مافيهوش أرقام كيلومترات.** سيبناه صفر
     * عن قصد بدل ما نخترع رقم: أول قراية حقيقية بتتسجّل من شاشة
     * العربيات وبتبقى نقطة البداية. لو حطّينا رقم مخترع، كل حسابات
     * الكيلومترات بعد كده بتبقى مبنية عليه.
     *
     * كل صف: [رقم اللوحة، النوع، مبرّدة؟، إيميل السواق، إيميل المندوب، عداد الكيلو]
     *
     * @var list<array<string, mixed>>
     */
    public const FLEET = [
        [
            'plate' => 'رج ا 9161',
            'kind' => 'GMC ربع نقل ثلاجة',
            'kind_en' => 'GMC quarter-ton refrigerated',
            'is_fridge' => true,
            // سامح بيسوق عربيته بنفسه — سواق ومندوب
            'driver' => 'sameh@promax.com',
            'rep' => 'sameh@promax.com',
        ],
        [
            'plate' => 'رج ا 9159',
            'kind' => 'GMC ربع نقل ثلاجة',
            'kind_en' => 'GMC quarter-ton refrigerated',
            'is_fridge' => true,
            'driver' => 'mohamedsowilam@promax.com',
            'rep' => 'mariam@promax.com',
        ],
        [
            'plate' => 'رط د 8582',
            // ⚠️ **صندوق مش ثلاجة.** الفرق مش شكلي: العربية دي
            // مابتشيلش الأصناف اللي محتاجة تبريد، وخط سير محمد خطاب
            // (أكتوبر والهرم والمعادي) لازم يتحط في الاعتبار عند
            // تحميل العهدة.
            'kind' => 'شيفروليه ربع نقل صندوق',
            'kind_en' => 'Chevrolet quarter-ton box',
            'is_fridge' => false,
            'driver' => 'sobhimohamed@promax.com',
            'rep' => 'mohamedkhatab@promax.com',
        ],
    ];

    /** الإيميلات كلها */
    public static function emails(): array
    {
        return array_column(self::TEAM, 'email');
    }

    /** صف واحد بالإيميل */
    public static function find(string $email): ?array
    {
        foreach (self::TEAM as $row) {
            if ($row['email'] === $email) {
                return $row;
            }
        }

        return null;
    }
}
