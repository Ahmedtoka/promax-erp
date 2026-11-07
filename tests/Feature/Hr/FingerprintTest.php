<?php

namespace Tests\Feature\Hr;

use App\Models\AppNotification;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\FpCommand;
use App\Models\FpDevice;
use App\Models\FpDeviceUser;
use App\Models\FpLog;
use App\Models\HrPermission;
use App\Services\Attendance;
use App\Services\Fingerprint\Adms;
use App\Services\Hr\Payroll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * مكنة البصمة ZKTeco (ADMS / iclock — ٧/١١/٢٠٢٦): مكنة متقلّدة بتبعت سطور ATTLOG
 * زي الحقيقية بالظبط، والبصمة بتوصل الحضور (نفس يوم الأبلكيشن)، والأذونات بالساعة.
 */
class FingerprintTest extends TestCase
{
    use RefreshDatabase;

    private const SN = 'CKJK211160123';

    private FpDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        // الإتنين ٩ نوفمبر ٢٠٢٦ — يوم شغل (الجمعة الإجازة الأسبوعية)
        Carbon::setTestNow('2026-11-09 18:00:00');
        $this->device = FpDevice::create(['serial' => self::SN, 'name' => 'مكنة المكتب', 'mode' => 'keys', 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'code' => Employee::nextCode(),
            'name' => 'موظف بصمة',
            'name_en' => 'Finger Employee',
            'attendance_required' => true,
        ], $attrs));
    }

    /** المكنة بتبعت سطور ATTLOG — نفس الشكل الحقيقي: PIN\tTIME\tSTATUS\tVERIFY\tWORKCODE\t... */
    private function push(array $lines, string $sn = self::SN, string $table = 'ATTLOG'): TestResponse
    {
        $body = implode("\n", array_map(fn ($l) => is_array($l) ? implode("\t", $l) : $l, $lines))."\n";

        return $this->call('POST', "/iclock/cdata?SN={$sn}&table={$table}&Stamp=9999", [], [], [],
            ['CONTENT_TYPE' => 'text/plain'], $body);
    }

    private function line(string $pin, string $time, int $status = 0, int $verify = 1): array
    {
        return [$pin, $time, $status, $verify, 0, 0, 0, 0, 0];
    }

    // ═══ البروتوكول ═══

    public function test_iclock_routes_are_outside_the_web_group(): void
    {
        foreach (['iclock.cdata', 'iclock.getrequest', 'iclock.devicecmd'] as $name) {
            $mw = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertNotContains('web', $mw, $name);
            $this->assertNotContains('auth', $mw, $name);
        }
    }

    public function test_unknown_serial_is_rejected_and_listed_for_registration(): void
    {
        $this->get('/iclock/cdata?SN=STRANGER1&options=all')->assertNotFound();
        $this->push([$this->line('1', '2026-11-09 08:55:00')], 'STRANGER1')->assertNotFound();

        $this->assertSame(0, FpLog::count());
        $this->assertArrayHasKey('STRANGER1', cache()->get(Adms::UNKNOWN_CACHE_KEY, []));
    }

    public function test_handshake_returns_options_and_marks_device_seen(): void
    {
        $res = $this->get('/iclock/cdata?SN='.self::SN.'&options=all&pushver=2.4.1')->assertOk();

        $this->assertStringStartsWith('GET OPTION FROM: '.self::SN, $res->getContent());
        $this->assertStringContainsString('ATTLOGStamp=0', $res->getContent());
        $this->assertStringContainsString('Realtime=1', $res->getContent());
        $this->assertStringStartsWith('text/plain', $res->headers->get('Content-Type'));
        $this->assertTrue($this->device->fresh()->isOnline());
    }

    public function test_ping_answers_for_setup_check(): void
    {
        $this->get('/iclock/ping')->assertOk()->assertSee('PROMAX-ICLOCK OK');
    }

    // ═══ البصمة ⇐ الحضور ═══

    public function test_device_punches_become_attendance_with_device_time(): void
    {
        $e = $this->employee(['fp_pin' => '7']);

        $this->push([
            $this->line('7', '2026-11-09 08:55:12', 0),
            $this->line('7', '2026-11-09 17:05:00', 1),
        ])->assertOk()->assertSeeText('OK: 2');

        $punches = AttendancePunch::orderBy('at')->get();
        $this->assertSame(['in', 'out'], $punches->pluck('type')->all());
        $this->assertSame(['device', 'device'], $punches->pluck('source')->all());
        $this->assertSame('2026-11-09 08:55:12', $punches[0]->at->format('Y-m-d H:i:s'));
        $this->assertSame($e->id, (int) $punches[0]->employee_id);

        $day = AttendanceDay::sole();
        $this->assertSame(489, (int) $day->worked_minutes);
        $this->assertSame(2, FpLog::where('result', FpLog::APPLIED)->count());
        $this->assertSame('9999', $this->device->fresh()->attlog_stamp);
    }

    public function test_the_same_lines_pushed_twice_are_not_duplicated(): void
    {
        $this->employee(['fp_pin' => '7']);
        $lines = [$this->line('7', '2026-11-09 08:55:00', 0), $this->line('7', '2026-11-09 17:00:00', 1)];

        $this->push($lines)->assertSeeText('OK: 2');
        // المكنة ماوصلهاش الـOK (النت قطع) وبعتت نفس السطور تاني
        $this->push($lines)->assertSeeText('OK: 2');

        $this->assertSame(2, FpLog::count());
        $this->assertSame(2, AttendancePunch::count());
    }

    public function test_double_press_within_a_minute_is_ignored(): void
    {
        $this->employee(['fp_pin' => '7']);

        $this->push([$this->line('7', '2026-11-09 08:55:00', 0), $this->line('7', '2026-11-09 08:55:20', 0)]);

        $this->assertSame(1, AttendancePunch::count());
        $this->assertSame(FpLog::DUPLICATE, FpLog::orderByDesc('id')->first()->result);
    }

    public function test_device_and_app_share_the_same_day(): void
    {
        $rep = $this->makeRep();
        $e = $this->employee(['user_id' => $rep->id, 'fp_pin' => '12']);

        // حضر من الأبلكيشن ٨:٥٠
        Carbon::setTestNow('2026-11-09 08:50:00');
        Attendance::punch($rep, AttendancePunch::IN);

        // وبصم على المكنة ٩:٠٠ ⇒ مكررة — وانصرف من المكنة ٥
        Carbon::setTestNow('2026-11-09 18:00:00');
        $this->push([$this->line('12', '2026-11-09 09:00:00', 0), $this->line('12', '2026-11-09 17:00:00', 1)]);

        $this->assertSame(1, AttendanceDay::count());
        $day = AttendanceDay::sole();
        $this->assertSame(['app', 'device'], $day->punches->pluck('source')->all());
        $this->assertSame(['in', 'out'], $day->punches->pluck('type')->all());
        $this->assertSame(490, (int) $day->worked_minutes);
        $this->assertSame([FpLog::DUPLICATE, FpLog::APPLIED], FpLog::orderBy('punched_at')->pluck('result')->all());
        $this->assertSame($e->id, (int) $day->employee_id);
    }

    public function test_break_keys_map_to_break_and_back(): void
    {
        $this->employee(['fp_pin' => '3']);

        $this->push([
            $this->line('3', '2026-11-09 09:00:00', 0),
            $this->line('3', '2026-11-09 12:00:00', 2),
            $this->line('3', '2026-11-09 12:30:00', 3),
            $this->line('3', '2026-11-09 17:00:00', 1),
        ]);

        $day = AttendanceDay::sole();
        $this->assertSame(['in', 'break', 'back', 'out'], $day->punches->pluck('type')->all());
        $this->assertSame(450, (int) $day->worked_minutes);
        $this->assertSame(30, (int) $day->break_minutes);
    }

    public function test_toggle_mode_ignores_the_key_and_alternates(): void
    {
        $this->device->update(['mode' => 'toggle']);
        $this->employee(['fp_pin' => '5']);

        // المكنة بتبعت status 0 دايماً (محدش بيدوس زرار)
        $this->push([$this->line('5', '2026-11-09 09:00:00', 0), $this->line('5', '2026-11-09 17:00:00', 0)]);

        $this->assertSame(['in', 'out'], AttendancePunch::orderBy('at')->pluck('type')->all());
    }

    public function test_out_of_order_upload_after_outage_still_counts(): void
    {
        $this->employee(['fp_pin' => '8']);

        // النت قطع — الانصراف وصل الأول وبعدين الحضور اترفع متأخر
        $this->push([$this->line('8', '2026-11-09 17:00:00', 1)]);
        $this->push([$this->line('8', '2026-11-09 09:00:00', 0)]);

        // الانصراف اترفض الأول (مش حاضر)، ولما الحضور وصل اتجرب تاني واتسجل
        $this->assertSame(FpLog::APPLIED, FpLog::where('punched_at', '2026-11-09 17:00:00')->value('result'));
        $this->assertSame(['in', 'out'], AttendancePunch::orderBy('at')->pluck('type')->all());
        $this->assertSame(480, (int) AttendanceDay::sole()->worked_minutes);
    }

    public function test_unmapped_pin_waits_then_applies_when_mapped(): void
    {
        $admin = $this->makeAdmin();
        $this->push([$this->line('44', '2026-11-09 17:30:00', 0)]);

        $this->assertSame(FpLog::UNMAPPED, FpLog::sole()->result);
        $this->assertSame(0, AttendancePunch::count());
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->exists());

        $e = $this->employee();
        $this->actingAs($admin)->post(route('hr.fingerprint.map'), ['employee_id' => $e->id, 'pin' => '44'])
            ->assertRedirect();

        $this->assertSame('44', $e->fresh()->fp_pin);
        $this->assertSame(FpLog::APPLIED, FpLog::sole()->result);
        $this->assertSame(1, AttendancePunch::where('employee_id', $e->id)->count());
    }

    public function test_history_before_apply_from_is_kept_raw_only(): void
    {
        $this->device->update(['apply_from' => '2026-11-09 00:00:00']);
        $this->employee(['fp_pin' => '7']);

        $this->push([$this->line('7', '2026-10-01 09:00:00', 0), $this->line('7', '2026-11-09 09:00:00', 0)]);

        $this->assertSame([FpLog::OLD, FpLog::APPLIED], FpLog::orderBy('punched_at')->pluck('result')->all());
        $this->assertSame(1, AttendancePunch::count());
    }

    public function test_late_check_in_notifies_admins_and_hr(): void
    {
        $admin = $this->makeAdmin();
        $hr = $this->makeAdmin(['role' => 'hr']);
        $this->employee(['fp_pin' => '7']);

        Carbon::setTestNow('2026-11-09 09:41:00');
        $this->push([$this->line('7', '2026-11-09 09:40:00', 0)]);

        foreach ([$admin, $hr] as $u) {
            $this->assertTrue(AppNotification::where('user_id', $u->id)->where('is_good', false)->exists(), $u->role);
        }
    }

    public function test_on_time_check_in_is_quiet_by_default(): void
    {
        $admin = $this->makeAdmin();
        $this->employee(['fp_pin' => '7']);

        Carbon::setTestNow('2026-11-09 09:01:00');
        $this->push([$this->line('7', '2026-11-09 09:00:00', 0)]);

        $this->assertFalse(AppNotification::where('user_id', $admin->id)->exists());
    }

    public function test_operlog_users_are_stored_for_mapping(): void
    {
        $this->push(["USER PIN=7\tName=Ahmed Ali\tPri=0\tPasswd=\tCard=123\tGrp=1\tTZ=0000000000000000",
            "FP PIN=7\tFID=1\tSize=1000\tValid=1\tTMP=xyz"], self::SN, 'OPERLOG')->assertSeeText('OK: 2');

        $u = FpDeviceUser::sole();
        $this->assertSame(['7', 'Ahmed Ali', '123'], [$u->pin, $u->name, $u->card]);
    }

    public function test_commands_are_delivered_once_and_results_recorded(): void
    {
        Adms::queueResync($this->device, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-09'));
        $cmd = FpCommand::sole();

        $res = $this->get('/iclock/getrequest?SN='.self::SN.'&INFO=Ver%206.60%20Apr%202020,12,30,4500,192.168.1.201')->assertOk();
        $this->assertStringContainsString('C:'.$cmd->id.":DATA QUERY ATTLOG\tStartTime=2026-11-01 00:00:00", $res->getContent());
        $this->assertSame('sent', $cmd->fresh()->status);
        $this->assertSame('Ver 6.60 Apr 2020', $this->device->fresh()->firmware);
        $this->assertSame(12, $this->device->fresh()->users_count);

        // البولينج اللي بعده مفيهوش حاجة
        $this->get('/iclock/getrequest?SN='.self::SN)->assertSeeText('OK');

        $this->call('POST', '/iclock/devicecmd?SN='.self::SN, [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            'ID='.$cmd->id.'&Return=0&CMD=DATA')->assertOk();
        $this->assertSame('done', $cmd->fresh()->status);
    }

    public function test_garbage_lines_are_skipped(): void
    {
        $this->employee(['fp_pin' => '7']);

        $this->push(['', 'not a punch', $this->line('7', 'yesterday', 0), $this->line('7', '2026-11-09 09:00:00', 0)])
            ->assertSeeText('OK: 1');
    }

    // ═══ الشاشات ═══

    public function test_screens_open_for_hr_and_fingerprint_is_closed_to_managers(): void
    {
        $hr = $this->makeAdmin(['role' => 'hr']);
        $this->employee(['fp_pin' => '7']);
        $this->push([$this->line('7', '2026-11-09 09:30:00', 0)]);

        $this->actingAs($hr)->get(route('hr.fingerprint'))->assertOk()->assertSee(self::SN);
        $this->actingAs($hr)->get(route('hr.fingerprint.live', ['after' => 0]))->assertOk()->assertJsonPath('logs.0.pin', '7');
        $this->actingAs($hr)->get(route('hr.attendance.daily', ['date' => '2026-11-09']))->assertOk()->assertSee('09:30');
        $this->actingAs($hr)->get(route('hr.attendance.monthly', ['month' => '2026-11']))->assertOk();
        $this->actingAs($hr)->get(route('hr.permissions'))->assertOk();
        $this->actingAs($hr)->get(route('hr.attendance.daily', ['date' => '2026-11-09', 'export' => 1]))->assertOk();

        $manager = $this->makeAdmin(['role' => 'manager']);
        $this->actingAs($manager)->get(route('hr.fingerprint'))->assertForbidden();
    }

    public function test_hr_registers_a_device_from_the_screen(): void
    {
        $hr = $this->makeAdmin(['role' => 'hr']);

        $this->actingAs($hr)->post(route('hr.fingerprint.store'), ['serial' => 'NEWSN001', 'name' => 'Gate', 'mode' => 'toggle'])
            ->assertRedirect();

        $d = FpDevice::where('serial', 'NEWSN001')->sole();
        $this->assertSame('toggle', $d->mode);
        $this->assertSame(today()->toDateString(), $d->apply_from->toDateString());
    }

    // ═══ الأذونات والمرتبات ═══

    public function test_approved_late_permission_removes_lateness_from_payroll_and_sheet(): void
    {
        $hr = $this->makeAdmin(['role' => 'hr']);
        $e = $this->employee(['fp_pin' => '7', 'basic_salary' => 6000, 'hire_date' => '2026-01-01']);

        // حضر ١٠:٠٠ — ٦٠ دقيقة تأخير
        $this->push([$this->line('7', '2026-11-09 10:00:00', 0), $this->line('7', '2026-11-09 18:00:00', 1)]);
        $day = Carbon::parse('2026-11-09');

        $this->assertSame(60, Payroll::attendance($e, $day, $day)[2]);

        $this->actingAs($hr)->post(route('hr.permissions.store'), [
            'employee_id' => $e->id, 'date' => '2026-11-09', 'kind' => 'late_in', 'minutes' => 60, 'reason' => 'دكتور',
        ])->assertRedirect();
        $perm = HrPermission::sole();
        $this->assertSame('pending', $perm->status);

        // لسه مستني ⇒ التأخير زي ما هو
        $this->assertSame(60, Payroll::attendance($e, $day, $day)[2]);

        $this->actingAs($hr)->post(route('hr.permissions.decide', $perm), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $perm->fresh()->status);

        $this->assertSame(0, Payroll::attendance($e, $day, $day)[2]);
        $grid = \App\Services\Hr\AttendanceSheet::grid(collect([$e]), $day, $day);
        $this->assertSame('present', $grid[$e->id]['2026-11-09']['status']);
    }

    public function test_sheet_marks_absent_late_leave_and_weekend(): void
    {
        $late = $this->employee(['fp_pin' => '1']);
        $absent = $this->employee();
        $onLeave = $this->employee();
        \App\Models\LeaveRequest::create([
            'employee_id' => $onLeave->id, 'type' => 'annual', 'from' => '2026-11-09', 'to' => '2026-11-09',
            'days' => 1, 'status' => 'approved',
        ]);
        $this->push([$this->line('1', '2026-11-09 09:30:00', 0)]);

        $grid = \App\Services\Hr\AttendanceSheet::grid(collect([$late, $absent, $onLeave]),
            Carbon::parse('2026-11-06'), Carbon::parse('2026-11-09'));

        $this->assertSame('late', $grid[$late->id]['2026-11-09']['status']);
        $this->assertSame(30, $grid[$late->id]['2026-11-09']['late']);
        $this->assertSame('absent', $grid[$absent->id]['2026-11-09']['status']);
        $this->assertSame('leave', $grid[$onLeave->id]['2026-11-09']['status']);
        // الجمعة ٦/١١
        $this->assertSame('weekend', $grid[$absent->id]['2026-11-06']['status']);
    }

    public function test_a_manager_cannot_approve_their_own_permission(): void
    {
        $mgrUser = $this->makeAdmin(['role' => 'manager']);
        $mgr = $this->employee(['user_id' => $mgrUser->id]);
        $perm = HrPermission::create(['employee_id' => $mgr->id, 'date' => '2026-11-09', 'kind' => 'late_in', 'minutes' => 30,
            'status' => 'pending', 'requested_by' => $mgrUser->id]);

        $this->actingAs($mgrUser)->post(route('hr.permissions.decide', $perm), ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('pending', $perm->fresh()->status);
    }
}
