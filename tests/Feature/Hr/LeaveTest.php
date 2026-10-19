<?php

namespace Tests\Feature\Hr;

use App\Exceptions\Rejected;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Hr\Leave;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإجازات (Lane 7 — المهمة ٣): الأنواع والأرصدة والطلبات واعتماد المدير المباشر.
 * ⚠️ الجمعة إجازة أسبوعية (`hr_weekend_days=5`) — ٢٠٢٦/١٠/١٩ يوم اتنين.
 */
class LeaveTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, array $attrs = []): User
    {
        return $this->makeAdmin(array_merge(['role' => $role, 'email' => $role.uniqid().'@promax.test'], $attrs));
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'code' => Employee::nextCode(),
            'name' => 'موظف تجربة',
            'name_en' => 'Test Employee',
        ], $attrs));
    }

    /** @return array{0: User, 1: Employee, 2: User, 3: Employee} موظف (محاسب) ومديره المباشر */
    private function team(): array
    {
        $boss = $this->staff('manager');
        $bossE = $this->employee(['user_id' => $boss->id, 'name' => 'المدير', 'name_en' => 'Boss']);
        $u = $this->staff('accountant');
        $e = $this->employee(['user_id' => $u->id, 'manager_id' => $bossE->id]);

        return [$u, $e, $boss, $bossE];
    }

    private function d(string $ymd): Carbon
    {
        return Carbon::parse($ymd);
    }

    private function balance(Employee $e, string $type, int $year = 2026): array
    {
        return collect(Leave::balances($e, $year))->firstWhere('type', $type);
    }

    public function test_days_skip_the_weekend(): void
    {
        // خميس → سبت: الخميس + السبت، والجمعة إجازة
        $this->assertSame(2.0, Leave::days($this->d('2026-10-22'), $this->d('2026-10-24')));
    }

    public function test_balances_default_from_settings(): void
    {
        [, $e] = $this->team();

        $annual = $this->balance($e, 'annual');
        $this->assertSame(21.0, $annual['entitled']);
        $this->assertSame(0.0, $annual['used']);
        $this->assertSame(21.0, $annual['remaining']);
        $this->assertSame(['annual', 'casual', 'sick', 'unpaid'], array_column(Leave::balances($e, 2026), 'type'));
    }

    public function test_request_and_manager_approval_updates_balance(): void
    {
        [$u, $e, $boss] = $this->team();

        // أحد → تلات = ٣ أيام
        $r = Leave::request($e, $u, 'annual', $this->d('2026-10-25'), $this->d('2026-10-27'), 'سفر');
        $this->assertSame('pending', $r->status);
        $this->assertSame(3.0, (float) $r->days);

        $r = Leave::decide($r, $boss, 'approved', null);

        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame($boss->id, (int) $r->fresh()->decided_by);
        $this->assertNotNull($r->fresh()->decided_at);
        $this->assertSame(3.0, (float) LeaveBalance::query()->where('employee_id', $e->id)->where('type', 'annual')->value('used'));
        $this->assertSame(18.0, $this->balance($e, 'annual')['remaining']);
    }

    public function test_rejection_keeps_the_balance(): void
    {
        [$u, $e, $boss] = $this->team();

        $r = Leave::request($e, $u, 'annual', $this->d('2026-10-25'), $this->d('2026-10-27'), null);
        Leave::decide($r, $boss, 'rejected', 'ضغط شغل');

        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertSame('ضغط شغل', $r->fresh()->decision_note);
        $this->assertSame(21.0, $this->balance($e, 'annual')['remaining']);
    }

    public function test_overlapping_request_is_refused(): void
    {
        [$u, $e] = $this->team();

        Leave::request($e, $u, 'annual', $this->d('2026-10-25'), $this->d('2026-10-27'), null);

        try {
            Leave::request($e, $u, 'casual', $this->d('2026-10-27'), $this->d('2026-10-28'), null);
            $this->fail('overlap accepted');
        } catch (Rejected $ex) {
            $this->assertContains($ex->getMessage(), [__('hrm.leave_overlap', [], 'ar'), __('hrm.leave_overlap', [], 'en')]);
        }

        $this->assertSame(1, LeaveRequest::query()->count());
    }

    public function test_request_beyond_balance_is_refused(): void
    {
        [$u, $e] = $this->team();

        // سبت ٧/١١ → سبت ١٤/١١ = ٨ أيام ناقص الجمعة = ٧ > عارضة ٦
        $this->expectException(Rejected::class);
        Leave::request($e, $u, 'casual', $this->d('2026-11-07'), $this->d('2026-11-14'), null);
    }

    public function test_request_beyond_balance_is_refused_on_the_web(): void
    {
        [$u] = $this->team();

        $this->actingAs($u)
            ->post(route('hr.leave.store'), ['type' => 'casual', 'from' => '2026-11-07', 'to' => '2026-11-14'])
            ->assertStatus(302)
            ->assertSessionHasErrors('leave');

        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_approval_beyond_balance_is_refused(): void
    {
        [$u, $e, $boss] = $this->team();

        // طلبين ٤ أيام عارضة قبل أي اعتماد — الرصيد ٦ فالاتنين اتقبلوا كطلبات
        $a = Leave::request($e, $u, 'casual', $this->d('2026-11-01'), $this->d('2026-11-04'), null);
        $b = Leave::request($e, $u, 'casual', $this->d('2026-11-15'), $this->d('2026-11-18'), null);

        Leave::decide($a, $boss, 'approved', null);

        try {
            Leave::decide($b->fresh(), $boss, 'approved', null);
            $this->fail('approval beyond balance accepted');
        } catch (Rejected) {
        }

        $this->assertSame('pending', $b->fresh()->status);
        $this->assertSame(4.0, (float) LeaveBalance::query()->where('employee_id', $e->id)->where('type', 'casual')->value('used'));
    }

    public function test_unpaid_has_no_balance_check(): void
    {
        [$u, $e] = $this->team();

        $r = Leave::request($e, $u, 'unpaid', $this->d('2026-11-01'), $this->d('2026-12-20'), null);

        $this->assertGreaterThan(40, (float) $r->days);
        $this->assertSame(0.0, $this->balance($e, 'unpaid')['remaining']);
    }

    public function test_other_manager_cannot_decide(): void
    {
        [$u, $e] = $this->team();
        $other = $this->staff('manager');
        $this->employee(['user_id' => $other->id, 'name_en' => 'Other']);

        $r = Leave::request($e, $u, 'annual', $this->d('2026-10-25'), $this->d('2026-10-27'), null);

        try {
            Leave::decide($r, $other, 'approved', null);
            $this->fail('other manager decided');
        } catch (Rejected) {
        }

        $this->actingAs($other)
            ->post(route('hr.leave.decide', $r), ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $r->fresh()->status);
    }

    public function test_requester_cannot_decide_their_own_request(): void
    {
        $hr = $this->staff('hr');
        $e = $this->employee(['user_id' => $hr->id]);

        $r = Leave::request($e, $hr, 'annual', $this->d('2026-10-25'), $this->d('2026-10-27'), null);

        $this->expectException(Rejected::class);
        Leave::decide($r, $hr, 'approved', null);
    }

    public function test_requester_cancels_a_pending_request(): void
    {
        [$u, $e] = $this->team();
        $r = Leave::request($e, $u, 'annual', $this->d('2026-10-25'), $this->d('2026-10-27'), null);

        $this->actingAs($u)->post(route('hr.leave.cancel', $r))->assertStatus(302)->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $r->fresh()->status);
    }

    public function test_manager_is_notified_and_approves_from_the_web(): void
    {
        [$u, $e, $boss] = $this->team();

        $this->actingAs($u)
            ->post(route('hr.leave.store'), ['type' => 'annual', 'from' => '2026-10-25', 'to' => '2026-10-27', 'note' => 'سفر'])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $r = LeaveRequest::query()->sole();
        $this->assertSame($u->id, (int) $r->requested_by);
        $this->assertTrue(\App\Models\AppNotification::query()->where('user_id', $boss->id)->exists());

        $this->actingAs($boss)->get(route('hr.leave', ['tab' => 'approvals']))
            ->assertOk()
            ->assertSee($e->displayName());

        $this->actingAs($boss)
            ->post(route('hr.leave.decide', $r), ['decision' => 'approved'])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $r->fresh()->status);
        $this->assertTrue(\App\Models\AppNotification::query()->where('user_id', $u->id)->exists());
    }

    public function test_leave_page_opens_and_exports(): void
    {
        [$u, $e] = $this->team();
        Leave::request($e, $u, 'annual', $this->d('2026-10-25'), $this->d('2026-10-27'), null);

        $this->actingAs($u)->get(route('hr.leave'))->assertOk();

        $csv = $this->actingAs($u)->get(route('hr.leave', ['export' => 1, 'from' => '2026-10-01', 'to' => '2026-10-31']));
        $csv->assertOk();
        $this->assertStringContainsString('2026-10-25', $csv->streamedContent());

        // من غير ملف موظف الصفحة بتفتح برسالة — مش 403
        $this->actingAs($this->staff('warehouse_keeper'))->get(route('hr.leave'))->assertOk();
    }

    public function test_seed_opening_is_idempotent(): void
    {
        $this->team();

        $first = Leave::seedOpening(2026);
        $this->assertSame(8, $first['created']);   // موظفين × ٤ أنواع
        $this->assertSame(0, Leave::seedOpening(2026)['created']);
        $this->assertSame(8, LeaveBalance::query()->count());

        $dry = Leave::seedOpening(2027, true);
        $this->assertSame(8, $dry['created']);
        $this->assertSame(0, LeaveBalance::query()->where('year', 2027)->count());

        $this->artisan('promax:hr-leave-seed', ['--year' => 2027])->assertSuccessful();
        $this->assertSame(8, LeaveBalance::query()->where('year', 2027)->count());
    }

    public function test_admin_edits_a_balance(): void
    {
        [, $e] = $this->team();
        Leave::seedOpening(2026);
        $row = LeaveBalance::query()->where('employee_id', $e->id)->where('type', 'annual')->where('year', 2026)->sole();

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('hr.leave.balances', ['year' => 2026]))->assertOk()->assertSee($e->displayName());

        $this->actingAs($admin)
            ->post(route('hr.leave.balances.update', $row), ['entitled' => 30, 'note' => 'أقدمية'])
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $this->assertSame(30.0, (float) $row->fresh()->entitled);
        $this->assertSame(30.0, $this->balance($e, 'annual')['remaining']);

        // المحاسب مايعدّلش أرصدة
        $this->actingAs($this->staff('accountant'))
            ->post(route('hr.leave.balances.update', $row), ['entitled' => 99])
            ->assertForbidden();
        $this->assertSame(30.0, (float) $row->fresh()->entitled);
    }

    public function test_request_longer_than_a_year_is_refused(): void
    {
        [$u, $e] = $this->team();

        try {
            Leave::request($e, $u, 'unpaid', $this->d('2026-01-01'), $this->d('2062-01-01'), null);
            $this->fail('expected Rejected');
        } catch (Rejected $ex) {
            $this->assertContains($ex->getMessage(), [__('hrm.leave_too_long', [], 'ar'), __('hrm.leave_too_long', [], 'en')]);
        }

        $this->assertSame(0, LeaveRequest::query()->count());
    }
}
