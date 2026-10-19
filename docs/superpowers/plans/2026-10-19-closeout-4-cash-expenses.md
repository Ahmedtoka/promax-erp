# Close-out Lane 4: Safes & banks, expenses with approval, fixed assets

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. One builder subagent per task (TDD), one reviewer subagent per task. Steps use `- [ ]`.

**Goal:** Safes/banks operations (register, live balance, daily count, transfers, deposits, bank reconciliation), expenses with category, cost center, safe, receipt and an approval flow, and fixed assets with monthly straight-line depreciation.

**Master:** `2026-10-19-closeout-master.md` (Global Constraints and Interfaces are binding). Spec §3.2, §3.3, §4, §4.1. Lane 2 report: `Safe`, `CostCenter`, `gl_lines.cost_center_id`.

**Branch / worktree / DB:** `close-4-cash-expenses`, `C:\xampp\htdocs\ProMax\_wt\close-4`, `promax_test_4`.

## Decisions (logged in the lane report; the owner can change them)

- **L4-D1 Safe balance stays GL-based** (Lane 2 D8). Every cash document posts through `Ledger`, so `Safe::balance()` is the live balance. With `gl_enabled` off the screen shows a banner instead of a fake number.
- **L4-D2 Each safe gets its own GL account.** On create, if no account is picked, `Safe::ensureGlAccount()` creates a postable child of the `cash_main` parent group (`11`), code `11.<SAFE CODE>`. `MAADI-MAIN`/`BANK-1` keep `cash_main`/`bank` (Lane 2 seed). The mapping screen (Lane 3) can re-point it.
- **L4-D3 Cash documents extend `cash_movements`** (no new table): new nullable `from_safe_id`, `to_safe_id`, `cost_center_id`; new kinds `safe_transfer` (cash→cash) and `bank_transfer` (bank→bank). `deposit` (cash→bank) and `withdraw` (bank→cash) accept safes too. A row with no safes posts exactly as today (rule keys), so old rows and the old form keep working.
- **L4-D4 Rule keys stay the fallback.** When a document names a safe that has a GL account, that account replaces the rule's `cash_main`/`bank` key on that side. Every line carries `cost_center_id` = its safe's center (cash side) or the document's center (expense side).
- **L4-D5 Daily count = `safe_counts` document.** `expected` = `Safe::balance(date)` at count time, `difference = counted − expected`. A non-zero difference posts `cash.count`: shortage Dr `cash_over_short` / Cr safe; overage the reverse. One posted count per safe per day; void allowed in an open period.
- **L4-D6 Bank reconciliation never writes `gl_*`.** Marks live in `bank_statement_marks` keyed by a stable `line_key` (`<source_type>|<source_id>|<slot>` for auto entries, `entry:<id>|line:<id>` otherwise) so a rebuild that recreates line ids keeps the marks.
- **L4-D7 Expense status flow:** `pending → approved → posted` (posted = paid), plus `rejected` and `void`. `Rules::expense()` already posts only `posted`, so pending/approved never touch the safe (§4.1).
  - Approval key `act.gl.expense_approve` = admin only (`[]`), grantable per user or role from the permissions screen. That is the "approval holders configurable later, no code" structure.
  - An expense created by someone holding the approval key is approved and paid at once (the owner entering his own expense). Everyone else creates `pending`.
  - Paying (`gl.expenses.pay`) is `act.gl.post` (accountant + admin) and needs `approved`.
- **L4-D8 Categories = `expense_categories` settings list** with `type ∈ operating|asset|payroll|factory`, a default GL account, a default life (asset) and `links_vehicle` (fuel). Seeded idempotently with the spec's examples.
- **L4-D9 Legacy fallback, no forced fields server-side.** Server-side `category_id`, `safe_id` and `cost_center_id` are optional so old callers (and other lanes' tests) keep working: no safe → `Safe::forLegacy(paid_from)`; no center → the safe's center → `CostCenter::company()`; no category → `account_id` required as today. The form marks category, safe, center and receipt as required. A paid expense without a receipt shows a "no receipt" badge.
- **L4-D10 Asset expense creates the asset on payment.** Type `asset` debits the category's asset account (`fixed_assets` key by default) instead of an expense account. When it becomes `posted`, a `fixed_assets` row is created in the same transaction (cost = amount, life and start date from the expense, default from the category). Voiding a paid asset expense is refused once depreciation was posted.
- **L4-D11 Depreciation:** straight line, `monthly = round((cost − salvage) / life_months, 2)`, starting in the month of `start_date`, last month takes the remainder, never above `cost − salvage − opening_accum`. One `fixed_asset_depreciations` row per asset per `YYYY-MM` (unique) posted as `asset.depreciation` (Dr `expense_depreciation` / Cr `accum_depreciation`, center = asset's). `promax:depreciate {--month=}` defaults to last month, refuses a closed month, and is idempotent.
- **L4-D12 Opening assets for Lane 3:** a fixed asset can be entered with `opening_accum` and `opening_as_of`; months up to `opening_as_of` are not depreciated. `FixedAsset::openingEntry()` returns the lines; Lane 3 posts them with `Ledger::manual(..., 'opening')`.
- **L4-D13 New GL accounts are seeded by system key, never by code.** `CashGlSeed` looks a key up first and only creates the account when the code is free (else suffix `.L4`), so an imported accountant tree is never hijacked. `php artisan promax:cash-setup {--dry-run}` runs it plus the rules and categories on live.
- **L4-D14 Lang keys live in a new file `lang/{ar,en}/cash.php`** to keep merge conflicts with Lane 3 (`gl.php`) small.

## Interfaces (produced by this lane)

```php
// Safe (Lane 2 model, extended)
public function ensureGlAccount(): GlAccount            // creates 11.<code> if gl_account_id is null
public function movementsBetween(?Carbon $from, ?Carbon $to): array{in:float,out:float}
public static function forLegacy(string $paidFrom): ?Safe // unchanged

// CashMovement
KINDS = ['deposit','withdraw','rep_advance','rep_return','safe_transfer','bank_transfer']
SAFE_KINDS = ['deposit'=>['cash','bank'],'withdraw'=>['bank','cash'],'safe_transfer'=>['cash','cash'],'bank_transfer'=>['bank','bank']]

// SafeCount  (table safe_counts: safe_id, date, expected, counted, difference, note, status posted|void, ...)
// BankStatement (safe_id, number BST-####, date, date_from, date_to, opening_balance, closing_balance, status open|closed)
// BankStatementMark (statement_id, line_key unique, gl_line_id, amount, line_date, marked_by, marked_at)
App\Services\Cash\BankRecon::lineKey(GlLine $l): string
App\Services\Cash\BankRecon::lines(Safe $bank, ?Carbon $from, ?Carbon $to): Collection
App\Services\Cash\BankRecon::summary(BankStatement $s): array{book:float, cleared:float, statement:float, difference:float}

// ExpenseCategory: TYPES = ['operating','asset','payroll','factory']
// Expense: STATUSES = ['pending','approved','rejected','posted','void']
public function resolvedSafe(): ?Safe                    // safe_id ?? Safe::forLegacy(paid_from)
App\Services\Cash\ExpenseFlow::canApprove(User $u): bool // Access::action($u,'act.gl.expense_approve')
App\Services\Cash\ExpenseFlow::approve(Expense $x, User $by, ?string $note): void
App\Services\Cash\ExpenseFlow::reject(Expense $x, User $by, ?string $note): void
App\Services\Cash\ExpenseFlow::pay(Expense $x, User $by, ?int $safeId = null): void  // → posted, creates FixedAsset for type asset

// FixedAsset
public function monthlyAmount(): float
public function accumulated(?string $uptoPeriod = null): float   // opening_accum + posted rows
public function bookValue(): float
public function amountFor(string $periodKey): float              // 0 when outside life / fully depreciated
public function openingEntry(): array{memo:string, date:string, lines:list<array{account_id:int,cost_center_id:?int,debit:float,credit:float}>}
App\Services\Cash\Depreciation::run(string $periodKey, ?User $by = null, bool $dryRun = false): array{created:int, skipped:int, amount:float}
php artisan promax:depreciate {--month=} {--dry-run}
php artisan promax:cash-setup {--dry-run}

// Rule keys added: cash.safe_transfer, cash.bank_transfer, cash.count, asset.depreciation
// System keys added: fixed_assets, accum_depreciation, expense_depreciation, cash_over_short, expense_factory
```

**Routes (all inside `gl.` prefix, `role:admin,accountant`):**
`gl.safes` (GET), `gl.safes.store`, `gl.safes.update`, `gl.safes.counts` (GET), `gl.safes.counts.store`, `gl.safes.counts.void`, `gl.recon` (GET), `gl.recon.store`, `gl.recon.show` (GET), `gl.recon.mark`, `gl.recon.close`, `gl.expenses.approve`, `gl.expenses.reject`, `gl.expenses.pay`, `gl.expense_categories` (GET), `gl.expense_categories.store`, `gl.expense_categories.update`, `gl.assets` (GET), `gl.assets.store`, `gl.assets.depreciate` (admin).

**Access:** NAV group `nav.group_gl` gets `gl.safes`, `gl.recon`, `gl.assets`, `gl.expense_categories`. ACTIONS: `act.gl.post` covers the new store/void/mark routes; `act.gl.expense_approve` (`[]`) covers approve/reject; `act.gl.admin` covers `gl.assets.depreciate`, `gl.expense_categories.store/update`.

## Review Focus slice (Lane 4)

1. A pending expense: no GL entry, safe balance unchanged; after approve still none; after pay one entry, safe credited, center on both lines.
2. Depreciation run twice for the same month: second run creates 0, GL has one entry per asset per month.
3. Old `cash_movements` kinds and old expense rows (no safe) post exactly as before (`ExpensesCashTest` stays green except the accountant test, which now goes through approval).

---

### Task 1: Schema + models + GL seed

**Files:** create `database/migrations/2026_10_19_000400_cash_expenses_assets.php`, `app/Models/{ExpenseCategory,SafeCount,BankStatement,BankStatementMark,FixedAsset,FixedAssetDepreciation}.php`, `app/Services/Cash/CashGlSeed.php`, `app/Console/Commands/CashSetup.php`; modify `app/Models/{Expense,CashMovement,Safe}.php`, `app/Models/Gl/GlAccount.php` (SYSTEM_KEYS), `database/seeders/GlSeeder.php` (call `CashGlSeed::run()` at the end), `database/seeders/DatabaseSeeder.php` if needed. Test `tests/Feature/Cash/CashSchemaTest.php`.

- [ ] Failing tests: `test_tables_and_columns_exist`, `test_cash_setup_is_idempotent_and_never_takes_an_existing_code` (pre-create an account with code `1301` and no key → setup creates `fixed_assets` with another code and leaves `1301` untouched; second run creates 0), `test_cash_setup_dry_run_writes_nothing`, `test_default_categories_are_seeded_once`, `test_expense_resolved_safe_falls_back_to_legacy`.
- [ ] Guarded migration (hasTable/hasColumn, `dateTime()` only).
- [ ] Commit `feat(cash): schema, models and GL seed for safes, expenses and assets`.

### Task 2: Safes screen

**Files:** `app/Http/Controllers/Gl/SafeController.php`, `resources/views/gl/safes.blade.php`, routes, Access, `lang/{ar,en}/cash.php`. Test `tests/Feature/Cash/SafesScreenTest.php`.

- [ ] Tests: `test_admin_adds_a_safe_with_custodian_and_gets_its_own_gl_account`, `test_bank_needs_bank_name`, `test_balance_column_matches_safe_balance` (post a cash movement into the safe, screen shows the number), `test_date_range_shows_in_and_out_for_the_window`, `test_export_lists_every_safe`, `test_rep_gets_403`.
- [ ] Commit `feat(cash): safes and banks register with live balance`.

### Task 3: Cash documents through safes

**Files:** `Gl/CashMovementController.php`, `resources/views/gl/cash.blade.php`, `Services/Gl/Rules.php` (cash()), `GlSeeder` rules. Test `tests/Feature/Cash/CashDocumentsTest.php`.

- [ ] Tests: `test_safe_to_safe_transfer_moves_balance_between_safes_with_centers_on_lines`, `test_bank_deposit_from_a_safe`, `test_bank_to_bank_transfer`, `test_kind_must_match_safe_kinds` (deposit from a bank → error), `test_same_safe_on_both_sides_is_refused`, `test_old_deposit_without_safes_posts_as_before`.
- [ ] Commit `feat(cash): safe transfer, bank deposit and bank transfer documents`.

### Task 4: Daily cash count

**Files:** `SafeCount` model, `Observers/Gl/SafeCountObserver.php`, `Rules::count()`, `Ledger::sources()` adds posted counts, `SafeController@counts/storeCount/voidCount`, `resources/views/gl/safe_counts.blade.php`. Test `tests/Feature/Cash/SafeCountTest.php`.

- [ ] Tests: `test_shortage_posts_to_over_short_and_brings_the_balance_to_the_count`, `test_overage_posts_the_other_way`, `test_exact_count_posts_nothing_but_is_recorded`, `test_second_count_same_day_is_refused`, `test_void_removes_the_entry`, `test_rebuild_regenerates_the_count_entry`.
- [ ] Commit `feat(cash): daily cash count with shortage and overage`.

### Task 5: Bank reconciliation

**Files:** `Services/Cash/BankRecon.php`, `Gl/BankReconController.php`, `resources/views/gl/recon.blade.php`, `recon_show.blade.php`. Test `tests/Feature/Cash/BankReconTest.php`.

- [ ] Tests: `test_statement_lists_the_bank_lines_in_its_window`, `test_marking_lines_moves_them_to_cleared_and_difference_reaches_zero`, `test_marks_survive_a_rebuild` (line ids change, line_key keeps the mark), `test_a_line_cannot_be_marked_on_two_statements`, `test_closed_statement_refuses_marks`, `test_only_bank_safes`.
- [ ] Commit `feat(cash): bank reconciliation against a statement`.

### Task 6: Expenses: categories, safe, center, approval, fuel

**Files:** `Gl/ExpenseController.php`, `Gl/ExpenseCategoryController.php`, `Services/Cash/ExpenseFlow.php`, `Rules::expense()`, `ExpenseObserver` (watched fields), views `gl/expenses.blade.php`, `gl/expense_categories.blade.php`. Tests `tests/Feature/Cash/ExpenseApprovalTest.php`; adjust `tests/Feature/Gl/ExpensesCashTest.php` accountant case (now pending → approve → pay).

- [ ] Tests: `test_accountant_expense_is_pending_and_not_posted`, `test_admin_approves_then_accountant_pays_and_safe_is_credited_with_centers`, `test_accountant_cannot_approve_unless_granted`, `test_granted_user_can_approve`, `test_reject_keeps_it_out_of_the_ledger`, `test_pay_requires_approved`, `test_admin_expense_is_paid_at_once`, `test_category_account_is_used_when_no_account_given`, `test_fuel_links_rep_and_vehicle`, `test_legacy_paid_from_uses_the_legacy_safe_account`, `test_categories_screen_crud_is_admin_only`.
- [ ] Commit `feat(expenses): categories, safe, cost center, approval flow and fuel link`.

### Task 7: Fixed assets and depreciation

**Files:** `FixedAsset`, `FixedAssetDepreciation`, `Services/Cash/Depreciation.php`, `Console/Commands/Depreciate.php`, `Observers/Gl/FixedAssetDepreciationObserver.php`, `Rules::depreciation()`, `Ledger::sources()`, `ExpenseFlow::pay()` hook, `Gl/FixedAssetController.php`, `resources/views/gl/assets.blade.php`. Test `tests/Feature/Cash/FixedAssetTest.php`.

- [ ] Tests: `test_paying_an_asset_expense_creates_the_asset_and_debits_fixed_assets`, `test_monthly_amount_and_last_month_remainder` (cost 1000, life 3 → 333.33, 333.33, 333.34), `test_depreciate_is_idempotent_per_month`, `test_depreciation_posts_with_cost_center`, `test_no_depreciation_before_start_or_after_life`, `test_opening_asset_skips_months_up_to_opening_as_of_and_opening_entry_balances`, `test_closed_month_is_refused`, `test_void_of_depreciated_asset_expense_is_refused`, `test_register_screen_and_export`.
- [ ] Commit `feat(assets): fixed-asset register and monthly straight-line depreciation`.

### Task 8: Wrap-up

- [ ] UAT cases `HANDOVER_2026-10\uat\_src\uat_cases_close4.py`.
- [ ] Full suite, `promax:i18n-check`, `static_check.py`.
- [ ] QA (`--env=qa`): Shopify/iTrack tokens NULL check, `migrate --env=qa`, `promax:cash-setup --env=qa`, browser check of the new screens on port 8010.
- [ ] Lane report `LANE_REPORTS_closeout\LANE_4.md`, one line in `HANDOFF_LIVE.md`.
