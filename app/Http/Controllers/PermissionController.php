<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\PermissionMatrix;
use App\Services\SheetWriter;
use App\Support\Access;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشة الرولز والصلاحيات (2026-08-05 → ٩/١١ مهمة ٨)
 * ═══════════════════════════════════════════════════════════════
 *
 * ست تابات (`?tab=`): الرولز · المصفوفة · ورقة الرول · مين معاه الصلاحية ·
 * مقارنة · السجل. الفعلي والمصدر كلهم من `PermissionMatrix` (اللي بيقرا
 * من `Access` نفسه) — مفيش نسخة تانية من الريزولفر هنا.
 *
 * الطبقات: يوزر ← رول مخصص ← رول أساسي ← الكود.
 * ⚠️ **مفيش استثناءات على أدمن.** الأدمن معاه كل حاجة دايماً.
 * ⚠️ الصفحة لازم تفضل سريعة (~٣٧٤ مفتاح): كل موضوع شجرته بتتحسب مرة واحدة،
 * والعدّادات بكويري واحد متجمّع — مفيش N+1.
 */
class PermissionController extends Controller
{
    public const TABS = ['roles', 'matrix', 'sheet', 'who', 'compare', 'history'];

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'roles';
        $roles = $this->roleCatalog();

        $data = match ($tab) {
            'roles' => $this->rolesTab($roles),
            'matrix' => $this->matrixTab($request, $roles),
            'sheet' => $this->sheetTab($request, $roles),
            'who' => $this->whoTab($request, $roles),
            'compare' => $this->compareTab($request, $roles),
            'history' => $this->historyTab($request, $roles),
        };

        return view('erp.perms.index', ['tab' => $tab, 'roles' => $roles] + $data);
    }

    // ═══════════════════════ الرولز ═══════════════════════

    /**
     * كل الرولز (أساسية غير الأدمن + المخصصة) بعدّاداتها — ٣ كويريز.
     *
     * @return array<string, array<string, mixed>> مفتاح ⇒ وصف
     */
    private function roleCatalog(): array
    {
        $valid = array_flip(PermissionMatrix::validKeys());
        $maps = RolePermission::maps();
        $diffs = fn (string $key) => count(array_intersect_key($maps[$key] ?? [], $valid));

        $counts = User::query()
            ->selectRaw('role, custom_role_id, active, count(*) as n')
            ->groupBy('role', 'custom_role_id', 'active')
            ->get();

        // اليوزر على رول مخصص بيتعد على المخصص بس (ده اللي بيمنع مسحه/إيقافه)
        $users = [];

        foreach ($counts as $c) {
            $k = $c->custom_role_id === null ? (string) $c->role : 'c:'.$c->custom_role_id;
            $users[$k]['total'] = ($users[$k]['total'] ?? 0) + (int) $c->n;
            $users[$k]['active'] = ($users[$k]['active'] ?? 0) + ($c->active ? (int) $c->n : 0);
        }

        $out = [];

        foreach (Role::BASE_ROLES() as $r) {
            $out[$r] = [
                'key' => $r,
                'label' => __('enums.role.'.$r),
                'custom' => false,
                'model' => null,
                'base' => $r,
                'web' => in_array($r, Access::WEB_ROLES, true),
                'app' => in_array($r, User::APP_ROLES, true),
                'active' => true,
                'users' => $users[$r]['total'] ?? 0,
                'active_users' => $users[$r]['active'] ?? 0,
                'diffs' => $diffs($r),
            ];
        }

        foreach (Role::query()->orderByDesc('active')->orderBy('name_ar')->get() as $role) {
            $k = $role->key();
            $out[$k] = [
                'key' => $k,
                'label' => $role->displayName(),
                'custom' => true,
                'model' => $role,
                'base' => (string) $role->base_role,
                'web' => (bool) $role->web_login,
                'app' => (bool) $role->app_login,
                'active' => (bool) $role->active,
                'users' => $users[$k]['total'] ?? 0,
                'active_users' => $users[$k]['active'] ?? 0,
                'diffs' => $diffs($k),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rolesTab(array $roles): array
    {
        return [
            'baseRoles' => Role::BASE_ROLES(),
            // الأساس اللي معناه «نطاق الفرع» على شاشات الويب — للتحذير في المودال
            'branchScopeBases' => array_values(array_merge(User::FIELD_ROLES, ['branch_manager'])),
        ];
    }

    // ═══════════════════════ المصفوفة ═══════════════════════

    /** @return array<string, mixed> */
    private function matrixTab(Request $request, array $roles): array
    {
        $users = User::webUsers()
            ->where('role', '!=', 'admin')
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'name_en', 'role', 'code', 'custom_role_id']);

        $user = $request->filled('user') ? $users->firstWhere('id', (int) $request->input('user')) : null;
        $roleKey = $user === null ? $this->pickRole($request->query('role'), $roles) : null;

        return [
            'users' => $users,
            'subjectUser' => $user,
            'subjectRole' => $roleKey,
            'tree' => $user !== null ? PermissionMatrix::forUser($user) : PermissionMatrix::forRole($roleKey),
        ];
    }

    /** رول صالح من الكتالوج، وإلا أول رول */
    private function pickRole(mixed $key, array $roles, ?string $fallback = null): string
    {
        return is_string($key) && isset($roles[$key]) ? $key : ($fallback ?? array_key_first($roles));
    }

    // ═══════════════════════ ورقة الرول ═══════════════════════

    /** @return array<string, mixed> */
    private function sheetTab(Request $request, array $roles): array
    {
        $key = $this->pickRole($request->query('role'), $roles);
        $tree = PermissionMatrix::forRole($key);

        return ['sheetRole' => $key, 'tree' => $tree, 'sheetStats' => $this->sheetStats($tree)];
    }

    /**
     * عدّادات الورقة: مسموح / ممنوع / حرج مسموح — على الصفحات والشاشات والأزرار.
     *
     * @return array{allowed:int, denied:int, critical:int}
     */
    private function sheetStats(array $tree): array
    {
        $st = ['allowed' => 0, 'denied' => 0, 'critical' => 0];

        foreach (self::flatRows($tree) as $r) {
            if ($r['kind'] === 'group') {
                continue;
            }

            $st[$r['effective'] ? 'allowed' : 'denied']++;

            if ($r['effective'] && $r['risk'] === PermissionCatalog::CRITICAL) {
                $st['critical']++;
            }
        }

        return $st;
    }

    /**
     * الشجرة مفرودة بالترتيب: قسم ← صفحة ← شاشاتها ← أزرارها.
     *
     * @return list<array<string, mixed>> كل صف + `group` (مفتاح القسم) + `depth`
     */
    public static function flatRows(array $tree): array
    {
        $out = [];

        foreach ($tree as $g) {
            $out[] = $g + ['group' => $g['key'], 'depth' => 0];

            foreach ($g['pages'] as $p) {
                $out[] = $p + ['group' => $g['key'], 'depth' => 1];

                foreach (array_merge($p['screens'], $p['actions']) as $c) {
                    $out[] = $c + ['group' => $g['key'], 'depth' => 2, 'page' => $p['label'], 'page_key' => $p['key'], 'page_what' => $p['what']];
                }
            }
        }

        return $out;
    }

    /**
     * اسم السطر للعرض: الليبل لو موجود؛ الصفحة العامة/الشاشة اللي ليها وصف
     * خاص = وصفها؛ والشاشة اللي وصفها وصف صفحتها = آخر جزء من مسارها
     * (عشان أربع شاشات العميل مايبانوش بنفس الاسم).
     *
     * @return array{0:string, 1:bool} [الاسم، نعرض «بيعمل إيه» تحته؟]
     */
    public static function rowTitle(array $r): array
    {
        if ($r['label'] !== $r['key']) {
            return [$r['label'], true];
        }

        $pageKey = $r['page_key'] ?? null;

        if ($r['kind'] === 'screen' && $pageKey !== null && ($r['what'] ?? null) === ($r['page_what'] ?? null)) {
            $suffix = str_starts_with($r['key'], $pageKey.'.') ? substr($r['key'], strlen($pageKey) + 1) : $r['key'];

            return [__('perm.screen_named', ['x' => str_replace(['.', '_'], [' › ', ' '], $suffix)]), false];
        }

        return [(string) ($r['what'] ?? $r['key']), false];
    }

    /** إكسيل ورقة الرول — نفس `SheetWriter` بتاع باقي التصديرات */
    public function sheetExcel(Request $request)
    {
        $roles = $this->roleCatalog();
        $key = $request->query('role');
        abort_unless(is_string($key) && isset($roles[$key]), 404);

        $role = $roles[$key];
        $tree = PermissionMatrix::forRole($key);
        $st = $this->sheetStats($tree);

        $x = new SheetWriter(__('perm.sheet_title'));

        foreach ([34, 30, 12, 12, 12, 16, 60, 60] as $i => $w) {
            $x->width($i, $w);
        }

        $x->row([['v' => __('perm.sheet_title').' — '.$role['label'], 'style' => 'title']]);
        $x->merge(0, 7);
        $x->row([
            ['v' => __('perm.base').': '.__('enums.role.'.$role['base']), 'style' => 'label'],
            ['v' => __('perm.st_allowed').': '.$st['allowed'], 'style' => 'label'],
            ['v' => __('perm.st_denied').': '.$st['denied'], 'style' => 'label'],
            ['v' => __('perm.st_critical').': '.$st['critical'], 'style' => 'label'],
            ['v' => __('common.date').': '.now()->format('Y-m-d H:i'), 'style' => 'label'],
        ]);
        $x->blank();

        $x->row(array_map(fn ($h) => ['v' => __($h), 'style' => 'header'], [
            'perm.c_item', 'perm.c_key', 'perm.c_kind', 'perm.c_status', 'perm.c_risk', 'perm.c_source', 'perm.c_what', 'perm.c_data',
        ]));

        foreach (self::flatRows($tree) as $r) {
            $isGroup = $r['kind'] === 'group';
            $style = $isGroup ? 'total' : 'default';
            $x->row([
                ['v' => str_repeat('   ', $r['depth']).self::rowTitle($r)[0], 'style' => $style],
                ['v' => $r['key'], 'style' => $isGroup ? 'total' : 'muted'],
                ['v' => __('perm.kind_'.$r['kind']), 'style' => $style],
                ['v' => $r['effective'] ? '✓ '.__('perm.allowed') : '✗ '.__('perm.denied'), 'style' => $style],
                ['v' => PermissionCatalog::riskLabel($r['risk']), 'style' => $style],
                ['v' => __('perm.src_'.$r['source']), 'style' => $style],
                ['v' => $r['what'] ?? '', 'style' => $style],
                ['v' => $r['data'] ?? '', 'style' => $isGroup ? 'total' : 'muted'],
            ]);
        }

        $slug = $role['custom'] ? 'custom-'.$role['model']->id : $key;

        return $x->download('role-sheet-'.$slug.'-'.now()->format('Y-m-d-Hi').'.xlsx');
    }

    // ═══════════════════════ مين معاه الصلاحية ═══════════════════════

    /** @return array<string, mixed> */
    private function whoTab(Request $request, array $roles): array
    {
        $keys = PermissionCatalog::keys();
        $key = $request->query('key');
        $key = is_string($key) && in_array($key, $keys, true) ? $key : null;

        // قايمة الاختيار: كل مفتاح باسمه تحت قسمه (من غير فعلي — رخيصة)
        $picker = [];

        foreach (PermissionCatalog::groups() as $g) {
            $picker[$g] = [];
        }

        $groupOf = $this->groupOfKeys();

        foreach ($keys as $k) {
            $picker[$groupOf[$k] ?? Access::PUBLIC_GROUP][$k] = PermissionMatrix::labelOf($k);
        }

        if ($key === null) {
            return ['whoKey' => null, 'picker' => $picker];
        }

        $cells = [];

        foreach ($roles as $rk => $r) {
            $cells[$rk] = PermissionMatrix::cell($rk, $key);
        }

        $overrides = UserPermission::query()
            ->where('perm', $key)
            // `user.permissions` محمّلة مرة واحدة — `permMap()` بيقرا منها بدل كويري لكل يوزر
            ->with(['user.permissions'])
            ->get()
            ->filter(fn ($o) => $o->user !== null)
            ->map(fn ($o) => [
                'user' => $o->user,
                'allow' => (bool) $o->allow,
                'effective' => isset(Access::ACTIONS[$key]) ? Access::action($o->user, $key) : Access::allows($o->user, $key),
            ])
            ->sortBy(fn ($o) => $o['user']->name)
            ->values();

        $desc = PermissionCatalog::describe($key);
        $page = PermissionCatalog::pageOf($key);
        [$title, $withWhat] = self::rowTitle([
            'label' => PermissionMatrix::labelOf($key), 'key' => $key, 'kind' => PermissionCatalog::kind($key),
            'what' => $desc['what'], 'page_key' => $page, 'page_what' => $page !== null ? PermissionCatalog::describe($page)['what'] : null,
        ]);

        return [
            'whoKey' => $key,
            'picker' => $picker,
            'whoInfo' => [
                'title' => $title,
                'with_what' => $withWhat,
                'label' => PermissionMatrix::labelOf($key),
                'kind' => PermissionCatalog::kind($key),
                'risk' => PermissionCatalog::risk($key),
                'what' => $desc['what'],
                'data' => $desc['data'],
                'locked' => Access::isLocked($key),
            ],
            'cells' => $cells,
            'overrides' => $overrides,
        ];
    }

    /** @return array<string, string> مفتاح ⇒ قسمه (صفحة/شاشة/زرار عن طريق الصفحة) */
    private function groupOfKeys(): array
    {
        $out = [];

        foreach (PermissionCatalog::groups() as $g) {
            $out[$g] = $g;
            $pages = $g === Access::PUBLIC_GROUP ? Access::PUBLIC_PAGES : array_column(Access::NAV[$g] ?? [], 0);

            foreach ($pages as $p) {
                $out[$p] ??= $g;

                foreach (Access::screensOf($p) as $sc) {
                    $out[$sc] ??= $g;
                }
            }
        }

        foreach (Access::ACTIONS as $k => $def) {
            $page = $def[1];
            $best = null;

            foreach ($out as $p => $g) {
                if (($page === $p || str_starts_with($page, $p.'.')) && ($best === null || strlen($p) > strlen($best))) {
                    $best = $p;
                }
            }

            $out[$k] = $best !== null ? $out[$best] : Access::PUBLIC_GROUP;
        }

        return $out;
    }

    // ═══════════════════════ مقارنة ═══════════════════════

    /** @return array<string, mixed> */
    private function compareTab(Request $request, array $roles): array
    {
        $keys = array_keys($roles);
        $a = $this->pickRole($request->query('a'), $roles, $keys[0]);
        $b = $this->pickRole($request->query('b'), $roles, $keys[1] ?? $keys[0]);
        $only = $request->boolean('only');

        $rowsB = [];

        foreach (self::flatRows(PermissionMatrix::forRole($b)) as $r) {
            $rowsB[$r['key']] = $r;
        }

        $all = [];
        $diffCount = 0;
        // قسم/صفحة فيها فرق تحتها — بتفضل كعنوان في «الفروق بس»
        $hasDiff = [];

        foreach (self::flatRows(PermissionMatrix::forRole($a)) as $r) {
            $other = $rowsB[$r['key']] ?? null;
            $differs = $other !== null && $other['effective'] !== $r['effective'];

            if ($differs && $r['kind'] !== 'group') {
                $diffCount++;
                $hasDiff[$r['group']] = true;
                if (isset($r['page_key'])) {
                    $hasDiff[$r['page_key']] = true;
                }
            }

            $all[] = ['row' => $r, 'b' => $other, 'differs' => $differs];
        }

        $rows = $only
            ? array_values(array_filter($all, fn ($x) => $x['differs'] || isset($hasDiff[$x['row']['key']])))
            : $all;

        return ['cmpA' => $a, 'cmpB' => $b, 'cmpOnly' => $only, 'cmpRows' => $rows, 'cmpDiffs' => $diffCount];
    }

    // ═══════════════════════ السجل ═══════════════════════

    /** @return array<string, mixed> */
    private function historyTab(Request $request, array $roles): array
    {
        $kind = in_array($request->query('kind'), ['perms', 'roles'], true) ? (string) $request->query('kind') : null;

        $rows = ActivityLog::query()
            ->where(function ($q) use ($kind) {
                if ($kind !== 'roles') {
                    $q->orWhere('event', 'perms.saved');
                }

                if ($kind !== 'perms') {
                    $q->orWhere('event', 'roles.cloned')
                        ->orWhere(fn ($w) => $w->where('subject_type', 'Role')->whereIn('event', ['created', 'updated', 'deleted']));
                }
            })
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return ['histRows' => $rows, 'histKind' => $kind];
    }

    /** اسم موضوع صف السجل — رول مخصص (حتى لو اتمسح)، رول أساسي، أو يوزر */
    public static function historySubject(ActivityLog $r): string
    {
        if ($r->subject_type === 'BuiltinRole') {
            return __('enums.role.'.$r->title);
        }

        if ($r->subject_type === 'Role') {
            $role = Role::maps()[(int) $r->subject_id] ?? null;

            return $role?->displayName() ?? __('perm.hist_deleted_role', ['id' => $r->subject_id]);
        }

        return (string) $r->title;
    }

    /**
     * حفظ استثناءات يوزر — من الخدمة (الفرق + صف نشاط واحد، ٩/١١).
     * مفاتيح مجهولة/مقفولة بتتجاهل هناك (`PermissionMatrix::validKeys`).
     */
    public function save(Request $request, User $user)
    {
        // ⚠️ استثناءات على أدمن ممنوعة — وعلى رولز الميدان مالهاش معنى ويب
        abort_if($user->role === 'admin' || ! Access::isWebRole($user), 403);

        PermissionMatrix::save($user, $this->perms($request));

        return back()->with('ok', __('perm.saved', ['name' => $user->name]));
    }

    /** حفظ استثناءات رول كامل — أساسي أو مخصص `c:<id>` (٢٣/٨، ٩/١١) */
    public function saveRole(Request $request, string $role)
    {
        $subject = $this->roleSubject($role);

        PermissionMatrix::save($subject instanceof Role ? $subject->key() : $subject, $this->perms($request));

        $name = $subject instanceof Role ? $subject->displayName() : __('enums.role.'.$role);

        return back()->with('ok', __('perm.saved_role', ['role' => $name]));
    }

    /**
     * معاينة الفرق قبل الحفظ (JSON) — `role` (مفتاح) أو `user` (id) + `perm[]`.
     * مفيش كتابة: الخدمة بتجرّب جوه ترانزاكشن بيترجع.
     */
    public function diff(Request $request)
    {
        $perms = $this->perms($request);

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

    /**
     * `perm[key]` = '' (وراثة) | '0' | '1' بس — أي قيمة تانية (أراي، 'x') 422.
     *
     * @return array<string, mixed>
     */
    private function perms(Request $request): array
    {
        $request->validate(['perm' => ['nullable', 'array'], 'perm.*' => ['nullable', 'in:0,1']]);

        return (array) $request->input('perm', []);
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
