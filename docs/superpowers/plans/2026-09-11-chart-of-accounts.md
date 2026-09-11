# Chart of Accounts & General Ledger — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a double-entry general ledger (chart of accounts, journal, trial balance, income statement, balance sheet, expenses, cash/bank movements, audited corrections, full rebuild from source documents) on top of the existing PROMAX client ledger without changing any existing sales/collection flow.

**Architecture:** `transactions` stays the source of truth for receivables; every `transactions` row, rep settlement, supplier ledger row, expense voucher and cash movement generates one balanced `gl_entries` journal entry through a single `App\Services\Gl\Ledger` service, driven by an editable `gl_posting_rules` table keyed on `gl_accounts.system_key`. Observers post on create/delete; admin invoice tools call `repost` explicitly; `rebuild()` regenerates every automatic entry from `gl_start_date` while preserving manual account overrides, then proves `receivables == SUM(clients.balance)`.

**Tech Stack:** Laravel 12, MySQL, Blade, PHPUnit on `promax_test`. No new composer packages. Arabic-first UI with `lang/ar/gl.php` + `lang/en/gl.php`.

**Spec:** `docs/superpowers/specs/2026-09-11-chart-of-accounts-design.md`

## Global Constraints

- Never run anything against the `promax` database (live copy); tests use `promax_test` via phpunit.xml. Click-through on `promax_qa` only.
- Migrations guarded with `Schema::hasTable` / `hasColumn`; time columns are `dateTime()`, never `timestamp()`.
- Every user-visible string in both `lang/ar/gl.php` and `lang/en/gl.php` with the same key; `php artisan promax:i18n-check` must stay green.
- All money writes inside `DB::transaction`; entries must satisfy `round(Σdebit,2) === round(Σcredit,2)` or the service throws.
- New GET routes get `->middleware('role:admin,accountant')` and matching `Access::SCREENS` entries for `accountant`; buttons gated with `Access::action($user, 'act.gl.*')` whose `ACTIONS` roles equal the route gate (RoleAccessTest enforces this).
- Every list screen uses `App\Support\DateRange` for from/to and gets the layout's generic Excel/PDF buttons for free.
- Bash tool heredocs mangle `\\`: write edit scripts with the Write tool and run them by path.
- Commit after every task; do not push (the owner pushes).

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_11_000100_gl_core.php` | `gl_accounts`, `gl_entries`, `gl_lines`, `gl_line_overrides`, `gl_posting_rules`, `gl_periods` |
| `database/migrations/2026_09_11_000200_gl_expenses_cash.php` | `expenses`, `cash_movements` |
| `app/Models/Gl/{GlAccount,GlEntry,GlLine,GlLineOverride,GlPostingRule,GlPeriod}.php` | Eloquent models, relations, constants |
| `app/Models/Expense.php`, `app/Models/CashMovement.php` | vouchers |
| `database/seeders/GlSeeder.php` | roots, system accounts, default posting rules (idempotent) |
| `app/Services/Gl/Rules.php` | source → `[debitLines, creditLines]` using rules + system keys; rep inference |
| `app/Services/Gl/Ledger.php` | the only writer: post/unpost/repost/manual/reverse/overrideAccount/rebuild |
| `app/Services/Gl/Reports.php` | trial balance, account statement, income statement, balance sheet |
| `app/Observers/Gl/*.php` | `TransactionObserver`, `RepSettlementObserver`, `SupplierTransactionObserver`, `ExpenseObserver`, `CashMovementObserver` |
| `app/Providers/AppServiceProvider.php` | register observers |
| `app/Console/Commands/GlSyncRepAccounts.php` | `promax:gl-sync-reps` |
| `app/Http/Controllers/Gl/{AccountController,EntryController,ReportController,ExpenseController,CashMovementController,SettingsController}.php` | screens |
| `resources/views/gl/*.blade.php` | accounts, account, entries, expenses, cash, trial_balance, income, balance_sheet, settings |
| `app/Support/Access.php`, `routes/web.php`, `lang/{ar,en}/gl.php`, `lang/{ar,en}/nav.php`, `lang/{ar,en}/perm.php` | registration |
| `tests/Feature/Gl/*.php` | GlSchemaTest, GlPostingTest, GlCorrectionTest, GlRebuildTest, GlInvariantTest, ExpensesCashTest, GlReportsTest, GlScreensTest |

---

### Task 1: Schema, models and seeder

**Files:**
- Create: `database/migrations/2026_09_11_000100_gl_core.php`
- Create: `database/migrations/2026_09_11_000200_gl_expenses_cash.php`
- Create: `app/Models/Gl/GlAccount.php`, `GlEntry.php`, `GlLine.php`, `GlLineOverride.php`, `GlPostingRule.php`, `GlPeriod.php`
- Create: `app/Models/Expense.php`, `app/Models/CashMovement.php`
- Create: `database/seeders/GlSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call `GlSeeder` after `UserSeeder`/team seeders — find the line that calls the warehouse seeder and add after it)
- Test: `tests/Feature/Gl/GlSchemaTest.php`

**Interfaces:**
- Produces: `GlAccount::findKey(string $systemKey): GlAccount` (throws if missing), `GlAccount::repCash(User $u): GlAccount` (creates `1110.{code}` under the `rep_cash` parent if absent), `GlAccount::isRoot()`, `GlAccount::children()`, `GlAccount::balanceBetween(?Carbon $from, ?Carbon $to): float` (debit − credit signed per `normal_side`), constants `GlAccount::TYPES`, `GlAccount::SYSTEM_KEYS` (list in step 3).
- `GlEntry` constants `ORIGINS = ['auto','manual','reversal','opening']`; relation `lines()`, `source()` morph, `reverses()`.
- `GlPostingRule::forKey(string $key): ?GlPostingRule` cached in a static array flushed by `GlPostingRule::flush()`.
- `GlPeriod::isClosed(Carbon $date): bool`, `GlPeriod::close(string $key, User $by)`, `GlPeriod::reopen(string $key, User $by)`.
- `Expense::PAID_FROM = ['cash_main','bank','rep_cash']`, `PAYEE_TYPES = ['supplier','employee','other']`; `CashMovement::KINDS = ['deposit','withdraw','rep_advance','rep_return']`. Both use `HasDocumentNumber` with prefixes `EXP-` and `CM-`, start 1001.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Gl/GlSchemaTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_builds_the_five_roots_and_every_system_key_and_is_idempotent(): void
    {
        $this->seed(GlSeeder::class);
        $this->seed(GlSeeder::class);

        $this->assertSame(['1', '2', '3', '4', '5'], GlAccount::whereNull('parent_id')->orderBy('code')->pluck('code')->all());
        foreach (GlAccount::SYSTEM_KEYS as $key) {
            $acc = GlAccount::findKey($key);
            $this->assertTrue($acc->is_system, $key);
            $this->assertTrue($acc->is_postable || $key === 'rep_cash', $key.' postable');
        }
        $this->assertSame(1, GlAccount::where('system_key', 'receivables')->count(), 'no duplicates on re-seed');
        $this->assertGreaterThanOrEqual(18, GlPostingRule::count());
        $this->assertSame('receivables', GlPostingRule::forKey('tx.sale')->debit_key);
    }

    public function test_a_rep_cash_account_is_created_per_field_user_under_the_parent(): void
    {
        $this->seed(GlSeeder::class);
        $rep = $this->makeRep(['code' => 'SLS-777']);

        $acc = GlAccount::repCash($rep);
        $again = GlAccount::repCash($rep);

        $this->assertSame('1110.SLS-777', $acc->code);
        $this->assertSame($acc->id, $again->id);
        $this->assertSame(GlAccount::findKey('rep_cash')->id, $acc->parent_id);
        $this->assertSame('asset', $acc->type);
        $this->assertSame('debit', $acc->normal_side);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GlSchemaTest`
Expected: FAIL — `Class "Database\Seeders\GlSeeder" not found`.

- [ ] **Step 3: Write the migrations, models and seeder**

`database/migrations/2026_09_11_000100_gl_core.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر الأستاذ العام (١١ سبتمبر ٢٠٢٦) — طبقة مشتقة فوق `transactions`.
 * ⚠️ مفيش أي تعديل على الجداول الموجودة. المديونية مصدرها `transactions`،
 * والشجرة بتتولد منها (شوف docs/superpowers/specs/2026-09-11-chart-of-accounts-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gl_accounts')) {
            Schema::create('gl_accounts', function (Blueprint $t) {
                $t->id();
                $t->string('code', 20)->unique();
                $t->string('name', 120);
                $t->string('name_en', 120)->nullable();
                $t->foreignId('parent_id')->nullable()->constrained('gl_accounts')->nullOnDelete();
                $t->string('type', 10);          // asset liability equity revenue expense
                $t->string('normal_side', 6);    // debit credit
                $t->boolean('is_system')->default(false);
                $t->string('system_key', 40)->nullable()->unique();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->boolean('is_postable')->default(true);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('gl_entries')) {
            Schema::create('gl_entries', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->date('date');
                $t->string('period_key', 7)->index();
                $t->string('memo', 250);
                $t->string('origin', 10)->index();  // auto manual reversal opening
                $t->string('source_type', 60)->nullable();
                $t->unsignedBigInteger('source_id')->nullable();
                $t->string('rule_key', 40)->nullable();
                $t->boolean('needs_review')->default(false);
                $t->foreignId('reverses_entry_id')->nullable()->constrained('gl_entries')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('edited_at')->nullable();
                $t->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                // قيد آلي واحد لكل مستند
                $t->unique(['source_type', 'source_id', 'origin'], 'gl_entries_source_unique');
                $t->index(['date', 'id']);
            });
        }
        if (! Schema::hasTable('gl_lines')) {
            Schema::create('gl_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('entry_id')->constrained('gl_entries')->cascadeOnDelete();
                $t->foreignId('account_id')->constrained('gl_accounts');
                $t->decimal('debit', 14, 2)->default(0);
                $t->decimal('credit', 14, 2)->default(0);
                $t->string('memo', 250)->nullable();
                $t->boolean('overridden')->default(false);
                $t->foreignId('rule_account_id')->nullable()->constrained('gl_accounts');
                $t->string('slot', 20)->nullable(); // dr / cr / tax — لإعادة تطبيق التعديل بعد إعادة البناء
                $t->timestamps();
                $t->index(['account_id', 'entry_id']);
            });
        }
        if (! Schema::hasTable('gl_line_overrides')) {
            Schema::create('gl_line_overrides', function (Blueprint $t) {
                $t->id();
                $t->foreignId('line_id')->constrained('gl_lines')->cascadeOnDelete();
                $t->foreignId('from_account_id')->constrained('gl_accounts');
                $t->foreignId('to_account_id')->constrained('gl_accounts');
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('at');
                $t->string('note', 250)->nullable();
            });
        }
        if (! Schema::hasTable('gl_posting_rules')) {
            Schema::create('gl_posting_rules', function (Blueprint $t) {
                $t->id();
                $t->string('key', 40)->unique();
                $t->string('label', 120);
                $t->string('debit_key', 40)->nullable();
                $t->string('credit_key', 40)->nullable();
                $t->string('tax_key', 40)->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('gl_periods')) {
            Schema::create('gl_periods', function (Blueprint $t) {
                $t->id();
                $t->string('key', 7)->unique();   // YYYY-MM
                $t->string('status', 8)->default('open');
                $t->dateTime('closed_at')->nullable();
                $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('reopened_at')->nullable();
                $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['gl_line_overrides', 'gl_lines', 'gl_entries', 'gl_posting_rules', 'gl_periods', 'gl_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
```

`database/migrations/2026_09_11_000200_gl_expenses_cash.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** سندات المصروف وحركة النقدية (١١/٩/٢٠٢٦) — كل سند بيولّد قيد في gl_entries. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->date('date');
                $t->foreignId('account_id')->constrained('gl_accounts');
                $t->decimal('amount', 14, 2);
                $t->string('paid_from', 10);                 // cash_main bank rep_cash
                $t->foreignId('paid_from_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('payee_type', 10)->default('other'); // supplier employee other
                $t->foreignId('payee_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $t->foreignId('payee_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('payee_name', 120)->nullable();
                $t->string('reference', 80)->nullable();
                $t->string('note', 250)->nullable();
                $t->string('attachment_path')->nullable();
                $t->string('status', 8)->default('posted');  // posted void
                $t->dateTime('voided_at')->nullable();
                $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['date', 'status']);
            });
        }
        if (! Schema::hasTable('cash_movements')) {
            Schema::create('cash_movements', function (Blueprint $t) {
                $t->id();
                $t->string('number', 20)->unique();
                $t->date('date');
                $t->string('kind', 12);                      // deposit withdraw rep_advance rep_return
                $t->decimal('amount', 14, 2);
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('reference', 80)->nullable();
                $t->string('note', 250)->nullable();
                $t->string('attachment_path')->nullable();
                $t->string('status', 8)->default('posted');
                $t->dateTime('voided_at')->nullable();
                $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['date', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('expenses');
    }
};
```

`app/Models/Gl/GlAccount.php`:

```php
<?php

namespace App\Models\Gl;

use App\Models\Concerns\HasBilingualName;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * حساب في الشجرة. الجذور الخمسة وحسابات النظام (`system_key`) بتتولد من
 * `GlSeeder` وقواعد الترحيل بتشاور عليها بالمفتاح — الكود ممكن يتغيّر، المفتاح لا.
 */
class GlAccount extends Model
{
    use HasBilingualName;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** نوع الجذر حسب أول رقم في الكود */
    public const ROOT_TYPES = ['1' => 'asset', '2' => 'liability', '3' => 'equity', '4' => 'revenue', '5' => 'expense'];

    public const SYSTEM_KEYS = [
        'cash_main', 'bank', 'rep_cash', 'receivables', 'withheld_tax', 'suspense',
        'payables', 'vat_output', 'opening_equity',
        'sales', 'sales_returns', 'discounts_allowed',
        'expense_purchases', 'expense_fuel', 'expense_maintenance', 'expense_salaries',
        'expense_commissions', 'expense_rent', 'expense_gifts', 'expense_other',
    ];

    protected $table = 'gl_accounts';

    protected $fillable = [
        'code', 'name', 'name_en', 'parent_id', 'type', 'normal_side',
        'is_system', 'system_key', 'user_id', 'is_postable', 'active',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'bool', 'is_postable' => 'bool', 'active' => 'bool'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlLine::class, 'account_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public static function normalSideFor(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }

    /** حساب النظام بمفتاحه — بيرمي لو الشجرة مش متولدة (شغّل GlSeeder) */
    public static function findKey(string $systemKey): self
    {
        $acc = static::where('system_key', $systemKey)->first();
        if ($acc === null) {
            throw new \RuntimeException("GL system account [{$systemKey}] missing — run GlSeeder");
        }

        return $acc;
    }

    /** حساب «نقدية مع المندوب» — بيتولد أول مرة تحت الأب rep_cash */
    public static function repCash(User $user): self
    {
        $existing = static::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }
        $parent = static::findKey('rep_cash');

        return static::create([
            'code' => $parent->code.'.'.$user->code,
            'name' => 'نقدية مع '.$user->name,
            'name_en' => 'Cash with '.($user->name_en ?: $user->name),
            'parent_id' => $parent->id,
            'type' => 'asset',
            'normal_side' => 'debit',
            'is_system' => true,
            'user_id' => $user->id,
            'is_postable' => true,
            'active' => true,
        ]);
    }

    /** كل الأحفاد (ids) بما فيهم الحساب نفسه — للتجميع في الشجرة */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $next = static::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $next);
            $frontier = $next;
        }

        return $ids;
    }

    /**
     * الرصيد الموقّع بطبيعة الحساب (مدين موجب لحسابات المدين، دائن موجب
     * لحسابات الدائن) على الفترة، شامل الأحفاد.
     */
    public function balanceBetween(?Carbon $from, ?Carbon $to): float
    {
        $q = GlLine::whereIn('gl_lines.account_id', $this->subtreeIds())
            ->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id');
        if ($from) {
            $q->whereDate('gl_entries.date', '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate('gl_entries.date', '<=', $to->toDateString());
        }
        $row = $q->selectRaw('COALESCE(SUM(gl_lines.debit),0) d, COALESCE(SUM(gl_lines.credit),0) c')->first();
        $net = (float) $row->d - (float) $row->c;

        return round($this->normal_side === 'debit' ? $net : -$net, 2);
    }
}
```

`app/Models/Gl/GlEntry.php`:

```php
<?php

namespace App\Models\Gl;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GlEntry extends Model
{
    use HasDocumentNumber;

    public const ORIGINS = ['auto', 'manual', 'reversal', 'opening'];

    protected $table = 'gl_entries';

    protected $fillable = [
        'number', 'date', 'period_key', 'memo', 'origin', 'source_type', 'source_id',
        'rule_key', 'needs_review', 'reverses_entry_id', 'created_by', 'edited_at', 'edited_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'needs_review' => 'bool', 'edited_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('JV-', 1001);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlLine::class, 'entry_id')->orderBy('id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }
}
```

`app/Models/Gl/GlLine.php`:

```php
<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlLine extends Model
{
    protected $table = 'gl_lines';

    protected $fillable = ['entry_id', 'account_id', 'debit', 'credit', 'memo', 'overridden', 'rule_account_id', 'slot'];

    protected function casts(): array
    {
        return ['overridden' => 'bool'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(GlEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'account_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(GlLineOverride::class, 'line_id');
    }
}
```

`app/Models/Gl/GlLineOverride.php`:

```php
<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;

class GlLineOverride extends Model
{
    public $timestamps = false;

    protected $table = 'gl_line_overrides';

    protected $fillable = ['line_id', 'from_account_id', 'to_account_id', 'user_id', 'at', 'note'];

    protected function casts(): array
    {
        return ['at' => 'datetime'];
    }
}
```

`app/Models/Gl/GlPostingRule.php`:

```php
<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;

class GlPostingRule extends Model
{
    protected $table = 'gl_posting_rules';

    protected $fillable = ['key', 'label', 'debit_key', 'credit_key', 'tax_key', 'active'];

    protected function casts(): array
    {
        return ['active' => 'bool'];
    }

    /** @var array<string, ?GlPostingRule>|null */
    private static ?array $cache = null;

    public static function forKey(string $key): ?self
    {
        if (self::$cache === null) {
            self::$cache = static::all()->keyBy('key')->all();
        }

        return self::$cache[$key] ?? null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }
}
```

`app/Models/Gl/GlPeriod.php`:

```php
<?php

namespace App\Models\Gl;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GlPeriod extends Model
{
    protected $table = 'gl_periods';

    protected $fillable = ['key', 'status', 'closed_at', 'closed_by', 'reopened_at', 'reopened_by'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public static function keyFor(Carbon $date): string
    {
        return $date->format('Y-m');
    }

    public static function isClosed(Carbon $date): bool
    {
        return static::where('key', self::keyFor($date))->where('status', 'closed')->exists();
    }

    public static function close(string $key, User $by): self
    {
        return static::updateOrCreate(['key' => $key], ['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);
    }

    public static function reopen(string $key, User $by): self
    {
        return static::updateOrCreate(['key' => $key], ['status' => 'open', 'reopened_at' => now(), 'reopened_by' => $by->id]);
    }
}
```

`app/Models/Expense.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Gl\GlAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سند مصروف — بيولّد قيد آلي: مدين حساب المصروف / دائن الخزنة أو البنك أو نقدية المندوب */
class Expense extends Model
{
    use HasDocumentNumber;

    public const PAID_FROM = ['cash_main', 'bank', 'rep_cash'];

    public const PAYEE_TYPES = ['supplier', 'employee', 'other'];

    protected $fillable = [
        'number', 'date', 'account_id', 'amount', 'paid_from', 'paid_from_user_id',
        'payee_type', 'payee_supplier_id', 'payee_user_id', 'payee_name',
        'reference', 'note', 'attachment_path', 'status', 'voided_at', 'voided_by', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'voided_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('EXP-', 1001);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'account_id');
    }

    public function paidFromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_from_user_id');
    }

    public function payeeSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'payee_supplier_id');
    }

    public function payeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function payeeLabel(): string
    {
        return match ($this->payee_type) {
            'supplier' => $this->payeeSupplier?->displayName() ?? '—',
            'employee' => $this->payeeUser?->displayName() ?? '—',
            default => (string) ($this->payee_name ?: '—'),
        };
    }
}
```

`app/Models/CashMovement.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة نقدية بين الخزنة والبنك ونقدية المناديب.
 * deposit: خزنة→بنك · withdraw: بنك→خزنة · rep_advance: خزنة→مندوب · rep_return: مندوب→خزنة
 */
class CashMovement extends Model
{
    use HasDocumentNumber;

    public const KINDS = ['deposit', 'withdraw', 'rep_advance', 'rep_return'];

    protected $fillable = [
        'number', 'date', 'kind', 'amount', 'user_id', 'reference', 'note',
        'attachment_path', 'status', 'voided_at', 'voided_by', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'voided_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('CM-', 1001);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`database/seeders/GlSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use Illuminate\Database\Seeder;

/**
 * الشجرة الأساسية وقواعد الترحيل — آمن يتعاد: بيعمل updateOrCreate بالكود
 * وبالمفتاح، ومابيلمسش الحسابات الحرة اللي المالك ضافها.
 */
class GlSeeder extends Seeder
{
    /** [code, name, name_en, parentCode, system_key, postable] */
    private const ACCOUNTS = [
        ['1', 'الأصول', 'Assets', null, null, false],
        ['11', 'النقدية وما في حكمها', 'Cash & equivalents', '1', null, false],
        ['1101', 'الخزنة الرئيسية', 'Main safe', '11', 'cash_main', true],
        ['1102', 'البنك', 'Bank', '11', 'bank', true],
        ['1110', 'نقدية مع المناديب', 'Cash with reps', '11', 'rep_cash', false],
        ['12', 'المدينون', 'Receivables', '1', null, false],
        ['1201', 'العملاء', 'Clients', '12', 'receivables', true],
        ['1202', 'ضرايب مخصومة تحت الحساب', 'Withholding tax receivable', '12', 'withheld_tax', true],
        ['1901', 'حساب معلّق', 'Suspense', '1', 'suspense', true],
        ['2', 'الخصوم', 'Liabilities', null, null, false],
        ['21', 'الدائنون', 'Payables', '2', null, false],
        ['2101', 'الموردون', 'Suppliers', '21', 'payables', true],
        ['22', 'الضرائب', 'Taxes', '2', null, false],
        ['2201', 'ضريبة القيمة المضافة — مخرجات', 'VAT output', '22', 'vat_output', true],
        ['3', 'حقوق الملكية', 'Equity', null, null, false],
        ['3101', 'رأس المال والأرصدة الافتتاحية', 'Capital & opening balances', '3', 'opening_equity', true],
        ['4', 'الإيرادات', 'Revenue', null, null, false],
        ['4101', 'المبيعات', 'Sales', '4', 'sales', true],
        ['4102', 'مرتجعات المبيعات', 'Sales returns', '4', 'sales_returns', true],
        ['4103', 'خصومات مسموح بها', 'Discounts allowed', '4', 'discounts_allowed', true],
        ['5', 'المصروفات', 'Expenses', null, null, false],
        ['5101', 'مشتريات بضاعة', 'Purchases', '5', 'expense_purchases', true],
        ['5102', 'بنزين ووقود', 'Fuel', '5', 'expense_fuel', true],
        ['5103', 'صيانة عربيات', 'Vehicle maintenance', '5', 'expense_maintenance', true],
        ['5104', 'رواتب وأجور', 'Salaries & wages', '5', 'expense_salaries', true],
        ['5105', 'عمولات وحوافز', 'Commissions & incentives', '5', 'expense_commissions', true],
        ['5106', 'إيجار', 'Rent', '5', 'expense_rent', true],
        ['5107', 'هدايا وعينات', 'Gifts & samples', '5', 'expense_gifts', true],
        ['5199', 'مصروفات أخرى', 'Other expenses', '5', 'expense_other', true],
    ];

    /** [key, label, debit_key, credit_key, tax_key] */
    private const RULES = [
        ['tx.sale', 'فاتورة / مبيعات', 'receivables', 'sales', 'vat_output'],
        ['tx.collection.rep_cash', 'تحصيل كاش عن طريق مندوب', 'rep_cash', 'receivables', null],
        ['tx.collection.bank', 'تحصيل تحويل / شيك / كارت', 'bank', 'receivables', null],
        ['tx.collection.office_cash', 'تحصيل كاش مكتبي', 'cash_main', 'receivables', null],
        ['tx.collection.auto', 'تحصيل مقابل فاتورة/أمر كاش', 'rep_cash', 'receivables', null],
        ['tx.return', 'مرتجع', 'sales_returns', 'receivables', 'vat_output'],
        ['tx.refund', 'رد فلوس مرتجع كاش', 'receivables', 'rep_cash', null],
        ['tx.rebate', 'خصم تجاري', 'discounts_allowed', 'receivables', null],
        ['tx.settlement', 'تسوية / مقاصة', 'discounts_allowed', 'receivables', null],
        ['tx.transfer', 'قيد تحويل', 'receivables', 'suspense', null],
        ['tx.taxded', 'ضرايب مخصومة تحت الحساب', 'withheld_tax', 'receivables', null],
        ['tx.opening', 'رصيد افتتاحي عميل', 'receivables', 'opening_equity', null],
        ['settle.received', 'تصفية مندوب — المستلم', 'cash_main', 'rep_cash', null],
        ['sup.invoice', 'فاتورة مورد', 'expense_purchases', 'payables', null],
        ['sup.payment.cash', 'دفعة مورد كاش', 'payables', 'cash_main', null],
        ['sup.payment.bank', 'دفعة مورد تحويل / شيك', 'payables', 'bank', null],
        ['sup.opening', 'رصيد افتتاحي مورد', 'opening_equity', 'payables', null],
        ['sup.adjust', 'تسوية مورد', 'payables', 'suspense', null],
        ['expense.cash_main', 'مصروف من الخزنة', null, 'cash_main', null],
        ['expense.bank', 'مصروف من البنك', null, 'bank', null],
        ['expense.rep_cash', 'مصروف من نقدية مندوب', null, 'rep_cash', null],
        ['cash.deposit', 'إيداع خزنة → بنك', 'bank', 'cash_main', null],
        ['cash.withdraw', 'سحب بنك → خزنة', 'cash_main', 'bank', null],
        ['cash.rep_advance', 'عهدة نقدية خزنة → مندوب', 'rep_cash', 'cash_main', null],
        ['cash.rep_return', 'رد عهدة مندوب → خزنة', 'cash_main', 'rep_cash', null],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $en, $parentCode, $key, $postable]) {
            $parent = $parentCode ? GlAccount::where('code', $parentCode)->first() : null;
            $type = GlAccount::ROOT_TYPES[$code[0]];
            $acc = GlAccount::where('code', $code)->first()
                ?? ($key ? GlAccount::where('system_key', $key)->first() : null);
            $attrs = [
                'parent_id' => $parent?->id, 'type' => $type,
                'normal_side' => GlAccount::normalSideFor($type),
                'is_system' => true, 'system_key' => $key, 'is_postable' => $postable,
            ];
            if ($acc) {
                // الاسم اللي المالك غيّره مايتداسش
                $acc->fill($attrs)->save();
            } else {
                GlAccount::create($attrs + ['code' => $code, 'name' => $name, 'name_en' => $en, 'active' => true]);
            }
        }

        foreach (self::RULES as [$key, $label, $dr, $cr, $tax]) {
            GlPostingRule::firstOrCreate(['key' => $key], [
                'label' => $label, 'debit_key' => $dr, 'credit_key' => $cr, 'tax_key' => $tax, 'active' => true,
            ]);
        }
        GlPostingRule::flush();
    }
}
```

In `database/seeders/DatabaseSeeder.php`, add `$this->call(GlSeeder::class);` right after the seeder that creates users (grep `UserSeeder` / `TeamSeeder`; if none, add as the first call).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter GlSchemaTest`
Expected: PASS (2 tests). Also run `php artisan test --filter SeedersRunTest` — must stay green (the seeder is idempotent).

- [ ] **Step 5: Commit**

```bash
git add database app/Models tests/Feature/Gl/GlSchemaTest.php
git commit -m "GL: chart-of-accounts schema, models and idempotent seeder"
```

---

### Task 2: Posting rules and automatic entries from `transactions`

**Files:**
- Create: `app/Services/Gl/Rules.php`, `app/Services/Gl/Ledger.php`, `app/Services/Gl/UnbalancedEntry.php`
- Create: `app/Observers/Gl/TransactionObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (register observer in `boot()`)
- Test: `tests/Feature/Gl/GlPostingTest.php`

**Interfaces:**
- Consumes: Task 1 models; `Transaction` (`kind`, `debit`, `credit`, `tax`, `method`, `source_type`, `source_id`, `date`, `memo`, `client_id`), `Setting::read`.
- Produces:
  - `Ledger::enabledFor(Carbon $date): bool` — `Setting::read('gl_enabled') === '1'` and `date >= gl_start_date` (start date empty = everything).
  - `Ledger::post(Model $source, ?User $by = null): ?GlEntry` — idempotent by (`source_type`,`source_id`,`origin=auto`); returns null when not enabled, when `Rules::linesFor` returns empty, or when the entry exists.
  - `Ledger::unpost(Model $source): void`, `Ledger::repost(Model $source): ?GlEntry` (unpost then post, keeping overrides — implemented fully in Task 4; here repost = unpost+post).
  - `Rules::linesFor(Model $source): array{rule: string, memo: string, needs_review: bool, lines: list<array{slot:string, account_id:int, debit:float, credit:float}>}`.
  - `Rules::repFor(Transaction $tx): ?User`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Gl/GlPostingTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlPostingTest extends TestCase
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

    private function entryFor(Transaction $tx): GlEntry
    {
        $e = GlEntry::where('source_type', $tx->getMorphClass())->where('source_id', $tx->id)->where('origin', 'auto')->first();
        $this->assertNotNull($e, 'entry posted for '.$tx->kind);
        $this->assertSame($e->totalDebit(), $e->totalCredit(), 'balanced');

        return $e;
    }

    private function lineOn(GlEntry $e, string $key): array
    {
        $acc = GlAccount::findKey($key);
        $l = $e->lines->firstWhere('account_id', $acc->id);
        $this->assertNotNull($l, "line on {$key}");

        return [(float) $l->debit, (float) $l->credit];
    }

    public function test_a_credit_sale_with_tax_debits_receivables_and_splits_sales_and_vat(): void
    {
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => '2026-09-01', 'memo' => 'فاتورة', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);

        $e = $this->entryFor($tx);
        $this->assertSame([1140.0, 0.0], $this->lineOn($e, 'receivables'));
        $this->assertSame([0.0, 1000.0], $this->lineOn($e, 'sales'));
        $this->assertSame([0.0, 140.0], $this->lineOn($e, 'vat_output'));
        $this->assertSame('tx.sale', $e->rule_key);
        $this->assertSame('2026-09', $e->period_key);
    }

    public function test_a_field_cash_collection_goes_to_the_reps_cash_account(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $visit = Visit::create(['user_id' => $rep->id, 'client_id' => $client->id, 'checked_in_at' => now()]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحصيل', 'debit' => 0, 'credit' => 500, 'kind' => 'collection', 'method' => 'cash', 'source_type' => Visit::class, 'source_id' => $visit->id]);

        $e = $this->entryFor($tx);
        $repAcc = GlAccount::repCash($rep);
        $this->assertSame(500.0, (float) $e->lines->firstWhere('account_id', $repAcc->id)->debit);
        $this->assertSame([0.0, 500.0], $this->lineOn($e, 'receivables'));
    }

    public function test_a_bank_transfer_collection_and_a_direct_office_cash_collection(): void
    {
        $client = $this->makeClient();
        $bank = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحويل', 'debit' => 0, 'credit' => 700, 'kind' => 'collection', 'method' => 'transfer', 'reference' => 'TRX']);
        $cash = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'كاش مكتب', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);

        $this->assertSame([700.0, 0.0], $this->lineOn($this->entryFor($bank), 'bank'));
        $this->assertSame([300.0, 0.0], $this->lineOn($this->entryFor($cash), 'cash_main'));
    }

    public function test_the_automatic_collection_behind_a_cash_invoice_lands_on_the_invoice_rep(): void
    {
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $inv = Invoice::create(['number' => 'INV-90001', 'client_id' => $client->id, 'user_id' => $rep->id, 'payment' => 'cash', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'tax_total' => 0, 'grand_total' => 100]);
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'مقابل فاتورة كاش', 'debit' => 0, 'credit' => 100, 'kind' => 'collection', 'source_type' => Invoice::class, 'source_id' => $inv->id]);

        $e = $this->entryFor($tx);
        $this->assertSame('tx.collection.auto', $e->rule_key);
        $this->assertSame(100.0, (float) $e->lines->firstWhere('account_id', GlAccount::repCash($rep)->id)->debit);
    }

    public function test_return_refund_rebate_taxded_and_opening_post_to_their_accounts(): void
    {
        $client = $this->makeClient();
        $mk = fn (array $a) => Transaction::create(array_merge(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $a));

        $ret = $this->entryFor($mk(['kind' => 'return', 'credit' => 228, 'tax' => 28]));
        $this->assertSame([200.0, 0.0], $this->lineOn($ret, 'sales_returns'));
        $this->assertSame([28.0, 0.0], $this->lineOn($ret, 'vat_output'));
        $this->assertSame([0.0, 228.0], $this->lineOn($ret, 'receivables'));

        $refund = $this->entryFor($mk(['kind' => 'refund', 'debit' => 50]));
        $this->assertSame([50.0, 0.0], $this->lineOn($refund, 'receivables'));
        $this->assertSame([0.0, 50.0], $this->lineOn($refund, 'cash_main')); // مفيش مندوب → الخزنة + needs_review
        $this->assertTrue($refund->needs_review);

        $this->assertSame([40.0, 0.0], $this->lineOn($this->entryFor($mk(['kind' => 'rebate', 'credit' => 40])), 'discounts_allowed'));
        $this->assertSame([30.0, 0.0], $this->lineOn($this->entryFor($mk(['kind' => 'taxded', 'credit' => 30])), 'withheld_tax'));

        $open = $this->entryFor($mk(['kind' => 'opening', 'debit' => 900]));
        $this->assertSame([900.0, 0.0], $this->lineOn($open, 'receivables'));
        $this->assertSame([0.0, 900.0], $this->lineOn($open, 'opening_equity'));
    }

    public function test_consignment_and_rows_before_the_start_date_or_with_the_switch_off_are_not_posted(): void
    {
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'أمانة', 'debit' => 0, 'credit' => 0, 'kind' => 'consignment']);
        Transaction::create(['client_id' => $client->id, 'date' => '2025-12-31', 'memo' => 'قديم', 'debit' => 10, 'credit' => 0, 'kind' => 'sale']);
        Setting::write('gl_enabled', '0');
        Setting::flushCache();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'مقفول', 'debit' => 10, 'credit' => 0, 'kind' => 'sale']);

        $this->assertSame(0, GlEntry::count());
    }

    public function test_posting_is_idempotent_and_deleting_the_row_removes_the_entry(): void
    {
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 10, 'credit' => 0, 'kind' => 'sale']);
        app(Ledger::class)->post($tx);
        app(Ledger::class)->post($tx);
        $this->assertSame(1, GlEntry::count());

        $tx->delete();
        $this->assertSame(0, GlEntry::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GlPostingTest`
Expected: FAIL — entries not found (`entry posted for sale`).

- [ ] **Step 3: Write Rules, Ledger, exception and observer**

`app/Services/Gl/UnbalancedEntry.php`:

```php
<?php

namespace App\Services\Gl;

class UnbalancedEntry extends \RuntimeException
{
}
```

`app/Services/Gl/Rules.php`:

```php
<?php

namespace App\Services\Gl;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\RepSettlement;
use App\Models\SupplierTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Model;

/**
 * تحويل مستند → سطور قيد حسب `gl_posting_rules`. مفيش كتابة هنا.
 * المفاتيح (`debit_key`/`credit_key`) بتشاور على `gl_accounts.system_key`؛
 * المفتاح الخاص `rep_cash` بيتحل لحساب المندوب المستنتج من المستند،
 * ولو مفيش مندوب بيرجع للخزنة الرئيسية ويعلّم القيد `needs_review`.
 */
class Rules
{
    /** @return array{rule:string, memo:string, needs_review:bool, lines:list<array{slot:string,account_id:int,debit:float,credit:float}>} */
    public function linesFor(Model $source): array
    {
        return match (true) {
            $source instanceof Transaction => $this->transaction($source),
            $source instanceof RepSettlement => $this->settlement($source),
            $source instanceof SupplierTransaction => $this->supplier($source),
            $source instanceof Expense => $this->expense($source),
            $source instanceof CashMovement => $this->cash($source),
            default => $this->none(),
        };
    }

    private function none(): array
    {
        return ['rule' => '', 'memo' => '', 'needs_review' => false, 'lines' => []];
    }

    /** مندوب التحصيل من مصدر القيد */
    public function repFor(Transaction $tx): ?User
    {
        return match ($tx->source_type) {
            Visit::class => Visit::find($tx->source_id)?->user,
            Invoice::class => Invoice::find($tx->source_id)?->user,
            PurchaseOrder::class => ($po = PurchaseOrder::find($tx->source_id)) && $po->assigned_to ? User::find($po->assigned_to) : null,
            User::class => User::find($tx->source_id),
            default => null,
        };
    }

    private function transaction(Transaction $tx): array
    {
        $amount = round((float) $tx->debit + (float) $tx->credit, 2);
        if ($tx->kind === 'consignment' || $amount == 0.0) {
            return $this->none();
        }

        $key = match ($tx->kind) {
            'collection' => match (true) {
                $tx->method === null || $tx->method === '' => 'tx.collection.auto',
                $tx->method === Transaction::METHOD_CASH && $tx->source_type !== null => 'tx.collection.rep_cash',
                $tx->method === Transaction::METHOD_CASH => 'tx.collection.office_cash',
                default => 'tx.collection.bank',
            },
            default => 'tx.'.$tx->kind,
        };

        $rule = GlPostingRule::forKey($key);
        if ($rule === null || ! $rule->active) {
            return $this->none();
        }

        $tax = round((float) ($tx->tax ?? 0), 2);
        $needsReview = false;
        $resolve = function (?string $k) use ($tx, &$needsReview): ?int {
            if ($k === null) {
                return null;
            }
            if ($k === 'rep_cash') {
                $rep = $this->repFor($tx);
                if ($rep === null) {
                    $needsReview = true;

                    return GlAccount::findKey('cash_main')->id;
                }

                return GlAccount::repCash($rep)->id;
            }

            return GlAccount::findKey($k)->id;
        };

        // الاتجاه بالإشارة: debit>0 → المدين هو debit_key، وإلا القيد معكوس
        // (opening/transfer ممكن يبقوا دائن)
        $isDebit = (float) $tx->debit > 0;
        $drKey = $isDebit ? $rule->debit_key : $rule->credit_key;
        $crKey = $isDebit ? $rule->credit_key : $rule->debit_key;
        // ⚠️ القاعدة اللي مكتوبة أصلاً كقيد دائن (collection/return/...) الإشارة
        // بتاعتها credit>0 وهي الطبيعي — فمانقلبش إلا لو النوع ثنائي الاتجاه
        if (! in_array($tx->kind, ['opening', 'transfer'], true)) {
            $drKey = $rule->debit_key;
            $crKey = $rule->credit_key;
        }

        $lines = [['slot' => 'dr', 'account_id' => $resolve($drKey), 'debit' => $amount, 'credit' => 0.0]];
        if ($rule->tax_key && $tax > 0) {
            // الضريبة بتتفصل عن الطرف «الإيراد/المرتجع» مش عن العملاء
            if ($tx->kind === 'sale') {
                $lines[] = ['slot' => 'cr', 'account_id' => $resolve($crKey), 'debit' => 0.0, 'credit' => round($amount - $tax, 2)];
                $lines[] = ['slot' => 'tax', 'account_id' => $resolve($rule->tax_key), 'debit' => 0.0, 'credit' => $tax];
            } else { // return: المدين هو المرتجعات + الضريبة، الدائن العملاء
                $lines = [
                    ['slot' => 'dr', 'account_id' => $resolve($drKey), 'debit' => round($amount - $tax, 2), 'credit' => 0.0],
                    ['slot' => 'tax', 'account_id' => $resolve($rule->tax_key), 'debit' => $tax, 'credit' => 0.0],
                    ['slot' => 'cr', 'account_id' => $resolve($crKey), 'debit' => 0.0, 'credit' => $amount],
                ];
            }
        } else {
            $lines[] = ['slot' => 'cr', 'account_id' => $resolve($crKey), 'debit' => 0.0, 'credit' => $amount];
        }

        return [
            'rule' => $key,
            'memo' => (string) ($tx->memo ?: Transaction::KINDS[$tx->kind] ?? $tx->kind),
            'needs_review' => $needsReview,
            'lines' => $lines,
        ];
    }

    private function settlement(RepSettlement $s): array
    {
        $received = round((float) $s->received, 2);
        $rule = GlPostingRule::forKey('settle.received');
        if ($received <= 0 || ! $rule || ! $rule->active || ! $s->user) {
            return $this->none();
        }

        return [
            'rule' => 'settle.received', 'needs_review' => false,
            'memo' => 'تصفية '.$s->number.' — '.$s->user->displayName(),
            'lines' => [
                ['slot' => 'dr', 'account_id' => GlAccount::findKey($rule->debit_key)->id, 'debit' => $received, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => GlAccount::repCash($s->user)->id, 'debit' => 0.0, 'credit' => $received],
            ],
        ];
    }

    private function supplier(SupplierTransaction $st): array
    {
        $amount = round((float) $st->debit + (float) $st->credit, 2);
        if ($amount == 0.0) {
            return $this->none();
        }
        $key = 'sup.'.$st->kind;
        if ($st->kind === 'payment') {
            $method = $st->source_type === \App\Models\SupplierPayment::class
                ? (\App\Models\SupplierPayment::find($st->source_id)?->method ?? 'cash') : 'cash';
            $key = $method === 'cash' ? 'sup.payment.cash' : 'sup.payment.bank';
        }
        $rule = GlPostingRule::forKey($key);
        if (! $rule || ! $rule->active) {
            return $this->none();
        }
        // في دفتر المورد: credit = علينا له (فاتورة)، debit = دفعنا له. المفاتيح مكتوبة
        // على الاتجاه الطبيعي لكل نوع؛ opening/adjust بيتقلبوا لو الإشارة معكوسة
        $dr = $rule->debit_key;
        $cr = $rule->credit_key;
        if (in_array($st->kind, ['opening', 'adjust'], true) && (float) $st->debit > 0) {
            [$dr, $cr] = [$cr, $dr];
        }

        return [
            'rule' => $key, 'needs_review' => false,
            'memo' => (string) ($st->memo ?: $rule->label),
            'lines' => [
                ['slot' => 'dr', 'account_id' => GlAccount::findKey($dr)->id, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => GlAccount::findKey($cr)->id, 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }

    private function expense(Expense $x): array
    {
        if ($x->status !== 'posted') {
            return $this->none();
        }
        $rule = GlPostingRule::forKey('expense.'.$x->paid_from);
        if (! $rule || ! $rule->active) {
            return $this->none();
        }
        $creditAcc = $x->paid_from === 'rep_cash' && $x->paidFromUser
            ? GlAccount::repCash($x->paidFromUser)
            : GlAccount::findKey($rule->credit_key === 'rep_cash' ? 'cash_main' : $rule->credit_key);
        $amount = round((float) $x->amount, 2);

        return [
            'rule' => 'expense.'.$x->paid_from, 'needs_review' => false,
            'memo' => $x->number.' — '.($x->note ?: $x->account?->displayName()),
            'lines' => [
                ['slot' => 'dr', 'account_id' => $x->account_id, 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $creditAcc->id, 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }

    private function cash(CashMovement $m): array
    {
        if ($m->status !== 'posted') {
            return $this->none();
        }
        $rule = GlPostingRule::forKey('cash.'.$m->kind);
        if (! $rule || ! $rule->active) {
            return $this->none();
        }
        $res = fn (string $k) => $k === 'rep_cash' && $m->user ? GlAccount::repCash($m->user)->id : GlAccount::findKey($k === 'rep_cash' ? 'cash_main' : $k)->id;
        $amount = round((float) $m->amount, 2);

        return [
            'rule' => 'cash.'.$m->kind, 'needs_review' => false,
            'memo' => $m->number.' — '.$rule->label,
            'lines' => [
                ['slot' => 'dr', 'account_id' => $res($rule->debit_key), 'debit' => $amount, 'credit' => 0.0],
                ['slot' => 'cr', 'account_id' => $res($rule->credit_key), 'debit' => 0.0, 'credit' => $amount],
            ],
        ];
    }
}
```

`app/Services/Gl/Ledger.php` (Task 2 scope — manual/reverse/override/rebuild are added in Tasks 3–4):

```php
<?php

namespace App\Services\Gl;

use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ المكان الوحيد اللي بيكتب في دفتر الأستاذ ═══
 * post/unpost/repost للقيود الآلية · manual/reverse/overrideAccount للتصحيح ·
 * rebuild لإعادة البناء. كل كتابة داخل ترانزاكشن وكل قيد لازم يتوازن.
 */
class Ledger
{
    public function __construct(private Rules $rules)
    {
    }

    public function enabledFor(Carbon $date): bool
    {
        if (Setting::read('gl_enabled') !== '1') {
            return false;
        }
        $start = Setting::read('gl_start_date');

        return ! $start || $date->toDateString() >= $start;
    }

    /** تاريخ المستند اللي القيد بياخده */
    public static function dateOf(Model $source): Carbon
    {
        $d = $source->date ?? $source->to_at ?? $source->created_at ?? now();

        return Carbon::parse($d);
    }

    public function post(Model $source, ?User $by = null): ?GlEntry
    {
        $date = self::dateOf($source);
        if (! $this->enabledFor($date)) {
            return null;
        }
        $existing = $this->autoEntryFor($source);
        if ($existing) {
            return $existing;
        }
        $spec = $this->rules->linesFor($source);
        if ($spec['lines'] === []) {
            return null;
        }

        return DB::transaction(function () use ($source, $date, $spec, $by) {
            $entry = GlEntry::create([
                'number' => GlEntry::nextNumber(),
                'date' => $date->toDateString(),
                'period_key' => GlPeriod::keyFor($date),
                'memo' => mb_substr($spec['memo'], 0, 250),
                'origin' => 'auto',
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'rule_key' => $spec['rule'],
                'needs_review' => $spec['needs_review'],
                'created_by' => $by?->id,
            ]);
            $this->writeLines($entry, $spec['lines']);

            return $entry->load('lines');
        });
    }

    public function unpost(Model $source): void
    {
        $this->autoEntryFor($source)?->delete();
    }

    public function repost(Model $source, ?User $by = null): ?GlEntry
    {
        $this->unpost($source);

        return $this->post($source, $by);
    }

    public function autoEntryFor(Model $source): ?GlEntry
    {
        return GlEntry::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())->where('origin', 'auto')->first();
    }

    /** @param list<array{slot?:string,account_id:int,debit:float,credit:float,memo?:string,rule_account_id?:int,overridden?:bool}> $lines */
    protected function writeLines(GlEntry $entry, array $lines): void
    {
        $dr = round(array_sum(array_column($lines, 'debit')), 2);
        $cr = round(array_sum(array_column($lines, 'credit')), 2);
        if ($dr !== $cr || $dr <= 0) {
            throw new UnbalancedEntry("Entry {$entry->number}: debit {$dr} != credit {$cr}");
        }
        foreach ($lines as $l) {
            GlLine::create([
                'entry_id' => $entry->id,
                'account_id' => $l['account_id'],
                'debit' => round($l['debit'], 2),
                'credit' => round($l['credit'], 2),
                'memo' => $l['memo'] ?? null,
                'slot' => $l['slot'] ?? null,
                'rule_account_id' => $l['rule_account_id'] ?? $l['account_id'],
                'overridden' => $l['overridden'] ?? false,
            ]);
        }
    }
}
```

`app/Observers/Gl/TransactionObserver.php`:

```php
<?php

namespace App\Observers\Gl;

use App\Models\Transaction;
use App\Services\Gl\Ledger;

/**
 * كل صف في `transactions` = قيد يومية. الإنشاء بيرحّل، والمسح بيشيل القيد.
 * ⚠️ التعديلات اللي بتمشي بـ`whereKey()->update()` (تعديل تاريخ الفاتورة،
 * الترقيم، التحويل لعميل تاني) مابتمرش هنا — أدوات الفاتورة بتنادي
 * `Ledger::repost` صراحةً (Task 5).
 */
class TransactionObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(Transaction $tx): void
    {
        $this->ledger->post($tx);
    }

    public function updated(Transaction $tx): void
    {
        if ($tx->wasChanged(['debit', 'credit', 'tax', 'kind', 'method', 'date', 'source_type', 'source_id', 'client_id'])) {
            $this->ledger->repost($tx);
        }
    }

    public function deleted(Transaction $tx): void
    {
        $this->ledger->unpost($tx);
    }
}
```

In `app/Providers/AppServiceProvider.php` `boot()` add:

```php
\App\Models\Transaction::observe(\App\Observers\Gl\TransactionObserver::class);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter GlPostingTest`
Expected: PASS (7 tests). Then run the money-flow guards: `php artisan test --filter 'DirectCollectionTest|ReturnsCycleTest|ClientFlowTest|SeedersRunTest|ScopeGuardTest'` — all green (the observer is inert while `gl_enabled` is unset).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Gl app/Observers app/Providers/AppServiceProvider.php tests/Feature/Gl/GlPostingTest.php
git commit -m "GL: posting rules and automatic journal entries from the client ledger"
```

---

### Task 3: Manual entries, reversal, account override and periods

**Files:**
- Modify: `app/Services/Gl/Ledger.php` (add `manual`, `reverse`, `overrideAccount`, `assertOpen`)
- Create: `app/Services/Gl/ClosedPeriod.php` (exception)
- Test: `tests/Feature/Gl/GlCorrectionTest.php`

**Interfaces:**
- Produces:
  - `Ledger::manual(Carbon $date, string $memo, array $lines, User $by, string $origin = 'manual'): GlEntry` — `$lines` items `['account_id'=>int,'debit'=>float,'credit'=>float,'memo'=>?string]`; throws `UnbalancedEntry` / `ClosedPeriod`.
  - `Ledger::reverse(GlEntry $entry, User $by, ?Carbon $date = null, ?string $memo = null): GlEntry` — origin `reversal`, `reverses_entry_id`, lines swapped, date defaults to today; refuses if `$date` is closed.
  - `Ledger::overrideAccount(GlLine $line, GlAccount $to, User $by, ?string $note = null): GlEntry` — open period: updates the line in place, `overridden=true`, writes `gl_line_overrides`, stamps `edited_at/by`; closed period: `reverse($entry)` + a new `manual` entry (origin `manual`, memo «تصحيح JV-xxxx») with the corrected account, dated today; returns the entry now carrying the correct posting.
  - `Ledger::assertOpen(Carbon $date): void` throws `ClosedPeriod`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Gl/GlCorrectionTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLineOverride;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\Ledger;
use App\Services\Gl\UnbalancedEntry;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
    }

    public function test_a_manual_entry_must_balance(): void
    {
        $admin = $this->makeAdmin();
        $cash = GlAccount::findKey('cash_main');
        $rent = GlAccount::findKey('expense_rent');

        $this->expectException(UnbalancedEntry::class);
        app(Ledger::class)->manual(today(), 'إيجار', [
            ['account_id' => $rent->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 900],
        ], $admin);
    }

    public function test_a_balanced_manual_entry_is_numbered_and_stored(): void
    {
        $admin = $this->makeAdmin();
        $e = app(Ledger::class)->manual(today(), 'إيجار', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ], $admin);

        $this->assertStringStartsWith('JV-', $e->number);
        $this->assertSame('manual', $e->origin);
        $this->assertSame($admin->id, $e->created_by);
        $this->assertSame(1000.0, GlAccount::findKey('expense_rent')->balanceBetween(null, null));
        $this->assertSame(-1000.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }

    public function test_override_in_an_open_period_edits_in_place_and_logs_the_audit(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $entry = app(Ledger::class)->autoEntryFor($tx);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $result = app(Ledger::class)->overrideAccount($line, GlAccount::findKey('bank'), $admin, 'كان تحويل');

        $this->assertSame($entry->id, $result->id, 'same entry, edited in place');
        $line->refresh();
        $this->assertSame(GlAccount::findKey('bank')->id, $line->account_id);
        $this->assertTrue($line->overridden);
        $this->assertSame(GlAccount::findKey('cash_main')->id, $line->rule_account_id);
        $this->assertSame(1, GlLineOverride::where('line_id', $line->id)->count());
        $this->assertNotNull($entry->fresh()->edited_at);
    }

    public function test_override_in_a_closed_period_reverses_and_reposts_today(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $old = Carbon::parse('2026-07-15');
        $tx = Transaction::create(['client_id' => $client->id, 'date' => $old, 'memo' => 'x', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $entry = app(Ledger::class)->autoEntryFor($tx);
        GlPeriod::close('2026-07', $admin);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $fixed = app(Ledger::class)->overrideAccount($line, GlAccount::findKey('bank'), $admin);

        $this->assertSame(3, GlEntry::count(), 'original + reversal + corrected');
        $reversal = GlEntry::where('origin', 'reversal')->first();
        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertSame(today()->toDateString(), $reversal->date->toDateString());
        $this->assertNotSame($entry->id, $fixed->id);
        // الأصل ماتلمسش
        $this->assertSame(GlAccount::findKey('cash_main')->id, $line->fresh()->account_id);
        // الصافي: الخزنة صفر، البنك 300، العملاء −300
        $this->assertSame(0.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
        $this->assertSame(300.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(-300.0, GlAccount::findKey('receivables')->balanceBetween(null, null));
    }

    public function test_manual_entries_dated_inside_a_closed_period_are_refused(): void
    {
        $admin = $this->makeAdmin();
        GlPeriod::close('2026-07', $admin);

        $this->expectException(ClosedPeriod::class);
        app(Ledger::class)->manual(Carbon::parse('2026-07-20'), 'x', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 10, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 10],
        ], $admin);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GlCorrectionTest`
Expected: FAIL — `Call to undefined method Ledger::manual()`.

- [ ] **Step 3: Add the methods**

`app/Services/Gl/ClosedPeriod.php`:

```php
<?php

namespace App\Services\Gl;

class ClosedPeriod extends \RuntimeException
{
}
```

Append to `Ledger` (inside the class):

```php
    public function assertOpen(Carbon $date): void
    {
        if (GlPeriod::isClosed($date)) {
            throw new ClosedPeriod(__('gl.period_closed', ['period' => GlPeriod::keyFor($date)]));
        }
    }

    /** قيد يدوي — سطور حرة، لازم تتوازن، الفترة لازم تكون مفتوحة */
    public function manual(Carbon $date, string $memo, array $lines, User $by, string $origin = 'manual', ?int $reversesId = null): GlEntry
    {
        $this->assertOpen($date);

        return DB::transaction(function () use ($date, $memo, $lines, $by, $origin, $reversesId) {
            $entry = GlEntry::create([
                'number' => GlEntry::nextNumber(),
                'date' => $date->toDateString(),
                'period_key' => GlPeriod::keyFor($date),
                'memo' => mb_substr($memo, 0, 250),
                'origin' => $origin,
                'reverses_entry_id' => $reversesId,
                'created_by' => $by->id,
            ]);
            $this->writeLines($entry, array_map(fn ($l) => [
                'account_id' => (int) $l['account_id'],
                'debit' => (float) ($l['debit'] ?? 0),
                'credit' => (float) ($l['credit'] ?? 0),
                'memo' => $l['memo'] ?? null,
            ], array_values($lines)));

            return $entry->load('lines');
        });
    }

    /** قيد عكسي بتاريخ النهاردة (أو تاريخ مفتوح تختاره) */
    public function reverse(GlEntry $entry, User $by, ?Carbon $date = null, ?string $memo = null): GlEntry
    {
        $date ??= today();
        $lines = $entry->lines->map(fn (GlLine $l) => [
            'account_id' => $l->account_id, 'debit' => (float) $l->credit, 'credit' => (float) $l->debit,
        ])->all();

        return $this->manual($date, $memo ?? __('gl.reversal_of', ['number' => $entry->number]), $lines, $by, 'reversal', $entry->id);
    }

    /**
     * تغيير حساب سطر: في الفترة المفتوحة تعديل في مكانه بسجل تدقيق؛ في
     * الفترة المقفولة قيد عكسي + قيد صحيح بتاريخ النهاردة. بيرجّع القيد
     * اللي فيه الترحيل الصحيح دلوقتي.
     */
    public function overrideAccount(GlLine $line, GlAccount $to, User $by, ?string $note = null): GlEntry
    {
        $entry = $line->entry;
        if ($line->account_id === $to->id) {
            return $entry;
        }
        if (! $to->is_postable || ! $to->active) {
            throw new \InvalidArgumentException(__('gl.account_not_postable'));
        }

        if (! GlPeriod::isClosed($entry->date)) {
            return DB::transaction(function () use ($line, $to, $by, $note, $entry) {
                GlLineOverride::create([
                    'line_id' => $line->id, 'from_account_id' => $line->account_id, 'to_account_id' => $to->id,
                    'user_id' => $by->id, 'at' => now(), 'note' => $note,
                ]);
                $line->update(['account_id' => $to->id, 'overridden' => true, 'rule_account_id' => $line->rule_account_id ?? $line->account_id]);
                $entry->update(['edited_at' => now(), 'edited_by' => $by->id]);

                return $entry->fresh('lines');
            });
        }

        return DB::transaction(function () use ($line, $to, $by, $note, $entry) {
            $this->reverse($entry, $by);
            $lines = $entry->lines->map(fn (GlLine $l) => [
                'account_id' => $l->id === $line->id ? $to->id : $l->account_id,
                'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
            ])->all();

            return $this->manual(today(), __('gl.correction_of', ['number' => $entry->number]).($note ? ' — '.$note : ''), $lines, $by, 'manual', $entry->id);
        });
    }
```

Add `use App\Models\Gl\GlAccount; use App\Models\Gl\GlLineOverride;` to the Ledger imports.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter 'GlCorrectionTest|GlPostingTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Gl tests/Feature/Gl/GlCorrectionTest.php
git commit -m "GL: manual entries, reversals, audited account override, period lock"
```

---

### Task 4: Rebuild with preview, override preservation and the receivables invariant

**Files:**
- Modify: `app/Services/Gl/Ledger.php` (add `rebuild`, `sources`, `invariants`)
- Create: `app/Services/Gl/RebuildReport.php` (value object)
- Test: `tests/Feature/Gl/GlRebuildTest.php`

**Interfaces:**
- Produces:
  - `Ledger::rebuild(Carbon $from, bool $keepOverrides = true, bool $dryRun = false, ?User $by = null): RebuildReport` — inside one `DB::transaction`; on `dryRun` rolls back at the end and returns the report; on invariant failure throws `\RuntimeException` after rollback with the report attached (`$e->report`).
  - `RebuildReport` public props: `int $deleted`, `int $created`, `int $overridesKept`, `int $overridesDropped`, `array $accountDiff` (`account_code => ['before'=>float,'after'=>float]` only where changed), `array $invariants` (`['receivables' => ['gl'=>float,'clients'=>float,'ok'=>bool], 'payables' => [...]]`), `bool $ok`.
  - `Ledger::invariants(): array` — same structure; `receivables.gl` = `GlAccount::findKey('receivables')->balanceBetween(null,null)` (as of everything posted), `receivables.clients` = `(float) DB::table('clients')->sum('balance')` **plus** the sum of client balances that come from rows before `gl_start_date` must be excluded — so the check compares the GL receivables balance against `SUM(transactions.debit − credit)` for rows with `date >= gl_start_date` (this is exact by construction; `clients.balance` equals it when start date is empty). `payables.gl` vs `SUM(supplier_transactions.credit − debit)` for rows `>= start`.
  - `Ledger::sources(Carbon $from): \Generator` yields models ordered by date then id: `Transaction`, `RepSettlement` (by `to_at`), `SupplierTransaction`, `Expense` (posted), `CashMovement` (posted).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Gl/GlRebuildTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlRebuildTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::write('gl_start_date', '2026-08-01');
        Setting::flushCache();
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $mk = fn (array $a) => Transaction::create(array_merge(['client_id' => $client->id, 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $a));
        $mk(['date' => '2026-07-10', 'kind' => 'sale', 'debit' => 5000]);            // قبل البداية — مايتقيدش
        $mk(['date' => '2026-08-05', 'kind' => 'sale', 'debit' => 1140, 'tax' => 140]);
        $coll = $mk(['date' => '2026-08-20', 'kind' => 'collection', 'credit' => 600, 'method' => 'cash']);
        $mk(['date' => '2026-09-02', 'kind' => 'return', 'credit' => 114, 'tax' => 14]);
        $client->recalculate();

        return [$admin, $client, $coll];
    }

    private function balances(): array
    {
        return GlAccount::where('is_postable', true)->get()
            ->mapWithKeys(fn ($a) => [$a->code => $a->balanceBetween(null, null)])->all();
    }

    public function test_rebuild_reproduces_the_same_balances_and_the_invariant_holds(): void
    {
        [$admin] = $this->world();
        $before = $this->balances();
        $this->assertSame(3, GlEntry::count(), 'the July row is before the start date');

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);

        $this->assertTrue($report->ok);
        $this->assertSame(3, $report->deleted);
        $this->assertSame(3, $report->created);
        $this->assertSame($before, $this->balances());
        $this->assertTrue($report->invariants['receivables']['ok']);
        $this->assertSame(1140.0 - 600.0 - 114.0, $report->invariants['receivables']['gl']);
    }

    public function test_dry_run_reports_the_effect_of_a_changed_rule_without_writing(): void
    {
        [$admin] = $this->world();
        GlPostingRule::where('key', 'tx.collection.office_cash')->update(['debit_key' => 'bank']);
        GlPostingRule::flush();
        $before = $this->balances();

        $report = app(Ledger::class)->rebuild(Carbon::parse('2026-08-01'), true, true, $admin);

        $this->assertSame($before, $this->balances(), 'dry run writes nothing');
        $this->assertSame(['before' => -600.0, 'after' => 0.0], $report->accountDiff['1101']);
        $this->assertSame(['before' => 0.0, 'after' => 600.0], $report->accountDiff['1102']);
    }

    public function test_rebuild_keeps_manual_overrides_unless_asked_to_drop_them(): void
    {
        [$admin, , $coll] = $this->world();
        $ledger = app(Ledger::class);
        $entry = $ledger->autoEntryFor($coll);
        $line = $entry->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);
        $ledger->overrideAccount($line, GlAccount::findKey('bank'), $admin);

        $kept = $ledger->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);
        $this->assertSame(1, $kept->overridesKept);
        $this->assertSame(600.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertTrue($ledger->autoEntryFor($coll)->lines->firstWhere('slot', 'dr')->overridden);

        $clean = $ledger->rebuild(Carbon::parse('2026-08-01'), false, false, $admin);
        $this->assertSame(1, $clean->overridesDropped);
        $this->assertSame(0.0, GlAccount::findKey('bank')->balanceBetween(null, null));
        $this->assertSame(-600.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }

    public function test_manual_and_opening_entries_survive_a_rebuild(): void
    {
        [$admin] = $this->world();
        $ledger = app(Ledger::class);
        $ledger->manual(Carbon::parse('2026-08-01'), 'افتتاحي', [
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('opening_equity')->id, 'debit' => 0, 'credit' => 10000],
        ], $admin, 'opening');

        $ledger->rebuild(Carbon::parse('2026-08-01'), true, false, $admin);

        $this->assertSame(1, GlEntry::where('origin', 'opening')->count());
        $this->assertSame(10000.0 - 600.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GlRebuildTest`
Expected: FAIL — `Call to undefined method Ledger::rebuild()`.

- [ ] **Step 3: Implement**

`app/Services/Gl/RebuildReport.php`:

```php
<?php

namespace App\Services\Gl;

class RebuildReport
{
    public int $deleted = 0;

    public int $created = 0;

    public int $overridesKept = 0;

    public int $overridesDropped = 0;

    /** @var array<string, array{before: float, after: float}> */
    public array $accountDiff = [];

    /** @var array<string, array{gl: float, expected: float, ok: bool}> */
    public array $invariants = [];

    public bool $ok = false;

    public bool $dryRun = false;
}
```

Append to `Ledger`:

```php
    /** المستندات اللي بتولّد قيود، بترتيب التاريخ */
    public function sources(Carbon $from): \Generator
    {
        $d = $from->toDateString();
        $all = collect()
            ->concat(\App\Models\Transaction::whereDate('date', '>=', $d)->orderBy('date')->orderBy('id')->cursor())
            ->concat(\App\Models\RepSettlement::whereDate('to_at', '>=', $d)->orderBy('to_at')->cursor())
            ->concat(\App\Models\SupplierTransaction::whereDate('date', '>=', $d)->orderBy('date')->orderBy('id')->cursor())
            ->concat(\App\Models\Expense::where('status', 'posted')->whereDate('date', '>=', $d)->orderBy('date')->cursor())
            ->concat(\App\Models\CashMovement::where('status', 'posted')->whereDate('date', '>=', $d)->orderBy('date')->cursor())
            ->sortBy(fn ($m) => self::dateOf($m)->format('Y-m-d').'-'.str_pad((string) $m->getKey(), 10, '0', STR_PAD_LEFT));
        foreach ($all as $m) {
            yield $m;
        }
    }

    /** الفحص الثابت: عملاء الشجرة = صافي قيود العملاء من تاريخ البداية · موردون كذلك */
    public function invariants(): array
    {
        $start = Setting::read('gl_start_date') ?: '1970-01-01';
        $recvGl = GlAccount::findKey('receivables')->balanceBetween(null, null);
        $recvExp = round((float) DB::table('transactions')->whereDate('date', '>=', $start)
            ->where('kind', '!=', 'consignment')->selectRaw('COALESCE(SUM(debit - credit),0) v')->value('v'), 2);
        $payGl = GlAccount::findKey('payables')->balanceBetween(null, null);
        $payExp = round((float) DB::table('supplier_transactions')->whereDate('date', '>=', $start)
            ->selectRaw('COALESCE(SUM(credit - debit),0) v')->value('v'), 2);

        return [
            'receivables' => ['gl' => $recvGl, 'expected' => $recvExp, 'ok' => abs($recvGl - $recvExp) < 0.005],
            'payables' => ['gl' => $payGl, 'expected' => $payExp, 'ok' => abs($payGl - $payExp) < 0.005],
        ];
    }

    /**
     * إعادة البناء: مسح القيود الآلية من التاريخ، توليدها تاني بالقواعد الحالية،
     * إعادة تطبيق التعديلات اليدوية (بالمصدر + الخانة)، ثم الفحص. dryRun = rollback في الآخر.
     */
    public function rebuild(Carbon $from, bool $keepOverrides = true, bool $dryRun = false, ?User $by = null): RebuildReport
    {
        $report = new RebuildReport;
        $report->dryRun = $dryRun;
        $snapshot = fn () => GlAccount::where('is_postable', true)->get()
            ->mapWithKeys(fn ($a) => [$a->code => $a->balanceBetween(null, null)])->all();

        DB::beginTransaction();
        try {
            $before = $snapshot();

            // 1. التعديلات اليدوية على القيود الآلية اللي هتتمسح
            $overrides = [];
            $autoQ = GlEntry::where('origin', 'auto')->whereDate('date', '>=', $from->toDateString());
            foreach ((clone $autoQ)->with('lines')->cursor() as $e) {
                foreach ($e->lines as $l) {
                    if ($l->overridden) {
                        $overrides[$e->source_type.'|'.$e->source_id.'|'.$l->slot] = ['to' => $l->account_id, 'from' => $l->rule_account_id];
                    }
                }
            }
            $report->deleted = (clone $autoQ)->count();
            (clone $autoQ)->delete(); // gl_lines cascade

            // 2. التوليد
            foreach ($this->sources($from) as $src) {
                $entry = $this->post($src, $by);
                if ($entry === null) {
                    continue;
                }
                $report->created++;
                foreach ($entry->lines as $l) {
                    $k = $entry->source_type.'|'.$entry->source_id.'|'.$l->slot;
                    if (! isset($overrides[$k])) {
                        continue;
                    }
                    if ($keepOverrides) {
                        $l->update(['account_id' => $overrides[$k]['to'], 'overridden' => true, 'rule_account_id' => $l->account_id]);
                        $report->overridesKept++;
                    } else {
                        $report->overridesDropped++;
                    }
                    unset($overrides[$k]);
                }
            }
            $report->overridesDropped += count($overrides); // مصدر اتشال

            // 3. الفحص
            $after = $snapshot();
            foreach ($after as $code => $v) {
                if (round(($before[$code] ?? 0.0), 2) !== round($v, 2)) {
                    $report->accountDiff[$code] = ['before' => round($before[$code] ?? 0.0, 2), 'after' => round($v, 2)];
                }
            }
            $report->invariants = $this->invariants();
            $report->ok = collect($report->invariants)->every(fn ($i) => $i['ok']);

            if ($dryRun || ! $report->ok) {
                DB::rollBack();
                if (! $report->ok) {
                    $e = new \RuntimeException(__('gl.rebuild_invariant_failed'));
                    $e->report = $report;
                    throw $e;
                }
            } else {
                DB::commit();
            }
        } catch (\Throwable $t) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $t;
        }

        return $report;
    }
```

Note: `$e->report` on a `RuntimeException` needs a small subclass — create `App\Services\Gl\RebuildFailed extends \RuntimeException { public RebuildReport $report; }` and use it instead of the dynamic property.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter 'GlRebuildTest|GlCorrectionTest|GlPostingTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Gl tests/Feature/Gl/GlRebuildTest.php
git commit -m "GL: rebuild from source documents with preview, kept overrides and the receivables invariant"
```

---

### Task 5: Settlement, supplier and invoice-tool hooks + rep account sync

**Files:**
- Create: `app/Observers/Gl/RepSettlementObserver.php`, `app/Observers/Gl/SupplierTransactionObserver.php`
- Create: `app/Console/Commands/GlSyncRepAccounts.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the two observers + `User::created` hook)
- Modify: `app/Http/Controllers/OpsController.php` — in `redateInvoice`, `renumberInvoices`, `reassignInvoice`, `repriceInvoice`, `editInvoiceItems`, `toggleInvoicePayment`: after the transactions are updated via query builder, call `app(\App\Services\Gl\Ledger::class)->repost($tx)` for each affected `Transaction` model (load them with `Transaction::where('source_type', Invoice::class)->where('source_id', $invoice->id)->get()` after the update).
- Test: `tests/Feature/Gl/GlInvariantTest.php`

**Interfaces:**
- Consumes: `Ledger::post/unpost/repost`, `GlAccount::repCash`.
- Produces: `promax:gl-sync-reps` command (creates a `rep_cash` account for every active `FIELD_WORK_ROLES` user; prints created count); observers post `RepSettlement` on `created`, `SupplierTransaction` on `created`/`deleted`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Gl/GlInvariantTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\RepSettlement;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
    }

    public function test_a_settlement_moves_cash_from_the_rep_to_the_safe(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        $client = $this->makeClient(['rep_id' => $rep->id]);
        $visit = \App\Models\Visit::create(['user_id' => $rep->id, 'client_id' => $client->id, 'checked_in_at' => now()]);
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 800, 'kind' => 'collection', 'method' => 'cash', 'source_type' => \App\Models\Visit::class, 'source_id' => $visit->id]);

        $s = RepSettlement::create([
            'number' => 'SET-9001', 'user_id' => $rep->id, 'from_at' => now()->subDay(), 'to_at' => now(),
            'invoices_count' => 0, 'cash_sales' => 0, 'credit_sales' => 0, 'cash_refunds' => 0, 'expected' => 800,
            'prev_balance' => 0, 'received' => 750, 'balance' => 50, 'created_by' => $admin->id,
        ]);

        $this->assertNotNull(app(Ledger::class)->autoEntryFor($s));
        $this->assertSame(750.0, GlAccount::findKey('cash_main')->balanceBetween(null, null));
        $this->assertSame(50.0, GlAccount::repCash($rep)->balanceBetween(null, null), 'اللي لسه معاه');
    }

    public function test_supplier_invoice_and_payment_post_to_payables_and_the_invariant_holds(): void
    {
        $sup = Supplier::create(['code' => 'SUP-1', 'name' => 'مورد', 'name_en' => 'Supplier', 'active' => true]);
        $sup->post('invoice', today()->toDateString(), 0, 2000, 'فاتورة مورد');
        $sup->post('payment', today()->toDateString(), 500, 0, 'دفعة كاش');

        $this->assertSame(1500.0, GlAccount::findKey('payables')->balanceBetween(null, null));
        $this->assertSame(2000.0, GlAccount::findKey('expense_purchases')->balanceBetween(null, null));
        $inv = app(Ledger::class)->invariants();
        $this->assertTrue($inv['payables']['ok']);
    }

    public function test_the_receivables_invariant_holds_after_a_mixed_world_and_every_entry_balances(): void
    {
        $rep = $this->makeRep();
        $a = $this->makeClient(['rep_id' => $rep->id]);
        $b = $this->makeClient();
        $mk = fn ($c, array $x) => Transaction::create(array_merge(['client_id' => $c->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 0, 'tax' => 0], $x));
        $mk($a, ['kind' => 'sale', 'debit' => 1140, 'tax' => 140]);
        $mk($a, ['kind' => 'collection', 'credit' => 500, 'method' => 'cash']);
        $mk($b, ['kind' => 'sale', 'debit' => 300]);
        $mk($b, ['kind' => 'return', 'credit' => 100]);
        $mk($b, ['kind' => 'collection', 'credit' => 50, 'method' => 'transfer', 'reference' => 'T']);
        $a->recalculate();
        $b->recalculate();

        $inv = app(Ledger::class)->invariants();
        $this->assertTrue($inv['receivables']['ok'], json_encode($inv));
        $this->assertSame((float) $a->fresh()->balance + (float) $b->fresh()->balance, $inv['receivables']['gl']);
        GlEntry::with('lines')->get()->each(fn ($e) => $this->assertSame($e->totalDebit(), $e->totalCredit(), $e->number));
    }

    public function test_the_sync_command_creates_a_cash_account_for_every_active_field_user(): void
    {
        $this->makeRep();
        $this->makeRep();
        $this->artisan('promax:gl-sync-reps')->assertSuccessful();

        $this->assertSame(2, GlAccount::where('is_system', true)->whereNotNull('user_id')->count());
        $this->artisan('promax:gl-sync-reps')->assertSuccessful();
        $this->assertSame(2, GlAccount::whereNotNull('user_id')->count(), 'idempotent');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GlInvariantTest`
Expected: FAIL on the settlement entry (null) and the command (not found).

- [ ] **Step 3: Implement observers, command and repost calls**

`app/Observers/Gl/RepSettlementObserver.php`:

```php
<?php

namespace App\Observers\Gl;

use App\Models\RepSettlement;
use App\Services\Gl\Ledger;

class RepSettlementObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(RepSettlement $s): void
    {
        $this->ledger->post($s);
    }

    public function deleted(RepSettlement $s): void
    {
        $this->ledger->unpost($s);
    }
}
```

`app/Observers/Gl/SupplierTransactionObserver.php`:

```php
<?php

namespace App\Observers\Gl;

use App\Models\SupplierTransaction;
use App\Services\Gl\Ledger;

class SupplierTransactionObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(SupplierTransaction $t): void
    {
        $this->ledger->post($t);
    }

    public function deleted(SupplierTransaction $t): void
    {
        $this->ledger->unpost($t);
    }
}
```

`app/Console/Commands/GlSyncRepAccounts.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Gl\GlAccount;
use App\Models\User;
use Illuminate\Console\Command;

/** حساب «نقدية مع المندوب» لكل مستخدم ميداني نشط — آمن يتعاد */
class GlSyncRepAccounts extends Command
{
    protected $signature = 'promax:gl-sync-reps';

    protected $description = 'إنشاء حساب نقدية في الشجرة لكل مندوب/سواق/بروموتر/مدير ميداني نشط';

    public function handle(): int
    {
        if (! GlAccount::where('system_key', 'rep_cash')->exists()) {
            $this->error('الشجرة مش متولدة — شغّل GlSeeder الأول');

            return self::FAILURE;
        }
        $n = 0;
        foreach (User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->get() as $u) {
            $had = GlAccount::where('user_id', $u->id)->exists();
            GlAccount::repCash($u);
            $n += $had ? 0 : 1;
        }
        $this->info("تم — حسابات جديدة: {$n}");

        return self::SUCCESS;
    }
}
```

In `AppServiceProvider::boot()`:

```php
\App\Models\RepSettlement::observe(\App\Observers\Gl\RepSettlementObserver::class);
\App\Models\SupplierTransaction::observe(\App\Observers\Gl\SupplierTransactionObserver::class);
// مستخدم ميداني جديد = حساب نقدية جديد (لو الشجرة متولدة)
\App\Models\User::created(function (\App\Models\User $u) {
    if (in_array($u->role, \App\Models\User::FIELD_WORK_ROLES, true)
        && \App\Models\Gl\GlAccount::where('system_key', 'rep_cash')->exists()) {
        \App\Models\Gl\GlAccount::repCash($u);
    }
});
```

In `OpsController`, at the end of each admin invoice tool's `DB::transaction` closure (redate, renumber, reassign, reprice, editInvoiceItems, toggleInvoicePayment) add:

```php
// الشجرة: القيود اتعدّلت بـwhereKey()->update() فالـobserver ماشافهاش (٩/٩)
foreach (Transaction::where('source_type', Invoice::class)->where('source_id', $invoice->id)->get() as $glTx) {
    app(\App\Services\Gl\Ledger::class)->repost($glTx);
}
```

(For `renumberInvoices`, which touches every invoice, call `app(Ledger::class)->rebuild(Carbon::parse(Setting::read('gl_start_date') ?: '1970-01-01'))` once after the loop instead, guarded by `Setting::read('gl_enabled') === '1'`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter 'GlInvariantTest|InvoiceTools|Renumber|Reprice|Redate'`
Expected: PASS (existing invoice-tool tests stay green because the GL switch is off in them).

- [ ] **Step 5: Commit**

```bash
git add app/Observers app/Console/Commands/GlSyncRepAccounts.php app/Providers/AppServiceProvider.php app/Http/Controllers/OpsController.php tests/Feature/Gl/GlInvariantTest.php
git commit -m "GL: settlement and supplier hooks, rep cash accounts sync, repost after admin invoice tools"
```

---

### Task 6: Expense and cash-movement vouchers (models wired, controllers, views)

**Files:**
- Create: `app/Observers/Gl/ExpenseObserver.php`, `app/Observers/Gl/CashMovementObserver.php` (post on `created`; on `updated` when `status` changed to `void` → `unpost`)
- Create: `app/Http/Controllers/Gl/ExpenseController.php` (`index`, `store`, `void`), `app/Http/Controllers/Gl/CashMovementController.php` (`index`, `store`, `void`)
- Create: `resources/views/gl/expenses.blade.php`, `resources/views/gl/cash.blade.php`
- Modify: `routes/web.php` (new `Route::prefix('gl')->name('gl.')` group inside the `auth,screen` group), `app/Support/Access.php` (SCREENS `accountant` += `'gl.'` prefix; NAV new group `nav.group_gl` with icon `📒`; ACTIONS `act.gl.post`), `lang/{ar,en}/gl.php` (new), `lang/{ar,en}/nav.php` (`group_gl`, `gl_expenses`, `gl_cash`), `lang/{ar,en}/perm.php` (`act_gl_post`), `app/Providers/AppServiceProvider.php`
- Modify: `resources/views/erp/repclose_show.blade.php` + `RepSettlementController::show` — pass `'repExpenses' => Expense::where('paid_from','rep_cash')->where('paid_from_user_id',$rep->id)->where('status','posted')->whereBetween('date', [$from,$to])->get()` and render a row «مصروفات معتمدة من نقدية المندوب» with the sum under the expected-cash block (display only; the settlement math is unchanged — the GL balance carries the difference).
- Test: `tests/Feature/Gl/ExpensesCashTest.php`

**Interfaces:**
- Routes: `gl.expenses` GET `/gl/expenses`, `gl.expenses.store` POST, `gl.expenses.void` POST `/gl/expenses/{expense}/void`; `gl.cash` GET `/gl/cash`, `gl.cash.store` POST, `gl.cash.void` POST `/gl/cash/{cashMovement}/void`. All `->middleware('role:admin,accountant')`.
- Validation (`store` expense): `date` required date; `account_id` required exists gl_accounts,id + must be `type=expense`, postable, active; `amount` required numeric min 0.01 max 99999999; `paid_from` in PAID_FROM; `paid_from_user_id` required_if paid_from,rep_cash exists users; `payee_type` in PAYEE_TYPES; `payee_supplier_id` required_if payee_type,supplier; `payee_user_id` required_if payee_type,employee; `payee_name` required_if payee_type,other max 120; `reference` nullable max 80; `note` nullable max 250; `attachment` nullable file image/pdf max 8192 → `store('gl-attachments','public')`.
- Validation (`store` cash): `date`, `kind` in KINDS, `amount`, `user_id` required_if kind in rep_advance,rep_return, `reference`, `note`, `attachment`.
- `void`: sets `status=void`, `voided_at/by`; refused (422 with `gl.period_closed`) if the voucher's period is closed — the accountant must post a reversing cash movement/expense instead.
- `Access::ACTIONS['act.gl.post'] = ['perm.act_gl_post', 'gl.expenses', ['accountant'], ['gl.expenses.store','gl.expenses.void','gl.cash.store','gl.cash.void','gl.entries.store','gl.entries.override']]`.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ExpensesCashTest`
Expected: FAIL — route `gl.expenses.store` not defined.

- [ ] **Step 3: Implement**

Routes (inside the `Route::middleware(['auth','screen'])` group, after the `erp` prefix group):

```php
    // ═══ الحسابات العامة — الشجرة واليومية والمصروفات (١١/٩/٢٠٢٦) ═══
    Route::prefix('gl')->name('gl.')->middleware('role:admin,accountant')->group(function () {
        $g = \App\Http\Controllers\Gl\ExpenseController::class;
        Route::get('/expenses', [$g, 'index'])->name('expenses');
        Route::post('/expenses', [$g, 'store'])->name('expenses.store');
        Route::post('/expenses/{expense}/void', [$g, 'void'])->name('expenses.void');
        $c = \App\Http\Controllers\Gl\CashMovementController::class;
        Route::get('/cash', [$c, 'index'])->name('cash');
        Route::post('/cash', [$c, 'store'])->name('cash.store');
        Route::post('/cash/{cashMovement}/void', [$c, 'void'])->name('cash.void');
    });
```

`ExpenseController`:

```php
<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPeriod;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request, 'month');
        $q = Expense::with(['account', 'paidFromUser', 'payeeSupplier', 'payeeUser'])
            ->tap(fn ($q) => $range->apply($q, 'date'))
            ->when($request->filled('account'), fn ($q) => $q->where('account_id', $request->integer('account')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()));

        return view('gl.expenses', [
            'range' => $range,
            'rows' => (clone $q)->orderByDesc('date')->orderByDesc('id')->paginate(50)->withQueryString(),
            'total' => (float) (clone $q)->where('status', 'posted')->sum('amount'),
            'accounts' => GlAccount::where('type', 'expense')->where('is_postable', true)->where('active', true)->orderBy('code')->get(),
            'reps' => User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'employees' => User::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(['id', 'name', 'name_en']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'account_id' => ['required', Rule::exists('gl_accounts', 'id')->where('type', 'expense')->where('is_postable', 1)->where('active', 1)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'paid_from' => ['required', Rule::in(Expense::PAID_FROM)],
            'paid_from_user_id' => ['required_if:paid_from,rep_cash', 'nullable', 'exists:users,id'],
            'payee_type' => ['required', Rule::in(Expense::PAYEE_TYPES)],
            'payee_supplier_id' => ['required_if:payee_type,supplier', 'nullable', 'exists:suppliers,id'],
            'payee_user_id' => ['required_if:payee_type,employee', 'nullable', 'exists:users,id'],
            'payee_name' => ['required_if:payee_type,other', 'nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:250'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $date = \Illuminate\Support\Carbon::parse($data['date']);
        if (GlPeriod::isClosed($date)) {
            return back()->withErrors(['date' => __('gl.period_closed', ['period' => GlPeriod::keyFor($date)])])->withInput();
        }

        Expense::create([
            'number' => Expense::nextNumber(),
            'date' => $date->toDateString(),
            'account_id' => $data['account_id'],
            'amount' => round((float) $data['amount'], 2),
            'paid_from' => $data['paid_from'],
            'paid_from_user_id' => $data['paid_from'] === 'rep_cash' ? $data['paid_from_user_id'] : null,
            'payee_type' => $data['payee_type'],
            'payee_supplier_id' => $data['payee_type'] === 'supplier' ? $data['payee_supplier_id'] : null,
            'payee_user_id' => $data['payee_type'] === 'employee' ? $data['payee_user_id'] : null,
            'payee_name' => $data['payee_type'] === 'other' ? $data['payee_name'] : null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'attachment_path' => $request->hasFile('attachment') ? $request->file('attachment')->store('gl-attachments', 'public') : null,
            'status' => 'posted',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('gl.expenses')->with('ok', __('gl.expense_saved'));
    }

    public function void(Request $request, Expense $expense)
    {
        if ($expense->status === 'void') {
            return back();
        }
        if (GlPeriod::isClosed($expense->date)) {
            return back()->withErrors(['void' => __('gl.void_closed_hint')]);
        }
        $expense->update(['status' => 'void', 'voided_at' => now(), 'voided_by' => $request->user()->id]);

        return back()->with('ok', __('gl.voided'));
    }
}
```

`CashMovementController` mirrors it: validation `kind` in `CashMovement::KINDS`, `user_id` `required_if:kind,rep_advance,rep_return`, same closed-period guard, `CashMovement::create([... 'number' => CashMovement::nextNumber() ...])`, view `gl.cash` with `'kinds' => CashMovement::KINDS`, `'reps'`, `'rows'`, `'range'`, totals by kind.

Observers `ExpenseObserver` / `CashMovementObserver`:

```php
public function created($m): void { $this->ledger->post($m); }
public function updated($m): void
{
    if ($m->wasChanged('status') && $m->status === 'void') { $this->ledger->unpost($m); }
    elseif ($m->wasChanged(['amount', 'date', 'account_id', 'paid_from', 'paid_from_user_id', 'kind', 'user_id'])) { $this->ledger->repost($m); }
}
public function deleted($m): void { $this->ledger->unpost($m); }
```

Register both in `AppServiceProvider::boot()`.

View `resources/views/gl/expenses.blade.php` — follow `erp/dues.blade.php`: `@extends('layouts.system')`, `@section('title', __('gl.expenses'))`, `@section('actions')` with a `<button class="btn gold" onclick="openDlg('dlgExpense')">＋ {{ __('gl.new_expense') }}</button>` gated by `Access::action(auth()->user(),'act.gl.post')`; KPIs (total posted in range, count); GET filter form (`from`/`to` via `$range->fromValue()`, `account`, `status`); table columns: number, date, account (`displayName()`), payee (`payeeLabel()`), paid from (label + rep name), amount, reference, attachment link (📎 → `Storage::url`), status badge, ✕ void form (POST, `onsubmit="return confirm(...)"`). `<dialog id="dlgExpense">` **inside `@section('content')`** with `<form class="dlg" method="POST" enctype="multipart/form-data" action="{{ route('gl.expenses.store') }}">`, required stars on `date`, `account_id`, `amount`, `paid_from`, `payee_type`; `paid_from_user_id` select shown only when `paid_from=rep_cash` (JS `onchange`), payee fields switched by `payee_type` (JS). Keep `type="date"` inputs named `date`.

`lang/ar/gl.php` keys used so far (mirror in `lang/en/gl.php`):

```php
return [
    'expenses' => 'المصروفات', 'new_expense' => 'سند مصروف', 'expense_saved' => 'اتسجّل سند المصروف وقيده.',
    'cash' => 'حركة النقدية', 'new_cash' => 'سند حركة نقدية', 'cash_saved' => 'اتسجّلت الحركة وقيدها.',
    'voided' => 'اتلغى السند وقيده.', 'void_closed_hint' => 'الفترة مقفولة — سجّل سند عكسي بتاريخ النهاردة بدل الإلغاء.',
    'period_closed' => 'الفترة :period مقفولة.', 'account_not_postable' => 'الحساب ده مجموعة أو موقوف — اختار حساب فرعي.',
    'reversal_of' => 'قيد عكسي لـ :number', 'correction_of' => 'تصحيح :number', 'rebuild_invariant_failed' => 'إعادة البناء اترجّعت: رصيد العملاء أو الموردين في الشجرة مايساوي الدفتر.',
    'account' => 'الحساب', 'amount' => 'المبلغ', 'paid_from' => 'اتدفع من', 'payee' => 'لمن', 'payee_supplier' => 'مورد', 'payee_employee' => 'موظف', 'payee_other' => 'اسم حر',
    'paid_cash_main' => 'الخزنة الرئيسية', 'paid_bank' => 'البنك', 'paid_rep_cash' => 'نقدية مندوب', 'rep' => 'المندوب', 'attachment' => 'صورة الفاتورة / الإيصال',
    'kind' => 'النوع', 'kind_deposit' => 'إيداع خزنة → بنك', 'kind_withdraw' => 'سحب بنك → خزنة', 'kind_rep_advance' => 'عهدة نقدية خزنة → مندوب', 'kind_rep_return' => 'رد عهدة مندوب → خزنة',
    'status_posted' => 'مرحّل', 'status_void' => 'ملغي', 'void' => 'إلغاء', 'confirm_void' => 'إلغاء السند وقيده؟',
    'rep_expenses_row' => 'مصروفات معتمدة من نقدية المندوب في النافذة',
];
```

`lang/{ar,en}/nav.php`: `'group_gl' => 'الحسابات العامة'` / `'General Ledger'`, `'gl_expenses' => 'المصروفات'`, `'gl_cash' => 'حركة النقدية'`. `lang/{ar,en}/perm.php`: `'act_gl_post' => 'تسجيل قيود ومصروفات وحركة نقدية'` / `'Post entries, expenses and cash movements'`.

`Access.php`: add `'nav.group_gl' => '📒'` to the icons map (after `nav.group_money`); add NAV group after `nav.group_money`:

```php
        'nav.group_gl' => [
            ['gl.expenses', '🧾', 'nav.gl_expenses', 'gl.expenses*', null],
            ['gl.cash', '🏦', 'nav.gl_cash', 'gl.cash*', null],
        ],
```

SCREENS: `accountant` += `'gl.'` (prefix). ACTIONS: `'act.gl.post' => ['perm.act_gl_post', 'gl.expenses', ['accountant'], ['gl.expenses.store', 'gl.expenses.void', 'gl.cash.store', 'gl.cash.void']]` (Task 8 extends the route list).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter 'ExpensesCashTest|RoleAccessTest|FormStarIntegrityTest|BilingualFormsTest'`
Expected: PASS. `php artisan promax:i18n-check` green.

- [ ] **Step 5: Commit**

```bash
git add app routes lang resources/views/gl tests/Feature/Gl/ExpensesCashTest.php
git commit -m "GL: expense and cash-movement vouchers with automatic posting, void, and the accountant screens"
```

---

### Task 7: Reports service

**Files:**
- Create: `app/Services/Gl/Reports.php`
- Test: `tests/Feature/Gl/GlReportsTest.php`

**Interfaces:**
- Produces:
  - `Reports::trialBalance(?Carbon $from, ?Carbon $to): array{rows: list<array{account: GlAccount, debit: float, credit: float, opening: float, closing: float}>, totals: array{debit: float, credit: float}}` — postable accounts with any movement or non-zero opening; `opening` = signed balance before `$from`; `debit/credit` = period sums; `closing` = opening ± movement (signed per normal side); `totals.debit === totals.credit` for movements.
  - `Reports::statement(GlAccount $acc, ?Carbon $from, ?Carbon $to): array{opening: float, rows: list<array{entry: GlEntry, line: GlLine, running: float}>, closing: float}` (subtree included).
  - `Reports::income(Carbon $from, Carbon $to): array{revenue: list<array{account,amount}>, expenses: list<...>, total_revenue: float, total_expenses: float, net: float}` — revenue positive as credit-normal; `sales_returns`/`discounts_allowed` appear as negative revenue lines.
  - `Reports::balanceSheet(Carbon $asOf): array{assets: list, liabilities: list, equity: list, retained: float, total_assets: float, total_liabilities_equity: float, balanced: bool}` — `retained` = cumulative net income up to `$asOf` (revenue − expenses) added to equity so the sheet balances.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Gl/GlReportsTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Gl\Ledger;
use App\Services\Gl\Reports;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlReportsTest extends TestCase
{
    use RefreshDatabase;

    private function world(): void
    {
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        app(Ledger::class)->manual(Carbon::parse('2026-08-01'), 'افتتاحي', [
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 10000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('opening_equity')->id, 'debit' => 0, 'credit' => 10000],
        ], $admin, 'opening');
        Transaction::create(['client_id' => $client->id, 'date' => '2026-08-10', 'memo' => 'x', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);
        Transaction::create(['client_id' => $client->id, 'date' => '2026-09-05', 'memo' => 'x', 'debit' => 0, 'credit' => 500, 'kind' => 'collection', 'method' => 'cash']);
        app(Ledger::class)->manual(Carbon::parse('2026-09-06'), 'إيجار', [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 300],
        ], $admin);
    }

    public function test_the_trial_balance_balances_and_carries_openings(): void
    {
        $this->world();
        $tb = app(Reports::class)->trialBalance(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);
        $cash = collect($tb['rows'])->first(fn ($r) => $r['account']->system_key === 'cash_main');
        $this->assertSame(10000.0, $cash['opening']);
        $this->assertSame(500.0, $cash['debit']);
        $this->assertSame(300.0, $cash['credit']);
        $this->assertSame(10200.0, $cash['closing']);
    }

    public function test_the_statement_runs_a_balance_and_the_income_statement_nets_revenue_and_expenses(): void
    {
        $this->world();
        $st = app(Reports::class)->statement(GlAccount::findKey('cash_main'), Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $this->assertSame(10000.0, $st['opening']);
        $this->assertSame([10500.0, 10200.0], array_map(fn ($r) => $r['running'], $st['rows']));
        $this->assertSame(10200.0, $st['closing']);

        $inc = app(Reports::class)->income(Carbon::parse('2026-08-01'), Carbon::parse('2026-09-30'));
        $this->assertSame(1000.0, $inc['total_revenue']);
        $this->assertSame(300.0, $inc['total_expenses']);
        $this->assertSame(700.0, $inc['net']);
    }

    public function test_the_balance_sheet_balances_with_retained_earnings(): void
    {
        $this->world();
        $bs = app(Reports::class)->balanceSheet(Carbon::parse('2026-09-30'));

        $this->assertTrue($bs['balanced'], json_encode([$bs['total_assets'], $bs['total_liabilities_equity']]));
        $this->assertSame(700.0, $bs['retained']);
        $this->assertSame(10200.0 + 640.0, $bs['total_assets']); // خزنة 10200 + عملاء 640
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter GlReportsTest` → FAIL, class `Reports` not found.

- [ ] **Step 3: Implement `Reports`**

```php
<?php

namespace App\Services\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** قراءة بس — كل التقارير من gl_lines × gl_entries بفترة */
class Reports
{
    /** مجاميع مدين/دائن لكل حساب (بالأحفاد) على فترة */
    private function sums(?Carbon $from, ?Carbon $to): array
    {
        $q = DB::table('gl_lines')->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id');
        if ($from) {
            $q->whereDate('gl_entries.date', '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate('gl_entries.date', '<=', $to->toDateString());
        }

        return $q->selectRaw('gl_lines.account_id, SUM(gl_lines.debit) d, SUM(gl_lines.credit) c')
            ->groupBy('gl_lines.account_id')->get()->keyBy('account_id')->all();
    }

    private function signed(GlAccount $a, float $d, float $c): float
    {
        return round($a->normal_side === 'debit' ? $d - $c : $c - $d, 2);
    }

    public function trialBalance(?Carbon $from, ?Carbon $to): array
    {
        $period = $this->sums($from, $to);
        $before = $from ? $this->sums(null, $from->copy()->subDay()) : [];
        $rows = [];
        $td = $tc = 0.0;
        foreach (GlAccount::where('is_postable', true)->orderBy('code')->get() as $a) {
            $p = $period[$a->id] ?? null;
            $b = $before[$a->id] ?? null;
            $opening = $b ? $this->signed($a, (float) $b->d, (float) $b->c) : 0.0;
            $d = round((float) ($p->d ?? 0), 2);
            $c = round((float) ($p->c ?? 0), 2);
            if ($opening == 0.0 && $d == 0.0 && $c == 0.0) {
                continue;
            }
            $rows[] = ['account' => $a, 'opening' => $opening, 'debit' => $d, 'credit' => $c,
                'closing' => round($opening + $this->signed($a, $d, $c), 2)];
            $td += $d;
            $tc += $c;
        }

        return ['rows' => $rows, 'totals' => ['debit' => round($td, 2), 'credit' => round($tc, 2)]];
    }

    public function statement(GlAccount $acc, ?Carbon $from, ?Carbon $to): array
    {
        $ids = $acc->subtreeIds();
        $opening = $from ? $acc->balanceBetween(null, $from->copy()->subDay()) : 0.0;
        $q = GlLine::with(['entry', 'account'])->whereIn('account_id', $ids)
            ->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id')->select('gl_lines.*')
            ->orderBy('gl_entries.date')->orderBy('gl_entries.id')->orderBy('gl_lines.id');
        if ($from) {
            $q->whereDate('gl_entries.date', '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate('gl_entries.date', '<=', $to->toDateString());
        }
        $running = $opening;
        $rows = [];
        foreach ($q->get() as $l) {
            $running = round($running + $this->signed($acc, (float) $l->debit, (float) $l->credit), 2);
            $rows[] = ['entry' => $l->entry, 'line' => $l, 'running' => $running];
        }

        return ['opening' => round($opening, 2), 'rows' => $rows, 'closing' => $running];
    }

    public function income(Carbon $from, Carbon $to): array
    {
        $sums = $this->sums($from, $to);
        $rev = $exp = [];
        $tr = $te = 0.0;
        foreach (GlAccount::whereIn('type', ['revenue', 'expense'])->where('is_postable', true)->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            $amount = $this->signed($a, (float) $s->d, (float) $s->c);
            if ($a->type === 'revenue') {
                $rev[] = ['account' => $a, 'amount' => $amount];
                $tr += $amount;
            } else {
                $exp[] = ['account' => $a, 'amount' => $amount];
                $te += $amount;
            }
        }

        return ['revenue' => $rev, 'expenses' => $exp, 'total_revenue' => round($tr, 2), 'total_expenses' => round($te, 2), 'net' => round($tr - $te, 2)];
    }

    public function balanceSheet(Carbon $asOf): array
    {
        $sums = $this->sums(null, $asOf);
        $out = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $tot = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
        foreach (GlAccount::whereIn('type', ['asset', 'liability', 'equity'])->where('is_postable', true)->orderBy('code')->get() as $a) {
            $s = $sums[$a->id] ?? null;
            if (! $s) {
                continue;
            }
            $amount = $this->signed($a, (float) $s->d, (float) $s->c);
            $out[$a->type === 'asset' ? 'assets' : ($a->type === 'liability' ? 'liabilities' : 'equity')][] = ['account' => $a, 'amount' => $amount];
            $tot[$a->type] += $amount;
        }
        $retained = $this->income(Carbon::parse('1970-01-01'), $asOf)['net'];
        $tle = round($tot['liability'] + $tot['equity'] + $retained, 2);

        return $out + [
            'retained' => $retained,
            'total_assets' => round($tot['asset'], 2),
            'total_liabilities_equity' => $tle,
            'balanced' => abs(round($tot['asset'], 2) - $tle) < 0.005,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes** — `php artisan test --filter GlReportsTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Gl/Reports.php tests/Feature/Gl/GlReportsTest.php
git commit -m "GL: trial balance, account statement, income statement and balance sheet"
```

---

### Task 8: Ledger screens — accounts tree, journal with manual entry and ✎ override, statement, reports, settings with rebuild

**Files:**
- Create: `app/Http/Controllers/Gl/AccountController.php` (`index`, `store`, `update`, `show`), `EntryController.php` (`index`, `store`, `override`), `ReportController.php` (`trialBalance`, `income`, `balanceSheet`, each with `?export=1` CSV via `App\Support\Csv`), `SettingsController.php` (`index`, `saveRules`, `saveGeneral`, `closePeriod`, `reopenPeriod`, `rebuildPreview`, `rebuild`)
- Create views: `resources/views/gl/accounts.blade.php`, `account.blade.php`, `entries.blade.php`, `trial_balance.blade.php`, `income.blade.php`, `balance_sheet.blade.php`, `settings.blade.php`
- Modify: `routes/web.php` (extend the `gl` group), `app/Support/Access.php` (NAV entries, `act.gl.admin`, `act.gl.post` route list), `lang/{ar,en}/gl.php`, `lang/{ar,en}/nav.php`, `lang/{ar,en}/perm.php`
- Test: `tests/Feature/Gl/GlScreensTest.php`

**Interfaces / routes** (all in the `gl` group, `role:admin,accountant` unless noted):
- `gl.accounts` GET `/accounts` · `gl.accounts.store` POST · `gl.accounts.update` POST `/accounts/{account}` · `gl.accounts.show` GET `/accounts/{account}` (statement, `from/to`, `?export=1`).
- `gl.entries` GET `/entries` (filters `from/to`, `origin`, `account`, `q` on number/memo; paginate 50; each row shows lines and a link to the source document: `Transaction` → `route('erp.clients.show', client_id)`, `RepSettlement` → `erp.repclose.show`, `SupplierTransaction` → `erp.suppliers.show`, `Expense` → `gl.expenses`, `CashMovement` → `gl.cash`) · `gl.entries.store` POST (manual entry: `date`, `memo`, `lines[i][account_id]`, `lines[i][debit]`, `lines[i][credit]`) · `gl.entries.override` POST `/entries/lines/{line}/override` (`account_id`, `note`).
- `gl.trial_balance` GET `/trial-balance` · `gl.income` GET `/income` · `gl.balance_sheet` GET `/balance-sheet`.
- `gl.settings` GET `/settings` · `gl.settings.rules` POST · `gl.settings.general` POST (`gl_start_date`, `gl_bank_name`, `gl_enabled`) · `gl.periods.close` POST `/periods/{key}/close` · `gl.periods.reopen` POST (admin) · `gl.rebuild.preview` POST · `gl.rebuild` POST (admin). Admin-only routes carry `->middleware('role:admin')`.
- ACTIONS: `act.gl.post` route list += `gl.entries.store`, `gl.entries.override`, `gl.accounts.store`, `gl.accounts.update`, `gl.periods.close`; new `'act.gl.admin' => ['perm.act_gl_admin', 'gl.settings', [], ['gl.settings.rules','gl.settings.general','gl.periods.reopen','gl.rebuild.preview','gl.rebuild']]` (roles `[]` = admin only, matching `role:admin`).
- NAV group `nav.group_gl` order: accounts, entries, trial_balance, income, balance_sheet, expenses, cash, settings.
- Exceptions mapping in controllers: `UnbalancedEntry` and `ClosedPeriod` → `back()->withErrors(['lines' => $e->getMessage()])->withInput()`; `RebuildFailed` → settings page with `$report` and the diff table.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Gl/GlScreensTest.php
namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        $this->acc = User::factory()->create(['role' => 'accountant', 'active' => true]);
    }

    public function test_every_gl_screen_renders_for_the_accountant_with_export(): void
    {
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'فاتورة تيست', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);

        foreach (['gl.accounts', 'gl.entries', 'gl.trial_balance', 'gl.income', 'gl.balance_sheet', 'gl.settings'] as $r) {
            $this->actingAs($this->acc)->get(route($r))->assertOk();
        }
        $this->actingAs($this->acc)->get(route('gl.entries'))->assertSee('فاتورة تيست')->assertSee('JV-1001');
        $res = $this->actingAs($this->acc)->get(route('gl.trial_balance', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->actingAs($this->acc)->get(route('gl.accounts.show', GlAccount::findKey('receivables')))->assertOk()->assertSee('1,140.00');
    }

    public function test_the_accountant_can_add_a_free_account_and_the_admin_edits_a_system_name_only(): void
    {
        $this->actingAs($this->acc)->post(route('gl.accounts.store'), [
            'parent_id' => GlAccount::where('code', '5')->first()->id, 'code' => '5108', 'name' => 'اتصالات', 'name_en' => 'Telecom',
        ])->assertSessionHasNoErrors();
        $acc = GlAccount::where('code', '5108')->first();
        $this->assertSame('expense', $acc->type);
        $this->assertFalse($acc->is_system);

        $sys = GlAccount::findKey('bank');
        $this->actingAs($this->makeAdmin())->post(route('gl.accounts.update', $sys), ['name' => 'بنك CIB', 'name_en' => 'CIB', 'code' => '9999'])->assertSessionHasNoErrors();
        $sys->refresh();
        $this->assertSame('بنك CIB', $sys->name);
        $this->assertSame('1102', $sys->code, 'system code never changes');
    }

    public function test_a_manual_entry_from_the_screen_and_an_unbalanced_one_is_refused(): void
    {
        $lines = [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ];
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'إيجار', 'lines' => $lines])->assertSessionHasNoErrors();
        $this->assertSame(1, GlEntry::where('origin', 'manual')->count());

        $lines[1]['credit'] = 999;
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'x', 'lines' => $lines])->assertSessionHasErrors('lines');
    }

    public function test_the_override_button_changes_the_account_and_the_rebuild_preview_shows_a_rule_change(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $tx = Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'x', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $line = GlEntry::first()->lines->firstWhere('account_id', GlAccount::findKey('cash_main')->id);

        $this->actingAs($this->acc)->post(route('gl.entries.override', $line), ['account_id' => GlAccount::findKey('bank')->id, 'note' => 'تحويل'])->assertSessionHasNoErrors();
        $this->assertSame(GlAccount::findKey('bank')->id, $line->fresh()->account_id);

        GlPostingRule::where('key', 'tx.collection.office_cash')->update(['debit_key' => 'bank']);
        $this->actingAs($admin)->post(route('gl.rebuild.preview'), ['keep_overrides' => 1])->assertOk()->assertSee('1102');
        $this->assertSame(1, GlEntry::count(), 'preview writes nothing');

        $this->actingAs($this->acc)->post(route('gl.rebuild.preview'))->assertForbidden();
    }

    public function test_closing_a_period_blocks_manual_entries_inside_it(): void
    {
        $this->actingAs($this->acc)->post(route('gl.periods.close', '2026-07'))->assertRedirect();
        $this->assertTrue(GlPeriod::isClosed(\Illuminate\Support\Carbon::parse('2026-07-15')));

        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => '2026-07-15', 'memo' => 'x', 'lines' => [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1],
        ]])->assertSessionHasErrors('lines');
    }
}
```

- [ ] **Step 2: Run test to verify it fails** — routes undefined.

- [ ] **Step 3: Implement controllers, views, routes, access, lang**

Controller sketches (complete the bodies exactly as specified in the Interfaces block):

`AccountController::index`: `$range = DateRange::fromRequest($request)`; `$accounts = GlAccount::with('children')->orderBy('code')->get()`; `$balances = ` map id → `balanceBetween($range->from, $range->to)` computed once via `Reports::sums`-style aggregation (add a public `Reports::balancesByAccount(?Carbon,?Carbon): array<int,float>` that returns signed subtree balances for every account — implement by summing postable balances up the parent chain); view renders roots recursively with a Blade partial `gl/_tree_node.blade.php` (`<details open>` per non-postable node).
`AccountController::store`: validate `parent_id` exists, `code` unique regex `^[0-9]{1,6}(\.[A-Z0-9-]{1,12})?$` and must start with the parent's root digit, `name` required max 120, `name_en` nullable; `type = GlAccount::ROOT_TYPES[$code[0]]`, `normal_side = normalSideFor(type)`, `is_system=false`, `is_postable=true`.
`AccountController::update`: `name`, `name_en`, `active`; `code` and `parent_id` only when `! $account->is_system`; refuse deactivation of system accounts.
`AccountController::show`: `Reports::statement($account, $range->from, $range->to)`; `?export=1` → `Csv::download('gl-'.$account->code.'-'.now()->format('Y-m-d').'.csv', [...])`.
`EntryController::index`: filters as in Interfaces; `with('lines.account')`; `origin` badge; each line with ✎ button (`Access::action(...,'act.gl.post')`) opening `<dialog id="dlgOverride">` whose form posts to `gl.entries.override` with a `<select name="account_id">` of postable active accounts and `note`.
`EntryController::store`: validate `date` required date, `memo` required max 250, `lines` array min 2, `lines.*.account_id` exists postable, `lines.*.debit`/`credit` nullable numeric min 0; call `Ledger::manual`; catch `UnbalancedEntry|ClosedPeriod` → `back()->withErrors(['lines' => $e->getMessage()])->withInput()`.
`EntryController::override`: validate `account_id`, `note`; `Ledger::overrideAccount($line, GlAccount::findOrFail(...), $user, $note)`; catch `\InvalidArgumentException` → errors.
`ReportController`: each method builds `DateRange` (`'month'` default for trial balance/income; balance sheet uses `to` only, default today) and returns the view or CSV.
`SettingsController::index`: rules (`GlPostingRule::orderBy('key')`), account keys for the selects (`GlAccount::whereNotNull('system_key')`), general settings, periods (last 18 months with status), invariants (`Ledger::invariants()`), and `$report` from session when a preview ran.
`SettingsController::saveRules`: `rules[key][debit_key|credit_key|tax_key|active]`; every key must exist in `GlAccount::SYSTEM_KEYS`; `GlPostingRule::flush()` after.
`SettingsController::saveGeneral`: `gl_start_date` nullable date, `gl_bank_name` nullable max 80 (also renames the `bank` account), `gl_enabled` boolean → `Setting::writeMany`.
`SettingsController::rebuildPreview`: `Ledger::rebuild($from, (bool) $request->boolean('keep_overrides', true), true, $user)` where `$from = Carbon::parse(Setting::read('gl_start_date') ?: '1970-01-01')`; renders `gl.settings` with `$report`; on `RebuildFailed` render with `$report = $e->report` and an error flash. Returns 200 (view) so the test can `assertSee`.
`SettingsController::rebuild`: same with `dryRun=false`; redirect with flash.

Routes:

```php
    Route::prefix('gl')->name('gl.')->middleware('role:admin,accountant')->group(function () {
        $a = \App\Http\Controllers\Gl\AccountController::class;
        Route::get('/accounts', [$a, 'index'])->name('accounts');
        Route::post('/accounts', [$a, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}', [$a, 'show'])->name('accounts.show');
        Route::post('/accounts/{account}', [$a, 'update'])->name('accounts.update');
        $e = \App\Http\Controllers\Gl\EntryController::class;
        Route::get('/entries', [$e, 'index'])->name('entries');
        Route::post('/entries', [$e, 'store'])->name('entries.store');
        Route::post('/entries/lines/{line}/override', [$e, 'override'])->name('entries.override');
        $r = \App\Http\Controllers\Gl\ReportController::class;
        Route::get('/trial-balance', [$r, 'trialBalance'])->name('trial_balance');
        Route::get('/income', [$r, 'income'])->name('income');
        Route::get('/balance-sheet', [$r, 'balanceSheet'])->name('balance_sheet');
        $s = \App\Http\Controllers\Gl\SettingsController::class;
        Route::get('/settings', [$s, 'index'])->name('settings');
        Route::post('/periods/{key}/close', [$s, 'closePeriod'])->name('periods.close');
        Route::post('/periods/{key}/reopen', [$s, 'reopenPeriod'])->middleware('role:admin')->name('periods.reopen');
        Route::post('/settings/rules', [$s, 'saveRules'])->middleware('role:admin')->name('settings.rules');
        Route::post('/settings/general', [$s, 'saveGeneral'])->middleware('role:admin')->name('settings.general');
        Route::post('/rebuild/preview', [$s, 'rebuildPreview'])->middleware('role:admin')->name('rebuild.preview');
        Route::post('/rebuild', [$s, 'rebuild'])->middleware('role:admin')->name('rebuild');
        // ... expenses/cash routes from Task 6 stay here
    });
```

Route model binding: `{account}` → `GlAccount`, `{line}` → `GlLine` (type-hint the controller parameters; both models live in `App\Models\Gl`).

Views follow `erp/dues.blade.php` and `erp/collections.blade.php` structure (cards, `.kpis`, `.tablewrap`, `data-nosum` on reference columns, `<dialog>` inside `@section('content')`, `openDlg/closeDlg`). The manual-entry dialog has a JS `glAddLine()` that clones a row template and `glRecalc()` that shows the live debit/credit totals and disables the submit while they differ. Number all amounts with `number_format(x, 2)`.

`lang/{ar,en}/gl.php` additions: `accounts`, `tree`, `entries`, `journal`, `new_entry`, `new_account`, `edit_account`, `code`, `name`, `parent`, `type_*` (5), `debit`, `credit`, `balance`, `opening`, `closing`, `running`, `trial_balance`, `income_statement`, `balance_sheet`, `revenue`, `expenses_total`, `net_income`, `assets`, `liabilities`, `equity`, `retained_earnings`, `balanced`, `not_balanced`, `settings`, `rules`, `rule_debit`, `rule_credit`, `rule_tax`, `start_date`, `bank_name`, `enabled`, `enabled_hint`, `periods`, `period_open`, `period_closed_badge`, `close_period`, `reopen_period`, `rebuild`, `rebuild_preview`, `rebuild_keep_overrides`, `rebuild_result`, `rebuild_deleted`, `rebuild_created`, `rebuild_diff`, `rebuild_ok`, `override`, `override_note`, `override_done`, `origin_auto`, `origin_manual`, `origin_reversal`, `origin_opening`, `needs_review`, `source`, `invariant_receivables`, `invariant_payables`, `manual_entry_saved`, `lines_min`. `nav.php`: `gl_accounts`, `gl_entries`, `gl_trial_balance`, `gl_income`, `gl_balance_sheet`, `gl_settings`. `perm.php`: `act_gl_admin`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter 'Gl|RoleAccessTest|FormStarIntegrityTest|ScreenScriptsTest|BilingualFormsTest|BladePhpBlockTest'`
Expected: PASS. Then `php artisan promax:i18n-check`.

- [ ] **Step 5: Commit**

```bash
git add app routes lang resources/views/gl tests/Feature/Gl/GlScreensTest.php
git commit -m "GL: accounts tree, journal with manual entries and audited overrides, statements, financial reports, settings with rebuild"
```

---

### Task 9: QA run on real data, docs and skills

**Files:**
- Modify: `C:\xampp\htdocs\ProMax\promax-skills\promax-accounting\SKILL.md` (new section «16. دفتر الأستاذ العام» summarising doctrine: derived layer, invariant, posting rules by key, correction rules, rebuild, expenses/cash), `promax-system/SKILL.md` (§2 pointer + traps), `promax-system/references/schema.md` (8 tables), `promax-system/references/tests.md` (8 test classes), `DECISIONS.md` (١١ سبتمبر), `promax-system/references/runbook.md` (enable sequence below).
- Modify: `app/Support/Access.php` comment header of the `gl` group; `docs/superpowers/specs/...` unchanged.

- [ ] **Step 1: Run the full suite** — `php artisan test` → all green.

- [ ] **Step 2: QA on `promax_qa`** (never on `promax`):

```bash
php artisan db:seed --class=GlSeeder --env=qa
php artisan promax:gl-sync-reps --env=qa
php artisan tinker --env=qa --execute="App\Models\Setting::write('gl_start_date','2026-07-01'); App\Models\Setting::write('gl_enabled','1'); App\Models\Setting::flushCache(); \$r = app(App\Services\Gl\Ledger::class)->rebuild(\Illuminate\Support\Carbon::parse('2026-07-01'), true, false); echo json_encode([\$r->created, \$r->invariants]);"
php artisan promax:crawl --env=qa --roles=admin,accountant --links=60
```

Expected: `created` ≈ 328 (every non-consignment transaction), `receivables.ok === true`, crawl shows the new screens 200 with no slow page (>1500 ms) — the trial balance on 328 entries must be well under 500 ms. Open `localhost:8010/gl/accounts` in the Browser pane and eyeball the tree.

- [ ] **Step 3: Enable-on-live runbook** (write into `runbook.md`, do not execute):

1. Deploy code; `composer dump-autoload -o`; `php artisan migrate --force` (two guarded migrations); `php artisan db:seed --class=GlSeeder --force`; `php artisan promax:gl-sync-reps`.
2. In `/gl/settings`: set `gl_start_date` (owner's choice), bank name; **leave `gl_enabled` off**.
3. Post opening balances as one `opening` manual entry dated `gl_start_date − 1 day` for cash, bank, rep cash, capital.
4. Rebuild preview → check `receivables.ok`; rebuild.
5. Turn `gl_enabled` on. From now on every document posts live.

- [ ] **Step 4: Commit both repos**

```bash
git commit -am "GL: docs, runbook and QA notes"   # in Promax-erp
cd ../promax-skills && git add -A && git commit -m "Record the general ledger layer: doctrine, tables, tests, enable runbook"
```

---

## Self-review

- **Spec coverage:** §3 tables → Task 1; §4 rules → Task 1 seeder + Task 2 `Rules`; §5 services → Tasks 2–4, 7; hooks incl. `whereKey()->update()` tools → Task 5; §6 screens 1–7 → Tasks 6 and 8 (✎ override is `gl.entries.override`); §7 settlement row → Task 6; §8 guards → Tasks 3, 4, 6, 8; §9 tests → one class per bullet plus `GlSchemaTest`; §3.9 settings → Task 8 `saveGeneral`; `promax:gl-sync-reps` → Task 5. Out-of-scope items (§10) have no task by design.
- **Type consistency:** `Ledger::post(Model, ?User)`, `autoEntryFor(Model)`, `manual(Carbon, string, array, User, string, ?int)`, `reverse(GlEntry, User, ?Carbon, ?string)`, `overrideAccount(GlLine, GlAccount, User, ?string)`, `rebuild(Carbon, bool, bool, ?User): RebuildReport`, `invariants(): array{receivables, payables}` are used with the same signatures in Tasks 2–8. `RebuildFailed` carries `$report`. `GlLine.slot` values `dr|cr|tax` are written in `Rules` and read in `rebuild`.
- **Placeholders:** none — every controller body is either fully written (Task 6 expense) or specified field-by-field with validation rules and exception mapping (Task 8), which the implementer reproduces with the Task 6 controller as the template.
