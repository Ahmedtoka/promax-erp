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
                    || Access::isLocked($screen) || $this->coveredByPrefix($screen)) {
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

    /**
     * فحص ساكن للبليدات: أي `route('<راوت مغطّي بأكشن>')` لازم يبقى جوه `@if`
     * فيه `Access::action`/`Access::allows` (أو متغيّر في نفس الملف متاخد منهم).
     * من غيرها منع الأكشن بيرمي 403 والزرار لسه باين — والمنح مابيبيّنوش.
     */
    public function test_buttons_of_covered_routes_are_guarded_in_blades(): void
    {
        $keyOf = [];

        foreach (Access::ACTIONS as $key => [, , , $routes]) {
            if (in_array($key, self::LEGACY_UNAUDITED_KEYS, true)) {
                continue;
            }
            foreach ($routes as $route) {
                $keyOf[$route] = $key;
            }
        }

        $root = resource_path('views').DIRECTORY_SEPARATOR;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $bad = [];

        foreach ($files as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }

            $rel = str_replace('\\', '/', substr((string) $file, strlen($root)));
            $src = str_replace("\r\n", "\n", (string) file_get_contents((string) $file));
            $lines = explode("\n", $src);
            // متغيّر ⇒ المفتاح/الراوت اللي اتاخد منه (`$canX = Access::action(..., 'act.x')`)
            preg_match_all('/(\$\w+)\s*=[^;]*?Access::(?:action|allows)\([^;]*?[\'"]([a-z0-9_.]+)[\'"]\s*\)/', $src, $m, PREG_SET_ORDER);
            $guardVars = [];

            foreach ($m as [, $var, $bound]) {
                $guardVars[$var][] = $bound;
            }

            foreach ($lines as $i => $line) {
                if (! preg_match_all('/route\(\s*[\'"]([a-z0-9_.]+)[\'"]/', $line, $mm)) {
                    continue;
                }

                foreach ($mm[1] as $route) {
                    $key = $keyOf[$route] ?? null;

                    if ($key === null || isset(self::GUARD_ALLOW["$rel|$route"]) || isset(self::GUARD_ALLOW["$rel|$key"])) {
                        continue;
                    }

                    // الحارس على نفس السطر (`@unless (...) inert @endunless`) أو في `@if` فوقه
                    if (! $this->guarded([$line, ...$this->enclosingConditions($lines, $i)], $key, $guardVars, $keyOf)) {
                        $bad[] = "$rel:".($i + 1)." $route ({$keyOf[$route]})";
                    }
                }
            }
        }

        $this->assertSame([], $bad, "زراير راوتها مغطّي بأكشن ومش محروسة:\n  ".implode("\n  ", $bad));
    }

    /** إعفاءات الفحص الساكن: «الملف|الراوت» ⇒ السبب (لينك JS زرّاره محروس في مكان تاني...) */
    private const GUARD_ALLOW = [
        // الصفحة نفسها راوت مغطّي بنفس الأكشن — مابتتفتحش من غيره
        // («الملف|المفتاح» = راوتات المفتاح ده بس في الملف ده)
        'erp/client_form.blade.php|act.clients.create' => 'the form is reached only through erp.clients.new/clone (act.clients.create) or edit; the dupe check is its JS helper',
        'erp/quotation_form.blade.php|act.quotations.save' => 'erp.reports.quotation / quotations.edit are covered by act.quotations.save',
        'erp/kpi_setup.blade.php|act.kpi.setup' => 'erp.kpi.setup is covered by act.kpi.setup',
        'ops/return_new.blade.php|act.returns.create' => 'ops.returns.new is covered by act.returns.create',
        'ops/po_reprice_check.blade.php|act.ka.reprice' => 'ops.pos.reprice.check is covered by act.ka.reprice',
        // URL في الجافاسكربت — العنصر اللي بيستخدمه محروس في نفس الملف
        'erp/app_version.blade.php|erp.app_version.chunk' => 'JS upload URL used only by the upload form, which is inert without the key',
        'erp/client_locations.blade.php|erp.client_locations.confirm' => 'JS URL; the confirm controls are guarded with Access::allows(erp.client_locations.confirm)',
        'erp/review/_tabs.blade.php|erp.review.mark' => 'JS fetch URL; the ✓/✗ buttons in review/_mark are guarded with act.review.mark',
        'erp/zones.blade.php|erp.zones.store' => 'JS URL; the zone dialog and its openers are guarded with act.org.manage',
        'erp/zones.blade.php|erp.zones.update' => 'JS URL; the zone dialog and its openers are guarded with act.org.manage',
        'gl/coa.blade.php|gl.coa.update' => 'JS inline-edit URL; the server enforces act.gl.coa_draft (row actions and dialogs are guarded)',
        'gl/coa.blade.php|gl.coa.move' => 'JS move-under URL; the move form and buttons are guarded with act.gl.coa_draft',
        'ops/geo_planner.blade.php|ops.geo.plan' => 'JS config of the planner map; the server enforces act.field.plan',
        'ops/geo_planner.blade.php|ops.geo.unplan' => 'JS config of the planner map; the server enforces act.field.plan',
        'ops/geo_planner.blade.php|ops.geo.distribute' => 'JS config of the planner map; the server enforces act.field.plan',
        'ops/geo_planner.blade.php|ops.geo.assign' => 'JS config of the planner map; the server enforces act.field.plan',
    ];

    /** مفاتيح قبل جرد ٩/١١ لسه زرايرها ماتراجعتش — الفحص بيتخطاها لحد ما تتراجع */
    private const LEGACY_UNAUDITED_KEYS = [
        'act.clients.activate',
        'act.clients.collect',
        'act.clients.edit',
        'act.contracts.manage',
        'act.custody.handout',
        'act.gl.admin',
        'act.gl.post',
        'act.handover.meta',
        'act.hr.advances',
        'act.hr.employees',
        'act.hr.leave_balances',
        'act.hr.payroll',
        'act.hr.payroll_approve',
        'act.hr.payroll_pay',
        'act.hr.settings',
        'act.import.run',
        'act.ka.create',
        'act.ka.decide',
        'act.ka.edit',
        'act.leads.manage',
        'act.mfg.boms_edit',
        'act.mfg.specs_edit',
        'act.money.eta',
        'act.org.structure',
        'act.prices.activate',
        'act.prices.edit',
        'act.prices.propagate',
        'act.products.edit',
        'act.purchase_requests.create',
        'act.purchasing.manage',
        'act.purchasing.receive',
        'act.supplier_contracts.manage',
        'act.suppliers.manage',
        'act.suppliers.pay',
        'act.warehouses.manage',
        'act.wh.count',
        'act.wh.count_manage',
        'act.wh.pick',
        'act.wh.pick_raise',
        'act.wh.putaway',
        'act.wh.receive',
        'act.wh.transfer',
        'act.wh.transfer_receive',
        'act.wh.van_transfer',
    ];

    /**
     * الحارس لازم يشاور على **مفتاح الراوت نفسه**: `Access::action(..., '<المفتاح>')`،
     * أو `Access::allows(..., '<راوت تبع نفس المفتاح>')`، أو متغيّر متاخد من واحد منهم.
     *
     * @param array<string, list<string>> $guardVars
     * @param array<string, string> $keyOf
     */
    private function guarded(array $conditions, string $key, array $guardVars, array $keyOf): bool
    {
        $ours = fn (string $bound): bool => $bound === $key || ($keyOf[$bound] ?? null) === $key;

        foreach ($conditions as $c) {
            preg_match_all('/Access::(?:action|allows)\([^;]*?[\'"]([a-z0-9_.]+)[\'"]\s*\)/', $c, $mm);

            foreach ($mm[1] as $bound) {
                if ($ours($bound)) {
                    return true;
                }
            }

            foreach ($guardVars as $var => $bounds) {
                if (preg_match('/'.preg_quote($var, '/').'\b/', $c) && array_filter($bounds, $ours) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * شروط الـ`@if`/`@elseif`/`@unless` المفتوحة فوق السطر ده (من غير فرع `@else`).
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function enclosingConditions(array $lines, int $i): array
    {
        $conds = [];
        $depth = 0;
        $inElse = false;
        $re = '/@(endif|endunless|if|unless|elseif|else)\b(\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/';

        for ($j = $i; $j >= 0; $j--) {
            $text = $lines[$j];

            if ($j === $i) {
                $text = substr($text, 0, (int) strpos($text, 'route('));
            }

            preg_match_all($re, $text, $tokens, PREG_SET_ORDER);

            foreach (array_reverse($tokens) as $t) {
                $d = $t[1];

                if ($d === 'endif' || $d === 'endunless') {
                    $depth++;
                } elseif ($d === 'if' || $d === 'unless') {
                    if ($depth > 0) {
                        $depth--;
                    } else {
                        if (! $inElse) {
                            $conds[] = $this->fullCondition($lines, $j, $t[0]);
                        }
                        $inElse = false;
                    }
                } elseif ($depth === 0 && $d === 'elseif') {
                    if (! $inElse) {
                        $conds[] = $this->fullCondition($lines, $j, $t[0]);
                    }
                    $inElse = true;
                } elseif ($depth === 0 && $d === 'else') {
                    $inElse = true;
                }
            }
        }

        return $conds;
    }

    /** شرط `@if` اللي قوسه مابيتقفلش على نفس السطر — بيكمّل من السطور اللي بعده (لحد ٥) */
    private function fullCondition(array $lines, int $j, string $token): string
    {
        if (str_contains($token, '(')) {
            return $token;
        }

        return implode(' ', array_slice($lines, $j, 5));
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

    /** نفس مطابقة البادئة اللي `resolveMap` بيستخدمها وقت التشغيل */
    private function coveredByPrefix(string $name): bool
    {
        foreach (Access::ACTIONS as [, , , $routes]) {
            foreach ($routes as $c) {
                if ($name === $c || str_starts_with($name, $c.'.')) {
                    return true;
                }
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
