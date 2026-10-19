<?php

namespace Tests\Feature\Mfg;

use App\Models\Bom;
use App\Models\BomLine;
use App\Models\CostCenter;
use App\Models\ProductionOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * التصنيع (الإغلاق، الخط ٦ — ١٩/١٠/٢٠٢٦) — السكيما والموديلز.
 */
class MfgSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_exist_after_migrate(): void
    {
        foreach (['product_specs', 'boms', 'bom_lines', 'production_orders', 'production_order_lines', 'production_issues'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "الجدول {$t} مش موجود");
        }

        $this->assertTrue(Schema::hasColumn('production_orders', 'fg_batch_id'));
        $this->assertTrue(Schema::hasColumn('production_orders', 'raw_warehouse_id'));
        $this->assertTrue(Schema::hasColumn('production_order_lines', 'std_qty_actual'));
        $this->assertTrue(Schema::hasColumn('production_issues', 'unit_cost'));
    }

    public function test_bom_std_qty_per_unit_and_per_batch_with_waste(): void
    {
        $fg = $this->makeProduct(['kind' => 'finished']);
        $raw = $this->makeProduct(['kind' => 'raw']);
        $pack = $this->makeProduct(['kind' => 'packaging']);

        $bom = Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'draft', 'waste_pct' => 5]);
        $perUnit = $bom->lines()->create(['product_id' => $raw->id, 'qty' => 0.035, 'basis' => 'per_unit', 'sort' => 1]);
        $perBatch = $bom->lines()->create(['product_id' => $pack->id, 'qty' => 2, 'basis' => 'per_batch', 'sort' => 2]);

        $this->assertEqualsWithDelta(35.0, $perUnit->baseFor(1000), 0.0001);
        $this->assertEqualsWithDelta(2.0, $perBatch->baseFor(1000), 0.0001);
        $this->assertSame(36.75, $bom->stdFor($perUnit, 1000));
        $this->assertSame(2.1, $bom->stdFor($perBatch, 1000));

        $this->assertSame([$perUnit->id, $perBatch->id], $bom->lines()->pluck('id')->all());
        $this->assertSame(BomLine::BASES, ['per_unit', 'per_batch']);
    }

    public function test_order_numbers_increment(): void
    {
        $this->assertSame('MO-1001', ProductionOrder::nextNumber());

        $fg = $this->makeProduct(['kind' => 'finished']);
        $bom = Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
        $line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'active' => true]);
        $wh = $this->makeWarehouse();

        $o = ProductionOrder::create([
            'number' => ProductionOrder::nextNumber(), 'product_id' => $fg->id, 'bom_id' => $bom->id,
            'line_id' => $line->id, 'warehouse_id' => $wh->id, 'raw_warehouse_id' => $wh->id,
            'batch_no' => 'B-1', 'planned_qty' => 100, 'planned_on' => today(),
        ]);

        $this->assertSame('MO-1001', $o->number);
        $this->assertSame('planned', $o->fresh()->status);
        $this->assertTrue($o->fresh()->isOpen());
        $this->assertSame('MO-1002', ProductionOrder::nextNumber());
        $this->assertSame($bom->id, $o->bom->id);
        $this->assertSame($line->id, $o->line->id);
        $this->assertSame(1, $fg->boms()->count());
    }

    public function test_mfg_screens_follow_the_roles_and_export_csv(): void
    {
        $fg = $this->makeProduct(['kind' => 'finished', 'name_en' => 'Mango Bar']);
        $bom = Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'approved']);
        $line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'name_en' => 'Line 1', 'kind' => 'line', 'active' => true]);
        $wh = $this->makeWarehouse();
        $base = ['product_id' => $fg->id, 'bom_id' => $bom->id, 'line_id' => $line->id,
            'warehouse_id' => $wh->id, 'raw_warehouse_id' => $wh->id, 'planned_qty' => 100];
        ProductionOrder::create($base + ['number' => 'MO-1001', 'batch_no' => 'IN-RANGE', 'planned_on' => '2026-10-10',
            'status' => 'closed', 'qc_status' => 'quarantine', 'produced_on' => '2026-10-11']);
        ProductionOrder::create($base + ['number' => 'MO-1002', 'batch_no' => 'OUT-RANGE', 'planned_on' => '2026-08-01']);
        \App\Models\ProductSpec::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'draft', 'shelf_life_days' => 180]);

        $pm = $this->makeAdmin(['role' => 'production_manager']);
        $quality = $this->makeAdmin(['role' => 'quality']);
        $rnd = $this->makeAdmin(['role' => 'rnd']);
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper']);

        $this->actingAs($pm)->get(route('mfg.orders', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()->assertSee('IN-RANGE')->assertDontSee('OUT-RANGE');
        $this->actingAs($keeper)->get(route('mfg.orders'))->assertOk();
        $this->actingAs($keeper)->get(route('mfg.qc'))->assertForbidden();
        $this->actingAs($quality)->get(route('mfg.qc'))->assertOk()->assertSee('IN-RANGE')->assertDontSee('OUT-RANGE');
        $this->actingAs($rnd)->get(route('mfg.specs'))->assertOk()->assertSee('Mango Bar');
        $this->actingAs($rnd)->get(route('mfg.orders'))->assertForbidden();

        $csv = $this->actingAs($pm)->get(route('mfg.orders', ['export' => 1, 'from' => '2026-10-01', 'to' => '2026-10-31']));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('IN-RANGE', $body);
        $this->assertStringNotContainsString('OUT-RANGE', $body);

        $this->assertStringContainsString('MO-1001', $this->actingAs($quality)->get(route('mfg.qc', ['export' => 1]))->streamedContent());
        $this->assertStringContainsString('180', $this->actingAs($rnd)->get(route('mfg.specs', ['export' => 1]))->streamedContent());
    }

    public function test_approved_for_returns_the_approved_version(): void
    {
        $fg = $this->makeProduct(['kind' => 'finished']);
        Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'archived']);
        $v2 = Bom::create(['product_id' => $fg->id, 'version' => 2, 'status' => 'approved']);
        Bom::create(['product_id' => $fg->id, 'version' => 3, 'status' => 'draft']);

        $this->assertSame($v2->id, Bom::approvedFor($fg)?->id);
        $this->assertNull(\App\Models\ProductSpec::approvedFor($fg));
    }
}
