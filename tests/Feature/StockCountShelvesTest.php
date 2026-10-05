<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\PickOrder;
use App\Services\StockCounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الجرد بيظبط الأرفف على المعدود (بلاغ العسل — ٥/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * باتش رصيده 14 ومش مترصّف على أي رف، الجرد لقى 37 — الباتش اتكتب 37
 * والأرفف فضلت صفر، فأمر التوريد قال «مفيش مخزون على الرف».
 */
class StockCountShelvesTest extends TestCase
{
    use RefreshDatabase;

    private function batch($warehouse, $product, string $no, int $qty, int $months = 6): Batch
    {
        return Batch::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => $no,
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths($months),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => 10,
        ]);
    }

    private function countTo($warehouse, $admin, array $byBatch): void
    {
        $count = StockCounting::open($warehouse, $admin);
        $entries = [];

        foreach ($count->items as $item) {
            if (array_key_exists($item->batch_id, $byBatch)) {
                $entries[$item->id] = ['counted' => $byBatch[$item->batch_id], 'reason' => 'entry_error'];
            }
        }

        StockCounting::record($count, $entries);
        StockCounting::approve($count->fresh(), $admin);
    }

    private function shelved(Batch $b): int
    {
        return (int) BatchLocation::where('batch_id', $b->id)->sum('qty');
    }

    public function test_an_unshelved_batch_counted_up_lands_on_a_shelf_and_can_be_picked(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $honey = $this->makeProduct(['name_en' => 'Honey Cup']);
        $b = $this->batch($wh, $honey, 'C71.303', 14);   // مش على أي رف — زي اللايف

        $this->countTo($wh, $admin, [$b->id => 37]);

        $this->assertSame(37, (int) $b->fresh()->qty_remaining);
        $this->assertSame(37, $this->shelved($b), 'the counted quantity must be on a shelf');

        // وأمر التجهيز بيشوفهم
        $r = PickOrder::raise(warehouse: $wh, rep: $admin, qtyByProduct: [$honey->id => 37]);
        $this->assertNull($r['error']);
    }

    public function test_a_count_that_matches_the_batch_still_fixes_missing_shelves(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $p = $this->makeProduct();
        $b = $this->batch($wh, $p, 'B-SAME', 20);       // رصيده 20 ومش مترصّف

        $this->countTo($wh, $admin, [$b->id => 20]);    // العد مطابق

        $this->assertSame(20, $this->shelved($b));
    }

    public function test_a_shortage_still_comes_off_the_shelves(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $p = $this->makeProduct();
        $b = $this->batch($wh, $p, 'B-SHORT', 30);
        $loc = Location::create(['warehouse_id' => $wh->id, 'code' => 'S01', 'stand' => 'S', 'level' => 1, 'active' => true]);
        BatchLocation::create(['batch_id' => $b->id, 'location_id' => $loc->id, 'product_id' => $p->id, 'qty' => 30]);

        $this->countTo($wh, $admin, [$b->id => 22]);

        $this->assertSame(22, (int) $b->fresh()->qty_remaining);
        $this->assertSame(22, $this->shelved($b));
        $this->assertSame(8, (int) $b->fresh()->qty_damaged);
    }

    public function test_the_shelve_command_no_longer_invents_stock_from_a_lagging_summary(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $p = $this->makeProduct();
        $b = $this->batch($wh, $p, 'B-LAG', 50);
        StockCounting::resync($p->id, $wh->id);                       // stocks = 50

        // أمر تجهيز اتسحب ولسه ماتسلّمش: الباتش بقى 38 و stocks لسه 50
        $b->update(['qty_remaining' => 38]);

        Artisan::call('promax:shelve', ['--fix' => true]);

        $this->assertSame(38, (int) Batch::where('product_id', $p->id)->sum('qty_remaining'),
            'no adjustment batch may be created from the lagging summary');
        $this->assertSame(38, $this->shelved($b), 'the loose quantity got shelved');
    }
}
