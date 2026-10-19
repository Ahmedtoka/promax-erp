<?php

namespace Tests\Feature\Costing;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Batch;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\CostMonth;
use App\Models\CostMonthLine;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductSpec;
use App\Models\ProductTransferPrice;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\CostMonths;
use App\Services\Costing\CostSync;
use App\Services\Costing\Overhead;
use App\Services\Gl\Ledger;
use App\Services\Mfg\Closing;
use App\Services\Mfg\Issues;
use App\Services\Mfg\Orders;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تحميل التكاليف غير المباشرة، قيد الإنتاج، وإقفال شهر التكاليف
 * (الخط ٩ — المهمة ٥، D7/D8/D9/D10، Review Focus 3).
 */
class OverheadTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private CostCenter $fac;

    private CostCenter $l1;

    private CostCenter $l2;

    private Bom $bom;

    private Product $fg;

    private Product $oats;

    private Product $box;

    private Warehouse $raw;

    private Warehouse $fgWh;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        CostingGlSeed::run();
        Setting::flushCache();

        $co = CostCenter::company();
        $this->fac = CostCenter::create(['code' => 'FAC', 'name' => 'المصنع', 'kind' => 'factory', 'parent_id' => $co->id, 'active' => true]);
        $this->l1 = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'parent_id' => $this->fac->id, 'active' => true]);
        $this->l2 = CostCenter::create(['code' => 'L2', 'name' => 'خط ٢', 'kind' => 'line', 'parent_id' => $this->fac->id, 'active' => true]);

        $this->fg = $this->makeProduct(['kind' => 'finished']);
        $this->oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $this->box = $this->makeProduct(['kind' => 'packaging']);
        $this->bom = $bom = Bom::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 5]);
        $bom->lines()->create(['product_id' => $this->oats->id, 'qty' => 0.035, 'basis' => 'per_unit', 'sort' => 1]);
        $bom->lines()->create(['product_id' => $this->box->id, 'qty' => 2, 'basis' => 'per_batch', 'sort' => 2]);
        $this->raw = $this->makeWarehouse();
        $this->fgWh = $this->makeWarehouse();
        $this->fgWh->forceFill(['type' => Warehouse::TYPE_FACTORY, 'cost_center_id' => $this->fac->id])->save();
        $this->pm = $this->makeAdmin(['role' => 'production_manager']);
    }

    private function glOn(): void
    {
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();
    }

    private function rate(?string $v): void
    {
        Setting::write('cost_default_kg_rate', $v);
        Setting::flushCache();
    }

    private function spec(float $grams): void
    {
        ProductSpec::create(['product_id' => $this->fg->id, 'version' => 1, 'status' => 'approved', 'net_weight_g' => $grams]);
    }

    /** أمر منتهي بخامات ١٬٢٣٠ (شوفان ٤٠ × ٣٠ + علب ٣ × ١٠)؛ `$hours` null = من غير ساعات */
    private function finished(?float $hours = null): ProductionOrder
    {
        $o = Orders::create([
            'product_id' => $this->fg->id, 'line_id' => $this->l1->id, 'planned_qty' => 1000,
            'batch_no' => 'MB-'.uniqid(), 'planned_on' => today()->toDateString(),
            'warehouse_id' => $this->fgWh->id, 'raw_warehouse_id' => $this->raw->id,
        ], $this->pm);
        foreach ([[$this->oats, 100, 30], [$this->box, 10, 10]] as [$p, $q, $c]) {
            Batch::create(['product_id' => $p->id, 'warehouse_id' => $this->raw->id, 'batch_no' => 'R-'.uniqid(),
                'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
                'qty_received' => $q, 'qty_remaining' => $q, 'cost' => $c]);
        }
        Orders::start($o, $this->pm);
        Issues::issue($o, $this->oats, 40, $this->pm);
        Issues::issue($o, $this->box, 3, $this->pm);
        Orders::finish($o, $this->pm, 1000);
        $o->refresh()->forceFill(['hours' => $hours])->save();

        return $o->fresh();
    }

    /** أمر بالأرقام مباشرة (للأحواض والإقفال) */
    private function order(CostCenter $line, array $attrs): ProductionOrder
    {
        $o = ProductionOrder::create([
            'number' => ProductionOrder::nextNumber(), 'product_id' => $this->fg->id, 'bom_id' => $this->bom->id, 'line_id' => $line->id,
            'warehouse_id' => $this->fgWh->id, 'raw_warehouse_id' => $this->raw->id,
            'batch_no' => 'X-'.++$this->n, 'planned_qty' => 100, 'planned_on' => '2026-09-01',
        ]);
        $o->forceFill($attrs)->save();

        return $o->fresh();
    }

    private function expense(?CostCenter $center, float $amount, ?string $type = null, string $date = '2026-09-10', string $status = 'posted'): Expense
    {
        $cat = $type === null ? null : ExpenseCategory::create(['code' => 'C'.++$this->n, 'name' => 'بند', 'type' => $type]);

        return Expense::create([
            'number' => 'EXP-'.(70000 + ++$this->n), 'date' => $date, 'account_id' => GlAccount::where('type', 'expense')->value('id'),
            'amount' => $amount, 'paid_from' => 'cash_main', 'status' => $status,
            'category_id' => $cat?->id, 'cost_center_id' => $center?->id,
        ]);
    }

    private function closedMonth(string $period, CostCenter $line, ?float $rate): void
    {
        $m = CostMonth::create(['period' => $period, 'status' => 'closed', 'closed_at' => now()]);
        CostMonthLine::create(['cost_month_id' => $m->id, 'line_id' => $line->id, 'rate' => $rate]);
    }

    private function productionEntry(ProductionOrder $o): ?CostEntry
    {
        return CostEntry::where('kind', 'production')->where('origin_type', $o->getMorphClass())->where('origin_id', $o->id)->first();
    }

    private function flags(?string $f): array
    {
        return array_values(array_filter(explode(',', (string) $f)));
    }

    // ═══ D8 — التحميل عند القفل ═══

    public function test_order_without_hours_is_costed_by_kg_at_default_rate_and_flagged(): void
    {
        $this->rate('3.00');
        $this->spec(70);
        $o = Closing::close($this->finished(null), 1000, $this->pm)->fresh();

        $this->assertEqualsWithDelta(210.00, (float) $o->overhead_cost, 0.001);   // ٧٠ جم × ١٠٠٠ = ٧٠ كجم × ٣
        $this->assertSame('kg_default', $o->overhead_method);
        $this->assertSame(['no_hours'], $this->flags($o->overhead_flag));
        $this->assertEqualsWithDelta(70.0, (float) $o->overhead_kg, 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $o->overhead_rate, 0.0001);
        $this->assertEqualsWithDelta(1440.00, (float) $o->total_cost, 0.001);
        $this->assertSame('1.4400', $o->unit_cost);
        $this->assertSame('1.44', $o->fgBatch->cost);   // (١٢٣٠ + ٢١٠) ÷ ١٠٠٠
    }

    public function test_order_with_zero_recorded_hours_counts_as_no_hours(): void
    {
        $this->rate('3.00');
        $this->spec(70);
        $this->closedMonth('2026-09', $this->l1, 50);
        $o = Closing::close($this->finished(0.0), 1000, $this->pm)->fresh();

        $this->assertSame('kg_default', $o->overhead_method);
        $this->assertSame(['no_hours'], $this->flags($o->overhead_flag));
        $this->assertEqualsWithDelta(210.00, (float) $o->overhead_cost, 0.001);
    }

    public function test_order_without_weight_uses_the_fallback_and_flags_no_weight(): void
    {
        $this->rate('3.00');
        $o = Closing::close($this->finished(null), 1000, $this->pm)->fresh();

        // ٠٫١ كجم × ١٠٠٠ = ١٠٠ كجم × ٣
        $this->assertEqualsWithDelta(100.0, (float) $o->overhead_kg, 0.0001);
        $this->assertEqualsWithDelta(300.00, (float) $o->overhead_cost, 0.001);
        $this->assertSame(['no_hours', 'no_weight'], $this->flags($o->overhead_flag));

        // محتوى الصنف الصافي (GS1) قبل الافتراضي
        $this->fg->forceFill(['net_content' => 250, 'net_uom' => 'g'])->save();
        $this->assertSame(['kg' => 250.0, 'flag' => null], Overhead::kgFor($this->fg->fresh(), 1000));
        $this->fg->forceFill(['net_content' => 1.5, 'net_uom' => 'kg'])->save();
        $this->assertSame(['kg' => 15.0, 'flag' => null], Overhead::kgFor($this->fg->fresh(), 10));
    }

    public function test_no_default_rate_costs_zero_overhead_flagged_no_rate_without_error(): void
    {
        $this->spec(70);
        $o = Closing::close($this->finished(null), 1000, $this->pm)->fresh();

        $this->assertSame('closed', $o->status);
        $this->assertEqualsWithDelta(0.0, (float) $o->overhead_cost, 0.001);
        $this->assertContains('no_rate', $this->flags($o->overhead_flag));
        $this->assertEqualsWithDelta(1230.00, (float) $o->total_cost, 0.001);
        $this->assertSame('1.23', $o->fgBatch->cost);

        $this->rate('0');
        $o2 = Overhead::forClosing($this->finished(null), 1000);
        $this->assertSame(0.0, $o2['amount']);
        $this->assertContains('no_rate', $this->flags($o2['flag']));
    }

    public function test_order_with_hours_uses_three_month_average_rate(): void
    {
        $this->rate('3.00');
        $this->spec(70);
        $this->closedMonth('2026-05', $this->l1, 1000);   // أقدم من آخر ٣ — مش داخل
        $this->closedMonth('2026-07', $this->l1, 10);
        $this->closedMonth('2026-08', $this->l1, 20);
        $this->closedMonth('2026-09', $this->l1, 30);
        $this->closedMonth('2026-06', $this->l2, 999);    // خط تاني

        $this->assertSame(20.0, Overhead::estimateRate($this->l1, Carbon::parse('2026-10-01')));
        $o = Closing::close($this->finished(5), 1000, $this->pm, Carbon::parse('2026-10-19'))->fresh();

        $this->assertSame('hours_estimate', $o->overhead_method);
        $this->assertNull($o->overhead_flag);
        $this->assertEqualsWithDelta(100.00, (float) $o->overhead_cost, 0.001);   // ٢٠ × ٥ س
        $this->assertEqualsWithDelta(20.0, (float) $o->overhead_rate, 0.0001);
        $this->assertSame('1.33', $o->fgBatch->cost);   // (١٢٣٠ + ١٠٠) ÷ ١٠٠٠
    }

    public function test_hours_without_history_falls_back_to_kg_and_flags_no_history(): void
    {
        $this->rate('3.00');
        $this->spec(70);
        $o = Closing::close($this->finished(5), 1000, $this->pm)->fresh();

        $this->assertSame('kg_default', $o->overhead_method);
        $this->assertSame(['no_history'], $this->flags($o->overhead_flag));
        $this->assertEqualsWithDelta(210.00, (float) $o->overhead_cost, 0.001);
    }

    // ═══ D7 — الأحواض ═══

    public function test_pool_splits_factory_expenses_by_line_hours(): void
    {
        $this->expense($this->fac, 900, 'factory');
        $this->expense($this->l1, 100);                       // من غير بند = تشغيلي
        $this->expense($this->l1, 5000, 'asset');             // أصل — بيتهلك، مستبعد
        $this->expense($this->l1, 777, 'payroll');            // فيه مسير معتمد — مستبعد
        $this->expense($this->l1, 60, 'operating', '2026-10-02');   // شهر تاني
        $this->expense($this->l1, 70, 'operating', '2026-09-12', 'pending');   // مش مرحّل

        $run = PayrollRun::create(['period' => '2026-09', 'date' => '2026-09-30', 'status' => 'approved']);
        $emp = Employee::create(['code' => Employee::nextCode(), 'name' => 'عامل']);
        Payslip::create(['payroll_run_id' => $run->id, 'employee_id' => $emp->id, 'cost_center_id' => $this->l1->id,
            'gross' => 300, 'insurance_employer' => 100]);
        $asset = FixedAsset::create(['code' => 'FA-1', 'name' => 'ماكينة', 'cost' => 6000, 'life_months' => 120,
            'start_date' => '2026-01-01', 'cost_center_id' => $this->l1->id]);
        FixedAssetDepreciation::create(['fixed_asset_id' => $asset->id, 'period_key' => '2026-09', 'date' => '2026-09-30', 'amount' => 50]);

        $this->order($this->l1, ['status' => 'finished', 'hours' => 2, 'finished_at' => '2026-09-05 10:00:00']);
        $this->order($this->l2, ['status' => 'finished', 'hours' => 1, 'finished_at' => '2026-09-06 10:00:00']);

        $p = Overhead::pools(Carbon::parse('2026-09-01'));
        $a = $p[$this->l1->id];
        $b = $p[$this->l2->id];
        $this->assertSame(600.0, $a['shared']);
        $this->assertSame(300.0, $b['shared']);
        $this->assertSame(100.0, $a['direct']);
        $this->assertSame(400.0, $a['payroll']);
        $this->assertSame(50.0, $a['depreciation']);
        $this->assertSame(1150.0, $a['pool']);
        $this->assertSame(2.0, $a['hours']);
        $this->assertSame(575.0, $a['rate']);
        $this->assertSame(300.0, $b['pool']);
        $this->assertSame(0.0, $p['factory_unshared']);

        // من غير مسير معتمد بند المرتبات بيدخل
        $run->forceFill(['status' => 'draft'])->save();
        $p = Overhead::pools(Carbon::parse('2026-09-01'));
        $this->assertSame(877.0, $p[$this->l1->id]['direct']);
        $this->assertSame(0.0, $p[$this->l1->id]['payroll']);
    }

    public function test_factory_pool_falls_back_to_output_kg_then_stays_unshared(): void
    {
        $this->spec(100);
        $this->expense($this->fac, 900, 'factory');
        $p = Overhead::pools(Carbon::parse('2026-09-01'));
        $this->assertSame(900.0, $p['factory_unshared']);
        $this->assertSame(0.0, $p[$this->l1->id]['shared']);

        // من غير ساعات ← بكيلو الإنتاج: ٢٠٠ قطعة × ١٠٠ جم = ٢٠ كجم على L1، ١٠ كجم على L2
        $this->order($this->l1, ['status' => 'closed', 'output_qty' => 200, 'closed_at' => '2026-09-20 10:00:00']);
        $this->order($this->l2, ['status' => 'closed', 'output_qty' => 100, 'closed_at' => '2026-09-21 10:00:00']);
        $p = Overhead::pools(Carbon::parse('2026-09-01'));
        $this->assertSame(600.0, $p[$this->l1->id]['shared']);
        $this->assertSame(300.0, $p[$this->l2->id]['shared']);
        $this->assertSame(20.0, $p[$this->l1->id]['output_kg']);
        $this->assertNull($p[$this->l1->id]['rate']);
        $this->assertSame(0.0, $p['factory_unshared']);
    }

    // ═══ D9 — إقفال الشهر ═══

    public function test_month_close_posts_variance_pool_minus_applied(): void
    {
        $this->expense($this->l1, 1000);
        $o = $this->order($this->l1, ['status' => 'closed', 'output_qty' => 100, 'hours' => 4,
            'finished_at' => '2026-09-14 10:00:00', 'closed_at' => '2026-09-15 10:00:00',
            'material_cost' => 500, 'overhead_cost' => 800, 'total_cost' => 1300, 'unit_cost' => 13]);
        $this->glOn();

        $cm = CostMonths::close('2026-09', $this->makeAdmin());

        $this->assertSame('closed', $cm->status);
        $ml = $cm->lines->firstWhere('line_id', $this->l1->id);
        $this->assertSame('1000.00', $ml->pool);
        $this->assertSame('800.00', $ml->applied);
        $this->assertSame('200.00', $ml->variance);
        $this->assertSame('250.0000', $ml->rate);

        $e = CostEntry::where('kind', 'variance')->where('origin_type', $ml->getMorphClass())->where('origin_id', $ml->id)->sole();
        $this->assertSame('2026-09-30', $e->date->toDateString());
        $this->assertSame($this->l1->id, $e->cost_center_id);
        $gl = app(Ledger::class)->autoEntryFor($e);
        $this->assertNotNull($gl);
        $this->assertSame('cost.variance', $gl->rule_key);
        $dr = $gl->lines->firstWhere('account_id', GlAccount::findKey('production_variance')->id);
        $cr = $gl->lines->firstWhere('account_id', GlAccount::findKey('overhead_applied')->id);
        $this->assertSame(200.0, (float) $dr->debit);
        $this->assertSame(200.0, (float) $cr->credit);
        $this->assertSame($this->l1->id, $dr->cost_center_id);
        $this->assertSame($this->l1->id, $cr->cost_center_id);

        // الأوامر المقفولة مابتتحسبش تاني
        $o2 = $o->fresh();
        $this->assertSame('800.00', $o2->overhead_cost);
        $this->assertSame('1300.00', $o2->total_cost);

        // L2 من غير حوض ولا محمّل: قيد انحراف بصفر من غير سطور دفتر
        $this->assertNull(app(Ledger::class)->autoEntryFor(CostEntry::where('kind', 'variance')
            ->where('cost_center_id', $this->l2->id)->sole()), 'انحراف صفر = مفيش قيد');
    }

    public function test_negative_variance_flips_the_pair(): void
    {
        $this->expense($this->l1, 100);
        $this->order($this->l1, ['status' => 'closed', 'output_qty' => 100, 'closed_at' => '2026-09-15 10:00:00', 'overhead_cost' => 250]);
        $this->glOn();
        CostMonths::close('2026-09', $this->makeAdmin());

        $e = CostEntry::where('kind', 'variance')->where('cost_center_id', $this->l1->id)->sole();
        $this->assertSame(-150.0, $e->official());
        $gl = app(Ledger::class)->autoEntryFor($e);
        $this->assertSame(150.0, (float) $gl->lines->firstWhere('account_id', GlAccount::findKey('production_variance')->id)->credit);
        $this->assertSame(150.0, (float) $gl->lines->firstWhere('account_id', GlAccount::findKey('overhead_applied')->id)->debit);
    }

    public function test_month_close_with_zero_hours_puts_whole_pool_in_variance(): void
    {
        $this->expense($this->l1, 500);
        $this->expense($this->fac, 900, 'factory');   // خطوط المصنع من غير ساعات ولا إنتاج ← على المصنع
        $this->glOn();

        $cm = CostMonths::close('2026-09', $this->makeAdmin());

        $ml = $cm->lines->firstWhere('line_id', $this->l1->id);
        $this->assertNull($ml->rate);
        $this->assertSame('0.00', $ml->hours);
        $this->assertSame('500.00', $ml->variance);
        $fac = $cm->lines->firstWhere('line_id', $this->fac->id);
        $this->assertSame('900.00', $fac->variance);
        $this->assertSame('0.00', $fac->applied);

        $e = CostEntry::where('kind', 'variance')->where('cost_center_id', $this->fac->id)->sole();
        $gl = app(Ledger::class)->autoEntryFor($e);
        $this->assertSame(900.0, (float) $gl->lines->firstWhere('account_id', GlAccount::findKey('production_variance')->id)->debit);
        $this->assertSame([$this->fac->id], $gl->lines->pluck('cost_center_id')->unique()->values()->all());
        $this->assertEqualsWithDelta(1400.0, $cm->summary['variance'], 0.001);
    }

    public function test_month_close_refuses_current_month_and_twice(): void
    {
        $admin = $this->makeAdmin();
        foreach ([today()->format('Y-m'), today()->addMonth()->format('Y-m')] as $period) {
            try {
                CostMonths::close($period, $admin);
                $this->fail('expected Rejected');
            } catch (Rejected $e) {
                $this->assertSame(__('costing.month_not_past'), $e->getMessage());
            }
        }

        CostMonths::close('2026-09', $admin);
        try {
            CostMonths::close('2026-09', $admin);
            $this->fail('expected Rejected');
        } catch (Rejected $e) {
            $this->assertSame(__('costing.month_closed'), $e->getMessage());
        }
        $this->assertSame(1, CostMonth::where('period', '2026-09')->count());

        GlPeriod::close('2026-08', $admin);
        try {
            CostMonths::close('2026-08', $admin);
            $this->fail('expected Rejected');
        } catch (Rejected $e) {
            $this->assertSame(__('gl.period_closed', ['period' => '2026-08']), $e->getMessage());
        }
        $this->assertFalse(CostMonth::where('period', '2026-08')->exists());
    }

    // ═══ قيد الإنتاج + التنبيه ═══

    public function test_production_entry_posts_inventory_and_overhead_applied(): void
    {
        $this->rate('3.00');
        $this->spec(70);
        $this->glOn();
        $o = Closing::close($this->finished(null), 1000, $this->pm)->fresh();

        $e = $this->productionEntry($o);
        $this->assertNotNull($e);
        $this->assertSame($this->l1->id, $e->cost_center_id);
        $this->assertSame($this->fac->id, $e->other_center_id);
        $this->assertSame('1230.00', $e->materials);
        $this->assertSame('210.00', $e->overhead);
        $this->assertSame(1440.0, $e->amount('batch'));
        $this->assertSame(1440.0, $e->amount('wavg'));
        $this->assertTrue($e->hasFlag('no_hours'));

        $gl = app(Ledger::class)->autoEntryFor($e);
        $this->assertNotNull($gl);
        $inv = GlAccount::findKey('inventory')->id;
        $this->assertSame(1440.0, round((float) $gl->lines->where('account_id', $inv)->where('cost_center_id', $this->fac->id)->sum('debit'), 2));
        $this->assertSame(1230.0, round((float) $gl->lines->where('account_id', $inv)->where('cost_center_id', $this->l1->id)->sum('credit'), 2));
        $oh = $gl->lines->firstWhere('account_id', GlAccount::findKey('overhead_applied')->id);
        $this->assertSame(210.0, (float) $oh->credit);
        $this->assertSame($this->l1->id, $oh->cost_center_id);

        // إعادة البناء: القيد بالقيم المخزّنة ونفس الـid
        $id = $e->id;
        $this->rate('9.00');
        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-01-01'));
        $this->assertTrue($report->ok, json_encode($report->invariants));
        $e2 = $this->productionEntry($o);
        $this->assertSame($id, $e2->id);
        $this->assertSame('210.00', $e2->overhead);
        $this->assertNotNull(app(Ledger::class)->autoEntryFor($e2));
    }

    public function test_zero_overhead_production_posts_materials_only_and_rebuild_keeps_variance(): void
    {
        $this->spec(70);
        $this->glOn();
        $o = Closing::close($this->finished(null), 1000, $this->pm)->fresh();
        $gl = app(Ledger::class)->autoEntryFor($this->productionEntry($o));
        $this->assertCount(2, $gl->lines);
        $this->assertNull($gl->lines->firstWhere('account_id', GlAccount::findKey('overhead_applied')->id));

        $this->expense($this->l2, 40, null, today()->subMonthNoOverflow()->startOfMonth()->toDateString());
        CostMonths::close(today()->subMonthNoOverflow()->format('Y-m'), $this->makeAdmin());
        $variances = CostEntry::where('kind', 'variance')->pluck('id')->sort()->values()->all();

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-01-01'));
        $this->assertTrue($report->ok, json_encode($report->invariants));
        $this->assertSame($variances, CostEntry::where('kind', 'variance')->pluck('id')->sort()->values()->all());
        $v = CostEntry::where('kind', 'variance')->where('cost_center_id', $this->l2->id)->sole();
        $this->assertNotNull(app(Ledger::class)->autoEntryFor($v));

        // run() مابيلمسش الانحراف ومابيحسبش التحميل تاني
        CostSync::run(Carbon::parse('2026-01-01'));
        $this->assertSame($variances, CostEntry::where('kind', 'variance')->pluck('id')->sort()->values()->all());
    }

    public function test_unit_cost_near_transfer_price_notifies_admins_once(): void
    {
        $this->rate('3.00');
        $this->spec(70);
        $a1 = $this->makeAdmin();
        $a2 = $this->makeAdmin();
        $other = $this->makeAdmin(['role' => 'accountant']);
        ProductTransferPrice::create(['product_id' => $this->fg->id, 'price' => 1.50, 'unit_cost' => 1.2,
            'effective_from' => '2026-01-01', 'approved_by' => $a1->id, 'approved_at' => now()]);

        $o = Closing::close($this->finished(null), 1000, $this->pm)->fresh();   // ١٫٤٤ ≥ ٩٠٪ × ١٫٥٠

        foreach ([$a1, $a2] as $a) {
            $note = AppNotification::where('user_id', $a->id)->sole();
            $this->assertStringContainsString($o->number, $note->title);
            $this->assertSame(route('mfg.orders.show', $o, false), $note->link);
        }
        $this->assertSame(0, AppNotification::where('user_id', $other->id)->count());

        // قفل تاني مرفوض ← مفيش تنبيه تاني، وإعادة المزامنة مابتنبّهش
        try {
            Closing::close($o, 1000, $this->pm);
        } catch (Rejected) {
        }
        CostSync::run(Carbon::parse('2026-01-01'), true);
        $this->assertSame(1, AppNotification::where('user_id', $a1->id)->count());

        // تحت الحد = مفيش تنبيه
        $this->assertSame('ok', \App\Services\Costing\TransferPrice::alertLevel(1.0, ProductTransferPrice::first()));
        $this->assertSame('above', \App\Services\Costing\TransferPrice::alertLevel(1.6, ProductTransferPrice::first()));
        $this->assertSame('none', \App\Services\Costing\TransferPrice::alertLevel(1.6, null));
    }
}
