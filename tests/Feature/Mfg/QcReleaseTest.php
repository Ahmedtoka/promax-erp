<?php

namespace Tests\Feature\Mfg;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSpec;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mfg\Closing;
use App\Services\Mfg\Issues;
use App\Services\Mfg\Orders;
use App\Services\Mfg\Qc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار الجودة على باتش التام (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦، D7، Review Focus 4):
 * الباتش في الحجر مش متاح للبيع/التجهيز لحد الإفراج، والمرفوض يفضل موقوف.
 */
class QcReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private Warehouse $fgWh;

    private User $quality;

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = $this->makeProduct(['kind' => 'finished']);
        $oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
        $bom->lines()->create(['product_id' => $oats->id, 'qty' => 0.04, 'basis' => 'per_unit', 'sort' => 1]);
        $line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'active' => true]);
        $raw = $this->makeWarehouse();
        $this->fgWh = $this->makeWarehouse();
        $this->fgWh->forceFill(['type' => Warehouse::TYPE_FACTORY])->save();
        $this->quality = $this->makeAdmin(['role' => 'quality']);

        ProductSpec::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved',
            'shelf_life_days' => 180, 'net_weight_g' => 400,
            'qc_limits' => [['param' => 'moisture', 'min' => 2, 'max' => 5, 'unit' => '%']]]);

        Batch::create(['product_id' => $oats->id, 'warehouse_id' => $raw->id, 'batch_no' => 'R-1',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 100, 'qty_remaining' => 100, 'cost' => 20]);

        $pm = $this->makeAdmin(['role' => 'production_manager']);
        $o = Orders::create([
            'product_id' => $this->fg->id, 'line_id' => $line->id, 'planned_qty' => 500,
            'batch_no' => 'MB-'.uniqid(), 'planned_on' => today()->toDateString(),
            'warehouse_id' => $this->fgWh->id, 'raw_warehouse_id' => $raw->id,
        ], $pm);
        Orders::start($o, $pm);
        Issues::issue($o, $oats, 20, $pm);
        Orders::finish($o, $pm, 500);
        $this->order = Closing::close($o->fresh(), 500, $pm);
    }

    public function test_quarantined_batch_is_not_available_until_released(): void
    {
        $this->assertSame(0, $this->fgWh->availableFor($this->fg->id));
        $this->assertSame(0, $this->fg->availableQty());

        $this->actingAs($this->quality)->post(route('mfg.qc.decide', $this->order), [
            'decision' => 'released', 'note' => 'كله تمام', 'results' => ['0' => '3.4'],
        ])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $o = $this->order->fresh();
        $this->assertSame('released', $o->qc_status);
        $this->assertSame(['moisture' => 3.4], $o->qc_results);
        $this->assertSame($this->quality->id, $o->qc_by);
        $this->assertNotNull($o->qc_at);
        $this->assertFalse($o->fgBatch->blocked);
        $this->assertSame(500, $this->fgWh->availableFor($this->fg->id));

        // القرار مرة واحدة بس
        $this->expectException(Rejected::class);
        $this->expectExceptionMessage(__('mfg.qc_bad_state'));
        Qc::decide($o, 'rejected', 'late', $this->quality);
    }

    public function test_reject_keeps_batch_blocked_with_note(): void
    {
        Qc::decide($this->order, 'rejected', 'رطوبة عالية', $this->quality, ['moisture' => '7.1', 'other' => '1']);

        $o = $this->order->fresh();
        $this->assertSame('rejected', $o->qc_status);
        $this->assertSame('رطوبة عالية', $o->qc_note);
        $this->assertSame(['moisture' => 7.1], $o->qc_results);
        $b = $o->fgBatch;
        $this->assertTrue($b->blocked);
        $this->assertSame(500, (int) $b->qty_remaining);
        $this->assertStringContainsString('رطوبة عالية', $b->notes);
        $this->assertStringContainsString($o->number, $b->notes);
        $this->assertSame(0, $this->fgWh->availableFor($this->fg->id));
    }

    public function test_qc_needs_a_note(): void
    {
        $this->actingAs($this->quality)->post(route('mfg.qc.decide', $this->order), ['decision' => 'released', 'note' => ''])
            ->assertSessionHasErrors('note');

        try {
            Qc::decide($this->order, 'released', '   ', $this->quality);
            $this->fail('expected Rejected');
        } catch (Rejected $e) {
            $this->assertSame(__('mfg.qc_note_required'), $e->getMessage());
        }

        $this->assertSame('quarantine', $this->order->fresh()->qc_status);
        $this->assertTrue($this->order->fresh()->fgBatch->blocked);
    }

    public function test_production_manager_cannot_decide_qc(): void
    {
        $pm = $this->makeAdmin(['role' => 'production_manager']);

        $this->actingAs($pm)->post(route('mfg.qc.decide', $this->order), ['decision' => 'released', 'note' => 'ok'])
            ->assertForbidden();
        $this->assertSame('quarantine', $this->order->fresh()->qc_status);

        // بيشوف الشاشة من غير زرار القرار
        $this->actingAs($pm)->get(route('mfg.qc'))->assertOk()->assertSee($this->order->batch_no)
            ->assertDontSee(route('mfg.qc.decide', $this->order));
        $this->actingAs($this->quality)->get(route('mfg.qc'))->assertOk()
            ->assertSee(route('mfg.qc.decide', $this->order))->assertSee('moisture');
    }

    public function test_qc_list_export(): void
    {
        $res = $this->actingAs($this->quality)->get(route('mfg.qc', ['export' => 1, 'qc_status' => 'quarantine']));
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString($this->order->number, $csv);
        $this->assertStringContainsString($this->order->batch_no, $csv);

        $empty = $this->actingAs($this->quality)->get(route('mfg.qc', ['export' => 1, 'qc_status' => 'released']))->streamedContent();
        $this->assertStringNotContainsString($this->order->number, $empty);
    }
}
