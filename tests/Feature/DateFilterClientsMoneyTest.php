<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\ContractDue;
use App\Models\RepSettlement;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلتر «من — إلى» على شاشات العملاء والفلوس (٩ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * سبع شاشات كانت بتعرض صفوف بتاريخ من غير أي فترة. لكل واحدة: صف
 * جوه الفترة وصف بره، والشاشة تعرض الأول وتخبّي التاني — على **عمود
 * التاريخ التجاري** (تاريخ القيد/الأمر/الصلاحية) مش `created_at`.
 * وباراميتر عبيط (`from=garbage`) مايرميش 500 — `DateRange` بيتجاهله.
 */
class DateFilterClientsMoneyTest extends TestCase
{
    use RefreshDatabase;

    private const RANGE = ['from' => '2026-06-01', 'to' => '2026-06-30'];

    // ═══════════════ 1. كارت العميل — كشف الحساب على `transactions.date` ═══════════════

    public function test_client_ledger_filters_on_transaction_date(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();

        Transaction::create(['client_id' => $client->id, 'date' => '2026-06-15', 'memo' => 'MEMO-INSIDE-JUNE', 'debit' => 100, 'credit' => 0, 'kind' => 'sale']);
        Transaction::create(['client_id' => $client->id, 'date' => '2026-08-15', 'memo' => 'MEMO-OUTSIDE-AUG', 'debit' => 200, 'credit' => 0, 'kind' => 'sale']);
        $client->recalculate();

        $this->actingAs($admin)->get(route('erp.clients.show', $client))
            ->assertOk()->assertSee('MEMO-INSIDE-JUNE')->assertSee('MEMO-OUTSIDE-AUG');

        $res = $this->actingAs($admin)->get(route('erp.clients.show', ['client' => $client] + self::RANGE))
            ->assertOk()->assertSee('MEMO-INSIDE-JUNE')->assertDontSee('MEMO-OUTSIDE-AUG');

        // لينكات تصدير الحركة بتمشي بنفس الفترة
        $res->assertSee('from=2026-06-01', false)->assertSee('to=2026-06-30', false);

        $this->actingAs($admin)->get(route('erp.clients.show', ['client' => $client, 'from' => 'garbage']))->assertOk();
    }

    // ═══════════════ 2. كارت الصنف — الباتشات على `expires_on` ═══════════════

    public function test_product_batches_filter_on_expiry_date(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct();
        $wh = $this->makeWarehouse();

        Batch::create(['product_id' => $product->id, 'warehouse_id' => $wh->id, 'batch_no' => 'BT-INSIDE-JUNE', 'expires_on' => '2026-06-20', 'qty_received' => 10, 'qty_remaining' => 10]);
        Batch::create(['product_id' => $product->id, 'warehouse_id' => $wh->id, 'batch_no' => 'BT-OUTSIDE-DEC', 'expires_on' => '2026-12-20', 'qty_received' => 10, 'qty_remaining' => 10]);

        $this->actingAs($admin)->get(route('erp.products.show', $product))
            ->assertOk()->assertSee('BT-INSIDE-JUNE')->assertSee('BT-OUTSIDE-DEC');

        $this->actingAs($admin)->get(route('erp.products.show', ['product' => $product] + self::RANGE))
            ->assertOk()->assertSee('BT-INSIDE-JUNE')->assertDontSee('BT-OUTSIDE-DEC');

        $this->actingAs($admin)->get(route('erp.products.show', ['product' => $product, 'from' => 'garbage']))->assertOk();
    }

    // ═══════════════ 3. صفحة السلسلة — الفترة بتسوق لينكات التصدير ═══════════════

    public function test_group_page_passes_range_to_export_links(): void
    {
        $admin = $this->makeAdmin();
        $channel = $this->makeChannel();
        $group = ClientGroup::create([
            'code' => 'G-'.strtoupper(uniqid()), 'name' => 'سلسلة التيست', 'name_en' => 'Test chain',
            'channel_id' => $channel->id, 'active' => true,
        ]);
        $branch = $this->makeClient(['group_id' => $group->id, 'channel_id' => $channel->id]);

        $res = $this->actingAs($admin)->get(route('erp.groups.show', ['group' => $group] + self::RANGE))->assertOk();

        foreach ([
            route('erp.groups.statements', ['group' => $group] + self::RANGE),
            route('erp.groups.branch_statement', ['group' => $group, 'client' => $branch] + self::RANGE),
            route('erp.groups.movements', ['group' => $group] + self::RANGE),
        ] as $url) {
            // ⚠️ `assertSee` بتعمل `e()` على المتوقع — `&` في الـhref بتطلع `&amp;`
            $res->assertSee($url);
        }

        // من غير فترة — اللينكات نظيفة زي ما كانت
        $this->actingAs($admin)->get(route('erp.groups.show', $group))
            ->assertOk()->assertDontSee('from=', false);

        $this->actingAs($admin)->get(route('erp.groups.show', ['group' => $group, 'from' => 'garbage']))->assertOk();
    }

    // ═══════════════ 4. كارت المورد — الدفتر على `supplier_transactions.date` ═══════════════

    public function test_supplier_ledger_filters_on_transaction_date(): void
    {
        $admin = $this->makeAdmin();
        $supplier = Supplier::create(['code' => 'SUP-'.strtoupper(uniqid()), 'name' => 'مورد التيست', 'active' => true]);

        SupplierTransaction::create(['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'kind' => 'invoice', 'debit' => 0, 'credit' => 500, 'memo' => 'SUPMEMO-INSIDE-JUNE']);
        SupplierTransaction::create(['supplier_id' => $supplier->id, 'date' => '2026-08-10', 'kind' => 'payment', 'debit' => 500, 'credit' => 0, 'memo' => 'SUPMEMO-OUTSIDE-AUG']);

        $this->actingAs($admin)->get(route('erp.suppliers.show', $supplier))
            ->assertOk()->assertSee('SUPMEMO-INSIDE-JUNE')->assertSee('SUPMEMO-OUTSIDE-AUG');

        $this->actingAs($admin)->get(route('erp.suppliers.show', ['supplier' => $supplier] + self::RANGE))
            ->assertOk()->assertSee('SUPMEMO-INSIDE-JUNE')->assertDontSee('SUPMEMO-OUTSIDE-AUG');

        $this->actingAs($admin)->get(route('erp.suppliers.show', ['supplier' => $supplier, 'from' => 'garbage']))->assertOk();
    }

    // ═══════════════ 5. أوامر الشراء — على `ordered_on` ═══════════════

    public function test_purchase_orders_filter_on_ordered_on(): void
    {
        $admin = $this->makeAdmin();
        $supplier = Supplier::create(['code' => 'SUP-'.strtoupper(uniqid()), 'name' => 'مورد التيست', 'active' => true]);
        $wh = $this->makeWarehouse();

        foreach ([['SPO-INSIDE-JUNE', '2026-06-12'], ['SPO-OUTSIDE-AUG', '2026-08-12']] as [$number, $on]) {
            SupplierOrder::create([
                'number' => $number, 'supplier_id' => $supplier->id, 'warehouse_id' => $wh->id,
                'status' => 'open', 'ordered_on' => $on, 'total' => 100, 'created_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->get(route('erp.purchasing'))
            ->assertOk()->assertSee('SPO-INSIDE-JUNE')->assertSee('SPO-OUTSIDE-AUG');

        // مع فلتر الحالة الموجود — الاتنين شغالين سوا
        $this->actingAs($admin)->get(route('erp.purchasing', ['status' => 'open'] + self::RANGE))
            ->assertOk()->assertSee('SPO-INSIDE-JUNE')->assertDontSee('SPO-OUTSIDE-AUG');

        $this->actingAs($admin)->get(route('erp.purchasing', ['from' => 'garbage']))->assertOk();
    }

    // ═══════════════ 6. المستحقات — على `period_end` ═══════════════

    public function test_dues_filter_on_period_end(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();

        foreach ([['CT-INSIDE-JUNE', '2026-04-01', '2026-06-30'], ['CT-OUTSIDE-SEP', '2026-07-01', '2026-09-30']] as [$number, $start, $end]) {
            $contract = Contract::create(['client_id' => $client->id, 'number' => $number, 'type' => 'contract', 'discount' => 0, 'active' => true]);
            ContractDue::create([
                'contract_id' => $contract->id, 'client_id' => $client->id, 'kind' => 'rebate', 'basis' => 'quarterly',
                'period_start' => $start, 'period_end' => $end, 'basis_amount' => 1000, 'pct' => 0.05, 'amount' => 50,
                'status' => ContractDue::STATUS_DUE,
            ]);
        }

        $this->actingAs($admin)->get(route('erp.dues'))
            ->assertOk()->assertSee('CT-INSIDE-JUNE')->assertSee('CT-OUTSIDE-SEP');

        $this->actingAs($admin)->get(route('erp.dues', ['status' => 'due'] + self::RANGE))
            ->assertOk()->assertSee('CT-INSIDE-JUNE')->assertDontSee('CT-OUTSIDE-SEP');

        $this->actingAs($admin)->get(route('erp.dues', ['from' => 'garbage']))->assertOk();
    }

    // ═══════════════ 7. تصفية المناديب — السجل على `to_at` ═══════════════

    public function test_settlement_history_filters_on_closing_time(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();

        foreach ([['RS-INSIDE-JUNE', '2026-06-18 18:00:00'], ['RS-OUTSIDE-AUG', '2026-08-18 18:00:00']] as [$number, $at]) {
            RepSettlement::create([
                'number' => $number, 'user_id' => $rep->id, 'from_at' => null, 'to_at' => $at,
                'invoices_count' => 0, 'expected' => 0, 'received' => 0, 'balance' => 0, 'created_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->get(route('erp.repclose'))
            ->assertOk()->assertSee('RS-INSIDE-JUNE')->assertSee('RS-OUTSIDE-AUG');

        $this->actingAs($admin)->get(route('erp.repclose', self::RANGE))
            ->assertOk()->assertSee('RS-INSIDE-JUNE')->assertDontSee('RS-OUTSIDE-AUG');

        $this->actingAs($admin)->get(route('erp.repclose', ['from' => 'garbage']))->assertOk();
    }
}
