<?php

namespace Tests\Feature\Costing;

use App\Models\Batch;
use App\Models\ClientReturn;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductTransferPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostSync;
use App\Services\Gl\RebuildFailed;
use App\Services\Costing\Valuation;
use App\Services\Gl\Ledger;
use App\Services\Gl\RebuildDiff;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تكلفة البيع والمرتجع (الخط ٩ — المهمة ٣): قيد التكلفة بالطريقتين،
 * ترحيله في الدفتر، وإعادة البناء من غير ما الـids تتغير.
 */
class SaleCostTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    private int $n = 0;

    private function world(bool $costing = true): void
    {
        $this->seed(GlSeeder::class);
        if ($costing) {
            CostingGlSeed::run();
        }
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        Valuation::flush();
        $this->rep = $this->makeRep();
    }

    /** @param list<array{0:Product,1:int,2:float,3?:float}> $lines [product, qty, unit_cost, price] */
    private function sale(array $lines, string $date = '2026-08-10'): array
    {
        $client = $this->makeClient();
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $l[1] * ($l[3] ?? 20);
        }
        $inv = Invoice::create([
            'number' => 'INV-'.(90000 + ++$this->n), 'client_id' => $client->id, 'user_id' => $this->rep->id,
            'payment' => 'credit', 'subtotal' => $total, 'discount' => 0, 'total' => $total, 'tax_total' => 0, 'grand_total' => $total,
        ]);
        $items = [];
        foreach ($lines as $l) {
            $price = $l[3] ?? 20;
            $items[] = InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $l[0]->id, 'qty' => $l[1],
                'list_price' => $price, 'price' => $price, 'unit_cost' => $l[2], 'total' => $l[1] * $price, 'tax_rate' => 0, 'tax' => 0]);
        }
        $tx = Transaction::create(['client_id' => $client->id, 'date' => $date, 'memo' => $inv->number, 'debit' => $total,
            'credit' => 0, 'tax' => 0, 'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);

        return [$inv, $tx, $items, $client];
    }

    private function entryFor(Transaction $tx, string $kind = 'sale'): ?CostEntry
    {
        return CostEntry::where('kind', $kind)->where('origin_type', $tx->getMorphClass())->where('origin_id', $tx->id)->first();
    }

    private function bal(string $key): float
    {
        return GlAccount::findKey($key)->balanceBetween(null, null);
    }

    private function receipt(string $on, ?int $sourceWarehouse = null): GoodsReceipt
    {
        return GoodsReceipt::create(['number' => 'GRN-'.(70000 + ++$this->n), 'warehouse_id' => $this->makeWarehouse()->id,
            'source_warehouse_id' => $sourceWarehouse, 'received_on' => $on, 'status' => 'posted']);
    }

    private function batch(Product $p, int $qty, float $cost, ?GoodsReceipt $gr): Batch
    {
        return Batch::create(['product_id' => $p->id, 'warehouse_id' => $gr?->warehouse_id ?? $this->makeWarehouse()->id,
            'goods_receipt_id' => $gr?->id, 'batch_no' => 'B-'.++$this->n, 'expires_on' => '2027-06-01',
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => $cost]);
    }

    public function test_invoice_sale_posts_cogs_at_batch_cost(): void
    {
        $this->world();
        [, $tx] = $this->sale([[$this->makeProduct(), 3, 10], [$this->makeProduct(), 5, 4]]);

        $e = $this->entryFor($tx);
        $this->assertNotNull($e);
        $this->assertSame(50.0, $e->amount('batch'));
        $this->assertCount(2, $e->lines);
        $this->assertSame(160.0, (float) $e->revenue);

        $gl = app(Ledger::class)->autoEntryFor($e);
        $this->assertNotNull($gl, 'قيد التكلفة اترحّل');
        $this->assertSame('cost.sale', $gl->rule_key);
        $maadi = CostCenter::maadi()->id;
        $cogs = $gl->lines->firstWhere('account_id', GlAccount::findKey('cogs')->id);
        $inv = $gl->lines->firstWhere('account_id', GlAccount::findKey('inventory')->id);
        $this->assertSame(50.0, (float) $cogs->debit);
        $this->assertSame(50.0, (float) $inv->credit);
        $this->assertSame($maadi, $cogs->cost_center_id);
        $this->assertSame($maadi, $inv->cost_center_id);
    }

    public function test_weighted_average_amount_is_stored_and_used_when_official(): void
    {
        $this->world();
        $p = $this->makeProduct(['cost' => 5]);
        $gr = $this->receipt('2026-08-02');
        $this->batch($p, 10, 8, $gr);
        $this->batch($p, 30, 12, $gr);
        [, $tx] = $this->sale([[$p, 4, 8]]);

        $e = $this->entryFor($tx);
        $this->assertSame(32.0, $e->amount('batch'));
        $this->assertSame(44.0, $e->amount('wavg'));
        $this->assertSame(11.0, (float) $e->lines->first()->unit_wavg);
        $this->assertSame(32.0, $this->bal('cogs'), 'الباتش هو الرسمي افتراضياً');

        Setting::write('cost_method', 'wavg');
        Setting::flushCache();
        app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'));

        $this->assertSame(44.0, $this->bal('cogs'));
        $this->assertSame(-44.0, $this->bal('inventory'));
    }

    public function test_transfer_twin_batches_do_not_count_in_the_average(): void
    {
        $this->world();
        $p = $this->makeProduct(['cost' => 5]);
        $gr = $this->receipt('2026-08-02');
        $this->batch($p, 10, 8, $gr);
        $this->batch($p, 30, 12, $gr);
        // توأم تحويل (إذن باستلام من مخزن تاني) — مايتعدّش
        $this->batch($p, 50, 100, $this->receipt('2026-08-03', $this->makeWarehouse()->id));
        // باتش بعد التاريخ — مايتعدّش
        $this->batch($p, 50, 100, $this->receipt('2026-08-20'));
        // باتش من غير تكلفة — مايتعدّش
        $this->batch($p, 50, 0, $gr);

        $this->assertSame(11.0, Valuation::wavg($p, Carbon::parse('2026-08-10')));
        Valuation::flush();
        $this->assertSame(5.0, Valuation::wavg($p->id, Carbon::parse('2026-08-01')), 'مفيش باتشات قبل التاريخ = تكلفة الصنف');
    }

    public function test_po_delivery_partial_uses_delivered_qty_and_product_cost(): void
    {
        $this->world();
        $a = $this->makeProduct(['cost' => 7]);
        $b = $this->makeProduct(['cost' => 3]);
        $client = $this->makeClient();
        $po = PurchaseOrder::create(['number' => 'PO-90001', 'client_id' => $client->id, 'status' => 'delivered',
            'assigned_to' => $this->rep->id, 'total' => 275, 'tax_total' => 0, 'grand_total' => 275]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $a->id, 'qty' => 10, 'delivered_qty' => 6,
            'price' => 20, 'total' => 200, 'list_price' => 20, 'discount_pct' => 0, 'tax_rate' => 0, 'tax' => 0]);
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $b->id, 'qty' => 5, 'delivered_qty' => 5,
            'price' => 15, 'total' => 75, 'list_price' => 15, 'discount_pct' => 0, 'tax_rate' => 0, 'tax' => 0]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => '2026-08-12', 'memo' => 'PO', 'debit' => 195, 'credit' => 0,
            'tax' => 0, 'kind' => 'sale', 'source_type' => PurchaseOrder::class, 'source_id' => $po->id]);

        $e = $this->entryFor($tx);
        $this->assertSame(57.0, $e->amount('batch'));   // 6×7 + 5×3
        $this->assertSame(11.0, (float) $e->qty);
        $this->assertSame(195.0, (float) $e->revenue); // 6×20 + 75
        $this->assertSame(57.0, $this->bal('cogs'));
    }

    public function test_sound_return_reverses_cost_damaged_does_not(): void
    {
        $this->world();
        $p = $this->makeProduct(['cost' => 6]);
        [$inv, , $items, $client] = $this->sale([[$p, 10, 10]]);
        $this->assertSame(100.0, $this->bal('cogs'));

        $ret = ClientReturn::create(['number' => 'RET-90001', 'client_id' => $client->id, 'source' => 'erp', 'policy' => 'account',
            'subtotal' => 100, 'discount' => 0, 'total' => 100, 'tax_total' => 0, 'grand_total' => 100]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $p->id, 'invoice_id' => $inv->id, 'invoice_item_id' => $items[0]->id,
            'qty' => 2, 'condition' => 'good', 'list_price' => 20, 'price' => 20, 'total' => 40, 'tax_rate' => 0, 'tax' => 0]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $p->id, 'invoice_id' => null, 'invoice_item_id' => null,
            'qty' => 1, 'condition' => 'good', 'list_price' => 20, 'price' => 20, 'total' => 20, 'tax_rate' => 0, 'tax' => 0]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $p->id, 'invoice_id' => $inv->id, 'invoice_item_id' => $items[0]->id,
            'qty' => 3, 'condition' => 'damaged', 'list_price' => 20, 'price' => 20, 'total' => 60, 'tax_rate' => 0, 'tax' => 0]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => '2026-08-15', 'memo' => 'RET', 'debit' => 0, 'credit' => 120,
            'tax' => 0, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);

        $e = $this->entryFor($tx, 'return');
        $this->assertNotNull($e);
        $this->assertSame(26.0, $e->amount('batch'));   // 2×10 (البند الأصلي) + 1×6 (تكلفة الصنف)
        $this->assertSame(-120.0, (float) $e->revenue, 'إيراد المرتجع كامل (التالف كمان)');
        $this->assertCount(3, $e->lines);
        $damaged = $e->lines->firstWhere('revenue', '-60.00');
        $this->assertSame(0.0, (float) $damaged->amount_batch, 'التالف مالوش عكس تكلفة');
        $this->assertSame(0.0, (float) $damaged->amount_wavg);
        $this->assertNull($damaged->amount_transfer);
        $this->assertFalse($e->hasFlag('zero_cost'));
        $this->assertSame(74.0, $this->bal('cogs'));
        $this->assertSame(-74.0, $this->bal('inventory'));

        // مرتجع كله تالف = مفيش قيد تكلفة
        $ret2 = ClientReturn::create(['number' => 'RET-90002', 'client_id' => $client->id, 'source' => 'erp', 'policy' => 'account',
            'subtotal' => 20, 'discount' => 0, 'total' => 20, 'tax_total' => 0, 'grand_total' => 20]);
        ReturnItem::create(['return_id' => $ret2->id, 'product_id' => $p->id, 'invoice_id' => $inv->id, 'invoice_item_id' => $items[0]->id,
            'qty' => 1, 'condition' => 'damaged', 'list_price' => 20, 'price' => 20, 'total' => 20, 'tax_rate' => 0, 'tax' => 0]);
        $tx2 = Transaction::create(['client_id' => $client->id, 'date' => '2026-08-16', 'memo' => 'RET2', 'debit' => 0, 'credit' => 20,
            'tax' => 0, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret2->id]);
        $e2 = $this->entryFor($tx2, 'return');
        $this->assertNotNull($e2, 'مرتجع كله تالف = قيد بالإيراد ومبالغ صفر');
        $this->assertSame(-20.0, (float) $e2->revenue);
        $this->assertSame(0.0, $e2->amount('batch'));
        $this->assertNull(app(Ledger::class)->autoEntryFor($e2), 'مفيش سطور دفتر');
        $this->assertSame(74.0, $this->bal('cogs'));

        // وإعادة البناء بتقبل غيابه (مالوش قيمة)
        $this->assertTrue(app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'))->ok);
    }

    public function test_sale_with_transfer_price_posts_markup_pair(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => 15, 'unit_cost' => 10, 'markup_pct' => 50,
            'effective_from' => '2026-08-01', 'approved_by' => $this->makeAdmin()->id, 'approved_at' => now()]);
        [, $tx] = $this->sale([[$p, 2, 10], [$this->makeProduct(['kind' => 'finished']), 1, 4]]);

        $e = $this->entryFor($tx);
        $this->assertSame(30.0, (float) $e->amount_transfer, 'مجموع سطور سعر التحويل بس');
        $this->assertSame(15.0, (float) $e->lines->firstWhere('product_id', $p->id)->unit_transfer);

        $gl = app(Ledger::class)->autoEntryFor($e);
        $cogs = GlAccount::findKey('cogs')->id;
        $elim = $gl->lines->firstWhere('account_id', GlAccount::findKey('internal_elim')->id);
        $this->assertSame(34.0, round((float) $gl->lines->where('account_id', $cogs)->sum('debit'), 2)); // 24 + 10
        $this->assertSame(24.0, (float) $gl->lines->firstWhere('account_id', GlAccount::findKey('inventory')->id)->credit);
        $this->assertSame(10.0, (float) $elim->credit);
        $this->assertSame(CostCenter::company()->id, $elim->cost_center_id);
        $this->assertSame('mk_cr', $elim->slot);
    }

    public function test_zero_cost_line_is_flagged(): void
    {
        $this->world();
        [, $tx] = $this->sale([[$this->makeProduct(['cost' => 0]), 2, 0], [$this->makeProduct(), 1, 10]]);

        $e = $this->entryFor($tx);
        $this->assertTrue($e->hasFlag('zero_cost'));
        $this->assertTrue((bool) $e->lines->firstWhere('amount_batch', '0.00')->zero_cost);
        $this->assertFalse($e->hasFlag('no_transfer_price'));
        $this->assertSame(10.0, $this->bal('cogs'));
    }

    public function test_rebuild_twice_with_cost_entries_gives_empty_diff_and_keeps_ids(): void
    {
        $this->world();
        $admin = $this->makeAdmin();
        $p = $this->makeProduct(['cost' => 6]);
        [$inv, , $items, $client] = $this->sale([[$p, 10, 10]]);
        $this->sale([[$this->makeProduct(), 4, 3]], '2026-09-03');
        $ret = ClientReturn::create(['number' => 'RET-90003', 'client_id' => $client->id, 'source' => 'erp', 'policy' => 'account',
            'subtotal' => 20, 'discount' => 0, 'total' => 20, 'tax_total' => 0, 'grand_total' => 20]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $p->id, 'invoice_id' => $inv->id, 'invoice_item_id' => $items[0]->id,
            'qty' => 1, 'condition' => 'good', 'list_price' => 20, 'price' => 20, 'total' => 20, 'tax_rate' => 0, 'tax' => 0]);
        Transaction::create(['client_id' => $client->id, 'date' => '2026-08-20', 'memo' => 'RET', 'debit' => 0, 'credit' => 20,
            'tax' => 0, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);
        $ids = CostEntry::orderBy('id')->pluck('id')->all();
        $this->assertCount(3, $ids);

        $svc = app(RebuildDiff::class);
        $run = function () use ($svc, $admin) {
            $p = $svc->preview($admin);
            $this->assertSame('ok', $p->status, (string) $p->error);
            $svc->approve($p, $admin);
            $x = $svc->execute($p->fresh(), $admin);
            $this->assertSame('ok', $x->status, (string) $x->error);

            return $x;
        };
        $run();
        $second = $run();

        $this->assertSame(0, $second->summary['created']);
        $this->assertSame(0, $second->summary['deleted']);
        $this->assertSame(0, $second->summary['changed']);
        $this->assertSame($ids, CostEntry::orderBy('id')->pluck('id')->all());
        $this->assertSame(3, GlEntry::where('source_type', (new CostEntry)->getMorphClass())->count());
        $this->assertSame(102.0, $this->bal('cogs')); // 100 + 12 − 10
    }

    public function test_rebuild_drops_a_cost_entry_whose_origin_is_no_longer_costable(): void
    {
        $this->world();
        [$inv, $tx] = $this->sale([[$this->makeProduct(), 3, 10]]);
        $this->assertNotNull($this->entryFor($tx));
        // بنود اتشالت من ورا الموديل — القيد مابقاش له تكلفة
        InvoiceItem::where('invoice_id', $inv->id)->delete();

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'));

        $this->assertTrue($report->ok);
        $this->assertNull($this->entryFor($tx));
        $this->assertSame(0.0, $this->bal('cogs'));
    }

    public function test_inactive_cost_rules_make_the_rebuild_report_missing_entries(): void
    {
        $this->world();
        $this->sale([[$this->makeProduct(), 3, 10]]);
        GlPostingRule::whereIn('key', ['cost.sale', 'cost.markup'])->update(['active' => false]);
        GlPostingRule::flush();

        $dry = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), dryRun: true);
        $this->assertFalse($dry->invariants['entries']['ok'], 'قاعدة موقوفة = قيد ضايع مش غياب مقصود');
        $this->assertSame(1, $dry->invariants['entries']['missing']);

        $this->expectException(RebuildFailed::class);
        app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'));
    }

    public function test_return_is_valued_at_the_original_sale_and_nets_to_zero(): void
    {
        $this->world();
        Setting::write('cost_method', 'wavg');
        Setting::flushCache();
        $admin = $this->makeAdmin();
        $p = $this->makeProduct(['kind' => 'finished', 'cost' => 5]);
        $this->batch($p, 10, 8, $this->receipt('2026-08-02'));
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => 15, 'unit_cost' => 8, 'effective_from' => '2026-08-01',
            'approved_by' => $admin->id, 'approved_at' => now()]);
        [$inv, , $items, $client] = $this->sale([[$p, 2, 8]]);

        // بعد البيعة: متوسط جديد وسعر تحويل جديد
        $this->batch($p, 10, 20, $this->receipt('2026-08-12'));
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => 18, 'unit_cost' => 14, 'effective_from' => '2026-08-12',
            'approved_by' => $admin->id, 'approved_at' => now()]);

        $ret = ClientReturn::create(['number' => 'RET-90010', 'client_id' => $client->id, 'source' => 'erp', 'policy' => 'account',
            'subtotal' => 40, 'discount' => 0, 'total' => 40, 'tax_total' => 0, 'grand_total' => 40]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $p->id, 'invoice_id' => $inv->id, 'invoice_item_id' => $items[0]->id,
            'qty' => 2, 'condition' => 'good', 'list_price' => 20, 'price' => 20, 'total' => 40, 'tax_rate' => 0, 'tax' => 0]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => '2026-08-15', 'memo' => 'RET', 'debit' => 0, 'credit' => 40,
            'tax' => 0, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);

        $line = $this->entryFor($tx, 'return')->lines->first();
        $this->assertSame(8.0, (float) $line->unit_wavg);
        $this->assertSame(15.0, (float) $line->unit_transfer);
        $this->assertSame(0.0, $this->bal('cogs'));
        $this->assertSame(0.0, $this->bal('internal_elim'));
        $this->assertSame(0.0, $this->bal('inventory'));
    }

    public function test_supplier_return_credits_inventory_with_the_stock_rule(): void
    {
        $this->world();
        $sup = Supplier::create(['code' => 'SUP-L9R', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $sup->post('invoice', '2026-08-10', 0, 500, 'فاتورة مورد');
        $st = $sup->post('return', '2026-08-11', 300, 0, 'مرتجع لمورد');

        $this->assertSame('sup.return.stock', app(Ledger::class)->autoEntryFor($st)->rule_key);
        $this->assertSame(200.0, $this->bal('inventory'));
        $this->assertSame(200.0, $this->bal('payables'));
    }

    public function test_cost_entry_in_a_closed_period_is_frozen(): void
    {
        $this->world();
        [$inv, $tx] = $this->sale([[$this->makeProduct(), 3, 10]]);
        $e = $this->entryFor($tx);
        GlPeriod::close('2026-08', $this->makeAdmin());

        InvoiceItem::where('invoice_id', $inv->id)->delete();
        CostSync::run(Carbon::parse('2026-08-01'), true);
        $this->assertNotNull(CostEntry::find($e->id), 'شهر مقفول = مابيتمسحش');
        $this->assertSame(30.0, CostEntry::find($e->id)->amount('batch'), 'ولا بيتعدّل');

        $tx->delete();
        $this->assertNotNull(CostEntry::find($e->id));
        $this->assertNotNull(app(Ledger::class)->autoEntryFor($e));
    }

    public function test_deleting_a_sale_removes_its_cost_entry_and_gl_entry(): void
    {
        $this->world();
        [, $tx] = $this->sale([[$this->makeProduct(), 3, 10]]);
        $this->assertSame(1, GlEntry::where('source_type', (new CostEntry)->getMorphClass())->count());

        $tx->delete();

        $this->assertSame(0, CostEntry::count());
        $this->assertSame(0, GlEntry::where('source_type', (new CostEntry)->getMorphClass())->count());
        $this->assertSame(0.0, $this->bal('cogs'));
    }

    public function test_deleting_a_sale_while_the_gl_is_off_keeps_the_gl_entry(): void
    {
        $this->world();
        [, $tx] = $this->sale([[$this->makeProduct(), 3, 10]]);
        Setting::write('gl_enabled', '0');
        Setting::flushCache();

        $tx->delete();

        $this->assertSame(0, CostEntry::count());
        $this->assertSame(1, GlEntry::where('source_type', (new CostEntry)->getMorphClass())->count(), 'السويتش مقفول = الدفتر مايتلمسش');
    }

    public function test_return_of_a_transfer_priced_product_flips_the_markup_pair(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => 15, 'unit_cost' => 10, 'effective_from' => '2026-08-01',
            'approved_by' => $this->makeAdmin()->id, 'approved_at' => now()]);
        [$inv, , $items, $client] = $this->sale([[$p, 2, 10]]);
        $ret = ClientReturn::create(['number' => 'RET-90011', 'client_id' => $client->id, 'source' => 'erp', 'policy' => 'account',
            'subtotal' => 20, 'discount' => 0, 'total' => 20, 'tax_total' => 0, 'grand_total' => 20]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $p->id, 'invoice_id' => $inv->id, 'invoice_item_id' => $items[0]->id,
            'qty' => 1, 'condition' => 'good', 'list_price' => 20, 'price' => 20, 'total' => 20, 'tax_rate' => 0, 'tax' => 0]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => '2026-08-15', 'memo' => 'RET', 'debit' => 0, 'credit' => 20,
            'tax' => 0, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);

        $gl = app(Ledger::class)->autoEntryFor($this->entryFor($tx, 'return'));
        $elim = $gl->lines->firstWhere('slot', 'mk_cr');
        $this->assertSame(5.0, (float) $elim->debit);
        $this->assertSame(5.0, (float) $gl->lines->firstWhere('slot', 'mk_dr')->credit);
        $this->assertSame(10.0, (float) $gl->lines->firstWhere('slot', 'dr')->credit);
        $this->assertSame(15.0, $this->bal('cogs'));          // 30 − 15
        $this->assertSame(-5.0, $this->bal('internal_elim')); // دائن 10 − مدين 5
    }

    public function test_transfer_price_below_cost_flips_the_markup_pair(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => 8, 'unit_cost' => 10, 'effective_from' => '2026-08-01',
            'approved_by' => $this->makeAdmin()->id, 'approved_at' => now()]);
        [, $tx] = $this->sale([[$p, 2, 10]]);

        $gl = app(Ledger::class)->autoEntryFor($this->entryFor($tx));
        $this->assertSame(4.0, (float) $gl->lines->firstWhere('slot', 'mk_dr')->credit);
        $this->assertSame(4.0, (float) $gl->lines->firstWhere('slot', 'mk_cr')->debit);
        $this->assertSame(16.0, $this->bal('cogs'));
        $this->assertSame(4.0, $this->bal('internal_elim'));
    }

    public function test_model_update_of_the_sale_resyncs_through_the_observer(): void
    {
        $this->world();
        [, $tx, $items] = $this->sale([[$this->makeProduct(), 3, 10]]);
        $id = $this->entryFor($tx)->id;

        InvoiceItem::whereKey($items[0]->id)->update(['qty' => 5, 'total' => 100]);
        $tx->update(['debit' => 100]);

        $e = $this->entryFor($tx);
        $this->assertSame($id, $e->id);
        $this->assertSame(50.0, $e->amount('batch'));
        $this->assertSame(50.0, $this->bal('cogs'));
        $this->assertSame(1, GlEntry::where('source_type', (new CostEntry)->getMorphClass())->count());
    }

    public function test_admin_invoice_repost_resyncs_the_cost_entry(): void
    {
        $this->world();
        [, $tx, $items] = $this->sale([[$this->makeProduct(), 3, 10]]);
        $id = $this->entryFor($tx)->id;

        // أداة تعديل البنود بتكتب من ورا الموديل وبعدين بتنادي repost
        InvoiceItem::whereKey($items[0]->id)->update(['qty' => 5, 'total' => 100]);
        app(Ledger::class)->repost($tx->fresh());

        $e = $this->entryFor($tx);
        $this->assertSame($id, $e->id);
        $this->assertSame(50.0, $e->amount('batch'));
        $this->assertSame(50.0, $this->bal('cogs'));
    }

    public function test_without_costing_seed_nothing_posts_and_supplier_invoice_stays_expense(): void
    {
        $this->world(costing: false);
        [, $tx] = $this->sale([[$this->makeProduct(), 3, 10]]);

        $e = $this->entryFor($tx);
        $this->assertNotNull($e, 'طبقة التكلفة شغالة حتى من غير الدفتر');
        $this->assertNull(app(Ledger::class)->autoEntryFor($e));
        $this->assertSame(0, GlEntry::where('source_type', (new CostEntry)->getMorphClass())->count());

        $sup = Supplier::create(['code' => 'SUP-L9', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $st = $sup->post('invoice', '2026-08-10', 0, 500, 'فاتورة مورد');
        $this->assertSame('sup.invoice', app(Ledger::class)->autoEntryFor($st)->rule_key);
        $this->assertSame(500.0, $this->bal('expense_purchases'));
    }

    public function test_rebuild_without_costing_seed_leaves_the_cost_layer_alone(): void
    {
        $this->world(costing: false);
        [, $kept] = $this->sale([[$this->makeProduct(), 3, 10]]);
        [, $gone] = $this->sale([[$this->makeProduct(), 2, 10]]);
        $keptEntry = $this->entryFor($kept);
        // تغيير من ورا الطبقة: قيد اتمسح وقيد اتعدّل — المزامنة كانت هترجّعهم
        $goneEntry = $this->entryFor($gone);
        \Illuminate\Support\Facades\DB::table('cost_lines')->where('cost_entry_id', $goneEntry->id)->delete();
        \Illuminate\Support\Facades\DB::table('cost_entries')->where('id', $goneEntry->id)->delete();
        \Illuminate\Support\Facades\DB::table('cost_entries')->where('id', $keptEntry->id)
            ->update(['amount_batch' => 999, 'updated_at' => '2026-08-11 09:00:00']);

        $this->assertTrue(app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'))->ok);

        $this->assertSame(1, CostEntry::count(), 'مفيش seed = إعادة البناء ماتزامنش الطبقة');
        $row = CostEntry::find($keptEntry->id);
        $this->assertSame(999.0, (float) $row->amount_batch);
        $this->assertSame('2026-08-11 09:00:00', $row->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, GlEntry::where('source_type', (new CostEntry)->getMorphClass())->count());
    }

    public function test_supplier_invoice_debits_inventory_with_the_stock_rule(): void
    {
        $this->world();
        $sup = Supplier::create(['code' => 'SUP-L9', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $st = $sup->post('invoice', '2026-08-10', 0, 500, 'فاتورة مورد');

        $this->assertSame('sup.invoice.stock', app(Ledger::class)->autoEntryFor($st)->rule_key);
        $this->assertSame(500.0, $this->bal('inventory'));
        $this->assertSame(0.0, $this->bal('expense_purchases'));
        $this->assertSame(500.0, $this->bal('payables'));
    }

    public function test_taxed_supplier_invoice_keeps_input_vat_out_of_inventory(): void
    {
        $this->world();
        $sup = Supplier::create(['code' => 'SUP-L9T', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $inv = SupplierInvoice::record(['supplier_id' => $sup->id, 'invoice_date' => '2026-08-10',
            'subtotal' => 1000, 'tax' => 140, 'total' => 1140, 'created_by' => $this->makeAdmin()->id]);
        $st = SupplierTransaction::where('source_type', $inv->getMorphClass())->where('source_id', $inv->id)->firstOrFail();

        $gl = app(Ledger::class)->autoEntryFor($st);
        $this->assertSame('sup.invoice.stock', $gl->rule_key);
        $this->assertFalse((bool) $gl->needs_review);
        $this->assertSame(1000.0, $this->bal('inventory'));
        $this->assertSame(140.0, $this->bal('expense_purchases'), 'الضريبة على حساب المسار القديم');
        $this->assertSame(1140.0, $this->bal('payables'));

        // من غير ضريبة = زي الأول: الكل مخزون
        $plain = SupplierInvoice::record(['supplier_id' => $sup->id, 'invoice_date' => '2026-08-11',
            'subtotal' => 500, 'tax' => 0, 'total' => 500, 'created_by' => $this->makeAdmin()->id]);
        $this->assertCount(2, app(Ledger::class)->autoEntryFor(
            SupplierTransaction::where('source_type', $plain->getMorphClass())->where('source_id', $plain->id)->firstOrFail())->lines);
        $this->assertSame(1500.0, $this->bal('inventory'));
        $this->assertSame(140.0, $this->bal('expense_purchases'));
        $this->assertSame(1640.0, $this->bal('payables'));
    }

    public function test_taxed_supplier_invoice_without_costing_seed_is_all_expense(): void
    {
        $this->world(costing: false);
        $sup = Supplier::create(['code' => 'SUP-L9U', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        SupplierInvoice::record(['supplier_id' => $sup->id, 'invoice_date' => '2026-08-10',
            'subtotal' => 1000, 'tax' => 140, 'total' => 1140, 'created_by' => $this->makeAdmin()->id]);

        $this->assertSame(1140.0, $this->bal('expense_purchases'));
        $this->assertSame(0.0, $this->bal('inventory'));
        $this->assertSame(1140.0, $this->bal('payables'));
    }

    public function test_costing_sync_command_dry_run_writes_nothing(): void
    {
        $this->world();
        $this->sale([[$this->makeProduct(), 3, 10]]);
        CostEntry::query()->delete();

        $this->artisan('promax:costing-sync', ['--from' => '2026-08-01', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, CostEntry::count());

        $this->artisan('promax:costing-sync', ['--from' => '2026-08-01'])->assertSuccessful();
        $e = CostEntry::first();
        $this->assertNotNull($e);
        $this->assertNotNull(app(Ledger::class)->autoEntryFor($e), 'التشغيل الحقيقي بيرحّل');
    }
}
