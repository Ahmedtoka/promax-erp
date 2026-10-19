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
        foreach (['sales_agent', 'driver', 'promoter', 'manager', 'admin', 'warehouse_keeper', 'accountant', 'production_manager'] as $role) {
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

    /**
     * أوامر الإنتاج (التصنيع ١٩/١٠ — عقد الخط ٨، أبلكيشن 2.6.0+13):
     * شكل الصف ثابت، `order` في رد ابدأ/خلص، 422 بـ`message`، و403
     * للرولز التانية (الأبلكيشن بيخبّي الميزة).
     */
    public function test_production_endpoints_follow_the_contract(): void
    {
        $fg = $this->makeProduct(['kind' => 'finished']);
        $raw = $this->makeProduct(['kind' => 'raw']);
        $bom = \App\Models\Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
        $bom->lines()->create(['product_id' => $raw->id, 'qty' => 1, 'basis' => 'per_unit', 'sort' => 1]);
        $line = \App\Models\CostCenter::create(['code' => 'LC1', 'name' => 'خط', 'name_en' => 'Line', 'kind' => 'line', 'active' => true]);
        $wh = $this->makeWarehouse();
        $wh->forceFill(['type' => \App\Models\Warehouse::TYPE_FACTORY])->save();
        $pm = $this->user('production_manager');
        $o = \App\Services\Mfg\Orders::create([
            'product_id' => $fg->id, 'line_id' => $line->id, 'planned_qty' => 50,
            'batch_no' => 'CT-1', 'planned_on' => now()->toDateString(),
        ], $pm);

        $row = ['id', 'code', 'product_name', 'line_id', 'line_name', 'status', 'status_label',
            'planned_qty', 'output_qty', 'unit', 'started_at', 'finished_at'];
        $h = $this->tokenFor($pm);

        $this->withHeaders($h)->getJson('/api/production/orders')->assertOk()
            ->assertJsonStructure(['orders' => ['*' => $row], 'lines' => ['*' => ['id', 'name']]])
            ->assertJsonPath('orders.0.code', $o->number);

        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/start", ['idem_key' => 'ct-s'])
            ->assertOk()->assertJsonStructure(['message', 'order' => $row]);
        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/finish", ['idem_key' => 'ct-f', 'output_qty' => 48])
            ->assertOk()->assertJsonStructure(['message', 'order' => $row]);
        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/start", ['idem_key' => 'ct-s2'])
            ->assertStatus(422)->assertJsonStructure(['message']);

        foreach (['sales_agent', 'manager'] as $role) {
            $this->withHeaders($this->tokenFor($this->user($role)))
                ->getJson('/api/production/orders')->assertStatus(403);
        }
    }
}
