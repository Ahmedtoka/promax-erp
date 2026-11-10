<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `prices:apply-sheet` — dry-run مابيكتبش، و--apply بيصلّح التقريب والسعر
 * الشامل المتسجّل غلط وعلامة الضريبة، ومابيلمسش قايمة الكاش فان.
 */
class ApplyPriceSheetCommandTest extends TestCase
{
    use RefreshDatabase;

    private function scene(): array
    {
        $this->makeAdmin();
        $new = $this->makePriceList('new');
        $net = PriceList::create(['code' => 'Ontherun', 'name' => 'net', 'active' => true, 'is_default' => false]);
        $cash = PriceList::create(['code' => 'CashVan', 'name' => 'cash', 'active' => true, 'is_default' => false]);
        $p = $this->makeProduct(['price_new' => 87.71, 'price_old' => 87.71]);

        PriceListItem::updateOrCreate(['price_list_id' => $new->id, 'product_id' => $p->id], ['price' => 87.71]); // مقطوع
        PriceListItem::create(['price_list_id' => $net->id, 'product_id' => $p->id, 'price' => 100]);           // شامل غلط
        PriceListItem::create(['price_list_id' => $cash->id, 'product_id' => $p->id, 'price' => 100]);          // برا النطاق

        $c = $this->makeClient(['taxable' => false]);

        return [$new, $net, $cash, $p, $c];
    }

    public function test_dry_run_writes_nothing(): void
    {
        [$new, $net, , $p, $c] = $this->scene();

        $this->artisan('prices:apply-sheet')->assertSuccessful();

        $this->assertEqualsWithDelta(87.71, (float) PriceListItem::where(['price_list_id' => $new->id, 'product_id' => $p->id])->value('price'), 0.001);
        $this->assertEqualsWithDelta(100.0, (float) PriceListItem::where(['price_list_id' => $net->id, 'product_id' => $p->id])->value('price'), 0.001);
        $this->assertFalse((bool) $c->fresh()->taxable);
    }

    public function test_apply_fixes_rounding_incl_items_and_taxable_but_not_cashvan(): void
    {
        [$new, $net, $cash, $p, $c] = $this->scene();

        $this->artisan('prices:apply-sheet', ['--apply' => true])->assertSuccessful();

        $price = fn ($l) => (float) PriceListItem::where(['price_list_id' => $l->id, 'product_id' => $p->id])->value('price');
        $this->assertEqualsWithDelta(87.72, $price($new), 0.001, 'new: round(100 ÷ 1.14) = 87.72');
        $this->assertEqualsWithDelta(87.72, (float) $p->fresh()->price_new, 0.001, 'عمود الصنف لازم يتزامن مع القايمة');
        $this->assertEqualsWithDelta(87.72, $price($net), 0.001, 'الشامل المتسجّل غلط يتحوّل لغير شامل');
        $this->assertEqualsWithDelta(100.0, $price($cash), 0.001, 'الكاش فان مابيتلمسش');
        $this->assertTrue((bool) $c->fresh()->taxable, 'الكل خاضع');
        $this->assertNull(Client::whereNull('price_list_id')->where('id', $c->id)->first(), 'مفيش عميل من غير قايمة');
    }

    public function test_plan_assigns_list_and_discount_through_every_layer_and_retires_empty_lists(): void
    {
        [$new, $net, , , $c] = $this->scene();
        $old = $this->makePriceList('old');
        $g = \App\Models\ClientGroup::create(['code' => 'GT-1', 'name' => 'سلسلة تيست', 'price_list_id' => $net->id, 'default_discount' => 0]);
        $c->update(['group_id' => $g->id, 'price_list_id' => $net->id, 'discount' => 0.1]);
        // عقد شخصي سارٍ بخصم مختلف — لازم يتختم وإلا هيكسب على خصم العميل
        $ct = \App\Models\Contract::create(['client_id' => $c->id, 'chain' => 'x', 'discount' => 0.05, 'active' => true]);
        $dead = PriceList::create(['code' => 'deadlist', 'name' => 'dead', 'active' => true, 'is_default' => false]);

        $plan = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($plan, json_encode([
            'assign' => [['label' => 'تيست', 'source' => 'sheet', 'list' => 'old', 'discount' => 0.3, 'clients' => [$c->code], 'groups' => ['سلسلة تيست']]],
            'deactivate' => ['Ontherun'], 'delete' => ['deadlist'],
        ]));

        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $plan, '--only' => 'assign,retire'])->assertSuccessful();

        $c->refresh();
        $this->assertSame($old->id, $c->price_list_id);
        $this->assertEqualsWithDelta(0.3, $c->effectiveDiscount(), 0.0001, 'الخصم الفعلي (بعد العقد) = خصم الشيت');
        $this->assertSame($old->id, \App\Services\Pricing::listRowFor($c)->id);
        $this->assertEqualsWithDelta(0.3, (float) $ct->fresh()->discount, 0.0001);
        $this->assertSame($old->id, $g->fresh()->price_list_id);
        $this->assertFalse((bool) $net->fresh()->active, 'القايمة اللي فضيت تتوقف');
        $this->assertNull(PriceList::where('code', 'deadlist')->first(), 'القايمة اللي مالهاش أي مرجع تتمسح');
    }
}
