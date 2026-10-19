<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\CoaDraftAccount;
use App\Models\Gl\GlAccount;
use App\Models\Setting;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\CoaHasRed;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اعتماد شجرة المحاسب في `gl_accounts`: هيكل بس (مفيش قيود)، ممنوع طول ما فيه أحمر،
 * والإعادة بتحدّث مش بتكرر، وكود حساب نظام متصادم بيتنقل لـ`S-<code>`.
 */
class CoaAdoptTest extends TestCase
{
    use RefreshDatabase;

    private int $sort = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
    }

    private function row(array $attrs = []): CoaDraftAccount
    {
        return CoaDraftAccount::create(array_merge([
            'parent_id' => null, 'sort' => ++$this->sort, 'code' => (string) (7000 + $this->sort),
            'name' => 'Account '.$this->sort, 'name_ar' => 'حساب '.$this->sort,
            'qb_type' => 'Bank', 'balance' => 10,
        ], $attrs));
    }

    public function test_adopt_refused_while_red_exists(): void
    {
        $this->row(['code' => '7777']);
        $this->row(['code' => '7777']);

        try {
            CoaAdopter::adopt($this->makeAdmin());
            $this->fail('CoaHasRed expected');
        } catch (CoaHasRed $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(0, GlAccount::whereNotNull('coa_draft_id')->count());
        $this->assertFalse(CoaAdopter::adopted());
    }

    public function test_adopt_creates_accounts_with_types_parents_and_postable_leaves(): void
    {
        $admin = $this->makeAdmin();
        $bank = $this->row(['code' => '7100', 'name' => 'BANKS', 'name_ar' => 'البنوك', 'balance' => 0]);
        $a = $this->row(['parent_id' => $bank->id, 'code' => '7101', 'qb_type' => null, 'name' => 'CIB']);
        $b = $this->row(['parent_id' => $bank->id, 'code' => '7102', 'qb_type' => null, 'name' => 'QNB', 'name_ar' => null]);

        $r = CoaAdopter::adopt($admin);
        $this->assertSame(3, $r['created']);
        $this->assertSame(0, $r['updated']);

        $root = GlAccount::where('code', '1')->whereNull('parent_id')->firstOrFail();
        $p = GlAccount::where('coa_draft_id', $bank->id)->firstOrFail();
        $this->assertSame($root->id, $p->parent_id);
        $this->assertFalse($p->is_postable);
        $this->assertSame('asset', $p->type);
        $this->assertSame('البنوك', $p->name);
        $this->assertSame('BANKS', $p->name_en);
        $this->assertFalse($p->is_system);

        foreach ([$a, $b] as $d) {
            $g = GlAccount::where('coa_draft_id', $d->id)->firstOrFail();
            $this->assertSame($p->id, $g->parent_id);
            $this->assertTrue($g->is_postable);
            $this->assertTrue($g->active);
            $this->assertSame('asset', $g->type);
            $this->assertSame('debit', $g->normal_side);
        }
        // من غير اسم عربي ⇒ الاسم الإنجليزي
        $this->assertSame('QNB', GlAccount::where('coa_draft_id', $b->id)->value('name'));

        $this->assertTrue(CoaAdopter::adopted());
        $this->assertNotNull(Setting::read('gl_coa_adopted_at'));
        $this->assertSame((string) $admin->id, Setting::read('gl_coa_adopted_by'));
    }

    public function test_adopt_twice_updates_not_duplicates(): void
    {
        $admin = $this->makeAdmin();
        $inc = $this->row(['code' => '7400', 'qb_type' => 'Income', 'name' => 'SALES']);
        $gone = $this->row(['code' => '7500', 'qb_type' => 'Expense', 'name' => 'OLD']);
        CoaAdopter::adopt($admin);
        $id = GlAccount::where('coa_draft_id', $inc->id)->value('id');
        $goneId = GlAccount::where('coa_draft_id', $gone->id)->value('id');
        $count = GlAccount::count();

        $inc->update(['name_ar' => 'المبيعات الجديدة']);
        $gone->delete();
        $r = CoaAdopter::adopt($admin);

        $this->assertSame(0, $r['created']);
        $this->assertSame(1, $r['updated']);
        $this->assertSame(1, $r['deactivated']);
        $this->assertSame($count, GlAccount::count());
        $g = GlAccount::findOrFail($id);
        $this->assertSame('المبيعات الجديدة', $g->name);
        $this->assertSame('revenue', $g->type);
        $this->assertSame('credit', $g->normal_side);
        $this->assertSame(GlAccount::where('code', '4')->value('id'), $g->parent_id);
        $this->assertFalse(GlAccount::findOrFail($goneId)->active);
    }

    public function test_colliding_seeded_code_is_recoded(): void
    {
        $seeded = GlAccount::where('code', '1101')->firstOrFail();
        $d = $this->row(['code' => '1101', 'name' => 'Cash on hand']);

        $r = CoaAdopter::adopt($this->makeAdmin());

        $this->assertSame(['1101'], $r['recoded']);
        $seeded->refresh();
        $this->assertSame('S-1101', $seeded->code);
        $this->assertSame('cash_main', $seeded->system_key);
        $this->assertSame($d->id, GlAccount::where('code', '1101')->value('coa_draft_id'));

        // إعادة تشغيل السيدر بعد الاعتماد مابتلمسش الحساب المعتمد ولا بتكرر حساب النظام
        $this->seed(GlSeeder::class);
        $this->assertSame($d->id, GlAccount::where('code', '1101')->value('coa_draft_id'));
        $this->assertFalse(GlAccount::where('code', '1101')->value('is_system'));
        $this->assertSame('S-1101', GlAccount::where('system_key', 'cash_main')->value('code'));

        // اعتماد تاني: مفيش ترقيم تاني
        $this->assertSame([], CoaAdopter::adopt($this->makeAdmin())['recoded']);
    }

    public function test_route_is_admin_only_and_redirects_with_counts(): void
    {
        $this->row(['code' => '7200']);

        $this->actingAs($this->makeAdmin(['role' => 'accountant']))
            ->post(route('gl.coa.adopt'))->assertForbidden();
        $this->assertSame(0, GlAccount::whereNotNull('coa_draft_id')->count());

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post(route('gl.coa.adopt'))
            ->assertRedirect(route('gl.coa'))
            ->assertSessionHas('ok', __('coa.adopted_ok', ['created' => 1, 'updated' => 0, 'deactivated' => 0, 'recoded' => 0]));
        $this->assertSame(1, GlAccount::whereNotNull('coa_draft_id')->count());

        // بعد الاعتماد الشاشة بتعرض وقت الاعتماد
        $this->actingAs($admin)->get(route('gl.coa'))->assertOk()->assertSee(__('coa.adopted_at'));

        // أحمر ⇒ رجوع بخطأ من غير اعتماد
        $this->row(['code' => '7200']);
        $this->actingAs($admin)->post(route('gl.coa.adopt'))->assertSessionHasErrors('adopt');
    }

    public function test_screen_shows_adopt_button_for_admin_disabled_while_red(): void
    {
        $this->row(['code' => '7300']);
        $this->row(['code' => '7300']);

        $this->actingAs($this->makeAdmin())->get(route('gl.coa'))->assertOk()
            ->assertSee(route('gl.coa.adopt'))->assertSee(__('coa.adopt_blocked', ['n' => 2]));
        $this->actingAs($this->makeAdmin(['role' => 'accountant']))->get(route('gl.coa'))->assertOk()
            ->assertDontSee(route('gl.coa.adopt'));
    }
}
