<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قفل `app_login` على رول مخصص لازم يقفل الجلسات المفتوحة كمان، مش
 * اللوجين الجديد بس (مراجعة ٩/١١ — مهم ٣).
 */
class AppLoginRevokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    private function bearer(User $u): array
    {
        return ['Authorization' => 'Bearer '.$u->issueToken('mobile')->token, 'Accept' => 'application/json'];
    }

    public function test_switching_app_login_off_refuses_existing_token(): void
    {
        $role = Role::create([
            'name_ar' => 'مندوب خاص', 'name_en' => 'Special Rep', 'base_role' => 'sales_agent',
            'web_login' => false, 'app_login' => true, 'active' => true,
        ]);

        $u = $this->makeRep();
        $u->custom_role_id = $role->id;
        $u->save();

        $h = $this->bearer($u);
        $this->getJson('/api/me', $h)->assertOk();

        $role->update(['app_login' => false]);

        $this->getJson('/api/me', $h)->assertStatus(401)
            ->assertJson(['message' => __('api.no_app_access')]);
    }

    public function test_built_in_and_inactive_custom_role_users_unaffected(): void
    {
        $rep = $this->makeRep();
        $this->getJson('/api/me', $this->bearer($rep))->assertOk();

        // رول مخصص متوقف = الأساس — مابيقفلش
        $role = Role::create([
            'name_ar' => 'متوقف', 'name_en' => 'Off', 'base_role' => 'sales_agent',
            'web_login' => false, 'app_login' => false, 'active' => false,
        ]);
        $other = $this->makeRep();
        $other->custom_role_id = $role->id;
        $other->save();

        $this->getJson('/api/me', $this->bearer($other->fresh()))->assertOk();
    }
}
