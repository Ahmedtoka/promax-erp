<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Rules\SellableProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * نوع الصنف (١٩/١٠) — منتج تام / خامة / تعبئة.
 *
 * الخامة والتعبئة أصناف حقيقية في المخازن (مخزن الخامات، التصنيع)،
 * بس **مابتتباعش**: `Product::sellable()` بيستبعدها، وكل اللي بينادي
 * السكوب أو `isSellable()` أو قاعدة `SellableProduct` بيورث الفلتر.
 */
class ProductKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_products_are_finished_by_default(): void
    {
        $this->assertSame('finished', $this->makeProduct()->fresh()->kind);
    }

    public function test_sellable_excludes_raw_packaging_and_drafts(): void
    {
        $fin = $this->makeProduct();
        $this->makeProduct(['kind' => 'raw']);
        $this->makeProduct(['kind' => 'packaging']);
        $this->makeProduct(['active' => false]);

        $this->assertSame([$fin->id], Product::sellable()->pluck('id')->all());
    }

    public function test_is_sellable_false_for_raw(): void
    {
        $this->assertFalse($this->makeProduct(['kind' => 'raw'])->isSellable());
        $this->assertFalse($this->makeProduct(['kind' => 'packaging'])->isSellable());
        $this->assertTrue($this->makeProduct()->isSellable());
    }

    public function test_sellable_rule_rejects_raw_with_the_not_for_sale_message(): void
    {
        $raw = $this->makeProduct(['kind' => 'raw', 'name_en' => 'Cocoa powder']);

        $v = Validator::make(['p' => $raw->id], ['p' => [new SellableProduct]]);

        $this->assertTrue($v->fails());
        $this->assertStringContainsString('Cocoa powder', $v->errors()->first('p'));
        $this->assertSame(__('api.product_not_for_sale', ['product' => $raw->displayName()]), $v->errors()->first('p'));
    }

    public function test_any_kind_rule_accepts_raw_but_still_rejects_drafts(): void
    {
        $raw = $this->makeProduct(['kind' => 'raw']);
        $draft = $this->makeProduct(['kind' => 'raw', 'active' => false]);

        $this->assertFalse(Validator::make(['p' => $raw->id], ['p' => [new SellableProduct(anyKind: true)]])->fails());
        $this->assertTrue(Validator::make(['p' => $draft->id], ['p' => [new SellableProduct(anyKind: true)]])->fails());
    }

    public function test_receipt_accepts_a_raw_material(): void
    {
        $raw = $this->makeProduct(['kind' => 'raw']);
        $wh = $this->makeWarehouse();

        $this->actingAs($this->makeAdmin())
            ->post(route('wh.receipts.store'), [
                'warehouse_id' => $wh->id,
                'received_on' => now()->toDateString(),
                'lines' => [[
                    'product_id' => $raw->id, 'batch_no' => 'RM-1', 'qty' => 5,
                    'produced_on' => now()->toDateString(),
                ]],
            ])
            ->assertSessionDoesntHaveErrors('lines.0.product_id');
    }

    public function test_product_form_saves_kind(): void
    {
        $admin = $this->makeAdmin();
        $base = [
            'code' => 'RM-SUGAR', 'family' => array_key_first(Product::FAMILIES),
            'name' => 'سكر', 'name_en' => 'Sugar', 'unit' => 'كيلو', 'price_new' => 1, 'cost' => 1,
        ];

        $this->actingAs($admin)->post(route('erp.products.store'), $base + ['kind' => 'xyz'])
            ->assertSessionHasErrors('kind');

        $this->actingAs($admin)->post(route('erp.products.store'), $base + ['kind' => 'raw'])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('raw', Product::where('code', 'RM-SUGAR')->value('kind'));
    }
}
