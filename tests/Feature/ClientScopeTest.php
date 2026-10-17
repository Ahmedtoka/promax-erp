<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * سكوب العملاء والعقود (الخط ٢ — تدقيق ١٧ أكتوبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * كل تيست قصاد ثغرة واحدة من جدول السكوب في خطة الـ١٠ شاتات:
 * علاقة جاية من الريكوست بـ`exists` بس ومن غير `Scope`.
 */
class ClientScopeTest extends TestCase
{
    use RefreshDatabase;

    /** فريق كامل: مدير + مندوبه + عميله */
    private function team(string $suffix): array
    {
        $manager = $this->makeAdmin([
            'role' => 'manager',
            'name_en' => 'Manager '.$suffix,
            'code' => 'MGR-'.strtoupper(uniqid()),
        ]);

        $rep = $this->makeRep(['manager_id' => $manager->id, 'name_en' => 'Rep '.$suffix]);

        $client = $this->makeClient([
            'manager_id' => $manager->id,
            'rep_id' => $rep->id,
            'name_en' => 'Client '.$suffix,
        ]);

        return [$manager, $rep, $client];
    }

    private function contractFor(?Client $client): Contract
    {
        return Contract::create([
            'client_id' => $client?->id,
            'number' => 'CT-'.strtoupper(uniqid()),
            'type' => Contract::TYPE_CHOICES[0],
            'discount' => 0.05,
            'active' => true,
        ]);
    }

    // ═══════════════════ العقود والبنود ═══════════════════

    public function test_manager_cannot_create_contract_on_another_teams_client(): void
    {
        [$mgrA] = $this->team('A');
        [, , $clientB] = $this->team('B');

        $this->actingAs($mgrA)
            ->post(route('erp.contracts.store'), ['client_id' => $clientB->id, 'discount' => 10])
            ->assertForbidden();

        $this->assertFalse(Contract::where('client_id', $clientB->id)->exists());
    }

    public function test_manager_can_still_create_contract_on_own_client(): void
    {
        [$mgrA, , $clientA] = $this->team('A');

        $this->actingAs($mgrA)
            ->post(route('erp.contracts.store'), ['client_id' => $clientA->id, 'discount' => 10])
            ->assertRedirect();

        $this->assertTrue(Contract::where('client_id', $clientA->id)->exists());
    }

    public function test_manager_cannot_link_orphan_contract_to_another_teams_client(): void
    {
        [$mgrA] = $this->team('A');
        [, , $clientB] = $this->team('B');
        $orphan = $this->contractFor(null);

        $this->actingAs($mgrA)
            ->post(route('erp.contracts.link', $orphan), ['client_id' => $clientB->id])
            ->assertForbidden();

        $this->assertNull($orphan->fresh()->client_id);
    }

    public function test_manager_cannot_relink_another_teams_contract(): void
    {
        [$mgrA, , $clientA] = $this->team('A');
        [, , $clientB] = $this->team('B');
        $ctB = $this->contractFor($clientB);

        $this->actingAs($mgrA)
            ->post(route('erp.contracts.link', $ctB), ['client_id' => $clientA->id])
            ->assertForbidden();

        $this->assertSame((int) $clientB->id, (int) $ctB->fresh()->client_id);
    }

    public function test_manager_cannot_delete_another_teams_contract(): void
    {
        [$mgrA] = $this->team('A');
        [, , $clientB] = $this->team('B');
        $ctB = $this->contractFor($clientB);

        $this->actingAs($mgrA)
            ->delete(route('erp.contracts.destroy', $ctB))
            ->assertForbidden();

        $this->assertNotNull($ctB->fresh());
    }

    public function test_manager_cannot_add_or_delete_clause_on_another_teams_contract(): void
    {
        [$mgrA] = $this->team('A');
        [, , $clientB] = $this->team('B');
        $ctB = $this->contractFor($clientB);

        $this->actingAs($mgrA)
            ->post(route('erp.clauses.store', $ctB), [
                'kind' => array_key_first(ContractClause::KINDS),
                'basis' => ContractClause::BASES[0],
                'label' => 'بند',
                'pct' => 50,
            ])
            ->assertForbidden();

        $this->assertSame(0, $ctB->contractClauses()->count());

        $clause = ContractClause::create([
            'contract_id' => $ctB->id,
            'kind' => array_key_first(ContractClause::KINDS),
            'basis' => ContractClause::BASES[0],
            'label' => 'بند قديم',
        ]);

        $this->actingAs($mgrA)
            ->delete(route('erp.clauses.destroy', $ctB), ['clause_id' => $clause->id])
            ->assertForbidden();

        $this->assertNotNull($clause->fresh());
    }

    // ═══════════════════ `manager_id` في فورم العميل ═══════════════════

    private function clientPayload(array $extra = []): array
    {
        return $extra + [
            'name' => 'عميل سكوب',
            'name_en' => 'Scope client',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList('new')->id,
            'discount' => 0,
        ];
    }

    public function test_manager_cannot_create_client_under_another_manager(): void
    {
        [$mgrA] = $this->team('A');
        [$mgrB] = $this->team('B');

        $this->actingAs($mgrA)
            ->post(route('erp.clients.store'), $this->clientPayload(['manager_id' => $mgrB->id]))
            ->assertSessionHasErrors('manager_id');

        $this->assertFalse(Client::where('name_en', 'Scope client')->exists());
    }

    public function test_manager_cannot_move_own_client_to_another_manager(): void
    {
        [$mgrA, , $clientA] = $this->team('A');
        [$mgrB] = $this->team('B');

        $this->actingAs($mgrA)
            ->put(route('erp.clients.update', $clientA), $this->clientPayload([
                'name' => $clientA->name,
                'name_en' => $clientA->name_en,
                'manager_id' => $mgrB->id,
            ]))
            ->assertSessionHasErrors('manager_id');

        $this->assertSame((int) $mgrA->id, (int) $clientA->fresh()->manager_id);
    }

    public function test_client_manager_must_be_a_manager_role(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();

        $this->actingAs($admin)
            ->post(route('erp.clients.store'), $this->clientPayload(['manager_id' => $rep->id]))
            ->assertSessionHasErrors('manager_id');
    }

    // ═══════════════════ تأكيد اللوكيشن ═══════════════════

    private function geo(array $extra = []): array
    {
        return $extra + [
            'lat' => 30.0444, 'lng' => 31.2357,
            'address' => 'Cairo', 'address_ar' => 'القاهرة',
        ];
    }

    public function test_manager_cannot_confirm_location_of_another_teams_client(): void
    {
        [$mgrA] = $this->team('A');
        [, , $clientB] = $this->team('B');

        $this->actingAs($mgrA)
            ->post(route('erp.client_locations.confirm', $clientB), $this->geo())
            ->assertForbidden();

        $this->assertNull($clientB->fresh()->location_confirmed_at);
    }

    public function test_branch_manager_cannot_move_client_to_zone_of_another_branch(): void
    {
        $b1 = Branch::create(['code' => 'BR-T1', 'name' => 'فرع ١']);
        $b2 = Branch::create(['code' => 'BR-T2', 'name' => 'فرع ٢']);
        $bm = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $b1->id]);
        $client = $this->makeClient(['branch_id' => $b1->id]);
        $foreign = $this->makeZone();
        $foreign->update(['branch_id' => $b2->id]);

        $this->actingAs($bm)
            ->post(route('erp.client_locations.confirm', $client), $this->geo(['zone_id' => $foreign->id]))
            ->assertSessionHasErrors('zone_id');

        $this->assertNotSame((int) $foreign->id, (int) $client->fresh()->zone_id);
    }

    // ═══════════════════ التفعيل ═══════════════════

    public function test_activation_writes_the_real_price_list_id(): void
    {
        $admin = $this->makeAdmin();
        $zone = $this->makeZone();
        $list = $this->makePriceList('L-SCOPE');
        $client = $this->makeClient(['status' => 'pending', 'zone_id' => $zone->id]);

        $this->actingAs($admin)
            ->post(route('erp.clients.activate.do'), [
                'ids' => [$client->id],
                'price_list_id' => $list->id,
            ])
            ->assertRedirect();

        $client->refresh();
        $this->assertSame('active', $client->status);
        $this->assertSame((int) $list->id, (int) $client->price_list_id);
        // العمود النصي متزامن بنفس قاعدة فورم العميل (المسمّاة = new)
        $this->assertSame('new', $client->price_list);
    }

    public function test_activation_legacy_price_list_code_resolves_to_its_list(): void
    {
        $admin = $this->makeAdmin();
        $list = $this->makePriceList('old');
        $client = $this->makeClient(['status' => 'pending', 'zone_id' => $this->makeZone()->id]);

        $this->actingAs($admin)
            ->post(route('erp.clients.activate.do'), ['ids' => [$client->id], 'price_list' => 'old'])
            ->assertRedirect();

        $this->assertSame((int) $list->id, (int) $client->fresh()->price_list_id);
    }

    public function test_activation_rejects_non_manager_as_account_manager(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        $client = $this->makeClient(['status' => 'pending', 'zone_id' => $this->makeZone()->id]);

        $this->actingAs($admin)
            ->post(route('erp.clients.activate.do'), ['ids' => [$client->id], 'manager_id' => $rep->id])
            ->assertSessionHasErrors('manager_id');

        $this->assertSame('pending', $client->fresh()->status);
    }

    public function test_manager_cannot_activate_onto_another_teams_rep(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB] = $this->team('B');
        $client = $this->makeClient(['status' => 'pending', 'manager_id' => null, 'zone_id' => $this->makeZone()->id]);

        $this->actingAs($mgrA)
            ->post(route('erp.clients.activate.do'), ['ids' => [$client->id], 'rep_id' => $repB->id])
            ->assertForbidden();

        $this->assertNull($client->fresh()->rep_id);
    }

    // ═══════════════════ المستند اليدوي ═══════════════════

    public function test_manual_doc_rejects_rep_and_client_from_different_teams(): void
    {
        $admin = $this->makeAdmin();
        [, $repA] = $this->team('A');
        [, , $clientB] = $this->team('B');

        $this->actingAs($admin)
            ->post(route('ops.manual.invoice'), [
                'user_id' => $repA->id,
                'client_id' => $clientB->id,
                'doc_date' => now()->toDateString(),
                'paper_ref' => 'P-1',
                'items' => [['product_id' => $this->makeProduct()->id, 'qty' => 1]],
            ])
            ->assertForbidden();
    }

    // ═══════════════════ الفروع والعربيات والمخازن ═══════════════════

    public function test_branch_manager_must_be_a_manager_role(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();

        $this->actingAs($admin)
            ->post(route('erp.branches.store'), ['name' => 'فرع جديد', 'manager_id' => $rep->id])
            ->assertSessionHasErrors('manager_id');

        $mgr = $this->makeAdmin(['role' => 'manager']);

        $this->actingAs($admin)
            ->post(route('erp.branches.store'), ['name' => 'فرع سليم', 'manager_id' => $mgr->id])
            ->assertSessionHasNoErrors();
    }

    public function test_vehicle_crew_must_be_active_rep_or_driver(): void
    {
        $admin = $this->makeAdmin();
        $accountant = $this->makeAdmin(['role' => 'accountant']);
        $inactive = $this->makeRep(['active' => false]);

        $this->actingAs($admin)
            ->post(route('erp.vehicles.store'), [
                'plate' => 'T-'.uniqid(),
                'rep_id' => $accountant->id,
                'driver_id' => $inactive->id,
            ])
            ->assertSessionHasErrors(['rep_id', 'driver_id']);
    }

    public function test_warehouse_manager_must_be_manager_or_keeper(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();

        $this->actingAs($admin)
            ->post(route('erp.warehouses.store'), [
                'code' => 'WH-T'.random_int(100, 999),
                'name' => 'مخزن',
                'type' => Warehouse::TYPE_BRANCH,
                'manager_id' => $rep->id,
            ])
            ->assertSessionHasErrors('manager_id');
    }
}
