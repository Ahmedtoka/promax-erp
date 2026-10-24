<?php

namespace Tests\Feature\Reprice;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceChangeRun;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * تعديل الأسعار من الشاشات (Lane 13 — Task 4)
 * ═══════════════════════════════════════════════════════════════
 *
 * كل مصدر (بنود قايمة / قايمة سلسلة / قايمة فرع أو عميل) بقى بيعمل
 * **مسودة** ويودّي على صفحة المعاينة — ومفيش ولا سعر بيتكتب لحد
 * ما حد يدوس «تأكيد» (أدمن) أو «احفظ للبيع الجديد بس».
 *
 * المشهد: صنف P، قايمة A سعره 10، قايمة B سعره 15، ضريبة 14%.
 */
class PriceChangeFlowTest extends TestCase
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

    // ═══════════════ بنّائين ═══════════════

    private function client(PriceList $list, array $attrs = []): Client
    {
        return $this->makeClient(array_merge([
            'price_list_id' => $list->id,
            'taxable' => true,
            'payment_terms' => 'credit',
        ], $attrs));
    }

    private function group(): ClientGroup
    {
        return ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة التيست', 'name_en' => 'Test chain',
            'channel_id' => $this->makeChannel()->id, 'active' => true,
        ]);
    }

    private function manager(): User
    {
        return $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-'.strtoupper(uniqid())]);
    }

    /** فاتورة آجل 3 قطع بسعر معيّن + قيد البيع */
    private function invoice(Client $client, float $price): Invoice
    {
        $net = round($price * 3, 2);
        $tax = round($net * 0.14, 2);
        $grand = round($net + $tax, 2);

        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(100000, 999999),
            'client_id' => $client->id,
            'user_id' => $this->rep->id,
            'payment' => 'credit',
            'price_list' => 'new',
            'subtotal' => $net,
            'discount_pct' => 0,
            'discount' => 0,
            'total' => $net,
            'tax_total' => $tax,
            'grand_total' => $grand,
            'eta_status' => 'ready',
            'cost_total' => 15,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'qty' => 3,
            'list_price' => $price,
            'price' => $price,
            'unit_cost' => 5,
            'total' => $net,
            'tax_rate' => 0.14,
            'tax' => $tax,
        ]);

        Transaction::create([
            'client_id' => $client->id, 'date' => today(), 'memo' => 'بيع',
            'debit' => $grand, 'credit' => 0, 'tax' => $tax, 'kind' => 'sale',
            'source_type' => Invoice::class, 'source_id' => $invoice->id,
        ]);

        $client->recalculate();

        return $invoice;
    }

    private function priceOnA(): float
    {
        return (float) PriceListItem::where('price_list_id', $this->listA->id)
            ->where('product_id', $this->product->id)->value('price');
    }

    /** فورم العميل بأقل الحقول — نفس اللي الويزارد بيبعته */
    private function clientForm(Client $c, array $extra = []): array
    {
        return array_merge([
            'name' => $c->name,
            'name_en' => $c->name_en,
            'channel_id' => $c->channel_id ?? $this->makeChannel()->id,
            'phone' => '01011112222',
            'governorate' => 'giza',
            'zone_id' => $c->zone_id ?? $this->makeZone()->id,
            'address' => 'Test address',
            'payment_terms' => Client::PAY_CREDIT,
            'discount' => 0,
            'price_list_id' => $c->price_list_id,
            'taxable' => 1,
            'tax_rate' => 14,
            'has_contract' => 0,
        ], $extra);
    }

    private function lastRun(): PriceChangeRun
    {
        return PriceChangeRun::latest('id')->firstOrFail();
    }

    // ═══════════════ بنود القايمة ═══════════════

    public function test_save_prices_creates_draft_and_changes_nothing(): void
    {
        $client = $this->client($this->listA);
        $inv = $this->invoice($client, 10);

        $res = $this->actingAs($this->admin)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);

        $run = $this->lastRun();
        $res->assertRedirect(route('erp.prices.change', $run));

        $this->assertSame('draft', $run->status);
        $this->assertSame('list', $run->trigger);
        $this->assertSame(1, (int) $run->invoices_changed);
        $this->assertEqualsWithDelta(10.0, $this->priceOnA(), 0.001, 'السعر اتكتب قبل التأكيد');
        $this->assertEqualsWithDelta(34.20, (float) $inv->fresh()->grand_total, 0.001);

        // الصفحة بتفتح وفيها الأرقام
        $this->actingAs($this->admin)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertSee($run->number)
            ->assertSee($inv->number)
            ->assertSee('41.04');
    }

    public function test_save_prices_without_a_change_makes_no_draft(): void
    {
        $this->actingAs($this->admin)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 10]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, PriceChangeRun::count());
    }

    public function test_bulk_creates_a_draft(): void
    {
        $this->actingAs($this->admin)
            ->post(route('erp.prices.bulk', $this->listA), ['ids' => [$this->product->id], 'mode' => 'pct', 'value' => 50])
            ->assertRedirect(route('erp.prices.change', $this->lastRun()));

        $run = $this->lastRun();
        $this->assertEqualsWithDelta(15.0, (float) $run->payload['prices'][$this->product->id], 0.001);
        $this->assertEqualsWithDelta(10.0, $this->priceOnA(), 0.001);
    }

    public function test_admin_confirm_applies_prices_and_documents(): void
    {
        $client = $this->client($this->listA);
        $inv = $this->invoice($client, 10);

        $this->actingAs($this->admin)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);
        $run = $this->lastRun();

        $this->actingAs($this->admin)->post(route('erp.prices.change.apply', $run))
            ->assertRedirect(route('erp.prices.change', $run))
            ->assertSessionHasNoErrors();

        $this->assertSame('applied', $run->fresh()->status);
        $this->assertEqualsWithDelta(12.0, $this->priceOnA(), 0.001);
        $this->assertEqualsWithDelta(41.04, (float) $inv->fresh()->grand_total, 0.001);
        $this->assertSame((int) $run->id, (int) $inv->fresh()->price_change_run_id);
        $this->assertEqualsWithDelta(41.04, (float) $client->fresh()->balance, 0.001);

        $this->actingAs($this->admin)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertSee(__('reprice.status_applied'))
            ->assertSee($inv->number);
    }

    public function test_manager_can_save_master_only_but_not_apply(): void
    {
        $manager = $this->manager();
        $client = $this->client($this->listA, ['manager_id' => $manager->id]);
        $inv = $this->invoice($client, 10);

        $this->actingAs($manager)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]])
            ->assertRedirect();
        $run = $this->lastRun();
        $this->assertSame('draft', $run->status);

        $this->actingAs($manager)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertDontSee(route('erp.prices.change.apply', $run));

        $this->actingAs($manager)->post(route('erp.prices.change.apply', $run))->assertForbidden();
        $this->assertEqualsWithDelta(10.0, $this->priceOnA(), 0.001);

        $this->actingAs($manager)->post(route('erp.prices.change.master', $run))->assertRedirect();

        $this->assertSame('master_only', $run->fresh()->status);
        $this->assertEqualsWithDelta(12.0, $this->priceOnA(), 0.001);
        $this->assertEqualsWithDelta(34.20, (float) $inv->fresh()->grand_total, 0.001, 'الفاتورة اتلمست');
        $this->assertNull($inv->fresh()->price_change_run_id);
    }

    public function test_cancel_leaves_everything(): void
    {
        $this->actingAs($this->admin)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);
        $run = $this->lastRun();

        $this->actingAs($this->admin)->post(route('erp.prices.change.cancel', $run))->assertRedirect();

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertEqualsWithDelta(10.0, $this->priceOnA(), 0.001);

        // تأكيد بعد الإلغاء = رسالة مش 500
        $this->actingAs($this->admin)->from(route('erp.prices.change', $run))
            ->post(route('erp.prices.change.apply', $run))
            ->assertSessionHasErrors('reprice');
    }

    public function test_accountant_and_sales_cannot_create_or_apply(): void
    {
        $client = $this->client($this->listA);
        $acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.strtoupper(uniqid())]);

        $this->actingAs($acc)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]])
            ->assertForbidden();
        $this->actingAs($acc)
            ->post(route('erp.prices.change.resync'), ['trigger' => 'client', 'id' => $client->id])
            ->assertForbidden();

        $this->actingAs($this->rep)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]])
            ->assertForbidden();

        $this->assertSame(0, PriceChangeRun::count());
    }

    public function test_other_users_draft_is_403(): void
    {
        $a = $this->manager();
        $b = $this->manager();

        $this->actingAs($a)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);
        $run = $this->lastRun();

        $this->actingAs($b)->get(route('erp.prices.change', $run))->assertForbidden();
        $this->actingAs($b)->post(route('erp.prices.change.master', $run))->assertForbidden();
        $this->actingAs($b)->post(route('erp.prices.change.cancel', $run))->assertForbidden();
        $this->actingAs($b)->post(route('erp.prices.change.refresh', $run))->assertForbidden();

        // الأدمن بيشوف أي مسودة
        $this->actingAs($this->admin)->get(route('erp.prices.change', $run))->assertOk();
    }

    // ═══════════════ السلسلة ═══════════════

    public function test_chain_update_apply_price_list_goes_through_preview(): void
    {
        $g = $this->group();
        $b1 = $this->client($this->listA, ['group_id' => $g->id]);
        $b2 = $this->client($this->listA, ['group_id' => $g->id]);
        $inv = $this->invoice($b1, 10);

        $this->actingAs($this->admin)->put(route('erp.groups.update', $g), [
            'name' => 'اسم جديد للسلسلة', 'name_en' => 'Renamed chain',
            'channel_id' => $g->channel_id, 'active' => 1,
            'apply_price_list' => $this->listB->id,
        ])->assertRedirect(route('erp.prices.change', $this->lastRun()));

        $run = $this->lastRun();
        $this->assertSame('chain', $run->trigger);
        $this->assertSame('اسم جديد للسلسلة', $g->fresh()->name, 'باقي الحقول لازم تتحفظ');
        $this->assertSame((int) $this->listA->id, (int) $b1->fresh()->price_list_id);
        $this->assertSame((int) $this->listA->id, (int) $b2->fresh()->price_list_id);

        $this->actingAs($this->admin)->post(route('erp.prices.change.apply', $run))->assertSessionHasNoErrors();

        $this->assertSame((int) $this->listB->id, (int) $b1->fresh()->price_list_id);
        $this->assertSame((int) $this->listB->id, (int) $b2->fresh()->price_list_id);
        $this->assertEqualsWithDelta(51.30, (float) $inv->fresh()->grand_total, 0.001);
    }

    public function test_chain_page_shows_chain_list_and_resync_for_admin_only(): void
    {
        $g = $this->group();
        $this->client($this->listA, ['group_id' => $g->id]);

        $this->actingAs($this->admin)->get(route('erp.groups.show', $g))
            ->assertOk()
            ->assertSee($this->listA->displayName())
            ->assertSee(route('erp.prices.change.resync'));

        $this->actingAs($this->manager())->get(route('erp.groups.show', $g))
            ->assertOk()
            ->assertDontSee(route('erp.prices.change.resync'));
    }

    public function test_resync_chain_creates_draft_without_list_change(): void
    {
        $g = $this->group();
        $b1 = $this->client($this->listA, ['group_id' => $g->id]);
        $inv = $this->invoice($b1, 9);   // اتباعت بسعر قديم

        $this->actingAs($this->admin)
            ->post(route('erp.prices.change.resync'), ['trigger' => 'chain', 'id' => $g->id])
            ->assertRedirect(route('erp.prices.change', $this->lastRun()));

        $run = $this->lastRun();
        $this->assertNull($run->price_list_id);
        $this->assertSame(1, (int) $run->invoices_changed);
        $this->assertEqualsWithDelta(30.78, (float) $inv->fresh()->grand_total, 0.001);
    }

    // ═══════════════ العميل / الفرع ═══════════════

    public function test_client_update_holds_only_price_list(): void
    {
        $client = $this->client($this->listA, ['channel_id' => $this->makeChannel()->id, 'zone_id' => $this->makeZone()->id]);

        $this->actingAs($this->admin)
            ->put(route('erp.clients.update', $client), $this->clientForm($client, [
                'name' => 'اسم جديد',
                'name_en' => 'New name',
                'price_list_id' => $this->listB->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('erp.prices.change', $this->lastRun()));

        $fresh = $client->fresh();
        $this->assertSame('اسم جديد', $fresh->name);
        $this->assertSame((int) $this->listA->id, (int) $fresh->price_list_id, 'القايمة اتكتبت قبل المعاينة');

        $run = $this->lastRun();
        $this->assertSame('client', $run->trigger);
        $this->assertSame((int) $this->listB->id, (int) $run->price_list_id);

        $this->actingAs($this->admin)->post(route('erp.prices.change.master', $run))->assertRedirect();
        $this->assertSame((int) $this->listB->id, (int) $client->fresh()->price_list_id);
    }

    public function test_client_update_same_list_saves_as_before(): void
    {
        $client = $this->client($this->listA, ['channel_id' => $this->makeChannel()->id, 'zone_id' => $this->makeZone()->id]);

        $this->actingAs($this->admin)
            ->put(route('erp.clients.update', $client), $this->clientForm($client, ['name' => 'اسم تاني']))
            ->assertRedirect(route('erp.clients.show', $client));

        $this->assertSame(0, PriceChangeRun::count());
        $this->assertSame('اسم تاني', $client->fresh()->name);
    }

    public function test_branch_preview_asks_branch_or_chain(): void
    {
        $g = $this->group();
        $b1 = $this->client($this->listA, ['group_id' => $g->id, 'channel_id' => $this->makeChannel()->id, 'zone_id' => $this->makeZone()->id]);
        $b2 = $this->client($this->listA, ['group_id' => $g->id, 'name' => 'فرع تاني', 'name_en' => 'Second branch']);

        $this->actingAs($this->admin)
            ->put(route('erp.clients.update', $b1), $this->clientForm($b1, ['price_list_id' => $this->listB->id]))
            ->assertSessionHasNoErrors();

        $run = $this->lastRun();
        $this->assertSame('branch', $run->trigger);
        $this->assertSame('branch', $run->scope);
        $this->assertSame(1, (int) $run->clients_count);

        $this->actingAs($this->admin)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertSee(__('reprice.branch_differs', ['list' => $this->listA->displayName(), 'n' => 2]));

        $this->actingAs($this->admin)
            ->post(route('erp.prices.change.refresh', $run), ['scope' => 'chain'])
            ->assertRedirect(route('erp.prices.change', $run));

        $run->refresh();
        $this->assertSame('chain', $run->scope);
        $this->assertSame(2, (int) $run->clients_count);

        $this->actingAs($this->admin)->post(route('erp.prices.change.apply', $run))->assertSessionHasNoErrors();
        $this->assertSame((int) $this->listB->id, (int) $b2->fresh()->price_list_id);
    }

    public function test_client_page_resync_button_for_admin(): void
    {
        $client = $this->client($this->listA);

        $this->actingAs($this->admin)->get(route('erp.clients.show', $client))
            ->assertOk()
            ->assertSee(route('erp.prices.change.resync'));

        $this->actingAs($this->admin)
            ->post(route('erp.prices.change.resync'), ['trigger' => 'client', 'id' => $client->id])
            ->assertRedirect(route('erp.prices.change', $this->lastRun()));

        $this->assertSame('client', $this->lastRun()->trigger);
    }

    // ═══════════════ الفاتورة الواحدة ═══════════════

    public function test_single_invoice_reprice_logs_a_run(): void
    {
        $client = $this->client($this->listA);
        $inv = $this->invoice($client, 9);

        $this->actingAs($this->admin)->post(route('ops.invoices.reprice', $inv))
            ->assertSessionHasNoErrors();

        $run = $this->lastRun();
        $this->assertSame('invoice', $run->trigger);
        $this->assertSame('applied', $run->status);
        $this->assertSame(1, $run->docs()->where('outcome', 'changed')->count());
        $this->assertSame((int) $run->id, (int) $inv->fresh()->price_change_run_id);
    }

    public function test_single_invoice_reprice_without_change_logs_nothing(): void
    {
        $client = $this->client($this->listA);
        $inv = $this->invoice($client, 10);

        $this->actingAs($this->admin)->post(route('ops.invoices.reprice', $inv))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, PriceChangeRun::count());
    }

    // ═══════════════ الصفحة نفسها (مراجعة Task 4) ═══════════════

    /**
     * ⚠️ التأكيد كان ميت: `json_encode` بيطلّع `"…"` جوه `onsubmit="…"`
     * فالـattribute بيتقفل بدري والفورم بيتبعت من غير سؤال.
     */
    public function test_confirm_dialogs_use_single_quoted_strings(): void
    {
        $this->client($this->listA);
        $this->actingAs($this->admin)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);

        $html = $this->actingAs($this->admin)->get(route('erp.prices.change', $this->lastRun()))
            ->assertOk()
            ->assertSee("return confirm('", false)
            ->assertDontSee('confirm("', false)
            ->getContent();

        $this->assertSame(3, substr_count($html, "return confirm('"), 'تأكيد / للبيع الجديد / إلغاء');
    }

    public function test_master_only_page_does_not_show_documents_as_changed(): void
    {
        $inv = $this->invoice($this->client($this->listA), 10);

        $this->actingAs($this->admin)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);
        $run = $this->lastRun();
        $this->actingAs($this->admin)->post(route('erp.prices.change.master', $run));

        $this->actingAs($this->admin)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertSee(__('reprice.master_only_note', ['date' => $run->fresh()->applied_at->format('Y-m-d H:i')]))
            ->assertDontSee($inv->number)
            ->assertDontSee(__('reprice.k_invoices'))
            ->assertDontSee(__('reprice.docs_title'));
    }

    public function test_cancelled_page_says_nothing_changed(): void
    {
        $inv = $this->invoice($this->client($this->listA), 10);

        $this->actingAs($this->admin)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);
        $run = $this->lastRun();
        $this->actingAs($this->admin)->post(route('erp.prices.change.cancel', $run));

        $this->actingAs($this->admin)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertSee(__('reprice.cancelled_note'))
            ->assertDontSee($inv->number)
            ->assertDontSee(__('reprice.k_invoices'));
    }

    /**
     * القايمة بتتحسب على الشركة كلها — المدير يشوف الإجماليات وتغيير
     * الصنف بس، مش فواتير فريق تاني ولا أسماء عملاءه.
     */
    public function test_manager_list_preview_shows_totals_not_other_teams_documents(): void
    {
        $manager = $this->manager();
        $other = $this->manager();
        $mine = $this->invoice($this->client($this->listA, ['manager_id' => $manager->id]), 10);
        $theirs = $this->invoice($this->client($this->listA, [
            'manager_id' => $other->id, 'name' => 'عميل فريق تاني', 'name_en' => 'Other team client',
        ]), 10);

        $this->actingAs($manager)
            ->post(route('erp.prices.save', $this->listA), ['prices' => [$this->product->id => 12]]);
        $run = $this->lastRun();

        $this->actingAs($manager)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertSee(__('reprice.totals_title'))
            ->assertSee(__('reprice.aggregate_only'))
            ->assertSee($this->product->displayName())
            ->assertDontSee($theirs->number)
            ->assertDontSee('Other team client')
            ->assertDontSee('عميل فريق تاني')
            ->assertDontSee($mine->number);

        // الأدمن بيشوف التفاصيل عادي
        $this->actingAs($this->admin)->get(route('erp.prices.change', $run))
            ->assertOk()
            ->assertDontSee(__('reprice.aggregate_only'));
    }

    // ═══════════════ شاشة السجل (هيكل Task 5) ═══════════════

    public function test_log_routes_exist_for_admin_and_accountant(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.strtoupper(uniqid())]);

        $this->actingAs($this->admin)->get(route('erp.price_changes'))->assertOk();
        $this->actingAs($acc)->get(route('erp.price_changes'))->assertOk();
        $this->actingAs($this->manager())->get(route('erp.price_changes'))->assertForbidden();
    }
}
