<?php

namespace Tests\Feature\Reprice;

use App\Models\PriceChangeDoc;
use App\Models\PriceChangeRun;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** هيكل جداول تعديل الأسعار + مفاتيح الصلاحية (Lane 13 — المهمة ١). */
class PriceChangeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function run_(array $attrs = []): PriceChangeRun
    {
        return PriceChangeRun::create($attrs + [
            'trigger' => 'list', 'status' => 'draft', 'scope' => 'list', 'payload' => ['x' => 1],
        ]);
    }

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('price_change_runs'));
        $this->assertTrue(Schema::hasTable('price_change_docs'));
        $this->assertTrue(Schema::hasColumns('invoices', ['repriced_at', 'repriced_by', 'price_change_run_id']));
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'price_change_run_id'));
    }

    public function test_run_number_sequence(): void
    {
        $this->assertSame('PCR-1001', $this->run_()->number);
        $this->assertSame('PCR-1002', $this->run_()->number);
    }

    public function test_models_labels_and_relations(): void
    {
        $run = $this->run_();
        $this->assertTrue($run->isDraft());
        $this->assertSame(__('reprice.trigger_list'), $run->triggerLabel());
        $this->assertSame(__('reprice.status_draft'), $run->statusLabel());

        $doc = PriceChangeDoc::create([
            'run_id' => $run->id, 'doc_type' => 'po', 'doc_id' => 999999, 'number' => 'PO-X',
            'outcome' => 'skipped', 'reason' => 'po_delivered',
        ]);
        $this->assertSame(__('reprice.skip_po_delivered', ['detail' => null]), $doc->reasonLabel());
        $this->assertNull($doc->document());
        $this->assertTrue($run->docs()->first()->is($doc));
        $this->assertTrue($doc->run->is($run));
    }

    public function test_propagate_action_is_admin_only(): void
    {
        $admin = $this->makeAdmin();
        $manager = $this->makeAdmin(['role' => 'manager']);
        $branch = $this->makeAdmin(['role' => 'branch_manager']);
        $accountant = $this->makeAdmin(['role' => 'accountant']);
        $sales = $this->makeAdmin(['role' => 'sales_agent']);

        foreach (['erp.prices.change.apply', 'erp.prices.change.resync'] as $route) {
            $this->assertTrue(Access::allows($admin, $route));
            $this->assertFalse(Access::allows($manager, $route), "manager $route");
            $this->assertFalse(Access::allows($branch, $route), "branch $route");
        }

        // المدير لسه يقدر يحفظ للبيع الجديد بس ويشوف صفحة المعاينة
        $this->assertTrue(Access::allows($manager, 'erp.prices.change'));
        $this->assertTrue(Access::allows($manager, 'erp.prices.change.master'));
        $this->assertFalse(Access::action($manager, 'act.prices.propagate'));
        $this->assertTrue(Access::action($admin, 'act.prices.propagate'));

        $this->assertTrue(Access::allows($admin, 'erp.price_changes'));
        $this->assertTrue(Access::allows($accountant, 'erp.price_changes'));
        $this->assertFalse(Access::allows($sales, 'erp.price_changes'));
        $this->assertFalse(Access::allows($manager, 'erp.price_changes'));
    }
}
