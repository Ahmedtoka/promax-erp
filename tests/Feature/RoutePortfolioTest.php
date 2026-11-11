<?php

namespace Tests\Feature;

use App\Models\JourneyPlan;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══ ✨ محفظة العملاء الحاليين + التابات الأربعة (١١/١١/٢٠٢٦) ═══
 * «عاوز زي العملاء المحتملين بالظبط» — نفس المحفظة ونفس سكشن المنيو.
 */
class RoutePortfolioTest extends TestCase
{
    use RefreshDatabase;

    private User $mgr;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mgr = $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-'.strtoupper(uniqid())]);
        $this->rep = $this->makeRep(['manager_id' => $this->mgr->id]);
    }

    private function client(array $a = [])
    {
        return $this->makeClient($a + [
            'code' => 'CL-'.strtoupper(uniqid()),
            'manager_id' => $this->mgr->id,
            'rep_id' => $this->rep->id,
        ]);
    }

    public function test_portfolio_page_has_the_four_leads_tabs_and_kpis(): void
    {
        $this->client(['lat' => 30.05, 'lng' => 31.4]);
        $this->client(['rep_id' => null]);

        $res = $this->actingAs($this->mgr)->get(route('ops.portfolio'))->assertOk()
            ->assertSee(route('ops.geo'), false)
            ->assertSee(route('ops.journeys'), false)
            ->assertSee(route('ops.journeys.week'), false)
            ->assertSee('tile.openstreetmap.org', false);

        $stats = $res->viewData('stats');
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['assigned']);
    }

    public function test_menu_has_a_current_clients_group_with_the_same_four_items_as_leads(): void
    {
        $leads = array_column(Access::NAV['nav.group_leads'], 2);
        $routes = array_column(Access::NAV['nav.group_routes'], 2);
        $this->assertSame($leads, $routes);
        $this->assertSame(array_column(Access::NAV['nav.group_leads'], 1), array_column(Access::NAV['nav.group_routes'], 1));
        $this->assertSame(['ops.portfolio', 'ops.geo', 'ops.journeys', 'ops.journeys.week'],
            array_column(Access::NAV['nav.group_routes'], 0));
    }

    public function test_kpi_focus_filters_match_the_card_numbers(): void
    {
        $planned = $this->client();
        $this->client();
        JourneyPlan::create(['user_id' => $this->rep->id, 'client_id' => $planned->id, 'weekday' => 1,
            'every_weeks' => 1, 'sort' => 1, 'active' => true]);

        $all = $this->actingAs($this->mgr)->get(route('ops.portfolio'))->viewData('stats');
        $list = $this->actingAs($this->mgr)->get(route('ops.portfolio', ['focus' => 'planned']))->viewData('clients');

        $this->assertSame(1, $all['planned']);
        $this->assertSame(1, $list->total());
        $this->assertSame($planned->id, $list->items()[0]->id);
    }

    public function test_zone_bulk_assigns_orphans_to_the_rep(): void
    {
        $zone = $this->makeZone();
        $a = $this->client(['rep_id' => null, 'zone_id' => $zone->id, 'balance' => 50]);
        $b = $this->client(['rep_id' => null, 'zone_id' => $zone->id, 'balance' => 10]);

        $this->actingAs($this->mgr)->post(route('ops.portfolio.bulk'), [
            'zone_id' => $zone->id, 'target' => 'r-'.$this->rep->id, 'count' => 1,
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame($this->rep->id, $a->fresh()->rep_id); // الأعلى مديونية الأول
        $this->assertNull($b->fresh()->rep_id);
    }

    public function test_bulk_set_selected_and_all_filtered(): void
    {
        $a = $this->client(['rep_id' => null]);
        $b = $this->client(['rep_id' => null]);
        $mate = $this->makeRep(['manager_id' => $this->mgr->id]);

        $this->actingAs($this->mgr)->post(route('ops.portfolio.bulkset'), [
            'target' => 'r-'.$mate->id, 'ids' => [$a->id],
        ])->assertRedirect();
        $this->assertSame($mate->id, $a->fresh()->rep_id);
        $this->assertNull($b->fresh()->rep_id);

        $this->actingAs($this->mgr)->post(route('ops.portfolio.bulkset'), [
            'target' => 'r-'.$this->rep->id, 'all_filtered' => 1, 'unassigned' => 1,
        ])->assertRedirect();
        $this->assertSame($this->rep->id, $b->fresh()->rep_id);
        $this->assertSame($mate->id, $a->fresh()->rep_id); // مش في الفلتر
    }

    public function test_other_teams_clients_are_skipped_and_other_teams_rep_is_forbidden(): void
    {
        $otherMgr = $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-'.strtoupper(uniqid())]);
        $otherRep = $this->makeRep(['manager_id' => $otherMgr->id]);
        $foreign = $this->makeClient(['code' => 'CL-'.strtoupper(uniqid()), 'manager_id' => $otherMgr->id]);

        $this->actingAs($this->mgr)->post(route('ops.portfolio.bulkset'), [
            'target' => 'r-'.$otherRep->id, 'ids' => [$foreign->id],
        ])->assertForbidden();

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post(route('ops.portfolio.bulkset'), [
            'target' => 'r-'.$this->rep->id, 'ids' => [$foreign->id],
        ])->assertRedirect();
        $this->assertNull($foreign->fresh()->rep_id);
    }

    public function test_denying_field_plan_blocks_bulk_assign(): void
    {
        RolePermission::create(['role' => 'manager', 'perm' => 'act.field.plan', 'allow' => false]);
        RolePermission::flush();
        $a = $this->client(['rep_id' => null]);

        $this->actingAs($this->mgr)->post(route('ops.portfolio.bulkset'), [
            'target' => 'r-'.$this->rep->id, 'ids' => [$a->id],
        ])->assertForbidden();
        $this->assertNull($a->fresh()->rep_id);
    }

    public function test_export_all_downloads_csv(): void
    {
        $this->client(['name_en' => 'Export Shop']);

        $res = $this->actingAs($this->mgr)->get(route('ops.portfolio', ['export' => 1]))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
    }

    public function test_branch_manager_cannot_open_the_portfolio(): void
    {
        $bm = $this->makeAdmin(['role' => 'branch_manager', 'code' => 'BM-'.strtoupper(uniqid())]);
        $this->assertContains($this->actingAs($bm)->get(route('ops.portfolio'))->status(), [302, 403]);
    }

    public function test_leads_and_client_screens_share_the_same_tab_strip(): void
    {
        $admin = $this->makeAdmin();

        foreach (['erp.leads', 'erp.leads.route', 'erp.leads.planner', 'erp.leads.week',
            'ops.portfolio', 'ops.geo', 'ops.journeys', 'ops.journeys.week'] as $r) {
            $this->actingAs($admin)->get(route($r))->assertOk()->assertSee('class="mod-tabs"', false);
        }
    }
}
