<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\PriceList;
use App\Models\Setting;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أمر تنظيف ٨/٩/٢٠٢٦ — معاينة من غير كتابة، تنفيذ idempotent، وحارس تكرار.
 *
 * ⚠️ الأمر بيطابق بالكود وبالـid مع فحص الاسم، فالفيكستشر بيعمل الصفوف
 * بنفس الأكواد والـids والأسماء اللي على اللايف.
 */
class CleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $rep = $this->makeRep();
        $cashVan = PriceList::forceCreate(['id' => 3, 'code' => 'CashVan', 'name' => 'كاش قان', 'name_en' => 'Cash van', 'active' => true, 'is_default' => false]);
        $zayed = Zone::forceCreate(['id' => 82, 'code' => 'Z-82', 'name' => 'الشيخ زايد', 'name_en' => 'Sheikh Zayed', 'active' => true]);
        $october = Zone::forceCreate(['id' => 6, 'code' => 'Z-6', 'name' => 'السادس من أكتوبر', 'name_en' => '6th of October', 'active' => true]);
        $dupOctober = Zone::forceCreate(['id' => 680, 'code' => 'Z-680', 'name' => '٦ أكتوبر', 'name_en' => '6 October', 'active' => true]);
        DB::table('zone_user')->insert(['zone_id' => $dupOctober->id, 'user_id' => $rep->id]);

        $cl71 = $this->makeClient(['code' => 'CL-71', 'name' => 'كيو ماركت الشيخ زايد', 'rep_id' => $rep->id, 'price_list' => 'new']);
        $cl71->forceFill(['price_list_id' => null, 'zone_id' => null])->save();

        $caribou = ClientGroup::forceCreate(['id' => 29, 'code' => 'CRB', 'name' => 'كاريبو', 'name_en' => 'Caribou', 'channel_id' => 3, 'active' => true]);
        $branch = $this->makeClient(['code' => 'CRB-02', 'name' => 'كاريبو - القصر العيني', 'group_id' => $caribou->id, 'price_list_id' => $cashVan->id]);
        $branch->forceFill(['channel_id' => null])->save();

        $keep = $this->makeClient(['code' => 'CL-58', 'name' => 'ديلي مارت الدقي', 'price_list_id' => $cashVan->id]);
        $keep->forceFill(['channel_id' => null])->save();
        $dupe = $this->makeClient(['code' => 'CL-59', 'name' => 'ديلي مارت الدقي', 'price_list_id' => $cashVan->id]);
        $dupe->forceFill(['channel_id' => null])->save();

        $inDup = $this->makeClient(['code' => 'CL-900', 'name' => 'عميل في المكرر', 'zone_id' => $dupOctober->id, 'rep_id' => $rep->id, 'price_list_id' => $cashVan->id]);

        return compact('rep', 'cl71', 'branch', 'keep', 'dupe', 'inDup', 'dupOctober', 'october');
    }

    public function test_preview_changes_nothing(): void
    {
        $w = $this->world();

        $this->artisan('promax:cleanup-2026-09-08')->assertSuccessful();

        $this->assertNull($w['cl71']->fresh()->price_list_id);
        $this->assertNull($w['branch']->fresh()->channel_id);
        $this->assertSame('active', $w['dupe']->fresh()->status);
        $this->assertTrue((bool) $w['dupOctober']->fresh()->active);
        $this->assertNull(Setting::read('cleanup_2026_09_08_done'));
    }

    public function test_apply_fixes_every_item_and_is_guarded_against_a_second_run(): void
    {
        $w = $this->world();

        $this->artisan('promax:cleanup-2026-09-08 --apply')->assertSuccessful();

        // أ. القايمة والزون + التغطية
        $cl71 = $w['cl71']->fresh();
        $this->assertSame(3, (int) $cl71->price_list_id);
        $this->assertSame(82, (int) $cl71->zone_id);
        $this->assertTrue(DB::table('zone_user')->where('zone_id', 82)->where('user_id', $w['rep']->id)->exists(),
            'Coverage::sync لازم يعلّم الزون الجديد للمندوب');

        // ب. القنوات ومكرر ديلي مارت
        $this->assertSame(3, (int) $w['branch']->fresh()->channel_id, 'فرع كاريبو يورث قناة السلسلة');
        $this->assertSame(3, (int) $w['keep']->fresh()->channel_id);
        $this->assertSame('pending', $w['dupe']->fresh()->status);
        $this->assertStringContainsString('CL-58', (string) $w['dupe']->fresh()->notes);

        // ج. الدمج
        $this->assertSame(6, (int) $w['inDup']->fresh()->zone_id);
        $this->assertFalse((bool) $w['dupOctober']->fresh()->active);
        $this->assertSame(0, DB::table('zone_user')->where('zone_id', 680)->count());
        $this->assertTrue(DB::table('zone_user')->where('zone_id', 6)->where('user_id', $w['rep']->id)->exists());

        $this->assertNotNull(Setting::read('cleanup_2026_09_08_done'));

        // الحارس: تشغيل تاني بلا --force يرفض؛ بـ--force يعدّي من غير أي تغيير
        Setting::flushCache();
        $this->artisan('promax:cleanup-2026-09-08 --apply')->assertFailed();
        $this->artisan('promax:cleanup-2026-09-08 --apply --force')->assertSuccessful();
        $this->assertSame(6, (int) $w['inDup']->fresh()->zone_id);
    }

    /** مكرر عليه حركة مايتقفلش أوتوماتيك — ده عميل حقيقي */
    public function test_a_duplicate_with_a_balance_is_left_alone(): void
    {
        $w = $this->world();
        $w['dupe']->forceFill(['balance' => 120])->save();

        $this->artisan('promax:cleanup-2026-09-08 --apply')->assertSuccessful();

        $this->assertSame('active', $w['dupe']->fresh()->status);
    }
}
