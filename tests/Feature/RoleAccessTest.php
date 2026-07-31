<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use App\Support\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * كل رول بيشوف شاشاته — وبس
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده اتكتب لأن السايدبار كان بيعرض كل حاجة لكل حد.**
 * الـ`role:` middleware كان على التعديل بس (`store`/`update`/`destroy`)،
 * أما شاشات العرض — `GET /erp/clients`، `GET /wh/picks`، `GET /erp/team`
 * — فكانت مفتوحة لأي حد عامل لوجين.
 *
 * ماكانش باين لأن اللي بيدخل الويب كان أدمن أو مدير. مع دخول
 * **المحاسب** و**أمين المخزن** بقى شغل يومي: أمين المخزن هيفتح كشف
 * حساب عميل، والمحاسب هيفتح أوامر التجهيز.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════ 1. الخريطة نفسها ═══════════════

    /**
     * كل رول في `User::ROLES` معروف للـ`Access`.
     *
     * ⚠️ الرول اللي مش في الخريطة `allows()` بترجّع له `false` على كل
     * شاشة — يعني بيدخل ويلاقي سايدبار فاضي وصفحة 403، من غير أي
     * رسالة تقول إن الرول نفسه هو المشكلة.
     */
    public function test_every_role_is_known_to_the_access_map(): void
    {
        $unmapped = [];

        foreach (array_keys(User::ROLES) as $role) {
            if ($role === 'admin') {
                continue;   // بيعدّي من غير خريطة عن قصد
            }

            $isWeb = in_array($role, Access::WEB_ROLES, true);
            $hasScreens = isset(Access::SCREENS[$role]);

            // رول ويب من غير شاشات = سايدبار فاضي
            // رول ميدان معاه شاشات = تناقض
            if ($isWeb !== $hasScreens) {
                $unmapped[] = $role;
            }
        }

        $this->assertSame([], $unmapped,
            'رولز مش متسقة بين WEB_ROLES و SCREENS: '.implode(', ', $unmapped));
    }

    /**
     * كل رول ويب بيهبط على شاشة **مسموحة له**.
     *
     * ⚠️ الديفولت كان `erp.overview` للكل. أمين المخزن كان يدخل
     * ويترمي على 403 في وشه أول ثانية ويفتكر إن الحساب مش شغال.
     */
    public function test_every_role_lands_on_a_screen_it_may_open(): void
    {
        foreach (Access::WEB_ROLES as $role) {
            $user = $this->makeAdmin(['role' => $role, 'email' => $role.'@test.local']);
            $home = Access::home($user);

            $this->assertTrue(Access::allows($user, $home),
                "الرول «{$role}» بيهبط على «{$home}» وهو مش مسموح له بيها");
        }
    }

    /**
     * كل لينك في السايدبار له راوت حقيقي.
     *
     * ⚠️ لينك باسم راوت مش موجود بيرمي `RouteNotFoundException` وقت
     * الرندر — يعني **كل صفحة في السيستم** بتطلع 500، مش الصفحة دي بس،
     * لأن السايدبار في الليّاوت.
     */
    public function test_every_sidebar_link_points_at_a_real_route(): void
    {
        $names = collect(Route::getRoutes())->map->getName()->filter()->all();
        $missing = [];

        foreach (Access::NAV as $group => $links) {
            foreach ($links as [$route, , , , ]) {
                if (! in_array($route, $names, true)) {
                    $missing[] = $group.' → '.$route;
                }
            }
        }

        $this->assertSame([], $missing,
            'لينكات في السايدبار مالهاش راوتس — كل صفحة هترمي 500: '.implode(', ', $missing));
    }

    // ═══════════════ 2. الحراسة الفعلية ═══════════════

    /**
     * **كل** راوت ويب متحرس.
     *
     * ⚠️ ده التيست اللي كان لازم يكون موجود من الأول. الراوت اللي
     * بيتضاف من غير حراسة مابيبانش في أي شاشة — بيبان لما حد يجرّب
     * الرابط أو لما حد يشوف داتا مش بتاعته.
     */
    public function test_every_web_screen_route_is_guarded(): void
    {
        $open = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            // شاشات السيستم بس — مش اللوجين ولا تبديل اللغة ولا الـAPI
            if (! preg_match('/^(erp|wh|ops)\./', $name)) {
                continue;
            }

            if (! in_array('screen', $route->gatherMiddleware(), true)) {
                $open[] = $name;
            }
        }

        $this->assertSame([], $open,
            'راوتس شاشات من غير حراسة — أي حد عامل لوجين بيوصلها: '.implode(', ', $open));
    }

    public static function forbiddenPairs(): array
    {
        return [
            // ⚠️ المحاسب مالوش دعوة بحركة البضاعة. ولو شاف زرار «نزّل
            // أمر توريد» هيدوس عليه يوم ويطلّع بضاعة محدش طلبها.
            'محاسب ← المخزن' => ['accountant', 'wh.index'],
            'محاسب ← أوامر التجهيز' => ['accountant', 'wh.picks'],
            'محاسب ← الجرد' => ['accountant', 'wh.counts'],
            'محاسب ← توزيع الشغل' => ['accountant', 'ops.assignments'],
            'محاسب ← الشاشة اللايف' => ['accountant', 'ops.live'],

            // ⚠️ أمين المخزن مالوش دعوة بمديونية العميل ولا خصمه ولا عقده.
            'أمين مخزن ← العملاء' => ['warehouse_keeper', 'erp.clients'],
            'أمين مخزن ← المستحقات' => ['warehouse_keeper', 'erp.dues'],
            'أمين مخزن ← العقود' => ['warehouse_keeper', 'erp.contracts'],
            'أمين مخزن ← الفريق' => ['warehouse_keeper', 'erp.team'],
            'أمين مخزن ← الفواتير' => ['warehouse_keeper', 'ops.invoices'],

            // ⚠️ المدير مالوش دعوة بالاستيراد ولا إعدادات الضرايب.
            'مدير قناة ← الاستيراد' => ['manager', 'erp.import'],
        ];
    }

    /**
     * @dataProvider forbiddenPairs
     */
    public function test_a_role_cannot_open_a_screen_that_is_not_its_job(string $role, string $route): void
    {
        $user = $this->makeAdmin(['role' => $role, 'email' => $role.'.deny@test.local']);

        $this->assertFalse(Access::allows($user, $route),
            "«{$role}» مسموح له بـ«{$route}» وده مش شغله");

        $this->actingAs($user)->get(route($route))->assertForbidden();
    }

    public static function allowedPairs(): array
    {
        return [
            'محاسب ← المستحقات' => ['accountant', 'erp.dues'],
            'محاسب ← العملاء' => ['accountant', 'erp.clients'],
            'محاسب ← الفواتير' => ['accountant', 'ops.invoices'],
            'محاسب ← الضرايب' => ['accountant', 'erp.tax.settings'],
            'أمين مخزن ← المخزن' => ['warehouse_keeper', 'wh.index'],
            'أمين مخزن ← أوامر التجهيز' => ['warehouse_keeper', 'wh.picks'],
            'أمين مخزن ← الاستلام' => ['warehouse_keeper', 'wh.receipts'],
            'أمين مخزن ← المخزون' => ['warehouse_keeper', 'erp.stock'],
        ];
    }

    /**
     * @dataProvider allowedPairs
     */
    public function test_a_role_can_open_the_screens_it_needs(string $role, string $route): void
    {
        $user = $this->makeAdmin(['role' => $role, 'email' => $role.'.allow@test.local']);

        $this->assertTrue(Access::allows($user, $route),
            "«{$role}» ممنوع من «{$route}» وهو شغله الأساسي");

        // ⚠️ **بنفتح الصفحة فعلاً** — الخريطة ممكن تسمح والحراسة ترفض.
        // بنفحص إنها **مش 403**، مش إنها 200: الصفحة على داتابيز فاضية
        // ممكن ترجع 404 لأن مافيش مخزن أصلاً، وده مش عيب في الصلاحيات.
        // الفرق مهم — تيست بيفشل لسبب مش بتاعه بيتشال بعد أسبوع.
        $status = $this->actingAs($user)->get(route($route))->getStatusCode();

        $this->assertNotSame(403, $status,
            "«{$role}» اترفض من «{$route}» وهو شغله الأساسي");
    }

    /**
     * المندوب والسواق والبروموتر مالهمش ويب.
     *
     * ⚠️ بياخدوا رسالة واضحة «شغلك على الأبلكيشن» مش 403 جافة —
     * الجافة بتخلّيهم يفتكروا إن الحساب باظ ويكلّموا الأدمن.
     */
    public function test_field_users_are_kept_out_of_the_web(): void
    {
        foreach (User::FIELD_ROLES as $role) {
            $user = $this->makeAdmin(['role' => $role, 'email' => $role.'.field@test.local']);

            $this->assertFalse(Access::isWebRole($user));
            $this->actingAs($user)->get(route('erp.overview'))->assertForbidden();
            $this->actingAs($user)->get(route('wh.index'))->assertForbidden();
        }
    }

    // ═══════════════ 3. السايدبار = الحراسة ═══════════════

    /**
     * اللينك اللي بيبان في السايدبار بيفتح فعلاً.
     *
     * ⚠️ **ده الشرط اللي كل الحتة دي قامت عليه.** لو السايدبار
     * والـmiddleware اتفرقوا، بيبقى فيه لينك بيودّي لـ403 — والمستخدم
     * بيفتكر إن السيستم باظ. أو أسوأ: صفحة شغالة مالهاش لينك، وحد
     * لقاها بالصدفة.
     */
    public function test_the_sidebar_never_shows_a_link_the_user_cannot_open(): void
    {
        foreach (Access::WEB_ROLES as $role) {
            $user = $this->makeAdmin(['role' => $role, 'email' => $role.'.nav@test.local']);
            $bad = [];

            foreach (Access::navFor($user) as $links) {
                foreach ($links as [$route, , , , ]) {
                    // ⚠️ **بنفتح اللينك فعلاً بـHTTP.** الاكتفاء بنداء
                    // `Access::allows()` تاني كان تحصيل حاصل — نفس
                    // الدالة اللي بنت القايمة. والنتيجة إن اللينكات
                    // اللي بتعدّي الخريطة وبيرفضها `role:` middleware
                    // (زي خطط السير لمدير الفرع) ماكانتش بتتمسك خالص.
                    $status = $this->actingAs($user)->get(route($route))->getStatusCode();

                    if ($status === 403) {
                        $bad[] = $route;
                    }
                }
            }

            $this->assertSame([], $bad,
                "السايدبار بيوري «{$role}» لينكات بترفضه: ".implode(', ', $bad));
        }
    }

    /** المجموعة الفاضية بتختفي — عنوان فوق فراغ بيلخبط */
    public function test_empty_sidebar_groups_disappear(): void
    {
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper', 'email' => 'wk.nav@test.local']);
        $nav = Access::navFor($keeper);

        foreach ($nav as $group => $links) {
            $this->assertNotEmpty($links, "المجموعة «{$group}» بتتعرض فاضية");
        }

        // أمين المخزن مالوش دعوة بمجموعة الإعدادات خالص
        $this->assertArrayNotHasKey('nav.group_settings', $nav);
    }

    /** الأدمن بيشوف كل حاجة — ولا لينك ناقص */
    public function test_the_admin_sees_everything(): void
    {
        $admin = $this->makeAdmin();
        $nav = Access::navFor($admin);

        $shown = collect($nav)->flatten(1)->count();
        $total = collect(Access::NAV)->flatten(1)->count();

        $this->assertSame($total, $shown, 'الأدمن مش شايف كل اللينكات');
    }

    // ═══════════════ 4. الفريق الحقيقي ═══════════════

    /** كل رول في قايمة الفريق رول معروف */
    public function test_the_roster_uses_real_roles(): void
    {
        foreach (Roster::TEAM as $row) {
            $this->assertArrayHasKey($row['role'], User::ROLES,
                "«{$row['email']}» رولها «{$row['role']}» مش موجود");
        }
    }

    /** مفيش إيميل ولا كود متكرر */
    public function test_the_roster_has_no_duplicates(): void
    {
        $emails = array_column(Roster::TEAM, 'email');
        $codes = array_column(Roster::TEAM, 'code');

        // ⚠️ الإيميل المكرر بيخلّي `updateOrCreate` يدوس على الأول
        // بالتاني — يعني حساب بيختفي في صمت والأمر بيقول «تمّ».
        $this->assertSame($emails, array_unique($emails), 'إيميل مكرر في قايمة الفريق');
        $this->assertSame($codes, array_unique($codes), 'كود موظف مكرر في قايمة الفريق');
    }

    /**
     * كل أمين مخزن مربوط بمخزن.
     *
     * ⚠️ أمين المخزن من غير `warehouse_id` بيشوف المخازن كلها —
     * وأمين مخزن المعادي اللي بيجرد مخزن المصنع بيطلّع فرق محدش
     * عارف مصدره.
     */
    public function test_every_warehouse_keeper_is_tied_to_a_warehouse(): void
    {
        foreach (Roster::TEAM as $row) {
            if ($row['role'] !== 'warehouse_keeper') {
                continue;
            }

            $this->assertNotEmpty($row['warehouse'] ?? null,
                "«{$row['email']}» أمين مخزن من غير مخزن");
        }
    }
}
