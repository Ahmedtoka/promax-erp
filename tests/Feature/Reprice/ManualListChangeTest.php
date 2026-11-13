<?php

namespace Tests\Feature\Reprice;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Transaction;
use App\Services\ListHistory;
use App\Services\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * (قرار أحمد ٢٧ — ١٣/١١/٢٠٢٦) د. عمار نقل سلاسل لقايمة «مترو ماركت» يدوياً:
 * «تسعير جديد من وقت التغيير». اللي اتسلّم قبله بيفضل بسعره القديم:
 *   • `prices:audit` بيعتبره معلومة لو = سعر القايمة السابقة بالظبط، وخطأ لو اتسلّم بعد التغيير
 *   • `prices:apply-sheet` مابيرجّعش السلسلة لقايمة الخطة، ومابيعيدش تسعير الأمر القديم
 */
class ManualListChangeTest extends TestCase
{
    use RefreshDatabase;

    private PriceList $old;

    private PriceList $metro;

    private ClientGroup $chain;

    private Client $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTax(14);
        $this->makeAdmin();
        $this->old = $this->makePriceList('old');
        $this->metro = PriceList::create(['code' => 'metro', 'name' => 'مترو', 'active' => true, 'is_default' => false]);
        $this->product = $this->makeProduct(['cost' => 5]);
        PriceListItem::updateOrCreate(['price_list_id' => $this->old->id, 'product_id' => $this->product->id], ['price' => 10]);
        PriceListItem::create(['price_list_id' => $this->metro->id, 'product_id' => $this->product->id, 'price' => 9.6]);
        $this->chain = ClientGroup::create(['code' => 'MT-1', 'name' => 'مترو تيست', 'price_list_id' => $this->old->id, 'default_discount' => 0.3]);
        $this->client = $this->makeClient(['group_id' => $this->chain->id, 'price_list_id' => $this->old->id, 'price_list' => 'old',
            'discount' => 0.3, 'taxable' => true]);
        PriceList::flushCache();
    }

    /** نفس اللي شاشة السلاسل عملته: السلسلة وفروعها على metro + سطر نشاط يدوي */
    private function chainMovedByHand(string $at): void
    {
        $this->chain->forceFill(['price_list_id' => $this->metro->id])->saveQuietly();
        Client::where('group_id', $this->chain->id)->update(['price_list_id' => $this->metro->id, 'price_list' => 'metro']);
        DB::table('activity_logs')->insert(['user_name' => 'عمار', 'role' => 'admin', 'event' => 'updated', 'subject_type' => 'ClientGroup',
            'subject_id' => $this->chain->id, 'changes' => json_encode(['price_list_id' => [$this->old->id, $this->metro->id]]),
            'url' => 'erp/setup/chains', 'method' => 'POST', 'route' => 'erp.setup.chains.save', 'created_at' => $at, 'updated_at' => $at]);
        $this->client->refresh();
        PriceList::flushCache();
        ListHistory::flush();
    }

    /** أمر اتسلّم بسعر الوحدة ده (٤ أرقام) — بنوده وقيده متسقين */
    private function deliveredPo(string $number, float $unit, string $deliveredAt): PurchaseOrder
    {
        $calc = Tax::line($unit, 10, 0.14);
        $po = PurchaseOrder::create(['number' => $number, 'client_id' => $this->client->id, 'status' => 'delivered',
            'total' => $calc['net'], 'tax_total' => $calc['tax'], 'grand_total' => round($calc['net'] + $calc['tax'], 2)]);
        $po->forceFill(['delivered_at' => $deliveredAt, 'created_at' => $deliveredAt])->saveQuietly();
        PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->product->id, 'qty' => 10, 'delivered_qty' => 10,
            'list_price' => 10, 'discount_pct' => 0.3, 'price' => $unit, 'total' => $calc['net'], 'tax_rate' => 0.14, 'tax' => $calc['tax']]);
        Transaction::create(['client_id' => $this->client->id, 'date' => substr($deliveredAt, 0, 10), 'memo' => 'PO', 'debit' => round($calc['net'] + $calc['tax'], 2),
            'credit' => 0, 'tax' => $calc['tax'], 'kind' => 'sale', 'source_type' => PurchaseOrder::class, 'source_id' => $po->id]);

        return $po;
    }

    private function auditCsv(): string
    {
        $csv = tempnam(sys_get_temp_dir(), 'aud');
        $this->artisan('prices:audit', ['--csv' => $csv])->assertSuccessful();

        return (string) file_get_contents($csv);
    }

    /** صفوف الـCSV اللي فيها رقم المستند ده */
    private function rowsFor(string $body, string $number): array
    {
        return array_values(array_filter(explode("\n", $body), fn ($l) => str_contains($l, $number.' ·') && str_contains($l, 'line_price_from_list')));
    }

    public function test_list_history_finds_the_manual_chain_change_and_ignores_console_runs(): void
    {
        // تشغيل كونسول (route فاضي، GET) مابيتحسبش
        DB::table('activity_logs')->insert(['user_name' => 'system', 'event' => 'updated', 'subject_type' => 'ClientGroup', 'subject_id' => $this->chain->id,
            'changes' => json_encode(['price_list_id' => [$this->metro->id, $this->old->id]]), 'method' => 'GET', 'created_at' => '2026-11-11 15:52:40', 'updated_at' => '2026-11-11 15:52:40']);
        $this->assertNull(ListHistory::lastManualChange($this->client));

        $this->chainMovedByHand('2026-11-13 15:20:54');
        $ch = ListHistory::lastManualChange($this->client);

        $this->assertSame($this->old->id, $ch['from']);
        $this->assertSame($this->metro->id, $ch['to']);
        $this->assertSame('chain', $ch['on']);
        $this->assertSame('2026-11-13 15:20:54', $ch['at']->format('Y-m-d H:i:s'));
    }

    public function test_audit_accepts_a_delivered_po_priced_under_the_previous_list_before_the_change(): void
    {
        $before = $this->deliveredPo('PO-BEFORE', 7.0, '2026-11-10 12:00:00'); // ١٠ × ٠٫٧ على old
        $this->chainMovedByHand('2026-11-13 15:20:54');

        $rows = $this->rowsFor($this->auditCsv(), $before->number);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('القايمة السابقة', $rows[0]);
        $this->assertStringStartsWith('info,', $rows[0], 'معلومة مش خطأ');
    }

    public function test_audit_still_flags_a_po_delivered_after_the_change_at_the_old_price(): void
    {
        $this->chainMovedByHand('2026-11-13 15:20:54');
        $after = $this->deliveredPo('PO-AFTER', 7.0, '2026-11-13 18:00:00');

        $body = $this->auditCsv();
        $rows = $this->rowsFor($body, $after->number);

        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('error,', $rows[0]);
        $this->assertStringNotContainsString('القايمة السابقة', $rows[0]);
        $this->assertStringContainsString('6.72', $rows[0], 'المتوقّع سعر metro');
    }

    public function test_audit_flags_a_po_before_the_change_that_matches_neither_list(): void
    {
        $odd = $this->deliveredPo('PO-ODD', 6.5, '2026-11-10 12:00:00');
        $this->chainMovedByHand('2026-11-13 15:20:54');

        $rows = $this->rowsFor($this->auditCsv(), $odd->number);

        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('error,', $rows[0]);
        $this->assertStringNotContainsString('القايمة السابقة', $rows[0]);
    }

    private function plan(string $generated, string $list): string
    {
        $plan = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($plan, json_encode(['generated' => $generated, 'assign' => [[
            'label' => 'metro-kheir', 'source' => 'sheet', 'list' => $list, 'discount' => 0.3,
            'clients' => [$this->client->code], 'groups' => [$this->chain->name],
        ]]]));

        return $plan;
    }

    public function test_apply_sheet_does_not_move_a_chain_back_after_a_manual_change_newer_than_the_plan(): void
    {
        $this->chainMovedByHand('2026-11-13 15:20:54');

        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $this->plan('2026-11-12', 'old'), '--only' => 'assign'])->assertSuccessful();

        $this->assertSame($this->metro->id, $this->chain->fresh()->price_list_id, 'السلسلة فضلت على metro');
        $this->assertSame($this->metro->id, $this->client->fresh()->price_list_id, 'الفرع فضل على metro');
    }

    public function test_override_manual_or_an_older_change_lets_the_plan_win(): void
    {
        $this->chainMovedByHand('2026-11-11 10:00:00'); // قبل الخطة ⇒ الخطة تكسب

        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $this->plan('2026-11-12', 'old'), '--only' => 'assign'])->assertSuccessful();
        $this->assertSame($this->old->id, $this->client->fresh()->price_list_id);

        $this->chainMovedByHand('2026-11-13 15:20:54');
        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $this->plan('2026-11-12', 'old'), '--only' => 'assign', '--override-manual' => true])->assertSuccessful();
        $this->assertSame($this->old->id, $this->chain->fresh()->price_list_id);
    }

    public function test_plan_on_metro_is_a_no_op_and_docs_step_keeps_the_po_delivered_before_the_change(): void
    {
        $po = $this->deliveredPo('PO-KEEP', 7.0, '2026-11-10 12:00:00');
        $this->chainMovedByHand('2026-11-13 15:20:54');
        $grand = (float) $po->grand_total;

        $this->artisan('prices:apply-sheet', ['--apply' => true, '--plan' => $this->plan('2026-11-13', 'metro'), '--only' => 'assign,docs'])->assertSuccessful();

        $this->assertSame($this->metro->id, $this->client->fresh()->price_list_id);
        $this->assertEqualsWithDelta(7.0, (float) $po->items()->first()->price, 0.00001, 'الأمر اللي اتسلّم قبل التغيير بسعره');
        $this->assertEqualsWithDelta($grand, (float) $po->fresh()->grand_total, 0.001);
    }
}
