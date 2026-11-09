<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * الرول المخصص (٩/١١) — طبقة بين استثناء اليوزر واستثناء الرول الأساسي،
 * والمفاتيح المقفولة (LOCKED) اللي مفيش صف يفتحها لحد.
 */
class CustomRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    private function role(array $o = []): Role
    {
        return Role::create($o + [
            'name_ar' => 'مدير المخزن', 'name_en' => 'Stock Lead',
            'base_role' => 'warehouse_keeper', 'web_login' => true, 'app_login' => false,
        ]);
    }

    private function userOn(string $base, ?Role $role = null): User
    {
        $u = User::factory()->create(['role' => $base, 'code' => 'T-'.strtoupper(uniqid())]);

        if ($role !== null) {
            $u->custom_role_id = $role->id;
            $u->save();
        }

        return $u->fresh();
    }

    private function rolePerm(string $role, string $perm, bool $allow): void
    {
        RolePermission::create(['role' => $role, 'perm' => $perm, 'allow' => $allow]);
        RolePermission::flush();
    }

    /** EnsureRole مباشرة على ريكوست مربوط باسم الراوت — بيرجع كود الحالة */
    private function gate(User $u, string $routeName, string ...$roles): int
    {
        $this->actingAs($u);
        $request = Request::create('/_gate_probe');
        $route = (new RoutingRoute(['GET'], '/_gate_probe', fn () => 'ok'))->name($routeName);
        $request->setRouteResolver(fn () => $route);

        try {
            return (new EnsureRole)->handle($request, fn () => response('ok'), ...$roles)->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_custom_role_inherits_base_screens_live(): void
    {
        $role = $this->role();
        $u = $this->userOn('warehouse_keeper', $role);

        $this->assertTrue(Access::allows($u, 'wh.index'));
        $this->assertFalse(Access::allows($u, 'erp.reports'));

        // منح الرول الأساسي بعد الإنشاء بيوصل للمخصص (وراثة حيّة)
        $this->rolePerm('warehouse_keeper', 'erp.reports', true);

        $this->assertTrue(Access::allows($u, 'erp.reports'));
        $this->assertTrue(Access::effectiveFor($role->key(), 'erp.reports'));
    }

    public function test_custom_deny_beats_base_allow(): void
    {
        $role = $this->role();
        $this->rolePerm($role->key(), 'wh.index', false);

        $custom = $this->userOn('warehouse_keeper', $role);
        $plain = $this->userOn('warehouse_keeper');

        $this->assertFalse(Access::allows($custom, 'wh.index'));
        $this->assertTrue(Access::allows($plain, 'wh.index'));
        $this->assertFalse(Access::effectiveFor($role->key(), 'wh.index'));
        $this->assertTrue(Access::effectiveFor('warehouse_keeper', 'wh.index'));
        $this->assertSame(false, Access::customOverride($custom, 'wh.index'));
        $this->assertNull(Access::customOverride($plain, 'wh.index'));
    }

    public function test_custom_allow_passes_role_middleware(): void
    {
        $role = $this->role(['base_role' => 'accountant']);
        $this->rolePerm($role->key(), 'erp.purchasing.approve', true);

        $custom = $this->userOn('accountant', $role);
        $plain = $this->userOn('accountant');

        $this->assertSame(200, $this->gate($custom, 'erp.purchasing.approve', 'admin'));
        $this->assertSame(403, $this->gate($plain, 'erp.purchasing.approve', 'admin'));

        // والمنع الصريح بيقفل حتى لو `role:` سامح
        $this->rolePerm($role->key(), 'erp.clients', false);
        $this->assertSame(403, $this->gate($custom, 'erp.clients', 'accountant'));
    }

    public function test_user_override_beats_custom(): void
    {
        $role = $this->role();
        $this->rolePerm($role->key(), 'wh.index', true);
        $u = $this->userOn('warehouse_keeper', $role);

        UserPermission::create(['user_id' => $u->id, 'perm' => 'wh.index', 'allow' => false]);
        $u = $u->fresh();

        $this->assertFalse(Access::allows($u, 'wh.index'));
        $this->assertSame(403, $this->gate($u, 'wh.index', 'warehouse_keeper'));
    }

    public function test_custom_layer_applies_to_actions(): void
    {
        $role = $this->role(['base_role' => 'manager']);
        $custom = $this->userOn('manager', $role);
        $plain = $this->userOn('manager');

        $this->assertTrue(Access::action($custom, 'act.clients.create'));
        $this->assertTrue(Access::actionFor($role->key(), 'act.clients.create'));

        $this->rolePerm($role->key(), 'act.clients.create', false);

        $this->assertFalse(Access::action($custom, 'act.clients.create'));
        $this->assertTrue(Access::action($plain, 'act.clients.create'));
        $this->assertFalse(Access::actionFor($role->key(), 'act.clients.create'));
        $this->assertTrue(Access::actionFor('manager', 'act.clients.create'));

        // أدمن بس في الكود — الرول المخصص يقدر يمنحه
        $this->assertFalse(Access::actionFor($role->key(), 'act.prices.propagate'));
        $this->rolePerm($role->key(), 'act.prices.propagate', true);
        $this->assertTrue(Access::actionFor($role->key(), 'act.prices.propagate'));
        $this->assertTrue(Access::action($custom->fresh(), 'act.prices.propagate'));
    }

    public function test_locked_keys_ignore_override_rows(): void
    {
        $role = $this->role(['base_role' => 'manager']);

        foreach (['erp.perms', 'erp.roles.store', 'erp.worktime', 'agent.ask'] as $key) {
            $u = $this->userOn('manager', $role);

            UserPermission::create(['user_id' => $u->id, 'perm' => $key, 'allow' => true]);
            $this->rolePerm($role->key(), $key, true);
            $this->rolePerm('manager', $key, true);
            $u = $u->fresh();

            $this->assertTrue(Access::isLocked($key), "{$key} مش مقفول");
            $this->assertFalse(Access::allows($u, $key), "صف استثناء فتح {$key}");
            $this->assertSame(403, $this->gate($u, $key, 'admin'), "EnsureRole عدّى {$key}");
            $this->assertNull(Access::userOverride($u, $key));
            $this->assertNull(Access::customOverride($u, $key));
            $this->assertNull(Access::roleOverride('manager', $key));
            $this->assertFalse(Access::effectiveFor($role->key(), $key));
            $this->assertFalse(Access::effectiveFor('manager', $key));
        }

        // الأدمن لسه بيعدّي بوابة `role:admin` على الصلاحيات
        $admin = $this->makeAdmin();
        $this->assertTrue(Access::allows($admin, 'erp.perms'));
        $this->assertSame(200, $this->gate($admin, 'erp.perms', 'admin'));

        // نطاق القفل: بادئات بس، مش أي اسم شبهها
        $this->assertTrue(Access::isLocked('erp.perms.role.save'));
        $this->assertTrue(Access::isLocked('agent.action.confirm'));
        $this->assertFalse(Access::isLocked('erp.permsx'));
        $this->assertFalse(Access::isLocked('erp.agent.runs'));
        $this->assertFalse(Access::isLocked('nav.group_settings'));
        $this->assertFalse(Access::isLocked('act.clients.create'));
    }

    public function test_inactive_custom_role_behaves_as_base(): void
    {
        $role = $this->role();
        $this->rolePerm($role->key(), 'wh.index', false);
        $role->update(['active' => false]);

        $u = $this->userOn('warehouse_keeper', $role);

        $this->assertTrue(Access::allows($u, 'wh.index'));
        $this->assertNull(Access::customOverride($u, 'wh.index'));
    }

    public function test_web_login_flag(): void
    {
        $field = $this->role(['base_role' => 'sales_agent', 'web_login' => true]);
        $this->assertTrue(Access::isWebRole($this->userOn('sales_agent', $field)));
        $this->assertFalse(Access::isWebRole($this->userOn('sales_agent')));

        $noWeb = $this->role(['base_role' => 'accountant', 'web_login' => false]);
        $this->assertFalse(Access::isWebRole($this->userOn('accountant', $noWeb)));
        $this->assertTrue(Access::isWebRole($this->userOn('accountant')));
    }

    public function test_home_falls_back_when_base_home_denied(): void
    {
        $role = $this->role();
        $u = $this->userOn('warehouse_keeper', $role);
        $this->assertSame('wh.index', Access::home($u));

        $this->rolePerm($role->key(), 'wh.index', false);
        $home = Access::home($u);

        $first = array_values(Access::navFor($u))[0][0][0] ?? null;
        $this->assertNotNull($first);
        $this->assertSame($first, $home);
        $this->assertTrue(Access::allows($u, $home));

        // كل الأقسام مقفولة = صفحة «مفيش شاشات» مش 403
        foreach (array_keys(Access::NAV) as $group) {
            $this->rolePerm($role->key(), $group, false);
        }

        $this->assertSame([], Access::navFor($u));
        $this->assertSame('no_screens', Access::home($u));
        $this->assertTrue(Access::allows($u, 'no_screens'));
        $this->actingAs($u)->get(route('no_screens'))->assertOk()->assertSee(__('perm.no_screens_title'));
    }

    public function test_sidebar_matches_middleware_for_custom_roles(): void
    {
        $role = $this->role(['base_role' => 'accountant']);
        $this->rolePerm($role->key(), 'erp.reports', true);
        $this->rolePerm($role->key(), 'wh.index', true);
        $this->rolePerm($role->key(), 'erp.clients', false);
        $this->rolePerm('accountant', 'erp.stock', true);

        $u = $this->userOn('accountant', $role);
        $bad = [];

        foreach (Access::navFor($u) as $links) {
            foreach ($links as [$route, , , , ]) {
                if ($this->actingAs($u)->get(route($route))->getStatusCode() === 403) {
                    $bad[] = $route;
                }
            }
        }

        $this->assertNotEmpty(Access::navFor($u));
        $this->assertSame([], $bad, 'السايدبار بيوري الرول المخصص لينكات بترفضه: '.implode(', ', $bad));
    }
}
