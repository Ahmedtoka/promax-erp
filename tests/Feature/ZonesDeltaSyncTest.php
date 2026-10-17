<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\FieldApiController;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/zones?updated_since= (١٧/١٠/٢٠٢٦) — من غير البارامتر نفس مفتاح
 * `zones` بتاع البوت ستراب بالحرف، وبيه اللي اتغيّر بس + كل الـids عشان الحذف.
 */
class ZonesDeltaSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    private User $stranger;

    private array $c = [];

    protected function setUp(): void
    {
        parent::setUp();
        // نص النهار — عشان «قبل النهارده = رد كامل» مايتلخبطش بنص الليل
        $this->travelTo(today()->setTime(10, 0));

        $manager = User::factory()->create(['role' => 'manager', 'active' => true]);
        $this->rep = $this->makeRep();
        $this->rep->forceFill(['manager_id' => $manager->id])->save();
        $this->stranger = $this->makeRep();

        $z1 = $this->makeZone();
        $z2 = $this->makeZone();
        DB::table('zone_user')->insert(['zone_id' => $z1->id, 'user_id' => $this->rep->id]);

        $this->c['a'] = $this->makeClient(['zone_id' => $z1->id, 'rep_id' => $this->rep->id, 'manager_id' => $manager->id]);
        $this->c['b'] = $this->makeClient(['zone_id' => $z1->id, 'rep_id' => $this->rep->id, 'manager_id' => $manager->id]);
        $this->c['c'] = $this->makeClient(['zone_id' => $z2->id, 'rep_id' => $this->rep->id, 'manager_id' => $manager->id]);
    }

    private function zones(?string $since = null)
    {
        return $this->withHeaders($this->tokenFor($this->rep))
            ->getJson('/api/zones'.($since === null ? '' : '?updated_since='.urlencode($since)));
    }

    /** @return list<int> */
    private function clientIdsIn(array $zones): array
    {
        return collect($zones)->flatMap(fn ($z) => collect($z['clients'])->pluck('id'))->sort()->values()->all();
    }

    public function test_without_the_param_it_is_the_bootstrap_zones_byte_for_byte(): void
    {
        $res = $this->zones()->assertOk()->assertHeader('X-Server-Time');

        $this->assertSame(json_encode(['zones' => FieldApiController::zonesPayload($this->rep)]), $res->getContent());

        $boot = $this->withHeaders($this->tokenFor($this->rep))->getJson('/api/bootstrap')->assertOk();
        $this->assertSame($boot->json('zones'), $res->json('zones'));
        $this->assertCount(3, $this->clientIdsIn($res->json('zones')));
    }

    public function test_only_what_changed_since_comes_back_and_the_ids_cover_removals(): void
    {
        // الفيكستشر نفسه (العملاء والإعدادات) اتكتب الساعة 10:00 بالظبط
        $this->travel(1)->minutes();
        $since = now()->toIso8601String();
        $this->travel(5)->minutes();

        // ماحصلش حاجة: مفيش عملاء، بس الـids كاملة
        $res = $this->zones($since)->assertOk()->assertJsonPath('full', false);
        $this->assertSame([], $res->json('zones'));
        $this->assertSame(collect($this->c)->pluck('id')->sort()->values()->all(), $res->json('client_ids'));
        $this->assertCount(2, $res->json('zone_ids'));

        // عميل اتعدّل + زيارة على عميل تاني
        $this->c['a']->update(['phone' => '01000000000']);
        Visit::create(['user_id' => $this->rep->id, 'client_id' => $this->c['c']->id, 'checked_in_at' => now()]);

        $res = $this->zones($since)->assertOk()->assertJsonPath('full', false);
        $this->assertSame([$this->c['a']->id, $this->c['c']->id], $this->clientIdsIn($res->json('zones')));
        $this->assertSame('01000000000', collect($res->json('zones'))->flatMap(fn ($z) => $z['clients'])
            ->firstWhere('id', $this->c['a']->id)['phone']);
        // نفس شكل العميل اللي في الرد الكامل بالحرف
        $fullA = collect(FieldApiController::zonesPayload($this->rep))->flatMap(fn ($z) => $z['clients'])
            ->firstWhere('id', $this->c['a']->id);
        $this->assertSame(json_decode(json_encode($fullA), true), collect($res->json('zones'))->flatMap(fn ($z) => $z['clients'])
            ->firstWhere('id', $this->c['a']->id));

        // عميل اتنقل لمندوب فريق تاني: مش في الـids فالأبلكيشن يشيله
        $this->c['b']->update(['rep_id' => $this->stranger->id, 'manager_id' => null]);
        $res = $this->zones($since)->assertOk();
        $this->assertNotContains($this->c['b']->id, $res->json('client_ids'));
        $this->assertNotContains($this->c['b']->id, $this->clientIdsIn($res->json('zones')));
    }

    public function test_a_since_before_today_or_after_a_settings_change_is_a_full_reply(): void
    {
        $res = $this->zones(now()->subDay()->toIso8601String())->assertOk()->assertJsonPath('full', true);
        $this->assertCount(3, $this->clientIdsIn($res->json('zones')));

        $since = now()->toIso8601String();
        $this->travel(1)->minutes();
        \App\Models\Setting::write('tax_enabled', '1');
        $res = $this->zones($since)->assertOk()->assertJsonPath('full', true);
        $this->assertCount(3, $this->clientIdsIn($res->json('zones')));
    }

    public function test_a_bad_date_is_a_422_with_a_message(): void
    {
        $this->zones('not-a-date')->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_roles_without_zones_get_empty_lists(): void
    {
        $driver = User::factory()->create(['role' => 'driver', 'active' => true]);
        $this->withHeaders($this->tokenFor($driver))->getJson('/api/zones')
            ->assertOk()->assertExactJson(['zones' => []]);
    }
}
