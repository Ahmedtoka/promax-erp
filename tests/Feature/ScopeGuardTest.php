<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Custody;
use App\Models\JourneyPlan;
use App\Models\User;
use App\Support\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * حارس السكوب — «مين يقدر يشتغل على مين» (تدقيق ٨ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * كل تيست هنا بيقابل **ثغرة حقيقية** اتلقيت في الفحص الشامل:
 * ٩ شاشات كانت بتاخد علاقة (مندوب/عميل/منطقة) من الريكوست من غير ما
 * السيرفر يتأكد منها. `exists:users,id` بتقول «اليوزر موجود» بس.
 *
 * ⚠️ **التيستات دي مش تجميلية.** كل واحدة فيهم كانت طريق مفتوح
 * لكتابة على دفتر عميل مش بتاعك أو تحميل عربية مش بتاعتك.
 */
class ScopeGuardTest extends TestCase
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

        $rep = $this->makeRep([
            'manager_id' => $manager->id,
            'name_en' => 'Rep '.$suffix,
        ]);

        $client = $this->makeClient([
            'manager_id' => $manager->id,
            'rep_id' => $rep->id,
            'name_en' => 'Client '.$suffix,
        ]);

        return [$manager, $rep, $client];
    }

    // ═══════════════════ الحارس نفسه ═══════════════════

    public function test_manager_cannot_touch_another_managers_rep(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB] = $this->team('B');

        $this->assertFalse(Scope::canRep($mgrA, $repB));
    }

    public function test_manager_can_touch_own_rep(): void
    {
        [$mgrA, $repA] = $this->team('A');

        $this->assertTrue(Scope::canRep($mgrA, $repA));
    }

    /**
     * ⚠️ الثغرة اللي المالك بلّغ عنها: `exists:users,id` بتقبل محاسب.
     * تحميل عهدة على محاسب = عهدة محدش هيقفلها.
     */
    public function test_non_field_user_is_never_a_valid_rep(): void
    {
        $admin = $this->makeAdmin();
        $accountant = $this->makeAdmin(['role' => 'accountant']);

        $this->assertFalse(Scope::canRep($admin, $accountant));
    }

    /** الحساب الموقوف كمان — البضاعة بتخرج لحد مش شغال */
    public function test_inactive_rep_is_rejected_even_for_admin(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep(['active' => false]);

        $this->assertFalse(Scope::canRep($admin, $rep));
    }

    public function test_manager_cannot_see_another_managers_client(): void
    {
        [$mgrA] = $this->team('A');
        [, , $clientB] = $this->team('B');

        $this->assertFalse(Scope::canClient($mgrA, $clientB));
    }

    /**
     * ⚠️ اتساق العلاقة: عميل مدير «أ» على مندوب مدير «ب» = بضاعة
     * بتخرج من فريق وتقع في تصفية فريق تاني.
     */
    public function test_client_and_rep_must_share_a_manager(): void
    {
        [, $repA, $clientA] = $this->team('A');
        [, $repB] = $this->team('B');

        $this->assertTrue(Scope::sameTeam($repA, $clientA));
        $this->assertFalse(Scope::sameTeam($repB, $clientA));
    }

    /**
     * ⚠️ **الفحص بيتخطى لو حد من الاتنين مالوش مدير.** الداتا
     * التاريخية فيها عملاء بلا `manager_id`، والفحص الصارم كان
     * هيقفل تسكين شرعي.
     */
    public function test_same_team_skips_when_relation_is_unknown(): void
    {
        [, $repA] = $this->team('A');
        $orphan = $this->makeClient(['manager_id' => null]);

        $this->assertTrue(Scope::sameTeam($repA, $orphan));
    }

    /** مندوب لسه مالوش مناطق = أول تسكين مسموح */
    public function test_zone_check_skips_for_rep_with_no_zones(): void
    {
        [, $rep] = $this->team('A');
        $zone = $this->makeZone();
        $client = $this->makeClient(['zone_id' => $zone->id]);

        $this->assertTrue(Scope::inZone($rep, $client));
    }

    public function test_zone_check_blocks_client_outside_rep_zones(): void
    {
        [, $rep] = $this->team('A');
        $his = $this->makeZone();
        $other = $this->makeZone();

        $rep->update(['zone_id' => $his->id]);
        $rep->refresh()->load('zones');

        $client = $this->makeClient(['zone_id' => $other->id]);

        $this->assertFalse(Scope::inZone($rep, $client));
        // ⚠️ ومع منطقة بتتسكّن في نفس الريكوست، بيعدّي
        $this->assertTrue(Scope::inZone($rep, $client, [$other->id]));
    }

    // ═══════════════════ الراوتس ═══════════════════

    /**
     * ⚠️ **`assignPurchaseOrder` كانت بلا أي حارس** — أي مدير يعيد
     * تسكين أي أمر في الشركة على أي يوزر.
     */
    public function test_manager_cannot_assign_po_of_another_team(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB, $clientB] = $this->team('B');

        $po = \App\Models\PurchaseOrder::create([
            'number' => 'PO-TEST-1',
            'client_id' => $clientB->id,
            'status' => 'pending',
            'total' => 0,
        ]);

        $this->actingAs($mgrA)
            ->post(route('ops.pos.assign', $po), ['assigned_to' => $repB->id])
            ->assertForbidden();
    }

    /**
     * ⚠️ **`/ops/live/{user}` كان بيفتح يوم أي مندوب بالـid** —
     * مساره وزياراته وفواتيره.
     */
    public function test_manager_cannot_open_another_teams_rep_day(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB] = $this->team('B');

        $this->actingAs($mgrA)
            ->get(route('ops.rep_day', $repB))
            ->assertForbidden();
    }

    /**
     * ⚠️ **`closeCustody` كان بلا حارس** — أي مدير يقفل يوم أي مندوب
     * وهو لسه في الشارع.
     */
    public function test_manager_cannot_close_another_teams_custody(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB] = $this->team('B');

        Custody::create([
            'user_id' => $repB->id,
            'warehouse_id' => $this->makeWarehouse()->id,
            'date' => today(),
            'status' => 'open',
        ]);

        $this->actingAs($mgrA)
            ->post(route('ops.rep.close', $repB))
            ->assertForbidden();
    }

    /**
     * ⚠️ **شاشة تسكين العملاء ماكانتش بتتحقق من حاجة** — دي جذر
     * السيناريو اللي المالك اشتكى منه.
     */
    public function test_manager_cannot_assign_clients_to_another_teams_rep(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB] = $this->team('B');
        [, , $clientA] = $this->team('A2');

        $this->actingAs($mgrA)
            ->post(route('ops.assignments.assign'), [
                'user_id' => $repB->id,
                'client_ids' => [$clientA->id],
            ])
            ->assertForbidden();
    }

    /** ونفس الحارس على خطة السير — `store` كانت بتتجاهل فلتر `index` */
    public function test_journey_plan_respects_team_scope(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB, $clientB] = $this->team('B');

        $this->actingAs($mgrA)
            ->post(route('ops.journeys.store'), [
                'user_id' => $repB->id,
                'weekday' => 1,
                'every_weeks' => 1,
                'client_ids' => [$clientB->id],
            ])
            ->assertForbidden();
    }

    public function test_journey_plan_delete_respects_team_scope(): void
    {
        [$mgrA] = $this->team('A');
        [, $repB, $clientB] = $this->team('B');

        $plan = JourneyPlan::create([
            'user_id' => $repB->id,
            'client_id' => $clientB->id,
            'weekday' => 1,
            'every_weeks' => 1,
            'sort' => 1,
            'active' => true,
        ]);

        $this->actingAs($mgrA)
            ->delete(route('ops.journeys.destroy', $plan))
            ->assertForbidden();
    }

    /**
     * ⚠️ **العميل المعتمد كان بيتولد يتيم** — بلا قناة ولا مندوب ولا
     * مدير، فمابيظهرش في `visibleTo` لأي حد.
     */
    public function test_approved_client_inherits_the_reps_assignment(): void
    {
        [$manager, $rep] = $this->team('A');
        $zone = $this->makeZone();
        $channel = $this->makeChannel();

        $rep->update(['zone_id' => $zone->id, 'channel_id' => $channel->id]);

        $req = \App\Models\ClientRequest::create([
            'number' => 'REQ-1',
            'name' => 'عميل جديد',
            'status' => 'pending',
            'created_by' => $rep->id,
        ]);

        $this->actingAs($manager)
            ->post(route('ops.requests.decide', $req), ['decision' => 'approved'])
            ->assertRedirect();

        $client = Client::latest('id')->first();

        $this->assertSame((int) $rep->id, (int) $client->rep_id);
        $this->assertSame((int) $manager->id, (int) $client->manager_id);
        $this->assertSame((int) $channel->id, (int) $client->channel_id);
        $this->assertSame((int) $zone->id, (int) $client->zone_id);

        // ⚠️ الاختبار الحقيقي: بيظهر للمدير اللي اعتمده
        $this->assertTrue($client->visibleBy($manager));
    }

    /**
     * ⚠️ **مدير بيعمل عميل كان بيضيّعه**: `manager_id` اختياري في
     * الفورم وبلا افتراضي، و`visibleTo` بتفلتر عليه — فالعميل بيختفي
     * من شاشة اللي عمله في اللحظة اللي بيتحفظ فيها.
     */
    public function test_manager_created_client_defaults_to_himself(): void
    {
        [$manager] = $this->team('A');

        $list = $this->makePriceList('new');
        $channel = $this->makeChannel();

        $this->actingAs($manager)
            ->post(route('erp.clients.store'), [
                'name' => 'عميل من المدير',
                'name_en' => 'Manager made client',
                'channel_id' => $channel->id,
                'price_list_id' => $list->id,
                'discount' => 0,
            ])
            ->assertRedirect();

        $client = Client::where('name_en', 'Manager made client')->firstOrFail();

        $this->assertSame((int) $manager->id, (int) $client->manager_id);

        // الاختبار الحقيقي: بيبان في قايمته هو
        $this->assertTrue(
            Client::visibleTo(Client::query(), $manager)
                ->whereKey($client->id)->exists(),
        );
    }

    /** `assertStaff` بتشمل موظفين مش ميدانيين — الحضور والنقاط */
    public function test_staff_scope_covers_non_field_roles(): void
    {
        [$mgrA] = $this->team('A');

        $office = $this->makeAdmin([
            'role' => 'accountant',
            'manager_id' => $mgrA->id,
        ]);

        $stranger = $this->makeAdmin(['role' => 'accountant']);

        $this->assertTrue(Scope::canStaff($mgrA, $office));
        $this->assertFalse(Scope::canStaff($mgrA, $stranger));
    }

    /** الأدمن بيعدّي من سكوب الفريق — بس مش من شرط الرول الميداني */
    public function test_admin_passes_team_scope_but_not_role_check(): void
    {
        $admin = $this->makeAdmin();
        [, $repB] = $this->team('B');

        $this->assertTrue(Scope::canRep($admin, $repB));
        $this->assertFalse(Scope::canRep($admin, $this->makeAdmin(['role' => 'accountant'])));
    }
}
