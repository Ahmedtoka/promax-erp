<?php

namespace Tests\Feature\Mfg;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSpec;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mfg\Closing;
use App\Services\Mfg\Issues;
use App\Services\Mfg\Orders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * قفل أمر الإنتاج: باتش تام موقوف في مخزن التام، التكلفة الفعلية،
 * الانحراف والهالك (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦، D6 + D9، Review Focus 3).
 */
class CloseOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private Product $oats;

    private Product $box;

    private Warehouse $raw;

    private Warehouse $fgWh;

    private User $pm;

    private ProductionOrder $order;

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

        $this->order = Orders::create([
            'product_id' => $this->fg->id, 'line_id' => $line->id, 'planned_qty' => 1000,
            'batch_no' => 'MB-'.uniqid(), 'planned_on' => today()->toDateString(),
            'warehouse_id' => $this->fgWh->id, 'raw_warehouse_id' => $this->raw->id,
        ], $this->pm);
    }

    private function stock(Product $p, int $qty, float $cost): Batch
    {
        return Batch::create([
            'product_id' => $p->id, 'warehouse_id' => $this->raw->id, 'batch_no' => 'R-'.uniqid(),
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => $cost,
        ]);
    }

    /** خامات ١٬٢٣٠: شوفان ٤٠ كجم × ٣٠ + علب ٣ × ١٠، والأمر انتهى */
    private function produce(): ProductionOrder
    {
        $this->stock($this->oats, 100, 30);
        $this->stock($this->box, 10, 10);
        Orders::start($this->order, $this->pm);
        Issues::issue($this->order, $this->oats, 40, $this->pm);
        Issues::issue($this->order, $this->box, 3, $this->pm);
        Orders::finish($this->order, $this->pm, 1000);

        return $this->order->fresh();
    }

    public function test_close_creates_blocked_batch_in_factory_with_spec_expiry(): void
    {
        ProductSpec::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved',
            'shelf_life_days' => 180, 'net_weight_g' => 400]);
        $quality = $this->makeAdmin(['role' => 'quality']);
        $o = $this->produce();

        $this->actingAs($this->pm)->post(route('mfg.orders.close', $o), [
            'output_qty' => 980, 'produced_on' => '2026-10-19',
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $o->refresh();
        $this->assertSame('closed', $o->status);
        $this->assertSame('quarantine', $o->qc_status);
        $this->assertSame(980, $o->output_qty);
        $this->assertSame('spec', $o->expiry_source);
        $this->assertSame($this->pm->id, $o->closed_by);
        $this->assertNotNull($o->closed_at);

        $b = Batch::findOrFail($o->fg_batch_id);
        $this->assertSame($this->fg->id, $b->product_id);
        $this->assertSame($this->fgWh->id, $b->warehouse_id);
        $this->assertSame($o->batch_no, $b->batch_no);
        $this->assertTrue($b->blocked);
        $this->assertSame(980, (int) $b->qty_received);
        $this->assertSame(980, (int) $b->qty_remaining);
        $this->assertSame('2026-10-19', $b->produced_on->format('Y-m-d'));
        $this->assertSame('2027-04-17', $b->expires_on->format('Y-m-d'));
        $this->assertSame('2027-04-17', $o->expires_on->format('Y-m-d'));
        $this->assertSame($o->number, $b->notes);
        $this->assertSame(980, (int) BatchLocation::where('batch_id', $b->id)->sum('qty'));
        $this->assertSame(980, (int) Stock::where('product_id', $this->fg->id)->where('warehouse_id', $this->fgWh->id)->value('qty'));

        // الجودة اتبلّغت بلينك شاشة الإفراج
        $note = AppNotification::where('user_id', $quality->id)->sole();
        $this->assertStringContainsString($o->batch_no, $note->title);
        $this->assertSame(route('mfg.qc', [], false), $note->link);

        // كارت التكلفة على صفحة الأمر
        $this->actingAs($this->pm)->get(route('mfg.orders.show', $o))->assertOk()
            ->assertSee(__('mfg.cost_card_title'))->assertSee('1,230.00')->assertSee(__('mfg.qc_status_quarantine'));
    }

    public function test_close_falls_back_to_product_shelf_life(): void
    {
        $o = $this->produce();
        $on = Carbon::parse('2026-10-19');

        Closing::close($o, 1000, $this->pm, $on);

        $o->refresh();
        $this->assertSame('product', $o->expiry_source);
        $this->assertSame($this->fg->expiryFrom($on)->format('Y-m-d'), $o->fgBatch->expires_on->format('Y-m-d'));
    }

    public function test_batch_cost_is_actual_materials_per_unit(): void
    {
        $o = Closing::close($this->produce(), 1000, $this->pm);

        $this->assertEqualsWithDelta(1230.00, (float) $o->material_cost, 0.001);
        $this->assertEqualsWithDelta(1230.00, (float) $o->total_cost, 0.001);
        $this->assertSame('1.2300', $o->fresh()->unit_cost);
        $this->assertSame('1.23', $o->fresh()->fgBatch->cost);
        $this->assertEqualsWithDelta(100.0, (float) $o->yield_pct, 0.001);
    }

    public function test_variance_per_material_qty_and_value(): void
    {
        $o = Closing::close($this->produce(), 1000, $this->pm)->fresh();

        $oats = $o->lines()->where('product_id', $this->oats->id)->sole();
        $this->assertEqualsWithDelta(35.000, (float) $oats->base_qty_actual, 0.0001);
        $this->assertEqualsWithDelta(36.750, (float) $oats->std_qty_actual, 0.0001);
        $this->assertEqualsWithDelta(40.000, (float) $oats->actual_qty, 0.0001);
        $this->assertEqualsWithDelta(1200.00, (float) $oats->actual_cost, 0.001);
        $this->assertEqualsWithDelta(1102.50, (float) $oats->std_cost, 0.001);   // 36.750 × متوسط ٣٠
        $this->assertEqualsWithDelta(3.250, (float) $oats->variance_qty, 0.0001);
        $this->assertEqualsWithDelta(97.50, (float) $oats->variance_value, 0.001);
        $this->assertEqualsWithDelta(12.50, (float) $oats->waste_pct, 0.001);    // (40 − 35) ÷ 40

        $box = $o->lines()->where('product_id', $this->box->id)->sole();
        $this->assertEqualsWithDelta(2.100, (float) $box->std_qty_actual, 0.0001);
        $this->assertEqualsWithDelta(21.00, (float) $box->std_cost, 0.001);
        $this->assertEqualsWithDelta(33.33, (float) $box->waste_pct, 0.001);

        $this->assertEqualsWithDelta(1123.50, (float) $o->std_material_cost, 0.001);
        // (1230 − 1070) ÷ 1230 على القيم
        $this->assertEqualsWithDelta(13.01, (float) $o->waste_pct, 0.001);
    }

    public function test_close_twice_is_refused(): void
    {
        $o = $this->produce();
        Closing::close($o, 1000, $this->pm);

        $this->actingAs($this->pm)->post(route('mfg.orders.close', $o), ['output_qty' => 1000, 'produced_on' => today()->toDateString()])
            ->assertSessionHasErrors('order');

        try {
            Closing::close($o->fresh(), 1000, $this->pm);
            $this->fail('expected Rejected');
        } catch (Rejected $e) {
            $this->assertSame(__('mfg.order_bad_state'), $e->getMessage());
        }

        $this->assertSame(1, Batch::where('product_id', $this->fg->id)->count());
    }

    public function test_close_without_materials_is_refused(): void
    {
        Orders::start($this->order, $this->pm);
        Orders::finish($this->order, $this->pm, 1000);

        $this->expectException(Rejected::class);
        $this->expectExceptionMessage(__('mfg.close_no_materials'));

        try {
            Closing::close($this->order->fresh(), 1000, $this->pm);
        } finally {
            $this->assertSame('finished', $this->order->fresh()->status);
            $this->assertSame(0, Batch::where('product_id', $this->fg->id)->count());
        }
    }

    public function test_close_refuses_batch_no_taken_by_another_batch(): void
    {
        $o = $this->produce();
        Batch::create(['product_id' => $this->fg->id, 'warehouse_id' => $this->fgWh->id, 'batch_no' => $o->batch_no,
            'produced_on' => today(), 'expires_on' => today()->addYear(), 'qty_received' => 1, 'qty_remaining' => 1, 'cost' => 1]);

        $this->expectException(Rejected::class);
        $this->expectExceptionMessage(__('mfg.batch_no_taken'));
        Closing::close($o, 1000, $this->pm);
    }

    public function test_only_order_managers_can_close(): void
    {
        $o = $this->produce();

        $this->actingAs($this->makeAdmin(['role' => 'quality']))->post(route('mfg.orders.close', $o), ['output_qty' => 1000, 'produced_on' => today()->toDateString()])
            ->assertForbidden();
        $this->actingAs($this->makeAdmin(['role' => 'warehouse_keeper']))->post(route('mfg.orders.close', $o), ['output_qty' => 1000, 'produced_on' => today()->toDateString()])
            ->assertForbidden();
        $this->assertSame('finished', $o->fresh()->status);

        // اللوحة على الصفحة للي يقدر بس
        $this->actingAs($this->pm)->get(route('mfg.orders.show', $o))->assertOk()->assertSee(route('mfg.orders.close', $o));
        $this->actingAs($this->makeAdmin(['role' => 'quality']))->get(route('mfg.orders.show', $o))->assertOk()
            ->assertDontSee(route('mfg.orders.close', $o));
    }
}
