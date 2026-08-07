<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Services\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * عقيدة التسعير — مين بيحدّد الخصم ومين بيحدّد السعر
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الملف ده مهم:** سعر البيع بيتحسب في مكان واحد بس
 * (`App\Services\Pricing`)، والعميل بيوصل له بطريقتين: `effectiveDiscount()`
 * و`priceFor()`. لو الترتيب المقدّس (عقد ← خصم العميل ← صفر) اتكسر،
 * الفاتورة بتطلع بخصم محدش قرره — والفرق ده كاش بيخرج من الشركة فعلاً
 * ومابيبانش غير في مراجعة آخر الشهر.
 *
 * ⚠️ **القناة والسلسلة مالهمش نسبة خصم خالص** (قرارات 2026-07-31 و
 * 2026-08-01). العمود `uses_channel_discount` لسه موجود للداتا القديمة
 * بس `effectiveDiscount()` مابتقراهوش — والتيستات هنا بتقفل على ده
 * عشان محدش يرجّعه بحسن نية.
 */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════ 1. نسبة الخصم المعتمدة ═══════════════

    /**
     * خصم العميل بيشتغل لما يكون أكبر من صفر.
     *
     * ⚠️ الدوكترين: النسبة بتتفاوض عليها لكل عميل على حدة، فالخانة
     * اللي على كارت العميل هي اللي بتحكم — مش القناة ولا السلسلة.
     */
    public function test_the_client_own_rate_is_used_when_it_is_set(): void
    {
        $client = $this->makeClient(['discount' => 0.20]);

        $this->assertEqualsWithDelta(0.20, $client->effectiveDiscount(), 0.0001);
        $this->assertSame('custom_discount', $client->discountSourceKey(),
            'مصدر الخصم لازم يقول إنه خصم العميل — الشاشة بتعرضه للمستخدم');
    }

    /**
     * خصم صفر = سعر القائمة كامل، حتى مع `uses_channel_discount` مفعّل.
     *
     * ⚠️ **ده أهم تيست في الملف.** العمود ده كان معناه «ارجع لخصم
     * القناة»، والقناة مابقاش لها نسبة (قرار 2026-07-31). لو حد رجّع
     * القراية من العمود ده، كل عميل جديد اتحط في «كي أكاونت» هياخد
     * خصم أوتوماتيك من غير ما حد يتفاوض عليه — وده بالظبط الباج اللي
     * القرار اتاخد عشانه.
     */
    public function test_a_zero_rate_falls_back_to_the_full_list_price_not_to_the_channel(): void
    {
        $channel = $this->makeChannel();

        $client = $this->makeClient([
            'channel_id' => $channel->id,
            'discount' => 0,
            // العمود المهجور — لازم يتطنّش تماماً
            'uses_channel_discount' => true,
        ]);

        $this->assertEqualsWithDelta(0.0, $client->effectiveDiscount(), 0.0001,
            'القناة مالهاش نسبة — العميل من غير خصم خاص بياخد القائمة كاملة');

        $this->assertSame('no_discount', $client->discountSourceKey(),
            'ممنوع يرجع مصدر خصم اسمه القناة — المصادر بقت العقد أو العميل بس');
    }

    /**
     * عميلين في نفس القناة بنسبتين مختلفتين.
     *
     * ⚠️ القناة بُعد تجميع وتقرير (كام عميل، كام مبيعات) — مش مصدر
     * تسعير. لو النسبة رجعت للقناة، التيست ده بيقع لأن الاتنين
     * هياخدوا نفس الرقم.
     */
    public function test_two_clients_in_the_same_channel_can_be_on_different_rates(): void
    {
        $channel = $this->makeChannel();

        $a = $this->makeClient(['channel_id' => $channel->id, 'discount' => 0.40]);
        $b = $this->makeClient(['channel_id' => $channel->id, 'discount' => 0.55]);

        $this->assertEqualsWithDelta(0.40, $a->effectiveDiscount(), 0.0001);
        $this->assertEqualsWithDelta(0.55, $b->effectiveDiscount(), 0.0001);
    }

    /**
     * العقد السارٍ بيغلب خصم العميل.
     *
     * ⚠️ العقد اتفاق مكتوب واتوقّع، وخانة الخصم على كارت العميل رقم
     * بيتكتب بالإيد. لو الترتيب اتقلب، الفاتورة بتطلع بنسبة مخالفة
     * للعقد الموقّع — ودي مشكلة قانونية مش بس حسابية.
     */
    public function test_a_live_contract_beats_the_client_own_rate(): void
    {
        $client = $this->makeClient(['discount' => 0.10]);

        Contract::create([
            'client_id' => $client->id,
            'type' => 'test',
            'discount' => 0.35,
            'active' => true,
            'starts_at' => today()->subMonth(),
            'ends_at' => today()->addYear(),
        ]);

        $fresh = $client->fresh();

        $this->assertEqualsWithDelta(0.35, $fresh->effectiveDiscount(), 0.0001);
        $this->assertSame('contract', $fresh->discountSourceKey());
    }

    /**
     * العقد المنتهي بيرجّع الحكم لخصم العميل.
     *
     * ⚠️ العقد الميت كان لسه بيدي خصم — يعني عميل خلص عقده من سنة
     * لسه بياخد نسبته. `liveContract()` هي الحارس، والتيست ده بيقفل
     * عليها من ناحية الخصم.
     */
    public function test_an_expired_contract_gives_way_to_the_client_rate(): void
    {
        $client = $this->makeClient(['discount' => 0.10]);

        Contract::create([
            'client_id' => $client->id,
            'type' => 'test',
            'discount' => 0.35,
            'active' => true,
            'starts_at' => today()->subYears(2),
            'ends_at' => today()->subDay(),   // خلص إمبارح
        ]);

        $fresh = $client->fresh();

        $this->assertEqualsWithDelta(0.10, $fresh->effectiveDiscount(), 0.0001,
            'العقد المنتهي ممنوع يدي خصم');
        $this->assertSame('custom_discount', $fresh->discountSourceKey());
    }

    /**
     * العقد الموقوف (`active = false`) زيّه زي المنتهي.
     *
     * ⚠️ الإيقاف قرار إداري — لو فضل بيدي خصم، الإيقاف نفسه مالوش
     * معنى وحد هيفتكر إنه أوقفه وهو شغال.
     */
    public function test_an_inactive_contract_gives_no_discount(): void
    {
        $client = $this->makeClient(['discount' => 0]);

        Contract::create([
            'client_id' => $client->id,
            'type' => 'test',
            'discount' => 0.35,
            'active' => false,
            'starts_at' => today()->subMonth(),
            'ends_at' => today()->addYear(),
        ]);

        $this->assertEqualsWithDelta(0.0, $client->fresh()->effectiveDiscount(), 0.0001);
    }

    // ═══════════════ 2. سعر البيع للعميل ═══════════════

    /**
     * `priceFor()` = سعر القائمة ناقص الخصم.
     *
     * ⚠️ الدالة دي هي اللي الشاشات والأبلكيشن بيوروا بيها السعر
     * للمندوب قبل ما يبيع. لو اختلفت عن اللي الفاتورة بتتحسب بيه،
     * المندوب بيقول للعميل رقم والفاتورة بتطلع برقم تاني.
     */
    public function test_price_for_applies_the_discount_to_the_list_price(): void
    {
        $client = $this->makeClient(['discount' => 0.25, 'price_list' => 'new']);
        $product = $this->makeProduct(['price_new' => 100, 'price_old' => 80]);

        $this->assertEqualsWithDelta(75.0, $client->priceFor($product), 0.01,
            '100 ناقص 25% = 75');
    }

    /**
     * من غير خصم = سعر القائمة بالظبط.
     *
     * ⚠️ الحارس على الحالة الشائعة: أغلب العملاء من غير خصم، وأي
     * «خصم افتراضي» بيتسرّب من أي مكان بيبان هنا فوراً.
     */
    public function test_a_client_with_no_discount_pays_the_full_list_price(): void
    {
        $client = $this->makeClient(['discount' => 0, 'price_list' => 'new']);
        $product = $this->makeProduct(['price_new' => 100]);

        $this->assertEqualsWithDelta(100.0, $client->priceFor($product), 0.01);
    }

    /**
     * قائمة العميل هي اللي بتحدد السعر الأساسي.
     *
     * ⚠️ عميل على القائمة القديمة لازم يتحاسب بأسعارها. لو القائمة
     * اتطنشت، عميل بعقد على أسعار قديمة بياخد الأسعار الجديدة —
     * وده كسر لاتفاق مكتوب.
     */
    public function test_the_client_price_list_decides_the_base_price(): void
    {
        $product = $this->makeProduct(['price_old' => 80, 'price_new' => 100]);

        $onOld = $this->makeClient(['price_list' => 'old', 'discount' => 0]);
        $onNew = $this->makeClient(['price_list' => 'new', 'discount' => 0]);

        $this->assertSame('old', $onOld->priceList());
        $this->assertSame('new', $onNew->priceList());

        $this->assertEqualsWithDelta(80.0, $onOld->priceFor($product), 0.01);
        $this->assertEqualsWithDelta(100.0, $onNew->priceFor($product), 0.01);
    }

    /**
     * `priceFor()` و`Pricing::unitPrice()` نفس الرقم بالظبط.
     *
     * ⚠️ الموديل مفروض بيفوّض لـ`Pricing` مش بيحسب لوحده. أول ما
     * حد يحط حسبة في الموديل، الاتنين بيفترقوا والشاشة بتكدب على
     * الفاتورة.
     */
    public function test_the_model_just_delegates_to_the_pricing_service(): void
    {
        $client = $this->makeClient(['discount' => 0.15, 'price_list' => 'new']);
        $product = $this->makeProduct(['price_new' => 37.5]);

        $this->assertSame(
            Pricing::unitPrice($client, $product),
            $client->priceFor($product),
            'الموديل بيحسب بنفسه بدل ما يعدّي على Pricing',
        );
    }

    /**
     * سعر الوحدة في التسعيرة **مخصوم أصلاً** — ممنوع الخصم مرتين.
     *
     * ⚠️ الباج اللي حصل فعلاً: الخصم اتطبق على السطر وتاني على
     * الإجمالي. `line_total` لازم يساوي سعر الوحدة المخصوم × الكمية،
     * والفرق بين `list_price` و`unit_price` هو الخصم كله.
     */
    public function test_the_quote_discounts_once_and_the_line_total_follows_it(): void
    {
        $client = $this->makeClient(['discount' => 0.20, 'price_list' => 'new']);
        $product = $this->makeProduct(['price_new' => 50, 'cost' => 30]);

        $quote = $client->quoteFor($product, null, 3);

        $this->assertEqualsWithDelta(50.0, $quote['list_price'], 0.01);
        $this->assertEqualsWithDelta(0.20, $quote['discount_pct'], 0.0001);
        $this->assertEqualsWithDelta(40.0, $quote['unit_price'], 0.01);
        $this->assertEqualsWithDelta(120.0, $quote['line_total'], 0.01,
            'الخصم اتطبق مرتين لو الرقم أقل من 120');
        $this->assertSame('custom_discount', $quote['discount_source']);
    }

    /**
     * التسعيرة بتحمل التكلفة والهامش من نفس اللقطة.
     *
     * ⚠️ الربحية بتتخزّن على سطر الفاتورة وقت البيع. لو الهامش
     * اتحسب على سعر القائمة بدل السعر المخصوم، كل تقرير ربحية
     * بيطلع أعلى من الحقيقة.
     */
    public function test_the_margin_is_measured_against_the_discounted_price(): void
    {
        $client = $this->makeClient(['discount' => 0.20, 'price_list' => 'new']);
        $product = $this->makeProduct(['price_new' => 50, 'cost' => 30]);

        $quote = $client->quoteFor($product, null, 1);

        $this->assertEqualsWithDelta(30.0, $quote['unit_cost'], 0.01);
        $this->assertEqualsWithDelta(10.0, $quote['margin'], 0.01, '40 − 30 = 10');
        $this->assertEqualsWithDelta(0.25, $quote['margin_pct'], 0.0001, '10 ÷ 40');
    }
}
