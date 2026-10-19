<?php

namespace Tests\Feature\Hr;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use App\Services\Gl\CostCenterResolver;
use App\Services\Hr\Employees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ملف الموظف (Lane 7 — المهمة ١): الموظف كيان مستقل عن اليوزر (ممكن
 * يبقى من غير لوجين)، ورول `hr` الجديد، وخطوة الموظف في `CostCenterResolver`.
 */
class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    private function hrUser(array $attrs = []): User
    {
        return $this->makeAdmin(array_merge(['role' => 'hr', 'email' => 'hr'.uniqid().'@promax.test'], $attrs));
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'code' => Employee::nextCode(),
            'name' => 'موظف تجربة',
            'name_en' => 'Test Employee',
        ], $attrs));
    }

    public function test_hr_creates_an_employee_with_defaults(): void
    {
        $this->actingAs($this->hrUser())
            ->post('/hr/employees', ['name' => 'أحمد علي', 'code' => ''])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $e = Employee::query()->where('name', 'أحمد علي')->firstOrFail();
        $this->assertTrue($e->insured);
        $this->assertTrue($e->taxable);
        $this->assertSame('employee', $e->employee_share_borne_by);
        $this->assertSame('cash', $e->pay_method);
        $this->assertSame('EMP-1001', $e->code);
        $this->assertTrue($e->active);
        $this->assertTrue($e->attendance_required);
    }

    public function test_employee_without_login_is_allowed(): void
    {
        $this->actingAs($this->hrUser())
            ->post('/hr/employees', ['name' => 'عامل نظافة', 'user_id' => ''])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $e = Employee::query()->where('name', 'عامل نظافة')->firstOrFail();
        $this->assertNull($e->user_id);
        $this->assertNull(Employee::forUser(null));
    }

    public function test_for_user_finds_the_linked_employee(): void
    {
        $u = $this->makeRep();
        $e = $this->employee(['user_id' => $u->id]);

        $this->assertSame($e->id, Employee::forUser($u)?->id);
    }

    public function test_manager_must_be_another_employee(): void
    {
        $e = $this->employee();

        $this->actingAs($this->hrUser())
            ->from('/hr/employees')
            ->put('/hr/employees/'.$e->id, ['name' => $e->name, 'code' => $e->code, 'manager_id' => $e->id])
            ->assertStatus(302)
            ->assertSessionHasErrors('manager_id');

        $this->assertNull($e->fresh()->manager_id);
    }

    public function test_hr_updates_an_employee_and_uploads_a_contract(): void
    {
        $boss = $this->employee(['name' => 'المدير']);
        $e = $this->employee();
        $hr = $this->hrUser();

        $this->actingAs($hr)
            ->put('/hr/employees/'.$e->id, [
                'name' => 'اسم جديد', 'code' => $e->code, 'manager_id' => $boss->id,
                'basic_salary' => '5000', 'pay_method' => 'bank', 'bank_account' => 'EG12',
                'insured' => '0', 'employee_share_borne_by' => 'company', 'national_id' => '29001011234567',
                'contract' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf'),
            ])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertSame('اسم جديد', $e->name);
        $this->assertSame($boss->id, $e->manager_id);
        $this->assertSame('5000.00', (string) $e->basic_salary);
        $this->assertFalse($e->insured);
        $this->assertSame('company', $e->employee_share_borne_by);
        $this->assertNotNull($e->contract_path);
        $this->assertStringStartsWith('hr/contracts/', $e->contract_path);
        $full = storage_path('app/'.$e->contract_path);
        $this->assertFileExists($full);

        $this->actingAs($hr)->get('/hr/employees/'.$e->id.'/contract')->assertOk();
        // المحاسب بيشوف كل الملفات (`visibleTo`) — فالعقد مفتوح له قراءة
        $this->actingAs($this->makeAdmin(['role' => 'accountant']))->get('/hr/employees/'.$e->id.'/contract')->assertOk();

        @unlink($full);
    }

    public function test_accountant_sees_list_but_cannot_store(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant']);

        $this->actingAs($acc)->get('/hr/employees')->assertOk();
        $this->actingAs($acc)->post('/hr/employees', ['name' => 'x'])->assertForbidden();
        $this->assertSame(0, Employee::query()->count());
    }

    public function test_list_hides_national_id_and_insurance_number(): void
    {
        $this->employee(['national_id' => '29001011234567', 'insurance_no' => 'INS-998877']);

        $this->actingAs($this->hrUser())->get('/hr/employees')
            ->assertOk()
            ->assertSee('Test Employee', false)
            ->assertDontSee('29001011234567', false)
            ->assertDontSee('INS-998877', false);
    }

    public function test_edit_form_shows_national_id_to_hr_only(): void
    {
        $e = $this->employee(['national_id' => '29001011234567']);

        $this->actingAs($this->hrUser())->get('/hr/employees/'.$e->id.'/edit')
            ->assertOk()->assertSee('29001011234567', false);
        $this->actingAs($this->makeAdmin(['role' => 'accountant']))->get('/hr/employees/'.$e->id.'/edit')
            ->assertForbidden();
    }

    public function test_export_respects_date_range(): void
    {
        $this->employee(['code' => 'EMP-OLD1', 'hire_date' => '2026-01-10']);
        $this->employee(['code' => 'EMP-NEW2', 'hire_date' => '2026-09-01', 'national_id' => '29001011234567']);

        $csv = $this->actingAs($this->hrUser())
            ->get('/hr/employees?from=2026-09-01&export=1')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('EMP-NEW2', $csv);
        $this->assertStringNotContainsString('EMP-OLD1', $csv);
        $this->assertStringNotContainsString('29001011234567', $csv);
    }

    public function test_seed_from_users_is_idempotent(): void
    {
        $this->makeRep(['code' => 'REP-77']);
        $this->makeAdmin();
        $this->makeAdmin(['active' => false]);

        $first = app(Employees::class)->seedFromUsers();
        $this->assertSame(2, $first['created']);
        $this->assertTrue(Employee::query()->where('code', 'REP-77')->exists());

        $second = app(Employees::class)->seedFromUsers();
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, Employee::query()->count());
    }

    public function test_seed_dry_run_writes_nothing(): void
    {
        $this->makeRep();

        $r = app(Employees::class)->seedFromUsers(true);

        $this->assertSame(1, $r['created']);
        $this->assertSame(0, Employee::query()->count());
    }

    public function test_seed_command_prints_counts(): void
    {
        $this->makeRep();

        $this->artisan('promax:hr-employees-seed')->expectsOutputToContain('Created: 1')->assertSuccessful();
        $this->assertSame(1, Employee::query()->count());
    }

    public function test_resolver_uses_employee_cost_center(): void
    {
        $center = CostCenter::create(['code' => 'CC-HR', 'name' => 'إدارة', 'kind' => 'company']);
        $e = $this->employee(['cost_center_id' => $center->id]);

        $expense = (new Expense)->forceFill(['employee_id' => $e->id]);

        $this->assertSame($center->id, CostCenterResolver::for($expense));
    }

    public function test_hr_role_gets_403_outside_its_screens(): void
    {
        $hr = $this->hrUser();

        $this->actingAs($hr)->get('/gl/accounts')->assertForbidden();
        $this->actingAs($hr)->get('/hr/employees')->assertOk();
    }

    public function test_visible_to_limits_a_plain_user_to_self_and_direct_reports(): void
    {
        $boss = $this->makeAdmin(['role' => 'manager']);
        $bossEmp = $this->employee(['user_id' => $boss->id]);
        $report = $this->employee(['manager_id' => $bossEmp->id]);
        $other = $this->employee();

        $ids = Employee::query()->visibleTo($boss)->pluck('id')->sort()->values()->all();
        $this->assertSame([$bossEmp->id, $report->id], $ids);
        $this->assertSame(3, Employee::query()->visibleTo($this->hrUser())->count());

        $this->assertTrue($report->isManagedBy($boss));
        $this->assertFalse($other->isManagedBy($boss));
        $this->assertTrue($other->isManagedBy($this->hrUser()));
    }
}
