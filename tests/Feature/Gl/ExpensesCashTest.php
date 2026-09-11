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

        $this->assertSame(2, CashMovement::count());
        $this->assertSame(4000.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(-4200.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
        $this->assertSame(200.0, GlAccount::repCash($rep)->balanceBetween(null, null));
    }

    public function test_the_screens_render_for_the_accountant_and_are_hidden_from_a_rep(): void
    {
        $acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
        $this->actingAs($acc)->get(route('gl.expenses'))->assertOk()->assertSee('name="account_id"', false);
        $this->actingAs($acc)->get(route('gl.cash'))->assertOk()->assertSee('name="kind"', false);
        $this->actingAs($this->makeRep())->get(route('gl.expenses'))->assertForbidden();
    }
}
