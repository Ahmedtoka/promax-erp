# PROMAX Close-out: Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the remaining PROMAX modules from the approved design: handover tracker, GL go-live wizard, safes/banks, expenses, purchasing, manufacturing, HR/payroll, costing and the 5-level P&L. Ship them module by module to the receiving company.

**Architecture:** Laravel 12 ERP plus the Flutter app.
- A small **foundation** lands first: cost centers, `products.kind` and safes. Every later lane builds on its interfaces.
- Each subsystem is one **lane**: a separate chat, worktree and branch, with its own `promax_test_N`. Each lane writes its own detailed plan from this master (writing-plans skill), then executes it with subagent-driven-development: a builder subagent per task, a reviewer subagent per task, and a tester pass per lane.
- Nightly integration merges lanes in dependency order.

**Tech Stack:** PHP 8 / Laravel 12, MariaDB 10.4 (XAMPP), Blade, PHPUnit feature tests, Flutter (Dart) app, Playwright for training-pack captures.

**Spec:** `docs/superpowers/specs/2026-10-19-promax-closeout-design.md` (approved by the owner 2026-10-19). Read it with this plan.

## Global Constraints

- Doctrine in `C:\xampp\htdocs\ProMax\promax-skills\promax-system\SKILL.md` is binding. Load `promax-system` + `promax-feature-dev` before coding, and `promax-flow-guard` before saying done.
- Money writes go through `DB::transaction`. GL writes only through `App\Services\Gl\Ledger`. Prices only through `App\Services\Pricing`. Scope goes first through `App\Support\Scope`. Buttons use `Access::action`, and new screens are registered in `Access::SCREENS` and `Access::NAV`.
- Every migration is guarded (`hasTable` / `hasColumn`). Time columns are `dateTime()`, never `timestamp()`. Timezone is Africa/Cairo.
- Every UI string goes in `lang/ar` and `lang/en` with the same key. `php artisan promax:i18n-check` must be clean.
- Every table screen has a `DateRange` from/to on its business date and a server export (`?export=1`).
- Mobile money actions accept an `idem_key` (pattern: `App\Support\Idempotency`, from lane 1 of 17/10).
- DB `promax` = live data, read-only. Tests use `promax_test_N` (set in the worktree's `phpunit.xml`, then `git update-index --skip-worktree phpunit.xml`). Browser checks use `promax_qa` (port 8010) or `promax_demo` (port 8031). Never touch port 8020. Never start or stop MySQL from a shell.
- The repo is public. No client data, passwords or lane reports in commits. Lane reports go to `C:\xampp\htdocs\ProMax\LANE_REPORTS_closeout\LANE_<n>.md`, first line `STATUS: DONE|PARTIAL — …`.
- Additive only. The one approved behaviour change is closed-period rejection (spec §3.1).
- One legal company, EGP only. Currency columns exist but are fixed to `EGP`.
- Keep `C:\xampp\htdocs\ProMax\HANDOFF_LIVE.md` updated at each lane milestone.

## Review Focus

1. **Back-dated document into a closed month from the web:** rejected with the Arabic reason naming the month. The same document from a late mobile sync is accepted and gets `needs_review`. Owner: Lane 3.
2. **Rebuild run twice with no data change:** the second diff report is empty (0 created / 0 deleted / 0 changed), and manual entries and overrides survive. Owner: Lane 3.
3. **Production order closed with no recorded hours:** costed by kg at the default rate and flagged. It is never left uncosted or divided by zero. Owner: Lane 9.
4. **A raw-material or packaging product never appears where finished goods are sold:** catalog, price lists, van load, online links, invoices. Owner: Lane 2 (`Product::sellable()` scope applied everywhere products are listed for sale).
5. **Handover reviewer role:** can mark screens but gets 403 on every other ERP screen, and cannot edit test/live/deadline. Owner: Lane 1.

---

## Lanes, order and interfaces

| Wave | Lane | Branch | Depends on | Detailed plan (written by the lane) |
|---|---|---|---|---|
| A | 1 Handover tracker | `close-1-handover` | none | **in this file** (below) |
| A | 2 Foundation: cost centers, product kinds, safes | `close-2-foundation` | none | `plans/2026-10-19-closeout-2-foundation.md` |
| B | 3 GL go-live wizard + COA checks + mapping + opening balances + rebuild diff + closed-period rejection + reports | `close-3-gl-wizard` | 2 | `...-3-gl-wizard.md` |
| B | 4 Safes & banks ops + expenses with approval + fixed assets/depreciation | `close-4-cash-expenses` | 2 | `...-4-cash-expenses.md` |
| B | 5 Purchasing: requests, quotes, supplier contracts, receipt QC, raw-material store | `close-5-purchasing` | 2 | `...-5-purchasing.md` |
| B | 6 Manufacturing core: specs, BOM versions, production lines, production orders, issue, QC release, FG receipt, lot genealogy | `close-6-manufacturing` | 2 (5 for raw-material stock; stub until merged) | `...-6-manufacturing.md` |
| B | 7 HR: employees, attendance for all + dashboard punch, leave, payroll with insurance/tax settings | `close-7-hr` | 2 | `...-7-hr.md` |
| B | 8 Flutter: attendance for all staff, leave requests/approvals, production start/finish | `close-8-app` | 6, 7 APIs (contract below) | `...-8-app.md` |
| C | 9 Costing + internal transfer + COGS (both methods) + overhead allocation + 5-level P&L + wizard steps 7–9 run | `close-9-costing` | 3, 4, 6, 7 | `...-9-costing.md` |
| C | 10 Training pack update (HANDOVER_2026-10): new screens' content, shots, videos, UAT cases; seed handover modules | `close-10-training` | all | `...-10-training.md` |

Merge order every night: 2, 1, 3, 4, 5, 6, 7, 9, (8 in the app repo), 10.

### Interfaces fixed now (Lane 2 produces them; everyone else consumes them, exactly as named)

- **Table `cost_centers`:** `id`, `code` (unique), `name`, `name_en`, `parent_id` (nullable self-FK), `kind` varchar(20) ∈ `company|factory|line|branch`, `branch_id` (nullable), `warehouse_id` (nullable), `active` bool, `created_at`/`updated_at`.
- **Model `App\Models\CostCenter`:**
  - `public const KINDS = ['company','factory','line','branch']`
  - `public static function company(): self` (the single root)
  - `public static function forWarehouse(?Warehouse $w): ?self` (the warehouse's own center, else its branch's, else null)
  - `public static function maadi(): self` (the default sales center)
  - `public function lines(): HasMany` (children of kind `line`)
- **Column `gl_lines.cost_center_id`:** nullable FK. `Rules::linesFor()` line arrays may carry `'cost_center_id' => int|null`, and `Ledger::post()` persists it.
- **`App\Services\Gl\CostCenterResolver::for(Model $source): ?int`:** order is the source warehouse → its center, then the employee's center (HR), then `CostCenter::maadi()->id` for sales and collections. Lane 3 calls it from `Rules`.
- **Column `products.kind`:** varchar(20), default `'finished'`, ∈ `Product::KINDS = ['finished','raw','packaging']`.
  - `Product::scopeSellable($q)` → `where('kind','finished')`.
  - Lane 2 applies `sellable()` to every product listing used for selling (enumerate them with grep in its plan).
- **Table `safes`:** `id`, `code` (unique), `name`, `name_en`, `kind` ∈ `cash|bank`, `branch_id`, `cost_center_id`, `currency` char(3) default `'EGP'`, `custodian_user_id` (nullable), `gl_account_id` (nullable), `bank_name`, `account_no`, `active`.
  - Seeded idempotently: `MAADI-MAIN` (cash, maps the existing `Expense::PAID_FROM` value `cash_main`) and `BANK-1` (bank, maps `bank`). `rep_cash` stays the rep custody accounts.
  - **Model `App\Models\Safe`:** `public static function forLegacy(string $paidFrom): ?self`, `public function balance(?Carbon $asOf = null): float`.
- **Seed:** one company center `CO`, factory `FAC` (linked to every warehouse of type `factory` that is the production site), and branch `MAADI` linked to the Maadi branch/warehouse. Command `php artisan promax:cost-centers-seed` (idempotent, `--dry-run`).

### API contract for Lane 8 (lanes 6 and 7 implement it)

- `POST /api/attendance/punch` exists. Lane 7 extends it to every role with an employee record, adds `source` (`app|dashboard|supervisor`), and adds a `POST /attendance/punch` web route for the dashboard.
- `GET /api/leave/balances`, `POST /api/leave/requests {type, from, to, note, idem_key}`, `GET /api/leave/requests?mine=1`, `GET /api/leave/approvals`, `POST /api/leave/requests/{id}/decide {decision: approved|rejected, note}`.
- `GET /api/production/orders?line=&status=`, `POST /api/production/orders/{id}/start {idem_key}`, `POST /api/production/orders/{id}/finish {idem_key, output_qty}`.
- Every response follows the existing `ApiContractTest` style. Lanes 6 and 7 add their endpoints to `ApiContractTest`.

### What each detailed lane plan must contain (lanes 2–10)

- The tasks in the TDD step style of this file, with exact file paths, signatures, test names and assertions.
- An Interfaces block per task.
- Its slice of Review Focus.
- Its UAT additions as entries in `HANDOVER_2026-10\uat\_src\` (Lane 10 merges them).

---

# Lane 1: Handover tracker (detailed)

**Decisions** (spec §3.8, §4.1):
- A new role `handover_reviewer` sees only the handover screens.
- Modules are seeded from the `Access::NAV` groups, and screens from the NAV items, editable afterwards.
- The owner (admin) sets test status, live status, deadline and notes.
- A mark can be removed, with a log.
- A screen flagged as changed after its mark shows "اتعدلت بعد الاستلام" without clearing the mark.

**Files:**
- Create: `database/migrations/2026_10_19_000100_handover_tracker.php`
- Create: `app/Models/HandoverModule.php`, `app/Models/HandoverScreen.php`, `app/Models/HandoverMark.php`
- Create: `app/Services/Handover.php` (progress math + seeding)
- Create: `app/Console/Commands/HandoverSeed.php`, `app/Console/Commands/HandoverTouch.php`
- Create: `app/Http/Controllers/HandoverController.php`
- Create: `resources/views/handover/index.blade.php`, `resources/views/handover/module.blade.php`
- Modify: `routes/web.php` (new group), `app/Support/Access.php` (SCREENS, ACTIONS, NAV), `app/Models/User.php` (`ROLES` += `handover_reviewer`), `lang/ar/*.php` + `lang/en/*.php` (new file `handover.php` in both)
- Test: `tests/Feature/HandoverTrackerTest.php`

### Task 1.1: Schema, models and progress math

**Interfaces:**
- **Tables:**
  - `handover_modules`: `id`, `key` (unique), `name_ar`, `name_en`, `sort` int, `nav_group_key` (nullable), `test_status` ∈ `pending|in_progress|done` default `pending`, `live_status` ∈ `no|partial|yes` default `no`, `deadline` date nullable, `notes` text nullable.
  - `handover_screens`: `id`, `module_id`, `route` (unique per module), `title_ar`, `title_en`, `sort`, `touched_at` dateTime nullable.
  - `handover_marks`: `id`, `screen_id`, `user_id`, `marked_at` dateTime, `unmarked_at` dateTime nullable, `unmarked_by` nullable, `note` nullable.
- **Model methods:**
  - `HandoverScreen::activeMark(): ?HandoverMark` (latest mark with `unmarked_at` null)
  - `HandoverScreen::isStale(): bool` (`touched_at` > active mark's `marked_at`)
  - `App\Services\Handover::moduleProgress(HandoverModule $m): array{marked:int,total:int,pct:int}`
  - `App\Services\Handover::overall(): array{marked:int,total:int,pct:int}`
  - `pct` = `intdiv(marked*100, total)`, and `0` when `total` is `0`.

- [ ] **Step 1: Write failing tests** in `tests/Feature/HandoverTrackerTest.php`:
  - `test_progress_is_half_when_one_of_two_screens_is_marked`: module with 2 screens, 1 active mark → `['marked'=>1,'total'=>2,'pct'=>50]`
  - `test_unmarked_screen_no_longer_counts_but_keeps_its_log`: mark then unmark → pct 0, the `handover_marks` row still exists with `unmarked_at` set
  - `test_empty_module_is_zero_not_an_error`: 0 screens → pct 0
  - `test_screen_touched_after_mark_is_stale_but_still_marked`: mark, then `touched_at` = now+1min → `isStale()` true, pct unchanged
- [ ] **Step 2:** Run `php artisan test --filter=HandoverTrackerTest`. Expected: FAIL (tables missing).
- [ ] **Step 3:** Implement the guarded migration, the three models and `App\Services\Handover`.
- [ ] **Step 4:** Run `php artisan test --filter=HandoverTrackerTest`. Expected: 4 passed.
- [ ] **Step 5: Commit** `feat(handover): schema, models and progress math`.

### Task 1.2: Seeding from the sidebar and the "touch" command

**Interfaces:**
- `App\Services\Handover::seedFromNav(bool $dryRun = false): array{modules_added:int,screens_added:int}`. This is idempotent: it only adds missing rows, never deletes or renames. `key` = the NAV group key, the screen `route` = the NAV item route name, and titles come from `__($label, [], 'ar'|'en')`.
- `php artisan promax:handover-seed {--dry-run}`
- `php artisan promax:handover-touch {route*}`: sets `touched_at = now()` on matching screens. It prints the count, and returns failure if 0 matched.

- [ ] **Step 1: Failing tests:**
  - `test_seed_creates_one_module_per_nav_group_and_one_screen_per_item`: counts equal `count(Access::NAV)` and the sum of items
  - `test_seed_twice_adds_nothing`: the second run returns `['modules_added'=>0,'screens_added'=>0]`
  - `test_seed_keeps_owner_edits`: rename a module, re-seed → the name is unchanged
  - `test_touch_marks_screen_stale`
- [ ] **Step 2:** Run the filter. Expected: FAIL.
- [ ] **Step 3:** Implement `seedFromNav` and the two commands.
- [ ] **Step 4:** Run the filter. Expected: PASS.
- [ ] **Step 5: Commit** `feat(handover): seed from sidebar, touch command`.

### Task 1.3: Role, routes, screens and permissions

**Interfaces:**
- **Role:** `User::ROLES` gets `'handover_reviewer'`, with label keys in `lang/*/enums.php`.
- **Routes:** in the group `handover.` with middleware `role:admin,handover_reviewer`:
  - `GET /handover` → `index`
  - `GET /handover/{module}` → `show`
  - `POST /handover/screens/{screen}/mark` (note optional)
  - `POST /handover/screens/{screen}/unmark`
  - `POST /handover/{module}/mark-all`
  - `POST /handover/{module}/unmark-all`
  - `POST /handover/{module}/meta` (admin only: `role:admin`), with fields `test_status`, `live_status`, `deadline`, `notes`
  - `GET /handover/export` (CSV via `App\Support\Csv::download`: module, screens, marked, pct, test, live, deadline)
- **The two screens:**
  - **`index`:** module cards with the screen count, a progress bar with `pct`, a test badge, a live badge, the deadline (red when past and pct < 100), and the overall pct at the top.
  - **`module`:** the screen list with a checkbox, who marked it and when, the note, and a stale badge ("اتعدلت بعد الاستلام"). The header checkbox = mark-all/unmark-all. Admin sees the meta form.
- **Marks log tab:** a `DateRange` on `marked_at`.
- **Access:**
  - SCREENS: `handover.*` for admin and handover_reviewer.
  - NAV: a new group `nav.group_handover` with one item `handover.index`, visible to both roles.
  - ACTIONS: `act.handover.meta` = admin only.
  - **handover_reviewer gets NO other screen.**

- [ ] **Step 1: Failing tests:**
  - `test_reviewer_can_mark_and_unmark_a_screen` (POST mark → 302, row exists; unmark → `unmarked_at` set)
  - `test_mark_all_marks_every_screen_and_module_is_100`
  - `test_reviewer_cannot_edit_meta` (POST meta as reviewer → 403)
  - `test_reviewer_gets_403_on_other_screens` (GET `/erp`, `/ops/invoices`, `/gl/accounts` → 403)
  - `test_admin_sets_deadline_and_live_status`
  - `test_export_lists_every_module_with_pct`
  - `RoleAccessTest` stays green (it crawls the map against the gate)
- [ ] **Step 2:** Run `php artisan test --filter="HandoverTrackerTest|RoleAccessTest|AccessTest"`. Expected: new tests FAIL.
- [ ] **Step 3:** Implement the routes, controller (with `Access::allows` on the meta form), views (`ui-promax` style) and lang keys in both files.
- [ ] **Step 4:** Run the same filter plus `php artisan promax:i18n-check`. Expected: all pass, i18n clean.
- [ ] **Step 5:** On `promax_qa`: `php artisan migrate --env=qa`, then `php artisan promax:handover-seed --env=qa`, then open `/handover` as admin in the browser. Expected: one module per `Access::NAV` group (18 groups today + the new handover group), screen counts that match the sidebar, 0%.
- [ ] **Step 6: Commit** `feat(handover): screens, role, export`.

**Lane 1 done when:** all tests are green, i18n is clean, the QA check is done, the lane report is written, and `HANDOFF_LIVE.md` is updated.
