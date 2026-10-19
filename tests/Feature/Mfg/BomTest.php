<?php

namespace Tests\Feature\Mfg;

use App\Exceptions\Rejected;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Product;
use App\Models\User;
use App\Services\Mfg\Boms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قوايم المواد (BOM) — إصدارات واعتماد (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦).
 *
 * ⚠️ المعتمدة ماتتعدلش — التعديل إصدار جديد، والأوامر بتاخد المعتمدة بس.
 */
class BomTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return $this->makeAdmin(['role' => $role]);
    }

    private function finished(): Product
    {
        return $this->makeProduct(['kind' => 'finished']);
    }

    private function raw(array $attrs = []): Product
    {
        return $this->makeProduct(array_merge(['kind' => 'raw', 'unit' => 'كجم'], $attrs));
    }

    private function bom(Product $p, array $attrs = []): Bom
    {
        return Bom::create(array_merge([
            'product_id' => $p->id, 'version' => 1, 'status' => 'draft', 'waste_pct' => 0,
        ], $attrs));
    }

    public function test_draft_bom_saves_lines_with_basis(): void
    {
        $rnd = $this->user('rnd');
        $p = $this->finished();
        $oats = $this->raw();
        $box = $this->makeProduct(['kind' => 'packaging']);

        $this->actingAs($rnd)->post(route('mfg.boms.store'), ['product_id' => $p->id])
            ->assertSessionHasNoErrors()->assertRedirect();

        $bom = Bom::query()->where('product_id', $p->id)->firstOrFail();
        $this->assertSame('draft', $bom->status);
        $this->assertSame(1, $bom->version);

        $this->actingAs($rnd)->post(route('mfg.boms.lines', $bom), [
            'waste_pct' => '5',
            'notes' => 'first recipe',
            'lines' => [
                ['product_id' => $oats->id, 'qty' => '0.035', 'basis' => 'per_unit'],
                ['product_id' => $box->id, 'qty' => '2', 'basis' => 'per_batch'],
            ],
        ])->assertSessionHasNoErrors();

        $bom->refresh();
        $this->assertEqualsWithDelta(5.0, (float) $bom->waste_pct, 0.001);
        $this->assertSame('first recipe', $bom->notes);
        $lines = $bom->lines;
        $this->assertCount(2, $lines);
        $this->assertSame($oats->id, $lines[0]->product_id);
        $this->assertSame('per_unit', $lines[0]->basis);
        $this->assertSame('per_batch', $lines[1]->basis);
        $this->assertEqualsWithDelta(36.750, $bom->stdFor($lines[0], 1000), 0.0001);
        $this->assertEqualsWithDelta(2.100, $bom->stdFor($lines[1], 1000), 0.0001);

        $this->actingAs($rnd)->get(route('mfg.boms.show', $bom))->assertOk()->assertSee($oats->code);
    }

    public function test_finished_product_line_is_refused(): void
    {
        $p = $this->finished();
        $bom = $this->bom($p);
        $other = $this->finished();

        $this->actingAs($this->user('rnd'))->post(route('mfg.boms.lines', $bom), [
            'waste_pct' => '0',
            'lines' => [['product_id' => $other->id, 'qty' => '1', 'basis' => 'per_unit']],
        ])->assertSessionHasErrors('bom');

        $this->assertSame(0, $bom->lines()->count());

        // نفس الخامة مرتين = مرفوض برضه
        $oats = $this->raw();
        $this->expectException(Rejected::class);
        Boms::saveLines($bom, [
            ['product_id' => $oats->id, 'qty' => 1, 'basis' => 'per_unit'],
            ['product_id' => $oats->id, 'qty' => 2, 'basis' => 'per_batch'],
        ], 0, null);
    }

    public function test_approve_archives_previous_and_orders_use_approved(): void
    {
        $admin = $this->user('admin');
        $p = $this->finished();
        $oats = $this->raw();

        $v1 = $this->bom($p);
        Boms::saveLines($v1, [['product_id' => $oats->id, 'qty' => 1, 'basis' => 'per_unit']], 0, null);
        $this->actingAs($admin)->post(route('mfg.boms.approve', $v1))->assertSessionHasNoErrors();
        $this->assertSame('approved', $v1->fresh()->status);

        $v2 = Boms::newVersion($p, $admin);
        $this->assertSame(2, $v2->version);
        // المسودة الجديدة مابتغيّرش اللي الأوامر بتستخدمه
        $this->assertSame($v1->id, Bom::approvedFor($p)->id);

        $this->actingAs($admin)->post(route('mfg.boms.approve', $v2))->assertSessionHasNoErrors();

        $this->assertSame('archived', $v1->fresh()->status);
        $this->assertSame('approved', $v2->fresh()->status);
        $this->assertSame($admin->id, $v2->fresh()->approved_by);
        $this->assertSame($v2->id, Bom::approvedFor($p)->id);
    }

    public function test_approved_bom_lines_cannot_change(): void
    {
        $p = $this->finished();
        $oats = $this->raw();
        $bom = $this->bom($p);
        Boms::saveLines($bom, [['product_id' => $oats->id, 'qty' => 1, 'basis' => 'per_unit']], 0, null);
        Boms::approve($bom, $this->user('admin'));

        $this->actingAs($this->user('rnd'))->post(route('mfg.boms.lines', $bom), [
            'waste_pct' => '9',
            'lines' => [['product_id' => $oats->id, 'qty' => '5', 'basis' => 'per_batch']],
        ])->assertSessionHasErrors('bom');

        $bom->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $bom->waste_pct, 0.001);
        $this->assertCount(1, $bom->lines);
        $this->assertEqualsWithDelta(1.0, (float) $bom->lines[0]->qty, 0.0001);
        $this->assertSame('per_unit', $bom->lines[0]->basis);
    }

    public function test_empty_bom_cannot_be_approved(): void
    {
        $bom = $this->bom($this->finished());

        $this->actingAs($this->user('admin'))->post(route('mfg.boms.approve', $bom))
            ->assertSessionHasErrors('bom');

        $this->assertSame('draft', $bom->fresh()->status);
    }

    public function test_new_version_copies_lines(): void
    {
        $rnd = $this->user('rnd');
        $p = $this->finished();
        $oats = $this->raw();
        $box = $this->makeProduct(['kind' => 'packaging']);

        $v1 = $this->bom($p, ['notes' => 'v1']);
        Boms::saveLines($v1, [
            ['product_id' => $oats->id, 'qty' => 0.035, 'basis' => 'per_unit'],
            ['product_id' => $box->id, 'qty' => 2, 'basis' => 'per_batch'],
        ], 5, 'v1');
        Boms::approve($v1, $this->user('admin'));

        // مفيش copy_from = آخر إصدار
        $this->actingAs($rnd)->post(route('mfg.boms.store'), ['product_id' => $p->id])
            ->assertSessionHasNoErrors();

        $v2 = Bom::query()->where('product_id', $p->id)->where('version', 2)->firstOrFail();
        $this->assertSame('draft', $v2->status);
        $this->assertEqualsWithDelta(5.0, (float) $v2->waste_pct, 0.001);
        $this->assertSame('v1', $v2->notes);
        $this->assertSame(
            [[$oats->id, '0.0350', 'per_unit'], [$box->id, '2.0000', 'per_batch']],
            $v2->lines->map(fn (BomLine $l) => [$l->product_id, (string) $l->qty, $l->basis])->all()
        );
        // الأصل ماتلمسش
        $this->assertCount(2, $v1->fresh()->lines);

        // مسودة مفتوحة = مفيش تانية
        $this->actingAs($rnd)->post(route('mfg.boms.store'), ['product_id' => $p->id])
            ->assertSessionHasErrors('bom');
        $this->assertSame(2, Bom::query()->where('product_id', $p->id)->count());

        // الخامة مالهاش BOM
        $this->actingAs($rnd)->post(route('mfg.boms.store'), ['product_id' => $oats->id])
            ->assertSessionHasErrors('bom');
    }

    public function test_production_manager_views_but_cannot_edit(): void
    {
        $pm = $this->user('production_manager');
        $p = $this->finished();
        $oats = $this->raw();
        $bom = $this->bom($p);

        $this->actingAs($pm)->get(route('mfg.boms'))->assertOk();
        $this->actingAs($pm)->get(route('mfg.boms.show', $bom))->assertOk();

        $this->actingAs($pm)->post(route('mfg.boms.lines', $bom), [
            'waste_pct' => '0',
            'lines' => [['product_id' => $oats->id, 'qty' => '1', 'basis' => 'per_unit']],
        ])->assertForbidden();
        $this->actingAs($pm)->post(route('mfg.boms.store'), ['product_id' => $p->id])->assertForbidden();
        $this->actingAs($pm)->post(route('mfg.boms.approve', $bom))->assertForbidden();
        $this->actingAs($this->user('rnd'))->post(route('mfg.boms.approve', $bom))->assertForbidden();

        $this->assertSame(0, $bom->lines()->count());
    }

    public function test_boms_list_filters_and_exports(): void
    {
        $admin = $this->user('admin');
        $p = $this->finished();
        $q = $this->finished();
        $this->bom($p);
        $this->bom($q, ['status' => 'approved']);

        $this->actingAs($admin)->get(route('mfg.boms', ['status' => 'approved']))
            ->assertOk()
            ->assertViewHas('boms', fn ($b) => $b->total() === 1 && $b->first()->product_id === $q->id);

        $res = $this->actingAs($admin)->get(route('mfg.boms', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
    }
}
