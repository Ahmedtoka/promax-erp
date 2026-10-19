<?php

namespace Tests\Feature\Mfg;

use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مواصفات المنتجات (التصنيع، الخط ٦ — ١٩/١٠/٢٠٢٦، spec §3.5).
 *
 * ⚠️ المعتمدة ماتتعدلش — أي تعديل إصدار جديد، والاعتماد أدمن بس.
 */
class ProductSpecTest extends TestCase
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

    private function spec(Product $p, array $attrs = []): ProductSpec
    {
        return ProductSpec::create(array_merge([
            'product_id' => $p->id, 'version' => 1, 'status' => 'draft',
            'net_weight_g' => 50, 'shelf_life_days' => 180,
        ], $attrs));
    }

    public function test_rnd_creates_draft_and_submits(): void
    {
        $rnd = $this->user('rnd');
        $p = $this->finished();

        $this->actingAs($rnd)->post(route('mfg.specs.store'), ['product_id' => $p->id])
            ->assertSessionHasNoErrors()->assertRedirect();

        $spec = ProductSpec::query()->where('product_id', $p->id)->firstOrFail();
        $this->assertSame('draft', $spec->status);
        $this->assertSame(1, $spec->version);
        $this->assertSame($rnd->id, $spec->created_by);

        $this->actingAs($rnd)->post(route('mfg.specs.update', $spec), [
            'net_weight_g' => '45.5',
            'shelf_life_days' => '270',
            'nutrition' => ['energy_kcal' => '520', 'protein_g' => '6.2', 'bogus' => '1'],
            'qc_limits' => [
                ['param' => 'Moisture', 'min' => '1', 'max' => '3', 'unit' => '%'],
                ['param' => '', 'min' => '', 'max' => '', 'unit' => ''],
            ],
            'notes' => 'first draft',
        ])->assertSessionHasNoErrors();

        $spec->refresh();
        $this->assertEqualsWithDelta(45.5, (float) $spec->net_weight_g, 0.001);
        $this->assertSame(270, $spec->shelf_life_days);
        $this->assertEquals(['energy_kcal' => 520, 'protein_g' => 6.2], $spec->nutrition);
        $this->assertEquals([['param' => 'Moisture', 'min' => 1, 'max' => 3, 'unit' => '%']], $spec->qc_limits);

        $this->actingAs($rnd)->post(route('mfg.specs.submit', $spec))->assertSessionHasNoErrors();
        $spec->refresh();
        $this->assertSame('under_review', $spec->status);
        $this->assertNotNull($spec->submitted_at);

        $this->actingAs($rnd)->get(route('mfg.specs.show', $spec))->assertOk()->assertSee('Moisture');
    }

    public function test_submit_without_shelf_life_is_refused(): void
    {
        $spec = $this->spec($this->finished(), ['shelf_life_days' => null]);

        $this->actingAs($this->user('rnd'))->post(route('mfg.specs.submit', $spec))
            ->assertSessionHasErrors();

        $this->assertSame('draft', $spec->fresh()->status);
    }

    public function test_admin_approval_archives_previous_approved(): void
    {
        $p = $this->finished();
        $admin = $this->makeAdmin();
        $v1 = $this->spec($p, ['status' => 'under_review']);

        $this->actingAs($admin)->post(route('mfg.specs.approve', $v1))->assertSessionHasNoErrors();
        $this->assertSame('approved', $v1->fresh()->status);

        // إصدار جديد منسوخ من المعتمد — والإنتاج لسه على v1 لحد ما v2 تتعتمد
        $this->actingAs($this->user('rnd'))->post(route('mfg.specs.store'), ['product_id' => $p->id, 'copy_from' => $v1->id])
            ->assertSessionHasNoErrors();
        $v2 = ProductSpec::query()->where('product_id', $p->id)->where('version', 2)->firstOrFail();
        $this->assertSame('draft', $v2->status);
        $this->assertSame(180, $v2->shelf_life_days);
        $this->assertSame($v1->id, ProductSpec::approvedFor($p)->id);

        $this->actingAs($this->user('rnd'))->post(route('mfg.specs.submit', $v2))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('mfg.specs.approve', $v2))->assertSessionHasNoErrors();

        $this->assertSame('archived', $v1->fresh()->status);
        $this->assertSame('approved', $v2->fresh()->status);
        $this->assertSame($admin->id, $v2->fresh()->approved_by);
        $this->assertSame($v2->id, ProductSpec::approvedFor($p)->id);

        // اعتماد تاني لنفس الإصدار = حالة غلط
        $this->actingAs($admin)->post(route('mfg.specs.approve', $v2))->assertSessionHasErrors();
    }

    public function test_send_back_returns_to_draft(): void
    {
        $spec = $this->spec($this->finished(), ['status' => 'under_review', 'submitted_at' => now()]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('mfg.specs.show', $spec))
            ->assertOk()->assertSee(route('mfg.specs.approve', $spec))->assertSee(route('mfg.specs.send_back', $spec));
        $this->actingAs($this->user('rnd'))->get(route('mfg.specs.show', $spec))
            ->assertOk()->assertDontSee(route('mfg.specs.approve', $spec));

        $this->actingAs($admin)->post(route('mfg.specs.send_back', $spec), ['note' => 'fix sodium'])
            ->assertSessionHasNoErrors();

        $this->assertSame('draft', $spec->fresh()->status);
        $this->assertNull($spec->fresh()->submitted_at);
    }

    public function test_approved_spec_cannot_be_updated(): void
    {
        $spec = $this->spec($this->finished(), ['status' => 'approved']);

        $this->actingAs($this->user('rnd'))->post(route('mfg.specs.update', $spec), ['shelf_life_days' => 10])
            ->assertSessionHasErrors();

        $this->assertSame(180, $spec->fresh()->shelf_life_days);
    }

    public function test_quality_can_view_but_not_edit(): void
    {
        $spec = $this->spec($this->finished());
        $quality = $this->user('quality');

        $this->actingAs($quality)->get(route('mfg.specs'))->assertOk();
        $this->actingAs($quality)->get(route('mfg.specs.show', $spec))->assertOk();
        $this->actingAs($quality)->post(route('mfg.specs.update', $spec), ['shelf_life_days' => 10])->assertForbidden();
        $this->actingAs($quality)->post(route('mfg.specs.store'), ['product_id' => $spec->product_id])->assertForbidden();

        $this->assertSame(180, $spec->fresh()->shelf_life_days);
    }

    public function test_rnd_cannot_approve(): void
    {
        $spec = $this->spec($this->finished(), ['status' => 'under_review']);
        $rnd = $this->user('rnd');

        $this->actingAs($rnd)->post(route('mfg.specs.approve', $spec))->assertForbidden();
        $this->actingAs($rnd)->post(route('mfg.specs.send_back', $spec))->assertForbidden();

        $this->assertSame('under_review', $spec->fresh()->status);
    }

    public function test_attachment_upload_and_download(): void
    {
        Storage::fake('local');
        $spec = $this->spec($this->finished());
        $rnd = $this->user('rnd');

        $this->actingAs($rnd)->post(route('mfg.specs.update', $spec), [
            'attachment' => UploadedFile::fake()->create('label.pdf', 200, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $spec->refresh();
        $this->assertSame('label.pdf', $spec->attachment_name);
        $this->assertStringStartsWith('mfg/specs/', $spec->attachment_path);
        Storage::disk('local')->assertExists($spec->attachment_path);

        $this->actingAs($this->user('quality'))->get(route('mfg.specs.file', $spec))
            ->assertOk()->assertDownload('label.pdf');

        // نوع مش مسموح
        $this->actingAs($rnd)->post(route('mfg.specs.update', $spec), [
            'attachment' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('attachment');
    }

    public function test_specs_list_export_is_csv(): void
    {
        $p = $this->finished();
        $this->spec($p);

        $res = $this->actingAs($this->user('production_manager'))->get(route('mfg.specs', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('Test product', $res->streamedContent());
    }

    public function test_raw_product_cannot_get_a_spec(): void
    {
        $raw = $this->makeProduct(['kind' => 'raw']);

        $this->actingAs($this->user('rnd'))->post(route('mfg.specs.store'), ['product_id' => $raw->id])
            ->assertSessionHasErrors();

        $this->assertSame(0, ProductSpec::query()->where('product_id', $raw->id)->count());
    }
}
