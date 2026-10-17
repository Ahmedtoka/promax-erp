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
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:online-close-picks (١٧/١٠/٢٠٢٦ — الخط ٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * أمر تجهيز الأونلاين بيفضل `ready` للأبد. الأمر بيصنّف القديم منهم:
 * يتيم إعادة شحن · خلص (اتشحن/اتحصل/رجع) · واقف (جاهز ومااتشحنش) —
 * ومعاينة افتراضياً، والتنفيذ مابيلمسش الواقف ولا المخزون.
 */
class OnlineClosePicksTest extends TestCase
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

        $this->p = $this->makeProduct(['name_en' => 'Close Bar']);
        $batch = Batch::create([
            'product_id' => $this->p->id, 'warehouse_id' => $this->wh->id, 'batch_no' => 'B-CL',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 50, 'qty_remaining' => 50, 'cost' => 10,
        ]);
        $loc = Location::create(['warehouse_id' => $this->wh->id, 'code' => 'C01', 'stand' => 'C', 'level' => 1, 'active' => true]);
        BatchLocation::create(['batch_id' => $batch->id, 'location_id' => $loc->id, 'product_id' => $this->p->id, 'qty' => 50]);
    }

    private function onShelf(): int
    {
        return (int) BatchLocation::where('product_id', $this->p->id)->sum('qty');
    }

    /** أوردر اتأكد واتجهز (جاهز ومتراجع) */
    private function readyOrder(string $number): OnlineOrder
    {
        $o = OnlineOrder::create([
            'shopify_id' => 940000 + (int) $number, 'number' => $number,
            'customer_name' => 'عميل', 'items_count' => 2, 'subtotal' => 200, 'shipping' => 50,
            'total' => 250, 'status' => 'new',
        ]);
        OnlineOrderItem::create(['online_order_id' => $o->id, 'title' => 'Close Bar', 'product_id' => $this->p->id,
            'qty' => 2, 'units_per' => 1, 'price' => 100, 'total' => 200]);

        $this->actingAs($this->admin)->post(route('online.confirm', $o))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('online.prep.done', $o->fresh()->pick_order_id))->assertSessionHasNoErrors();
        $o->fresh()->update(['reviewed_at' => now()]);

        return $o->fresh();
    }

    private function ship(OnlineOrder $o): OnlinePickup
    {
        $pu = OnlinePickup::create(['number' => OnlinePickup::nextNumber(), 'date' => today(), 'created_by' => $this->admin->id]);
        $o->update(['status' => 'shipped', 'pickup_id' => $pu->id, 'shipped_at' => now()]);

        return $pu;
    }

    /** يرجّع كل الأوامر الجاهزة ١٠ أيام لورا (زي الـ42 على اللايف) */
    private function age(): void
    {
        PickOrder::where('status', 'ready')->update(['ready_at' => now()->subDays(10)]);
    }

    /** @return array{0: OnlineOrder, 1: OnlineOrder, 2: OnlineOrder, 3: int} done · reshipped · stuck · old pick id */
    private function scenario(): array
    {
        // ① اتشحن واتحصّل
        $done = $this->readyOrder('2001');
        $this->ship($done);
        $done->update(['status' => 'completed', 'collected_total' => 200, 'collected_at' => now()]);

        // ② اتشحن ← رجع كله ← اتعاد شحنه: أمره القديم بقى يتيم
        $re = $this->readyOrder('2002');
        $oldPick = $re->pick_order_id;
        $this->ship($re);
        $item = $re->items()->first();
        $this->actingAs($this->admin)->post(route('online.return', $re), ['items' => [$item->id => 2]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('online.reship', $re->fresh()))->assertSessionHasNoErrors();

        // ③ جاهز ومتراجع ومااتشحنش
        $stuck = $this->readyOrder('2003');

        $this->age();

        return [$done->fresh(), $re->fresh(), $stuck->fresh(), $oldPick];
    }

    public function test_classifies_old_ready_online_picks(): void
    {
        [$done, $re, $stuck, $oldPick] = $this->scenario();

        $rows = collect(\App\Console\Commands\OnlineClosePicks::classify(7))->keyBy(fn ($r) => $r['pick']->id);

        $this->assertCount(3, $rows);
        $this->assertSame('done', $rows[$done->pick_order_id]['class']);
        $this->assertSame('orphan_reship', $rows[$oldPick]['class']);
        $this->assertSame($re->id, $rows[$oldPick]['order']->id);
        $this->assertSame('stuck', $rows[$stuck->pick_order_id]['class']);

        // أمر إعادة الشحن الجديد لسه «مطلوب» — مش من الجاهزين أصلاً
        $this->assertArrayNotHasKey($re->pick_order_id, $rows->all());
    }

    public function test_dry_run_by_default_changes_nothing(): void
    {
        $this->scenario();
        $before = PickOrder::orderBy('id')->pluck('status', 'id')->all();

        $this->assertSame(0, Artisan::call('promax:online-close-picks'));

        $this->assertSame($before, PickOrder::orderBy('id')->pluck('status', 'id')->all());
        $this->assertStringContainsString('orphan_reship=1', Artisan::output());
    }

    public function test_apply_closes_orphans_only_and_moves_no_stock(): void
    {
        [$done, $re, $stuck, $oldPick] = $this->scenario();
        $shelf = $this->onShelf();

        $this->assertSame(0, Artisan::call('promax:online-close-picks', ['--apply' => true]));

        $this->assertSame('handed', PickOrder::find($oldPick)->status);
        $this->assertNotNull(PickOrder::find($oldPick)->handed_at);
        $this->assertSame('ready', PickOrder::find($done->pick_order_id)->status);
        $this->assertSame('ready', PickOrder::find($stuck->pick_order_id)->status);
        $this->assertSame($shelf, $this->onShelf());

        // مرة تانية = مفيش حاجة (idempotent)
        Artisan::call('promax:online-close-picks', ['--apply' => true]);
        $this->assertStringContainsString('هيتقفل: 0', Artisan::output());
    }

    public function test_include_done_closes_shipped_but_never_stuck(): void
    {
        [$done, , $stuck] = $this->scenario();
        $shelf = $this->onShelf();

        Artisan::call('promax:online-close-picks', ['--apply' => true, '--include-done' => true]);

        $pick = PickOrder::find($done->pick_order_id);
        $this->assertSame('handed', $pick->status);
        $this->assertEquals($done->shipped_at->toDateTimeString(), $pick->handed_at->toDateTimeString());
        $this->assertSame('ready', PickOrder::find($stuck->pick_order_id)->status);
        $this->assertSame($shelf, $this->onShelf());

        // الأوردر الواقف لسه ظاهر في «جاهزة للشحن» وصفحة الأوردر سليمة
        $this->actingAs($this->admin)->get(route('online.ready'))->assertOk()->assertSee('#2003');
        $this->actingAs($this->admin)->get(route('online.view', $done))->assertOk();
    }

    public function test_return_after_close_still_restocks(): void
    {
        [$done] = $this->scenario();
        Artisan::call('promax:online-close-picks', ['--apply' => true, '--include-done' => true]);

        // أوردر اتشحن (مش متحصّل) وأمره اتقفل — المرتجع لازم يرجّع للرف عادي
        $o = $this->readyOrder('2004');
        $this->ship($o);
        $this->age();
        Artisan::call('promax:online-close-picks', ['--apply' => true, '--include-done' => true]);
        $this->assertSame('handed', PickOrder::find($o->pick_order_id)->status);

        $shelf = $this->onShelf();
        $item = $o->items()->first();
        $this->actingAs($this->admin)->post(route('online.return', $o->fresh()), ['items' => [$item->id => 2]])->assertSessionHasNoErrors();

        $this->assertSame($shelf + 2, $this->onShelf());
        $this->assertSame('returned', $o->fresh()->status);
    }
}
