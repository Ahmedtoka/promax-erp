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
use App\Services\ShopifyOnline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * باندل شوبيفاي — فاريانت واحد بكذا منتج (٢٦/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «عندي باندل على شوبيفاي، عاوز أربطه بأكتر من منتج،
 * ولما يتجهز يخصم من كل صنف حسب الربط».
 *
 * الباندل هنا: ٢ قطعة من A + قطعة من B في الباندل الواحد.
 */
class OnlineBundleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Product $a;
    private Product $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        $this->warehouse = $this->makeWarehouse();
        Setting::writeMany(['online_warehouse_id' => (string) $this->warehouse->id]);

        $this->a = $this->makeProduct(['code' => 'BNDA', 'name' => 'بار شوكولاتة', 'name_en' => 'Choco Bar', 'cost' => 10]);
        $this->b = $this->makeProduct(['code' => 'BNDB', 'name' => 'بار فول سوداني', 'name_en' => 'Peanut Bar', 'cost' => 7]);

        $this->stock($this->a, 'A01', 100);
        $this->stock($this->b, 'A02', 100);
    }

    private function stock(Product $p, string $code, int $qty): void
    {
        $batch = Batch::create([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id, 'batch_no' => 'B-'.$code,
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => $p->cost,
        ]);
        $loc = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => $code, 'stand' => 'A',
            'level' => (int) substr($code, 1), 'active' => true,
        ]);
        BatchLocation::create([
            'batch_id' => $batch->id, 'location_id' => $loc->id, 'product_id' => $p->id, 'qty' => $qty,
        ]);
    }

    private function onShelf(Product $p): int
    {
        return (int) BatchLocation::where('product_id', $p->id)->sum('qty');
    }

    private function link(): ShopifyProductLink
    {
        return ShopifyProductLink::create([
            'shopify_variant_id' => 555001, 'shopify_product_id' => 555, 'title' => 'Mix Box',
            'units' => 1,
        ]);
    }

    private function order(int $qty, string $status = 'new'): OnlineOrder
    {
        static $n = 0;
        $n++;

        $order = OnlineOrder::create([
            'shopify_id' => 810000 + $n, 'number' => 'BND-'.$n, 'customer_name' => 'عميل باندل',
            'items_count' => 0, 'subtotal' => 300 * $qty, 'shipping' => 0, 'total' => 300 * $qty,
            'status' => $status,
        ]);

        OnlineOrderItem::create([
            'online_order_id' => $order->id, 'shopify_variant_id' => 555001, 'title' => 'Mix Box',
            'qty' => $qty, 'price' => 300, 'total' => 300 * $qty,
        ]);

        return $order;
    }

    private function saveBundle(ShopifyProductLink $link): void
    {
        $this->actingAs($this->admin)->post(route('online.products.bundle', $link), ['parts' => [
            ['product_id' => $this->a->id, 'units' => 2],
            ['product_id' => $this->b->id, 'units' => 1],
        ]])->assertSessionHasNoErrors();
    }

    public function test_saving_a_bundle_links_open_orders_to_every_component(): void
    {
        $link = $this->link();
        $open = $this->order(3);

        $this->saveBundle($link);

        $link->refresh();
        $this->assertTrue($link->isBundle());
        $this->assertSame([
            ['product_id' => $this->a->id, 'units' => 2],
            ['product_id' => $this->b->id, 'units' => 1],
        ], $link->components());

        $item = $open->items()->first();
        $this->assertTrue($item->isBundle());
        $this->assertSame([$this->a->id => 6, $this->b->id => 3], $item->piecesByProduct());
        $this->assertSame(9, $open->fresh()->items_count);
        $this->assertFalse($open->fresh()->load('items')->hasUnmatchedItems());
    }

    public function test_prep_deducts_each_component_times_the_order_quantity(): void
    {
        $link = $this->link();
        $order = $this->order(3);
        $this->saveBundle($link);

        $this->actingAs($this->admin)->post(route('online.confirm', $order->fresh()))->assertSessionHasNoErrors();

        $pick = PickOrder::with('items')->find($order->fresh()->pick_order_id);
        $this->assertSame(6, (int) $pick->items->where('product_id', $this->a->id)->sum('qty_requested'));
        $this->assertSame(3, (int) $pick->items->where('product_id', $this->b->id)->sum('qty_requested'));

        $this->actingAs($this->admin)->post(route('online.prep.done', $pick))->assertSessionHasNoErrors();

        $this->assertSame(94, $this->onShelf($this->a));
        $this->assertSame(97, $this->onShelf($this->b));
        $this->assertSame('ready', $order->fresh()->status);
        // التكلفة من الباتشات: 6×10 + 3×7
        $this->assertEquals(81.0, (float) $order->fresh()->cost_total);
    }

    public function test_returning_one_bundle_puts_each_component_back_on_its_shelf(): void
    {
        $link = $this->link();
        $order = $this->order(3);
        $this->saveBundle($link);

        $this->actingAs($this->admin)->post(route('online.confirm', $order->fresh()));
        $this->actingAs($this->admin)->post(route('online.prep.done', PickOrder::find($order->fresh()->pick_order_id)));
        $order->fresh()->update(['status' => 'shipped', 'shipped_at' => now()]);

        $item = $order->items()->first();
        $this->actingAs($this->admin)->post(route('online.return', $order), ['items' => [$item->id => 1]])
            ->assertSessionHasNoErrors();

        $this->assertSame(96, $this->onShelf($this->a));   // 94 + 2
        $this->assertSame(98, $this->onShelf($this->b));   // 97 + 1
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertEquals(300.0, (float) $order->fresh()->returned_total);
    }

    public function test_sync_rematch_copies_the_bundle_onto_unlinked_lines(): void
    {
        $link = $this->link();
        $order = $this->order(2);   // وصل قبل الربط — البند فاضي

        $link->update([
            'product_id' => $this->a->id, 'units' => 2,
            'bundle' => [['product_id' => $this->a->id, 'units' => 2], ['product_id' => $this->b->id, 'units' => 1]],
        ]);

        $this->assertSame(1, ShopifyOnline::rematchUnlinked());

        $item = $order->items()->first();
        $this->assertSame([$this->a->id => 4, $this->b->id => 2], $item->piecesByProduct());
        $this->assertSame(6, $order->fresh()->items_count);
    }

    public function test_a_single_part_turns_the_bundle_back_into_a_normal_link(): void
    {
        $link = $this->link();
        $open = $this->order(1);
        $this->saveBundle($link);

        $this->actingAs($this->admin)->post(route('online.products.bundle', $link), ['parts' => [
            ['product_id' => $this->b->id, 'units' => 4],
            ['product_id' => null, 'units' => 1],
        ]])->assertSessionHasNoErrors();

        $link->refresh();
        $this->assertFalse($link->isBundle());
        $this->assertSame($this->b->id, (int) $link->product_id);
        $this->assertSame(4, (int) $link->units);
        $this->assertSame([$this->b->id => 4], $open->items()->first()->piecesByProduct());

        // فاضي خالص = رفض مش مسح
        $this->actingAs($this->admin)->post(route('online.products.bundle', $link), ['parts' => [
            ['product_id' => null, 'units' => 1],
        ]])->assertSessionHasErrors('products');
    }

    public function test_confirmed_orders_keep_their_bundle_when_the_link_changes(): void
    {
        $link = $this->link();
        $order = $this->order(1);
        $this->saveBundle($link);
        $this->actingAs($this->admin)->post(route('online.confirm', $order->fresh()));

        $this->actingAs($this->admin)->post(route('online.products.bundle', $link), ['parts' => [
            ['product_id' => $this->b->id, 'units' => 5],
        ]]);

        $this->assertSame([$this->a->id => 2, $this->b->id => 1], $order->items()->first()->piecesByProduct());
    }

    public function test_screens_show_the_bundle_contents(): void
    {
        $link = $this->link();
        $order = $this->order(2);
        $this->saveBundle($link);

        $this->actingAs($this->admin)->get(route('online.products'))
            ->assertOk()->assertSee('Choco Bar')->assertSee('Peanut Bar');
        $this->actingAs($this->admin)->get(route('online.sync'))
            ->assertOk()->assertSee('Mix Box')->assertSee('4 × Choco Bar', false)->assertSee('2 × Peanut Bar', false);
        $this->actingAs($this->admin)->get(route('online.invoice', $order))
            ->assertOk()->assertSee('Mix Box')->assertSee('4 × Choco Bar', false);
    }
}
