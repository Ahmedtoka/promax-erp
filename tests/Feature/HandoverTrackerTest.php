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

        // 📖 الدليل (٢١/١٠) مجموعة منيو مش موديول تسليم
        $nav = array_diff_key(Access::NAV, array_flip(Handover::NOT_HANDOVER));
        $items = array_sum(array_map('count', $nav));

        $this->assertSame(count($nav), HandoverModule::count());
        $this->assertSame($items, HandoverScreen::count());
        $this->assertSame(['modules_added' => count($nav), 'screens_added' => $items], $out);
        $this->assertFalse(HandoverModule::where('key', 'nav.group_docs')->exists());

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

        $this->assertSame(count(Access::NAV) - count(Handover::NOT_HANDOVER), $out['modules_added']);
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

    // ═══════════════ 1.3 الرول والشاشات والتصدير ═══════════════

    private function reviewer(): User
    {
        return $this->makeAdmin(['role' => 'handover_reviewer', 'email' => 'rev'.uniqid().'@promax.test']);
    }

    public function test_reviewer_can_mark_and_unmark_a_screen(): void
    {
        $m = $this->module();
        $screen = $m->screens->first();
        $rev = $this->reviewer();

        $this->actingAs($rev)->post(route('handover.index.mark', $screen), ['note' => 'تمام'])->assertRedirect();

        $mark = HandoverMark::where('screen_id', $screen->id)->firstOrFail();
        $this->assertSame($rev->id, $mark->user_id);
        $this->assertSame('تمام', $mark->note);
        $this->assertNull($mark->unmarked_at);

        // تعليم تاني على شاشة معلّمة مابيعملش صف تاني
        $this->actingAs($rev)->post(route('handover.index.mark', $screen))->assertRedirect();
        $this->assertSame(1, HandoverMark::where('screen_id', $screen->id)->count());

        $this->actingAs($rev)->post(route('handover.index.unmark', $screen))->assertRedirect();

        $mark->refresh();
        $this->assertNotNull($mark->unmarked_at);
        $this->assertSame($rev->id, $mark->unmarked_by);
        $this->assertSame(0, Handover::moduleProgress($m)['pct']);
    }

    public function test_mark_all_marks_every_screen_and_module_is_100(): void
    {
        $m = $this->module('nav.group_all', 3);
        $rev = $this->reviewer();

        $this->mark($m->screens->first(), $rev);   // معلّمة قبل كده — مابتتكررش

        $this->actingAs($rev)->post(route('handover.index.mark_all', $m))->assertRedirect();

        $this->assertSame(['marked' => 3, 'total' => 3, 'pct' => 100], Handover::moduleProgress($m));
        $this->assertSame(3, HandoverMark::count());

        $this->actingAs($rev)->post(route('handover.index.unmark_all', $m))->assertRedirect();

        $this->assertSame(0, Handover::moduleProgress($m)['pct']);
        $this->assertSame(3, HandoverMark::whereNotNull('unmarked_at')->count());
    }

    public function test_reviewer_cannot_edit_meta(): void
    {
        $m = $this->module();

        $this->actingAs($this->reviewer())->post(route('handover.index.meta', $m), [
            'test_status' => 'done', 'live_status' => 'yes', 'deadline' => '2026-11-01',
        ])->assertForbidden();

        $this->assertSame('pending', $m->fresh()->test_status);
        $this->assertNull($m->fresh()->deadline);
    }

    public function test_reviewer_gets_403_on_other_screens(): void
    {
        $rev = $this->reviewer();

        foreach (['/erp', '/ops/invoices', '/gl/accounts',
            route('erp.clients'), route('wh.index'), route('erp.perms'), route('notifications.latest')] as $path) {
            $this->actingAs($rev)->get($path)->assertForbidden();
        }

        // والسايدبار بتاعه فيه مجموعة التسليم بس
        $this->assertSame(['nav.group_handover'], array_keys(Access::navFor($rev)));
        $this->assertSame('handover.index', Access::home($rev));
    }

    public function test_reviewer_opens_the_handover_screens(): void
    {
        $m = $this->module();
        $screen = $m->screens->first();
        $this->mark($screen, $this->makeAdmin());
        $screen->update(['touched_at' => now()->addMinute()]);
        $rev = $this->reviewer();

        $this->actingAs($rev)->get(route('handover.index'))->assertOk()->assertSee($m->name_en);
        $this->actingAs($rev)->get(route('handover.index.show', $m))->assertOk()
            ->assertSee($screen->title_en)
            ->assertSee(__('handover.stale'))
            // فورم الإعدادات للأدمن بس
            ->assertDontSee(route('handover.index.meta', $m));
        $this->actingAs($rev)->get(route('handover.index.log'))->assertOk();
    }

    /**
     * ⚠️ مستلم النظام مالوش إشعارات — اللياوت مابيرسمش الجرس ولا بيبولّ
     * `notifications.latest` (كان هيضرب 403 كل ٢٥ ثانية). زحف `RoleAccessTest`
     * مابيمسكهاش لأن `@js()` بيهرّب الـ`/` في الـURL.
     */
    public function test_reviewer_layout_has_no_notification_bell_or_poll(): void
    {
        $html = $this->actingAs($this->reviewer())->get(route('handover.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('pmxToasts', $html);
        $this->assertStringNotContainsString('notifications\/latest', $html);
        $this->assertStringNotContainsString('class="bell"', $html);

        // وباقي الرولز لسه شايفينه
        $admin = $this->actingAs($this->makeAdmin())->get(route('handover.index'))->getContent();
        $this->assertStringContainsString('pmxToasts', $admin);
    }

    public function test_other_roles_cannot_open_handover(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);

        $this->actingAs($manager)->get(route('handover.index'))->assertForbidden();
    }

    public function test_admin_sets_deadline_and_live_status(): void
    {
        $m = $this->module();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('handover.index.show', $m))->assertOk()
            ->assertSee(route('handover.index.meta', $m));

        $this->actingAs($admin)->post(route('handover.index.meta', $m), [
            'test_status' => 'in_progress', 'live_status' => 'partial',
            'deadline' => '2026-11-15', 'notes' => 'بعد التدريب',
        ])->assertRedirect();

        $m->refresh();
        $this->assertSame('in_progress', $m->test_status);
        $this->assertSame('partial', $m->live_status);
        $this->assertSame('2026-11-15', $m->deadline->toDateString());
        $this->assertSame('بعد التدريب', $m->notes);

        // قيمة برّه القايمة بترفض
        $this->actingAs($admin)->post(route('handover.index.meta', $m), ['test_status' => 'x', 'live_status' => 'yes'])
            ->assertSessionHasErrors('test_status');
    }

    public function test_export_lists_every_module_with_pct(): void
    {
        $a = $this->module('nav.group_a', 2);
        $b = $this->module('nav.group_b', 4);
        $this->mark($a->screens->first(), $this->makeAdmin());

        $res = $this->actingAs($this->reviewer())->get(route('handover.index.export'));
        $res->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('Test,2,1,50', $csv);
        $this->assertStringContainsString('Test,4,0,0', $csv);
    }

    public function test_marks_log_filters_by_marked_at(): void
    {
        $m = $this->module();
        $admin = $this->makeAdmin();
        [$s1, $s2] = [$m->screens[0], $m->screens[1]];
        $s1->marks()->create(['user_id' => $admin->id, 'marked_at' => '2026-10-01 10:00:00', 'note' => 'OLD-NOTE']);
        $s2->marks()->create(['user_id' => $admin->id, 'marked_at' => '2026-10-18 10:00:00', 'note' => 'NEW-NOTE']);

        $this->actingAs($admin)->get(route('handover.index.log', ['from' => '2026-10-15', 'to' => '2026-10-19']))
            ->assertOk()->assertSee('NEW-NOTE')->assertDontSee('OLD-NOTE');

        $csv = $this->actingAs($admin)
            ->get(route('handover.index.log', ['from' => '2026-10-15', 'to' => '2026-10-19', 'export' => 1]))
            ->streamedContent();
        $this->assertStringContainsString('NEW-NOTE', $csv);
        $this->assertStringNotContainsString('OLD-NOTE', $csv);
    }

    /**
     * ⚠️ الراوتس الفرعية تحت `handover.index.*` — إخفاء الصفحة أو المجموعة
     * من شاشة الصلاحيات بيقفل الموديول والسجل والتصدير والتعليم كمان، مش
     * الإندكس بس (كانت `handover.show`/`handover.mark` بره بادئة بند المنيو).
     */
    public function test_hiding_the_page_or_group_closes_every_handover_route(): void
    {
        $m = $this->module();
        $screen = $m->screens->first();

        foreach (['handover.index', 'nav.group_handover'] as $perm) {
            $rev = $this->reviewer();
            $rev->permissions()->create(['perm' => $perm, 'allow' => false]);

            $this->actingAs($rev)->get(route('handover.index'))->assertForbidden();
            $this->actingAs($rev)->get(route('handover.index.show', $m))->assertForbidden();
            $this->actingAs($rev)->get(route('handover.index.log'))->assertForbidden();
            $this->actingAs($rev)->get(route('handover.index.export'))->assertForbidden();
            $this->actingAs($rev)->post(route('handover.index.mark', $screen))->assertForbidden();
            $this->actingAs($rev)->post(route('handover.index.unmark', $screen))->assertForbidden();
            $this->actingAs($rev)->post(route('handover.index.mark_all', $m))->assertForbidden();
            $this->actingAs($rev)->post(route('handover.index.unmark_all', $m))->assertForbidden();
        }

        $this->assertSame(0, HandoverMark::count());
    }

    /** منح الصفحة لرول تاني بيفتح الموديول والسجل — والـmeta لسه للأدمن بس */
    public function test_granting_the_page_opens_its_sub_routes_but_not_meta(): void
    {
        $m = $this->module();
        $acc = $this->makeAdmin(['role' => 'accountant', 'email' => 'acc'.uniqid().'@promax.test']);
        $acc->permissions()->create(['perm' => 'handover.index', 'allow' => true]);

        $this->actingAs($acc)->get(route('handover.index.show', $m))->assertOk()
            ->assertDontSee(route('handover.index.meta', $m));
        $this->actingAs($acc)->get(route('handover.index.log'))->assertOk();
        $this->actingAs($acc)->post(route('handover.index.mark', $m->screens->first()))->assertRedirect();
        $this->actingAs($acc)->post(route('handover.index.meta', $m), [
            'test_status' => 'done', 'live_status' => 'yes',
        ])->assertForbidden();

        $this->assertSame('pending', $m->fresh()->test_status);
    }
}
