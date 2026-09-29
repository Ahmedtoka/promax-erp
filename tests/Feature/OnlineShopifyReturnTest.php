<?php

namespace Tests\Feature;

use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\Setting;
use App\Services\ShopifyOnline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * مرتجع الأونلاين في شوبيفاي (٢٩/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك (أوردر #1059): «Return line items return reason note — The
 * note is required when the return reason is Other». والمرتجع اللي اترفض
 * لازم يتبعت تاني من زرار «ادفع الحالة لشوبيفاي» بالفرق بس.
 */
class OnlineShopifyReturnTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> متغيرات كل returnCreate اتبعت */
    private array $created = [];

    private function fakeShopify(int $alreadyReturned): void
    {
        Setting::writeMany(['shopify_domain' => 'test-shop.myshopify.com', 'shopify_admin_token' => 'shpat_test']);

        Http::fake(function (Request $req) use ($alreadyReturned) {
            $q = (string) ($req->data()['query'] ?? '');

            if (str_contains($q, 'returns(first')) {
                return Http::response(['data' => ['order' => ['returns' => ['edges' => $alreadyReturned > 0 ? [[
                    'node' => ['status' => 'CLOSED', 'returnLineItems' => ['edges' => [[
                        'node' => ['quantity' => $alreadyReturned,
                            'fulfillmentLineItem' => ['lineItem' => ['id' => 'gid://shopify/LineItem/5001']]],
                    ]]]],
                ]] : []]]]]);
            }

            if (str_contains($q, 'returnableFulfillments')) {
                return Http::response(['data' => ['returnableFulfillments' => ['edges' => [[
                    'node' => ['returnableFulfillmentLineItems' => ['edges' => [[
                        'node' => ['quantity' => 3 - $alreadyReturned, 'fulfillmentLineItem' => [
                            'id' => 'gid://shopify/FulfillmentLineItem/9001',
                            'lineItem' => ['id' => 'gid://shopify/LineItem/5001']]],
                    ]]]],
                ]]]]]);
            }

            if (str_contains($q, 'returnCreate')) {
                $this->created[] = $req->data()['variables']['input'];

                return Http::response(['data' => ['returnCreate' => [
                    'return' => ['id' => 'gid://shopify/Return/1'], 'userErrors' => []]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function returnedOrder(int $returnedQty): OnlineOrder
    {
        $o = OnlineOrder::create([
            'shopify_id' => 777001, 'number' => '1059', 'customer_name' => 'عميل', 'items_count' => 3,
            'subtotal' => 300, 'shipping' => 0, 'total' => 300, 'status' => 'returned',
        ]);
        OnlineOrderItem::create([
            'online_order_id' => $o->id, 'shopify_line_id' => 5001, 'title' => 'Bar', 'qty' => 3,
            'returned_qty' => $returnedQty, 'price' => 100, 'total' => 300,
        ]);

        return $o;
    }

    public function test_the_return_carries_the_note_shopify_requires_for_reason_other(): void
    {
        $this->fakeShopify(0);

        $this->assertNull(ShopifyOnline::createReturn($this->returnedOrder(3), [5001 => 3]));

        $line = $this->created[0]['returnLineItems'][0];
        $this->assertSame('OTHER', $line['returnReason']);
        $this->assertNotEmpty($line['returnReasonNote']);
        $this->assertStringContainsString('1059', $line['returnReasonNote']);
    }

    public function test_the_retry_pushes_only_what_shopify_is_missing(): void
    {
        $this->fakeShopify(1);   // واحدة بس وصلت شوبيفاي قبل كده

        $this->assertNull(ShopifyOnline::syncReturn($this->returnedOrder(3)));

        $this->assertCount(1, $this->created);
        $this->assertSame(2, $this->created[0]['returnLineItems'][0]['quantity']);
    }

    public function test_the_retry_does_nothing_when_shopify_already_has_everything(): void
    {
        $this->fakeShopify(3);

        $this->assertNull(ShopifyOnline::syncReturn($this->returnedOrder(3)));
        $this->assertSame([], $this->created);
    }
}
