<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\OnlinePickup;
use App\Models\OnlineReturn;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * مرتجعات الأونلاين + إعادة الشحن (١/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * السايكل: تأكيد ← تجهيز ← شحن ← مرتجع (كله) ← إعادة شحن ← تجهيز جديد.
 * كل مرتجع صف في السجل، وإعادة الشحن بتعلّم الصف وبتطلع الأوردر من
 * الشيت القديم.
 */
class OnlineReshipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $wh;
    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        $this->wh = $this->makeWarehouse();
        Setting::writeMany(['online_warehouse_id' => (string) $this->wh->id]);

        $this->p = $this->makeProduct(['name_en' => 'Reship Bar']);
        $batch = Batch::create([
            'product_id' => $this->p->id, 'warehouse_id' => $this->wh->id, 'batch_no' => 'B-RS',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 50, 'qty_remaining' => 50, 'cost' => 10,
        ]);
        $loc = Location::create(['warehouse_id' => $this->wh->id, 'code' => 'R01', 'stand' => 'R', 'level' => 1, 'active' => true]);
        BatchLocation::create(['batch_id' => $batch->id, 'location_id' => $loc->id, 'product_id' => $this->p->id, 'qty' => 50]);
    }

    private function onShelf(): int
    {
        return (int) BatchLocation::where('product_id', $this->p->id)->sum('qty');
    }

    /** أوردر اتأكد واتجهز واتشحن في شيت — زي اللايف */
    private function shipped(int $qty = 3): OnlineOrder
    {
        $o = OnlineOrder::create([
            'shopify_id' => 930000 + random_int(1, 9999), 'number' => (string) random_int(1100, 9999),
            'customer_name' => 'عميل مرتجع', 'items_count' => $qty, 'subtotal' => 100 * $qty, 'shipping' => 50,
            'total' => 100 * $qty + 50, 'status' => 'new',
        ]);
        OnlineOrderItem::create(['online_order_id' => $o->id, 'title' => 'Reship Bar', 'product_id' => $this->p->id,
            'qty' => $qty, 'units_per' => 1, 'price' => 100, 'total' => 100 * $qty]);

        $this->actingAs($this->admin)->post(route('online.confirm', $o))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('online.prep.done', $o->fresh()->pick_order_id))->assertSessionHasNoErrors();

        $pu = OnlinePickup::create(['number' => OnlinePickup::nextNumber(), 'date' => today(), 'created_by' => $this->admin->id]);
        $o->fresh()->update(['status' => 'shipped', 'pickup_id' => $pu->id, 'shipped_at' => now(), 'reviewed_at' => now()]);

        return $o->fresh();
    }

    public function test_full_cycle_return_then_reship(): void
    {
        $o = $this->shipped(3);
        $oldPick = $o->pick_order_id;
        $oldPickup = $o->pickup_id;
        $this->assertSame(47, $this->onShelf());

        // ── مرتجع كامل: البضاعة ترجع، وصف في السجل ──
        $item = $o->items()->first();
        $this->actingAs($this->admin)->post(route('online.return', $o), ['items' => [$item->id => 3]])
            ->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('returned', $o->status);
        $this->assertSame(50, $this->onShelf());

        $log = OnlineReturn::where('online_order_id', $o->id)->sole();
        $this->assertSame('full', $log->kind);
        $this->assertSame(3, (int) $log->pieces);
        $this->assertEquals(300.0, (float) $log->value);
        $this->assertSame($oldPickup, $log->pickup_id);
        $this->assertTrue($log->canReship());

        // ── الصفحات: الزرار ظاهر في كل الأوردرات والشيت والمرتجعات ──
        foreach ([route('online.orders'), route('online.pickup', $oldPickup), route('online.returns')] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee(route('online.reship', $o), false);
        }

        // ── إعادة الشحن: تجهيز جديد بالكمية كاملة، وبره الشيت القديم ──
        $this->actingAs($this->admin)->post(route('online.reship', $o))->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('preparing', $o->status);
        $this->assertNull($o->pickup_id);
        $this->assertEquals(0.0, (float) $o->returned_total);
        $this->assertSame(0, (int) $o->items()->first()->returned_qty);
        $this->assertNotSame($oldPick, $o->pick_order_id);

        $pick = PickOrder::with('items')->find($o->pick_order_id);
        $this->assertSame(3, (int) $pick->items->sum('qty_requested'));
        $this->assertStringEndsWith('R', $pick->number);

        $log->refresh();
        $this->assertNotNull($log->reshipped_at);
        $this->assertSame($pick->id, $log->reship_pick_order_id);
        $this->assertFalse($log->canReship());

        // الشيت القديم مابقاش فيه الأوردر — والمرتجعات بتقول «اتشحن تاني» من غير زرار
        $this->assertSame(0, OnlinePickup::find($oldPickup)->orders()->count());

        // ومعادلة الشيت لسه شايفاه (٧/١٠): طلع 300 = اتحصل 0 + رجع 300 + باقي 0
        $t = OnlinePickup::find($oldPickup)->totals();
        $this->assertSame(1, $t['out_orders']);
        $this->assertEquals(300.0, $t['out_goods']);
        $this->assertSame(1, $t['returned_orders']);
        $this->assertEquals(300.0, $t['returned_value']);
        $this->assertEquals(0.0, $t['remaining']);
        $this->actingAs($this->admin)->get(route('online.pickups'))->assertOk()
            ->assertSee(__('online.ret_value'))->assertSee('300.00');
        $this->actingAs($this->admin)->get(route('online.returns'))->assertOk()
            ->assertSee(__('online.return_reshipped'))->assertDontSee(route('online.reship', $o), false);

        // شاشة التجهيز: الأمر الجديد ظاهر باسم العميل، والقديم (جاهز ومالوش أوردر) مش ظاهر
        $oldNumber = PickOrder::find($oldPick)->number;
        $this->actingAs($this->admin)->get(route('online.prep'))->assertOk()
            ->assertSee('#'.$o->number)->assertSee('عميل مرتجع')
            ->assertDontSee('<b>'.$oldNumber.'</b>', false);

        // والتجهيز الجديد بيخرّج البضاعة تاني
        $this->actingAs($this->admin)->post(route('online.prep.done', $pick))->assertSessionHasNoErrors();
        $this->assertSame(47, $this->onShelf());
        $this->assertSame('ready', $o->fresh()->status);
    }

    public function test_partial_return_is_logged_but_cannot_be_reshipped(): void
    {
        $o = $this->shipped(3);
        $item = $o->items()->first();

        $this->actingAs($this->admin)->post(route('online.return', $o), ['items' => [$item->id => 1]])
            ->assertSessionHasNoErrors();

        $log = OnlineReturn::where('online_order_id', $o->id)->sole();
        $this->assertSame('partial', $log->kind);

        // المعادلة: طلع 300 = اتحصل 0 + رجع 100 + باقي 200
        $t = $o->fresh()->pickup->totals();
        $this->assertEquals(300.0, $t['out_goods']);
        $this->assertEquals(100.0, $t['returned_value']);
        $this->assertEquals(200.0, $t['remaining']);
        $this->assertEquals($t['out_goods'], $t['collected'] + $t['returned_value'] + $t['remaining']);
        $this->assertSame('shipped', $o->fresh()->status);
        $this->assertFalse($log->canReship());

        $picks = PickOrder::count();
        $this->actingAs($this->admin)->post(route('online.reship', $o))->assertSessionHasErrors('order');
        $this->assertSame($picks, PickOrder::count(), 'no pick order raised');
    }

    public function test_only_the_team_can_reship_and_the_accountant_sees_the_page_without_the_button(): void
    {
        $o = $this->shipped(2);
        $this->actingAs($this->admin)->post(route('online.return', $o), ['items' => [$o->items()->first()->id => 2]]);

        $acc = User::create(['name' => 'محاسب', 'email' => 'acc-rs@x.test', 'code' => 'ACC-RS', 'role' => 'accountant',
            'password' => bcrypt('x'), 'active' => true]);

        $this->actingAs($acc)->get(route('online.returns'))->assertOk()->assertDontSee(route('online.reship', $o), false);
        $this->actingAs($acc)->post(route('online.reship', $o))->assertForbidden();
        $this->assertSame('returned', $o->fresh()->status);
    }
}
