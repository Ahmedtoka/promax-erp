<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureScreenAccess;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * لقطة ثابتة لقرارات الصلاحيات من غير ولا صف استثناء (مراجعة ٩/١١).
 *
 * لكل رول أساسي غير الأدمن: قرار كل راوت ويب متسمّي عن طريق
 * EnsureScreenAccess + EnsureRole الحقيقيين، `Access::action` لكل زرار،
 * لينكات `navFor`، و`home()` — ومقارنتها بـ tests/fixtures/permission-parity.json.
 *
 * ⚠️ اللقطة اتولدت من كود اتراجع إنه = سلوك ما قبل الرولز المخصصة
 * (الفرق الوحيد erp.leads.store/update لمدير الفرع، وكانوا 403 من الكنترولر).
 *
 * إعادة التوليد (عن قصد بس، بعد ما تتأكد إن التغيير في السلوك مقصود):
 *   PowerShell: $env:PERMISSION_PARITY_REGENERATE='1'; php artisan test --filter=PermissionParitySnapshotTest; Remove-Item Env:PERMISSION_PARITY_REGENERATE
 *   Bash:       PERMISSION_PARITY_REGENERATE=1 php artisan test --filter=PermissionParitySnapshotTest
 * وراجع الـ diff بتاع الملف في git قبل الكوميت.
 *
 * الراوتس/الأزرار الجديدة اللي مش في اللقطة بتتجاهل (القرار القديم بس اللي
 * محروس)؛ الناف و`home()` بيتقارنوا بالظبط.
 */
class PermissionParitySnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'tests/fixtures/permission-parity.json';

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    public function test_zero_override_decisions_match_snapshot(): void
    {
        $this->assertSame(0, RolePermission::count());

        $current = $this->record();
        $path = base_path(self::FIXTURE);

        if (getenv('PERMISSION_PARITY_REGENERATE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestSkipped('permission-parity.json regenerated');
        }

        $this->assertFileExists($path, 'اللقطة مش موجودة — ولّدها (شوف تعليق الكلاس)');
        $snap = json_decode((string) file_get_contents($path), true);

        $routes = array_values(array_intersect($snap['routes'], $current['routes']));
        $actions = array_values(array_intersect($snap['actions'], $current['actions']));
        $bad = [];

        foreach ($snap['roles'] as $role => $s) {
            $c = $current['roles'][$role] ?? null;

            if ($c === null) {
                $bad[] = "{$role}: role missing";

                continue;
            }

            $was = array_flip($s['routes_allowed']);
            $now = array_flip($c['routes_allowed']);

            foreach ($routes as $r) {
                if (isset($was[$r]) !== isset($now[$r])) {
                    $bad[] = "{$role} route {$r}: ".(isset($was[$r]) ? 'allowed' : 'denied').' → '.(isset($now[$r]) ? 'allowed' : 'denied');
                }
            }

            $was = array_flip($s['actions_allowed']);
            $now = array_flip($c['actions_allowed']);

            foreach ($actions as $a) {
                if (isset($was[$a]) !== isset($now[$a])) {
                    $bad[] = "{$role} action {$a}: ".(isset($was[$a]) ? 'on' : 'off').' → '.(isset($now[$a]) ? 'on' : 'off');
                }
            }

            if ($s['nav'] !== $c['nav']) {
                $bad[] = "{$role} nav: -[".implode(',', array_diff($s['nav'], $c['nav'])).'] +['.implode(',', array_diff($c['nav'], $s['nav'])).']';
            }

            if ($s['home'] !== $c['home']) {
                $bad[] = "{$role} home: {$s['home']} → {$c['home']}";
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    /** @return array{routes:list<string>, actions:list<string>, roles:array<string, array<string, mixed>>} */
    private function record(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name !== null && in_array('web', $route->gatherMiddleware(), true)) {
                $routes[$name] = $route;
            }
        }

        ksort($routes);
        $actions = array_keys(Access::ACTIONS);
        sort($actions);

        $out = ['routes' => array_keys($routes), 'actions' => $actions, 'roles' => []];

        foreach (array_keys(User::ROLES) as $role) {
            if ($role === 'admin') {
                continue;
            }

            $user = new User(['role' => $role, 'name' => 'parity '.$role]);
            $this->actingAs($user);

            $allowed = [];

            foreach ($routes as $name => $route) {
                if ($this->decide($route) === 200) {
                    $allowed[] = $name;
                }
            }

            $nav = [];

            foreach (Access::navFor($user) as $links) {
                foreach ($links as $l) {
                    $nav[] = $l[0];
                }
            }

            $out['roles'][$role] = [
                'home' => Access::home($user),
                'nav' => $nav,
                'actions_allowed' => array_values(array_filter($actions, fn (string $k) => Access::action($user, $k))),
                'routes_allowed' => $allowed,
            ];
        }

        return $out;
    }

    /** القرار عن طريق الميدل ويرز الحقيقية بنفس الترتيب: screen ثم كل role: */
    private function decide(RoutingRoute $route): int
    {
        $request = Request::create('/_parity_probe');
        $request->setRouteResolver(fn () => $route);
        $ok = fn () => response('ok');

        try {
            foreach ($route->gatherMiddleware() as $m) {
                if ($m === 'screen') {
                    $res = (new EnsureScreenAccess)->handle($request, $ok);
                } elseif (is_string($m) && str_starts_with($m, 'role:')) {
                    $res = (new EnsureRole)->handle($request, $ok, ...explode(',', substr($m, 5)));
                } else {
                    continue;
                }

                if ($res->getStatusCode() !== 200) {
                    return $res->getStatusCode();
                }
            }
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return 200;
    }
}
