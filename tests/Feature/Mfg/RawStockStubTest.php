<?php

namespace Tests\Feature\Mfg;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\RawStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * صرف الخامات (STUB الخط ٦ — الخط ٥ صاحب `RawStock` الحقيقي).
 * FEFO على باتشات الخامة في المخزن، والرفوف والرصيد بيمشوا مع الباتش.
 */
class RawStockStubTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    private Product $raw;

    private function batch(string $no, int $qty, \DateTimeInterface $expires, array $attrs = [], bool $shelve = true): Batch
    {
        $b = Batch::create(array_merge([
            'product_id' => $this->raw->id, 'warehouse_id' => $this->wh->id, 'batch_no' => $no,
            'produced_on' => today()->subMonth(), 'expires_on' => $expires,
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => 10,
        ], $attrs));

        if ($shelve) {
            $loc = Location::create(['warehouse_id' => $this->wh->id, 'code' => 'S-'.$no, 'stand' => 'S', 'level' => 1, 'active' => true]);
            BatchLocation::create(['batch_id' => $b->id, 'location_id' => $loc->id, 'product_id' => $this->raw->id, 'qty' => $qty]);
        }

        return $b;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->wh = $this->makeWarehouse();
        $this->raw = $this->makeProduct(['kind' => 'raw']);
    }

    private function issue(float $qty): array
    {
        return DB::transaction(fn () => RawStock::issue($this->wh, $this->raw, $qty, $this->raw));
    }

    public function test_issue_is_fefo_across_batches_and_shelves(): void
    {
        $later = $this->batch('L', 10, today()->addMonths(6), ['cost' => 12]);
        $sooner = $this->batch('S', 5, today()->addMonths(2), ['cost' => 10]);

        $lines = $this->issue(8);

        $this->assertSame([
            ['batch_id' => $sooner->id, 'qty' => 5.0, 'cost' => 10.0],
            ['batch_id' => $later->id, 'qty' => 3.0, 'cost' => 12.0],
        ], $lines);

        $this->assertSame(0, (int) $sooner->fresh()->qty_remaining);
        $this->assertSame(5, (int) $sooner->fresh()->qty_issued);
        $this->assertSame(7, (int) $later->fresh()->qty_remaining);

        foreach ([$sooner, $later] as $b) {
            $this->assertSame((int) $b->fresh()->qty_remaining,
                (int) BatchLocation::where('batch_id', $b->id)->sum('qty'));
        }

        $this->assertSame(7, (int) Stock::where('product_id', $this->raw->id)->where('warehouse_id', $this->wh->id)->value('qty'));
    }

    public function test_short_issue_moves_nothing(): void
    {
        $later = $this->batch('L', 10, today()->addMonths(6));
        $sooner = $this->batch('S', 5, today()->addMonths(2));

        try {
            $this->issue(16);
            $this->fail('الصرف أكتر من المتاح لازم يترفض');
        } catch (Rejected $e) {
            $this->assertStringContainsString('15', $e->getMessage());
        }

        $this->assertSame(10, (int) $later->fresh()->qty_remaining);
        $this->assertSame(5, (int) $sooner->fresh()->qty_remaining);
        $this->assertSame(15, (int) BatchLocation::where('product_id', $this->raw->id)->sum('qty'));
    }

    public function test_blocked_and_expired_batches_are_skipped(): void
    {
        $blocked = $this->batch('B', 10, today()->addMonths(1), ['blocked' => true]);
        $expired = $this->batch('X', 10, today()->subDay());
        $good = $this->batch('G', 4, today()->addMonths(6));

        $lines = $this->issue(4);
        $this->assertSame([['batch_id' => $good->id, 'qty' => 4.0, 'cost' => 10.0]], $lines);
        $this->assertSame(10, (int) $blocked->fresh()->qty_remaining);
        $this->assertSame(10, (int) $expired->fresh()->qty_remaining);

        $this->expectException(Rejected::class);
        $this->issue(1);
    }

    public function test_fractional_qty_is_refused(): void
    {
        $b = $this->batch('L', 10, today()->addMonths(6));

        foreach ([2.5, 0.0, -1.0] as $qty) {
            try {
                $this->issue($qty);
                $this->fail("الكمية {$qty} لازم تترفض");
            } catch (Rejected $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(10, (int) $b->fresh()->qty_remaining);
    }
}
