<?php

namespace Tests\Feature;

use App\Models\JourneyPlan;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Target;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مفاتيح جرد ٩/١١ بتشتغل فعلاً: المنح بيفتح الزرار والراوت، والمنع بيقفلهم
 * (مش بس الخريطة — الكنترولر نفسه بيسأل `Access::action`).
 */
class ActionKeyBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::flush();
        RolePermission::flush();
    }

    private function plan(User $rep): JourneyPlan
    {
        return JourneyPlan::create([
            'user_id' => $rep->id,
            'client_id' => $this->makeClient(['manager_id' => $rep->manager_id])->id,
            'weekday' => 1,
            'every_weeks' => 1,
            'sort' => 1,
            'active' => true,
        ]);
    }

    /** يوزر مدير فرع على رول مخصص — الصفوف المدية للرول المخصص */
    private function onCustomRole(array $perms): User
    {
        $role = Role::create([
            'name_ar' => 'منسق خطوط السير', 'name_en' => 'Route Planner',
            'base_role' => 'branch_manager', 'web_login' => true, 'app_login' => false,
        ]);

        foreach ($perms as $perm) {
            RolePermission::create(['role' => 'c:'.$role->id, 'perm' => $perm, 'allow' => true]);
        }
        RolePermission::flush();

        $user = $this->makeAdmin(['role' => 'branch_manager']);
        $user->custom_role_id = $role->id;
        $user->save();

        return $user->fresh();
    }

    /** المنح لغير المدير بيمسح خطط مناديبه بس — فريق تاني مايتلمسش (مش مسح للشركة) */
    public function test_journey_wipe_granted_to_a_custom_role_is_team_scoped(): void
    {
        // مدير الفرع مالوش المسح افتراضياً (`role:admin,manager`) — المنح هو اللي بيفتحه
        $planner = $this->onCustomRole(['act.field.wipe']);
        $ownRep = $this->makeRep(['manager_id' => $planner->id]);
        $this->plan($ownRep);

        $otherManager = $this->makeAdmin(['role' => 'manager']);
        $otherRep = $this->makeRep(['manager_id' => $otherManager->id]);
        $this->plan($otherRep);

        $this->actingAs($planner)
            ->post(route('ops.journeys.wipe'))
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertSame(0, JourneyPlan::where('user_id', $ownRep->id)->count());
        $this->assertSame(1, JourneyPlan::where('user_id', $otherRep->id)->count());
    }

    /** منح صفحة خطوط السير بس مابيورّثش المسح — الزرار مخفي والراوت 403 */
    public function test_journeys_page_grant_alone_does_not_give_wipe(): void
    {
        $planner = $this->onCustomRole(['ops.journeys']);
        $rep = $this->makeRep(['manager_id' => $planner->id]);
        $this->plan($rep);

        $this->actingAs($planner)->get(route('ops.journeys'))
            ->assertOk()
            ->assertDontSee(route('ops.journeys.wipe'), false);

        $this->actingAs($planner)->post(route('ops.journeys.wipe'))->assertForbidden();
        $this->assertSame(1, JourneyPlan::where('user_id', $rep->id)->count());
    }

    public function test_journey_wipe_denied_to_a_manager_is_hidden_and_forbidden(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $rep = $this->makeRep(['manager_id' => $manager->id]);
        $this->plan($rep);

        // قبل المنع: الزرار باين
        $this->actingAs($manager)->get(route('ops.journeys'))
            ->assertOk()
            ->assertSee(route('ops.journeys.wipe'), false);

        UserPermission::create(['user_id' => $manager->id, 'perm' => 'act.field.wipe', 'allow' => false]);
        $manager = User::find($manager->id);

        $this->actingAs($manager)->get(route('ops.journeys'))
            ->assertOk()
            ->assertDontSee(route('ops.journeys.wipe'), false);

        $this->actingAs($manager)->post(route('ops.journeys.wipe'))->assertForbidden();
        $this->assertSame(1, JourneyPlan::where('user_id', $rep->id)->count());
    }

    public function test_company_node_rebalance_needs_annual_company_and_a_grant_opens_it(): void
    {
        $year = (int) now()->year + 1;
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('erp.targets.annual.company'), ['year' => $year, 'amount' => 120000])
            ->assertRedirect();

        $company = Target::where('year', $year)->where('kind', Target::KIND_COMPANY)->firstOrFail();
        $manager = $this->makeAdmin(['role' => 'manager']);
        $payload = ['month' => 3, 'amount' => 15000];

        // افتراضي: عقدة الشركة أدمن بس
        $this->actingAs($manager)
            ->post(route('erp.targets.annual.rebalance', $company), $payload)
            ->assertForbidden();

        UserPermission::create(['user_id' => $manager->id, 'perm' => 'act.targets.annual_company', 'allow' => true]);
        $manager = User::find($manager->id);

        $this->actingAs($manager)
            ->post(route('erp.targets.annual.rebalance', $company), $payload)
            ->assertRedirect()
            ->assertSessionHas('ok');
    }
}
