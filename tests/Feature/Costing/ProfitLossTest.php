<?php

namespace Tests\Feature\Costing;

use App\Models\Batch;
use App\Models\ClientReturn;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\CostLine;
use App\Models\CostMonth;
use App\Models\CostMonthLine;
use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductTransferPrice;
use App\Models\ReturnItem;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\ProfitLoss;
use App\Services\Costing\TransferPrice;
use App\Services\Costing\Valuation;
use App\Services\SalesSource;
use App\Support\DateRange;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** قايمة الدخل الإدارية بالمستويات الخمسة (الخط ٩ — مهمة ٨) */
class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    private int $n = 0;

    private CostCenter $maadi;

    private CostCenter $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        CostingGlSeed::run();
        Setting::write('cost_method', 'batch');
        Setting::flushCache();
        Valuation::flush();
        TransferPrice::flush();
        $this->rep = $this->makeRep();
        $co = CostCenter::company();
        $fac = CostCenter::create(['code' => 'FAC', 'name' => 'المصنع', 'name_en' => 'Factory', 'kind' => 'factory', 'parent_id' => $co->id, 'active' => true]);
        $this->line = CostCenter::create(['code' => 'L1', 'name' => 'خط', 'name_en' => 'Line 1', 'kind' => 'line', 'parent_id' => $fac->id, 'active' => true]);
        $this->maadi = CostCenter::maadi();
    }

    private function aug(): DateRange
    {
        return DateRange::fromRequest(Request::create('/', 'GET', ['from' => '2026-08-01', 'to' => '2026-08-31']));
    }

    /** @param list<array{0:Product,1:float,2:float,3?:float}> $lines [product, qty, unit_cost, price] */
    private function sale(array $lines, string $date = '2026-08-10'): array
    {
        $client = $this->makeClient();
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $l[1] * ($l[3] ?? 20);
        }
        $inv = Invoice::create([
            'number' => 'INV-'.(80000 + ++$this->n), 'client_id' => $client->id, 'user_id' => $this->rep->id,
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

    private function returnOf(array $sale, float $qty, float $price = 20, string $condition = 'good', string $date = '2026-08-20'): Transaction
    {
        [$inv, , $items, $client] = $sale;
        $total = $qty * $price;
        $ret = ClientReturn::create(['number' => 'RET-'.(80000 + ++$this->n), 'client_id' => $client->id, 'source' => 'erp', 'policy' => 'account',
            'subtotal' => $total, 'discount' => 0, 'total' => $total, 'tax_total' => 0, 'grand_total' => $total]);
        ReturnItem::create(['return_id' => $ret->id, 'product_id' => $items[0]->product_id, 'invoice_id' => $inv->id, 'invoice_item_id' => $items[0]->id,
            'qty' => $qty, 'condition' => $condition, 'list_price' => $price, 'price' => $price, 'total' => $total, 'tax_rate' => 0, 'tax' => 0]);

        return Transaction::create(['client_id' => $client->id, 'date' => $date, 'memo' => $ret->number, 'debit' => 0, 'credit' => $total,
            'tax' => 0, 'kind' => 'return', 'source_type' => ClientReturn::class, 'source_id' => $ret->id]);
    }

    private function tp(Product $p, float $price): void
    {
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => $price, 'unit_cost' => 10, 'markup_pct' => 50,
            'effective_from' => '2026-08-01', 'approved_by' => $this->makeAdmin()->id, 'approved_at' => now()]);
        TransferPrice::flush();
    }

    /** قيد تحويل داخلي مستلم في الطبقة مباشرة (الـP&L بيقرا الطبقة بس) */
    private function transfer(Product $p, float $qty, float $unitR, ?float $unitTp, string $date = '2026-08-05'): CostEntry
    {
        $r = round($qty * $unitR, 2);
        $tpAmt = $unitTp === null ? $r : round($qty * $unitTp, 2);
        $e = CostEntry::create(['kind' => 'transfer', 'origin_type' => (new StockTransfer)->getMorphClass(), 'origin_id' => 900 + ++$this->n,
            'date' => $date, 'cost_center_id' => $this->line->id, 'other_center_id' => $this->maadi->id, 'qty' => $qty, 'revenue' => 0,
            'amount_batch' => $r, 'amount_wavg' => $r, 'amount_transfer' => $tpAmt, 'materials' => 0, 'overhead' => 0,
            'flags' => $unitTp === null ? 'no_transfer_price' : null]);
        CostLine::create(['cost_entry_id' => $e->id, 'product_id' => $p->id, 'qty' => $qty, 'revenue' => 0,
            'unit_batch' => $unitR, 'unit_wavg' => $unitR, 'unit_transfer' => $unitTp,
            'amount_batch' => $r, 'amount_wavg' => $r, 'amount_transfer' => $tpAmt, 'zero_cost' => false]);

        return $e;
    }

    private function expense(?CostCenter $center, float $amount, string $date = '2026-08-12'): Expense
    {
        return Expense::create([
            'number' => 'EXP-'.(80000 + ++$this->n), 'date' => $date, 'account_id' => GlAccount::where('type', 'expense')->value('id'),
            'amount' => $amount, 'paid_from' => 'cash_main', 'status' => 'posted', 'cost_center_id' => $center?->id,
        ]);
    }

    private function branch(array $pnl, CostCenter $c): array
    {
        return collect($pnl['branches'])->firstWhere('key', 'branch:'.$c->id);
    }

    public function test_company_equals_sum_of_levels_minus_elimination(): void
    {
        $p = $this->makeProduct(['kind' => 'finished', 'cost' => 10]);
        $this->tp($p, 15);
        $this->transfer($p, 10, 10, 15);                    // مصنع: 150 إيراد داخلي، 100 تكلفة
        $this->sale([[$p, 4, 10, 20]]);                      // فرع: 80 إيراد، 60 بسعر التحويل، R = 40
        $this->expense($this->maadi, 50);
        $this->expense(null, 30);
        $cm = CostMonth::create(['period' => '2026-08', 'status' => 'closed', 'closed_at' => now()]);
        CostMonthLine::create(['cost_month_id' => $cm->id, 'line_id' => $this->line->id, 'pool' => 125, 'applied' => 100, 'variance' => 25]);

        $pnl = ProfitLoss::build($this->aug());
        $f = $pnl['factory'];
        $b = $this->branch($pnl, $this->maadi);
        $cc = $pnl['company_center'];
        $c = $pnl['company'];

        $this->assertSame([150.0, 100.0, 50.0, 25.0, 25.0], [$f['revenue'], $f['cost'], $f['gross'], $f['expenses'], $f['net']]);
        $this->assertSame(25.0, $f['children'][0]['expenses'], 'الخط = تحميل ناقص الشهر المقفول');
        $this->assertSame([80.0, 60.0, 20.0, 50.0, -30.0], [$b['revenue'], $b['cost'], $b['gross'], $b['expenses'], $b['net']]);
        $this->assertSame(-30.0, $cc['net']);
        $this->assertSame(30.0, $pnl['elimination'], 'هامش التحويل 50 − هامش البيع 20');
        $this->assertSame([80.0, 40.0, 105.0, -65.0], [$c['revenue'], $c['cost'], $c['expenses'], $c['net']]);
        $this->assertSame($c['net'], round($f['net'] + $b['net'] + $cc['net'] - $pnl['elimination'], 2));
        $this->assertTrue($pnl['reconciles']);
        $this->assertSame([], $f['provisional']);
    }

    public function test_open_month_absorption_is_provisional_from_pools(): void
    {
        $this->expense($this->line, 70);   // حوض الخط 70 والمحمّل صفر — الشهر مفتوح
        $pnl = ProfitLoss::build($this->aug());
        $this->assertSame(70.0, $pnl['factory']['expenses']);
        $this->assertSame(['2026-08'], $pnl['factory']['provisional']);
        $this->assertSame(0.0, $pnl['company_center']['expenses'], 'مصروف الخط في الحوض مش في مركز الشركة');

        // نص شهر: آخر يوم مش جوه الفترة = مفيش شهور
        $half = ProfitLoss::build(DateRange::fromRequest(Request::create('/', 'GET', ['from' => '2026-08-01', 'to' => '2026-08-15'])));
        $this->assertSame([], $half['months']);
        $this->assertSame(0.0, $half['factory']['expenses']);
        $this->assertSame(['2026-07', '2026-08'], ProfitLoss::months(Carbon::parse('2026-07-15'), Carbon::parse('2026-08-31')->endOfDay()));
    }

    public function test_branch_cost_is_transfer_price_company_cost_is_real(): void
    {
        $withTp = $this->makeProduct(['kind' => 'finished', 'cost' => 10]);
        $plain = $this->makeProduct(['kind' => 'finished', 'cost' => 4]);
        $this->tp($withTp, 15);
        $sale = $this->sale([[$withTp, 2, 10, 20], [$plain, 1, 4, 20]]);
        $this->returnOf($sale, 1);                           // مرتجع سليم لصنف سعر التحويل
        $this->returnOf($sale, 1, 20, 'damaged');            // تالف: الإيراد بيرجع والتكلفة بتفضل

        $pnl = ProfitLoss::build($this->aug());
        $b = $this->branch($pnl, $this->maadi);
        // إيراد 60 − 20 − 20 = 20 · تكلفة الفرع: 30 + 4 − 15 = 19 · الحقيقية: 20 + 4 − 10 = 14
        $this->assertSame(20.0, $b['revenue']);
        $this->assertSame(19.0, $b['cost']);
        $this->assertSame(14.0, $pnl['company']['cost']);
        $this->assertSame(-5.0, $pnl['elimination'], 'مفيش تحويل في الفترة — هامش البيع كله استبعاد سالب');
        $this->assertTrue($pnl['reconciles']);
        $prod = collect($b['children'])->flatMap(fn ($f) => $f['children'])->firstWhere('key', 'product:'.$withTp->id);
        $this->assertSame(15.0, $prod['cost']);
    }

    public function test_branch_expenses_allocated_to_products_by_sales_share(): void
    {
        $a = $this->makeProduct(['kind' => 'finished', 'family' => 'fa']);
        $b = $this->makeProduct(['kind' => 'finished', 'family' => 'fb']);
        $this->sale([[$a, 3, 5, 25], [$b, 1, 5, 25]]);        // 75 / 25
        $this->expense($this->maadi, 400);

        $pnl = ProfitLoss::build($this->aug());
        $br = $this->branch($pnl, $this->maadi);
        $products = collect($br['children'])->flatMap(fn ($f) => $f['children'])->keyBy('key');
        $this->assertSame(300.0, $products['product:'.$a->id]['expenses']);
        $this->assertSame(100.0, $products['product:'.$b->id]['expenses']);
        $this->assertSame(400.0, round(collect($br['children'])->sum('expenses'), 2), 'العائلات = مصروفات الفرع');

        // الكمية كأساس: 3 / 1 نفس النسبة؛ والقروش على أكبر نصيب
        $this->assertSame([1 => 33.34, 2 => 33.33, 3 => 33.33], ProfitLoss::allocate(100, [1 => 1, 2 => 1, 3 => 1]));
        $this->assertSame([1 => 100.0], ProfitLoss::allocate(100, [1 => 5, 2 => 0, 3 => -2]), 'أساس ≤ ٠ = صفر');
        Setting::write('cost_branch_alloc', 'qty');
        Setting::flushCache();
        $q = collect($this->branch(ProfitLoss::build($this->aug()), $this->maadi)['children'])->flatMap(fn ($f) => $f['children'])->keyBy('key');
        $this->assertSame(300.0, $q['product:'.$a->id]['expenses']);
    }

    public function test_alt_method_column_and_switch(): void
    {
        $p = $this->makeProduct(['cost' => 5]);
        $gr = GoodsReceipt::create(['number' => 'GRN-'.++$this->n, 'warehouse_id' => $this->makeWarehouse()->id,
            'received_on' => '2026-08-02', 'status' => 'posted']);
        foreach ([[10, 8], [30, 12]] as [$q, $cost]) {
            Batch::create(['product_id' => $p->id, 'warehouse_id' => $gr->warehouse_id, 'goods_receipt_id' => $gr->id, 'batch_no' => 'B-'.++$this->n,
                'expires_on' => '2027-06-01', 'qty_received' => $q, 'qty_remaining' => $q, 'cost' => $cost]);
        }
        Valuation::flush();
        $this->sale([[$p, 4, 8, 20]]);                        // باتش 32، متوسط 44

        $batch = ProfitLoss::build($this->aug(), 'batch');
        $this->assertSame([32.0, 44.0], [$batch['company']['cost'], $batch['company']['cost_alt']]);
        $this->assertSame('wavg', $batch['alt']);
        $wavg = ProfitLoss::build($this->aug(), 'wavg');
        $this->assertSame([44.0, 32.0], [$wavg['company']['cost'], $wavg['company']['cost_alt']]);
        $this->assertSame(44.0, $this->branch($wavg, $this->maadi)['cost']);

        Setting::write('cost_method', 'wavg');
        Setting::flushCache();
        $this->assertSame(44.0, ProfitLoss::build($this->aug())['company']['cost'], 'الافتراضي = الرسمية');
        $this->assertSame(32.0, ProfitLoss::build($this->aug(), 'bogus')['company']['cost_alt']);

        $acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.uniqid()]);
        $this->actingAs($acc)->get(route('costing.pnl', ['from' => '2026-08-01', 'to' => '2026-08-31', 'method' => 'batch']))
            ->assertOk()->assertSee('32.00')->assertSee('44.00')->assertSee(__('costing.col_cost_alt_m', ['m' => __('costing.method_wavg')]));
    }

    public function test_zero_cost_and_missing_transfer_price_warnings(): void
    {
        $this->sale([[$this->makeProduct(['cost' => 0]), 2, 0], [$this->makeProduct(), 1, 10]]);
        $p = $this->makeProduct(['kind' => 'finished', 'cost' => 10]);
        $this->transfer($p, 5, 10, null);                    // من غير سعر تحويل: إيراد = R، مفيش هامش

        $pnl = ProfitLoss::build($this->aug(), 'wavg');
        $this->assertSame(1, $pnl['warnings']['zero_cost']);
        $this->assertSame(1, $pnl['warnings']['no_transfer_price']);
        $this->assertSame(0.0, $pnl['factory']['gross'], 'سطر من غير سعر تحويل مالوش هامش حتى تحت المتوسط');
        $this->assertSame(0.0, $pnl['elimination']);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('costing.pnl', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk()
            ->assertSee(__('costing.warn_zero_cost', ['n' => 1]))->assertSee(__('costing.warn_no_tp', ['n' => 1]));
    }

    public function test_revenue_matches_sales_source_for_the_range(): void
    {
        $p = $this->makeProduct(['cost' => 6]);
        $s1 = $this->sale([[$p, 10, 6, 20]]);
        $this->sale([[$p, 3, 6, 30], [$this->makeProduct(), 2, 1, 7]], '2026-08-25');
        $this->returnOf($s1, 2);
        $this->returnOf($s1, 1, 20, 'damaged');
        $this->sale([[$p, 5, 6]], '2026-09-02');             // بره الفترة

        $a = Carbon::parse('2026-08-01');
        $b = Carbon::parse('2026-08-31');
        $ledger = (float) SalesSource::docs($a, $b)->sum('total') - (float) SalesSource::returns($a, $b)->sum('amount');
        $pnl = ProfitLoss::build($this->aug());
        $this->assertSame(round($ledger, 2), $pnl['company']['revenue']);
        $this->assertSame(244.0, $pnl['company']['revenue']);
        $this->assertSame(0.0, $pnl['warnings']['lines_without_items']);

        // قيد بيع يدوي بلا بنود = الفرق في التحذير
        Transaction::create(['client_id' => $this->makeClient()->id, 'date' => '2026-08-11', 'memo' => 'OPEN', 'debit' => 55,
            'credit' => 0, 'tax' => 0, 'kind' => 'sale']);
        $this->assertSame(55.0, ProfitLoss::build($this->aug())['warnings']['lines_without_items']);
    }

    public function test_export_and_access(): void
    {
        $p = $this->makeProduct(['kind' => 'finished', 'family' => 'fx']);
        $this->sale([[$p, 1, 5, 20]], today()->toDateString());
        $acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.uniqid()]);
        $this->actingAs($acc)->get(route('costing.pnl'))->assertOk()->assertSee(__('costing.pnl_title'))->assertSee($p->displayName());

        $r = $this->actingAs($acc)->get(route('costing.pnl', ['export' => 1]));
        $r->assertOk();
        $this->assertStringContainsString('csv', (string) $r->headers->get('content-type'));
        $csv = $r->streamedContent();
        foreach (['company', 'factory', 'branch', 'family', 'product', 'company_center', 'elimination'] as $level) {
            $this->assertMatchesRegularExpression('/^'.$level.',/m', $csv);
        }
        $this->assertStringContainsString(__('costing.col_level'), $csv);

        $agent = $this->makeAdmin(['role' => 'sales_agent', 'code' => 'AG-'.uniqid()]);
        $this->actingAs($agent)->get(route('costing.pnl'))->assertForbidden();
        $this->assertTrue(\App\Support\Access::allows($acc, 'costing.pnl'));
    }
}
