<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الحضور لكل موظف (Lane 7 — المهمة ٢): مصدر البانش (أبلكيشن / داش بورد /
 * مشرف)، زرار البانش في الهيدر، المشرف بيسجّل للعامل اللي مالوش لوجين،
 * وتقرير البانشات.
 */
class AttendanceForAllTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'code' => Employee::nextCode(),
            'name' => 'موظف تجربة',
            // الاسم الإنجليزي = العربي — اليوزر في التيست `en` و`displayName()` بيختار حسب اللغة
            'name_en' => $attrs['name'] ?? 'Test Employee',
        ], $attrs));
    }

    private function staff(string $role, array $attrs = []): User
    {
        return $this->makeAdmin(array_merge(['role' => $role, 'email' => $role.uniqid().'@promax.test'], $attrs));
    }

    /** رسالة الخطأ بأي لغة — اليوزر ممكن يبقى en والتيست ar */
    private function assertErrorIs(string $key, ?string $actual): void
    {
        $this->assertContains($actual, [__($key, [], 'ar'), __($key, [], 'en')]);
    }

    public function test_app_punch_records_source_app_and_employee(): void
    {
        $rep = $this->makeRep();
        $e = $this->employee(['user_id' => $rep->id]);

        $this->withHeaders($this->tokenFor($rep))
            ->postJson('/api/attendance/punch', ['type' => 'in'])
            ->assertOk()
            ->assertJsonPath('attendance.punches.0.source', 'app');

        $p = AttendancePunch::query()->sole();
        $this->assertSame('app', $p->source);
        $this->assertSame($e->id, (int) $p->employee_id);
        $this->assertSame($e->id, (int) $p->day->employee_id);
    }

    public function test_keeper_with_employee_can_punch_from_app(): void
    {
        $keeper = $this->staff('warehouse_keeper', ['warehouse_id' => $this->makeWarehouse()->id]);
        $this->employee(['user_id' => $keeper->id]);

        $this->withHeaders($this->tokenFor($keeper))
            ->postJson('/api/attendance/punch', ['type' => 'in'])
            ->assertOk();

        $this->assertSame(1, AttendancePunch::query()->count());
    }

    public function test_dashboard_punch_records_device_and_ip(): void
    {
        $acc = $this->staff('accountant');
        $e = $this->employee(['user_id' => $acc->id]);

        $this->actingAs($acc)
            ->withHeader('User-Agent', 'UA-test')
            ->post('/attendance/punch', ['type' => 'in'])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $p = AttendancePunch::query()->sole();
        $this->assertSame('dashboard', $p->source);
        $this->assertSame('UA-test', $p->device);
        $this->assertNotNull($p->ip);
        $this->assertSame($e->id, (int) $p->employee_id);
        $this->assertNull($p->lat);
    }

    public function test_dashboard_punch_without_employee_is_refused(): void
    {
        $acc = $this->staff('accountant');

        $this->actingAs($acc)
            ->from(route('erp.overview'))
            ->post('/attendance/punch', ['type' => 'in'])
            ->assertStatus(302)
            ->assertSessionHasErrors('punch');

        $this->assertErrorIs('hrm.no_employee_record', session('errors')->first('punch'));
        $this->assertSame(0, AttendancePunch::query()->count());
    }

    public function test_dashboard_punch_follows_the_state_machine(): void
    {
        $mgr = $this->staff('manager');
        $this->employee(['user_id' => $mgr->id]);

        $this->actingAs($mgr)->post('/attendance/punch', ['type' => 'in'])->assertSessionHasNoErrors();
        $this->actingAs($mgr)->post('/attendance/punch', ['type' => 'in'])->assertSessionHasErrors('punch');

        $this->assertSame(1, AttendancePunch::query()->count());
    }

    public function test_handover_reviewer_cannot_punch_from_the_dashboard(): void
    {
        $rev = $this->staff('handover_reviewer');
        $this->employee(['user_id' => $rev->id]);

        $this->actingAs($rev)->post('/attendance/punch', ['type' => 'in'])->assertStatus(403);
        $this->assertSame(0, AttendancePunch::query()->count());
    }

    public function test_supervisor_punches_worker_without_login(): void
    {
        $mgr = $this->staff('manager');
        $m = $this->employee(['user_id' => $mgr->id, 'name' => 'المشرف']);
        $w = $this->employee(['name' => 'عامل الخط', 'manager_id' => $m->id]);

        $this->actingAs($mgr)
            ->post('/hr/attendance/supervisor/punch', ['employee_id' => $w->id, 'type' => 'in'])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $day = AttendanceDay::query()->sole();
        $this->assertSame($w->id, (int) $day->employee_id);
        $this->assertNull($day->user_id);
        $this->assertSame('working', $day->state());

        $p = AttendancePunch::query()->sole();
        $this->assertSame('supervisor', $p->source);
        $this->assertSame($mgr->id, (int) $p->recorded_by);
        $this->assertNull($p->user_id);
        $this->assertSame($w->id, (int) $p->employee_id);

        // الشاشة بتعرض العامل بحالته
        $this->actingAs($mgr)->get('/hr/attendance/supervisor')
            ->assertOk()->assertSee('عامل الخط');
    }

    public function test_supervisor_cannot_punch_someone_elses_worker(): void
    {
        $mgr = $this->staff('manager');
        $this->employee(['user_id' => $mgr->id]);
        $other = $this->employee(['name' => 'مدير تاني']);
        $w = $this->employee(['name' => 'عامل غيره', 'manager_id' => $other->id]);

        $this->actingAs($mgr)
            ->post('/hr/attendance/supervisor/punch', ['employee_id' => $w->id, 'type' => 'in'])
            ->assertStatus(403);

        $this->assertSame(0, AttendancePunch::query()->count());

        $this->actingAs($mgr)->get('/hr/attendance/supervisor')
            ->assertOk()->assertDontSee('عامل غيره');
    }

    public function test_supervisor_cannot_punch_an_employee_with_a_login(): void
    {
        $hr = $this->staff('hr');
        $rep = $this->makeRep();
        $e = $this->employee(['user_id' => $rep->id]);

        $this->actingAs($hr)
            ->from('/hr/attendance/supervisor')
            ->post('/hr/attendance/supervisor/punch', ['employee_id' => $e->id, 'type' => 'in'])
            ->assertStatus(302)
            ->assertSessionHasErrors('punch');

        $this->assertErrorIs('hrm.supervisor_has_login', session('errors')->first('punch'));
        $this->assertSame(0, AttendancePunch::query()->count());
    }

    public function test_auto_close_closes_a_worker_day_without_user(): void
    {
        $hr = $this->staff('hr');
        $w = $this->employee(['name' => 'عامل']);

        $this->travelTo(now()->subDay()->setTime(9, 0));
        [$err] = Attendance::punchEmployee($w, AttendancePunch::IN, $hr);
        $this->assertNull($err);
        $this->travelBack();

        $day = AttendanceDay::query()->sole();
        $this->assertTrue(Attendance::autoClose($day));

        $last = $day->fresh()->lastPunch();
        $this->assertSame(AttendancePunch::OUT, $last->type);
        $this->assertSame($w->id, (int) $last->employee_id);
        $this->assertNull($last->user_id);
        $this->assertSame(AttendanceDay::STATUS_AUTO, $day->fresh()->status);
    }

    public function test_punches_report_filters_by_source_and_exports(): void
    {
        $hr = $this->staff('hr');
        $rep = $this->makeRep(['name' => 'مندوب الأبلكيشن']);
        $this->employee(['user_id' => $rep->id, 'name' => 'مندوب الأبلكيشن']);
        $w = $this->employee(['name' => 'عامل المشرف']);

        $this->punchIn($rep);
        Attendance::punchEmployee($w, AttendancePunch::IN, $hr);

        $res = $this->actingAs($hr)->get('/hr/attendance/punches?source=supervisor&export=1');
        $res->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('عامل المشرف', $csv);
        $this->assertStringNotContainsString('مندوب الأبلكيشن', $csv);

        $this->actingAs($hr)->get('/hr/attendance/punches')
            ->assertOk()->assertSee('عامل المشرف')->assertSee('مندوب الأبلكيشن');
    }

    public function test_punches_report_hides_other_teams_from_a_manager(): void
    {
        $mgr = $this->staff('manager');
        $m = $this->employee(['user_id' => $mgr->id]);
        $mine = $this->employee(['name' => 'عاملي', 'manager_id' => $m->id]);
        $theirs = $this->employee(['name' => 'عامل بعيد']);
        $hr = $this->staff('hr');

        Attendance::punchEmployee($mine, AttendancePunch::IN, $hr);
        Attendance::punchEmployee($theirs, AttendancePunch::IN, $hr);

        $this->actingAs($mgr)->get('/hr/attendance/punches')
            ->assertOk()->assertSee('عاملي')->assertDontSee('عامل بعيد');
    }

    public function test_topbar_shows_the_punch_buttons_only_for_an_employee(): void
    {
        $acc = $this->staff('accountant');

        $this->actingAs($acc)->get(route('erp.overview'))->assertOk()
            ->assertDontSee('id="punchBar"', false);

        $this->employee(['user_id' => $acc->id]);

        $this->actingAs($acc)->get(route('erp.overview'))->assertOk()
            ->assertSee('id="punchBar"', false)
            ->assertSee(route('attendance.punch'), false);

        // ⚠️ عرض الصفحة مايفتحش يوم حضور
        $this->assertSame(0, AttendanceDay::query()->count());
    }
}
