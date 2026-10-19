<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Safe;
use App\Models\Setting;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lane 4 Task 2: شاشة الخزن والبنوك — سجل بحساب دفتر لكل خزنة، والرصيد
 * من الدفتر (L4-D1)، وحساب الخزنة بيتولد تحت أب `cash_main` (L4-D2).
 */
class SafesScreenTest extends TestCase
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

    private function safeWithAccount(string $code, string $kind = 'cash', array $extra = []): Safe
    {
        $safe = Safe::create(array_merge(['code' => $code, 'name' => 'خزنة '.$code, 'name_en' => 'Safe '.$code, 'kind' => $kind], $extra));
        $safe->ensureGlAccount();

        return $safe->fresh();
    }

    private function move(Safe $safe, string $date, float $in, float $out): void
    {
        $ledger = app(Ledger::class);
        $equity = GlAccount::findKey('opening_equity')->id;
        $admin = $this->makeAdmin();
        if ($in > 0) {
            $ledger->manual(Carbon::parse($date), 'داخل', [
                ['account_id' => $safe->gl_account_id, 'debit' => $in, 'credit' => 0],
                ['account_id' => $equity, 'debit' => 0, 'credit' => $in],
            ], $admin);
        }
        if ($out > 0) {
            $ledger->manual(Carbon::parse($date), 'خارج', [
                ['account_id' => $equity, 'debit' => $out, 'credit' => 0],
                ['account_id' => $safe->gl_account_id, 'debit' => 0, 'credit' => $out],
            ], $admin);
        }
    }

    public function test_admin_adds_a_safe_with_custodian_and_gets_its_own_gl_account(): void
    {
        $admin = $this->makeAdmin();
        $keeper = $this->makeAdmin(['role' => 'accountant']);

        $this->actingAs($admin)->post(route('gl.safes.store'), [
            'code' => 'MAADI-2', 'name' => 'خزنة المعادي ٢', 'name_en' => 'Maadi safe 2',
            'kind' => 'cash', 'custodian_user_id' => $keeper->id, 'active' => '1',
        ])->assertRedirect(route('gl.safes'))->assertSessionHasNoErrors();

        $safe = Safe::where('code', 'MAADI-2')->firstOrFail();
        $this->assertSame($keeper->id, $safe->custodian_user_id);
        $this->assertSame('EGP', $safe->currency);
        $this->assertNotNull($safe->gl_account_id);

        $acc = GlAccount::findOrFail($safe->gl_account_id);
        $parent = GlAccount::findKey('cash_main')->parent;
        $this->assertSame($parent->id, $acc->parent_id);
        $this->assertSame($parent->code.'.MAADI-2', $acc->code);
        $this->assertTrue((bool) $acc->is_postable);
        $this->assertSame('asset', $acc->type);
        $this->assertSame('debit', $acc->normal_side);

        // ensureGlAccount مرة تانية مابتعملش حساب جديد
        $this->assertSame($acc->id, $safe->ensureGlAccount()->id);
        $this->assertSame(1, GlAccount::where('code', $parent->code.'.MAADI-2')->count());

        // التعديل بيغيّر الاسم بس والكود ثابت
        $this->actingAs($admin)->post(route('gl.safes.update', $safe), [
            'code' => 'HACKED', 'name' => 'اسم جديد', 'kind' => 'cash', 'active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('MAADI-2', $safe->fresh()->code);
        $this->assertSame('اسم جديد', $safe->fresh()->name);
    }

    public function test_bank_needs_bank_name(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('gl.safes.store'), [
            'code' => 'BANK-9', 'name' => 'بنك', 'kind' => 'bank',
        ])->assertSessionHasErrors('bank_name');
        $this->assertSame(0, Safe::where('code', 'BANK-9')->count());

        $this->actingAs($admin)->post(route('gl.safes.store'), [
            'code' => 'BANK-9', 'name' => 'بنك', 'kind' => 'bank', 'bank_name' => 'CIB', 'account_no' => '100200300',
        ])->assertSessionHasNoErrors();
        $this->assertSame('CIB', Safe::where('code', 'BANK-9')->value('bank_name'));
    }

    public function test_balance_column_matches_safe_balance(): void
    {
        $safe = $this->safeWithAccount('MAADI-3');
        $this->move($safe, today()->toDateString(), 1234.5, 0);

        $this->assertSame(1234.5, $safe->balance());

        $this->actingAs($this->makeAdmin())->get(route('gl.safes'))
            ->assertOk()
            ->assertSee('MAADI-3')
            ->assertSee('1,234.50');
    }

    public function test_date_range_shows_in_and_out_for_the_window(): void
    {
        $safe = $this->safeWithAccount('MAADI-4');
        $this->move($safe, '2026-08-15', 500, 0);       // قبل النافذة → رصيد أول المدة
        $this->move($safe, '2026-09-05', 300, 120);     // جوه النافذة
        $this->move($safe, '2026-10-02', 77, 0);        // بعد النافذة

        $this->assertSame(['in' => 300.0, 'out' => 120.0],
            $safe->movementsBetween(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')));

        $res = $this->actingAs($this->makeAdmin())->get(route('gl.safes', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertOk();
        $rows = collect($res->viewData('rows'));
        $row = $rows->firstWhere('safe.id', $safe->id);
        $this->assertSame(500.0, $row['opening']);
        $this->assertSame(300.0, $row['in']);
        $this->assertSame(120.0, $row['out']);
        $this->assertSame(680.0, $row['balance']);
    }

    public function test_export_lists_every_safe(): void
    {
        $this->safeWithAccount('EXP-A');
        $this->safeWithAccount('EXP-B', 'bank', ['bank_name' => 'NBE']);
        Safe::create(['code' => 'EXP-C', 'name' => 'من غير حساب', 'kind' => 'cash', 'active' => false]);

        $res = $this->actingAs($this->makeAdmin())->get(route('gl.safes', ['export' => 1]))->assertOk();
        $csv = $res->streamedContent();
        foreach (['EXP-A', 'EXP-B', 'EXP-C', 'NBE'] as $needle) {
            $this->assertStringContainsString($needle, $csv);
        }

        // الفلتر بيمشي على التصدير كمان
        $bankOnly = $this->actingAs($this->makeAdmin())->get(route('gl.safes', ['export' => 1, 'kind' => 'bank']))->streamedContent();
        $this->assertStringContainsString('EXP-B', $bankOnly);
        $this->assertStringNotContainsString('EXP-A', $bankOnly);
    }

    public function test_rep_gets_403(): void
    {
        $rep = $this->makeRep();
        $this->actingAs($rep)->get(route('gl.safes'))->assertForbidden();
        $this->actingAs($rep)->post(route('gl.safes.store'), ['code' => 'X', 'name' => 'x', 'kind' => 'cash'])->assertForbidden();
        $this->assertSame(0, Safe::where('code', 'X')->count());
    }

    public function test_banner_when_ledger_is_off(): void
    {
        Setting::write('gl_enabled', '0');
        Setting::flushCache();
        $this->actingAs($this->makeAdmin())->get(route('gl.safes'))->assertOk()->assertSee(__('cash.ledger_off'));
    }

    // ═══ جولة المراجعة ١ ═══

    public function test_branch_cost_center_fallback_on_store_and_update(): void
    {
        $admin = $this->makeAdmin();
        $branch = Branch::create(['code' => 'BR-CC', 'name' => 'فرع', 'active' => true]);
        $cc = CostCenter::create(['code' => 'CC-BR', 'name' => 'مركز الفرع', 'kind' => 'branch', 'branch_id' => $branch->id, 'active' => true]);
        $other = CostCenter::create(['code' => 'CC-X', 'name' => 'مركز تاني', 'kind' => 'branch', 'active' => true]);

        $this->actingAs($admin)->post(route('gl.safes.store'), [
            'code' => 'CC-1', 'name' => 'خزنة', 'kind' => 'cash', 'branch_id' => $branch->id,
        ])->assertSessionHasNoErrors();
        $safe = Safe::where('code', 'CC-1')->firstOrFail();
        $this->assertSame($cc->id, $safe->cost_center_id);

        // مركز مختار صريح بيتحترم
        $this->actingAs($admin)->post(route('gl.safes.update', $safe), [
            'name' => 'خزنة', 'kind' => 'cash', 'branch_id' => $branch->id, 'cost_center_id' => $other->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($other->id, $safe->fresh()->cost_center_id);

        // فاضي في التعديل = مركز الفرع، زي الإضافة بالظبط
        $this->actingAs($admin)->post(route('gl.safes.update', $safe), [
            'name' => 'خزنة', 'kind' => 'cash', 'branch_id' => $branch->id, 'cost_center_id' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame($cc->id, $safe->fresh()->cost_center_id);
    }

    public function test_rename_syncs_only_the_auto_created_account(): void
    {
        $admin = $this->makeAdmin();
        $safe = $this->safeWithAccount('REN-1');
        $this->actingAs($admin)->post(route('gl.safes.update', $safe), [
            'name' => 'اسم جديد', 'name_en' => 'New name', 'kind' => 'cash',
        ])->assertSessionHasNoErrors();
        $acc = GlAccount::find($safe->gl_account_id);
        $this->assertSame('اسم جديد', $acc->name);
        $this->assertSame('New name', $acc->name_en);

        // حساب نظام (cash_main) متربط بالخزنة مايتغيرش اسمه
        $main = GlAccount::findKey('cash_main');
        $before = [$main->name, $main->name_en];
        $legacy = Safe::create(['code' => 'MAADI-MAIN', 'name' => 'رئيسية', 'kind' => 'cash', 'gl_account_id' => $main->id]);
        $this->actingAs($admin)->post(route('gl.safes.update', $legacy), [
            'name' => 'اسم تاني', 'name_en' => 'Other', 'kind' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->assertSame($before, [$main->fresh()->name, $main->fresh()->name_en]);
        $this->assertSame($main->id, $legacy->fresh()->gl_account_id);
    }

    public function test_update_with_empty_account_keeps_the_current_one(): void
    {
        $admin = $this->makeAdmin();
        $safe = $this->safeWithAccount('KEEP-1');
        $accId = $safe->gl_account_id;
        $count = GlAccount::count();

        $this->actingAs($admin)->post(route('gl.safes.update', $safe), [
            'name' => 'خزنة', 'kind' => 'cash', 'gl_account_id' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame($accId, $safe->fresh()->gl_account_id);
        $this->assertSame($count, GlAccount::count());
    }
}
