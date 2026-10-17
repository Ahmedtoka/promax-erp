<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchLocation;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\GiftHandout;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\MerchVisit;
use App\Models\PickOrder;
use App\Models\PickOrderItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ReplenishmentItem;
use App\Models\ReplenishmentRequest;
use App\Models\ShelfRefill;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WarehouseVisits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * منع التكرار والسباق في أكشنات الموبايل (الخط ١، ١٧/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الدليل من اللايف: INV-1167 وINV-1168 — نفس المندوب والعميل والمبلغ
 * والورقية 1161، بينهم 37 ثانية. كل مسار هنا: طلبين بنفس `idem_key`
 * = مستند واحد وخصم واحد، والأبلكيشن القديم (من غير مفتاح) شغال.
 */
class MobileIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** مندوب بحضور وعهدة مفتوحة فيها صنف بباتش */
    private function stockedRep(int $qty = 50, int $gifts = 0): array
    {
        $rep = $this->punchIn($this->makeRep());
        $product = $this->makeProduct(['cost' => 10, 'price_old' => 18, 'price_new' => 20]);
        $warehouse = $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'batch_no' => 'B-1',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => $qty, 'qty_remaining' => $qty, 'cost' => 10,
        ]);

        $custody = Custody::create([
            'user_id' => $rep->id, 'warehouse_id' => $warehouse->id, 'date' => today(), 'status' => 'open',
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id, 'product_id' => $product->id, 'batch_id' => $batch->id,
            'assigned' => $qty, 'sold' => 0, 'gift_assigned' => $gifts, 'gift_given' => 0,
        ]);

        return [$rep, $product, $custody, $warehouse, $batch];
    }

    // ═══════════════════ الفاتورة ═══════════════════

    public function test_same_invoice_key_returns_the_same_invoice_and_deducts_once(): void
    {
        [$rep, $product, $custody] = $this->stockedRep();
        $client = $this->makeClient();
        $visit = $this->openVisit($rep, $client);
        $h = $this->tokenFor($rep);

        $body = [
            'client_id' => $client->id, 'visit_id' => $visit->id, 'idem_key' => 'inv-key-1',
            'items' => [['product_id' => $product->id, 'qty' => 5]],
        ];

        $a = $this->withHeaders($h)->postJson('/api/invoices', $body)->assertStatus(201);
        $b = $this->withHeaders($h)->postJson('/api/invoices', $body)->assertOk();

        $this->assertSame($a->json('invoice.id'), $b->json('invoice.id'));
        $this->assertSame($a->json('invoice.number'), $b->json('invoice.number'));
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Transaction::where('kind', 'sale')->count());
        $this->assertSame(5, (int) $custody->items()->first()->sold, 'خصم واحد بس من العهدة');
    }

    public function test_replay_still_returns_the_invoice_after_the_visit_closed(): void
    {
        [$rep, $product] = $this->stockedRep();
        $client = $this->makeClient();
        $visit = $this->openVisit($rep, $client);
        $h = $this->tokenFor($rep);

        $body = [
            'client_id' => $client->id, 'visit_id' => $visit->id, 'idem_key' => 'inv-key-2',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ];

        $id = $this->withHeaders($h)->postJson('/api/invoices', $body)->assertStatus(201)->json('invoice.id');
        $visit->update(['checked_out_at' => now()]);

        $this->assertSame($id, $this->withHeaders($h)->postJson('/api/invoices', $body)->assertOk()->json('invoice.id'));
    }

    public function test_invoices_without_a_key_still_work_like_old_app_versions(): void
    {
        [$rep, $product, $custody] = $this->stockedRep();
        $client = $this->makeClient();

        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 2]])->assertStatus(201);
        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 2]])->assertStatus(201);

        $this->assertSame(2, Invoice::count(), 'من غير مفتاح ولا ورقية = فاتورتين زي الأول');
        $this->assertSame(4, (int) $custody->items()->first()->sold);
    }

    /** L-6 بالظبط: نفس الورقية لنفس المندوب والعميل في نفس اليوم */
    public function test_duplicate_paper_ref_same_rep_client_day_is_refused(): void
    {
        [$rep, $product, $custody] = $this->stockedRep();
        $client = $this->makeClient();

        $first = $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 3]], ['paper_ref' => '1161'])
            ->assertStatus(201);

        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 3]], ['paper_ref' => ' 1161 '])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => __('idem.paper_ref_taken', [
                'ref' => '1161', 'number' => $first->json('invoice.number'),
            ])]);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(3, (int) $custody->items()->first()->sold, 'الرفض مابيخصمش');
    }

    public function test_same_paper_ref_for_another_client_or_day_is_allowed(): void
    {
        [$rep, $product] = $this->stockedRep();
        $a = $this->makeClient();
        $b = $this->makeClient();

        $this->sellApi($rep, $a, [['product_id' => $product->id, 'qty' => 1]], ['paper_ref' => '77'])->assertStatus(201);
        $this->sellApi($rep, $b, [['product_id' => $product->id, 'qty' => 1]], ['paper_ref' => '77'])->assertStatus(201);

        // نفس العميل بس امبارح
        Invoice::where('client_id', $a->id)->update(['created_at' => now()->subDay()]);
        $this->sellApi($rep, $a, [['product_id' => $product->id, 'qty' => 1]], ['paper_ref' => '77'])->assertStatus(201);

        $this->assertSame(3, Invoice::count());
    }

    // ═══════════════════ الهدايا ═══════════════════

    public function test_same_gift_key_records_one_handout(): void
    {
        [$rep, $product, $custody] = $this->stockedRep(10, 5);
        $h = $this->tokenFor($rep);
        $body = ['product_id' => $product->id, 'qty' => 2, 'idem_key' => 'gift-key-1'];

        $this->withHeaders($h)->postJson('/api/gifts', $body)->assertOk();
        $this->withHeaders($h)->postJson('/api/gifts', $body)->assertOk();

        $this->assertSame(1, GiftHandout::count());
        $this->assertSame(2, (int) $custody->items()->first()->gift_given);
    }

    public function test_gifts_without_a_key_still_work(): void
    {
        [$rep, $product, $custody] = $this->stockedRep(10, 5);
        $h = $this->tokenFor($rep);

        $this->withHeaders($h)->postJson('/api/gifts', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->withHeaders($h)->postJson('/api/gifts', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $this->assertSame(2, (int) $custody->items()->first()->gift_given);
    }

    public function test_gift_visit_must_be_my_open_visit_on_the_same_client(): void
    {
        [$rep, $product, $custody] = $this->stockedRep(10, 5);
        $client = $this->makeClient();
        $other = $this->makeClient();
        $h = $this->tokenFor($rep);

        // زيارة مندوب تاني
        $stranger = $this->punchIn($this->makeRep());
        $theirs = $this->openVisit($stranger, $client);
        $this->withHeaders($h)->postJson('/api/gifts', [
            'product_id' => $product->id, 'qty' => 1, 'client_id' => $client->id, 'visit_id' => $theirs->id,
        ])->assertStatus(403);

        // زيارتي بس على عميل تاني
        $mine = $this->openVisit($rep, $other);
        $this->withHeaders($h)->postJson('/api/gifts', [
            'product_id' => $product->id, 'qty' => 1, 'client_id' => $client->id, 'visit_id' => $mine->id,
        ])->assertStatus(422);

        // زيارتي مقفولة
        $mine->update(['client_id' => $client->id, 'checked_out_at' => now()]);
        $this->withHeaders($h)->postJson('/api/gifts', [
            'product_id' => $product->id, 'qty' => 1, 'client_id' => $client->id, 'visit_id' => $mine->id,
        ])->assertStatus(422);

        $this->assertSame(0, GiftHandout::count());
        $this->assertSame(0, (int) $custody->items()->first()->gift_given);

        // زيارتي المفتوحة على نفس العميل = تمام
        $ok = $this->openVisit($rep, $client);
        $this->withHeaders($h)->postJson('/api/gifts', [
            'product_id' => $product->id, 'qty' => 1, 'client_id' => $client->id, 'visit_id' => $ok->id,
        ])->assertOk();
        $this->assertSame(1, GiftHandout::count());
    }

    // ═══════════════════ تسليم أمر التوريد ═══════════════════

    private function arrivedPo(User $rep, int $productId, int $qty = 4): PurchaseOrder
    {
        $client = $this->makeClient();
        $po = PurchaseOrder::create([
            'number' => 'PO-IDEM-'.uniqid(), 'client_id' => $client->id, 'status' => 'arrived',
            'assigned_to' => $rep->id, 'arrived_at' => now(),
            'total' => 20 * $qty, 'tax_total' => 0, 'grand_total' => 20 * $qty,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $productId, 'qty' => $qty,
            'price' => 20, 'total' => 20 * $qty, 'list_price' => 20, 'tax_rate' => 0, 'tax' => 0,
        ]);

        return $po;
    }

    public function test_same_deliver_key_returns_the_same_summary_and_posts_once(): void
    {
        [$rep, $product, $custody] = $this->stockedRep();
        $po = $this->arrivedPo($rep, $product->id);
        $h = $this->tokenFor($rep);

        $a = $this->withHeaders($h)->postJson("/api/pos/{$po->id}/deliver", ['idem_key' => 'po-key-1'])->assertOk();
        $b = $this->withHeaders($h)->postJson("/api/pos/{$po->id}/deliver", ['idem_key' => 'po-key-1'])->assertOk();

        $this->assertSame($a->json(), $b->json());
        $this->assertSame(1, Transaction::where('source_type', PurchaseOrder::class)->where('kind', 'sale')->count());
        $this->assertSame(4, (int) $custody->items()->first()->sold);
    }

    public function test_second_deliver_without_a_key_is_refused_and_changes_nothing(): void
    {
        [$rep, $product, $custody] = $this->stockedRep();
        $po = $this->arrivedPo($rep, $product->id);
        $h = $this->tokenFor($rep);

        $this->withHeaders($h)->postJson("/api/pos/{$po->id}/deliver")->assertOk();
        $this->withHeaders($h)->postJson("/api/pos/{$po->id}/deliver")->assertStatus(422);

        $this->assertSame(1, Transaction::where('source_type', PurchaseOrder::class)->where('kind', 'sale')->count());
        $this->assertSame(4, (int) $custody->items()->first()->sold);
    }

    // ═══════════════════ استلام أمر التجهيز ═══════════════════

    private function readyPick(User $rep, Warehouse $wh, Batch $batch, int $qty = 6): PickOrder
    {
        $pick = PickOrder::create([
            'number' => 'PCK-IDEM-'.uniqid(), 'warehouse_id' => $wh->id, 'assigned_to' => $rep->id,
            'requested_by' => $rep->id, 'purpose' => PickOrder::PURPOSE_VAN_LOAD, 'status' => 'ready',
            'ready_at' => now(),
        ]);
        PickOrderItem::create([
            'pick_order_id' => $pick->id, 'product_id' => $batch->product_id, 'batch_id' => $batch->id,
            'qty_requested' => $qty, 'qty_picked' => $qty,
        ]);

        return $pick;
    }

    public function test_same_receive_key_loads_the_van_once(): void
    {
        [$rep, , $custody, $wh, $batch] = $this->stockedRep(0);
        [$err] = WarehouseVisits::checkIn($rep, $wh);
        $this->assertNull($err);
        $pick = $this->readyPick($rep, $wh, $batch);
        $h = $this->tokenFor($rep);

        $this->withHeaders($h)->postJson("/api/picks/{$pick->id}/receive", ['idem_key' => 'pick-key-1'])->assertOk();
        $this->withHeaders($h)->postJson("/api/picks/{$pick->id}/receive", ['idem_key' => 'pick-key-1'])
            ->assertOk()->assertJsonPath('pick.id', $pick->id);

        $this->assertSame(6, (int) $custody->items()->sum('assigned'), 'البضاعة نزلت العهدة مرة واحدة');
    }

    public function test_second_receive_without_a_key_is_refused(): void
    {
        [$rep, , $custody, $wh, $batch] = $this->stockedRep(0);
        WarehouseVisits::checkIn($rep, $wh);
        $pick = $this->readyPick($rep, $wh, $batch);
        $h = $this->tokenFor($rep);

        $this->withHeaders($h)->postJson("/api/picks/{$pick->id}/receive")->assertOk();
        $this->withHeaders($h)->postJson("/api/picks/{$pick->id}/receive")->assertStatus(422);

        $this->assertSame(6, (int) $custody->items()->sum('assigned'));
    }

    /** النسخة اللي في الذاكرة لسه `ready` بس الصف اتسلّم — القفل بيقرا الصف */
    public function test_hand_over_reads_the_status_under_the_lock(): void
    {
        [$rep, , $custody, $wh, $batch] = $this->stockedRep(0);
        $pick = $this->readyPick($rep, $wh, $batch);

        $stale = PickOrder::find($pick->id);
        $this->assertNull($pick->handOver($rep));

        $this->assertSame(__('stock.pick_not_ready'), $stale->handOver($rep));
        $this->assertSame(6, (int) $custody->items()->sum('assigned'));
    }

    // ═══════════════════ تنزيل الريفيل من موبايل المدير ═══════════════════

    private function pendingReplenishment(): array
    {
        $admin = $this->punchIn($this->makeAdmin());
        $rep = $this->punchIn($this->makeRep());
        $product = $this->makeProduct();
        $wh = Warehouse::defaultBranch() ?? $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id, 'warehouse_id' => $wh->id, 'batch_no' => 'R-1',
            'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
            'qty_received' => 40, 'qty_remaining' => 40, 'cost' => 10,
        ]);
        $loc = Location::create(['warehouse_id' => $wh->id, 'code' => 'A1', 'stand' => 'A', 'level' => 1, 'active' => true]);
        BatchLocation::create(['batch_id' => $batch->id, 'location_id' => $loc->id, 'product_id' => $product->id, 'qty' => 40]);

        Custody::create(['user_id' => $rep->id, 'warehouse_id' => $wh->id, 'date' => today(), 'status' => 'open']);

        $client = $this->makeClient(['rep_id' => $rep->id]);
        $r = ReplenishmentRequest::create([
            'number' => 'RPL-'.uniqid(), 'client_id' => $client->id, 'requested_by' => $rep->id, 'status' => 'pending',
        ]);
        ReplenishmentItem::create(['replenishment_request_id' => $r->id, 'product_id' => $product->id, 'qty' => 5]);

        return [$admin, $rep, $r];
    }

    public function test_same_assign_key_creates_one_pick_order(): void
    {
        [$admin, $rep, $r] = $this->pendingReplenishment();
        $h = $this->tokenFor($admin);
        $body = ['assigned_to' => $rep->id, 'idem_key' => 'rpl-key-1'];

        $a = $this->withHeaders($h)->postJson("/api/manager/replenishments/{$r->id}/assign", $body)->assertOk();
        $b = $this->withHeaders($h)->postJson("/api/manager/replenishments/{$r->id}/assign", $body)->assertOk();

        $this->assertSame($a->json('pick_id'), $b->json('pick_id'));
        $this->assertSame(1, PickOrder::where('replenishment_request_id', $r->id)->count());
    }

    public function test_second_assign_without_a_key_is_refused(): void
    {
        [$admin, $rep, $r] = $this->pendingReplenishment();
        $h = $this->tokenFor($admin);

        $this->withHeaders($h)->postJson("/api/manager/replenishments/{$r->id}/assign", ['assigned_to' => $rep->id])->assertOk();
        $this->withHeaders($h)->postJson("/api/manager/replenishments/{$r->id}/assign", ['assigned_to' => $rep->id])
            ->assertStatus(422);

        $this->assertSame(1, PickOrder::where('replenishment_request_id', $r->id)->count());
    }

    // ═══════════════════ الريفيل (البروموتر) ═══════════════════

    public function test_same_refill_key_does_not_overwrite_a_newer_save(): void
    {
        $promoter = $this->punchIn($this->makeRep(['role' => 'promoter']));
        $product = $this->makeProduct();
        $client = $this->makeClient();
        $visit = MerchVisit::create(['user_id' => $promoter->id, 'client_id' => $client->id, 'checked_in_at' => now()]);
        $h = $this->tokenFor($promoter);
        $url = "/api/promoter/visits/{$visit->id}/refill";

        $old = ['idem_key' => 'refill-1', 'lines' => [['product_id' => $product->id, 'store_qty' => 10, 'moved_qty' => 4]]];
        $new = ['idem_key' => 'refill-2', 'lines' => [['product_id' => $product->id, 'store_qty' => 10, 'moved_qty' => 7]]];

        $this->withHeaders($h)->postJson($url, $old)->assertOk();
        $this->withHeaders($h)->postJson($url, $new)->assertOk();
        // إعادة إرسال متأخرة للمحاولة الأولى
        $this->withHeaders($h)->postJson($url, $old)->assertOk();

        $this->assertSame(1, ShelfRefill::where('merch_visit_id', $visit->id)->count());
        $this->assertSame(7, (int) ShelfRefill::where('merch_visit_id', $visit->id)->value('moved_qty'));

        // من غير مفتاح = الكتابة زي الأول
        $this->withHeaders($h)->postJson($url, ['lines' => [['product_id' => $product->id, 'store_qty' => 10, 'moved_qty' => 2]]])
            ->assertOk();
        $this->assertSame(2, (int) ShelfRefill::where('merch_visit_id', $visit->id)->value('moved_qty'));
    }

    // ═══════════════════ الهيلبر ═══════════════════

    public function test_keys_are_scoped_per_user_and_action(): void
    {
        [$rep, $product] = $this->stockedRep();
        [$other, $otherProduct] = $this->stockedRep();
        $client = $this->makeClient();

        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 1]], ['idem_key' => 'shared'])->assertStatus(201);
        $this->sellApi($other, $client, [['product_id' => $otherProduct->id, 'qty' => 1]], ['idem_key' => 'shared'])->assertStatus(201);

        $this->assertSame(2, Invoice::count(), 'نفس المفتاح من مندوبين = فاتورتين');
        $this->assertSame(2, DB::table('idempotency_keys')->count());
    }
}
