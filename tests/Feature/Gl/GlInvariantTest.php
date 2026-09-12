<?php
// tests/Feature/Gl/GlInvariantTest.php
namespace Tests\Feature\Gl;

use App\Models\Batch;
use App\Models\Client;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RepSettlement;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    /**
     * ⚠️ مراجعة الجولة ١ (فحص ٣): التيست القديم كان زيف — الـ
     * `User::created` hook (Task 5) بيعمل حساب المندوب أول ما يتعمل
     * لوحده، فالأمر مايعملش حاجة والتيست بينجح حتى لو الأمر نفسه اتكسر.
     * هنا بنمسح الحسابات اللي الهوك عملها الأول عشان نختبر **الأمر**
     * مش الهوك.
     */
    public function test_the_sync_command_creates_a_cash_account_for_every_active_field_user(): void
    {
        $this->makeRep();
        $this->makeRep();
        GlAccount::whereNotNull('user_id')->delete();

        $this->artisan('promax:gl-sync-reps')
            ->expectsOutputToContain('2')
            ->assertSuccessful();

        $this->assertSame(2, GlAccount::where('is_system', true)->whereNotNull('user_id')->count());

        $this->artisan('promax:gl-sync-reps')
            ->expectsOutputToContain('0')
            ->assertSuccessful();

        $this->assertSame(2, GlAccount::whereNotNull('user_id')->count(), 'idempotent');
    }

    public function test_the_sync_command_fails_when_the_tree_is_not_seeded(): void
    {
        GlAccount::whereNotNull('user_id')->delete();
        GlAccount::where('system_key', 'rep_cash')->delete();

        $this->artisan('promax:gl-sync-reps')->assertFailed();
    }

    /**
     * ⚠️ **تاريخ البداية لما يتحرك لقدام.** المستندات القديمة بتخرج من
     * الفحص الثابت فوراً، فالقيود اللي اتولدت وهي البداية أقدم لازم
     * تتشال معاها — وإلا الشجرة بتفضل شايلة فترة برّه الدفتر والفحص
     * بيقع ويمنع أي إعادة بناء بعد كده.
     */
    public function test_moving_the_start_date_forward_purges_the_earlier_automatic_entries(): void
    {
        $admin = $this->makeAdmin();
        Setting::write('gl_start_date', '2026-01-01');
        $client = $this->makeClient();
        $mk = fn (array $a) => Transaction::create(array_merge(['client_id' => $client->id, 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $a));
        $mk(['date' => '2026-02-10', 'kind' => 'sale', 'debit' => 500]);
        $mk(['date' => '2026-09-01', 'kind' => 'sale', 'debit' => 300]);
        $client->recalculate();
        $this->assertSame(2, GlEntry::count());

        $res = $this->actingAs($admin)->post(route('gl.settings.general'), [
            'gl_start_date' => '2026-08-01', 'gl_enabled' => '1',
        ]);
        $res->assertRedirect(route('gl.settings'));

        $this->assertSame(1, GlEntry::where('origin', 'auto')->count(), 'قيد فبراير لازم يتشال');
        $this->assertStringContainsString(__('gl.start_moved_purged', ['n' => 1]), (string) session('ok'));

        // والفحص الثابت بيقارن الطرفين من نفس التاريخ — ٣٠٠ مقابل ٣٠٠
        $inv = app(Ledger::class)->invariants();
        $this->assertTrue($inv['receivables']['ok'], json_encode($inv));
        $this->assertSame(300.0, $inv['receivables']['gl']);
    }

    // ═══════════════════════ أدوات الفاتورة الإدارية ═══════════════════════

    /**
     * راكب مندوب + عميل + صنف + عهدة مفتوحة، جاهز للبيع عبر الـAPI
     * الحقيقي (نفس مسرح `InvoiceTaxLedgerTest::scene()`) — عشان
     * القيود اللي بنختبر الأدوات الإدارية عليها تبقى قيود حقيقية
     * مرحّلة من مسار البيع الفعلي مش قيود مصطنعة.
     *
     * @return array{0: User, 1: Client, 2: Product}
     */
    private function sceneReadyToSell(): array
    {
        $channel = $this->makeChannel(0.0);
        $zone = $this->makeZone();
        $rep = $this->makeRep(['zone_id' => $zone->id, 'channel_id' => $channel->id]);
        $this->punchIn($rep);

        $client = $this->makeClient(['zone_id' => $zone->id, 'channel_id' => $channel->id, 'taxable' => false]);
        $product = $this->makeProduct(['cost' => 10, 'price_new' => 20]);
        $warehouse = $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-1',
            'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6),
            'qty_received' => 100,
            'qty_remaining' => 100,
            'cost' => 10,
        ]);

        $custody = Custody::create([
            'user_id' => $rep->id,
            'warehouse_id' => $warehouse->id,
            'date' => today(),
            'status' => 'open',
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'assigned' => 50,
            'sold' => 0,
        ]);

        return [$rep, $client, $product];
    }

    /** كل قيود الشجرة المرتبطة بقيود كشف حساب الفاتورة دي (مش بمعرّفها هي) */
    private function glEntriesForInvoice(Invoice $invoice): Collection
    {
        $txIds = Transaction::where('source_type', Invoice::class)->where('source_id', $invoice->id)->pluck('id');

        return GlEntry::where('source_type', Transaction::class)->whereIn('source_id', $txIds)->get();
    }

    /**
     * فاتورة كاش حقيقية عبر مسار البيع بالـAPI: قيد بيع + قيد تحصيل
     * تلقائي مربوطين بالفاتورة، اتنين مرحّلين لأن السويتش شغال من
     * `setUp()`.
     */
    public function test_toggle_invoice_payment_unposts_and_reposts_the_collection_entry(): void
    {
        $admin = $this->makeAdmin();
        [$rep, $client, $product] = $this->sceneReadyToSell();
        // ⚠️ كاش/آجل من تعريف العميل مش من البوست (قرار المالك ٣/٨) —
        // حقل `payment` في `/api/invoices` بيتطنش
        $client->update(['payment_terms' => 'cash']);

        $resp = $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'cash']);
        $resp->assertCreated();

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertSame('cash', $invoice->payment);
        $this->assertCount(2, $this->glEntriesForInvoice($invoice), 'فاتورة الكاش لازم تولّد قيد بيع + قيد تحصيل');

        // كاش → آجل: قيد التحصيل بيتشال، والفصل بالصف (ruling A) بيخلي
        // TransactionObserver::deleted يشيل قيده من الشجرة تلقائي
        $this->actingAs($admin)
            ->post(route('ops.invoices.payment', $invoice))
            ->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertSame('credit', $invoice->payment);
        $this->assertCount(1, $this->glEntriesForInvoice($invoice), 'قيد التحصيل لازم يتشال من الشجرة مع الفاتورة');
        $this->assertTrue(app(Ledger::class)->invariants()['receivables']['ok']);

        // آجل → كاش: قيد تحصيل جديد بيتعمل وبيترحّل من نفسه
        $this->actingAs($admin)
            ->post(route('ops.invoices.payment', $invoice))
            ->assertRedirect();

        $invoice = $invoice->fresh();
        $this->assertSame('cash', $invoice->payment);
        $this->assertCount(2, $this->glEntriesForInvoice($invoice), 'رجوعها لكاش لازم يرجّع قيد التحصيل');
        $this->assertTrue(app(Ledger::class)->invariants()['receivables']['ok']);
    }

    public function test_redate_invoice_moves_the_gl_entries_to_the_new_date_and_period(): void
    {
        $admin = $this->makeAdmin();
        [$rep, $client, $product] = $this->sceneReadyToSell();

        $resp = $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'credit']);
        $resp->assertCreated();

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $entriesBefore = $this->glEntriesForInvoice($invoice);
        $this->assertGreaterThan(0, $entriesBefore->count());

        $newDate = today()->subDays(3);

        $this->actingAs($admin)
            ->post(route('ops.invoices.redate', $invoice), ['date' => $newDate->toDateString()])
            ->assertRedirect();

        $invoice = $invoice->fresh();

        foreach (Transaction::where('source_type', Invoice::class)->where('source_id', $invoice->id)->get() as $tx) {
            $this->assertSame($newDate->toDateString(), $tx->date->toDateString());
        }

        $entriesAfter = $this->glEntriesForInvoice($invoice);
        $this->assertSame($entriesBefore->count(), $entriesAfter->count(), 'مفيش قيد لازم يضيع أو يتضاعف');

        foreach ($entriesAfter as $e) {
            $this->assertSame($newDate->toDateString(), $e->date->toDateString());
            $this->assertSame($newDate->format('Y-m'), $e->period_key);
        }
    }

    /**
     * ⚠️ **إعادة الترقيم بقت repost لكل فاتورة اتغيّر رقمها** بدل إعادة
     * بناء كاملة للدفتر. لو الترحيل نفسه وقع (قاعدة موقوفة هنا) الترقيم
     * بيكمّل — بس الشاشة لازم تقول إن الدفتر ورا، مش تقول «تمام» وبس.
     */
    public function test_renumber_reposts_each_invoice_and_warns_when_the_ledger_could_not_follow(): void
    {
        $admin = $this->makeAdmin();
        [$rep, $client, $product] = $this->sceneReadyToSell();
        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'credit'])->assertCreated();

        $invoice = Invoice::latest('id')->first();
        // رقم مختلف عن اللي الترقيم هيديه (INV-1001) عشان الفاتورة
        // تتعدّ «اتغيّر رقمها» فعلاً
        $invoice->update(['number' => 'INV-9977']);
        $this->assertCount(1, $this->glEntriesForInvoice($invoice));

        // القاعدة اتوقفت — القيد هيتمسح من غير بديل وقت الـrepost
        \App\Models\Gl\GlPostingRule::where('key', 'tx.sale')->update(['active' => false]);
        \App\Models\Gl\GlPostingRule::flush();

        $res = $this->actingAs($admin)->post(route('ops.invoices.renumber'));

        $res->assertRedirect();
        $res->assertSessionHasErrors('gl');
        $this->assertSame(__('gl.repost_failed_n', ['n' => 1]), session('errors')->first('gl'));
        $this->assertSame('INV-1001', $invoice->fresh()->number, 'الترقيم نفسه كمّل');
        $this->assertCount(0, $this->glEntriesForInvoice($invoice->fresh()));
    }

    /** الترقيم والشجرة ماشيين مع بعض لما القواعد سليمة — مفيش تحذير */
    public function test_renumber_keeps_the_entries_when_the_rules_are_healthy(): void
    {
        $admin = $this->makeAdmin();
        [$rep, $client, $product] = $this->sceneReadyToSell();
        $this->sellApi($rep, $client, [['product_id' => $product->id, 'qty' => 5]], ['payment' => 'credit'])->assertCreated();

        $invoice = Invoice::latest('id')->first();
        $invoice->update(['number' => 'INV-9977']);

        $this->actingAs($admin)->post(route('ops.invoices.renumber'))->assertSessionHasNoErrors();

        $this->assertSame('INV-1001', $invoice->fresh()->number);
        $this->assertCount(1, $this->glEntriesForInvoice($invoice->fresh()), 'القيد لازم يفضل موجود بعد الترقيم');
        $this->assertTrue(app(Ledger::class)->invariants()['receivables']['ok']);
    }

    // ⚠️ `editInvoiceItems` مش متغطي هنا — مسرحه محتاج عهدة مفتوحة
    // ببند مطابق (نفس الصنف/الباتش) وقفل/فتح على مستوى بيانات دقيق
    // (custody item lockForUpdate على `sold`)، وده أغلى بكتير من نطاق
    // مراجعة الجولة دي. الحماية بتاعته (`repostInvoiceGl`) نفس الأربعة
    // التانية اللي اتغطوا هنا وهناك بالفعل، فمفيش سلوك جديد يستاهل
    // تيست منفصل غالي.
}
