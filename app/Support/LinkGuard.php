<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * ═══════════════════════════════════════════════════════════════
 * «اللينك ده اليوزر يقدر يفتحه؟» — مصدر واحد (٢٢ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * مراجعة ٢٢/٩ ربطت كل حاجة ببعضها: اسم العميل بيفتح صفحته، المندوب صفحته،
 * رقم الأمر صفحته… في مئات الأماكن. والقاعدة القديمة لسه قايمة: **مفيش شاشة
 * توري يوزر لينك هيرميه على 403** (أمين المخزن مالوش صفحة العميل، والمدير
 * مالوش مندوب مش من فريقه). بدل `@if` على كل لينك في كل بليد — وأول واحد
 * يتنسي يكسر القاعدة — الفحص هنا مرة واحدة، و`GuardLinks` بيطبّقه على
 * الصفحة كلها وهي خارجة.
 *
 * الفحص نفس ترتيب `EnsureScreenAccess` + `EnsureRole` + سكوب الصف:
 *   1. خريطة الشاشات والأوفررايدز (`Access::allows`)
 *   2. بوابة `role:` على الراوت (وأوفررايد الرول بيغلبها)
 *   3. الصف نفسه: عميل مش مرئي له / مندوب مش من فريقه
 */
final class LinkGuard
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /** @var array<int, true>|null */
    private static ?array $clientIds = null;

    /** @var \Illuminate\Support\Collection<int, User>|null */
    private static $users = null;

    /** الكاش بعمر الطلب — `GuardLinks` بيصفّره في أول كل صفحة (العمال طويلة العمر والتيستات) */
    public static function reset(): void
    {
        self::$cache = [];
        self::$clientIds = null;
        self::$users = null;
    }

    public static function canOpen(User $user, string $url): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $path = (string) parse_url(html_entity_decode($url), PHP_URL_PATH);

        if ($path === '' || $path[0] !== '/') {
            return true;
        }

        return self::$cache[$user->id.'|'.$path] ??= self::check($user, $path);
    }

    private static function check(User $user, string $path): bool
    {
        $route = rescue(fn () => Route::getRoutes()->match(Request::create($path, 'GET')), null, false);

        if (! $route instanceof RoutingRoute || ! ($name = $route->getName())) {
            return true;   // مش راوت عندنا (ملف، صورة، رابط خارجي) — مش شغلنا
        }

        if (! Access::allows($user, $name)) {
            return false;
        }

        if (($override = Access::userOverride($user, $name)) !== null) {
            return $override;
        }

        $roleOverride = Access::roleOverride($user->role, $name);

        if ($roleOverride === false) {
            return false;
        }

        if ($roleOverride === null) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'role:')
                    && ! in_array($user->role, explode(',', substr($middleware, 5)), true)) {
                    return false;
                }
            }
        }

        return self::rowAllowed($user, $route);
    }

    /** سكوب الصف — العميل والمندوب بس؛ دول اللي اللينكات الجديدة بتشاور عليهم */
    private static function rowAllowed(User $user, RoutingRoute $route): bool
    {
        $params = $route->parameters();

        if (isset($params['client']) && ctype_digit((string) $params['client'])) {
            self::$clientIds ??= array_fill_keys(
                Client::visibleTo(Client::query(), $user)->pluck('clients.id')->all(), true);

            if (! isset(self::$clientIds[(int) $params['client']])) {
                return false;
            }
        }

        if (isset($params['user']) && ctype_digit((string) $params['user'])
            && ! str_starts_with((string) $route->getName(), 'erp.activity')) {
            self::$users ??= User::all()->keyBy('id');

            if (! Scope::canRep($user, self::$users[(int) $params['user']] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
