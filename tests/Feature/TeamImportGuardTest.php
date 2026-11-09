<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\Product;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Importers\TeamImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * استيراد الفريق للأدمن بس (مراجعة ٩/١١ — حرج ١): منحة `act.import.run`
 * من المصفوفة ماتديش غير الأدمن صلاحية إنشاء/تعديل يوزرز (وأدمنز) بشيت.
 */
class TeamImportGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    /** مدير معاه صفحة الاستيراد + زرار التشغيل من المصفوفة */
    private function grantedManager(): User
    {
        RolePermission::create(['role' => 'manager', 'perm' => 'erp.import', 'allow' => true]);
        RolePermission::create(['role' => 'manager', 'perm' => 'act.import.run', 'allow' => true]);
        RolePermission::flush();

        return User::factory()->create(['role' => 'manager', 'code' => 'M-'.strtoupper(uniqid())]);
    }

    private function csv(string $name, string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function teamCsv(): UploadedFile
    {
        return $this->csv('team.csv', "name,role,email\nNew Boss,admin,boss@promax.test\n");
    }

    public function test_non_admin_with_import_grant_cannot_upload_team(): void
    {
        $m = $this->grantedManager();
        $before = User::count();

        $this->actingAs($m)->post(route('erp.import.upload'), ['kind' => 'team', 'file' => $this->teamCsv()])
            ->assertForbidden();

        $this->assertSame(0, Import::count());
        $this->assertSame($before, User::count());
        $this->assertNull(User::where('email', 'boss@promax.test')->first());
    }

    public function test_non_admin_cannot_apply_or_preview_a_team_import_uploaded_by_admin(): void
    {
        $admin = $this->makeAdmin();
        $victim = $this->makeAdmin(['email' => 'victim@promax.test']);

        $this->actingAs($admin)->post(route('erp.import.upload'), [
            'kind' => 'team',
            'file' => $this->csv('team.csv', "name,role,email\nDemoted,driver,victim@promax.test\n"),
        ])->assertRedirect();

        $import = Import::firstOrFail();
        $m = $this->grantedManager();

        $this->actingAs($m)->get(route('erp.import.preview', $import))->assertForbidden();
        $this->actingAs($m)->post(route('erp.import.apply', $import))->assertForbidden();

        $this->assertTrue($import->fresh()->isPending());
        $this->assertSame('admin', $victim->fresh()->role);
    }

    public function test_team_kind_is_hidden_from_non_admin_import_page(): void
    {
        $m = $this->grantedManager();

        $this->actingAs($m)->get(route('erp.import'))->assertOk()
            ->assertDontSee('value="team"', false)
            ->assertSee('value="products"', false);

        $this->actingAs($this->makeAdmin())->get(route('erp.import'))->assertOk()
            ->assertSee('value="team"', false);
    }

    public function test_other_kinds_still_work_for_granted_non_admin(): void
    {
        $m = $this->grantedManager();

        $this->actingAs($m)->post(route('erp.import.upload'), [
            'kind' => 'products',
            'file' => $this->csv('p.csv', "code,name,price_new\nP-IMP-1,Imported Bar,12.5\n"),
        ])->assertRedirect();

        $import = Import::firstOrFail();
        $this->actingAs($m)->get(route('erp.import.preview', $import))->assertOk();
        $this->actingAs($m)->post(route('erp.import.apply', $import))->assertRedirect(route('erp.import'));

        $this->assertSame(Import::STATUS_APPLIED, $import->fresh()->status);
        $this->assertNotNull(Product::where('code', 'P-IMP-1')->first());
    }

    public function test_admin_import_changing_role_clears_custom_role(): void
    {
        $admin = $this->makeAdmin();
        $role = Role::create([
            'name_ar' => 'رول', 'name_en' => 'Probe', 'base_role' => 'manager',
            'web_login' => true, 'app_login' => false,
        ]);

        $moved = User::factory()->create(['role' => 'manager', 'email' => 'moved@promax.test', 'code' => 'M-MOVED']);
        $moved->custom_role_id = $role->id;
        $moved->save();

        $kept = User::factory()->create(['role' => 'manager', 'email' => 'kept@promax.test', 'code' => 'M-KEPT']);
        $kept->custom_role_id = $role->id;
        $kept->save();

        $this->actingAs($admin);
        (new TeamImporter)->apply([
            ['name' => 'Moved', 'role' => 'driver', 'email' => 'moved@promax.test', 'code' => 'M-MOVED'],
            ['name' => 'Kept', 'role' => 'manager', 'email' => 'kept@promax.test', 'code' => 'M-KEPT'],
        ]);

        $this->assertSame(['driver', null], [$moved->fresh()->role, $moved->fresh()->custom_role_id]);
        $this->assertSame(['manager', $role->id], [$kept->fresh()->role, (int) $kept->fresh()->custom_role_id]);
    }
}
