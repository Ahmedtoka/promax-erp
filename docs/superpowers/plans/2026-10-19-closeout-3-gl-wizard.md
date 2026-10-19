# Lane 3: GL go-live wizard (detailed plan)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. One fresh builder subagent per task (TDD: failing test first), then one fresh reviewer subagent per task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Turn the accountant's chart of accounts into the live ledger from one resumable wizard screen: COA checks (red blocks, yellow accepted and saved), adopt, map posting rules, cost centers, safes, manual opening balances with an auto difference account, a rebuild preview with a detailed diff, closed-period rejection, and cost-center reports.

**Spec:** `docs/superpowers/specs/2026-10-19-promax-closeout-design.md` §3.1, §3.1.1, §3.1.2, §4.1. **Master:** `docs/superpowers/plans/2026-10-19-closeout-master.md` (Global Constraints are binding).

**Branch / worktree / DB:** `close-3-gl-wizard`, `C:\xampp\htdocs\ProMax\_wt\close-3`, `promax_test_3` (phpunit.xml, skip-worktree).

**Consumes from Lane 2 (merged in master):** `CostCenter::company()/maadi()/forWarehouse()/lines()`, `CostCenterResolver::for(Model): ?int`, `Safe::forLegacy()/balance()`, `Safe::LEGACY`, `FoundationSeed::run()`, `gl_lines.cost_center_id`.

## Global rules for every task (builders read this)

- Load `promax-system`, `promax-feature-dev`, `promax-accounting` (§12), `ui-promax`, `promax-i18n` from `C:\xampp\htdocs\ProMax\promax-skills`.
- GL writes only through `App\Services\Gl\Ledger`. Money writes in `DB::transaction`.
- Every migration guarded with `hasTable`/`hasColumn`. Time columns `dateTime()`; use `$t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();` instead of `timestamps()`.
- Every UI string in `lang/ar/*.php` **and** `lang/en/*.php`, same key. New wizard strings live in a new file `lang/{ar,en}/golive.php`. Double quotes for English strings containing an apostrophe.
- New screens: `Access::SCREENS` (admin + accountant through the existing `gl.` prefix), `Access::NAV` (`nav.group_gl`), buttons through `Access::allows`/`Access::action`. Writes that change the ledger structure are `act.gl.admin` (admin only).
- Every table screen: `DateRange` where a business date exists, and `?export=1` CSV via `App\Support\Csv::download` where it lists rows.
- Tests: `php artisan test --filter=<Test>`; DB is `promax_test_3`. Never touch DB `promax`, never start/stop MySQL, never touch port 8020.
- Commit after each task with a conventional message; end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. No client data in commits.

## Decisions (logged in the lane report too)

- **D1 Mapping is two-layer.** Rules keep `debit_key/credit_key/tax_key` (system keys). The mapping screen (a) moves each **system key** onto an adopted account, and (b) optionally pins a **rule slot** to a specific adopted account (`gl_posting_rules.debit_account_id/credit_account_id/tax_account_id`). `Rules` prefers the pinned account, else the key. So one tx type can post to the accountant's own account without touching other rules.
- **D2 Adopted top-level accounts hang under the seeded root of their type** (`1`…`5`). Reports, balance sheet and type logic keep working. A seeded system account whose code collides with an adopted code is re-coded `S-<code>` at adoption (before go-live, no history meaning lost; logged in the adopt result).
- **D3 Type of an adopted account** = QuickBooks `qb_type` mapped (`QB_TYPES` table in `CoaAdopter`), else the nearest ancestor's, else red check `no_type`.
- **D4 Red checks:** `dup_code`, `no_code`, `parent_balance` (a draft parent with a non-zero file balance, or an adopted group account with GL lines), `no_type`, `code_taken` (code used by a non-system GL account that is not this draft row). **Yellow:** `truncated_name` (name ends in `...`/`…` or length ≥ 31 with no space in the last 3 chars — QuickBooks cuts at 31), `no_arabic`, `per_product` (name equals a product's `name`/`name_en`/`code`), `unused` (leaf, file balance 0/null, and no GL lines once adopted). Yellow never blocks; its accept is saved per (check, draft row).
- **D5 Unmapped rule blocks rebuild only after adoption** (`Setting gl_coa_adopted_at` set). Before adoption the old seeded tree keeps working exactly as today (existing tests stay green).
- **D6 Opening entry:** one `origin=opening` GL entry, `rule_key='golive.opening'`, dated `gl_start_date − 1 day`, rewritten as a whole on every "post opening". Receivables line = Σ client `transactions` dated before start (excluding consignment); payables line = Σ `supplier_transactions` before start (credit − debit). Clients and suppliers get their own opening balance **by name** as a real `opening` transaction / supplier transaction dated start − 1 (so their statements show it). Inventory = Σ batch `qty_remaining` × `Pricing::costFor` + open custody remaining × batch cost, per warehouse cost center. Fixed assets and advances come from container-bound registers (Lane 4 / Lane 7 bind them; null defaults). Any imbalance goes to system account `opening_difference` («فرق الأرصدة الافتتاحية», created under equity on demand) and stays shown as a warning until zero.
- **D7 Closed-period rejection is a request mode.** Web requests run with `PeriodGuard::MODE_REJECT`, API requests with `MODE_FLAG`, console/tests-without-HTTP with `MODE_OFF` (today's behaviour). Watched models are rejected on create/update/delete when their business date (old or new) is in a closed month. API keeps accepting; the GL entry gets `needs_review` with reason `closed_period` (already stored by `Ledger::post`) plus `late_sync`.
- **D8 Rebuild with closed months:** the wizard rebuild uses `Ledger::rebuild(..., skipClosed: true)`: auto entries and sources inside closed months are frozen (not deleted, not re-posted). The settings-screen rebuild keeps today's strict refusal.
- **D9 Diff identity:** an auto entry is keyed by `source_type|source_id`; its signature is the sorted list of `(slot, account_id, cost_center_id, debit, credit)`. Created = key only after, deleted = key only before, changed = both with different signature (amount delta = after total − before total). Grouped by `period_key` × short source type.
- **D10 Wizard steps 7 and 9 are placeholders** registered through `GoLiveWizard::register()`; Lane 9 replaces them. Leave balances (step 6) are not GL money → Lane 7.
- **D11 Close policy (step 1):** `gl_close_policy` ∈ `manual|auto`, `gl_close_after_days` (default 10). `php artisan promax:gl-close-periods {--dry-run}` closes every month whose last day + N days < today, never a month before the start month; scheduled daily 02:20 only acting when policy = auto.

---

## Task 1: Schema and models

**Files:**
- Create: `database/migrations/2026_10_19_000300_gl_golive.php`
- Create: `app/Models/Gl/CoaCheckAccept.php`, `app/Models/Gl/GlOpeningLine.php`, `app/Models/Gl/GlRebuildRun.php`, `app/Models/Gl/GlGoLiveStep.php`
- Modify: `app/Models/Gl/GlAccount.php` (`SYSTEM_KEYS` += `inventory`, `opening_difference`; fillable += `coa_draft_id`; `public static function openingDifference(): self` firstOrCreate under root `3`, code `3199` or `3199.1` if taken, name «فرق الأرصدة الافتتاحية» / "Opening balance difference", postable, system), `app/Models/Gl/GlPostingRule.php` (fillable += three `*_account_id`, relations `debitAccount()/creditAccount()/taxAccount()`)
- Test: `tests/Feature/Gl/GoLiveSchemaTest.php`

**Interfaces:**
- `gl_accounts.coa_draft_id` unsignedBigInteger nullable, index.
- `gl_posting_rules.debit_account_id|credit_account_id|tax_account_id` nullable FK → `gl_accounts` `nullOnDelete`.
- `coa_check_accepts(id, check varchar(30), draft_account_id unsignedBigInteger nullable, accepted_by FK users nullable, accepted_at dateTime)`, unique(`check`,`draft_account_id`).
- `gl_opening_lines(id, account_id FK gl_accounts, cost_center_id FK nullable, debit decimal(14,2) default 0, credit decimal(14,2) default 0, note varchar(250) nullable, created_by nullable, created_at/updated_at dateTime)`.
- `gl_rebuild_runs(id, mode varchar(10) preview|execute, from_date date, status varchar(10) ok|failed|blocked, summary longText json, rows longText json, checks longText json, error varchar(500) nullable, preview_id nullable self-ref, approved_at dateTime nullable, approved_by nullable, created_by nullable, created_at/updated_at dateTime)`. Model casts `summary/rows/checks` → `array`.
- `gl_golive_steps(id, key varchar(30) unique, status varchar(10) default 'todo', data longText json nullable, done_at dateTime nullable, done_by nullable, created_at/updated_at dateTime)`. `GlGoLiveStep::for(string $key): self` (firstOrCreate).

- [ ] **Step 1:** failing tests: `test_new_tables_and_columns_exist`, `test_opening_difference_account_is_created_once_under_equity` (call twice → same id, type equity, normal_side credit, system_key `opening_difference`), `test_step_row_is_created_on_first_read`.
- [ ] **Step 2:** run `php artisan test --filter=GoLiveSchemaTest` → FAIL.
- [ ] **Step 3:** implement migration (guarded; `down()` drops only what it created) and models.
- [ ] **Step 4:** run filter + `--filter=GlSchemaTest` → PASS.
- [ ] **Step 5:** commit `feat(gl): go-live schema (draft link, rule accounts, check accepts, opening lines, rebuild runs, wizard steps)`.

## Task 2: Cost center on every rule line

**Files:** Modify `app/Services/Gl/Rules.php`. Test `tests/Feature/Gl/RulesCostCenterTest.php`.

**Interfaces:** `Rules::linesFor(Model $source)` — after the per-type builder, every line without a `cost_center_id` key gets `CostCenterResolver::for($source)` (one call per source, not per line). Lines that already carry one keep it.

- [ ] **Step 1:** failing tests: `test_sale_transaction_lines_carry_maadi_center` (seed GlSeeder, `CostCenter::maadi()`, a sale tx → every `gl_lines.cost_center_id` = maadi id), `test_supplier_lines_have_no_center` (null), `test_resolver_failure_does_not_lose_the_entry` (drop nothing; just assert entry exists when resolver returns null).
- [ ] **Step 2–4:** red → implement → green, plus `--filter=Gl` all green.
- [ ] **Step 5:** commit `feat(gl): rules put the resolved cost center on every line`.

## Task 3: COA checks (red / yellow with saved accept)

**Files:**
- Create: `app/Services/Gl/CoaChecks.php`
- Modify: `app/Http/Controllers/Gl/CoaDraftController.php` (pass checks to the view; `accept()` action), `resources/views/gl/coa.blade.php` (checks panel above the tree: red count, yellow count, accepted count; list grouped by check with the account path; row badge in the tree; "موافق" per yellow and "موافق على الكل" per check type), `routes/web.php` (`POST /gl/coa/checks/accept` → `gl.coa.accept`, `role:admin`), `app/Support/Access.php` (`act.gl.admin` += `gl.coa.accept`), `lang/{ar,en}/coa.php`.
- Test: `tests/Feature/Gl/CoaChecksTest.php`

**Interfaces:**
- `CoaChecks::RED = ['dup_code','no_code','parent_balance','no_type','code_taken']`, `CoaChecks::YELLOW = ['truncated_name','no_arabic','per_product','unused']`.
- `CoaChecks::run(): array{items: list<array{check:string, level:'red'|'yellow', draft_id:?int, code:?string, name:string, path:string, accepted:bool}>, red:int, yellow:int, accepted:int}` — `red` counts all red items, `yellow` counts **unaccepted** yellow items.
- `CoaChecks::hasRed(): bool`.
- `CoaChecks::accept(string $check, ?int $draftId, User $by): void` (yellow only; red → `InvalidArgumentException`), `CoaChecks::acceptAll(string $check, User $by): int`.
- `CoaChecks::typeFor(CoaDraftAccount $a, Collection $byId): ?string` (shared with the adopter; uses `CoaAdopter::QB_TYPES` — define the const here as `CoaChecks::QB_TYPES` and let the adopter reuse it):
  `Bank, Accounts Receivable, Other Current Asset, Fixed Asset, Other Asset → asset`; `Accounts Payable, Credit Card, Other Current Liability, Long Term Liability → liability`; `Equity → equity`; `Income, Other Income → revenue`; `Cost of Goods Sold, Expense, Other Expense → expense` (case-insensitive, trimmed).
- Accept route body: `check`, `draft_id` (nullable), `all` (bool).

- [ ] **Step 1:** failing tests (each builds draft rows directly):
  - `test_duplicate_code_is_red_on_both_rows`
  - `test_account_without_code_is_red`
  - `test_parent_with_file_balance_is_red`
  - `test_unknown_type_without_typed_ancestor_is_red_and_typed_ancestor_clears_it`
  - `test_missing_arabic_is_yellow_and_accept_is_saved` (accept → `run()['yellow']` drops by one; a second `run()` still shows accepted=true)
  - `test_red_cannot_be_accepted` (exception)
  - `test_per_product_and_truncated_and_unused_are_yellow`
  - `test_accept_route_is_admin_only` (accountant → 403, admin → 302)
  - `test_coa_screen_shows_the_checks_panel` (GET gl.coa as admin → sees `__('coa.checks_title')`)
- [ ] **Step 2–4:** red → implement → green; `--filter=CoaDraftTest` green.
- [ ] **Step 5:** commit `feat(gl): COA draft checks with red blockers and saved yellow accepts`.

## Task 4: Adopt the tree

**Files:**
- Create: `app/Services/Gl/CoaAdopter.php`, `app/Services/Gl/CoaHasRed.php` (exception)
- Modify: `CoaDraftController` (`adopt()`), `routes/web.php` (`POST /gl/coa/adopt` → `gl.coa.adopt`, `role:admin`), `Access` (`act.gl.admin` += `gl.coa.adopt`), `gl/coa.blade.php` (button «اعتمد الشجرة», disabled with reason while red exists; after adoption shows adopted-at and a link to the mapping screen), lang.
- Test: `tests/Feature/Gl/CoaAdoptTest.php`

**Interfaces:**
- `CoaAdopter::adopt(User $by): array{created:int, updated:int, deactivated:int, recoded:list<string>}` — throws `CoaHasRed` (message `__('coa.adopt_blocked', ['n'=>red])`) when `CoaChecks::hasRed()`.
  - In one `DB::transaction`. Walk the draft tree parents-first. Each draft row ↔ `GlAccount` by `coa_draft_id` (update) or new.
  - `code` = draft code, `name` = `name_ar ?: name`, `name_en` = `name`, `type` = `CoaChecks::typeFor`, `normal_side` = `GlAccount::normalSideFor(type)`, `parent_id` = adopted parent or the seeded root of the type (`GlAccount::where('code', <root digit>)->whereNull('parent_id')`, root digit from `GlAccount::ROOT_TYPES` flipped), `is_postable` = draft has no children, `is_system` false, `active` true.
  - Seeded system account (`is_system` and `coa_draft_id` null) holding a colliding code → re-coded `S-<code>` first (listed in `recoded`).
  - Previously adopted account whose draft row is gone → `active=false` if it has no lines (counted in `deactivated`), else left as is.
  - Writes `Setting gl_coa_adopted_at` = now (string) and `gl_coa_adopted_by`.
- `CoaAdopter::adopted(): bool` (setting present).

- [ ] **Step 1:** failing tests:
  - `test_adopt_refused_while_red_exists` (dup code → `CoaHasRed`, 0 accounts with `coa_draft_id`)
  - `test_adopt_creates_accounts_with_types_parents_and_postable_leaves` (Bank parent with two children → parent not postable under root `1`, children postable asset/debit)
  - `test_adopt_twice_updates_not_duplicates` (rename draft, re-adopt → same id, new name)
  - `test_colliding_seeded_code_is_recoded` (draft code `1101` → seeded main safe becomes `S-1101`, keeps `system_key`)
  - `test_route_is_admin_only_and_redirects_with_counts`
- [ ] **Step 2–4:** red → implement → green.
- [ ] **Step 5:** commit `feat(gl): adopt the accountant tree into gl_accounts (blocked while red)`.

## Task 5: Mapping (system keys + rule slots) and the unmapped guard

**Files:**
- Create: `app/Services/Gl/GlMapping.php`, `app/Services/Gl/UnmappedRules.php` (exception, `public array $problems`), `app/Http/Controllers/Gl/MappingController.php`, `resources/views/gl/mapping.blade.php`
- Modify: `app/Services/Gl/Rules.php` (slot account pin), `app/Http/Controllers/Gl/SettingsController.php` (`rebuildPreview`/`rebuild` catch `UnmappedRules` → page with error listing problems), `routes/web.php` (`GET /gl/mapping` → `gl.mapping`; `POST /gl/mapping/keys` → `gl.mapping.keys`; `POST /gl/mapping/rules` → `gl.mapping.rules`; both POST `role:admin`), `Access` (NAV item after `gl.coa`: `['gl.mapping','🔗','nav.gl_mapping','gl.mapping*',null]`; `act.gl.admin` += the two POST routes), lang `gl.php` + `nav.php`.
- Test: `tests/Feature/Gl/GlMappingTest.php`

**Interfaces:**
- `GlMapping::REQUIRED_KEYS = ['receivables','payables','cash_main','bank','rep_cash','sales','sales_returns','discounts_allowed','vat_output','withheld_tax','opening_equity','expense_purchases','suspense','opening_difference','inventory']`. `GlMapping::SYSTEM_ONLY = ['suspense','opening_difference']` (may stay on the seeded/auto account after adoption).
- `GlMapping::keys(): list<array{key:string, account:?GlAccount, ok:bool, why:?string}>` — ok when the account exists, is active, is postable (except `rep_cash`, which must be a group), and (after adoption) has `coa_draft_id` or the key is SYSTEM_ONLY.
- `GlMapping::assignKey(string $key, GlAccount $to, User $by): void` — transaction: clear `system_key` on the holder, set it on `$to`. For `rep_cash`: `$to` becomes `is_postable=false`, every account with `user_id` not null is re-parented under `$to` and re-coded `<to.code>.<suffix>` (suffix = old code after the last `.`). Refuses `$to` that already holds another required key (`InvalidArgumentException`).
- `GlMapping::rules(): list<array{rule:GlPostingRule, slots: array<string, array{key:?string, account:?GlAccount, pinned:bool, ok:bool, why:?string}>}>` — slots `debit|credit|tax`; `tax` only when `tax_key` or `tax_account_id` set; the expense rules' debit slot is "from the document" (always ok). `rep_cash` slots ok when the `rep_cash` key is ok.
- `GlMapping::pin(GlPostingRule $rule, string $slot, ?GlAccount $account): void` (null clears the pin; account must be postable+active).
- `GlMapping::problems(): list<string>` (translated, one per bad key / bad active rule slot; inactive rules are listed as a problem too). `GlMapping::assertComplete(): void` throws `UnmappedRules` when `CoaAdopter::adopted()` and problems exist; no-op before adoption.
- `Rules`: when a rule has `<side>_account_id`, that account is used through `safeAccount()` instead of the key; `rep_cash` pins are not allowed (validated in `pin`).

- [ ] **Step 1:** failing tests:
  - `test_before_adoption_mapping_never_blocks` (`assertComplete()` no throw on the seeded tree)
  - `test_after_adoption_unmapped_key_blocks_rebuild_preview` (adopt a small draft, POST `gl.rebuild.preview` → page shows `UnmappedRules` problem text, no `gl_entries` change)
  - `test_assign_key_moves_the_system_key` (receivables → adopted AR account; old account `system_key` null; a new sale posts to the adopted account)
  - `test_rep_cash_assignment_reparents_rep_accounts`
  - `test_pinned_rule_slot_wins_over_the_key` (pin `tx.sale` credit to adopted "Sales Van" → sale credit line on it)
  - `test_mapping_screen_lists_every_rule_and_is_admin_write_only`
- [ ] **Step 2–4:** red → implement → green; `--filter=Gl` green.
- [ ] **Step 5:** commit `feat(gl): mapping screen for system keys and rule slots; unmapped rules block rebuild after adoption`.

## Task 6: Closed-period rejection (web) and late-sync flag (mobile)

**Files:**
- Create: `app/Support/PeriodGuard.php`, `app/Http/Middleware/PeriodGuardMode.php`, `app/Observers/Gl/PeriodGuardObserver.php`
- Modify: `bootstrap/app.php` (append `PeriodGuardMode:reject` to the `web` group and `PeriodGuardMode:flag` to the `api` group), `app/Providers/AppServiceProvider.php` (watch list), `app/Services/Gl/Ledger.php` (`post()`: add reason `late_sync` when `PeriodGuard::mode() === MODE_FLAG` and the period is closed), `lang/{ar,en}/gl.php`.
- **Explicit guards** (these paths use mass `Query\Builder` update/delete or set the date after create, so observers never see the real date). Each calls `PeriodGuard::assertOpen(...)` **before** any write; the `ValidationException` is rendered by Laravel as back-with-errors (web) — make sure no surrounding `catch (\Throwable)` swallows it (re-throw `ValidationException` where such a catch exists):
  - `ManualDocController::anchors()` (≈line 166) — the `doc_date` for every manual invoice/return/gift/collection.
  - `OpsController`: `redateInvoice` (old `created_at` and new `date`), `repriceInvoice`, `editInvoiceItems`, `reassignInvoice`, `toggleInvoicePayment`, `destroyInvoice` (all on `invoice.created_at`). `renumberInvoices`: skip invoices in closed months (do not renumber them) and report how many were skipped — a renumber must not edit a closed month.
  - `OpsController::collect` (date input), `ErpController::openingBalance` / `Client::setOpeningBalance` (old opening date and new), `SupplierController::opening` (old and new), `SupplierController::pay` (`paid_on`), `SupplierController::invoiceOrder` (`invoice_date`), `ReturnController::destroy` (return's transaction date), `RepSettlementController::destroy` (`to_at`), `DuesController::settle` (`period_end`).
  - Existing guards in `Gl\ExpenseController` / `Gl\CashMovementController` switch to the new message (same key `period`), keep `void_closed_hint`.
- Survey facts: mobile endpoints date documents with server `today()`; a late sync reaches a closed month only when the current month is closed — the test closes the current month.
- Test: `tests/Feature/Gl/ClosedPeriodRejectionTest.php`

**Interfaces:**
- `PeriodGuard::MODE_OFF='off'`, `MODE_REJECT='reject'`, `MODE_FLAG='flag'`. `PeriodGuard::mode(): string`, `PeriodGuard::setMode(string): void`, `PeriodGuard::using(string $mode, callable $fn): mixed`.
- `PeriodGuard::watch(string $modelClass, string|callable $dateOf): void` — Lanes 4–7 call it for their own documents. Defaults registered here: `Transaction` (`date`), `SupplierTransaction` (`date`), `Expense` (`date`), `CashMovement` (`date`), `RepSettlement` (`to_at ?? created_at`), `Invoice` (`created_at`), `PurchaseOrder` (`delivered_at ?? created_at`, only when status is delivered), `ClientReturn` (`created_at`).
- `PeriodGuard::assertOpen(Carbon|string|null $date, ?string $what = null): void` — in `MODE_REJECT` throws `Illuminate\Validation\ValidationException::withMessages(['period' => __('gl.period_closed_reject', ['month' => <Arabic/English month name + year>])])`; no-op otherwise. Message (ar): «شهر :month مقفول، افتح الفترة من الإعدادات» / (en) "The month :month is closed. Reopen the period from the settings.". Month label via `Carbon::translatedFormat('F Y')` in the current locale.
- `PeriodGuard::isClosed(Carbon $date): bool` (per-request cache of closed keys, flushed by `GlPeriod` saved).
- Observer `creating/updating/deleting` on watched models: date old and new (updating), current (creating/deleting) → `assertOpen`.

- [ ] **Step 1:** failing tests:
  - `test_web_back_dated_manual_document_into_closed_month_is_rejected_with_the_month_named` (close `2026-09`, POST the manual-doc collection route dated 2026-09-15 → session error `period` contains the localized "September 2026"/«سبتمبر 2026»; no `transactions` row)
  - `test_same_document_from_mobile_late_sync_is_accepted_and_flagged` (API collection/sale with `idem_key` dated in the closed month → 200/201, row exists, GL entry `needs_review` and `review_reason` contains `closed_period`)
  - `test_expense_void_in_closed_month_is_rejected`
  - `test_invoice_redate_into_closed_month_is_rejected`
  - `test_open_month_is_untouched` (same POST dated an open month → succeeds)
  - `test_console_mode_keeps_todays_behaviour` (direct `Transaction::create` in a closed month → created + needs_review)
- [ ] **Step 2–4:** red → implement → green; run full `php artisan test` (guards touch everything).
- [ ] **Step 5:** commit `feat(gl): reject web documents dated in a closed month; flag late mobile syncs`.

## Task 7: Opening balances (manual per account, clients and suppliers by name, inventory, registers, difference)

**Files:**
- Create: `app/Services/Gl/OpeningBuilder.php`, `app/Contracts/Gl/OpeningProvider.php`, `app/Contracts/Gl/FixedAssetRegister.php`, `app/Contracts/Gl/AdvancesRegister.php`, `app/Services/Gl/Opening/NullFixedAssetRegister.php`, `app/Services/Gl/Opening/NullAdvancesRegister.php`, `app/Services/Gl/Opening/InventoryOpening.php`
- Modify: `app/Services/Gl/OpeningBalances.php` (suppliers), `app/Services/Gl/Ledger.php` (`opening()`), `app/Providers/AppServiceProvider.php` (`bindIf` the two null registers)
- Test: `tests/Feature/Gl/OpeningBuilderTest.php`, extend `tests/Feature/Gl/OpeningBalancesTest.php`

**Interfaces:**
- `interface OpeningProvider { public function key(): string; public function label(): string; /** @return list<array{account_id:int, cost_center_id?:?int, debit:float, credit:float, memo?:string}> */ public function lines(Carbon $asOf): array; }`
- `interface FixedAssetRegister extends OpeningProvider {}` and `interface AdvancesRegister extends OpeningProvider {}` — **Lane 4 binds `FixedAssetRegister`** (asset cost Dr / accumulated depreciation Cr per asset account), **Lane 7 binds `AdvancesRegister`**. Null defaults return `[]`.
- `OpeningBalances::setClient(Client $c, float $amount, Carbon $date, User $by): ?Transaction` — one `opening` transaction per client with `idem_key = 'golive-opening:client:{id}'`; updates the amount if it exists (0 deletes it), `recalculate()` in the same transaction.
- `OpeningBalances::setSupplier(Supplier $s, float $amount, Carbon $date, User $by): ?SupplierTransaction` — `kind='opening'`, positive = we owe (credit), negative = debit; one per supplier keyed by `memo`/`idem` marker `golive-opening:supplier:{id}` (add `idem_key` column guarded if missing on `supplier_transactions`, else match on `source_type='golive'`).
- `OpeningBalances::previewSuppliers(string $path): array` / `postSuppliers(string $path, User $by): int` — CSV (supplier code or name, amount, date) same statuses as clients.
- `OpeningBuilder::asOf(): ?Carbon` (= start − 1 day, null when no start date).
- `OpeningBuilder::providers(): list<OpeningProvider>` — receivables, payables, inventory, fixed assets, advances, plus `OpeningBuilder::register(OpeningProvider $p)`.
- `OpeningBuilder::compose(): array{lines: list<line>, by_source: array<string, array{label:string, debit:float, credit:float}>, debit:float, credit:float, difference:float}` — manual `gl_opening_lines` + providers; `difference = debit − credit` (rounded 2).
- `OpeningBuilder::post(User $by): GlEntry` — `Ledger::opening($asOf, $lines + difference line on GlAccount::openingDifference(), $by)`.
- `Ledger::opening(Carbon $date, array $lines, User $by, string $memo): GlEntry` — transaction: delete the previous entry with `origin='opening'` and `rule_key='golive.opening'`; `assertOpen($date)`; lines may include control accounts (built only by the builder); writes `rule_key='golive.opening'`.
- `OpeningBuilder::differenceBalance(): float` — live GL balance of `opening_difference` (shown until 0).

- [ ] **Step 1:** failing tests:
  - `test_compose_balances_with_a_difference_line` (manual Dr cash 1000 only → difference credit 1000 on `opening_difference`)
  - `test_receivables_line_equals_client_balances_before_start` (client opening by name 500 + an old sale 300 → receivables Dr 800)
  - `test_supplier_opening_by_name_shows_on_their_ledger_and_payables_line`
  - `test_inventory_line_uses_current_batch_costs` (batch qty 10 cost 12.5 → 125 on `inventory`)
  - `test_fixed_asset_register_binding_is_used` (bind a fake in the test → its lines appear)
  - `test_post_twice_rewrites_one_opening_entry` and `test_rebuild_never_touches_the_opening_entry`
  - `test_set_client_zero_removes_the_opening_row`
- [ ] **Step 2–4:** red → implement → green; `--filter="Opening|Gl"` green.
- [ ] **Step 5:** commit `feat(gl): opening balances builder with clients/suppliers by name, inventory, register hooks and auto difference`.

## Task 8: Rebuild with a detailed diff (preview → approve → execute)

**Files:**
- Create: `app/Services/Gl/RebuildDiff.php`
- Modify: `app/Services/Gl/Ledger.php` (`rebuild()` gets `bool $skipClosed = false, ?callable $probe = null`; skipClosed excludes entries and sources whose month is closed and replaces the refusal; `$probe($report)` runs inside the transaction right before rollback/commit and its return value is stored on `$report->probe`), `app/Services/Gl/RebuildReport.php` (`public mixed $probe = null; public array $frozenPeriods = [];`)
- Test: `tests/Feature/Gl/RebuildDiffTest.php`

**Interfaces:**
- `RebuildDiff::preview(User $by): GlRebuildRun` — `GlMapping::assertComplete()` (blocked → run with `status='blocked'`, `error` = problems), snapshot before, `Ledger::rebuild(from: start, keepOverrides: true, dryRun: true, by, skipClosed: true, probe: snapshot+checks)`, diff, store `mode='preview'`.
- `RebuildDiff::approve(GlRebuildRun $preview, User $by): void` — only `mode=preview`, `status=ok`, created within the last 24 h.
- `RebuildDiff::execute(GlRebuildRun $preview, User $by, ?callable $progress = null): GlRebuildRun` — requires approved; real rebuild; stores `mode='execute'`, `preview_id`. `$progress(int $done, int $total, string $phase)` is passed through for Lane 9's loading screen (called at least at start/end of each phase: `snapshot`, `rebuild`, `diff`, `checks`).
- `summary` = `{created:int, deleted:int, changed:int, created_amount:float, deleted_amount:float, changed_delta:float, overrides_kept:int, overrides_dropped:int, frozen:list<string>}`.
- `rows` = list of `{period:string, source:string, created:int, deleted:int, changed:int, created_amount:float, deleted_amount:float, changed_delta:float}` sorted by period then source; `source` = class basename (`Transaction`, `SupplierTransaction`, `Expense`, `CashMovement`, `RepSettlement`). Plus `samples` (first 20 changed keys with before/after totals) inside `summary`.
- `checks` = list of `{key, label_key, expected:float, actual:float, ok:bool}` for `receivables_total` (GL receivables all dates vs `SUM(clients.balance)`), `payables_total` (vs supplier ledger), `trial_balanced` (Σ debit = Σ credit), `invariants` (from the `RebuildReport`). `RebuildDiff::addCheck(string $key, callable $fn)` lets Lane 4 add `safes` and Lane 9 add costing checks; `$fn(): array{label_key:string, expected:float, actual:float, ok:bool}`.

- [ ] **Step 1:** failing tests:
  - **Review Focus 2:** `test_rebuild_twice_without_change_gives_an_empty_diff_and_keeps_manual_entries_and_overrides` (world with sale+collection+override+manual entry; execute twice; second summary 0/0/0; manual entry still there; override still applied)
  - `test_changed_amount_is_reported_per_month_and_source` (edit a tx amount behind the ledger with `whereKey()->update`, preview → 1 changed in that month for `Transaction` with the delta)
  - `test_new_document_is_created_and_deleted_source_is_deleted_in_the_diff`
  - `test_execute_requires_an_approved_ok_preview`
  - `test_blocked_when_mapping_incomplete_after_adoption`
  - `test_closed_month_is_frozen_not_refused` (close a month after start → preview ok, frozen lists it, its entries untouched)
  - `test_checks_report_receivables_against_client_balances`
- [ ] **Step 2–4:** red → implement → green; `--filter=Gl` green (strict refusal in old rebuild tests still passes).
- [ ] **Step 5:** commit `feat(gl): rebuild preview/approve/execute with a per-month per-source diff and go-live checks`.

## Task 9: Reports filtered by cost center

**Files:** Modify `app/Services/Gl/Reports.php` (`trialBalance/income/balanceSheet` gain `?int $costCenterId = null`; the filter includes the center and its descendants via `cost_centers.parent_id` walk; `sums()` gains the same parameter), `app/Http/Controllers/Gl/ReportController.php` (read `cost_center_id`, pass centers list, CSV header meta), the three views (select «مركز التكلفة» in the filter form, «كل المراكز» default), lang. Test `tests/Feature/Gl/ReportsCostCenterTest.php`.

- [ ] **Step 1:** failing tests: `test_trial_balance_filtered_by_center_shows_only_its_lines`, `test_company_center_includes_children`, `test_income_and_balance_sheet_accept_the_filter_and_export`.
- [ ] **Step 2–4:** red → green; `--filter=GlReportsTest` green.
- [ ] **Step 5:** commit `feat(gl): trial balance, income and balance sheet by cost center`.

## Task 10: Close policy command

**Files:** Create `app/Console/Commands/GlClosePeriods.php`; modify `routes/console.php` (daily 02:20, `withoutOverlapping`). Test `tests/Feature/Gl/GlClosePeriodsTest.php`.

**Interfaces:** `php artisan promax:gl-close-periods {--dry-run}` — no-op unless `gl_close_policy=auto` and `gl_start_date` set; closes months `>= start month` whose last day + `gl_close_after_days` (default 10) < today; prints the keys; returns success.

- [ ] Tests: `test_manual_policy_closes_nothing`, `test_auto_policy_closes_due_months_but_never_before_start`, `test_dry_run_writes_nothing`. Red → green → commit `feat(gl): auto close policy command`.

## Task 11: The wizard screen (steps 1–6 and 8; 7 and 9 as extension points)

**Files:**
- Create: `app/Services/Gl/GoLive/Step.php` (interface), `app/Services/Gl/GoLive/StepState.php`, `app/Services/Gl/GoLive/GoLiveWizard.php`, `app/Services/Gl/GoLive/Steps/{StartDateStep,CoaStep,CostCentersStep,SafesStep,MappingStep,OpeningStep,CostingPlaceholderStep,PreviewStep,RunPlaceholderStep}.php`, `app/Http/Controllers/Gl/GoLiveController.php`, `resources/views/gl/golive/index.blade.php` (stepper rail + current step), `resources/views/gl/golive/steps/{start,coa,cost_centers,safes,mapping,opening,placeholder,preview}.blade.php`, `lang/{ar,en}/golive.php`
- Modify: `routes/web.php`, `app/Support/Access.php` (NAV first item in `nav.group_gl`: `['gl.golive','🚀','nav.gl_golive','gl.golive*',null]`; `act.gl.admin` += every POST route below), `lang/{ar,en}/nav.php`
- Test: `tests/Feature/Gl/GoLiveWizardTest.php`

**Interfaces:**
- `interface Step { public function number(): int; public function key(): string; public function titleKey(): string; public function state(): StepState; public function view(): string; public function data(): array; }`
- `final class StepState { public function __construct(public string $level /* red|yellow|green|todo */, public array $messages = [], public bool $done = false) {} public function blocks(): bool { return $this->level === 'red'; } }`
- `GoLiveWizard::steps(): array<int, Step>` (1..9 ordered), `GoLiveWizard::register(Step $step): void` (replaces the step with the same `number()` — **Lane 9 registers 7 and 9 from its service provider**), `GoLiveWizard::step(int $n): Step`, `GoLiveWizard::current(): int` (Setting `gl_golive_current`, default 1), `GoLiveWizard::confirm(int $n, User $by): void` (refuses when `state()->blocks()`; writes `gl_golive_steps.status='done'`, `done_at`, `done_by`; advances current), `GoLiveWizard::reopen(int $n)`.
- Routes (prefix `gl/golive`, name `gl.golive.`): `GET /` → `index` (redirect to current step), `GET /{n}` → `show` (`whereNumber`), `POST /{n}/confirm` → `confirm`, `POST /start` → `saveStart` (start date, close policy, after-days, optional "close months up to"), `POST /cost-centers` → `addCenter` (kind line|branch, parent, code, name, name_en), `POST /cost-centers/seed` → `seedCenters` (`FoundationSeed::run(dryRun)`; dry-run shown first), `POST /safes/{safe}` → `linkSafe` (gl_account_id; legacy safes also `GlMapping::assignKey('cash_main'|'bank')`), `POST /opening/lines` (add), `POST /opening/lines/{line}/delete`, `POST /opening/client` (client_id, amount), `POST /opening/supplier` (supplier_id, amount), `POST /opening/post`, `POST /preview` → `RebuildDiff::preview`, `POST /preview/{run}/approve`.
- Step rules:
  1. Start: red when no start date; yellow when policy not chosen; saving uses the same purge as `SettingsController::saveGeneral` (extract to `Ledger::purgeBefore(string $date): int`). Never closes a month before the start month.
  2. COA: red = `CoaChecks` red or not adopted; yellow = unaccepted yellow; links to `gl.coa`.
  3. Cost centers: red when no `CO` or no `MAADI`; yellow when the factory has no line; tree list.
  4. Safes: red when an active safe has no `gl_account_id` or the `rep_cash` key is not ok; list of safes and reps count.
  5. Mapping: red = `GlMapping::problems()`; embeds a summary and links to `gl.mapping`.
  6. Opening: red when start date missing; yellow when `difference != 0`; manual lines table, client/supplier by-name forms (search box, max 60 results), auto lines by source, post button.
  7. Placeholder: "Costing settings (Lane 9)", level `todo`, never blocks.
  8. Preview: red when the last preview is blocked/failed or a check failed; shows summary, per-month × source table (sticky header, CSV `?export=1`), checks table, approve button.
  9. Placeholder: "Run (Lane 9)", disabled until step 8 approved.
- Persisted & resumable: `gl_golive_steps` + `gl_golive_current`; reopening a done step whose state later turns red shows red again (state is live, `done` is history).

- [ ] **Step 1:** failing tests:
  - `test_wizard_opens_on_the_current_step_and_lists_nine_steps`
  - `test_step_with_red_cannot_be_confirmed` (step 1 without start date → confirm → error, status todo)
  - `test_confirm_persists_and_resumes` (confirm step 1 → `gl_golive_current`=2; new request lands on 2)
  - `test_lane9_can_replace_a_placeholder_step` (register a fake Step number 7 → page shows its title)
  - `test_wizard_write_routes_are_admin_only` (accountant GET 200, POST 403)
  - `test_opening_step_posts_the_entry_and_shows_the_difference`
  - `test_preview_step_runs_and_approves`
  - `RoleAccessTest`, `AccessTest` green.
- [ ] **Step 2–4:** red → implement → green; `php artisan promax:i18n-check` clean.
- [ ] **Step 5:** commit `feat(gl): go-live wizard (steps 1-6 and 8, 7/9 extension points)`.

## Task 12: Verification, QA, report

- [ ] Full `php artisan test`, `php artisan promax:i18n-check`, `python C:\xampp\htdocs\ProMax\promax-skills\promax-feature-dev\scripts\static_check.py` (from the worktree).
- [ ] QA (`promax_qa`, `--env=qa`, port 8010): verify `shopify_admin_token`, `itrack_token`, `itrack_password_md5` are NULL/blank first; `php artisan migrate --env=qa`; serve `php artisan serve --env=qa --port=8010`; log in with an existing QA admin (no persistent users created); open `/gl/golive`, each step, `/gl/coa`, `/gl/mapping`, reports with a cost center.
- [ ] UAT cases: `C:\xampp\htdocs\ProMax\HANDOVER_2026-10\uat\_src\uat_cases_close3.py` (same format as `uat_cases_close2.py`).
- [ ] Report `C:\xampp\htdocs\ProMax\LANE_REPORTS_closeout\LANE_3.md` (first line `STATUS: …`) and one line in `C:\xampp\htdocs\ProMax\HANDOFF_LIVE.md`.

## Review Focus (this lane)

1. Back-dated document into a closed month from the web → rejected with the Arabic reason naming the month; the same document from a late mobile sync → accepted and `needs_review` (Task 6 tests 1–2).
2. Rebuild run twice with no data change → second diff 0/0/0, manual entries and overrides survive (Task 8 test 1).

## Interfaces produced for Lane 9

- `GoLiveWizard::register(Step $step)` with `Step`/`StepState` (steps 7 and 9).
- `RebuildDiff::preview/approve/execute(..., ?callable $progress)` and `RebuildDiff::addCheck()`.
- `Ledger::rebuild(..., bool $skipClosed = false, ?callable $probe = null)`.
- `OpeningBuilder::register(OpeningProvider)`; `FixedAssetRegister` (Lane 4) and `AdvancesRegister` (Lane 7) container bindings.
- `PeriodGuard::watch()/assertOpen()/mode()` for every new document type.
- `GlMapping::REQUIRED_KEYS` (Lane 9 adds its COGS/internal-transfer keys there and to `GlSeeder`).
