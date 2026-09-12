<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Custody;
use App\Models\GiftHandout;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\ProductMovements;
use App\Services\Returns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * حركة الأصناف بالكمية — كارت العميل وصفحة السلسلة (٨ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * كشف الحساب فلوس. ده بضاعة: لكل صنف كام قطعة اتسحبت (فواتير + أوامر
 * توريد مسلَّمة) وبكام وإمتى، وكام رجع (سليم/تالف)، وكام اتهدى — مجمّع
 * بالعائلة. التيست بيبني عميل بحركة من كل نوع ويقفل الأرقام بالقطعة.
 */
class ProductMovementsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{client: Client, bar: Product, spread: Product, rep: User, group: ClientGroup} */
    private function clientWithMovements(array $clientAttrs = []): array
    {
        $channel = $this->makeChannel();
        $group = ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة التيست', 'name_en' => 'Test chain',
            'channel_id' => $channel->id, 'active' => true,
        ]);
        $rep = $this->makeRep();
        $client = $this->makeClient(array_merge([
            'group_id' => $group->id, 'channel_id' => $channel->id, 'rep_id' => $rep->id,
            'return_policies' => [Client::RETURN_ACCOUNT],
        ], $clientAttrs));

        $bar = $this->makeProduct(['code' => 'BAR-1', 'name' => 'بروتين بار', 'name_en' => 'Protein bar', 'family' => 'promax_bar']);
        $spread = $this->makeProduct(['code' => 'SPR-1', 'name' => 'زبدة فول سوداني', 'name_en' => 'Peanut butter', 'family' => 'spreads']);

        // فاتورة: 10 بار بـ20 + 2 سبريد بـ100
        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(10000, 99999), 'client_id' => $client->id, 'user_id' => $rep->id,
            'payment' => 'credit', 'subtotal' => 400, 'discount' => 0, 'total' => 400, 'tax_total' => 0, 'grand_total' => 400,
        ]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $bar->id, 'qty' => 10, 'list_price' => 20, 'price' => 20, 'unit_cost' => 10, 'total' => 200, 'tax_rate' => 0, 'tax' => 0]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $spread->id, 'qty' => 2, 'list_price' => 100, 'price' => 100, 'unit_cost' => 60, 'total' => 200, 'tax_rate' => 0, 'tax' => 0]);

        // أمر توريد مسلَّم: 5 بار بـ18 (طلب 6 واتسلّم 5)
        $po = PurchaseOrder::create([
            'number' => 'PO-'.random_int(10000, 99999), 'client_id' => $client->id, 'status' => 'delivered',
            'delivered_at' => now(), 'total' => 108, 'tax_total' => 0, 'grand_total' => 108,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'product_id' => $bar->id, 'qty' => 6, 'delivered_qty' => 5,
            'price' => 18, 'list_price' => 20, 'discount_pct' => 0.1, 'total' => 108, 'tax_rate' => 0, 'tax' => 0,
        ]);

        // أمر توريد **مش** مسلَّم — مايتحسبش
        $pending = PurchaseOrder::create([
            'number' => 'PO-'.random_int(10000, 99999), 'client_id' => $client->id, 'status' => 'pending',
            'total' => 180, 'tax_total' => 0, 'grand_total' => 180,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $pending->id, 'product_id' => $bar->id, 'qty' => 10, 'delivered_qty' => 0,
            'price' => 18, 'list_price' => 20, 'discount_pct' => 0.1, 'total' => 180, 'tax_rate' => 0, 'tax' => 0,
        ]);

        // عهدة النهارده — مرتجع المندوب بيتسجّل على عربيته (Returns::create بترفض من غيرها)
        $custody = Custody::create(['user_id' => $rep->id, 'date' => today(), 'status' => 'open']);

        // مرتجع 3 بار (من الفاتورة، سليم)
        Returns::create(
            client: $client->fresh(),
            items: [['product_id' => $bar->id, 'qty' => 3]],
            policy: Client::RETURN_ACCOUNT,
            rep: $rep,
        );

        // هدية 2 بار
        GiftHandout::create([
            'custody_id' => $custody->id, 'user_id' => $rep->id, 'product_id' => $bar->id,
            'client_id' => $client->id, 'qty' => 2, 'reason' => 'promo',
        ]);

        return ['client' => $client->fresh(), 'bar' => $bar, 'spread' => $spread, 'rep' => $rep, 'group' => $group];
    }

    // ═══════════════ 1. المحرك ═══════════════

    public function test_summary_counts_pieces_per_product_grouped_by_family(): void
    {
        ['client' => $client, 'bar' => $bar, 'spread' => $spread] = $this->clientWithMovements();

        $s = ProductMovements::summary([$client->id]);

        $this->assertCount(2, $s['families']);
        $byKey = collect($s['families'])->keyBy('key');

        $barFamily = $byKey['promax_bar'];
        $this->assertSame(15, $barFamily['sold_qty'], 'المسحوب = 10 فاتورة + 5 أمر توريد مسلَّم (المعلّق مايتحسبش)');
        $this->assertSame(3, $barFamily['returned_qty']);
        $this->assertSame(2, $barFamily['gift_qty']);
        $this->assertSame(12, $barFamily['net_qty']);

        $barRow = $barFamily['products'][0];
        $this->assertSame($bar->id, $barRow['product']->id);
        $this->assertSame(290.0, $barRow['sold_value'], '10×20 + 5×18');
        $this->assertSame(19.33, $barRow['avg_price']);
        $this->assertSame(3, $barRow['returned_good']);
        $this->assertSame(0, $barRow['returned_damaged']);
        $this->assertSame(2, $barRow['docs']);

        $spreadFamily = $byKey['spreads'];
        $this->assertSame(2, $spreadFamily['sold_qty']);
        $this->assertSame($spread->id, $spreadFamily['products'][0]['product']->id);

        $this->assertSame(17, $s['totals']['sold_qty']);
        $this->assertSame(14, $s['totals']['net_qty']);
        $this->assertSame(490.0, $s['totals']['sold_value']);
    }

    public function test_rows_carry_every_document_line_in_date_order(): void
    {
        ['client' => $client] = $this->clientWithMovements();

        $rows = ProductMovements::rows([$client->id]);

        // 2 بنود فاتورة + 1 بند أمر توريد + 1 مرتجع + 1 هدية
        $this->assertCount(5, $rows);
        $this->assertSame(['sale', 'po', 'return', 'gift'], $rows->pluck('kind')->unique()->values()->all());
        $this->assertSame(5, $rows->firstWhere('kind', 'po')->qty, 'كمية الأمر = المسلَّم مش المطلوب');
    }

    public function test_a_period_filter_narrows_the_movements(): void
    {
        ['client' => $client] = $this->clientWithMovements();

        $this->assertCount(0, ProductMovements::rows([$client->id], to: today()->subDay()));
        $this->assertCount(5, ProductMovements::rows([$client->id], from: today()));
    }

    // ═══════════════ 2. الصفحات ═══════════════

    public function test_the_client_card_shows_the_movements_card_and_export_links(): void
    {
        ['client' => $client, 'bar' => $bar] = $this->clientWithMovements();
        $admin = $this->makeAdmin();

        $res = $this->actingAs($admin)->get(route('erp.clients.show', $client));

        $res->assertOk()
            ->assertSee(__('client.movements_title'))
            ->assertSee($bar->displayName())
            ->assertSee(route('erp.clients.movements', $client), false);
    }

    public function test_the_chain_page_aggregates_its_branches(): void
    {
        ['client' => $a, 'group' => $group, 'bar' => $bar] = $this->clientWithMovements();
        // فرع تاني في نفس السلسلة بفاتورة 4 بار
        $b = $this->makeClient(['name' => 'فرع ب', 'group_id' => $group->id, 'channel_id' => $a->channel_id]);
        $inv = Invoice::create([
            'number' => 'INV-'.random_int(10000, 99999), 'client_id' => $b->id, 'user_id' => $this->makeRep()->id,
            'payment' => 'cash', 'subtotal' => 80, 'discount' => 0, 'total' => 80, 'tax_total' => 0, 'grand_total' => 80,
        ]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'product_id' => $bar->id, 'qty' => 4, 'list_price' => 20, 'price' => 20, 'unit_cost' => 10, 'total' => 80, 'tax_rate' => 0, 'tax' => 0]);

        $s = ProductMovements::summary([$a->id, $b->id]);
        $this->assertSame(19, collect($s['families'])->keyBy('key')['promax_bar']['sold_qty'], '15 + 4');

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('erp.groups.show', $group))
            ->assertOk()
            ->assertSee(__('client.movements_title_chain'))
            ->assertSee(route('erp.groups.movements', $group), false);
    }

    /** فلتر «من — إلى» على كارت المسحوبات نفسه (١٢/٩/٢٠٢٦ — طلب المالك) */
    public function test_the_chain_page_movements_follow_the_period_filter(): void
    {
        ['group' => $group] = $this->clientWithMovements();
        $admin = $this->makeAdmin();

        // كل الحركة النهارده: فترة بتنتهي امبارح = صفر
        $this->actingAs($admin)
            ->get(route('erp.groups.show', ['group' => $group, 'from' => today()->subDays(30)->toDateString(), 'to' => today()->subDay()->toDateString()]))
            ->assertOk()
            ->assertSee('data-range-filter', false)
            ->assertViewHas('movements', fn ($m) => (int) $m['totals']['sold_qty'] === 0);

        // فترة تشمل النهارده = 15 بار + 2 سبريد
        $this->actingAs($admin)
            ->get(route('erp.groups.show', ['group' => $group, 'from' => today()->toDateString()]))
            ->assertOk()
            ->assertViewHas('movements', fn ($m) => (int) $m['totals']['sold_qty'] === 17);
    }

    // ═══════════════ 3. التصدير ═══════════════

    public function test_the_detail_export_lists_every_movement_and_the_summary_export_every_product(): void
    {
        ['client' => $client, 'group' => $group] = $this->clientWithMovements();
        $admin = $this->makeAdmin();

        $detail = $this->csvRows($this->actingAs($admin)->get(route('erp.clients.movements', $client)));
        // هيدر + 5 حركات + إجمالي
        $this->assertCount(7, $detail);
        $col = array_flip($detail[0]);
        $kinds = array_column(array_slice($detail, 1, 5), $col[__('client.movement_kind')]);
        $this->assertContains(__('client.mv_po'), $kinds);
        $this->assertContains(__('client.mv_gift'), $kinds);

        $summary = $this->csvRows($this->actingAs($admin)->get(route('erp.groups.movements', [$group, 'view' => 'summary'])));
        // هيدر + صنفين + إجمالي
        $this->assertCount(4, $summary);
        $scol = array_flip($summary[0]);
        $barRow = collect($summary)->first(fn ($r) => ($r[$scol[__('common.code')]] ?? '') === 'BAR-1');
        $this->assertSame('15', $barRow[$scol[__('client.sold_qty')]]);
        $this->assertSame('3', $barRow[$scol[__('client.returned_good')]]);
        $this->assertSame('2', $barRow[$scol[__('client.gift_qty')]]);
        $this->assertSame('12', $barRow[$scol[__('client.net_qty')]]);
    }

    /** ⚠️ فلترة القايمة مش حماية — راوت التصدير نفسه بيرفض عميل بره السكوب */
    public function test_a_manager_cannot_export_a_client_outside_their_scope(): void
    {
        ['client' => $client] = $this->clientWithMovements();
        $mgrA = $this->makeAdmin(['role' => 'manager', 'email' => 'mgr.a@test.local']);
        $mgrB = $this->makeAdmin(['role' => 'manager', 'email' => 'mgr.b@test.local']);
        $client->update(['manager_id' => $mgrA->id]);

        $this->actingAs($mgrB)->get(route('erp.clients.movements', $client))->assertForbidden();
        $this->actingAs($mgrA)->get(route('erp.clients.movements', $client))->assertOk();
    }

    /** @return list<list<string>> */
    private function csvRows($response): array
    {
        $this->assertSame(200, $response->getStatusCode());
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3))), fn ($l) => $l !== ''));

        return array_map(fn ($l) => str_getcsv($l), $lines);
    }
}
