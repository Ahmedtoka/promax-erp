<?php
// tests/Feature/Gl/ExpensesCashTest.php
namespace Tests\Feature\Gl;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpensesCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        Storage::fake('public');
    }

    public function test_an_accountant_records_a_fuel_expense_from_a_reps_cash_with_a_receipt(): void
    {
        $acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
        $rep = $this->makeRep();

        $this->actingAs($acc)->post(route('gl.expenses.store'), [
            'date' => today()->toDateString(),
            'account_id' => GlAccount::findKey('expense_fuel')->id,
            'amount' => 350,
            'paid_from' => 'rep_cash',
            'paid_from_user_id' => $rep->id,
            'payee_type' => 'other',
            'payee_name' => 'محطة بنزين',
            'attachment' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $x = Expense::first();
        $this->assertStringStartsWith('EXP-', $x->number);
        Storage::disk('public')->assertExists($x->attachment_path);
        $this->assertSame(350.0, GlAccount::findKey('expense_fuel')->balanceBetween(null, null));
        $this->assertSame(-350.0, GlAccount::repCash($rep)->balanceBetween(null, null));
    }

    public function test_only_postable_expense_accounts_are_accepted(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('gl.expenses.store'), [
            'date' => today()->toDateString(), 'account_id' => GlAccount::findKey('cash_main')->id,
            'amount' => 10, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'x',
        ])->assertSessionHasErrors('account_id');
    }

    public function test_voiding_removes_the_entry_in_an_open_period_and_is_refused_in_a_closed_one(): void
    {
        $admin = $this->makeAdmin();
        $x = Expense::create(['number' => Expense::nextNumber(), 'date' => today(), 'account_id' => GlAccount::findKey('expense_rent')->id, 'amount' => 1000, 'paid_from' => 'bank', 'payee_type' => 'other', 'payee_name' => 'مالك', 'status' => 'posted', 'created_by' => $admin->id]);
        $this->assertSame(-1000.0, GlAccount::findKey('bank')->balanceBetween(null, null));

        // الصف نفسه بيترسم بزرار الإلغاء — ده اللي بيمشّي `@js` في
        // الـ`onsubmit` على الرندر الحقيقي، مش على الشاشة الفاضية
        $this->actingAs($admin)->get(route('gl.expenses'))
            ->assertOk()->assertSee($x->number, false)->assertSee(__('gl.void'), false);

        $this->actingAs($admin)->post(route('gl.expenses.void', $x))->assertRedirect();
        $this->assertSame('void', $x->fresh()->status);
        $this->assertSame(0.0, GlAccount::findKey('bank')->balanceBetween(null, null));

        $old = Expense::create(['number' => Expense::nextNumber(), 'date' => '2026-07-03', 'account_id' => GlAccount::findKey('expense_rent')->id, 'amount' => 5, 'paid_from' => 'cash_main', 'payee_type' => 'other', 'payee_name' => 'x', 'status' => 'posted', 'created_by' => $admin->id]);
        GlPeriod::close('2026-07', $admin);
        $this->actingAs($admin)->from(route('gl.expenses'))->post(route('gl.expenses.void', $old))->assertSessionHasErrors();
        $this->assertSame('posted', $old->fresh()->status);
    }

    public function test_a_deposit_moves_money_from_the_safe_to_the_bank_and_an_advance_to_a_rep(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();

        $this->actingAs($admin)->post(route('gl.cash.store'), ['date' => today()->toDateString(), 'kind' => 'deposit', 'amount' => 4000, 'reference' => 'DEP-1'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('gl.cash.store'), ['date' => today()->toDateString(), 'kind' => 'rep_advance', 'amount' => 200, 'user_id' => $rep->id])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get(route('gl.cash'))
            ->assertOk()->assertSee(CashMovement::first()->number, false)->assertSee(__('gl.void'), false);

        $this->assertSame(2, CashMovement::count());
        $this->assertSame(4000.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(-4200.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
        $this->assertSame(200.0, GlAccount::repCash($rep)->balanceBetween(null, null));
    }

    /**
     * ⚠️ **`exists:users,id` لوحدها كانت ثغرة.** المفتاح ده بيوصل
     * لـ`GlAccount::repCash()` اللي بتفتح حساب نقدية جديد لأي يوزر —
     * فريكوست متظبط كان بيولّد «نقدية مع المحاسب» تحت أب نقدية
     * المناديب في الشجرة، وحساب وهمي في الشجرة مابيتشالش بسهولة.
     */
    public function test_rep_cash_refuses_a_user_who_is_not_an_active_field_employee(): void
    {
        $admin = $this->makeAdmin();
        $office = User::factory()->create(['role' => 'accountant', 'active' => true]);

        $this->actingAs($admin)->post(route('gl.expenses.store'), [
            'date' => today()->toDateString(),
            'account_id' => GlAccount::findKey('expense_fuel')->id,
            'amount' => 50, 'paid_from' => 'rep_cash', 'paid_from_user_id' => $office->id,
            'payee_type' => 'other', 'payee_name' => 'x',
        ])->assertSessionHasErrors('paid_from_user_id');

        $this->assertSame(0, Expense::count());
        $this->assertFalse(GlAccount::where('user_id', $office->id)->exists());
    }

    public function test_a_cash_movement_refuses_a_rep_who_is_not_an_active_field_employee(): void
    {
        $admin = $this->makeAdmin();
        $office = User::factory()->create(['role' => 'accountant', 'active' => true]);

        $this->actingAs($admin)->post(route('gl.cash.store'), [
            'date' => today()->toDateString(), 'kind' => 'rep_advance',
            'amount' => 200, 'user_id' => $office->id,
        ])->assertSessionHasErrors('user_id');

        $this->assertSame(0, CashMovement::count());
        $this->assertFalse(GlAccount::where('user_id', $office->id)->exists());

        // ونفس القاعدة على المندوب الموقوف — حسابه موجود بس مابيتحركش
        $retired = $this->makeRep(['active' => false]);
        $this->actingAs($admin)->post(route('gl.cash.store'), [
            'date' => today()->toDateString(), 'kind' => 'rep_advance',
            'amount' => 200, 'user_id' => $retired->id,
        ])->assertSessionHasErrors('user_id');
    }

    /**
     * صف «مصروفات معتمدة من نقدية المندوب» في ورقة التصفية — عرض بس،
     * والأرقام فوقه (المتوقع والمرحّل) مابتتغيّرش.
     *
     * ⚠️ الراوت `erp.repclose.show` بياخد **المندوب** مش التصفية
     * (`erp/rep-close/{user}`) — التصفية بتحدد بداية النافذة بس.
     */
    public function test_the_settlement_sheet_shows_the_rep_cash_expenses_of_the_open_window(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();

        \App\Models\RepSettlement::create([
            'number' => 'SET-9001', 'user_id' => $rep->id,
            'from_at' => now()->subDays(3), 'to_at' => now()->subDay(),
            'invoices_count' => 0, 'cash_sales' => 0, 'credit_sales' => 0, 'cash_refunds' => 0,
            'expected' => 0, 'prev_balance' => 0, 'received' => 0, 'balance' => 0,
            'created_by' => $admin->id,
        ]);

        $expense = Expense::create([
            'number' => Expense::nextNumber(), 'date' => today(),
            'account_id' => GlAccount::findKey('expense_fuel')->id, 'amount' => 180,
            'paid_from' => 'rep_cash', 'paid_from_user_id' => $rep->id,
            'payee_type' => 'other', 'payee_name' => 'محطة بنزين',
            'status' => 'posted', 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get(route('erp.repclose.show', $rep))
            ->assertOk()
            ->assertSee(__('gl.rep_expenses_row'), false)
            ->assertSee($expense->number, false)
            ->assertSee('180.00', false);
    }

    public function test_the_screens_render_for_the_accountant_and_are_hidden_from_a_rep(): void
    {
        $acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
        $this->actingAs($acc)->get(route('gl.expenses'))->assertOk()->assertSee('name="account_id"', false);
        $this->actingAs($acc)->get(route('gl.cash'))->assertOk()->assertSee('name="kind"', false);
        $this->actingAs($this->makeRep())->get(route('gl.expenses'))->assertForbidden();
    }
}
