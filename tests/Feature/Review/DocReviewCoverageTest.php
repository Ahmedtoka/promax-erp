<?php

namespace Tests\Feature\Review;

use App\Models\ClientGroup;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
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

    /**
     * (١٣/١١ — لايف) «الاجماليات دي كلها فيها مشكلة»: صف Σ كان ٢٤٣ مليون لأن
     * العدد والمبلغ كانوا في خلية واحدة والسكريبت قراهم رقم واحد. الإجمالي
     * دلوقتي من السيرفر، كل مستند مرة واحدة، ولازم يساوي مجموع الصفوف في
     * العرضين (عميل/سلسلة) ومع كل فلتر.
     */
    public function test_row_and_total_money_match_the_documents_per_client_and_per_chain(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant']);
        $rep = $this->makeRep();
        $chain = ClientGroup::create(['code' => 'GRP-T1', 'name' => 'سلسلة اختبار']);
        $b1 = $this->makeClient(['name' => 'فرع أول', 'group_id' => $chain->id]);
        $b2 = $this->makeClient(['name' => 'فرع تاني', 'group_id' => $chain->id]);
        $solo = $this->makeClient(['name' => 'عميل لوحده']);

        $inv = fn ($c, float $g) => Invoice::create(['number' => 'INV-'.random_int(10000, 99999), 'paper_ref' => (string) random_int(100, 99999), 'client_id' => $c->id, 'user_id' => $rep->id,
            'payment' => 'credit', 'price_list' => 'x', 'subtotal' => $g, 'discount_pct' => 0, 'discount' => 0, 'total' => $g,
            'tax_total' => 0, 'grand_total' => $g, 'eta_status' => 'ready', 'cost_total' => 0]);
        $po = fn ($c, float $g) => PurchaseOrder::create(['number' => 'PO-'.random_int(10000, 99999), 'client_id' => $c->id, 'status' => 'delivered',
            'approval_status' => 'approved', 'assigned_to' => $rep->id, 'price_mode' => 'la', 'total' => $g, 'tax_total' => 0, 'grand_total' => $g]);
        $ret = fn ($c, float $g) => ClientReturn::create(['number' => ClientReturn::nextNumber(), 'client_id' => $c->id, 'user_id' => $rep->id,
            'source' => 'erp', 'policy' => 'account', 'subtotal' => $g, 'discount' => 0, 'total' => $g, 'tax_total' => 0, 'grand_total' => $g,
            'good_units' => 1, 'damaged_units' => 0]);
        $col = fn ($c, float $a) => Transaction::create(['client_id' => $c->id, 'date' => '2026-10-11', 'memo' => 'ت', 'debit' => 0, 'credit' => $a,
            'kind' => 'collection', 'source_type' => 'App\\Models\\User', 'source_id' => $rep->id]);

        // فرع ١: فاتورتين + أمر + مرتجع + تحصيلين — فرع ٢: فاتورة + أمرين — لوحده: فاتورة + مرتجع + تحصيل
        DocReview::mark($acc, $inv($b1, 7514.40), 'ok', null);
        $inv($b1, 1228.12);
        $po($b1, 33166.56);
        $ret($b1, 2515.00);
        $col($b1, 45510.00);
        $col($b1, 490.00);
        DocReview::mark($acc, $inv($b2, 20250.00), 'ok', null);
        $po($b2, 5670.00);
        $po($b2, 8100.00);
        $inv($solo, 4050.00);
        $ret($solo, 6880.00);
        $col($solo, 5000.00);

        $get = fn (array $q = []) => $this->actingAs($acc)->get(route('erp.review.docs.coverage', $q))->assertOk();

        // لكل عميل
        $res = $get();
        $rows = $res->viewData('list')->keyBy('client_id');
        $this->assertSame([2, 1, 1, 2], [$rows[$b1->id]['inv'], $rows[$b1->id]['po'], $rows[$b1->id]['ret'], $rows[$b1->id]['col']]);
        $this->assertEqualsWithDelta(8742.52, $rows[$b1->id]['inv_amt'], 0.001);
        $this->assertEqualsWithDelta(33166.56, $rows[$b1->id]['po_amt'], 0.001);
        $this->assertEqualsWithDelta(2515.00, $rows[$b1->id]['ret_amt'], 0.001);
        $this->assertEqualsWithDelta(46000.00, $rows[$b1->id]['col_amt'], 0.001);
        $this->assertEqualsWithDelta(13770.00, $rows[$b2->id]['po_amt'], 0.001);

        $foot = $res->viewData('foot');
        $expect = ['inv' => [4, 33042.52], 'po' => [3, 46936.56], 'ret' => [2, 9395.00], 'col' => [3, 51000.00]];
        foreach ($expect as $k => [$n, $amt]) {
            $this->assertSame($n, $foot[$k]['n'], $k);
            $this->assertEqualsWithDelta($amt, $foot[$k]['amt'], 0.001, $k);
            $this->assertEqualsWithDelta($res->viewData('list')->sum($k.'_amt'), $foot[$k]['amt'], 0.001, $k.' = sum of rows');
        }
        $this->assertSame([9, 2], [$foot['docs'], $foot['ok']]);
        // الإجمالي مكتوب في الصفحة (tfoot من السيرفر) — والعدد والمبلغ في خانات منفصلة
        $res->assertSee('<tfoot>', false)->assertSee('33,042.52')->assertSee('46,936.56')->assertSee('51,000.00')
            ->assertSee('<td class="num cov-amt">8,742.52</td>', false);

        // لكل سلسلة: الفرعين صف واحد، والإجمالي نفسه بالظبط
        $res = $get(['by' => 'chain', 'sort' => 'progress']);
        $chainRow = $res->viewData('list')->firstWhere('group_id', $chain->id);
        $this->assertSame(2, $chainRow['clients']);
        $this->assertSame([3, 3, 1, 2], [$chainRow['inv'], $chainRow['po'], $chainRow['ret'], $chainRow['col']]);
        $this->assertEqualsWithDelta(28992.52, $chainRow['inv_amt'], 0.001);
        $this->assertEqualsWithDelta(46936.56, $chainRow['po_amt'], 0.001);
        $this->assertSame($foot, $res->viewData('foot'));
        $this->assertSame(2, $res->viewData('list')->count());

        // الفلاتر: السلسلة بس / البحث / «لسه» / «شغال فيه» — الإجمالي = المستندات اللي في القايمة بس
        $f = $get(['group' => $chain->id])->viewData('foot');
        $this->assertSame([3, 3, 1, 2], [$f['inv']['n'], $f['po']['n'], $f['ret']['n'], $f['col']['n']]);
        $this->assertEqualsWithDelta(46000.00, $f['col']['amt'], 0.001);
        $f = $get(['q' => $solo->code])->viewData('foot');
        $this->assertSame([1, 0, 1, 1, 2], [$f['inv']['n'], $f['po']['n'], $f['ret']['n'], $f['col']['n'], $f['docs']]);
        $this->assertEqualsWithDelta(6880.00, $f['ret']['amt'], 0.001);
        $this->assertSame(2, $get(['show' => 'none'])->viewData('foot')['docs']);
        $f = $get(['by' => 'chain', 'show' => 'partial'])->viewData('foot');
        $this->assertSame([7, 2], [$f['docs'], $f['ok']]);
    }
}
