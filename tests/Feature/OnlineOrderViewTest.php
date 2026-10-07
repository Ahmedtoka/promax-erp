<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\OnlinePickup;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══ صفحة الأوردر بالهيستوري + «اتسلّم خارج السيستم» (٧/١٠/٢٠٢٦) ═══
 */
class OnlineOrderViewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $wh;
    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin(['name' => 'جاد']);
        $this->wh = $this->makeWarehouse();
        Setting::writeMany(['online_warehouse_id' => (string) $this->wh->id]);
        $this->p = $this->makeProduct(['name_en' => 'View Bar']);

        $b = Batch::create(['product_id' => $this->p->id, 'warehouse_id' => $this->wh->id, 'batch_no' => 'B-OV',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 20, 'qty_remaining' => 20, 'cost' => 10]);
        $l = Location::create(['warehouse_id' => $this->wh->id, 'code' => 'V01', 'stand' => 'V', 'level' => 1, 'active' => true]);
        BatchLocation::create(['batch_id' => $b->id, 'location_id' => $l->id, 'product_id' => $this->p->id, 'qty' => 20]);
    }

    private function order(string $number, int $qty = 2): OnlineOrder
    {
        $o = OnlineOrder::create(['shopify_id' => 970000 + (int) $number, 'number' => $number, 'customer_name' => 'Mostafa Elnagdy',
            'items_count' => $qty, 'subtotal' => 100 * $qty, 'shipping' => 65, 'total' => 100 * $qty + 65, 'status' => 'new',
            'ordered_at' => now()->subDays(3)]);
        OnlineOrderItem::create(['online_order_id' => $o->id, 'title' => 'ProBar View', 'product_id' => $this->p->id,
            'units_per' => 1, 'qty' => $qty, 'price' => 100, 'total' => 100 * $qty]);

        return $o;
    }

    private function onShelf(): int
    {
        return (int) BatchLocation::where('product_id', $this->p->id)->sum('qty');
    }

    public function test_the_order_number_opens_the_order_page_with_its_history_and_who_did_it(): void
    {
        $o = $this->order('2001');

        $this->actingAs($this->admin)->post(route('online.confirm', $o))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('online.prep.done', $o->fresh()->pick_order_id))->assertSessionHasNoErrors();

        // رقم الأوردر في الشاشات بقى بيفتح صفحة الأوردر مش الفاتورة
        foreach ([route('online.orders'), route('online.prep')] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee(route('online.view', $o), false);
        }

        $this->actingAs($this->admin)->get(route('online.view', $o))->assertOk()
            ->assertSee('#2001')->assertSee('ProBar View')->assertSee('View Bar')
            ->assertSee(route('online.invoice', $o), false)                       // الفاتورة زرار لوحدها
            ->assertSeeInOrder([__('online.hist_ordered'), __('online.hist_synced'), __('online.hist_pick_raised'),
                __('online.hist_prepared')])
            ->assertSee(__('online.hist_confirmed'))
            ->assertSee(__('online.act_confirm'))->assertSee('جاد');               // سجل العمليات: مين داس

        $keeper = User::create(['name' => 'أمين', 'email' => 'k-ov@x.test', 'code' => 'WHK-OV', 'role' => 'warehouse_keeper',
            'password' => bcrypt('x'), 'active' => true]);
        $this->actingAs($keeper)->get(route('online.view', $o))->assertOk();
    }

    public function test_an_order_delivered_outside_the_system_lands_in_the_sheet_collected_without_moving_stock(): void
    {
        $o = $this->order('1055', 3);
        $pu = OnlinePickup::create(['number' => 'PU-1001', 'date' => today()->subDays(8), 'created_by' => $this->admin->id]);
        $picks = PickOrder::count();

        $this->actingAs($this->admin)->get(route('online.sync'))->assertOk()
            ->assertSee(__('online.act_manual_ship'));

        $this->actingAs($this->admin)->post(route('online.manualship', $o), [
            'pickup_id' => $pu->id, 'amount' => 300, 'note' => 'خرج يدوي من بدري والمخزن اتظبط بالجرد',
        ])->assertSessionDoesntHaveErrors(['order', 'note', 'amount', 'pickup_id']);   // تحذير شوبيفاي «مش متظبط» بس

        $o->refresh();
        $this->assertSame('completed', $o->status);
        $this->assertSame($pu->id, $o->pickup_id);
        $this->assertEquals(300.0, (float) $o->collected_total);
        $this->assertNull($o->pick_order_id);
        $this->assertSame($picks, PickOrder::count(), 'no pick order');
        $this->assertSame(20, $this->onShelf(), 'no stock movement');
        $this->assertStringContainsString('خرج يدوي', (string) $o->notes);

        // بيظهر في الشيت كامل، ومش في السينك
        $this->actingAs($this->admin)->get(route('online.pickup', $pu))->assertOk()->assertSee('#1055');
        $this->actingAs($this->admin)->get(route('online.sync'))->assertOk()->assertDontSee('#1055');
        $t = $pu->fresh()->totals();
        $this->assertEquals(300.0, $t['collected']);
        $this->assertEquals(0.0, $t['remaining']);

        // وصفحته بتقول اتسلّم خارج السيستم
        $this->actingAs($this->admin)->get(route('online.view', $o))->assertOk()
            ->assertSee(__('online.act_manual_ship'))->assertSee(__('online.hist_shipped'))->assertSee('PU-1001');
    }

    public function test_manual_delivery_is_admin_only_needs_a_reason_and_only_for_open_orders(): void
    {
        $o = $this->order('1056');
        $pu = OnlinePickup::create(['number' => 'PU-2001', 'date' => today(), 'created_by' => $this->admin->id]);

        $manager = User::create(['name' => 'مدير', 'email' => 'm-ov@x.test', 'code' => 'CHM-OV', 'role' => 'manager',
            'password' => bcrypt('x'), 'active' => true]);
        $this->actingAs($manager)->post(route('online.manualship', $o), ['pickup_id' => $pu->id, 'amount' => 200, 'note' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->admin)->post(route('online.manualship', $o), ['pickup_id' => $pu->id, 'amount' => 200])
            ->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post(route('online.manualship', $o), ['pickup_id' => $pu->id, 'amount' => 999, 'note' => 'x'])
            ->assertSessionHasErrors('order');
        $this->assertSame('new', $o->fresh()->status);

        // تحصيل جزئي = مشحون بالباقي
        $this->actingAs($this->admin)->post(route('online.manualship', $o), ['pickup_id' => $pu->id, 'amount' => 150, 'note' => 'x'])
            ->assertSessionDoesntHaveErrors(['order', 'note', 'amount', 'pickup_id']);
        $this->assertSame('shipped', $o->fresh()->status);
        $this->assertEquals(50.0, $o->fresh()->remaining());

        // مرة تانية = مرفوض (مابقاش جديد)
        $this->actingAs($this->admin)->post(route('online.manualship', $o), ['pickup_id' => $pu->id, 'amount' => 50, 'note' => 'x'])
            ->assertSessionHasErrors('order');
    }
}
