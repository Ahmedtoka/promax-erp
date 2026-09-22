<?php

namespace Tests\Feature;

use App\Models\Gl\CoaDraftAccount;
use App\Services\Gl\CoaDraftImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مسودة شجرة حسابات العميل (٢٢/٩/٢٠٢٦): الملف بينزل **زي ما هو** — بالتكرار
 * وبالحسابات اللي من غير كود ومن غير أي ربط — والتعديل من الشاشة.
 */
class CoaDraftTest extends TestCase
{
    use RefreshDatabase;

    private function file(): string
    {
        $path = storage_path('framework/testing/coa-'.uniqid().'.csv');
        @mkdir(dirname($path), 0777, true);

        // نفس شكل تقرير QuickBooks: أعمدة فاضية بين الأعمدة، صف مكرر، حساب من غير كود،
        // وحساب كوده بيقول إنه فرعي بس مساره في الملف جذر
        file_put_contents($path, implode("\n", [
            ',,Account,,Type,,Balance Total,,Description',
            ',,11 CURRENT ASSETS,,Bank,,1000',
            ',,11 CURRENT ASSETS:111 CASH,,Bank,,1000',
            ',,11 CURRENT ASSETS:111 CASH:1111  Cash on hand,,Bank,,400',
            ',,11 CURRENT ASSETS:111 CASH:1111  Cash on hand,,Bank,,400',
            ',,1122 Receivable Other,,Other Current Asset,,50',
            ',,41 REVENUE,,Income,,',
            ',,41 REVENUE:4111 Cash Vans,,Income,,',
            ',,41 REVENUE:4112 Key Accounts,,Income,,',
            ',,Sales Discounts,,Income,,',
        ]));

        return $path;
    }

    public function test_the_file_loads_exactly_as_it_is(): void
    {
        $s = CoaDraftImporter::import($this->file(), false);

        $this->assertSame(['rows' => 9, 'roots' => 4, 'no_code' => 1, 'duplicates' => 1], $s);

        // الصف المكرر نزل مرتين تحت نفس الأب
        $this->assertSame(2, CoaDraftAccount::where('code', '1111')->count());
        $cash = CoaDraftAccount::where('code', '111')->first();
        $this->assertSame([$cash->id, $cash->id], CoaDraftAccount::where('code', '1111')->pluck('parent_id')->all());

        // مفيش «تصحيح»: 1122 جذر زي الملف، والحساب اللي من غير كود فضل من غير كود
        $this->assertNull(CoaDraftAccount::where('code', '1122')->first()->parent_id);
        $this->assertNull(CoaDraftAccount::where('name', 'Sales Discounts')->first()->code);
        $this->assertSame('Other Current Asset', CoaDraftAccount::where('code', '1122')->first()->qb_type);
        $this->assertSame('400.00', CoaDraftAccount::where('code', '1111')->first()->balance);

        // الربط الوحيد
        $this->assertSame('sales_van', CoaDraftAccount::where('code', '4111')->first()->feed);
        $this->assertSame('sales_ka', CoaDraftAccount::where('code', '4112')->first()->feed);
        $this->assertSame(2, CoaDraftAccount::whereNotNull('feed')->count());

        // مفيش أي حاجة اتكتبت في شجرة النظام
        $this->assertSame(0, \App\Models\Gl\GlAccount::count());
    }

    public function test_the_owner_edits_reorders_moves_and_deletes(): void
    {
        CoaDraftImporter::import($this->file(), false);
        $admin = $this->makeAdmin();
        $vans = CoaDraftAccount::where('code', '4111')->first();
        $ka = CoaDraftAccount::where('code', '4112')->first();

        $this->actingAs($admin)->get(route('gl.coa'))->assertOk()->assertSee('Cash Vans');

        // تعديل خانة
        $this->actingAs($admin)->postJson(route('gl.coa.update', $vans), ['field' => 'name_ar', 'value' => 'كاش فان'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('كاش فان', $vans->fresh()->name_ar);

        $this->actingAs($admin)->postJson(route('gl.coa.update', $vans), ['field' => 'name', 'value' => ''])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('gl.coa.update', $vans), ['field' => 'balance', 'value' => '9'])->assertStatus(422);

        // الترتيب: كي أكاونت يطلع فوق كاش فان
        $this->actingAs($admin)->post(route('gl.coa.move', $ka), ['dir' => 'up'])->assertRedirect();
        $this->assertTrue($ka->fresh()->sort < $vans->fresh()->sort);

        // النقل: ماينفعش تحت نفسه أو تحت ولاده، وينفع تحت حساب تاني
        $rev = CoaDraftAccount::where('code', '41')->first();
        $this->actingAs($admin)->post(route('gl.coa.move', $rev), ['dir' => 'parent', 'parent_id' => $ka->id])
            ->assertSessionHasErrors('parent_id');
        $disc = CoaDraftAccount::where('name', 'Sales Discounts')->first();
        $this->actingAs($admin)->post(route('gl.coa.move', $disc), ['dir' => 'parent', 'parent_id' => $rev->id])->assertRedirect();
        $this->assertSame($rev->id, $disc->fresh()->parent_id);

        // المسح بيطلّع الولاد لفوق ومابيمسحهمش
        $this->actingAs($admin)->delete(route('gl.coa.destroy', $rev))->assertRedirect();
        $this->assertNull($vans->fresh()->parent_id);
        $this->assertSame(8, CoaDraftAccount::count());

        // إضافة + تصدير
        $this->actingAs($admin)->post(route('gl.coa.store'), ['name' => 'New root', 'code' => '9'])->assertRedirect();
        $this->actingAs($admin)->get(route('gl.coa', ['export' => 1]))->assertOk();
    }

    public function test_only_accounting_roles_reach_it(): void
    {
        $this->actingAs($this->makeAdmin(['role' => 'accountant']))->get(route('gl.coa'))->assertOk();
        $this->actingAs($this->makeAdmin(['role' => 'manager']))->get(route('gl.coa'))->assertForbidden();
    }
}
