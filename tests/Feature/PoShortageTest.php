<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Client;
use App\Models\Location;
use App\Models\PickOrder;
use App\Models\PickOrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\PoShortage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * الطلب مقابل المخزون (٩/١١/٢٠٢٦) — التعريفات في App\Services\PoShortage.
 */
class PoShortageTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $wh;

    private Location $loc;

    private Client $client;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wh = $this->makeWarehouse();
        $this->loc = Location::create(['warehouse_id' => $this->wh->id, 'code' => 'A-01', 'stand' => 'A', 'level' => 1]);
        $this->client = $this->makeClient(['channel_id' => $this->makeChannel()->id]);
    }

    private function shelve(Product $p, int $qty, array $batch = []): Batch
    {
        $b = Batch::create(array_merge([
            'product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'batch_no' => 'B'.(++$this->seq),
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => 5,
        ], $batch));
        BatchLocation::create(['batch_id' => $b->id, 'location_id' => $this->loc->id, 'product_id' => $p->id, 'qty' => $qty]);

        return $b;
    }

    /** @param array<int, array{0: Product, 1: int, 2?: int}> $lines [product, qty, delivered] — سعر 10 + ضريبة 14% */
    private function po(array $lines, array $attrs = []): PurchaseOrder
    {
        $po = PurchaseOrder::create(array_merge([
            'number' => 'PO-T'.(++$this->seq), 'client_id' => $this->client->id, 'status' => 'pending',
            'approval_status' => 'pending', 'warehouse_id' => $this->wh->id,
            'total' => 0, 'tax_total' => 0, 'grand_total' => 0,
        ], $attrs));

        foreach ($lines as $l) {
            $po->items()->create([
                'product_id' => $l[0]->id, 'qty' => $l[1], 'delivered_qty' => $l[2] ?? 0,
                'price' => 10, 'total' => 10 * $l[1], 'tax_rate' => 0.14, 'tax' => 1.4 * $l[1],
            ]);
        }

        return $po;
    }

    private function pick(Product $p, int $qty, string $status, ?PurchaseOrder $po = null): PickOrder
    {
        $rep = $this->makeRep();
        $pk = PickOrder::create([
            'number' => 'PK-T'.(++$this->seq), 'warehouse_id' => $this->wh->id, 'assigned_to' => $rep->id,
            'purpose' => $po ? PickOrder::PURPOSE_CUSTOMER_PO : PickOrder::PURPOSE_VAN_LOAD,
            'status' => $status, 'purchase_order_id' => $po?->id,
        ]);
        PickOrderItem::create(['pick_order_id' => $pk->id, 'product_id' => $p->id, 'location_id' => $this->loc->id, 'qty_requested' => $qty]);
        $po?->update(['pick_order_id' => $pk->id]);

        return $pk;
    }

    private function report(array $q = []): array
    {
        $this->actingAs($this->makeAdmin());

        return (new PoShortage(Request::create('/', 'GET', $q)))->report();
    }

    public function test_demand_available_and_gap_follow_the_definitions(): void
    {
        $a = $this->makeProduct(['code' => 'PA']);

        // المتاح: 100 صالح — المنتهي والموقوف بره — وأمر تجهيز عهدة مفتوح حاجز 20
        $this->shelve($a, 100);
        $this->shelve($a, 50, ['expires_on' => today()->subDay()]);
        $this->shelve($a, 30, ['blocked' => true]);
        $this->pick($a, 20, 'requested');
        // أمر تجهيز اتقفل (handed) مابيحجزش حاجة
        $this->pick($a, 999, 'handed');

        $this->po([[$a, 60]]);                       // محتاج 60
        $this->po([[$a, 50]]);                       // محتاج 50
        $this->po([[$a, 10, 4]], ['status' => 'arrived']); // باقي 6
        $covered = $this->po([[$a, 40]], ['approval_status' => 'approved']);
        $this->pick($a, 40, 'handed', $covered);     // متغطّي — خرج للمندوب
        $this->po([[$a, 500]], ['status' => 'delivered']);
        $this->po([[$a, 500]], ['approval_status' => 'rejected']);
        $this->po([[$a, 500]], ['status' => 'cancelled']);

        ['rows' => $rows, 'totals' => $t] = $this->report();
        $r = $rows->firstWhere('product_id', $a->id);

        $this->assertSame(156, $r->ordered_units);   // 60+50+6+40
        $this->assertSame(116, $r->need_units);
        $this->assertSame(40, $r->prepared_units);
        $this->assertSame(100, $r->shelf);
        $this->assertSame(20, $r->held);
        $this->assertSame(80, $r->free);
        $this->assertSame(36, $r->short_units);
        $this->assertEqualsWithDelta(36 * 11.4, $r->short_value, 0.01);
        $this->assertEqualsWithDelta(156 * 11.4, $r->ordered_value, 0.01);
        $this->assertEqualsWithDelta(round((40 + 80) / 156 * 100, 1), $r->coverage, 0.001);
        $this->assertSame(1, $t['short_products']);
        $this->assertSame(36, $t['short_units']);
        // كلهم مستنيين الحسابات ومالهمش أمر تجهيز (NULL مايضيعش العدّ)
        $this->assertSame(116, $t['awaiting_units']);
        $this->assertSame(3, $t['need_orders']);
        $this->assertSame(80, $t['free_for_need']);
    }

    public function test_a_po_with_a_reserving_pick_order_is_covered_not_double_counted(): void
    {
        $a = $this->makeProduct();
        $this->shelve($a, 50);
        $po = $this->po([[$a, 30]], ['approval_status' => 'approved']);
        $this->pick($a, 30, 'picking', $po);

        $r = $this->report()['rows']->first();

        $this->assertSame(30, $r->reserved_units);
        $this->assertSame(0, $r->need_units);
        $this->assertSame(20, $r->free);
        $this->assertSame(0, $r->short_units);
        $this->assertSame(100.0, $r->coverage);
    }

    public function test_orders_are_served_in_due_order_and_flag_what_is_missing(): void
    {
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $this->shelve($a, 70);
        $this->shelve($b, 5);

        $late = $this->po([[$a, 50]], ['due_at' => now()->addDays(3)]);
        $first = $this->po([[$a, 60]], ['due_at' => now()->addDay()]);
        $mixed = $this->po([[$a, 5], [$b, 10]], ['due_at' => now()->addDays(2)]);

        $this->actingAs($this->makeAdmin());
        $orders = (new PoShortage(Request::create('/')))->orders()->keyBy('id');

        $this->assertTrue($orders[$first->id]->can_serve);
        // الأمر المخلوط ناقص الصنف b — مايحجزش a عشان اللي بعده
        $this->assertFalse($orders[$mixed->id]->can_serve);
        $this->assertSame([$b->id => 5], $orders[$mixed->id]->missing);
        $this->assertFalse($orders[$late->id]->can_serve);
        $this->assertSame([$a->id => 40], $orders[$late->id]->missing);

        // درِل داون صنف b: نفس الحكم من الطابور كله + كمية الصنف في الأمر
        $forB = (new PoShortage(Request::create('/')))->orders($b->id);
        $this->assertSame([$mixed->id], $forB->pluck('id')->all());
        $this->assertSame(10, $forB->first()->product_units);
        $this->assertFalse($forB->first()->can_serve);
    }

    public function test_filters_follow_the_po_list_and_the_warehouse(): void
    {
        $a = $this->makeProduct();
        $this->shelve($a, 10);
        $other = $this->makeWarehouse();
        $this->po([[$a, 30]]);
        $this->po([[$a, 7]], ['warehouse_id' => $other->id, 'approval_status' => 'approved']);

        $this->assertSame(37, $this->report()['totals']['ordered_units']);
        $this->assertSame(30, $this->report(['warehouse' => $this->wh->id])['totals']['ordered_units']);
        // مخزن تاني: الرف بتاعه فاضي — كل المطلوب عجز
        $this->assertSame(7, $this->report(['warehouse' => $other->id])['totals']['short_units']);
        $this->assertSame(7, $this->report(['approval' => 'approved'])['totals']['ordered_units']);
        // كروت ops.pos: فلتر «اتسلم» أو «مرفوض» = مفيش مفتوح
        $this->assertSame(0, $this->report(['status' => 'delivered'])['totals']['ordered_units']);
        $this->assertSame(0, $this->report(['approval' => 'rejected'])['totals']['ordered_units']);
    }

    public function test_a_manager_only_sees_demand_from_own_clients(): void
    {
        $a = $this->makeProduct();
        $manager = $this->makeAdmin(['role' => 'manager']);
        $other = $this->makeAdmin(['role' => 'manager']);
        $this->client->update(['manager_id' => $manager->id]);
        $foreign = $this->makeClient(['manager_id' => $other->id]);

        $this->po([[$a, 30]]);
        $this->po([[$a, 9]], ['client_id' => $foreign->id]);

        $this->actingAs($manager);
        $this->assertSame(30, (new PoShortage(Request::create('/')))->report()['totals']['ordered_units']);
        $this->actingAs($other);
        $this->assertSame(9, (new PoShortage(Request::create('/')))->report()['totals']['ordered_units']);
    }

    public function test_screen_renders_with_drill_down_and_only_shortages(): void
    {
        $a = $this->makeProduct(['name_en' => 'Short Item']);
        $b = $this->makeProduct(['name_en' => 'Plenty Item']);
        $this->shelve($a, 5);
        $this->shelve($b, 100);
        $po = $this->po([[$a, 20], [$b, 10]]);

        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('ops.po.shortage'))
            ->assertOk()->assertSee('Short Item')->assertSee('Plenty Item')->assertSee($po->number)
            // الإجابة فوق + الشرح + تولتيب العمود
            ->assertSee(__('shortage.verdict_short'))->assertSee(__('shortage.how'))
            ->assertSee(__('shortage.t_free'), false);

        $this->actingAs($admin)->get(route('ops.po.shortage', ['status' => 'delivered']))
            ->assertOk()->assertSee(__('shortage.verdict_none'))->assertSee(__('shortage.empty_title'));

        $this->actingAs($admin)->get(route('ops.po.shortage', ['short' => 1]))
            ->assertOk()->assertSee('Short Item')->assertDontSee('Plenty Item');

        $this->actingAs($admin)->get(route('ops.po.shortage', ['product' => $a->id]))
            ->assertOk()->assertSee($po->number);
    }

    public function test_access_matches_the_po_list(): void
    {
        foreach (['manager', 'branch_manager', 'warehouse_keeper'] as $role) {
            $this->actingAs($this->makeAdmin(['role' => $role]))
                ->get(route('ops.po.shortage'))->assertOk();
        }

        $this->actingAs($this->makeAdmin(['role' => 'accountant']))
            ->get(route('ops.po.shortage'))->assertForbidden();
    }

    public function test_po_list_shows_demand_boxes_with_a_link_carrying_the_filters(): void
    {
        $a = $this->makeProduct();
        $this->shelve($a, 5);
        $this->po([[$a, 20]]);

        $res = $this->actingAs($this->makeAdmin())->get(route('ops.pos', ['approval' => 'pending']));

        $res->assertOk()
            ->assertSee(__('shortage.box_title'))
            // الرابط بنفس الفلاتر + «كل المفتوح» عشان الأرقام تطابق الشاشة
            ->assertSee(e(route('ops.po.shortage', ['scope' => 'open', 'approval' => 'pending'])), false);
        $this->assertSame(15, $res->viewData('demand')['short_units']);
        $this->assertSame(1, $res->viewData('demand')['open_orders']);

        $res = $this->actingAs($this->makeAdmin())->get(route('ops.pos', ['status' => 'delivered']));
        $this->assertSame(0, $res->viewData('demand')['open_orders']);
    }
    public function test_quick_scope_splits_awaiting_and_covered_and_the_screen_defaults_to_awaiting(): void
    {
        $a = $this->makeProduct(['name_en' => 'Scoped Item']);
        $this->shelve($a, 100);
        $waiting = $this->po([[$a, 30]]);
        $covered = $this->po([[$a, 12]], ['approval_status' => 'approved']);
        $this->pick($a, 12, 'requested', $covered);
        $this->po([[$a, 500]], ['status' => 'delivered']);

        $this->assertSame(42, $this->report(['scope' => 'open'])['totals']['ordered_units']);
        $this->assertSame(30, $this->report(['scope' => 'awaiting'])['totals']['ordered_units']);
        $this->assertSame(12, $this->report(['scope' => 'covered'])['totals']['ordered_units']);
        $this->assertSame(1, $this->report(['scope' => 'covered'])['totals']['open_orders']);

        $admin = $this->makeAdmin();
        // الشاشة من غير scope = «مستني الحسابات»
        $res = $this->actingAs($admin)->get(route('ops.po.shortage'))->assertOk()
            ->assertSee(__('shortage.scope_hint_awaiting'));
        $this->assertSame('awaiting', $res->viewData('filters')['scope']);
        $this->assertSame(30, $res->viewData('t')['ordered_units']);

        // ولوحة الأوامر: نفس الفلتر السريع على القايمة
        $res = $this->actingAs($admin)->get(route('ops.pos', ['scope' => 'awaiting']))->assertOk();
        $this->assertSame([$waiting->id], $res->viewData('pos')->pluck('id')->all());
        $res = $this->actingAs($admin)->get(route('ops.pos', ['scope' => 'covered']))->assertOk();
        $this->assertSame([$covered->id], $res->viewData('pos')->pluck('id')->all());
        // من غير scope اللوحة زي ما هي — كل الأوامر
        $this->assertCount(3, $this->actingAs($admin)->get(route('ops.pos'))->viewData('pos'));
    }

    public function test_available_stock_is_per_warehouse_and_orders_say_where_they_can_be_served_from(): void
    {
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $w2 = $this->makeWarehouse();
        $loc2 = Location::create(['warehouse_id' => $w2->id, 'code' => 'B-01', 'stand' => 'B', 'level' => 1]);

        $this->shelve($a, 10);                         // مخزن ١: a=10
        $b2 = Batch::create(['product_id' => $a->id, 'warehouse_id' => $w2->id, 'batch_no' => 'W2A',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(3),
            'qty_received' => 40, 'qty_remaining' => 40, 'cost' => 5]);
        BatchLocation::create(['batch_id' => $b2->id, 'location_id' => $loc2->id, 'product_id' => $a->id, 'qty' => 40]);
        // حجز في مخزن ٢ مايقدرش ياكل رف مخزن ١
        PickOrderItem::create(['pick_order_id' => PickOrder::create([
            'number' => 'PK-W2', 'warehouse_id' => $w2->id, 'assigned_to' => $this->makeRep()->id,
            'purpose' => PickOrder::PURPOSE_VAN_LOAD, 'status' => 'requested'])->id,
            'product_id' => $a->id, 'location_id' => $loc2->id, 'qty_requested' => 5]);
        $this->shelve($b, 3);                          // مخزن ١: b=3

        $home = $this->po([[$a, 8]], ['due_at' => now()->addHour()]);                 // من مخزنه
        $moved = $this->po([[$a, 30]], ['due_at' => now()->addHours(2)]);             // مخزنه فيه 2 بس ← مخزن ٢
        $split = $this->po([[$a, 6]], ['due_at' => now()->addHours(3)]);              // 2 في ١ + 5 في ٢
        $short = $this->po([[$b, 9]], ['due_at' => now()->addHours(4)]);              // ناقص 6

        $r = $this->report()['rows']->firstWhere('product_id', $a->id);
        $this->assertSame([$w2->id => 35, $this->wh->id => 10], $r->free_by_wh);
        $this->assertSame(45, $r->free);
        $t = $this->report()['totals'];
        $this->assertSame(35, $t['by_warehouse'][$w2->id]['units']);
        $this->assertEqualsWithDelta(35 * 11.4, $t['by_warehouse'][$w2->id]['value'], 0.01);
        $this->assertEqualsWithDelta(48 * 11.4, $t['free_value'], 0.01); // a 45 + b 3

        $this->actingAs($this->makeAdmin());
        $o = (new PoShortage(Request::create('/')))->orders()->keyBy('id');
        $this->assertSame(['can', [$this->wh->id]], [$o[$home->id]->state, $o[$home->id]->from]);
        $this->assertSame(['other', [$w2->id]], [$o[$moved->id]->state, $o[$moved->id]->from]);
        $this->assertSame('split', $o[$split->id]->state);
        $this->assertEqualsCanonicalizing([$this->wh->id, $w2->id], $o[$split->id]->from);
        $this->assertSame(['short', [$b->id => 6]], [$o[$short->id]->state, $o[$short->id]->missing]);

        // الشاشة بتقول المخزن والباتش
        $this->actingAs($this->makeAdmin())->get(route('ops.po.shortage'))->assertOk()
            ->assertSee('W2A')->assertSee(__('shortage.wh_title'));
    }
}
