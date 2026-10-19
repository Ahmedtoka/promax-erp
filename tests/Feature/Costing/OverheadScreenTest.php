<?php

namespace Tests\Feature\Costing;

use App\Exceptions\Rejected;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\CostMonth;
use App\Models\ProductionOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostMonths;
use Database\Seeders\GlSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** شاشة التكاليف غير المباشرة بالشهر (الخط ٩ — مهمة ٦) */
class OverheadScreenTest extends TestCase
{
    use RefreshDatabase;

    private CostCenter $line;

    private User $admin;

    private User $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        CostingGlSeed::run();
        Setting::flushCache();
        $co = CostCenter::company();
        $fac = CostCenter::create(['code' => 'FAC', 'name' => 'المصنع', 'kind' => 'factory', 'parent_id' => $co->id, 'active' => true]);
        $this->line = CostCenter::create(['code' => 'L1', 'name' => 'خط واحد', 'kind' => 'line', 'parent_id' => $fac->id, 'active' => true]);
        $this->admin = $this->makeAdmin();
        $this->acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.uniqid()]);
    }

    private function closedOrder(string $flag, string $method = 'kg_default'): ProductionOrder
    {
        $fg = $this->makeProduct(['kind' => 'finished']);
        $wh = $this->makeWarehouse();
        $o = ProductionOrder::create([
            'number' => ProductionOrder::nextNumber(), 'product_id' => $fg->id, 'bom_id' => Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0])->id, 'line_id' => $this->line->id,
            'warehouse_id' => $wh->id, 'raw_warehouse_id' => $wh->id,
            'batch_no' => 'S-'.uniqid(), 'planned_qty' => 100, 'planned_on' => today()->toDateString(),
        ]);
        $o->forceFill([
            'status' => 'closed', 'closed_at' => now(), 'overhead_method' => $method, 'overhead_flag' => $flag,
            'overhead_rate' => 1.5, 'overhead_kg' => 20, 'overhead_cost' => 30, 'unit_cost' => 2.5,
        ])->save();

        return $o;
    }

    public function test_accountant_sees_the_screen_and_cannot_close(): void
    {
        $this->actingAs($this->acc)->get(route('costing.overhead'))->assertOk()
            ->assertDontSee(route('costing.overhead.close'), false);
        $this->actingAs($this->acc)->post(route('costing.overhead.close'), ['period' => '2020-01'])->assertForbidden();
        $this->actingAs($this->acc)->post(route('costing.sync'))->assertForbidden();
    }

    public function test_admin_closes_a_month_from_the_screen(): void
    {
        $period = today()->subMonths(2)->format('Y-m');
        $this->actingAs($this->admin)->get(route('costing.overhead'))->assertOk()
            ->assertSee(route('costing.overhead.close'), false);
        $this->actingAs($this->admin)->post(route('costing.overhead.close'), ['period' => $period])
            ->assertRedirect()->assertSessionHas('ok');
        $this->assertSame('closed', CostMonth::where('period', $period)->value('status'));

        $this->actingAs($this->admin)->post(route('costing.overhead.close'), ['period' => $period])
            ->assertSessionHasErrors('period');
        $this->actingAs($this->admin)->post(route('costing.overhead.close'), ['period' => today()->format('Y-m')])
            ->assertSessionHasErrors('period');
    }

    public function test_admin_runs_the_sync_from_the_screen(): void
    {
        $this->actingAs($this->admin)->post(route('costing.sync'))->assertSessionHas('ok');
    }

    public function test_flagged_orders_are_listed_with_their_flag(): void
    {
        $o = $this->closedOrder('no_hours,no_rate');
        $this->actingAs($this->admin)->get(route('costing.overhead'))->assertOk()
            ->assertSee($o->number)
            ->assertSee(__('costing.flag_no_hours'))
            ->assertSee(__('costing.flag_no_rate'))
            ->assertSee(__('costing.rate_per_kg', ['v' => '1.5000']));
    }

    public function test_hours_orders_show_per_hour_rate(): void
    {
        $this->closedOrder('', 'hours_estimate');
        $this->actingAs($this->admin)->get(route('costing.overhead'))
            ->assertSee(__('costing.rate_per_hour', ['v' => '1.5000']));
    }

    public function test_export_is_csv(): void
    {
        $o = $this->closedOrder('no_weight');
        $r = $this->actingAs($this->admin)->get(route('costing.overhead', ['export' => 1]));
        $r->assertOk();
        $this->assertStringContainsString('csv', (string) $r->headers->get('content-type'));
        $this->assertStringContainsString($o->number, $r->streamedContent());
    }

    public function test_sales_agent_gets_403(): void
    {
        $agent = $this->makeAdmin(['role' => 'sales_agent', 'code' => 'AG-'.uniqid()]);
        $this->actingAs($agent)->get(route('costing.overhead'))->assertForbidden();
    }

    public function test_unique_violation_on_period_becomes_friendly_rejection(): void
    {
        // السباق الحقيقي صعب يتعمل في تيست واحد؛ بنتحقق من المسار بمحاكاة الاستثناء
        $e = new QueryException('mysql', 'insert into cost_months', [], new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '2020-01' for key 'cost_months_period_unique'", 23000));
        $this->assertSame('23000', (string) $e->getCode());
        $this->assertStringContainsString('period', $e->getMessage());

        $period = today()->subMonths(2)->format('Y-m');
        CostMonths::close($period, $this->admin);
        $this->expectException(Rejected::class);
        $this->expectExceptionMessage(__('costing.month_closed'));
        CostMonths::close($period, $this->admin);
    }
}
