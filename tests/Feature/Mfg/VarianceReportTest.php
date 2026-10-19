<?php

namespace Tests\Feature\Mfg;

use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Mfg\Orders;
use App\Services\Mfg\Variance;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * تقرير الفروق الشهري (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦، مهمة ٧):
 * الأوامر المقفولة بس، مجمّعة بالمنتج + شهر الإنتاج، والتفصيل بالخامة.
 */
class VarianceReportTest extends TestCase
{
    use RefreshDatabase;

    private Product $granola;

    private Product $bar;

    private Product $oats;

    private CostCenter $line;

    private Warehouse $raw;

    private Warehouse $fgWh;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->granola = $this->makeProduct(['kind' => 'finished', 'name' => 'جرانولا', 'name_en' => 'Granola']);
        $this->bar = $this->makeProduct(['kind' => 'finished', 'name' => 'بار', 'name_en' => 'Bar']);
        $this->oats = $this->makeProduct(['kind' => 'raw', 'name' => 'شوفان', 'name_en' => 'Oats']);
        foreach ([$this->granola, $this->bar] as $p) {
            $bom = Bom::create(['product_id' => $p->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
            $bom->lines()->create(['product_id' => $this->oats->id, 'qty' => 0.04, 'basis' => 'per_unit', 'sort' => 1]);
        }
        $this->line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'active' => true]);
        $this->raw = $this->makeWarehouse();
        $this->fgWh = $this->makeWarehouse();
        $this->fgWh->forceFill(['type' => Warehouse::TYPE_FACTORY])->save();
        $this->pm = $this->makeAdmin(['role' => 'production_manager']);
    }

    /** أمر مقفول بأرقامه مباشرة (القفل نفسه متغطّي في CloseOrderTest) */
    private function closed(Product $p, string $on, float $std, float $actual, int $output = 1000, string $status = 'closed'): ProductionOrder
    {
        $o = Orders::create([
            'product_id' => $p->id, 'line_id' => $this->line->id, 'planned_qty' => 1000,
            'batch_no' => 'V-'.uniqid(), 'planned_on' => $on,
            'warehouse_id' => $this->fgWh->id, 'raw_warehouse_id' => $this->raw->id,
        ], $this->pm);
        $waste = round(($actual - $std) / $actual * 100, 2);
        $o->forceFill([
            'status' => $status, 'produced_on' => $on, 'output_qty' => $output,
            'material_cost' => $actual, 'std_material_cost' => $std, 'total_cost' => $actual,
            'waste_pct' => $waste,
        ])->save();
        $o->lines()->first()->update([
            'base_qty_actual' => $std / 30, 'std_qty_actual' => $std / 30, 'actual_qty' => $actual / 30,
            'std_cost' => $std, 'actual_cost' => $actual,
            'variance_qty' => ($actual - $std) / 30, 'variance_value' => $actual - $std, 'waste_pct' => $waste,
        ]);

        return $o;
    }

    private function seedOrders(): void
    {
        $this->closed($this->granola, '2026-10-05', 1200, 1260);
        $this->closed($this->granola, '2026-10-20', 1200, 1240, 900);
        $this->closed($this->granola, '2026-09-11', 600, 600);
        $this->closed($this->bar, '2026-10-07', 300, 330);
        // مش مقفول = برّه التقرير
        $this->closed($this->granola, '2026-10-08', 5000, 9000, 1000, 'finished');
    }

    public function test_variance_groups_by_product_and_month(): void
    {
        $this->seedOrders();

        $rows = Variance::monthly(DateRange::fromRequest(Request::create('/', 'GET')), null);
        $this->assertCount(3, $rows);

        $oct = $rows->first(fn ($r) => $r['product']->id === $this->granola->id && $r['month'] === '2026-10');
        $this->assertSame(2, $oct['orders']);
        $this->assertSame(2000, $oct['planned_qty']);
        $this->assertSame(1900, $oct['output_qty']);
        $this->assertEqualsWithDelta(2400, $oct['std_value'], 0.001);
        $this->assertEqualsWithDelta(2500, $oct['actual_value'], 0.001);
        $this->assertEqualsWithDelta(100, $oct['variance_value'], 0.001);
        // على القيم: (٢٥٠٠ − ٢٤٠٠) / ٢٥٠٠
        $this->assertEqualsWithDelta(4.0, $oct['waste_pct'], 0.01);

        // فلتر المنتج والفترة
        $this->assertCount(1, Variance::monthly(DateRange::fromRequest(Request::create('/', 'GET', ['from' => '2026-10-01'])), $this->bar->id));
        $this->assertCount(2, Variance::monthly(DateRange::fromRequest(Request::create('/', 'GET', ['from' => '2026-10-01'])), null));

        // التفصيل بالخامة
        $lines = Variance::lines(DateRange::fromRequest(Request::create('/', 'GET')), $this->granola->id, '2026-10');
        $this->assertCount(1, $lines);
        $this->assertSame($this->oats->id, $lines->first()['material']->id);
        $this->assertEqualsWithDelta(100, $lines->first()['variance_value'], 0.001);

        // الشاشة + الدريل داون
        $this->actingAs($this->pm)->get(route('mfg.variance'))
            ->assertOk()->assertSee('Granola')->assertSee('2026-09')->assertSee('2,500.00');
        $this->actingAs($this->pm)->get(route('mfg.variance', ['product_id' => $this->granola->id, 'month' => '2026-10']))
            ->assertOk()->assertSee(__('mfg.variance_lines_title', ['product' => $this->granola->displayName(), 'month' => '2026-10']))
            ->assertSee('Oats');

        // الجودة مالهاش التقرير
        $this->actingAs($this->makeAdmin(['role' => 'quality']))->get(route('mfg.variance'))->assertForbidden();
    }

    public function test_variance_export_csv(): void
    {
        $this->seedOrders();

        $csv = $this->actingAs($this->pm)->get(route('mfg.variance', ['export' => 1, 'from' => '2026-10-01']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Granola', $csv);
        $this->assertStringContainsString('2026-10', $csv);
        $this->assertStringContainsString('2500.00', $csv);
        $this->assertStringNotContainsString('2026-09', $csv);
    }
}
