<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * جرد الصلاحيات (٩/١١) — مفيش زرار ولا شاشة بره المصفوفة
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ المصفوفة بتعرض مجموعة ← صفحة ← شاشات (`screensOf`) ← أزرار (`act.*`).
 * أي راوت كتابة تحت صفحة ومش مغطّي بزرار = حاجة الأدمن مايقدرش يمنحها
 * ولا يمنعها. التيست ده بيطلّع القايمة بالاسم أول ما راوت جديد يتضاف.
 */
class PermissionInventoryTest extends TestCase
{
    use RefreshDatabase;

    /** كل راوت كتابة متسمّي تحت صفحة (NAV أو الشاشات العامة) = أكشن أو معفي أو مقفول */
    public function test_every_write_route_under_a_page_is_an_action_or_exempt(): void
    {
        $offenders = [];

        foreach ($this->writeRoutes() as $name => $route) {
            if ($this->pageOf($name) === null || $this->managed($name)) {
                continue;
            }
            $offenders[] = $name.'  ['.$this->pageOf($name).']  '.implode(',', $this->roleGate($route) ?? ['-']);
        }

        sort($offenders);
        $this->assertSame([], $offenders,
            "راوتس كتابة تحت صفحة من غير أكشن ولا إعفاء:\n  ".implode("\n  ", $offenders));
    }

    /** وراوتس الكتابة اللي ورا `screen` بره أي صفحة كمان — مفيش حاجة تفلت من المصفوفة */
    public function test_no_screen_write_route_outside_pages_is_unmanaged(): void
    {
        $offenders = [];

        foreach ($this->writeRoutes() as $name => $route) {
            if (! in_array('screen', $route->gatherMiddleware(), true) || $this->managed($name)) {
                continue;
            }
            $offenders[] = $name;
        }

        sort($offenders);
        $this->assertSame([], $offenders,
            "راوتس كتابة ورا `screen` من غير أكشن ولا إعفاء:\n  ".implode("\n  ", $offenders));
    }

    /**
     * افتراضي كل أكشن = `role:` اللي على راوتاته: أي رول ويب الزرار بيبان له
     * بالافتراضي لازم يعدّي من بوابة الراوت (وإلا زرار بيبان ويرمي 403).
     */
    public function test_new_action_defaults_match_route_middleware(): void
    {
        $routes = Route::getRoutes();
        $bad = [];

        foreach (Access::ACTIONS as $key => [, , , $covered]) {
            foreach ($covered as $name) {
                $route = $routes->getByName($name);

                if ($route === null) {
                    $bad[] = "$key → $name (راوت مش موجود)";
                    continue;
                }

                $gate = $this->roleGate($route);

                if ($gate === null) {
                    continue;
                }

                foreach (Access::WEB_ROLES as $role) {
                    if ($role !== 'admin' && Access::action(new User(['role' => $role]), $key) && ! in_array($role, $gate, true)) {
                        $bad[] = "$key → $name: $role";
                    }
                }
            }
        }

        $this->assertSame([], $bad, "أكشن افتراضيه أوسع من بوابة راوته:\n  ".implode("\n  ", $bad));
    }

    public function test_screens_of_clients_include_show_but_not_writes(): void
    {
        $screens = Access::screensOf('erp.clients');

        $this->assertContains('erp.clients.show', $screens);
        $this->assertContains('erp.clients.statement_csv', $screens);
        $this->assertNotContains('erp.clients', $screens);
        // كتابة / مغطّي بأكشن / صفحة NAV لوحدها
        $this->assertNotContains('erp.clients.store', $screens);
        $this->assertNotContains('erp.clients.new', $screens);
        $this->assertNotContains('erp.clients.activate', $screens);
        $sorted = $screens;
        sort($sorted);
        $this->assertSame($sorted, $screens);
    }

    /** الشاشات GET بس، مش مقفولة، ومش مغطّية بأكشن — لكل صفحة */
    public function test_screens_are_get_unlocked_and_uncovered(): void
    {
        $routes = Route::getRoutes();
        $bad = [];

        foreach ($this->pages() as $page) {
            foreach (Access::screensOf($page) as $screen) {
                $route = $routes->getByName($screen);

                if ($route === null || ! in_array('GET', $route->methods(), true)
                    || Access::isLocked($screen) || $this->covered($screen)) {
                    $bad[] = "$page → $screen";
                }
            }
        }

        $this->assertSame([], $bad);
        $this->assertSame([], Access::screensOf('erp.perms'), 'المقفول مالوش شاشات');
    }

    /** الإعفاء: راوت كتابة موجود فعلاً، مش مغطّي بأكشن، وبسبب مكتوب */
    public function test_exempt_entries_are_real_uncovered_writes_with_a_reason(): void
    {
        $writes = $this->writeRoutes();
        $bad = [];

        foreach (Access::ACTION_EXEMPT as $name => $reason) {
            if (! isset($writes[$name]) || $this->covered($name) || trim($reason) === '') {
                $bad[] = $name;
            }
        }

        $this->assertSame([], $bad);
    }

    /** الشاشات العامة: بره NAV، ليها راوت، وعنوان المجموعة مترجم */
    public function test_public_pages_are_outside_nav_and_reachable(): void
    {
        $names = array_keys($this->allRoutes());
        $bad = [];

        foreach (Access::PUBLIC_PAGES as $page) {
            $hit = array_filter($names, fn (string $n) => $n === $page || str_starts_with($n, $page.'.'));

            if ($hit === [] || Access::groupOf($page) !== null) {
                $bad[] = $page;
            }
        }

        $this->assertSame([], $bad);
        $this->assertSame('nav.group_public', Access::PUBLIC_GROUP);

        foreach (['ar', 'en'] as $locale) {
            $this->assertNotSame('nav.group_public', __('nav.group_public', [], $locale));
        }
    }

    /** كل أكشن ليه عنوان في اللغتين */
    public function test_every_action_has_a_label_in_both_languages(): void
    {
        $missing = [];

        foreach (Access::ACTIONS as $key => [$label]) {
            foreach (['ar', 'en'] as $locale) {
                if (__($label, [], $locale) === $label) {
                    $missing[] = "$locale: $key";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    // ═══════════════ مساعدات ═══════════════

    /** @return array<string, \Illuminate\Routing\Route> */
    private function allRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            if (($name = $route->getName()) !== null) {
                $out[$name] = $route;
            }
        }

        return $out;
    }

    /** @return array<string, \Illuminate\Routing\Route> */
    private function writeRoutes(): array
    {
        return array_filter($this->allRoutes(), fn ($r) => ! in_array('GET', $r->methods(), true));
    }

    /** @return list<string> */
    private function pages(): array
    {
        $pages = Access::PUBLIC_PAGES;

        foreach (Access::NAV as $links) {
            foreach ($links as $link) {
                $pages[] = $link[0];
            }
        }

        return $pages;
    }

    private function pageOf(string $name): ?string
    {
        $best = null;

        foreach ($this->pages() as $page) {
            if (($name === $page || str_starts_with($name, $page.'.')) && strlen($page) > strlen((string) $best)) {
                $best = $page;
            }
        }

        return $best;
    }

    private function covered(string $name): bool
    {
        foreach (Access::ACTIONS as [, , , $routes]) {
            if (in_array($name, $routes, true)) {
                return true;
            }
        }

        return false;
    }

    private function managed(string $name): bool
    {
        return $this->covered($name) || isset(Access::ACTION_EXEMPT[$name]) || Access::isLocked($name);
    }

    /** @return list<string>|null */
    private function roleGate(\Illuminate\Routing\Route $route): ?array
    {
        $gate = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (str_starts_with($middleware, 'role:')) {
                $roles = explode(',', substr($middleware, 5));
                $gate = $gate === null ? $roles : array_values(array_intersect($gate, $roles));
            }
        }

        return $gate;
    }
}
