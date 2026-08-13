<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * عقيدة الأرقام — كشف الحساب هو مصدر الحقيقة الوحيد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الملف ده مهم:** جدول `transactions` هو المصدر الوحيد لفلوس
 * العميل، وعمود `clients.balance` مجرد تجميعة بتتحسب منه. لو الاتنين
 * اتفرقوا، كشف الحساب بيقول رقم والكارت بيقول رقم تاني ومحدش يعرف
 * أنهي واحد صح — ودي أسوأ حالة ممكنة في نظام محاسبي، لأنها بتفضل
 * ساكتة لحد ما العميل يعترض.
 *
 * الدوكترين اللي بيتحرس هنا:
 *   • فاتورة كاش  ⇒ قيدين: `sale` مدين + `collection` دائن ⇒ الرصيد صفر
 *   • فاتورة آجل  ⇒ قيد `sale` بس ⇒ الرصيد بيزيد بالقيمة
 *   • `recalculate()` ⇒ `balance` = مجموع (مدين − دائن) بالمليم
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مندوب بعهدة مليانة — نفس التجهيز اللي بيسبق أي فاتورة حقيقية.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Product, 2: \App\Models\Channel}
     */
    private function stockedRep(int $qty = 100): array
    {
        $channel = $this->makeChannel();
        $rep = $this->makeRep(['channel_id' => $channel->id]);

        // ⚠️ **الحضور قبل أي بيع** (حارس `RequireAttendance`، ٨/٨/٢٠٢٦).
        // من غيره كل بوست على `/api/invoices` بيرجّع 423 «مش مسجّل حضور».
        $this->punchIn($rep);

        $product = $this->makeProduct(['cost' => 10, 'price_old' => 18, 'price_new' => 20]);
        $warehouse = $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-LEDGER',
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

        return [$rep, $product, $channel];
    }

    // ═══════════════ 1. الفاتورة الكاش ═══════════════

    /**
     * فاتورة كاش بتكتب قيدين والرصيد بيفضل صفر.
     *
     * ⚠️ **القيد الواحد مش كفاية.** لو اتكتب `sale` بس من غير
     * `collection`، العميل الكاش اللي دفع في إيد المندوب بيبان عليه
     * مديونية — وقايمة المتأخرات بتمتلي بعملاء مامديونينش، والمتابعة
     * بتلاحق ناس مش مدينة وبتسيب اللي فعلاً متأخر.
     *
     * ⚠️ **والعكس برضه غلط:** لو اتكتب قيد صافي واحد بصفر، المبيعات
     * بتختفي من كشف الحساب ومن `purchases` — والعميل يبان كأنه
     * ماشتراش حاجة.
     */
    public function test_a_cash_invoice_writes_a_sale_and_a_matching_collection(): void
    {
        [$rep, $product] = $this->stockedRep();
        $client = $this->makeClient(['payment_terms' => 'cash']);

        $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'cash'])
            ->assertStatus(201);

        $invoice = Invoice::firstOrFail();
        $rows = Transaction::where('client_id', $client->id)->get();

        $this->assertCount(2, $rows, 'فاتورة الكاش لازم تسيب قيدين بالظبط');

        $sale = $rows->firstWhere('kind', 'sale');
        $collection = $rows->firstWhere('kind', 'collection');

        $this->assertNotNull($sale, 'قيد المبيعات ناقص');
        $this->assertNotNull($collection, 'قيد التحصيل المقابل ناقص');

        // ⚠️ المدين بالإجمالي شامل الضريبة — ده اللي العميل بيدفعه فعلاً
        $this->assertEqualsWithDelta($invoice->payable(), (float) $sale->debit, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $sale->credit, 0.01,
            'قيد المبيعات مدين بس — الأعمدة موجبة دايماً');

        $this->assertEqualsWithDelta($invoice->payable(), (float) $collection->credit, 0.01,
            'التحصيل بالإجمالي عشان الرصيد يرجع صفر بالظبط');
        $this->assertEqualsWithDelta(0.0, (float) $collection->debit, 0.01);

        $this->assertEqualsWithDelta(0.0, (float) $client->fresh()->balance, 0.01,
            'عميل الكاش مايتفتحلوش مديونية أبداً');
    }

    /**
     * القيدين مربوطين بالفاتورة نفسها.
     *
     * ⚠️ من غير الربط (`source`)، مافيش طريقة نعرف بيها إن التحصيل
     * ده بتاع الفاتورة دي — ولا نقدر نلغي فاتورة من غير ما نسيب
     * قيدها الدائن يتيم في كشف الحساب.
     */
    public function test_both_entries_point_back_at_their_invoice(): void
    {
        [$rep, $product] = $this->stockedRep();
        $client = $this->makeClient(['payment_terms' => 'cash']);

        $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 2]], ['payment' => 'cash'])
            ->assertStatus(201);

        $invoice = Invoice::firstOrFail();

        $this->assertCount(2, $invoice->transactions,
            'القيدين لازم يكونوا متعلقين بالفاتورة عن طريق source');
    }

    // ═══════════════ 2. الفاتورة الآجل ═══════════════

    /**
     * فاتورة آجل بتكتب قيد `sale` بس والرصيد بيزيد بقيمتها.
     *
     * ⚠️ **ممنوع أي قيد تحصيل هنا.** لو اتكتب، مديونية العميل بتختفي
     * وهو لسه مادفعش — والشركة بتفقد أثر فلوس عند العميل.
     */
    public function test_a_credit_invoice_writes_one_entry_and_raises_the_balance(): void
    {
        [$rep, $product] = $this->stockedRep();

        // ⚠️ كاش/آجل من تعريف العميل مش من البوست (قرار 2026-08-03) —
        // فبنثبّتها على الكارت نفسه، والتصنيف مش `danger` عشان
        // مايقسرش الكاش.
        $client = $this->makeClient([
            'payment_terms' => 'credit',
            'category' => 'ok',
        ]);

        $this->sellApi($rep, $client,
            [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'credit'])
            ->assertStatus(201);

        $invoice = Invoice::firstOrFail();
        $rows = Transaction::where('client_id', $client->id)->get();

        $this->assertCount(1, $rows, 'الفاتورة الآجل قيد واحد بس');
        $this->assertSame('sale', $rows->first()->kind);
        $this->assertSame('credit', $invoice->payment);

        // 5 × 20 = 100 من غير ضريبة (الضريبة مقفولة افتراضياً)
        $this->assertEqualsWithDelta(100.0, $invoice->payable(), 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $client->fresh()->balance, 0.01,
            'الرصيد لازم يزيد بقيمة الفاتورة بالظبط');
    }

    /**
     * فواتير آجل متتالية بتتراكم على الرصيد.
     *
     * ⚠️ الحارس على أي «إعادة تعيين» للرصيد بدل الإضافة —
     * `recalculate()` بتعيد الحساب من كل الحركات، فلو حد كتب
     * `balance = آخر فاتورة` التيست ده بيقع.
     */
    public function test_credit_invoices_accumulate_on_the_balance(): void
    {
        [$rep, $product] = $this->stockedRep();
        $client = $this->makeClient(['payment_terms' => 'credit', 'category' => 'ok']);

        foreach ([3, 7, 2] as $qty) {
            $this->sellApi($rep, $client,
                [['product_id' => $product->id, 'qty' => $qty]], ['payment' => 'credit'])
                ->assertStatus(201);
        }

        // (3 + 7 + 2) × 20 = 240
        $this->assertEqualsWithDelta(240.0, (float) $client->fresh()->balance, 0.01);
    }

    // ═══════════════ 3. الرصيد = كشف الحساب ═══════════════

    /**
     * `recalculate()` بتخلّي `balance` = مجموع المدين ناقص الدائن.
     *
     * ⚠️ **ده العقد الأساسي للسيستم كله.** كل شاشة بتعرض رصيد بتقرا
     * العمود، وكل كشف حساب بيقرا الحركات. الاتنين لازم يقولوا نفس
     * الرقم بالمليم، وإلا العميل بيشوف كشف حساب مش مطابق للمبلغ
     * اللي بنطالبه بيه.
     */
    public function test_recalculate_makes_the_balance_equal_the_ledger(): void
    {
        $client = $this->makeClient();

        $entries = [
            ['kind' => 'sale', 'debit' => 1000, 'credit' => 0],
            ['kind' => 'sale', 'debit' => 500, 'credit' => 0],
            ['kind' => 'collection', 'debit' => 0, 'credit' => 700],
            ['kind' => 'return', 'debit' => 0, 'credit' => 120.50],
            ['kind' => 'rebate', 'debit' => 0, 'credit' => 79.50],
        ];

        foreach ($entries as $e) {
            Transaction::create($e + [
                'client_id' => $client->id,
                'date' => today()->subDays(3),
                'memo' => 'قيد تيست',
            ]);
        }

        $client->recalculate();
        $fresh = $client->fresh();

        $ledger = Transaction::where('client_id', $client->id)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as net')
            ->value('net');

        // 1500 مدين − 900 دائن = 600
        $this->assertEqualsWithDelta(600.0, (float) $ledger, 0.01);
        $this->assertEqualsWithDelta(
            (float) $ledger,
            (float) $fresh->balance,
            0.01,
            'الرصيد لازم يساوي صافي كشف الحساب بالمليم',
        );
    }

    /**
     * `recalculate()` بتوزّع الحركات على أعمدتها حسب النوع.
     *
     * ⚠️ الأعمدة دي (`purchases` / `collections` / `returns` …) هي
     * اللي التقارير بتتبني عليها. لو التوزيع غلط، إجمالي المبيعات
     * في الداشبورد بيختلف عن مجموع الفواتير من غير سبب ظاهر.
     */
    public function test_recalculate_splits_the_ledger_by_kind(): void
    {
        $client = $this->makeClient();

        $rows = [
            ['kind' => 'sale', 'debit' => 800, 'credit' => 0],
            ['kind' => 'collection', 'debit' => 0, 'credit' => 300],
            ['kind' => 'return', 'debit' => 0, 'credit' => 50],
            ['kind' => 'rebate', 'debit' => 0, 'credit' => 25],
            ['kind' => 'settlement', 'debit' => 0, 'credit' => 25],
        ];

        foreach ($rows as $r) {
            Transaction::create($r + [
                'client_id' => $client->id,
                'date' => today(),
                'memo' => 'قيد تيست',
            ]);
        }

        $client->recalculate();
        $fresh = $client->fresh();

        $this->assertEqualsWithDelta(800.0, (float) $fresh->purchases, 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $fresh->collections, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $fresh->returns, 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $fresh->rebates, 0.01);
        $this->assertEqualsWithDelta(25.0, (float) $fresh->settlements, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $fresh->balance, 0.01);
    }

    /**
     * الرصيد الافتتاحي بيستبدل نفسه مش بيتراكم.
     *
     * ⚠️ الباج اللي الحارس ده موجود عشانه: كتابة الافتتاحي مرتين
     * كانت بتسيب القيد القديم، فرصيد أول المدة بيتحسب مرتين ورصيد
     * العميل يطلع ضعف الحقيقة من غير ما حد يعرف من فين.
     */
    public function test_the_opening_balance_never_stacks_on_itself(): void
    {
        $client = $this->makeClient();

        $client->setOpeningBalance(1000);
        $client->setOpeningBalance(400);

        $this->assertSame(1, Transaction::where('client_id', $client->id)
            ->where('kind', 'opening')->count(),
            'قيد افتتاحي واحد بس لكل عميل');

        $this->assertEqualsWithDelta(400.0, (float) $client->fresh()->balance, 0.01);
    }

    /**
     * الافتتاحي السالب بيتقيّد **دائن** مش مدين بالسالب.
     *
     * ⚠️ أعمدة المدين والدائن موجبة دايماً. رقم سالب في المدين
     * بيكسّر كل جمع في كشف الحساب وفي التقارير اللي بتجمع العمود
     * لوحده.
     */
    public function test_a_negative_opening_balance_lands_in_the_credit_column(): void
    {
        $client = $this->makeClient();

        $txn = $client->setOpeningBalance(-250);

        $this->assertNotNull($txn);
        $this->assertEqualsWithDelta(0.0, (float) $txn->debit, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $txn->credit, 0.01);
        $this->assertEqualsWithDelta(-250.0, (float) $client->fresh()->balance, 0.01,
            'الرصيد الدائن بيبان بالسالب — العميل دافع مقدماً');
    }
}
