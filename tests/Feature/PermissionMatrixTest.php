<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\PermissionMatrix;
use App\Support\Access;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خدمة المصفوفة (٩/١١): الشجرة بالفعلي + المصدر، الفرق قبل الحفظ، والحفظ
 * بصف نشاط واحد. الفعلي لازم يطابق `Access` بالظبط — مش نسخة من المنطق.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    private function custom(string $base = 'manager'): Role
    {
        return Role::create([
            'name_ar' => 'مدير تجريبي', 'name_en' => 'Trial Lead',
            'base_role' => $base, 'web_login' => true, 'app_login' => false,
        ]);
    }

    private function rolePerm(string $role, string $perm, bool $allow): void
    {
        RolePermission::create(['role' => $role, 'perm' => $perm, 'allow' => $allow]);
        RolePermission::flush();
    }

    private function userOn(string $base, ?Role $role = null): User
    {
        $u = User::factory()->create(['role' => $base, 'code' => 'T-'.strtoupper(uniqid())]);

        if ($role !== null) {
            $u->custom_role_id = $role->id;
            $u->save();
        }

        return $u->fresh();
    }

    /** @return array<string, array<string, mixed>> مفتاح ⇒ صف (أقسام وصفحات وشاشات وأزرار) */
    private function flat(array $tree): array
    {
        $out = [];

        foreach ($tree as $g) {
            $out[$g['key']] = $g;

            foreach ($g['pages'] as $p) {
                $out[$p['key']] = $p;

                foreach (array_merge($p['screens'], $p['actions']) as $c) {
                    $this->assertArrayNotHasKey($c['key'], $out, "«{$c['key']}» متكرر في الشجرة");
                    $out[$c['key']] = $c;
                }
            }
        }

        return $out;
    }

    public function test_source_badges_code_base_role_user(): void
    {
        $this->rolePerm('manager', 'erp.leads', false);           // صف الرول الأساسي
        $custom = $this->custom('manager');
        $this->rolePerm($custom->key(), 'erp.dues', false);       // صف الرول المخصص
        $this->rolePerm($custom->key(), 'act.clients.create', false);

        // ═══ رول أساسي ═══
        $m = $this->flat(PermissionMatrix::forRole('manager'));
        $this->assertSame(['code', null, true], [$m['erp.clients']['source'], $m['erp.clients']['override'], $m['erp.clients']['effective']]);
        $this->assertSame(['role', false, false], [$m['erp.leads']['source'], $m['erp.leads']['override'], $m['erp.leads']['effective']]);

        // ═══ رول مخصص: صف الأساسي بيبان «base» ═══
        $c = $this->flat(PermissionMatrix::forRole($custom->key()));
        $this->assertSame(['base', null, false], [$c['erp.leads']['source'], $c['erp.leads']['override'], $c['erp.leads']['effective']]);
        $this->assertSame(['role', false, false], [$c['erp.dues']['source'], $c['erp.dues']['override'], $c['erp.dues']['effective']]);
        $this->assertSame(['role', false, false], [$c['act.clients.create']['source'], $c['act.clients.create']['override'], $c['act.clients.create']['effective']]);
        $this->assertSame('code', $c['erp.clients']['source']);

        // ═══ يوزر على الرول المخصص ═══
        $u = $this->userOn('manager', $custom);
        UserPermission::create(['user_id' => $u->id, 'perm' => 'erp.contracts', 'allow' => false]);
        $f = $this->flat(PermissionMatrix::forUser($u->fresh()));
        $this->assertSame(['user', false, false], [$f['erp.contracts']['source'], $f['erp.contracts']['override'], $f['erp.contracts']['effective']]);
        $this->assertSame(['role', null, false], [$f['erp.dues']['source'], $f['erp.dues']['override'], $f['erp.dues']['effective']]);
        $this->assertSame(['base', null, false], [$f['erp.leads']['source'], $f['erp.leads']['override'], $f['erp.leads']['effective']]);
        $this->assertSame(['code', null, true], [$f['erp.clients']['source'], $f['erp.clients']['override'], $f['erp.clients']['effective']]);

        // يوزر من غير رول مخصص: صف روله الأساسي = «role»
        $plain = $this->flat(PermissionMatrix::forUser($this->userOn('manager')));
        $this->assertSame('role', $plain['erp.leads']['source']);

        // المقفول: مفيش override، المصدر كود
        $this->assertTrue($m['erp.perms']['locked']);
        $this->assertSame('code', $m['erp.perms']['source']);
    }

    /** الفعلي في الشجرة = Access بالظبط، وكل مفتاح في الكتالوج ليه صف واحد */
    public function test_tree_matches_resolver_and_covers_catalog(): void
    {
        $custom = $this->custom('accountant');
        $this->rolePerm('accountant', 'nav.group_money', false);
        $this->rolePerm($custom->key(), 'erp.dues', true);
        $this->rolePerm($custom->key(), 'act.clients.collect', false);

        foreach (['accountant', 'warehouse_keeper', 'sales_agent', $custom->key()] as $key) {
            $rows = $this->flat(PermissionMatrix::forRole($key));

            foreach (PermissionCatalog::keys() as $k) {
                $this->assertArrayHasKey($k, $rows, "«{$k}» ناقص من مصفوفة «{$key}»");
            }

            foreach ($rows as $k => $row) {
                $this->assertContains($row['source'], ['code', 'base', 'role', 'user']);
                $kind = PermissionCatalog::kind($k);
                if ($kind === 'group') {
                    continue;
                }
                $expected = $kind === 'action' ? Access::actionFor($key, $k) : Access::effectiveFor($key, $k);
                $this->assertSame($expected, $row['effective'], "«{$k}» في «{$key}»");
            }
        }

        $u = $this->userOn('accountant', $custom);
        foreach ($this->flat(PermissionMatrix::forUser($u)) as $k => $row) {
            $kind = PermissionCatalog::kind($k);
            if ($kind === 'group') {
                continue;
            }
            $expected = $kind === 'action' ? Access::action($u, $k) : Access::allows($u, $k);
            $this->assertSame($expected, $row['effective'], "يوزر: «{$k}»");
        }
    }

    /** [المصدر، via] لمفتاح مش في الشجرة (راوت مغطّي) — من نفس دالة الشجرة */
    private function sourceOf(string|User $subject, string $key): array
    {
        $m = new \ReflectionMethod(PermissionMatrix::class, 'subject');
        $s = $m->invoke(null, $subject);

        return (new \ReflectionMethod(PermissionMatrix::class, 'sourceOf'))->invoke(null, $s, $key);
    }

    /** الزرار من غير صف بيتبع صفحته: المصدر مصدر الصفحة و`via` = الصفحة */
    public function test_action_without_row_follows_its_page_source(): void
    {
        // رول أساسي بيمنع الصفحة ⇒ الزرار «role» عن طريق الصفحة
        $this->rolePerm('manager', 'erp.clients', false);
        $m = $this->flat(PermissionMatrix::forRole('manager'));
        $this->assertSame([false, 'role', 'erp.clients'], [$m['act.clients.create']['effective'], $m['act.clients.create']['source'], $m['act.clients.create']['via']]);
        $this->assertNull($m['erp.clients']['via']);
        // زرار أدمن بس: الكود هو اللي قافله مهما كانت الصفحة
        $this->assertSame(['code', null], [$m['act.overview.wipe']['source'], $m['act.overview.wipe']['via']]);

        // رول مخصص بيورث صف صفحة الأساسي ⇒ «base» عن طريق الصفحة
        $custom = $this->custom('manager');
        $c = $this->flat(PermissionMatrix::forRole($custom->key()));
        $this->assertSame([false, 'base', 'erp.clients'], [$c['act.clients.create']['effective'], $c['act.clients.create']['source'], $c['act.clients.create']['via']]);

        // صف يوزر على الصفحة ⇒ «user» عن طريق الصفحة
        $u = $this->userOn('manager');
        UserPermission::create(['user_id' => $u->id, 'perm' => 'erp.contracts', 'allow' => false]);
        $f = $this->flat(PermissionMatrix::forUser($u->fresh()));
        $this->assertSame([false, 'user', 'erp.contracts'], [$f['act.contracts.manage']['effective'], $f['act.contracts.manage']['source'], $f['act.contracts.manage']['via']]);

        // الرول الأساسي مش في رولز الزرار ⇒ الكود (مش الصفحة)
        $acc = $this->flat(PermissionMatrix::forRole('accountant'));
        $this->assertSame(['code', null], [$acc['act.clients.create']['source'], $acc['act.clients.create']['via']]);

        // صفحة من غير صف: الزرار بيتبعها والمصدر كود
        $this->assertSame(['code', 'erp.contracts'], [$m['act.contracts.manage']['source'], $m['act.contracts.manage']['via']]);

        // راوت كتابة مغطّي بأكشن من غير صف ⇒ نفس سلسلة الأكشن
        $this->assertSame(['role', 'erp.clients'], $this->sourceOf('manager', 'erp.clients.store'));
        $this->assertFalse(Access::effectiveFor('manager', 'erp.clients.store'));
        $this->rolePerm('manager', 'act.clients.create', true);
        $this->assertSame(['role', null], $this->sourceOf('manager', 'erp.clients.store'));
    }

    public function test_invalid_values_are_ignored_by_the_service_and_rejected_by_routes(): void
    {
        $diff = PermissionMatrix::save('manager', [
            'erp.dues' => ['0'], 'erp.leads' => 'x', 'erp.contracts' => '2', 'erp.clients' => 0,
        ]);

        $this->assertSame(['erp.clients'], array_column($diff, 'key'));
        $this->assertSame(['erp.clients' => false], RolePermission::mapFor('manager'));

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->postJson(route('erp.perms.diff'), ['role' => 'manager', 'perm' => ['erp.dues' => ['1']]])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('erp.perms.role.save', 'manager'), ['perm' => ['erp.dues' => 'yes']])->assertStatus(422);
        $this->assertSame(['erp.clients' => false], RolePermission::mapFor('manager'));
    }

    /** استثنا جوه المعاينة/الحفظ: الكاش يرجع زي ما كان، والكتابة تترجع */
    public function test_isolated_restores_cache_and_db_on_exception(): void
    {
        $cache = \Illuminate\Support\Facades\Cache::getFacadeRoot();
        $level = \Illuminate\Support\Facades\DB::transactionLevel();

        foreach ([false, true] as $commit) {
            try {
                PermissionMatrix::isolated(function () {
                    RolePermission::create(['role' => 'manager', 'perm' => 'erp.dues', 'allow' => false]);
                    throw new \RuntimeException('boom');
                }, $commit);
                $this->fail('exception swallowed');
            } catch (\RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }

            $this->assertSame($cache, \Illuminate\Support\Facades\Cache::getFacadeRoot());
            $this->assertSame($level, \Illuminate\Support\Facades\DB::transactionLevel());
            $this->assertSame(0, RolePermission::count());
        }

        $this->assertTrue(Access::effectiveFor('manager', 'erp.dues'));
    }

    public function test_admin_is_never_a_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PermissionMatrix::forRole('admin');
    }

    public function test_diff_lists_only_changes_with_effective_before_after(): void
    {
        $this->rolePerm('manager', 'erp.leads', false);

        $rows = PermissionMatrix::diff('manager', [
            'erp.leads' => '',             // false → وراثة: الفعلي يرجع true
            'erp.clients' => '',           // مفيش تغيير — مايطلعش
            'erp.dues' => '0',             // وراثة → منع
            'act.clients.create' => '1',   // وراثة → سماح، الفعلي ثابت
            'bogus.key' => '1',
        ]);

        $by = collect($rows)->keyBy('key');
        $this->assertSame(['act.clients.create', 'erp.dues', 'erp.leads'], $by->keys()->sort()->values()->all());
        $this->assertSame([false, null, false, true], [$by['erp.leads']['before'], $by['erp.leads']['after'], $by['erp.leads']['effective_before'], $by['erp.leads']['effective_after']]);
        $this->assertSame([null, false, true, false], [$by['erp.dues']['before'], $by['erp.dues']['after'], $by['erp.dues']['effective_before'], $by['erp.dues']['effective_after']]);
        $this->assertSame([null, true, true, true], [$by['act.clients.create']['before'], $by['act.clients.create']['after'], $by['act.clients.create']['effective_before'], $by['act.clients.create']['effective_after']]);
        $this->assertSame(PermissionCatalog::risk('act.clients.create'), $by['act.clients.create']['risk']);

        // المعاينة مابتكتبش حاجة
        $this->assertSame(1, RolePermission::count());
        $this->assertTrue(Access::effectiveFor('manager', 'erp.dues'));
        $this->assertFalse(Access::effectiveFor('manager', 'erp.leads'));
    }

    public function test_diff_for_user_shows_effective_after_through_all_layers(): void
    {
        $custom = $this->custom('manager');
        $u = $this->userOn('manager', $custom);

        $rows = collect(PermissionMatrix::diff($u, ['erp.dues' => '0', 'nav.group_clients' => '0']))->keyBy('key');

        $this->assertFalse($rows['erp.dues']['effective_after']);
        $this->assertTrue($rows['nav.group_clients']['effective_before']);
        $this->assertFalse($rows['nav.group_clients']['effective_after']);
        $this->assertSame(0, UserPermission::count());
        $this->assertTrue(Access::allows($u->fresh(), 'erp.dues'));
    }

    public function test_save_ignores_locked_and_unknown_keys(): void
    {
        PermissionMatrix::save('manager', [
            'erp.perms' => '1', 'erp.perms.role.save' => '1', 'erp.worktime' => '1',
            'agent.ask' => '1', 'bogus.route' => '1', Access::PUBLIC_GROUP => '1',
            'erp.dues' => '0',
        ]);

        $this->assertSame(['erp.dues' => false], RolePermission::mapFor('manager'));
        $this->assertFalse(Access::effectiveFor('manager', 'erp.dues'));   // الكاش اتمسح
    }

    public function test_save_writes_one_activity_row_with_diff(): void
    {
        $custom = $this->custom('manager');
        $u = $this->userOn('manager', $custom);

        $diff = PermissionMatrix::save($u, ['erp.dues' => '0', 'act.clients.create' => '0', 'erp.clients' => '']);

        $logs = ActivityLog::where('event', 'perms.saved')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('User', $logs[0]->subject_type);
        $this->assertSame($u->id, (int) $logs[0]->subject_id);
        $this->assertSame($u->name, $logs[0]->title);
        $this->assertCount(2, $logs[0]->changes);
        $this->assertSame($diff, $logs[0]->changes);
        $this->assertFalse(Access::allows($u->fresh(), 'erp.dues'));

        PermissionMatrix::save('manager', ['erp.leads' => '0']);
        PermissionMatrix::save($custom->key(), ['erp.leads' => '1']);
        $this->assertSame(['BuiltinRole', null, 'manager'], ActivityLog::where('subject_type', 'BuiltinRole')->get(['subject_id', 'title'])->map(fn ($l) => ['BuiltinRole', $l->subject_id, $l->title])->first());
        $this->assertSame([$custom->id, $custom->key()], ActivityLog::where('event', 'perms.saved')->where('subject_type', 'Role')->get()->map(fn ($l) => [(int) $l->subject_id, $l->title])->first());

        // حفظ من غير تغيير = مفيش صف
        PermissionMatrix::save('manager', ['erp.leads' => '0']);
        $this->assertSame(3, ActivityLog::where('event', 'perms.saved')->count());
    }

    public function test_save_on_custom_role_does_not_touch_base_rows(): void
    {
        $this->rolePerm('manager', 'erp.leads', false);
        $custom = $this->custom('manager');

        PermissionMatrix::save($custom->key(), ['erp.leads' => '1', 'erp.dues' => '0']);
        PermissionMatrix::save($custom->key(), ['erp.dues' => '']);

        $this->assertSame(['erp.leads' => false], RolePermission::mapFor('manager'));
        $this->assertSame(['erp.leads' => true], RolePermission::mapFor($custom->key()));
        $this->assertTrue(Access::effectiveFor($custom->key(), 'erp.leads'));
        $this->assertFalse(Access::effectiveFor('manager', 'erp.leads'));
    }

    public function test_routes_save_custom_role_and_preview_diff(): void
    {
        $admin = $this->makeAdmin();
        $custom = $this->custom('manager');

        $this->actingAs($admin)
            ->postJson(route('erp.perms.diff'), ['role' => $custom->key(), 'perm' => ['erp.dues' => '0']])
            ->assertOk()
            ->assertJsonPath('changes.0.key', 'erp.dues')
            ->assertJsonPath('changes.0.effective_after', false);
        $this->assertSame(0, RolePermission::count());

        $this->actingAs($admin)
            ->post(route('erp.perms.role.save', $custom->key()), ['perm' => ['erp.dues' => '0']])
            ->assertRedirect();
        $this->assertSame(['erp.dues' => false], RolePermission::mapFor($custom->key()));

        $this->actingAs($admin)->post(route('erp.perms.role.save', 'admin'), ['perm' => ['erp.dues' => '0']])->assertForbidden();
        $this->actingAs($admin)->post(route('erp.perms.role.save', 'c:99999'), ['perm' => ['erp.dues' => '0']])->assertForbidden();
        $this->actingAs($admin)->postJson(route('erp.perms.diff'), ['role' => 'admin', 'perm' => []])->assertForbidden();

        $u = $this->userOn('manager');
        $this->actingAs($admin)->post(route('erp.perms.save', $u), ['perm' => ['erp.dues' => '0']])->assertRedirect();
        $this->assertSame(['erp.dues' => false], $u->fresh()->permMap());

        $this->assertTrue(Access::isLocked('erp.perms.diff'));
        $this->actingAs($this->userOn('manager'))->postJson(route('erp.perms.diff'), ['role' => 'manager'])->assertForbidden();
    }
}
