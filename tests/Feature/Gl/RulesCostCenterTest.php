<?php

namespace Tests\Feature\Gl;

use App\Models\CostCenter;
use App\Models\Gl\GlEntry;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use App\Services\Gl\Rules;
use Database\Seeders\GlSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مركز التكلفة على كل سطر قاعدة (خطة القفل ١٩/١٠ Lane 3، المهمة ٢):
 * `Rules::linesFor` بيحط مركز المستند على أي سطر مالوش مركز.
 */
class RulesCostCenterTest extends TestCase
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

    private function entryFor(Model $source): GlEntry
    {
        $e = GlEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)
            ->where('origin', 'auto')->first();
        $this->assertNotNull($e, 'entry posted');

        return $e;
    }

    private function sale(): Transaction
    {
        $client = $this->makeClient();

        return Transaction::create(['client_id' => $client->id, 'date' => '2026-09-01', 'memo' => 'فاتورة', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);
    }

    /** نسخة من القواعد مركزها بيرجع اللي الـcallback بيرجعه (أو بيرمي) */
    private function bindRules(callable $center): void
    {
        $this->app->instance(Rules::class, new class($center) extends Rules
        {
            public function __construct(private $center) {}

            protected function costCenterFor(Model $source): ?int
            {
                return ($this->center)($source);
            }
        });
        $this->app->forgetInstance(Ledger::class);
    }

    public function test_sale_transaction_lines_carry_maadi_center(): void
    {
        $maadi = CostCenter::maadi();
        $tx = $this->sale();

        $lines = $this->entryFor($tx)->lines;
        $this->assertCount(3, $lines, 'receivables + sales + vat');
        foreach ($lines as $l) {
            $this->assertSame($maadi->id, (int) $l->cost_center_id, 'slot '.$l->slot);
        }
    }

    public function test_supplier_lines_have_no_center(): void
    {
        CostCenter::maadi();
        $sup = Supplier::create(['code' => 'SUP-1', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $st = $sup->post('invoice', today()->toDateString(), 0, 2000, 'فاتورة مورد');

        $lines = $this->entryFor($st)->lines;
        $this->assertNotEmpty($lines);
        foreach ($lines as $l) {
            $this->assertNull($l->cost_center_id);
        }
    }

    public function test_a_line_that_already_carries_a_center_keeps_it(): void
    {
        $rules = new class extends Rules
        {
            public function linesFor(Model $source): array
            {
                return $this->withCostCenter($source, ['rule' => 't', 'memo' => '', 'needs_review' => false, 'lines' => [
                    ['slot' => 'dr', 'account_id' => 1, 'debit' => 5.0, 'credit' => 0.0, 'cost_center_id' => 999],
                    ['slot' => 'cr', 'account_id' => 2, 'debit' => 0.0, 'credit' => 5.0],
                ]]);
            }
        };
        $maadi = CostCenter::maadi();
        $tx = new Transaction(['kind' => 'sale', 'debit' => 5, 'credit' => 0]);

        $lines = $rules->linesFor($tx)['lines'];
        $this->assertSame(999, $lines[0]['cost_center_id']);
        $this->assertSame($maadi->id, $lines[1]['cost_center_id']);
    }

    public function test_resolver_failure_does_not_lose_the_entry(): void
    {
        $this->bindRules(fn () => null);
        $tx = $this->sale();
        $e = $this->entryFor($tx);
        $this->assertCount(3, $e->lines);
        $this->assertTrue($e->lines->every(fn ($l) => $l->cost_center_id === null));

        $this->bindRules(function () {
            throw new \RuntimeException('resolver broke');
        });
        $tx2 = $this->sale();
        $e2 = $this->entryFor($tx2);
        $this->assertCount(3, $e2->lines);
        $this->assertSame($e2->totalDebit(), $e2->totalCredit());
        $this->assertTrue($e2->lines->every(fn ($l) => $l->cost_center_id === null));
    }
}
