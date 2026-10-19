<?php

namespace Tests\Feature\Costing;

use App\Models\Batch;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductTransferPrice;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\Costing\CostCard;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostSync;
use App\Services\Costing\TransferPrice;
use App\Services\Costing\Valuation;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** كارت التكلفة وسعر التحويل المقترح واعتماده (الخط ٩ — مهمة ٧) */
class CostCardTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private User $admin;

    private User $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        CostingGlSeed::run();
        Setting::write('cost_markup_pct', '20');
        Setting::write('cost_method', 'batch');
        Setting::flushCache();
        Valuation::flush();
        TransferPrice::flush();
        $this->admin = $this->makeAdmin();
        $this->acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.uniqid()]);
    }

    private function batch(Product $p, int $qty, float $cost): Batch
    {
        return Batch::create(['product_id' => $p->id, 'warehouse_id' => $this->makeWarehouse()->id, 'batch_no' => 'B-'.++$this->n,
            'expires_on' => '2027-06-01', 'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => $cost]);
    }

    /** @return array{0:Product,1:Product,2:Product} [finished, raw, pack] — 0.05 raw @40 + 1 pack @1.5 per unit */
    private function recipe(): array
    {
        $fg = $this->makeProduct(['kind' => 'finished', 'cost' => 0]);
        $raw = $this->makeProduct(['kind' => 'raw', 'cost' => 1]);
        $pack = $this->makeProduct(['kind' => 'packaging', 'cost' => 1]);
        $this->batch($raw, 10, 40);
        $this->batch($pack, 10, 1.5);
        $bom = Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
        BomLine::create(['bom_id' => $bom->id, 'product_id' => $raw->id, 'qty' => 0.05, 'basis' => 'per_unit', 'sort' => 1]);
        BomLine::create(['bom_id' => $bom->id, 'product_id' => $pack->id, 'qty' => 1, 'basis' => 'per_unit', 'sort' => 2]);

        return [$fg, $raw, $pack];
    }

    private function closedOrder(Product $fg, float $overhead, int $out): ProductionOrder
    {
        $wh = $this->makeWarehouse();
        $o = ProductionOrder::create([
            'number' => ProductionOrder::nextNumber(), 'product_id' => $fg->id, 'bom_id' => Bom::approvedFor($fg)->id, 'line_id' => CostCenter::create(['code' => 'L'.++$this->n, 'name' => 'خط', 'kind' => 'line', 'parent_id' => CostCenter::company()->id, 'active' => true])->id,
            'warehouse_id' => $wh->id, 'raw_warehouse_id' => $wh->id,
            'batch_no' => 'S-'.uniqid(), 'planned_qty' => $out, 'planned_on' => today()->toDateString(),
        ]);
        $o->forceFill(['status' => 'closed', 'closed_at' => now(), 'output_qty' => $out, 'overhead_cost' => $overhead])->save();

        return $o;
    }

    public function test_card_cost_from_bom_materials_and_overhead(): void
    {
        [$fg] = $this->recipe();
        $this->closedOrder($fg, 70, 100);

        $c = CostCard::for($fg);
        $this->assertSame(2.0, $c['raw']);
        $this->assertSame(1.5, $c['packaging']);
        $this->assertSame(0.7, $c['overhead']);
        $this->assertSame('last_order', $c['overhead_basis']);
        $this->assertSame(4.2, $c['unit']);
        $this->assertSame(20.0, $c['markup_pct']);
        $this->assertSame(5.04, $c['suggested']);
        $this->assertNull($c['tp']);
        $this->assertSame('none', $c['status']);
        $this->assertCount(2, $c['lines']);
    }

    public function test_card_shows_both_methods(): void
    {
        [$fg, $raw] = $this->recipe();
        $this->batch($raw, 10, 50); // آخر سعر 50، المتوسط 45
        $c = CostCard::for($fg);
        $this->assertSame(2.5 + 1.5, $c['unit_batch']);
        $this->assertSame(2.25 + 1.5, $c['unit_wavg']);
        $this->assertSame($c['unit_batch'], $c['unit']);

        Setting::write('cost_method', 'wavg');
        Setting::flushCache();
        $w = CostCard::for($fg);
        $this->assertSame(3.75, $w['unit']);
        $this->assertSame(round(3.75 * 1.2, 2), $w['suggested']);
    }

    public function test_overhead_falls_back_to_kg_default_then_none_and_no_bom_uses_product_cost(): void
    {
        [$fg] = $this->recipe();
        $this->assertSame('none', CostCard::for($fg)['overhead_basis']);

        $fg->update(['net_content' => 500, 'net_uom' => 'g']);
        Setting::write('cost_default_kg_rate', '2');
        Setting::flushCache();
        $c = CostCard::for($fg->fresh());
        $this->assertSame('kg_default', $c['overhead_basis']);
        $this->assertSame(1.0, $c['overhead']);

        $bare = $this->makeProduct(['kind' => 'finished', 'cost' => 7]);
        $b = CostCard::for($bare);
        $this->assertNull($b['bom']);
        $this->assertSame(7.0, $b['unit']);
        $this->assertSame(7.0, $b['unit_wavg']);
        $this->assertSame(0.0, $b['overhead']);
    }

    public function test_status_near_and_above_against_approved_price(): void
    {
        [$fg] = $this->recipe();
        $this->closedOrder($fg, 70, 100); // unit 4.20
        $tp = fn (float $price) => ProductTransferPrice::create(['product_id' => $fg->id, 'price' => $price, 'unit_cost' => 4,
            'effective_from' => today()->toDateString(), 'approved_by' => $this->admin->id, 'approved_at' => now()]);

        $tp(5.0);
        TransferPrice::flush();
        $this->assertSame('ok', CostCard::for($fg)['status']);

        $tp(4.5);
        TransferPrice::flush();
        $this->assertSame('near', CostCard::for($fg)['status']);

        $tp(4.0);
        TransferPrice::flush();
        $this->assertSame('above', CostCard::for($fg)['status']);
    }

    public function test_only_admin_approves_and_history_lists_it(): void
    {
        [$fg] = $this->recipe();
        $this->closedOrder($fg, 70, 100);

        $this->actingAs($this->acc)->post(route('costing.cards.approve', $fg), ['price' => 5.04])->assertForbidden();
        $this->actingAs($this->acc)->get(route('costing.cards'))->assertOk()
            ->assertDontSee(route('costing.cards.approve', $fg), false);
        $this->assertSame(0, ProductTransferPrice::count());

        $this->actingAs($this->admin)->get(route('costing.cards'))->assertOk()
            ->assertSee((string) \Illuminate\Support\Js::from(route('costing.cards.approve', $fg)), false);
        $this->actingAs($this->admin)->post(route('costing.cards.approve', $fg), ['price' => 5.04, 'note' => 'ok'])
            ->assertRedirect()->assertSessionHas('ok');
        $row = ProductTransferPrice::first();
        $this->assertSame('5.04', (string) $row->price);
        $this->assertSame('4.2000', (string) $row->unit_cost);
        $this->assertSame('20.00', (string) $row->markup_pct);
        $this->assertSame($this->admin->id, (int) $row->approved_by);

        $this->actingAs($this->acc)->get(route('costing.cards', ['tab' => 'history']))->assertOk()
            ->assertSee($fg->displayName())->assertSee('5.04');
        $this->actingAs($this->admin)->post(route('costing.cards.approve', $fg), ['price' => 0])->assertSessionHasErrors('price');

        $out = $this->actingAs($this->acc)->get(route('costing.cards', ['tab' => 'history', 'from' => '2020-01-01', 'to' => '2020-01-31']));
        $out->assertDontSee('5.04');
    }

    public function test_non_finished_product_cannot_be_approved(): void
    {
        $raw = $this->makeProduct(['kind' => 'raw']);
        $this->actingAs($this->admin)->post(route('costing.cards.approve', $raw), ['price' => 5])->assertNotFound();
        $this->assertSame(0, ProductTransferPrice::count());
    }

    public function test_new_approval_does_not_change_past_transfers(): void
    {
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        $co = CostCenter::company();
        $fac = CostCenter::create(['code' => 'FAC', 'name' => 'المصنع', 'name_en' => 'Factory', 'kind' => 'factory', 'parent_id' => $co->id, 'active' => true]);
        $maadi = CostCenter::maadi();
        $from = $this->makeWarehouse();
        $from->update(['cost_center_id' => $fac->id]);
        $to = $this->makeWarehouse();
        $to->update(['cost_center_id' => $maadi->id]);

        $p = $this->makeProduct(['kind' => 'finished']);
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => 11, 'unit_cost' => 8, 'effective_from' => '2026-08-01',
            'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $t = StockTransfer::create(['number' => 'TRF-96001', 'kind' => 'wh_wh', 'from_warehouse_id' => $from->id,
            'to_warehouse_id' => $to->id, 'status' => 'received', 'sent_on' => '2026-08-10', 'received_on' => '2026-08-10']);
        StockTransferItem::create(['stock_transfer_id' => $t->id, 'product_id' => $p->id, 'batch_no' => 'B-1',
            'qty_sent' => 10, 'qty_received' => 10, 'qty_short' => 0, 'cost' => 8]);
        CostSync::settleTransfer($t);
        $entry = fn () => CostEntry::where('kind', 'transfer')->where('origin_id', $t->id)->first();
        $this->assertSame(110.0, (float) $entry()->amount_transfer);

        $this->actingAs($this->admin)->post(route('costing.cards.approve', $p), ['price' => 15])->assertSessionHas('ok');
        TransferPrice::flush();
        CostSync::run(Carbon::parse('2026-08-01'), true);

        $this->assertSame(110.0, (float) $entry()->amount_transfer);
        $this->assertSame('15.00', (string) TransferPrice::effective($p->id, today())->price);
    }

    public function test_cards_export_csv(): void
    {
        [$fg] = $this->recipe();
        $r = $this->actingAs($this->acc)->get(route('costing.cards', ['export' => 1]));
        $r->assertOk();
        $this->assertStringContainsString('csv', (string) $r->headers->get('content-type'));
        $this->assertStringContainsString($fg->displayName(), $r->streamedContent());

        $h = $this->actingAs($this->acc)->get(route('costing.cards', ['tab' => 'history', 'export' => 1]));
        $h->assertOk();
        $this->assertStringContainsString('csv', (string) $h->headers->get('content-type'));
    }

    public function test_zero_cost_products_raise_a_warning(): void
    {
        $this->makeProduct(['kind' => 'finished', 'cost' => 0]);
        $this->actingAs($this->acc)->get(route('costing.cards'))->assertOk()
            ->assertSee(__('costing.zero_cost_warning', ['n' => 1]));
    }

    public function test_sales_agent_gets_403(): void
    {
        $agent = $this->makeAdmin(['role' => 'sales_agent', 'code' => 'AG-'.uniqid()]);
        $this->actingAs($agent)->get(route('costing.cards'))->assertForbidden();
    }
}
