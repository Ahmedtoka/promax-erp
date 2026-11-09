<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access;
use App\Support\LinkGuard;
use App\Support\WorkTimeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * راوتس الكتابة اللي عليها أكشن (قرار ٦، ٩/١١): صف الصفحة/البادئة مابيفتحهاش
 * ولا بيقفلها — صف الراوت بالظبط، أو صف الأكشن، أو افتراضي الأكشن.
 */
class ActionGovernedRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * راوتس الخريطة كانت بتعدّيها والكنترولر نفسه بيرفضها بنفس الأكشن —
     * القرار الفعلي (403) ماتغيرش، بس `allows` بقى يقول الحقيقة.
     */
    private const CONTROLLER_GATED = [
        // LeadController::canWrite/canTouch → Access::action('act.leads.manage')
        'erp.leads.store' => true,
        'erp.leads.update' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    private function customOn(string $base, array $rows): User
    {
        $role = Role::create([
            'name_ar' => 'رول تجربة', 'name_en' => 'Probe Role',
            'base_role' => $base, 'web_login' => true, 'app_login' => false,
        ]);

        foreach ($rows as $perm => $allow) {
            RolePermission::create(['role' => $role->key(), 'perm' => $perm, 'allow' => $allow]);
        }
        RolePermission::flush();

        $u = User::factory()->create(['role' => $base, 'code' => 'T-'.strtoupper(uniqid())]);
        $u->custom_role_id = $role->id;
        $u->save();

        return $u->fresh();
    }

    /** @return list<string>|null */
    private function roleGate(RoutingRoute $route): ?array
    {
        $gate = null;

        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'role:')) {
                $roles = explode(',', substr($m, 5));
                $gate = $gate === null ? $roles : array_values(array_intersect($gate, $roles));
            }
        }

        return $gate;
    }

    /** كل `role:` على الراوت الحقيقي عن طريق EnsureRole — بيرجع كود الحالة */
    private function gate(User $u, string $routeName): int
    {
        $route = Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($route, "راوت مش موجود: {$routeName}");
        $this->actingAs($u);

        $request = Request::create('/_gate_probe');
        $request->setRouteResolver(fn () => $route);

        foreach ($route->gatherMiddleware() as $m) {
            if (! is_string($m) || ! str_starts_with($m, 'role:')) {
                continue;
            }
            try {
                $code = (new EnsureRole)->handle($request, fn () => response('ok'), ...explode(',', substr($m, 5)))->getStatusCode();
            } catch (HttpException $e) {
                return $e->getStatusCode();
            }
            if ($code !== 200) {
                return $code;
            }
        }

        return 200;
    }

    public function test_page_grant_does_not_open_list_default_action_routes(): void
    {
        $u = $this->customOn('accountant', ['ops.journeys' => true]);

        $this->assertSame('act.field.wipe', Access::actionKeyFor('ops.journeys.wipe'));
        $this->assertTrue(Access::allows($u, 'ops.journeys'));
        $this->actingAs($u)->get(route('ops.journeys'))->assertOk();

        $this->assertFalse(Access::allows($u, 'ops.journeys.wipe'));
        $this->assertSame(403, $this->gate($u, 'ops.journeys.wipe'));
        $this->actingAs($u)->post(route('ops.journeys.wipe'))->assertForbidden();
        $this->assertFalse(Access::effectiveFor($u->permRoleKey(), 'ops.journeys.wipe'));
    }

    public function test_page_grant_opens_null_default_action_routes(): void
    {
        // act.field.plan: افتراضي null وصفحته ops.assignments — المحاسب مالوش ops. خالص
        $this->assertNull(Access::ACTIONS['act.field.plan'][2]);
        $this->assertSame('ops.assignments', Access::ACTIONS['act.field.plan'][1]);

        $plain = $this->customOn('accountant', []);
        $this->assertFalse(Access::allows($plain, 'ops.journeys.store'));
        $this->assertSame(403, $this->gate($plain, 'ops.journeys.store'));

        $u = $this->customOn('accountant', ['ops.assignments' => true]);
        $key = $u->permRoleKey();

        foreach (['ops.journeys.store', 'ops.assignments.assign', 'ops.geo.plan'] as $route) {
            $this->assertSame('act.field.plan', Access::actionKeyFor($route));
            $this->assertTrue(Access::allows($u, $route), $route);
            $this->assertTrue(Access::effectiveFor($key, $route), $route);
            $this->assertSame(200, $this->gate($u, $route), "EnsureRole رفض {$route}");
        }

        // نفس الصفحة مابتفتحش أكشن افتراضيه قايمة رولز (المسح `['manager']`)
        $this->assertFalse(Access::allows($u, 'ops.journeys.wipe'));
        $this->assertSame(403, $this->gate($u, 'ops.journeys.wipe'));
    }

    public function test_action_deny_beats_explicit_page_allow(): void
    {
        RolePermission::create(['role' => 'manager', 'perm' => 'ops.journeys', 'allow' => true]);
        RolePermission::create(['role' => 'manager', 'perm' => 'act.field.wipe', 'allow' => false]);
        RolePermission::flush();

        $manager = $this->makeAdmin(['role' => 'manager']);

        $this->assertTrue(Access::allows($manager, 'ops.journeys'));
        $this->assertFalse(Access::allows($manager, 'ops.journeys.wipe'));
        $this->assertFalse(Access::action($manager, 'act.field.wipe'));
        $this->assertFalse(Access::effectiveFor('manager', 'ops.journeys.wipe'));
        $this->assertSame(403, $this->gate($manager, 'ops.journeys.wipe'));

        $this->actingAs($manager)->get(route('ops.journeys'))
            ->assertOk()
            ->assertDontSee(route('ops.journeys.wipe'), false);
        $this->actingAs($manager)->post(route('ops.journeys.wipe'))->assertForbidden();
    }

    public function test_page_deny_does_not_close_covered_routes_but_group_deny_does(): void
    {
        // منع صفحة ops.journeys: صف البادئة مابيقفلش ops.journeys.store (أكشن
        // act.field.plan صفحته ops.assignments) — بس المسح صفحته ops.journeys
        // فافتراضيه («الرول ∧ شايف الصفحة») بيقفل
        $u = $this->customOn('manager', ['ops.journeys' => false]);
        $this->assertFalse(Access::allows($u, 'ops.journeys'));
        $this->assertTrue(Access::allows($u, 'ops.journeys.store'));
        $this->assertSame(200, $this->gate($u, 'ops.journeys.store'));
        $this->assertFalse(Access::allows($u, 'ops.journeys.wipe'));
        $this->assertSame(403, $this->gate($u, 'ops.journeys.wipe'));

        // منع القسم بيقفل كل حاجة تحته — حتى الراوتس المغطّية
        $g = $this->customOn('manager', ['nav.group_field' => false]);
        $this->assertFalse(Access::allows($g, 'ops.journeys.wipe'));
        $this->assertSame(403, $this->gate($g, 'ops.journeys.wipe'));

        // ومنح القسم مابيفتحش الراوتس المغطّية — الأكشن بيقرر
        $open = $this->customOn('accountant', ['nav.group_field' => true]);
        $this->assertTrue(Access::allows($open, 'ops.journeys'));
        $this->assertFalse(Access::allows($open, 'ops.journeys.wipe'));
        $this->assertSame(403, $this->gate($open, 'ops.journeys.wipe'));
    }

    public function test_exact_route_row_still_applies(): void
    {
        // منح الراوت بالاسم بيفتحه حتى لو الأكشن نفسه أدمن بس
        $u = $this->customOn('accountant', ['ops.journeys.wipe' => true]);
        $this->assertTrue(Access::allows($u, 'ops.journeys.wipe'));
        $this->assertTrue(Access::effectiveFor($u->permRoleKey(), 'ops.journeys.wipe'));
        $this->assertSame(200, $this->gate($u, 'ops.journeys.wipe'));

        // ومنعه بالاسم بيقفله حتى لو صف الأكشن بيمنح
        $d = $this->customOn('manager', ['act.field.wipe' => true, 'ops.journeys.wipe' => false]);
        $this->assertFalse(Access::allows($d, 'ops.journeys.wipe'));
        $this->assertSame(403, $this->gate($d, 'ops.journeys.wipe'));

        // منح الأكشن لوحده بيفتح الراوت ويعدّي `role:`
        $a = $this->customOn('branch_manager', ['act.field.wipe' => true]);
        $this->assertTrue(Access::allows($a, 'ops.journeys.wipe'));
        $this->assertSame(200, $this->gate($a, 'ops.journeys.wipe'));
    }

    public function test_link_guard_follows_the_same_rule(): void
    {
        // erp.clients.new (GET) مغطّي بـact.clients.create — افتراضيه مش للمحاسب
        $page = $this->customOn('accountant', ['erp.clients' => true]);
        $this->assertTrue(LinkGuard::canOpen($page, route('erp.clients')));
        $this->assertFalse(LinkGuard::canOpen($page, route('erp.clients.new')));
        $this->assertSame(403, $this->gate($page, 'erp.clients.new'));

        LinkGuard::reset();
        $granted = $this->customOn('accountant', ['act.clients.create' => true]);
        $this->assertTrue(LinkGuard::canOpen($granted, route('erp.clients.new')));
        $this->assertSame(200, $this->gate($granted, 'erp.clients.new'));
    }

    public function test_get_screens_under_an_action_prefix_follow_their_page(): void
    {
        $this->assertNull(Access::actionKeyFor('gl.golive.run.progress'));
        $this->assertNull(Access::actionKeyFor('wh.receipt.putaway.form'));
        $this->assertSame('act.gl.admin', Access::actionKeyFor('gl.golive.run'));
        $this->assertSame('act.wh.putaway', Access::actionKeyFor('wh.receipt.putaway'));

        $this->assertTrue(Access::allows(new User(['role' => 'accountant']), 'gl.golive.run.progress'));
        $this->assertTrue(Access::allows(new User(['role' => 'branch_manager']), 'wh.receipt.putaway.form'));
    }

    public function test_every_covered_route_has_one_action_and_no_action_page_is_covered(): void
    {
        $bad = [];

        foreach (Access::ACTIONS as $key => [, $page, , $covered]) {
            if (Access::actionKeyFor($page) !== null) {
                $bad[] = "{$key}: صفحته {$page} مغطّية بـ".Access::actionKeyFor($page);
            }
            foreach ($covered as $route) {
                $owners = array_keys(array_filter(Access::ACTIONS,
                    fn ($d) => (bool) array_filter($d[3], fn ($c) => $route === $c || str_starts_with($route, $c.'.'))));
                if (count($owners) !== 1) {
                    $bad[] = "{$route}: ".implode(',', $owners);
                }
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_zero_overrides_unchanged_for_all_routes_and_roles(): void
    {
        $bad = [];
        $users = [];

        foreach (array_keys(User::ROLES) as $role) {
            $users[$role] = new User(['role' => $role]);
        }

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || WorkTimeGate::covers($name) || $name === Access::NO_SCREENS) {
                continue;
            }

            $key = Access::isLocked($name) ? null : Access::actionKeyFor($name);
            $gate = $this->roleGate($route);
            $screen = in_array('screen', $route->gatherMiddleware(), true);

            foreach ($users as $role => $user) {
                if ($role === 'admin') {
                    continue;
                }

                // ⚠️ مستخدمو الميدان بيترفضوا قبل الخريطة لو الراوت ورا `screen`
                if ($screen && ! Access::isWebRole($user)) {
                    continue;
                }

                $now = Access::allows($user, $name);
                $byGate = $gate === null || in_array($role, $gate, true);

                // القرار الفعلي قبل التعديل = خريطة SCREENS (لو ورا `screen`) ∧ `role:`
                $before = (! $screen || Access::roleDefault($role, $name)) && $byGate;
                $after = (! $screen || $now)
                    && ($gate === null || $this->gateWith($user, $route, $gate) === 200);

                if ($before !== $after && ! isset(self::CONTROLLER_GATED[$name])) {
                    $bad[] = "effective {$role} {$name} before=".(int) $before.' after='.(int) $after;
                }

                if ($key === null) {
                    // مش مغطّي: allows والمصفوفة زي ما كانوا بالظبط
                    if ($now !== Access::roleDefault($role, $name) || Access::effectiveFor($role, $name) !== $now) {
                        $bad[] = "uncovered {$role} {$name}";
                    }

                    continue;
                }

                // مغطّي: allows = افتراضي الأكشن = المصفوفة
                $act = Access::action($user, $key);

                if ($now !== $act || Access::effectiveFor($role, $name) !== $act || Access::actionFor($role, $key) !== $act) {
                    $bad[] = "covered {$role} {$name} ({$key}) allows=".(int) $now.' action='.(int) $act;
                }
            }
        }

        $this->assertSame([], $bad, "قرار اتغير من غير أي صف:\n  ".implode("\n  ", array_slice($bad, 0, 80)).(count($bad) > 80 ? "\n  … +".(count($bad) - 80) : ''));
    }

    /** EnsureRole بقايمة رولز البوابة المجمّعة — من غير تسجيل دخول لكل يوزر */
    private function gateWith(User $user, RoutingRoute $route, array $roles): int
    {
        auth()->setUser($user);
        $request = Request::create('/_gate_probe');
        $request->setRouteResolver(fn () => $route);

        try {
            return (new EnsureRole)->handle($request, fn () => response('ok'), ...$roles)->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }
}
