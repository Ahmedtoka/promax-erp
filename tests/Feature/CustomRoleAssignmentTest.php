<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعيين اليوزر على رول مخصص (٩/١١) — `c:<id>` من الفورم بيتحول لرول أساسي + custom_role_id،
 * وفلاجات دخول الويب/الأبلكيشن.
 */
class CustomRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    private function role(array $o = []): Role
    {
        return Role::create($o + [
            'name_ar' => 'مدير المخزن', 'name_en' => 'Stock Lead',
            'base_role' => 'warehouse_keeper', 'web_login' => true, 'app_login' => true,
        ]);
    }

    private function payload(array $o = []): array
    {
        return $o + [
            'name' => 'موظف جديد', 'name_en' => 'New Staff',
            'email' => 'n'.uniqid().'@promax.test', 'role' => 'accountant',
            'password' => 'secret-pass-1', 'active' => 1,
        ];
    }

    private function wh(): Warehouse
    {
        return Warehouse::create(['code' => 'W'.uniqid(), 'name' => 'مخزن', 'name_en' => 'WH', 'active' => true]);
    }

    private function branch(): Branch
    {
        return Branch::create(['code' => 'B'.uniqid(), 'name' => 'فرع', 'name_en' => 'Br', 'active' => true]);
    }

    public function test_admin_assigns_custom_role_sets_base_and_id(): void
    {
        $role = $this->role();
        $wh = $this->wh();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.team.store'),
            $this->payload(['role' => $role->key(), 'warehouse_id' => $wh->id, 'email' => 'c1@promax.test']))
            ->assertSessionHasNoErrors();

        $u = User::where('email', 'c1@promax.test')->firstOrFail();
        $this->assertSame('warehouse_keeper', $u->role);
        $this->assertSame($role->id, (int) $u->custom_role_id);
        $this->assertSame($role->id, $u->activeCustomRole()->id);
    }

    public function test_switching_back_to_builtin_clears_custom_role(): void
    {
        $role = $this->role();
        $admin = $this->makeAdmin();
        $u = User::factory()->create(['role' => 'warehouse_keeper', 'warehouse_id' => $this->wh()->id, 'code' => 'T-1']);
        $u->custom_role_id = $role->id;
        $u->save();

        $this->actingAs($admin)->put(route('erp.team.update', $u), [
            'name' => 'س', 'name_en' => 'S', 'email' => $u->email, 'role' => 'accountant', 'active' => 1,
        ])->assertSessionHasNoErrors();

        $u->refresh();
        $this->assertSame('accountant', $u->role);
        $this->assertNull($u->custom_role_id);
    }

    public function test_unknown_or_inactive_custom_role_is_rejected(): void
    {
        $inactive = $this->role(['active' => false]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.team.store'), $this->payload(['role' => $inactive->key()]))
            ->assertSessionHasErrors('role');
        $this->actingAs($admin)->post(route('erp.team.store'), $this->payload(['role' => 'c:99999']))
            ->assertSessionHasErrors('role');
    }

    public function test_keeper_based_custom_role_requires_warehouse(): void
    {
        $role = $this->role();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.team.store'), $this->payload(['role' => $role->key()]))
            ->assertSessionHasErrors('warehouse_id');
    }

    public function test_field_based_custom_role_requires_branch(): void
    {
        $role = $this->role(['base_role' => 'sales_agent', 'name_en' => 'Field Lead']);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.team.store'), $this->payload(['role' => $role->key()]))
            ->assertSessionHasErrors('branch_id');

        $this->actingAs($admin)->post(route('erp.team.store'),
            $this->payload(['role' => $role->key(), 'branch_id' => $this->branch()->id, 'email' => 'f1@promax.test']))
            ->assertSessionHasNoErrors();
        $this->assertSame('sales_agent', User::where('email', 'f1@promax.test')->value('role'));
    }

    public function test_team_manager_cannot_assign_admin_or_custom_or_edit_admin(): void
    {
        $role = $this->role();
        $adminTarget = $this->makeAdmin();
        $mgr = User::factory()->create(['role' => 'accountant', 'code' => 'T-M']);

        UserPermission::create(['user_id' => $mgr->id, 'perm' => 'act.team.manage', 'allow' => true]);

        $mgr = $mgr->fresh();

        $this->actingAs($mgr)->post(route('erp.team.store'), $this->payload(['role' => 'admin']))
            ->assertSessionHasErrors('role');
        $this->actingAs($mgr)->post(route('erp.team.store'),
            $this->payload(['role' => $role->key(), 'warehouse_id' => $this->wh()->id]))
            ->assertSessionHasErrors('role');
        $this->actingAs($mgr)->put(route('erp.team.update', $adminTarget), [
            'name' => 'x', 'name_en' => 'x', 'email' => $adminTarget->email, 'role' => 'accountant',
        ])->assertForbidden();
        // وماينفعش يرقّي يوزر عادي لأدمن من مسار التعديل
        $plain = User::factory()->create(['role' => 'accountant', 'code' => 'T-P']);
        $this->actingAs($mgr)->put(route('erp.team.update', $plain), [
            'name' => 'x', 'name_en' => 'x', 'email' => $plain->email, 'role' => 'admin',
        ])->assertSessionHasErrors('role');
        $this->assertSame('accountant', $plain->fresh()->role);

        $this->actingAs($mgr)->post(route('erp.team.password', $adminTarget), [
            'password' => 'newpass-123', 'password_confirmation' => 'newpass-123',
        ])->assertForbidden();

        // وبرضه يقدر يضيف يوزر عادي
        $this->actingAs($mgr)->post(route('erp.team.store'), $this->payload(['email' => 'ok1@promax.test']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'ok1@promax.test', 'role' => 'accountant']);
    }

    public function test_team_manager_editing_kept_field_custom_role_still_needs_branch(): void
    {
        $role = $this->role(['base_role' => 'sales_agent', 'name_en' => 'Field Lead']);
        $br = $this->branch();
        $u = User::factory()->create(['role' => 'sales_agent', 'code' => 'T-K', 'branch_id' => $br->id]);
        $u->custom_role_id = $role->id;
        $u->save();
        $mgr = User::factory()->create(['role' => 'accountant', 'code' => 'T-KM']);
        UserPermission::create(['user_id' => $mgr->id, 'perm' => 'act.team.manage', 'allow' => true]);
        $mgr = $mgr->fresh();

        $body = ['name' => 'س', 'name_en' => 'S', 'email' => $u->email, 'role' => 'sales_agent', 'active' => 1];

        $this->actingAs($mgr)->put(route('erp.team.update', $u), $body + ['branch_id' => ''])
            ->assertSessionHasErrors('branch_id');

        $this->actingAs($mgr)->put(route('erp.team.update', $u), $body + ['branch_id' => $br->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($role->id, (int) $u->fresh()->custom_role_id);
    }

    private function appUser(Role $role, string $base = 'accountant'): User
    {
        $u = User::create([
            'name' => 'أبلكيشن', 'name_en' => 'App', 'email' => 'a'.uniqid().'@promax.test',
            'password' => bcrypt('secret'), 'role' => $base, 'code' => 'A-'.uniqid(), 'active' => true,
        ]);
        $u->custom_role_id = $role->id;
        $u->save();

        return $u;
    }

    public function test_api_login_blocked_when_app_login_off(): void
    {
        $role = $this->role(['base_role' => 'accountant', 'app_login' => false]);
        $u = $this->appUser($role);

        $this->postJson('/api/login', ['login' => $u->email, 'password' => 'secret'])
            ->assertForbidden()
            ->assertJsonPath('message', __('api.no_app_access'));
    }

    public function test_api_payload_keeps_base_role_and_custom_label(): void
    {
        $role = $this->role(['base_role' => 'accountant', 'app_login' => true]);
        $u = $this->appUser($role);

        $this->postJson('/api/login', ['login' => $u->email, 'password' => 'secret'])
            ->assertOk()
            ->assertJsonPath('user.role', 'accountant')
            ->assertJsonPath('user.role_label', $role->displayName())
            ->assertJsonPath('user.custom_role', $role->displayName());
    }

    public function test_team_page_lists_custom_roles_and_preselects_for_admin_only(): void
    {
        $role = $this->role(['name_en' => 'Stock Lead', 'name_ar' => 'مدير المخزن']);
        $admin = $this->makeAdmin();
        $u = User::factory()->create(['role' => 'warehouse_keeper', 'code' => 'T-V', 'warehouse_id' => $this->wh()->id]);
        $u->custom_role_id = $role->id;
        $u->save();

        $html = $this->actingAs($admin)->get(route('erp.team'))->assertOk()->getContent();
        $this->assertStringContainsString('value="c:'.$role->id.'"', $html);
        $this->assertStringContainsString('"role":"c:'.$role->id.'"', html_entity_decode($html));

        $mgr = User::factory()->create(['role' => 'accountant', 'code' => 'T-W']);
        foreach (['erp.team', 'act.team.manage'] as $k) {
            UserPermission::create(['user_id' => $mgr->id, 'perm' => $k, 'allow' => true]);
        }
        $html = $this->actingAs($mgr->fresh())->get(route('erp.team'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<optgroup label="'.__('team.custom_roles_group').'">', $html);
        $sel = substr($html, strpos($html, '<select name="role" id="uRole"'), 1500);
        $this->assertStringNotContainsString('value="admin"', $sel);
        $this->assertStringContainsString('value="accountant"', $sel);
    }

    public function test_web_users_scope_includes_web_enabled_field_custom_role(): void
    {
        $webField = $this->role(['base_role' => 'sales_agent', 'name_en' => 'WF', 'web_login' => true]);
        $noWeb = $this->role(['base_role' => 'accountant', 'name_en' => 'NW', 'web_login' => false]);

        $a = User::factory()->create(['role' => 'sales_agent', 'code' => 'T-A']);
        $a->custom_role_id = $webField->id;
        $a->save();
        $b = User::factory()->create(['role' => 'accountant', 'code' => 'T-B']);
        $b->custom_role_id = $noWeb->id;
        $b->save();
        $c = User::factory()->create(['role' => 'accountant', 'code' => 'T-C']);
        $d = User::factory()->create(['role' => 'sales_agent', 'code' => 'T-D']);
        // رول مخصص موقوف = بيرجع لسلوك الأساسي (محاسب = ويب، ميداني = لأ)
        $off = $this->role(['base_role' => 'accountant', 'name_en' => 'Off', 'web_login' => false, 'active' => false]);
        $e = User::factory()->create(['role' => 'accountant', 'code' => 'T-E']);
        $e->custom_role_id = $off->id;
        $e->save();

        $ids = User::webUsers()->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
        $this->assertContains($c->id, $ids);
        $this->assertNotContains($d->id, $ids);
        $this->assertContains($e->id, $ids);
    }
}
