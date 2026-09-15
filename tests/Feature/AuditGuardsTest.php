<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Client;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حراسات تدقيق ١٥ سبتمبر ٢٠٢٦ — كل تيست بيحرس ثغرة اتقفلت:
 * إلغاء أمر التوريد قرار إدارة، الهدايا لرولز الميدان بس، البوت ستراب
 * مايقعش لموظف بلا صف حضور، واللوكيشن المؤكَّد مايتكتبش عليه من الميدان.
 */
class AuditGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_warehouse_keeper_cannot_cancel_a_purchase_order(): void
    {
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper']);
        $manager = $this->makeAdmin(['role' => 'manager']);
        $client = $this->makeClient();
        $po = PurchaseOrder::create([
            'number' => 'PO-GUARD-1', 'client_id' => $client->id, 'status' => 'pending',
            'assigned_to' => $manager->id, 'total' => 0, 'tax_total' => 0, 'grand_total' => 0,
        ]);

        $this->actingAs($keeper)
            ->post(route('ops.pos.cancel', $po), ['reason' => 'x', 'fate' => 'return'])
            ->assertForbidden();

        $this->assertSame('pending', $po->fresh()->status);
    }

    public function test_office_roles_cannot_post_gifts_through_the_api(): void
    {
        foreach (['accountant', 'warehouse_keeper', 'branch_manager'] as $role) {
            $u = $this->makeAdmin(['role' => $role]);
            $this->withHeaders($this->tokenFor($u))
                ->postJson('/api/gifts', ['product_id' => 1, 'qty' => 1, 'client_id' => 1])
                ->assertForbidden();
        }
    }

    public function test_bootstrap_does_not_crash_for_a_user_without_an_attendance_row(): void
    {
        foreach (['warehouse_keeper', 'accountant'] as $role) {
            $u = $this->makeAdmin(['role' => $role]);
            $this->withHeaders($this->tokenFor($u))->getJson('/api/bootstrap')->assertOk();
        }
    }

    public function test_a_confirmed_client_location_is_not_overwritten_from_the_field(): void
    {
        $rep = $this->punchIn($this->makeRep());
        $client = $this->makeClient([
            'rep_id' => $rep->id,
            'lat' => 30.0444, 'lng' => 31.2357,
            'location_confirmed_at' => now(),
        ]);

        $this->withHeaders($this->tokenFor($rep))
            ->postJson("/api/clients/{$client->id}/location", ['lat' => 30.05, 'lng' => 31.24])
            ->assertStatus(409);

        $this->assertSame(30.0444, (float) $client->fresh()->lat);
    }

    public function test_a_promoter_may_send_a_location_for_a_branch_of_their_channel_and_zone(): void
    {
        $channel = $this->seededChannel(Channel::KEY_ACCOUNT);
        $zone = $this->makeZone();

        $promoter = $this->punchIn($this->makeRep(['role' => 'promoter', 'zone_id' => $zone->id, 'channel_id' => $channel->id]));
        $branch = $this->makeClient(['channel_id' => $channel->id, 'zone_id' => $zone->id]);

        // مش في البول ولا مسكّن له كمندوب — القاعدة القديمة كانت ترفضه بـ403
        $this->withHeaders($this->tokenFor($promoter))
            ->postJson("/api/clients/{$branch->id}/location", ['lat' => 30.05, 'lng' => 31.24])
            ->assertOk();

        $this->assertNotNull($branch->fresh()->location_submitted_at);
    }
}
