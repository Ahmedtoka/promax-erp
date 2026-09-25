<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\PickOrder;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الأونلاين: فلتر المناطق + نوت التأكيد للتجهيز (٢٥/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «فلتر بالمناطق في السينك وفي صفحة الشحن عشان أجمع
 * مناطق التجمع كلها في شيت مندوب» + «لما أدوس أوردر مؤكد أكتب نوت
 * تظهر في التجهيز».
 */
class OnlineAreaFilterAndNoteTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $number, ?string $area, array $attrs = []): OnlineOrder
    {
        static $n = 0;
        $n++;

        return OnlineOrder::create(array_merge([
            'shopify_id' => 700000 + $n, 'number' => $number, 'customer_name' => 'عميل '.$number,
            'area' => $area, 'items_count' => 1, 'subtotal' => 100, 'shipping' => 0, 'total' => 100,
            'status' => 'new',
        ], $attrs));
    }

    private function seedAreas(array $attrs = []): void
    {
        $this->order('TGM-1111', 'التجمع الخامس - Cairo', $attrs);
        $this->order('TGM-2222', 'New Cairo - Cairo', $attrs);
        $this->order('NSR-3333', 'مدينة نصر - Cairo', $attrs);
        $this->order('GIZ-4444', 'Sheikh Zayed - Giza', $attrs);
        $this->order('NOA-5555', null, $attrs);
    }

    public function test_sync_filters_by_several_areas_together(): void
    {
        $this->seedAreas();

        $this->actingAs($this->makeAdmin())
            ->get(route('online.sync', ['areas' => ['التجمع الخامس - Cairo', 'New Cairo - Cairo']]))
            ->assertOk()
            ->assertSee('TGM-1111')->assertSee('TGM-2222')
            ->assertDontSee('NSR-3333')->assertDontSee('GIZ-4444')->assertDontSee('NOA-5555');
    }

    public function test_sync_filters_by_governorate_and_search(): void
    {
        $this->seedAreas();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('online.sync', ['gov' => 'Giza']))
            ->assertOk()->assertSee('GIZ-4444')->assertDontSee('TGM-1111')->assertDontSee('NOA-5555');

        $this->actingAs($admin)->get(route('online.sync', ['q' => 'التجمع']))
            ->assertOk()->assertSee('TGM-1111')->assertDontSee('TGM-2222');

        // «من غير منطقة» اختيار حقيقي، مش ضايع
        $this->actingAs($admin)->get(route('online.sync', ['areas' => ['__none__']]))
            ->assertOk()->assertSee('NOA-5555')->assertDontSee('TGM-1111');
        $this->actingAs($admin)->get(route('online.sync', ['gov' => '__none__']))
            ->assertOk()->assertSee('NOA-5555')->assertDontSee('GIZ-4444');

        // بلا فلتر — الكل، وقيم غريبة ماترميش 500
        $this->actingAs($admin)->get(route('online.sync'))
            ->assertOk()->assertSee('TGM-1111')->assertSee('GIZ-4444')->assertSee('NOA-5555');
        $this->actingAs($admin)->get('/erp/online/sync?areas=x&gov[]=1&q[]=2')->assertOk();
        $this->actingAs($admin)->get(route('online.sync', ['q' => '%_']))->assertOk()->assertDontSee('TGM-1111');
    }

    public function test_ready_list_filters_by_area(): void
    {
        $this->seedAreas(['status' => 'ready', 'reviewed_at' => now(), 'ready_at' => now()]);

        $this->actingAs($this->makeAdmin())
            ->get(route('online.ready', ['areas' => ['التجمع الخامس - Cairo', 'New Cairo - Cairo']]))
            ->assertOk()
            ->assertSee('TGM-1111')->assertSee('TGM-2222')
            ->assertDontSee('NSR-3333')->assertDontSee('GIZ-4444');
    }

    public function test_confirm_note_reaches_the_prep_screen(): void
    {
        $admin = $this->makeAdmin();
        $warehouse = $this->makeWarehouse();
        Setting::writeMany(['online_warehouse_id' => (string) $warehouse->id]);

        $product = $this->makeProduct();
        $batch = Batch::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => 'B-ON',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 50, 'qty_remaining' => 50, 'cost' => 10,
        ]);
        $loc = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'A01', 'stand' => 'A', 'level' => 1, 'active' => true,
        ]);
        BatchLocation::create([
            'batch_id' => $batch->id, 'location_id' => $loc->id, 'product_id' => $product->id, 'qty' => 50,
        ]);

        $order = $this->order('NOTE-7777', 'التجمع الخامس - Cairo');
        OnlineOrderItem::create([
            'online_order_id' => $order->id, 'title' => 'بار', 'product_id' => $product->id,
            'qty' => 2, 'units_per' => 1, 'price' => 50, 'total' => 100,
        ]);

        $this->actingAs($admin)
            ->post(route('online.confirm', $order), ['note' => 'اتصل قبل ما تنزل — البوابة التانية'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $pick = PickOrder::find($order->pick_order_id);

        $this->assertSame('preparing', $order->status);
        $this->assertSame('اتصل قبل ما تنزل — البوابة التانية', $order->notes);
        $this->assertSame('اتصل قبل ما تنزل — البوابة التانية', $pick->notes);

        $this->actingAs($admin)->get(route('online.prep'))
            ->assertOk()->assertSee('اتصل قبل ما تنزل — البوابة التانية');
    }

    public function test_confirm_without_note_still_works(): void
    {
        $admin = $this->makeAdmin();
        $warehouse = $this->makeWarehouse();
        Setting::writeMany(['online_warehouse_id' => (string) $warehouse->id]);

        $product = $this->makeProduct();
        $batch = Batch::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => 'B-ON2',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 10, 'qty_remaining' => 10, 'cost' => 10,
        ]);
        $loc = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'A02', 'stand' => 'A', 'level' => 2, 'active' => true,
        ]);
        BatchLocation::create([
            'batch_id' => $batch->id, 'location_id' => $loc->id, 'product_id' => $product->id, 'qty' => 10,
        ]);

        $order = $this->order('PLAIN-8888', 'Maadi - Cairo');
        OnlineOrderItem::create([
            'online_order_id' => $order->id, 'title' => 'بار', 'product_id' => $product->id,
            'qty' => 1, 'units_per' => 1, 'price' => 100, 'total' => 100,
        ]);

        $this->actingAs($admin)->post(route('online.confirm', $order), ['note' => '   '])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('preparing', $order->status);
        $this->assertNull($order->notes);
        $this->assertNull(PickOrder::find($order->pick_order_id)->notes);

        // نص طويل زيادة بيترفض بفاليديشن مش 500
        $other = $this->order('LONG-9999', 'Maadi - Cairo');
        $this->actingAs($admin)->post(route('online.confirm', $other), ['note' => str_repeat('x', 501)])
            ->assertSessionHasErrors('note');
    }
}
