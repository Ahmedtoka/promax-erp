<?php

namespace Tests\Feature\Cash;

use App\Exceptions\Rejected;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cash\Depreciation;
use App\Services\Cash\ExpenseFlow;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lane 4 Task 7: الأصول الثابتة والإهلاك الشهري بالقسط الثابت
 * (L4-D10 أصل من سند المصروف، L4-D11 الإهلاك، L4-D12 الأصل الافتتاحي).
 */
class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();
    }

    private function cat(string $code): ExpenseCategory
    {
        return ExpenseCategory::where('code', $code)->firstOrFail();
    }

    private function center(string $code): CostCenter
    {
        return CostCenter::create(['code' => $code, 'name' => 'مركز '.$code, 'name_en' => 'Center '.$code,
            'kind' => 'branch', 'parent_id' => CostCenter::company()->id, 'active' => true]);
    }

    private function asset(array $attrs = []): FixedAsset
    {
        return FixedAsset::create(array_merge([
            'code' => FixedAsset::nextNumber(), 'name' => 'لابتوب', 'category_id' => $this->cat('LAPTOP')->id,
            'cost' => 1000, 'salvage' => 0, 'life_months' => 3, 'start_date' => '2026-04-10',
        ], $attrs));
    }

    private function balance(string $key): float
    {
        return GlAccount::findKey($key)->balanceBetween(null, null);
    }

    /** سند أصل مدفوع عن طريق دورة الاعتماد: محاسب → أدمن يعتمد → يدفع */
    private function paidAssetExpense(array $attrs = []): Expense
    {
        $acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
        $admin = $this->makeAdmin();
        $cat = $this->cat('LAPTOP');
        $flow = app(ExpenseFlow::class);
        $x = $flow->create(array_merge([
            'date' => '2026-04-15', 'category_id' => $cat->id, 'account_id' => $cat->account_id,
            'amount' => 3600, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'مورد',
            'asset_name' => 'لابتوب المحاسب', 'asset_life_months' => 36, 'asset_start_date' => '2026-04-15',
        ], $attrs), $acc);
        $this->assertSame('pending', $x->status);
        $flow->approve($x, $admin);
        $flow->pay($x, $admin);

        return $x->fresh();
    }

    public function test_paying_an_asset_expense_creates_the_asset_and_debits_fixed_assets(): void
    {
        $center = $this->center('C1');
        $x = $this->paidAssetExpense(['cost_center_id' => $center->id]);

        $this->assertSame('posted', $x->status);
        $a = FixedAsset::where('expense_id', $x->id)->firstOrFail();
        $this->assertSame('لابتوب المحاسب', $a->name);
        $this->assertSame(3600.0, (float) $a->cost);
        $this->assertSame(36, $a->life_months);
        $this->assertSame('2026-04-15', $a->start_date->toDateString());
        $this->assertSame($center->id, $a->cost_center_id);
        $this->assertSame($this->cat('LAPTOP')->id, $a->category_id);
        $this->assertSame(GlAccount::findKey('fixed_assets')->id, $a->asset_account_id);
        $this->assertStringStartsWith('FA-', $a->code);
        $this->assertSame(100.0, $a->monthlyAmount());

        $this->assertSame(3600.0, $this->balance('fixed_assets'));
        // الأصل نفسه مابيترحّلش — قيد واحد بس (سند المصروف)
        $this->assertSame(1, GlEntry::count());

        // دفع تاني مش ممكن، ومفيش أصل مكرر لو afterPaid اتنده تاني
        $this->assertSame(1, FixedAsset::count());
    }

    public function test_monthly_amount_and_last_month_remainder(): void
    {
        $a = $this->asset();
        $this->assertSame(333.33, $a->monthlyAmount());

        $svc = app(Depreciation::class);
        foreach (['2026-04', '2026-05', '2026-06'] as $p) {
            $this->assertSame(1, $svc->run($p)['created']);
        }

        $this->assertSame(['2026-04' => 333.33, '2026-05' => 333.33, '2026-06' => 333.34],
            FixedAssetDepreciation::orderBy('period_key')->get()->mapWithKeys(fn ($d) => [$d->period_key => (float) $d->amount])->all());
        $this->assertSame('2026-06-30', FixedAssetDepreciation::where('period_key', '2026-06')->value('date')->toDateString());

        $a->refresh();
        $this->assertSame(1000.0, $a->accumulated());
        $this->assertSame(333.33, $a->accumulated('2026-04'));
        $this->assertSame(0.0, $a->bookValue());
        // accum_depreciation دائن الطبيعة → الرصيد الموقّع موجب
        $this->assertSame(1000.0, $this->balance('accum_depreciation'));
        $this->assertSame(1000.0, $this->balance('expense_depreciation'));
    }

    public function test_depreciate_is_idempotent_per_month(): void
    {
        $this->asset(['life_months' => 10]);
        $this->asset(['life_months' => 10, 'cost' => 500]);

        $first = app(Depreciation::class)->run('2026-05');
        $this->assertSame(['created' => 2, 'skipped' => 0, 'amount' => 150.0], $first);

        $second = app(Depreciation::class)->run('2026-05');
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['skipped']);

        // الكوماند كمان idempotent، والـdry-run مابيكتبش
        $this->artisan('promax:depreciate', ['--month' => '2026-05'])->assertSuccessful();
        $this->artisan('promax:depreciate', ['--month' => '2026-06', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, FixedAssetDepreciation::count());
        $this->assertSame(2, GlEntry::where('source_type', (new FixedAssetDepreciation)->getMorphClass())->count());

        $this->artisan('promax:depreciate', ['--month' => '2026-06'])->assertSuccessful();
        $this->assertSame(4, FixedAssetDepreciation::count());
        $this->artisan('promax:depreciate', ['--month' => 'bad'])->assertFailed();
    }

    public function test_depreciation_posts_with_cost_center(): void
    {
        $center = $this->center('C2');
        $a = $this->asset(['cost_center_id' => $center->id]);
        $b = $this->asset(['code' => 'FA-9999']);

        app(Depreciation::class)->run('2026-04');

        $d = FixedAssetDepreciation::where('fixed_asset_id', $a->id)->firstOrFail();
        $entry = app(Ledger::class)->autoEntryFor($d);
        $this->assertNotNull($entry);
        $this->assertSame('asset.depreciation', $entry->rule_key);
        $this->assertSame('2026-04-30', Carbon::parse($entry->date)->toDateString());
        $this->assertFalse((bool) $entry->needs_review);
        $lines = GlLine::where('entry_id', $entry->id)->get();
        $this->assertCount(2, $lines);
        $this->assertEqualsCanonicalizing([$center->id, $center->id], $lines->pluck('cost_center_id')->all());
        $dr = $lines->firstWhere('debit', '>', 0);
        $cr = $lines->firstWhere('credit', '>', 0);
        $this->assertSame(GlAccount::findKey('expense_depreciation')->id, $dr->account_id);
        $this->assertSame(GlAccount::findKey('accum_depreciation')->id, $cr->account_id);
        $this->assertSame(333.33, (float) $dr->debit);

        // أصل من غير مركز → مركز الشركة على السطرين
        $db = FixedAssetDepreciation::where('fixed_asset_id', $b->id)->firstOrFail();
        $this->assertSame([CostCenter::company()->id, CostCenter::company()->id],
            GlLine::where('entry_id', app(Ledger::class)->autoEntryFor($db)->id)->pluck('cost_center_id')->all());

        // حذف القسط بيشيل القيد
        $d->delete();
        $this->assertNull(GlEntry::find($entry->id));
    }

    public function test_no_depreciation_before_start_or_after_life(): void
    {
        $a = $this->asset();
        $this->assertSame(0.0, $a->amountFor('2026-03'));
        $this->assertSame(333.33, $a->amountFor('2026-04'));
        $this->assertSame(0.0, $a->amountFor('2026-07'));

        $r = app(Depreciation::class)->run('2026-03');
        $this->assertSame(0, $r['created']);
        $this->assertSame(0, FixedAssetDepreciation::count());

        $r = app(Depreciation::class)->run('2026-07');
        $this->assertSame(0, $r['created']);

        // المستبعد مابيتهلكش
        $a->update(['status' => 'disposed', 'disposed_at' => '2026-04-20 10:00:00']);
        $this->assertSame(0.0, $a->fresh()->amountFor('2026-05'));
        $this->assertSame(0, app(Depreciation::class)->run('2026-05')['created']);
    }

    public function test_opening_asset_skips_months_up_to_opening_as_of_and_opening_entry_balances(): void
    {
        // الشهور لحد ديسمبر لازم تكون خلصت
        Carbon::setTestNow('2027-01-15 10:00:00');
        $center = $this->center('C3');
        $a = $this->asset([
            'cost' => 1200, 'life_months' => 12, 'start_date' => '2026-01-01', 'cost_center_id' => $center->id,
            'opening_accum' => 300, 'opening_as_of' => '2026-03-31',
        ]);

        $this->assertSame(0.0, $a->amountFor('2026-02'));
        $this->assertSame(0.0, $a->amountFor('2026-03'));
        $this->assertSame(100.0, $a->amountFor('2026-04'));
        $this->assertSame(300.0, $a->accumulated());
        $this->assertSame(900.0, $a->bookValue());

        $svc = app(Depreciation::class);
        $this->assertSame(0, $svc->run('2026-03')['created']);
        for ($m = 4; $m <= 12; $m++) {
            $this->assertSame(1, $svc->run(sprintf('2026-%02d', $m))['created']);
        }
        $a->refresh();
        $this->assertSame(1200.0, $a->accumulated());
        $this->assertSame(900.0, round((float) FixedAssetDepreciation::sum('amount'), 2));

        $e = $a->openingEntry();
        $this->assertSame('2026-03-31', $e['date']);
        $this->assertNotSame('', $e['memo']);
        $lines = collect($e['lines']);
        $this->assertCount(3, $lines);
        $this->assertSame(round($lines->sum('debit'), 2), round($lines->sum('credit'), 2));
        $this->assertSame(1200.0, (float) $lines->firstWhere('account_id', GlAccount::findKey('fixed_assets')->id)['debit']);
        $this->assertSame(300.0, (float) $lines->firstWhere('account_id', GlAccount::findKey('accum_depreciation')->id)['credit']);
        $this->assertSame(900.0, (float) $lines->firstWhere('account_id', GlAccount::findKey('opening_equity')->id)['credit']);
        $this->assertSame([$center->id], $lines->pluck('cost_center_id')->unique()->values()->all());

        // Lane 3 بيرحّلها بـLedger::manual(..., 'opening') — لازم تعدّي
        $entry = app(Ledger::class)->manual(Carbon::parse($e['date']), $e['memo'], $e['lines'], $this->makeAdmin(), 'opening');
        $this->assertSame('opening', $entry->origin);

        // من غير مجمع افتتاحي: سطرين بس
        $b = $this->asset(['code' => 'FA-9998']);
        $this->assertCount(2, $b->openingEntry()['lines']);
        $this->assertSame('2026-04-10', $b->openingEntry()['date']);
    }

    public function test_current_or_future_month_and_missing_rule_are_refused(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        $this->asset();
        foreach (['2026-05', '2026-09'] as $p) {
            try {
                app(Depreciation::class)->run($p);
                $this->fail("open month {$p} should be refused");
            } catch (Rejected $e) {
                $this->assertStringContainsString($p, $e->getMessage());
            }
        }
        $this->artisan('promax:depreciate', ['--month' => '2026-05'])->assertFailed();
        $this->assertSame(0, FixedAssetDepreciation::count());

        // القاعدة واقفة = مرفوض قبل أي صف (كان هيتسجل من غير قيد)
        \App\Models\Gl\GlPostingRule::where('key', 'asset.depreciation')->update(['active' => false]);
        \App\Models\Gl\GlPostingRule::flush();
        $this->expectException(Rejected::class);
        try {
            app(Depreciation::class)->run('2026-04');
        } finally {
            $this->assertSame(0, FixedAssetDepreciation::count());
        }
    }

    public function test_disposed_asset_is_charged_up_to_its_disposal_month(): void
    {
        $a = $this->asset(['life_months' => 10]);
        $a->update(['status' => 'disposed', 'disposed_at' => '2026-05-12 09:00:00']);
        $svc = app(Depreciation::class);
        $this->assertSame(1, $svc->run('2026-04')['created']);
        $this->assertSame(1, $svc->run('2026-05')['created']);
        $this->assertSame(0, $svc->run('2026-06')['created']);
        $this->assertSame(200.0, $a->fresh()->accumulated());
    }

    public function test_manual_asset_started_long_ago_needs_opening_fields(): void
    {
        $acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
        $base = ['name' => 'شاشة', 'category_id' => $this->cat('LAPTOP')->id, 'cost' => 600, 'life_months' => 12];

        $this->actingAs($acc)->post(route('gl.assets.store'), $base + ['start_date' => '2025-03-01'])
            ->assertSessionHasErrors('opening_as_of');
        $this->actingAs($acc)->post(route('gl.assets.store'), $base + ['start_date' => '2025-03-01', 'opening_accum' => 100])
            ->assertSessionHasErrors('opening_as_of');
        $this->assertSame(0, FixedAsset::count());

        // من الشهر اللي فات وبعده: مش محتاج افتتاحي
        $last = now('Africa/Cairo')->subMonthNoOverflow()->startOfMonth()->toDateString();
        $this->actingAs($acc)->post(route('gl.assets.store'), $base + ['start_date' => $last])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, FixedAsset::count());
    }

    public function test_closed_month_is_refused(): void
    {
        $this->asset();
        $admin = $this->makeAdmin();
        GlPeriod::close('2026-04', $admin);

        try {
            app(Depreciation::class)->run('2026-04');
            $this->fail('closed month should be refused');
        } catch (Rejected $e) {
            $this->assertStringContainsString('2026-04', $e->getMessage());
        }
        $this->assertSame(0, FixedAssetDepreciation::count());

        $this->artisan('promax:depreciate', ['--month' => '2026-04'])->assertFailed();

        $this->actingAs($admin)->post(route('gl.assets.depreciate'), ['month' => '2026-04'])
            ->assertRedirect()->assertSessionHasErrors('month');
        $this->assertSame(0, FixedAssetDepreciation::count());

        $this->actingAs($admin)->post(route('gl.assets.depreciate'), ['month' => '2026-05'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertSame(1, FixedAssetDepreciation::count());

        // المحاسب مايشغّلش الإهلاك
        $acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
        $this->actingAs($acc)->post(route('gl.assets.depreciate'), ['month' => '2026-06'])->assertForbidden();
    }

    public function test_void_of_depreciated_asset_expense_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $x = $this->paidAssetExpense();
        app(Depreciation::class)->run('2026-04');
        $this->assertSame(1, FixedAssetDepreciation::count());

        $this->actingAs($admin)->post(route('gl.expenses.void', $x))
            ->assertRedirect()->assertSessionHasErrors('void');
        $this->assertSame('posted', $x->fresh()->status);
        $this->assertSame(1, FixedAsset::count());

        // سند أصل من غير إهلاك: الإلغاء بيشيل الأصل والقيد
        $y = $this->paidAssetExpense(['date' => '2026-05-02', 'asset_start_date' => '2026-05-02']);
        $this->assertSame(2, FixedAsset::count());
        $this->actingAs($admin)->post(route('gl.expenses.void', $y))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('void', $y->fresh()->status);
        $this->assertNull(FixedAsset::where('expense_id', $y->id)->first());
        $this->assertNull(app(Ledger::class)->autoEntryFor($y->fresh()));
    }

    public function test_register_screen_and_export(): void
    {
        $admin = $this->makeAdmin();
        $center = $this->center('C4');
        $x = $this->paidAssetExpense();
        $this->asset(['code' => 'FA-5001', 'name' => 'مكتب', 'category_id' => $this->cat('FURNITURE')->id, 'life_months' => 60]);
        app(Depreciation::class)->run('2026-04');

        $this->actingAs($admin)->get(route('gl.assets'))->assertOk()
            ->assertSee('FA-5001')->assertSee($x->number)->assertSee('لابتوب المحاسب')
            ->assertSee(__('cash.assets'));

        $csv = $this->actingAs($admin)->get(route('gl.assets', ['export' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('FA-5001', $csv);
        $this->assertStringContainsString($x->number, $csv);

        $only = $this->actingAs($admin)->get(route('gl.assets', ['export' => 1, 'category' => $this->cat('FURNITURE')->id]))->streamedContent();
        $this->assertStringContainsString('FA-5001', $only);
        $this->assertStringNotContainsString($x->number, $only);

        // التاريخ على start_date
        $this->actingAs($admin)->get(route('gl.assets', ['from' => '2026-05-01', 'to' => '2026-05-31']))->assertOk()
            ->assertDontSee('FA-5001');

        // أصل افتتاحي يدوي — المحاسب (act.gl.post)
        $acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
        $this->actingAs($acc)->get(route('gl.assets'))->assertOk();
        $this->actingAs($acc)->post(route('gl.assets.store'), [
            'name' => 'كاميرا', 'category_id' => $this->cat('CAMERA')->id, 'cost' => 900, 'salvage' => 0,
            'life_months' => 36, 'start_date' => '2025-01-01', 'cost_center_id' => $center->id,
            'opening_accum' => 950, 'opening_as_of' => '2025-12-31',
        ])->assertSessionHasErrors('opening_accum');
        $this->actingAs($acc)->post(route('gl.assets.store'), [
            'name' => 'كاميرا', 'category_id' => $this->cat('CAMERA')->id, 'cost' => 900, 'salvage' => 0,
            'life_months' => 36, 'start_date' => '2025-01-01', 'cost_center_id' => $center->id,
            'opening_accum' => 300, 'opening_as_of' => '2025-12-31',
        ])->assertRedirect(route('gl.assets'))->assertSessionHasNoErrors();
        $cam = FixedAsset::where('name', 'كاميرا')->firstOrFail();
        $this->assertSame(300.0, (float) $cam->opening_accum);
        $this->assertSame(GlAccount::findKey('fixed_assets')->id, $cam->asset_account_id);
        // الأصل الافتتاحي مابيترحّلش من الشاشة
        $this->assertSame(0, GlEntry::where('origin', 'opening')->count());

        // بند مش من نوع أصل مرفوض
        $this->actingAs($acc)->post(route('gl.assets.store'), [
            'name' => 'غلط', 'category_id' => $this->cat('BUFFET')->id, 'cost' => 100, 'life_months' => 12, 'start_date' => '2026-01-01',
        ])->assertSessionHasErrors('category_id');
    }
}
