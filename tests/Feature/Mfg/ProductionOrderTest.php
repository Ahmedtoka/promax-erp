<?php

namespace Tests\Feature\Mfg;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mfg\LineHours;
use App\Services\Mfg\Orders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * خطوط الإنتاج وأوامر الإنتاج (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦).
 *
 * ⚠️ الأمر بياخد الـBOM المعتمدة بس، ورقم التشغيلة مايتكررش للمنتج،
 * والبدء/الانتهاء بنفس `idem_key` بيرجّع نفس الأمر.
 */
class ProductionOrderTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private Product $oats;

    private Bom $bom;

    private CostCenter $line;

    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = $this->makeProduct(['kind' => 'finished', 'code' => 'MB1']);
        $this->oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $box = $this->makeProduct(['kind' => 'packaging']);

        $this->bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 5]);
        $this->bom->lines()->create(['product_id' => $this->oats->id, 'qty' => 0.035, 'basis' => 'per_unit', 'sort' => 1]);
        $this->bom->lines()->create(['product_id' => $box->id, 'qty' => 2, 'basis' => 'per_batch', 'sort' => 2]);

        $this->line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'name_en' => 'Line 1', 'kind' => 'line', 'active' => true]);
        $this->wh = $this->makeWarehouse();
        $this->wh->forceFill(['type' => Warehouse::TYPE_FACTORY])->save();
    }

    private function pm(): User
    {
        return $this->makeAdmin(['role' => 'production_manager']);
    }

    private function data(array $over = []): array
    {
        return array_merge([
            'product_id' => $this->fg->id, 'line_id' => $this->line->id, 'planned_qty' => 1000,
            'batch_no' => 'MB1-20261019', 'planned_on' => '2026-10-19',
        ], $over);
    }

    private function order(array $over = []): ProductionOrder
    {
        return Orders::create($this->data($over), $this->pm());
    }

    public function test_order_needs_approved_bom(): void
    {
        $other = $this->makeProduct(['kind' => 'finished']);
        Bom::create(['product_id' => $other->id, 'version' => 1, 'status' => 'draft']);

        $this->actingAs($this->pm())->post(route('mfg.orders.store'), $this->data(['product_id' => $other->id]))
            ->assertSessionHasErrors('order');

        $this->assertSame(0, ProductionOrder::count());

        $this->expectException(Rejected::class);
        $this->expectExceptionMessage(__('mfg.no_approved_bom'));
        $this->order(['product_id' => $other->id]);
    }

    public function test_order_snapshots_bom_lines_with_waste(): void
    {
        $this->actingAs($this->pm())->post(route('mfg.orders.store'), $this->data())
            ->assertSessionHasNoErrors()->assertRedirect();

        $o = ProductionOrder::query()->firstOrFail();
        $this->assertSame('MO-1001', $o->number);
        $this->assertSame('planned', $o->status);
        $this->assertSame($this->bom->id, $o->bom_id);
        // مفيش مخزن متحدد ولا إعداد = أول مخزن مصنع نشط
        $factory = Warehouse::query()->where('type', Warehouse::TYPE_FACTORY)->where('active', true)->orderBy('id')->value('id');
        $this->assertSame($factory, $o->warehouse_id);
        $this->assertSame($factory, $o->raw_warehouse_id);

        $lines = $o->lines;
        $this->assertCount(2, $lines);
        $this->assertSame($this->oats->id, $lines[0]->product_id);
        $this->assertSame('per_unit', $lines[0]->basis);
        $this->assertEqualsWithDelta(36.750, (float) $lines[0]->std_qty, 0.0001);
        $this->assertEqualsWithDelta(0.035, (float) $lines[0]->bom_qty, 0.00001);
        $this->assertEqualsWithDelta(2.100, (float) $lines[1]->std_qty, 0.0001);

        // الإعداد بيغلب أول مخزن مصنع
        $raw = $this->makeWarehouse();
        Setting::write('mfg_raw_warehouse_id', (string) $raw->id);
        $o2 = $this->order(['batch_no' => 'B-2']);
        $this->assertSame($raw->id, $o2->raw_warehouse_id);
        $this->assertSame($factory, $o2->warehouse_id);
        // المخزن المختار بيغلب الاتنين
        $this->assertSame($this->wh->id, $this->order(['batch_no' => 'B-3', 'warehouse_id' => $this->wh->id])->warehouse_id);

        $this->actingAs($this->pm())->get(route('mfg.orders.show', $o))
            ->assertOk()->assertSee('MO-1001')->assertSee('36.75');
    }

    public function test_batch_no_must_be_unique_per_product(): void
    {
        $this->order(['batch_no' => 'X-1']);

        try {
            $this->order(['batch_no' => 'X-1']);
            $this->fail('duplicate batch_no accepted');
        } catch (Rejected $e) {
            $this->assertSame(__('mfg.batch_no_taken'), $e->getMessage());
        }

        // باتش موجود في المخزن بنفس الرقم للمنتج = مرفوض برضه
        Batch::create(['product_id' => $this->fg->id, 'warehouse_id' => $this->wh->id, 'batch_no' => 'OLD-7',
            'produced_on' => today(), 'expires_on' => today()->addYear(), 'qty_received' => 1, 'qty_remaining' => 1, 'cost' => 1]);

        $this->actingAs($this->pm())->post(route('mfg.orders.store'), $this->data(['batch_no' => 'OLD-7']))
            ->assertSessionHasErrors('order');

        // نفس الرقم لمنتج تاني عادي
        $other = $this->makeProduct(['kind' => 'finished']);
        $b2 = Bom::create(['product_id' => $other->id, 'version' => 1, 'status' => 'approved']);
        $b2->lines()->create(['product_id' => $this->oats->id, 'qty' => 1, 'basis' => 'per_unit', 'sort' => 1]);
        $this->assertNotNull($this->order(['product_id' => $other->id, 'batch_no' => 'X-1'])->id);

        $this->assertSame(2, ProductionOrder::count());
    }

    public function test_line_must_be_a_production_line(): void
    {
        $branch = CostCenter::create(['code' => 'BR1', 'name' => 'فرع', 'kind' => 'branch', 'active' => true]);
        $off = CostCenter::create(['code' => 'L9', 'name' => 'خط واقف', 'kind' => 'line', 'active' => false]);

        foreach ([$branch, $off] as $c) {
            try {
                $this->order(['line_id' => $c->id]);
                $this->fail('non-line center accepted');
            } catch (Rejected $e) {
                $this->assertSame(__('mfg.not_a_line'), $e->getMessage());
            }
        }

        $this->assertSame(0, ProductionOrder::count());
    }

    public function test_start_then_finish_sets_timestamps_and_hours(): void
    {
        $pm = $this->pm();
        $o = $this->order();

        Carbon::setTestNow('2026-10-19 08:00:00');
        $this->actingAs($pm)->post(route('mfg.orders.start', $o))->assertSessionHasNoErrors();
        $o->refresh();
        $this->assertSame('in_progress', $o->status);
        $this->assertSame('2026-10-19 08:00:00', $o->started_at->format('Y-m-d H:i:s'));
        $this->assertSame($pm->id, $o->started_by);

        Carbon::setTestNow('2026-10-19 09:30:00');
        $this->actingAs($pm)->post(route('mfg.orders.finish', $o), ['output_qty' => 980])->assertSessionHasNoErrors();
        $o->refresh();
        $this->assertSame('finished', $o->status);
        $this->assertSame(980, $o->output_qty);
        $this->assertSame('2026-10-19 09:30:00', $o->finished_at->format('Y-m-d H:i:s'));
        $this->assertSame('1.50', (string) $o->hours);
        $this->assertSame($pm->id, $o->finished_by);
        $this->assertSame(1.5, LineHours::forOrder($o));

        Carbon::setTestNow();
    }

    public function test_start_twice_with_same_idem_key_returns_same_order(): void
    {
        $pm = $this->pm();
        $o = $this->order();

        Carbon::setTestNow('2026-10-19 08:00:00');
        $first = Orders::start($o, $pm, 'k-1');
        Carbon::setTestNow('2026-10-19 08:05:00');
        $again = Orders::start($o->fresh(), $pm, 'k-1');
        Carbon::setTestNow();

        $this->assertInstanceOf(ProductionOrder::class, $again);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('2026-10-19 08:00:00', $again->started_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, DB::table('idempotency_keys')->where('action', 'mfg_start')->count());

        // من غير مفتاح = الحالة غلط
        $this->expectException(Rejected::class);
        $this->expectExceptionMessage(__('mfg.order_bad_state'));
        Orders::start($o->fresh(), $pm);
    }

    public function test_finish_planned_order_is_refused(): void
    {
        $o = $this->order();

        $this->actingAs($this->pm())->post(route('mfg.orders.finish', $o), ['output_qty' => 10])
            ->assertSessionHasErrors('order');
        $this->assertSame('planned', $o->fresh()->status);
        $this->assertNull($o->fresh()->finished_at);

        // الجودة وأمين المخزن بيشوفوا الأمر بس
        $this->actingAs($this->makeAdmin(['role' => 'quality']))->post(route('mfg.orders.start', $o))->assertForbidden();
        $this->actingAs($this->makeAdmin(['role' => 'warehouse_keeper']))->post(route('mfg.orders.cancel', $o))->assertForbidden();
        $this->actingAs($this->makeAdmin(['role' => 'warehouse_keeper']))->get(route('mfg.orders.show', $o))->assertOk();

        // الإلغاء من المخطط شغال
        $this->actingAs($this->pm())->post(route('mfg.orders.cancel', $o))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $o->fresh()->status);
    }

    public function test_line_hours_for_month(): void
    {
        $base = ['product_id' => $this->fg->id, 'bom_id' => $this->bom->id, 'line_id' => $this->line->id,
            'warehouse_id' => $this->wh->id, 'raw_warehouse_id' => $this->wh->id, 'planned_qty' => 10,
            'planned_on' => '2026-10-01', 'status' => 'finished'];
        ProductionOrder::create($base + ['number' => 'MO-1', 'batch_no' => 'a', 'hours' => 1.5, 'finished_at' => '2026-10-02 10:00:00']);
        ProductionOrder::create($base + ['number' => 'MO-2', 'batch_no' => 'b', 'hours' => 2.25, 'finished_at' => '2026-10-31 23:00:00']);
        ProductionOrder::create($base + ['number' => 'MO-3', 'batch_no' => 'c', 'hours' => 4, 'finished_at' => '2026-11-01 00:10:00']);
        $other = CostCenter::create(['code' => 'L2', 'name' => 'خط ٢', 'kind' => 'line', 'active' => true]);
        ProductionOrder::create(array_merge($base, ['line_id' => $other->id]) + ['number' => 'MO-4', 'batch_no' => 'd', 'hours' => 7, 'finished_at' => '2026-10-05 10:00:00']);

        $this->assertSame(3.75, LineHours::forMonth($this->line, Carbon::parse('2026-10-15')));
        $this->assertSame(0.0, LineHours::forMonth($this->line, Carbon::parse('2026-09-01')));
        $this->assertNull(LineHours::forOrder(ProductionOrder::create($base + ['number' => 'MO-5', 'batch_no' => 'e'])));
    }

    public function test_lines_screen_creates_line_under_fac(): void
    {
        $pm = $this->pm();

        $this->actingAs($pm)->get(route('mfg.lines'))->assertOk();

        $this->actingAs($pm)->post(route('mfg.lines.store'), ['code' => 'L7', 'name' => 'خط البار', 'name_en' => 'Bar Line'])
            ->assertSessionHasNoErrors();

        $l = CostCenter::query()->where('code', 'L7')->firstOrFail();
        $fac = CostCenter::query()->where('code', 'FAC')->firstOrFail();
        $this->assertSame('line', $l->kind);
        $this->assertSame($fac->id, $l->parent_id);
        $this->assertSame('factory', $fac->kind);
        $this->assertSame(CostCenter::company()->id, $fac->parent_id);

        // كود مكرر = مرفوض
        $this->actingAs($pm)->post(route('mfg.lines.store'), ['code' => 'L7', 'name' => 'تاني'])
            ->assertSessionHasErrors('code');

        $this->actingAs($pm)->post(route('mfg.lines.update', $l), ['name' => 'خط البار ٢', 'name_en' => 'Bar 2', 'active' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($l->fresh()->active);
        $this->assertSame('خط البار ٢', $l->fresh()->name);

        // الإعدادات أدمن بس
        $this->actingAs($pm)->post(route('mfg.lines.settings'), ['mfg_raw_warehouse_id' => $this->wh->id])->assertForbidden();
        $this->actingAs($this->makeAdmin())->post(route('mfg.lines.settings'), [
            'mfg_raw_warehouse_id' => $this->wh->id, 'mfg_fg_warehouse_id' => $this->wh->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame((string) $this->wh->id, Setting::read('mfg_fg_warehouse_id'));

        $this->actingAs($this->makeAdmin(['role' => 'quality']))->get(route('mfg.lines'))->assertForbidden();
    }

    public function test_orders_list_filters_and_exports(): void
    {
        $pm = $this->pm();
        $a = $this->order(['batch_no' => 'IN-A']);
        $other = CostCenter::create(['code' => 'L2', 'name' => 'خط ٢', 'name_en' => 'Line 2', 'kind' => 'line', 'active' => true]);
        $b = $this->order(['batch_no' => 'IN-B', 'line_id' => $other->id]);
        Orders::start($b, $pm);
        $this->order(['batch_no' => 'OUT-C', 'planned_on' => '2026-08-01']);

        $this->actingAs($pm)->get(route('mfg.orders', ['line_id' => $this->line->id, 'from' => '2026-10-01']))
            ->assertOk()->assertSee('IN-A')->assertDontSee('IN-B')->assertDontSee('OUT-C');
        $this->actingAs($pm)->get(route('mfg.orders', ['status' => 'in_progress']))
            ->assertOk()->assertSee('IN-B')->assertDontSee('IN-A');
        $this->actingAs($pm)->get(route('mfg.orders', ['product_id' => $this->fg->id]))
            ->assertOk()->assertViewHas('orders', fn ($p) => $p->total() === 3);

        $csv = $this->actingAs($pm)->get(route('mfg.orders', ['export' => 1, 'from' => '2026-10-01', 'to' => '2026-10-31']));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('IN-A', $body);
        $this->assertStringContainsString('IN-B', $body);
        $this->assertStringNotContainsString('OUT-C', $body);
        $this->assertSame('MO-1001', $a->number);
    }
}
