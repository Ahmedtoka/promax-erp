<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Lead;
use App\Models\Zone;
use App\Services\Importers\LeadImporter;
use App\Support\LeadScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * استيراد العملاء المحتملين من الأدلة الخارجية
 * ═══════════════════════════════════════════════════════════════
 *
 * التيستات دي بتحرس التلات حاجات اللي لو وقعت، الموديول كله بيبقى
 * ضرر بدل ما يبقى نفع:
 *
 *   ١. **الديدوب** — لو وقع، المندوب بيكلّم نفس المحل تلات مرات،
 *      والمحلات اللي هي أصلاً عملاء بتظهر كفرص جديدة.
 *   ٢. **الترتيب** — لو السكور وقع، القايمة بتبقى ٢٠٠٠ اسم بلا
 *      أولوية ومحدش بيشتغل عليها.
 *   ٣. **الليد ما يبقاش عميل** — الاستيراد ممنوع يلمس `clients`.
 */
class LeadImportTest extends TestCase
{
    use RefreshDatabase;

    private function rows(array ...$rows): array
    {
        $blank = [
            'name' => null, 'category' => null, 'phone' => null, 'address' => null,
            'city' => null, 'governorate' => null, 'lat' => null, 'lng' => null,
            'rating' => null, 'reviews' => null, 'external_id' => null,
            'website' => null, 'contact_name' => null, 'closed' => null,
            'expected_monthly' => null, 'notes' => null,
        ];

        return array_map(fn ($r) => array_merge($blank, $r), $rows);
    }

    // ═══════════════════════ التصنيف والسكور ═══════════════════════

    public function test_gyms_outrank_cafes_which_outrank_plain_groceries(): void
    {
        // نفس التقييم ونفس عدد الريفيوهات — الفرق في النشاط بس،
        // عشان نتأكد إن الترتيب اللي المالك طلبه هو اللي بيطلع.
        $gym = LeadScore::compute('Gym', 'Gold Gym', 4.5, 500);
        $cafe = LeadScore::compute('Coffee shop', 'Beans', 4.5, 500);
        $grocery = LeadScore::compute('Grocery store', 'Baqala', 4.5, 500);

        $this->assertGreaterThan($cafe, $gym);
        $this->assertGreaterThan($grocery, $cafe);
    }

    public function test_a_big_cafe_beats_a_tiny_one_with_a_perfect_rating(): void
    {
        // ⚠️ ده الفخ اللي التصميم كله اتعمل عشانه: مكان بـ٥.٠٠ من
        // ٣ ريفيوهات ماينفعش يسبق مكان بـ٤.٢ من ٤٠٠٠.
        $big = LeadScore::compute('Coffee shop', 'Big', 4.2, 4000);
        $tiny = LeadScore::compute('Coffee shop', 'Tiny', 5.0, 3);

        $this->assertGreaterThan($tiny, $big);
    }

    public function test_hypermarkets_go_to_key_account_not_cash_van(): void
    {
        $this->assertSame(Channel::KEY_ACCOUNT, LeadScore::channel('Hypermarket', 'Carrefour City'));
        $this->assertSame('chain', LeadScore::subChannel('Hypermarket', 'Carrefour City'));

        // والسوبرماركت المستقل بيفضل كاش فان
        $this->assertSame(Channel::CASH_VAN, LeadScore::channel('Supermarket', 'Nour Market'));
    }

    public function test_a_positive_match_wins_over_a_rejected_word(): void
    {
        // «Juice Bar» فيها كلمة bar المرفوضة، بس النشاط جيم/عصير
        $this->assertNotNull(LeadScore::match('Juice shop', 'Gym & Juice Bar'));

        // و«Barista» مالهاش علاقة بـ bar — الرفض بكلمة كاملة
        $this->assertFalse(LeadScore::rejected('Coffee shop', 'Barista House'));

        // وده مرفوض فعلاً
        $this->assertTrue(LeadScore::rejected('Hookah lounge', 'Shisha Place'));
    }

    // ═══════════════════════ الديدوب ═══════════════════════

    public function test_a_place_that_is_already_a_client_is_skipped(): void
    {
        $this->makeClient(['name' => 'كافيه المعادي', 'phone' => '01000000001']);

        $importer = new LeadImporter;

        // مرة بالتليفون ومرة بالاسم المطبّع (همزة مختلفة، من غير «ال»)
        $checked = $importer->validateAll($this->rows(
            ['name' => 'مكان تاني خالص', 'category' => 'Gym', 'phone' => '0100 000 0001'],
            ['name' => 'كافيه المعادى', 'category' => 'Coffee shop'],
            ['name' => 'كافيه جديد', 'category' => 'Coffee shop', 'phone' => '01111111111'],
        ));

        $this->assertCount(1, $checked['ok']);
        $this->assertSame('كافيه جديد', $checked['ok'][0]['name']);
    }

    public function test_the_same_place_id_never_enters_twice(): void
    {
        $importer = new LeadImporter;

        // مرتين في نفس الملف
        $checked = $importer->validateAll($this->rows(
            ['name' => 'Gold Gym Nasr City', 'category' => 'Gym', 'external_id' => 'ChIJ_aaa'],
            ['name' => 'Gold Gym — Nasr City', 'category' => 'Gym', 'external_id' => 'ChIJ_aaa'],
        ));

        $this->assertCount(1, $checked['ok']);

        $importer->apply($checked['ok']);
        $this->assertSame(1, Lead::count());

        // ورفعة تانية بنفس المعرّف مابتضيفش حاجة
        $again = $importer->validateAll($this->rows(
            ['name' => 'Gold Gym Nasr City', 'category' => 'Gym', 'external_id' => 'ChIJ_aaa'],
        ));

        $this->assertCount(0, $again['ok']);
    }

    public function test_closed_places_and_off_target_activities_are_dropped(): void
    {
        $checked = (new LeadImporter)->validateAll($this->rows(
            ['name' => 'جيم قافل', 'category' => 'Gym', 'closed' => 'true'],
            ['name' => 'بنك مصر', 'category' => 'Bank'],
            ['name' => 'جيم شغال', 'category' => 'Gym'],
        ));

        $this->assertCount(1, $checked['ok']);
        $this->assertSame('جيم شغال', $checked['ok'][0]['name']);
    }

    public function test_swapped_coordinates_are_rejected_with_an_error(): void
    {
        // خط عرض ١١٥ مستحيل — العمودين متبدلين
        $checked = (new LeadImporter)->validateAll($this->rows(
            ['name' => 'مكان بإحداثيات مقلوبة', 'category' => 'Gym', 'lat' => '115.2', 'lng' => '30.1'],
        ));

        $this->assertCount(0, $checked['ok']);
        $this->assertNotEmpty($checked['errors']);
    }

    // ═══════════════════════ التنفيذ ═══════════════════════

    public function test_import_assigns_the_nearest_zone_and_its_rep(): void
    {
        $near = Zone::create(['code' => 'ZN1', 'name' => 'المعادي', 'lat' => 29.9600, 'lng' => 31.2570, 'active' => true]);
        $far = Zone::create(['code' => 'ZN2', 'name' => 'الإسكندرية', 'lat' => 31.2001, 'lng' => 29.9187, 'active' => true]);

        $rep = $this->makeRep(['zone_id' => $near->id]);

        Channel::create(['code' => Channel::CASH_VAN, 'name' => 'كاش فان', 'active' => true]);

        $checked = (new LeadImporter)->validateAll($this->rows(
            ['name' => 'جيم المعادي', 'category' => 'Gym', 'lat' => '29.9610', 'lng' => '31.2580', 'reviews' => '800', 'rating' => '4.6'],
            // ⚠️ في وسط الصحرا — أبعد من السقف، فالمفروض يدخل بلا زون
            ['name' => 'جيم الواحات', 'category' => 'Gym', 'lat' => '27.2000', 'lng' => '28.3500'],
        ));

        $result = (new LeadImporter)->apply($checked['ok']);

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['zoned']);
        $this->assertSame(1, $result['unzoned']);
        $this->assertSame(1, $result['assigned']);

        $maadi = Lead::where('name', 'جيم المعادي')->firstOrFail();
        $this->assertSame($near->id, $maadi->zone_id);
        $this->assertNotSame($far->id, $maadi->zone_id);
        $this->assertSame($rep->id, $maadi->assigned_to);
        $this->assertGreaterThan(0, $maadi->score);

        $desert = Lead::where('name', 'جيم الواحات')->firstOrFail();
        $this->assertNull($desert->zone_id);
        $this->assertNull($desert->assigned_to);
    }

    public function test_import_creates_leads_and_never_touches_clients(): void
    {
        $before = \App\Models\Client::count();

        $checked = (new LeadImporter)->validateAll($this->rows(
            ['name' => 'كافيه الزمالك', 'category' => 'Coffee shop', 'reviews' => '1200', 'rating' => '4.4'],
        ));

        (new LeadImporter)->apply($checked['ok']);

        $this->assertSame(1, Lead::count());
        $this->assertSame($before, \App\Models\Client::count());

        $lead = Lead::firstOrFail();
        $this->assertSame('new', $lead->status);
        $this->assertNull($lead->client_id);
        // ⚠️ المتوقع شهرياً بيفضل صفر — السكور ترتيب مش فلوس
        $this->assertSame(0.0, (float) $lead->expected_monthly);
    }

    public function test_the_source_is_detected_from_the_external_reference(): void
    {
        $checked = (new LeadImporter)->validateAll($this->rows(
            ['name' => 'جيم من جوجل', 'category' => 'Gym', 'external_id' => 'ChIJabc123'],
            ['name' => 'جيم من فيسبوك', 'category' => 'Gym', 'website' => 'https://www.facebook.com/somegym'],
            ['name' => 'جيم من شيت', 'category' => 'Gym'],
        ));

        (new LeadImporter)->apply($checked['ok']);

        $this->assertSame('gmaps', Lead::where('name', 'جيم من جوجل')->value('source'));
        $this->assertSame('facebook', Lead::where('name', 'جيم من فيسبوك')->value('source'));
        $this->assertSame('sheet', Lead::where('name', 'جيم من شيت')->value('source'));
    }

    public function test_chain_branches_with_the_same_name_all_get_imported(): void
    {
        // ⚠️ الفخ ده حقيقي: إكسبورت جوجل بيكتب اسم السلسلة في عمود
        // `title` لكل فرع. الحكم بالاسم لوحده كان بيدخّل فرع واحد
        // ويرمي الباقي كـ«مكررين» — يعني ٣٩ فرصة بتضيع في صمت.
        $checked = (new LeadImporter)->validateAll($this->rows(
            ['name' => 'سيلانترو', 'category' => 'Coffee shop', 'lat' => '30.0444', 'lng' => '31.2357'],
            ['name' => 'سيلانترو', 'category' => 'Coffee shop', 'lat' => '30.0100', 'lng' => '31.2100'],
            ['name' => 'سيلانترو', 'category' => 'Coffee shop', 'lat' => '29.9800', 'lng' => '31.2600'],
            // ودي فعلاً نفس الفرع (٢٠ متر) — لازم تتخطى
            ['name' => 'سيلانترو', 'category' => 'Coffee shop', 'lat' => '30.04442', 'lng' => '31.23588'],
        ));

        $this->assertCount(3, $checked['ok']);
    }

    public function test_hypermarkets_carry_their_sub_channel_into_the_client(): void
    {
        Channel::create(['code' => Channel::KEY_ACCOUNT, 'name' => 'كي أكاونت', 'active' => true]);
        $this->makePriceList('new');
        $admin = $this->makeAdmin();

        $checked = (new LeadImporter)->validateAll($this->rows(
            ['name' => 'Carrefour City Maadi', 'category' => 'Hypermarket', 'reviews' => '2000', 'rating' => '4.1'],
        ));

        (new LeadImporter)->apply($checked['ok']);

        $lead = Lead::firstOrFail();
        $this->assertSame('chain', $lead->sub_channel);

        // ⚠️ والقسم بيوصل للعميل — من غير كده القرار بينفّذ نصه بس
        $client = \App\Services\Leads::convert($lead, $admin);
        $this->assertSame('chain', $client->sub_channel);
    }

    public function test_every_source_key_has_a_label_in_both_languages(): void
    {
        foreach (Lead::SOURCES as $s) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Lang::has('lead.source_'.$s, $locale),
                    "lead.source_$s is missing from lang/$locale",
                );
            }
        }
    }
}
