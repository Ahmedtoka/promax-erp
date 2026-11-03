<?php

namespace Tests\Feature\Reprice;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceChangeRun;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reprice\PriceChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بيانات السلسلة (٣/١١/٢٠٢٦): الخصم بمعاينة زي القايمة، وشامل/خاضع
 * والمستندات على طول، والفرع اللي يتضم بياخدهم.
 *
 * المشهد زي PriceChangeEngineTest: صنف سعره 10 في قايمة أ و15 في ب،
 * ضريبة 14%، والفاتورة 3 قطع.
 */
class ChainDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Product $product;

    private PriceList $listA;

    private PriceList $listB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableTax(14);
        $this->admin = $this->makeAdmin();
        $this->rep = $this->makeRep();
        $this->product = $this->makeProduct(['cost' => 5]);

        $this->listA = PriceList::create(['code' => 'la', 'name' => 'قايمة أ', 'name_en' => 'List A', 'active' => true, 'is_default' => false]);
        $this->listB = PriceList::create(['code' => 'lb', 'name' => 'قايمة ب', 'name_en' => 'List B', 'active' => true, 'is_default' => false]);

        PriceListItem::create(['price_list_id' => $this->listA->id, 'product_id' => $this->product->id, 'price' => 10]);
        PriceListItem::create(['price_list_id' => $this->listB->id, 'product_id' => $this->product->id, 'price' => 15]);
    }

    private function group(array $attrs = []): ClientGroup
    {
        return ClientGroup::create(array_merge([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة', 'name_en' => 'Chain',
            'channel_id' => $this->makeChannel()->id, 'active' => true,
        ], $attrs));
    }

    private function client(PriceList $list, array $attrs = []): Client
    {
        return $this->makeClient(array_merge(['price_list_id' => $list->id, 'taxable' => true, 'payment_terms' => 'credit'], $attrs));
    }

    private function invoice(Client $client, float $price): Invoice
    {
        $net = round($price * 3, 2);
        $tax = round($net * 0.14, 2);

        $inv = Invoice::create([
            'number' => 'INV-'.random_int(100000, 999999), 'client_id' => $client->id, 'user_id' => $this->rep->id,
            'payment' => 'credit', 'price_list' => 'new', 'subtotal' => $net, 'discount_pct' => 0, 'discount' => 0,
            'total' => $net, 'tax_total' => $tax, 'grand_total' => $net + $tax, 'eta_status' => 'ready', 'cost_total' => 15,
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id, 'product_id' => $this->product->id, 'qty' => 3, 'list_price' => $price,
            'price' => $price, 'unit_cost' => 5, 'total' => $net, 'tax_rate' => 0.14, 'tax' => $tax,
        ]);
        Transaction::create([
            'client_id' => $client->id, 'date' => today(), 'memo' => 'بيع', 'debit' => $net + $tax, 'credit' => 0,
            'tax' => $tax, 'kind' => 'sale', 'source_type' => Invoice::class, 'source_id' => $inv->id,
        ]);
        $client->recalculate();

        return $inv;
    }

    public function test_chain_discount_goes_through_preview_and_reprices_documents(): void
    {
        $g = $this->group();
        $ct = Contract::create(['group_id' => $g->id, 'number' => Contract::nextNumber(), 'chain' => 'سلسلة', 'discount' => 0.3, 'active' => true]);
        $a = $this->client($this->listA, ['group_id' => $g->id]);
        $ia = $this->invoice($a, 10);

        $run = PriceChange::draft($this->admin, 'chain', ['group_id' => $g->id, 'price_list_id' => null, 'discount' => 0.1]);

        $this->assertSame(0.1, $run->payload['discount']);
        $this->assertEqualsWithDelta(0.3, $run->item_changes[0]['old_discount'], 0.0001, 'قبل = الخصم الفعلي (عقد السلسلة)');
        $this->assertEqualsWithDelta(0.1, $run->item_changes[0]['new_discount'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $a->fresh()->discount, 0.0001, 'المسودة ماتغيّرش الخصم');
        $this->assertSame(1, (int) $run->fresh()->invoices_changed);

        PriceChange::apply($run, $this->admin);

        $this->assertEqualsWithDelta(0.1, (float) $a->fresh()->discount, 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) $ct->fresh()->discount, 0.0001, 'عقد السلسلة اتختم');
        $this->assertEqualsWithDelta(0.1, $a->fresh()->effectiveDiscount(), 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) $g->fresh()->default_discount, 0.0001, 'الخصم بقى من بيانات السلسلة');
        $this->assertLessThan(34.20, (float) $ia->fresh()->grand_total);
        $this->assertNotNull($ia->fresh()->price_change_run_id);
    }

    public function test_joining_branch_takes_chain_data_and_only_its_documents_reprice(): void
    {
        Storage::fake('public');
        $g = $this->group(['price_list_id' => $this->listB->id, 'taxable' => false, 'docs_path' => 'chain-docs/x.pdf', 'docs_type' => 'pdf']);
        $old = $this->client($this->listB, ['group_id' => $g->id]);
        $iOld = $this->invoice($old, 14);
        $joining = $this->client($this->listA);
        $iJoin = $this->invoice($joining, 10);

        $res = $this->actingAs($this->admin)->post(route('erp.groups.attach', $g), [
            'action' => 'attach', 'client_ids' => [$joining->id],
        ]);

        $run = PriceChangeRun::latest('id')->first();
        $res->assertRedirect(route('erp.prices.change', $run));
        $this->assertSame([$joining->id], $run->payload['client_ids']);

        $j = $joining->fresh();
        $this->assertSame($g->id, (int) $j->group_id);
        $this->assertFalse($j->taxable, 'شامل/خاضع اتطبق على طول');
        $this->assertSame('chain-docs/x.pdf', $j->docs_path);
        $this->assertSame($this->listA->id, (int) $j->price_list_id, 'القايمة مستنية التأكيد');

        PriceChange::apply($run, $this->admin);

        $this->assertSame($this->listB->id, (int) $joining->fresh()->price_list_id);
        $this->assertNotNull($iJoin->fresh()->price_change_run_id);
        $this->assertNull($iOld->fresh()->price_change_run_id, 'فواتير الفروع القديمة ماتتلمسش');
        $this->assertEqualsWithDelta(14 * 3 * 1.14, (float) $iOld->fresh()->grand_total, 0.01);
    }

    public function test_joining_branch_with_same_pricing_needs_no_preview(): void
    {
        $g = $this->group(['price_list_id' => $this->listA->id]);
        $c = $this->client($this->listA);

        $this->actingAs($this->admin)->post(route('erp.groups.attach', $g), ['action' => 'attach', 'client_ids' => [$c->id]])
            ->assertRedirect();

        $this->assertSame(0, PriceChangeRun::count());
        $this->assertSame($g->id, (int) $c->fresh()->group_id);
    }

    public function test_chain_without_data_attaches_as_before(): void
    {
        $g = $this->group();
        $c = $this->client($this->listA, ['taxable' => true]);

        $this->actingAs($this->admin)->post(route('erp.groups.attach', $g), ['action' => 'attach', 'client_ids' => [$c->id]]);

        $this->assertSame(0, PriceChangeRun::count());
        $this->assertTrue($c->fresh()->taxable);
    }

    public function test_edit_dialog_discount_opens_preview_and_plain_fields_apply_now(): void
    {
        Storage::fake('public');
        $g = $this->group();
        $a = $this->client($this->listA, ['group_id' => $g->id, 'taxable' => true]);

        $res = $this->actingAs($this->admin)->put(route('erp.groups.update', $g), [
            'name' => 'سلسلة', 'active' => 1,
            'apply_discount' => 12.5,
            'apply_inclusive' => 'inclusive',
            'chain_docs' => UploadedFile::fake()->create('cr.pdf', 50, 'application/pdf'),
        ]);

        $run = PriceChangeRun::latest('id')->first();
        $res->assertRedirect(route('erp.prices.change', $run));
        $this->assertEqualsWithDelta(0.125, $run->payload['discount'], 0.0001);

        $fresh = $a->fresh();
        $this->assertEqualsWithDelta(0.0, (float) $fresh->discount, 0.0001, 'الخصم مستني التأكيد');
        $this->assertFalse($fresh->taxable);
        $this->assertTrue($fresh->has_docs);
        Storage::disk('public')->assertExists($g->fresh()->docs_path);
        $this->assertFalse($g->fresh()->taxable);
    }
}
