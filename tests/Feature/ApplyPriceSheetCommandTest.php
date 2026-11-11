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

    public function test_apply_takes_new_from_the_sheet_converts_incl_items_and_splits_cashvan_vat(): void
    {
        [$new, $net, $cash, $p, $c] = $this->scene();
        $p->update(['barcode' => '6220000000001']);
        $plan = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($plan, json_encode([
            // سعر المستهلك في الشيت 100 → ÷ 1.14 بـ٤ أرقام
            'items' => [['list' => 'new', 'barcode' => '6220000000001', 'price' => round(100 / 1.14, 4), 'why' => 'rounding (sheet)']],
            'vat_split_lists' => ['CashVan'],
        ]));

        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $plan, '--no-docs' => true])->assertSuccessful();

        $price = fn ($l) => (float) PriceListItem::where(['price_list_id' => $l->id, 'product_id' => $p->id])->value('price');
        $this->assertEqualsWithDelta(87.7193, $price($new), 0.00001, 'new = سعر الشيت ÷ 1.14 بـ٤ أرقام');
        $this->assertEqualsWithDelta(87.7193, (float) $p->fresh()->price_new, 0.00001, 'عمود الصنف لازم يتزامن مع القايمة');
        $this->assertEqualsWithDelta(87.7193, $price($net), 0.00001, 'الشامل المتسجّل غلط يتحوّل لغير شامل');
        $this->assertEqualsWithDelta(87.7193, $price($cash), 0.00001, 'الكاش فان: الشامل ÷ 1.14 (المبلغ للعميل ثابت)');
        $this->assertTrue((bool) $c->fresh()->taxable, 'الكل خاضع');
        $this->assertNull(Client::whereNull('price_list_id')->where('id', $c->id)->first(), 'مفيش عميل من غير قايمة');

        // ⚠️ (١١/١١) تشغيل تاني (خطة جديدة أو --apply اتعاد) مايقسمش الكاش فان تاني
        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $plan, '--no-docs' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(87.7193, $price($cash), 0.00001, 'الكاش فان اتقسم مرة واحدة بس');
        $this->assertStringContainsString('[vat-split:done]', (string) $cash->fresh()->notes);
    }

    public function test_vat_split_keeps_what_the_customer_paid_and_the_balance(): void
    {
        $c = $this->makeClient(['taxable' => false]);
        $p = $this->makeProduct(['price_new' => 80]);
        $inv = \App\Models\Invoice::create(['number' => 'INV-T1', 'client_id' => $c->id, 'user_id' => $this->makeAdmin()->id, 'payment' => 'credit', 'price_list' => 'CashVan',
            'subtotal' => 800, 'discount' => 0, 'total' => 800, 'tax_total' => 0, 'grand_total' => 800]);
        \App\Models\InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $p->id, 'qty' => 10, 'list_price' => 80, 'price' => 80,
            'unit_cost' => 0, 'total' => 800, 'tax_rate' => 0, 'tax' => 0]);
        \App\Models\Transaction::create(['client_id' => $c->id, 'date' => today(), 'memo' => 't', 'debit' => 800, 'credit' => 0, 'kind' => 'sale',
            'source_type' => \App\Models\Invoice::class, 'source_id' => $inv->id]);
        $c->recalculate();
        $before = (float) $c->fresh()->balance;

        $r = \App\Services\Reprice\VatSplit::invoice($inv);
        $c->recalculate();

        $inv->refresh();
        $it = $inv->items()->first();
        $this->assertNotNull($r);
        $this->assertEqualsWithDelta(800.00, (float) $inv->grand_total, 0.001, 'المبلغ اللي اتدفع ثابت');
        $this->assertEqualsWithDelta(701.75, (float) $inv->total, 0.001, '800 ÷ 1.14');
        $this->assertEqualsWithDelta(98.25, (float) $inv->tax_total, 0.001);
        $this->assertEqualsWithDelta(70.1754, (float) $it->price, 0.00005, '80 ÷ 1.14');
        $this->assertEqualsWithDelta(0.14, (float) $it->tax_rate, 0.0001);
        $this->assertEqualsWithDelta($before, (float) $c->fresh()->balance, 0.001, 'الرصيد ثابت');
        $this->assertNull(\App\Services\Reprice\VatSplit::invoice($inv->fresh()), 'مرة تانية = مفيش تغيير');
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

    public function test_activate_turns_a_stopped_net_list_on_before_clients_move_onto_it(): void
    {
        // شيت ١١/١١: أوسكار رجع على قايمة oscar الصافي — كانت موقوفة، و`listRowFor`
        // بيتجاهل الموقوفة فالعميل كان هيتحاسب بالافتراضية في صمت
        [$new, , , $p, $c] = $this->scene();
        $p->update(['barcode' => '6220000000009']);
        $oscar = PriceList::create(['code' => 'oscar', 'name' => 'اوسكار', 'active' => false, 'is_default' => false]);

        $plan = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($plan, json_encode([
            'items' => [['list' => 'oscar', 'barcode' => '6220000000009', 'price' => 80, 'why' => 'sheet']],
            'activate' => ['oscar'],
            'assign' => [['label' => 'Oscar', 'source' => 'sheet', 'list' => 'oscar', 'discount' => 0, 'clients' => [$c->code], 'groups' => []]],
        ]));

        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $plan, '--only' => 'items,activate,assign'])->assertSuccessful();

        $this->assertTrue((bool) $oscar->fresh()->active);
        $this->assertSame($oscar->id, \App\Services\Pricing::listRowFor($c->fresh())->id);
        $this->assertEqualsWithDelta(80.0, \App\Services\Pricing::quote($c->fresh(), $p)['unit_price'], 0.0001);

        // الرجوع بيرجّعها موقوفة
        $snap = collect(\Illuminate\Support\Facades\Storage::files('price-sheet'))->filter(fn ($f) => str_contains($f, 'snapshot-'))->sort()->last();
        $this->artisan('prices:apply-sheet', ['--rollback' => $snap, '--apply' => true])->assertSuccessful();
        $this->assertFalse((bool) $oscar->fresh()->active);
    }
}
