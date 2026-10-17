<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══ الخط ٣ (١٧/١٠/٢٠٢٦) — سكوب العمليات والليدات + الـ500 ═══
 *
 * كل تيست بيقابل ثغرة من فحص ١٧/١٠: استيراد أمر توريد على سواق
 * مدير تاني أو مخزن فرع تاني، ومدير بيعدّل ليد مدير تاني، وشاشة
 * إعادة التسعير بترمي 500، ولينك إشعار بيودّي المستلم 403.
 */
class OpsLeadScopeTest extends TestCase
{
    use RefreshDatabase;

    private function team(string $suffix): array
    {
        $manager = $this->makeAdmin(['role' => 'manager', 'name_en' => 'Mgr '.$suffix, 'code' => 'MGR-'.strtoupper(uniqid())]);
        $rep = $this->makeRep(['manager_id' => $manager->id, 'role' => 'driver']);
        $client = $this->makeClient(['manager_id' => $manager->id, 'rep_id' => $rep->id]);

        return [$manager, $rep, $client];
    }

    private function warehouse(array $attrs = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'code' => 'WH-'.strtoupper(uniqid()), 'name' => 'مخزن', 'name_en' => 'WH',
            'type' => Warehouse::TYPE_BRANCH, 'active' => true,
        ], $attrs));
    }

    private function importOne(User $actor, User $rep, $client, Warehouse $wh, array $extra = [])
    {
        $product = $this->makeProduct(['price_new' => 20, 'price_old' => 18]);

        return $this->actingAs($actor)->postJson(route('ops.po.import.one'), array_merge([
            'warehouse_id' => $wh->id,
            'assigned_to' => $rep->id,
            'due_at' => now()->addDay()->toDateString(),
            'client_id' => $client->id,
            'source' => 'PO-'.uniqid(),
            'items' => json_encode([$product->id => 5]),
        ], $extra));
    }

    // ═══════════════ ١. استيراد أوامر التوريد ═══════════════

    public function test_import_one_rejects_another_managers_driver(): void
    {
        [$mgrA, , $clientA] = $this->team('A');
        [, $repB] = $this->team('B');

        $this->importOne($mgrA, $repB, $clientA, $this->warehouse())->assertForbidden();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_import_one_rejects_accountant_as_driver_even_for_admin(): void
    {
        [, , $client] = $this->team('A');
        $accountant = $this->makeAdmin(['role' => 'accountant']);

        $this->importOne($this->makeAdmin(), $accountant, $client, $this->warehouse())->assertForbidden();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_import_one_rejects_inactive_warehouse(): void
    {
        [$mgrA, $repA, $clientA] = $this->team('A');

        $this->importOne($mgrA, $repA, $clientA, $this->warehouse(['active' => false]))->assertForbidden();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_import_one_accepts_own_team(): void
    {
        [$mgrA, $repA, $clientA] = $this->team('A');

        $this->importOne($mgrA, $repA, $clientA, $this->warehouse())->assertOk()->assertJson(['ok' => true]);
        $this->assertSame($repA->id, (int) PurchaseOrder::first()->assigned_to);
    }

    public function test_bulk_import_rejects_another_managers_driver(): void
    {
        [$mgrA, , $clientA] = $this->team('A');
        [, $repB] = $this->team('B');
        $product = $this->makeProduct(['price_new' => 20]);

        $this->actingAs($mgrA)->post(route('ops.po.import.store'), [
            'warehouse_id' => $this->warehouse()->id,
            'assigned_to' => $repB->id,
            'due_at' => now()->addDay()->toDateString(),
            'orders' => [['client_id' => $clientA->id, 'source' => 'X1', 'items' => json_encode([$product->id => 3])]],
        ])->assertForbidden();

        $this->assertSame(0, PurchaseOrder::count());
    }

    // ═══════════════ ٢. الليدات ═══════════════

    private function lead(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'number' => 'LD-'.uniqid(), 'name' => 'ليد', 'status' => 'new',
        ], $attrs));
    }

    private function leadForm(Lead $lead, array $over = []): array
    {
        return array_merge([
            'name' => $lead->name, 'zone_id' => $lead->zone_id,
            'assigned_to' => $lead->assigned_to, 'status' => $lead->status,
        ], $over);
    }

    public function test_manager_cannot_edit_another_managers_lead(): void
    {
        [$mgrA, $repA] = $this->team('A');
        [$mgrB, $repB] = $this->team('B');
        $lead = $this->lead(['manager_id' => $mgrB->id, 'assigned_to' => $repB->id]);

        $this->actingAs($mgrA)->put(route('erp.leads.update', $lead), $this->leadForm($lead, ['assigned_to' => $repA->id]))
            ->assertForbidden();

        $this->assertSame($repB->id, (int) $lead->fresh()->assigned_to);
    }

    public function test_manager_cannot_assign_own_lead_to_another_team(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB] = $this->team('B');
        $lead = $this->lead(['manager_id' => $mgrA->id]);

        $this->actingAs($mgrA)->put(route('erp.leads.update', $lead), $this->leadForm($lead, ['assigned_to' => $repB->id]))
            ->assertForbidden();

        $this->assertNull($lead->fresh()->assigned_to);
    }

    public function test_manager_assigns_own_lead_and_pool_lead_to_own_rep(): void
    {
        [$mgrA, $repA] = $this->team('A');
        $own = $this->lead(['manager_id' => $mgrA->id]);
        $pool = $this->lead();

        foreach ([$own, $pool] as $lead) {
            $this->actingAs($mgrA)->put(route('erp.leads.update', $lead), $this->leadForm($lead, ['assigned_to' => $repA->id]))
                ->assertSessionHasNoErrors();
            $this->assertSame($repA->id, (int) $lead->fresh()->assigned_to);
        }
    }

    public function test_manager_store_cannot_assign_another_teams_rep(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB] = $this->team('B');

        $this->actingAs($mgrA)->post(route('erp.leads.store'), ['name' => 'جديد', 'assigned_to' => $repB->id])
            ->assertForbidden();

        $this->assertSame(0, Lead::count());
    }

    public function test_branch_manager_follows_leads_manage_button(): void
    {
        $branch = Branch::create(['code' => 'BR-'.uniqid(), 'name' => 'فرع', 'active' => true]);
        $bm = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $branch->id]);
        $lead = $this->lead();

        // الافتراضي: `act.leads.manage` للمدير بس — فمدير الفرع مابيكتبش
        $this->assertFalse(\App\Support\Access::action($bm, 'act.leads.manage'));

        $this->actingAs($bm)->post(route('erp.leads.store'), ['name' => 'جديد'])->assertForbidden();
        $this->actingAs($bm)->put(route('erp.leads.update', $lead), $this->leadForm($lead, ['name' => 'متغير']))->assertForbidden();
        $this->assertSame('ليد', $lead->fresh()->name);
    }

    public function test_admin_still_edits_any_lead(): void
    {
        [$mgrB, $repB] = $this->team('B');
        [, $repA] = $this->team('A');
        $lead = $this->lead(['manager_id' => $mgrB->id, 'assigned_to' => $repB->id]);

        $this->actingAs($this->makeAdmin())->put(route('erp.leads.update', $lead), $this->leadForm($lead, ['assigned_to' => $repA->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($repA->id, (int) $lead->fresh()->assigned_to);
    }

    // ═══════════════ ٣. شاشة إعادة التسعير ═══════════════

    public function test_reprice_check_renders_with_a_mismatched_po(): void
    {
        $admin = $this->makeAdmin();
        [, $rep, $client] = $this->team('A');

        $this->importOne($admin, $rep, $client, $this->warehouse())->assertOk();
        // فرق مقصود عشان الصف يظهر بشارته (statusColor كانت بترمي 500 هنا)، وقايمة مسماة
        // لأن old/new مستثناة من الشاشة عن قصد
        PurchaseOrder::query()->update(['grand_total' => 1, 'price_mode' => 'vip']);

        $this->actingAs($admin)->get(route('ops.pos.reprice.check'))
            ->assertOk()
            ->assertSee(PurchaseOrder::first()->number);
    }

    // ═══════════════ ٤. لينك الإشعار ═══════════════

    public function test_notification_to_a_screen_the_receiver_cannot_open_does_not_dead_end(): void
    {
        $accountant = $this->makeAdmin(['role' => 'accountant']);
        $this->assertFalse(\App\Support\Access::allows($accountant, 'ops.merch'));

        $note = AppNotification::create(['user_id' => $accountant->id, 'title' => 't', 'link' => 'merch', 'is_good' => false]);

        $this->actingAs($accountant)->from(route('erp.overview'))->get(route('notifications.go', $note))
            ->assertRedirect(route('erp.overview'))
            ->assertSessionHasErrors('note');

        $this->assertNotNull($note->fresh()->read_at);
    }

    public function test_notification_opens_its_screen_when_allowed(): void
    {
        $accountant = $this->makeAdmin(['role' => 'accountant']);
        $note = AppNotification::create(['user_id' => $accountant->id, 'title' => 't', 'link' => 'collections']);

        $this->actingAs($accountant)->get(route('notifications.go', $note))
            ->assertRedirect(route('erp.collections'));
    }

    /** إشعار حد تاني يفضل 403 — ده اللي الزحف شافه على 2600 (إشعار محاسب فتحه الأدمن) */
    public function test_someone_elses_notification_stays_forbidden(): void
    {
        $accountant = $this->makeAdmin(['role' => 'accountant']);
        $note = AppNotification::create(['user_id' => $accountant->id, 'title' => 't', 'link' => 'collections']);

        $this->actingAs($this->makeAdmin())->get(route('notifications.go', $note))->assertForbidden();
    }
}
