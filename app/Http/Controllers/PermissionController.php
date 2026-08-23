<?php

namespace App\Http\Controllers;

use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشة الصلاحيات — رول كامل أو يوزر بيوزر (2026-08-05 / 2026-08-23)
 * ═══════════════════════════════════════════════════════════════
 *
 * تلات طبقات بالترتيب:
 *   1. **الكود** (Access::SCREENS / ACTIONS) — الافتراضي.
 *   2. **الرول** (role_permissions — تاب «الرولز»، ٢٣/٨) — «المديرين
 *      كلهم يشوفوا عروض الأسعار» بتتقال مرة واحدة.
 *   3. **اليوزر** (user_permissions) — استثناء لموظف بعينه بيغلب الكل.
 *
 * «وراثة» في تاب الرولز = افتراضي الكود، وفي تاب الموظفين = افتراضي
 * الرول **بعد** استثناءاته — عشان الأدمن يشوف الحقيقة اللي اليوزر
 * هيورثها فعلاً مش نص الكود القديم.
 *
 * ⚠️ **مفيش استثناءات على أدمن.** الأدمن معاه كل حاجة دايماً.
 */
class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $roles = array_values(array_diff(Access::WEB_ROLES, ['admin']));

        $users = User::whereIn('role', Access::WEB_ROLES)
            ->where('role', '!=', 'admin')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'code']);

        // ═══ تاب الرولز — ?role=manager ═══
        $role = $request->query('role');

        if ($role !== null && in_array($role, $roles, true)) {
            return view('erp.perms', [
                'roles' => $roles,
                'role' => $role,
                'users' => $users,
                'user' => null,
                'tree' => $this->tree($role, RolePermission::mapFor($role), withRoleLayer: false),
            ]);
        }

        // ═══ تاب الموظفين — الافتراضي ═══
        $user = $request->filled('user')
            ? $users->firstWhere('id', (int) $request->input('user'))
            : $users->first();

        return view('erp.perms', [
            'roles' => $roles,
            'role' => null,
            'users' => $users,
            'user' => $user,
            // ⚠️ withRoleLayer — «وراثة» اليوزر لازم توري افتراضي
            // الرول بعد استثناءات الرول، مش نص الكود
            'tree' => $user ? $this->tree($user->role, $user->permMap(), withRoleLayer: true) : [],
        ]);
    }

    /**
     * الشجرة: قسم → صفحاته → أزرار صفحاته — لكل عنصر الافتراضي
     * اللي بيتورّث + الاستثناء الحالي من الخريطة المطلوبة.
     *
     * @param array<string, bool> $map استثناءات الرول أو اليوزر
     * @param bool $withRoleLayer الافتراضي يحسب طبقة الرول (تاب الموظفين)
     * @return array<string, array{override:?bool, default:bool, pages:list<array<string,mixed>>}>
     */
    private function tree(string $role, array $map, bool $withRoleLayer): array
    {
        $out = [];

        foreach (Access::NAV as $group => $links) {
            $pages = [];

            foreach ($links as [$route, $icon, $label]) {
                $actions = [];

                foreach (Access::ACTIONS as $key => $def) {
                    if ($def[1] !== $route) {
                        continue;
                    }

                    $actions[] = [
                        'key' => $key,
                        'label' => $def[0],
                        'default' => $this->actionDefault($role, $key, $withRoleLayer),
                        'override' => $map[$key] ?? null,
                    ];
                }

                $pages[] = [
                    'route' => $route,
                    'icon' => $icon,
                    'label' => $label,
                    'default' => $withRoleLayer
                        ? Access::roleEffective($role, $route)
                        : Access::roleDefault($role, $route),
                    'override' => $map[$route] ?? null,
                    'actions' => $actions,
                ];
            }

            $out[$group] = [
                'override' => $map[$group] ?? null,
                // القسم «ظاهر افتراضياً» لو الرول شايف أي صفحة فيه
                'default' => (bool) array_filter($pages, fn ($p) => $p['default']),
                'pages' => $pages,
            ];
        }

        return $out;
    }

    /** افتراضي الزرار — من الكود، وبطبقة الرول لو تاب الموظفين */
    private function actionDefault(string $role, string $key, bool $withRoleLayer): bool
    {
        if ($withRoleLayer) {
            $roleMap = RolePermission::mapFor($role);

            if (array_key_exists($key, $roleMap)) {
                return $roleMap[$key];
            }
        }

        [, $page, $roles] = Access::ACTIONS[$key];

        if ($roles === []) {
            return false;   // أدمن بس
        }

        $pageOk = $withRoleLayer
            ? Access::roleEffective($role, $page)
            : Access::roleDefault($role, $page);

        return $roles === null
            ? $pageOk
            : ($pageOk && in_array($role, $roles, true));
    }

    /** كل المفاتيح المسموح كتابتها — أقسام وصفحات وأزرار متسجلة بس */
    private function validKeys(): array
    {
        return array_merge(
            array_keys(Access::ACTIONS),
            array_keys(Access::NAV),
            array_map(fn ($l) => $l[0], array_merge(...array_values(Access::NAV))),
        );
    }

    public function save(Request $request, User $user)
    {
        // ⚠️ استثناءات على أدمن ممنوعة — وعلى رولز الميدان مالهاش معنى ويب
        abort_if($user->role === 'admin' || ! in_array($user->role, Access::WEB_ROLES, true), 403);

        $valid = $this->validKeys();
        $perms = (array) $request->input('perm', []);

        DB::transaction(function () use ($user, $perms, $valid) {
            foreach ($perms as $key => $val) {
                if (! in_array($key, $valid, true)) {
                    continue;   // مفتاح مش متسجل — مايتكتبش
                }

                if ($val === '' || $val === null) {
                    // وراثة = مفيش صف
                    $user->permissions()->where('perm', $key)->delete();
                } else {
                    $user->permissions()->updateOrCreate(
                        ['perm' => $key],
                        ['allow' => (bool) (int) $val],
                    );
                }
            }
        });

        return back()->with('ok', __('perm.saved', ['name' => $user->name]));
    }

    /** حفظ استثناءات رول كامل — تاب الرولز (٢٣/٨) */
    public function saveRole(Request $request, string $role)
    {
        abort_if($role === 'admin' || ! in_array($role, Access::WEB_ROLES, true), 403);

        $valid = $this->validKeys();
        $perms = (array) $request->input('perm', []);

        DB::transaction(function () use ($role, $perms, $valid) {
            foreach ($perms as $key => $val) {
                if (! in_array($key, $valid, true)) {
                    continue;
                }

                if ($val === '' || $val === null) {
                    RolePermission::where('role', $role)->where('perm', $key)->delete();
                } else {
                    RolePermission::updateOrCreate(
                        ['role' => $role, 'perm' => $key],
                        ['allow' => (bool) (int) $val],
                    );
                }
            }
        });

        // ⚠️ من غير المسح ده التعديل مايبانش غير بعد ساعة كاش
        RolePermission::flush();

        return back()->with('ok', __('perm.saved_role', ['role' => __('enums.role.'.$role)]));
    }
}
