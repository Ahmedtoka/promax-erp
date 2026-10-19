<?php

namespace Tests\Feature\Costing;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseRequest;
use App\Models\Warehouse;
use App\Services\Mfg\Orders;
use App\Services\Mfg\PurchaseLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** طلبات الشراء لعجز الإنتاج (لين ٩، D14) */
class ShortageRequestTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private Product $oats;

    private Product $sugar;

    private CostCenter $line;

    private Warehouse $raw;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = $this->makeProduct(['kind' => 'finished']);
        $this->oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $this->sugar = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
        $bom->lines()->create(['product_id' => $this->oats->id, 'qty' => 0.0124, 'basis' => 'per_unit', 'sort' => 1]);
        $bom->lines()->create(['product_id' => $this->sugar->id, 'qty' => 0.003, 'basis' => 'per_unit', 'sort' => 2]);
        $this->line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'active' => true]);
        $this->raw = $this->makeWarehouse();
    }

    private function order(): ProductionOrder
    {
        // 1000 وحدة: شوفان 12.4 وسكر 3 ولا رصيد خامات
        return Orders::create([
            'product_id' => $this->fg->id, 'line_id' => $this->line->id, 'planned_qty' => 1000,
            'batch_no' => 'B-'.uniqid(), 'planned_on' => today()->toDateString(),
            'warehouse_id' => $this->raw->id, 'raw_warehouse_id' => $this->raw->id,
        ], $this->makeAdmin());
    }

    private function raise(ProductionOrder $o, array $lines, $by = null)
    {
        return DB::transaction(fn () => PurchaseRequest::raiseFor($lines, $o, $by ?? $this->makeAdmin()));
    }

    public function test_shortage_raises_one_request_per_material_rounded_up(): void
    {
        $o = $this->order();
        $by = $this->makeAdmin(['role' => 'production_manager']);

        $made = $this->raise($o, [
            ['product_id' => $this->oats->id, 'qty' => 12.4],
            ['product_id' => $this->sugar->id, 'qty' => 3.0],
        ], $by);

        $this->assertCount(2, $made);
        $this->assertSame(2, PurchaseRequest::count());
        $oats = PurchaseRequest::where('product_id', $this->oats->id)->first();
        $this->assertSame(13, $oats->qty);
        $this->assertSame(3, PurchaseRequest::where('product_id', $this->sugar->id)->first()->qty);
        foreach (PurchaseRequest::all() as $r) {
            $this->assertSame('open', $r->status);
            $this->assertSame($this->line->id, $r->cost_center_id);
            $this->assertSame(ProductionOrder::class, $r->source_type);
            $this->assertSame($o->id, $r->source_id);
            $this->assertSame($by->id, $r->requested_by);
            $this->assertStringContainsString($o->number, (string) $r->notes);
        }
        $this->assertTrue($oats->source->is($o));
    }

    public function test_rerun_does_not_duplicate_open_requests(): void
    {
        $o = $this->order();
        $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 12.4], ['product_id' => $this->sugar->id, 'qty' => 3]]);
        $again = $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 12.4], ['product_id' => $this->sugar->id, 'qty' => 3]]);

        $this->assertSame(2, PurchaseRequest::count());
        $this->assertCount(2, $again);

        $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 20.2]]);
        $this->assertSame(2, PurchaseRequest::count());
        $this->assertSame(21, PurchaseRequest::where('product_id', $this->oats->id)->value('qty'));

        // أصغر من الموجود: مابينقصش
        $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 5]]);
        $this->assertSame(21, PurchaseRequest::where('product_id', $this->oats->id)->value('qty'));
    }

    public function test_quoting_request_is_reused_not_resized(): void
    {
        $o = $this->order();
        $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 12.4]]);
        PurchaseRequest::query()->update(['status' => 'quoting']);

        $r = $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 50]]);

        $this->assertSame(1, PurchaseRequest::count());
        $this->assertSame(13, PurchaseRequest::first()->qty);
        $this->assertSame('quoting', PurchaseRequest::first()->status);
        $this->assertSame(PurchaseRequest::first()->id, $r->first()->id);
    }

    public function test_cancelled_request_allows_a_new_one(): void
    {
        $o = $this->order();
        $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 12.4]]);
        PurchaseRequest::query()->update(['status' => 'cancelled']);

        $this->raise($o, [['product_id' => $this->oats->id, 'qty' => 12.4]]);

        $this->assertSame(2, PurchaseRequest::count());
        $this->assertSame(1, PurchaseRequest::where('status', 'open')->count());
    }

    public function test_order_page_shortage_button_creates_requests_and_still_notifies_admins(): void
    {
        $o = $this->order();
        $admin = $this->makeAdmin();
        $pm = $this->makeAdmin(['role' => 'production_manager']);

        $this->actingAs($pm)->post(route('mfg.orders.shortage', $o))->assertSessionHasNoErrors();

        $this->assertSame(2, PurchaseRequest::where('source_id', $o->id)->count());
        $o->refresh();
        $this->assertNotNull($o->shortage_requested_at);
        $this->assertSame((int) PurchaseRequest::orderBy('id')->value('id'), $o->purchase_request_id);
        $this->assertSame(1, AppNotification::query()->where('user_id', $admin->id)->count());

        // كل خامة ناقصة ليها طلب شغّال: مرفوض
        $this->actingAs($pm)->post(route('mfg.orders.shortage', $o))->assertSessionHasErrors('order');
        $this->assertSame(2, PurchaseRequest::count());
        try {
            PurchaseLink::raise($o->fresh(), $pm);
            $this->fail('expected Rejected');
        } catch (Rejected $e) {
            $this->assertStringContainsString(explode(' (', __('mfg.shortage_already_requested'))[0], $e->getMessage());
        }

        // طلب السكر اتلغى: الضغطة الجاية تعمل طلب السكر بس
        PurchaseRequest::where('product_id', $this->sugar->id)->update(['status' => 'cancelled']);
        $this->actingAs($pm)->post(route('mfg.orders.shortage', $o))->assertSessionHasNoErrors();
        $this->assertSame(3, PurchaseRequest::count());
        $this->assertSame(1, PurchaseRequest::where('product_id', $this->sugar->id)->where('status', 'open')->count());

        // صفحة الطلب بتعرض مصدره
        $r = PurchaseRequest::where('product_id', $this->oats->id)->first();
        $this->actingAs($this->makeAdmin())->get(route('erp.purchase_requests.show', $r))->assertOk()
            ->assertSee($o->number)->assertSee(route('mfg.orders.show', $o));
    }

    public function test_requests_wait_for_normal_approval(): void
    {
        $o = $this->order();
        $this->actingAs($this->makeAdmin(['role' => 'production_manager']))->post(route('mfg.orders.shortage', $o));

        foreach (PurchaseRequest::all() as $r) {
            $this->assertNull($r->supplier_order_id);
            $this->assertNull($r->approved_at);
            $this->assertSame('open', $r->status);
            $this->assertTrue($r->isActive());
        }
    }
}
