<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Contract;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الفلوهات اللي ممنوع تنكسر
 * ═══════════════════════════════════════════════════════════════
 *
 * كل تيست هنا بيقابل باج **حصل فعلاً** واتصلح، والتيست موجود عشان
 * مايرجعش. الوصف بيقول الباج كان إيه.
 */
class CoreFlowsTest extends TestCase
{
    use RefreshDatabase;

    private function stockedRep(int $qty = 50): array
    {
        $channel = $this->makeChannel(0.0);
        $rep = $this->makeRep(['channel_id' => $channel->id]);

        // ⚠️ **الحضور قبل أي بيع** (حارس `RequireAttendance`، ٨/٨/٢٠٢٦).
        // من غيره كل بوست على `/api/invoices` بيرجّع 423 «مش مسجّل
        // حضور» — وده سلوك إنتاج صح، فالمشهد لازم يبصم زي الواقع.
        $this->punchIn($rep);

        $product = $this->makeProduct(['cost' => 10, 'price_old' => 18, 'price_new' => 20]);
        $warehouse = $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-1',
            'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6),
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'cost' => 10,
        ]);

        $custody = Custody::create([
            'user_id' => $rep->id,
            'warehouse_id' => $warehouse->id,
            'date' => today(),
            'status' => 'open',
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'assigned' => $qty,
            'sold' => 0,
        ]);

        return [$rep, $product, $channel, $custody];
    }

    // ═══════════════════════ 1. بيع كاش فان ═══════════════════════

    public function test_selling_more_than_the_van_holds_is_refused_and_changes_nothing(): void
    {
        [$rep, $product, $channel] = $this->stockedRep(10);
        $client = $this->makeClient(['channel_id' => $channel->id]);

        $res = $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 999]], ['payment' => 'cash']);

        // ⚠️ 422 مش 500 — الرفض المتوقّع بيرجع رسالة مفهومة
        $res->assertStatus(422);

        $this->assertSame(0, Invoice::count(), 'مفيش فاتورة اتعملت');
        $this->assertSame(0, Transaction::count(), 'مفيش قيد اتكتب');
    }

    public function test_credit_is_blocked_for_a_cash_only_client(): void
    {
        [$rep, $product, $channel] = $this->stockedRep();

        // ⚠️ تصنيف danger = كاش بس — حتى لو الخانة نفسها مكتوب فيها
        // آجل. ومن 2026-08-03 السيرفر مابيسمعش للبوست أصلاً: أي
        // `payment` مبعوت بيتطنش والفاتورة بتتسجل كاش بقيد تحصيل
        // مقابل، فالرصيد مايتحركش.
        $client = $this->makeClient([
            'channel_id' => $channel->id,
            'category' => 'danger',
            'payment_terms' => 'credit',   // بتتداس بالتصنيف
        ]);

        $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 1]], ['payment' => 'credit'])
            ->assertStatus(201);

        $invoice = Invoice::first();
        $this->assertSame('cash', $invoice->payment, 'المفروض تتقسر كاش');
        $this->assertEqualsWithDelta(0.0, (float) $client->fresh()->balance, 0.01,
            'عميل الكاش الإجباري مايتفتحلوش مديونية أبداً');
    }

    public function test_a_sale_deducts_from_the_van(): void
    {
        [$rep, $product, $channel, $custody] = $this->stockedRep(50);
        $client = $this->makeClient(['channel_id' => $channel->id]);

        $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 12]], ['payment' => 'cash'])
            ->assertStatus(201);

        $item = $custody->items()->first()->fresh();
        $this->assertSame(12, (int) $item->sold);
        $this->assertSame(38, $item->remaining());
    }

    // ═══════════════════════ 2. التسعير ═══════════════════════

    public function test_discount_is_applied_once_not_twice(): void
    {
        // ⚠️ الباج: الخصم كان بيتطبق على السطر وتاني على الإجمالي.
        [$rep, $product, $channel] = $this->stockedRep();

        $client = $this->makeClient([
            'channel_id' => $channel->id,
            'discount' => 0.10,
        ]);

        $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 10]], ['payment' => 'cash'])
            ->assertStatus(201);

        $invoice = Invoice::first();

        // 10 × 20 = 200 قبل الخصم، خصم 10% = 20، الصافي 180
        $this->assertEqualsWithDelta(200.0, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $invoice->discount, 0.01,
            'خصم مرتين كان بيدي 38 بدل 20');
        $this->assertEqualsWithDelta(180.0, (float) $invoice->total, 0.01);
    }

    /**
     * الخصم على **الفرع** مش على السلسلة ولا القناة.
     *
     * ⚠️ التيست ده اتغيّر مرتين: كان «خصم القناة» (اتشال 2026-07-31)
     * وبعدين «خصم السلسلة» (اتشال 2026-08-01). المصدر الوحيد الباقي
     * فوق العقد هو خصم العميل نفسه — واللي المندوب بيتفاوض عليه.
     */
    public function test_only_the_client_own_rate_applies_below_the_contract(): void
    {
        $group = \App\Models\ClientGroup::create([
            'code' => 'CHAIN-T', 'name' => 'سلسلة التيست', 'name_en' => 'Test chain',
            'active' => true,
        ]);

        // فرع في سلسلة ومالوش خصم خاص → سعر القائمة كامل
        $bare = $this->makeClient(['group_id' => $group->id, 'discount' => 0]);
        $this->assertEqualsWithDelta(0.0, $bare->effectiveDiscount(), 0.0001);

        // فرع تاني في نفس السلسلة بخصمه هو → خصمه بيشتغل
        $own = $this->makeClient(['group_id' => $group->id, 'discount' => 0.15]);
        $this->assertEqualsWithDelta(0.15, $own->effectiveDiscount(), 0.0001);
    }

    public function test_the_channel_gives_no_discount_at_all(): void
    {
        // ⚠️ الحارس على القرار: عميل في قناة من غير أي شروط تانية
        // بياخد **سعر القائمة كامل**.
        $channel = $this->makeChannel();
        $client = $this->makeClient(['channel_id' => $channel->id, 'discount' => 0]);

        $this->assertEqualsWithDelta(0.0, $client->effectiveDiscount(), 0.0001);
    }

    public function test_an_expired_contract_does_not_change_the_price_list(): void
    {
        $client = $this->makeClient(['price_list' => 'new']);

        Contract::create([
            'client_id' => $client->id,
            'type' => 'test',
            'discount' => 0.20,
            'price_list' => 'old',
            'active' => true,
            'starts_at' => today()->subYears(2),
            'ends_at' => today()->subMonth(),   // خلص
        ]);

        // ⚠️ الباج: العقد الميت كان لسه بيدي قائمة وخصم
        $this->assertSame('new', Pricing::listFor($client->fresh()),
            'العقد المنتهي ممنوع يغيّر قائمة السعر');
        $this->assertEqualsWithDelta(0.0, $client->fresh()->effectiveDiscount(), 0.0001,
            'العقد المنتهي ممنوع يدي خصم');
    }

    public function test_unit_price_is_already_discounted(): void
    {
        $channel = $this->makeChannel(0.0);
        $client = $this->makeClient([
            'channel_id' => $channel->id,
            'discount' => 0.25,
        ]);
        $product = $this->makeProduct(['price_new' => 100]);

        $quote = Pricing::quote($client, $product, null, 4);

        $this->assertEqualsWithDelta(100.0, $quote['list_price'], 0.01);
        $this->assertEqualsWithDelta(75.0, $quote['unit_price'], 0.01,
            'unit_price مخصوم أصلاً — ممنوع تخصم عليه تاني');
        $this->assertEqualsWithDelta(300.0, $quote['line_total'], 0.01);
    }

    // ═══════════════════════ 3. الليدجر ═══════════════════════

    public function test_credit_sale_raises_the_balance_and_cash_does_not(): void
    {
        [$rep, $product, $channel] = $this->stockedRep(100);

        // ⚠️ كاش/آجل من تعريف العميل مش من البوست — بنثبّتها هنا
        $onCredit = $this->makeClient(['channel_id' => $channel->id, 'name' => 'عميل آجل', 'payment_terms' => 'credit']);
        $onCash = $this->makeClient(['channel_id' => $channel->id, 'name' => 'عميل كاش', 'payment_terms' => 'cash']);

        $this->sellApi($rep, $onCredit,
            [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'credit'])
            ->assertStatus(201);

        $this->sellApi($rep, $onCash,
            [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'cash'])
            ->assertStatus(201);

        $this->assertEqualsWithDelta(100.0, (float) $onCredit->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $onCash->fresh()->balance, 0.01);
    }

    public function test_balance_always_equals_the_ledger(): void
    {
        // ⚠️ عمود `balance` تجميعة، و `transactions` مصدر الحقيقة.
        // لو الاتنين اختلفوا، كشف الحساب بيبقى مش مطابق للرصيد.
        [$rep, $product, $channel] = $this->stockedRep(100);
        $client = $this->makeClient(['channel_id' => $channel->id]);

        foreach ([3, 7, 2] as $qty) {
            $this->sellApi($rep, $client,
                [['product_id' => $product->id, 'qty' => $qty]], ['payment' => 'credit'])
                ->assertStatus(201);
        }

        $ledger = Transaction::where('client_id', $client->id)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')
            ->value('net');

        $this->assertEqualsWithDelta(
            (float) $ledger,
            (float) $client->fresh()->balance,
            0.01,
            'الرصيد لازم يساوي صافي كشف الحساب بالمليم',
        );
    }

    // ═══════════════════════ 4. الترقيم ═══════════════════════

    public function test_invoice_numbers_never_go_negative(): void
    {
        // ⚠️ الباج: `filter_var(FILTER_SANITIZE_NUMBER_INT)` كان بيسيب
        // الإشارة السالبة فـ INV-1001 بقت -1001 والترقيم اتكسر.
        $this->assertSame('INV-1001', Invoice::nextNumber());

        [$rep, $product, $channel] = $this->stockedRep();
        $client = $this->makeClient(['channel_id' => $channel->id]);

        $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 1]], ['payment' => 'cash'])
            ->assertStatus(201);

        $this->assertSame('INV-1002', Invoice::nextNumber());
    }

    // ═══════════════════════ 5. الأسماء المزدوجة ═══════════════════════

    public function test_display_name_falls_back_to_arabic_when_english_is_missing(): void
    {
        app()->setLocale('en');

        $withEn = $this->makeClient(['name' => 'محل النور', 'name_en' => 'Al Nour Market']);
        $withoutEn = $this->makeClient(['name' => 'محل الأمل', 'name_en' => null]);

        $this->assertSame('Al Nour Market', $withEn->displayName());

        // ⚠️ ممنوع خانة فاضية في الشاشة — بيرجع العربي أحسن من الفراغ
        $this->assertSame('محل الأمل', $withoutEn->displayName());

        app()->setLocale('ar');
        $this->assertSame('محل النور', $withEn->displayName());
    }

    public function test_users_have_bilingual_names_too(): void
    {
        // ⚠️ الباج: `users` كان الجدول الوحيد من غير `name_en`،
        // فأسماء الفريق كانت بتفضل عربي في الواجهة الإنجليزية.
        app()->setLocale('en');

        $rep = $this->makeRep(['name' => 'أحمد محمود', 'name_en' => 'Ahmed Mahmoud']);

        $this->assertSame('Ahmed Mahmoud', $rep->displayName());
    }
}
