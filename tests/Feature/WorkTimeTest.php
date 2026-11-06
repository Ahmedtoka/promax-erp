<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\WorkTime;
use App\Support\Access;
use App\Support\WorkTimeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══ وقت الشغل الفعلي — جاد بس (٥ نوفمبر ٢٠٢٦) ═══
 */
class WorkTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_jad_reaches_the_screen_even_other_admins_get_403(): void
    {
        $jad = $this->makeAdmin(['code' => 'ADM-001']);
        $saad = $this->makeAdmin(['code' => 'ADM-002']);
        $manager = $this->makeAdmin(['code' => 'MGR-'.uniqid(), 'role' => 'manager']);

        $this->actingAs($jad)->get(route('erp.worktime'))->assertOk();
        $this->actingAs($jad)->get(route('erp.worktime.user', $saad))->assertOk();

        foreach ([$saad, $manager] as $u) {
            $this->actingAs($u)->get(route('erp.worktime'))->assertForbidden();
            $this->actingAs($u)->get(route('erp.worktime.user', $jad))->assertForbidden();
            $this->actingAs($u)->postJson(route('erp.worktime.check'))->assertForbidden();
            $this->assertFalse(Access::allows($u, 'erp.worktime'));
        }

        $this->assertTrue(ActivityLog::where('user_id', $saad->id)->where('title', 'worktime_blocked')->exists());

        // اللينك في المنيو لجاد بس — والأدمن التاني مايشوفوش رغم إن الأدمن «بيعدّي كل حاجة»
        $links = fn ($u) => collect(Access::navFor($u))->flatten(1)->pluck(0)->all();
        $this->assertContains('erp.worktime', $links($jad));
        $this->assertNotContains('erp.worktime', $links($saad));
        $this->assertTrue(Access::allows($saad, 'erp.activity'));

        $this->get('/logout');
        auth()->logout();
        $this->get(route('erp.worktime'))->assertRedirect();
    }

    public function test_the_allowed_code_changes_without_a_deploy_and_is_separate_from_the_assistant(): void
    {
        $saad = $this->makeAdmin(['code' => 'ADM-002']);

        Setting::writeMany(['ai_allowed_codes' => 'ADM-001,ADM-002']);
        Setting::flushCache();
        $this->assertFalse(WorkTimeGate::allows($saad), 'السماح بالمساعد فتح المراقبة');

        Setting::writeMany(['worktime_allowed_codes' => 'ADM-002']);
        Setting::flushCache();
        $this->assertTrue(WorkTimeGate::allows($saad->fresh()));
        $this->assertFalse(WorkTimeGate::allows($saad->fresh()->forceFill(['active' => false])));
    }

    public function test_ping_stores_one_row_per_tab_per_minute_and_stays_out_of_the_activity_log(): void
    {
        $u = $this->makeAdmin(['code' => 'ADM-002']);
        $before = ActivityLog::count();

        $this->actingAs($u)->postJson(route('presence.ping'), ['tab' => 'abc123', 'state' => 'idle', 'url' => '/erp/clients'])->assertNoContent();
        $this->actingAs($u)->postJson(route('presence.ping'), ['tab' => 'abc123', 'state' => 'active', 'url' => '/erp/clients'])->assertNoContent();
        $this->actingAs($u)->postJson(route('presence.ping'), ['tab' => 'other1', 'state' => 'hidden', 'url' => '/erp/clients'])->assertNoContent();

        $this->assertSame(2, DB::table('presence_pings')->count());
        $row = DB::table('presence_pings')->where('tab', 'abc123')->first();
        $this->assertSame('active', $row->state);
        $this->assertSame('erp/clients', $row->url);
        $this->assertSame('erp.clients', $row->route);
        $this->assertSame($before, ActivityLog::count(), 'النبضة اتسجلت في مركز النشاط');

        $this->actingAs($u)->postJson(route('presence.ping'), ['tab' => 'x', 'state' => 'sleeping'])->assertStatus(422);
    }

    public function test_three_hours_open_with_a_single_save_is_flagged(): void
    {
        Carbon::setTestNow('2026-11-05 13:00:00');
        $u = $this->makeAdmin(['code' => 'ADM-002', 'name' => 'سعد', 'name_en' => 'Saad W']);
        $start = Carbon::parse('2026-11-05 10:00:00');

        // ساعة ساكت على الشركات الأم، ٥ دقايق شغل، ساعة و٥٤ دقيقة ساكت تاني — زي مثال المالك
        for ($i = 0; $i < 180; $i++) {
            $state = $i >= 60 && $i < 65 ? 'active' : ($i % 3 === 0 ? 'hidden' : 'idle');
            DB::table('presence_pings')->insert(['user_id' => $u->id, 'tab' => 't1', 'state' => $state,
                'route' => 'erp.mother_companies', 'url' => 'erp/mother-companies', 'at' => $start->copy()->addMinutes($i)]);
            // تاب تاني مفتوح في نفس الوقت — مايتحسبش مرتين
            DB::table('presence_pings')->insert(['user_id' => $u->id, 'tab' => 't2', 'state' => 'hidden',
                'route' => 'erp.clients', 'url' => 'erp/clients', 'at' => $start->copy()->addMinutes($i)]);
        }

        ActivityLog::create(['user_id' => $u->id, 'user_name' => 'سعد', 'event' => 'action',
            'title' => 'erp.mother_companies.store', 'route' => 'erp.mother_companies.store', 'url' => 'erp/mother-companies',
            'method' => 'POST', 'status' => 302, 'created_at' => $start->copy()->addMinutes(179)]);

        $a = $start->copy()->startOfDay();
        $b = $start->copy()->endOfDay();
        $sum = WorkTime::summarize(WorkTime::minutes($a, $b)[$u->id], WorkTime::actions($a, $b)[$u->id]);

        $this->assertSame(180, $sum['open']);
        $this->assertSame(5, $sum['active']);
        $this->assertSame(115, $sum['longest_quiet']);
        $this->assertSame(1, $sum['actions']);
        $this->assertContains('open_no_work', $sum['flags']);
        $this->assertContains('long_quiet', $sum['flags']);
        $this->assertContains('low_ratio', $sum['flags']);
        $this->assertSame('watch', $sum['verdict']);

        $jad = $this->makeAdmin(['code' => 'ADM-001']);
        $this->actingAs($jad)->get(route('erp.worktime'))->assertOk()
            ->assertSee('Saad W')->assertSee(__('worktime.v_watch'));
        $this->actingAs($jad)->get(route('erp.worktime.user', ['user' => $u, 'day' => '2026-11-05']))->assertOk()
            ->assertSee(__('worktime.f_open_no_work'));
    }

    public function test_someone_who_did_not_open_shows_in_the_did_not_open_list(): void
    {
        $jad = $this->makeAdmin(['code' => 'ADM-001']);
        $this->makeAdmin(['code' => 'ADM-009', 'name' => 'موظف غايب', 'name_en' => 'Absent Guy']);

        $this->actingAs($jad)->get(route('erp.worktime'))->assertOk()
            ->assertSee(__('worktime.none_title'))->assertSee('Absent Guy');
    }

    public function test_check_sends_only_the_computed_numbers_and_returns_the_summary(): void
    {
        config(['agents.key' => 'test-key', 'agents.model' => 'claude-sonnet-5']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'كله تمام']]])]);

        $jad = $this->makeAdmin(['code' => 'ADM-001']);
        $this->actingAs($jad)->postJson(route('erp.worktime.check'))
            ->assertOk()->assertJson(['ok' => true, 'text' => 'كله تمام']);

        Http::assertSent(function ($req) {
            $body = json_decode($req['messages'][0]['content'], true);

            return isset($body['people']) && ! str_contains($req->body(), 'password');
        });

        config(['agents.key' => '']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($jad)->postJson(route('erp.worktime.check'))->assertOk()->assertJson(['ok' => false]);
    }
}
