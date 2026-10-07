<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\ShopifyProductLink;
use App\Models\User;
use App\Services\ShopifyOnline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ═══ منتجات اتشالت من شوبيفاي بترجع مع كل جلب (طلب المالك ٧/١٠/٢٠٢٦) ═══
 *
 * الجلب بقى للأكتيف بس، واللي مارجعش بيتعلّم «مش أكتيف» ويتمسح بزرار.
 */
class OnlineProductLinksPruneTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> كويريز كل نداء products.json */
    private array $asked = [];

    private function fakeShopifyWithOneActiveProduct(): void
    {
        Setting::writeMany(['shopify_domain' => 'test-shop.myshopify.com', 'shopify_admin_token' => 'shpat_test']);

        Http::fake(function (Request $req) {
            if (str_contains($req->url(), 'products.json')) {
                parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
                $this->asked[] = $q;

                return Http::response(['products' => [[
                    'id' => 100, 'title' => 'ProBundle 5', 'image' => null,
                    'variants' => [['id' => 1001, 'title' => 'Default Title', 'sku' => 'PROBUNDLE-5']],
                ]]]);
            }

            return Http::response([], 404);
        });
    }

    public function test_fetch_asks_for_active_products_and_flags_the_ones_that_did_not_come_back(): void
    {
        ShopifyProductLink::create(['shopify_variant_id' => 1001, 'shopify_product_id' => 100, 'title' => 'ProBundle 5', 'units' => 1]);
        ShopifyProductLink::create(['shopify_variant_id' => 2001, 'shopify_product_id' => 200, 'title' => 'Probundle 6', 'units' => 1]);
        // فاريانت اتعرف من أوردر (ربط بند يدوي) — مالوش «آخر ظهور» فمايتعلّمش
        ShopifyProductLink::create(['shopify_variant_id' => 3001, 'shopify_product_id' => 0, 'title' => 'Old order line', 'units' => 1]);

        $this->fakeShopifyWithOneActiveProduct();
        $result = ShopifyOnline::fetchProducts();

        $this->assertNull($result['error']);
        $this->assertSame('active', $this->asked[0]['status'] ?? null);
        $this->assertSame(1, $result['stale']);
        $this->assertSame(['Probundle 6'], ShopifyProductLink::stale()->pluck('title')->all());
        $this->assertFalse(ShopifyProductLink::where('shopify_variant_id', 1001)->first()->isStale());
    }

    public function test_the_team_deletes_one_row_or_all_inactive_and_the_keeper_cannot(): void
    {
        $admin = $this->makeAdmin();
        ShopifyProductLink::create(['shopify_variant_id' => 1001, 'shopify_product_id' => 100, 'title' => 'ProBundle 5', 'units' => 1]);
        $gone1 = ShopifyProductLink::create(['shopify_variant_id' => 2001, 'shopify_product_id' => 200, 'title' => 'Probundle 6 A', 'units' => 1]);
        ShopifyProductLink::create(['shopify_variant_id' => 2002, 'shopify_product_id' => 200, 'title' => 'Probundle 6 B', 'units' => 1]);
        $manual = ShopifyProductLink::create(['shopify_variant_id' => 3001, 'shopify_product_id' => 0, 'title' => 'Old order line', 'units' => 1]);

        $this->fakeShopifyWithOneActiveProduct();
        ShopifyOnline::fetchProducts();

        // الشاشة: الشارة + الفلتر + زرار مسح الكل
        $this->actingAs($admin)->get(route('online.products'))->assertOk()
            ->assertSee(__('online.stale_badge'))->assertSee(route('online.products.prune'), false)
            ->assertSee(route('online.products.delete', $manual), false);
        $this->actingAs($admin)->get(route('online.products', ['stale' => 1]))->assertOk()
            ->assertSee('Probundle 6 A')->assertDontSee('ProBundle 5');

        $keeper = User::create(['name' => 'أمين', 'email' => 'k-pr@x.test', 'code' => 'WHK-PR', 'role' => 'warehouse_keeper',
            'password' => bcrypt('x'), 'active' => true]);
        $this->actingAs($keeper)->post(route('online.products.prune'))->assertForbidden();
        $this->actingAs($keeper)->post(route('online.products.delete', $gone1))->assertForbidden();

        // مسح صف واحد
        $this->actingAs($admin)->post(route('online.products.delete', $manual))->assertSessionHasNoErrors();
        $this->assertNull(ShopifyProductLink::find($manual->id));

        // مسح كل اللي مش أكتيف — الأكتيف بيفضل
        $this->actingAs($admin)->post(route('online.products.prune'))->assertSessionHasNoErrors();
        $this->assertSame(['ProBundle 5'], ShopifyProductLink::pluck('title')->all());
    }

    public function test_nothing_is_flagged_before_the_first_fetch(): void
    {
        ShopifyProductLink::create(['shopify_variant_id' => 2001, 'shopify_product_id' => 200, 'title' => 'Never fetched', 'units' => 1]);

        $this->assertSame(0, ShopifyProductLink::stale()->count());
    }
}
