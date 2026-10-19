<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\MerchVisit;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\PickOrder;
use App\Models\ShopifyProductLink;
use App\Services\ShopifyOnline;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review Focus ٤ (خطة القفل ١٩/١٠): **الخامة والتعبئة ماتظهرش أبداً في
 * أي مكان بيتباع فيه منتج تام** — الكتالوج، قوايم الأسعار، تحميل
 * العربية، روابط الأونلاين، الفواتير، الريفيل، طلبات البضاعة.
 *
 * كل تيست بيعمل صنف تام وخامة بنفس الشكل، وبيتأكد إن التام موجود
 * والخامة لأ (أو إن الطلب بالخامة بيترفض).
 */
class SellableKindTest extends TestCase
{
    use RefreshDatabase;

    private Product $fin;

    private Product $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makePriceList();
        $this->fin = $this->makeProduct(['code' => 'FG-'.strtoupper(uniqid())]);
        $this->raw = $this->makeProduct(['code' => 'RM-'.strtoupper(uniqid()), 'kind' => 'raw']);
    }

    /** @return array{0: User, 1: \App\Models\Client} */
    private function repWithClient(): array
    {
        $rep = $this->punchIn($this->makeRep());
        $client = $this->makeClient(['rep_id' => $rep->id]);

        return [$rep, $client];
    }

    private function idsIn(array $rows, string $key = 'product_id'): array
    {
        return array_map(fn ($r) => (int) $r[$key], $rows);
    }

    // ═══════════════ الأبلكيشن ═══════════════

    public function test_catalog_api_excludes_raw(): void
    {
        [$rep, $client] = $this->repWithClient();

        $res = $this->withHeaders($this->tokenFor($rep))->getJson("/api/clients/{$client->id}/catalog")->assertOk();
        $ids = $this->idsIn($res->json('items') ?? $res->json('data') ?? $res->json());

        $this->assertContains($this->fin->id, $ids);
        $this->assertNotContains($this->raw->id, $ids);
    }

    public function test_client_prices_api_excludes_raw_in_custody(): void
    {
        [$rep, $client] = $this->repWithClient();
        $wh = $this->makeWarehouse();
        $custody = Custody::create(['user_id' => $rep->id, 'warehouse_id' => $wh->id, 'date' => today(), 'status' => 'open']);
        foreach ([$this->fin, $this->raw] as $p) {
            $batch = Batch::create([
                'product_id' => $p->id, 'warehouse_id' => $wh->id, 'batch_no' => 'B-'.uniqid(),
                'produced_on' => today()->subMonth(), 'expires_on' => today()->addMonths(6),
                'qty_received' => 10, 'qty_remaining' => 10, 'cost' => 10,
            ]);
            CustodyItem::create(['custody_id' => $custody->id, 'product_id' => $p->id, 'batch_id' => $batch->id, 'assigned' => 5, 'sold' => 0]);
        }

        $res = $this->withHeaders($this->tokenFor($rep))->getJson("/api/clients/{$client->id}/prices")->assertOk();
        $body = json_encode($res->json());

        $this->assertStringContainsString('"product_id":'.$this->fin->id, $body);
        $this->assertStringNotContainsString('"product_id":'.$this->raw->id.',', $body);
    }

    public function test_returnable_api_excludes_raw(): void
    {
        [$rep, $client] = $this->repWithClient();

        $res = $this->withHeaders($this->tokenFor($rep))->getJson("/api/clients/{$client->id}/returnable")->assertOk();
        $body = json_encode($res->json());

        $this->assertStringContainsString('"product_id":'.$this->fin->id.',', $body);
        $this->assertStringNotContainsString('"product_id":'.$this->raw->id.',', $body);
    }

    public function test_app_invoice_with_raw_is_refused(): void
    {
        [$rep, $client] = $this->repWithClient();

        $this->sellApi($rep, $client, [['product_id' => $this->raw->id, 'qty' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_goods_request_with_raw_is_refused(): void
    {
        [$rep, $client] = $this->repWithClient();
        $visit = $this->openVisit($rep, $client);

        $this->withHeaders($this->tokenFor($rep))->postJson('/api/goods-requests', [
            'visit_id' => $visit->id,
            'items' => [['product_id' => $this->raw->id, 'qty' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
    }

    public function test_promoter_bootstrap_excludes_raw(): void
    {
        $promoter = $this->makeRep(['role' => 'promoter']);

        $ids = array_map(fn ($p) => (int) $p['id'],
            $this->withHeaders($this->tokenFor($promoter))->getJson('/api/promoter/bootstrap')->assertOk()->json('products'));

        $this->assertContains($this->fin->id, $ids);
        $this->assertNotContains($this->raw->id, $ids);
    }

    public function test_replenishment_request_with_raw_is_refused(): void
    {
        $promoter = $this->punchIn($this->makeRep(['role' => 'promoter']));
        $client = $this->makeClient();
        $visit = MerchVisit::create(['user_id' => $promoter->id, 'client_id' => $client->id, 'checked_in_at' => now()]);

        $this->withHeaders($this->tokenFor($promoter))
            ->postJson("/api/promoter/visits/{$visit->id}/replenishment", [
                'items' => [['product_id' => $this->raw->id, 'qty' => 2]],
            ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
    }

    // ═══════════════ شاشات الويب ═══════════════

    private function screenShowsOnlyFinished(string $url): void
    {
        // ⚠️ مش assertSee/assertDontSee: لما بيفشلوا بيطبعوا الصفحة كلها
        // ورسالة الفرق على صفحة بالحجم ده بتاخد دقايق
        $html = $this->actingAs($this->makeAdmin())->get($url)->assertOk()->getContent();
        $this->assertTrue(str_contains($html, $this->fin->code), "finished {$this->fin->code} missing on {$url}");
        $this->assertFalse(str_contains($html, $this->raw->code), "raw {$this->raw->code} listed on {$url}");
    }

    public function test_price_list_screen_excludes_raw(): void
    {
        $list = $this->makePriceList();
        $this->screenShowsOnlyFinished(route('erp.prices.show', $list));
    }

    public function test_price_list_missing_ignores_raw(): void
    {
        $list = $this->makePriceList();

        $this->assertNotContains($this->raw->id, $list->missing()->pluck('id')->all());
        $this->assertContains($this->fin->id, $list->missing()->pluck('id')->all());
    }

    public function test_price_list_bulk_ignores_raw(): void
    {
        $list = $this->makePriceList();

        $this->actingAs($this->makeAdmin())
            ->post(route('erp.prices.bulk', $list), ['ids' => [$this->raw->id], 'mode' => 'set', 'value' => 5])
            ->assertSessionHasErrors('ids');
    }

    public function test_client_card_priced_excludes_raw(): void
    {
        $this->screenShowsOnlyFinished(route('erp.clients.show', $this->makeClient()));
    }

    public function test_handout_screen_excludes_raw(): void
    {
        $this->screenShowsOnlyFinished(route('ops.handout', ['warehouse' => $this->makeWarehouse()->id]));
    }

    public function test_handout_store_refuses_raw(): void
    {
        $wh = $this->makeWarehouse();
        $rep = $this->makeRep();

        $this->actingAs($this->makeAdmin())->post(route('ops.handout.store'), [
            'warehouse_id' => $wh->id, 'rep_id' => $rep->id, 'qty' => [$this->raw->id => 3],
        ])->assertSessionHasErrors(['qty' => $this->raw->notSellableReason()]);

        $this->assertSame(0, PickOrder::count());
    }

    private function makePick(): PickOrder
    {
        return PickOrder::create([
            'number' => 'PCK-'.uniqid(), 'warehouse_id' => $this->makeWarehouse()->id, 'assigned_to' => $this->makeRep()->id,
            'requested_by' => $this->makeAdmin()->id, 'purpose' => PickOrder::PURPOSE_VAN_LOAD, 'status' => 'requested',
        ]);
    }

    public function test_pick_screen_excludes_raw(): void
    {
        $this->screenShowsOnlyFinished(route('wh.picks.show', $this->makePick()));
    }

    public function test_pick_update_refuses_raw(): void
    {
        $pick = $this->makePick();

        $this->actingAs($this->makeAdmin())->post(route('wh.picks.update', $pick), ['qty' => [$this->raw->id => 2]])
            ->assertSessionHasErrors(['qty' => $this->raw->notSellableReason()]);

        $this->assertSame(0, $pick->items()->count());
    }

    public function test_replenishment_screen_excludes_raw(): void
    {
        $this->screenShowsOnlyFinished(route('ops.replenishments'));
    }

    public function test_po_handout_excludes_raw(): void
    {
        $this->screenShowsOnlyFinished(route('ops.po.handout'));
    }

    public function test_returns_screen_excludes_raw(): void
    {
        $this->screenShowsOnlyFinished(route('ops.returns'));
    }

    public function test_manual_doc_catalog_excludes_raw(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);

        $rows = $this->actingAs($this->makeAdmin())
            ->getJson(route('ops.manual.data', ['user_id' => $rep->id, 'client_id' => $client->id]))
            ->assertOk()->json('ret_products');
        $ids = array_map(fn ($r) => (int) ($r['id'] ?? $r['product_id']), $rows);

        $this->assertContains($this->fin->id, $ids);
        $this->assertNotContains($this->raw->id, $ids);
    }

    public function test_edit_invoice_items_refuses_raw(): void
    {
        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(70000, 79999), 'client_id' => $this->makeClient()->id, 'user_id' => $this->makeRep()->id,
            'payment' => 'cash', 'subtotal' => 0, 'discount' => 0, 'total' => 0, 'tax_total' => 0, 'grand_total' => 0,
        ]);

        $this->actingAs($this->makeAdmin())->post(route('ops.invoices.items', $invoice), [
            'reason' => 'x', 'items' => [['product_id' => $this->raw->id, 'qty' => 1]],
        ])->assertSessionHasErrors('items.0.product_id');
    }

    // ═══════════════ الأونلاين ═══════════════

    public function test_online_products_screen_excludes_raw(): void
    {
        ShopifyProductLink::create(['shopify_variant_id' => 1001, 'shopify_product_id' => 10, 'title' => 'Bar', 'product_id' => null, 'units' => 1]);
        $this->screenShowsOnlyFinished(route('online.products'));
    }

    public function test_online_sync_screen_excludes_raw(): void
    {
        $this->screenShowsOnlyFinished(route('online.sync'));
    }

    public function test_online_link_to_raw_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $link = ShopifyProductLink::create(['shopify_variant_id' => 1002, 'shopify_product_id' => 10, 'title' => 'Bar', 'product_id' => null, 'units' => 1]);

        $this->actingAs($admin)->post(route('online.products.save'), ['links' => [$link->id => $this->raw->id]])
            ->assertSessionHasErrors('links.'.$link->id);
        $this->actingAs($admin)->post(route('online.products.bundle', $link), ['parts' => [['product_id' => $this->raw->id, 'units' => 1]]])
            ->assertSessionHasErrors('parts.0.product_id');

        $o = OnlineOrder::create(['shopify_id' => 991300, 'number' => '1300', 'customer_name' => 'X', 'items_count' => 1,
            'subtotal' => 10, 'shipping' => 0, 'total' => 10, 'status' => 'new']);
        $item = OnlineOrderItem::create(['online_order_id' => $o->id, 'shopify_variant_id' => 1003, 'title' => 'Bar',
            'product_id' => null, 'units_per' => 1, 'qty' => 1, 'price' => 10, 'total' => 10]);
        $this->actingAs($admin)->post(route('online.item.link', $item), ['product_id' => $this->raw->id, 'units' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertNull($link->fresh()->product_id);
        $this->assertNull($item->fresh()->product_id);
    }

    public function test_shopify_sku_never_matches_raw(): void
    {
        $item = new OnlineOrderItem(['shopify_variant_id' => 424242, 'sku' => $this->raw->code]);
        $this->assertNull(ShopifyOnline::matchItem($item)['product_id']);

        $item = new OnlineOrderItem(['shopify_variant_id' => 424243, 'sku' => $this->fin->code]);
        $this->assertSame($this->fin->id, ShopifyOnline::matchItem($item)['product_id']);
    }

    public function test_pick_update_still_edits_an_order_holding_a_later_drafted_line(): void
    {
        $pick = $this->makePick();
        $old = $this->makeProduct(['code' => 'OLD-'.strtoupper(uniqid())]);
        $pick->items()->create(['product_id' => $old->id, 'qty_requested' => 4]);
        $old->update(['active' => false]);   // اتوقف بعد ما الأمر اترفع

        $this->actingAs($this->makeAdmin())
            ->post(route('wh.picks.update', $pick), ['qty' => [$old->id => 4, $this->fin->id => 0]])
            // (المخزن فاضي في التيست فممكن يطلع خطأ رصيد — المهم مش رفض الصنف)
            ->assertSessionDoesntHaveErrors(['qty' => $old->fresh()->notSellableReason()]);
    }

    public function test_existing_shopify_link_to_a_reclassified_raw_product_no_longer_matches(): void
    {
        ShopifyProductLink::create(['shopify_variant_id' => 515151, 'shopify_product_id' => 51, 'title' => 'Old link',
            'product_id' => $this->raw->id, 'units' => 1]);

        $item = new OnlineOrderItem(['shopify_variant_id' => 515151, 'sku' => '']);
        $this->assertNull(ShopifyOnline::matchItem($item)['product_id']);
    }
}
