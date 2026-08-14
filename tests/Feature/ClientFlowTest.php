<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلو العميل كامل: إضافة ← إعادة فتح ← تعديل ← إعادة فتح
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده بيحرس حاجة `ClientFormIntegrityTest` مابتشوفهاش.**
 * التيست التاني **بنيوي**: بيقارن الفورم بالقواعد بالأعمدة ويتأكد إن
 * كل خانة ليها مكان. الملف ده **سلوكي**: بيملا الفورم زي المستخدم
 * بالظبط، يحفظ، يفتح صفحة التعديل، ويتأكد إن اللي كتبه **ظاهر قدامه**.
 *
 * الفرق ده مش أكاديمي — الباجات اللي عاشت في الشاشة دي كلها كانت من
 * النوع التاني: الحقل موجود والقاعدة موجودة والعمود موجود، والقيمة
 * بتتحفظ… وصفحة التعديل بتفتح فاضية لأن الفيو بيقرا من مصدر تاني.
 *
 * **الثلاث قواعد اللي بيحرسها:**
 *   1. أي حاجة الشاشة بتقول عليها إجبارية — السيرفر يرفضها فاضية.
 *   2. أي حاجة اتكتبت — تبان تاني لما أفتح التعديل.
 *   3. تعديل خانة واحدة — مايمسحش الباقي.
 *
 * ⚠️⚠️ **فخ الإنتربوليشن العربي — «$var» جوه دبل كوت.**
 * PHP في التركيب البسيط جوه `"..."` بيعتبر أي بايت من `\x80` لـ`\xFF`
 * **حرف صالح في اسم المتغيّر**. يعني `"«$field» فاضية"` بيتقري متغيّر
 * اسمه `field»` (بالبايتين `\xC2\xBB` ملزوقين في الاسم) — و
 * `Undefined variable $field` بترمي `ErrorException` وتفشّل التيست
 * برسالة مالهاش أي علاقة باللي بيتفحص.
 *
 * القاعدة: **أي متغيّر وراه حرف عربي أو علامة تنصيص «» أو أي بايت
 * غير أسكي — يتحط بين أقواس معكوفة**: `{$field}`. الأقواس بتقفل
 * الاسم صراحةً فالبايت اللي بعدها بيبقى نص عادي.
 */
class ClientFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * الفورم كامل زي ما المستخدم بيملاه — **كل** خانة فيها قيمة.
     *
     * ⚠️ **القيم مميّزة عن بعضها عن قصد** (تليفونات مختلفة، أرقام
     * مختلفة، نصوص مختلفة). لو حطّينا نفس القيمة في خانتين، خانة
     * بتتكتب في عمود التانية كانت هتعدّي من غير ما التيست يحس.
     */
    private function fullPayload(array $extra = []): array
    {
        $clauses = [];

        foreach (array_keys(Contract::CLAUSE_PRESETS) as $preset) {
            $clauses[$preset] = ['on' => 0, 'value' => 0];
        }

        return array_replace_recursive([
            // ═══ خطوة 1: التعريف ═══
            'name' => 'جولدز جيم — الشيخ زايد',
            'name_en' => 'Golds Gym — Sheikh Zayed',
            'channel_id' => $this->makeChannel()->id,
            'phone' => '01011112222',
            'governorate' => 'giza',
            'zone_id' => $this->makeZone()->id,
            'address' => '7 Beverly Hills, Sheikh Zayed',
            'location_url' => 'https://maps.app.goo.gl/abc123',
            'lat' => 30.0444,
            'lng' => 31.2357,
            'contacts' => [
                ['name' => 'Ahmed Samir', 'role' => 'Branch Manager', 'phone' => '01033334444'],
            ],
            // ═══ خطوة 1: شروط الدفع ═══
            'payment_terms' => Client::PAY_BOTH,
            'payment_days' => 45,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
            // ═══ خطوة 2: التسعير والعقد ═══
            'price_list_id' => $this->makePriceList()->id,
            'discount' => 12.5,
            'has_contract' => 1,
            'contract_type' => Contract::TYPE_CHOICES[0],
            'contract_duration' => 'open',
            'clause' => $clauses,
            'contract_note' => 'Signed with the chain HQ',
            // ═══ خطوة 3: الضريبة والملاحظات ═══
            'taxable' => 1,
            'tax_rate' => 14,
            'tax_cycle' => 'monthly',
            'tax_id' => '123-456-789',
            'eta_type' => 'B',
            'notes' => 'ملاحظة داخلية على الحساب',
        ], $extra);
    }

    private function created(): Client
    {
        return Client::orderByDesc('id')->firstOrFail();
    }

    // ═══════════════════ 1. الإضافة ═══════════════════

    public function test_a_full_client_saves_every_field_it_was_given(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post('/erp/clients', $this->fullPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $c = $this->created();

        // ⚠️ **بنفحص كل خانة على حدة مش `assertDatabaseHas` بالجملة.**
        // الفحص المجمّع بيقول «مش موجود» من غير ما يقول **أنهي** خانة،
        // واللي بيصلّح بيقعد يقارن أرايين بالعين.
        $this->assertSame('جولدز جيم — الشيخ زايد', $c->name, 'الاسم العربي');
        $this->assertSame('Golds Gym — Sheikh Zayed', $c->name_en, 'الاسم الإنجليزي');
        $this->assertSame('01011112222', $c->phone, 'التليفون');
        $this->assertSame('giza', $c->governorate, 'المحافظة');
        $this->assertNotNull($c->zone_id, 'المنطقة');
        $this->assertSame('7 Beverly Hills, Sheikh Zayed', $c->address, 'العنوان');
        $this->assertNotNull($c->channel_id, 'القناة');

        // ⚠️ الخصم بيتقسم على 100 **مرة واحدة** في الكنترولر — 12.5% ⇒ 0.125
        $this->assertEqualsWithDelta(0.125, (float) $c->discount, 0.0001, 'الخصم');

        $this->assertSame(Client::PAY_BOTH, $c->payment_terms, 'طريقة الدفع');
        $this->assertSame(45, (int) $c->payment_days, 'مدة السداد');
        $this->assertSame(Contract::DAYS_FROM_INVOICE, $c->payment_days_from, 'أساس العد');

        $this->assertTrue((bool) $c->taxable, 'خاضع للضريبة');
        $this->assertSame('123-456-789', $c->tax_id, 'الرقم الضريبي');
        $this->assertSame('monthly', $c->tax_cycle, 'دورة الضريبة');
        $this->assertSame('B', $c->eta_type, 'نوع المستلم');
        $this->assertSame('ملاحظة داخلية على الحساب', $c->notes, 'الملاحظات');

        // جهات التواصل — عمود JSON، أول صف بالكامل
        $this->assertSame('Ahmed Samir', $c->contactList()[0]['name'] ?? null, 'جهة التواصل');
        $this->assertSame('01033334444', $c->contactList()[0]['phone'] ?? null, 'تليفون جهة التواصل');
    }

    // ═══════════════════ 2. الإجباري لازم يوقف الحفظ ═══════════════════

    /**
     * ⚠️ **كل حقل لوحده في دورة مستقلة.** لو بعتنا الفورم ناقص 4 حقول
     * مرة واحدة والتيست عدّى، ده مايثبتش إن الأربعة بيترفضوا — يمكن
     * واحد بس هو اللي رفض. الحلقة دي بتشيل حقل واحد كل مرة والباقي
     * كامل، فالسبب الوحيد للفشل هو الحقل ده.
     *
     * @dataProvider requiredFields
     */
    public function test_a_required_field_cannot_be_left_empty(string $field): void
    {
        $admin = $this->makeAdmin();
        $payload = $this->fullPayload();

        unset($payload[$field]);

        $this->actingAs($admin)
            ->post('/erp/clients', $payload)
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Client::count(),
            "العميل اتحفظ رغم إن «{$field}» فاضية — الشاشة بتقول عليها إجبارية");
    }

    public static function requiredFields(): array
    {
        return [
            'الاسم العربي' => ['name'],
            'الاسم الإنجليزي' => ['name_en'],
            'القناة' => ['channel_id'],
            'قايمة السعر' => ['price_list_id'],
            'الخصم' => ['discount'],
        ];
    }

    /** العقد: النوع والمدة إجباريين **بس لما يكون فيه عقد** */
    public function test_contract_fields_are_required_only_with_a_contract(): void
    {
        $admin = $this->makeAdmin();

        // من غير عقد — بيعدّي من غير نوع ولا مدة
        $noContract = $this->fullPayload(['has_contract' => 0]);
        unset($noContract['contract_type'], $noContract['contract_duration']);

        $this->actingAs($admin)->post('/erp/clients', $noContract)
            ->assertSessionHasNoErrors()->assertRedirect();

        // بعقد ومن غير نوع — بيترفض
        $withContract = $this->fullPayload();
        unset($withContract['contract_type']);

        $this->actingAs($admin)->post('/erp/clients', $withContract)
            ->assertSessionHasErrors('contract_type');
    }

    /**
     * ⚠️ **نهاية من غير بداية مرفوضة.** `after_or_equal:contract_starts_at`
     * بتتقارن بلا شيء لما البداية فاضية، فبتعدّي — والعقد بيتحفظ بمدة
     * مالهاش أول.
     */
    public function test_an_end_date_without_a_start_date_is_rejected(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post('/erp/clients', $this->fullPayload([
                'contract_duration' => 'custom',
                'contract_ends_at' => now()->addYear()->toDateString(),
            ]))
            ->assertSessionHasErrors('contract_starts_at');
    }

    /** ⚠️ رقم أيام سداد من غير نقطة بداية = تاريخ استحقاق مالوش أساس */
    public function test_payment_days_without_a_basis_is_rejected(): void
    {
        $payload = $this->fullPayload();
        unset($payload['payment_days_from']);

        $this->actingAs($this->makeAdmin())
            ->post('/erp/clients', $payload)
            ->assertSessionHasErrors('payment_days_from');
    }

    // ═══════════════════ 3. إعادة الفتح: اللي اتكتب يبان ═══════════════════

    /**
     * ⚠️ **ده التيست اللي بيمسك أخطر نوع باج في الشاشة دي.** القيمة
     * بتتحفظ في الداتابيز صح، والفيو بيقراها من مصدر تاني (أو بينساها
     * خالص)، فصفحة التعديل بتفتح فاضية — والمستخدم بيعيد الكتابة أو
     * بيحفظ فيمسحها.
     */
    public function test_reopening_the_edit_screen_shows_everything_that_was_saved(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->fullPayload())
            ->assertSessionHasNoErrors();

        $c = $this->created();

        $html = $this->actingAs($admin)
            ->get('/erp/clients/'.$c->id.'/edit')
            ->assertOk()
            ->getContent();

        foreach ([
            'الاسم العربي' => 'جولدز جيم — الشيخ زايد',
            'الاسم الإنجليزي' => 'Golds Gym — Sheikh Zayed',
            'التليفون' => '01011112222',
            'العنوان' => '7 Beverly Hills, Sheikh Zayed',
            'لينك اللوكيشن' => 'https://maps.app.goo.gl/abc123',
            'جهة التواصل' => 'Ahmed Samir',
            'تليفون جهة التواصل' => '01033334444',
            'الرقم الضريبي' => '123-456-789',
            'الملاحظات' => 'ملاحظة داخلية على الحساب',
        ] as $label => $needle) {
            $this->assertStringContainsString($needle, $html,
                "«{$label}» اتحفظت بس مش ظاهرة في شاشة التعديل");
        }

        // الدروب داونز: المختار لازم يكون عليه `selected`
        $this->assertMatchesRegularExpression(
            '/value="'.Client::PAY_BOTH.'"[^>]*selected/',
            $html, 'طريقة الدفع مش مختارة في الدروب داون');

        $this->assertMatchesRegularExpression(
            '/value="'.$c->zone_id.'"[^>]*selected/',
            $html, 'المنطقة مش مختارة في الدروب داون');

        // ⚠️ الخصم بيتعرض **بالنسبة** مش بالكسر — 0.125 ⇒ 12.5
        $this->assertStringContainsString('value="12.5"', $html, 'الخصم مش ظاهر بالنسبة');

        // ⚠️ مدة السداد رقم صافي — لو طلعت 45.00 يبقى فيه cast غلط
        $this->assertStringContainsString('value="45"', $html, 'مدة السداد مش ظاهرة');
    }

    // ═══════════════════ 4. التعديل مايمسحش الباقي ═══════════════════

    /**
     * ⚠️ **الباج ده حصل فعلاً في `TaxController`**: الحفظ كان بيلف على
     * كل الإعدادات بدل بتاعته هو ويمسح إعدادات موديولات تانية. نفس
     * الشكل هنا: تعديل التليفون لازم مايحركش الخصم ولا الضريبة ولا
     * شروط الدفع.
     */
    public function test_editing_one_field_leaves_the_rest_untouched(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->fullPayload())
            ->assertSessionHasNoErrors();

        $c = $this->created();
        $before = $c->only([
            'name', 'name_en', 'discount', 'payment_terms', 'payment_days',
            'payment_days_from', 'tax_id', 'tax_cycle', 'eta_type', 'notes',
            'governorate', 'zone_id', 'channel_id',
        ]);

        // نفس الفورم بالظبط بس التليفون اتغيّر — ده اللي بيحصل فعلاً
        $this->actingAs($admin)
            ->put('/erp/clients/'.$c->id, $this->fullPayload([
                'phone' => '01099998888',
                'zone_id' => $c->zone_id,
                'channel_id' => $c->channel_id,
                'price_list_id' => $c->price_list_id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $after = $c->fresh();

        $this->assertSame('01099998888', $after->phone, 'التليفون ماتغيّرش');

        foreach ($before as $key => $value) {
            $this->assertEquals($value, $after->{$key},
                "«{$key}» اتغيّرت وانت بتعدّل التليفون بس");
        }
    }

    /** التعديل الجزئي بيوصل فعلاً — مش بيتبلع في صمت */
    public function test_editing_payment_terms_actually_persists(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->fullPayload())
            ->assertSessionHasNoErrors();

        $c = $this->created();

        $this->actingAs($admin)->put('/erp/clients/'.$c->id, $this->fullPayload([
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 30,
            'payment_days_from' => Contract::DAYS_FROM_FIRST_SUPPLY,
            'zone_id' => $c->zone_id,
            'channel_id' => $c->channel_id,
            'price_list_id' => $c->price_list_id,
        ]))->assertSessionHasNoErrors();

        $after = $c->fresh();

        $this->assertSame(Client::PAY_CREDIT, $after->payment_terms);
        $this->assertSame(30, (int) $after->payment_days);
        $this->assertSame(Contract::DAYS_FROM_FIRST_SUPPLY, $after->payment_days_from);
        $this->assertFalse($after->paymentIsChoice(), 'لسه بيقول إن المندوب يختار');
        $this->assertTrue($after->allowsCredit());
    }

    // ═══════════════════ 5. شروط الدفع: العقد يغلب ═══════════════════

    /**
     * ⚠️ **الترتيب ده هو كل الدوكترين.** العقد ورقة موقّعة والخانة على
     * العميل إعداد داخلي — لو انعكسوا، تعديل بسيط على كارت العميل
     * بيغيّر مدة سداد متفق عليها في عقد من غير ما حد يفتح العقد.
     */
    public function test_a_live_contract_overrides_the_client_payment_days(): void
    {
        $client = $this->makeClient([
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 15,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
        ]);

        $this->assertSame(15, $client->paymentDays(), 'من غير عقد: خانة العميل');

        Contract::create([
            'client_id' => $client->id,
            'number' => 'CT-'.random_int(1000, 9999),
            'type' => Contract::TYPE_CHOICES[0],
            'discount' => 0,
            'payment_days' => 60,
            'payment_days_from' => Contract::DAYS_FROM_FIRST_SUPPLY,
            'active' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addYear(),
        ]);

        $client->refresh()->load('contract');

        $this->assertSame(60, $client->paymentDays(), 'العقد الساري لازم يغلب');
        $this->assertSame(Contract::DAYS_FROM_FIRST_SUPPLY, $client->paymentBasis());
    }

    /** ⚠️ العقد المنتهي شروطه بتوقف — والعميل يرجع لخانته */
    public function test_an_expired_contract_hands_the_terms_back_to_the_client(): void
    {
        $client = $this->makeClient([
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 15,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
        ]);

        Contract::create([
            'client_id' => $client->id,
            'number' => 'CT-'.random_int(1000, 9999),
            'type' => Contract::TYPE_CHOICES[0],
            'discount' => 0,
            'payment_days' => 60,
            'payment_days_from' => Contract::DAYS_FROM_FIRST_SUPPLY,
            'active' => true,
            'starts_at' => now()->subYears(2),
            'ends_at' => now()->subDay(),
        ]);

        $client->refresh()->load('contract');

        $this->assertSame(15, $client->paymentDays(), 'العقد المنتهي لسه بيحكم');
    }

    /**
     * ⚠️ **«صفر يوم» قرار مش فراغ.** الفحص القديم كان `if ($days)`،
     * فعقد بصفر يوم (مستحق فوراً) كان بيتقري كأنه «مفيش مدة» والعميل
     * ينزل على خانته — يعني العقد الموقّع اتداس عليه.
     */
    public function test_a_zero_day_contract_still_beats_the_client_field(): void
    {
        $client = $this->makeClient([
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 30,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
        ]);

        Contract::create([
            'client_id' => $client->id,
            'number' => 'CT-'.random_int(1000, 9999),
            'type' => Contract::TYPE_CHOICES[0],
            'discount' => 0,
            'payment_days' => 0,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
            'active' => true,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addYear(),
        ]);

        $client->refresh()->load('contract');

        $this->assertSame(0, $client->paymentDays(), 'صفر يوم اتقري كأنه مفيش مدة');
    }

    // ═══════════════════ 6. السلسلة بتختم على الفروع ═══════════════════

    public function test_applying_payment_terms_on_a_chain_stamps_every_branch(): void
    {
        $admin = $this->makeAdmin();
        $group = ClientGroup::create([
            'code' => 'GG', 'name' => 'جولدز جيم', 'name_en' => 'Golds Gym', 'active' => true,
        ]);

        $a = $this->makeClient(['group_id' => $group->id, 'payment_terms' => 'cash']);
        $b = $this->makeClient(['group_id' => $group->id, 'payment_terms' => null]);

        $this->actingAs($admin)->put('/erp/groups/'.$group->id, [
            'name' => $group->name,
            'name_en' => $group->name_en,
            'active' => 1,
            'apply_payment_terms' => Client::PAY_CREDIT,
            'apply_payment_days' => 30,
            'apply_payment_days_from' => Contract::DAYS_FROM_INVOICE,
        ])->assertRedirect();

        foreach ([$a, $b] as $branch) {
            $fresh = $branch->fresh();
            $this->assertSame(Client::PAY_CREDIT, $fresh->payment_terms, 'الفرع مااتختمش');
            $this->assertSame(30, (int) $fresh->payment_days);
            $this->assertSame(Contract::DAYS_FROM_INVOICE, $fresh->payment_days_from);
        }
    }

    /**
     * ⚠️ **الخانة الفاضية = ماتلمسش الفروع.** لو اتعاملت كـ«حسب القناة»،
     * أي تعديل على اسم السلسلة كان بيمسح شروط دفع كل فروعها في صمت —
     * نفس الفخ اللي حصل في الخصم بالظبط.
     */
    public function test_saving_a_chain_without_choosing_terms_leaves_branches_alone(): void
    {
        $admin = $this->makeAdmin();
        $group = ClientGroup::create([
            'code' => 'GG2', 'name' => 'جولدز 2', 'name_en' => 'Golds 2', 'active' => true,
        ]);

        $branch = $this->makeClient([
            'group_id' => $group->id,
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 21,
        ]);

        $this->actingAs($admin)->put('/erp/groups/'.$group->id, [
            'name' => 'جولدز جيم مصر',
            'name_en' => 'Golds Gym Egypt',
            'active' => 1,
            'apply_payment_terms' => '',
        ])->assertRedirect();

        $fresh = $branch->fresh();

        $this->assertSame(Client::PAY_CREDIT, $fresh->payment_terms, 'شروط الفرع اتمسحت');
        $this->assertSame(21, (int) $fresh->payment_days, 'مدة الفرع اتمسحت');
    }

    /** ⚠️ الكاش مالوش مدة — الختم لازم يمسحها مش يسيبها */
    public function test_stamping_cash_on_a_chain_clears_the_payment_days(): void
    {
        $admin = $this->makeAdmin();
        $group = ClientGroup::create([
            'code' => 'GG3', 'name' => 'جولدز 3', 'name_en' => 'Golds 3', 'active' => true,
        ]);

        $branch = $this->makeClient([
            'group_id' => $group->id,
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 40,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
        ]);

        $this->actingAs($admin)->put('/erp/groups/'.$group->id, [
            'name' => $group->name,
            'name_en' => $group->name_en,
            'active' => 1,
            'apply_payment_terms' => Client::PAY_CASH,
        ])->assertRedirect();

        $fresh = $branch->fresh();

        $this->assertSame(Client::PAY_CASH, $fresh->payment_terms);
        $this->assertNull($fresh->payment_days, 'عميل كاش لسه شايل مدة سداد');
    }

    // ═══════════════════ 7. كل خانة تحتها شرح ═══════════════════

    /**
     * ⚠️ **الشرح جزء من الشاشة مش زينة.** اللي بيدخّل الداتا مش لازم
     * يعرف إن «القناة» بتحدد السعر و«المنطقة» بتوصّل العميل للمندوب —
     * ولو مايعرفش، بيسيب خانات فاضية مالهاش أثر ظاهر دلوقتي وبتظهر
     * بعد شهر كعميل مالوش مندوب.
     *
     * التيست ده بيقفل الباب على إضافة خانة جديدة من غير شرحها.
     */
    public function test_every_key_field_has_a_note_explaining_where_it_lands(): void
    {
        $html = $this->actingAs($this->makeAdmin())
            ->get('/erp/clients/new')
            ->assertOk()
            ->getContent();

        foreach ([
            'name_en', 'channel_id', 'zone_id', 'payment_terms',
            'payment_days', 'price_list_id', 'category', 'tax_id', 'eta_type',
        ] as $field) {
            $this->assertNotSame('client.hint_'.$field, __('client.hint_'.$field),
                "خانة «{$field}» مالهاش شرح — ضيف `hint_{$field}` في lang/ar و lang/en");
        }

        $this->assertStringContainsString('class="fhint"', $html,
            'سطور الشرح مش بتترندر في الشاشة خالص');
    }

    // ═══════════════════ 8. الحفظ من أي خطوة ═══════════════════

    /**
     * ⚠️ **زرار حفظ في كل خطوة مش في آخر واحدة بس.** اللي بيعدّل تليفون
     * في خطوة «تعريف العميل» ماينفعش يمشي على العقد والضريبة عشان
     * يوصل للزرار — وده كان بيخلّي الناس تسيب التعديل من غير حفظ.
     *
     * والحارس في الجافاسكربت بيفحص **المراحل التلاتة** قبل الإرسال،
     * فالحفظ من الخطوة الأولى مابيخلقش عميل ناقص: أول خانة إجبارية
     * فاضية بيفتح مرحلتها ويقف عليها.
     */
    public function test_the_save_button_appears_on_every_step(): void
    {
        $client = $this->makeClient();

        $html = $this->actingAs($this->makeAdmin())
            ->get('/erp/clients/'.$client->id.'/edit')
            ->assertOk()
            ->getContent();

        $panes = preg_split('/<div class="card step-pane"/', $html);

        // العنصر الأول قبل أول مرحلة — بنشيله
        array_shift($panes);

        $this->assertCount(3, $panes, 'المراحل مش تلاتة — الفورم اتغيّر');

        foreach ($panes as $i => $pane) {
            $this->assertStringContainsString('type="submit"', $pane,
                'الخطوة '.($i + 1).' مافيهاش زرار حفظ — المستخدم لازم يمشي لآخر صفحة');
        }
    }

    /**
     * ⚠️ الحفظ من الخطوة الأولى لازم يوصل للسيرفر كامل — مش بيبعت
     * خانات المرحلة اللي المستخدم ماوصلهاش فاضية.
     */
    public function test_saving_from_the_first_step_keeps_the_later_steps_data(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->fullPayload())
            ->assertSessionHasNoErrors();

        $c = $this->created();

        // المستخدم غيّر التليفون في الخطوة 1 ودَاس حفظ من هناك —
        // المتصفح بيبعت الفورم كله لأن المراحل `display:none` مش `disabled`
        $this->actingAs($admin)->put('/erp/clients/'.$c->id, $this->fullPayload([
            'phone' => '01055556666',
            'zone_id' => $c->zone_id,
            'channel_id' => $c->channel_id,
            'price_list_id' => $c->price_list_id,
        ]))->assertSessionHasNoErrors();

        $after = $c->fresh();

        $this->assertSame('01055556666', $after->phone);
        $this->assertSame('123-456-789', $after->tax_id, 'بيانات خطوة الضريبة اتمسحت');
        $this->assertSame(45, (int) $after->payment_days, 'شروط الدفع اتمسحت');
    }

    // ═══════════════════ 8. الصلاحيات ═══════════════════

    /** ⚠️ الشاشة دي بتحدد أسعار وشروط دفع — مش لأي حد */
    public function test_a_rep_cannot_create_or_edit_a_client(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient();

        $this->actingAs($rep)->get('/erp/clients/new')->assertForbidden();
        $this->actingAs($rep)->post('/erp/clients', $this->fullPayload())->assertForbidden();
        $this->actingAs($rep)->put('/erp/clients/'.$client->id, $this->fullPayload())->assertForbidden();
    }
}
