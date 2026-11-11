<?php

namespace Tests\Feature;

use App\Models\ClientGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Services\PriceListOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شاشة قوايم الأسعار المطوّرة (١١/١١): الشغّالة بس افتراضياً، مين على كل
 * قايمة (عملاء + سلاسل)، عدد الأصناف، الأساس، وصفحة القايمة بالشامل.
 */
class PriceListOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function scene(): array
    {
        $new = $this->makePriceList('new');
        $net = PriceList::create(['code' => 'NetA', 'name' => 'قايمة صافي', 'active' => true, 'is_default' => false]);
        $off = PriceList::create(['code' => 'OldOff', 'name' => 'قايمة موقوفة', 'active' => false, 'is_default' => false]);
        $p = $this->makeProduct();
        PriceListItem::updateOrCreate(['price_list_id' => $new->id, 'product_id' => $p->id], ['price' => 100]);
        PriceListItem::create(['price_list_id' => $net->id, 'product_id' => $p->id, 'price' => 82.8947]);

        $g = ClientGroup::create(['code' => 'CH-T', 'name' => 'سلسلة تجربة', 'price_list_id' => $net->id]);
        $a = $this->makeClient(['name' => 'فرع ألف', 'name_en' => 'Branch Alef', 'price_list_id' => $net->id, 'group_id' => $g->id, 'discount' => 0.1, 'status' => 'active']);
        $b = $this->makeClient(['name' => 'فرع باء', 'name_en' => 'Branch Beh', 'price_list_id' => $net->id, 'discount' => 0.1, 'status' => 'active']);
        $this->makeClient(['name' => 'عميل مستني', 'name_en' => 'Pending One', 'price_list_id' => $net->id, 'status' => 'pending']);

        return compact('new', 'net', 'off', 'p', 'g', 'a', 'b');
    }

    public function test_stats_count_clients_chains_items_discounts_and_basis(): void
    {
        $s = $this->scene();
        $st = PriceListOverview::forLists(collect([$s['new'], $s['net']]));

        $this->assertSame(2, $st[$s['net']->id]['clients_active']);
        $this->assertSame(1, $st[$s['net']->id]['clients_other']);
        $this->assertSame(1, $st[$s['net']->id]['chains']->count());
        $this->assertSame(1, $st[$s['net']->id]['priced']);
        $this->assertSame(2, $st[$s['net']->id]['discounts']['10']);
        $this->assertSame('net', $st[$s['net']->id]['basis']['kind']);
        $this->assertEqualsWithDelta(0.828947, $st[$s['net']->id]['basis']['ratio'], 0.00001);
        $this->assertFalse($st[$s['net']->id]['basis']['incl_suspect']);
        $this->assertSame('consumer_new', $st[$s['new']->id]['basis']['kind']);
    }

    public function test_list_sharing_most_prices_with_consumer_is_still_net(): void
    {
        // زي سيركل ك: أغلب الأصناف بنفس سعر المستهلك وكام صنف مختلف —
        // وسيط الكل كان ١٫٠٠ والشاشة كانت بتقول «زي المستهلك الجديد»
        $s = $this->scene();
        foreach (range(1, 4) as $i) {
            $p = $this->makeProduct();
            PriceListItem::updateOrCreate(['price_list_id' => $s['new']->id, 'product_id' => $p->id], ['price' => 50]);
            PriceListItem::create(['price_list_id' => $s['net']->id, 'product_id' => $p->id, 'price' => 50]);
        }

        $b = PriceListOverview::forLists(collect([$s['net']]))[$s['net']->id]['basis'];

        $this->assertSame('net', $b['kind']);
        $this->assertSame(1, $b['differ']);
        $this->assertEqualsWithDelta(0.828947, $b['ratio'], 0.00001);
    }

    public function test_incl_stored_list_is_flagged(): void
    {
        $s = $this->scene();
        PriceListItem::where('price_list_id', $s['net']->id)->update(['price' => 114]);

        $st = PriceListOverview::forLists(collect([$s['net']]));

        $this->assertTrue($st[$s['net']->id]['basis']['incl_suspect']);
    }

    public function test_index_shows_active_only_by_default_and_who_is_on_each_list(): void
    {
        $s = $this->scene();
        $this->actingAs($this->makeAdmin());

        $this->get(route('erp.prices'))->assertOk()
            ->assertSee('قايمة صافي')->assertSee('سلسلة تجربة')->assertSee('Branch Alef')
            // الموقوفة بتبان في قايمة «انسخ من» بس، مش في الجدول
            ->assertDontSee('<a href="'.route('erp.prices.show', $s['off']).'"><b>', false)
            ->assertSee(__('price.ov_showing', ['n' => PriceList::where('active', true)->count(), 'all' => PriceList::count()]));

        $this->get(route('erp.prices', ['status' => 'all']))->assertOk()
            ->assertSee('<a href="'.route('erp.prices.show', $s['off']).'"><b>', false);
        $this->get(route('erp.prices', ['status' => 'inactive']))->assertOk()
            ->assertSee('<a href="'.route('erp.prices.show', $s['off']).'"><b>', false)
            ->assertDontSee('<a href="'.route('erp.prices.show', $s['net']).'"><b>', false);
    }

    public function test_search_finds_a_list_by_client_name(): void
    {
        $this->scene();
        $this->actingAs($this->makeAdmin());

        $this->get(route('erp.prices', ['q' => 'فرع باء']))->assertOk()
            ->assertSee('قايمة صافي')->assertSee(__('price.ov_showing', ['n' => 1, 'all' => PriceList::count()]));
    }

    public function test_show_lists_clients_chains_and_incl_price_with_four_decimals(): void
    {
        $s = $this->scene();
        $this->actingAs($this->makeAdmin());

        $this->get(route('erp.prices.show', $s['net']))->assertOk()
            ->assertSee(__('price.who_title'))
            ->assertSee('سلسلة تجربة')->assertSee('Branch Alef')->assertSee('Pending One')
            // الخانة بـ٤ أرقام — مش 82.89 اللي كانت هتعمل مسودة تقريب
            ->assertSee('value="82.8947"', false)
            // الشامل = round(82.8947 × 1.14, 2) = 94.50
            ->assertSee('94.50');
    }
}
