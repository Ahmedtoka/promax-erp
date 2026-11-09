<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionMatrix;
use App\Support\Access;
use Illuminate\Http\Request;

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

        $users = User::webUsers()
            ->where('role', '!=', 'admin')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'name_en', 'role', 'code', 'custom_role_id']);

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

    /**
     * حفظ استثناءات يوزر — من الخدمة (الفرق + صف نشاط واحد، ٩/١١).
     * مفاتيح مجهولة/مقفولة بتتجاهل هناك (`PermissionMatrix::validKeys`).
     */
    public function save(Request $request, User $user)
    {
        // ⚠️ استثناءات على أدمن ممنوعة — وعلى رولز الميدان مالهاش معنى ويب
        abort_if($user->role === 'admin' || ! Access::isWebRole($user), 403);

        PermissionMatrix::save($user, (array) $request->input('perm', []));

        return back()->with('ok', __('perm.saved', ['name' => $user->name]));
    }

    /** حفظ استثناءات رول كامل — أساسي أو مخصص `c:<id>` (٢٣/٨، ٩/١١) */
    public function saveRole(Request $request, string $role)
    {
        $subject = $this->roleSubject($role);

        PermissionMatrix::save($subject instanceof Role ? $subject->key() : $subject, (array) $request->input('perm', []));

        $name = $subject instanceof Role ? $subject->displayName() : __('enums.role.'.$role);

        return back()->with('ok', __('perm.saved_role', ['role' => $name]));
    }

    /**
     * معاينة الفرق قبل الحفظ (JSON) — `role` (مفتاح) أو `user` (id) + `perm[]`.
     * مفيش كتابة: الخدمة بتجرّب جوه ترانزاكشن بيترجع.
     */
    public function diff(Request $request)
    {
        $perms = (array) $request->input('perm', []);

        if ($request->filled('user')) {
            $user = User::find((int) $request->input('user'));
            abort_if($user === null || $user->role === 'admin' || ! Access::isWebRole($user), 403);

            return response()->json(['changes' => PermissionMatrix::diff($user, $perms)]);
        }

        $subject = $this->roleSubject((string) $request->input('role', ''));

        return response()->json(['changes' => PermissionMatrix::diff(
            $subject instanceof Role ? $subject->key() : $subject, $perms,
        )]);
    }

    /** رول أساسي (غير الأدمن) أو مخصص موجود — وإلا 403 */
    private function roleSubject(string $role): string|Role
    {
        if (str_starts_with($role, 'c:')) {
            $custom = Role::fromKey($role);
            abort_if($custom === null, 403);

            return $custom;
        }

        abort_if(! in_array($role, Role::BASE_ROLES(), true), 403);

        return $role;
    }
}
