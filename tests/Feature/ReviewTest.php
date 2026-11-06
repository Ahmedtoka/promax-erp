<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\ClientGroup;
use App\Models\ReviewCheck;
use App\Models\ReviewStockMove;
use App\Models\Transaction;
use App\Services\Review\MonthFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** مراجعة إعادة التشغيل (٥/١١/٢٠٢٦) */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_screens_open_for_the_review_team_and_not_for_reps(): void
    {
        $this->makeClient();
        $this->makeWarehouse();

        foreach (['admin', 'accountant', 'manager', 'branch_manager'] as $role) {
            $u = $this->makeAdmin(['role' => $role]);
            foreach (['erp.review', 'erp.review.data', 'erp.review.money', 'erp.review.stock'] as $r) {
                $this->actingAs($u)->get(route($r))->assertOk();
            }
        }

        $this->actingAs($this->makeRep())->get(route('erp.review'))->assertStatus(403);
    }

    public function test_marks_ok_issue_and_clear_with_who_and_note(): void
    {
        $admin = $this->makeAdmin();
        $c = $this->makeClient(['name' => 'Review Client', 'name_en' => 'Review Client', 'address' => 'Maadi', 'commercial_register' => '12345']);
        $this->assertSame([], ReviewCheck::missingItems($c), 'test client must have every data item filled');

        $this->actingAs($admin)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $c->id, 'items' => ReviewCheck::DATA_ITEMS, 'status' => 'ok',
        ])->assertOk()->assertJsonPath('checks.name.status', 'ok');

        $this->assertSame(6, ReviewCheck::where('entity_id', $c->id)->where('status', 'ok')->count());

        $this->actingAs($admin)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $c->id, 'items' => ['address'], 'status' => 'issue', 'note' => 'العنوان غلط',
        ])->assertJsonPath('checks.address.note', 'العنوان غلط');

        $chk = ReviewCheck::where(['entity_id' => $c->id, 'item' => 'address'])->sole();
        $this->assertSame('issue', $chk->status);
        $this->assertSame($admin->id, (int) $chk->user_id);

        $this->actingAs($admin)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $c->id, 'items' => ['address'], 'status' => 'clear',
        ])->assertOk();
        $this->assertSame(0, ReviewCheck::where(['entity_id' => $c->id, 'item' => 'address'])->count());

        // بند مش من المرحلة دي
        $this->actingAs($admin)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $c->id, 'items' => ['zeroed'], 'status' => 'ok',
        ])->assertStatus(422);

        $this->actingAs($admin)->get(route('erp.review'))->assertOk()->assertSee('Review Client');
        $this->actingAs($admin)->get(route('erp.review.data', ['show' => 'all']))->assertSee('Review Client');
    }

    public function test_manager_cannot_mark_another_teams_client(): void
    {
        $mgr = $this->makeAdmin(['role' => 'manager']);
        $c = $this->makeClient();

        $this->actingAs($mgr)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $c->id, 'items' => ['name'], 'status' => 'ok',
        ])->assertNotFound();
    }

    public function test_month_figures_match_the_statement(): void
    {
        $c = $this->makeClient();
        $add = fn ($date, $kind, $d, $cr) => Transaction::create(['client_id' => $c->id, 'date' => $date, 'memo' => $kind, 'debit' => $d, 'credit' => $cr, 'kind' => $kind]);
        $add('2026-07-15', 'sale', 100, 0);         // قبل أول شهر — بيدخل في الرصيد بس
        $add('2026-08-03', 'sale', 1000, 0);
        $add('2026-08-20', 'return', 0, 200);
        $add('2026-09-05', 'collection', 0, 500);
        $add('2026-09-10', 'sale', 300, 0);

        $f = MonthFigures::forClients([$c->id])[$c->id];

        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_keys($f));
        $this->assertEqualsWithDelta(800, $f['2026-08']['net'], 0.001);
        $this->assertEqualsWithDelta(900, $f['2026-08']['balance'], 0.001);
        $this->assertEqualsWithDelta(300, $f['2026-09']['net'], 0.001);
        $this->assertEqualsWithDelta(700, $f['2026-09']['balance'], 0.001);
        $this->assertEqualsWithDelta(0, $f['2026-10']['net'], 0.001);
        $this->assertEqualsWithDelta(700, $f['2026-10']['balance'], 0.001);

        $this->actingAs($this->makeAdmin())->get(route('erp.review.money', ['show' => 'all']))->assertOk()->assertSee('2026-09');
    }

    public function test_chain_opening_balance_is_one_figure_and_marks_the_item(): void
    {
        $g = ClientGroup::create(['code' => 'G1', 'name' => 'Chain', 'active' => true]);
        $this->makeClient(['group_id' => $g->id]);

        $this->actingAs($this->makeAdmin())->post(route('erp.review.opening', $g), ['amount' => 12500.5, 'as_of' => '2026-07-31'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(12500.5, (float) $g->fresh()->opening_balance, 0.001);
        $this->assertSame('ok', ReviewCheck::where(['entity_type' => 'group', 'entity_id' => $g->id, 'item' => 'opening'])->value('status'));
    }

    public function test_zero_warehouse_needs_the_code_and_logs_old_quantities(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $p = $this->makeProduct();
        $b = Batch::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'batch_no' => 'B1', 'expires_on' => '2027-06-01',
            'qty_received' => 40, 'qty_remaining' => 25, 'qty_issued' => 15, 'qty_damaged' => 0, 'cost' => 5]);

        $this->actingAs($admin)->post(route('erp.review.stock.zero', $wh), ['confirm' => 'WRONG'])->assertSessionHasErrors('confirm');
        $this->assertSame(25, (int) $b->fresh()->qty_remaining);

        $this->actingAs($admin)->post(route('erp.review.stock.zero', $wh), ['confirm' => $wh->code])->assertRedirect();

        $this->assertSame(0, (int) $b->fresh()->qty_remaining);
        $this->assertSame(0, (int) $b->fresh()->qty_damaged, 'التصفير مش توالف');
        $this->assertSame(25, (int) ReviewStockMove::where('kind', 'zero')->sole()->qty);
        $this->assertSame('ok', ReviewCheck::where(['entity_type' => 'warehouse', 'entity_id' => $wh->id, 'item' => 'zeroed'])->value('status'));

        $this->actingAs($this->makeAdmin(['role' => 'accountant']))
            ->post(route('erp.review.stock.zero', $wh), ['confirm' => $wh->code])->assertStatus(403);
    }

    public function test_paper_balance_by_batch_creates_or_tops_up_and_refuses_wrong_expiry(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $p = $this->makeProduct(['cost' => 7]);

        $this->actingAs($admin)->post(route('erp.review.stock.batch', $wh), [
            'product_id' => $p->id, 'batch_no' => 'LOT-9', 'expires_on' => '2027-03-01', 'qty' => 30,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $b = Batch::where(['warehouse_id' => $wh->id, 'batch_no' => 'LOT-9'])->sole();
        $this->assertSame(30, (int) $b->qty_remaining);
        $this->assertEqualsWithDelta(7, (float) $b->cost, 0.001);
        $this->assertSame(30, (int) \Illuminate\Support\Facades\DB::table('stocks')->where(['warehouse_id' => $wh->id, 'product_id' => $p->id])->value('qty'));

        $this->actingAs($admin)->post(route('erp.review.stock.batch', $wh), [
            'product_id' => $p->id, 'batch_no' => 'LOT-9', 'expires_on' => '2027-03-01', 'qty' => 5,
        ]);
        $this->assertSame(35, (int) $b->fresh()->qty_remaining);

        $this->actingAs($admin)->post(route('erp.review.stock.batch', $wh), [
            'product_id' => $p->id, 'batch_no' => 'LOT-9', 'expires_on' => '2027-09-01', 'qty' => 5,
        ])->assertSessionHasErrors('batch_no');
        $this->assertSame(35, (int) $b->fresh()->qty_remaining);
        $this->assertSame(2, ReviewStockMove::where('kind', 'opening')->count());
    }

    public function test_missing_item_cannot_be_ticked_until_it_is_filled(): void
    {
        $admin = $this->makeAdmin();
        $c = $this->makeClient(['address' => 'Maadi', 'commercial_register' => null]);
        $this->assertContains('cr', ReviewCheck::missingItems($c));

        $this->actingAs($admin)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $c->id, 'items' => ['address', 'cr'], 'status' => 'ok',
        ])->assertOk()->assertJsonPath('missing', ['cr']);
        $this->assertFalse(ReviewCheck::where('entity_id', $c->id)->where('item', 'cr')->exists());
        $this->assertTrue(ReviewCheck::where('entity_id', $c->id)->where('item', 'address')->exists());

        $this->actingAs($admin)->get(route('erp.review.data', ['show' => 'all']))->assertOk()->assertSee(__('review.missing_fix'));

        $c->update(['commercial_register' => '998877']);
        $this->actingAs($admin)->postJson(route('erp.review.mark'), [
            'entity_type' => 'client', 'entity_id' => $c->id, 'items' => ['cr'], 'status' => 'ok',
        ])->assertJsonPath('missing', [])->assertJsonPath('checks.cr.status', 'ok');
    }
}
