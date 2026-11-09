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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * شاشة الرولز والصلاحيات (٩/١١، مهمة ٨): تابات الرولز، المصفوفة، ورقة الرول،
 * مين معاه الصلاحية، المقارنة، والسجل — أدمن بس.
 */
class PermissionScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    private function custom(string $base = 'manager', array $attrs = []): Role
    {
        return Role::create($attrs + [
            'name_ar' => 'مدير تجريبي', 'name_en' => 'Trial Lead',
            'base_role' => $base, 'web_login' => true, 'app_login' => false, 'active' => true,
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

    private function tab(string $tab, array $q = []): string
    {
        return route('erp.perms', ['tab' => $tab] + $q);
    }

    public function test_each_tab_renders_for_admin(): void
    {
        $admin = $this->makeAdmin();
        $custom = $this->custom();
        PermissionMatrix::save($custom->key(), ['erp.dues' => '0']);

        $expect = [
            'roles' => 'perm.new_role',
            'matrix' => 'perm.only_diff',
            'sheet' => 'perm.sheet_print',
            'who' => 'perm.who_pick',
            'compare' => 'perm.cmp_only_diff',
            'history' => 'perm.hist_title',
        ];

        foreach ($expect as $tab => $label) {
            $this->actingAs($admin)->get($this->tab($tab))
                ->assertOk()
                ->assertSee(__('perm.tab_'.$tab))
                ->assertSee(__($label));
        }

        // الافتراضي = تاب الرولز
        $this->actingAs($admin)->get(route('erp.perms'))->assertOk()->assertSee(__('perm.new_role'));
        // تاب مجهول ⇒ الرولز برضه، من غير 500
        $this->actingAs($admin)->get($this->tab('nope'))->assertOk()->assertSee(__('perm.new_role'));
    }

    public function test_non_admin_403(): void
    {
        $manager = $this->userOn('manager');

        foreach (['roles', 'matrix', 'sheet', 'who', 'compare', 'history'] as $tab) {
            $this->actingAs($manager)->get($this->tab($tab))->assertForbidden();
        }

        $this->actingAs($manager)->get(route('erp.perms.sheet.excel', ['role' => 'manager']))->assertForbidden();
        $this->assertTrue(Access::isLocked('erp.perms.sheet.excel'));
    }

    public function test_sheet_marks_allowed_and_denied_for_keeper(): void
    {
        $admin = $this->makeAdmin();
        $allowed = null;
        $denied = null;

        foreach (PermissionCatalog::pages() as $page) {
            if (Access::isLocked($page)) {
                continue;
            }

            $on = Access::effectiveFor('warehouse_keeper', $page);
            $allowed ??= $on ? $page : null;
            $denied ??= $on ? null : $page;
        }

        $this->assertNotNull($allowed);
        $this->assertNotNull($denied);

        $html = $this->actingAs($admin)->get($this->tab('sheet', ['role' => 'warehouse_keeper']))
            ->assertOk()
            ->assertSee(__('enums.role.warehouse_keeper'))
            ->getContent();

        $this->assertStringContainsString('data-sheet-key="'.$allowed.'" data-allowed="1"', $html);
        $this->assertStringContainsString('data-sheet-key="'.$denied.'" data-allowed="0"', $html);

        // التغيير على الرول بيبان فوراً في الورقة
        $this->rolePerm('warehouse_keeper', $allowed, false);
        $this->actingAs($admin)->get($this->tab('sheet', ['role' => 'warehouse_keeper']))
            ->assertSee('data-sheet-key="'.$allowed.'" data-allowed="0"', false);
    }

    public function test_sheet_excel_downloads(): void
    {
        $custom = $this->custom();

        $res = $this->actingAs($this->makeAdmin())->get(route('erp.perms.sheet.excel', ['role' => $custom->key()]));

        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('.xlsx', (string) $res->headers->get('content-disposition'));

        // رول مجهول أو أدمن ⇒ 404 مش ملف فاضي
        $this->actingAs($this->makeAdmin())->get(route('erp.perms.sheet.excel', ['role' => 'admin']))->assertNotFound();
        $this->actingAs($this->makeAdmin())->get(route('erp.perms.sheet.excel', ['role' => 'c:99999']))->assertNotFound();
    }

    public function test_who_has_lists_role_and_user_override(): void
    {
        $admin = $this->makeAdmin();
        $custom = $this->custom('warehouse_keeper');
        $this->rolePerm($custom->key(), 'erp.dues', true);
        $user = $this->userOn('accountant');
        UserPermission::create(['user_id' => $user->id, 'perm' => 'erp.dues', 'allow' => false]);

        $html = $this->actingAs($admin)->get($this->tab('who', ['key' => 'erp.dues']))
            ->assertOk()
            ->assertSee($custom->displayName())
            ->assertSee($user->name)
            ->getContent();

        $this->assertStringContainsString('data-who-role="'.$custom->key().'" data-eff="1" data-src="role"', $html);
        $this->assertStringContainsString('data-who-user="'.$user->id.'" data-override="0"', $html);
        // كل الرولز الأساسية (غير الأدمن) في الجدول
        foreach (Role::BASE_ROLES() as $r) {
            $this->assertStringContainsString('data-who-role="'.$r.'"', $html);
        }

        // مفتاح مجهول ⇒ الشاشة بتطلب اختيار، من غير 500
        $this->actingAs($admin)->get($this->tab('who', ['key' => 'no.such.key']))->assertOk()
            ->assertDontSee('data-who-role=', false);
    }

    public function test_compare_shows_only_differences(): void
    {
        $admin = $this->makeAdmin();
        $custom = $this->custom('manager');
        $this->rolePerm($custom->key(), 'erp.dues', ! Access::effectiveFor('manager', 'erp.dues'));

        $q = ['a' => 'manager', 'b' => $custom->key()];

        $this->actingAs($admin)->get($this->tab('compare', $q))->assertOk()
            ->assertSee('data-cmp-key="erp.dues"', false)
            ->assertSee('data-cmp-key="erp.clients"', false);

        $group = collect(Access::NAV)->search(fn ($links) => in_array('erp.dues', array_column($links, 0), true));
        $this->assertIsString($group);

        $this->actingAs($admin)->get($this->tab('compare', $q + ['only' => 1]))->assertOk()
            ->assertSee('data-cmp-key="erp.dues"', false)
            // عنوان القسم بيفضل لما فيه فرق تحته
            ->assertSee('data-cmp-key="'.$group.'"', false)
            ->assertDontSee('data-cmp-key="erp.clients"', false);
    }

    /**
     * فلتر «المتحدد بس» والعدّاد بيعتمدوا على `data-pm-row` — لازم يبقى على
     * السطور اللي ليها تحكم بس، والمقفول/القسم الوهمي `data-locked`.
     */
    public function test_explicit_only_filter_marker_is_only_on_rows_with_controls(): void
    {
        $html = $this->actingAs($this->makeAdmin())->get($this->tab('matrix', ['role' => 'manager']))->assertOk()->getContent();

        $this->assertStringContainsString('data-key="erp.perms" data-locked', $html);
        $this->assertStringContainsString('data-key="'.Access::PUBLIC_GROUP.'" data-locked', $html);
        $this->assertStringContainsString('data-key="erp.dues" data-pm-row', $html);

        preg_match_all('/data-key="([^"]+)" data-pm-row/', $html, $marked);
        preg_match_all('/name="perm\[([^\]]+)\]" value="1"/', $html, $controls);
        $this->assertSame(array_values(array_unique($controls[1])), $marked[1], 'every data-pm-row has exactly one tri-state control');
        $this->assertSame([], array_values(array_filter($marked[1], fn ($k) => Access::isLocked($k))));
    }

    public function test_role_names_are_escaped_on_every_tab(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $evil = '<script>alert(1)</script>';
        $role = $this->custom('manager', ['name_ar' => $evil, 'name_en' => $evil]);
        PermissionMatrix::save($role->key(), ['erp.dues' => '0']);

        foreach ([
            $this->tab('roles'),
            $this->tab('matrix', ['role' => $role->key()]),
            $this->tab('sheet', ['role' => $role->key()]),
            $this->tab('who', ['key' => 'erp.dues']),
            $this->tab('compare', ['a' => 'manager', 'b' => $role->key()]),
            $this->tab('history'),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($evil, $html, $url);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $url);
        }
    }

    public function test_who_has_locked_key_and_deleted_clone_source(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get($this->tab('who', ['key' => 'erp.perms']))->assertOk()
            ->assertSee(__('perm.locked_short'))
            ->assertDontSee('<td>'.__('perm.seg_inherit').'</td>', false);

        $this->custom('manager', ['cloned_from' => 'c:99999']);
        $this->actingAs($admin)->get($this->tab('roles'))->assertOk()
            ->assertSee(__('perm.hist_deleted_role', ['id' => 99999]))
            ->assertDontSee('enums.role.c:');
    }

    public function test_who_has_does_not_query_per_override_user(): void
    {
        $admin = $this->makeAdmin();
        $count = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get($this->tab('who', ['key' => 'erp.dues']))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        UserPermission::create(['user_id' => $this->userOn('accountant')->id, 'perm' => 'erp.dues', 'allow' => true]);
        $one = $count();

        foreach (range(1, 6) as $i) {
            UserPermission::create(['user_id' => $this->userOn('accountant')->id, 'perm' => 'erp.dues', 'allow' => (bool) ($i % 2)]);
        }

        $this->assertLessThanOrEqual($one + 2, $count(), 'who-has must not run a query per override user');
    }

    public function test_matrix_has_tri_state_controls_and_locked_rows_read_only(): void
    {
        $admin = $this->makeAdmin();
        $this->rolePerm('manager', 'erp.dues', false);

        $html = $this->actingAs($admin)->get($this->tab('matrix', ['role' => 'manager']))
            ->assertOk()
            ->assertSee(route('erp.perms.role.save', 'manager'), false)
            ->assertSee(route('erp.perms.diff'), false)
            ->getContent();

        // التلات حالات، والمنع المحفوظ مختار
        $this->assertStringContainsString('name="perm[erp.dues]" value="0" checked', $html);
        $this->assertStringContainsString('name="perm[erp.dues]" value="1"', $html);
        $this->assertStringContainsString('name="perm[erp.dues]" value=""', $html);
        // المقفول: مفيش إدخال، والسبب ظاهر
        $this->assertStringNotContainsString('name="perm[erp.perms]"', $html);
        $this->assertStringContainsString(e(__('perm.locked_reason')), $html);
        // القسم الوهمي مالوش تحكم
        $this->assertStringNotContainsString('name="perm['.Access::PUBLIC_GROUP.']"', $html);
    }

    public function test_matrix_for_a_user_posts_to_the_user_save_route(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->userOn('accountant');
        UserPermission::create(['user_id' => $user->id, 'perm' => 'erp.dues', 'allow' => true]);

        $this->actingAs($admin)->get($this->tab('matrix', ['user' => $user->id]))
            ->assertOk()
            ->assertSee(route('erp.perms.save', $user), false)
            ->assertSee('name="perm[erp.dues]" value="1" checked', false);

        // أدمن مش موضوع للمصفوفة ⇒ الشاشة بترجع لاختيار رول من غير 500
        $this->actingAs($admin)->get($this->tab('matrix', ['user' => $admin->id]))->assertOk()
            ->assertDontSee(route('erp.perms.save', $admin), false);
    }

    public function test_matrix_page_avoids_n_plus_one(): void
    {
        $admin = $this->makeAdmin();
        $custom = $this->custom();
        $this->rolePerm($custom->key(), 'erp.dues', false);
        $this->get('/'); // تسخين

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get($this->tab('matrix', ['role' => $custom->key()]))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(40, $n, "matrix tab ran {$n} queries");
    }

    public function test_roles_tab_lists_counts_and_blocks_delete_with_users(): void
    {
        $admin = $this->makeAdmin();
        $busy = $this->custom('warehouse_keeper', ['name_ar' => 'مدير المخزن', 'name_en' => 'Store Lead']);
        $idle = $this->custom('accountant', ['name_ar' => 'محاسب مساعد', 'name_en' => 'Junior Acc']);
        $this->userOn('warehouse_keeper', $busy);
        $this->userOn('warehouse_keeper', $busy);
        $this->rolePerm($busy->key(), 'erp.reports', true);

        $html = $this->actingAs($admin)->get($this->tab('roles'))
            ->assertOk()
            ->assertSee('مدير المخزن')
            ->assertSee('محاسب مساعد')
            ->assertSee(route('erp.roles.store'), false)
            ->assertSee(route('erp.roles.clone'), false)
            ->assertSee('data-pm-delete="'.$idle->key().'"', false)
            ->assertSee(e(__('perm.scope_branch_warning')), false)
            ->getContent();

        $this->assertStringContainsString('data-role-row="'.$busy->key().'" data-users="2" data-diffs="1"', $html);
        // عليه يوزرز ⇒ المسح متقفل بالسبب، ومفيش فورم مسح له
        $this->assertStringNotContainsString('data-pm-delete="'.$busy->key().'"', $html);
        $this->assertStringContainsString(e(__('role.has_users', ['count' => 2])), $html);
        // الرولز الأساسية في نفس الجدول
        $this->assertStringContainsString('data-role-row="warehouse_keeper"', $html);
    }

    public function test_role_refusal_error_is_shown(): void
    {
        $admin = $this->makeAdmin();
        $busy = $this->custom();
        $this->userOn('manager', $busy);

        $this->actingAs($admin)->from($this->tab('roles'))
            ->delete(route('erp.roles.destroy', $busy))
            ->assertRedirect($this->tab('roles'));

        $this->actingAs($admin)->get($this->tab('roles'))
            ->assertSee(__('role.has_users', ['count' => 1]));
    }

    public function test_history_lists_perm_saves_and_role_events(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin);
        $custom = $this->custom('manager', ['name_ar' => 'رول السجل', 'name_en' => 'History Role']);
        PermissionMatrix::save($custom->key(), ['erp.dues' => '0']);
        PermissionMatrix::save('accountant', ['erp.reports' => '1']);
        ActivityLog::record('created', ['subject_type' => 'Client', 'subject_id' => 1, 'title' => 'NOT-A-PERM-ROW']);

        $this->get($this->tab('history'))
            ->assertOk()
            ->assertSee('رول السجل')
            ->assertSee(__('enums.role.accountant'))
            ->assertSee('erp.dues')
            ->assertDontSee('NOT-A-PERM-ROW');
    }
}
