<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * عقد الأبلكيشن ↔ الـAPI (٩/٩/٢٠٢٦) — مراجعة المطابقة الليلية
 * ═══════════════════════════════════════════════════════════════
 *
 * • توكن الجهاز وبينج الفتح لكل اللي عنده توكن: أمين المخزن والمحاسب
 *   بيدخلوا الأبلكيشن وكانوا بياخدوا 403 في صمت فعمرهم ما استلموا إشعار.
 * • الراوتات اللي الأبلكيشن عمره ما ناداها اتشالت (GET /attendance ·
 *   GET /keeper/picks/{pick} · GET /manager/replenishments · DELETE device-token).
 * • إحداثيات أكشنات أوامر التوريد بقت متحقق منها.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'active' => true]);
    }

    public function test_every_app_role_can_register_and_forget_a_device_token(): void
    {
        foreach (['sales_agent', 'driver', 'promoter', 'manager', 'admin', 'warehouse_keeper', 'accountant'] as $role) {
            $u = $this->user($role);
            $tok = 'fcm-'.$role.'-'.bin2hex(random_bytes(4));

            $this->withHeaders($this->tokenFor($u))
                ->postJson('/api/device-token', ['token' => $tok, 'platform' => 'android', 'app_version' => '2.0.0'])
                ->assertOk();
            $this->assertTrue(DeviceToken::where('token', $tok)->where('user_id', $u->id)->exists(), $role);

            $this->withHeaders($this->tokenFor($u))
                ->postJson('/api/app-open', ['lat' => 30.0, 'lng' => 31.2])
                ->assertOk();

            $this->withHeaders($this->tokenFor($u))
                ->postJson('/api/device-token/forget', ['token' => $tok])
                ->assertOk();
            $this->assertFalse(DeviceToken::where('token', $tok)->exists(), $role);
        }
    }

    public function test_the_routes_nobody_calls_are_gone(): void
    {
        $admin = $this->user('admin');
        $h = $this->tokenFor($admin);

        $this->withHeaders($h)->getJson('/api/attendance')->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/keeper/picks/1')->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/manager/replenishments')->assertStatus(404);
        $this->withHeaders($h)->deleteJson('/api/device-token')->assertStatus(405);
        $this->withHeaders($h)->deleteJson('/api/device-token/forget')->assertStatus(405);

        // اللي الأبلكيشن بيناديه فعلاً لسه شغّال
        $this->withHeaders($h)->postJson('/api/attendance/punch', ['type' => 'in'])->assertSuccessful();
        $this->withHeaders($h)->getJson('/api/keeper/picks')->assertOk();
        $this->withHeaders($h)->getJson('/api/manager/bootstrap')->assertOk();
    }

    /**
     * ⚠️ شكل رد البانش ثابت — الأبلكيشن (Lane 8) بيقرا المفاتيح دي بالاسم.
     * الإضافة الوحيدة في Lane 7: `source` على كل بانش.
     */
    public function test_attendance_punch_shape_is_stable(): void
    {
        $rep = $this->makeRep();

        $this->withHeaders($this->tokenFor($rep))
            ->postJson('/api/attendance/punch', ['type' => 'in', 'lat' => 30.05, 'lng' => 31.25])
            ->assertOk()
            ->assertJsonStructure([
                'attendance' => [
                    'state', 'worked_minutes', 'open_since',
                    'punches' => ['*' => ['type', 'label', 'at', 'source']],
                ],
            ])
            ->assertJsonPath('attendance.state', 'working')
            ->assertJsonPath('attendance.punches.0.type', 'in')
            ->assertJsonPath('attendance.punches.0.source', 'app');
    }

    public function test_bad_coordinates_on_a_purchase_order_arrival_are_refused(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $po = \App\Models\PurchaseOrder::create([
            'number' => 'PO-CONTRACT-1', 'client_id' => $client->id, 'status' => 'pending',
            'assigned_to' => $rep->id, 'total' => 0, 'tax_total' => 0, 'grand_total' => 0,
        ]);
        // الحضور مطلوب على أكشنات الميدان
        $this->withHeaders($this->tokenFor($rep))->postJson('/api/attendance/punch', ['type' => 'in']);

        $this->withHeaders($this->tokenFor($rep))
            ->postJson("/api/pos/{$po->id}/arrive", ['lat' => 999, 'lng' => 31.2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lat');

        $this->assertSame('pending', $po->fresh()->status, 'الأمر مايتحرّكش على إحداثيات غلط');
    }

    /**
     * ⚠️ عقد الإجازات (Lane 8) — الأبلكيشن بيقرا المفاتيح دي بالاسم،
     * والأرقام JSON numbers. من غير ملف موظف 403 والكارت بيستخبى.
     *
     * @return array{0: User, 1: User}
     */
    private function leaveTeam(): array
    {
        $boss = $this->user('manager');
        $bossE = \App\Models\Employee::create(['code' => 'EMP-9001', 'name' => 'المدير', 'name_en' => 'Boss', 'user_id' => $boss->id]);
        $rep = $this->makeRep();
        \App\Models\Employee::create(['code' => 'EMP-9002', 'name' => 'مندوب', 'name_en' => 'Rep', 'user_id' => $rep->id, 'manager_id' => $bossE->id]);

        return [$rep, $boss];
    }

    public function test_leave_endpoints_follow_the_app_contract(): void
    {
        [$rep, $boss] = $this->leaveTeam();
        $h = $this->tokenFor($rep);

        $this->withHeaders($h)->getJson('/api/leave/balances')
            ->assertOk()
            ->assertJsonStructure(['year', 'balances' => ['*' => ['type', 'label', 'entitled', 'used', 'remaining']]])
            ->assertJsonPath('balances.0.type', 'annual')
            ->assertJsonPath('balances.0.entitled', 21);

        $res = $this->withHeaders($h)->postJson('/api/leave/requests', [
            'type' => 'annual', 'from' => '2026-10-25', 'to' => '2026-10-27', 'note' => 'سفر',
        ])->assertCreated();

        $res->assertJsonStructure(['request' => [
            'id', 'type', 'type_label', 'from', 'to', 'days', 'note', 'status', 'status_label',
            'decision_note', 'employee_name', 'created_at',
        ]])
            ->assertJsonPath('request.from', '2026-10-25')
            ->assertJsonPath('request.to', '2026-10-27')
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.employee_name', 'Rep');
        $this->assertSame(
            ['id', 'type', 'type_label', 'from', 'to', 'days', 'note', 'status', 'status_label', 'decision_note', 'employee_name', 'created_at'],
            array_keys($res->json('request')));
        $this->assertIsNumeric($res->json('request.days'));
        $this->assertEquals(3, $res->json('request.days'));
        $id = $res->json('request.id');

        $this->withHeaders($h)->getJson('/api/leave/requests?mine=1')
            ->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.id', $id);

        $hb = $this->tokenFor($boss);
        $this->withHeaders($hb)->getJson('/api/leave/approvals')
            ->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.id', $id);

        $this->withHeaders($hb)->postJson("/api/leave/requests/{$id}/decide", ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('request.status', 'approved');

        // نفس القرار تاني = نفس الصف، وقرار مختلف = 422
        $this->withHeaders($hb)->postJson("/api/leave/requests/{$id}/decide", ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('request.status', 'approved');
        $this->withHeaders($hb)->postJson("/api/leave/requests/{$id}/decide", ['decision' => 'rejected'])
            ->assertStatus(422)->assertJsonStructure(['message']);

        $this->withHeaders($h)->getJson('/api/leave/balances')->assertJsonPath('balances.0.remaining', 18);

        // الموظف نفسه مايقررش (مش مديره)
        $this->withHeaders($h)->postJson("/api/leave/requests/{$id}/decide", ['decision' => 'rejected'])
            ->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_same_idem_key_returns_the_same_request(): void
    {
        [$rep] = $this->leaveTeam();
        $h = $this->tokenFor($rep);
        $body = ['type' => 'annual', 'from' => '2026-10-25', 'to' => '2026-10-27', 'idem_key' => 'leave-abc-1'];

        $a = $this->withHeaders($h)->postJson('/api/leave/requests', $body)->assertCreated()->json('request.id');
        $b = $this->withHeaders($h)->postJson('/api/leave/requests', $body)->assertOk()->json('request.id');

        $this->assertSame($a, $b);
        $this->assertSame(1, \App\Models\LeaveRequest::query()->count());
    }

    public function test_leave_without_employee_record_is_403(): void
    {
        $rep = $this->makeRep();
        $h = $this->tokenFor($rep);

        $this->withHeaders($h)->getJson('/api/leave/balances')->assertForbidden()->assertJsonStructure(['message']);
        $this->withHeaders($h)->getJson('/api/leave/requests?mine=1')->assertForbidden()->assertJsonStructure(['message']);
        $this->withHeaders($h)->postJson('/api/leave/requests', ['type' => 'annual', 'from' => '2026-10-25', 'to' => '2026-10-27'])
            ->assertForbidden()->assertJsonStructure(['message']);
        $this->withHeaders($h)->getJson('/api/leave/approvals')->assertOk()->assertExactJson(['requests' => []]);
    }
}
