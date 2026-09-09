<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\FieldApiController;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «المندوب هيشوف كام عميل؟» (٩/٩/٢٠٢٦) — العدّاد `visibleCounts` لازم
 * يساوي ناتج `zonesPayload` بالحرف، لأن شاشة عملاء المديرين بقت بتعرض
 * العدّاد (كويريتين) بدل ما تشغّل الـpayload الكامل لكل مندوب
 * (كان ٥٢٠٠ كويري و١١ ثانية على داتا اللايف).
 */
class VisibleCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_counter_matches_the_app_payload_for_a_rep_and_a_manager(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
        // ⚠️ `manager_id` مش في fillable اليوزر — forceFill وإلا يتجاهل في صمت
        $rep = $this->makeRep();
        $rep->forceFill(['manager_id' => $manager->id])->save();
        $mate = $this->makeRep();
        $mate->forceFill(['manager_id' => $manager->id])->save();
        $stranger = $this->makeRep();

        $z1 = $this->makeZone();
        $z2 = $this->makeZone();
        $zOff = $this->makeZone();
        $zOff->update(['active' => false]);
        DB::table('zone_user')->insert(['zone_id' => $z1->id, 'user_id' => $rep->id]);

        // عملاءه هو، وعملاء زميله في نفس الفريق (البول)، وعميل موقوف، وعميل في منطقة موقوفة، وعميل فريق تاني
        $this->makeClient(['zone_id' => $z1->id, 'rep_id' => $rep->id, 'manager_id' => $manager->id]);
        $this->makeClient(['zone_id' => $z2->id, 'rep_id' => $mate->id, 'manager_id' => $manager->id]);
        $this->makeClient(['zone_id' => $z1->id, 'rep_id' => $rep->id, 'manager_id' => $manager->id, 'status' => 'pending']);
        $this->makeClient(['zone_id' => $zOff->id, 'rep_id' => $rep->id, 'manager_id' => $manager->id]);
        $this->makeClient(['zone_id' => $z1->id, 'rep_id' => $stranger->id]);
        // عميل بلا مندوب ولا مدير في منطقته — بيظهر له (اليتيم في البول العام)
        $this->makeClient(['zone_id' => $z1->id]);

        foreach ([$rep, $mate, $manager, $stranger] as $user) {
            $payload = FieldApiController::zonesPayload($user);
            $counts = FieldApiController::visibleCounts($user);

            $this->assertSame(count($payload), $counts['zones'], "zones for {$user->role} #{$user->id}");
            $this->assertSame(
                collect($payload)->sum(fn ($z) => count($z['clients'])),
                $counts['clients'],
                "clients for {$user->role} #{$user->id}",
            );
        }

        // العدّاد بيشوف فعلاً حاجة (مش صفر يساوي صفر)
        $this->assertGreaterThan(0, FieldApiController::visibleCounts($rep)['clients']);
    }

    public function test_the_manager_clients_screen_renders_the_counter(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
        $rep = $this->makeRep();
        $rep->forceFill(['manager_id' => $manager->id])->save();
        $zone = $this->makeZone();
        $this->makeClient(['zone_id' => $zone->id, 'rep_id' => $rep->id, 'manager_id' => $manager->id, 'name' => 'عميل العدّاد', 'name_en' => 'Counter client X']);

        $this->actingAs($this->makeAdmin())
            ->get(route('erp.managers.clients', ['manager' => $manager->id]))
            ->assertOk()
            ->assertSee(app()->getLocale() === 'en' ? 'Counter client X' : 'عميل العدّاد')
            ->assertSee($rep->code);
    }
}
