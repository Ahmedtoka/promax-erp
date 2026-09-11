<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\ClientRequest;
use App\Models\Contract;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\JourneyPlan;
use App\Models\Lead;
use App\Models\PickOrder;
use App\Models\PriceListItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReplenishmentItem;
use App\Models\ReplenishmentRequest;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\User;
use App\Services\Returns;
use App\Support\Access;
use App\Support\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
            // ⚠️ `online.` و`notifications.` اتضافوا (٨/٩) — كانوا بره الفحص
            if (! preg_match('/^(erp|wh|ops|online|notifications|gl)\./', $name)) {
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

    // ═══════════════ 5. كل لينك في كل شاشة — مش السايدبار بس ═══════════════

    /**
     * كل `route('x')` في كل فيو راوت حقيقي.
     *
     * ⚠️ التيست اللي فوق بيفحص لينكات السايدبار بس. اللينك اللي جوه جدول
     * أو مودال بيرمي `RouteNotFoundException` وقت الرندر — 500 على الصفحة
     * كلها — وما بيبانش غير لما الصف اللي فيه اللينك يظهر لأول مرة.
     */
    public function test_every_route_call_in_every_view_points_at_a_real_route(): void
    {
        $names = collect(Route::getRoutes())->map->getName()->filter()->flip()->all();
        $missing = [];

        foreach ($this->bladeFiles() as $view => $path) {
            preg_match_all("/route\\('([A-Za-z0-9_.]+)'/", (string) file_get_contents($path), $m);

            foreach (array_unique($m[1]) as $name) {
                if (! isset($names[$name])) {
                    $missing[] = $view.' → '.$name;
                }
            }
        }

        $this->assertSame([], $missing,
            'route() في الفيوهات على راوتس مش موجودة — 500 وقت الرندر: '.implode(', ', $missing));
    }

    public static function webRoles(): array
    {
        return [
            'مدير قناة' => ['manager'],
            'مدير فرع' => ['branch_manager'],
            'محاسب' => ['accountant'],
            'أمين مخزن' => ['warehouse_keeper'],
        ];
    }

    /**
     * ولا شاشة بتوري الرول لينك أو زرار أو فورم بيودّي لـ403.
     *
     * ⚠️ **ده اللي كان بره نطاق التيستات.** زرار «راسم خط السير» في
     * بورد الليدز كان بيدي 403 لمدير الفرع (اتصلّح ٨/٩/٢٠٢٦) — مش في
     * السايدبار، فتيست السايدبار ما شافه، والمشي بالعين في الشاشات هو
     * اللي لقاه.
     *
     * هنا بنبني عالم فيه داتا حقيقية (عميل وعقد وسلسلة وليد وطلب عميل
     * وفاتورة ومرتجع وأمر توريد وعهدة وتجهيز وريفيل ومهمة ومورد)، عشان
     * الصفوف تظهر ولينكاتها معاها. وبعدين لكل رول:
     *
     *   1. نفتح **كل** شاشة GET مسموحة له في الخريطة (مش السايدبار بس).
     *   2. نمشي على كل `<a href>` فيها ونفتحه **فعلاً بـHTTP** — 403 = عيب.
     *   3. كل `<form action>` وكل URL داخلي في الجافاسكربت بيتفحص ضد
     *      الخريطة و`role:` middleware من غير إرسال (عشان مانكتبش داتا).
     *   4. أي شاشة بترجع 500 على الداتا دي بتتسجّل كمان — شاشة بتقع
     *      على عميل عادي مش «مش صلاحيات»، بس برضه مش مقبولة.
     *
     * @dataProvider webRoles
     */
    public function test_no_screen_shows_the_role_a_link_or_form_it_cannot_use(string $role): void
    {
        $user = $this->populatedWorld()[$role];

        $queue = [];
        $from = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            // ⚠️ البداية من الشاشات اللي الرول **يقدر** يفتحها فعلاً (خريطة +
            // `role:`). الشاشة اللي الخريطة بتسمح بيها والـmiddleware بيرفضها
            // بيمسكها تيست الخريطة تحت — هنا موضوعنا اللينكات.
            if ($name === null || ! $this->isScreenRoute($name)
                || ! in_array('GET', $route->methods(), true)
                || str_contains($route->uri(), '{')
                || ! $this->mayUse($user, $route)) {
                continue;
            }

            $path = $this->pathOf(route($name));
            $queue[] = $path;
            $from[$path] = 'seed';
        }

        $seen = [];
        $forbidden = [];
        $broken = [];

        while ($queue !== []) {
            $path = array_shift($queue);

            if (isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;

            // ⚠️ حارس: لو التكرار ما اتشالش صح الزحف مابيخلصش
            $this->assertLessThan(900, count($seen), 'الزحف طلع عن السيطرة — راجع إزالة التكرار في pathOf()');

            $response = $this->actingAs($user)->get($path);
            $status = $response->getStatusCode();

            if ($status === 403) {
                $forbidden[] = $from[$path].' → [GET] '.$path;

                continue;
            }

            if ($status >= 500) {
                $broken[] = $path.' ('.$status.')';

                continue;
            }

            if ($status !== 200) {
                continue;   // redirect أو 404 على داتا مش موجودة — مش موضوعنا
            }

            foreach ($this->targetsIn((string) $response->getContent()) as [$method, $target, $kind]) {
                $targetPath = $this->pathOf($target);
                $routes = $this->routesFor($targetPath, $method);

                if ($routes === []) {
                    continue;
                }

                if ($kind === 'link') {
                    if (! isset($seen[$targetPath]) && ! isset($from[$targetPath])) {
                        $from[$targetPath] = $path;
                        $queue[] = $targetPath;
                    }

                    continue;
                }

                // فورم أو URL جافاسكربت: يكفي إن **راوت واحد** من المطابقين
                // مسموح — الـURL الواحد ممكن يبقى GET للعرض وPOST للحفظ.
                $usable = array_filter($routes, fn ($r) => $this->mayUse($user, $r));

                if ($usable === []) {
                    $names = implode('|', array_map(fn ($r) => (string) $r->getName(), $routes));
                    $forbidden[] = $path.' → ['.$method.'] '.$names.' ('.$kind.')';
                }
            }
        }

        $forbidden = array_values(array_unique($forbidden));

        $this->assertSame([], $forbidden,
            "شاشات بتوري «{$role}» لينكات أو فورمات بترفضه بـ403:\n  ".implode("\n  ", $forbidden));
        $this->assertSame([], $broken,
            "شاشات بتقع لـ«{$role}» على داتا عادية:\n  ".implode("\n  ", $broken));
    }

    // ═══════════════ أدوات الزحف ═══════════════

    private function isScreenRoute(string $name): bool
    {
        return preg_match('/^(erp|wh|ops|online|notifications|gl)\./', $name) === 1;
    }

    /** المسار من غير الكويري — `?page=2` و`?status=x` نفس الشاشة */
    private function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        return '/'.ltrim((string) $path, '/');
    }

    /**
     * الراوتس اللي بتطابق المسار: بالميثود المحدد، أو بكل الميثودز لو `ANY`.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    private function routesFor(string $path, string $method): array
    {
        $methods = $method === 'ANY' ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] : [$method];
        $out = [];

        foreach ($methods as $m) {
            try {
                $route = Route::getRoutes()->match(Request::create($path, $m));
            } catch (\Throwable) {
                continue;
            }

            if ($route->getName() !== null && $this->isScreenRoute($route->getName())) {
                $out[] = $route;
            }
        }

        return $out;
    }

    /**
     * نفس قرار `EnsureScreenAccess` + `EnsureRole` بس من غير ريكوست:
     * الخريطة، واستثناء الرول، وقايمة `role:` على الراوت.
     */
    private function mayUse(User $user, \Illuminate\Routing\Route $route): bool
    {
        $name = (string) $route->getName();

        if (! Access::allows($user, $name)) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if (($override = Access::roleOverride($user->role, $name)) !== null) {
            return $override;
        }

        $gate = $this->roleGate($route);

        return $gate === null || in_array($user->role, $gate, true);
    }

    /**
     * الرولز اللي بتعدّي كل `role:` على الراوت — تقاطعهم لو أكتر من واحد
     * (مجموعة + راوت)، و`null` لو مفيش بوابة رول أصلاً.
     *
     * @return list<string>|null
     */
    private function roleGate(\Illuminate\Routing\Route $route): ?array
    {
        $gate = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! str_starts_with($middleware, 'role:')) {
                continue;
            }

            $roles = explode(',', substr($middleware, 5));
            $gate = $gate === null ? $roles : array_values(array_intersect($gate, $roles));
        }

        return $gate;
    }

    /**
     * كل مكان في الصفحة بيودّي لراوت: لينكات، فورمات، وURLs في الجافاسكربت.
     *
     * @return list<array{0:string,1:string,2:string}>  [method, url, link|form|script]
     */
    private function targetsIn(string $html): array
    {
        $out = [];
        $local = fn (string $u): bool => $u !== '' && ! str_starts_with($u, '#')
            && preg_match('/^(javascript|mailto|tel|data):/i', $u) !== 1
            && (str_starts_with($u, '/') || str_starts_with($u, url('/')));

        preg_match_all('/<a\b[^>]*\bhref="([^"]*)"/i', $html, $am);

        foreach ($am[1] as $href) {
            $href = html_entity_decode($href);

            if ($local($href)) {
                $out[] = ['GET', $href, 'link'];
            }
        }

        preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/si', $html, $fm, PREG_SET_ORDER);

        $knownForms = [];

        foreach ($fm as [, $attrs, $body]) {
            if (! preg_match('/\baction="([^"]*)"/i', $attrs, $act)) {
                continue;   // بيتكتب من الجافاسكربت — بيتمسك تحت كـURL
            }

            // ⚠️ الفورم اللي مالوش زرار إرسال (ولا زرار بره بـ`form="id"`)
            // مش بيتبعت من المستخدم — فورم عرض مقفول. الزرار المخفي بالحارس
            // هو بالظبط التصليح المطلوب، فلازم نحترمه.
            $formId = preg_match('/\bid="([^"]+)"/i', $attrs, $idm) ? $idm[1] : null;
            $hasSubmit = preg_match('/<button\b(?![^>]*\btype="(?:button|reset)")|<input\b[^>]*\btype="(?:submit|image)"/i', $body) === 1
                || ($formId !== null && preg_match('/<button\b[^>]*\bform="'.preg_quote($formId, '/').'"/i', $html) === 1);

            // action الفورم معروف حتى لو الفورم مقفول — عشان مسح الجافاسكربت تحت مايعيدوش
            $knownForms[] = html_entity_decode($act[1]);

            if (! $hasSubmit) {
                continue;
            }

            $action = html_entity_decode($act[1]);

            if (! $local($action)) {
                continue;
            }

            $method = preg_match('/\bmethod="([A-Za-z]+)"/i', $attrs, $mm) ? strtoupper($mm[1]) : 'GET';

            // `@method('PUT')` ⇐ `<input type="hidden" name="_method" value="PUT">`
            if (preg_match('/<input\b[^>]*name="_method"[^>]*value="([A-Za-z]+)"|<input\b[^>]*value="([A-Za-z]+)"[^>]*name="_method"/i', $body, $om)) {
                $method = strtoupper($om[1] !== '' ? $om[1] : $om[2]);
            }

            $out[] = [$method, $action, $method === 'GET' ? 'link' : 'form'];
        }

        // URLs داخلية بين كوتس في الجافاسكربت (`fetch('/erp/…')`، `BASE_URL`،
        // `location.href = …`) — الـplaceholders (`__ID__`) بتتبدل برقم.
        // ⚠️ اللي ظهر فوق كلينك أو action فورم مش بيتعاد هنا: فورم العرض
        // المقفول (من غير زرار) action بتاعه لسه في الـHTML وده مش زرار.
        $known = [];

        foreach (array_merge(array_column($out, 1), $knownForms) as $seenUrl) {
            $known[$this->pathOf($seenUrl)] = true;
        }

        preg_match_all('~["\']((?:https?://[^/"\']+)?/(?:erp|wh|ops|online|notifications|gl)(?:/[^"\'\s<>]*)?)["\']~', $html, $jm);

        foreach (array_unique($jm[1]) as $u) {
            $u = html_entity_decode($u);

            if (isset($known[$this->pathOf($u)])) {
                continue;
            }
            $u = (string) preg_replace('/__[A-Z_]+__|\{[^}]*\}|\$\{[^}]*\}/', '1', $u);

            if ($local($u)) {
                $out[] = ['ANY', $u, 'script'];
            }
        }

        return $out;
    }

    /** @return array<string, string>  view ⇒ path */
    private function bladeFiles(): array
    {
        $root = resource_path('views');
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $view = str_replace(['\\', '.blade.php'], ['/', ''], substr($file->getPathname(), strlen($root) + 1));
                $out[$view] = $file->getPathname();
            }
        }

        return $out;
    }

    /**
     * عالم فيه صف من كل حاجة — عشان الشاشات تطلّع لينكات الصفوف.
     *
     * ⚠️ الصفحة على داتابيز فاضية بتطلّع الهيدر والفلاتر بس. لينك
     * «كارت العميل» و«الفاتورة» و«أمر التوريد» و«ابدأ التجهيز» مابيظهروش
     * غير لما يكون فيه صف — وهما بالظبط اللينكات اللي اتكسرت قبل كده.
     *
     * @return array<string, User>  role ⇒ user
     */
    private function populatedWorld(): array
    {
        $branch = Branch::create([
            'code' => 'BR-'.strtoupper(uniqid()), 'name' => 'فرع التيست', 'name_en' => 'Test branch', 'active' => true,
        ]);
        $warehouse = $this->makeWarehouse();
        $zone = $this->makeZone();
        $channel = $this->seededChannel(Channel::CASH_VAN);
        $list = $this->makePriceList('new');
        $product = $this->makeProduct();
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $product->id, 'price' => 20]);

        $manager = $this->makeAdmin(['role' => 'manager', 'email' => 'mgr.crawl@test.local', 'channel_id' => $channel->id]);
        $branchManager = $this->makeAdmin(['role' => 'branch_manager', 'email' => 'bm.crawl@test.local', 'branch_id' => $branch->id]);
        $accountant = $this->makeAdmin(['role' => 'accountant', 'email' => 'acc.crawl@test.local']);
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper', 'email' => 'wk.crawl@test.local', 'warehouse_id' => $warehouse->id]);

        $rep = $this->makeRep([
            'manager_id' => $manager->id, 'branch_id' => $branch->id, 'zone_id' => $zone->id,
            'channel_id' => $channel->id, 'warehouse_id' => $warehouse->id,
        ]);
        $rep->zones()->attach($zone->id);
        $driver = $this->makeRep(['role' => 'driver', 'manager_id' => $manager->id, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id]);
        $promoter = $this->makeRep(['role' => 'promoter', 'manager_id' => $manager->id, 'branch_id' => $branch->id]);

        $client = $this->makeClient([
            'zone_id' => $zone->id, 'rep_id' => $rep->id, 'manager_id' => $manager->id, 'branch_id' => $branch->id,
            'channel_id' => $channel->id, 'price_list_id' => $list->id, 'category' => 'grow',
            'return_policies' => [Client::RETURN_ACCOUNT],
        ]);
        Contract::create([
            'client_id' => $client->id, 'type' => 'test', 'discount' => 0.05, 'active' => true,
            'starts_at' => today()->subMonth(), 'ends_at' => today()->addYear(),
        ]);

        $group = ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة التيست', 'name_en' => 'Test chain',
            'channel_id' => $channel->id, 'active' => true,
        ]);
        $this->makeClient([
            'name' => 'فرع السلسلة', 'group_id' => $group->id, 'zone_id' => $zone->id, 'rep_id' => $rep->id,
            'manager_id' => $manager->id, 'branch_id' => $branch->id, 'channel_id' => $channel->id, 'price_list_id' => $list->id,
        ]);

        Lead::create([
            'number' => Lead::nextNumber(), 'name' => 'ليد التيست', 'name_en' => 'Test lead', 'phone' => '01000000001',
            'status' => 'new', 'zone_id' => $zone->id, 'channel_id' => $channel->id,
            'assigned_to' => $rep->id, 'manager_id' => $manager->id, 'created_by' => $manager->id,
        ]);
        ClientRequest::create([
            'number' => 'REQ-CRAWL', 'name' => 'طلب عميل جديد', 'status' => 'pending',
            'created_by' => $rep->id, 'zone_id' => $zone->id,
        ]);
        JourneyPlan::create(['user_id' => $rep->id, 'client_id' => $client->id, 'weekday' => 1, 'every_weeks' => 1, 'sort' => 1]);
        Task::create([
            'title' => 'مهمة التيست', 'assigned_to' => $rep->id, 'created_by' => $manager->id,
            'priority' => 'normal', 'status' => 'open',
        ]);
        Supplier::create(['code' => 'SUP-'.strtoupper(uniqid()), 'name' => 'مورد التيست', 'name_en' => 'Test supplier', 'active' => true]);

        $batch = Batch::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => 'B-CRAWL',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 100, 'qty_remaining' => 100, 'cost' => 10,
        ]);
        Stock::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'qty' => 100, 'hold_qty' => 0, 'good_qty' => 100]);

        $custody = Custody::create(['user_id' => $rep->id, 'warehouse_id' => $warehouse->id, 'date' => today(), 'status' => 'open']);
        CustodyItem::create(['custody_id' => $custody->id, 'product_id' => $product->id, 'batch_id' => $batch->id, 'assigned' => 50]);

        // فاتورة كاش حقيقية من الأبلكيشن + مرتجع عليها — عشان كشف الحساب
        // وشاشة الفاتورة والمرتجعات يطلّعوا صفوف
        $this->punchIn($rep);
        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 2]], ['payment' => 'cash'])->assertSuccessful();
        Returns::create(
            client: $client->fresh(),
            items: [['product_id' => $product->id, 'qty' => 1]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        $po = PurchaseOrder::create([
            'number' => 'PO-CRAWL', 'client_id' => $client->id, 'status' => 'pending',
            'assigned_to' => $driver->id, 'warehouse_id' => $warehouse->id,
            'total' => 40, 'tax_total' => 0, 'grand_total' => 40, 'created_by' => $manager->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $product->id, 'qty' => 2,
            'price' => 20, 'total' => 40, 'list_price' => 20, 'discount_pct' => 0, 'tax_rate' => 0, 'tax' => 0,
        ]);

        $replenishment = ReplenishmentRequest::create([
            'number' => 'RPL-CRAWL', 'client_id' => $client->id, 'requested_by' => $promoter->id, 'status' => 'pending',
        ]);
        ReplenishmentItem::create(['replenishment_request_id' => $replenishment->id, 'product_id' => $product->id, 'qty' => 3]);

        PickOrder::create([
            'number' => 'PK-CRAWL', 'warehouse_id' => $warehouse->id, 'requested_by' => $manager->id,
            'assigned_to' => $keeper->id, 'purpose' => PickOrder::PURPOSE_CUSTOMER_PO, 'status' => 'pending',
            'purchase_order_id' => $po->id,
        ]);

        return [
            'manager' => $manager,
            'branch_manager' => $branchManager,
            'accountant' => $accountant,
            'warehouse_keeper' => $keeper,
        ];
    }

    // ═══════════════ 6. الخريطة = `role:` middleware ═══════════════

    /**
     * قرار الخريطة لكل رول على كل راوت شاشة = قرار `role:` اللي على الراوت.
     *
     * ⚠️ الخريطة (`SCREENS` للشاشات، `ACTIONS` للزراير) هي اللي البليد
     * بيرسم منها، و`role:` middleware هو اللي بيمنع. لو اتفرقوا في راوت،
     * بيبقى فيه زرار أو لينك بيبان وبيرفض — وده بالظبط اللي الزحف فوق
     * لقاه في ٤٠ مكان. التيست ده بيمسك الفرق **عند المصدر** من غير
     * ما يحتاج داتا ولا رندر.
     *
     * الراوت من غير `role:` بيتحكم فيه الخريطة لوحدها، فمفيش حاجة
     * يتقارن بيها — بيتخطى.
     */
    public function test_the_access_map_agrees_with_the_role_middleware(): void
    {
        $disagree = [];

        // الراوت ⇒ الأكشن اللي بيغطيه (لو فيه)
        $actionOf = [];

        foreach (Access::ACTIONS as $key => [, , , $routes]) {
            foreach ($routes as $covered) {
                $actionOf[$covered] = $key;
            }
        }

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! $this->isScreenRoute($name)) {
                continue;
            }

            $gate = $this->roleGate($route);

            // ⚠️ النطاق = وعد الدوكترين: شاشات GET (الخريطة لازم تكون
            // مضبوطة عليها عشان مفيش لينك يودّي لـ403) وراوتات الزراير
            // المسجّلة في ACTIONS. أدوات الأدمن على الفاتورة والمسح وغيرها
            // بتتحرس في الفيو بـ`isAdmin()` ومش في الخريطة — بره النطاق.
            $isGet = in_array('GET', $route->methods(), true);

            if ($gate === null || (! $isGet && ! isset($actionOf[$name]))) {
                continue;
            }

            foreach (Access::WEB_ROLES as $role) {
                if ($role === 'admin') {
                    continue;
                }

                $user = new User(['role' => $role]);
                $byGate = in_array($role, $gate, true);

                // الزرار بيتحكم فيه `action()`، والشاشة بيتحكم فيها `allows()`
                $byMap = isset($actionOf[$name])
                    ? Access::action($user, $actionOf[$name])
                    : Access::allows($user, $name);

                if ($byMap !== $byGate) {
                    $disagree[] = sprintf('%-18s %-40s map=%s gate=%s%s',
                        $role, $name, $byMap ? 'yes' : 'no', $byGate ? 'yes' : 'no',
                        isset($actionOf[$name]) ? ' ('.$actionOf[$name].')' : '');
                }
            }
        }

        $this->assertSame([], $disagree,
            "الخريطة وmiddleware `role:` مختلفين — زرار بيبان وبيرفض، أو راوت مسموح ومخفي:\n  ".implode("\n  ", $disagree));
    }
}
