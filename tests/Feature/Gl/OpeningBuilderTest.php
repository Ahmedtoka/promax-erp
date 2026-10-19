<?php

namespace Tests\Feature\Gl;

use App\Contracts\Gl\FixedAssetRegister;
use App\Models\Batch;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlOpeningLine;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use App\Services\Gl\OpeningBalances;
use App\Services\Gl\OpeningBuilder;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OpeningBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function books(): void
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
    }

    private function lineOn(array $lines, string $key): ?array
    {
        $id = GlAccount::findKey($key)->id;
        foreach ($lines as $l) {
            if ($l['account_id'] === $id) {
                return $l;
            }
        }

        return null;
    }

    public function test_compose_balances_with_a_difference_line(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        GlOpeningLine::create(['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 1000, 'credit' => 0, 'created_by' => $admin->id]);

        $b = app(OpeningBuilder::class);
        $this->assertSame('2026-07-31', $b->asOf()->toDateString());
        $c = $b->compose();
        $this->assertSame(1000.0, $c['debit']);
        $this->assertSame(0.0, $c['credit']);
        $this->assertSame(1000.0, $c['difference']);
        $this->assertSame(1000.0, $c['by_source']['manual']['debit']);

        $entry = $b->post($admin);

        $this->assertSame('opening', $entry->origin);
        $this->assertSame('golive.opening', $entry->rule_key);
        $this->assertSame('2026-07-31', $entry->date->toDateString());
        $diff = $entry->lines->firstWhere('account_id', GlAccount::openingDifference()->id);
        $this->assertNotNull($diff);
        $this->assertEquals(1000, $diff->credit);
        $this->assertEquals(0, $diff->debit);
        $this->assertSame(1000.0, $b->differenceBalance());
    }

    public function test_receivables_line_equals_client_balances_before_start(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        app(OpeningBalances::class)->setClient($client, 500, Carbon::parse('2026-07-31'), $admin);
        Transaction::create(['client_id' => $client->id, 'date' => '2026-07-10', 'memo' => 'old sale',
            'debit' => 300, 'credit' => 0, 'tax' => 0, 'kind' => 'sale']);
        // بعد البداية = مش جزء من الافتتاحي
        Transaction::create(['client_id' => $client->id, 'date' => '2026-08-05', 'memo' => 'new sale',
            'debit' => 50, 'credit' => 0, 'tax' => 0, 'kind' => 'sale']);
        // الأمانة مابتدخلش المديونية
        Transaction::create(['client_id' => $client->id, 'date' => '2026-07-11', 'memo' => 'consignment',
            'debit' => 70, 'credit' => 0, 'tax' => 0, 'kind' => 'consignment']);

        $c = app(OpeningBuilder::class)->compose();

        $recv = $this->lineOn($c['lines'], 'receivables');
        $this->assertNotNull($recv);
        $this->assertSame(800.0, $recv['debit']);
        $this->assertSame(0.0, $recv['credit']);
        $this->assertSame(800.0, $c['by_source']['receivables']['debit']);

        // الترحيل بيكتب على حساب العملاء رغم إنه حساب مراقبة — والفحص الثابت لسه سليم
        $entry = app(OpeningBuilder::class)->post($admin);
        $this->assertEquals(800, $entry->lines->firstWhere('account_id', GlAccount::findKey('receivables')->id)->debit);
        $this->assertTrue(app(Ledger::class)->invariants()['receivables']['ok']);
    }

    public function test_supplier_opening_by_name_shows_on_their_ledger_and_payables_line(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $s = Supplier::create(['code' => 'SUP-001', 'name' => 'مورد التيست', 'active' => true]);

        $tx = app(OpeningBalances::class)->setSupplier($s, 1200, Carbon::parse('2026-07-31'), $admin);

        $this->assertSame('opening', $tx->kind);
        $this->assertEquals(1200, $tx->credit);
        $this->assertSame('golive-opening:supplier:'.$s->id, $tx->idem_key);
        $this->assertEquals(1200, $s->fresh()->balance);

        // نفس المورد تاني = تعديل نفس الصف، مش صف جديد
        app(OpeningBalances::class)->setSupplier($s, -300, Carbon::parse('2026-07-31'), $admin);
        $this->assertSame(1, SupplierTransaction::where('supplier_id', $s->id)->count());
        $this->assertEquals(-300, $s->fresh()->balance);
        app(OpeningBalances::class)->setSupplier($s, 1200, Carbon::parse('2026-07-31'), $admin);

        $c = app(OpeningBuilder::class)->compose();
        $pay = $this->lineOn($c['lines'], 'payables');
        $this->assertSame(1200.0, $pay['credit']);
        $this->assertSame(0.0, $pay['debit']);
        $this->assertSame(-1200.0, $c['difference']);

        $entry = app(OpeningBuilder::class)->post($admin);
        $this->assertEquals(1200, $entry->lines->firstWhere('account_id', GlAccount::openingDifference()->id)->debit);
        $this->assertTrue(app(Ledger::class)->invariants()['payables']['ok']);
    }

    public function test_inventory_line_uses_current_batch_costs(): void
    {
        $this->books();
        $p = $this->makeProduct(['cost' => 9]);
        $wh = $this->makeWarehouse();
        $cc = CostCenter::create(['code' => 'CC-WH', 'name' => 'مخزن', 'kind' => 'branch', 'warehouse_id' => $wh->id, 'active' => true]);
        Batch::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'batch_no' => 'B-1', 'produced_on' => today()->subMonth(),
            'expires_on' => today()->addYear(), 'qty_received' => 10, 'qty_remaining' => 10, 'cost' => 12.5]);
        // باتش خلص = صفر
        Batch::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'batch_no' => 'B-0', 'produced_on' => today()->subMonth(),
            'expires_on' => today()->addYear(), 'qty_received' => 4, 'qty_remaining' => 0, 'cost' => 50]);

        $c = app(OpeningBuilder::class)->compose();

        $inv = $this->lineOn($c['lines'], 'inventory');
        $this->assertSame(125.0, $inv['debit']);
        $this->assertSame($cc->id, $inv['cost_center_id']);
        $this->assertSame(125.0, $c['by_source']['inventory']['debit']);
    }

    public function test_fixed_asset_register_binding_is_used(): void
    {
        $this->books();
        $cash = GlAccount::findKey('cash_main')->id;
        $equity = GlAccount::findKey('opening_equity')->id;
        $this->app->instance(FixedAssetRegister::class, new class($cash, $equity) implements FixedAssetRegister
        {
            public function __construct(private int $dr, private int $cr)
            {
            }

            public function key(): string
            {
                return 'fixed_assets';
            }

            public function label(): string
            {
                return 'Fake assets';
            }

            public function lines(Carbon $asOf): array
            {
                return [
                    ['account_id' => $this->dr, 'debit' => 700, 'credit' => 0, 'memo' => 'car'],
                    ['account_id' => $this->cr, 'debit' => 0, 'credit' => 200, 'memo' => 'dep'],
                ];
            }
        });

        $c = app(OpeningBuilder::class)->compose();

        $this->assertSame(['label' => 'Fake assets', 'debit' => 700.0, 'credit' => 200.0], $c['by_source']['fixed_assets']);
        $this->assertSame(500.0, $c['difference']);
    }

    public function test_post_twice_rewrites_one_opening_entry(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $line = GlOpeningLine::create(['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 1000, 'credit' => 0, 'created_by' => $admin->id]);
        $b = app(OpeningBuilder::class);
        $first = $b->post($admin);

        $line->update(['debit' => 400]);
        $second = $b->post($admin);

        $this->assertSame(1, GlEntry::where('origin', 'opening')->where('rule_key', 'golive.opening')->count());
        $this->assertNull(GlEntry::find($first->id));
        $this->assertEquals(400, $second->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id)->debit);
        $this->assertSame(400.0, $b->differenceBalance());
    }

    public function test_rebuild_never_touches_the_opening_entry(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        GlOpeningLine::create(['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 1000, 'credit' => 0, 'created_by' => $admin->id]);
        $entry = app(OpeningBuilder::class)->post($admin);

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-07-01'), by: $admin);

        $this->assertTrue($report->ok);
        $this->assertNotNull(GlEntry::find($entry->id));
        $this->assertSame(2, GlEntry::find($entry->id)->lines()->count());
    }

    public function test_posted_entry_turns_stale_after_a_change_and_cannot_be_reversed(): void
    {
        $this->books();
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        GlOpeningLine::create(['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 1000, 'credit' => 0, 'created_by' => $admin->id]);
        $b = app(OpeningBuilder::class);
        $this->assertFalse($b->isStale(), 'nothing posted yet');
        $entry = $b->post($admin);
        $this->assertFalse($b->isStale());

        app(OpeningBalances::class)->setClient($client, 300, Carbon::parse('2026-07-31'), $admin);
        $this->assertTrue($b->isStale());
        $b->post($admin);
        $this->assertFalse($b->isStale());

        // تاريخ البداية اتحرك = القيد بتاريخ قديم
        Setting::write('gl_start_date', '2026-08-02');
        Setting::flushCache();
        $this->assertTrue($b->isStale());
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();

        $latest = GlEntry::where('rule_key', 'golive.opening')->sole();
        try {
            app(Ledger::class)->reverse($latest, $admin);
            $this->fail('the opening entry must not be reversed');
        } catch (\InvalidArgumentException) {
        }
        try {
            app(Ledger::class)->overrideAccount($latest->lines->first(), GlAccount::findKey('bank'), $admin);
            $this->fail('the opening entry lines must not be moved');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(1, GlEntry::count());
        $this->assertNull(GlEntry::find($entry->id));
    }
}
