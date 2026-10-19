<?php

namespace Tests\Feature\Purchasing;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\Product;
use App\Models\RawStockMove;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\RawStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RawStockTest extends TestCase
{
    use RefreshDatabase;

    private function raw(string $kind = 'raw'): Product
    {
        return $this->makeProduct(['kind' => $kind]);
    }

    private function batch(Warehouse $w, Product $p, int $qty, int $days, float $cost = 2.5, array $extra = []): Batch
    {
        return Batch::create(array_merge([
            'product_id' => $p->id,
            'warehouse_id' => $w->id,
            'batch_no' => 'B'.uniqid(),
            'expires_on' => now()->addDays($days)->toDateString(),
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'cost' => $cost,
        ], $extra));
    }

    private function forModel(): User
    {
        return $this->makeAdmin();
    }

    public function test_issue_takes_the_earliest_expiry_first(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->raw();
        $a = $this->batch($w, $p, 50, 60, 2.5);
        $b = $this->batch($w, $p, 40, 30, 3);
        $for = $this->forModel();

        $out = RawStock::issue($w, $p, 60, $for);

        $this->assertSame([
            ['batch_id' => $b->id, 'qty' => 40.0, 'cost' => 3.0],
            ['batch_id' => $a->id, 'qty' => 20.0, 'cost' => 2.5],
        ], $out);
        $this->assertSame(0, $b->fresh()->qty_remaining);
        $this->assertSame(40, $b->fresh()->qty_issued);
        $this->assertSame(30, $a->fresh()->qty_remaining);
        $this->assertSame(20, $a->fresh()->qty_issued);
        $this->assertSame(2, RawStockMove::where('for_type', $for->getMorphClass())->where('for_id', $for->id)->count());
        $this->assertSame(30, (int) Stock::where('warehouse_id', $w->id)->where('product_id', $p->id)->value('qty'));
    }

    public function test_expired_and_blocked_batches_are_skipped(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->raw('packaging');
        $this->batch($w, $p, 100, -1);
        $this->batch($w, $p, 100, 5, 2.5, ['blocked' => true]);
        $ok = $this->batch($w, $p, 10, 90);

        $out = RawStock::issue($w, $p, 10, $this->forModel());
        $this->assertSame($ok->id, $out[0]['batch_id']);

        $this->expectException(Rejected::class);
        RawStock::issue($w, $p, 1, $this->forModel());
    }

    public function test_issue_locks_batches_and_never_goes_negative(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->raw();
        $b = $this->batch($w, $p, 40, 30);
        $for = $this->forModel();

        RawStock::issue($w, $p, 30, $for);
        try {
            RawStock::issue($w, $p, 30, $for);
            $this->fail('second issue must be refused');
        } catch (Rejected $e) {
            $this->assertStringContainsString('10', $e->getMessage());
            $this->assertSame(__('stock.raw_not_enough', ['product' => $p->displayName(), 'need' => 30, 'available' => 10]), $e->getMessage());
        }

        $this->assertSame(10, $b->fresh()->qty_remaining);
        $this->assertSame(1, RawStockMove::count());
    }

    public function test_finished_goods_are_refused(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->makeProduct(['kind' => 'finished']);
        $this->batch($w, $p, 10, 30);

        try {
            RawStock::issue($w, $p, 1, $this->forModel());
            $this->fail('expected Rejected');
        } catch (Rejected $e) {
            $this->assertSame(__('stock.raw_kind_only'), $e->getMessage());
        }
    }

    public function test_fractional_qty_is_refused(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->raw();
        $this->batch($w, $p, 10, 30);

        try {
            RawStock::issue($w, $p, 2.5, $this->forModel());
            $this->fail('expected Rejected');
        } catch (Rejected $e) {
            $this->assertSame(__('stock.raw_qty_not_whole'), $e->getMessage());
        }
        $this->assertSame(0, RawStockMove::count());
    }

    public function test_zero_is_refused(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->raw();
        $this->batch($w, $p, 10, 30);

        $this->expectException(Rejected::class);
        RawStock::issue($w, $p, 0, $this->forModel());
    }

    public function test_shelved_part_is_taken_after_the_unshelved_part(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->raw();
        $b = $this->batch($w, $p, 50, 60);
        $loc = Location::create(['warehouse_id' => $w->id, 'code' => 'R-1-1', 'stand' => 1, 'level' => 1]);
        $this->assertNull(BatchLocation::putAway($b, $loc, 20));

        RawStock::issue($w, $p, 40, $this->forModel());

        $this->assertSame(10, $b->fresh()->qty_remaining);
        $this->assertSame(10, (int) BatchLocation::where('batch_id', $b->id)->sum('qty'));
        $this->assertSame(10, (int) Stock::where('warehouse_id', $w->id)->where('product_id', $p->id)->value('qty'));
        $this->assertGreaterThanOrEqual((int) BatchLocation::where('batch_id', $b->id)->sum('qty'), $b->fresh()->qty_remaining);
    }

    public function test_other_warehouse_batches_are_not_touched(): void
    {
        $w = $this->makeWarehouse();
        $other = $this->makeWarehouse();
        $p = $this->raw();
        $this->batch($w, $p, 10, 30);
        $o = $this->batch($other, $p, 100, 5);

        try {
            RawStock::issue($w, $p, 20, $this->forModel());
            $this->fail('expected Rejected');
        } catch (Rejected) {
        }
        RawStock::issue($w, $p, 10, $this->forModel());

        $this->assertSame(100, $o->fresh()->qty_remaining);
    }

    public function test_available_counts_only_usable_batches(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->raw();
        $this->batch($w, $p, 10, 30);
        $this->batch($w, $p, 7, 10);
        $this->batch($w, $p, 100, -3);
        $this->batch($w, $p, 100, 20, 1, ['blocked' => true]);
        $this->batch($this->makeWarehouse(), $p, 100, 20);

        $this->assertSame(17, RawStock::available($w, $p));
    }
}
