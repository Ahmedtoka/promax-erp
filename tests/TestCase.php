<?php

namespace Tests;

use App\Models\Channel;
use App\Models\Client;
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
            'code' => 'SLS-'.random_int(1000, 9999),
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
            'code' => 'CH'.random_int(100, 999),
            'name' => 'قناة التيست',
            'name_en' => 'Test channel',
            'active' => true,
        ]);
    }

    protected function makeZone(): Zone
    {
        return Zone::create([
            'code' => 'Z'.random_int(10, 99),
            'name' => 'زون التيست',
            'name_en' => 'Test zone',
            'active' => true,
        ]);
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
            'code' => 'WH'.random_int(10, 99),
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
