<?php

namespace Tests\Feature\Purchasing;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ReceiptInspection;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptQcTest extends TestCase
{
    use RefreshDatabase;

    private function order(Product $p, int $qty = 20, float $cost = 8, ?Warehouse $wh = null): SupplierOrder
    {
        $s = Supplier::create(['code' => Supplier::nextCode(), 'name' => 'مورد', 'name_en' => 'Sup', 'active' => true]);
        $wh ??= $this->makeWarehouse();
        $o = SupplierOrder::create([
            'number' => SupplierOrder::nextNumber(), 'supplier_id' => $s->id, 'warehouse_id' => $wh->id,
            'status' => 'open', 'ordered_on' => today(), 'total' => $qty * $cost, 'created_by' => $this->makeAdmin()->id,
        ]);
        $o->items()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_cost' => $cost]);

        return $o;
    }

    public function test_passed_line_enters_stock_as_a_batch_with_lot_and_expiry(): void
    {
        $p = $this->makeProduct(['kind' => 'raw']);
        $o = $this->order($p);
        $admin = $this->makeAdmin();

        $receipt = $o->receive([$p->id => [
            'qty' => 10, 'qc' => 'pass', 'batch_no' => 'L-77', 'expires_on' => '2027-03-01',
        ]], $admin);

        $b = Batch::where('product_id', $p->id)->firstOrFail();
        $this->assertSame('L-77', $b->batch_no);
        $this->assertSame('2027-03-01', $b->expires_on->toDateString());
        $this->assertSame(10, (int) $b->qty_remaining);
        $i = ReceiptInspection::firstOrFail();
        $this->assertSame('pass', $i->result);
        $this->assertSame($b->id, $i->batch_id);
        $this->assertSame($receipt->id, $i->goods_receipt_id);
        $this->assertSame(10, (int) $o->items()->first()->received_qty);
    }

    public function test_failed_line_does_not_enter_stock_and_creates_a_supplier_return(): void
    {
        Storage::fake('public');
        $p = $this->makeProduct();
        $o = $this->order($p, 20, 8);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('erp.purchasing.receive', $o), [
            'lines' => [$p->id => [
                'qty' => 5, 'qc' => 'fail', 'qc_note' => 'مكسور',
                'qc_photo' => UploadedFile::fake()->image('bad.jpg'),
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Batch::where('product_id', $p->id)->count());
        $item = $o->items()->first();
        $this->assertSame(5, (int) $item->rejected_qty);
        $this->assertSame(0, (int) $item->received_qty);

        $r = SupplierReturn::firstOrFail();
        $this->assertSame('SRET-1001', $r->number);
        $this->assertSame($o->id, $r->supplier_order_id);
        $this->assertSame('40.00', (string) $r->total);
        $this->assertSame(today()->toDateString(), $r->returned_on->toDateString());
        $this->assertSame(1, $r->items()->count());
        $line = $r->items()->first();
        $this->assertSame(5, (int) $line->qty);
        $this->assertSame('8.00', (string) $line->unit_cost);
        $this->assertSame('مكسور', $line->note);
        $this->assertNotNull($line->photo_path);
        Storage::disk('public')->assertExists($line->photo_path);

        $i = ReceiptInspection::firstOrFail();
        $this->assertSame('fail', $i->result);
        $this->assertNull($i->batch_id);
        $this->assertSame($r->id, $i->supplier_return_id);
        $this->assertSame('open', $o->fresh()->status);
    }

    public function test_mixed_receipt_invoices_only_passed_qty(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $o = $this->order($p1, 10, 8);
        $o->items()->create(['product_id' => $p2->id, 'qty' => 10, 'unit_cost' => 5]);
        $admin = $this->makeAdmin();

        $receipt = $o->receive([
            $p1->id => ['qty' => 10, 'qc' => 'pass'],
            $p2->id => ['qty' => 4, 'qc' => 'fail', 'qc_note' => 'x'],
        ], $admin);

        $this->assertSame(1, $receipt->supplierReturn()->count());
        $this->assertSame('20.00', (string) $receipt->supplierReturn->total);

        $this->actingAs($admin)->post(route('erp.purchasing.invoice', $o), [
            'invoice_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('80.00', number_format((float) $o->invoices()->first()->subtotal, 2, '.', ''));
    }

    public function test_one_return_per_receipt_for_several_failed_lines(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $o = $this->order($p1, 10, 8);
        $o->items()->create(['product_id' => $p2->id, 'qty' => 10, 'unit_cost' => 5]);

        $o->receive([
            $p1->id => ['qty' => 2, 'qc' => 'fail'],
            $p2->id => ['qty' => 3, 'qc' => 'fail'],
        ], $this->makeAdmin());

        $this->assertSame(1, SupplierReturn::count());
        $this->assertSame(2, SupplierReturn::first()->items()->count());
        $this->assertSame('31.00', (string) SupplierReturn::first()->total);
    }

    public function test_raw_line_without_lot_or_expiry_is_refused(): void
    {
        $p = $this->makeProduct(['kind' => 'raw']);
        $o = $this->order($p);
        $admin = $this->makeAdmin();

        foreach ([
            ['qty' => 5, 'expires_on' => '2027-01-01'],
            ['qty' => 5, 'batch_no' => 'L1'],
        ] as $line) {
            try {
                $o->receive([$p->id => $line], $admin);
                $this->fail('expected Rejected');
            } catch (Rejected $e) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, Batch::count());
        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, (int) $o->items()->first()->received_qty);
    }

    public function test_finished_line_without_qc_fields_behaves_as_before(): void
    {
        $p = $this->makeProduct();
        $o = $this->order($p);

        $receipt = $o->receive([$p->id => ['qty' => 6]], $this->makeAdmin());

        $b = Batch::where('product_id', $p->id)->firstOrFail();
        $this->assertSame($receipt->number, $b->batch_no);
        $this->assertSame(6, (int) $b->qty_remaining);
        $this->assertSame('pass', ReceiptInspection::firstOrFail()->result);
        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_rejected_qty_counts_against_over_receipt(): void
    {
        $p = $this->makeProduct();
        $o = $this->order($p, 10);
        $admin = $this->makeAdmin();

        $o->receive([$p->id => ['qty' => 6, 'qc' => 'pass']], $admin);
        foreach (['pass', 'fail'] as $qc) {
            try {
                $o->receive([$p->id => ['qty' => 5, 'qc' => $qc]], $admin);
                $this->fail('expected Rejected');
            } catch (Rejected $e) {
                $this->assertStringContainsString('4', $e->getMessage());
            }
        }
        // rejected goods do not consume the order: the supplier can resend
        $o->receive([$p->id => ['qty' => 4, 'qc' => 'fail']], $admin);
        $o->receive([$p->id => ['qty' => 4, 'qc' => 'pass']], $admin);
        $this->assertSame('received', $o->fresh()->status);
        $this->assertSame(4, (int) $o->items()->first()->rejected_qty);
    }

    public function test_photos_are_deleted_when_receive_is_rejected(): void
    {
        Storage::fake('public');
        $p = $this->makeProduct();
        $o = $this->order($p, 3);

        $this->actingAs($this->makeAdmin())->post(route('erp.purchasing.receive', $o), [
            'lines' => [$p->id => [
                'qty' => 5, 'qc' => 'fail', 'qc_photo' => UploadedFile::fake()->image('bad.jpg'),
            ]],
        ])->assertSessionHasErrors('lines');

        $this->assertSame([], Storage::disk('public')->allFiles('receipt-qc'));
    }

    public function test_returns_list_filters_by_date_and_exports_csv(): void
    {
        $p = $this->makeProduct();
        $o = $this->order($p, 20, 8);
        $o->receive([$p->id => ['qty' => 2, 'qc' => 'fail']], $this->makeAdmin());
        $old = SupplierReturn::firstOrFail();
        $old->update(['returned_on' => '2026-01-05']);
        $o->receive([$p->id => ['qty' => 3, 'qc' => 'fail']], $this->makeAdmin());

        $manager = $this->makeAdmin(['role' => 'manager']);

        $this->actingAs($manager)->get(route('erp.purchasing.returns', ['from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertOk()->assertSee('SRET-1001')->assertDontSee('SRET-1002');

        $csv = $this->actingAs($manager)->get(route('erp.purchasing.returns', ['export' => 1]));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('SRET-1001', $body);
        $this->assertStringContainsString('SRET-1002', $body);

        $this->actingAs($manager)->get(route('erp.purchasing.returns.show', $old))
            ->assertOk()->assertSee('SRET-1001');
    }

    public function test_keeper_of_other_warehouse_still_gets_403(): void
    {
        $p = $this->makeProduct();
        $o = $this->order($p);
        $other = $this->makeWarehouse();
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper', 'warehouse_id' => $other->id]);

        $this->actingAs($keeper)->post(route('erp.purchasing.receive', $o), [
            'lines' => [$p->id => ['qty' => 1, 'qc' => 'pass']],
        ])->assertForbidden();
    }

    public function test_keeper_sees_only_returns_of_his_warehouse(): void
    {
        $p = $this->makeProduct();
        $mine = $this->order($p);
        $theirs = $this->order($p);
        $admin = $this->makeAdmin();
        $mine->receive([$p->id => ['qty' => 1, 'qc' => 'fail']], $admin);
        $theirs->receive([$p->id => ['qty' => 1, 'qc' => 'fail']], $admin);
        $keeper = $this->makeAdmin(['role' => 'warehouse_keeper', 'warehouse_id' => $mine->warehouse_id]);

        $this->actingAs($keeper)->get(route('erp.purchasing.returns'))
            ->assertOk()->assertSee('SRET-1001')->assertDontSee('SRET-1002');
    }
}
