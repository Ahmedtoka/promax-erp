<?php
// tests/Feature/Gl/GlPostingTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-01-01');
        Setting::flushCache();
    }

    private function entryFor(Transaction $tx): GlEntry
    {
        $e = GlEntry::where('source_type', $tx->getMorphClass())->where('source_id', $tx->id)->where('origin', 'auto')->first();
        $this->assertNotNull($e, 'entry posted for '.$tx->kind);
        $this->assertSame($e->totalDebit(), $e->totalCredit(), 'balanced');

        return $e;
    }

    private function lineOn(GlEntry $e, string $key): array
    {
        $acc = GlAccount::findKey($key);
        $l = $e->lines->firstWhere('account_id', $acc->id);
        $this->assertNotNull($l, "line on {$key}");

        return [(float) $l->debit, (float) $l->credit];
    }

    public function test_a_credit_sale_with_tax_debits_receivables_and_splits_sales_and_vat(): void
    {
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => '2026-09-01', 'memo' => 'فاتورة', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);

        $e = $this->entryFor($tx);
        $this->assertSame([1140.0, 0.0], $this->lineOn($e, 'receivables'));
        $this->assertSame([0.0, 1000.0], $this->lineOn($e, 'sales'));
        $this->assertSame([0.0, 140.0], $this->lineOn($e, 'vat_output'));
        $this->assertSame('tx.sale', $e->rule_key);
        $this->assertSame('2026-09', $e->period_key);
    }

    public function test_a_field_cash_collection_goes_to_the_reps_cash_account(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $visit = Visit::create(['user_id' => $rep->id, 'client_id' => $client->id, 'checked_in_at' => now()]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحصيل', 'debit' => 0, 'credit' => 500, 'kind' => 'collection', 'method' => 'cash', 'source_type' => Visit::class, 'source_id' => $visit->id]);

        $e = $this->entryFor($tx);
        $repAcc = GlAccount::repCash($rep);
        $this->assertSame(500.0, (float) $e->lines->firstWhere('account_id', $repAcc->id)->debit);
        $this->assertSame([0.0, 500.0], $this->lineOn($e, 'receivables'));
    }

    public function test_a_bank_transfer_collection_and_a_direct_office_cash_collection(): void
    {
        $client = $this->makeClient();
        $bank = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحويل', 'debit' => 0, 'credit' => 700, 'kind' => 'collection', 'method' => 'transfer', 'reference' => 'TRX']);
        $cash = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'كاش مكتب', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);

        $this->assertSame([700.0, 0.0], $this->lineOn($this->entryFor($bank), 'bank'));
        $this->assertSame([300.0, 0.0], $this->lineOn($this->entryFor($cash), 'cash_main'));
    }

    public function test_the_automatic_collection_behind_a_cash_invoice_lands_on_the_invoice_rep(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $inv = Invoice::create(['number' => 'INV-90001', 'client_id' => $client->id, 'user_id' => $rep->id, 'payment' => 'cash', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'tax_total' => 0, 'grand_total' => 100]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'مقابل فاتورة كاش', 'debit' => 0, 'credit' => 100, 'kind' => 'collection', 'source_type' => Invoice::class, 'source_id' => $inv->id]);

        $e = $this->entryFor($tx);
        $this->assertSame('tx.collection.auto', $e->rule_key);
        $this->assertSame(100.0, (float) $e->lines->firstWhere('account_id', GlAccount::repCash($rep)->id)->debit);
    }

    public function test_return_refund_rebate_taxded_and_opening_post_to_their_accounts(): void
    {
        $client = $this->makeClient();
        $mk = fn (array $a) => Transaction::create(array_merge(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $a));

        $ret = $this->entryFor($mk(['kind' => 'return', 'credit' => 228, 'tax' => 28]));
        $this->assertSame([200.0, 0.0], $this->lineOn($ret, 'sales_returns'));
        $this->assertSame([28.0, 0.0], $this->lineOn($ret, 'vat_output'));
        $this->assertSame([0.0, 228.0], $this->lineOn($ret, 'receivables'));

        $refund = $this->entryFor($mk(['kind' => 'refund', 'debit' => 50]));
        $this->assertSame([50.0, 0.0], $this->lineOn($refund, 'receivables'));
        $this->assertSame([0.0, 50.0], $this->lineOn($refund, 'cash_main')); // مفيش مندوب → الخزنة + needs_review
        $this->assertTrue($refund->needs_review);

        $this->assertSame([40.0, 0.0], $this->lineOn($this->entryFor($mk(['kind' => 'rebate', 'credit' => 40])), 'discounts_allowed'));
        $this->assertSame([30.0, 0.0], $this->lineOn($this->entryFor($mk(['kind' => 'taxded', 'credit' => 30])), 'withheld_tax'));

        $open = $this->entryFor($mk(['kind' => 'opening', 'debit' => 900]));
        $this->assertSame([900.0, 0.0], $this->lineOn($open, 'receivables'));
        $this->assertSame([0.0, 900.0], $this->lineOn($open, 'opening_equity'));
    }

    public function test_consignment_and_rows_before_the_start_date_or_with_the_switch_off_are_not_posted(): void
    {
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'أمانة', 'debit' => 0, 'credit' => 0, 'kind' => 'consignment']);
        Transaction::create(['client_id' => $client->id, 'date' => '2025-12-31', 'memo' => 'قديم', 'debit' => 10, 'credit' => 0, 'kind' => 'sale']);
        Setting::write('gl_enabled', '0');
        Setting::flushCache();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'مقفول', 'debit' => 10, 'credit' => 0, 'kind' => 'sale']);

        $this->assertSame(0, GlEntry::count());
    }

    public function test_posting_is_idempotent_and_deleting_the_row_removes_the_entry(): void
    {
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 10, 'credit' => 0, 'kind' => 'sale']);
        app(Ledger::class)->post($tx);
        app(Ledger::class)->post($tx);
        $this->assertSame(1, GlEntry::count());

        $tx->delete();
        $this->assertSame(0, GlEntry::count());
    }
}
