<?php

namespace Tests\Feature\Review;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Review\DocReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** (١٣/١١ — أحمد) حجم الشغل واللي خلص — لكل عميل وسلسلة */
class DocReviewCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_counts_work_and_fully_reviewed_clients(): void
    {
        Carbon::setTestNow('2026-11-13 10:00:00');
        $acc = $this->makeAdmin(['role' => 'accountant']);
        $rep = $this->makeRep();
        $done = $this->makeClient(['name' => 'عميل خلص']);
        $half = $this->makeClient(['name' => 'عميل نص']);
        $mk = function ($client, string $serial) use ($rep) {
            $inv = Invoice::create(['number' => 'INV-'.random_int(10000, 99999), 'paper_ref' => $serial, 'client_id' => $client->id, 'user_id' => $rep->id,
                'payment' => 'credit', 'price_list' => 'x', 'subtotal' => 10, 'discount_pct' => 0, 'discount' => 0, 'total' => 10,
                'tax_total' => 0, 'grand_total' => 10, 'eta_status' => 'ready', 'cost_total' => 0]);
            $inv->forceFill(['created_at' => Carbon::parse('2026-10-10 12:00')])->save();

            return $inv;
        };
        DocReview::mark($acc, $mk($done, 'C1'), 'ok', null);
        DocReview::mark($acc, $mk($half, 'C2'), 'ok', null);
        $mk($half, 'C3');
        Transaction::create(['client_id' => $half->id, 'date' => '2026-10-11', 'memo' => 'ميداني', 'debit' => 0, 'credit' => 7,
            'kind' => 'collection', 'source_type' => 'App\\Models\\Visit', 'source_id' => 1]);

        $res = $this->actingAs($acc)->get(route('erp.review.docs.coverage'))->assertOk()->assertSee(__('review.cov_done_clients'));
        $this->assertSame(1, $res->viewData('top')['done_clients']);
        $this->assertSame(2, $res->viewData('top')['clients']);
        $list = $res->viewData('list')->keyBy('client_id');
        $this->assertTrue($list[$done->id]['done']);
        $this->assertSame(2, $list[$half->id]['docs']);
        $this->assertSame(1, $list[$half->id]['col']);
        $this->assertEquals(50, $list[$half->id]['pct']);
        // الأكتر شغل الأول، وفلتر «خلص»
        $this->assertSame($half->id, $res->viewData('list')->first()['client_id']);
        $this->assertSame([$done->id], $this->actingAs($acc)->get(route('erp.review.docs.coverage', ['show' => 'done']))->viewData('list')->pluck('client_id')->all());
        $this->actingAs($acc)->get(route('erp.review.docs.coverage', ['by' => 'chain', 'sort' => 'progress']))->assertOk();
        Carbon::setTestNow();
    }
}
