<?php

namespace Tests\Feature;

use App\Http\Controllers\RoutePlannerController;
use App\Models\JourneyPlan;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ═══ راسم خط السير + جدولة الأسبوع + المتابعة للعملاء (١١/١١/٢٠٢٦) ═══
 * السبيك: docs/superpowers/specs/2026-11-11-client-route-planner-design.md
 */
class RoutePlannerTest extends TestCase
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
            'lat' => 30.05, 'lng' => 31.40,
        ]);
    }

    private function plan(User $u, $c, int $wd, int $sort = 1, array $a = []): JourneyPlan
    {
        $p = JourneyPlan::create($a + [
            'user_id' => $u->id, 'client_id' => $c->id, 'weekday' => $wd,
            'every_weeks' => 1, 'sort' => $sort, 'active' => true,
        ]);
        // خطة قديمة — المتابعة مابتحسبش أيام قبل إنشاء الخطة
        JourneyPlan::whereKey($p->id)->update(['created_at' => '2026-01-01 00:00:00']);

        return $p->fresh();
    }

    private function save(array $ids, int $wd = 1, array $extra = [])
    {
        return $this->actingAs($this->mgr)->post(route('ops.geo.save'), $extra + [
            'rep_id' => $this->rep->id, 'weekday' => $wd, 'every_weeks' => 1, 'ids' => $ids,
        ]);
    }

    private function day(int $wd = 1): array
    {
        return JourneyPlan::where('user_id', $this->rep->id)->where('weekday', $wd)
            ->orderBy('sort')->pluck('client_id')->all();
    }

    /** زيارة مقفولة بتاريخ معيّن — `created_at` مش fillable فبالـforceFill */
    private function visitAt($c, string $at): Visit
    {
        $v = new Visit;
        $v->forceFill([
            'user_id' => $this->rep->id, 'client_id' => $c->id,
            'checked_in_at' => $at, 'checked_out_at' => Carbon::parse($at)->addMinutes(15),
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $v->timestamps = false;
        $v->save();

        return $v;
    }

    // ═══════════════════ الراسم ═══════════════════

    public function test_builder_page_loads_with_the_reps_day_preloaded(): void
    {
        $a = $this->client(['name_en' => 'Alpha Shop']);
        $b = $this->client(['name_en' => 'Beta Shop']);
        $this->plan($this->rep, $b, 1, 1);
        $this->plan($this->rep, $a, 1, 2);

        $res = $this->actingAs($this->mgr)->get(route('ops.geo', ['rep' => $this->rep->id, 'day' => 1]))
            ->assertOk()
            ->assertSee('tile.openstreetmap.org', false)
            ->assertDontSee('cartocdn', false);

        $map = $res->viewData('dayMap');
        $this->assertSame([$b->id, $a->id], $map[$this->rep->id][1]);
    }

    public function test_old_geo_url_redirects_to_the_builder(): void
    {
        $this->actingAs($this->mgr)->get('/ops/geo-planner')->assertRedirect('/ops/route-builder');
    }

    public function test_save_makes_the_day_exactly_the_sequence(): void
    {
        [$a, $b, $c] = [$this->client(), $this->client(), $this->client()];
        $this->plan($this->rep, $a, 1, 1, ['every_weeks' => 2, 'visit_at' => '10:00:00']);
        $this->plan($this->rep, $b, 1, 2);

        $this->save([$c->id, $a->id])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame([$c->id, $a->id], $this->day());
        // الموجود بيحتفظ بتردده ووقته
        $kept = JourneyPlan::where('client_id', $a->id)->first();
        $this->assertSame(2, $kept->every_weeks);
        $this->assertSame('10:00', substr((string) $kept->visit_at, 0, 5));
    }

    public function test_new_stops_take_the_bar_frequency_and_first_date_sets_the_day(): void
    {
        $a = $this->client();
        // 2026-11-17 = الثلاثاء (2)
        $this->save([$a->id], 1, ['every_weeks' => 2, 'starts_on' => '2026-11-17', 'visit_at' => '09:30']);

        $p = JourneyPlan::where('client_id', $a->id)->first();
        $this->assertSame(2, $p->weekday);
        $this->assertSame(2, $p->every_weeks);
        $this->assertSame('2026-11-17', $p->starts_on->toDateString());
    }

    public function test_empty_sequence_clears_the_day_only(): void
    {
        [$a, $b] = [$this->client(), $this->client()];
        $this->plan($this->rep, $a, 1);
        $this->plan($this->rep, $b, 3);

        $this->save([], 1)->assertRedirect();

        $this->assertSame([], $this->day(1));
        $this->assertSame([$b->id], $this->day(3));
    }

    public function test_client_on_another_day_keeps_it_and_gains_this_day(): void
    {
        $a = $this->client();
        $this->plan($this->rep, $a, 3);

        $this->save([$a->id], 1);

        $this->assertSame([$a->id], $this->day(1));
        $this->assertSame([$a->id], $this->day(3));
    }

    public function test_same_day_plan_of_a_teammate_moves_to_this_rep(): void
    {
        $mate = $this->makeRep(['manager_id' => $this->mgr->id]);
        $a = $this->client();
        $this->plan($mate, $a, 1);

        $this->save([$a->id])->assertSessionHas('ok');

        $this->assertSame([$a->id], $this->day());
        $this->assertSame(0, JourneyPlan::where('user_id', $mate->id)->count());
    }

    public function test_other_team_client_is_skipped_and_named_not_silent(): void
    {
        $otherMgr = $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-'.strtoupper(uniqid())]);
        $foreign = $this->makeClient([
            'code' => 'CL-'.strtoupper(uniqid()),
            'manager_id' => $otherMgr->id, 'name_en' => 'Foreign Shop',
        ]);
        $mine = $this->client();

        $this->save([$foreign->id, $mine->id])
            ->assertSessionHas('warn', fn ($w) => str_contains($w, 'Foreign Shop'));

        $this->assertSame([$mine->id], $this->day());
    }

    public function test_main_rep_is_filled_only_when_empty(): void
    {
        $orphan = $this->client(['rep_id' => null]);
        $mate = $this->makeRep(['manager_id' => $this->mgr->id]);
        $owned = $this->client(['rep_id' => $mate->id]);

        $this->save([$orphan->id, $owned->id]);

        $this->assertSame($this->rep->id, $orphan->fresh()->rep_id);
        $this->assertSame($mate->id, $owned->fresh()->rep_id);
    }

    public function test_save_for_another_teams_rep_is_forbidden(): void
    {
        $otherMgr = $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-'.strtoupper(uniqid())]);
        $otherRep = $this->makeRep(['manager_id' => $otherMgr->id]);

        $this->actingAs($this->mgr)->post(route('ops.geo.save'), [
            'rep_id' => $otherRep->id, 'weekday' => 1, 'every_weeks' => 1, 'ids' => [],
        ])->assertForbidden();
    }

    public function test_denying_field_plan_blocks_the_save(): void
    {
        RolePermission::create(['role' => 'manager', 'perm' => 'act.field.plan', 'allow' => false]);
        RolePermission::flush();
        $a = $this->client();

        $this->save([$a->id])->assertForbidden();
        $this->assertSame(0, JourneyPlan::count());
    }

    // ═══════════════════ البورد ═══════════════════

    public function test_board_sync_writes_frequency_first_date_and_time(): void
    {
        $a = $this->client();
        $p = $this->plan($this->rep, $a, 1);

        $this->actingAs($this->mgr)->post(route('ops.journeys.sync'), [
            'user_id' => $this->rep->id,
            'rows' => [[
                'id' => $p->id, 'client_id' => $a->id, 'weekday' => 1, 'sort' => 1,
                'every_weeks' => 4, 'starts_on' => '2026-11-18', 'visit_at' => '11:15',
            ]],
        ])->assertRedirect();

        $p->refresh();
        $this->assertSame(4, $p->every_weeks);
        $this->assertSame(3, $p->weekday); // 18/11 = الأربع
        $this->assertSame('11:15', substr((string) $p->visit_at, 0, 5));
    }

    public function test_board_sync_without_schedule_fields_keeps_them(): void
    {
        $a = $this->client();
        $p = $this->plan($this->rep, $a, 1, 1, ['every_weeks' => 2, 'visit_at' => '08:00:00']);

        $this->actingAs($this->mgr)->post(route('ops.journeys.sync'), [
            'user_id' => $this->rep->id,
            'rows' => [['id' => $p->id, 'client_id' => $a->id, 'weekday' => 2, 'sort' => 1]],
        ])->assertRedirect();

        $p->refresh();
        $this->assertSame(2, $p->every_weeks);
        $this->assertSame(2, $p->weekday);
        $this->assertSame('08:00', substr((string) $p->visit_at, 0, 5));
    }

    public function test_board_page_marks_off_week_stops_not_due(): void
    {
        $a = $this->client();
        // كل أسبوعين، مرساة الأسبوع اللي فيه 2026-11-16 (الاتنين)
        $this->plan($this->rep, $a, 1, 1, ['every_weeks' => 2, 'starts_on' => '2026-11-16']);

        $due = $this->actingAs($this->mgr)
            ->get(route('ops.journeys', ['rep' => $this->rep->id, 'week' => '2026-11-16']))
            ->assertOk()->viewData('board')['plans'][0]['due'];
        $notDue = $this->actingAs($this->mgr)
            ->get(route('ops.journeys', ['rep' => $this->rep->id, 'week' => '2026-11-23']))
            ->assertOk()->viewData('board')['plans'][0]['due'];

        $this->assertTrue($due);
        $this->assertFalse($notDue);
    }

    public function test_board_week_starts_on_saturday(): void
    {
        $start = $this->actingAs($this->mgr)
            ->get(route('ops.journeys', ['rep' => $this->rep->id, 'week' => '2026-11-18']))
            ->assertOk()->viewData('weekStart');

        $this->assertSame('2026-11-14', $start->toDateString());
        $this->assertSame(Carbon::SATURDAY, $start->dayOfWeek);
    }

    // ═══════════════════ المتابعة ═══════════════════

    public function test_follow_counts_planned_visited_missed_and_off_plan(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00'); // الأربع
        [$a, $b, $c] = [$this->client(), $this->client(), $this->client()];
        $this->plan($this->rep, $a, 0); // الأحد 15/11 — اتزار
        $this->plan($this->rep, $b, 1); // الاتنين 16/11 — فات
        $this->plan($this->rep, $a, 4); // الخميس 19/11 — لسه

        $this->visitAt($a, '2026-11-15 10:00:00');
        // زيارة لعميل مش مستحق = بره الخطة
        $this->visitAt($c, '2026-11-16 11:00:00');

        $days = collect(range(0, 6))->map(fn ($i) => Carbon::parse('2026-11-14')->addDays($i));
        $card = RoutePlannerController::follow(collect([$this->rep]), $days)->first();

        $this->assertSame(3, $card['planned']);
        $this->assertSame(1, $card['done']);
        $this->assertSame(1, $card['missed']);
        $this->assertSame(1, $card['off']);
        $this->assertSame('pending', $card['grid']['2026-11-19']['items'][0]['state']);

        $this->actingAs($this->mgr)->get(route('ops.journeys.week', ['week' => '2026-11-14']))
            ->assertOk()->assertSee($this->rep->displayName());

        Carbon::setTestNow();
    }

    public function test_follow_skips_fortnightly_stop_in_its_off_week(): void
    {
        Carbon::setTestNow('2026-11-30 12:00:00');
        $a = $this->client();
        $this->plan($this->rep, $a, 1, 1, ['every_weeks' => 2, 'starts_on' => '2026-11-16']);

        $off = collect(range(0, 6))->map(fn ($i) => Carbon::parse('2026-11-21')->addDays($i));
        $on = collect(range(0, 6))->map(fn ($i) => Carbon::parse('2026-11-14')->addDays($i));

        $this->assertSame(0, RoutePlannerController::follow(collect([$this->rep]), $off)->first()['planned']);
        $this->assertSame(1, RoutePlannerController::follow(collect([$this->rep]), $on)->first()['missed']);

        Carbon::setTestNow();
    }

    public function test_follow_does_not_count_days_before_the_plan_existed(): void
    {
        Carbon::setTestNow('2026-11-18 12:00:00');
        $a = $this->client();
        JourneyPlan::create(['user_id' => $this->rep->id, 'client_id' => $a->id, 'weekday' => 1,
            'every_weeks' => 1, 'sort' => 1, 'active' => true]); // اتعملت النهارده

        $days = collect(range(0, 6))->map(fn ($i) => Carbon::parse('2026-11-14')->addDays($i));
        $card = RoutePlannerController::follow(collect([$this->rep]), $days)->first();

        $this->assertSame(0, $card['planned']);
        $this->assertSame(0, $card['missed']);

        Carbon::setTestNow();
    }

    public function test_week_page_lists_only_the_managers_team(): void
    {
        $otherMgr = $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-'.strtoupper(uniqid())]);
        $otherRep = $this->makeRep(['manager_id' => $otherMgr->id, 'name_en' => 'Outsider Rep']);

        $reps = $this->actingAs($this->mgr)->get(route('ops.journeys.week'))->assertOk()->viewData('reps');

        $this->assertTrue($reps->contains('id', $this->rep->id));
        $this->assertFalse($reps->contains('id', $otherRep->id));
    }

    public function test_branch_manager_cannot_open_the_planner_screens(): void
    {
        $bm = $this->makeAdmin(['role' => 'branch_manager', 'code' => 'BM-'.strtoupper(uniqid())]);

        foreach (['ops.geo', 'ops.journeys', 'ops.journeys.week'] as $r) {
            $this->assertContains($this->actingAs($bm)->get(route($r))->status(), [302, 403], $r);
        }
    }

    // ═══════════════════ ربط الزيارة بالخطة ═══════════════════

    public function test_check_in_links_the_plan_only_when_due(): void
    {
        $a = $this->client();
        $wd = today()->dayOfWeek;
        // كل أسبوعين ومرساته الأسبوع اللي فات = النهارده أسبوع إجازته
        $off = $this->plan($this->rep, $a, $wd, 1, [
            'every_weeks' => 2, 'starts_on' => today()->subWeek()->toDateString(),
        ]);
        $this->assertFalse($off->dueOn(today()));

        $this->punchIn($this->rep);
        $h = $this->tokenFor($this->rep);

        $id = $this->withHeaders($h)->postJson('/api/visits/check-in', ['client_id' => $a->id])
            ->assertOk()->json('visit_id');
        $this->assertNull(Visit::find($id)->journey_plan_id);
        Visit::whereKey($id)->update(['checked_out_at' => now()]);

        // نفس العميل أسبوعي = مستحق → بيتربط
        $off->update(['every_weeks' => 1, 'starts_on' => null]);
        $id2 = $this->withHeaders($h)->postJson('/api/visits/check-in', ['client_id' => $a->id])
            ->assertOk()->json('visit_id');
        $this->assertSame($off->id, Visit::find($id2)->journey_plan_id);
    }
}
