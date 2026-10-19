<?php

namespace Tests\Feature\Mfg;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionIssue;
use App\Models\ProductionOrder;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mfg\Issues;
use App\Services\Mfg\Orders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صرف الخامات لأمر الإنتاج — صرف معياري، صرف زيادة، مرتجع للمخزن
 * (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦، Review Focus 1 و2).
 */
class IssueTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private Product $oats;

    private Product $box;

    private Warehouse $raw;

    private ProductionOrder $order;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = $this->makeProduct(['kind' => 'finished']);
        $this->oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $this->box = $this->makeProduct(['kind' => 'packaging']);
        $bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 5]);
        // 0.035 × 1000 × 1.05 = 36.750 كجم ← المعياري بيتصرف 37 (D2)
        $bom->lines()->create(['product_id' => $this->oats->id, 'qty' => 0.035, 'basis' => 'per_unit', 'sort' => 1]);
        // 2 × 1.05 = 2.100 ← 3
        $bom->lines()->create(['product_id' => $this->box->id, 'qty' => 2, 'basis' => 'per_batch', 'sort' => 2]);
        $line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'active' => true]);
        $this->raw = $this->makeWarehouse();
        $this->keeper = $this->makeAdmin(['role' => 'warehouse_keeper']);

        $this->order = Orders::create([
            'product_id' => $this->fg->id, 'line_id' => $line->id, 'planned_qty' => 1000,
            'batch_no' => 'B-'.uniqid(), 'planned_on' => today()->toDateString(),
            'warehouse_id' => $this->raw->id, 'raw_warehouse_id' => $this->raw->id,
        ], $this->makeAdmin());
    }

    private function stock(Product $p, int $qty, float $cost, \DateTimeInterface $expires): Batch
    {
        $b = Batch::create([
            'product_id' => $p->id, 'warehouse_id' => $this->raw->id, 'batch_no' => 'R-'.uniqid(),
            'produced_on' => today()->subMonth(), 'expires_on' => $expires,
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => $cost,
        ]);
        $loc = Location::create(['warehouse_id' => $this->raw->id, 'code' => 'S-'.$b->id, 'stand' => 'S', 'level' => 1, 'active' => true, 'capacity' => 1000]);
        BatchLocation::create(['batch_id' => $b->id, 'location_id' => $loc->id, 'product_id' => $p->id, 'qty' => $qty]);

        return $b;
    }

    /** @return array{0: Batch, 1: Batch} */
    private function oatsLots(): array
    {
        return [
            $this->stock($this->oats, 20, 10, today()->addMonths(2)),
            $this->stock($this->oats, 30, 12, today()->addMonths(6)),
        ];
    }

    private function shelved(Batch $b): int
    {
        return (int) BatchLocation::where('batch_id', $b->id)->sum('qty');
    }

    private function stockQty(Product $p): int
    {
        return (int) Stock::where('product_id', $p->id)->where('warehouse_id', $this->raw->id)->value('qty');
    }

    public function test_standard_issue_draws_fefo_and_records_cost(): void
    {
        [$sooner, $later] = $this->oatsLots();
        $boxes = $this->stock($this->box, 10, 1.5, today()->addYear());

        $this->actingAs($this->keeper)->post(route('mfg.orders.issue_standard', $this->order))
            ->assertSessionHasNoErrors()->assertSessionHas('ok');

        $rows = ProductionIssue::where('production_order_id', $this->order->id)->orderBy('id')->get();
        $this->assertCount(3, $rows);

        // FEFO: الأقرب انتهاءً الأول، بتكلفة الوحدة بتاعة كل باتش
        $this->assertSame([$sooner->id, 'issue', 20.0, 10.0, 200.0], [$rows[0]->batch_id, $rows[0]->kind, (float) $rows[0]->qty, (float) $rows[0]->unit_cost, (float) $rows[0]->value]);
        $this->assertSame([$later->id, 'issue', 17.0, 12.0, 204.0], [$rows[1]->batch_id, $rows[1]->kind, (float) $rows[1]->qty, (float) $rows[1]->unit_cost, (float) $rows[1]->value]);
        $this->assertSame([$boxes->id, 3.0, 4.5], [$rows[2]->batch_id, (float) $rows[2]->qty, (float) $rows[2]->value]);
        $this->assertSame($this->keeper->id, $rows[0]->user_id);

        $this->assertSame(0, (int) $sooner->fresh()->qty_remaining);
        $this->assertSame(13, (int) $later->fresh()->qty_remaining);
        $this->assertSame(13, $this->shelved($later));
        $this->assertSame(13, $this->stockQty($this->oats));
        $this->assertSame(7, (int) $boxes->fresh()->qty_remaining);

        $o = $this->order->fresh();
        $this->assertEqualsWithDelta(37.0, $o->netIssued($this->oats->id), 0.0001);
        $this->assertSame([$sooner->id => 20.0, $later->id => 17.0], $o->netIssuedByBatch($this->oats->id));
        $this->assertEqualsWithDelta(408.5, $o->materialCost(), 0.001);

        // المعياري اتصرف كله — مرة تانية مفيش حاجة تتصرف
        $this->assertSame(0, Issues::issueStandard($o, $this->keeper));

        $this->actingAs($this->keeper)->get(route('mfg.orders.show', $this->order))
            ->assertOk()->assertSee($sooner->batch_no)->assertSee('204.00');
    }

    public function test_extra_issue_is_kind_extra(): void
    {
        $this->oatsLots();

        $this->actingAs($this->keeper)->post(route('mfg.orders.issue', $this->order), [
            'material_id' => $this->oats->id, 'qty' => '2', 'kind' => 'extra', 'note' => 'spill',
        ])->assertSessionHasNoErrors();

        $row = ProductionIssue::where('production_order_id', $this->order->id)->sole();
        $this->assertSame('extra', $row->kind);
        $this->assertSame('spill', $row->note);
        $this->assertEqualsWithDelta(2.0, (float) $row->qty, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $row->value, 0.001);
    }

    public function test_issue_beyond_stock_moves_nothing(): void
    {
        [$sooner, $later] = $this->oatsLots();

        $this->actingAs($this->keeper)->post(route('mfg.orders.issue', $this->order), [
            'material_id' => $this->oats->id, 'qty' => '51', 'kind' => 'issue',
        ])->assertSessionHasErrors('issue');

        $this->assertSame(0, ProductionIssue::count());
        $this->assertSame(20, (int) $sooner->fresh()->qty_remaining);
        $this->assertSame(30, (int) $later->fresh()->qty_remaining);
        $this->assertSame(20, $this->shelved($sooner));

        // صرف المعياري: الخامة متاحة بس العلبة مالهاش رصيد ← ولا سطر يتصرف
        try {
            Issues::issueStandard($this->order, $this->keeper);
            $this->fail('standard issue should be refused when one line is short');
        } catch (Rejected $e) {
            $this->assertStringContainsString($this->box->displayName(), $e->getMessage());
        }

        $this->assertSame(0, ProductionIssue::count());
        $this->assertSame(20, (int) $sooner->fresh()->qty_remaining);
        $this->assertSame(30, (int) $later->fresh()->qty_remaining);
    }

    public function test_material_not_in_bom_is_refused(): void
    {
        $other = $this->makeProduct(['kind' => 'raw']);
        $this->stock($other, 10, 5, today()->addYear());

        $this->actingAs($this->keeper)->post(route('mfg.orders.issue', $this->order), [
            'material_id' => $other->id, 'qty' => '1', 'kind' => 'issue',
        ])->assertSessionHasErrors(['issue' => __('mfg.not_in_bom')]);

        $this->expectException(Rejected::class);
        Issues::returnToStore($this->order, $other, 1, $this->keeper);
    }

    public function test_return_goes_back_to_the_drawn_batch_and_shelf(): void
    {
        [$sooner, $later] = $this->oatsLots();

        Issues::issue($this->order, $this->oats, 25, $this->keeper);
        // الرف بتاع الأقرب اتشال لما فضي (BatchLocation::take)
        $this->assertSame(0, (int) $sooner->fresh()->qty_remaining);
        $this->assertSame(25, (int) $later->fresh()->qty_remaining);
        $this->assertSame(25, $this->stockQty($this->oats));

        $this->actingAs($this->keeper)->post(route('mfg.orders.return', $this->order), [
            'material_id' => $this->oats->id, 'qty' => '8', 'note' => 'left over',
        ])->assertSessionHasNoErrors();

        // الأحدث صرفاً الأول: 5 للباتش الأبعد (اتسحب منه آخر حاجة)، و3 للأقرب
        $returns = ProductionIssue::where('kind', 'return')->orderBy('id')->get();
        $this->assertCount(2, $returns);
        $this->assertSame([$later->id, 5.0, 12.0, 60.0], [$returns[0]->batch_id, (float) $returns[0]->qty, (float) $returns[0]->unit_cost, (float) $returns[0]->value]);
        $this->assertSame([$sooner->id, 3.0, 10.0, 30.0], [$returns[1]->batch_id, (float) $returns[1]->qty, (float) $returns[1]->unit_cost, (float) $returns[1]->value]);

        foreach ([[$sooner, 3, 17], [$later, 30, 0]] as [$b, $remaining, $issued]) {
            $f = $b->fresh();
            $this->assertSame($remaining, (int) $f->qty_remaining);
            $this->assertSame($issued, (int) $f->qty_issued);
            $this->assertSame($remaining, $this->shelved($b));
        }
        $this->assertSame(33, $this->stockQty($this->oats));

        $o = $this->order->fresh();
        $this->assertEqualsWithDelta(17.0, $o->netIssued($this->oats->id), 0.0001);
        $this->assertSame([$sooner->id => 17.0], $o->netIssuedByBatch($this->oats->id));
        $this->assertEqualsWithDelta(170.0, $o->materialCost(), 0.001);
    }

    public function test_return_more_than_held_is_refused(): void
    {
        [$sooner, $later] = $this->oatsLots();
        Issues::issue($this->order, $this->oats, 5, $this->keeper);

        $this->actingAs($this->keeper)->post(route('mfg.orders.return', $this->order), [
            'material_id' => $this->oats->id, 'qty' => '6',
        ])->assertSessionHasErrors(['issue' => __('mfg.return_exceeds', ['held' => '5'])]);

        // كسر = مرفوض لحد ما الخط ٥ ينزّل الوحدات (D2)
        try {
            Issues::returnToStore($this->order, $this->oats, 1.5, $this->keeper);
            $this->fail('fractional return should be refused');
        } catch (Rejected $e) {
            $this->assertSame(__('mfg.raw_whole_units'), $e->getMessage());
        }

        $this->assertSame(0, ProductionIssue::where('kind', 'return')->count());
        $this->assertSame(15, (int) $sooner->fresh()->qty_remaining);
        $this->assertSame(30, (int) $later->fresh()->qty_remaining);
        $this->assertSame(15, $this->shelved($sooner));
    }

    public function test_closed_order_cannot_issue(): void
    {
        [$sooner] = $this->oatsLots();
        $this->order->forceFill(['status' => 'closed'])->save();

        foreach ([
            fn () => Issues::issue($this->order, $this->oats, 1, $this->keeper),
            fn () => Issues::issueStandard($this->order, $this->keeper),
            fn () => Issues::returnToStore($this->order, $this->oats, 1, $this->keeper),
        ] as $call) {
            try {
                $call();
                $this->fail('a closed order should refuse issues');
            } catch (Rejected $e) {
                $this->assertSame(__('mfg.order_bad_state'), $e->getMessage());
            }
        }

        $this->assertSame(20, (int) $sooner->fresh()->qty_remaining);
        $this->actingAs($this->keeper)->get(route('mfg.orders.show', $this->order))
            ->assertOk()->assertDontSee(route('mfg.orders.issue_standard', $this->order));
    }

    public function test_keeper_can_issue_quality_cannot(): void
    {
        $this->oatsLots();
        $quality = $this->makeAdmin(['role' => 'quality']);
        $data = ['material_id' => $this->oats->id, 'qty' => '1', 'kind' => 'issue'];

        $this->actingAs($quality)->post(route('mfg.orders.issue', $this->order), $data)->assertForbidden();
        $this->actingAs($quality)->post(route('mfg.orders.issue_standard', $this->order))->assertForbidden();
        $this->actingAs($quality)->post(route('mfg.orders.return', $this->order), $data)->assertForbidden();
        $this->actingAs($quality)->get(route('mfg.orders.show', $this->order))
            ->assertOk()->assertDontSee(route('mfg.orders.issue', $this->order));

        $this->actingAs($this->keeper)->get(route('mfg.orders.show', $this->order))
            ->assertOk()->assertSee(route('mfg.orders.issue', $this->order));
        $this->actingAs($this->keeper)->post(route('mfg.orders.issue', $this->order), $data)->assertSessionHasNoErrors();
        $this->actingAs($this->makeAdmin(['role' => 'production_manager']))
            ->post(route('mfg.orders.issue', $this->order), $data)->assertSessionHasNoErrors();

        $this->assertSame(2, ProductionIssue::count());
    }
}
