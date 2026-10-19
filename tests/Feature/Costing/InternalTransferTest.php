<?php

namespace Tests\Feature\Costing;

use App\Models\Batch;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\Gl\GlAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductTransferPrice;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostSync;
use App\Services\Costing\Valuation;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * البيع الداخلي مصنع ← فرع بسعر التحويل (الخط ٩ — المهمة ٤).
 */
class InternalTransferTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private CostCenter $fac;

    private CostCenter $maadi;

    private Warehouse $factoryWh;

    private Warehouse $maadiWh;

    private function world(): void
    {
        $this->seed(GlSeeder::class);
        CostingGlSeed::run();
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        Valuation::flush();

        $co = CostCenter::company();
        $this->fac = CostCenter::create(['code' => 'FAC', 'name' => 'المصنع', 'name_en' => 'Factory', 'kind' => 'factory', 'parent_id' => $co->id, 'active' => true]);
        $this->maadi = CostCenter::maadi();
        $this->factoryWh = $this->makeWarehouse();
        $this->factoryWh->update(['cost_center_id' => $this->fac->id]);
        $this->maadiWh = $this->makeWarehouse();
        $this->maadiWh->update(['cost_center_id' => $this->maadi->id]);
    }

    private function tp(Product $p, float $price, float $cost = 8): void
    {
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => $price, 'unit_cost' => $cost, 'effective_from' => '2026-08-01',
            'approved_by' => $this->makeAdmin()->id, 'approved_at' => now()]);
    }

    /** @param list<array{0:Product,1:int,2:?int,3:float}> $lines [product, sent, received, cost] */
    private function received(array $lines, ?Warehouse $from = null, ?Warehouse $to = null, string $status = 'received'): StockTransfer
    {
        $t = StockTransfer::create(['number' => 'TRF-'.(95000 + ++$this->n), 'kind' => 'wh_wh',
            'from_warehouse_id' => ($from ?? $this->factoryWh)->id, 'to_warehouse_id' => ($to ?? $this->maadiWh)->id,
            'status' => $status, 'sent_on' => '2026-08-10', 'received_on' => '2026-08-10']);
        foreach ($lines as $l) {
            StockTransferItem::create(['stock_transfer_id' => $t->id, 'product_id' => $l[0]->id, 'batch_no' => 'B-'.++$this->n,
                'qty_sent' => $l[1], 'qty_received' => $l[2], 'qty_short' => $l[1] - (int) $l[2], 'cost' => $l[3]]);
        }

        return $t;
    }

    private function entryFor(StockTransfer $t): ?CostEntry
    {
        return CostEntry::where('kind', 'transfer')->where('origin_type', $t->getMorphClass())->where('origin_id', $t->id)->first();
    }

    private function bal(string $key): float
    {
        return GlAccount::findKey($key)->balanceBetween(null, null);
    }

    private function settle(StockTransfer $t): ?CostEntry
    {
        CostSync::settleTransfer($t);

        return $this->entryFor($t);
    }

    public function test_factory_to_maadi_transfer_posts_internal_pair(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        $this->tp($p, 11);
        $e = $this->settle($this->received([[$p, 10, 10, 8]]));

        $this->assertNotNull($e);
        $this->assertSame(10.0, (float) $e->qty);
        $this->assertSame(0.0, (float) $e->revenue);
        $this->assertSame(80.0, $e->amount('batch'));
        $this->assertSame(110.0, (float) $e->amount_transfer);
        $this->assertSame($this->fac->id, $e->cost_center_id);
        $this->assertSame($this->maadi->id, $e->other_center_id);
        $this->assertFalse($e->hasFlag('no_transfer_price'));

        $gl = app(Ledger::class)->autoEntryFor($e);
        $this->assertNotNull($gl);
        $sales = $gl->lines->where('account_id', GlAccount::findKey('internal_sales')->id);
        $this->assertSame(110.0, round((float) $sales->sum('credit'), 2));
        $this->assertSame([$this->fac->id], $sales->pluck('cost_center_id')->unique()->values()->all());
        $cogs = $gl->lines->firstWhere('account_id', GlAccount::findKey('internal_cogs')->id);
        $this->assertSame(80.0, (float) $cogs->debit);
        $this->assertSame($this->fac->id, $cogs->cost_center_id);
        $elim = $gl->lines->firstWhere('account_id', GlAccount::findKey('internal_elim')->id);
        $this->assertSame(30.0, (float) $elim->debit);
        $this->assertSame(CostCenter::company()->id, $elim->cost_center_id);
        $this->assertSame(0.0, $this->bal('inventory'), 'التحويل مابيلمسش المخزون في طبقة التكلفة');
    }

    public function test_raw_or_branch_to_branch_transfer_is_not_internal(): void
    {
        $this->world();
        $fin = $this->makeProduct(['kind' => 'finished']);
        $raw = $this->makeProduct(['kind' => 'raw']);
        $this->tp($fin, 11);
        $this->tp($raw, 11);

        // خام من المصنع للفرع = مفيش سطر مؤهّل
        $t1 = $this->received([[$raw, 5, 5, 8]]);
        $this->assertNull($this->settle($t1));

        // فرع لفرع
        $other = $this->makeWarehouse();
        $other->update(['cost_center_id' => CostCenter::create(['code' => 'BR2', 'name' => 'فرع', 'name_en' => 'Branch 2', 'kind' => 'branch',
            'parent_id' => CostCenter::company()->id, 'active' => true])->id]);
        $t2 = $this->received([[$fin, 5, 5, 8]], $this->maadiWh, $other);
        $this->assertNull($this->settle($t2));

        // لسه مش مستلم
        $t3 = $this->received([[$fin, 5, 5, 8]], null, null, 'sent');
        $this->assertNull($this->settle($t3));

        // مخلوط: التام بس بيتحسب
        $t4 = $this->received([[$fin, 5, 5, 8], [$raw, 5, 5, 8]]);
        $e = $this->settle($t4);
        $this->assertCount(1, $e->lines);
        $this->assertSame(0, CostEntry::count() - 1);
    }

    public function test_missing_transfer_price_uses_cost_and_flags(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        $e = $this->settle($this->received([[$p, 10, 10, 8]]));

        $this->assertTrue($e->hasFlag('no_transfer_price'));
        $this->assertSame(80.0, (float) $e->amount_transfer);
        $this->assertNull($e->lines->first()->unit_transfer);

        $gl = app(Ledger::class)->autoEntryFor($e);
        $this->assertSame(80.0, round((float) $gl->lines->where('account_id', GlAccount::findKey('internal_sales')->id)->sum('credit'), 2));
        $this->assertNull($gl->lines->firstWhere('account_id', GlAccount::findKey('internal_elim')->id), 'مفيش هامش');
    }

    public function test_company_pl_nets_internal_to_zero_after_branch_sells_everything(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        $this->tp($p, 11);
        $this->settle($this->received([[$p, 10, 10, 8]]));

        $client = $this->makeClient();
        $rep = $this->makeRep();
        $inv = Invoice::create(['number' => 'INV-95001', 'client_id' => $client->id, 'user_id' => $rep->id, 'payment' => 'credit',
            'subtotal' => 200, 'discount' => 0, 'total' => 200, 'tax_total' => 0, 'grand_total' => 200]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $p->id, 'qty' => 10, 'list_price' => 20, 'price' => 20,
            'unit_cost' => 8, 'total' => 200, 'tax_rate' => 0, 'tax' => 0]);
        Transaction::create(['client_id' => $client->id, 'date' => '2026-08-12', 'memo' => $inv->number, 'debit' => 200, 'credit' => 0,
            'tax' => 0, 'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);

        $this->assertSame(110.0, $this->bal('cogs'));
        $this->assertSame(0.0, $this->bal('internal_elim'), 'الاستبعاد: مدين 30 من التحويل ودائن 30 من بيع الفرع');
        $this->assertSame(80.0, $this->bal('internal_cogs'));
        $this->assertSame(110.0, $this->bal('internal_sales'));
        $company = $this->bal('cogs') + $this->bal('internal_cogs') + $this->bal('internal_elim') - $this->bal('internal_sales');
        $this->assertSame(80.0, round($company, 2), 'تكلفة الشركة = التكلفة الحقيقية');
    }

    public function test_receive_with_shortage_costs_received_qty_only(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished', 'cost' => 8]);
        $this->tp($p, 11);
        $admin = $this->makeAdmin();
        Batch::create(['product_id' => $p->id, 'warehouse_id' => $this->factoryWh->id, 'batch_no' => 'F-1', 'expires_on' => '2027-06-01',
            'qty_received' => 20, 'qty_remaining' => 20, 'cost' => 8]);
        $batch = Batch::where('batch_no', 'F-1')->first();

        $sent = StockTransfer::send($admin, $this->factoryWh->id, $this->maadiWh->id, '2026-08-10',
            [['product_id' => $p->id, 'source_batch_id' => $batch->id, 'qty' => 10]]);
        $this->assertNull($sent['error']);
        $t = $sent['transfer'];
        $this->assertNull($this->entryFor($t), 'لسه ماتستلمش');

        $item = $t->items()->first();
        $res = $t->receive($admin, [$item->id => 6]);
        $this->assertNull($res['error']);

        $e = $this->entryFor($t);
        $this->assertNotNull($e, 'الاستلام بيزامن ويرحّل');
        $this->assertSame(6.0, (float) $e->qty);
        $this->assertSame(48.0, $e->amount('batch'));
        $this->assertSame(66.0, (float) $e->amount_transfer);
        $this->assertNotNull(app(Ledger::class)->autoEntryFor($e));
        $this->assertSame(66.0, $this->bal('internal_sales'));
    }

    public function test_cancelled_transfer_drops_its_entry_and_rebuild_accepts_it(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        $this->tp($p, 11);
        $t = $this->received([[$p, 10, 10, 8]]);
        $e = $this->settle($t);
        $id = $e->id;
        $this->assertSame(110.0, $this->bal('internal_sales'));

        $t->update(['status' => 'cancelled']);
        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'));

        $this->assertTrue($report->ok, json_encode($report->invariants));
        $this->assertNull(CostEntry::find($id));
        $this->assertSame(0.0, $this->bal('internal_sales'));
    }

    public function test_rebuild_keeps_transfer_entry_ids_and_is_idempotent(): void
    {
        $this->world();
        $p = $this->makeProduct(['kind' => 'finished']);
        $this->tp($p, 11);
        $e = $this->settle($this->received([[$p, 10, 10, 8]]));

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'));
        $this->assertTrue($report->ok, json_encode($report->invariants));
        $this->assertSame([$e->id], CostEntry::pluck('id')->all());
        $this->assertSame(110.0, $this->bal('internal_sales'));

        $again = CostSync::run(Carbon::parse('2026-08-01'), true);
        $this->assertSame(1, $again['synced']);
        $this->assertSame([$e->id], CostEntry::pluck('id')->all());
    }
}
