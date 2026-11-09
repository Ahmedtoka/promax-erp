<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomRoleModelTest extends TestCase
{
    use RefreshDatabase;

    private function role(array $o = []): Role
    {
        return Role::create($o + [
            'name_ar' => 'مدير المخزن', 'name_en' => 'Stock Lead',
            'base_role' => 'warehouse_keeper', 'web_login' => true, 'app_login' => false,
        ]);
    }

    public function test_role_key_is_c_colon_id(): void
    {
        $role = $this->role();
        $this->assertSame('c:'.$role->id, $role->key());
        $this->assertSame($role->id, Role::fromKey($role->key())->id);
        $this->assertNull(Role::fromKey('manager'));
    }

    public function test_user_on_custom_role_shows_custom_label_but_keeps_base_role(): void
    {
        app()->setLocale('ar');
        $role = $this->role();
        $u = User::factory()->create(['role' => 'warehouse_keeper', 'custom_role_id' => $role->id]);

        $this->assertSame('مدير المخزن', $u->roleLabel());
        $this->assertSame('warehouse_keeper', $u->role);
        $this->assertFalse($u->isAdmin());
        $this->assertSame($role->key(), $u->permRoleKey());
    }

    public function test_inactive_custom_role_falls_back_to_base_label(): void
    {
        app()->setLocale('ar');
        $role = $this->role(['active' => false]);
        $u = User::factory()->create(['role' => 'warehouse_keeper', 'custom_role_id' => $role->id]);

        $this->assertNull($u->activeCustomRole());
        $this->assertNull($u->permRoleKey());
        $this->assertSame(__('enums.role.warehouse_keeper'), $u->roleLabel());
    }

    public function test_role_with_users_cannot_be_hard_deleted(): void
    {
        $role = $this->role();
        User::factory()->create(['role' => 'warehouse_keeper', 'custom_role_id' => $role->id]);

        $this->expectException(QueryException::class);
        $role->delete();
    }

    public function test_role_create_is_audited(): void
    {
        $role = $this->role();

        $this->assertTrue(ActivityLog::where('subject_type', 'Role')->where('subject_id', $role->id)->exists());
    }

    public function test_base_roles_exclude_admin(): void
    {
        $this->assertNotContains('admin', Role::BASE_ROLES());
        $this->assertContains('manager', Role::BASE_ROLES());
    }
}
