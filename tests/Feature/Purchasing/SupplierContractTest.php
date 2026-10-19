<?php

namespace Tests\Feature\Purchasing;

use App\Models\Supplier;
use App\Models\SupplierContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierContractTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(): Supplier
    {
        return Supplier::create(['code' => Supplier::nextCode(), 'name' => 'مورد', 'name_en' => 'Sup', 'active' => true]);
    }

    private function contract(Supplier $s, string $from, string $to, bool $active = true): SupplierContract
    {
        return SupplierContract::create([
            'number' => SupplierContract::nextNumber(), 'supplier_id' => $s->id,
            'starts_on' => $from, 'ends_on' => $to, 'active' => $active,
        ]);
    }

    public function test_manager_creates_contract_with_items_and_attachment(): void
    {
        Storage::fake('public');
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();
        $p1 = $this->makeProduct(['kind' => 'raw']);
        $p2 = $this->makeProduct();

        $this->actingAs($manager)->post(route('erp.supplier_contracts.store'), [
            'supplier_id' => $s->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
            'notes' => 'x', 'attachment' => UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $c = SupplierContract::firstOrFail();
        $this->assertSame('SCON-1001', $c->number);
        $this->assertNotNull($c->attachment_path);
        Storage::disk('public')->assertExists($c->attachment_path);

        $this->actingAs($manager)->post(route('erp.supplier_contracts.items', $c), [
            'price' => [$p1->id => '12.50', $p2->id => '3', 999999 => '', ],
        ])->assertRedirect();
        $this->assertSame(2, $c->items()->count());

        // blank removes
        $this->actingAs($manager)->post(route('erp.supplier_contracts.items', $c), [
            'price' => [$p1->id => '', $p2->id => '4.00'],
        ])->assertRedirect();
        $this->assertSame(1, $c->items()->count());
        $this->assertSame('4.00', $c->items()->first()->unit_price);

        $this->actingAs($manager)->get(route('erp.supplier_contracts.show', $c))->assertOk()->assertSee('SCON-1001');
    }

    public function test_live_item_respects_validity_dates(): void
    {
        $s = $this->supplier();
        $p = $this->makeProduct();
        $c = $this->contract($s, '2026-03-01', '2026-06-30');
        $c->items()->create(['product_id' => $p->id, 'unit_price' => 5]);

        $this->assertNotNull(SupplierContract::liveItemFor($s->id, $p->id, '2026-03-01'));
        $this->assertNotNull(SupplierContract::liveItemFor($s->id, $p->id, '2026-06-30'));
        $this->assertNull(SupplierContract::liveItemFor($s->id, $p->id, '2026-02-28'));
        $this->assertNull(SupplierContract::liveItemFor($s->id, $p->id, '2026-07-01'));
        $this->assertTrue(SupplierContract::anyItemFor($s->id, $p->id));

        $c->update(['active' => false]);
        $this->assertNull(SupplierContract::liveItemFor($s->id, $p->id, '2026-04-01'));
        $this->assertTrue(SupplierContract::anyItemFor($s->id, $p->id));
        $this->assertSame('inactive', $c->fresh()->statusKey());

        // overlap: latest starts_on wins
        $c->update(['active' => true]);
        $c2 = $this->contract($s, '2026-04-01', '2026-12-31');
        $c2->items()->create(['product_id' => $p->id, 'unit_price' => 7]);
        $this->assertSame('7.00', SupplierContract::liveItemFor($s->id, $p->id, '2026-05-01')->unit_price);
        $this->assertSame('5.00', SupplierContract::liveItemFor($s->id, $p->id, '2026-03-15')->unit_price);

        $this->assertFalse(SupplierContract::anyItemFor($s->id, $this->makeProduct()->id));
        $this->assertSame('expired', $c->statusKey(\Illuminate\Support\Carbon::parse('2026-08-01')));
        $this->assertSame('future', $c->statusKey(\Illuminate\Support\Carbon::parse('2026-01-01')));
        $this->assertSame('live', $c->statusKey(\Illuminate\Support\Carbon::parse('2026-05-01')));
    }

    public function test_ends_before_starts_is_rejected(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $s = $this->supplier();

        $this->actingAs($manager)->post(route('erp.supplier_contracts.store'), [
            'supplier_id' => $s->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-04-01',
        ])->assertSessionHasErrors('ends_on');
        $this->assertSame(0, SupplierContract::count());
    }

    public function test_list_filters_and_exports_csv(): void
    {
        $manager = $this->makeAdmin(['role' => 'manager']);
        $a = $this->supplier();
        $b = $this->supplier();
        $ca = $this->contract($a, '2026-01-01', '2026-12-31');
        $cb = $this->contract($b, '2026-01-01', '2026-12-31');

        $this->actingAs($manager)->get(route('erp.supplier_contracts', ['supplier' => $a->id, 'from' => '2026-01-01', 'to' => '2026-12-31']))
            ->assertOk()->assertSee($ca->number)->assertDontSee($cb->number);

        $r = $this->actingAs($manager)->get(route('erp.supplier_contracts', ['export' => 1, 'supplier' => $a->id, 'from' => '2026-01-01', 'to' => '2026-12-31']));
        $r->assertOk();
        $this->assertStringContainsString('text/csv', $r->headers->get('content-type'));
        $this->assertStringContainsString($ca->number, $r->streamedContent());
    }

    public function test_accountant_and_keeper_get_403(): void
    {
        $s = $this->supplier();
        $c = $this->contract($s, '2026-01-01', '2026-12-31');

        foreach (['accountant', 'warehouse_keeper'] as $role) {
            $u = $this->makeAdmin(['role' => $role]);
            $this->actingAs($u)->get(route('erp.supplier_contracts'))->assertForbidden();
            $this->actingAs($u)->get(route('erp.supplier_contracts.show', $c))->assertForbidden();
            $this->actingAs($u)->post(route('erp.supplier_contracts.store'), [])->assertForbidden();
            $this->actingAs($u)->put(route('erp.supplier_contracts.update', $c), [])->assertForbidden();
            $this->actingAs($u)->post(route('erp.supplier_contracts.items', $c), [])->assertForbidden();
        }
    }
}
