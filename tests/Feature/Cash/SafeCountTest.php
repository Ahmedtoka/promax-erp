<?php

namespace Tests\Feature\Cash;

use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Safe;
use App\Models\SafeCount;
use App\Models\Setting;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lane 4 Task 4: الجرد اليومي للخزنة (L4-D5) — المتوقع من الدفتر وقت
 * الجرد، الفرق بيترحّل `cash.count` على عجز وزيادة الخزنة، جرد واحد
 * مرحّل لكل خزنة في اليوم، والإلغاء وإعادة البناء.
 */
class SafeCountTest extends TestCase
{
    use RefreshDatabase;

    private Safe $safe;

    private CostCenter $center;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();

        $this->center = CostCenter::create(['code' => 'CC-SC', 'name' => 'مركز جرد', 'name_en' => 'Count center',
            'kind' => 'branch', 'parent_id' => CostCenter::company()->id, 'active' => true]);
        $safe = Safe::create(['code' => 'SC1', 'name' => 'خزنة جرد', 'name_en' => 'Count safe',
            'kind' => 'cash', 'cost_center_id' => $this->center->id]);
        $safe->ensureGlAccount();
        $this->safe = $safe->fresh();

        // رصيد افتتاحي ١٠٠٠ على الخزنة (قيد افتتاحي — إعادة البناء مابتلمسهوش)
        app(Ledger::class)->manual(today()->startOfMonth(), 'opening safe', [
            ['account_id' => $this->safe->gl_account_id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('opening_equity')->id, 'debit' => 0, 'credit' => 1000],
        ], $this->makeAdmin(), 'opening');
    }

    private function postCount(float $counted, ?string $date = null)
    {
        return $this->actingAs($this->makeAdmin())->post(route('gl.safes.counts.store'), [
            'safe_id' => $this->safe->id, 'date' => $date ?? today()->toDateString(), 'counted' => $counted, 'note' => 'جرد آخر اليوم',
        ]);
    }

    private function entryFor(SafeCount $c): ?GlEntry
    {
        return app(Ledger::class)->autoEntryFor($c);
    }

    public function test_shortage_posts_to_over_short_and_brings_the_balance_to_the_count(): void
    {
        $this->assertSame(1000.0, $this->safe->balance());

        $this->postCount(950)->assertSessionHasNoErrors()->assertRedirect();

        $c = SafeCount::firstOrFail();
        $this->assertSame('posted', $c->status);
        $this->assertStringStartsWith('SC-', $c->number);
        $this->assertSame(1000.0, (float) $c->expected);
        $this->assertSame(950.0, (float) $c->counted);
        $this->assertSame(-50.0, (float) $c->difference);

        $entry = $this->entryFor($c) ?? $this->fail('no GL entry for the shortage');
        $this->assertSame('cash.count', $entry->rule_key);
        $this->assertFalse((bool) $entry->needs_review);
        $lines = $entry->lines()->get()->keyBy('account_id');
        $os = GlAccount::findKey('cash_over_short');
        $this->assertSame(50.0, (float) $lines[$os->id]->debit);
        $this->assertSame(50.0, (float) $lines[$this->safe->gl_account_id]->credit);
        foreach ($lines as $l) {
            $this->assertSame($this->center->id, $l->cost_center_id);
        }

        $this->assertSame(950.0, $this->safe->balance());
    }

    public function test_overage_posts_the_other_way(): void
    {
        $this->postCount(1030)->assertSessionHasNoErrors();

        $c = SafeCount::firstOrFail();
        $this->assertSame(30.0, (float) $c->difference);
        $lines = $this->entryFor($c)->lines()->get()->keyBy('account_id');
        $os = GlAccount::findKey('cash_over_short');
        $this->assertSame(30.0, (float) $lines[$this->safe->gl_account_id]->debit);
        $this->assertSame(30.0, (float) $lines[$os->id]->credit);

        $this->assertSame(1030.0, $this->safe->balance());
    }

    public function test_exact_count_posts_nothing_but_is_recorded(): void
    {
        $this->postCount(1000)->assertSessionHasNoErrors();

        $c = SafeCount::firstOrFail();
        $this->assertSame(0.0, (float) $c->difference);
        $this->assertSame('posted', $c->status);
        $this->assertNull($this->entryFor($c));
        $this->assertSame(1000.0, $this->safe->balance());

        $this->actingAs($this->makeAdmin())->get(route('gl.safes.counts'))
            ->assertOk()->assertSee($c->number);
    }

    public function test_second_count_same_day_is_refused(): void
    {
        $this->postCount(990)->assertSessionHasNoErrors();
        $this->postCount(980)->assertSessionHasErrors('date');
        $this->assertSame(1, SafeCount::count());
        $this->assertSame(990.0, $this->safe->balance());

        // بعد إلغاء الأول ينفع جرد جديد في نفس اليوم — والمتوقع بيرجع للدفتر من غير القيد الملغي
        $first = SafeCount::firstOrFail();
        $this->actingAs($this->makeAdmin())->post(route('gl.safes.counts.void', $first))->assertSessionHasNoErrors();
        $this->postCount(980)->assertSessionHasNoErrors();
        $second = SafeCount::where('status', 'posted')->firstOrFail();
        $this->assertSame(1000.0, (float) $second->expected);
        $this->assertSame(980.0, $this->safe->balance());

        // تاريخ في المستقبل مرفوض
        $this->postCount(900, today()->addDay()->toDateString())->assertSessionHasErrors('date');
    }

    public function test_void_removes_the_entry(): void
    {
        $this->postCount(940)->assertSessionHasNoErrors();
        $c = SafeCount::firstOrFail();
        $this->assertNotNull($this->entryFor($c));

        $this->actingAs($this->makeAdmin())->post(route('gl.safes.counts.void', $c))->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('void', $c->status);
        $this->assertNotNull($c->voided_at);
        $this->assertNotNull($c->voided_by);
        $this->assertNull($this->entryFor($c));
        $this->assertSame(1000.0, $this->safe->balance());
    }

    public function test_rebuild_regenerates_the_count_entry(): void
    {
        $this->postCount(920)->assertSessionHasNoErrors();
        $c = SafeCount::firstOrFail();
        $oldId = $this->entryFor($c)->id;

        $report = app(Ledger::class)->rebuild(Carbon::parse(today()->startOfMonth()), true, false, $this->makeAdmin());
        $this->assertTrue($report->ok);

        $entry = $this->entryFor($c) ?? $this->fail('rebuild lost the count entry');
        $this->assertNotSame($oldId, $entry->id, 'the entry was regenerated');
        $lines = $entry->lines()->get()->keyBy('account_id');
        $this->assertSame(80.0, (float) $lines[$this->safe->gl_account_id]->credit);
        $this->assertSame(80.0, (float) $lines[GlAccount::findKey('cash_over_short')->id]->debit);
        // الفرق المتخزّن مااتحسبش تاني
        $this->assertSame(-80.0, (float) $c->fresh()->difference);
        $this->assertSame(920.0, $this->safe->balance());
    }
}
