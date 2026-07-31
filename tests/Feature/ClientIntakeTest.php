<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Services\ContractIntake;
use App\Support\Governorates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلو تعريف العميل — 3 مراحل
 * ═══════════════════════════════════════════════════════════════
 *
 * الفلو: تعريف (إنجليزي أولاً، قناة، تليفون، محافظة → منطقة، عنوان،
 * لينك) ← العقد وبنوده ← الضريبة.
 *
 * ⚠️ كل تيست هنا بيحرس **قرار**، مش سطر كود. لو حد غيّر السلوك،
 * التيست بيقول القرار كان إيه ومين اتضرر.
 */
class ClientIntakeTest extends TestCase
{
    use RefreshDatabase;

    /** الحقول الإجبارية بالحد الأدنى */
    private function payload(array $extra = []): array
    {
        // ⚠️ **مفيش `category`.** اتشال من فورم العميل الجديد — التصنيف
        // نتيجة سلوك مش مدخل، والعميل الجديد بيبدأ `grow` أوتوماتيك.
        $base = [
            'name' => 'سيركل كيه — المعادي دجلة',
            'name_en' => 'Circle K — Maadi Degla',
            'discount' => 0,
            'price_list' => 'new',
        ];

        // ⚠️ بلوك البنود بيتبعت **دايماً** من الفورم، حتى لو كله مقفول.
        // الفورم الحقيقي بيبعت `on=0` مخفي لكل بند.
        $clauses = [];
        foreach (array_keys(Contract::CLAUSE_PRESETS) as $preset) {
            $clauses[$preset] = ['on' => 0, 'value' => 0];
        }

        return array_replace_recursive($base + ['clause' => $clauses], $extra);
    }

    // ═══════════════════ 1. التعريف ═══════════════════

    public function test_a_client_is_created_with_governorate_and_location_link(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'governorate' => 'cairo',
            'phone' => '01000000000',
            'address' => 'شارع 9، المعادي',
            'location_url' => 'https://maps.app.goo.gl/abc123',
        ]))->assertRedirect();

        $client = Client::firstOrFail();

        $this->assertSame('cairo', $client->governorate);
        $this->assertSame('Circle K — Maadi Degla', $client->name_en);
        $this->assertSame('https://maps.app.goo.gl/abc123', $client->location_url);
        $this->assertSame('https://maps.app.goo.gl/abc123', $client->mapUrl());
    }

    public function test_a_javascript_location_link_is_refused(): void
    {
        // ⚠️ اللينك بيتحط في `href` في كارت العميل. `javascript:` جوه
        // `href` بيشتغل بمجرد الضغط — والمندوب هو اللي بيكتب اللينك.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post('/erp/clients', $this->payload(['location_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('location_url');

        $this->assertSame(0, Client::count());
    }

    public function test_an_unknown_governorate_is_refused(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post('/erp/clients', $this->payload(['governorate' => 'Cairo']))
            ->assertSessionHasErrors('governorate');
    }

    public function test_map_url_falls_back_to_coordinates_and_is_null_when_there_is_nothing(): void
    {
        $withCoords = $this->makeClient(['lat' => 30.0566, 'lng' => 31.3450]);
        $bare = $this->makeClient();

        $this->assertStringContainsString('30.0566', (string) $withCoords->mapUrl());
        // ⚠️ زرار «افتح على الخريطة» اللي بيروح لصفحة فاضية أسوأ من
        // زرار مش موجود — المندوب بيقف قدام العميل ومش لاقي العنوان.
        $this->assertNull($bare->mapUrl());
    }

    // ═══════════════════ 2. المحافظة والمنطقة ═══════════════════

    public function test_zone_governorate_is_guessed_from_its_name(): void
    {
        $this->assertSame('cairo', Governorates::guessFromZone('مدينة نصر', 'Nasr City'));
        $this->assertSame('giza', Governorates::guessFromZone('المهندسين', 'Mohandessin'));
        $this->assertSame('giza', Governorates::guessFromZone('الهرم وفيصل', 'Haram & Faisal'));
        $this->assertSame('sharqia', Governorates::guessFromZone('العاشر من رمضان', 'Tenth of Ramadan'));

        // ⚠️ التخمين الغلط أسوأ من الفاضي — الفاضي بيبان في الشاشة
        // ويتظبط، والغلط بيعدّي في التقرير.
        $this->assertNull(Governorates::guessFromZone('منطقة ٣', 'Area 3'));
        $this->assertNull(Governorates::guessFromZone(null, null));
    }

    public function test_every_governorate_key_has_a_label_in_both_languages(): void
    {
        foreach (Governorates::KEYS as $key) {
            foreach (['ar', 'en'] as $locale) {
                $label = __('geo.gov.'.$key, [], $locale);

                $this->assertNotSame('geo.gov.'.$key, $label,
                    "المحافظة {$key} مالهاش اسم في {$locale}");
            }
        }
    }

    // ═══════════════════ 3. بنود الخصم ═══════════════════

    public function test_checking_the_invoice_discount_clause_sets_the_contract_discount(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_type' => 'agreement',
            'clause' => [
                'invoice_discount' => ['on' => 1, 'value' => 50],
                'quarterly_rebate' => ['on' => 1, 'value' => 3],
                'annual_rebate' => ['on' => 1, 'value' => 2],
                'shelf_rent' => ['on' => 1, 'value' => 24000],
            ],
        ]))->assertRedirect();

        $contract = Contract::firstOrFail();

        // ⚠️ **الخصم على الفاتورة هو الوحيد اللي بينزل على السعر.**
        // لو الربع سنوي والسنوي اتجمعوا معاه، العميل بياخد 55% بدل 50%
        // وبياخد نفس الـ5% تاني في التسوية الدورية.
        $this->assertEqualsWithDelta(0.50, (float) $contract->discount, 0.0001);

        // كل النسب مجمّعة للربحية الحقيقية: 50 + 3 + 2 = 55
        $this->assertEqualsWithDelta(0.55, $contract->totalDeduction(), 0.0001);

        // المبلغ الثابت مابيدخلش في النسب خالص
        $this->assertEqualsWithDelta(24000.0, $contract->annualFees(), 0.01);
    }

    public function test_a_clause_with_zero_value_is_not_stored(): void
    {
        $contract = $this->contract();

        // ⚠️ بند بـ 0% بيبان في كارت العميل كأنه شرط حقيقي، والمندوب
        // بيقول للعميل «عندك خصم ربع سنوي» وهو مفيش.
        ContractIntake::syncClauses($contract, [
            'quarterly_rebate' => ['on' => 1, 'value' => 0],
        ]);

        $this->assertSame(0, ContractClause::count());
    }

    public function test_unchecking_a_clause_removes_it_and_lowers_the_discount(): void
    {
        $contract = $this->contract();

        ContractIntake::syncClauses($contract, [
            'invoice_discount' => ['on' => 1, 'value' => 40],
        ]);
        $this->assertEqualsWithDelta(0.40, (float) $contract->fresh()->discount, 0.0001);

        ContractIntake::syncClauses($contract, [
            'invoice_discount' => ['on' => 0, 'value' => 40],
        ]);

        $this->assertSame(0, ContractClause::where('preset', 'invoice_discount')->count());
        $this->assertEqualsWithDelta(0.0, (float) $contract->fresh()->discount, 0.0001);
    }

    public function test_hand_written_clauses_are_never_touched_by_the_form(): void
    {
        // ⚠️ الـ22 عقد الحقيقيين اتقروا من الـPDF وبنودهم اتكتبت بإيد.
        // لو الفورم قدر يمسح أي بند بنفس النوع، تشغيلة واحدة بتمسح
        // تحليل يومين.
        $contract = $this->contract();

        $manual = $this->manualClause($contract, 'rebate', 'quarterly', 0.03);

        ContractIntake::syncClauses($contract->fresh(), [
            'quarterly_rebate' => ['on' => 0, 'value' => 0],
        ]);

        $this->assertNotNull($manual->fresh(), 'البند المكتوب بإيد ممنوع يتمسح');
    }

    public function test_a_hand_written_invoice_discount_is_never_doubled(): void
    {
        // ⚠️ ده أخطر باج اتلقى في المراجعة: عقد On The Run خصمه 15%
        // مكتوب كبند بإيد. الفورم كان بيلاقي التشيك بوكس متعلّم من
        // `$contract->discount`، وعند الحفظ بيعمل بند **تاني** بنفس
        // النوع، و`recalcFromClauses()` بتجمّعهم = 30%. صامت ودائم.
        $contract = $this->contract();

        $this->manualClause($contract, 'invoice_discount', 'per_invoice', 0.15);
        $contract->fresh()->recalcFromClauses();

        $this->assertEqualsWithDelta(0.15, (float) $contract->fresh()->discount, 0.0001);

        $fresh = $contract->fresh()->load('contractClauses');
        $presets = ContractIntake::currentPresets($fresh);

        // الشاشة بتوريه بقيمته الحقيقية، بس مقفول
        $this->assertTrue($presets['invoice_discount']['on']);
        $this->assertTrue($presets['invoice_discount']['locked']);
        $this->assertEqualsWithDelta(15.0, $presets['invoice_discount']['value'], 0.01);

        // ونرجّع اللي الشاشة عرضته للسيرفر — زي ما المتصفح بيعمل
        ContractIntake::syncClauses($fresh, [
            'invoice_discount' => ['on' => 1, 'value' => 15],
        ]);

        $this->assertSame(1, ContractClause::where('kind', 'invoice_discount')->count(),
            'ممنوع يتعمل بند تاني بنفس النوع');
        $this->assertEqualsWithDelta(0.15, (float) $contract->fresh()->discount, 0.0001,
            'الخصم اتضاعف — البند المكتوب بإيد اتجمع مع بند جاهز');
    }

    public function test_a_checked_clause_with_a_blank_value_is_refused_not_deleted(): void
    {
        // ⚠️ من غير `required_if`، المستخدم اللي علّم البند وعدّى
        // الخانة بالتاب كان بيمسح خصم موجود والشاشة بترجع «اتحفظ»
        // خضرا.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => '']],
        ]))->assertSessionHasErrors('clause.invoice_discount.value');
    }

    public function test_a_percentage_clause_can_never_exceed_one_hundred(): void
    {
        // ⚠️ خصم 150% بيخلّي سعر البيع بالسالب والفاتورة بتطلع بمبلغ
        // سالب من غير ما ترفض.
        $contract = $this->contract();

        ContractIntake::syncClauses($contract, [
            'invoice_discount' => ['on' => 1, 'value' => 150],
        ]);

        $this->assertEqualsWithDelta(1.0, (float) $contract->fresh()->discount, 0.0001);
    }

    public function test_an_old_contract_discount_survives_a_save_from_the_form(): void
    {
        // ⚠️ العقود القديمة خصمها متخزن على العقد من غير بند جاهز. لو
        // الفورم فتح والتشيك بوكس طلع فاضي، أول حفظ كان بينزّل الخصم
        // صفر في صمت والعميل ياخد سعر كامل تاني يوم.
        $contract = $this->contract();
        $contract->forceFill(['discount' => 0.45])->save();

        $presets = ContractIntake::currentPresets($contract->fresh());

        $this->assertTrue($presets['invoice_discount']['on']);
        $this->assertEqualsWithDelta(45.0, $presets['invoice_discount']['value'], 0.01);
    }

    public function test_every_preset_has_a_label_in_both_languages(): void
    {
        foreach (array_keys(Contract::CLAUSE_PRESETS) as $preset) {
            foreach (['ar', 'en'] as $locale) {
                $label = __('client.preset_'.$preset, [], $locale);

                $this->assertNotSame('client.preset_'.$preset, $label,
                    "البند {$preset} مالوش اسم في {$locale}");
            }
        }
    }

    // ═══════════════════ 4. أيام السداد ═══════════════════

    public function test_payment_days_are_counted_from_the_first_supply_by_default(): void
    {
        $client = $this->makeClient(['first_activity_at' => '2026-01-10']);

        $contract = Contract::create([
            'client_id' => $client->id,
            'number' => 'CNT-T1',
            'payment_days' => 60,
            'active' => true,
        ]);

        $this->assertSame(Contract::DAYS_FROM_FIRST_SUPPLY, $contract->paymentBasis());
        $this->assertSame('2026-03-11', $contract->dueDateFor($client)->toDateString());
    }

    public function test_the_invoice_basis_counts_from_the_invoice_date(): void
    {
        $client = $this->makeClient(['first_activity_at' => '2026-01-10']);

        $contract = Contract::create([
            'client_id' => $client->id,
            'number' => 'CNT-T2',
            'payment_days' => 30,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
            'active' => true,
        ]);

        $this->assertSame('2026-06-30', $contract->dueDateFor($client, '2026-05-31')->toDateString());
    }

    public function test_there_is_no_due_date_before_the_first_supply(): void
    {
        // ⚠️ افتراض إن أول توريد هو النهارده كان بيدي ميعاد استحقاق
        // بيتحرك كل يوم، والمتابعة بتلاحق رقم مش ثابت.
        $client = $this->makeClient(['first_activity_at' => null]);

        $contract = Contract::create([
            'client_id' => $client->id,
            'number' => 'CNT-T3',
            'payment_days' => 45,
            'active' => true,
        ]);

        $this->assertNull($contract->dueDateFor($client));
    }

    // ═══════════════════ 4b. رصيد أول المدة والتأخير ═══════════════════

    public function test_the_opening_balance_replaces_itself_instead_of_stacking(): void
    {
        // ⚠️ لو القيد اتزوّد بدل ما يتستبدل، أول تصحيح لرقم غلط بيخلّي
        // رصيد العميل ضعف الحقيقي ومحدش يعرف من فين.
        $admin = $this->makeAdmin();
        $client = $this->makeClient();

        $this->actingAs($admin)->post('/erp/clients/'.$client->id.'/opening', [
            'amount' => 50000, 'date' => '2026-01-01',
        ])->assertRedirect();

        $this->actingAs($admin)->post('/erp/clients/'.$client->id.'/opening', [
            'amount' => 42000, 'date' => '2026-01-01',
        ])->assertRedirect();

        $this->assertSame(1, \App\Models\Transaction::where('kind', 'opening')->count());
        $this->assertEqualsWithDelta(42000.0, (float) $client->fresh()->balance, 0.01);
    }

    public function test_a_negative_opening_balance_is_recorded_as_credit(): void
    {
        // ⚠️ العميل الدافع مقدماً رصيده دائن. لو اتحط مدين بالسالب،
        // كل جمع في كشف الحساب بيطلع غلط لأن الأعمدة مفروض موجبة.
        $client = $this->makeClient();
        $txn = $client->setOpeningBalance(-8000, '2026-01-01');

        $this->assertEqualsWithDelta(0.0, (float) $txn->debit, 0.01);
        $this->assertEqualsWithDelta(8000.0, (float) $txn->credit, 0.01);
        $this->assertEqualsWithDelta(-8000.0, (float) $client->fresh()->balance, 0.01);
    }

    public function test_the_opening_date_becomes_the_first_supply_date(): void
    {
        // ⚠️ التاريخ مش شكلي — هو `first_activity_at`، ومنه بتتحسب
        // أيام السداد لما العقد بيعد «من أول توريد».
        $client = $this->makeClient();
        $client->setOpeningBalance(10000, '2026-05-01');

        $this->assertSame('2026-05-01', $client->fresh()->first_activity_at->toDateString());
    }

    public function test_a_client_inside_his_payment_window_is_not_overdue(): void
    {
        // ⚠️ ده الفرق كله بين الأعمار والتأخير: 45 يوم على عميل بشروط
        // 60 يوم بيبان في خانة «31-60» في الأعمار — بس متأخره صفر.
        $client = $this->makeClient();
        $client->setOpeningBalance(100000, today()->subDays(45)->toDateString());

        Contract::create([
            'client_id' => $client->id,
            'number' => 'CNT-W1',
            'payment_days' => 60,
            'active' => true,
        ]);

        $overdue = $client->fresh()->overdue();

        $this->assertTrue($overdue['has_terms']);
        $this->assertEqualsWithDelta(0.0, $overdue['amount'], 0.01, 'العميل لسه في مدته');

        // ⚠️ والأعمار **مالهاش تتغير** — لسه بتقول الفلوس عمرها 45 يوم.
        // الاتنين بيوصفوا نفس الرصيد بزاويتين، ولازم يفضلوا مستقلين.
        $aging = $client->fresh()->aging();
        $this->assertEqualsWithDelta(100000.0, $aging['a60'], 0.01,
            'الأعمار لازم تفضل شغّالة زي ما هي');
    }

    public function test_a_client_past_his_payment_window_is_overdue(): void
    {
        $client = $this->makeClient();
        $client->setOpeningBalance(100000, today()->subDays(100)->toDateString());

        Contract::create([
            'client_id' => $client->id,
            'number' => 'CNT-W2',
            'payment_days' => 60,
            'active' => true,
        ]);

        $overdue = $client->fresh()->overdue();

        $this->assertEqualsWithDelta(100000.0, $overdue['amount'], 0.01);
        $this->assertSame(40, $overdue['days'], '100 يوم من أول توريد ناقص 60 يوم سداد');
    }

    public function test_a_client_without_payment_days_is_never_flagged_overdue(): void
    {
        // ⚠️ الافتراض إن مفيش شروط = مستحق فوراً كان بيخلّي كل عميل
        // آجل متأخر من أول يوم وكل الشاشة حمرا ومحدش ياخدها بجد.
        $client = $this->makeClient();
        $client->setOpeningBalance(100000, today()->subYear()->toDateString());

        $overdue = $client->fresh()->overdue();

        $this->assertFalse($overdue['has_terms']);
        $this->assertEqualsWithDelta(0.0, $overdue['amount'], 0.01);
    }

    public function test_the_invoice_basis_ages_each_invoice_on_its_own(): void
    {
        $client = $this->makeClient();

        Contract::create([
            'client_id' => $client->id,
            'number' => 'CNT-W3',
            'payment_days' => 30,
            'payment_days_from' => Contract::DAYS_FROM_INVOICE,
            'active' => true,
        ]);

        // فاتورة قديمة (متأخرة) وفاتورة جديدة (لسه في مدتها)
        foreach ([[100, 6000], [5, 4000]] as [$ago, $amount]) {
            \App\Models\Transaction::create([
                'client_id' => $client->id,
                'date' => today()->subDays($ago)->toDateString(),
                'memo' => 'فاتورة',
                'debit' => $amount,
                'credit' => 0,
                'kind' => 'sale',
            ]);
        }

        $client->recalculate();
        $overdue = $client->fresh()->overdue();

        // ⚠️ المتأخر هو القديمة بس — 6000 مش الـ10000 كلها
        $this->assertEqualsWithDelta(6000.0, $overdue['amount'], 0.01);
        $this->assertSame(70, $overdue['days'], '100 يوم ناقص 30 يوم سداد');
    }

    // ═══════════════════ 4c. اللوكيشن والمنطقة والتواصل ═══════════════════

    public function test_coordinates_are_read_from_every_shape_of_maps_link(): void
    {
        $cases = [
            'https://www.google.com/maps/@30.0566,31.3450,17z',
            'https://www.google.com/maps/place/X/@30.0566,31.3450,17z/data=!3m1!4b1!3d30.0566!4d31.3450',
            'https://maps.google.com/?q=30.0566,31.3450',
            'https://www.google.com/maps/search/?api=1&query=30.0566%2C31.3450',
        ];

        foreach ($cases as $url) {
            $p = \App\Support\MapLink::parse($url);

            $this->assertNotNull($p, "مقدرش يقرا: {$url}");
            $this->assertEqualsWithDelta(30.0566, $p['lat'], 0.001, $url);
            $this->assertEqualsWithDelta(31.3450, $p['lng'], 0.001, $url);
        }
    }

    public function test_the_place_pin_wins_over_the_camera_centre(): void
    {
        // ⚠️ `@…` مركز الكاميرا وقت نسخ اللينك، و`!3d…!4d…` هي المكان
        // نفسه. لو المندوب حرّك الخريطة قبل ما ينسخ، الاتنين بيختلفوا
        // والدبوس بيتحط في الشارع اللي جنبه.
        $p = \App\Support\MapLink::parse(
            'https://www.google.com/maps/place/X/@29.9000,31.1000,15z/data=!3d30.0566!4d31.3450',
        );

        $this->assertEqualsWithDelta(30.0566, $p['lat'], 0.001);
    }

    public function test_a_zoom_level_is_not_mistaken_for_a_coordinate(): void
    {
        // ⚠️ `17z` رقم في اللينك ومش إحداثي. لما كان بيتقرا، الدبوس
        // كان بيتحط في نص المحيط الأطلنطي.
        $this->assertNull(\App\Support\MapLink::parse('https://www.google.com/maps/@,17z'));
        $this->assertNull(\App\Support\MapLink::parse('https://example.com/no-coords-here'));
        $this->assertNull(\App\Support\MapLink::parse(null));
    }

    public function test_only_map_hosts_are_ever_fetched_by_the_server(): void
    {
        // ⚠️ من غير الفحص ده، اليوزر بيلزق `http://127.0.0.1/admin`
        // والسيرفر بتاعنا بيروح يطلبه من جوه الشبكة — ده SSRF.
        $this->assertFalse(\App\Support\MapLink::isShort('https://evil.example.com/x'));
        $this->assertFalse(\App\Support\MapLink::isShort('http://127.0.0.1/admin'));
        $this->assertTrue(\App\Support\MapLink::isShort('https://maps.app.goo.gl/AbCdEf'));

        // والـ`expand` بيرفض من غير أي اتصال
        $this->assertNull(\App\Support\MapLink::expand('http://127.0.0.1/admin'));
    }

    public function test_the_detect_endpoint_refuses_a_link_with_no_coordinates(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/erp/geo/resolve', ['url' => 'https://example.com/nothing'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/erp/geo/resolve', ['url' => 'https://www.google.com/maps/@30.05,31.34,17z'])
            ->assertOk()
            ->assertJsonPath('lat', 30.05);
    }

    public function test_a_new_client_always_starts_as_a_growth_opportunity(): void
    {
        // ⚠️ التصنيف اتشال من الفورم لأنه **نتيجة** مش مدخل: بيدفع في
        // مواعيده ولا لأ، بيكبر ولا لأ. تحديده وقت التعريف كان تخمين
        // بيتحوّل لحقيقة — عميل يتعلّم «تحصيل فوري» من يومه الأول
        // ويتقفل عليه الآجل قبل ما يشتري حاجة.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload())->assertRedirect();

        $client = Client::firstOrFail();

        $this->assertSame('grow', $client->category, 'العميل الجديد فرصة لسه مااتجرّبتش');
        $this->assertTrue((bool) $client->is_new);
        $this->assertFalse($client->cashOnly(), 'ممنوع يتقفل عليه الآجل من يومه الأول');
    }

    public function test_the_category_can_still_be_corrected_from_the_client_card(): void
    {
        // ⚠️ لسه مفيش مصنّف أوتوماتيك، فالمدير لازم يقدر يعدّله من
        // الكارت بعد ما يشوف سلوك العميل — وإلا التصنيف بيتجمّد على
        // `grow` للأبد وشاشة المتابعة بتبقى بلا معنى.
        $admin = $this->makeAdmin();
        $client = $this->makeClient(['category' => 'grow']);

        $this->actingAs($admin)->put('/erp/clients/'.$client->id, $this->payload([
            'name' => $client->name,
            'category' => 'danger',
        ]))->assertRedirect();

        $this->assertSame('danger', $client->fresh()->category);
        $this->assertTrue($client->fresh()->cashOnly());
    }

    public function test_a_chain_can_be_created_without_leaving_the_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/erp/groups/quick', [
            'name' => 'أون ذا رن',
            'name_en' => 'On The Run',
        ])->assertStatus(201)->assertJsonStructure(['id', 'name']);

        $group = \App\Models\ClientGroup::where('name', 'أون ذا رن')->firstOrFail();

        $this->assertNotEmpty($group->code);
        // ⚠️ **السلسلة وعاء بيجمّع الفروع وبس** — مالهاش خصم أصلاً
        // (قرار 2026-08-01، مايجريشن `000028`). الخصم على كل فرع.
        $this->assertNull($group->channel_id, 'السلسلة الجديدة اتعملت بقناة محدش اختارها');
    }

    public function test_two_chains_with_the_same_name_do_not_collide_on_the_code(): void
    {
        // ⚠️ الكود مشتق من الاسم. الاسم المكرر كان بيقع على قيد التفرد
        // ويرمي 500 في وش المستخدم وهو في نص تعريف عميل.
        $admin = $this->makeAdmin();

        foreach ([1, 2] as $_) {
            $this->actingAs($admin)
                ->postJson('/erp/groups/quick', ['name' => 'Gourmet'])
                ->assertStatus(201);
        }

        $this->assertSame(2, \App\Models\ClientGroup::where('name', 'Gourmet')->count());
        $this->assertSame(2, \App\Models\ClientGroup::distinct('code')->count('code'));
    }

    public function test_a_zone_can_be_created_without_leaving_the_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson('/erp/zones/quick', [
            'name' => 'المعادي الجديدة',
            'name_en' => 'New Maadi',
            'governorate' => 'cairo',
        ])->assertStatus(201)->assertJsonStructure(['id', 'name', 'governorate']);

        $zone = \App\Models\Zone::where('name', 'المعادي الجديدة')->firstOrFail();

        $this->assertSame('cairo', $zone->governorate);
        $this->assertNotEmpty($zone->code, 'الكود بيتولّد — المستخدم في نص فورم تاني');
    }

    public function test_a_branch_manager_new_zone_lands_in_his_own_branch(): void
    {
        // ⚠️ المنطقة المركزية بتبان لكل الفروع. مدير فرع مالوش يعمل
        // واحدة بالغلط وهو مستعجل في نص تعريف عميل.
        $maadi = \App\Models\Branch::create(['code' => 'MDZ', 'name' => 'المعادي', 'active' => true]);
        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        $this->actingAs($manager)->postJson('/erp/zones/quick', ['name' => 'زون المدير'])
            ->assertStatus(201);

        $this->assertSame($maadi->id, \App\Models\Zone::where('name', 'زون المدير')->value('branch_id'));
    }

    public function test_contacts_are_stored_as_rows_and_empty_ones_are_dropped(): void
    {
        // ⚠️ المفاتيح جاية من `Date.now()` في الشاشة — أرقام كبيرة
        // ومتفرقة. من غير `array_values` بتتخزن ككائن JSON مش مصفوفة،
        // و`contactList()` بترجّع حاجة الشاشة مابتعرفش تلفّ عليها.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'contacts' => [
                1753900000000 => ['name' => 'أحمد سمير', 'role' => 'مدير الفرع', 'phone' => '01000000001'],
                1753900000001 => ['name' => '', 'role' => '', 'phone' => ''],
                1753900000002 => ['name' => 'منى', 'role' => '', 'phone' => '01000000002'],
            ],
        ]))->assertRedirect();

        $contacts = Client::firstOrFail()->contactList();

        $this->assertCount(2, $contacts, 'الصف الفاضي مالوش يتخزن');
        $this->assertSame([0, 1], array_keys($contacts), 'لازم مصفوفة مرقّمة مش كائن');
        $this->assertSame('أحمد سمير', $contacts[0]['name']);
        $this->assertSame('مدير الفرع', $contacts[0]['role']);
        $this->assertNull($contacts[1]['role'], 'الصفة الفاضية بترجع null مش نص فاضي');
    }

    public function test_the_form_sets_the_account_manager_and_never_the_field_rep(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeAdmin(['role' => 'manager', 'name' => 'مدير القناة']);
        $rep = $this->makeRep();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'manager_id' => $manager->id,
            // ⚠️ المندوب بيتخصص من شاشة توزيع المناطق. لو الفورم ده
            // قدر يكتبه، أي حفظ لبيانات العميل كان بيعيد توزيعه من
            // غير ما التوزيع يعرف.
            'rep_id' => $rep->id,
        ]))->assertRedirect();

        $client = Client::firstOrFail();

        $this->assertSame($manager->id, $client->manager_id);
        $this->assertNull($client->rep_id, 'المندوب ممنوع يتكتب من فورم العميل');
    }

    public function test_the_invoice_discount_still_lands_on_the_contract_as_a_plain_field(): void
    {
        // ⚠️ خصم الفاتورة طلع من التشيك بوكسيس لحقل أساسي. الشاشة
        // بتبعت `on=1` ثابتة، فلازم نتأكد إن المسار ده لسه بيوصل
        // لنفس النتيجة.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 35]],
        ]))->assertRedirect();

        $this->assertEqualsWithDelta(0.35, (float) Contract::firstOrFail()->discount, 0.0001);
    }

    // ═══════════════════ 5. ملف العقد ═══════════════════

    public function test_the_uploaded_contract_file_is_stored_outside_the_public_disk(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_file' => UploadedFile::fake()->create('circlek.pdf', 12, 'application/pdf'),
        ]))->assertRedirect();

        $contract = Contract::firstOrFail();

        $this->assertNotNull($contract->file_path);
        // ⚠️ العقد فيه أسعار وشروط تجارية — الديسك العام معناه إن أي
        // حد يعرف اسم الملف يفتحه من غير لوجين.
        $this->assertStringStartsWith('contracts/', $contract->file_path);
        $this->assertStringNotContainsString('public', $contract->file_path);
        $this->assertFileExists(storage_path('app/'.$contract->file_path));

        @unlink(storage_path('app/'.$contract->file_path));
    }

    public function test_an_executable_upload_is_refused(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_file' => UploadedFile::fake()->create('shell.php', 4, 'application/x-php'),
        ]))->assertSessionHasErrors('contract_file');
    }

    // ═══════════════════ 6. الاستنساخ ═══════════════════

    public function test_cloning_copies_the_terms_but_not_the_money(): void
    {
        $admin = $this->makeAdmin();
        $zone = $this->makeZone();
        $channel = $this->makeChannel(0.50);

        $source = $this->makeClient([
            'name' => 'سيركل كيه — التجمع',
            'name_en' => 'Circle K — Fifth Settlement',
            'channel_id' => $channel->id,
            'zone_id' => $zone->id,
            'governorate' => 'cairo',
            'sub_channel' => 'convenience',
            'discount' => 0.55,
            'price_list' => 'old',
            'taxable' => true,
            'tax_rate' => 0.14,
            'tax_cycle' => 'quarterly',
            // أرقام حقيقية — دي اللي **ممنوع** تتنسخ
            'purchases' => 900000,
            'balance' => 120000,
        ]);

        $contract = Contract::create([
            'client_id' => $source->id,
            'number' => 'CNT-SRC',
            'type_key' => 'agreement',
            'payment_days' => 60,
            'active' => true,
        ]);

        ContractIntake::syncClauses($contract, [
            'invoice_discount' => ['on' => 1, 'value' => 55],
            'annual_rebate' => ['on' => 1, 'value' => 2],
        ]);

        // الصفحة بتفتح ومعاها الشروط
        // ⚠️ بنفحص الكود مش الاسم — `displayName()` بترجّع العربي أو
        // الإنجليزي حسب لغة الواجهة، والتيست مايصحّش يتكسّر لو اللغة
        // الافتراضية اتغيّرت.
        $this->actingAs($admin)->get('/erp/clients/'.$source->id.'/clone')
            ->assertOk()
            ->assertSee($source->code);

        // الحفظ بالحقول اللي الفورم بيعبّيها من المصدر + اسم جديد
        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'name' => 'سيركل كيه — مدينتي',
            'name_en' => 'Circle K — Madinaty',
            'channel_id' => $channel->id,
            'sub_channel' => 'convenience',
            'governorate' => 'cairo',
            'discount' => 55,
            'price_list' => 'old',
            'taxable' => 1,
            'tax_rate' => 14,
            'tax_cycle' => 'quarterly',
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_payment_days' => 60,
            'clause' => [
                'invoice_discount' => ['on' => 1, 'value' => 55],
                'annual_rebate' => ['on' => 1, 'value' => 2],
            ],
        ]))->assertRedirect();

        $clone = Client::where('name_en', 'Circle K — Madinaty')->firstOrFail();

        $this->assertEqualsWithDelta(0.55, (float) $clone->discount, 0.0001);
        $this->assertSame('old', $clone->price_list);
        $this->assertSame('quarterly', $clone->tax_cycle);
        $this->assertTrue((bool) $clone->taxable);

        // ⚠️ الفرع الجديد بيفتح **برصيد صفر**. لو الأرقام اتنسخت،
        // بيبان وعليه مديونية فرع تاني وأول كشف حساب بيبقى كذب.
        $this->assertEqualsWithDelta(0.0, (float) $clone->purchases, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $clone->balance, 0.01);

        // ونفس نسب العقد
        $this->assertEqualsWithDelta(0.55, (float) $clone->contract->discount, 0.0001);
        $this->assertEqualsWithDelta(0.57, $clone->contract->totalDeduction(), 0.0001);
    }

    public function test_a_branch_manager_clones_inside_his_branch_but_not_outside(): void
    {
        $maadi = \App\Models\Branch::create(['code' => 'MD', 'name' => 'المعادي', 'active' => true]);
        $giza = \App\Models\Branch::create(['code' => 'GZ', 'name' => 'الجيزة', 'active' => true]);

        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        $mine = $this->makeClient(['branch_id' => $maadi->id]);
        $theirs = $this->makeClient(['branch_id' => $giza->id]);

        // مسموح له في فرعه — الاتفاق إنه «معاه صلاحيات كل حاجة تخص فرعه»
        $this->actingAs($manager)->get('/erp/clients/'.$mine->id.'/clone')->assertOk();

        // ⚠️ الاستنساخ بيعرض **كل** شروط المصدر: خصمه وعقده وبنوده.
        // من غير الحارس ده، مدير فرع بيقرا تسعير فرع تاني بالكامل من
        // صفحة مالهاش علاقة بقايمة العملاء.
        $this->actingAs($manager)->get('/erp/clients/'.$theirs->id.'/clone')->assertForbidden();
    }

    public function test_a_branch_manager_cannot_create_a_client_in_another_branch(): void
    {
        $maadi = \App\Models\Branch::create(['code' => 'MD2', 'name' => 'المعادي', 'active' => true]);
        $giza = \App\Models\Branch::create(['code' => 'GZ2', 'name' => 'الجيزة', 'active' => true]);

        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        // ⚠️ الحارس على القراءة مابيمنعش الكتابة — الفورم ممكن يتبعت
        // بـ `branch_id` بتاع فرع تاني من غير ما الشاشة تعرضه أصلاً.
        $this->actingAs($manager)
            ->post('/erp/clients', $this->payload(['branch_id' => $giza->id]))
            ->assertForbidden();

        $this->assertSame(0, Client::count());
    }

    public function test_a_branch_manager_client_lands_in_his_own_branch_by_default(): void
    {
        $maadi = \App\Models\Branch::create(['code' => 'MD3', 'name' => 'المعادي', 'active' => true]);
        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        // ⚠️ الفرع الفاضي معناه «مركزي بيبان لكل الفروع». لو سبناه
        // فاضي، مدير فرع بيعمل عملاء الشركة كلها تشوفهم من غير ما يقصد.
        $this->actingAs($manager)->post('/erp/clients', $this->payload())->assertRedirect();

        $this->assertSame($maadi->id, Client::firstOrFail()->branch_id);
    }

    // ═══════════════════ 7. الضريبة ═══════════════════

    public function test_the_tax_cycle_is_stored_and_labelled(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', $this->payload([
            'taxable' => 1,
            'tax_rate' => 14,
            'tax_cycle' => 'quarterly',
            'tax_id' => '123-456-789',
        ]))->assertRedirect();

        $client = Client::firstOrFail();

        $this->assertSame('quarterly', $client->tax_cycle);
        $this->assertEqualsWithDelta(0.14, (float) $client->tax_rate, 0.0001);
        $this->assertNotSame('—', $client->taxCycleLabel());
    }

    public function test_an_unknown_tax_cycle_is_refused(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post('/erp/clients', $this->payload(['tax_cycle' => 'weekly']))
            ->assertSessionHasErrors('tax_cycle');
    }

    // ═══════════════════ 8. التعديل من كارت العميل ═══════════════════

    public function test_saving_a_chain_branch_card_does_not_create_a_shadow_contract(): void
    {
        // ⚠️ فرع Circle K متغطي بعقد السلسلة (30% خصم + 25% حجز ضمان).
        // المودال كان بيتعبّى من العقد **الموروث** ويعلّم «فيه عقد»،
        // فأول حفظ — حتى لو بس لتصليح تليفون — كان بيعمل عقد خاص
        // بخصم صفر بيحجب عقد السلسلة والفرع يفقد الـ30% في صمت.
        $admin = $this->makeAdmin();

        $group = \App\Models\ClientGroup::create([
            'code' => 'CIRCLEK', 'name' => 'سيركل كيه', 'name_en' => 'Circle K', 'active' => true,
        ]);

        $groupContract = Contract::create([
            'group_id' => $group->id,
            'number' => 'CNT-GRP',
            'discount' => 0.30,
            'active' => true,
        ]);

        $branch = $this->makeClient(['group_id' => $group->id, 'name_en' => 'Circle K — Degla']);

        $this->assertNotNull($branch->liveContract(), 'الفرع لازم يورث عقد السلسلة');

        // الكارت بيفتح، والمستخدم بيصلّح التليفون بس
        $this->actingAs($admin)->get('/erp/clients/'.$branch->id)->assertOk();

        $this->actingAs($admin)->put('/erp/clients/'.$branch->id, $this->payload([
            'name' => $branch->name,
            'name_en' => $branch->name_en,
            'group_id' => $group->id,
            'phone' => '01111111111',
            'category' => $branch->category,
            'has_contract' => 0,
        ]))->assertRedirect();

        $branch->refresh();

        $this->assertNull($branch->contract, 'ممنوع يتعمل عقد خاص للفرع');
        $this->assertSame($groupContract->id, $branch->liveContract()?->id,
            'الفرع لازم يفضل على عقد السلسلة');
        $this->assertEqualsWithDelta(0.30, $branch->effectiveDiscount(), 0.0001,
            'الفرع فقد خصم السلسلة');
    }

    public function test_a_branch_manager_saving_a_central_client_does_not_annex_it(): void
    {
        // ⚠️ كل العملاء القدام مركزيين (`branch_id = null`) — الشركة
        // كانت فرع واحد. لو الحفظ حوّل الفاضي لفرع المدير، كل عميل
        // بيفتح كارته ويتحفظ كان بيختفي من باقي الفروع ومن التقارير
        // المركزية، في صمت.
        $maadi = \App\Models\Branch::create(['code' => 'MD4', 'name' => 'المعادي', 'active' => true]);
        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        $central = $this->makeClient(['branch_id' => null]);

        $this->actingAs($manager)->put('/erp/clients/'.$central->id, $this->payload([
            'name' => $central->name,
            'category' => $central->category,
        ]))->assertRedirect();

        $this->assertNull($central->fresh()->branch_id, 'العميل المركزي اتضم لفرع المدير');
    }

    public function test_a_client_without_an_eta_type_keeps_it_empty_after_a_save(): void
    {
        // ⚠️ القايمة كانت من غير خيار فاضي وبتختار `B` افتراضياً، فأي
        // حفظ كان بيختم العميل «شخص اعتباري» والتصدير للمصلحة يطلع غلط.
        $admin = $this->makeAdmin();
        $client = $this->makeClient(['eta_type' => null]);

        $this->actingAs($admin)->put('/erp/clients/'.$client->id, $this->payload([
            'name' => $client->name,
            'category' => $client->category,
        ]))->assertRedirect();

        $this->assertNull($client->fresh()->eta_type);
    }

    // ═══════════════════ 9. الراوتات ═══════════════════

    public function test_a_field_user_cannot_download_a_signed_contract(): void
    {
        // ⚠️ الراوت كان جوه `auth` بس من غير `role:` — أي مندوب معاه
        // بيانات دخول للويب كان بينزّل الـ22 عقد بأسعارهم وشروطهم.
        $rep = $this->makeRep();
        $contract = $this->contract();

        $this->actingAs($rep)
            ->get('/erp/contracts/'.$contract->id.'/file')
            ->assertForbidden();
    }

    public function test_a_branch_manager_cannot_download_another_branch_contract(): void
    {
        $maadi = \App\Models\Branch::create(['code' => 'MD5', 'name' => 'المعادي', 'active' => true]);
        $giza = \App\Models\Branch::create(['code' => 'GZ5', 'name' => 'الجيزة', 'active' => true]);

        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);
        $contract = $this->contract($this->makeClient(['branch_id' => $giza->id]));

        $this->actingAs($manager)
            ->get('/erp/contracts/'.$contract->id.'/file')
            ->assertForbidden();
    }


    public function test_the_new_client_page_is_not_swallowed_by_the_client_route(): void
    {
        // ⚠️ `/clients/new` لازم يتعرّف **قبل** `/clients/{client}`.
        // لو الترتيب اتعكس، لارافيل بيدوّر على عميل كوده «new» ويرمي 404.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/erp/clients/new')->assertOk();
    }

    public function test_a_field_user_cannot_open_the_new_client_page(): void
    {
        $rep = $this->makeRep();

        $this->actingAs($rep)->get('/erp/clients/new')->assertForbidden();
    }

    // ═══════════════════ أدوات ═══════════════════

    private function contract(?Client $client = null): Contract
    {
        $client ??= $this->makeClient();

        return Contract::create([
            'client_id' => $client->id,
            'number' => Contract::nextNumber(),
            'active' => true,
        ]);
    }

    /** بند مقروء من الـPDF ومكتوب بإيد — من غير `preset` */
    private function manualClause(Contract $contract, string $kind, string $basis, float $pct): ContractClause
    {
        return ContractClause::create([
            'contract_id' => $contract->id,
            'kind' => $kind,
            'basis' => $basis,
            'label' => 'بند من العقد الأصلي',
            'label_en' => 'Clause from the signed contract',
            'pct' => $pct,
            'is_alternative' => false,
            'is_uncertain' => false,
            'preset' => null,
        ]);
    }
}
