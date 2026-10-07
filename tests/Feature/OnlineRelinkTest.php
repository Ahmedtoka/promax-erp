<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShopifyProductLink;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══ «تحديث الربط» لأوردر مؤكد لسه مااتجهزش (بلاغ #1112 — ٧/١٠/٢٠٢٦) ═══
 *
 * «ProBar Peanut Butter» كان مربوط ببرطمان زبدة الفول (رصيده صفر) واتصلّح
 * لبروتين بار سوداني بعد ما الأوردر اتأكد — الأوردر فضل بالقديم وواقف.
 */
class OnlineRelinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $wh;
    private Product $jar;
    private Product $bar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        $this->wh = $this->makeWarehouse();
        Setting::writeMany(['online_warehouse_id' => (string) $this->wh->id]);

        $this->jar = $this->makeProduct(['name_en' => 'Peanut Butter Jar']);
        $this->bar = $this->makeProduct(['name_en' => 'Peanut Protein Bar']);
        $this->stock($this->jar, 'J01', 5);
        $this->stock($this->bar, 'B01', 50);
    }

    private function stock(Product $p, string $code, int $qty): void
    {
        $b = Batch::create(['product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'batch_no' => 'B-'.$code,
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => 10]);
        $l = Location::create(['warehouse_id' => $this->wh->id, 'code' => $code, 'stand' => $code[0], 'level' => 1, 'active' => true]);
        BatchLocation::create(['batch_id' => $b->id, 'location_id' => $l->id, 'product_id' => $p->id, 'qty' => $qty]);
    }

    /** أوردر اتأكد والربط كان غلط (برطمان) */
    private function confirmedWithWrongLink(): OnlineOrder
    {
        $link = ShopifyProductLink::create(['shopify_variant_id' => 4865, 'shopify_product_id' => 48, 'title' => 'ProBar Peanut Butter',
            'product_id' => $this->jar->id, 'units' => 1]);

        $o = OnlineOrder::create(['shopify_id' => 991112, 'number' => '1112', 'customer_name' => 'Mohamed', 'items_count' => 2,
            'subtotal' => 200, 'shipping' => 0, 'total' => 200, 'status' => 'new']);
        OnlineOrderItem::create(['online_order_id' => $o->id, 'shopify_variant_id' => 4865, 'title' => 'ProBar Peanut Butter',
            'product_id' => $this->jar->id, 'units_per' => 1, 'qty' => 2, 'price' => 100, 'total' => 200]);

        $this->actingAs($this->admin)->post(route('online.confirm', $o))->assertSessionHasNoErrors();

        // المالك صلّح الربط بعد التأكيد
        $link->update(['product_id' => $this->bar->id]);

        return $o->fresh();
    }

    public function test_refresh_link_moves_a_confirmed_order_to_the_corrected_product(): void
    {
        $o = $this->confirmedWithWrongLink();
        $oldPick = PickOrder::find($o->pick_order_id);
        $this->assertSame($this->jar->id, (int) $oldPick->items->first()->product_id);

        $this->actingAs($this->admin)->get(route('online.prep'))->assertOk()
            ->assertSee(route('online.relink', $o), false);

        $this->actingAs($this->admin)->post(route('online.relink', $o))->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('preparing', $o->status);
        $this->assertSame($this->bar->id, (int) $o->items()->first()->product_id);
        $this->assertSame('cancelled', $oldPick->fresh()->status);

        $new = PickOrder::with('items')->find($o->pick_order_id);
        $this->assertNotSame($oldPick->id, $new->id);
        $this->assertSame([$this->bar->id], $new->items->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame(2, (int) $new->items->sum('qty_requested'));

        // والتجهيز بيمشي عادي بالمنتج الصح
        $this->actingAs($this->admin)->post(route('online.prep.done', $new))->assertSessionHasNoErrors();
        $this->assertSame(48, (int) BatchLocation::where('product_id', $this->bar->id)->sum('qty'));
        $this->assertSame(5, (int) BatchLocation::where('product_id', $this->jar->id)->sum('qty'), 'the jar was never pulled');
    }

    public function test_refresh_link_is_refused_after_prep_and_for_the_keeper(): void
    {
        $o = $this->confirmedWithWrongLink();

        $keeper = User::create(['name' => 'أمين', 'email' => 'k-rl@x.test', 'code' => 'WHK-RL', 'role' => 'warehouse_keeper',
            'password' => bcrypt('x'), 'active' => true]);
        $this->actingAs($keeper)->post(route('online.relink', $o))->assertForbidden();

        // اتجهز بالقديم — خلاص، البضاعة خرجت
        $this->actingAs($this->admin)->post(route('online.prep.done', $o->pick_order_id));
        $picks = PickOrder::count();

        $this->actingAs($this->admin)->post(route('online.relink', $o))->assertSessionHasErrors('order');
        $this->assertSame($picks, PickOrder::count());
        $this->assertSame($this->jar->id, (int) $o->items()->first()->product_id);
    }

    public function test_a_failed_new_pick_changes_nothing(): void
    {
        $o = $this->confirmedWithWrongLink();
        BatchLocation::where('product_id', $this->bar->id)->update(['qty' => 0]);   // البار خلص
        $oldPickId = $o->pick_order_id;

        $this->actingAs($this->admin)->post(route('online.relink', $o))->assertSessionHasErrors('order');

        $this->assertSame($oldPickId, $o->fresh()->pick_order_id);
        $this->assertSame('requested', PickOrder::find($oldPickId)->status);
        $this->assertSame($this->jar->id, (int) $o->items()->first()->product_id);
    }
}
