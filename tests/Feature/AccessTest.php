<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * خريطة الصلاحيات — كل رول بيشوف شغله وبس
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الملف ده مهم:** `Access::SCREENS` هي المصدر الوحيد اللي
 * السايدبار بيرسم منه والميدلوير بيمنع منه. لو الخريطة نفسها اتكسرت،
 * الاتنين بيتكسروا مع بعض — يعني لينك بيبان لواحد ويرفضه السيرفر،
 * أو صفحة شغالة مالهاش لينك وحد بيلاقيها بالصدفة.
 *
 * ⚠️ الملف ده بيفحص **الخريطة نفسها** بالدوال الحقيقية
 * (`roleDefault` / `allows` / `action` / `navFor` / `isWebRole`) —
 * من غير HTTP. الفحص عن طريق الراوتس الحقيقية موجود في
 * `RoleAccessTest`، والاتنين مكمّلين لبعض: ده بيمسك خطأ في
 * الخريطة، وده بيمسك اختلاف الخريطة عن الحراسة.
 */
class AccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الشاشات اللي محصورة في الأدمن.
     *
     * ⚠️ التلاتة دول `role:admin` في الراوت نفسه. لو دخلوا خريطة أي
     * رول تاني، السايدبار هيوريهم لينك بيرفضه السيرفر — ولو الراوت
     * اتفك يوم، الرول هيوصل لصلاحيات الحسابات وسجل الحركة.
     */
    private const ADMIN_ONLY = ['erp.perms', 'erp.audit', 'erp.incentives'];

    // ═══════════════ 1. كل رول بيشوف شاشاته ═══════════════

    /**
     * كل بادئة مكتوبة لرول، الرول ده بيعدّي منها.
     *
     * ⚠️ الحارس على `matches()`: القاعدة «الاسم بالظبط أو الاسم +
     * نقطة». الغلطة اللي حصلت فعلاً إن `ops.invoices` مش
     * `ops.invoice` ولا بتبدأ بـ`ops.invoice.` — فوقعت بين
     * الاتنين ومدير الفرع فقد قايمة الفواتير. أي تغيير في قاعدة
     * المطابقة بيبان هنا فوراً.
     */
    public function test_every_role_passes_every_prefix_written_for_it(): void
    {
        $broken = [];

        foreach (Access::SCREENS as $role => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($prefix, '!')) {
                    continue;   // دي استثناءات — بتتفحص في تيست تاني
                }

                if (! Access::roleDefault($role, $prefix)) {
                    $broken[] = $role.' ← '.$prefix;
                }
            }
        }

        $this->assertSame([], $broken,
            'بادئات مكتوبة في الخريطة والرول مش بيعدّي منها: '.implode(', ', $broken));
    }

    /**
     * الاستثناء (`!`) بيغلب البادئة الواسعة اللي فوقه.
     *
     * ⚠️ الترتيب في `roleDefault()` مقصود: الاستثناءات الأول.
     * لو اتقلب، `erp.clients` هتغطّي `erp.clients.activate` والمحاسب
     * هيشوف زرار تفعيل العملاء والراوت هيرميه على 403.
     */
    public function test_every_exception_actually_blocks_its_route(): void
    {
        $leaking = [];

        foreach (Access::SCREENS as $role => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (! str_starts_with($prefix, '!')) {
                    continue;
                }

                $route = substr($prefix, 1);

                if (Access::roleDefault($role, $route)) {
                    $leaking[] = $role.' ← '.$route;
                }
            }
        }

        $this->assertSame([], $leaking,
            'استثناءات مكتوبة والبادئة الواسعة لسه بتغلبها: '.implode(', ', $leaking));
    }

    /**
     * كل استثناء بيمنع الراوت المقصود بس — مش القسم كله.
     *
     * ⚠️ `!erp.clients.activate` ممنوع يمنع `erp.clients` نفسها.
     * لو منعها، المحاسب بيفقد شاشة العملاء بالكامل وهي شغله الأساسي.
     */
    public function test_an_exception_never_swallows_its_parent_screen(): void
    {
        $accountant = $this->makeAdmin(['role' => 'accountant']);

        $this->assertFalse(Access::allows($accountant, 'erp.clients.activate'),
            'تفعيل العملاء قرار إداري — المحاسب مالوش دعوة بيه');

        $this->assertTrue(Access::allows($accountant, 'erp.clients'),
            'شاشة العملاء نفسها شغل المحاسب الأساسي');

        $this->assertTrue(Access::allows($accountant, 'erp.clients.show'),
            'كارت العميل تحت نفس البادئة ولازم يفضل مفتوح');
    }

    // ═══════════════ 2. المندوب مايشوفش الويب ═══════════════

    /**
     * المندوب مايشوفش أي شاشة أدمن.
     *
     * ⚠️ **ده أهم تيست في الملف.** المندوب معاه بيانات دخول شغالة
     * (نفس الحساب بتاع الأبلكيشن)، ومعظم شاشات العرض ماكانش عليها
     * `role:` middleware — يعني مندوب فتح المتصفح كان بيوصل لصلاحيات
     * الحسابات وسجل الحركة وإعدادات الحوافز. الباب كان مفتوح ومحدش
     * واخد باله لأن المناديب مابيدخلوش الويب عملياً.
     */
    public function test_a_sales_agent_never_reaches_an_admin_screen(): void
    {
        $rep = $this->makeRep();

        foreach (self::ADMIN_ONLY as $route) {
            $this->assertFalse(Access::allows($rep, $route),
                "المندوب وصل لـ«{$route}» — دي شاشة أدمن");
        }
    }

    /**
     * المندوب مايشوفش **أي** شاشة في الخريطة كلها.
     *
     * ⚠️ الفحص على القايمة كلها مش على تلات شاشات مختارة — أول
     * ما حد يضيف `sales_agent` للخريطة بحسن نية، التيست ده بيقع
     * بدل ما نكتشف بعد شهر إن المندوب بيشوف مديونيات العملاء.
     */
    public function test_a_sales_agent_is_locked_out_of_every_mapped_screen(): void
    {
        $rep = $this->makeRep();
        $reachable = [];

        foreach (Access::SCREENS as $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($prefix, '!')) {
                    continue;
                }

                if (Access::allows($rep, $prefix)) {
                    $reachable[] = $prefix;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($reachable)),
            'المندوب وصل لشاشات ويب: '.implode(', ', array_unique($reachable)));
    }

    /**
     * المندوب مالوش سايدبار ومش من رولز الويب أصلاً.
     *
     * ⚠️ السايدبار الفاضي مش صدفة — هو نتيجة إن `navFor()` بتفلتر
     * بنفس الدالة اللي بتحرس الراوت. لو طلع فيه لينك واحد، يبقى
     * الفلترة والحراسة اتفرقوا.
     */
    public function test_field_roles_have_no_sidebar_and_no_web_access(): void
    {
        foreach (User::FIELD_ROLES as $role) {
            $user = $this->makeAdmin(['role' => $role]);

            $this->assertFalse(Access::isWebRole($user),
                "«{$role}» متحسب رول ويب وهو شغله على الأبلكيشن");

            $this->assertSame([], Access::navFor($user),
                "«{$role}» ليه لينكات في السايدبار");
        }
    }

    // ═══════════════ 3. شاشات الأدمن محصورة فيه ═══════════════

    /**
     * ولا رول ويب تاني بيوصل لشاشات الأدمن.
     *
     * ⚠️ الخريطة مالهاش سطر للأدمن عن قصد (`allows()` بتخرج بـtrue
     * قبل ما توصلها). فالحارس الوحيد على الشاشات دي هو إنها **مش**
     * مكتوبة في خريطة أي رول تاني — والتيست ده هو اللي بيتأكد.
     */
    public function test_no_other_web_role_reaches_the_admin_only_screens(): void
    {
        $leaks = [];

        foreach (Access::WEB_ROLES as $role) {
            if ($role === 'admin') {
                continue;
            }

            $user = $this->makeAdmin(['role' => $role]);

            foreach (self::ADMIN_ONLY as $route) {
                if (Access::allows($user, $route)) {
                    $leaks[] = $role.' ← '.$route;
                }
            }
        }

        $this->assertSame([], $leaks,
            'رولز بتوصل لشاشات الأدمن: '.implode(', ', $leaks));
    }

    /**
     * الأدمن بيعدّي على كل حاجة من غير ما يتكتب في الخريطة.
     *
     * ⚠️ «الأدمن معاه كل أوبشن» — أي محاولة نعدّد شاشاته هتنسى
     * واحدة، فالخروج المبكر بـ`true` هو الحل. التيست ده بيقفل عليه.
     */
    public function test_the_admin_passes_everything_without_being_mapped(): void
    {
        $admin = $this->makeAdmin();

        $this->assertArrayNotHasKey('admin', Access::SCREENS,
            'الأدمن اتكتب في الخريطة — أي تعداد لشاشاته هينسى واحدة');

        foreach (self::ADMIN_ONLY as $route) {
            $this->assertTrue(Access::allows($admin, $route));
        }

        // شاشة متخيّلة لسه مااتكتبتش — الأدمن لازم يعدّي منها برضه
        $this->assertTrue(Access::allows($admin, 'erp.some.future.screen'));
    }

    // ═══════════════ 4. الأزرار الحساسة ═══════════════

    /**
     * الزرار اللي رولزه قايمة فاضية = أدمن بس.
     *
     * ⚠️ `[]` معناها «الأدمن بس» و`null` معناها «اللي شايف الصفحة».
     * الاتنين بيتلخبطوا بسهولة في PHP، والفرق هنا هو الفرق بين
     * زرار بيمسح الداتا كلها وزرار عادي.
     */
    public function test_an_admin_only_action_is_hidden_from_every_other_role(): void
    {
        $admin = $this->makeAdmin();
        $this->assertTrue(Access::action($admin, 'act.overview.wipe'));

        foreach (Access::WEB_ROLES as $role) {
            if ($role === 'admin') {
                continue;
            }

            $user = $this->makeAdmin(['role' => $role]);

            $this->assertFalse(Access::action($user, 'act.overview.wipe'),
                "«{$role}» شايف زرار مسح الداتا");
        }
    }

    /**
     * مفتاح أكشن مش متسجل = زرار محدش يشوفه.
     *
     * ⚠️ الافتراضي الآمن. لو المفتاح المش موجود رجّع `true`، أي
     * غلطة إملائية في اسم الأكشن جوه بليد بتفتح الزرار للكل في صمت.
     */
    public function test_an_unregistered_action_key_is_hidden_by_default(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);

        $this->assertFalse(Access::action($manager, 'act.does.not.exist'));
    }

    /**
     * كل أكشن مربوط بصفحة موجودة في الخريطة.
     *
     * ⚠️ الأكشن اللي صفحته مش مسموحة لأي رول بيبقى زرار ميّت —
     * الافتراضي `null` بيقول «اللي شايف الصفحة شايف الزرار»، ولو
     * الصفحة نفسها مش موجودة، الزرار مابيبانش لحد ولا حد بيلاحظ.
     */
    public function test_every_action_points_at_a_screen_some_role_can_open(): void
    {
        $orphans = [];

        foreach (Access::ACTIONS as $key => $def) {
            $page = $def[1];
            $seen = false;

            foreach (array_keys(Access::SCREENS) as $role) {
                if (Access::roleDefault($role, $page)) {
                    $seen = true;
                    break;
                }
            }

            if (! $seen) {
                $orphans[] = $key.' → '.$page;
            }
        }

        // ⚠️ الأكشنز الخاصة بالأدمن بس صفحتها ممكن ماتكونش في خريطة
        // أي رول تاني — ودي الحالة الوحيدة المقبولة.
        $adminOnly = array_keys(array_filter(
            Access::ACTIONS,
            fn (array $def) => $def[2] === [],
        ));

        $unexpected = array_values(array_filter(
            $orphans,
            fn (string $o) => ! in_array(explode(' → ', $o)[0], $adminOnly, true),
        ));

        $this->assertSame([], $unexpected,
            'أكشنز صفحتها مش مسموحة لأي رول: '.implode(', ', $unexpected));
    }

    // ═══════════════ 5. الهبوط بعد اللوجين ═══════════════

    /**
     * كل رول ويب بيهبط على شاشة مسموحة له.
     *
     * ⚠️ الديفولت كان `erp.overview` للكل — يعني أمين المخزن كان
     * بيعمل لوجين ويترمي على 403 في وشه أول ثانية، ويفتكر إن الحساب
     * مش شغال ويكلّم الأدمن.
     */
    public function test_every_web_role_lands_on_a_screen_it_may_open(): void
    {
        foreach (Access::WEB_ROLES as $role) {
            $user = $this->makeAdmin(['role' => $role]);
            $home = Access::home($user);

            $this->assertTrue(Access::allows($user, $home),
                "«{$role}» بيهبط على «{$home}» وهو ممنوع منها");
        }
    }
}
