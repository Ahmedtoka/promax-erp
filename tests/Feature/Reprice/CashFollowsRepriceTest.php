<?php

namespace Tests\Feature\Reprice;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reprice\FullResync;
use App\Services\Reprice\PriceChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * (١٣/١١/٢٠٢٦ قرار أحمد) «أكيد مبلغ التحصيل اللي نزل على السعر القديم غلط»:
 * إعادة التسعير الجماعية (PriceChange / FullResync) لازم تحرّك تحصيل الفاتورة
 * الكاش وردّ المرتجع الكاش مع الإجمالي الجديد. الآجل يفضل الفرق على الرصيد.
 */
class CashFollowsRepriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Client $client;

    private Product $product;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTax(14);
        $this->admin = $this->makeAdmin();
        $this->rep = $this->makeRep();
        $this->list = PriceList::create(['code' => 'la', 'name' => 'قايمة أ', 'active' => true, 'is_default' => false]);
        $this->product = $this->makeProduct(['cost' => 5]);
        PriceListItem::create(['price_list_id' => $this->list->id, 'product_id' => $this->product->id, 'price' => 8]);
        $this->client = $this->makeClient(['price_list_id' => $this->list->id, 'taxable' => true]);
    }

    /** فاتورة ٣ × ٨ (٢٧٫٣٦ شامل) + قيد البيع، وقيود تحصيل حسب العدد */
    private function invoice(string $payment, int $collections = 1): Invoice
    {
        $inv = Invoice::create([
            'number' => 'INV-'.random_int(10000, 99999), 'client_id' => $this->client->id, 'user_id' => $this->rep->id,
            'payment' => $payment, 'price_list' => 'la', 'subtotal' => 24, 'discount_pct' => 0, 'discount' => 0,
            'total' => 24, 'tax_total' => 3.36, 'grand_total' => 27.36, 'eta_status' => 'ready', 'cost_total' => 15,
        ]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $this->product->id, 'qty' => 3, 'list_price' => 8,
            'price' => 8, 'unit_cost' => 5, 'total' => 24, 'tax_rate' => 0.14, 'tax' => 3.36]);
        Transaction::create(['client_id' => $this->client->id, 'date' => today(), 'memo' => 'بيع', 'debit' => 27.36, 'credit' => 0,
            'tax' => 3.36, 'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        for ($i = 0; $i < $collections; $i++) {
            Transaction::create(['client_id' => $this->client->id, 'date' => today(), 'memo' => 'تحصيل', 'debit' => 0, 'credit' => 27.36,
                'tax' => 3.36, 'kind' => 'collection', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
        }
        $this->client->recalculate();

        return $inv;
    }

    private function setPrice(float $p): void
    {
        PriceListItem::where('price_list_id', $this->list->id)->update(['price' => $p]);
        PriceList::flushCache();
    }

    private function collected(Invoice $inv): float
    {
        return (float) Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'collection')->sum('credit');
    }

    private function assertBalanceIsLedger(): void
    {
        $c = $this->client->fresh();
        $rows = Transaction::where('client_id', $c->id)->get();
        $this->assertEqualsWithDelta((float) $rows->sum('debit') - (float) $rows->sum('credit'), (float) $c->balance, 0.001);
    }

    public function test_full_resync_moves_the_cash_collection_with_the_new_total(): void
    {
        $inv = $this->invoice('cash');
        $this->setPrice(10); // ٣ × ١٠ × ١٫١٤ = ٣٤٫٢٠

        $r = FullResync::invoice($inv);
        $this->client->recalculate();

        $this->assertEqualsWithDelta(34.20, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(34.20, $this->collected($inv), 0.001, 'تحصيل الكاش = الإجمالي الجديد');
        $this->assertEqualsWithDelta(0.0, (float) $this->client->fresh()->balance, 0.001, 'الفاتورة الكاش مالهاش أثر على الرصيد');
        $this->assertBalanceIsLedger();
        $this->assertNotNull($r);
    }

    public function test_credit_invoice_keeps_the_difference_on_the_balance(): void
    {
        $inv = $this->invoice('credit', 0);
        $this->setPrice(10);

        FullResync::invoice($inv);
        $this->client->recalculate();

        $this->assertEqualsWithDelta(34.20, (float) $this->client->fresh()->balance, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->collected($inv), 0.001);
    }

    public function test_price_change_engine_moves_cash_collection_and_ledger_diff_counts_credit_only(): void
    {
        $cash = $this->invoice('cash');
        $credit = $this->invoice('credit', 0);

        $run = PriceChange::draft($this->admin, 'list', ['price_list_id' => $this->list->id, 'prices' => [$this->product->id => 10]]);
        $result = PriceChange::apply($run, $this->admin);

        $this->assertEqualsWithDelta(34.20, $this->collected($cash), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->collected($credit), 0.001);
        $this->assertEqualsWithDelta(6.84, (float) $result['ledger_diff'], 0.001, 'أثر الرصيد = فرق الآجل بس');
        $this->assertEqualsWithDelta(34.20, (float) $this->client->fresh()->balance, 0.001);
        $this->assertBalanceIsLedger();
    }

    public function test_full_resync_moves_the_cash_refund_of_a_cash_return(): void
    {
        $this->setPrice(10);
        $ret = ClientReturn::create([
            'number' => 'RET-T1', 'client_id' => $this->client->id, 'user_id' => $this->rep->id, 'source' => 'field',
            'policy' => ClientReturn::POLICY_CASH, 'subtotal' => 8, 'discount' => 0, 'total' => 8, 'tax_total' => 1.12, 'grand_total' => 9.12,
            'good_units' => 1, 'damaged_units' => 0, 'created_by' => $this->rep->id,
        ]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $this->product->id, 'qty' => 1, 'condition' => 'good',
            'list_price' => 8, 'price' => 8, 'total' => 8, 'tax_rate' => 0.14, 'tax' => 1.12]);
        $credit = Transaction::create(['client_id' => $this->client->id, 'date' => today(), 'memo' => 'مرتجع', 'debit' => 0, 'credit' => 9.12,
            'tax' => 1.12, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);
        $refund = Transaction::create(['client_id' => $this->client->id, 'date' => today(), 'memo' => 'رد', 'debit' => 9.12, 'credit' => 0,
            'tax' => 1.12, 'kind' => 'refund', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);
        $ret->update(['transaction_id' => $credit->id, 'refund_transaction_id' => $refund->id]);

        FullResync::clientReturn($ret->fresh());
        $this->client->recalculate();

        $this->assertEqualsWithDelta(11.40, (float) $ret->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(11.40, (float) $credit->fresh()->credit, 0.001);
        $this->assertEqualsWithDelta(11.40, (float) $refund->fresh()->debit, 0.001, 'الرد الكاش = إجمالي المرتجع الجديد');
        $this->assertEqualsWithDelta(0.0, (float) $this->client->fresh()->balance, 0.001);
    }

    public function test_repair_command_dry_runs_then_sets_collection_to_the_total_and_rolls_back(): void
    {
        // اللي اتسجّل قبل الإصلاح: الإجمالي اتعاد تسعيره والتحصيل فضل على القديم
        $inv = $this->invoice('cash');
        $inv->update(['total' => 30, 'tax_total' => 4.20, 'grand_total' => 34.20]);
        Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'sale')->update(['debit' => 34.20, 'tax' => 4.20]);
        $this->client->recalculate();
        $this->assertEqualsWithDelta(6.84, (float) $this->client->fresh()->balance, 0.001);

        $this->artisan('prices:repair-collections')->assertSuccessful();
        $this->assertEqualsWithDelta(27.36, $this->collected($inv), 0.001, 'dry-run مابيكتبش');

        $this->artisan('prices:repair-collections', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(34.20, $this->collected($inv), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $this->client->fresh()->balance, 0.001);
        $this->assertBalanceIsLedger();

        $snap = collect(\Illuminate\Support\Facades\Storage::files('price-audit'))->filter(fn ($f) => str_contains($f, 'collections-snapshot-'))->sort()->last();
        $this->artisan('prices:repair-collections', ['--rollback' => $snap, '--apply' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(27.36, $this->collected($inv), 0.001, 'الرجوع بيرجّع المبلغ القديم');
    }

    public function test_price_audit_is_read_only_and_reports_a_wrong_cash_collection(): void
    {
        $inv = $this->invoice('cash');
        Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'collection')->update(['credit' => 20]);
        $this->client->recalculate();
        $csv = tempnam(sys_get_temp_dir(), 'aud');

        $this->artisan('prices:audit', ['--csv' => $csv])->assertSuccessful();

        $body = (string) file_get_contents($csv);
        $this->assertStringContainsString('cash_invoice_collected_equals_total', $body);
        $this->assertStringContainsString($inv->number, $body);
        $this->assertEqualsWithDelta(20.0, $this->collected($inv), 0.001, 'التدقيق قراية بس');
    }
}
