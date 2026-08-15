<?php

namespace Tests\Feature;

use App\Support\Dupes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * حارس التكرار — نفس العميل مايتفتحلوش حسابين
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الملف ده مهم:** العميل المكرر معناه حسابين لنفس المحل —
 * كل واحد بمديونيته ومرتجعاته وعقده. المندوب بيبيع على واحد والمحاسب
 * بيحصّل على التاني، والرصيد الحقيقي مش موجود في أي مكان.
 *
 * ⚠️ **`Dupes` هي المصدر الوحيد للمنطق ده.** المستورد وشاشة الإنشاء
 * اليدوي الاتنين بينادوا عليها. لما كان لكل مسار تطبيعه، الصف اللي
 * الاستيراد بيرفضه كانت الشاشة بتقبله والعكس — يعني الحارس نفسه كان
 * هو مصدر التكرار.
 */
class DupesTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════ 1. تطبيع الاسم ═══════════════

    /**
     * «فرع المعادى ١» و«المعادي 1» نفس المحل.
     *
     * ⚠️ ده الشكل اللي بيوصل فعلاً من شيتات السلاسل: كلمة «فرع»
     * زيادة، ياء بألف مقصورة، وأرقام عربي. لو التطبيع ماغطّاش
     * الحالات دي، نفس الفرع بيتفتح مرتين من شيتين مختلفين.
     */
    public function test_the_same_branch_written_two_ways_has_one_key(): void
    {
        $this->assertSame(
            Dupes::nameKey('فرع المعادى ١'),
            Dupes::nameKey('المعادي 1'),
            '«فرع» و«ال» والياء والأرقام العربي كلهم لازم يتطبّعوا',
        );

        // ⚠️ التأكيد إن المفتاح مش فاضي — مفتاح فاضي بيطابق كل حاجة
        // ومابيطابقش أي حاجة في نفس الوقت (الحارس بيتخطّاه).
        $this->assertNotSame('', Dupes::nameKey('فرع المعادى ١'));
    }

    /**
     * الهمزات والتاء المربوطة والمسافات الزيادة كلها بتتوحّد.
     *
     * ⚠️ اللي بيدخل الداتا بيكتب الاسم بشكل مختلف كل مرة. من غير
     * التوحيد ده، «أحمد» و«احمد» عميلين.
     */
    public function test_hamzas_and_ta_marbuta_and_spacing_are_normalised(): void
    {
        $this->assertSame(Dupes::nameKey('أسواق الحرية'), Dupes::nameKey('اسواق الحريه'));
        $this->assertSame(Dupes::nameKey('  سوبر   ماركت  '), Dupes::nameKey('سوبر ماركت'));
        $this->assertSame(Dupes::nameKey('مول (التجمع)'), Dupes::nameKey('مول التجمع'));
    }

    /** محلين مختلفين فعلاً لازم يفضلوا مختلفين */
    public function test_two_genuinely_different_names_stay_different(): void
    {
        $this->assertNotSame(Dupes::nameKey('المعادي 1'), Dupes::nameKey('المعادي 2'));
        $this->assertNotSame(Dupes::nameKey('محل النور'), Dupes::nameKey('محل الأمل'));
    }

    // ═══════════════ 2. تطبيع التليفون ═══════════════

    /**
     * الرقم بكود الدولة والرقم المحلي مفتاح واحد.
     *
     * ⚠️ نفس الرقم بيتكتب بأربع صور على الأقل: بمسافات، بشرطات،
     * بـ+20، وبصفر. من غير التطبيع، الحارس بيسيب نفس العميل يدخل
     * تاني لأن «الرقم مختلف».
     */
    public function test_a_local_number_and_its_country_code_form_share_one_key(): void
    {
        $expected = '01284082945';

        $this->assertSame($expected, Dupes::phoneKey('+20 128 408 2945'));
        $this->assertSame($expected, Dupes::phoneKey('01284082945'));
        $this->assertSame($expected, Dupes::phoneKey('0128-408-2945'));
        $this->assertSame($expected, Dupes::phoneKey('201284082945'));
    }

    /**
     * أرقام مختلفة بتفضل مختلفة، والفاضي بيفضل فاضي.
     *
     * ⚠️ المفتاح الفاضي بيتخطّى في `existing()` عن قصد — عميلين من
     * غير تليفون مش عميل واحد.
     */
    public function test_different_numbers_and_empty_input_are_handled(): void
    {
        $this->assertNotSame(Dupes::phoneKey('01284082945'), Dupes::phoneKey('01284082946'));
        $this->assertSame('', Dupes::phoneKey(null));
        $this->assertSame('', Dupes::phoneKey('   '));
    }

    // ═══════════════ 3. البحث عن العميل الموجود ═══════════════

    /**
     * الاسم المطبّع بيلاقي العميل الموجود.
     *
     * ⚠️ ده الفحص اللي بيقف قدام «فرع المعادي» وهو داخل تاني من
     * شيت جديد. `by = 'name'` عشان الشاشة تقول للمستخدم إن السبب
     * الاسم مش الرقم — الرسالة الغامضة بتخلّيه يغيّر الاسم شوية
     * ويعدّي.
     */
    public function test_existing_finds_a_client_by_his_normalised_name(): void
    {
        $stored = $this->makeClient(['name' => 'المعادي 1', 'phone' => null]);

        $hit = Dupes::existing('فرع المعادى ١', null);

        $this->assertNotNull($hit, 'الحارس مالقاش نفس العميل باسم مكتوب بشكل تاني');
        $this->assertSame('name', $hit['by']);
        $this->assertSame($stored->id, $hit['client']->id);
    }

    /**
     * التليفون بيلاقي العميل حتى لو الاسم مختلف تماماً.
     *
     * ⚠️ ده اللي بيمسك الحالة الشائعة: نفس المحل اتكتب مرة باسم
     * صاحبه ومرة باسم اللافتة. الرقم هو اللي بيفضحها.
     */
    public function test_existing_finds_a_client_by_his_phone_even_under_another_name(): void
    {
        $stored = $this->makeClient([
            'name' => 'محمد عبد الرحمن',
            'phone' => '01284082945',
        ]);

        $hit = Dupes::existing('سوبر ماركت النجم الساطع', '+20 128 408 2945');

        $this->assertNotNull($hit, 'الحارس مالقاش نفس الرقم مكتوب بكود الدولة');
        $this->assertSame('phone', $hit['by']);
        $this->assertSame($stored->id, $hit['client']->id);
    }

    /**
     * اسم جديد تماماً بيعدّي.
     *
     * ⚠️ الحارس اللي بيرفض كل حاجة أسوأ من اللي مش موجود — بيوقّف
     * الشغل ويخلّي اللي بيدخل الداتا يدوّر على طريقة يلفّ بيها حواليه.
     */
    public function test_existing_returns_null_for_a_brand_new_client(): void
    {
        $this->makeClient(['name' => 'المعادي 1', 'phone' => '01284082945']);

        $this->assertNull(
            Dupes::existing('مخبز الشروق الحديث', null),
            'اسم مالوش أي علاقة اترفض — الحارس بيمنع عملاء جداد',
        );
    }

    /**
     * التعديل على العميل نفسه مش تكرار.
     *
     * ⚠️ من غير `ignoreId`، فتح كارت العميل والضغط على «حفظ» من
     * غير أي تغيير كان بيترفض بحجة «الاسم موجود» — والاسم الموجود
     * هو اسمه هو.
     */
    public function test_a_client_is_never_a_duplicate_of_himself(): void
    {
        $client = $this->makeClient(['name' => 'المعادي 1', 'phone' => '01284082945']);

        $this->assertNull(
            Dupes::existing('المعادي 1', '01284082945', $client->id),
            'العميل اتحسب تكرار لنفسه وقت التعديل',
        );
    }

    /**
     * الاسم بيتفحص قبل التليفون — والاتنين بيتفحصوا.
     *
     * ⚠️ لو الفحص وقف عند أول واحد بس، عميل باسم جديد ورقم موجود
     * كان هيعدّي. التيست ده بيثبت إن الرقم لوحده كفاية للرفض،
     * وإن الاسم لوحده كفاية برضه.
     */
    public function test_either_the_name_or_the_phone_alone_is_enough_to_flag(): void
    {
        $this->makeClient(['name' => 'المعادي 1', 'phone' => '01284082945']);

        // اسم مطابق + رقم جديد
        $byName = Dupes::existing('فرع المعادى ١', '01000000001');
        $this->assertNotNull($byName);
        $this->assertSame('name', $byName['by']);

        // اسم جديد + رقم مطابق
        $byPhone = Dupes::existing('مخبز الشروق الحديث', '+20 128 408 2945');
        $this->assertNotNull($byPhone);
        $this->assertSame('phone', $byPhone['by']);
    }

    // ═══════════════ 4. النسخة الغنية اللي الشاشات بتستخدمها ═══════════════

    /**
     * `matches()` بترجّع كل الشبيهين مش أول واحد، ومعاهم درجة ثقة.
     *
     * ⚠️ الشاشة محتاجة **قايمة** عشان اللي بيسجّل يقارن بنفسه. رد
     * بواحد بس كان بيخلّيه يشوف الشبيه الغلط ويقول «لأ ده مختلف»
     * وهو مشافش الشبيه الحقيقي.
     */
    public function test_matches_returns_every_similar_client_with_a_confidence(): void
    {
        $this->makeClient(['name' => 'المعادي 1', 'phone' => '01284082945']);
        $this->makeClient(['name' => 'مخبز الشروق', 'phone' => '01000000001']);

        $hits = Dupes::matches(['name' => 'فرع المعادى ١', 'phone' => null]);

        $this->assertCount(1, $hits);
        $this->assertSame('name', $hits[0]['by']);
        $this->assertSame(Dupes::SURE, $hits[0]['confidence']);
        $this->assertTrue(Dupes::hasSure($hits));
    }

    /**
     * التليفون المشترك جوّه السلسلة **محتمل** مش مؤكد.
     *
     * ⚠️ سلسلة زي Circle K عندها عشرات الفروع كلهم مكتوب عليهم رقم
     * الإدارة. لو الرقم لوحده «مؤكد»، فلو «فرع جديد بشروط السلسلة»
     * بيتحرس على كل فرع — واللي بيسجّل بيتعلّم يدوس «كمّل» من غير
     * ما يقرا، فالحارس يبقى شكل.
     */
    public function test_a_shared_head_office_number_inside_one_chain_is_only_likely(): void
    {
        $group = \App\Models\ClientGroup::create([
            'code' => 'GRP-TEST', 'name' => 'سلسلة التيست', 'active' => true,
        ]);

        $this->makeClient([
            'name' => 'سلسلة التيست — المعادي',
            'phone' => '01284082945',
            'group_id' => $group->id,
        ]);

        $hits = Dupes::matches([
            'name' => 'سلسلة التيست — مدينة نصر',
            'phone' => '01284082945',
            'group_id' => $group->id,
        ]);

        $this->assertCount(1, $hits);
        $this->assertSame('phone', $hits[0]['by']);
        $this->assertSame(Dupes::LIKELY, $hits[0]['confidence']);
        $this->assertFalse(Dupes::hasSure($hits));
    }

    /** العميل الجديد تماماً مالوش أي شبيه */
    public function test_matches_is_empty_for_a_brand_new_client(): void
    {
        $this->makeClient(['name' => 'المعادي 1', 'phone' => '01284082945']);

        $this->assertSame([], Dupes::matches([
            'name' => 'مخبز الشروق الحديث',
            'phone' => '01000000002',
        ]));
    }

    /**
     * العميل مش تكرار لنفسه في `matches()` كمان.
     *
     * ⚠️ من غير ده، فتح كارت العميل والضغط على «حفظ» من غير أي
     * تغيير كان هيوري بانل أصفر بيقول إنه تكرار لنفسه.
     */
    public function test_matches_never_flags_the_client_being_edited(): void
    {
        $client = $this->makeClient(['name' => 'المعادي 1', 'phone' => '01284082945']);

        $this->assertSame([], Dupes::matches(
            ['name' => 'المعادي 1', 'phone' => '01284082945'],
            $client->id,
        ));
    }

    /**
     * عمود `dupe_key` بيتملى أوتوماتيك من الموديل.
     *
     * ⚠️ هو اللي بيخلّي اللقطة فهرس مش مسح كامل على 10 آلاف عميل.
     * لو الهوك اتشال، الحارس هيفضل شغّال (فيه مسار احتياطي) بس
     * هيقرا الجدول كله في كل ضغطة على خانة الاسم.
     */
    public function test_the_normalised_keys_are_stored_on_save(): void
    {
        $client = $this->makeClient(['name' => 'فرع المعادى ١', 'phone' => '+20 128 408 2945']);

        $this->assertSame(Dupes::nameKey('المعادي 1'), $client->fresh()->dupe_key);
        $this->assertSame('01284082945', $client->fresh()->dupe_phone);
    }
}
