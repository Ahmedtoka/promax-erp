<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\CoaCheckAccept;
use App\Models\Gl\CoaDraftAccount;
use App\Models\Gl\GlAccount;
use App\Services\Gl\CoaChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فحص مسودة الشجرة قبل الاعتماد: الأحمر بيوقف، والأصفر بيتقبل والقبول بيتحفظ
 * لكل (فحص × صف).
 */
class CoaChecksTest extends TestCase
{
    use RefreshDatabase;

    private int $sort = 0;

    private function row(array $attrs = []): CoaDraftAccount
    {
        return CoaDraftAccount::create(array_merge([
            'parent_id' => null, 'sort' => ++$this->sort, 'code' => (string) (1000 + $this->sort),
            'name' => 'Account '.$this->sort, 'name_ar' => 'حساب '.$this->sort,
            'qb_type' => 'Bank', 'balance' => 10,
        ], $attrs));
    }

    /** @return list<array> */
    private function itemsFor(string $check, ?int $draftId = null): array
    {
        return array_values(array_filter(CoaChecks::run()['items'],
            fn ($i) => $i['check'] === $check && ($draftId === null || $i['draft_id'] === $draftId)));
    }

    public function test_duplicate_code_is_red_on_both_rows(): void
    {
        $a = $this->row(['code' => '11']);
        $b = $this->row(['code' => '11']);
        $this->row(['code' => '12']);

        $items = $this->itemsFor('dup_code');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($items, 'draft_id'));
        $this->assertSame('red', $items[0]['level']);
        $this->assertTrue(CoaChecks::hasRed());
    }

    public function test_account_without_code_is_red(): void
    {
        $a = $this->row(['code' => null]);
        $this->row();

        $items = $this->itemsFor('no_code');
        $this->assertSame([$a->id], array_column($items, 'draft_id'));
        $this->assertSame('red', $items[0]['level']);
    }

    public function test_parent_whose_total_equals_its_children_is_not_red(): void
    {
        // QuickBooks «Balance Total» على الأب شامل ولاده — رصيده نفسه صفر
        $parent = $this->row(['balance' => 10]);
        $this->row(['parent_id' => $parent->id, 'balance' => 10]);

        $this->assertSame([], $this->itemsFor('parent_balance'));
    }

    public function test_parent_with_file_balance_is_red(): void
    {
        $parent = $this->row(['balance' => 100]);
        $this->row(['parent_id' => $parent->id]);
        $quiet = $this->row(['balance' => 0]);
        $this->row(['parent_id' => $quiet->id]);

        $this->assertSame([$parent->id], array_column($this->itemsFor('parent_balance'), 'draft_id'));

        // حساب تجميعي اتعتمد وعليه سطور قيود = أحمر برضه
        $gl = GlAccount::create([
            'code' => $quiet->code, 'name' => 'x', 'type' => 'asset', 'normal_side' => 'debit',
            'is_postable' => false, 'active' => true, 'coa_draft_id' => $quiet->id,
        ]);
        $entry = DB::table('gl_entries')->insertGetId([
            'number' => 'T-1', 'date' => '2026-10-01', 'period_key' => '2026-10', 'memo' => 't', 'origin' => 'manual',
        ]);
        DB::table('gl_lines')->insert(['entry_id' => $entry, 'account_id' => $gl->id, 'debit' => 5, 'credit' => 0]);

        $this->assertEqualsCanonicalizing([$parent->id, $quiet->id], array_column($this->itemsFor('parent_balance'), 'draft_id'));
    }

    public function test_unknown_type_without_typed_ancestor_is_red_and_typed_ancestor_clears_it(): void
    {
        $weird = $this->row(['qb_type' => 'Something Else']);
        $bank = $this->row(['qb_type' => ' bank ']);
        $child = $this->row(['parent_id' => $bank->id, 'qb_type' => null]);

        $this->assertSame([$weird->id], array_column($this->itemsFor('no_type'), 'draft_id'));

        $all = CoaDraftAccount::all()->keyBy('id');
        $this->assertSame('asset', CoaChecks::typeFor($child, $all));
        $this->assertNull(CoaChecks::typeFor($weird, $all));
        $this->assertSame('liability', CoaChecks::typeFor($this->row(['qb_type' => 'Credit Card']), CoaDraftAccount::all()->keyBy('id')));
    }

    public function test_code_used_by_a_non_system_gl_account_is_red(): void
    {
        $taken = $this->row(['code' => '7001']);
        $own = $this->row(['code' => '7002']);
        $sys = $this->row(['code' => '7003']);
        GlAccount::create(['code' => '7001', 'name' => 'a', 'type' => 'asset', 'normal_side' => 'debit']);
        GlAccount::create(['code' => '7002', 'name' => 'b', 'type' => 'asset', 'normal_side' => 'debit', 'coa_draft_id' => $own->id]);
        GlAccount::create(['code' => '7003', 'name' => 'c', 'type' => 'asset', 'normal_side' => 'debit', 'is_system' => true]);

        $this->assertSame([$taken->id], array_column($this->itemsFor('code_taken'), 'draft_id'));
        $this->assertNotContains($sys->id, array_column($this->itemsFor('code_taken'), 'draft_id'));
    }

    private function lineOn(int $accountId): void
    {
        $entry = DB::table('gl_entries')->insertGetId([
            'number' => 'T-'.$accountId, 'date' => '2026-10-01', 'period_key' => '2026-10', 'memo' => 't', 'origin' => 'manual',
        ]);
        DB::table('gl_lines')->insert(['entry_id' => $entry, 'account_id' => $accountId, 'debit' => 5, 'credit' => 0]);
    }

    public function test_duplicate_code_ignores_case_and_spaces(): void
    {
        $a = $this->row(['code' => 'ab-1']);
        $b = $this->row(['code' => ' AB-1 ']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($this->itemsFor('dup_code'), 'draft_id'));
    }

    public function test_adopted_account_with_lines_whose_type_changes_is_red(): void
    {
        $used = $this->row(['qb_type' => 'Expense']);
        $quiet = $this->row(['qb_type' => 'Expense']);
        $same = $this->row(['qb_type' => 'Bank']);
        foreach ([$used, $quiet, $same] as $d) {
            $g = GlAccount::create(['code' => $d->code, 'name' => 'x', 'type' => 'asset', 'normal_side' => 'debit',
                'is_postable' => true, 'active' => true, 'coa_draft_id' => $d->id]);
            if ($d->isNot($quiet)) {
                $this->lineOn($g->id);
            }
        }

        $items = $this->itemsFor('type_changed');
        $this->assertSame([$used->id], array_column($items, 'draft_id'));
        $this->assertSame('red', $items[0]['level']);
        $this->assertNotSame('coa.check_type_changed', __('coa.check_type_changed'));
        $this->assertNotSame('coa.check_type_changed_hint', __('coa.check_type_changed_hint'));
    }

    public function test_adopted_leaf_with_lines_that_gains_a_child_is_red(): void
    {
        $leaf = $this->row(['balance' => 0]);
        $g = GlAccount::create(['code' => $leaf->code, 'name' => 'x', 'type' => 'asset', 'normal_side' => 'debit',
            'is_postable' => true, 'active' => true, 'coa_draft_id' => $leaf->id]);
        $this->lineOn($g->id);
        $this->assertSame([], $this->itemsFor('parent_balance'));

        $this->row(['parent_id' => $leaf->id]);
        $this->assertSame([$leaf->id], array_column($this->itemsFor('parent_balance'), 'draft_id'));
    }

    public function test_code_taken_ignores_orphans_without_lines(): void
    {
        $free = $this->row(['code' => '7101']);
        $held = $this->row(['code' => '7102']);
        // حسابات صفها اتمسح من المسودة بعد الاعتماد (coa_draft_id مش موجود في المسودة)
        GlAccount::create(['code' => '7101', 'name' => 'a', 'type' => 'asset', 'normal_side' => 'debit', 'coa_draft_id' => 99901]);
        $used = GlAccount::create(['code' => '7102', 'name' => 'b', 'type' => 'asset', 'normal_side' => 'debit', 'coa_draft_id' => 99902]);
        $this->lineOn($used->id);

        $this->assertSame([$held->id], array_column($this->itemsFor('code_taken'), 'draft_id'));
        $this->assertNotContains($free->id, array_column($this->itemsFor('code_taken'), 'draft_id'));
    }

    public function test_missing_arabic_is_yellow_and_accept_is_saved(): void
    {
        $a = $this->row(['name_ar' => null]);
        $admin = $this->makeAdmin();

        $before = CoaChecks::run();
        $item = $this->itemsFor('no_arabic', $a->id)[0];
        $this->assertSame('yellow', $item['level']);
        $this->assertFalse($item['accepted']);

        CoaChecks::accept('no_arabic', $a->id, $admin);

        $after = CoaChecks::run();
        $this->assertSame($before['yellow'] - 1, $after['yellow']);
        $this->assertSame($before['accepted'] + 1, $after['accepted']);
        $this->assertTrue($this->itemsFor('no_arabic', $a->id)[0]['accepted']);
        $this->assertSame(1, CoaCheckAccept::where('check', 'no_arabic')->where('draft_account_id', $a->id)->count());

        // قبول تاني لنفس الصف مابيكررش
        CoaChecks::accept('no_arabic', $a->id, $admin);
        $this->assertSame(1, CoaCheckAccept::count());
    }

    public function test_red_cannot_be_accepted(): void
    {
        $a = $this->row(['code' => null]);

        $this->expectException(\InvalidArgumentException::class);
        CoaChecks::accept('no_code', $a->id, $this->makeAdmin());
    }

    public function test_per_product_and_truncated_and_unused_are_yellow(): void
    {
        $this->makeProduct(['code' => 'CHOCO1', 'name_en' => 'Choco Bar']);
        $prod = $this->row(['name' => ' choco bar ', 'qb_type' => 'Income']);
        $cut = $this->row(['name' => 'Accumulated Depreciation Equipm']);   // ٣١ حرف، QuickBooks قصّه
        $dots = $this->row(['name' => 'Long name...']);
        $unused = $this->row(['balance' => null]);
        $zero = $this->row(['balance' => 0]);
        $group = $this->row(['balance' => null]);
        $this->row(['parent_id' => $group->id]);

        $this->assertContains($prod->id, array_column($this->itemsFor('per_product'), 'draft_id'));
        $this->assertCount(1, $this->itemsFor('per_product'));
        $this->assertEqualsCanonicalizing([$cut->id, $dots->id], array_column($this->itemsFor('truncated_name'), 'draft_id'));
        $this->assertEqualsCanonicalizing([$unused->id, $zero->id], array_column($this->itemsFor('unused'), 'draft_id'));
        foreach (['per_product', 'truncated_name', 'unused'] as $c) {
            $this->assertSame('yellow', $this->itemsFor($c)[0]['level']);
        }
        $this->assertFalse(CoaChecks::hasRed());

        $n = CoaChecks::acceptAll('unused', $this->makeAdmin());
        $this->assertSame(2, $n);
        $this->assertTrue(collect($this->itemsFor('unused'))->every(fn ($i) => $i['accepted']));
    }

    public function test_accept_route_is_admin_only(): void
    {
        $a = $this->row(['name_ar' => null]);
        $this->row(['name_ar' => null]);

        $this->actingAs($this->makeAdmin(['role' => 'accountant']))
            ->post(route('gl.coa.accept'), ['check' => 'no_arabic', 'draft_id' => $a->id])->assertForbidden();
        $this->assertSame(0, CoaCheckAccept::count());

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post(route('gl.coa.accept'), ['check' => 'no_arabic', 'draft_id' => $a->id])
            ->assertStatus(302);
        $this->assertSame(1, CoaCheckAccept::count());

        $this->actingAs($admin)->post(route('gl.coa.accept'), ['check' => 'no_arabic', 'all' => 1])->assertStatus(302);
        $this->assertSame(2, CoaCheckAccept::count());

        $this->actingAs($admin)->post(route('gl.coa.accept'), ['check' => 'no_code', 'all' => 1])
            ->assertSessionHasErrors('check');
    }

    public function test_coa_screen_shows_the_checks_panel(): void
    {
        $this->row(['code' => null]);
        $this->row(['name_ar' => null]);

        $this->actingAs($this->makeAdmin())->get(route('gl.coa'))->assertOk()
            ->assertSee(__('coa.checks_title'))->assertSee(__('coa.check_no_code'))->assertSee(__('coa.accept_all'));

        // المحاسب بيشوف الفحص من غير أزرار القبول
        $this->actingAs($this->makeAdmin(['role' => 'accountant']))->get(route('gl.coa'))->assertOk()
            ->assertSee(__('coa.checks_title'))->assertDontSee(__('coa.accept_all'));
    }
}
