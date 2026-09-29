<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الرف المخطط فضي وقت التجهيز — السحب من المتاح بالـFEFO (٢٩/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: «أوردر أونلاين بيقول البضاعة مش موجودة على الرف F01
 * وهي موجودة» — الباتش المخطط اتسحب في أمر توريد قبل ما الأوردر
 * يتجهز (التخطيط مابيحجزش).
 */
class PickReplanTest extends TestCase
{
    use RefreshDatabase;

    private User $keeper;
    private Warehouse $wh;
    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keeper = $this->makeAdmin();
        $this->wh = $this->makeWarehouse();
        $this->p = $this->makeProduct(['name_en' => 'Vanilla Cup']);
    }

    /** باتش على رف — الأقرب انتهاءً بيتخطط الأول */
    private function shelf(string $code, int $level, string $batchNo, int $months, int $qty): BatchLocation
    {
        $batch = Batch::create([
            'product_id' => $this->p->id, 'warehouse_id' => $this->wh->id, 'batch_no' => $batchNo,
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths($months),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => 10,
        ]);
        $loc = Location::create(['warehouse_id' => $this->wh->id, 'code' => $code, 'stand' => substr($code, 0, 1),
            'level' => $level, 'active' => true]);

        return BatchLocation::create(['batch_id' => $batch->id, 'location_id' => $loc->id,
            'product_id' => $this->p->id, 'qty' => $qty]);
    }

    private function raise(int $qty): PickOrder
    {
        $r = PickOrder::raise(warehouse: $this->wh, rep: $this->keeper, qtyByProduct: [$this->p->id => $qty],
            purpose: PickOrder::PURPOSE_ONLINE, requestedBy: $this->keeper);
        $this->assertNull($r['error']);

        return $r['order'];
    }

    /** أمر تاني بيسحب نفس الصف الأول — زي أمر التوريد في البلاغ */
    private function drain(BatchLocation $row, int $qty): void
    {
        $other = $this->raise($qty);
        $this->assertNull($other->startPicking($this->keeper));
        $this->assertNull($other->fresh()->markReady($this->keeper));
    }

    private function pick(PickOrder $o): ?string
    {
        $this->assertNull($o->startPicking($this->keeper));

        return $o->fresh()->markReady($this->keeper);
    }

    public function test_the_line_moves_to_another_batch_when_its_planned_shelf_was_emptied(): void
    {
        $f01 = $this->shelf('F01', 1, 'B-OLD', 3, 5);     // الأقرب انتهاءً — هيتخطط الأول
        $g01 = $this->shelf('G01', 1, 'B-NEW', 9, 20);

        $online = $this->raise(4);                          // مخطط على F01
        $this->assertSame($f01->location_id, (int) $online->items->first()->location_id);

        $this->drain($f01, 5);                              // أمر توريد سحب F01 كله

        $this->assertNull($this->pick($online), 'must pick from G01 instead of failing');

        $items = $online->fresh('items')->items;
        $this->assertCount(1, $items);
        $this->assertSame((int) $g01->location_id, (int) $items[0]->location_id);
        $this->assertSame((int) $g01->batch_id, (int) $items[0]->batch_id);
        $this->assertSame(4, (int) $items[0]->qty_picked);
        $this->assertSame(16, (int) $g01->fresh()->qty);
        $this->assertSame(16, (int) Batch::find($g01->batch_id)->qty_remaining);
    }

    public function test_the_line_splits_by_fefo_when_no_single_shelf_is_enough(): void
    {
        $f01 = $this->shelf('F01', 1, 'B-OLD', 3, 10);
        $g01 = $this->shelf('G01', 1, 'B-MID', 6, 3);
        $h01 = $this->shelf('H01', 1, 'B-NEW', 9, 3);

        $online = $this->raise(5);                          // مخطط على F01
        $this->drain($f01, 8);                              // فضل على F01 اتنين

        $this->assertNull($this->pick($online));

        $items = $online->fresh('items')->items->sortBy('id')->values();
        // 2 من F01 (المخطط) + 3 من G01 (الأقرب انتهاءً بعده) — مجموع المطلوب مابيتغيرش
        $this->assertSame([2, 3], $items->pluck('qty_picked')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(5, (int) $items->sum('qty_requested'));
        $this->assertSame([(int) $f01->location_id, (int) $g01->location_id], $items->pluck('location_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(3, (int) $h01->fresh()->qty, 'the later-expiring batch is untouched');
    }

    public function test_not_enough_anywhere_refuses_and_moves_nothing(): void
    {
        $f01 = $this->shelf('F01', 1, 'B-OLD', 3, 5);
        $g01 = $this->shelf('G01', 1, 'B-NEW', 9, 2);

        $online = $this->raise(4);
        $this->drain($f01, 5);

        $err = $this->pick($online);
        $this->assertNotNull($err);
        $this->assertStringContainsString('2', $err);        // المتاح 2 بس
        $this->assertSame(2, (int) $g01->fresh()->qty, 'nothing moved');
        $this->assertSame('picking', $online->fresh()->status);
    }

    public function test_a_healthy_pick_still_takes_exactly_what_was_planned(): void
    {
        $f01 = $this->shelf('F01', 1, 'B-OLD', 3, 5);
        $g01 = $this->shelf('G01', 1, 'B-NEW', 9, 20);

        $online = $this->raise(4);
        $this->assertNull($this->pick($online));

        $this->assertSame(1, (int) $f01->fresh()->qty);
        $this->assertSame(20, (int) $g01->fresh()->qty);
    }
}
