<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ClientGroup;
use App\Models\Contract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * القناة بُعد تجميع مش مصدر تسعير
 * ═══════════════════════════════════════════════════════════════
 *
 * **قرار 2026-07-31.** الخصم بقى بالترتيب ده وبس:
 *
 *   1. العقد السارٍ (بتاع العميل أو الموروث من سلسلته)
 *   2. خصم خاص متسجّل على العميل
 *   3. خصم السلسلة
 *   4. صفر
 *
 * ⚠️ لما كانت القناة بتدي نسبة، عميل جديد اتحط في «كي أكاونت» كان
 * بياخد 50% أوتوماتيك من غير ما حد يتفاوض عليها — وأول فاتورة بتطلع
 * بخصم محدش قرره ومحدش واخد باله.
 */
class ChannelIsNotPricingTest extends TestCase
{
    use RefreshDatabase;

    private function channel(string $code = Channel::KEY_ACCOUNT): Channel
    {
        return Channel::create([
            'code' => $code,
            'name' => 'قناة',
            'name_en' => 'Channel',
            'active' => true,
        ]);
    }

    // ═══════════════════ السكيما ═══════════════════

    public function test_the_channel_table_has_no_discount_column(): void
    {
        $this->assertFalse(Schema::hasColumn('channels', 'discount'),
            'عمود خصم على القناة معناه إن حد هيكتب فيه رقم وهيتطبق على عملاء محدش راجعهم');
    }

    public function test_the_channel_model_cannot_be_given_a_rate(): void
    {
        $this->assertNotContains('discount', (new Channel)->getFillable());
    }

    // ═══════════════════ الترتيب المقدّس ═══════════════════

    public function test_a_client_with_no_terms_gets_no_discount(): void
    {
        // ⚠️ ده جوهر التغيير: العميل في قناة، ومالوش عقد ولا خصم خاص
        // ولا سلسلة → **سعر القائمة كامل**، مش نسبة القناة.
        $client = $this->makeClient(['channel_id' => $this->channel()->id, 'discount' => 0]);

        $this->assertEqualsWithDelta(0.0, $client->effectiveDiscount(), 0.0001);
        $this->assertSame('no_discount', $client->discountSourceKey());
    }

    public function test_two_clients_in_the_same_channel_can_be_on_different_rates(): void
    {
        // ⚠️ ده اللي الشغل كله اتعمل عشانه — النسبة لكل عميل.
        $channel = $this->channel();

        $a = $this->makeClient(['channel_id' => $channel->id, 'discount' => 0.40]);
        $b = $this->makeClient(['channel_id' => $channel->id, 'discount' => 0.55]);

        $this->assertEqualsWithDelta(0.40, $a->effectiveDiscount(), 0.0001);
        $this->assertEqualsWithDelta(0.55, $b->effectiveDiscount(), 0.0001);
    }

    public function test_the_contract_still_wins_over_everything(): void
    {
        $client = $this->makeClient(['channel_id' => $this->channel()->id, 'discount' => 0.20]);

        Contract::create([
            'client_id' => $client->id,
            'number' => 'CNT-P1',
            'discount' => 0.45,
            'active' => true,
        ]);

        $this->assertEqualsWithDelta(0.45, $client->fresh()->effectiveDiscount(), 0.0001);
        $this->assertSame('contract', $client->fresh()->discountSourceKey());
    }

    /**
     * ⚠️ **السلسلة مابقتش مصدر خصم — قرار 2026-08-01.**
     *
     * التيست ده كان بيوثّق العكس: فرع بياخد 30% من سلسلته. اتغيّر
     * لأن السلسلة بقت **مكان بنجمع فيه الفروع تحت اسم واحد** عشان
     * نشوف إجمالياتها — مش كيان تجاري ليه شروطه. كل فرع بيتفاوض
     * لوحده، وخصم على مستوى السلسلة كان بيتجاهل اتفاق الفرع.
     *
     * خصومات السلاسل القديمة اتنقلت على فروعها في مايجريشن
     * `000028_drop_group_discount` قبل ما العمود يتشال — فمفيش عميل
     * فقد خصمه.
     */
    public function test_the_chain_gives_no_discount_at_all(): void
    {
        $group = ClientGroup::create([
            'code' => 'CK', 'name' => 'سيركل كيه', 'name_en' => 'Circle K',
            'active' => true,
        ]);

        $client = $this->makeClient([
            'channel_id' => $this->channel()->id,
            'group_id' => $group->id,
            'discount' => 0,
        ]);

        $this->assertEqualsWithDelta(0.0, $client->effectiveDiscount(), 0.0001);
        $this->assertSame('no_discount', $client->discountSourceKey());
    }

    /**
     * السلسلة مافيهاش أعمدة خصم أصلاً.
     *
     * ⚠️ الحارس على القرار: أول ما حد يرجّع العمود، التيست ده بيقع
     * ويسأل «إحنا اتفقنا على إيه؟» قبل ما الخصم يرجع يشتغل في صمت
     * على كل فروع السلسلة.
     */
    public function test_the_chain_table_has_no_discount_columns(): void
    {
        foreach (['discount', 'uses_group_discount'] as $col) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('client_groups', $col),
                "عمود «{$col}» رجع على السلاسل — الخصم على الفرع مش على السلسلة",
            );
        }
    }

    public function test_moving_a_client_between_channels_never_changes_his_price(): void
    {
        // ⚠️ ده كان بيحصل فعلاً: نقل عميل من «جملة» لـ«كاش فان» كان
        // بينقله من 50% لصفر في لحظة — وأول فاتورة بعدها بضعف السعر.
        $wholesale = $this->channel(Channel::WHOLESALE);
        $cashVan = $this->channel(Channel::CASH_VAN);

        $client = $this->makeClient(['channel_id' => $wholesale->id, 'discount' => 0.35]);
        $before = $client->effectiveDiscount();

        $client->update(['channel_id' => $cashVan->id]);

        $this->assertEqualsWithDelta($before, $client->fresh()->effectiveDiscount(), 0.0001,
            'نقل العميل بين القنوات ممنوع يغيّر سعره');
    }

    // ═══════════════════ الشاشة ═══════════════════

    public function test_the_channels_screen_shows_goods_and_sales_not_a_rate(): void
    {
        $admin = $this->makeAdmin();
        $this->channel();

        $html = $this->actingAs($admin)->get('/erp/channels')->assertOk()->getContent();

        $this->assertStringContainsString(__('channel.goods_at_clients'), $html);
        $this->assertStringContainsString(__('channel.goods_in_vans'), $html);
        $this->assertStringContainsString(__('channel.units_sold'), $html);
        $this->assertStringContainsString(__('channel.discount_spread'), $html);
    }

    public function test_the_channel_edit_form_cannot_set_a_rate(): void
    {
        $admin = $this->makeAdmin();
        $channel = $this->channel();

        // ⚠️ حتى لو حد بعت `discount` بإيده، مالهاش أثر — مفيش عمود
        // ومفيش قاعدة. الشاشة مش هي الحارس الوحيد.
        $this->actingAs($admin)->put('/erp/channels/'.$channel->id, [
            'name' => 'كي أكاونت',
            'name_en' => 'Key Account',
            'discount' => 50,
            'active' => 1,
        ])->assertRedirect();

        $this->assertSame('Key Account', $channel->fresh()->name_en);

        $html = (string) file_get_contents(resource_path('views/erp/channels.blade.php'));
        $this->assertStringNotContainsString('name="discount"', $html,
            'خانة خصم على شاشة القنوات بترجّع السلوك القديم');
    }

    public function test_clients_with_no_channel_are_surfaced_not_hidden(): void
    {
        // ⚠️ العميل من غير قناة مش داخل في أي رقم في الشاشة. من غير
        // التنبيه، إجمالي الشاشة بيقل عن إجمالي السيستم ومحدش يعرف
        // الفرق راح فين.
        $admin = $this->makeAdmin();
        $this->channel();
        $this->makeClient(['channel_id' => null, 'status' => 'active']);

        $this->actingAs($admin)->get('/erp/channels')
            ->assertOk()
            ->assertSee(__('channel.orphan_clients', ['count' => 1]), false);
    }

    // ═══════════════════ الأربعة ═══════════════════

    public function test_the_four_channel_defaults_carry_no_rate(): void
    {
        foreach (Channel::DEFAULTS as $code => $row) {
            $this->assertCount(3, $row,
                "القناة {$code}: [عربي، إنجليزي، لون] — أي عنصر رابع معناه نسبة رجعت");
        }
    }
}
