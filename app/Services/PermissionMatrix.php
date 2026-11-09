<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access;
use App\Support\PermissionCatalog;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * مصفوفة الصلاحيات (٩/١١) — الشجرة بالفعلي والمصدر، الفرق قبل الحفظ،
 * والحفظ بصف نشاط واحد. الواجهة (مهمة ٨) مبنية على شكل الصفوف هنا.
 *
 * ⚠️ **الفعلي جاي من `Access` نفسه** (`effectiveFor`/`actionFor` للرول،
 * `allows`/`action` لليوزر) — مفيش نسخة تانية من الريزولفر. المصدر من
 * `Access::layerDecision` على الطبقات بنفس ترتيب الريزولفر.
 *
 * الطبقات: يوزر (`user`) ← الرول المخصص (`role`) ← الرول الأساسي (`base`
 * لو فيه مخصص، وإلا `role`) ← الكود (`code`).
 * ⚠️ الأدمن عمره ما يبقى موضوع للمصفوفة (معاه كل حاجة، ومفيش صفوف عليه).
 */
class PermissionMatrix
{
    public const SOURCES = ['code', 'base', 'role', 'user'];

    // ═══════════════════════ الشجرة ═══════════════════════

    /** @return list<array<string, mixed>> */
    public static function forRole(string $roleKey): array
    {
        return self::tree(self::subject($roleKey));
    }

    /** @return list<array<string, mixed>> */
    public static function forUser(User $user): array
    {
        return self::tree(self::subject($user));
    }

    /**
     * @param array<string, mixed> $s
     * @return list<array<string, mixed>>
     */
    private static function tree(array $s): array
    {
        $actionsOf = [];

        foreach (Access::ACTIONS as $key => $def) {
            $actionsOf[self::pageForAction($def[1])][] = $key;
        }

        $out = [];

        foreach (PermissionCatalog::groups() as $group) {
            $virtual = $group === Access::PUBLIC_GROUP;
            $pages = [];

            foreach (self::pagesOf($group) as [$page, $icon, $label]) {
                $row = self::row($s, $page, 'page', $label, $icon);
                $row['screens'] = array_map(
                    fn (string $k) => self::row($s, $k, 'screen', $k, null),
                    Access::screensOf($page),
                );
                $row['actions'] = array_map(
                    fn (string $k) => self::row($s, $k, 'action', __(Access::ACTIONS[$k][0]), null),
                    $actionsOf[$page] ?? [],
                );
                $pages[] = $row;
            }

            $g = $virtual
                // القسم الوهمي مالوش صف في الريزولفر (`groupOf` بيمشي على NAV بس)
                ? ['override' => null, 'source' => 'code']
                : ['override' => self::override($s, $group), 'source' => self::source($s, $group)];

            $out[] = [
                'key' => $group,
                'kind' => 'group',
                'label' => __($group),
                'icon' => Access::GROUP_ICONS[$group] ?? '•',
                'override' => $g['override'],
                'effective' => (bool) array_filter($pages, fn ($p) => $p['effective']),
                'source' => $g['source'],
                'risk' => PermissionCatalog::risk($group),
                'virtual' => $virtual,
                'pages' => $pages,
            ];
        }

        return $out;
    }

    /**
     * صف واحد — نفس الشكل للصفحة والشاشة والزرار.
     *
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    private static function row(array $s, string $key, string $kind, string $label, ?string $icon): array
    {
        $locked = Access::isLocked($key);
        $desc = PermissionCatalog::describe($key);

        return [
            'key' => $key,
            'kind' => $kind,
            'label' => $label,
            'icon' => $icon,
            'override' => $locked ? null : self::override($s, $key),
            'effective' => self::effective($s, $key),
            'source' => self::source($s, $key),
            'risk' => PermissionCatalog::risk($key),
            'what' => $desc['what'],
            'data' => $desc['data'],
            'locked' => $locked,
            'screens' => [],
            'actions' => [],
        ];
    }

    /** @return list<array{0:string,1:?string,2:string}> [صفحة، أيقونة، ليبل] */
    private static function pagesOf(string $group): array
    {
        if ($group === Access::PUBLIC_GROUP) {
            return array_map(fn (string $p) => [$p, null, $p], Access::PUBLIC_PAGES);
        }

        return array_map(fn (array $l) => [$l[0], $l[1], __($l[2])], Access::NAV[$group] ?? []);
    }

    /** صفحة الزرار في الشجرة — صفحته لو موجودة، وإلا أطول صفحة بتطابقها */
    private static function pageForAction(string $page): string
    {
        $pages = PermissionCatalog::pages();

        if (in_array($page, $pages, true)) {
            return $page;
        }

        $best = $pages[0];
        $len = -1;

        foreach ($pages as $p) {
            if (($page === $p || str_starts_with($page, $p.'.')) && strlen($p) > $len) {
                $best = $p;
                $len = strlen($p);
            }
        }

        return $best;
    }

    // ═══════════════════════ الفرق والحفظ ═══════════════════════

    /**
     * المفاتيح اللي هتتغير بس، بالفعلي قبل وبعد — من غير ما يتكتب حاجة.
     *
     * @param array<string, mixed> $perms مفتاح ⇒ '' (وراثة) | '0' | '1'
     * @return list<array{key:string, label:string, before:?bool, after:?bool, effective_before:bool, effective_after:bool, risk:string}>
     */
    public static function diff(string|User $subject, array $perms): array
    {
        $s = self::subject($subject);
        $changes = self::changes($s, $perms);

        if ($changes === []) {
            return [];
        }

        $before = self::effectives($s, array_keys($changes));
        $after = self::preview(function () use ($s, $changes) {
            self::write($s, $changes);

            return self::effectives(self::reload($s), array_keys($changes));
        });

        return self::rows($changes, $before, $after);
    }

    /**
     * يحفظ ويرجّع الفرق. مفاتيح مجهولة/مقفولة بتتجاهل. صف نشاط واحد
     * (`perms.saved`) لو فيه تغيير — الحذف كويري مباشر بيعدّي الأوبزرفر.
     *
     * @param array<string, mixed> $perms
     * @return list<array<string, mixed>>
     */
    public static function save(string|User $subject, array $perms): array
    {
        $s = self::subject($subject);
        $changes = self::changes($s, $perms);

        if ($changes === []) {
            return [];
        }

        $before = self::effectives($s, array_keys($changes));

        DB::transaction(fn () => self::write($s, $changes));

        // ⚠️ من غير المسح ده التعديل مايبانش غير بعد الكاش
        RolePermission::flush();

        $diff = self::rows($changes, $before, self::effectives(self::reload($s), array_keys($changes)));

        ActivityLog::record('perms.saved', [
            'subject_type' => $s['type'],
            'subject_id' => $s['type'] === 'BuiltinRole' ? null : $s['id'],
            'title' => mb_substr($s['title'], 0, 190),
            'changes' => $diff,
        ]);

        return $diff;
    }

    /** المفاتيح اللي ينفع تتكتب: الكتالوج ناقص المقفول والقسم الوهمي @return list<string> */
    public static function validKeys(): array
    {
        return array_values(array_filter(
            PermissionCatalog::keys(),
            fn (string $k) => $k !== Access::PUBLIC_GROUP && ! Access::isLocked($k),
        ));
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $perms
     * @return array<string, array{0:?bool, 1:?bool}> مفتاح ⇒ [قبل، بعد] — المتغير بس
     */
    private static function changes(array $s, array $perms): array
    {
        $valid = array_flip(self::validKeys());
        $out = [];

        foreach ($perms as $key => $val) {
            $key = (string) $key;

            if (! isset($valid[$key])) {
                continue;
            }

            $after = ($val === '' || $val === null) ? null : (bool) (int) $val;
            $before = array_key_exists($key, $s['map']) ? $s['map'][$key] : null;

            if ($before !== $after) {
                $out[$key] = [$before, $after];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, array{0:?bool, 1:?bool}> $changes
     */
    private static function write(array $s, array $changes): void
    {
        foreach ($changes as $key => [, $after]) {
            $query = $s['user'] !== null
                ? $s['user']->permissions()->where('perm', $key)
                : RolePermission::where('role', $s['key'])->where('perm', $key);

            if ($after === null) {
                $query->delete();   // وراثة = مفيش صف
            } elseif ($s['user'] !== null) {
                $s['user']->permissions()->updateOrCreate(['perm' => $key], ['allow' => $after]);
            } else {
                RolePermission::updateOrCreate(['role' => $s['key'], 'perm' => $key], ['allow' => $after]);
            }
        }
    }

    /**
     * يشغّل الكتابة جوه ترانزاكشن بيترجع، بكاش في الذاكرة — عشان الكاش المشترك
     * مايتلوّثش بصفوف عمرها ما اتحفظت.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private static function preview(\Closure $fn): mixed
    {
        $cache = Cache::getFacadeRoot();
        Cache::swap(new Repository(new ArrayStore));
        DB::beginTransaction();

        try {
            return $fn();
        } finally {
            DB::rollBack();
            Cache::swap($cache);
        }
    }

    /**
     * @param array<string, array{0:?bool, 1:?bool}> $changes
     * @param array<string, bool> $before
     * @param array<string, bool> $after
     * @return list<array<string, mixed>>
     */
    private static function rows(array $changes, array $before, array $after): array
    {
        $out = [];

        foreach ($changes as $key => [$b, $a]) {
            $out[] = [
                'key' => $key,
                'label' => self::label($key),
                'before' => $b,
                'after' => $a,
                'effective_before' => $before[$key],
                'effective_after' => $after[$key],
                'risk' => PermissionCatalog::risk($key),
            ];
        }

        return $out;
    }

    private static function label(string $key): string
    {
        if (isset(Access::ACTIONS[$key])) {
            return __(Access::ACTIONS[$key][0]);
        }

        if (str_starts_with($key, 'nav.')) {
            return __($key);
        }

        foreach (Access::NAV as $links) {
            foreach ($links as $l) {
                if ($l[0] === $key) {
                    return __($l[2]);
                }
            }
        }

        return $key;
    }

    // ═══════════════════════ الموضوع والطبقات ═══════════════════════

    /**
     * @return array{type:string, id:?int, key:?string, title:string, user:?User,
     *               map:array<string,bool>, layers:list<array{0:string,1:array<string,bool>}>}
     */
    private static function subject(string|User $subject): array
    {
        if ($subject instanceof User) {
            if ($subject->role === 'admin') {
                throw new \InvalidArgumentException('admin is never a matrix subject');
            }

            $user = $subject->fresh() ?? $subject;
            $customKey = $user->permRoleKey();
            $layers = [['user', $user->permMap()]];

            if ($customKey !== null) {
                $layers[] = ['role', RolePermission::mapFor($customKey)];
            }

            $layers[] = [$customKey !== null ? 'base' : 'role', RolePermission::mapFor((string) $user->role)];

            return [
                'type' => 'User', 'id' => (int) $user->id, 'key' => null, 'title' => (string) $user->name,
                'user' => $user, 'map' => $user->permMap(), 'layers' => $layers,
            ];
        }

        if (str_starts_with($subject, 'c:')) {
            $role = Role::fromKey($subject);

            if ($role === null) {
                throw new \InvalidArgumentException("unknown custom role {$subject}");
            }

            $map = RolePermission::mapFor($subject);

            return [
                'type' => 'Role', 'id' => (int) $role->id, 'key' => $subject, 'title' => $subject,
                'user' => null, 'map' => $map,
                'layers' => [['role', $map], ['base', RolePermission::mapFor((string) $role->base_role)]],
            ];
        }

        if (! in_array($subject, Role::BASE_ROLES(), true)) {
            throw new \InvalidArgumentException("invalid matrix subject {$subject}");
        }

        $map = RolePermission::mapFor($subject);

        return [
            'type' => 'BuiltinRole', 'id' => null, 'key' => $subject, 'title' => $subject,
            'user' => null, 'map' => $map, 'layers' => [['role', $map]],
        ];
    }

    /** نفس الموضوع بعد الكتابة — خرايط وكاش اليوزر جداد */
    private static function reload(array $s): array
    {
        return self::subject($s['user'] ?? (string) $s['key']);
    }

    /** @param array<string, mixed> $s */
    private static function override(array $s, string $key): ?bool
    {
        return array_key_exists($key, $s['map']) ? $s['map'][$key] : null;
    }

    /** أول طبقة ليها رأي بنفس ترتيب الريزولفر، وإلا الكود */
    private static function source(array $s, string $key): string
    {
        foreach ($s['layers'] as [$name, $map]) {
            if (Access::layerDecision($map, $key) !== null) {
                return $name;
            }
        }

        return 'code';
    }

    /** الفعلي من Access — القسم = أي صفحة فيه فعلية */
    private static function effective(array $s, string $key): bool
    {
        if (str_starts_with($key, 'nav.')) {
            foreach (self::pagesOf($key) as [$page]) {
                if (self::effective($s, $page)) {
                    return true;
                }
            }

            return false;
        }

        if (isset(Access::ACTIONS[$key])) {
            return $s['user'] !== null ? Access::action($s['user'], $key) : Access::actionFor($s['key'], $key);
        }

        return $s['user'] !== null ? Access::allows($s['user'], $key) : Access::effectiveFor($s['key'], $key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, bool>
     */
    private static function effectives(array $s, array $keys): array
    {
        $out = [];

        foreach ($keys as $k) {
            $out[$k] = self::effective($s, $k);
        }

        return $out;
    }
}
