<?php

namespace Tests\Feature\Costing;

use App\Models\Batch;
use App\Models\Bom;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPostingRule;
use App\Models\Gl\GlRebuildRun;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductTransferPrice;
use App\Models\Setting;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Costing\CostingGlSeed;
use App\Services\Costing\TransferPrice;
use App\Services\Costing\Valuation;
use App\Services\Gl\GoLive\GoLiveWizard;
use App\Services\Gl\RebuildDiff;
use App\Services\Mfg\Closing;
use App\Services\Mfg\Issues;
use App\Services\Mfg\Orders;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** معالج التشغيل — خطوة ٧ (إعدادات التكاليف) وخطوة ٩ (التشغيل الفعلي) — الخط ٩، المهمة ٩ */
class GoLiveCostingTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private const SETTINGS = [
        'cost_method' => 'batch', 'cost_default_kg_rate' => '3.5', 'cost_unit_kg_fallback' => '0.1',
        'cost_markup_pct' => '20', 'cost_alert_pct' => '90', 'cost_branch_alloc' => 'sales',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        Valuation::flush();
        TransferPrice::flush();
    }

    protected function tearDown(): void
    {
        RebuildDiff::removeCheck('t9_fail');
        parent::tearDown();
    }

    private function finishedWithBom(): Product
    {
        $p = $this->makeProduct(['kind' => 'finished']);
        Bom::create(['product_id' => $p->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);

        return $p;
    }

    private function tp(Product $p, float $price = 11): void
    {
        ProductTransferPrice::create(['product_id' => $p->id, 'price' => $price, 'unit_cost' => 8, 'effective_from' => '2026-08-01',
            'approved_by' => $this->makeAdmin()->id, 'approved_at' => now()]);
        TransferPrice::flush();
    }

    // ═══ خطوة ٧ ═══

    public function test_step7_red_until_seeded_and_saved_then_yellow_without_transfer_prices_then_green(): void
    {
        $admin = $this->makeAdmin();
        $fg = $this->finishedWithBom();
        $this->makeProduct(['kind' => 'finished']); // من غير وصفة معتمدة = مالوش دعوة

        $s = GoLiveWizard::step(7);
        $this->assertSame('costing', $s->key());
        $this->assertSame('red', $s->state()->level);
        $this->assertContains(__('golive.s7_not_seeded'), $s->state()->messages);
        $this->assertContains(__('golive.s7_not_saved'), $s->state()->messages);

        CostingGlSeed::run();
        $this->assertSame('red', GoLiveWizard::step(7)->state()->level);

        $this->actingAs($admin)->post(route('gl.golive.costing.save'), self::SETTINGS)->assertRedirect(route('gl.golive.show', 7));
        Setting::flushCache();
        $st = GoLiveWizard::step(7)->state();
        $this->assertSame('yellow', $st->level);
        $this->assertContains(__('golive.s7_no_tp', ['n' => 1]), $st->messages);

        $page = $this->actingAs($admin)->get(route('gl.golive.show', 7))->assertOk();
        $page->assertSee(route('costing.cards'), false)->assertSee($fg->displayName())->assertSee(__('golive.s7_method_note'));

        // الكيلو الافتراضي اتشال ⇒ أحمر تاني
        Setting::write('cost_default_kg_rate', '');
        Setting::flushCache();
        $this->assertContains(__('golive.s7_no_rate'), GoLiveWizard::step(7)->state()->messages);
        Setting::write('cost_default_kg_rate', '3.5');
        Setting::flushCache();

        $this->tp($fg);
        $this->assertSame('green', GoLiveWizard::step(7)->state()->level);

        // السويتش مقفول ⇒ تنبيه إن المعاينة محتاجاه
        Setting::write('gl_enabled', '0');
        Setting::flushCache();
        $st = GoLiveWizard::step(7)->state();
        $this->assertSame('yellow', $st->level);
        $this->assertContains(__('golive.s7_gl_off'), $st->messages);
    }

    public function test_step7_save_validates_and_stores_settings(): void
    {
        $admin = $this->makeAdmin();
        foreach ([
            ['cost_method' => 'fifo'], ['cost_default_kg_rate' => '0'], ['cost_unit_kg_fallback' => '-1'],
            ['cost_markup_pct' => '501'], ['cost_alert_pct' => '0'], ['cost_alert_pct' => '101'], ['cost_branch_alloc' => 'area'],
        ] as $bad) {
            $this->actingAs($admin)->post(route('gl.golive.costing.save'), $bad + self::SETTINGS)
                ->assertSessionHasErrors(array_key_first($bad));
        }
        Setting::flushCache();
        $this->assertNull(Setting::read('cost_settings_saved_at'));

        $this->actingAs($admin)->post(route('gl.golive.costing.save'), ['cost_method' => 'wavg', 'cost_branch_alloc' => 'qty',
            'cost_markup_pct' => '0'] + self::SETTINGS)->assertSessionHasNoErrors();
        Setting::flushCache();
        $this->assertSame('wavg', Setting::read('cost_method'));
        $this->assertSame('qty', Setting::read('cost_branch_alloc'));
        $this->assertSame('0', Setting::read('cost_markup_pct'));
        $this->assertSame('3.5', Setting::read('cost_default_kg_rate'));
        $this->assertSame((string) $admin->id, Setting::read('cost_settings_saved_by'));
    }

    public function test_step7_seed_button_dry_run_then_real(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post(route('gl.golive.costing.seed'), ['dry_run' => 1])
            ->assertRedirect(route('gl.golive.show', 7))->assertSessionHas('costing_seed');
        $this->assertFalse(CostingGlSeed::ready());
        $this->assertSame(6, session('costing_seed')['accounts_created']);
        $this->assertTrue(session('costing_seed')['dry']);
        $this->actingAs($admin)->get(route('gl.golive.show', 7))->assertOk()->assertSee(__('golive.s7_seed_apply'));

        $this->actingAs($admin)->post(route('gl.golive.costing.seed'), ['dry_run' => 0])->assertRedirect(route('gl.golive.show', 7));
        $this->assertTrue(CostingGlSeed::ready());

        // شجرة من غير جذر ٥ ⇒ الرفض مترجم والمعاينة بتبينه
        GlPostingRule::query()->delete();
        GlAccount::query()->update(['parent_id' => null]);
        GlAccount::query()->delete();
        GlPostingRule::flush();
        $this->actingAs($admin)->post(route('gl.golive.costing.seed'), ['dry_run' => 0])
            ->assertSessionHasErrors(['golive' => __('costing.seed_empty_tree')]);
    }

    // ═══ خطوة ٩ ═══

    public function test_step9_locked_until_preview_approved(): void
    {
        $admin = $this->makeAdmin();
        $s = GoLiveWizard::step(9);
        $this->assertSame('run', $s->key());
        $this->assertSame('todo', $s->state()->level);
        $this->actingAs($admin)->get(route('gl.golive.show', 9))->assertOk()
            ->assertSee(__('golive.s9_note_locked'))->assertDontSee('id="golive-run-btn"', false);

        $this->actingAs($admin)->post(route('gl.golive.run'))->assertSessionHasErrors('golive');
        $this->assertSame(0, GlRebuildRun::where('mode', 'execute')->count());

        $this->approvedPreview($admin);
        $this->actingAs($admin)->get(route('gl.golive.show', 9))->assertOk()
            ->assertSee(__('golive.s9_note_ready'))->assertSee('id="golive-run-btn"', false);
    }

    public function test_step9_run_executes_enables_gl_and_turns_green(): void
    {
        [$admin] = $this->costingWorld();
        $this->assertSame(0, GlEntry::where('rule_key', 'like', 'cost.%')->count());
        $this->approvedPreview($admin);

        $this->actingAs($admin)->post(route('gl.golive.run'))->assertRedirect(route('gl.golive.show', 9))->assertSessionHas('ok');

        Setting::flushCache();
        $this->assertSame('1', Setting::read('gl_enabled'));
        $this->assertNotNull(Setting::read('gl_golive_run_at'));
        $run = GlRebuildRun::where('mode', 'execute')->sole();
        $this->assertSame('ok', $run->status, (string) $run->error);
        foreach (['cost.sale', 'cost.transfer', 'cost.production'] as $rule) {
            $this->assertGreaterThan(0, GlEntry::where('rule_key', $rule)->count(), $rule);
        }
        $cogs = collect($run->checks)->firstWhere('key', 'cost_cogs');
        $this->assertNotNull($cogs);
        $this->assertTrue($cogs['ok']);
        $this->assertEquals(30.0, $cogs['expected'], 'بيع ٣ × ١٠ بتكلفة الباتش');
        $sums = DB::table('gl_lines')->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertEqualsWithDelta((float) $sums->d, (float) $sums->c, 0.005);

        $this->assertSame('green', GoLiveWizard::step(9)->state()->level);
        $page = $this->actingAs($admin)->get(route('gl.golive.show', 9))->assertOk();
        foreach (['gl.trial_balance', 'gl.balance_sheet', 'gl.income', 'costing.pnl'] as $r) {
            $page->assertSee(route($r), false);
        }
        $p = $this->actingAs($admin)->getJson(route('gl.golive.run.progress'))->assertOk();
        $this->assertSame('ok', $p->json('status'));

        // نفس المعاينة مرة تانية = مرفوض (والتشغيل الناجح بيفضل أخضر)
        $this->actingAs($admin)->post(route('gl.golive.run'))->assertSessionHasErrors('golive');
        $this->assertSame('green', GoLiveWizard::step(9)->state()->level);
    }

    public function test_step9_failure_rolls_back_everything_and_shows_the_reason(): void
    {
        [$admin] = $this->costingWorld();
        $this->approvedPreview($admin);
        $entries = GlEntry::count();
        $lines = DB::table('gl_lines')->count();
        RebuildDiff::addCheck('t9_fail', fn () => ['label_key' => 'golive.check_cost_cogs', 'expected' => 1, 'actual' => 2, 'ok' => false]);

        $this->actingAs($admin)->post(route('gl.golive.run'))->assertRedirect(route('gl.golive.show', 9))->assertSessionHas('err');

        Setting::flushCache();
        $this->assertSame('0', Setting::read('gl_enabled'));
        $this->assertNull(Setting::read('gl_golive_run_at'));
        $this->assertSame($entries, GlEntry::count());
        $this->assertSame($lines, DB::table('gl_lines')->count());
        $run = GlRebuildRun::where('mode', 'execute')->sole();
        $this->assertSame('failed', $run->status);
        $msg = __('golive.s9_phase_checks').': '.__('golive.rebuild_checks_failed');
        $this->assertSame($msg, $run->error);

        $st = GoLiveWizard::step(9)->state();
        $this->assertSame('red', $st->level);
        $this->assertContains(__('golive.s9_failed', ['msg' => $msg]), $st->messages);
        $this->actingAs($admin)->get(route('gl.golive.show', 9))->assertOk()->assertSee($msg);

        $p = $this->actingAs($admin)->getJson(route('gl.golive.run.progress'))->assertOk();
        $this->assertSame('failed', $p->json('status'));
        $this->assertSame($msg, $p->json('message'));
    }

    public function test_progress_endpoint_returns_json(): void
    {
        \Illuminate\Support\Facades\Cache::store('file')->forget('golive_run_progress');
        $acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.uniqid()]);
        $this->actingAs($acc)->getJson(route('gl.golive.run.progress'))->assertOk()
            ->assertJsonStructure(['phase', 'done', 'total', 'status', 'message'])
            ->assertJson(['status' => 'idle', 'done' => 0]);
    }

    public function test_accountant_cannot_run(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.uniqid()]);
        $this->approvedPreview($this->makeAdmin());
        $this->actingAs($acc)->post(route('gl.golive.run'))->assertForbidden();
        $this->actingAs($acc)->post(route('gl.golive.costing.save'), self::SETTINGS)->assertForbidden();
        $this->actingAs($acc)->post(route('gl.golive.costing.seed'), ['dry_run' => 1])->assertForbidden();
        $this->actingAs($acc)->get(route('gl.golive.show', 9))->assertOk()->assertDontSee('id="golive-run-btn"', false);
        $this->assertSame(0, GlRebuildRun::where('mode', 'execute')->count());
    }

    public function test_cost_cogs_check_is_zero_when_not_seeded_and_excludes_markup(): void
    {
        $check = fn () => collect(app(RebuildDiff::class)->checks())->firstWhere('key', 'cost_cogs');
        $c = $check();
        $this->assertNotNull($c, 'الفحص متسجّل');
        $this->assertSame([0.0, 0.0, true], [$c['expected'], $c['actual'], $c['ok']]);

        // بيع فرع بسعر تحويل: الهامش على حساب التكلفة مايدخلش في الفحص
        CostingGlSeed::run();
        GlPostingRule::flush();
        $p = $this->makeProduct(['kind' => 'finished']);
        $this->tp($p, 15);
        $this->sale($p, 2, 10);
        $cogsGl = GlAccount::findKey('cogs')->balanceBetween(null, null);
        $this->assertEquals(30.0, $cogsGl, 'تكلفة ٢٠ + هامش ١٠');
        $c = $check();
        $this->assertEquals(20.0, $c['expected']);
        $this->assertEquals(20.0, $c['actual']);
        $this->assertTrue($c['ok']);
    }

    // ═══ مساعدات ═══

    private function approvedPreview(User $by): GlRebuildRun
    {
        return GlRebuildRun::create(['mode' => 'preview', 'from_date' => '2026-08-01', 'status' => 'ok', 'checks' => [],
            'summary' => [], 'rows' => [], 'approved_at' => now(), 'approved_by' => $by->id, 'created_by' => $by->id]);
    }

    private function sale(Product $p, int $qty, float $unitCost, string $date = '2026-08-10'): Transaction
    {
        $client = $this->makeClient();
        $total = $qty * 20.0;
        $inv = Invoice::create(['number' => 'INV-'.(96000 + ++$this->n), 'client_id' => $client->id, 'user_id' => $this->makeRep()->id,
            'payment' => 'credit', 'subtotal' => $total, 'discount' => 0, 'total' => $total, 'tax_total' => 0, 'grand_total' => $total]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $p->id, 'qty' => $qty, 'list_price' => 20, 'price' => 20,
            'unit_cost' => $unitCost, 'total' => $total, 'tax_rate' => 0, 'tax' => 0]);

        return Transaction::create(['client_id' => $client->id, 'date' => $date, 'memo' => $inv->number, 'debit' => $total,
            'credit' => 0, 'tax' => 0, 'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id]);
    }

    /**
     * عالم ما قبل التشغيل (السويتش مقفول): بيع، تحويل مصنع ← فرع، وأمر إنتاج مقفول.
     *
     * @return array{0: User}
     */
    private function costingWorld(): array
    {
        CostingGlSeed::run();
        Setting::write('gl_enabled', '0');
        Setting::flushCache();
        foreach (self::SETTINGS as $k => $v) {
            Setting::write($k, $v);
        }
        Setting::flushCache();
        $admin = $this->makeAdmin();

        $co = CostCenter::company();
        $fac = CostCenter::create(['code' => 'FAC', 'name' => 'المصنع', 'name_en' => 'Factory', 'kind' => 'factory', 'parent_id' => $co->id, 'active' => true]);
        $line = CostCenter::create(['code' => 'L1', 'name' => 'خط ١', 'kind' => 'line', 'parent_id' => $fac->id, 'active' => true]);
        $maadi = CostCenter::maadi();

        // بيع: ٣ × تكلفة ١٠
        $this->sale($this->makeProduct(), 3, 10);

        // تحويل مصنع ← فرع بسعر تحويل
        $fg = $this->makeProduct(['kind' => 'finished']);
        $this->tp($fg, 11);
        $facWh = $this->makeWarehouse();
        $facWh->forceFill(['type' => Warehouse::TYPE_FACTORY, 'cost_center_id' => $fac->id])->save();
        $maadiWh = $this->makeWarehouse();
        $maadiWh->update(['cost_center_id' => $maadi->id]);
        $t = StockTransfer::create(['number' => 'TRF-'.(97000 + ++$this->n), 'kind' => 'wh_wh',
            'from_warehouse_id' => $facWh->id, 'to_warehouse_id' => $maadiWh->id,
            'status' => 'received', 'sent_on' => '2026-08-10', 'received_on' => '2026-08-10']);
        StockTransferItem::create(['stock_transfer_id' => $t->id, 'product_id' => $fg->id, 'batch_no' => 'B-'.++$this->n,
            'qty_sent' => 10, 'qty_received' => 10, 'qty_short' => 0, 'cost' => 8]);

        // أمر إنتاج مقفول
        $pm = $this->makeAdmin(['role' => 'production_manager', 'code' => 'PM-'.uniqid()]);
        $oats = $this->makeProduct(['kind' => 'raw', 'unit' => 'كجم']);
        $bom = Bom::create(['product_id' => $fg->id, 'version' => 1, 'status' => 'approved', 'waste_pct' => 0]);
        $bom->lines()->create(['product_id' => $oats->id, 'qty' => 0.04, 'basis' => 'per_unit', 'sort' => 1]);
        $raw = $this->makeWarehouse();
        $o = Orders::create(['product_id' => $fg->id, 'line_id' => $line->id, 'planned_qty' => 100,
            'batch_no' => 'MB-'.uniqid(), 'planned_on' => today()->toDateString(),
            'warehouse_id' => $facWh->id, 'raw_warehouse_id' => $raw->id], $pm);
        Batch::create(['product_id' => $oats->id, 'warehouse_id' => $raw->id, 'batch_no' => 'R-'.uniqid(),
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 10, 'qty_remaining' => 10, 'cost' => 30]);
        Orders::start($o, $pm);
        Issues::issue($o, $oats, 4, $pm);
        Orders::finish($o, $pm, 100);
        Closing::close($o->fresh(), 100, $pm);

        return [$admin];
    }
}
