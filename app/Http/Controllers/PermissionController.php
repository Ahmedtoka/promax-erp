<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشة الصلاحيات — تحكم الأدمن يوزر بيوزر (قرار المالك 2026-08-05)
 * ═══════════════════════════════════════════════════════════════
 *
 * الرول بيدي الافتراضي، والأدمن من هنا بيظبط الاستثناءات على تلات
 * مستويات: قسم كامل من المنيو، صفحة جوه قسم، زرار جوه صفحة.
 * «وراثة» = مفيش صف في user_permissions أصلاً.
 *
 * ⚠️ **مفيش استثناءات على أدمن.** الأدمن معاه كل حاجة دايماً —
 * والشاشة نفسها مش بتعرضه في القايمة.
 */
class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $users = User::whereIn('role', Access::WEB_ROLES)
            ->where('role', '!=', 'admin')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'code']);

        $user = $request->filled('user')
            ? $users->firstWhere('id', (int) $request->input('user'))
            : $users->first();

        return view('erp.perms', [
            'users' => $users,
            'user' => $user,
            'tree' => $user ? $this->tree($user) : [],
        ]);
    }

    /**
     * الشجرة: قسم → صفحاته → أزرار صفحاته — لكل عنصر الافتراضي
     * من الرول + الاستثناء الحالي لو موجود.
     *
     * @return array<string, array{key:string, pages:list<array<string,mixed>>}>
     */
    private function tree(User $user): array
    {
        $map = $user->permMap();
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
                        'default' => $this->actionDefault($user, $key),
                        'override' => $map[$key] ?? null,
                    ];
                }

                $pages[] = [
                    'route' => $route,
                    'icon' => $icon,
                    'label' => $label,
                    'default' => Access::roleDefault($user->role, $route),
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

    /** افتراضي الزرار من الرول — من غير استثناءات اليوزر */
    private function actionDefault(User $user, string $key): bool
    {
        [, $page, $roles] = Access::ACTIONS[$key];

        if ($roles === []) {
            return false;   // أدمن بس
        }

        $pageOk = Access::roleDefault($user->role, $page);

        return $roles === null
            ? $pageOk
            : ($pageOk && in_array($user->role, $roles, true));
    }

    public function save(Request $request, User $user)
    {
        // ⚠️ استثناءات على أدمن ممنوعة — وعلى رولز الميدان مالهاش معنى ويب
        abort_if($user->role === 'admin' || ! in_array($user->role, Access::WEB_ROLES, true), 403);

        $valid = array_merge(
            array_keys(Access::ACTIONS),
            array_keys(Access::NAV),
            array_map(fn ($l) => $l[0], array_merge(...array_values(Access::NAV))),
        );

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
}
