<?php

namespace Tests\Feature;

use App\Models\HandoverMark;
use App\Models\HandoverModule;
use App\Models\HandoverScreen;
use App\Models\User;
use App\Services\Handover;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * أداة تسليم النظام (الإغلاق، الخط ١ — ١٩/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الشركة المستلمة بتعلّم على كل شاشة إنها استلمتها. نسبة الموديول =
 * المعلّم ÷ الكل، والتعليم بيتلغي بسجل، والشاشة اللي اتعدلت بعد
 * التعليم بتاخد علامة من غير ما تتصفّر (spec §3.8، §4.1).
 */
class HandoverTrackerTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════ 1.1 النسبة ═══════════════

    private function module(string $key = 'nav.group_test', int $screens = 2): HandoverModule
    {
        $m = HandoverModule::create([
            'key' => $key, 'name_ar' => 'تجربة', 'name_en' => 'Test', 'sort' => 1,
        ]);

        for ($i = 1; $i <= $screens; $i++) {
            $m->screens()->create([
                'route' => 'erp.test'.$i, 'title_ar' => 'شاشة '.$i, 'title_en' => 'Screen '.$i, 'sort' => $i,
            ]);
        }

        return $m->fresh();
    }

    private function mark(HandoverScreen $s, User $u): HandoverMark
    {
        return $s->marks()->create(['user_id' => $u->id, 'marked_at' => now()]);
    }

    public function test_progress_is_half_when_one_of_two_screens_is_marked(): void
    {
        $m = $this->module();
        $this->mark($m->screens->first(), $this->makeAdmin());

        $this->assertSame(['marked' => 1, 'total' => 2, 'pct' => 50], Handover::moduleProgress($m));
    }

    public function test_unmarked_screen_no_longer_counts_but_keeps_its_log(): void
    {
        $m = $this->module();
        $admin = $this->makeAdmin();
        $mark = $this->mark($m->screens->first(), $admin);

        $mark->update(['unmarked_at' => now(), 'unmarked_by' => $admin->id]);

        $this->assertSame(0, Handover::moduleProgress($m)['pct']);
        $this->assertNull($m->screens->first()->activeMark());
        $this->assertNotNull(HandoverMark::find($mark->id)?->unmarked_at);
    }

    public function test_empty_module_is_zero_not_an_error(): void
    {
        $m = $this->module('nav.group_empty', 0);

        $this->assertSame(['marked' => 0, 'total' => 0, 'pct' => 0], Handover::moduleProgress($m));
        $this->assertSame(['marked' => 0, 'total' => 0, 'pct' => 0], Handover::overall());
    }

    public function test_screen_touched_after_mark_is_stale_but_still_marked(): void
    {
        $m = $this->module();
        $screen = $m->screens->first();
        $this->mark($screen, $this->makeAdmin());

        $screen->update(['touched_at' => now()->addMinute()]);
        $screen->refresh();

        $this->assertTrue($screen->isStale());
        $this->assertSame(50, Handover::moduleProgress($m)['pct']);
        $this->assertSame(['marked' => 1, 'total' => 2, 'pct' => 50], Handover::overall());
    }

    // ═══════════════ 1.2 التوليد من السايدبار + «اتلمست» ═══════════════

    public function test_seed_creates_one_module_per_nav_group_and_one_screen_per_item(): void
    {
        $out = Handover::seedFromNav();

        $items = array_sum(array_map('count', Access::NAV));

        $this->assertSame(count(Access::NAV), HandoverModule::count());
        $this->assertSame($items, HandoverScreen::count());
        $this->assertSame(['modules_added' => count(Access::NAV), 'screens_added' => $items], $out);

        // المفتاح = مفتاح المجموعة، والراوت = اسم راوت البند، والعناوين باللغتين
        $clients = HandoverModule::where('key', 'nav.group_clients')->firstOrFail();
        $this->assertSame('nav.group_clients', $clients->nav_group_key);
        $this->assertSame(__('nav.group_clients', [], 'ar'), $clients->name_ar);
        $this->assertSame(__('nav.group_clients', [], 'en'), $clients->name_en);

        $screen = $clients->screens()->where('route', 'erp.clients')->firstOrFail();
        $this->assertSame(__('nav.clients', [], 'ar'), $screen->title_ar);
        $this->assertSame(__('nav.clients', [], 'en'), $screen->title_en);
    }

    public function test_seed_dry_run_writes_nothing(): void
    {
        $out = Handover::seedFromNav(true);

        $this->assertSame(count(Access::NAV), $out['modules_added']);
        $this->assertSame(0, HandoverModule::count());
        $this->assertSame(0, HandoverScreen::count());
    }

    public function test_seed_twice_adds_nothing(): void
    {
        Handover::seedFromNav();

        $this->assertSame(['modules_added' => 0, 'screens_added' => 0], Handover::seedFromNav());
    }

    public function test_seed_keeps_owner_edits(): void
    {
        Handover::seedFromNav();

        $m = HandoverModule::where('key', 'nav.group_clients')->firstOrFail();
        $m->update(['name_ar' => 'اسم المالك', 'name_en' => 'Owner Name']);
        $s = $m->screens()->firstOrFail();
        $s->update(['title_en' => 'Owner Title']);

        $this->artisan('promax:handover-seed')->assertSuccessful();

        $this->assertSame('اسم المالك', $m->fresh()->name_ar);
        $this->assertSame('Owner Name', $m->fresh()->name_en);
        $this->assertSame('Owner Title', $s->fresh()->title_en);
    }

    public function test_touch_marks_screen_stale(): void
    {
        Handover::seedFromNav();
        $screen = HandoverScreen::where('route', 'erp.clients')->firstOrFail();
        $screen->marks()->create(['user_id' => $this->makeAdmin()->id, 'marked_at' => now()->subMinute()]);

        $this->assertFalse($screen->fresh()->isStale());

        $this->artisan('promax:handover-touch', ['route' => ['erp.clients']])->assertSuccessful();

        $this->assertTrue($screen->fresh()->isStale());
        // التعليم فاضل — العلامة تنبيه بس
        $this->assertNotNull($screen->fresh()->activeMark());
    }

    public function test_touch_fails_when_no_screen_matches(): void
    {
        Handover::seedFromNav();

        $this->artisan('promax:handover-touch', ['route' => ['erp.no_such_screen']])->assertFailed();
    }
}
