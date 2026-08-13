<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * سلامة فورم العميل — كل خانة توصل لعمودها
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده اتكتب بعد `Unknown column 'manager_id'` على شاشة
 * حفظ عميل حقيقي.** العمود كان في المايجريشن بس المايجريشن مااتشغلتش،
 * والسيستم كله عدّى — الفورم رسم الحقل، والتحقق قبله، والموديل حطّه
 * في `$fillable`، ومحدش سأل «العمود ده موجود أصلاً؟» غير MySQL في
 * وش المستخدم.
 *
 * التيستات هنا **بنيوية**: بتقارن الفورم بالقواعد بالأعمدة، فأي حقل
 * جديد ناقصه حلقة بيقع هنا مش في الإنتاج.
 */
class ClientFormIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** حقول بتوصل للفورم وليها معنى بس مش أعمدة في `clients` */
    private const FORM_ONLY = [
        'has_contract', 'contract_type', 'contract_duration', 'contract_payment_days',
        'contract_payment_days_from', 'contract_starts_at', 'contract_ends_at',
        'contract_note', 'contract_clauses', 'contract_file', 'clause',
        'cloned_from', '_token', '_method',
    ];

    /**
     * خانات على نفس الصفحة بس تابعة **لفورم تاني بإندبوينت تاني** —
     * فقواعدها مش في `ErpController::clientRules()`.
     *
     * ⚠️ **كارت العميل (`erp/client`) مابقاش فيه فورم تعريف خالص**
     * (المحرّر اتنقل للويزارد `erp/client_form`). اللي فاضل فيه
     * مودالين بس:
     *   • رصيد أول المدة → `erp.clients.opening`
     *     (`ErpController::openingBalance`): amount · date · memo
     *   • تحصيل من الكارت → `erp.clients.collect`
     *     (`OpsController::collect`): amount · date · memo · method ·
     *     reference · cheque_bank · cheque_due
     *
     * القواعد بتتفحص في مكانها الصح — `OpsControllerTest`/`LedgerTest`
     * بيغطوا التحصيل. اللي هنا موضوعه فورم العميل بس.
     *
     * @var array<string, list<string>>
     */
    private const OTHER_FORMS = [
        'erp/client' => [
            'amount', 'date', 'memo',
            'method', 'reference', 'cheque_bank', 'cheque_due',
        ],
        'erp/client_form' => ['amount', 'date', 'memo'],
    ];

    // ═══════════════════ 1. القواعد مقابل الأعمدة ═══════════════════

    public function test_every_validated_field_has_a_real_column(): void
    {
        $rules = $this->clientRules();
        $missing = [];

        foreach (array_keys($rules) as $key) {
            // القواعد المتفرّعة (`contacts.*.name`) بتتفحص بجذرها
            $root = explode('.', $key)[0];

            if (in_array($root, self::FORM_ONLY, true)) {
                continue;
            }

            if (! Schema::hasColumn('clients', $root)) {
                $missing[] = $root;
            }
        }

        $this->assertSame([], array_values(array_unique($missing)),
            'حقول بتتحقق ومالهاش أعمدة — المايجريشن ناقصة أو مااتشغلتش');
    }

    public function test_every_validated_field_is_fillable_or_deliberately_not(): void
    {
        // ⚠️ الحقل اللي بيعدّي التحقق ومش في `$fillable` بيتجاهل في
        // **صمت** — لا خطأ ولا رسالة. المستخدم بيكتب ويحفظ ويشوف
        // «اتحفظ» والخانة بترجع فاضية تاني يوم.
        $fillable = (new Client)->getFillable();
        $silent = [];

        foreach (array_keys($this->clientRules()) as $key) {
            $root = explode('.', $key)[0];

            if (in_array($root, self::FORM_ONLY, true)) {
                continue;
            }

            if (! in_array($root, $fillable, true)) {
                $silent[] = $root;
            }
        }

        $this->assertSame([], array_values(array_unique($silent)),
            'حقول بتتحقق ومش في fillable — بتتجاهل في صمت');
    }

    // ═══════════════════ 2. الشاشة مقابل القواعد ═══════════════════

    /**
     * كل `name="..."` في الفورمين لازم يكون متحقق منه.
     *
     * ⚠️ الحقل اللي مالوش قاعدة **مابيوصلش للموديل خالص** — `validate()`
     * بترجّع المفاتيح المتحقق منها بس. الخانة بتتكتب وبتتبعت وبتترمي،
     * والشاشة بتقول «اتحفظ».
     */
    public function test_every_input_on_screen_is_validated(): void
    {
        $rules = array_keys($this->clientRules());
        $orphans = [];

        foreach ($this->formFieldNames() as $file => $names) {
            foreach ($names as $name) {
                if (in_array($name, self::FORM_ONLY, true)) {
                    continue;
                }

                // `contacts[3][name]` ⇐ `contacts.*.name`
                $dotted = preg_replace('/\[\d+\]|\[\{\{[^}]*\}\}\]/', '.*', $name);
                $dotted = str_replace(['[', ']'], ['.', ''], (string) $dotted);
                $dotted = preg_replace('/\.+/', '.', $dotted);
                $dotted = rtrim((string) $dotted, '.');

                $root = explode('.', $dotted)[0];

                $known = in_array($dotted, $rules, true)
                    || in_array($root, $rules, true)
                    || $this->matchesWildcard($dotted, $rules);

                if (! $known) {
                    $orphans[] = $file.' :: '.$name;
                }
            }
        }

        $this->assertSame([], $orphans, 'خانات على الشاشة مالهاش قواعد — بتترمي في صمت');
    }

    // ═══════════════════ 3. رحلة كاملة: كل خانة تتملى وتترجع ═══════════════════

    public function test_a_fully_filled_form_persists_every_single_field(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeAdmin(['role' => 'manager', 'name' => 'مدير القناة']);
        $branch = Branch::create(['code' => 'FULL', 'name' => 'فرع كامل', 'active' => true]);
        $zone = $this->makeZone();
        $channel = $this->makeChannel(0.50);
        $group = ClientGroup::create(['code' => 'CK', 'name' => 'سيركل كيه', 'active' => true]);

        $payload = [
            // ─── مرحلة 1: التعريف ───
            'name' => 'بيت الجملة — التجمع',
            'name_en' => 'BaiT El Gomla — New Cairo',
            'phone' => '01110510260',
            'governorate' => 'cairo',
            'address' => 'شارع التسعين، التجمع',
            'location_url' => 'https://www.google.com/maps/@30.8798375,29.6161406,808m',
            'zone_id' => $zone->id,
            'channel_id' => $channel->id,
            'sub_channel' => 'chain',
            'branch_id' => $branch->id,
            'group_id' => $group->id,
            'manager_id' => $manager->id,
            'lat' => 30.8798375,
            'lng' => 29.6161406,
            'contacts' => [
                7 => ['name' => 'محمد أحمد', 'role' => 'مدير عام', 'phone' => '011105106515'],
            ],
            'notes' => 'ملاحظات على العميل',

            // ─── مرحلة 2: التسعير والعقد ───
            // ⚠️ **`price_list_id` بقى `required`** (2026-08-07) —
            // والعمود النصي `price_list` بيتزامن منه في `clientFields`،
            // فبعت النص لوحده كان بيترفض ويخلّي كل اللي تحت يقع على
            // `Client::firstOrFail()`.
            'price_list' => 'old',
            'price_list_id' => $this->makePriceList('old')->id,
            'discount' => 55,
            'has_contract' => 1,
            // ⚠️ **المدة `year` مش `open`.** التيست ده موضوعه إن
            // **كل خانة** تترجع زي ما اتكتبت، والتواريخ تحت جزء منه:
            // 2026-07-01 ← 2027-06-30 دي سنة بالظبط. `open` كانت
            // بترفض المستند كله (`Contract::checkDuration`: «مفتوح
            // بنهاية» غلط) وكمان بتصفّر `ends_at`.
            'contract_duration' => 'year',
            'contract_type' => 'supply',
            'contract_payment_days' => 60,
            'contract_payment_days_from' => Contract::DAYS_FROM_INVOICE,
            'contract_starts_at' => '2026-07-01',
            'contract_ends_at' => '2027-06-30',
            'contract_note' => 'ملاحظات على العقد',
            'contract_clauses' => ['بند حر أول', 'بند حر تاني'],
            'clause' => [
                'invoice_discount' => ['on' => 1, 'value' => 55],
                'quarterly_rebate' => ['on' => 1, 'value' => 3, 'note' => 'ربع سنوي'],
                'annual_rebate' => ['on' => 1, 'value' => 2],
                'collection_fee' => ['on' => 1, 'value' => 1],
                'withholding' => ['on' => 1, 'value' => 25],
                'shelf_rent' => ['on' => 1, 'value' => 24000],
                'magazine' => ['on' => 1, 'value' => 12000],
                'listing_fee' => ['on' => 1, 'value' => 5000],
                'opening_fee' => ['on' => 1, 'value' => 3000],
            ],

            // ─── مرحلة 3: الضريبة ───
            'taxable' => 1,
            'tax_rate' => 14,
            'tax_cycle' => 'quarterly',
            'tax_id' => '123-456-789',
            'eta_type' => 'B',
        ];

        $this->actingAs($admin)->post('/erp/clients', $payload)->assertRedirect();

        $c = Client::firstOrFail();

        // ─── التعريف ───
        $this->assertSame('بيت الجملة — التجمع', $c->name);
        $this->assertSame('BaiT El Gomla — New Cairo', $c->name_en);
        $this->assertSame('01110510260', $c->phone);
        $this->assertSame('cairo', $c->governorate);
        $this->assertSame('شارع التسعين، التجمع', $c->address);
        $this->assertStringContainsString('google.com/maps', (string) $c->location_url);
        $this->assertSame($zone->id, $c->zone_id);
        $this->assertSame($channel->id, $c->channel_id);
        $this->assertSame('chain', $c->sub_channel);
        $this->assertSame($branch->id, $c->branch_id);
        $this->assertSame($group->id, $c->group_id);
        $this->assertSame($manager->id, $c->manager_id);
        $this->assertEqualsWithDelta(30.8798375, (float) $c->lat, 0.0000001);
        $this->assertEqualsWithDelta(29.6161406, (float) $c->lng, 0.0000001);
        $this->assertSame('ملاحظات على العميل', $c->notes);

        // جهات التواصل — مصفوفة مرقّمة، مش كائن
        $contacts = $c->contactList();
        $this->assertCount(1, $contacts);
        $this->assertSame('محمد أحمد', $contacts[0]['name']);
        $this->assertSame('مدير عام', $contacts[0]['role']);
        $this->assertSame('011105106515', $contacts[0]['phone']);

        // ─── اللي بيتحدد أوتوماتيك ───
        $this->assertSame('grow', $c->category);
        $this->assertTrue((bool) $c->is_new);
        $this->assertSame('active', $c->status);
        $this->assertSame($admin->id, $c->created_by);
        $this->assertNotEmpty($c->code);
        $this->assertNull($c->rep_id, 'المندوب بيتخصص من شاشة التوزيع');

        // ─── التسعير ───
        $this->assertSame('old', $c->price_list);
        $this->assertEqualsWithDelta(0.55, (float) $c->discount, 0.0001);
        // ⚠️ العمود `uses_channel_discount` **مهجور** — القناة مابقاش
        // لها نسبة. بنتأكد إن الرقم جاي من الاتفاق مش من القناة.
        $this->assertEqualsWithDelta(0.55, $c->effectiveDiscount(), 0.0001);
        // ⚠️ **`contract` مش `custom_discount`** — الفورم ده بيعمل عقد
        // سارٍ بخصم فاتورة 55%، وترتيب `effectiveDiscount` بيحط العقد
        // قبل الخصم الخاص (عقيدة التسعير). الاتنين 55% هنا، والمهم
        // إن المصدر **اتفاق مكتوب** مش القناة.
        $this->assertSame('contract', $c->discountSourceKey());

        // ─── الضريبة ───
        $this->assertTrue((bool) $c->taxable);
        $this->assertEqualsWithDelta(0.14, (float) $c->tax_rate, 0.0001);
        $this->assertSame('quarterly', $c->tax_cycle);
        $this->assertSame('123-456-789', $c->tax_id);
        $this->assertSame('B', $c->eta_type);

        // ─── العقد ───
        $ct = $c->contract;
        $this->assertNotNull($ct, 'العقد مااتعملش');
        $this->assertSame('supply', $ct->type_key);
        $this->assertSame(60, $ct->paymentDays());
        $this->assertSame(Contract::DAYS_FROM_INVOICE, $ct->paymentBasis());
        $this->assertSame('2026-07-01', $ct->starts_at->toDateString());
        $this->assertSame('2027-06-30', $ct->ends_at->toDateString());
        $this->assertSame('ملاحظات على العقد', $ct->note);
        $this->assertSame(['بند حر أول', 'بند حر تاني'], $ct->clauseList());
        $this->assertSame('سيركل كيه', $ct->chain, 'اسم السلسلة بيتقرا من مجموعة العميل');

        // ─── البنود التسعة ───
        $this->assertSame(9, $ct->contractClauses()->count(), 'كل بند اتعلّم لازم يتخزن');

        // خصم الفاتورة هو الوحيد اللي على السعر
        $this->assertEqualsWithDelta(0.55, (float) $ct->discount, 0.0001);
        // 55 + 3 + 2 + 1 = 61 (خصم فاتورة + دوري + دوري + تحصيل)
        $this->assertEqualsWithDelta(0.61, $ct->totalDeduction(), 0.0001);
        $this->assertEqualsWithDelta(0.25, (float) $ct->withholding_pct, 0.0001);
        // أرفف 24000 + مجلات 12000 + تكويد 5000 + افتتاح 3000
        $this->assertEqualsWithDelta(44000.0, $ct->annualFees(), 0.01);
    }

    public function test_the_leanest_possible_form_still_saves(): void
    {
        // ⚠️ الطرف التاني: مندوب مستعجل كتب اللي الشاشة بتطلبه بنجمة
        // وضغط حفظ. لازم يعدّي بقيم افتراضية معقولة مش يرمي خطأ على
        // 12 خانة.
        //
        // ⚠️ **الحد الأدنى اتوسّع** (قرار المالك 2026-08-08): الاسم
        // الإنجليزي والقناة بقوا إجباريين على السيرفر كمان. الفورم كان
        // عليه `data-req` ونجمة من زمان — يعني المتصفح بيمنع واللي
        // بيبعت من غير المتصفح بيعدّي، والقاعدة الجديدة: **أي حاجة
        // الشاشة بتقول عليها إجبارية لازم السيرفر يرفضها فاضية**.
        //
        // الباقي لسه بيتملّى لوحده: التصنيف `grow`، الحالة `pending`،
        // الكود، وشروط الدفع من القناة.
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', [
            'name' => 'محل صغير',
            'name_en' => 'Small Shop',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id,
            'discount' => 0,
        ])->assertRedirect();

        $c = Client::firstOrFail();

        $this->assertSame('grow', $c->category);
        // ⚠️ خصم صفر = **سعر القائمة كامل**، مش خصم القناة.
        $this->assertEqualsWithDelta(0.0, $c->effectiveDiscount(), 0.0001);
        $this->assertNull($c->contract, 'مفيش عقد من غير ما يتعلّم');
        $this->assertSame([], $c->contactList());
    }

    // ═══════════════════ أدوات ═══════════════════

    /** قواعد الفورم من الكنترولر نفسه — مش نسخة منها */
    private function clientRules(): array
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\ErpController::class, 'clientRules');
        $method->setAccessible(true);

        return $method->invoke(app(\App\Http\Controllers\ErpController::class));
    }

    /**
     * أسماء كل الخانات في الفورمين.
     *
     * ⚠️ بيقرا الـBlade كنص عن قصد. أي طريقة تانية (رندر الصفحة) بتحتاج
     * داتا وبتخبّي الحقول اللي جوه شرط، والحقل المخبّي هو بالظبط اللي
     * بيتنسى.
     *
     * @return array<string, array<int, string>>
     */
    private function formFieldNames(): array
    {
        $out = [];

        foreach (['erp/client_form', 'erp/client'] as $view) {
            $path = resource_path('views/'.$view.'.blade.php');

            if (! is_file($path)) {
                continue;
            }

            $html = (string) file_get_contents($path);

            preg_match_all('/name="([^"]+)"/', $html, $m);

            $out[$view] = array_values(array_filter(
                array_unique($m[1]),
                fn ($n) => ! in_array($n, self::OTHER_FORMS[$view] ?? [], true),
            ));
        }

        return $out;
    }

    private function matchesWildcard(string $key, array $rules): bool
    {
        foreach ($rules as $rule) {
            if (! str_contains($rule, '*')) {
                continue;
            }

            $re = '/^'.str_replace(['\*', '\.'], ['[^.]+', '\.'], preg_quote($rule, '/')).'$/';

            if (preg_match($re, $key)) {
                return true;
            }
        }

        return false;
    }

    // ═══════════════ 6. الأخطاء بترجع للمستخدم شغّالة ═══════════════

    /**
     * كل دروب داون في فورم العميل بيبدأ باختيار فاضي.
     *
     * ⚠️ **من غير الاختيار الفاضي، أول عنصر بيبان كأنه المختار.** اللي
     * بيدخل الداتا بيعدّي عليه من غير ما يقرا، والقيمة بتتحفظ كأنه
     * قررها. ده اللي كان بيخلّي كل عقد جديد يطلع «اتفاق» — وبعد شهور
     * محدش يعرف ده كان عقد توريد ولا موزع معتمد.
     */
    public function test_every_dropdown_starts_with_an_empty_choice(): void
    {
        $screens = [
            'erp/client_form.blade.php',
            'erp/client.blade.php',
        ];

        // ⚠️ التصنيف مستثنى: العميل دايماً عليه تصنيف (بيبدأ `grow`)،
        // فالخانة بتتعدّل مش بتتملى لأول مرة.
        $skip = ['category'];

        $bad = [];

        foreach ($screens as $screen) {
            $html = (string) file_get_contents(resource_path('views/'.$screen));

            preg_match_all('/<select\b[^>]*name="([^"]+)"[^>]*>(.{0,800}?)<\/select>/s', $html, $m, PREG_SET_ORDER);

            foreach ($m as [, $name, $body]) {
                if (in_array($name, $skip, true)) {
                    continue;
                }

                if (! preg_match('/<option[^>]*value="([^"]*)"/', $body, $first) || $first[1] !== '') {
                    $bad[] = $screen.' → '.$name;
                }
            }
        }

        $this->assertSame([], $bad,
            'دروب داون من غير اختيار فاضي أول القايمة — أول عنصر بيتحفظ من غير ما حد يختاره');
    }

    /**
     * الحقول اللي الشاشة بتقول عليها إجبارية إجبارية فعلاً في القواعد.
     *
     * ⚠️ نجمة حمرا على خانة السيرفر بيقبلها فاضية = كدب في الواجهة.
     * والعكس أسوأ: خانة إجبارية من غير نجمة، المستخدم بيسيبها ويرجع
     * بصفحة أخطاء مش فاهم ليه.
     */
    public function test_the_star_on_screen_matches_the_server_rules(): void
    {
        $html = (string) file_get_contents(resource_path('views/erp/client_form.blade.php'));

        // ⚠️ `data-req` معناها «الجافاسكربت بيوقف الإرسال من غيرها».
        // فلازم السيرفر يوقفها كمان، وإلا الحماية في المتصفح بس —
        // وأي حفظ من الـAPI أو من غير جافاسكربت بيعدّي.
        // ⚠️ `\bdata-req\b` لوحدها بتمسك `data-req-contract` كمان —
        // الشرطة حد كلمة. لازم نقطع صراحةً.
        preg_match_all('/<(?:input|select|textarea)\b[^>]*\bdata-req(?![\w-])[^>]*>/', $html, $m);

        $jsRequired = [];

        foreach ($m[0] as $tag) {
            if (! preg_match('/name="([^"]+)"/', $tag, $nm)) {
                continue;
            }

            // ⚠️ الاسم المتشعّب لازم يترجم لمفتاح القاعدة:
            // `clause[invoice_discount][value]` → `clause.invoice_discount.value`
            $jsRequired[] = str_replace(['][', '[', ']'], ['.', '.', ''], $nm[1]);
        }

        $rules = $this->clientRules();
        $notEnforced = [];

        foreach (array_unique($jsRequired) as $field) {
            $set = (array) ($rules[$field] ?? []);
            $isRequired = false;

            foreach ($set as $rule) {
                if (is_string($rule) && str_starts_with($rule, 'required')) {
                    $isRequired = true;
                }
            }

            if (! $isRequired) {
                $notEnforced[] = $field;
            }
        }

        // ⚠️ `name_en` و`channel_id` مستثنيين عن قصد: الجافاسكربت
        // بيطلبهم للعميل الجديد، بس القواعد سايباهم `nullable` عشان
        // الـ103 عميل القدام اتعملوا قبل القاعدة دي وأي حفظ لواحد
        // فيهم كان هيترفض.
        $notEnforced = array_values(array_diff($notEnforced, ['name_en', 'channel_id']));

        $this->assertSame([], $notEnforced,
            'خانات الشاشة بتوقف عليها والسيرفر بيقبلها فاضية: '.implode(', ', $notEnforced));
    }

    /**
     * الحفظ الفاشل بيرجع كل اللي المستخدم كتبه.
     *
     * ⚠️ **ده كان أسوأ جزء في الفورم.** المستخدم بيملا 20 خانة، خانة
     * واحدة غلط، وبيرجع لصفحة فاضية يكتب من الأول. النتيجة إن حد
     * بيسيب الشاشة ويسجّل العميل ناقص من مكان تاني.
     */
    public function test_a_failed_save_returns_everything_the_user_typed(): void
    {
        $this->actingAs($this->makeAdmin());

        $payload = [
            'name' => 'سيركل كيه — تجربة',
            'name_en' => 'Circle K — Test',
            'phone' => '01000000000',
            'address' => 'شارع 9، المعادي',
            'notes' => 'ملاحظة مهمة اتكتبت بإيد',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id,
            'discount' => 5,
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            // ⚠️ نوع العقد ناقص عن قصد — ده اللي بيفشّل الحفظ
            'contract_payment_days' => 60,
            'contract_payment_days_from' => 'first_supply',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 12]],
        ];

        $response = $this->from(route('erp.clients.new'))
            ->post(route('erp.clients.store'), $payload);

        $response->assertSessionHasErrors('contract_type');

        // كل حاجة كتبها لازم ترجع معاه
        foreach (['name', 'name_en', 'phone', 'address', 'notes',
            'contract_payment_days', ] as $field) {
            $this->assertSame($payload[$field], session('_old_input')[$field] ?? null,
                "«{$field}» ضاع بعد فشل الحفظ — المستخدم هيكتبه تاني");
        }

        // ⚠️ والبنود المتشعّبة كمان. دي بالذات كانت بتضيع لأن الفورم
        // بيقراها من `$presetVal()` مش من `old()`.
        $this->assertSame(12, (int) (session('_old_input')['clause']['invoice_discount']['value'] ?? 0),
            'خصم الفاتورة ضاع بعد فشل الحفظ');

        // ومفيش عميل اتعمل
        $this->assertDatabaseMissing('clients', ['name_en' => 'Circle K — Test']);
    }

    /**
     * العقد من غير نوع بيترفض، والعقد بنوع بيعدّي.
     *
     * ⚠️ العميل اللي مالوش عقد بيعدّي من غير نوع — `required_if` مش
     * `required`. لو بقت `required`، كل عميل كاش فان عادي هيترفض.
     */
    public function test_contract_type_is_required_only_when_there_is_a_contract(): void
    {
        $this->actingAs($this->makeAdmin());

        $base = ['name' => 'عميل من غير عقد', 'name_en' => 'No Contract Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0];

        // من غير عقد → بيعدّي
        $this->post(route('erp.clients.store'), $base)
            ->assertSessionHasNoErrors();

        // بعقد من غير نوع → بيترفض
        // ⚠️ `array_merge` مش `+`. الجمع بيسيب قيم الشمال، فالأسماء
        // الجديدة كانت بتتلغي والطلب بيتبعت بنفس بيانات اللي قبله.
        $this->post(route('erp.clients.store'), array_merge($base, [
            'name' => 'عميل بعقد', 'name_en' => 'With Contract Co',
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 10]],
        ]))->assertSessionHasErrors('contract_type');

        // بعقد بنوع → بيعدّي
        $this->post(route('erp.clients.store'), [
            'name' => 'عميل بعقد كامل', 'name_en' => 'Full Contract Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0, 'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_type' => array_key_first(Contract::TYPE_KEYS),
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 10]],
        ])->assertSessionHasNoErrors();
    }

    /**
     * خصم الفاتورة إجباري مع العقد — وبيقبل صفر.
     *
     * ⚠️ هو البند الوحيد اللي بينزل على سعر البيع. عقد اتحفظ من غيره
     * معناه إن أول فاتورة بتطلع بسعر كامل والعميل بيرفض الاستلام.
     * وبيقبل صفر عن قصد: فيه عملاء فعلاً على سعر القائمة.
     */
    public function test_invoice_discount_is_required_with_a_contract_but_zero_is_allowed(): void
    {
        $this->actingAs($this->makeAdmin());

        $base = [
            'name' => 'عميل', 'name_en' => 'Client A',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0, 'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_type' => array_key_first(Contract::TYPE_KEYS),
        ];

        $this->post(route('erp.clients.store'), $base)
            ->assertSessionHasErrors('clause.invoice_discount.value');

        $this->post(route('erp.clients.store'), array_merge($base, [
            'name_en' => 'Client B',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 0]],
        ]))->assertSessionHasNoErrors();
    }

    /**
     * أيام السداد من غير أساس بترفض.
     *
     * ⚠️ 60 يوم من أول توريد غير 60 يوم من كل فاتورة. الرقم لوحده
     * بيولّد استحقاقات بمواعيد مالهاش أساس حد قرره.
     */
    public function test_payment_days_without_a_basis_are_rejected(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('erp.clients.store'), [
            'name' => 'عميل', 'name_en' => 'Days Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0, 'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_type' => array_key_first(Contract::TYPE_KEYS),
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 5]],
            'contract_payment_days' => 45,
        ])->assertSessionHasErrors('contract_payment_days_from');
    }

    /**
     * كل رسالة خطأ بتطلع باسم الخانة زي ما هي على الشاشة — مش باسم العمود.
     *
     * ⚠️ **ده كان بيطلع «The name_en field is required.»** — واللي
     * بيدخل الداتا مش عارف `name_en` دي مين ولا هي فين في الشاشة.
     */
    public function test_error_messages_name_the_field_the_user_sees(): void
    {
        $fields = array_keys($this->clientRules());

        foreach (['ar', 'en'] as $locale) {
            $attributes = (array) __('validation.attributes', [], $locale);

            $missing = [];

            foreach ($fields as $field) {
                // الحقول المتشعّبة بتتغطى بمفتاحها الكامل أو بجذرها
                $root = explode('.', $field)[0];

                if (! isset($attributes[$field]) && ! isset($attributes[$root])) {
                    $missing[] = $field;
                }
            }

            $this->assertSame([], $missing,
                "lang/{$locale}/validation.php — حقول رسالتها هتطلع باسم العمود: ".implode(', ', $missing));
        }
    }

    /**
     * الرسايل نفسها متترجمة — مش الأسماء بس.
     */
    public function test_the_validation_messages_themselves_are_translated(): void
    {
        $this->assertNotSame('validation.required', __('validation.required', [], 'ar'),
            'رسالة `required` مش متترجمة — هتطلع بالمفتاح الخام');

        $this->assertStringContainsString('مطلوب', (string) __('validation.required', [], 'ar'));

        // ⚠️ `max` و`min` و`between` ليهم 4 صيغ. الصيغة الناقصة بتطلع
        // بالمفتاح الخام على نوعها بس — رقم بيعدّي ونص بيقع.
        foreach (['max', 'min', 'between'] as $rule) {
            foreach (['numeric', 'file', 'string', 'array'] as $type) {
                foreach (['ar', 'en'] as $locale) {
                    $this->assertIsString(__("validation.{$rule}.{$type}", [], $locale));
                    $this->assertStringNotContainsString('validation.',
                        (string) __("validation.{$rule}.{$type}", [], $locale),
                        "validation.{$rule}.{$type} ناقصة في {$locale}");
                }
            }
        }
    }

    /**
     * الصفحة بتفتح على المرحلة اللي فيها الخطأ.
     *
     * ⚠️ من غير كده الخطأ في مرحلة 3 والصفحة بتفتح على 1 — المستخدم
     * بيشوف صفحة سليمة ومش فاهم ليه الحفظ مانفعش.
     */
    public function test_the_form_opens_on_the_step_that_has_the_error(): void
    {
        $this->actingAs($this->makeAdmin());

        // خطأ في مرحلة 3 (الضريبة) بس
        $this->from(route('erp.clients.new'))
            ->post(route('erp.clients.store'), [
                'name' => 'عميل', 'name_en' => 'Tax Co',
                'price_list' => 'new',
                'channel_id' => $this->makeChannel()->id,
                'price_list_id' => $this->makePriceList()->id, 'discount' => 0,
                'tax_rate' => 999,   // فوق الـ100
            ])->assertSessionHasErrors('tax_rate');

        // ⚠️ نفس الطلب اللي رجعت فيه الأخطاء — الأخطاء متفلاشة في
        // السيشن، والـblade بيحسب `errorStep` منها.
        $html = (string) $this->get(route('erp.clients.new'))->getContent();

        $this->assertMatchesRegularExpression('/errorStep"?\s*:\s*3/', $html,
            'الصفحة مش بتفتح على مرحلة الضريبة اللي فيها الخطأ');
    }

    // ═══════ 7. الأعطال اللي المراجعة العدائية طلّعتها ═══════

    /**
     * حقول العقد بتتعطّل لما مايكونش فيه عقد.
     *
     * ⚠️ **ده كان بيمنع حفظ أي عميل مالوش عقد — من المتصفح، دايماً.**
     * `display:none` مابيمنعش الإرسال. الـ`hidden` بتاع
     * `clause[invoice_discount][on]` كان بيتبعت بـ1 مع خانة قيمة
     * فاضية، وقاعدة `required_if:clause.*.on,1` كانت بترفض — والرسالة
     * بتشاور على بند جوه بلوك مقفول، يعني مفيش خانة يقدر يصلّحها.
     * الحل الوحيد كان يعلّم «فيه عقد»، يكتب رقم، ويشيل العلامة تاني.
     *
     * التيست بيتأكد إن `toggleContract()` بتعطّل كل حقول البلوك.
     */
    public function test_the_contract_block_is_disabled_when_there_is_no_contract(): void
    {
        $js = (string) file_get_contents(resource_path('views/erp/client_form.blade.php'));

        $this->assertMatchesRegularExpression(
            '/function toggleContract\(\).*?contractBox.*?querySelectorAll.*?disabled\s*=\s*!\s*on/s',
            $js,
            'حقول بلوك العقد مش بتتعطّل — الـhidden بتاع خصم الفاتورة هيتبعت '
            .'على كل عميل مالوش عقد ويرفض الحفظ برسالة على خانة مخبّية');
    }

    /**
     * نفس الحالة من ناحية السيرفر: البوست اللي الفورم بيبعته فعلاً
     * لعميل مالوش عقد لازم يعدّي.
     */
    public function test_a_client_with_no_contract_saves_even_with_the_clause_block_present(): void
    {
        $this->actingAs($this->makeAdmin());

        // ⚠️ ده شكل البوست الحقيقي **قبل** الإصلاح: البلوك المقفول
        // بيبعت `on=1` وقيمة فاضية.
        $this->post(route('erp.clients.store'), [
            'name' => 'محل من غير عقد',
            'name_en' => 'No Contract Shop',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id,
            'discount' => 0,
            'has_contract' => 0,
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => '']],
        ])->assertSessionHasErrors('clause.invoice_discount.value');

        // وده الشكل بعد الإصلاح: الحقول متعطّلة فمابتوصلش أصلاً
        $this->post(route('erp.clients.store'), [
            'name' => 'محل من غير عقد',
            'name_en' => 'No Contract Shop',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id,
            'discount' => 0,
            'has_contract' => 0,
        ])->assertSessionHasNoErrors();
    }

    /**
     * البند المقفول بيبعت قيمته في حقل مخفي.
     *
     * ⚠️ **ده كان بيقفل 17 عميل حقيقي.** الخانة المعروضة `disabled`
     * فمابتتبعتش، و`required_if:has_contract,1` كانت بترفض أي حفظ
     * لكارتهم — حتى تصليح رقم تليفون. On The Run، Rabbit، بيت الجملة،
     * باسم ماركت وغيرهم.
     */
    public function test_a_locked_clause_still_submits_its_value(): void
    {
        // ⚠️ **الفيو اتغيّر: محرّر العقد بقى في الويزارد.**
        // `erp/client.blade.php` بقى **كارت** العميل بس (كشف حساب +
        // مودال التحصيل + مودال الرصيد الافتتاحي)، وفورم التعريف
        // والتعديل كله في `erp/client_form.blade.php`. التيست كان لسه
        // بيدوّر على البند المقفول في الكارت.
        $html = (string) file_get_contents(resource_path('views/erp/client_form.blade.php'));

        $this->assertMatchesRegularExpression(
            '/locked.*?<input type="hidden" name="clause\[invoice_discount\]\[value\]"/s',
            $html,
            'البند المقفول مابيبعتش قيمته — كارت الـ17 عميل اللي بندهم '
            .'مكتوب بإيد مش هينفع يتحفظ خالص');
    }

    /**
     * حارس الجافاسكربت بيفحص المراحل المقفولة كمان.
     *
     * ⚠️ **الحارس كله كان كود ميت.** المرحلة المقفولة `display:none`،
     * فكل حقولها `offsetParent === null` — والفلتر القديم كان بيتخطّاها.
     * الزرار في مرحلة 3، يعني وقت الإرسال مرحلة 1 و2 مقفولين والحارس
     * بيرجع «مفيش مشكلة» دايماً. المستخدم كان بيدوس Enter في أي خانة
     * أو يقفز من الشريط لمرحلة 3، ويبعت فورم ناقص للسيرفر.
     */
    public function test_the_guard_looks_inside_closed_steps(): void
    {
        $js = (string) file_get_contents(resource_path('views/erp/client_form.blade.php'));

        $this->assertStringContainsString('function hiddenInPane(', $js,
            'الحارس لسه بيستخدم offsetParent — يعني بيتخطّى كل مرحلة مقفولة');

        $this->assertStringNotContainsString('offsetParent !== null', $js,
            'offsetParent لسه مستخدم في فورم العميل — المراحل المقفولة مش بتتفحص');
    }

    /**
     * كل خانة عليها `data-req-contract` إجبارية مع العقد في السيرفر.
     */
    public function test_contract_only_required_fields_are_enforced_by_the_server(): void
    {
        $rules = $this->clientRules();

        foreach (['erp/client_form.blade.php', 'erp/client.blade.php'] as $screen) {
            $html = (string) file_get_contents(resource_path('views/'.$screen));

            preg_match_all('/<(?:input|select|textarea)\b[^>]*\bdata-req-contract\b[^>]*>/', $html, $m);

            foreach ($m[0] as $tag) {
                if (! preg_match('/name="([^"]+)"/', $tag, $nm)) {
                    continue;
                }

                $field = str_replace(['][', '[', ']'], ['.', '.', ''], $nm[1]);
                $set = implode('|', (array) ($rules[$field] ?? []));

                $this->assertStringContainsString('required_if:has_contract', $set,
                    "{$screen} → «{$field}» الشاشة بتوقف عليها مع العقد والسيرفر مش بيطلبها");
            }
        }
    }

    // ═══════════════ 8. مدة التعاقد ═══════════════

    /**
     * كل نوع في القايمة المعروضة نوع صالح.
     *
     * ⚠️ نوع في الدروب داون ومش في `TYPE_KEYS` معناه إن المستخدم
     * بيختاره والسيرفر بيرفضه — بيملا الفورم كله ويرجع بخطأ على
     * خانة هو مختار منها صح.
     */
    public function test_every_offered_contract_type_is_valid(): void
    {
        foreach (Contract::TYPE_CHOICES as $key) {
            $this->assertArrayHasKey($key, Contract::TYPE_KEYS,
                "النوع «{$key}» معروض في الشاشة ومش في القايمة الصالحة");
        }
    }

    /**
     * الأنواع القديمة لسه صالحة.
     *
     * ⚠️ **ده أهم تيست في الحتة دي.** الـ22 عقد الحقيقيين فيهم
     * `supply_agreement` و`annual` و`business_development` و
     * `supplier_form`. لو اتشالوا من `TYPE_KEYS`، قاعدة `in:` بترفض
     * حفظ العميل — يعني حتى تصليح تليفونه بيترفض لأن نوع عقده «مش
     * من القايمة» — و`typeLabel()` بترجّع المفتاح الخام في الشاشة.
     */
    public function test_legacy_contract_types_still_validate(): void
    {
        foreach (Contract::TYPE_LEGACY as $key) {
            $this->assertArrayHasKey($key, Contract::TYPE_KEYS,
                "النوع القديم «{$key}» اتشال — العقود اللي عليه مش هتتحفظ");

            // وليه نص في اللغتين
            foreach (['ar', 'en'] as $locale) {
                $label = (string) __('client.contract_type_'.$key, [], $locale);

                $this->assertStringNotContainsString('client.', $label,
                    "«{$key}» مالوش نص في {$locale} — هيطلع المفتاح الخام");
            }
        }
    }

    /** كل مدة ليها نص في اللغتين */
    public function test_every_duration_is_translated(): void
    {
        foreach (array_keys(Contract::DURATIONS) as $key) {
            foreach (['ar', 'en'] as $locale) {
                $label = (string) __('client.duration_'.$key, [], $locale);

                $this->assertStringNotContainsString('client.', $label,
                    "المدة «{$key}» مالهاش نص في {$locale}");
            }
        }
    }

    public static function durationCases(): array
    {
        return [
            // [المدة، من، لـ، فيه خطأ؟]
            'شهر مظبوط' => ['month', '2026-01-01', '2026-01-31', false],
            'شهر قصير' => ['month', '2026-01-01', '2026-01-10', true],
            '3 أشهر مظبوطة' => ['quarter', '2026-01-01', '2026-03-31', false],
            '6 أشهر مظبوطة' => ['half_year', '2026-01-01', '2026-06-30', false],
            'سنة مظبوطة' => ['year', '2026-01-01', '2026-12-31', false],
            // ⚠️ الحالة اللي التعديل ده اتعمل عشانها: «سنة» بتواريخ شهرين
            'سنة بشهرين' => ['year', '2026-01-01', '2026-02-28', true],
            'سنة بسنتين' => ['year', '2026-01-01', '2027-12-31', true],
            'أكتر من سنة' => ['multi_year', '2026-01-01', '2028-12-31', false],
            // مفتوح المدة: بداية بس
            'مفتوح من غير نهاية' => ['open', '2026-01-01', null, false],
            'مفتوح بنهاية' => ['open', '2026-01-01', '2026-12-31', true],
            // تعامل بالطلب: مفيش تواريخ خالص
            'بالطلب من غير تواريخ' => ['per_order', null, null, false],
            'بالطلب بتواريخ' => ['per_order', '2026-01-01', '2026-12-31', true],
        ];
    }

    /**
     * @dataProvider durationCases
     */
    public function test_the_duration_must_match_the_dates(
        string $duration,
        ?string $from,
        ?string $to,
        bool $shouldFail,
    ): void {
        $err = Contract::checkDuration($duration, $from, $to);

        if ($shouldFail) {
            $this->assertNotNull($err, "«{$duration}» من {$from} لـ {$to} المفروض يترفض");
        } else {
            $this->assertNull($err, "«{$duration}» من {$from} لـ {$to} المفروض يعدّي — بس رجّع: {$err}");
        }
    }

    /**
     * الفاليديشن بتتنفّذ فعلاً في الحفظ.
     *
     * ⚠️ الدالة ممكن تكون سليمة ومحدش بينادينا. التيست ده بيبعت
     * ريكوست حقيقي.
     */
    public function test_a_mismatched_duration_is_rejected_on_save(): void
    {
        $this->actingAs($this->makeAdmin());

        $base = [
            'name' => 'عميل عقد', 'name_en' => 'Duration Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0, 'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_type' => 'supply',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 10]],
        ];

        // سنة بتواريخ شهرين → مرفوض
        $this->from(route('erp.clients.new'))
            ->post(route('erp.clients.store'), array_merge($base, [
                'contract_duration' => 'year',
                'contract_starts_at' => '2026-01-01',
                'contract_ends_at' => '2026-02-28',
            ]))->assertSessionHasErrors('contract_duration');

        // نفس المدة بتواريخ مظبوطة → بيعدّي
        $this->post(route('erp.clients.store'), array_merge($base, [
            'name_en' => 'Duration OK Co',
            'contract_duration' => 'year',
            'contract_starts_at' => '2026-01-01',
            'contract_ends_at' => '2026-12-31',
        ]))->assertSessionHasNoErrors();
    }

    /**
     * المدة إجبارية مع العقد.
     *
     * ⚠️ **`contract_duration` مش مبعوتة عن قصد** — دي نقطة التيست
     * كلها. تعليق «`open` عشان التيست ده موضوعه حاجة تانية» اتلزق
     * هنا بالغلط في تمشيطة جماعية، والنتيجة إن التيست كان بيبعت
     * المدة وبعدين بيطلب خطأ عليها.
     */
    public function test_the_duration_is_required_with_a_contract(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('erp.clients.store'), [
            'name' => 'عميل', 'name_en' => 'No Duration Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0, 'has_contract' => 1,
            'contract_type' => 'supply',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 10]],
        ])->assertSessionHasErrors('contract_duration');
    }

    /**
     * «تعامل بالطلب» بيتحفظ من غير تواريخ.
     *
     * ⚠️ `starts_at` كانت `?: today()` — يعني عقد المفروض مالوش
     * تواريخ خالص كان بياخد تاريخ النهاردة، وبعد سنة حد بيبص يلاقي
     * «عقد بدأ في يوم كذا» وهو أصلاً مش عقد.
     */
    public function test_a_per_order_arrangement_saves_with_no_dates(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('erp.clients.store'), [
            'name' => 'عميل بالطلب', 'name_en' => 'Per Order Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0, 'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_type' => 'agreement',
            'contract_duration' => 'per_order',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 5]],
        ])->assertSessionHasNoErrors();

        $contract = Client::where('name_en', 'Per Order Co')->firstOrFail()->contract;

        $this->assertNotNull($contract);
        $this->assertSame('per_order', $contract->duration);
        $this->assertNull($contract->starts_at, 'تعامل بالطلب اتحفظ بتاريخ بداية');
        $this->assertNull($contract->ends_at, 'تعامل بالطلب اتحفظ بتاريخ نهاية');
    }

    /** العقد المفتوح بيتحفظ ببداية من غير نهاية */
    public function test_an_open_ended_contract_keeps_its_start_and_drops_its_end(): void
    {
        $this->actingAs($this->makeAdmin());

        $this->post(route('erp.clients.store'), [
            'name' => 'عميل مفتوح', 'name_en' => 'Open Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id, 'discount' => 0, 'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            'contract_type' => 'supply',
            'contract_duration' => 'open',
            'contract_starts_at' => '2026-01-01',
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 5]],
        ])->assertSessionHasNoErrors();

        $contract = Client::where('name_en', 'Open Co')->firstOrFail()->contract;

        $this->assertSame('2026-01-01', $contract->starts_at?->toDateString());
        $this->assertNull($contract->ends_at, 'العقد المفتوح اتحفظ بتاريخ نهاية');
    }

    /**
     * النهاية المحسوبة صح.
     *
     * ⚠️ «ناقص يوم» مقصود: عقد سنة بيبدأ 1 يناير بينتهي 31 ديسمبر
     * مش 1 يناير اللي بعده — وإلا العقدين المتتاليين بيتداخلوا في
     * يوم والخصم بيتحسب مرتين على فاتورة اليوم ده.
     */
    public function test_the_computed_end_date_is_right(): void
    {
        $this->assertSame('2026-12-31', Contract::computeEnd('2026-01-01', 'year'));
        $this->assertSame('2026-06-30', Contract::computeEnd('2026-01-01', 'half_year'));
        $this->assertSame('2026-03-31', Contract::computeEnd('2026-01-01', 'quarter'));
        $this->assertSame('2026-01-31', Contract::computeEnd('2026-01-01', 'month'));

        // ⚠️ **آخر الشهر — دي الحالة اللي التجاوز بيبان فيها.**
        // 31 يناير + شهر بـ`addMonths` = 3 مارس (فبراير 28 يوم)،
        // وبـ`addMonthsNoOverflow` = 28 فبراير، وناقص يوم = 27.
        // الجافاسكربت في الشاشة بيعمل نفس الحاجة — لو الاتنين
        // اختلفوا، الشاشة بتملا تاريخ والسيرفر بيرفضه.
        $this->assertSame('2026-02-27', Contract::computeEnd('2026-01-31', 'month'));
        $this->assertSame('2026-02-27', Contract::computeEnd('2026-01-28', 'month'));
        $this->assertSame('2025-02-27', Contract::computeEnd('2024-02-29', 'year'));
        $this->assertSame('2026-11-29', Contract::computeEnd('2026-08-31', 'quarter'));

        // المدد اللي مالهاش نهاية
        $this->assertNull(Contract::computeEnd('2026-01-01', 'open'));
        $this->assertNull(Contract::computeEnd('2026-01-01', 'per_order'));
        $this->assertNull(Contract::computeEnd(null, 'year'));
    }

    /**
     * العقد اللي مايقعش في أي مدة قياسية بيتحفظ بـ«مدة مخصصة».
     *
     * ⚠️ **ده كان بيقفل كارتين عميل حقيقيين.** عقد بدأ مارس وبينتهي
     * 31 ديسمبر = 278 يوم: مش شهر ولا 3 ولا 6 ولا سنة (سنة = 362 على
     * الأقل) ولا أكتر من سنة (369 على الأقل). كل مدة بترفضه، وتواريخه
     * جاية من عقد موقّع مش هيتغيّر. النتيجة: حتى تصليح تليفون العميل
     * كان بيترفض.
     */
    public function test_an_odd_span_is_accepted_as_a_custom_term(): void
    {
        // الفجوات اللي بين النوافذ — دي بالظبط اللي كانت بتقفل
        foreach ([1, 26, 50, 120, 250, 278, 306, 360] as $days) {
            $to = \Illuminate\Support\Carbon::parse('2026-01-01')
                ->addDays($days - 1)->toDateString();

            $this->assertNull(
                Contract::checkDuration('custom', '2026-01-01', $to),
                "المدة المخصصة رفضت {$days} يوم",
            );
        }
    }

    /**
     * كل مدة ليها نافذة أيام، فيه تواريخ بتعدّيها.
     *
     * ⚠️ **التيست ده بيمسك النافذة الضيقة أوي.** لو حد ضيّق `min`
     * أو `max` على مدة، بيبقى فيه فترة زمنية مالهاش أي مدة صالحة —
     * والعقد اللي طوله كده بيبقى مستحيل يتحفظ.
     */
    public function test_every_bounded_term_accepts_its_own_computed_end(): void
    {
        foreach (Contract::DURATIONS as $key => $spec) {
            if ($spec['months'] === null) {
                continue;
            }

            $end = Contract::computeEnd('2026-01-01', $key);

            $this->assertNull(
                Contract::checkDuration($key, '2026-01-01', $end),
                "المدة «{$key}» بترفض النهاية اللي هي نفسها حسبتها ({$end})",
            );
        }
    }

    // ═══════ 9. الخانة الفاضية مابتكسرش الحفظ ═══════

    /**
     * الفورم زي ما المتصفح بيبعته بالظبط — كل اختياري فاضي.
     *
     * ⚠️ **ده اللي وقع في وش المستخدم بعد ما ملا الفورم كله.**
     *
     *     SQLSTATE[23000] 1048: Column 'eta_type' cannot be null
     *
     * المتصفح بيبعت الدروب داون الفاضية كنص فاضي `''`، وميدل وير
     * `ConvertEmptyStringsToNull` بيحوّله `null`، والقاعدة `nullable`
     * بتقبله — وبعدين MySQL بترفض لأن العمود `NOT NULL` بديفولت.
     *
     * ماكانش بيحصل قبل كده لأن الدروب داون كانت دايماً بقيمة مختارة
     * سلفاً، فالمفتاح ماكانش بيوصل `null` أبداً. أول ما حطّينا اختيار
     * «— اختر —» فاضي، الباب اتفتح.
     */
    public function test_the_form_saves_with_every_optional_select_left_empty(): void
    {
        $this->actingAs($this->makeAdmin());

        // ⚠️ نص فاضي مش `null` — ده اللي المتصفح بيبعته فعلاً.
        // الميدل وير هو اللي بيحوّله، فلازم نمرّ بنفس الطريق.
        $this->post(route('erp.clients.store'), [
            'name' => 'عميل بخانات فاضية',
            'name_en' => 'Empty Selects Co',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id,
            'discount' => 0,
            // كل الاختياري فاضي — زي فورم اتساب من غير ما حد يلمسه
            //
            // ⚠️ **`channel_id` مش هنا.** كان مكتوب مرتين في نفس
            // المصفوفة — التانية (`''`) كانت بتدوس على الأولى،
            // فالتيست كان بيرفض بـ«القناة مطلوبة». والقناة **فعلاً**
            // إجبارية من 2026-08-08 (عليها `data-req` في الفورم و
            // `required` في `clientRules`)، فمكانها مش في قايمة
            // «الاختياري الفاضي».
            'phone' => '',
            'governorate' => '',
            'zone_id' => '',
            'address' => '',
            'location_url' => '',
            'sub_channel' => '',
            'group_id' => '',
            'manager_id' => '',
            'branch_id' => '',
            'lat' => '',
            'lng' => '',
            'notes' => '',
            'tax_rate' => '',
            'tax_id' => '',
            'tax_cycle' => '',
            'eta_type' => '',
        ])->assertSessionHasNoErrors();

        $client = Client::where('name_en', 'Empty Selects Co')->firstOrFail();

        // الديفولتات من الداتابيز اتطبّقت بدل ما نبعت null
        $this->assertNotNull($client->eta_type, 'eta_type اتحفظ null على عمود NOT NULL');
        $this->assertNotNull($client->price_list);
        $this->assertNotNull($client->category);
    }

    /**
     * كل عمود `NOT NULL` في `clients` والفورم بيقدر يبعته فاضي
     * موجود في `DB_DEFAULTED`.
     *
     * ⚠️ التيست اللي فوق بيمسك الحالة الحالية. ده بيمسك **العمود
     * الجديد** اللي حد هيضيفه بعد كده وينسى يحطّه في القايمة.
     */
    public function test_every_not_null_column_the_form_can_blank_is_listed(): void
    {
        $ref = new \ReflectionClass(\App\Http\Controllers\ErpController::class);
        $listed = $ref->getConstant('DB_DEFAULTED');

        $rules = $this->clientRules();
        $missing = [];

        foreach (array_keys($rules) as $field) {
            $root = explode('.', $field)[0];

            if (in_array($root, self::FORM_ONLY, true) || in_array($root, $listed, true)) {
                continue;
            }

            if (! Schema::hasColumn('clients', $root)) {
                continue;
            }

            // القاعدة بتسمح بالفاضي؟
            $set = implode('|', (array) $rules[$field]);

            if (! str_contains($set, 'nullable')) {
                continue;
            }

            // والعمود `NOT NULL`؟
            $column = Schema::getColumns('clients');
            $meta = collect($column)->firstWhere('name', $root);

            if ($meta && ($meta['nullable'] ?? true) === false) {
                $missing[] = $root;
            }
        }

        $this->assertSame([], $missing,
            'أعمدة NOT NULL الفورم بيقدر يبعتها فاضية ومش في DB_DEFAULTED — '
            .'أول حفظ بقيمة فاضية هيطلع 500: '.implode(', ', $missing));
    }
}
