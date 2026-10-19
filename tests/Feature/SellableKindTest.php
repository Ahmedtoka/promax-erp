<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\MerchVisit;
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
}
