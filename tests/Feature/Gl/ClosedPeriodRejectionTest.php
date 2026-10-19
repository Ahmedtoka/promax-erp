<?php
// tests/Feature/Gl/ClosedPeriodRejectionTest.php
namespace Tests\Feature\Gl;

use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Support\PeriodGuard;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * الشهر المقفول (الخط ٣، ١٩/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * من الويب: أي مستند بتاريخ جوه شهر مقفول بيترفض برسالة بتسمّي الشهر.
 * من الموبايل: المزامنة المتأخرة بتتقبل، وقيدها بيتعلّم للمراجعة
 * (`closed_period` + `late_sync`). من الكونسول/التيست المباشر: زي
 * النهاردة بالظبط (بيتسجل + needs_review).
 */
class ClosedPeriodRejectionTest extends TestCase
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

    private function directCollection(array $over = []): array
    {
        return array_merge([
            'direct' => 1,
            'client_id' => $this->makeClient()->id,
            'doc_date' => '2026-09-15',
            'amount' => 500,
            'method' => 'cash',
        ], $over);
    }

    public function test_web_back_dated_manual_document_into_closed_month_is_rejected_with_the_month_named(): void
    {
        $admin = $this->makeAdmin(['locale' => 'ar']);
        GlPeriod::close('2026-09', $admin);
        $body = $this->directCollection();

        $this->actingAs($admin)->from(route('ops.manual'))
            ->post(route('ops.manual.collection'), $body)
            ->assertSessionHasErrors('period');

        $msg = session('errors')->first('period');
        $this->assertStringContainsString('سبتمبر 2026', $msg);
        $this->assertSame(0, Transaction::where('client_id', $body['client_id'])->count(), 'no transactions row');

        // نفس المستند بلغة إنجليزي — الشهر بالإنجليزي
        $en = $this->makeAdmin(['locale' => 'en']);
        $this->actingAs($en)->from(route('ops.manual'))
            ->post(route('ops.manual.collection'), $body)
            ->assertSessionHasErrors('period');
        $this->assertStringContainsString('September 2026', session('errors')->first('period'));
        $this->assertSame(0, Transaction::where('client_id', $body['client_id'])->count());

        // ⚠️ المود بيرجع off بعد الطلب — مايسرّبش لنداء مباشر بعده
        $this->assertSame(PeriodGuard::MODE_OFF, PeriodGuard::mode());
    }

    public function test_same_document_from_mobile_late_sync_is_accepted_and_flagged(): void
    {
        $admin = $this->makeAdmin();
        // الموبايل بيأرّخ بـtoday() بتاع السيرفر — المزامنة المتأخرة بتقع في
        // شهر مقفول بس لو الشهر الحالي نفسه اتقفل
        GlPeriod::close(today()->format('Y-m'), $admin);

        $rep = $this->punchIn($this->makeRep());
        $client = $this->makeClient();
        $visit = $this->openVisit($rep, $client);

        $res = $this->withHeaders($this->tokenFor($rep))
            ->postJson('/api/visits/'.$visit->id.'/collect', [
                'amount' => 300, 'method' => 'cash', 'idem_key' => 'late-sync-1',
            ]);
        $this->assertContains($res->status(), [200, 201], $res->getContent());

        $tx = Transaction::where('idem_key', 'late-sync-1')->first();
        $this->assertNotNull($tx, 'row exists');
        $e = GlEntry::where('source_type', $tx->getMorphClass())->where('source_id', $tx->id)->where('origin', 'auto')->first();
        $this->assertNotNull($e);
        $this->assertTrue((bool) $e->needs_review);
        $this->assertStringContainsString('closed_period', (string) $e->review_reason);
        $this->assertStringContainsString('late_sync', (string) $e->review_reason);
        $this->assertSame(PeriodGuard::MODE_OFF, PeriodGuard::mode());
    }

    public function test_expense_void_in_closed_month_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $x = Expense::create(['number' => Expense::nextNumber(), 'date' => '2026-09-03', 'account_id' => GlAccount::findKey('expense_rent')->id, 'amount' => 50, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'posted', 'created_by' => $admin->id]);
        GlPeriod::close('2026-09', $admin);

        $this->actingAs($admin)->from(route('gl.expenses'))
            ->post(route('gl.expenses.void', $x))
            ->assertSessionHasErrors(['period', 'void']);
        $this->assertStringContainsString('September 2026', session('errors')->first('period'));
        $this->assertSame('posted', $x->fresh()->status);

        // والسند الجديد بتاريخ الشهر المقفول كمان
        $this->actingAs($admin)->from(route('gl.expenses'))->post(route('gl.expenses.store'), [
            'date' => '2026-09-20', 'account_id' => GlAccount::findKey('expense_rent')->id, 'amount' => 10,
            'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'y',
        ])->assertSessionHasErrors(['period', 'date']);
        $this->assertSame(1, Expense::count());
    }

    public function test_invoice_redate_into_closed_month_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $rep = $this->makeRep();
        $inv = Invoice::create(['number' => 'INV-90001', 'client_id' => $client->id, 'user_id' => $rep->id, 'payment' => 'credit', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'tax_total' => 0, 'grand_total' => 100]);
        $inv->forceFill(['created_at' => '2026-08-10 10:00:00'])->save();
        GlPeriod::close('2026-09', $admin);

        $this->actingAs($admin)->from(route('ops.invoices'))
            ->post(route('ops.invoices.redate', $inv), ['date' => '2026-09-10'])
            ->assertSessionHasErrors('period');
        $this->assertSame('2026-08-10', $inv->fresh()->created_at->toDateString());

        // والفاتورة اللي في الشهر المقفول نفسه ماتتنقلش منه برضه
        GlPeriod::close('2026-08', $admin);
        $this->actingAs($admin)->from(route('ops.invoices'))
            ->post(route('ops.invoices.redate', $inv), ['date' => today()->toDateString()])
            ->assertSessionHasErrors('period');
        $this->assertSame('2026-08-10', $inv->fresh()->created_at->toDateString());
    }

    public function test_supplier_payment_dated_in_a_closed_month_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $supplier = Supplier::create(['code' => 'SUP-'.strtoupper(uniqid()), 'name' => 'مورد', 'active' => true]);
        GlPeriod::close('2026-09', $admin);

        $this->actingAs($admin)->from(route('erp.suppliers.show', $supplier))
            ->post(route('erp.suppliers.pay', $supplier), ['amount' => 100, 'paid_on' => '2026-09-05', 'method' => 'cash'])
            ->assertSessionHasErrors('period');
        $this->assertSame(0, \App\Models\SupplierTransaction::where('supplier_id', $supplier->id)->count());
    }

    public function test_open_month_is_untouched(): void
    {
        $admin = $this->makeAdmin();
        GlPeriod::close('2026-09', $admin);
        $body = $this->directCollection(['doc_date' => '2026-08-14']);

        $this->actingAs($admin)->from(route('ops.manual'))
            ->post(route('ops.manual.collection'), $body)
            ->assertSessionHasNoErrors();

        $tx = Transaction::where('client_id', $body['client_id'])->where('kind', 'collection')->first();
        $this->assertNotNull($tx);
        $e = GlEntry::where('source_type', $tx->getMorphClass())->where('source_id', $tx->id)->first();
        $this->assertNotNull($e);
        $this->assertFalse((bool) $e->needs_review);
    }

    public function test_console_mode_keeps_todays_behaviour(): void
    {
        $admin = $this->makeAdmin();
        GlPeriod::close('2026-09', $admin);
        $this->assertSame(PeriodGuard::MODE_OFF, PeriodGuard::mode());

        $tx = Transaction::create(['client_id' => $this->makeClient()->id, 'date' => '2026-09-12', 'memo' => 'x', 'debit' => 0, 'credit' => 70, 'kind' => 'collection', 'method' => 'cash']);
        $this->assertNotNull($tx->id);
        $e = GlEntry::where('source_type', $tx->getMorphClass())->where('source_id', $tx->id)->first();
        $this->assertTrue((bool) $e->needs_review);
        $this->assertSame('closed_period', $e->review_reason);
    }

    public function test_reject_mode_guards_watched_models_on_update_and_delete(): void
    {
        $admin = $this->makeAdmin();
        $tx = Transaction::create(['client_id' => $this->makeClient()->id, 'date' => '2026-09-12', 'memo' => 'x', 'debit' => 0, 'credit' => 70, 'kind' => 'collection', 'method' => 'cash']);
        GlPeriod::close('2026-09', $admin);

        PeriodGuard::using(PeriodGuard::MODE_REJECT, function () use ($tx) {
            foreach ([fn () => $tx->update(['credit' => 80]), fn () => $tx->update(['date' => '2026-10-01']), fn () => $tx->delete()] as $op) {
                try {
                    $op();
                    $this->fail('closed-month write went through');
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $this->assertArrayHasKey('period', $e->errors());
                }
            }
        });
        $this->assertSame(PeriodGuard::MODE_OFF, PeriodGuard::mode());
        $this->assertSame(70.0, (float) $tx->fresh()->credit);
    }

    public function test_renumber_skips_invoices_in_closed_months(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $rep = $this->makeRep();
        $mk = function (string $num, string $at) use ($client, $rep) {
            $i = Invoice::create(['number' => $num, 'client_id' => $client->id, 'user_id' => $rep->id, 'payment' => 'credit', 'subtotal' => 10, 'discount' => 0, 'total' => 10, 'tax_total' => 0, 'grand_total' => 10]);
            $i->forceFill(['created_at' => $at])->save();

            return $i;
        };
        $closed = $mk('INV-1001', '2026-09-05 10:00:00');
        $a = $mk('INV-5000', '2026-08-01 10:00:00');
        $b = $mk('INV-4000', '2026-10-01 10:00:00');
        GlPeriod::close('2026-09', $admin);

        $this->actingAs($admin)->from(route('ops.invoices'))
            ->post(route('ops.invoices.renumber'))
            ->assertSessionHasNoErrors();

        $this->assertSame('INV-1001', $closed->fresh()->number, 'closed month untouched');
        $this->assertSame('INV-1002', $a->fresh()->number, 'taken number skipped');
        $this->assertSame('INV-1003', $b->fresh()->number);
        $this->assertStringContainsString(__('gl.renumber_skipped_closed', ['n' => 1]), (string) session('ok'));
    }

    public function test_client_opening_balance_in_a_closed_month_cannot_be_replaced(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $client->setOpeningBalance(1000, '2026-09-01');   // كونسول = مسموح
        GlPeriod::close('2026-09', $admin);

        // الجديد في شهر مفتوح، بس القديم (اللي هيتمسح جماعي) في المقفول
        $this->actingAs($admin)->from(route('erp.clients.show', $client))
            ->post(route('erp.clients.opening', $client), ['amount' => 2000, 'date' => '2026-08-01'])
            ->assertSessionHasErrors('period');
        $this->assertSame(1000.0, (float) Transaction::where('client_id', $client->id)->where('kind', 'opening')->value('debit'));
    }
}
