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
}
