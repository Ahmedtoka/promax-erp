<?php

namespace Tests;

use App\Models\Channel;
use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الأساس المشترك لكل التيستات
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ التيستات **مابتشغّلش السيدرز**. سيدرز PROMAX بترحّل داتا حقيقية
 * (103 عميل و~2000 حركة)، والتيست اللي بيعتمد عليها بيبقى بطيء
 * وبيتكسّر أول ما الداتا تتغيّر. كل تيست بيبني اللي محتاجه بس.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ الإعدادات متكاشة — من غير المسح ده، تيست بيفعّل الضريبة
        // بيسرّب الحالة للتيست اللي بعده وبيخلّي النتيجة تعتمد على
        // الترتيب. ده أسوأ نوع تيست: بينجح لوحده وبيفشل في المجموعة.
        Setting::flushCache();

        // ⚠️ نفس السبب: `PriceList::default()`/`byCode()` و
        // `Governorates::rows()` ميمو ستاتيك، و`RefreshDatabase`
        // بترجّع الصفوف بين كل تيست والتاني — فالكاش بيفضل شايل
        // موديل لصف مابقاش موجود.
        PriceList::flushCache();
        \App\Support\Governorates::flush();
    }

    // ═══════════════════════ بنّائين ═══════════════════════

    protected function makeAdmin(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'أدمن التيست',
            'name_en' => 'Test Admin',
            'email' => 'admin'.uniqid().'@promax.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            // ⚠️ `code` عمود `unique`. `random_int(1000,9999)` كان بيدي
            // تصادم كل ~900 استدعاء — يعني تيست بيقع من غير سبب مرة
            // كل شوية، وده أسوأ من تيست بيقع دايماً لأن حد بيشيله.
            'code' => 'ADM-'.strtoupper(uniqid()),
            'active' => true,
            'locale' => 'en',
        ], $attrs));
    }

    protected function makeRep(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'مندوب التيست',
            'name_en' => 'Test Rep',
            'email' => 'rep'.uniqid().'@promax.test',
            'password' => bcrypt('secret'),
            'role' => 'sales_agent',
            // ⚠️ `uniqid()` مش `random_int` — نفس سبب `makeAdmin`:
            // العمود `unique`، والتيستات اللي بتعمل ٢-٣ مناديب في
            // كل واحد كانت بتقع بتصادم عشوائي مرة كل شوية.
            'code' => 'SLS-'.strtoupper(uniqid()),
            'active' => true,
            'locale' => 'en',
        ], $attrs));
    }

    /**
     * ⚠️ **البارامتر متسيب عن قصد ومابيعملش حاجة.** القناة مابقاش
     * لها نسبة خصم (قرار 2026-07-31)، والتيستات القديمة بتنادي
     * `makeChannel(0.15)` — شيله كان هيكسّر 6 ملفات في حاجة مالهاش
     * علاقة بالتغيير. اللي محتاج خصم بيحطه على العميل.
     */
    protected function makeChannel(float $ignoredDiscount = 0.0): Channel
    {
        return Channel::create([
            // ⚠️ `uniqid()` مش `random_int` — نفس درس `makeAdmin`
            // الموثّق فوق. `CH100..CH999` = 900 قيمة بس، والتيست
            // الواحد بيعمل قناتين وتلاتة فالتصادم كان بيقع على قيد
            // التفرد كل شوية من غير أي علاقة باللي بيتفحص.
            'code' => 'CH-'.strtoupper(uniqid()),
            'name' => 'قناة التيست',
            'name_en' => 'Test channel',
            'active' => true,
        ]);
    }

    /**
     * قناة من **الأربعة الثابتة** بكودها الحقيقي — `firstOrCreate`.
     *
     * ⚠️ **مايجريشن `000024_seed_four_channels` بتزرع الأربعة**
     * (`key_account` / `online` / `cash_van` / `wholesale`)، و
     * `RefreshDatabase` بتشغّل المايجريشنات — يعني الصفوف موجودة
     * قبل أول سطر في أي تيست. `Channel::create(['code' =>
     * Channel::CASH_VAN, …])` بترمي «Duplicate entry 'cash_van' for
     * key 'channels_code_unique'» — رسالة مالهاش أي علاقة باللي
     * بيتفحص، والتيست بيبان كأنه بيكشف باج في الدومين.
     *
     * القاعدة: اللي محتاج قناة **كودها مايفرقش** يستخدم
     * `makeChannel()`؛ واللي محتاج **كود بعينه** (عشان `sub_channel`
     * أو `paymentTerms()` أو التقارير) يستخدم دي.
     */
    protected function seededChannel(string $code = Channel::KEY_ACCOUNT, array $attrs = []): Channel
    {
        [$name, $nameEn] = Channel::DEFAULTS[$code] ?? ['قناة التيست', 'Test channel'];

        return Channel::firstOrCreate(['code' => $code], array_merge([
            'name' => $name,
            'name_en' => $nameEn,
            'active' => true,
        ], $attrs));
    }

    protected function makeZone(): Zone
    {
        // ⚠️ عمود `zones.code` طوله 20 — `Z-` + 13 حرف = 15
        return Zone::create([
            'code' => 'Z-'.strtoupper(uniqid()),
            'name' => 'زون التيست',
            'name_en' => 'Test zone',
            'active' => true,
        ]);
    }

    /**
     * قايمة سعر — **بترجّع الموجودة لو موجودة**.
     *
     * ⚠️ `price_list_id` بقى `required` على فورم العميل (2026-08-07)،
     * فأي تيست بيحفظ عميل محتاج قايمة حقيقية. و`is_default` بتتحط على
     * واحدة بس — التانية بتتعمل من غيرها عشان `Pricing::listRowFor`
     * ماتلاقيش افتراضيتين.
     */
    protected function makePriceList(string $code = 'new'): PriceList
    {
        return PriceList::firstOrCreate(
            ['code' => $code],
            [
                'name' => 'قايمة '.$code,
                'name_en' => 'List '.$code,
                'active' => true,
                'is_default' => ! PriceList::where('is_default', true)->exists(),
            ],
        );
    }

    protected function makeClient(array $attrs = []): Client
    {
        return Client::create(array_merge([
            'code' => 'CL-'.random_int(10000, 99999),
            'name' => 'عميل التيست',
            'name_en' => 'Test client',
            'category' => 'ok',
            'status' => 'active',
            'discount' => 0,
            'uses_channel_discount' => true,
            'price_list' => 'new',
            'taxable' => false,
            'tax_rate' => 0,
        ], $attrs));
    }

    protected function makeProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'code' => 'P'.random_int(1000, 9999),
            'name' => 'صنف التيست',
            'name_en' => 'Test product',
            'unit' => 'قطعة',
            'family' => array_key_first(Product::FAMILIES),
            'cost' => 10,
            'price_old' => 18,
            'price_new' => 20,
            'active' => true,
            'taxable' => true,
            'tax_rate' => 0,
        ], $attrs));
    }

    protected function makeWarehouse(): Warehouse
    {
        return Warehouse::create([
            // ⚠️ `WH10..WH99` = 90 قيمة بس على عمود `unique` — تصادم
            // عشوائي. نفس علاج `makeChannel`/`makeZone`.
            'code' => 'WH-'.strtoupper(uniqid()),
            'name' => 'مخزن التيست',
            'name_en' => 'Test warehouse',
            'type' => 'main',
            'active' => true,
        ]);
    }

    /**
     * هيدر التوكن للـ API.
     *
     * ⚠️ `actingAs()` **مابتشتغلش** مع الـ API — الميدلوير بيقرا
     * `Authorization: Bearer` من جدول `api_tokens` مش من الجلسة.
     * التيست اللي بيستخدم actingAs بيرجع 401 من غير سبب واضح.
     */
    protected function tokenFor(User $user): array
    {
        $token = \App\Models\ApiToken::create([
            'user_id' => $user->id,
            'name' => 'test',
            'token' => bin2hex(random_bytes(20)),
        ]);

        return ['Authorization' => 'Bearer '.$token->token];
    }

    /**
     * تسجيل حضور الموظف — **إجباري قبل أي أكشن ميداني**.
     *
     * ⚠️ **حارس الحضور شغّال من ٨ أغسطس ٢٠٢٦** (`RequireAttendance`
     * على مجموعة شغل الشارع): الموظف اللي مش `working` بياخد
     * **423** على أي بوست — فاتورة، مرتجع، تحصيل، تسليم أمر.
     * ده سلوك إنتاج صح ومقصود، فالتيست اللي بيبيع لازم يبصم أول.
     *
     * ⚠️ **مش جوه `makeRep()` عن قصد** — تيستات الحضور نفسها
     * بتحتاج مندوب لسه مابصمش، ولو البصمة اتحطت في البنّاء الحارس
     * ده مايتفحصش في أي تيست تاني.
     */
    protected function punchIn(User $user): User
    {
        [$err] = \App\Services\Attendance::punch($user, \App\Models\AttendancePunch::IN);

        $this->assertNull($err, 'مقدرش يسجّل حضور المندوب في التيست: '.(string) $err);

        return $user;
    }

    /**
     * زيارة مفتوحة على العميل — **مرساة أي أكشن ميداني**.
     *
     * ⚠️ **`visit_id` بقت `required` على `/api/invoices` و`/api/returns`
     * (تدقيق ٨ أغسطس ٢٠٢٦).** الزيارة هي الإثبات إن المندوب كان واقف
     * قدام المحل — من غيرها كان أي مندوب يفوتر أي عميل ويمدّن حسابه.
     * ده سلوك إنتاج صح، فالتيست بيفتح زيارة زي الواقع.
     *
     * ⚠️ **الزيارة المفتوحة القديمة بتتقفل الأول** — المندوب مابيكونش
     * واقف في محلين في نفس الوقت، والحارس بتاع الانصراف بيعد الزيارات
     * المفتوحة.
     */
    protected function openVisit(User $rep, Client $client): \App\Models\Visit
    {
        \App\Models\Visit::where('user_id', $rep->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => now()]);

        return \App\Models\Visit::create([
            'user_id' => $rep->id,
            'client_id' => $client->id,
            'checked_in_at' => now(),
        ]);
    }

    /**
     * فاتورة من الأبلكيشن — بتفتح الزيارة وبتبعت التوكن.
     *
     * ⚠️ **مكان واحد** عشان أي حارس جديد على الإندبوينت (الحضور،
     * الزيارة، المفتاح…) يتظبط مرة واحدة بدل ما ٤ ملفات تيست تتعدّل
     * كل مرة — وده بالظبط اللي حصل في حارس الحضور ٨/٨.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function sellApi(User $rep, Client $client, array $items, array $extra = [])
    {
        $visit = $this->openVisit($rep, $client);

        return $this->withHeaders($this->tokenFor($rep))
            ->postJson('/api/invoices', array_merge([
                'client_id' => $client->id,
                'visit_id' => $visit->id,
                'items' => $items,
            ], $extra));
    }

    /** تفعيل الضريبة بنسبة معيّنة */
    protected function enableTax(float $pct = 14.0): void
    {
        Setting::writeMany([
            'tax_enabled' => '1',
            'tax_rate' => (string) $pct,
            'company_tax_id' => '123-456-789',
            'company_activity_code' => '1071',
        ]);
    }
}
