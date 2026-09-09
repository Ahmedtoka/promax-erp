<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReportController;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * ═══════════════════════════════════════════════════════════════
 * زحف كل شاشة بكل رول — على نسخة QA (٩/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بيفتح كل راوت GET محمي بـ`auth` كأنه المستخدم (أول مستخدم نشط بالرول)،
 * بيحل البارامترات من آخر صف حقيقي في الجدول، وبيتبع اللينكات الداخلية
 * اللي في كل صفحة 200. بيطلّع جدول: الحالة، الوقت، عدد الكويريات،
 * والاستثناء لو 500. الهدف: 500 مخفي على داتا حقيقية، وصفحات تقيلة
 * (N+1) — التيستات بفيكستشر صغير مابتشوفهاش.
 *
 * ⚠️ **مايشتغلش على `promax`** (نسخة اللايف المحلية) ولا في production:
 * الزحف GET بس، لكن القاعدة إن أي أداة فحص تمشي على `promax_qa`.
 *
 *   php artisan promax:crawl --env=qa
 *   php artisan promax:crawl --env=qa --roles=admin,accountant --links=100 --slow=800
 */
class CrawlScreens extends Command
{
    protected $signature = 'promax:crawl
        {--roles=admin,manager,accountant,warehouse_keeper,branch_manager,sales_agent : الرولز المفصولة بفاصلة}
        {--links=300 : أقصى عدد لينكات داخلية تتبعها لكل رول}
        {--slow=1500 : الصفحة أبطأ من كده (ms) تتعلّم ⚠️}
        {--queries=200 : الصفحة فوق كده كويريات تتعلّم ⚠️}';

    protected $description = 'زحف كل راوت GET بكل رول على نسخة QA — 500 مخفي وصفحات تقيلة';

    /** @var array<string, true> */
    private array $seen = [];

    /** @var list<array{0:string,1:string,2:string,3:int|string,4:int,5:int,6:string}> */
    private array $rows = [];

    public function handle(Kernel $kernel): int
    {
        $db = (string) config('database.connections.'.config('database.default').'.database');

        if (app()->isProduction() || $db === 'promax') {
            $this->error("ممنوع على {$db}/".app()->environment().' — شغّله بـ --env=qa على promax_qa.');

            return self::FAILURE;
        }

        config(['app.debug' => true]);
        $maxLinks = (int) $this->option('links');

        foreach (explode(',', (string) $this->option('roles')) as $role) {
            $role = trim($role);
            $user = User::where('role', $role)->where('active', true)->orderBy('id')->first();

            if (! $user) {
                $this->warn("مفيش مستخدم نشط بالرول {$role}");

                continue;
            }

            $this->line("== {$role} ({$user->code})");
            $queue = [];

            foreach (Route::getRoutes() as $route) {
                if (! in_array('GET', $route->methods(), true)) {
                    continue;
                }
                $uri = $route->uri();
                if (str_starts_with($uri, 'api/') || str_starts_with($uri, '_') || str_starts_with($uri, 'storage/') || $uri === 'up') {
                    continue;
                }
                if (! collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with((string) $m, 'auth'))) {
                    continue;
                }
                $params = $this->resolveParams($route);
                if ($params === null) {
                    $this->rows[] = [$role, (string) $route->getName(), $uri, 'UNRESOLVED', 0, 0, 'مفيش صف في الجدول'];

                    continue;
                }
                $url = '/'.ltrim(str_replace(
                    array_map(fn ($k) => '{'.$k.'}', array_keys($params)), array_values($params),
                    preg_replace('/\{(\w+)\?\}/', '{$1}', $uri),
                ), '/');
                $queue[] = [$url, (string) ($route->getName() ?? $uri)];
            }

            $links = [];
            foreach ($queue as [$url, $label]) {
                $html = $this->hit($kernel, $url, $user, $label);
                if ($html === null || str_contains($url, 'export') || str_contains($url, 'excel')) {
                    continue;
                }
                preg_match_all('/href="([^"#]+)"/', $html, $m);
                foreach ($m[1] as $href) {
                    $href = html_entity_decode($href);
                    $host = rtrim((string) config('app.url'), '/');
                    if ($host && str_starts_with($href, $host)) {
                        $href = substr($href, strlen($host));
                    }
                    if (str_starts_with($href, '/') && ! preg_match('#^/(storage|brand|img)/#', $href)) {
                        $links[$href] = 'link← '.$label;
                    }
                }
            }
            $n = 0;
            foreach ($links as $url => $label) {
                if (isset($this->seen[$role.'|'.$url])) {
                    continue;
                }
                if (++$n > $maxLinks) {
                    break;
                }
                $this->hit($kernel, $url, $user, $label);
            }
        }

        return $this->report();
    }

    private function hit(Kernel $kernel, string $url, User $user, string $label): ?string
    {
        $key = $user->role.'|'.$url;
        if (isset($this->seen[$key])) {
            return null;
        }
        $this->seen[$key] = true;

        $req = Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
        Auth::guard('web')->setUser($user);
        $req->setUserResolver(fn () => $user);

        DB::enableQueryLog();
        $t = microtime(true);
        $content = null;
        try {
            $res = $kernel->handle($req);
            $status = $res->getStatusCode();
            $exc = $res->exception ?? null;
            $msg = $exc ? get_class($exc).': '.mb_substr(str_replace("\n", ' ', $exc->getMessage()), 0, 160).' @ '.basename($exc->getFile()).':'.$exc->getLine() : '';
            $content = $status === 200 ? $res->getContent() : null;
            $kernel->terminate($req, $res);
        } catch (Throwable $e) {
            $status = 'EXC';
            $msg = get_class($e).': '.mb_substr($e->getMessage(), 0, 160).' @ '.basename($e->getFile()).':'.$e->getLine();
        }
        $ms = (int) round((microtime(true) - $t) * 1000);
        $q = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->rows[] = [$user->role, $label, $url, $status, $ms, $q, $msg];

        return $content;
    }

    /** بارامترات الراوت من آخر صف حقيقي — null لو الجدول فاضي أو النوع مش معروف */
    private function resolveParams($route): ?array
    {
        $params = [];
        foreach ($route->parameterNames() as $name) {
            $sig = collect($route->signatureParameters())->first(fn ($p) => $p->getName() === $name);
            $type = $sig?->getType();
            $class = $type && ! $type->isBuiltin() ? $type->getName() : null;
            if ($class && is_subclass_of($class, Model::class)) {
                $m = $class::query()->orderByDesc((new $class)->getKeyName())->first();
                if (! $m) {
                    return null;
                }
                $field = $route->bindingFieldFor($name);
                $params[$name] = $field ? $m->{$field} : $m->getRouteKey();

                continue;
            }
            if ($name === 'key') {
                $keys = array_keys((new \ReflectionClass(ReportController::class))->getConstant('REPORTS') ?: []);
                if ($keys) {
                    $params[$name] = $keys[0];

                    continue;
                }
            }

            return null;
        }

        return $params;
    }

    private function report(): int
    {
        $slow = (int) $this->option('slow');
        $heavy = (int) $this->option('queries');
        $ok = [200, 302, 403, 404];
        $bad = 0;
        $flagged = [];

        foreach ($this->rows as [$role, $label, $url, $status, $ms, $q, $msg]) {
            $isBad = ! in_array($status, $ok, true) && $status !== 'UNRESOLVED';
            if ($isBad) {
                $bad++;
            }
            if ($isBad || $ms >= $slow || $q >= $heavy) {
                $flagged[] = [$role, $label, $url, (string) $status, $ms, $q, $msg];
            }
        }

        $byStatus = [];
        foreach ($this->rows as $r) {
            $byStatus[(string) $r[3]] = ($byStatus[(string) $r[3]] ?? 0) + 1;
        }
        $this->info(count($this->rows).' طلب — '.collect($byStatus)->map(fn ($n, $s) => "$s: $n")->implode(' · '));

        if ($flagged) {
            $this->table(['role', 'route', 'url', 'status', 'ms', 'queries', 'error'], $flagged);
        } else {
            $this->info('مفيش 500 ولا صفحة تقيلة.');
        }

        return $bad > 0 ? self::FAILURE : self::SUCCESS;
    }
}
