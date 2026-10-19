<?php

namespace Tests\Feature\Mfg;

use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mfg\Orders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * أوامر الإنتاج على الأبلكيشن (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦، مهمة ٨):
 * القايمة بفلتر الخط والحالة، ابدأ/خلص بـ`idem_key`، والرولز التانية 403.
 */
class ProductionApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $fg;

    private CostCenter $line1;

    private CostCenter $line2;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fg = $this->makeProduct(['kind' => 'finished', 'code' => 'MB1', 'unit' => 'قطعة']);
        $oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
        $bom->lines()->create(['product_id' => $oats->id, 'qty' => 0.035, 'basis' => 'per_unit', 'sort' => 1]);

        $this->line1 = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'name_en' => 'Line 1', 'kind' => 'line', 'active' => true]);
        $this->line2 = CostCenter::create(['code' => 'L2', 'name' => 'خط ٢', 'name_en' => 'Line 2', 'kind' => 'line', 'active' => true]);
        $wh = $this->makeWarehouse();
        $wh->forceFill(['type' => Warehouse::TYPE_FACTORY])->save();

        $this->pm = $this->makeAdmin(['role' => 'production_manager']);
    }

    private function order(string $batchNo, ?CostCenter $line = null): ProductionOrder
    {
        return Orders::create([
            'product_id' => $this->fg->id, 'line_id' => ($line ?? $this->line1)->id, 'planned_qty' => 1000,
            'batch_no' => $batchNo, 'planned_on' => '2026-10-19',
        ], $this->pm);
    }

    public function test_list_open_orders_filtered_by_line_and_status(): void
    {
        $a = $this->order('B-1');
        $b = $this->order('B-2', $this->line2);
        $c = $this->order('B-3');
        Orders::start($c, $this->pm);
        $cancelled = $this->order('B-4');
        Orders::cancel($cancelled, $this->pm);

        $h = $this->tokenFor($this->pm);

        $all = $this->withHeaders($h)->getJson('/api/production/orders')->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], collect($all->json('orders'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->line1->id, $this->line2->id], collect($all->json('lines'))->pluck('id')->all());

        $byLine = $this->withHeaders($h)->getJson('/api/production/orders?line='.$this->line2->id)->assertOk();
        $this->assertSame([$b->id], collect($byLine->json('orders'))->pluck('id')->all());

        $running = $this->withHeaders($h)->getJson('/api/production/orders?status=in_progress')->assertOk();
        $this->assertSame([$c->id], collect($running->json('orders'))->pluck('id')->all());

        $gone = $this->withHeaders($h)->getJson('/api/production/orders?status=cancelled')->assertOk();
        $this->assertSame([$cancelled->id], collect($gone->json('orders'))->pluck('id')->all());

        $this->withHeaders($h)->getJson('/api/production/orders?status=bogus')
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['status']]);
    }

    public function test_start_and_finish_from_the_app(): void
    {
        $o = $this->order('B-1');
        $h = $this->tokenFor($this->pm);

        Carbon::setTestNow('2026-10-19 08:00:00');
        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/start", ['idem_key' => 'app-s-1'])
            ->assertOk()
            ->assertJsonPath('order.id', $o->id)
            ->assertJsonPath('order.status', 'in_progress')
            ->assertJsonPath('order.started_at', Carbon::parse('2026-10-19 08:00:00')->toIso8601String());

        Carbon::setTestNow('2026-10-19 09:30:00');
        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/finish", ['idem_key' => 'app-f-1', 'output_qty' => 980.0])
            ->assertOk()
            ->assertJsonPath('order.status', 'finished')
            ->assertJsonPath('order.output_qty', 980)
            ->assertJsonPath('order.hours', 1.5);
        Carbon::setTestNow();

        $fresh = $o->fresh();
        $this->assertSame('finished', $fresh->status);
        $this->assertSame(980, $fresh->output_qty);
        $this->assertSame($this->pm->id, $fresh->finished_by);
    }

    public function test_finish_repeated_with_same_key_returns_same_order(): void
    {
        $o = $this->order('B-1');
        Orders::start($o, $this->pm);
        $h = $this->tokenFor($this->pm);

        Carbon::setTestNow('2026-10-19 10:00:00');
        $first = $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/finish", ['idem_key' => 'app-f-9', 'output_qty' => 990])
            ->assertOk()->json('order');
        Carbon::setTestNow('2026-10-19 10:07:00');
        $again = $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/finish", ['idem_key' => 'app-f-9', 'output_qty' => 990])
            ->assertOk()->json('order');
        Carbon::setTestNow();

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame($first['finished_at'], $again['finished_at']);
        $this->assertSame(1, DB::table('idempotency_keys')->where('action', 'mfg_finish')->count());
        $this->assertSame(Carbon::parse('2026-10-19 10:00:00')->toIso8601String(), $o->fresh()->finished_at->toIso8601String());
    }

    public function test_finish_planned_order_is_422_with_message(): void
    {
        $o = $this->order('B-1');
        $h = $this->tokenFor($this->pm);

        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/finish", ['idem_key' => 'x', 'output_qty' => 10])
            ->assertStatus(422)->assertJsonPath('message', __('mfg.order_bad_state'));

        Orders::start($o, $this->pm);
        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/finish", ['idem_key' => 'y', 'output_qty' => 10.5])
            ->assertStatus(422)->assertJsonPath('message', __('mfg.output_whole'));
        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/finish", ['idem_key' => 'z', 'output_qty' => 0])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['output_qty']]);

        $this->assertSame('in_progress', $o->fresh()->status);
    }

    public function test_sales_agent_gets_403(): void
    {
        $o = $this->order('B-1');
        $rep = $this->makeAdmin(['role' => 'sales_agent']);
        $h = $this->tokenFor($rep);

        $this->withHeaders($h)->getJson('/api/production/orders')->assertStatus(403);
        $this->withHeaders($h)->postJson("/api/production/orders/{$o->id}/start", ['idem_key' => 'k'])->assertStatus(403);
        $this->assertSame('planned', $o->fresh()->status);
    }

    public function test_production_manager_can_log_in_to_the_app(): void
    {
        $this->pm->forceFill(['password' => bcrypt('secret')])->save();

        $this->postJson('/api/login', ['login' => $this->pm->email, 'password' => 'secret'])
            ->assertOk()->assertJsonPath('user.role', 'production_manager');
    }
}
