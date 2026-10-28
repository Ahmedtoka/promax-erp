<?php

namespace Tests\Feature\Reprice;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PriceChangeDoc;
use App\Models\PriceChangeRun;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reprice\PriceChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سجل تعديلات الأسعار (Lane 13 — Task 5): القايمة والتصدير والفلتر
 * وصفحة التعديل وتفاصيل المستند وبادج الفاتورة.
 *
 * المشهد: صنف P، قايمة A سعره 10 وبيتغير 12، ضريبة 14%، فاتورة آجل 3 قطع.
 */
class PriceChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    private Product $product;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableTax(14);
        $this->admin = $this->makeAdmin();
        $this->rep = $this->makeRep();
        $this->product = $this->makeProduct(['cost' => 5]);
        $this->list = PriceList::create(['code' => 'la', 'name' => 'قايمة أ', 'name_en' => 'List A', 'active' => true, 'is_default' => false]);
        PriceListItem::create(['price_list_id' => $this->list->id, 'product_id' => $this->product->id, 'price' => 10]);
    }

    private function invoice(Client $client): Invoice
    {
        $invoice = Invoice::create([
            'number' => 'INV-'.random_int(100000, 999999),
            'client_id' => $client->id, 'user_id' => $this->rep->id, 'payment' => 'credit',
            'price_list' => 'new', 'subtotal' => 30, 'discount_pct' => 0, 'discount' => 0,
            'total' => 30, 'tax_total' => 4.2, 'grand_total' => 34.2, 'eta_status' => 'ready', 'cost_total' => 15,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'product_id' => $this->product->id, 'qty' => 3,
            'list_price' => 10, 'price' => 10, 'unit_cost' => 5, 'total' => 30, 'tax_rate' => 0.14, 'tax' => 4.2,
        ]);
        Transaction::create([
            'client_id' => $client->id, 'date' => today(), 'memo' => 'بيع',
            'debit' => 34.2, 'credit' => 0, 'tax' => 4.2, 'kind' => 'sale',
            'source_type' => Invoice::class, 'source_id' => $invoice->id,
        ]);
        $client->recalculate();

        return $invoice;
    }

    /** تعديل مطبّق فعلاً: يرجّع [run, invoice, client] */
    private function appliedRun(float $price = 12): array
    {
        $client = $this->makeClient(['price_list_id' => $this->list->id, 'taxable' => true, 'payment_terms' => 'credit']);
        $invoice = $this->invoice($client);
        $run = PriceChange::draft($this->admin, 'list', [
            'price_list_id' => $this->list->id,
            'prices' => [$this->product->id => $price],
        ]);
        PriceChange::apply($run, $this->admin);

        return [$run->fresh(), $invoice->fresh(), $client];
    }

    private function accountant(): User
    {
        return $this->makeAdmin(['role' => 'accountant', 'code' => 'ACC-'.strtoupper(uniqid())]);
    }

    public function test_admin_and_accountant_see_log(): void
    {
        [$run] = $this->appliedRun();

        foreach ([$this->admin, $this->accountant()] as $u) {
            $this->actingAs($u)->get(route('erp.price_changes'))
                ->assertOk()->assertSee($run->number);
        }
    }

    public function test_manager_and_sales_get_403(): void
    {
        $this->appliedRun();
        $manager = $this->makeAdmin(['role' => 'manager', 'code' => 'MGR-'.strtoupper(uniqid())]);

        foreach ([$manager, $this->rep] as $u) {
            $this->actingAs($u)->get(route('erp.price_changes'))->assertForbidden();
        }
    }

    public function test_export_returns_csv_with_bom(): void
    {
        [$run] = $this->appliedRun();

        $res = $this->actingAs($this->admin)->get(route('erp.price_changes', ['export' => 1]));
        $res->assertOk();
        $body = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString($run->number, $body);
    }

    public function test_date_range_filters_runs(): void
    {
        [$run] = $this->appliedRun();
        $old = PriceChangeRun::create([
            'user_id' => $this->admin->id, 'trigger' => 'list', 'status' => 'applied', 'scope' => 'client', 'payload' => [],
        ]);
        PriceChangeRun::whereKey($old->id)->update(['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2)]);

        $res = $this->actingAs($this->admin)->get(route('erp.price_changes', [
            'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString(),
        ]));

        $res->assertOk()->assertSee($run->number)->assertDontSee($old->number);

        // بلا فلتر فترة الاتنين ظاهرين
        $this->actingAs($this->admin)->get(route('erp.price_changes'))
            ->assertSee($run->number)->assertSee($old->number);
    }

    public function test_invoice_filter_finds_the_run(): void
    {
        // الفاتورة التانية اتعملت بعد التعديل الأول فمالهاش صف فيه
        [$first] = $this->appliedRun();
        [$second, $invoice] = $this->appliedRun(14);

        $this->actingAs($this->admin)->get(route('erp.price_changes', ['invoice' => $invoice->number]))
            ->assertOk()->assertSee($second->number)->assertDontSee($first->number);
    }

    public function test_run_and_doc_pages_show_before_after(): void
    {
        [$run, $invoice] = $this->appliedRun();
        $doc = PriceChangeDoc::where('run_id', $run->id)->where('doc_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->accountant())->get(route('erp.price_changes.show', $run))
            ->assertOk()->assertSee($run->number)->assertSee($doc->number)->assertSee('10.00')->assertSee('12.00');

        $this->actingAs($this->admin)->get(route('erp.price_changes.doc', [$run, $doc]))
            ->assertOk()->assertSee($doc->number)
            ->assertSee('34.20')->assertSee('41.04')
            ->assertSee(route('ops.invoice', $invoice->id), false);
    }

    public function test_invoice_page_shows_repriced_badge(): void
    {
        [$run, $invoice] = $this->appliedRun();
        $doc = PriceChangeDoc::where('run_id', $run->id)->where('doc_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->admin)->get(route('ops.invoice', $invoice->id))
            ->assertOk()
            ->assertSee(route('erp.price_changes.doc', [$run, $doc]), false)
            ->assertSee($run->number);
    }

    public function test_doc_from_other_run_404(): void
    {
        [$run] = $this->appliedRun();
        [$other, $invoice] = $this->appliedRun(14);
        $foreign = PriceChangeDoc::where('run_id', $other->id)->where('doc_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->admin)->get(route('erp.price_changes.doc', [$run, $foreign]))->assertNotFound();
    }

    public function test_entity_pages_link_to_filtered_log(): void
    {
        [, , $client] = $this->appliedRun();

        $this->actingAs($this->admin)->get(route('erp.clients.show', $client))
            ->assertOk()->assertSee('client_id='.$client->id, false);
        $this->actingAs($this->admin)->get(route('erp.prices.show', $this->list))
            ->assertOk()->assertSee('price_list_id='.$this->list->id, false);
    }

    /** صف التعديل في ملف التصدير (مقسوم خلايا) */
    private function csvRow(string $body, string $number): array
    {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $cells = str_getcsv($line);
            if (($cells[0] ?? null) === $number) {
                return $cells;
            }
        }

        $this->fail('الصف مش في الملف: '.$number);
    }

    public function test_log_money_counts_only_applied_runs(): void
    {
        [$applied] = $this->appliedRun();

        // مسودة اتلغت وأعمدتها لسه شايلة أرقام معاينة (صف قديم قبل التصفير)
        $legacy = PriceChangeRun::create([
            'user_id' => $this->admin->id, 'trigger' => 'list', 'status' => 'cancelled', 'scope' => 'list',
            'price_list_id' => $this->list->id, 'payload' => ['price_list_id' => $this->list->id, 'prices' => []],
            'invoices_changed' => 7, 'pos_changed' => 3, 'skipped_count' => 2,
            'before_grand' => 100, 'after_grand' => 900, 'ledger_diff' => 800,
            'preview' => ['totals' => ['all' => ['after_grand' => 900]]],
        ]);

        // «للبيع الجديد بس» من المحرك
        $c = $this->makeClient(['price_list_id' => $this->list->id, 'taxable' => true]);
        $this->invoice($c);
        $master = PriceChange::draft($this->admin, 'list', ['price_list_id' => $this->list->id, 'prices' => [$this->product->id => 15]]);
        PriceChange::applyMasterOnly($master, $this->admin);

        $res = $this->actingAs($this->admin)->get(route('erp.price_changes'))->assertOk();
        $kpi = $res->viewData('kpi');
        $this->assertSame(3, (int) $kpi->runs);
        $this->assertSame(1, (int) $kpi->invs);
        $this->assertSame(0, (int) $kpi->pos);
        $this->assertEqualsWithDelta((float) $applied->ledger_diff, (float) $kpi->ledger, 0.001);
        $this->assertEqualsWithDelta(6.84, (float) $kpi->ledger, 0.001);

        $body = $this->actingAs($this->admin)->get(route('erp.price_changes', ['export' => 1]))->streamedContent();

        foreach ([$legacy->number, $master->number] as $n) {
            $row = $this->csvRow($body, $n);
            $this->assertSame(array_fill(0, 7, ''), array_slice($row, 6, 7), 'غير المطبّق مالوش أرقام');
        }
        $this->assertSame('1', $this->csvRow($body, $applied->number)[6]);
        $this->assertSame('6.84', $this->csvRow($body, $applied->number)[12]);
    }

    public function test_export_neutralizes_formula_cells(): void
    {
        $evil = $this->makeClient([
            'name' => '=HYPERLINK("x")', 'name_en' => '=HYPERLINK("x")',
            'price_list_id' => $this->list->id, 'taxable' => true,
        ]);
        $run = PriceChange::draft($this->admin, 'client', ['client_id' => $evil->id, 'price_list_id' => null]);

        $body = $this->actingAs($this->admin)->get(route('erp.price_changes', ['export' => 1]))->streamedContent();

        $this->assertSame("'=HYPERLINK(\"x\")", $this->csvRow($body, $run->number)[4]);
        $this->assertStringNotContainsString(',=HYPERLINK', $body);
        $this->assertStringNotContainsString('"=HYPERLINK', $body);

        // الأرقام السالبة مابتتلمسش
        $this->assertSame('-12.50', \App\Support\Csv::safeCell('-12.50'));
        $this->assertSame("'-cmd", \App\Support\Csv::safeCell('-cmd'));
        $this->assertSame('-', \App\Support\Csv::safeCell('-'));
        $this->assertSame("'@SUM(A1)", \App\Support\Csv::safeCell('@SUM(A1)'));
        $this->assertSame(5, \App\Support\Csv::safeCell(5));
    }
}
