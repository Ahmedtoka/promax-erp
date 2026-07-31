<?php

namespace Tests\Feature;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\Stock;
use App\Models\StockCount;
use App\Services\StockCounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الجرد
 * ═══════════════════════════════════════════════════════════════
 *
 * الجرد بيحرّك مخزون فعلي، فالتيستات دي بتحرس:
 *   1. مفيش حركة قبل الاعتماد
 *   2. الفاضي ≠ الصفر
 *   3. `stocks` بتتزامن مع الباتشات بعد كل اعتماد
 */
class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private function scene(int $qty = 100): array
    {
        $admin = $this->makeAdmin();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct(['cost' => 10]);

        $batch = Batch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-1',
            'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6),
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'cost' => 10,
        ]);

        return [$admin, $warehouse, $product, $batch];
    }

    public function test_opening_a_count_snapshots_stock_without_moving_it(): void
    {
        [$admin, $warehouse, , $batch] = $this->scene(100);

        $count = StockCounting::open($warehouse, $admin);

        $this->assertSame(1, $count->lines);
        $this->assertSame('counting', $count->status);

        $item = $count->items()->first();
        $this->assertSame(100, $item->expected_qty);
        $this->assertNull($item->counted_qty, 'الفتح مايحطش رقم معدود');

        // ⚠️ الرصيد الحقيقي مااتغيرش
        $this->assertSame(100, (int) $batch->fresh()->qty_remaining);
    }

    public function test_two_open_counts_on_the_same_warehouse_are_refused(): void
    {
        [$admin, $warehouse] = $this->scene();

        StockCounting::open($warehouse, $admin);

        $this->expectException(Rejected::class);
        StockCounting::open($warehouse, $admin);
    }

    public function test_recording_numbers_does_not_move_stock(): void
    {
        [$admin, $warehouse, , $batch] = $this->scene(100);

        $count = StockCounting::open($warehouse, $admin);
        $item = $count->items()->first();

        StockCounting::record($count, [$item->id => ['counted' => 80, 'reason' => 'damage']]);

        $this->assertSame(80, (int) $item->fresh()->counted_qty);
        $this->assertSame(100, (int) $batch->fresh()->qty_remaining,
            'إدخال الأرقام ممنوع يحرّك المخزون — الاعتماد بس');
    }

    public function test_empty_is_not_the_same_as_zero(): void
    {
        [$admin, $warehouse] = $this->scene(100);

        $count = StockCounting::open($warehouse, $admin);
        $item = $count->items()->first();

        // فاضي = لسه مااتعدش
        StockCounting::record($count, [$item->id => ['counted' => null, 'reason' => null]]);
        $this->assertNull($item->fresh()->counted_qty);
        $this->assertTrue($item->fresh()->notCounted());

        // صفر = اتعدّ ومالقيناش حاجة
        StockCounting::record($count, [$item->id => ['counted' => 0, 'reason' => 'theft']]);
        $this->assertSame(0, (int) $item->fresh()->counted_qty);
        $this->assertFalse($item->fresh()->notCounted());
    }

    public function test_approving_writes_the_counted_number_and_records_the_shortage(): void
    {
        [$admin, $warehouse, $product, $batch] = $this->scene(100);

        $count = StockCounting::open($warehouse, $admin);
        $item = $count->items()->first();

        StockCounting::record($count, [$item->id => ['counted' => 80, 'reason' => 'damage']]);
        $count = StockCounting::approve($count, $admin);

        $this->assertSame('approved', $count->status);
        $this->assertSame(1, $count->diff_lines);
        $this->assertSame(-20, $count->qty_diff);
        $this->assertEqualsWithDelta(-200.0, (float) $count->value_diff, 0.01,
            'قيمة العجز = 20 × تكلفة 10');

        $batch = $batch->fresh();
        $this->assertSame(80, (int) $batch->qty_remaining, 'العد هو الحقيقة');
        $this->assertSame(20, (int) $batch->qty_damaged, 'العجز بيتسجّل كتوالف');

        // ⚠️ `stocks` تجميعة — لازم تتزامن وإلا شاشتين بيرقّموا مختلف
        //
        // ⚠️ **بمخزن الجرد بالتحديد.** التيست كان بيجيب `first()` على
        // `product_id` لوحده، فبعد ما المخزون بقى صف لكل (صنف، مخزن)
        // كان بينجح أو يفشل حسب ترتيب الإدخال — يعني مش بيمسك الحالة
        // اللي فيها الرقم اتكتب على المخزن الغلط، وهي بالظبط اللي
        // اتكسرت في الواقع.
        $stock = Stock::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertNotNull($stock, 'لازم يبقى فيه صف رصيد لمخزن الجرد');
        $this->assertSame(80, (int) $stock->qty);

        // ⚠️ ومخزن تاني في نفس الوقت لازم يفضل زي ما هو — الجرد
        // بيتعمل في مخزن واحد، ومايصحّش يمسّ رصيد غيره.
        $other = $this->makeWarehouse();
        Stock::create([
            'product_id' => $product->id, 'warehouse_id' => $other->id,
            'qty' => 55, 'hold_qty' => 0, 'good_qty' => 55,
        ]);

        StockCounting::resync($product->id, $warehouse->id);

        $this->assertSame(55, (int) Stock::where('product_id', $product->id)
            ->where('warehouse_id', $other->id)->value('qty'),
            'إعادة حساب مخزن مالهاش دعوة برصيد مخزن تاني');
    }

    public function test_uncounted_lines_are_left_untouched_on_approval(): void
    {
        [$admin, $warehouse, , $batch] = $this->scene(100);

        // باتش تاني مااتعدش
        $second = Batch::create([
            'product_id' => $batch->product_id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-2',
            'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(9),
            'qty_received' => 50,
            'qty_remaining' => 50,
            'cost' => 10,
        ]);

        $count = StockCounting::open($warehouse, $admin);
        $first = $count->items()->where('batch_id', $batch->id)->first();

        StockCounting::record($count, [$first->id => ['counted' => 90, 'reason' => null]]);
        StockCounting::approve($count, $admin);

        $this->assertSame(90, (int) $batch->fresh()->qty_remaining);
        $this->assertSame(50, (int) $second->fresh()->qty_remaining,
            'السطر اللي مااتعدش ممنوع يتصفّر');
    }

    public function test_approving_with_nothing_counted_is_refused(): void
    {
        [$admin, $warehouse] = $this->scene();

        $count = StockCounting::open($warehouse, $admin);

        $this->expectException(Rejected::class);
        StockCounting::approve($count, $admin);
    }

    public function test_an_approved_count_cannot_be_approved_again(): void
    {
        [$admin, $warehouse] = $this->scene(100);

        $count = StockCounting::open($warehouse, $admin);
        $item = $count->items()->first();
        StockCounting::record($count, [$item->id => ['counted' => 100, 'reason' => null]]);
        $count = StockCounting::approve($count, $admin);

        $this->expectException(Rejected::class);
        StockCounting::approve($count, $admin);
    }

    public function test_surplus_is_recorded_as_a_positive_difference(): void
    {
        [$admin, $warehouse, , $batch] = $this->scene(100);

        $count = StockCounting::open($warehouse, $admin);
        $item = $count->items()->first();

        StockCounting::record($count, [$item->id => ['counted' => 115, 'reason' => 'found']]);
        $count = StockCounting::approve($count, $admin);

        $this->assertSame(15, $count->qty_diff);
        $this->assertSame(115, (int) $batch->fresh()->qty_remaining);
        $this->assertSame(0, (int) $batch->fresh()->qty_damaged,
            'الزيادة مش توالف');
    }

    public function test_cancelling_leaves_stock_alone(): void
    {
        [$admin, $warehouse, , $batch] = $this->scene(100);

        $count = StockCounting::open($warehouse, $admin);
        $item = $count->items()->first();
        StockCounting::record($count, [$item->id => ['counted' => 10, 'reason' => null]]);

        StockCounting::cancel($count);

        $this->assertSame('cancelled', $count->fresh()->status);
        $this->assertSame(100, (int) $batch->fresh()->qty_remaining);

        // وبعد الإلغاء ينفع نفتح جرد جديد
        $again = StockCounting::open($warehouse, $admin);
        $this->assertInstanceOf(StockCount::class, $again);
    }
}
