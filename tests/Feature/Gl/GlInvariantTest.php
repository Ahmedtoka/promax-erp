<?php
// tests/Feature/Gl/GlInvariantTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\RepSettlement;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
    }

    public function test_a_settlement_moves_cash_from_the_rep_to_the_safe(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $visit = \App\Models\Visit::create(['user_id' => $rep->id, 'client_id' => $client->id, 'checked_in_at' => now()]);
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 800, 'kind' => 'collection', 'method' => 'cash', 'source_type' => \App\Models\Visit::class, 'source_id' => $visit->id]);

        $s = RepSettlement::create([
            'number' => 'SET-9001', 'user_id' => $rep->id, 'from_at' => now()->subDay(), 'to_at' => now(),
            'invoices_count' => 0, 'cash_sales' => 0, 'credit_sales' => 0, 'cash_refunds' => 0, 'expected' => 800,
            'prev_balance' => 0, 'received' => 750, 'balance' => 50, 'created_by' => $admin->id,
        ]);

        $this->assertNotNull(app(Ledger::class)->autoEntryFor($s));
        $this->assertSame(750.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
        $this->assertSame(50.0, GlAccount::repCash($rep)->balanceBetween(null, null), 'اللي لسه معاه');
    }

    public function test_supplier_invoice_and_payment_post_to_payables_and_the_invariant_holds(): void
    {
        $sup = Supplier::create(['code' => 'SUP-1', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $sup->post('invoice', today()->toDateString(), 0, 2000, 'فاتورة مورد');
        $sup->post('payment', today()->toDateString(), 500, 0, 'دفعة كاش');

        $this->assertSame(1500.0, GlAccount::findKey('payables')->balanceBetween(null, null));
        $this->assertSame(2000.0, GlAccount::findKey('expense_purchases')->balanceBetween(null, null));
        $inv = app(Ledger::class)->invariants();
        $this->assertTrue($inv['payables']['ok']);
    }

    public function test_the_receivables_invariant_holds_after_a_mixed_world_and_every_entry_balances(): void
    {
        $rep = $this->makeRep();
        $a = $this->makeClient(['rep_id' => $rep->id]);
        $b = $this->makeClient();
        $mk = fn ($c, array $x) => Transaction::create(array_merge(['client_id' => $c->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $x));
        $mk($a, ['kind' => 'sale', 'debit' => 1140, 'tax' => 140]);
        $mk($a, ['kind' => 'collection', 'credit' => 500, 'method' => 'cash']);
        $mk($b, ['kind' => 'sale', 'debit' => 300]);
        $mk($b, ['kind' => 'return', 'credit' => 100]);
        $mk($b, ['kind' => 'collection', 'credit' => 50, 'method' => 'transfer', 'reference' => 'T']);
        $a->recalculate();
        $b->recalculate();

        $inv = app(Ledger::class)->invariants();
        $this->assertTrue($inv['receivables']['ok'], json_encode($inv));
        $this->assertSame((float) $a->fresh()->balance + (float) $b->fresh()->balance, $inv['receivables']['gl']);
        GlEntry::with('lines')->get()->each(fn ($e) => $this->assertSame($e->totalDebit(), $e->totalCredit(), $e->number));
    }

    public function test_the_sync_command_creates_a_cash_account_for_every_active_field_user(): void
    {
        $this->makeRep();
        $this->makeRep();
        $this->artisan('promax:gl-sync-reps')->assertSuccessful();

        $this->assertSame(2, GlAccount::where('is_system', true)->whereNotNull('user_id')->count());
        $this->artisan('promax:gl-sync-reps')->assertSuccessful();
        $this->assertSame(2, GlAccount::whereNotNull('user_id')->count(), 'idempotent');
    }
}
