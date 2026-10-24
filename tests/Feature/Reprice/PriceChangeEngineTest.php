<?php

namespace Tests\Feature\Reprice;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\ClientReturn;
use App\Models\Gl\GlPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceChangeDoc;
use App\Models\PriceChangeRun;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReturnItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Pricing;
use App\Services\Reprice\PriceChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * محرك تعديل الأسعار (Lane 13) — مسودة / معاينة بالرجوع / تطبيق ذرّي
 * ═══════════════════════════════════════════════════════════════
 *
 * المشهد: صنف P، قايمة A سعره فيها 10، وقايمة B سعره فيها 15،
 * وضريبة 14%. الفاتورة 3 قطع — يعني على A: 30 + 4.20 = 34.20.
 *
 * ⚠️ بعد أي عملية بنفحص إن رصيد كل عميل = Σمدين − Σدائن بالظبط.
 */
class PriceChangeEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Product $product;

    private PriceList $listA;

    private PriceList $listB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableTax(14);
        $this->admin = $this->makeAdmin();
        $this->rep = $this->makeRep();
        $this->product = $this->makeProduct(['cost' => 5]);

        $this->listA = PriceList::create(['code' => 'la', 'name' => 'قايمة أ', 'name_en' => 'List A', 'active' => true, 'is_default' => false]);
        $this->listB = PriceList::create(['code' => 'lb', 'name' => 'قايمة ب', 'name_en' => 'List B', 'active' => true, 'is_default' => false]);

        PriceListItem::create(['price_list_id' => $this->listA->id, 'product_id' => $this->product->id, 'price' => 10]);
        PriceListItem::create(['price_list_id' => $this->listB->id, 'product_id' => $this->product->id, 'price' => 15]);
    }

    // ═══════════════ بنّائين ═══════════════

    private function client(PriceList $list, array $attrs = []): Client
    {
        return $this->makeClient(array_merge([
            'price_list_id' => $list->id,
            'taxable' => true,
            'payment_terms' => 'credit',
        ], $attrs));
    }

    private function group(): ClientGroup
    {
        $channel = $this->makeChannel();

        return ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة التيست', 'name_en' => 'Test chain',
            'channel_id' => $channel->id, 'active' => true,
        ]);
    }

    /** فاتورة 3 قطع بسعر معيّن + قيودها (بيع، وتحصيل لو كاش) */
    private function invoice(Client $client, float $price, string $payment = 'credit', array $attrs = [], ?Product $product = null): Invoice
    {
        $product ??= $this->product;
        $net = round($price * 3, 2);
        $tax = round($net * 0.14, 2);
        $grand = round($net + $tax, 2);

        $invoice = Invoice::create(array_merge([
            'number' => 'INV-'.random_int(100000, 999999),
            'client_id' => $client->id,
            'user_id' => $this->rep->id,
            'payment' => $payment,
            'price_list' => 'new',
            'subtotal' => $net,
            'discount_pct' => 0,
            'discount' => 0,
            'total' => $net,
            'tax_total' => $tax,
            'grand_total' => $grand,
            'eta_status' => 'ready',
            'cost_total' => 15,
        ], $attrs));

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'qty' => 3,
            'list_price' => $price,
            'price' => $price,
            'unit_cost' => 5,
            'total' => $net,
            'tax_rate' => 0.14,
            'tax' => $tax,
        ]);

        Transaction::create([
            'client_id' => $client->id, 'date' => today(), 'memo' => 'بيع',
            'debit' => $grand, 'credit' => 0, 'tax' => $tax, 'kind' => 'sale',
            'source_type' => Invoice::class, 'source_id' => $invoice->id,
        ]);

        if ($payment === 'cash') {
            Transaction::create([
                'client_id' => $client->id, 'date' => today(), 'memo' => 'تحصيل',
                'debit' => 0, 'credit' => $grand, 'tax' => $tax, 'kind' => 'collection',
                'source_type' => Invoice::class, 'source_id' => $invoice->id,
            ]);
        }

        $client->recalculate();

        return $invoice;
    }

    private function po(Client $client, float $price, string $status = 'pending', array $attrs = []): PurchaseOrder
    {
        $net = round($price * 3, 2);
        $tax = round($net * 0.14, 2);

        $po = PurchaseOrder::create(array_merge([
            'number' => 'PO-'.random_int(100000, 999999),
            'client_id' => $client->id,
            'status' => $status,
            'price_mode' => 'la',
            'total' => $net,
            'tax_total' => $tax,
            'grand_total' => round($net + $tax, 2),
        ], $attrs));

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'qty' => 3,
            'price' => $price,
            'list_price' => $price,
            'discount_pct' => 0,
            'total' => $net,
            'tax_rate' => 0.14,
            'tax' => $tax,
        ]);

        return $po;
    }

    private function listDraft(float $newPrice, ?User $by = null): PriceChangeRun
    {
        return PriceChange::draft($by ?? $this->admin, 'list', [
            'price_list_id' => $this->listA->id,
            'prices' => [$this->product->id => $newPrice],
        ]);
    }

    private function priceIn(PriceList $list): float
    {
        return (float) PriceListItem::where('price_list_id', $list->id)
            ->where('product_id', $this->product->id)->value('price');
    }

    private function assertLedgerReconciles(Client ...$clients): void
    {
        foreach ($clients as $client) {
            $c = $client->fresh();
            $rows = Transaction::where('client_id', $c->id)->get();

            $this->assertEqualsWithDelta(
                (float) $rows->sum('debit') - (float) $rows->sum('credit'),
                (float) $c->balance,
                0.001,
                'الرصيد لازم يساوي Σمدين − Σدائن بالظبط',
            );
        }
    }

    /** لقطة من كل الجداول اللي المعاينة ممكن تلمسها */
    private function snapshot(): array
    {
        $out = [];
        foreach (['price_list_items', 'invoices', 'invoice_items', 'transactions', 'purchase_orders', 'purchase_order_items', 'products', 'gl_entries', 'gl_lines', 'activity_logs'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $out['clients'] = DB::table('clients')->orderBy('id')->get(['id', 'balance', 'price_list_id', 'price_list'])->map(fn ($r) => (array) $r)->all();

        return $out;
    }

    private function assertRejected(string $message, callable $fn): void
    {
        try {
            $fn();
            $this->fail('كان المفروض يترفض: '.$message);
        } catch (Rejected $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    // ═══════════════ التيستات ═══════════════

    public function test_list_trigger_reprices_every_client_on_the_list(): void
    {
        $x = $this->client($this->listA);
        $y = $this->client($this->listA);
        $z = $this->client($this->listB);
        $ix = $this->invoice($x, 10);
        $iy = $this->invoice($y, 10);
        $iz = $this->invoice($z, 15);

        $run = $this->listDraft(12);
        $this->assertSame('draft', $run->status);
        $this->assertEqualsWithDelta(10.0, $this->priceIn($this->listA), 0.001, 'المسودة ماتغيّرش السعر');

        $result = PriceChange::apply($run, $this->admin);

        $this->assertEqualsWithDelta(12.0, $this->priceIn($this->listA), 0.001);
        foreach ([$ix, $iy] as $inv) {
            $fresh = $inv->fresh();
            $this->assertEqualsWithDelta(41.04, (float) $fresh->grand_total, 0.001);
            $this->assertSame($run->id, (int) $fresh->price_change_run_id);
            $this->assertSame($this->admin->id, (int) $fresh->repriced_by);
            $this->assertNotNull($fresh->repriced_at);
        }
        $this->assertEqualsWithDelta(51.30, (float) $iz->fresh()->grand_total, 0.001);
        $this->assertNull($iz->fresh()->price_change_run_id);

        $run->refresh();
        $this->assertSame('applied', $run->status);
        $this->assertNotNull($run->applied_at);
        $this->assertSame(2, (int) $run->invoices_changed);
        $this->assertSame(2, (int) $run->clients_count);
        $this->assertSame(2, $result['invoices_changed']);
        $this->assertSame(2, $run->docs()->where('outcome', 'changed')->count());
        $this->assertEqualsWithDelta(2 * 6.84, $result['ledger_diff'], 0.001);
        $this->assertEquals([[
            'product_id' => $this->product->id,
            'name' => $this->product->displayName(),
            'old' => 10.0,
            'new' => 12.0,
        ]], $run->item_changes);

        $this->assertEqualsWithDelta(41.04, (float) $x->fresh()->balance, 0.001);
        $this->assertLedgerReconciles($x, $y, $z);
    }

    public function test_preview_totals_equal_apply_totals(): void
    {
        $x = $this->client($this->listA);
        $this->invoice($x, 10);
        $this->invoice($x, 10, 'cash');
        $this->po($x, 10);

        $run = $this->listDraft(12);
        $preview = $run->fresh()->preview;

        $result = PriceChange::apply($run, $this->admin);

        foreach (['before_net', 'before_tax', 'before_grand', 'after_net', 'after_tax', 'after_grand', 'diff_net', 'diff_tax', 'diff_grand'] as $k) {
            $this->assertEqualsWithDelta($preview['totals']['all'][$k], $result['totals']['all'][$k], 0.001, $k);
        }

        $run->refresh();
        $this->assertEqualsWithDelta(3 * 34.20, (float) $run->before_grand, 0.001);
        $this->assertEqualsWithDelta(3 * 41.04, (float) $run->after_grand, 0.001);
        $this->assertEqualsWithDelta($result['totals']['all']['after_grand'], (float) $run->after_grand, 0.001);
        $this->assertSame(2, $result['invoices_changed']);
        $this->assertSame(1, $result['pos_changed']);
        $this->assertEqualsWithDelta(2 * 6.84, $result['ledger_diff'], 0.001);
        $this->assertEqualsWithDelta(6.84, $result['totals']['po']['diff_grand'], 0.001);
    }

    public function test_preview_writes_nothing(): void
    {
        $x = $this->client($this->listA);
        $this->invoice($x, 10);
        $this->invoice($x, 10, 'cash');
        $this->po($x, 10);

        $before = $this->snapshot();

        $run = $this->listDraft(12);
        PriceChange::refreshPreview($run);

        $this->assertSame($before, $this->snapshot(), 'المعاينة لازم ماتسيبش أي أثر');
        $this->assertEqualsWithDelta(10.0, (float) Pricing::priceForClient($x->fresh(), $this->product->fresh()), 0.001);
        $this->assertSame(0, PriceChangeDoc::count());

        $run->refresh();
        $this->assertSame('draft', $run->status);
        $this->assertSame(2, (int) $run->invoices_changed);
        $this->assertSame(1, (int) $run->pos_changed);
        $this->assertEqualsWithDelta(3 * 41.04, (float) $run->after_grand, 0.001);
        $this->assertNull(Invoice::whereNotNull('price_change_run_id')->first());
    }

    public function test_chain_trigger_moves_all_branches_and_reprices(): void
    {
        $g = $this->group();
        $a = $this->client($this->listA, ['group_id' => $g->id]);
        $b = $this->client($this->listA, ['group_id' => $g->id]);
        $ia = $this->invoice($a, 10);
        $ib = $this->invoice($b, 10);

        $run = PriceChange::draft($this->admin, 'chain', ['group_id' => $g->id, 'price_list_id' => $this->listB->id]);
        $this->assertSame($this->listA->id, (int) $a->fresh()->price_list_id, 'المسودة ماتنقلش الفروع');
        $this->assertCount(2, $run->item_changes);
        $this->assertSame($this->listA->id, $run->item_changes[0]['old_list_id']);
        $this->assertSame($this->listB->id, $run->item_changes[0]['new_list_id']);

        PriceChange::apply($run, $this->admin);

        foreach ([$a, $b] as $c) {
            $this->assertSame($this->listB->id, (int) $c->fresh()->price_list_id);
            $this->assertSame('new', $c->fresh()->price_list, 'القايمة المسمّاة بتتخزن new في العمود النصي');
        }
        foreach ([$ia, $ib] as $inv) {
            $this->assertEqualsWithDelta(51.30, (float) $inv->fresh()->grand_total, 0.001);
        }
        $this->assertSame('chain', $run->fresh()->scope);
        $this->assertSame($g->id, (int) $run->fresh()->group_id);
        $this->assertLedgerReconciles($a, $b);
    }

    public function test_branch_trigger_branch_only_vs_whole_chain(): void
    {
        $g = $this->group();
        $a = $this->client($this->listA, ['group_id' => $g->id]);
        $b = $this->client($this->listA, ['group_id' => $g->id]);
        $ia = $this->invoice($a, 10);
        $ib = $this->invoice($b, 10);

        // فرع بس
        $run = PriceChange::draft($this->admin, 'branch', ['client_id' => $a->id, 'price_list_id' => $this->listB->id, 'scope' => 'branch']);
        $this->assertSame(1, (int) $run->clients_count);
        PriceChange::apply($run, $this->admin);

        $this->assertSame($this->listB->id, (int) $a->fresh()->price_list_id);
        $this->assertSame($this->listA->id, (int) $b->fresh()->price_list_id);
        $this->assertEqualsWithDelta(51.30, (float) $ia->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $ib->fresh()->grand_total, 0.001);

        // كل السلسلة — وبتغيير النطاق على المسودة
        $run2 = PriceChange::draft($this->admin, 'branch', ['client_id' => $b->id, 'price_list_id' => $this->listB->id, 'scope' => 'branch']);
        $result = PriceChange::retarget($run2, 'chain', null);
        $this->assertSame(2, $result['clients']);
        PriceChange::apply($run2->fresh(), $this->admin);

        $this->assertSame($this->listB->id, (int) $b->fresh()->price_list_id);
        $this->assertEqualsWithDelta(51.30, (float) $ib->fresh()->grand_total, 0.001);
        $this->assertSame('chain', $run2->fresh()->scope);
        $this->assertLedgerReconciles($a, $b);
    }

    public function test_client_trigger_cash_van_without_chain(): void
    {
        $c = $this->client($this->listA);
        $inv = $this->invoice($c, 10);

        $run = PriceChange::draft($this->admin, 'client', ['client_id' => $c->id, 'price_list_id' => $this->listB->id]);
        $this->assertSame('client', $run->scope);
        $this->assertSame($c->id, (int) $run->client_id);

        PriceChange::apply($run, $this->admin);

        $this->assertSame($this->listB->id, (int) $c->fresh()->price_list_id);
        $this->assertEqualsWithDelta(51.30, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertLedgerReconciles($c);
    }

    public function test_cash_invoice_difference_lands_on_ledger(): void
    {
        // سعر أقل → الفرق رصيد دائن للعميل
        $c = $this->client($this->listA, ['payment_terms' => 'cash']);
        $inv = $this->invoice($c, 10, 'cash');
        $this->assertEqualsWithDelta(0.0, (float) $c->fresh()->balance, 0.001);

        PriceChange::apply($this->listDraft(8), $this->admin);

        $sale = Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'sale')->first();
        $coll = Transaction::where('source_type', Invoice::class)->where('source_id', $inv->id)->where('kind', 'collection')->first();
        $this->assertEqualsWithDelta(27.36, (float) $sale->debit, 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $coll->credit, 0.001, 'التحصيل ماحدش يلمسه');
        $this->assertEqualsWithDelta(-6.84, (float) $c->fresh()->balance, 0.001);
        $this->assertLedgerReconciles($c);

        // سعر أعلى → الفرق مديونية
        $d = $this->client($this->listB, ['payment_terms' => 'cash']);
        $this->invoice($d, 15, 'cash');
        PriceChange::apply(PriceChange::draft($this->admin, 'list', [
            'price_list_id' => $this->listB->id, 'prices' => [$this->product->id => 20],
        ]), $this->admin);

        // 60 + 8.40 = 68.40 مقابل 51.30 متحصّلة
        $this->assertEqualsWithDelta(17.10, (float) $d->fresh()->balance, 0.001);
        $this->assertLedgerReconciles($c, $d);
    }

    public function test_skipped_documents_have_reasons(): void
    {
        $x = $this->client($this->listA);
        $other = $this->makeProduct(['name' => 'صنف بلا سعر', 'name_en' => 'Unpriced']);

        $eta = $this->invoice($x, 10, 'credit', ['eta_status' => 'exported']);

        $cons = $this->invoice($x, 10);
        Transaction::create([
            'client_id' => $x->id, 'date' => today(), 'memo' => 'أمانة',
            'debit' => 0, 'credit' => 0, 'kind' => 'consignment',
            'source_type' => Invoice::class, 'source_id' => $cons->id,
        ]);

        $ret = $this->invoice($x, 10);
        $doc = ClientReturn::create([
            'number' => 'RET-'.random_int(10000, 99999),
            'client_id' => $x->id, 'user_id' => $this->rep->id,
            'source' => 'office', 'policy' => 'credit',
            'subtotal' => 10, 'discount' => 0, 'total' => 10, 'tax_total' => 0, 'grand_total' => 10,
        ]);
        ReturnItem::create([
            'return_id' => $doc->id, 'product_id' => $this->product->id,
            'invoice_id' => $ret->id, 'qty' => 1, 'condition' => 'good',
            'list_price' => 10, 'price' => 10, 'total' => 10, 'tax_rate' => 0, 'tax' => 0,
        ]);

        $closed = $this->invoice($x, 10);
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(2);
        Invoice::whereKey($closed->id)->update(['created_at' => $lastMonth]);
        GlPeriod::close($lastMonth->format('Y-m'), $this->admin);

        // فيها الصنف اللي اتغير (عشان مصدر القايمة يلمسها) + صنف بلا سعر
        $unpriced = $this->invoice($x, 10, 'credit', [], $other);
        InvoiceItem::create([
            'invoice_id' => $unpriced->id, 'product_id' => $this->product->id, 'qty' => 1,
            'list_price' => 10, 'price' => 10, 'unit_cost' => 5, 'total' => 0, 'tax_rate' => 0.14, 'tax' => 0,
        ]);

        $delivered = $this->po($x, 10, 'delivered');
        $cancelled = $this->po($x, 10, 'cancelled');
        $onRoad = $this->po($x, 10, 'arrived');
        // سعر صافي متفق عليه فعلاً: عميل له خصم + السطر بسعر القايمة قبل
        // الخصم (price_old = 18) — مش مجرد `price_mode = old`
        $f = $this->client($this->listA, ['discount' => 0.10]);
        $fixed = $this->po($f, 10, 'pending', ['price_mode' => 'old', 'total' => 54, 'tax_total' => 7.56, 'grand_total' => 61.56]);
        $fixed->items()->update(['price' => 18, 'list_price' => 18, 'total' => 54, 'tax' => 7.56]);

        $run = $this->listDraft(12);
        $result = PriceChange::apply($run, $this->admin);

        $this->assertSame(0, $result['invoices_changed']);
        $this->assertSame(0, $result['pos_changed']);
        $this->assertSame(9, $result['skipped']);

        $reasons = PriceChangeDoc::where('run_id', $run->id)->where('outcome', 'skipped')
            ->get()->mapWithKeys(fn ($d) => [$d->doc_type.':'.$d->doc_id => $d->reason])->all();

        $this->assertSame('eta_locked', $reasons['invoice:'.$eta->id]);
        $this->assertSame('consignment', $reasons['invoice:'.$cons->id]);
        $this->assertSame('has_return', $reasons['invoice:'.$ret->id]);
        $this->assertSame('closed_period', $reasons['invoice:'.$closed->id]);
        $this->assertSame('not_priced', $reasons['invoice:'.$unpriced->id]);
        $this->assertSame('po_delivered', $reasons['po:'.$delivered->id]);
        $this->assertSame('po_cancelled', $reasons['po:'.$cancelled->id]);
        $this->assertSame('po_in_delivery', $reasons['po:'.$onRoad->id]);
        $this->assertSame('po_fixed_price', $reasons['po:'.$fixed->id]);

        $notPriced = PriceChangeDoc::where('run_id', $run->id)->where('doc_id', $unpriced->id)->where('doc_type', 'invoice')->first();
        $this->assertSame($other->displayName(), $notPriced->reason_detail);
        $closedRow = PriceChangeDoc::where('run_id', $run->id)->where('doc_id', $closed->id)->where('doc_type', 'invoice')->first();
        $this->assertSame(\App\Support\PeriodGuard::label($lastMonth), $closedRow->reason_detail);
        $this->assertSame(__('reprice.skip_po_delivered'), PriceChangeDoc::where('run_id', $run->id)->where('doc_id', $delivered->id)->where('doc_type', 'po')->first()->reasonLabel());

        foreach ([$eta, $cons, $ret, $closed, $unpriced] as $inv) {
            $fresh = $inv->fresh();
            $this->assertEqualsWithDelta(34.20, (float) $fresh->grand_total, 0.001);
            $this->assertNull($fresh->price_change_run_id);
        }
        foreach ([$delivered, $cancelled, $onRoad] as $po) {
            $this->assertEqualsWithDelta(34.20, (float) $po->fresh()->grand_total, 0.001);
            $this->assertNull($po->fresh()->price_change_run_id);
        }
        $this->assertEqualsWithDelta(61.56, (float) $fixed->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(18.0, (float) $fixed->items()->first()->price, 0.001);
        $this->assertNull($fixed->fresh()->price_change_run_id);
        $this->assertLedgerReconciles($x);
    }

    public function test_default_new_list_channel_po_repriced_real_fixed_po_skipped(): void
    {
        // القايمة الافتراضية `new` — أوامر القناة عليها بتتخزّن `price_mode = new`
        $newList = $this->makePriceList('new');
        $chan = $this->client($newList, ['discount' => 0.10]);
        $fix = $this->client($newList, ['discount' => 0.10]);

        // أمر قناة عادي: السطر مخصوم (20 × 0.9 = 18)
        $channelPo = $this->po($chan, 18, 'pending', ['price_mode' => 'new']);
        $channelPo->items()->update(['list_price' => 20, 'discount_pct' => 0.10]);

        // سعر صافي متفق عليه: السطر بسعر القايمة قبل الخصم (price_new = 20)
        $fixedPo = $this->po($fix, 20, 'pending', ['price_mode' => 'new']);

        $run = PriceChange::draft($this->admin, 'list', [
            'price_list_id' => $newList->id,
            'prices' => [$this->product->id => 25],
        ]);
        $result = PriceChange::apply($run, $this->admin);

        // 25 × 0.9 = 22.50 × 3 = 67.50 + 14% = 76.95
        $channelPo->refresh();
        $this->assertEqualsWithDelta(76.95, (float) $channelPo->grand_total, 0.001);
        $this->assertEqualsWithDelta(22.50, (float) $channelPo->items()->first()->price, 0.001);
        $this->assertSame($run->id, (int) $channelPo->price_change_run_id);

        // ⚠️ تعديل قايمة `new` بيكتب `price_new` العكسي قبل الفحص —
        // الأمر الثابت لازم يفضل متخطّي برضو
        $this->assertEqualsWithDelta(68.40, (float) $fixedPo->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $fixedPo->items()->first()->price, 0.001);
        $this->assertSame('po_fixed_price', PriceChangeDoc::where('run_id', $run->id)
            ->where('doc_type', 'po')->where('doc_id', $fixedPo->id)->value('reason'));

        $this->assertSame(1, $result['pos_changed']);
    }

    public function test_pending_po_repriced_delivered_po_skipped(): void
    {
        $x = $this->client($this->listA);
        $pending = $this->po($x, 10);
        $delivered = $this->po($x, 10, 'delivered');
        $txBefore = Transaction::count();

        $run = $this->listDraft(12);
        PriceChange::apply($run, $this->admin);

        $pending->refresh();
        $this->assertEqualsWithDelta(41.04, (float) $pending->grand_total, 0.001);
        $this->assertTrue((bool) $pending->was_edited);
        $this->assertSame($this->admin->id, (int) $pending->edited_by);
        $this->assertSame($run->id, (int) $pending->price_change_run_id);
        $this->assertSame('pending', $pending->status);
        $this->assertSame(3, (int) $pending->items()->first()->qty);

        $this->assertEqualsWithDelta(34.20, (float) $delivered->fresh()->grand_total, 0.001);
        $this->assertSame($txBefore, Transaction::count(), 'أمر التوريد قبل التسليم مالوش قيود');
        $this->assertSame(1, (int) $run->fresh()->pos_changed);
    }

    public function test_idempotent_rerun_changes_nothing(): void
    {
        $g = $this->group();
        $a = $this->client($this->listA, ['group_id' => $g->id]);
        $b = $this->client($this->listA, ['group_id' => $g->id]);
        $ia = $this->invoice($a, 10);
        $this->invoice($b, 10);
        $po = $this->po($a, 10);

        $first = $this->listDraft(12);
        PriceChange::apply($first, $this->admin);
        $stampA = DB::table('invoices')->where('id', $ia->id)->value('updated_at');
        $stampPo = DB::table('purchase_orders')->where('id', $po->id)->value('updated_at');

        $this->travel(5)->minutes();

        $resync = PriceChange::draft($this->admin, 'chain', ['group_id' => $g->id, 'price_list_id' => null]);
        $this->assertSame(0, (int) $resync->invoices_changed);
        $this->assertSame(0, (int) $resync->pos_changed);
        $this->assertSame(3, (int) $resync->unchanged_count);

        $result = PriceChange::apply($resync, $this->admin);
        $this->assertSame(0, $result['invoices_changed']);
        $this->assertSame(3, $result['unchanged']);

        $this->assertSame($stampA, DB::table('invoices')->where('id', $ia->id)->value('updated_at'));
        $this->assertSame($stampPo, DB::table('purchase_orders')->where('id', $po->id)->value('updated_at'));
        $this->assertSame($first->id, (int) $ia->fresh()->price_change_run_id);
        $this->assertSame($first->id, (int) $po->fresh()->price_change_run_id);
        $this->assertSame(0, PriceChangeDoc::where('run_id', $resync->id)->count(), 'المستند اللي ماتغيّرش مابيتسجلش');
        $this->assertLedgerReconciles($a, $b);
    }

    public function test_apply_twice_is_rejected(): void
    {
        $x = $this->client($this->listA);
        $inv = $this->invoice($x, 10);
        $run = $this->listDraft(12);

        PriceChange::apply($run, $this->admin);
        $docs = PriceChangeDoc::count();

        // نفس الموديل القديم (دبل كليك) — لسه فاكر نفسه draft
        $this->assertRejected(__('reprice.not_draft'), fn () => PriceChange::apply($run, $this->admin));

        $this->assertEqualsWithDelta(41.04, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertSame($docs, PriceChangeDoc::count());
        $this->assertEqualsWithDelta(41.04, (float) $x->fresh()->balance, 0.001);
        $this->assertLedgerReconciles($x);
    }

    public function test_stale_preview_is_rejected(): void
    {
        $x = $this->client($this->listA);
        $inv = $this->invoice($x, 10);
        $run = $this->listDraft(12);

        // حد غيّر في الداتا بين المعاينة والتأكيد — فاتورة جديدة
        $late = $this->invoice($x, 10);

        $this->assertRejected(__('reprice.preview_stale'), fn () => PriceChange::apply($run, $this->admin));

        $this->assertSame('draft', $run->fresh()->status);
        $this->assertEqualsWithDelta(10.0, $this->priceIn($this->listA), 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $late->fresh()->grand_total, 0.001);
        $this->assertSame(0, PriceChangeDoc::count());

        // ومعاينة جديدة بتفك الحظر
        PriceChange::refreshPreview($run->fresh());
        PriceChange::apply($run->fresh(), $this->admin);
        $this->assertEqualsWithDelta(41.04, (float) $late->fresh()->grand_total, 0.001);
        $this->assertLedgerReconciles($x);
    }

    public function test_master_only_changes_prices_not_documents(): void
    {
        $x = $this->client($this->listA);
        $inv = $this->invoice($x, 10);
        $po = $this->po($x, 10);

        $run = $this->listDraft(12);
        PriceChange::applyMasterOnly($run, $this->admin);

        $this->assertEqualsWithDelta(12.0, $this->priceIn($this->listA), 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $po->fresh()->grand_total, 0.001);
        $this->assertSame('master_only', $run->fresh()->status);
        $this->assertSame(0, PriceChangeDoc::count());

        $this->assertRejected(__('reprice.not_draft'), fn () => PriceChange::apply($run, $this->admin));
    }

    public function test_master_only_writes_reverse_column_for_old_new_lists(): void
    {
        $list = $this->makePriceList('new');
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $this->product->id, 'price' => 20]);

        $run = PriceChange::draft($this->admin, 'list', ['price_list_id' => $list->id, 'prices' => [$this->product->id => 22.5]]);
        PriceChange::applyMasterOnly($run, $this->admin);

        $this->assertEqualsWithDelta(22.5, (float) $this->product->fresh()->price_new, 0.001);
        $this->assertEqualsWithDelta(18.0, (float) $this->product->fresh()->price_old, 0.001);
    }

    public function test_cancel_leaves_everything(): void
    {
        $x = $this->client($this->listA);
        $this->invoice($x, 10);
        $run = $this->listDraft(12);

        PriceChange::cancel($run);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertEqualsWithDelta(10.0, $this->priceIn($this->listA), 0.001);
        $this->assertRejected(__('reprice.not_draft'), fn () => PriceChange::apply($run->fresh(), $this->admin));
    }

    public function test_list_draft_ignores_unchanged_rows_and_rejects_empty(): void
    {
        $this->assertRejected(__('reprice.nothing_changed'), fn () => $this->listDraft(10));
        $this->assertSame(0, PriceChangeRun::count());
    }

    public function test_atomic_failure_rolls_back_everything(): void
    {
        $x = $this->client($this->listA);
        $y = $this->client($this->listA);
        $ix = $this->invoice($x, 10);
        $iy = $this->invoice($y, 10);
        $run = $this->listDraft(12);

        // عطل في نص التطبيق — بعد ما فاتورة أولى اتكتبت
        Invoice::updating(function (Invoice $inv) use ($iy) {
            if ($inv->id === $iy->id) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            PriceChange::apply($run, $this->admin);
            $this->fail('كان المفروض يقع');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertEqualsWithDelta(10.0, $this->priceIn($this->listA), 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $ix->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $ix->items()->first()->price, 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $iy->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(34.20, (float) Transaction::where('source_id', $ix->id)->where('kind', 'sale')->value('debit'), 0.001);
        $this->assertSame('draft', $run->fresh()->status);
        $this->assertSame(0, PriceChangeDoc::count());
        $this->assertLedgerReconciles($x, $y);
    }

    public function test_record_single_logs_one_applied_run(): void
    {
        $x = $this->client($this->listA);
        $inv = $this->invoice($x, 10);
        PriceListItem::where('price_list_id', $this->listA->id)->update(['price' => 12]);
        PriceList::flushCache();

        $plan = \App\Services\Reprice\InvoiceRepricer::plan($inv);
        \App\Services\Reprice\InvoiceRepricer::apply($inv, $plan, true, true);
        $run = PriceChange::recordSingle($inv->fresh(), $this->admin, $plan);

        $this->assertSame('invoice', $run->trigger);
        $this->assertSame('invoice', $run->scope);
        $this->assertSame('applied', $run->status);
        $this->assertSame(1, (int) $run->invoices_changed);
        $this->assertEqualsWithDelta(34.20, (float) $run->before_grand, 0.001);
        $this->assertEqualsWithDelta(41.04, (float) $run->after_grand, 0.001);
        $this->assertSame(1, $run->docs()->where('outcome', 'changed')->count());
        $this->assertSame($run->id, (int) $inv->fresh()->price_change_run_id);
    }

    public function test_chain_list_of_contract_then_branches(): void
    {
        $g = $this->group();
        $this->client($this->listA, ['group_id' => $g->id]);
        $this->client($this->listA, ['group_id' => $g->id]);
        $this->client($this->listB, ['group_id' => $g->id]);

        $info = PriceChange::chainListOf($g);
        $this->assertSame($this->listA->id, $info['list']->id);
        $this->assertSame('branches', $info['source']);
        $this->assertSame(2, $info['count']);
        $this->assertSame(3, $info['total']);

        $empty = PriceChange::chainListOf($this->group());
        $this->assertNull($empty['list']);
        $this->assertSame('none', $empty['source']);
    }

    public function test_list_scope_is_company_wide_chain_scope_is_manager(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $mine = $this->client($this->listA, ['manager_id' => $manager->id]);
        $notMine = $this->client($this->listA);
        $this->invoice($mine, 10);
        $theirs = $this->invoice($notMine, 10);

        // القايمة بيانات أساسية للشركة — كل عملائها حتى لو المنشئ مدير
        $run = $this->listDraft(12, $manager);
        $this->assertSame(2, (int) $run->clients_count);
        $this->assertTrue($run->preview['company_wide']);

        $result = PriceChange::apply($run, $this->admin);
        $this->assertTrue($result['company_wide']);
        $this->assertEqualsWithDelta(41.04, (float) $theirs->fresh()->grand_total, 0.001);

        // السلسلة فاضلة على سكوب المدير اللي عمل المسودة
        $g = $this->group();
        $a = $this->client($this->listA, ['group_id' => $g->id, 'manager_id' => $manager->id]);
        $b = $this->client($this->listA, ['group_id' => $g->id]);
        $ib = $this->invoice($b, 12);

        $chain = PriceChange::draft($manager, 'chain', ['group_id' => $g->id, 'price_list_id' => $this->listB->id]);
        $this->assertSame(1, (int) $chain->clients_count);
        $this->assertFalse($chain->preview['company_wide']);
        PriceChange::apply($chain, $this->admin);

        $this->assertSame($this->listB->id, (int) $a->fresh()->price_list_id);
        $this->assertSame($this->listA->id, (int) $b->fresh()->price_list_id);
        $this->assertEqualsWithDelta(41.04, (float) $ib->fresh()->grand_total, 0.001);
        $this->assertLedgerReconciles($mine, $notMine, $a, $b);
    }

    /** دفتر شجرة بيقع في إعادة الترحيل — `InvoiceGl::repost` بترجّع false */
    private function breakGl(): void
    {
        $this->mock(\App\Services\Gl\Ledger::class, function ($m) {
            $m->shouldReceive('autoEntryFor')->andReturn(null);
            $m->shouldReceive('repost')->andThrow(new \RuntimeException('gl down'));
            $m->shouldIgnoreMissing();
        });
    }

    public function test_gl_failure_rolls_back_the_whole_run(): void
    {
        $x = $this->client($this->listA);
        $y = $this->client($this->listA);
        $ix = $this->invoice($x, 10);
        $iy = $this->invoice($y, 10);
        $run = $this->listDraft(12);

        $this->breakGl();

        $msg = __('reprice.gl_failed', ['number' => $ix->number]);
        $this->assertRejected($msg, fn () => PriceChange::apply($run, $this->admin));

        $run->refresh();
        $this->assertSame('draft', $run->status);
        $this->assertSame($msg, $run->error);
        $this->assertEqualsWithDelta(10.0, $this->priceIn($this->listA), 0.001);
        foreach ([$ix, $iy] as $inv) {
            $this->assertEqualsWithDelta(34.20, (float) $inv->fresh()->grand_total, 0.001);
            $this->assertNull($inv->fresh()->price_change_run_id);
        }
        $this->assertSame(0, PriceChangeDoc::count());
        $this->assertLedgerReconciles($x, $y);
    }

    public function test_single_button_stays_lenient_on_gl_failure(): void
    {
        $x = $this->client($this->listA);
        $inv = $this->invoice($x, 10);
        PriceListItem::where('price_list_id', $this->listA->id)->update(['price' => 12]);
        PriceList::flushCache();
        $this->breakGl();

        $plan = \App\Services\Reprice\InvoiceRepricer::plan($inv);
        \App\Services\Reprice\InvoiceRepricer::apply($inv, $plan, true, true);

        $this->assertEqualsWithDelta(41.04, (float) $inv->fresh()->grand_total, 0.001);
    }

    public function test_failed_first_preview_cancels_the_draft(): void
    {
        $x = $this->client($this->listA);
        $inv = $this->invoice($x, 10);
        $this->breakGl();

        $msg = __('reprice.gl_failed', ['number' => $inv->number]);
        $this->assertRejected($msg, fn () => $this->listDraft(12));

        $run = PriceChangeRun::sole();
        $this->assertSame('cancelled', $run->status);
        $this->assertSame($msg, $run->error);
        $this->assertEqualsWithDelta(10.0, $this->priceIn($this->listA), 0.001);
    }

    public function test_preview_refused_once_run_left_draft(): void
    {
        $x = $this->client($this->listA);
        $this->invoice($x, 10);
        $run = $this->listDraft(12);

        // تاب تانية أكّدت — الموديل اللي في إيدنا لسه فاكر نفسه draft
        PriceChange::apply($run->fresh(), $this->admin);
        $applied = $run->fresh();

        $this->assertRejected(__('reprice.not_draft'), fn () => PriceChange::refreshPreview($run));
        $this->assertRejected(__('reprice.not_draft'), fn () => PriceChange::retarget($run, null, '2026-01-01'));

        $after = $run->fresh();
        $this->assertSame('applied', $after->status);
        $this->assertEquals($applied->preview, $after->preview);
        $this->assertNull($after->since);
    }

    public function test_stale_guard_compares_per_type_totals(): void
    {
        $x = $this->client($this->listA);
        $this->invoice($x, 10);
        $this->po($x, 10);
        $run = $this->listDraft(12);

        // نفس الإجمالي الكلي بس متوزع غلط بين الفواتير والأوامر
        $p = $run->preview;
        $p['totals']['invoice']['after_grand'] += 1;
        $p['totals']['po']['after_grand'] -= 1;
        $run->forceFill(['preview' => $p])->save();

        $this->assertRejected(__('reprice.preview_stale'), fn () => PriceChange::apply($run->fresh(), $this->admin));
        $this->assertSame('draft', $run->fresh()->status);
    }

    // ═══════════════ المراجعة الأخيرة ═══════════════

    public function test_list_trigger_touches_only_documents_with_changed_products(): void
    {
        $q = $this->makeProduct(['name' => 'صنف تاني', 'name_en' => 'Other item']);
        PriceListItem::create(['price_list_id' => $this->listA->id, 'product_id' => $q->id, 'price' => 5]);

        $x = $this->client($this->listA);
        $withP = $this->invoice($x, 10);
        // سعر Q على الفاتورة قديم (4 والقايمة 5) — إعادة تسعير كاملة كانت هتغيّره
        $onlyQ = $this->invoice($x, 4, 'credit', [], $q);
        $mixed = $this->invoice($x, 10);
        InvoiceItem::create([
            'invoice_id' => $mixed->id, 'product_id' => $q->id, 'qty' => 1,
            'list_price' => 4, 'price' => 4, 'unit_cost' => 2, 'total' => 4, 'tax_rate' => 0.14, 'tax' => 0.56,
        ]);
        $mixed->update(['subtotal' => 34, 'total' => 34, 'tax_total' => 4.76, 'grand_total' => 38.76]);
        Transaction::where('source_id', $mixed->id)->where('kind', 'sale')->update(['debit' => 38.76, 'tax' => 4.76]);
        $x->recalculate();

        $run = $this->listDraft(12);
        $result = PriceChange::apply($run, $this->admin);

        $this->assertSame(1, $result['invoices_changed']);
        $this->assertSame(0, $result['unchanged'], 'فاتورة Q بس مابتتعدّش أصلاً');
        $this->assertSame(1, $result['skipped']);
        $this->assertEqualsWithDelta(41.04, (float) $withP->fresh()->grand_total, 0.001);

        $this->assertEqualsWithDelta(13.68, (float) $onlyQ->fresh()->grand_total, 0.001);
        $this->assertNull($onlyQ->fresh()->price_change_run_id);
        $this->assertSame(0, PriceChangeDoc::where('doc_id', $onlyQ->id)->where('doc_type', 'invoice')->count());

        $this->assertEqualsWithDelta(38.76, (float) $mixed->fresh()->grand_total, 0.001);
        $this->assertSame('other_terms_changed', PriceChangeDoc::where('run_id', $run->id)
            ->where('doc_type', 'invoice')->where('doc_id', $mixed->id)->value('reason'));
        $this->assertLedgerReconciles($x);
    }

    public function test_client_discount_change_skips_on_list_but_reprices_on_resync(): void
    {
        $c = $this->client($this->listA);
        $inv = $this->invoice($c, 10);
        $po = $this->po($c, 10);
        $c->update(['discount' => 0.10]);   // الخصم اتغير بعد البيع

        $run = $this->listDraft(12);
        $result = PriceChange::apply($run, $this->admin);

        $this->assertSame(0, $result['invoices_changed']);
        $this->assertSame(0, $result['pos_changed']);
        $reasons = PriceChangeDoc::where('run_id', $run->id)->pluck('reason', 'doc_type')->all();
        $this->assertSame(['invoice' => 'other_terms_changed', 'po' => 'other_terms_changed'], $reasons);
        $this->assertEqualsWithDelta(34.20, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertSame(__('reprice.skip_other_terms_changed'), PriceChangeDoc::where('run_id', $run->id)->first()->reasonLabel());

        // «طبّق التسعيرة الحالية» من صفحة العميل = إعادة تسعير كاملة
        $resync = PriceChange::draft($this->admin, 'client', ['client_id' => $c->id, 'price_list_id' => null]);
        PriceChange::apply($resync, $this->admin);

        // 12 × 0.9 = 10.80 × 3 = 32.40 + 4.54 = 36.94
        $this->assertEqualsWithDelta(36.94, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertEqualsWithDelta(36.94, (float) $po->fresh()->grand_total, 0.001);
        $this->assertLedgerReconciles($c);
    }

    public function test_master_only_and_cancel_zero_money_columns(): void
    {
        $x = $this->client($this->listA);
        $this->invoice($x, 10);

        $m = $this->listDraft(12);
        $this->assertEqualsWithDelta(41.04, (float) $m->after_grand, 0.001);
        PriceChange::applyMasterOnly($m, $this->admin);

        $c = $this->listDraft(15);
        PriceChange::cancel($c);

        foreach ([$m->fresh(), $c->fresh()] as $run) {
            foreach (['clients_count', 'invoices_changed', 'pos_changed', 'skipped_count', 'unchanged_count'] as $k) {
                $this->assertSame(0, (int) $run->{$k}, $k);
            }
            foreach (['before_grand', 'after_grand', 'ledger_diff'] as $k) {
                $this->assertEqualsWithDelta(0.0, (float) $run->{$k}, 0.001, $k);
            }
            $this->assertNotEmpty($run->preview['totals'], 'المعاينة فاضلة في الـjson للتاريخ');
        }
    }

    public function test_deleted_creator_blocks_scoped_preview_and_apply(): void
    {
        $creator = $this->makeAdmin(['role' => 'manager']);
        $g = $this->group();
        $a = $this->client($this->listA, ['group_id' => $g->id]);
        $this->invoice($a, 10);

        $run = PriceChange::draft($creator, 'chain', ['group_id' => $g->id, 'price_list_id' => $this->listB->id]);
        DB::table('users')->where('id', $creator->id)->delete();
        $run = $run->fresh();
        $this->assertNull($run->user_id);

        $this->assertRejected(__('reprice.creator_missing'), fn () => PriceChange::refreshPreview($run));
        $this->assertRejected(__('reprice.creator_missing'), fn () => PriceChange::apply($run, $this->admin));
        $this->assertSame($this->listA->id, (int) $a->fresh()->price_list_id);
        $this->assertSame('draft', $run->fresh()->status);

        // القايمة على مستوى الشركة — مالهاش دعوة بصاحبها
        $owner = $this->makeAdmin();
        $list = $this->listDraft(12, $owner);
        DB::table('users')->where('id', $owner->id)->delete();
        PriceChange::apply($list->fresh(), $this->admin);
        $this->assertSame('applied', $list->fresh()->status);
    }
}
