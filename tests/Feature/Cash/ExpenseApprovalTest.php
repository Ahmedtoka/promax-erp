<?php

namespace Tests\Feature\Cash;

use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Safe;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lane 4 Task 6: بنود المصروف والخزنة والمركز ودورة الاعتماد (L4-D7..D9).
 * المحاسب بيسجّل معلّق، الأدمن (أو صاحب منحة) بيعتمد، والدفع هو اللي
 * بيرحّل القيد ويخصم من الخزنة.
 */
class ExpenseApprovalTest extends TestCase
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

    private function accountant(): User
    {
        return User::factory()->create(['role' => 'accountant', 'active' => true]);
    }

    private function center(string $code): CostCenter
    {
        return CostCenter::create(['code' => $code, 'name' => 'مركز '.$code, 'name_en' => 'Center '.$code,
            'kind' => 'branch', 'parent_id' => CostCenter::company()->id, 'active' => true]);
    }

    private function safe(string $code, string $kind = 'cash', ?CostCenter $center = null): Safe
    {
        $safe = Safe::create(['code' => $code, 'name' => 'خزنة '.$code, 'name_en' => 'Safe '.$code,
            'kind' => $kind, 'cost_center_id' => $center?->id]);
        $safe->ensureGlAccount();

        return $safe->fresh();
    }

    private function cat(string $code): ExpenseCategory
    {
        return ExpenseCategory::where('code', $code)->firstOrFail();
    }

    private function entryFor(Expense $x): ?GlEntry
    {
        return app(Ledger::class)->autoEntryFor($x);
    }

    private function store(User $u, array $data)
    {
        return $this->actingAs($u)->post(route('gl.expenses.store'), array_merge([
            'date' => today()->toDateString(), 'amount' => 500,
            'payee_type' => 'other', 'payee_name' => 'مورد',
        ], $data));
    }

    public function test_accountant_expense_is_pending_and_not_posted(): void
    {
        $safe = $this->safe('S1');

        $this->store($this->accountant(), ['category_id' => $this->cat('BUFFET')->id, 'safe_id' => $safe->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('gl.expenses'))
            ->assertSessionHas('ok', __('cash.exp_sent_for_approval'));

        $x = Expense::firstOrFail();
        $this->assertSame('pending', $x->status);
        $this->assertNull($x->approved_by);
        $this->assertNull($this->entryFor($x));
        $this->assertSame(0.0, $safe->balance());
        $this->assertSame(0, GlEntry::count());
    }

    public function test_admin_approves_then_accountant_pays_and_safe_is_credited_with_centers(): void
    {
        $admin = $this->makeAdmin();
        $acc = $this->accountant();
        $spend = $this->center('CC-OPS');
        $home = $this->center('CC-SAFE');
        $safe = $this->safe('S1', 'cash', $home);
        $fuel = GlAccount::findKey('expense_other');

        $this->store($acc, ['category_id' => $this->cat('BUFFET')->id, 'safe_id' => $safe->id, 'cost_center_id' => $spend->id, 'amount' => 750])
            ->assertSessionHasNoErrors();
        $x = Expense::firstOrFail();
        $this->assertSame($safe->id, $x->safe_id);
        $this->assertSame('cash_main', $x->paid_from);
        $this->assertSame($spend->id, $x->cost_center_id);

        // الاعتماد مابيرحّلش
        $this->actingAs($admin)->post(route('gl.expenses.approve', $x), ['note' => 'تمام'])->assertSessionHasNoErrors()->assertRedirect();
        $x->refresh();
        $this->assertSame('approved', $x->status);
        $this->assertSame($admin->id, $x->approved_by);
        $this->assertSame('تمام', $x->decision_note);
        $this->assertNull($this->entryFor($x));
        $this->assertSame(0.0, $safe->balance());

        // الدفع هو اللي بيرحّل
        $this->actingAs($acc)->post(route('gl.expenses.pay', $x))->assertSessionHasNoErrors()->assertRedirect();
        $x->refresh();
        $this->assertSame('posted', $x->status);
        $this->assertSame($acc->id, $x->paid_by);
        $this->assertNotNull($x->paid_at);

        $entry = $this->entryFor($x);
        $this->assertNotNull($entry);
        $dr = $entry->lines->firstWhere('debit', '>', 0);
        $cr = $entry->lines->firstWhere('credit', '>', 0);
        $this->assertSame($fuel->id, $dr->account_id);
        $this->assertSame($spend->id, (int) $dr->cost_center_id);
        $this->assertSame($safe->gl_account_id, $cr->account_id);
        $this->assertSame($home->id, (int) $cr->cost_center_id);
        $this->assertSame(-750.0, $safe->balance());
        $this->assertSame(750.0, $fuel->fresh()->balanceBetween(null, null));
    }

    public function test_accountant_cannot_approve_unless_granted(): void
    {
        $acc = $this->accountant();
        $this->store($acc, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main'])->assertSessionHasNoErrors();
        $x = Expense::firstOrFail();

        $this->actingAs($acc)->post(route('gl.expenses.approve', $x))->assertForbidden();
        $this->actingAs($acc)->post(route('gl.expenses.reject', $x), ['note' => 'لا'])->assertForbidden();
        $this->assertSame('pending', $x->fresh()->status);

        // ومالوش زرار اعتماد على الشاشة
        $this->actingAs($acc)->get(route('gl.expenses'))->assertOk()
            ->assertSee($x->number, false)
            ->assertDontSee(route('gl.expenses.approve', $x), false);
    }

    public function test_granted_user_can_approve(): void
    {
        $acc = $this->accountant();
        $this->store($acc, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main'])->assertSessionHasNoErrors();
        $x = Expense::firstOrFail();
        $this->assertSame('pending', $x->status);

        $acc->permissions()->create(['perm' => 'act.gl.expense_approve', 'allow' => true]);
        $acc = $acc->fresh();

        $this->actingAs($acc)->get(route('gl.expenses'))->assertOk()->assertSee(route('gl.expenses.approve', $x), false);
        $this->actingAs($acc)->post(route('gl.expenses.approve', $x))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', $x->fresh()->status);
        $this->assertSame($acc->id, $x->fresh()->approved_by);

        // صاحب صلاحية الاعتماد: سنده بيتدفع على طول (L4-D7)
        $this->store($acc, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main', 'amount' => 20])->assertSessionHasNoErrors();
        $own = Expense::where('amount', 20)->firstOrFail();
        $this->assertSame('posted', $own->status);
        $this->assertNotNull($this->entryFor($own));
    }

    public function test_reject_keeps_it_out_of_the_ledger(): void
    {
        $admin = $this->makeAdmin();
        $acc = $this->accountant();
        $this->store($acc, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main'])->assertSessionHasNoErrors();
        $x = Expense::firstOrFail();

        // السبب إجباري
        $this->actingAs($admin)->from(route('gl.expenses'))->post(route('gl.expenses.reject', $x), [])->assertSessionHasErrors('note');
        $this->assertSame('pending', $x->fresh()->status);

        $this->actingAs($admin)->post(route('gl.expenses.reject', $x), ['note' => 'مش مصروف شركة'])->assertSessionHasNoErrors();
        $x->refresh();
        $this->assertSame('rejected', $x->status);
        $this->assertSame($admin->id, $x->rejected_by);
        $this->assertSame('مش مصروف شركة', $x->decision_note);

        // المرفوض مايتدفعش ولا يتلغي ولا يتعتمد
        $this->actingAs($acc)->from(route('gl.expenses'))->post(route('gl.expenses.pay', $x))->assertSessionHasErrors('expense');
        $this->actingAs($admin)->from(route('gl.expenses'))->post(route('gl.expenses.approve', $x))->assertSessionHasErrors('expense');
        $this->actingAs($acc)->from(route('gl.expenses'))->post(route('gl.expenses.void', $x))->assertSessionHasErrors('void');
        $this->assertSame('rejected', $x->fresh()->status);
        $this->assertNull($this->entryFor($x));
        $this->assertSame(0, GlEntry::count());

        // المعتمد كمان يترفض
        $this->store($acc, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main', 'amount' => 9])->assertSessionHasNoErrors();
        $y = Expense::where('amount', 9)->firstOrFail();
        $this->actingAs($admin)->post(route('gl.expenses.approve', $y));
        $this->actingAs($admin)->post(route('gl.expenses.reject', $y), ['note' => 'اتلغى'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $y->fresh()->status);
        $this->assertSame(0, GlEntry::count());
    }

    public function test_pay_requires_approved(): void
    {
        $admin = $this->makeAdmin();
        $acc = $this->accountant();
        $this->store($acc, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main'])->assertSessionHasNoErrors();
        $x = Expense::firstOrFail();

        $this->actingAs($acc)->from(route('gl.expenses'))->post(route('gl.expenses.pay', $x))
            ->assertSessionHasErrors(['expense' => __('cash.exp_not_approved')]);
        $this->assertSame('pending', $x->fresh()->status);
        $this->assertNull($this->entryFor($x));

        // ومعتمد في فترة مقفولة مايتدفعش
        $old = Expense::create(['number' => Expense::nextNumber(), 'date' => '2026-07-03', 'account_id' => GlAccount::findKey('expense_rent')->id,
            'amount' => 5, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'approved', 'created_by' => $admin->id]);
        GlPeriod::close('2026-07', $admin);
        $this->actingAs($acc)->from(route('gl.expenses'))->post(route('gl.expenses.pay', $old))->assertSessionHasErrors('expense');
        $this->assertSame('approved', $old->fresh()->status);
        $this->assertNull($this->entryFor($old));

        // إلغاء المعلّق مابيلمسش الدفتر
        $this->actingAs($acc)->post(route('gl.expenses.void', $x))->assertSessionHasNoErrors();
        $this->assertSame('void', $x->fresh()->status);
        $this->assertSame(0, GlEntry::count());
    }

    public function test_admin_expense_is_paid_at_once(): void
    {
        $admin = $this->makeAdmin();
        $bank = $this->safe('BK', 'bank');

        $this->store($admin, ['category_id' => $this->cat('BUFFET')->id, 'safe_id' => $bank->id, 'paid_from' => 'cash_main', 'amount' => 300])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', __('cash.exp_saved_paid'));

        $x = Expense::firstOrFail();
        $this->assertSame('posted', $x->status);
        // نوع الخزنة بيغلب `paid_from` المبعوت
        $this->assertSame('bank', $x->paid_from);
        $this->assertSame($admin->id, $x->approved_by);
        $this->assertSame($admin->id, $x->paid_by);
        $this->assertNotNull($x->approved_at);
        $this->assertSame(CostCenter::company()->id, $x->cost_center_id);
        $this->assertNotNull($this->entryFor($x));
        $this->assertSame(-300.0, $bank->balance());

        // مدفوع من غير إيصال = بادج على الشاشة
        $this->actingAs($admin)->get(route('gl.expenses'))->assertOk()->assertSee(__('cash.exp_no_receipt'), false);
    }

    public function test_category_account_is_used_when_no_account_given(): void
    {
        $admin = $this->makeAdmin();

        $this->store($admin, ['category_id' => $this->cat('FUEL')->id, 'paid_from' => 'cash_main', 'amount' => 40])->assertSessionHasNoErrors();
        $this->assertSame(GlAccount::findKey('expense_fuel')->id, Expense::firstOrFail()->account_id);

        // بند مالوش حساب ومفيش حساب مبعوت
        $bare = ExpenseCategory::create(['code' => 'BARE', 'name' => 'بند فاضي', 'type' => 'operating']);
        $this->store($admin, ['category_id' => $bare->id, 'paid_from' => 'cash_main'])->assertSessionHasErrors('category_id');

        // من غير بند ولا حساب = زي الأول، الحساب إجباري
        $this->store($admin, ['paid_from' => 'cash_main'])->assertSessionHasErrors('account_id');

        // بند أصل: حساب الأصل، وحساب مصروف مرفوض
        $laptop = $this->cat('LAPTOP');
        $this->store($admin, ['category_id' => $laptop->id, 'account_id' => GlAccount::findKey('expense_other')->id, 'paid_from' => 'cash_main'])
            ->assertSessionHasErrors('account_id');
        $this->store($admin, ['category_id' => $laptop->id, 'paid_from' => 'cash_main', 'amount' => 36000, 'asset_name' => 'لابتوب المحاسب'])
            ->assertSessionHasNoErrors();
        $a = Expense::where('amount', 36000)->firstOrFail();
        $this->assertSame(GlAccount::findKey('fixed_assets')->id, $a->account_id);
        $this->assertSame('لابتوب المحاسب', $a->asset_name);
        $this->assertSame(36, $a->asset_life_months);
        $this->assertSame(today()->toDateString(), $a->asset_start_date->toDateString());
        $this->assertSame(36000.0, GlAccount::findKey('fixed_assets')->balanceBetween(null, null));

        // بيانات الأصل بتتشال من بند مش أصل
        $this->store($admin, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main', 'amount' => 7, 'asset_name' => 'x', 'asset_life_months' => 12])
            ->assertSessionHasNoErrors();
        $b = Expense::where('amount', 7)->firstOrFail();
        $this->assertNull($b->asset_name);
        $this->assertNull($b->asset_life_months);
    }

    public function test_fuel_links_rep_and_vehicle(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        $other = $this->makeRep();
        $van = Vehicle::create(['plate' => 'ق ن ر 123', 'rep_id' => $rep->id, 'active' => true]);
        $truck = Vehicle::create(['plate' => 'ع ب ت 456', 'active' => true]);

        // بنزين من نقدية المندوب: المندوب والعربية الافتراضيين
        $this->store($admin, ['category_id' => $this->cat('FUEL')->id, 'paid_from' => 'rep_cash', 'paid_from_user_id' => $rep->id, 'amount' => 350])
            ->assertSessionHasNoErrors();
        $x = Expense::where('amount', 350)->firstOrFail();
        $this->assertSame($rep->id, $x->rep_id);
        $this->assertSame($van->id, $x->vehicle_id);
        $this->assertNull($x->safe_id);
        $this->assertSame(-350.0, GlAccount::repCash($rep)->balanceBetween(null, null));

        // مندوب وعربية مكتوبين صريح
        $this->store($admin, ['category_id' => $this->cat('FUEL')->id, 'paid_from' => 'cash_main', 'rep_id' => $other->id, 'vehicle_id' => $truck->id, 'amount' => 200])
            ->assertSessionHasNoErrors();
        $y = Expense::where('amount', 200)->firstOrFail();
        $this->assertSame($other->id, $y->rep_id);
        $this->assertSame($truck->id, $y->vehicle_id);

        // بند مش مربوط بعربية بيشيلهم
        $this->store($admin, ['category_id' => $this->cat('BUFFET')->id, 'paid_from' => 'cash_main', 'rep_id' => $rep->id, 'vehicle_id' => $van->id, 'amount' => 15])
            ->assertSessionHasNoErrors();
        $z = Expense::where('amount', 15)->firstOrFail();
        $this->assertNull($z->rep_id);
        $this->assertNull($z->vehicle_id);

        // المندوب لازم يكون موظف ميداني شغّال
        $this->store($admin, ['category_id' => $this->cat('FUEL')->id, 'paid_from' => 'cash_main', 'rep_id' => $admin->id])
            ->assertSessionHasErrors('rep_id');
    }

    public function test_legacy_paid_from_uses_the_legacy_safe_account(): void
    {
        $admin = $this->makeAdmin();

        // من غير خزن خالص = مفاتيح القواعد زي الأول بالظبط
        $this->store($admin, ['account_id' => GlAccount::findKey('expense_rent')->id, 'paid_from' => 'bank', 'amount' => 100])->assertSessionHasNoErrors();
        $this->assertSame(-100.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $first = Expense::firstOrFail();
        $this->assertSame(CostCenter::company()->id, $first->cost_center_id);

        // خزنة `MAADI-MAIN` بحسابها الخاص (`11.MAADI-MAIN`) ومركز: السند من غير
        // `safe_id` بيفضل على مفتاح القاعدة `cash_main` زي قبل Lane 4 بالظبط —
        // الخزنة القديمة بتدّي المركز بس (قرار المراجعة، زي `Rules::cash()`)
        $maadi = $this->center('MAADI-X');
        $legacy = Safe::create(['code' => 'MAADI-MAIN', 'name' => 'الرئيسية', 'kind' => 'cash', 'cost_center_id' => $maadi->id]);
        $legacy->ensureGlAccount();
        $legacy = $legacy->fresh();
        $cashMain = GlAccount::findKey('cash_main');
        $this->assertNotSame($cashMain->id, $legacy->gl_account_id);

        $this->store($admin, ['account_id' => GlAccount::findKey('expense_rent')->id, 'paid_from' => 'cash_main', 'amount' => 60])->assertSessionHasNoErrors();
        $x = Expense::where('amount', 60)->firstOrFail();
        $this->assertNull($x->safe_id);
        $this->assertSame($maadi->id, $x->cost_center_id);
        $cr = $this->entryFor($x)->lines->firstWhere('credit', '>', 0);
        $this->assertSame($cashMain->id, $cr->account_id);
        $this->assertSame($maadi->id, (int) $cr->cost_center_id);
        $this->assertSame(0.0, $legacy->balance());

        // نفس الخزنة مسمّاة صراحةً (`safe_id`) = حسابها الخاص
        $this->store($admin, ['account_id' => GlAccount::findKey('expense_rent')->id, 'safe_id' => $legacy->id, 'amount' => 70])->assertSessionHasNoErrors();
        $y = Expense::where('amount', 70)->firstOrFail();
        $this->assertSame($legacy->id, $y->safe_id);
        $this->assertSame($legacy->gl_account_id, $this->entryFor($y)->lines->firstWhere('credit', '>', 0)->account_id);
        $this->assertSame(-70.0, $legacy->balance());

        // سند قديم موجود من غير خزنة ولا مركز: `cash_main`، والسطرين عليهم مركز
        // (الخزنة القديمة على الدائن والمدين)
        $old = Expense::create(['number' => Expense::nextNumber(), 'date' => today(), 'account_id' => GlAccount::findKey('expense_rent')->id,
            'amount' => 11, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'posted', 'created_by' => $admin->id]);
        $lines = $this->entryFor($old)->lines;
        $this->assertSame($cashMain->id, $lines->firstWhere('credit', '>', 0)->account_id);
        $this->assertSame($maadi->id, (int) $lines->firstWhere('debit', '>', 0)->cost_center_id);
        $this->assertSame($maadi->id, (int) $lines->firstWhere('credit', '>', 0)->cost_center_id);

        // سند قديم من نقدية مندوب من غير مركز = مركز الشركة على السطرين
        $rep = $this->makeRep();
        $repOld = Expense::create(['number' => Expense::nextNumber(), 'date' => today(), 'account_id' => GlAccount::findKey('expense_fuel')->id,
            'amount' => 12, 'paid_from' => 'rep_cash', 'paid_from_user_id' => $rep->id, 'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'posted', 'created_by' => $admin->id]);
        $lines = $this->entryFor($repOld)->lines;
        $this->assertSame(GlAccount::repCash($rep)->id, $lines->firstWhere('credit', '>', 0)->account_id);
        foreach ($lines as $l) {
            $this->assertSame(CostCenter::company()->id, (int) $l->cost_center_id);
        }
    }

    public function test_the_flow_refuses_approve_and_reject_without_the_right(): void
    {
        $acc = $this->accountant();
        $x = Expense::create(['number' => Expense::nextNumber(), 'date' => today(), 'account_id' => GlAccount::findKey('expense_rent')->id,
            'amount' => 5, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'pending', 'created_by' => $acc->id]);
        $flow = app(\App\Services\Cash\ExpenseFlow::class);

        foreach (['approve', 'reject'] as $m) {
            try {
                $flow->{$m}($x, $acc, 'x');
                $this->fail($m.' passed without the approval right');
            } catch (\App\Exceptions\Rejected $e) {
                $this->assertSame(__('cash.exp_no_approve_right'), $e->getMessage());
            }
        }
        $this->assertSame('pending', $x->fresh()->status);

        $flow->approve($x, $this->makeAdmin());
        $this->assertSame('approved', $x->fresh()->status);
    }

    public function test_pending_kpi_links_every_pending_request(): void
    {
        $admin = $this->makeAdmin();
        Expense::create(['number' => Expense::nextNumber(), 'date' => '2026-03-05', 'account_id' => GlAccount::findKey('expense_rent')->id,
            'amount' => 5, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'pending', 'created_by' => $admin->id]);

        $this->actingAs($admin)->get(route('gl.expenses'))->assertOk()
            ->assertSee(e(route('gl.expenses', ['status' => 'pending', 'from' => '2026-03-05', 'to' => '2026-03-05'])), false);
    }

    public function test_categories_screen_crud_is_admin_only(): void
    {
        $admin = $this->makeAdmin();
        $acc = $this->accountant();
        $other = GlAccount::findKey('expense_other');

        // المحاسب يقرا بس
        $this->actingAs($acc)->get(route('gl.expense_categories'))->assertOk()
            ->assertSee('FUEL', false)->assertDontSee(__('cash.cat_new'), false);
        $this->actingAs($acc)->post(route('gl.expense_categories.store'), [
            'code' => 'TEA', 'name' => 'شاي', 'type' => 'operating', 'account_id' => $other->id,
        ])->assertForbidden();
        $fuel = $this->cat('FUEL');
        $this->actingAs($acc)->post(route('gl.expense_categories.update', $fuel), [
            'name' => 'x', 'type' => 'operating', 'account_id' => $other->id,
        ])->assertForbidden();
        $this->assertFalse(ExpenseCategory::where('code', 'TEA')->exists());

        // الأدمن يضيف ويعدّل
        $this->actingAs($admin)->get(route('gl.expense_categories'))->assertOk()->assertSee(__('cash.cat_new'), false);
        $this->actingAs($admin)->post(route('gl.expense_categories.store'), [
            'code' => 'TEA', 'name' => 'شاي', 'type' => 'operating', 'account_id' => $other->id, 'life_months' => 12,
        ])->assertSessionHasNoErrors();
        $tea = ExpenseCategory::where('code', 'TEA')->firstOrFail();
        $this->assertNull($tea->life_months);
        $this->assertTrue($tea->active);

        // كود مكرر وحساب غلط للنوع مرفوضين
        $this->actingAs($admin)->post(route('gl.expense_categories.store'), [
            'code' => 'TEA', 'name' => 'تاني', 'type' => 'operating', 'account_id' => $other->id,
        ])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post(route('gl.expense_categories.store'), [
            'code' => 'PC', 'name' => 'كمبيوتر', 'type' => 'asset', 'account_id' => $other->id,
        ])->assertSessionHasErrors('account_id');

        $this->actingAs($admin)->post(route('gl.expense_categories.update', $tea), [
            'code' => 'CHANGED', 'name' => 'شاي وقهوة', 'type' => 'operating', 'account_id' => $other->id, 'links_vehicle' => '1', 'active' => '0',
        ])->assertSessionHasNoErrors();
        $tea->refresh();
        $this->assertSame('TEA', $tea->code);
        $this->assertSame('شاي وقهوة', $tea->name);
        $this->assertTrue($tea->links_vehicle);
        $this->assertFalse($tea->active);

        // التصدير
        $this->actingAs($acc)->get(route('gl.expense_categories', ['export' => 1]))->assertOk()->assertDownload();
    }
}
