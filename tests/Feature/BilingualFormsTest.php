<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * كل داتا أساسية بتتكتب بالإيد لازم يكون ليها خانة عربي وخانة إنجليزي
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الخانة الناقصة مابتظهرش كخطأ.** `displayName()` بترجّع العربي
 * لو الإنجليزي فاضي، فالواجهة الإنجليزية بتعرض «كي أكاونت» في نص
 * جملة إنجليزي وكل حاجة «شغّالة». اللي بيكتشفها الوحيد هو اللي بيقرا
 * التقرير الإنجليزي بعد شهور.
 *
 * وفي حتة تانية العكس بالظبط: `displayChain()` و`displayLabel()`
 * بيرجّعوا **فاضي** في الإنجليزي عن قصد — فالخانة الناقصة معناها اسم
 * بيختفي خالص من الشاشة.
 */
class BilingualFormsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * كل فورم فيه اسم أساسي: الفيو، والحقل العربي، والحقل الإنجليزي.
     *
     * ⚠️ `<select>` مستثنى — `kind` في شاشة الاستيراد قايمة اختيار
     * مش نص بيتكتب.
     */
    private const PAIRS = [
        ['erp/channels', 'name', 'name_en'],
        ['erp/groups', 'name', 'name_en'],
        ['erp/group', 'name', 'name_en'],
        ['erp/branches', 'name', 'name_en'],
        ['erp/vehicles', 'kind', 'kind_en'],
        ['erp/stock', 'name', 'name_en'],
        ['erp/stock', 'unit', 'unit_en'],
        ['erp/leads', 'name', 'name_en'],
        ['erp/client_form', 'name', 'name_en'],
        ['erp/client', 'name', 'name_en'],
        // ⚠️ `label_en` و`chain_en` أعمدة **موجودة من قبل** وعليها
        // داتا الـ22 عقد الحقيقيين. سايبينهم عشان الداتا دي تفضل
        // قابلة للتعديل — مش بنضيف نظائر جديدة للنص الحر.
        ['erp/contract', 'label', 'label_en'],
        ['erp/contracts', 'chain', 'chain_en'],
    ];

    public function test_every_master_data_form_has_both_languages(): void
    {
        $missing = [];

        foreach (self::PAIRS as [$view, $ar, $en]) {
            $html = (string) file_get_contents(resource_path('views/'.$view.'.blade.php'));

            if (! str_contains($html, 'name="'.$ar.'"')) {
                continue;   // الفيو مافيهوش الحقل ده أصلاً
            }

            if (! str_contains($html, 'name="'.$en.'"')) {
                $missing[] = $view.' :: '.$en;
            }
        }

        $this->assertSame([], $missing,
            'فورمات فيها الاسم العربي من غير الإنجليزي — الترجمة هتتكتب مرة تانية بعدين');
    }

    /**
     * ⚠️ الحقل على الشاشة من غير قاعدة تحقق **بيترمي في صمت**.
     * `validate()` بترجّع المفاتيح المتحقق منها بس، فالمستخدم بيكتب
     * الاسم الإنجليزي وبيضغط حفظ وبيشوف «اتحفظ» والخانة فاضية بعدين.
     */
    public function test_every_english_field_actually_reaches_the_database(): void
    {
        $admin = $this->makeAdmin();

        // ═══ القناة ═══
        $channel = Channel::create([
            'code' => 'test_ch', 'name' => 'قناة', 'discount' => 0, 'active' => true,
        ]);

        $this->actingAs($admin)->put('/erp/channels/'.$channel->id, [
            'name' => 'كي أكاونت',
            'name_en' => 'Key Account',
            'discount' => 50,
            'active' => 1,
        ])->assertRedirect();

        $this->assertSame('Key Account', $channel->fresh()->name_en, 'اسم القناة الإنجليزي اتجاهل');

        // ═══ السلسلة ═══
        $this->actingAs($admin)->post('/erp/groups', [
            'name' => 'سيركل كيه',
            'name_en' => 'Circle K',
            'discount' => 0,
        ])->assertRedirect();

        $this->assertSame('Circle K', ClientGroup::where('name', 'سيركل كيه')->value('name_en'),
            'اسم السلسلة الإنجليزي اتجاهل');

        // ═══ الصنف ═══
        $this->actingAs($admin)->post('/erp/stock', [
            'code' => 'P-TEST',
            'name' => 'بروماكس بار',
            'name_en' => 'PROMAX Bar',
            'unit' => 'كرتونة',
            'unit_en' => 'carton',
            'family' => array_key_first(Product::FAMILIES),
            'cost' => 10,
            'price_old' => 18,
            'price_new' => 20,
            'qty' => 0,
        ])->assertRedirect();

        $product = Product::where('code', 'P-TEST')->firstOrFail();

        $this->assertSame('PROMAX Bar', $product->name_en, 'اسم الصنف الإنجليزي اتجاهل');
        $this->assertSame('carton', $product->unit_en, 'وحدة الصنف الإنجليزية اتجاهلت');
    }

    /**
     * التبديل بين اللغتين بيدّي النص الصح — مش خليط.
     */
    public function test_switching_the_interface_language_switches_every_name(): void
    {
        $channel = Channel::create([
            'code' => 'ka', 'name' => 'كي أكاونت', 'name_en' => 'Key Account',
            'discount' => 0.5, 'active' => true,
        ]);

        $client = $this->makeClient([
            'name' => 'سيركل كيه — دجلة',
            'name_en' => 'Circle K — Degla',
            'channel_id' => $channel->id,
        ]);

        app()->setLocale('ar');
        $this->assertSame('كي أكاونت', $channel->displayName());
        $this->assertSame('سيركل كيه — دجلة', $client->displayName());

        app()->setLocale('en');
        $this->assertSame('Key Account', $channel->displayName());
        $this->assertSame('Circle K — Degla', $client->displayName());
    }

    /**
     * ⚠️ ده الجزء اللي بيخدع: الاسم الإنجليزي الفاضي **مابيبانش
     * كخطأ** — بيرجع العربي وكل حاجة تبان شغّالة. عشان كده الخانة
     * لازم تكون موجودة في الفورم من الأول.
     */
    public function test_a_missing_english_name_silently_falls_back_to_arabic(): void
    {
        $channel = Channel::create([
            'code' => 'silent', 'name' => 'كي أكاونت', 'name_en' => null,
            'discount' => 0, 'active' => true,
        ]);

        app()->setLocale('en');

        $this->assertSame('كي أكاونت', $channel->displayName(),
            'ده السلوك الحالي — عربي جوه واجهة إنجليزية من غير أي تحذير');
    }

    // ═══════════════════ الخط الفاصل: ثنائي مقابل إنجليزي ═══════════════════

    /**
     * الداتا اللي **بتتعرّف مرة وبتتكرر في كل الشاشات** — دي بس اللي
     * ليها عمودين. أي اسم منها بيتعرض في تقرير وفاتورة وتصدير، ولو
     * جه بلغة واحدة بيكسّر الشاشة التانية.
     */
    public function test_master_data_is_bilingual(): void
    {
        $bilingual = [
            [Client::class, 'name_en'],
            [Channel::class, 'name_en'],
            [ClientGroup::class, 'name_en'],
            [\App\Models\Zone::class, 'name_en'],
            [\App\Models\Branch::class, 'name_en'],
            [\App\Models\User::class, 'name_en'],
            [Product::class, 'name_en'],
            [Product::class, 'unit_en'],
            [\App\Models\Vehicle::class, 'kind_en'],
        ];

        foreach ($bilingual as [$model, $column]) {
            $this->assertContains($column, (new $model)->getFillable(),
                class_basename($model).' لازم يكون ثنائي اللغة');
        }
    }

    /**
     * ⚠️ **النص الحر اللي بيتكتب مرة لحالة واحدة = عمود واحد.**
     * العنوان، الملاحظات، اسم جهة التواصل وصفته، بنود العقد الحرة.
     * دول مالهمش «نسخة تانية» — عمودين هنا معناهم إن اللي بيدخل الداتا
     * بيكتب نفس الحاجة مرتين على 300 عميل، وفي الآخر نص الخانات بتفضل
     * فاضية والشاشة الإنجليزية بتبان ناقصة برضه.
     */
    public function test_one_off_free_text_stays_a_single_english_column(): void
    {
        $singles = [
            ['clients', 'address_en'],
            ['clients', 'notes_en'],
            ['contracts', 'note_en'],
            ['contracts', 'clauses_en'],
        ];

        foreach ($singles as [$table, $column]) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn($table, $column),
                "{$table}.{$column} اتضاف — ده نص حر المفروض عمود واحد بالإنجليزي");
        }
    }

    /**
     * الخانات دي لازم تقول للي بيكتب إنها إنجليزي — بـ`dir=ltr`
     * وبـplaceholder بيقوله «اكتب بالإنجليزي».
     *
     * ⚠️ **الـplaceholder اتنقل لملفات اللغة (٢٠٢٦-٠٨).** قبل كده كان
     * مثال إنجليزي مكتوب بالنص جوه البليد («Mohamed Ahmed» /
     * «Branch Manager») — والمثال بيتقري كأنه قيمة موجودة فعلاً، وفيه
     * ناس كانت بتسيب الخانة فاكرة إنها مليانة. والأهم: الصف اللي
     * الجافاسكربت بيضيفه كان بيتكتب مرة تانية بالإيد، فأول ما النصين
     * يختلفوا بيطلع صف جديد بلغة غير صفوف البليد.
     *
     * التيست ده كان بيدوّر على النص القديم حرفياً فبقى قديم هو كمان.
     * **نفس النية اتحرست بمصدرها الجديد:** الاتجاه `ltr` على كل خانة
     * نص حر، والـplaceholder مربوط بمفتاح اللغة **في البليد وفي
     * الجافاسكربت بنفس المفتاح**، والنص الإنجليزي للمفتاح إنجليزي فعلاً.
     */
    public function test_free_text_fields_signal_that_they_are_english(): void
    {
        $html = (string) file_get_contents(resource_path('views/erp/client_form.blade.php'));

        preg_match_all('/<input\b[^>]*>/', $html, $m);

        $tagWith = function (string $needle) use ($m): string {
            foreach ($m[0] as $tag) {
                if (str_contains($tag, $needle)) {
                    return $tag;
                }
            }

            return '';
        };

        // ═══ الاتجاه + الـplaceholder على كل خانة نص حر ═══
        $fields = [
            'العنوان' => ['name="address"', 'client.address_ph'],
            'اسم جهة التواصل' => ['][name]"', 'client.contact_name_ph'],
            'صفة جهة التواصل' => ['][role]"', 'client.contact_role_ph'],
        ];

        foreach ($fields as $label => [$needle, $langKey]) {
            $tag = $tagWith($needle);

            $this->assertNotSame('', $tag, "خانة «{$label}» مش موجودة في الفورم خالص");

            $this->assertStringContainsString('dir="ltr"', $tag,
                "خانة «{$label}» لازم تكون LTR — من غيرها المؤشر بيبدأ يمين والكتابة الإنجليزية بتتلغبط");

            $this->assertStringContainsString("__('".$langKey."')", $tag,
                "خانة «{$label}» مالهاش placeholder من ملف اللغة — النص المكتوب بالإيد "
                .'بيختلف عن الصف اللي الجافاسكربت بيضيفه');
        }

        // ═══ الصف اللي الجافاسكربت بيضيفه بنفس المفاتيح بالظبط ═══
        foreach (['contactNamePh' => 'contact_name_ph', 'contactRolePh' => 'contact_role_ph'] as $js => $key) {
            $this->assertStringContainsString("'".$js."' => __('client.".$key."')", $html,
                "مفتاح «{$key}» مش متمرّر للجافاسكربت — الصف الجديد هيطلع بلا placeholder");

            $this->assertStringContainsString('T.'.$js, $html,
                "الجافاسكربت مش بيستخدم «{$js}» — يبقى بيكتب النص بإيده تاني");
        }

        preg_match('/const cell = \(name, ph\) =>(.{0,400})/s', $html, $cm);
        $cell = $cm[1] ?? '';

        $this->assertNotSame('', $cell, 'دالة بناء صف جهة التواصل في الجافاسكربت اتشالت');
        $this->assertStringContainsString('dir="ltr"', $cell,
            'الصف اللي الجافاسكربت بيضيفه مش LTR — بيختلف عن صفوف البليد');
        $this->assertStringContainsString("placeholder=\"' + ph + '\"", $cell,
            'الصف الجديد بيتكتب بـplaceholder ثابت بدل اللي جاي من ملف اللغة');

        // ═══ والنص الإنجليزي للمفاتيح دي إنجليزي فعلاً ═══
        foreach (['address_ph', 'contact_name_ph', 'contact_role_ph'] as $key) {
            $en = (string) __('client.'.$key, [], 'en');
            $ar = (string) __('client.'.$key, [], 'ar');

            $this->assertStringNotContainsString('client.', $en,
                "المفتاح «{$key}» ناقص في lang/en — هيطلع خام على الشاشة");
            $this->assertStringNotContainsString('client.', $ar,
                "المفتاح «{$key}» ناقص في lang/ar");

            // لاتيني بحت — لو اتكتب عربي في `lang/en` الخانة بتقول
            // العكس بالظبط: «دي خانة عربي».
            $this->assertSame($en, (string) preg_replace('/[^\x20-\x7E]/', '', $en),
                "«{$key}» في lang/en فيه حروف مش لاتينية");

            $this->assertStringContainsString('English', $en,
                "«{$key}» لازم يقول للمستخدم إن الخانة إنجليزي");
        }
    }

    public function test_the_free_text_still_saves_end_to_end(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('/erp/clients', [
            'name' => 'بيت الجملة',
            'name_en' => 'BaiT El Gomla',
            'address' => '90th Street, New Cairo',
            'notes' => 'Pays on time',
            'price_list' => 'new',
            'channel_id' => $this->makeChannel()->id,
            'price_list_id' => $this->makePriceList()->id,
            'discount' => 0,
            'contacts' => [
                3 => ['name' => 'Mohamed Ahmed', 'role' => 'Branch Manager', 'phone' => '01000000000'],
            ],
            'has_contract' => 1,
            // ⚠️ المدة بقت إجبارية مع العقد — `open` عشان التيست ده
            // موضوعه حاجة تانية والتواريخ مش جزء منه.
            'contract_duration' => 'open',
            // ⚠️ **نوع العقد `required_if:has_contract,1`** — من غيره
            // الحفظ بيترفض، و`assertRedirect` بتعدّي لأن `back()`
            // ريدايركت، وبعدين `Client::firstOrFail()` بترمي
            // `ModelNotFoundException` بدل ما تقول «الفاليديشن رفض».
            'contract_type' => 'agreement',
            'contract_note' => 'Renewal pending',
            'contract_clauses' => ['First clause', 'Second clause'],
            'clause' => ['invoice_discount' => ['on' => 1, 'value' => 10]],
        ])->assertRedirect();

        $c = Client::firstOrFail();

        $this->assertSame('90th Street, New Cairo', $c->address);
        $this->assertSame('Pays on time', $c->notes);
        $this->assertSame('Mohamed Ahmed', $c->contactList()[0]['name']);
        $this->assertSame('Branch Manager', $c->contactList()[0]['role']);
        $this->assertSame('Renewal pending', $c->contract->note);
        $this->assertSame(['First clause', 'Second clause'], $c->contract->clauseList());
    }

    // ═══════════════════ القنوات الأربعة ═══════════════════

    public function test_the_four_channels_exist_after_seeding(): void
    {
        $this->seed(\Database\Seeders\ModernTradeSeeder::class);

        foreach (array_keys(Channel::DEFAULTS) as $code) {
            $channel = Channel::where('code', $code)->first();

            $this->assertNotNull($channel, "القناة {$code} مش موجودة");
            $this->assertNotEmpty($channel->name_en, "القناة {$code} مالهاش اسم إنجليزي");
        }

        $this->assertSame(4, Channel::count());
    }

    /**
     * ⚠️ **التيست ده كان على نسبة خصم القناة — والعمود اتشال بقرار
     * ٣١ يوليو ٢٠٢٦** (مايجريشن `000025_drop_channel_discount`).
     * القناة بقت بُعد تجميع مالوش سعر، فالكويري القديمة كانت بترمي
     * «Unknown column 'discount'».
     *
     * القرار اللي التيست بيحرسه فضل هو هو: **إعادة تشغيل السيدر
     * ممنوع تدوس على تعديل تجاري**. اللي بيتعدّل من `/erp/channels`
     * دلوقتي هو **الاسم** (عربي/إنجليزي) — فده اللي بنحرسه.
     */
    public function test_seeding_twice_does_not_overwrite_a_renamed_channel(): void
    {
        $this->seed(\Database\Seeders\ModernTradeSeeder::class);

        Channel::where('code', Channel::CASH_VAN)->update([
            'name' => 'كاش فان — القاهرة',
            'name_en' => 'Cash Van - Cairo',
        ]);

        $this->seed(\Database\Seeders\ModernTradeSeeder::class);

        $channel = Channel::where('code', Channel::CASH_VAN)->firstOrFail();

        $this->assertSame('كاش فان — القاهرة', $channel->name,
            'السيدر دعس على اسم اتغيّر بقرار تجاري');
        $this->assertSame('Cash Van - Cairo', $channel->name_en,
            'السيدر دعس على الاسم الإنجليزي');
    }

    /**
     * الحارس على القرار نفسه: العمود ممنوع يرجع.
     *
     * ⚠️ أول ما يرجع، حد هيكتب فيه رقم وهيتطبق على عملاء محدش راجعهم
     * — وده بالظبط اللي قرار ٣١ يوليو اتاخد عشانه.
     */
    public function test_the_channel_still_has_no_discount_column(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('channels', 'discount'),
            'عمود خصم القناة رجع — الخصم على العميل مش على القناة',
        );
    }

    // ═══════════════════ القسم للكي أكاونت وبس ═══════════════════

    public function test_only_the_key_account_channel_has_segments(): void
    {
        foreach (array_keys(Channel::DEFAULTS) as $code) {
            $expected = $code === Channel::KEY_ACCOUNT;

            $this->assertSame($expected, Channel::codeHasSubChannels($code),
                "القناة {$code}");
        }
    }

    public function test_a_segment_on_a_non_key_account_client_is_cleared(): void
    {
        // ⚠️ الحارس على **الموديل** مش على الفورم: العميل بيتعمل من 5
        // مسارات (شاشة، تحويل ليد، موافقة طلب، استيراد، سيدر).
        //
        // ⚠️ **`seededChannel` مش `Channel::create`** — الأربعة اتزرعوا
        // في مايجريشن `000024_seed_four_channels`، و`create` بكود منهم
        // بترمي «Duplicate entry» على `channels_code_unique`.
        $cashVan = $this->seededChannel(Channel::CASH_VAN);

        $client = $this->makeClient([
            'channel_id' => $cashVan->id,
            'sub_channel' => 'chain',
        ]);

        $this->assertNull($client->fresh()->sub_channel,
            'عميل كاش فان مالوش قسم — الفلتر كان بيطلّعه في نتيجة «سلاسل هايبر»');
    }

    public function test_moving_a_client_out_of_key_account_drops_his_segment(): void
    {
        // ⚠️ ده السيناريو الحقيقي: عميل كان كي أكاونت/سلاسل واتنقل
        // كاش فان. القناة بتتغيّر والقسم كان بيفضل.
        $ka = $this->seededChannel(Channel::KEY_ACCOUNT);
        $cashVan = $this->seededChannel(Channel::CASH_VAN);

        $client = $this->makeClient(['channel_id' => $ka->id, 'sub_channel' => 'convenience']);
        $this->assertSame('convenience', $client->fresh()->sub_channel, 'الكي أكاونت مسموح له');

        $client->update(['channel_id' => $cashVan->id]);

        $this->assertNull($client->fresh()->sub_channel);
    }

    public function test_a_key_account_client_keeps_his_segment(): void
    {
        $ka = $this->seededChannel(Channel::KEY_ACCOUNT);

        $client = $this->makeClient(['channel_id' => $ka->id, 'sub_channel' => 'chain']);
        $client->update(['phone' => '01000000000']);

        $this->assertSame('chain', $client->fresh()->sub_channel,
            'الحارس ممنوع يمسح قسم شرعي');
    }

    public function test_the_client_form_only_offers_a_segment_for_key_accounts(): void
    {
        // الشاشة بتخفي الخانة وبتفضّيها — والسيرفر بيصفّيها كمان
        $html = (string) file_get_contents(resource_path('views/erp/client_form.blade.php'));

        $this->assertStringContainsString("code === 'key_account'", $html);
        $this->assertStringContainsString("sub.value = ''", $html,
            'إخفاء الخانة من غير تفضيتها بيخلّي القيمة تتبعت مع الفورم');
    }
}
