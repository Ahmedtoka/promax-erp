<?php

namespace Tests\Feature\Mfg;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Batch;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionIssue;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Services\Mfg\Orders;
use App\Services\Mfg\PurchaseLink;
use App\Services\Mfg\Requirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * احتياج الخامات وطلب الشراء للعجز (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦).
 *
 * المعياري للكمية المخططة (بالهالك) قصاد رصيد الخامة الصالح في مخزن
 * الخامات (مش موقوف، مش منتهي)، والعجز بيتطلب شراء (D8 — stub لحد الخط ٥).
 */
class RequirementTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private Product $oats;

    private Bom $bom;

    private CostCenter $line;

    private Warehouse $raw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = $this->makeProduct(['kind' => 'finished']);
        $this->oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $this->bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 5]);
        $this->bom->lines()->create(['product_id' => $this->oats->id, 'qty' => 0.035, 'basis' => 'per_unit', 'sort' => 1]);
        $this->line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'active' => true]);
        $this->raw = $this->makeWarehouse();
    }

    private function stock(int $qty, array $attrs = [], ?Warehouse $w = null): Batch
    {
        return Batch::create(array_merge([
            'product_id' => $this->oats->id, 'warehouse_id' => ($w ?? $this->raw)->id, 'batch_no' => 'R-'.uniqid(),
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => 10,
        ], $attrs));
    }

    private function order(int $qty = 1000, array $over = []): ProductionOrder
    {
        return Orders::create(array_merge([
            'product_id' => $this->fg->id, 'line_id' => $this->line->id, 'planned_qty' => $qty,
            'batch_no' => 'B-'.uniqid(), 'planned_on' => today()->toDateString(),
            'warehouse_id' => $this->raw->id, 'raw_warehouse_id' => $this->raw->id,
        ], $over), $this->makeAdmin());
    }

    public function test_requirement_compares_std_with_raw_stock(): void
    {
        $this->stock(30);
        // الموقوف والمنتهي والفاضي مابيتحسبوش
        $this->stock(50, ['blocked' => true]);
        $this->stock(40, ['expires_on' => today()->subDay()]);
        $this->stock(0);
        // مخزن تاني مابيتحسبش
        $this->stock(100, [], $this->makeWarehouse());

        $o = $this->order();

        $this->assertEqualsWithDelta(30.0, Requirement::available($this->raw, $this->oats), 0.0001);

        $rows = Requirement::forOrder($o);
        $this->assertCount(1, $rows);
        $r = $rows->first();
        $this->assertSame($this->oats->id, $r['product']->id);
        $this->assertEqualsWithDelta(36.750, $r['std_qty'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $r['issued_net'], 0.0001);
        $this->assertEqualsWithDelta(36.750, $r['remaining'], 0.0001);
        $this->assertEqualsWithDelta(30.0, $r['available'], 0.0001);
        $this->assertEqualsWithDelta(6.750, $r['shortage'], 0.0001);

        // المصروف صافي (صرف + زيادة − مرتجع) بينزّل الباقي
        $b = $this->stock(1);
        foreach ([['issue', 10], ['extra', 2], ['return', 3]] as [$kind, $q]) {
            ProductionIssue::create(['production_order_id' => $o->id, 'product_id' => $this->oats->id, 'batch_id' => $b->id,
                'kind' => $kind, 'qty' => $q, 'unit_cost' => 10, 'value' => $q * 10, 'issued_at' => now()]);
        }
        $r = Requirement::forOrder($o->fresh())->first();
        $this->assertEqualsWithDelta(9.0, $r['issued_net'], 0.0001);
        $this->assertEqualsWithDelta(27.750, $r['remaining'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $r['shortage'], 0.0001);

        $this->actingAs($this->makeAdmin(['role' => 'production_manager']))->get(route('mfg.orders.show', $o))
            ->assertOk()->assertSee('36.75');
    }

    public function test_shortage_request_stub_marks_order_and_notifies_admin(): void
    {
        $this->stock(30);
        $o = $this->order();
        $admin = $this->makeAdmin();
        $off = $this->makeAdmin(['active' => false]);
        $pm = $this->makeAdmin(['role' => 'production_manager']);

        $this->actingAs($pm)->post(route('mfg.orders.shortage', $o))->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertNotNull($o->shortage_requested_at);
        $this->assertNull($o->purchase_request_id);

        $note = AppNotification::query()->where('user_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString($o->number, $note->title);
        $this->assertStringContainsString('6.75', (string) $note->body);
        $this->assertSame(route('mfg.orders.show', $o->id, false), $note->link);
        $this->assertSame(0, AppNotification::query()->where('user_id', $off->id)->count());
        $this->assertSame(0, AppNotification::query()->where('user_id', $pm->id)->count());

        // أمين المخزن مايطلبش شراء
        $this->actingAs($this->makeAdmin(['role' => 'warehouse_keeper']))->post(route('mfg.orders.shortage', $o))->assertForbidden();
    }

    public function test_no_shortage_is_refused(): void
    {
        $this->stock(40);
        $o = $this->order();

        $this->actingAs($this->makeAdmin(['role' => 'production_manager']))->post(route('mfg.orders.shortage', $o))
            ->assertSessionHasErrors('order');
        $this->assertNull($o->fresh()->shortage_requested_at);

        $this->expectException(Rejected::class);
        $this->expectExceptionMessage(__('mfg.no_shortage'));
        PurchaseLink::raise($o, $this->makeAdmin());
    }

    public function test_open_orders_requirement_aggregates_materials(): void
    {
        $this->stock(30);
        $second = $this->makeWarehouse();
        $this->stock(5, [], $second);

        $this->order(1000);                                   // 36.750
        $b = $this->order(200);                               // 7.350
        $this->order(400, ['raw_warehouse_id' => $second->id]); // 14.700
        $done = $this->order(1000);
        $done->forceFill(['status' => 'cancelled'])->save();
        Orders::start($b, $this->makeAdmin());

        $rows = Requirement::forOpenOrders();
        $this->assertCount(1, $rows);
        $r = $rows->first();
        $this->assertSame($this->oats->id, $r['product']->id);
        $this->assertEqualsWithDelta(58.800, $r['std_qty'], 0.0001);
        $this->assertEqualsWithDelta(58.800, $r['remaining'], 0.0001);
        // كل مخزن خامات بيتحسب مرة واحدة حتى لو عليه أكتر من أمر
        $this->assertEqualsWithDelta(35.0, $r['available'], 0.0001);
        $this->assertEqualsWithDelta(23.800, $r['shortage'], 0.0001);

        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper']);
        $this->actingAs($keeper)->get(route('mfg.requirements'))->assertOk()->assertSee('58.8');
        $csv = $this->actingAs($keeper)->get(route('mfg.requirements', ['export' => 1]));
        $csv->assertOk();
        $this->assertStringContainsString('23.8', $csv->streamedContent());
        $this->actingAs($this->makeAdmin(['role' => 'quality']))->get(route('mfg.requirements'))->assertForbidden();
    }
}
