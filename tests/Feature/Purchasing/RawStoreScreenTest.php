<?php

namespace Tests\Feature\Purchasing;

use App\Models\Batch;
use App\Models\Product;
use App\Models\RawStockMove;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RawStoreScreenTest extends TestCase
{
    use RefreshDatabase;

    private function batch(Warehouse $w, Product $p, int $qty, int $days, string $no, float $cost = 2.0): Batch
    {
        return Batch::create([
            'product_id' => $p->id,
            'warehouse_id' => $w->id,
            'batch_no' => $no,
            'expires_on' => now()->addDays($days)->toDateString(),
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'cost' => $cost,
        ]);
    }

    public function test_raw_store_lists_raw_and_packaging_batches_in_fefo_order_not_finished(): void
    {
        $w = $this->makeWarehouse();
        $raw = $this->makeProduct(['kind' => 'raw']);
        $pack = $this->makeProduct(['kind' => 'packaging']);
        $fin = $this->makeProduct(['kind' => 'finished']);
        $this->batch($w, $raw, 10, 90, 'LATE-RAW');
        $this->batch($w, $pack, 10, 10, 'EARLY-PACK');
        $this->batch($w, $fin, 10, 5, 'FINISHED-X');
        $this->batch($w, $raw, 0, 1, 'EMPTY-ONE');

        $res = $this->actingAs($this->makeAdmin())->get(route('wh.raw'));

        $res->assertOk();
        $res->assertSeeInOrder(['EARLY-PACK', 'LATE-RAW']);
        $res->assertDontSee('FINISHED-X');
        $res->assertDontSee('EMPTY-ONE');
    }

    public function test_kind_filter_narrows_batches(): void
    {
        $w = $this->makeWarehouse();
        $this->batch($w, $this->makeProduct(['kind' => 'raw']), 10, 30, 'RAWLOT');
        $this->batch($w, $this->makeProduct(['kind' => 'packaging']), 10, 30, 'PACKLOT');

        $res = $this->actingAs($this->makeAdmin())->get(route('wh.raw', ['kind' => 'packaging']));

        $res->assertSee('PACKLOT');
        $res->assertDontSee('RAWLOT');
    }

    public function test_moves_tab_shows_issues(): void
    {
        $w = $this->makeWarehouse();
        $p = $this->makeProduct(['kind' => 'raw']);
        $b = $this->batch($w, $p, 10, 30, 'MOVELOT');
        $admin = $this->makeAdmin();
        RawStockMove::create([
            'warehouse_id' => $w->id, 'product_id' => $p->id, 'batch_id' => $b->id,
            'qty' => 7, 'unit_cost' => 2, 'for_type' => $admin->getMorphClass(), 'for_id' => $admin->id,
            'created_by' => $admin->id, 'moved_at' => now(),
        ]);

        $res = $this->actingAs($admin)->get(route('wh.raw', ['tab' => 'moves']));

        $res->assertOk();
        $res->assertSee('MOVELOT');
        $res->assertSee('User #'.$admin->id);
    }

    public function test_export_returns_csv(): void
    {
        $w = $this->makeWarehouse();
        $this->batch($w, $this->makeProduct(['kind' => 'raw']), 10, 30, 'CSVLOT');
        $admin = $this->makeAdmin();

        $res = $this->actingAs($admin)->get(route('wh.raw', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('CSVLOT', $res->streamedContent());

        $res = $this->actingAs($admin)->get(route('wh.raw', ['export' => 1, 'tab' => 'moves']));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
    }

    public function test_keeper_sees_only_his_warehouse(): void
    {
        $mine = $this->makeWarehouse();
        $other = $this->makeWarehouse();
        $p = $this->makeProduct(['kind' => 'raw']);
        $this->batch($mine, $p, 10, 30, 'MINELOT');
        $this->batch($other, $p, 10, 30, 'OTHERLOT');
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper', 'warehouse_id' => $mine->id]);

        $res = $this->actingAs($keeper)->get(route('wh.raw', ['warehouse' => $other->id]));

        $res->assertOk();
        $res->assertSee('MINELOT');
        $res->assertDontSee('OTHERLOT');
    }

    public function test_accountant_gets_403(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant']);

        $this->actingAs($acc)->get(route('wh.raw'))->assertForbidden();
    }
}
