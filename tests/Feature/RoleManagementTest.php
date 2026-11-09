<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RolePermission;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** إدارة الرولز المخصصة (٩/١١) — إنشاء / نسخ / تعديل / إيقاف / مسح. */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'name_ar' => 'مشرف مخزن',
            'name_en' => 'Store Lead',
            'base_role' => 'warehouse_keeper',
            'web_login' => 1,
            'app_login' => 0,
        ], $over);
    }

    private function makeRole(array $over = []): Role
    {
        return Role::create(array_merge([
            'name_ar' => 'رول '.uniqid(),
            'name_en' => 'Role '.uniqid(),
            'base_role' => 'warehouse_keeper',
            'web_login' => true,
            'app_login' => false,
            'active' => true,
        ], $over));
    }

    public function test_admin_creates_role_with_base(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.roles.store'), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect();

        $role = Role::firstWhere('name_en', 'Store Lead');
        $this->assertNotNull($role);
        $this->assertSame('warehouse_keeper', $role->base_role);
        $this->assertTrue($role->web_login);
        $this->assertFalse($role->app_login);
        $this->assertSame($admin->id, $role->created_by);
    }

    public function test_clone_builtin_has_no_rows_and_same_effective_access(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.roles.clone'), [
            'source' => 'warehouse_keeper', 'name_ar' => 'نسخة مخزن', 'name_en' => 'WH Copy',
            'web_login' => 1, 'app_login' => 0,
        ])->assertSessionHasNoErrors();

        $role = Role::firstWhere('name_en', 'WH Copy');
        $this->assertSame('warehouse_keeper', $role->base_role);
        $this->assertSame('warehouse_keeper', $role->cloned_from);
        $this->assertSame(0, RolePermission::where('role', $role->key())->count());

        foreach (Access::NAV as $links) {
            foreach ($links as [$route]) {
                $this->assertSame(
                    Access::effectiveFor('warehouse_keeper', $route),
                    Access::effectiveFor($role->key(), $route),
                    $route
                );
            }
        }
    }

    public function test_clone_custom_copies_rows_with_same_base(): void
    {
        $admin = $this->makeAdmin();
        $src = $this->makeRole(['base_role' => 'accountant']);
        RolePermission::create(['role' => $src->key(), 'perm' => 'ops.invoices', 'allow' => true]);
        RolePermission::create(['role' => $src->key(), 'perm' => 'nav.group_x', 'allow' => false]);
        RolePermission::flush();

        $this->actingAs($admin)->post(route('erp.roles.clone'), [
            'source' => $src->key(), 'name_ar' => 'نسخة', 'name_en' => 'Copy of custom',
            'web_login' => 1, 'app_login' => 1,
        ])->assertSessionHasNoErrors();

        $copy = Role::firstWhere('name_en', 'Copy of custom');
        $this->assertSame('accountant', $copy->base_role);
        $this->assertSame($src->key(), $copy->cloned_from);
        $rows = RolePermission::where('role', $copy->key())->pluck('allow', 'perm')->map(fn ($v) => (bool) $v)->all();
        $this->assertEquals(['ops.invoices' => true, 'nav.group_x' => false], $rows);
        $this->assertSame(2, RolePermission::where('role', $src->key())->count());
    }

    public function test_base_role_cannot_change_on_update(): void
    {
        $admin = $this->makeAdmin();
        $role = $this->makeRole(['base_role' => 'driver']);

        $this->actingAs($admin)->put(route('erp.roles.update', $role), [
            'name_ar' => 'اسم جديد', 'name_en' => 'New Name', 'base_role' => 'accountant',
            'web_login' => 0, 'app_login' => 1, 'description_en' => 'desc',
        ])->assertSessionHasNoErrors();

        $role->refresh();
        $this->assertSame('driver', $role->base_role);
        $this->assertSame('New Name', $role->name_en);
        $this->assertFalse($role->web_login);
        $this->assertTrue($role->app_login);
        $this->assertSame('desc', $role->description_en);
    }

    public function test_admin_base_is_rejected(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.roles.store'), $this->payload(['base_role' => 'admin']))
            ->assertSessionHasErrors('base_role');
        $this->assertSame(0, Role::count());
    }

    public function test_names_must_be_unique_and_not_builtin_labels(): void
    {
        $admin = $this->makeAdmin();
        $this->makeRole(['name_en' => 'Taken']);

        $this->actingAs($admin)->post(route('erp.roles.store'), $this->payload(['name_en' => 'taken']))
            ->assertSessionHasErrors('name_en');
        $this->actingAs($admin)->post(route('erp.roles.store'), $this->payload(['name_en' => ' accountant ']))
            ->assertSessionHasErrors('name_en');
        $this->actingAs($admin)->post(route('erp.roles.store'), $this->payload(['name_ar' => str_repeat('ا', 61)]))
            ->assertSessionHasErrors('name_ar');
        $this->assertSame(1, Role::count());
    }

    public function test_cannot_deactivate_or_delete_with_users(): void
    {
        $admin = $this->makeAdmin();
        $role = $this->makeRole();
        $u = $this->makeAdmin(['role' => 'warehouse_keeper', 'active' => false]);
        $u->custom_role_id = $role->id;
        $u->save();

        $this->actingAs($admin)->post(route('erp.roles.toggle', $role))->assertSessionHasErrors('role');
        $this->assertTrue($role->fresh()->active);

        $this->actingAs($admin)->delete(route('erp.roles.destroy', $role))->assertSessionHasErrors('role');
        $this->assertNotNull(Role::find($role->id));

        // من غير يوزرز: الإيقاف والتفعيل شغالين
        $empty = $this->makeRole();
        $this->actingAs($admin)->post(route('erp.roles.toggle', $empty));
        $this->assertFalse($empty->fresh()->active);
        $this->actingAs($admin)->post(route('erp.roles.toggle', $empty));
        $this->assertTrue($empty->fresh()->active);
    }

    public function test_delete_removes_permission_rows(): void
    {
        $admin = $this->makeAdmin();
        $role = $this->makeRole();
        $other = $this->makeRole();
        RolePermission::create(['role' => $role->key(), 'perm' => 'ops.invoices', 'allow' => true]);
        RolePermission::create(['role' => $other->key(), 'perm' => 'ops.invoices', 'allow' => true]);

        $this->actingAs($admin)->delete(route('erp.roles.destroy', $role))->assertSessionHasNoErrors();

        $this->assertNull(Role::find($role->id));
        $this->assertSame(0, RolePermission::where('role', $role->key())->count());
        $this->assertSame(1, RolePermission::where('role', $other->key())->count());
    }

    public function test_non_admin_gets_403_even_with_override_row(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant']);
        RolePermission::create(['role' => 'accountant', 'perm' => 'erp.roles.store', 'allow' => true]);
        RolePermission::flush();

        $this->actingAs($acc)->post(route('erp.roles.store'), $this->payload())->assertForbidden();
        $this->assertSame(0, Role::count());
    }
}
