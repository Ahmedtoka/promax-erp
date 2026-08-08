<?php

namespace Tests\Feature;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use App\Services\Returns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * سايكل المرتجعات (٨ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * كل تيست بيقابل قرار مالك أو باج حقيقي:
 *   • التسعير من الفاتورة الأصلية مش سعر النهارده
 *   • السقف من نفس المصدر — مندوب كان يدّي رصيد بلا حد
 *   • السياسة بتتعرّف على العميل
 *   • سليم/تالف في خانتين منفصلتين
 *   • منع التكرار
 */
class ReturnsCycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * عميل اشترى ١٠ قطع بسعر ٢٠، ومندوب بعهدة مفتوحة.
     *
     * @return array{0: Client, 1: \App\Models\User, 2: \App\Models\Product}
     */
    private function soldTen(float $price = 20.0): array
    {
        $this->makePriceList('new');

        $rep = $this->makeRep();
        $product = $this->makeProduct(['cost' => 10, 'price_old' => 18, 'price_new' => $price]);

        $client = $this->makeClient([
            'rep_id' => $rep->id,
            'payment_terms' => Client::PAY_CREDIT,
            'payment_days' => 30,
        ]);

        $warehouse = $this->makeWarehouse();

        $custody = Custody::create([
            'user_id' => $rep->id,
            'warehouse_id' => $warehouse->id,
            'date' => today(),
            'status' => 'open',
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id,
            'product_id' => $product->id,
            'assigned' => 0,
            'sold' => 0,
            'returned' => 0,
            'returned_in' => 0,
            'damaged_in' => 0,
        ]);

        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(10000, 99999),
            'client_id' => $client->id,
            'user_id' => $rep->id,
            'payment' => 'credit',
            'subtotal' => $price * 10,
            'discount' => 0,
            'total' => $price * 10,
            'tax_total' => 0,
            'grand_total' => $price * 10,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 10,
            'list_price' => $price,
            'price' => $price,
            'unit_cost' => 10,
            'total' => $price * 10,
            'tax_rate' => 0,
            'tax' => 0,
        ]);

        Transaction::create([
            'client_id' => $client->id,
            'date' => today(),
            'memo' => 'بيع',
            'debit' => $price * 10,
            'credit' => 0,
            'kind' => 'sale',
        ]);

        $client->recalculate();

        return [$client->fresh(), $rep, $product];
    }

    // ═══════════════════ المتاح للرد ═══════════════════

    public function test_returnable_reads_from_invoices_not_from_custody(): void
    {
        [$client, , $product] = $this->soldTen();

        $rows = Returns::returnableByProduct($client);

        $this->assertSame(10, $rows[$product->id] ?? 0);
    }

    public function test_returnable_shrinks_after_a_return(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 4]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        $rows = Returns::returnableByProduct($client->fresh());

        $this->assertSame(6, $rows[$product->id] ?? 0);
    }

    /**
     * ⚠️ **الثغرة**: مندوب كان يقدر يدّي العميل رصيد بلا حد — مفيش
     * سقف بالمشتريات.
     */
    public function test_returning_more_than_purchased_is_rejected(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        $this->expectException(Rejected::class);

        Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 11]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );
    }

    // ═══════════════════ التسعير ═══════════════════

    /**
     * ⚠️ **الباج**: المرتجع كان بيتسعّر بسعر النهارده — فلو السعر
     * اتغيّر بين البيع والمرتجع، الدفتر بيسيب باقي مالوش تفسير.
     */
    public function test_return_is_priced_from_the_original_invoice(): void
    {
        [$client, $rep, $product] = $this->soldTen(20.0);

        // السعر اتغيّر بعد البيع
        $product->update(['price_new' => 35]);

        $doc = Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 3]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        // ٣ × ٢٠ (سعر الفاتورة) مش ٣ × ٣٥
        $this->assertEqualsWithDelta(60.0, (float) $doc->grand_total, 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $doc->items->first()->price, 0.01);
    }

    /** والبند بيفضل مربوط بسطر الفاتورة عشان المراجعة تمشي */
    public function test_return_item_links_back_to_the_invoice_line(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        $doc = Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 2]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        $this->assertNotNull($doc->items->first()->invoice_item_id);
        $this->assertNotNull($doc->items->first()->invoice_id);
    }

    // ═══════════════════ السياسات ═══════════════════

    /** ⚠️ السياسة بتتعرّف على العميل، والسيرفر بيرفض غير المسموح */
    public function test_policy_not_allowed_for_the_client_is_rejected(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        $client->update(['return_policies' => [Client::RETURN_ACCOUNT]]);

        $this->expectException(Rejected::class);

        Returns::create(
            client: $client->fresh(),
            items: [['product_id' => $product->id, 'qty' => 1]],
            policy: Client::RETURN_CASH,
            rep: $rep,
        );
    }

    /** ومابترجعش فاضية أبداً — الافتراضي بيتبع شروط الدفع */
    public function test_default_policies_follow_payment_terms(): void
    {
        $cash = $this->makeClient(['payment_terms' => Client::PAY_CASH]);
        $credit = $this->makeClient(['payment_terms' => Client::PAY_CREDIT]);

        $this->assertContains(Client::RETURN_CASH, $cash->returnPolicies());
        $this->assertNotContains(Client::RETURN_CASH, $credit->returnPolicies());
        $this->assertContains(Client::RETURN_ACCOUNT, $credit->returnPolicies());
    }

    /** `account` = قيد دائن واحد بيقلل المديونية */
    public function test_account_policy_reduces_the_balance(): void
    {
        [$client, $rep, $product] = $this->soldTen();
        $before = (float) $client->balance;

        Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 5]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        $this->assertEqualsWithDelta($before - 100, (float) $client->fresh()->balance, 0.01);
    }

    /**
     * `cash` = قيدين، والرصيد مايتغيّرش — المندوب ردّ الفلوس في إيده.
     */
    public function test_cash_policy_leaves_the_balance_untouched(): void
    {
        [$client, $rep, $product] = $this->soldTen();
        $client->update(['return_policies' => [Client::RETURN_CASH]]);
        $before = (float) $client->balance;

        $doc = Returns::create(
            client: $client->fresh(),
            items: [['product_id' => $product->id, 'qty' => 5]],
            policy: Client::RETURN_CASH,
            rep: $rep,
        );

        $this->assertEqualsWithDelta($before, (float) $client->fresh()->balance, 0.01);
        $this->assertNotNull($doc->refund_transaction_id);

        // والقيد المدين نوعه `refund` — تصفية المندوب بتدوّر عليه بالنوع ده
        $this->assertSame('refund', Transaction::find($doc->refund_transaction_id)->kind);
    }

    // ═══════════════════ سليم / تالف ═══════════════════

    /**
     * ⚠️ **قرار المالك**: السليم بيرجع للبيع والتالف لأ — فخانتين
     * منفصلتين في العهدة، والاتنين بيبانوا في التصفية.
     */
    public function test_good_and_damaged_land_in_separate_custody_columns(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        $doc = Returns::create(
            client: $client,
            items: [
                ['product_id' => $product->id, 'qty' => 3, 'condition' => 'good'],
                ['product_id' => $product->id, 'qty' => 2, 'condition' => 'damaged'],
            ],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        $this->assertSame(3, (int) $doc->good_units);
        $this->assertSame(2, (int) $doc->damaged_units);

        $item = CustodyItem::where('product_id', $product->id)
            ->whereNull('batch_id')->first();

        $this->assertSame(3, (int) $item->returned_in);
        $this->assertSame(2, (int) $item->damaged_in);
    }

    /** والقيمة بتتحسب على الاتنين — التالف بياخد نفس السعر */
    public function test_damaged_goods_are_still_credited(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        $doc = Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 2, 'condition' => 'damaged']],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        $this->assertEqualsWithDelta(40.0, (float) $doc->grand_total, 0.01);
    }

    // ═══════════════════ منع التكرار ═══════════════════

    /**
     * ⚠️ **الثغرة**: مفيش idempotency — N نداءات على شبكة ضعيفة
     * = N قيود دائنة.
     */
    public function test_same_idem_key_returns_the_same_document(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        $a = Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 2]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
            idemKey: 'ret-fixed-key',
        );

        $b = Returns::create(
            client: $client->fresh(),
            items: [['product_id' => $product->id, 'qty' => 2]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
            idemKey: 'ret-fixed-key',
        );

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ClientReturn::count());
        $this->assertSame(1, Transaction::where('kind', 'return')->count());
    }

    // ═══════════════════ المستند والقيد ═══════════════════

    /** القيد مصدره **المستند** مش الزيارة — عشان المراجعة توصل للبنود */
    public function test_credit_entry_points_at_the_return_document(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        $doc = Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 1]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        $entry = Transaction::find($doc->transaction_id);

        $this->assertSame(ClientReturn::class, $entry->source_type);
        $this->assertSame($doc->id, (int) $entry->source_id);
    }

    /**
     * عميل الأمانة مرتجعه مرفوض — بضاعته أصلاً ملك بروماكس، والتسوية
     * من تقرير مبيعات الفرع مش من هنا.
     */
    public function test_consignment_client_cannot_return_here(): void
    {
        [$client, $rep, $product] = $this->soldTen();

        // ⚠️ **`settlement_mode` مش `type`** — `Contract::isConsignment()`
        // بتقرا وضع التسوية، والنوع نص وصفي بس.
        \App\Models\Contract::create([
            'client_id' => $client->id,
            'number' => 'CT-'.random_int(1000, 9999),
            'type' => 'consignment',
            'settlement_mode' => \App\Models\Contract::MODE_CONSIGNMENT,
            'active' => true,
            'starts_at' => today()->subMonth(),
            'discount' => 0,
        ]);

        $client = $client->fresh();

        $this->assertTrue(
            $client->isConsignment(),
            'الفرضية: العقد بيخلّي العميل أمانة',
        );

        $this->expectException(Rejected::class);

        Returns::create(
            client: $client,
            items: [['product_id' => $product->id, 'qty' => 1]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );
    }

    /** المرتجع بلا أصناف مرفوض — مش مستند فاضي */
    public function test_empty_return_is_rejected(): void
    {
        [$client, $rep] = $this->soldTen();

        $this->expectException(Rejected::class);

        Returns::create(
            client: $client,
            items: [],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );
    }
}
