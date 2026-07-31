<?php

namespace Tests\Feature;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\JourneyPlan;
use App\Models\Lead;
use App\Models\Visit;
use App\Services\Journeys;
use App\Services\Leads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * العملاء المحتملين + خطط السير
 * ═══════════════════════════════════════════════════════════════
 */
class LeadJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function makeLead(array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'number' => Lead::nextNumber(),
            'name' => 'محل التيست',
            'name_en' => 'Test shop',
            'phone' => '01000000000',
            'status' => 'new',
        ], $attrs));
    }

    // ═══════════════════════ الليدز ═══════════════════════

    public function test_converting_creates_a_client_and_locks_the_lead(): void
    {
        $admin = $this->makeAdmin();
        $zone = $this->makeZone();
        $channel = $this->makeChannel(0.10);
        $rep = $this->makeRep();

        $lead = $this->makeLead([
            'zone_id' => $zone->id,
            'channel_id' => $channel->id,
            'assigned_to' => $rep->id,
            'address' => 'شارع التيست',
        ]);

        $client = Leads::convert($lead, $admin);

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('محل التيست', $client->name);
        $this->assertSame($zone->id, $client->zone_id);
        $this->assertSame($channel->id, $client->channel_id);
        $this->assertSame($rep->id, $client->rep_id, 'المندوب المسؤول بيتنقل للعميل');

        // ⚠️ عميل جديد بياخد خصم قناته، مش خصم خاص
        $this->assertEqualsWithDelta(0.0, (float) $client->discount, 0.0001);
        // ⚠️ العميل المتحوّل من عميل محتمل بيبدأ **من غير خصم** —
        // الشروط بتتفاوض بعد التحويل مش قبله.
        $this->assertEqualsWithDelta(0.0, $client->effectiveDiscount(), 0.0001);
        $this->assertSame('ok', $client->category, 'التصنيف التجاري بيتحدد من الشراء الفعلي');

        $lead = $lead->fresh();
        $this->assertSame('won', $lead->status);
        $this->assertSame($client->id, $lead->client_id);
        $this->assertTrue($lead->isConverted());
    }

    public function test_a_lead_cannot_be_converted_twice(): void
    {
        $admin = $this->makeAdmin();
        $lead = $this->makeLead();

        Leads::convert($lead, $admin);

        $this->expectException(Rejected::class);
        Leads::convert($lead->fresh(), $admin);
    }

    public function test_conversion_is_refused_when_a_client_already_has_the_name(): void
    {
        $admin = $this->makeAdmin();
        $this->makeClient(['name' => 'محل مكرر']);
        $lead = $this->makeLead(['name' => 'محل مكرر']);

        $this->expectException(Rejected::class);
        Leads::convert($lead, $admin);
    }

    public function test_overdue_only_counts_open_leads(): void
    {
        $past = today()->subDays(3)->toDateString();

        $open = $this->makeLead(['status' => 'contacted', 'next_action_on' => $past]);
        $lost = $this->makeLead(['status' => 'lost', 'next_action_on' => $past]);

        $this->assertTrue($open->isOverdue());
        $this->assertFalse($lost->isOverdue(), 'الليد اللي خلص مايبقاش متأخر');
    }

    // ═══════════════════════ خطط السير ═══════════════════════

    public function test_a_plan_only_shows_on_its_own_weekday(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient();

        // الاتنين = 1 في ترقيم Carbon
        JourneyPlan::create([
            'user_id' => $rep->id,
            'client_id' => $client->id,
            'weekday' => 1,
            'every_weeks' => 1,
            'sort' => 1,
        ]);

        $monday = Carbon::parse('2026-08-03');    // الاتنين
        $tuesday = Carbon::parse('2026-08-04');

        $this->assertSame(1, $monday->dayOfWeek, 'التاريخ ده لازم يكون اتنين');
        $this->assertCount(1, Journeys::forDay($rep, $monday));
        $this->assertCount(0, Journeys::forDay($rep, $tuesday));
    }

    public function test_fortnightly_plans_skip_odd_weeks(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient();

        JourneyPlan::create([
            'user_id' => $rep->id,
            'client_id' => $client->id,
            'weekday' => 1,
            'every_weeks' => 2,
            'sort' => 1,
        ]);

        $plan = JourneyPlan::first();

        // أسبوعين متتاليين — واحد بس المفروض يستحق
        $a = Carbon::parse('2026-08-03');
        $b = Carbon::parse('2026-08-10');

        $this->assertNotSame(
            $plan->dueOn($a),
            $plan->dueOn($b),
            'أسبوع ورا أسبوع معناه واحد يستحق والتاني لأ',
        );
    }

    public function test_summary_counts_done_visits_and_off_plan_separately(): void
    {
        $rep = $this->makeRep();
        $planned = $this->makeClient(['name' => 'عميل مخطط']);
        $extra = $this->makeClient(['name' => 'عميل بره الخطة']);

        $day = Carbon::parse('2026-08-03');   // الاتنين

        JourneyPlan::create([
            'user_id' => $rep->id, 'client_id' => $planned->id,
            'weekday' => 1, 'every_weeks' => 1, 'sort' => 1,
        ]);

        // زيارة مقفولة للعميل المخطط
        Visit::create([
            'user_id' => $rep->id,
            'client_id' => $planned->id,
            'checked_in_at' => $day->copy()->setTime(9, 0),
            'checked_out_at' => $day->copy()->setTime(9, 30),
        ])->forceFill(['created_at' => $day->copy()->setTime(9, 0)])->save();

        // زيارة لعميل مش في الخطة
        Visit::create([
            'user_id' => $rep->id,
            'client_id' => $extra->id,
            'checked_in_at' => $day->copy()->setTime(11, 0),
            'checked_out_at' => $day->copy()->setTime(11, 20),
        ])->forceFill(['created_at' => $day->copy()->setTime(11, 0)])->save();

        $summary = Journeys::summary($rep, $day);

        $this->assertSame(1, $summary['planned']);
        $this->assertSame(1, $summary['done']);
        $this->assertSame(0, $summary['pending']);
        $this->assertSame(1, $summary['off_plan'], 'الزيارة بره الخطة لازم تتعد لوحدها');
        $this->assertEqualsWithDelta(100.0, $summary['pct'], 0.01);
    }

    public function test_an_open_visit_counts_as_in_visit_not_done(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient();
        $day = Carbon::parse('2026-08-03');

        JourneyPlan::create([
            'user_id' => $rep->id, 'client_id' => $client->id,
            'weekday' => 1, 'every_weeks' => 1, 'sort' => 1,
        ]);

        Visit::create([
            'user_id' => $rep->id,
            'client_id' => $client->id,
            'checked_in_at' => $day->copy()->setTime(9, 0),
            'checked_out_at' => null,
        ])->forceFill(['created_at' => $day->copy()->setTime(9, 0)])->save();

        $summary = Journeys::summary($rep, $day);

        $this->assertSame(1, $summary['in_visit']);
        $this->assertSame(0, $summary['done'], 'الزيارة المفتوحة لسه مااتعملتش');
    }

    public function test_inactive_plans_are_ignored(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient();

        JourneyPlan::create([
            'user_id' => $rep->id, 'client_id' => $client->id,
            'weekday' => 1, 'every_weeks' => 1, 'sort' => 1, 'active' => false,
        ]);

        $this->assertCount(0, Journeys::forDay($rep, Carbon::parse('2026-08-03')));
    }

    public function test_weekday_numbering_matches_carbon(): void
    {
        // ⚠️ التيست ده بيحرس الافتراض اللي كل الخطط قايمة عليه.
        // لو حد غيّر الترقيم، كل الزيارات هتطلع بيوم غلط.
        $this->assertSame(0, Carbon::parse('2026-08-02')->dayOfWeek, 'الأحد = 0');
        $this->assertSame(6, Carbon::parse('2026-08-08')->dayOfWeek, 'السبت = 6');
        $this->assertSame(JourneyPlan::WEEKDAYS, [0, 1, 2, 3, 4, 5, 6]);
    }
}
