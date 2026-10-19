<?php

namespace Tests\Feature\Mfg;

use App\Models\Batch;
use App\Models\Bom;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mfg\Closing;
use App\Services\Mfg\Genealogy;
use App\Services\Mfg\Issues;
use App\Services\Mfg\Orders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * جينيالوجي اللوطات وشاشة السحب (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦، مهمة ٧):
 * باتش تام ← لوطات الخام، لوط خام ← الباتشات التامة، والعملاء اللي استلموا
 * (حتى بعد التحويل لمخزن تاني = توأم بنفس رقم الباتش).
 */
class GenealogyTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private Product $oats;

    private Product $box;

    private Warehouse $raw;

    private Warehouse $fgWh;

    private User $pm;

    private ProductionOrder $order;

    private Batch $oatsLot;

    private Batch $boxLot;

    private Batch $fgBatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = $this->makeProduct(['kind' => 'finished']);
        $this->oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $this->box = $this->makeProduct(['kind' => 'packaging']);
        $bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 5]);
        $bom->lines()->create(['product_id' => $this->oats->id, 'qty' => 0.035, 'basis' => 'per_unit', 'sort' => 1]);
        $bom->lines()->create(['product_id' => $this->box->id, 'qty' => 2, 'basis' => 'per_batch', 'sort' => 2]);
        $line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'active' => true]);
        $this->raw = $this->makeWarehouse();
        $this->fgWh = $this->makeWarehouse();
        $this->fgWh->forceFill(['type' => Warehouse::TYPE_FACTORY])->save();
        $this->pm = $this->makeAdmin(['role' => 'production_manager']);

        $this->oatsLot = $this->stock($this->oats, 100, 30, 'OAT-LOT-77');
        $this->boxLot = $this->stock($this->box, 10, 10, 'BOX-LOT-12');

        $this->order = Orders::create([
            'product_id' => $this->fg->id, 'line_id' => $line->id, 'planned_qty' => 1000,
            'batch_no' => 'FG-2610-A', 'planned_on' => today()->toDateString(),
            'warehouse_id' => $this->fgWh->id, 'raw_warehouse_id' => $this->raw->id,
        ], $this->pm);
        Orders::start($this->order, $this->pm);
        Issues::issue($this->order, $this->oats, 45, $this->pm);
        Issues::issue($this->order, $this->box, 3, $this->pm);
        Issues::returnToStore($this->order, $this->oats, 5, $this->pm);
        Orders::finish($this->order, $this->pm, 1000);
        $this->order = Closing::close($this->order->fresh(), 1000, $this->pm, Carbon::parse('2026-10-10'));
        $this->fgBatch = Batch::findOrFail($this->order->fg_batch_id);
    }

    private function stock(Product $p, int $qty, float $cost, string $no): Batch
    {
        return Batch::create([
            'product_id' => $p->id, 'warehouse_id' => $this->raw->id, 'batch_no' => $no,
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => $cost,
        ]);
    }

    /** نفس الباتش بعد التحويل للمعادي — صف تاني بنفس المنتج ورقم الباتش */
    private function twin(): Batch
    {
        $maadi = $this->makeWarehouse();

        return Batch::create([
            'product_id' => $this->fg->id, 'warehouse_id' => $maadi->id, 'batch_no' => $this->fgBatch->batch_no,
            'produced_on' => $this->fgBatch->produced_on, 'expires_on' => $this->fgBatch->expires_on,
            'qty_received' => 200, 'qty_remaining' => 200, 'cost' => $this->fgBatch->cost,
        ]);
    }

    private function invoice(Client $c, Batch $b, int $qty, string $at): Invoice
    {
        $rep = $this->makeRep();
        $inv = Invoice::create(['number' => 'INV-'.uniqid(), 'client_id' => $c->id, 'user_id' => $rep->id,
            'payment' => 'cash', 'subtotal' => $qty * 20, 'total' => $qty * 20]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $b->product_id, 'batch_id' => $b->id,
            'qty' => $qty, 'price' => 20, 'total' => $qty * 20]);
        $inv->forceFill(['created_at' => Carbon::parse($at)])->save();

        return $inv;
    }

    private function purchaseOrder(Client $c, Batch $b, int $qty, string $status): PurchaseOrder
    {
        $po = PurchaseOrder::create(['number' => 'PO-'.uniqid(), 'client_id' => $c->id, 'status' => $status,
            'delivered_at' => $status === 'delivered' ? Carbon::parse('2026-10-15 11:00') : null]);
        // زي اللايف: بند الأمر مالوش باتش — الباتش على أمر التجهيز اللي حمّل الأمر
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $po->id, 'product_id' => $b->product_id,
            'qty' => $qty, 'delivered_qty' => $qty, 'price' => 20, 'total' => $qty * 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pickId = DB::table('pick_orders')->insertGetId([
            'number' => 'PCK-'.uniqid(), 'warehouse_id' => $b->warehouse_id, 'assigned_to' => $this->makeRep()->id,
            'purpose' => 'customer_po', 'status' => 'handed', 'purchase_order_id' => $po->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pick_order_items')->insert([
            'pick_order_id' => $pickId, 'product_id' => $b->product_id, 'batch_id' => $b->id,
            'qty_requested' => $qty, 'qty_picked' => $qty, 'qty_received' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $po;
    }

    public function test_backward_finished_batch_to_raw_lots(): void
    {
        $lots = Genealogy::rawLotsFor($this->fgBatch)->keyBy(fn ($l) => $l['batch']->id);

        $this->assertCount(2, $lots);
        // ٤٥ − ٥ مرتجع = ٤٠ × ٣٠
        $this->assertEqualsWithDelta(40, $lots[$this->oatsLot->id]['qty'], 0.001);
        $this->assertEqualsWithDelta(1200, $lots[$this->oatsLot->id]['value'], 0.001);
        $this->assertSame($this->oats->id, $lots[$this->oatsLot->id]['product']->id);
        $this->assertEqualsWithDelta(3, $lots[$this->boxLot->id]['qty'], 0.001);
        $this->assertEqualsWithDelta(30, $lots[$this->boxLot->id]['value'], 0.001);

        // التوأم في مخزن تاني بيرجع لنفس اللوطات
        $this->assertCount(2, Genealogy::rawLotsFor($this->twin()));
    }

    public function test_forward_raw_lot_to_finished_batches(): void
    {
        $orders = Genealogy::finishedFor($this->oatsLot);

        $this->assertCount(1, $orders);
        $this->assertSame($this->order->id, $orders->first()->id);
        $this->assertSame($this->fgBatch->id, $orders->first()->fgBatch->id);
        $this->assertEqualsWithDelta(40, $orders->first()->lot_qty, 0.001);

        // لوط اتصرف واترجع كله = مش في الجينيالوجي
        $unused = $this->stock($this->oats, 5, 30, 'OAT-UNUSED');
        $this->assertCount(0, Genealogy::finishedFor($unused));
    }

    public function test_recall_lists_clients_that_received_the_batch_including_after_transfer(): void
    {
        $twin = $this->twin();
        $direct = $this->makeClient(['name' => 'عميل مباشر', 'name_en' => 'عميل مباشر']);
        $maadi = $this->makeClient(['name' => 'عميل المعادي', 'name_en' => 'عميل المعادي']);
        $chain = $this->makeClient(['name' => 'سلسلة', 'name_en' => 'سلسلة']);
        $notMine = $this->makeClient(['name' => 'باتش تاني', 'name_en' => 'باتش تاني']);

        $this->invoice($direct, $this->fgBatch, 5, '2026-10-12 10:00');
        $this->invoice($maadi, $twin, 7, '2026-10-14 10:00');
        $this->purchaseOrder($chain, $twin, 4, 'delivered');
        $this->purchaseOrder($chain, $twin, 9, 'pending');
        $other = Batch::create(['product_id' => $this->fg->id, 'warehouse_id' => $this->fgWh->id, 'batch_no' => 'FG-OTHER',
            'produced_on' => today(), 'expires_on' => today()->addYear(), 'qty_received' => 10, 'qty_remaining' => 10, 'cost' => 1]);
        $this->invoice($notMine, $other, 2, '2026-10-14 10:00');
        // عينة مجانية من التوأم لعميل
        $sampled = $this->makeClient(['name' => 'عينة', 'name_en' => 'عينة']);
        $sampleRep = $this->makeRep();
        $sampleCustody = Custody::create(['user_id' => $sampleRep->id, 'date' => today()->toDateString(), 'status' => 'closed']);
        \App\Models\GiftHandout::create(['custody_id' => $sampleCustody->id, 'user_id' => $sampleRep->id,
            'product_id' => $this->fg->id, 'client_id' => $sampled->id, 'batch_id' => $twin->id, 'qty' => 2]);

        $rec = Genealogy::recipientsFor($this->fgBatch);

        $this->assertCount(4, $rec);
        $this->assertSame('gift', $rec->firstWhere(fn ($r) => $r['client']->id === $sampled->id)['via']);
        $this->assertSame(2, $rec->firstWhere(fn ($r) => $r['client']->id === $sampled->id)['qty']);
        $byClient = $rec->keyBy(fn ($r) => $r['client']->id);
        $this->assertSame(5, (int) $byClient[$direct->id]['qty']);
        $this->assertSame('invoice', $byClient[$direct->id]['via']);
        $this->assertSame(7, (int) $byClient[$maadi->id]['qty']);
        $this->assertSame('sales_order', $byClient[$chain->id]['via']);
        $this->assertSame(4, (int) $byClient[$chain->id]['qty']);
        $this->assertSame('2026-10-15', $byClient[$chain->id]['date']->format('Y-m-d'));
        $this->assertFalse($byClient->has($notMine->id));

        // التوأم بيدّي نفس القايمة
        $this->assertCount(4, Genealogy::recipientsFor($twin));

        // في عربيات المناديب: عهدة مفتوحة فيها باقي من التوأم
        $rep = $this->makeRep();
        $custody = Custody::create(['user_id' => $rep->id, 'date' => today()->toDateString(), 'status' => 'open']);
        CustodyItem::create(['custody_id' => $custody->id, 'product_id' => $this->fg->id, 'batch_id' => $twin->id,
            'assigned' => 20, 'sold' => 6, 'returned' => 2, 'gift_assigned' => 3, 'gift_given' => 1]);
        $closed = Custody::create(['user_id' => $rep->id, 'date' => today()->subDay()->toDateString(), 'status' => 'closed']);
        CustodyItem::create(['custody_id' => $closed->id, 'product_id' => $this->fg->id, 'batch_id' => $twin->id,
            'assigned' => 50, 'sold' => 0, 'returned' => 0]);

        $vans = Genealogy::inVans($this->fgBatch);
        $this->assertCount(1, $vans);
        $this->assertSame($rep->id, $vans->first()['rep']->id);
        // ٢٠ − ٦ − ٢ = ١٢ للبيع + ٢ عينات لسه في العربية
        $this->assertSame(14, $vans->first()['qty']);
    }

    public function test_trace_screen_shows_clients_for_a_raw_lot(): void
    {
        $client = $this->makeClient(['name' => 'سوبر ماركت السحب', 'name_en' => 'سوبر ماركت السحب']);
        $this->invoice($client, $this->fgBatch, 5, '2026-10-12 10:00');
        $quality = $this->makeAdmin(['role' => 'quality']);

        $this->actingAs($quality)->get(route('mfg.trace', ['q' => 'OAT-LOT']))
            ->assertOk()
            ->assertSee('OAT-LOT-77')
            ->assertSee($this->order->number)
            ->assertSee('FG-2610-A')
            ->assertSee('سوبر ماركت السحب');

        // الباتش التام: لوطات الخام والعملاء
        $this->actingAs($quality)->get(route('mfg.trace', ['q' => 'FG-2610-A']))
            ->assertOk()->assertSee('OAT-LOT-77')->assertSee('BOX-LOT-12')->assertSee('سوبر ماركت السحب');

        // فلتر التاريخ على تاريخ الاستلام
        $this->actingAs($quality)->get(route('mfg.trace', ['q' => 'OAT-LOT-77', 'from' => '2026-10-13']))
            ->assertOk()->assertDontSee('سوبر ماركت السحب');

        $csv = $this->actingAs($quality)->get(route('mfg.trace', ['q' => 'OAT-LOT-77', 'export' => 1]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('سوبر ماركت السحب', $csv);
        $this->assertStringContainsString('FG-2610-A', $csv);

        // المخزن مالوش الشاشة
        $this->actingAs($this->makeAdmin(['role' => 'warehouse_keeper']))
            ->get(route('mfg.trace', ['q' => 'OAT-LOT-77']))->assertForbidden();
    }

    public function test_unknown_batch_shows_empty_state(): void
    {
        $this->actingAs($this->pm)->get(route('mfg.trace', ['q' => 'NOPE-404']))
            ->assertOk()->assertSee(__('mfg.trace_no_match', ['q' => 'NOPE-404']));

        $this->actingAs($this->pm)->get(route('mfg.trace'))
            ->assertOk()->assertSee(__('mfg.trace_hint'));
    }
}
