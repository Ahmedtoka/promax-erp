# Lane 6: Manufacturing Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Product specs, BOM versions, production lines, production orders (plan → issue → finish → close → QC release), lot genealogy with a recall screen, a variance report, and the production API for the app.

**Architecture:** Thin controllers over static services in `app/Services/Mfg/`. Every stock or money movement runs inside `DB::transaction` and throws `App\Exceptions\Rejected` with a translated message; controllers turn it into a flash error (web) or `422 {message}` (API). Raw stock is consumed only through `App\Services\RawStock::issue()` (Lane 5's interface; a marked stub lives in this branch). The finished batch is an ordinary `batches` row in the factory warehouse, blocked until QC releases it.

**Tech Stack:** Laravel 12, PHP 8, MariaDB 10.4, Blade (`layouts/system`), PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-10-19-promax-closeout-design.md` §3.5, §4, §4.1; master plan `docs/superpowers/plans/2026-10-19-closeout-master.md` (Global Constraints, Interfaces, API contract for Lane 8).

## Global Constraints

- Doctrine in `promax-skills/promax-system/SKILL.md` is binding; `promax-feature-dev` checklist applies to every task.
- Stock writes inside `DB::transaction`, rows read with `lockForUpdate()`. Errors = `throw new \App\Exceptions\Rejected(__('mfg.x'))`. Never catch `RuntimeException` around a transaction.
- No GL writes in this lane (costing entries are Lane 9). No price computed here (no `Pricing` call needed).
- Every migration guarded with `hasTable`/`hasColumn`; time columns `dateTime()`, never `timestamp()`; money `decimal(14,2)`, quantities `decimal(14,3)`, BOM quantities `decimal(14,4)`, percents `decimal(7,2)`.
- Every UI string in `lang/ar/mfg.php` **and** `lang/en/mfg.php` (same keys; English values with an apostrophe use double quotes). `php artisan promax:i18n-check` clean.
- Every table screen: `App\Support\DateRange` from/to on its business column + server export `?export=1` via `App\Support\Csv::download` from the same filtered query before pagination.
- New screens in `Access::SCREENS` (matching the `role:` middleware exactly — `RoleAccessTest::test_the_access_map_agrees_with_the_role_middleware`), `Access::NAV` group `nav.group_mfg`, buttons guarded with `Access::action($user, 'act.mfg.*')`.
- Mobile actions accept `idem_key` (`App\Support\Idempotency`).
- Tests on `promax_test_6` only (`phpunit.xml`, skip-worktree). Never DB `promax`, never port 8020, never start/stop MySQL. No push/merge.
- Raw/packaging products never appear on a sale surface (Lane 2 `Product::sellable()`); BOM lines accept only `kind ∈ {raw, packaging}`, BOM/order heads only `kind = finished`.

## Decisions (logged in the lane report)

- **D1 Roles.** Three new web roles from spec §4: `production_manager` (مدير الإنتاج / مشرف الخط), `quality` (الجودة), `rnd` (البحث والتطوير). Approvals (spec, BOM) are admin only ("الموافقات النهارده للأدمن").
- **D2 Units.** Batches hold integer quantities today. Finished output (`planned_qty`, `output_qty`) is integer pieces. Material quantities are stored `decimal(14,3)`; the RawStock stub rejects a non-whole quantity with `mfg.raw_whole_units` until Lane 5 lands its own unit handling.
- **D3 `RawStock::issue` return.** Each line is `['batch_id'=>int,'qty'=>float,'cost'=>float]` where `cost` is the **unit** cost of that batch. Verify at merge with Lane 5.
- **D4 Returns to store** go back to the exact raw batches this order drew from, newest issue first (`Mfg\Issues::returnToStore`), never above what the order holds net per batch. Lane 5 has no return API in the contract, so this lives in Lane 6.
- **D5 Only BOM materials** can be issued to an order (genealogy stays clean). Over-standard issue is kind `extra`.
- **D6 Close requires** status `finished`, at least one net-issued material, `output_qty ≥ 1`. Shelf life = approved spec `shelf_life_days`, else `Product::expiryFrom()` (flag `expiry_source = 'product'`).
- **D7 Rejected batch** stays in stock as blocked (hold); disposal goes through the existing count/adjust flows. Released = `blocked = false` → visible to distribution (FEFO, `availableFor`).
- **D8 Shortage → purchase request.** `Mfg\PurchaseLink::raise()` calls Lane 5's `App\Models\PurchaseRequest::raiseFor(array $lines, Model $for, User $by)` when that method exists; otherwise it records `shortage_requested_at` on the order, notifies admins, and returns null (stub until Lane 5 merges).
- **D9 Waste.** `std_qty` = base × (1 + `boms.waste_pct`/100). Line `waste_pct` = (actual − base)/actual × 100 (base = BOM qty for the actual output without allowance). Order `waste_pct` = same on values; `yield_pct` = output/planned × 100.
- **D10 Warehouses.** Order carries `raw_warehouse_id` (issue source) and `warehouse_id` (finished goods). Defaults from `Setting` keys `mfg_raw_warehouse_id` / `mfg_fg_warehouse_id`, else the first active `type = factory` warehouse.

## Review Focus

1. **Issue more than raw stock holds, or two issues racing for the last lot:** rejected with the batch shortfall, nothing moves (`lockForUpdate` in RawStock). Test in Task 5.
2. **Return more than the order holds of a material:** rejected, raw batches unchanged. Test in Task 5.
3. **Close an order twice / start a finished order / finish from the app twice with the same idem_key:** second call is refused or returns the same order, never a second batch. Tests in Tasks 6 and 8.
4. **A quarantined finished batch is never pickable/sellable before release**, and is after release. Test in Task 6.
5. **Editing an approved BOM or spec** is impossible; a new version is created and production keeps using the approved one until the new one is approved. Tests in Tasks 2 and 3.

---

## File Structure

- `database/migrations/2026_10_19_600100_manufacturing_core.php` — all lane tables.
- `app/Models/ProductSpec.php`, `Bom.php`, `BomLine.php`, `ProductionOrder.php`, `ProductionOrderLine.php`, `ProductionIssue.php`.
- `app/Services/RawStock.php` — **STUB (Lane 5 owns the real one)**.
- `app/Services/Mfg/Specs.php`, `Boms.php`, `Orders.php`, `Requirement.php`, `PurchaseLink.php`, `Issues.php`, `Closing.php`, `Qc.php`, `Genealogy.php`, `Variance.php`, `LineHours.php`.
- `app/Http/Controllers/Mfg/SpecController.php`, `BomController.php`, `LineController.php`, `OrderController.php`, `QcController.php`, `TraceController.php`, `VarianceController.php`; `app/Http/Controllers/Api/ProductionApiController.php`.
- `resources/views/mfg/*.blade.php`.
- `lang/{ar,en}/mfg.php`, `lang/{ar,en}/nav.php`, `lang/{ar,en}/enums.php`, `lang/{ar,en}/perm.php`.
- `routes/web.php` (group `mfg.`), `routes/api.php` (prefix `production`).
- `app/Support/Access.php`, `app/Models/User.php`, `app/Support/Idempotency.php`.
- Tests: `tests/Feature/Mfg/*Test.php`, `tests/Feature/ApiContractTest.php`, `tests/Feature/RoleAccessTest.php`.

---

### Task 1: Schema, models, roles, RawStock stub, navigation skeleton

**Files:** migration, the six models, `app/Services/RawStock.php`, `app/Models/User.php`, `app/Support/Access.php` (roles only + NAV group + GROUP_ICONS), `lang/{ar,en}/mfg.php` (start), `lang/{ar,en}/enums.php`, `lang/{ar,en}/nav.php`, `tests/Feature/RoleAccessTest.php` (`webRoles()`), Test: `tests/Feature/Mfg/MfgSchemaTest.php`, `tests/Feature/Mfg/RawStockStubTest.php`.

**Interfaces (Produces):**
- Tables:
  - `product_specs`: id, product_id FK, version uint, status varchar(20) ∈ `draft|under_review|approved|archived` default draft, attachment_path, attachment_name (nullable), net_weight_g decimal(10,3) null, shelf_life_days uint null, nutrition json null (keys `energy_kcal, protein_g, carbs_g, sugar_g, fat_g, sat_fat_g, fiber_g, sodium_mg`, per 100 g), qc_limits json null (list of `{param, min, max, unit}`), notes text null, created_by, submitted_at, approved_by, approved_at (dateTime null), timestamps; unique(product_id, version).
  - `boms`: id, product_id FK, version uint, status ∈ `draft|approved|archived`, waste_pct decimal(7,2) default 0, notes, created_by, approved_by, approved_at, timestamps; unique(product_id, version).
  - `bom_lines`: id, bom_id FK cascade, product_id FK, qty decimal(14,4), basis varchar(10) ∈ `per_unit|per_batch`, sort uint, timestamps.
  - `production_orders`: id, number unique, product_id, bom_id, line_id (FK cost_centers), warehouse_id (FG), raw_warehouse_id, batch_no varchar(60), planned_qty uint, output_qty uint null, status ∈ `planned|in_progress|finished|closed|cancelled` default planned, planned_on date, started_at, finished_at, closed_at (dateTime null), started_by, finished_by, closed_by, created_by (user FKs null), produced_on date null, expires_on date null, expiry_source varchar(10) null, fg_batch_id FK batches null, material_cost decimal(14,2) default 0, std_material_cost decimal(14,2) default 0, overhead_cost decimal(14,2) default 0, total_cost decimal(14,2) default 0, unit_cost decimal(14,4) default 0, hours decimal(8,2) null, overhead_method varchar(20) null, overhead_flag varchar(30) null, waste_pct decimal(7,2) null, yield_pct decimal(7,2) null, qc_status varchar(20) null ∈ `quarantine|released|rejected`, qc_note text, qc_results json, qc_by, qc_at, shortage_requested_at dateTime null, purchase_request_id uint null (no FK), notes, timestamps; index(line_id, status), index(product_id, batch_no).
  - `production_order_lines`: id, production_order_id FK cascade, product_id, basis, bom_qty decimal(14,4), std_qty decimal(14,3) (for planned qty, with waste), std_qty_actual decimal(14,3) null, base_qty_actual decimal(14,3) null, actual_qty decimal(14,3) null, actual_cost decimal(14,2) null, std_cost decimal(14,2) null, variance_qty decimal(14,3) null, variance_value decimal(14,2) null, waste_pct decimal(7,2) null, timestamps.
  - `production_issues`: id, production_order_id FK cascade, product_id, batch_id FK batches, kind varchar(10) ∈ `issue|extra|return`, qty decimal(14,3) (positive; returns are positive with kind return), unit_cost decimal(14,4), value decimal(14,2), user_id, issued_at dateTime, note, timestamps; index(batch_id).
- Models: `ProductSpec::STATUSES`, `Bom::STATUSES`, `BomLine::BASES = ['per_unit','per_batch']`, `ProductionOrder::STATUSES`, `ProductionOrder::QC = ['quarantine','released','rejected']`, `ProductionIssue::KINDS`. Relations: `Product->specs()`, `Product->boms()`; `Bom->lines()` ordered by sort; `ProductionOrder->product(), bom(), line() (CostCenter), warehouse(), rawWarehouse(), lines(), issues(), fgBatch()`; casts for json/dates/decimals. `ProductionOrder::nextNumber(): string` (`MO-1001`… per promax-feature-dev numbering). `ProductionOrder::isOpen(): bool` (planned|in_progress|finished). `Bom::approvedFor(Product $p): ?Bom`; `ProductSpec::approvedFor(Product $p): ?ProductSpec`. `BomLine::baseFor(int $outputQty): float` (per_unit → qty × output; per_batch → qty). `Bom::stdFor(BomLine $l, int $outputQty): float` = round(base × (1 + waste_pct/100), 3).
- `App\Services\RawStock::issue(Warehouse $w, Product $p, float $qty, Model $for): array` — **file header comment: `STUB — Lane 6 placeholder; Lane 5 owns RawStock. Expect a merge conflict: keep Lane 5's body, keep this signature.`** FEFO over `batches` of that product in that warehouse (`blocked = false`, `qty_remaining > 0`, `expires_on >= today`, order `expires_on, id`, `lockForUpdate`); takes from each batch's `batch_locations` (order id) and the batch (`qty_remaining −`, `qty_issued +`); then `StockCounting::resync($p->id, $w->id)`. Throws `Rejected(__('mfg.raw_short', ['product'=>…, 'available'=>…]))` before moving anything when the total is short; `Rejected(__('mfg.raw_whole_units'))` when `$qty` is not whole; `Rejected(__('mfg.qty_positive'))` when ≤ 0. Must be called inside a transaction (does not open one).
- Roles: `User::ROLES += production_manager, quality, rnd` (Arabic labels مدير الإنتاج / الجودة / البحث والتطوير), `lang/*/enums.php role.*`, `Access::WEB_ROLES`, `Access::HOME` (`mfg.orders`, `mfg.qc`, `mfg.specs`), `Access::SCREENS` entries for the three roles (filled per task; Task 1 puts `'notifications'` plus the screens of this task — none — so give each role its HOME screen prefix now and keep RoleAccessTest green by making HOME routes exist as placeholder `GET` routes returning the list views created in later tasks). **Simpler rule for Task 1:** register in `routes/web.php` the group `Route::prefix('mfg')->name('mfg.')` with the three index routes `mfg.orders`, `mfg.qc`, `mfg.specs` pointing at controllers that render a minimal list view; later tasks extend them.
- NAV: group `nav.group_mfg` (icon `🧪`), items added by later tasks; Task 1 adds `['mfg.orders','📋','nav.mfg_orders','mfg.orders*',null]`, `['mfg.qc','🔬','nav.mfg_qc','mfg.qc*',null]`, `['mfg.specs','📄','nav.mfg_specs','mfg.specs*',null]`.

- [ ] **Step 1: Failing tests** `MfgSchemaTest`: `test_tables_exist_after_migrate` (each table + `production_orders.fg_batch_id`); `test_bom_std_qty_per_unit_and_per_batch_with_waste` (waste 5 %, line per_unit 0.035 → std for 1000 = 36.750; line per_batch 2 → 2.100); `test_order_numbers_increment` (`MO-1001`, then `MO-1002`). `RawStockStubTest`: `test_issue_is_fefo_across_batches_and_shelves` (raw product, two batches 10 (expires later) and 5 (sooner) each on a shelf; issue 8 → lines `[{sooner, 5}, {later, 3}]`, batches 0/7, shelves sum = batch remaining, `stocks.qty` = 7); `test_short_issue_moves_nothing` (issue 16 → Rejected, both batches unchanged); `test_blocked_and_expired_batches_are_skipped`; `test_fractional_qty_is_refused`. `RoleAccessTest::webRoles()` gets the three roles.
- [ ] **Step 2:** `php artisan test --filter="MfgSchemaTest|RawStockStubTest"` → FAIL.
- [ ] **Step 3:** Implement migration, models, stub, roles, routes, NAV, lang.
- [ ] **Step 4:** `php artisan test --filter="MfgSchemaTest|RawStockStubTest|RoleAccessTest|AccessTest"` → PASS; `php artisan promax:i18n-check` clean.
- [ ] **Step 5:** Commit `feat(mfg): schema, models, roles, RawStock stub`.

### Task 2: Product specifications

**Files:** `app/Services/Mfg/Specs.php`, `app/Http/Controllers/Mfg/SpecController.php`, `resources/views/mfg/specs.blade.php`, `resources/views/mfg/spec.blade.php`, routes, Access, lang. Test: `tests/Feature/Mfg/ProductSpecTest.php`.

**Interfaces:**
- Consumes: `ProductSpec` (Task 1).
- Produces: `Specs::newVersion(Product $p, User $by, ?ProductSpec $copyFrom = null): ProductSpec` (version = max+1, draft, copies fields but not the attachment unless copied path exists); `Specs::submit(ProductSpec $s, User $by): void` (draft → under_review; requires shelf_life_days and net_weight_g); `Specs::approve(ProductSpec $s, User $by): void` (under_review → approved, the previous approved of the product → archived, in one transaction); `Specs::sendBack(ProductSpec $s, User $by, ?string $note): void` (under_review → draft). Wrong-state calls throw Rejected `mfg.spec_bad_state`.
- Routes (`mfg.specs*`): `GET /mfg/specs` (list; DateRange on `updated_at`; filter status, product; export), `GET /mfg/specs/{spec}` show/edit, `POST /mfg/specs` (new version for product_id, optional copy_from), `POST /mfg/specs/{spec}` update (draft only; fields above + `attachment` file pdf/jpg/png/docx ≤ 10 MB stored on `local` disk `mfg/specs/`), `POST /mfg/specs/{spec}/submit`, `POST /mfg/specs/{spec}/approve` and `/send-back` (`role:admin`), `GET /mfg/specs/{spec}/file` (download).
- Middleware: view `role:admin,production_manager,quality,rnd`; write `role:admin,rnd`; approve/send-back `role:admin`. ACTIONS `act.mfg.specs_edit` (rnd), `act.mfg.specs_approve` (admin only `[]`).
- Products offered: `kind = finished` only.

- [ ] **Step 1: Failing tests:** `test_rnd_creates_draft_and_submits`; `test_submit_without_shelf_life_is_refused`; `test_admin_approval_archives_previous_approved` (v1 approved, v2 approved → v1 archived, `approvedFor` = v2); `test_approved_spec_cannot_be_updated` (POST update → error, unchanged); `test_quality_can_view_but_not_edit` (GET 200, POST update 403); `test_rnd_cannot_approve` (403); `test_attachment_upload_and_download` (Storage::fake('local')); `test_specs_list_export_is_csv`; `test_raw_product_cannot_get_a_spec`.
- [ ] **Step 2:** run `--filter=ProductSpecTest` → FAIL.
- [ ] **Step 3:** Implement. View: nutrition grid + QC limits rows (add/remove row in JS via `<template>`), status badge, version list of the product.
- [ ] **Step 4:** `--filter="ProductSpecTest|RoleAccessTest"` PASS, i18n clean.
- [ ] **Step 5:** Commit `feat(mfg): product specs with review and approval`.

### Task 3: BOM versions

**Files:** `app/Services/Mfg/Boms.php`, `app/Http/Controllers/Mfg/BomController.php`, views `mfg/boms.blade.php`, `mfg/bom.blade.php`. Test: `tests/Feature/Mfg/BomTest.php`.

**Interfaces:**
- Produces: `Boms::newVersion(Product $p, User $by, ?Bom $copyFrom = null): Bom`; `Boms::saveLines(Bom $b, array $lines, float $wastePct, ?string $notes): void` (draft only; each line `{product_id, qty > 0, basis}`; product kind raw/packaging else Rejected `mfg.bom_line_not_material`; duplicate product refused); `Boms::approve(Bom $b, User $by): void` (draft with ≥1 line → approved; previous approved → archived). `Bom::approvedFor()` from Task 1 is the only BOM used by orders.
- Routes `mfg.boms*`: `GET /mfg/boms` (list, DateRange on `updated_at`, export), `GET /mfg/boms/{bom}`, `POST /mfg/boms` (new version), `POST /mfg/boms/{bom}/lines`, `POST /mfg/boms/{bom}/approve` (`role:admin`). View `role:admin,production_manager,rnd`; write `role:admin,rnd`. ACTIONS `act.mfg.boms_edit` (rnd), `act.mfg.boms_approve` ([]).
- Line picker lists `kind in (raw, packaging)` active products; head picker `kind = finished`.

- [ ] **Step 1: Failing tests:** `test_draft_bom_saves_lines_with_basis`; `test_finished_product_line_is_refused`; `test_approve_archives_previous_and_orders_use_approved` (`approvedFor` returns v2 after approving v2); `test_approved_bom_lines_cannot_change`; `test_empty_bom_cannot_be_approved`; `test_new_version_copies_lines`; `test_production_manager_views_but_cannot_edit` (403 on lines POST).
- [ ] **Step 2–4:** filter `BomTest|RoleAccessTest` FAIL → implement → PASS, i18n clean.
- [ ] **Step 5:** Commit `feat(mfg): BOM versions with approval`.

### Task 4: Production lines, production orders, requirement, shortage link

**Files:** `app/Services/Mfg/Orders.php`, `Requirement.php`, `PurchaseLink.php`, `LineHours.php`; controllers `LineController`, `OrderController` (index/store/show/start/finish/cancel/shortage, requirements); views `mfg/lines.blade.php`, `mfg/orders.blade.php`, `mfg/order.blade.php`, `mfg/requirements.blade.php`. Test: `tests/Feature/Mfg/ProductionOrderTest.php`, `tests/Feature/Mfg/RequirementTest.php`.

**Interfaces:**
- Lines: `cost_centers` rows `kind = 'line'`, `parent_id` = center `FAC` (create FAC via `firstOrCreate(['code'=>'FAC'], kind factory, parent CO)` if missing). Routes `GET /mfg/lines` (`mfg.lines`), `POST /mfg/lines` (`mfg.lines.store`: code unique, name, name_en), `POST /mfg/lines/{line}` (`mfg.lines.update`: names, active), `POST /mfg/lines/settings` (`mfg.lines.settings`, `role:admin`: `mfg_raw_warehouse_id`, `mfg_fg_warehouse_id`). View/write `role:admin,production_manager`. ACTION `act.mfg.lines_manage` (production_manager). Lines screen shows this month's hours per line (`LineHours`).
- `Orders::create(array $data, User $by): ProductionOrder` — data `product_id, line_id, planned_qty ≥ 1, batch_no, planned_on, warehouse_id?, raw_warehouse_id?, notes?`; product finished with an approved BOM (else `mfg.no_approved_bom`); line is an active `kind='line'` center; batch_no unique for that product across `production_orders` and `batches` (`mfg.batch_no_taken`); snapshots `production_order_lines` from the BOM (`std_qty` = `Bom::stdFor(line, planned_qty)`).
- `Orders::start(ProductionOrder $o, User $by, ?string $idemKey = null): ProductionOrder` — planned → in_progress, `started_at = now()`; idem action `Idempotency::MFG_START = 'mfg_start'`; repeat with the same key returns the order unchanged; start on a non-planned order without a matching key → Rejected `mfg.order_bad_state`.
- `Orders::finish(ProductionOrder $o, User $by, int $outputQty, ?string $idemKey = null): ProductionOrder` — in_progress → finished, `finished_at`, `output_qty`, `hours` = round(minutes/60, 2); `Idempotency::MFG_FINISH = 'mfg_finish'`.
- `Orders::cancel(ProductionOrder $o, User $by): void` — planned or in_progress with no net issue left (else `mfg.cancel_has_issues`).
- `LineHours::forOrder(ProductionOrder $o): ?float`; `LineHours::forMonth(CostCenter $line, \Carbon\CarbonInterface $month): float` (sum of `hours` of orders on that line with `finished_at` in the month).
- `Requirement::forOrder(ProductionOrder $o): Collection` of arrays `{product, std_qty, issued_net, remaining, available, shortage}`; `Requirement::forOpenOrders(): Collection` same keys aggregated per material over planned/in_progress orders (available per order's raw warehouse summed once per warehouse); `Requirement::available(Warehouse $w, Product $p): float` (non-blocked, non-expired `qty_remaining`).
- `PurchaseLink::raise(ProductionOrder $o, User $by): ?int` — D8; lines = shortages of `forOrder`; Rejected `mfg.no_shortage` when none; sets `shortage_requested_at`; notifies admins via `AppNotification::send` with closures.
- Routes: `GET /mfg/orders` (`mfg.orders`; DateRange on `planned_on`; filters status, line, product; export), `POST /mfg/orders` (`mfg.orders.store`), `GET /mfg/orders/{order}` (`mfg.orders.show`), `POST …/start`, `…/finish` (field `output_qty`), `…/cancel`, `…/shortage`; `GET /mfg/requirements` (`mfg.requirements`, export). Middleware: view orders `role:admin,production_manager,quality,warehouse_keeper`; store/start/finish/cancel/shortage `role:admin,production_manager`; requirements `role:admin,production_manager,warehouse_keeper`. ACTION `act.mfg.orders_manage` (production_manager).

- [ ] **Step 1: Failing tests:** `test_order_needs_approved_bom`; `test_order_snapshots_bom_lines_with_waste`; `test_batch_no_must_be_unique_per_product`; `test_line_must_be_a_production_line` (a `branch` center refused); `test_start_then_finish_sets_timestamps_and_hours` (Carbon::setTestNow +90 min → hours 1.50); `test_start_twice_with_same_idem_key_returns_same_order`; `test_finish_planned_order_is_refused`; `test_line_hours_for_month`; `test_lines_screen_creates_line_under_fac`; `test_orders_list_filters_and_exports`. `RequirementTest`: `test_requirement_compares_std_with_raw_stock` (std 36.750 kg, stock 30 → shortage 6.750); `test_shortage_request_stub_marks_order_and_notifies_admin`; `test_no_shortage_is_refused`; `test_open_orders_requirement_aggregates_materials`.
- [ ] **Step 2–4:** filter `ProductionOrderTest|RequirementTest|RoleAccessTest` FAIL → implement → PASS; i18n clean.
- [ ] **Step 5:** Commit `feat(mfg): production lines, orders, requirement and shortage link`.

### Task 5: Issue to production, extra issue, return to store

**Files:** `app/Services/Mfg/Issues.php`, `OrderController` (issue, issueStandard, returnToStore), `mfg/order.blade.php` (issue panel). Test: `tests/Feature/Mfg/IssueTest.php`.

**Interfaces:**
- `Issues::issue(ProductionOrder $o, Product $material, float $qty, User $by, string $kind = 'issue', ?string $note = null): Collection<ProductionIssue>` — order status planned|in_progress|finished (else `mfg.order_bad_state`); material in order lines (`mfg.not_in_bom`); kind ∈ issue|extra; inside `DB::transaction`: lock the order row, call `RawStock::issue($o->rawWarehouse, $material, $qty, $o)`, write one `production_issues` row per returned line (unit_cost = line cost, value = round(qty × unit_cost, 2)).
- `Issues::issueStandard(ProductionOrder $o, User $by): int` — for each order line issues `std_qty − issued_net` when > 0 (kind issue), all in one transaction; returns number of lines issued; a shortage on any line rolls everything back.
- `Issues::returnToStore(ProductionOrder $o, Product $material, float $qty, User $by, ?string $note = null): Collection<ProductionIssue>` — D4; ≤ net held (`mfg.return_exceeds`); per batch `Batch::receive((int) qty)` + shelf (first existing `batch_locations` row `give()`, else `BatchLocation::putAway` on `OpeningStock::pickShelf`), `StockCounting::resync`; kind return rows at the same unit cost.
- `ProductionOrder::netIssued(int $productId): float`, `ProductionOrder::netIssuedByBatch(int $productId): array<int batch_id, float>`, `ProductionOrder::materialCost(): float` (Σ issue/extra value − Σ return value).
- Routes `POST /mfg/orders/{order}/issue` (`mfg.orders.issue`: material_id, qty, kind, note), `POST …/issue-standard` (`mfg.orders.issue_standard`), `POST …/return` (`mfg.orders.return`). Middleware `role:admin,production_manager,warehouse_keeper`. ACTION `act.mfg.issue` (production_manager, warehouse_keeper).

- [ ] **Step 1: Failing tests:** `test_standard_issue_draws_fefo_and_records_cost` (two raw batches costs 10 and 12; lines and value correct; batch quantities down); `test_extra_issue_is_kind_extra`; `test_issue_beyond_stock_moves_nothing` (Review Focus 1); `test_material_not_in_bom_is_refused`; `test_return_goes_back_to_the_drawn_batch_and_shelf` (batch qty and shelf sum restored, `stocks` resynced); `test_return_more_than_held_is_refused` (Review Focus 2); `test_closed_order_cannot_issue`; `test_keeper_can_issue_quality_cannot` (403).
- [ ] **Step 2–4:** filter `IssueTest|RoleAccessTest` FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(mfg): issue, extra issue and return to store`.

### Task 6: Close the order (finished batch, cost, variance) and QC release

**Files:** `app/Services/Mfg/Closing.php`, `app/Services/Mfg/Qc.php`, `OrderController::close`, `Mfg/QcController.php`, views `mfg/order.blade.php` (close panel), `mfg/qc.blade.php`. Test: `tests/Feature/Mfg/CloseOrderTest.php`, `tests/Feature/Mfg/QcReleaseTest.php`.

**Interfaces:**
- `Closing::close(ProductionOrder $o, int $outputQty, User $by, ?\Carbon\CarbonInterface $producedOn = null): ProductionOrder` — D6; transaction with the order locked; creates `Batch` in `warehouse_id`: `batch_no`, `produced_on` (default today), `expires_on` (spec days or product), `qty_received = qty_remaining = output`, `cost = round(material_cost / output, 2)`, `blocked = true`, `notes = order number`; put away on `OpeningStock::pickShelf($fg)` (Rejected on error); `StockCounting::resync`; per order line fills `base_qty_actual`, `std_qty_actual`, `actual_qty`, `actual_cost`, `std_cost` (= std_qty_actual × actual average unit cost of that material, or 0 when none issued), `variance_qty`, `variance_value` (= actual_cost − std_cost), `waste_pct` (D9); order: `output_qty`, `material_cost`, `std_material_cost`, `total_cost = material_cost + overhead_cost`, `unit_cost = total_cost/output` (4 dp), `waste_pct`, `yield_pct`, `status = closed`, `closed_at/by`, `fg_batch_id`, `qc_status = quarantine`. A closed order → `mfg.order_bad_state`.
- `Qc::decide(ProductionOrder $o, string $decision, string $note, User $by, array $results = []): void` — `decision ∈ released|rejected`, note required (`mfg.qc_note_required`), only from `quarantine`; released → batch `blocked = false` + resync; rejected → batch stays blocked, note appended to batch notes. Results = map param → measured value for the approved spec's limits.
- Routes: `POST /mfg/orders/{order}/close` (`mfg.orders.close`, `role:admin,production_manager`, ACTION `act.mfg.orders_manage`), `GET /mfg/qc` (`mfg.qc`; DateRange on `produced_on`; filter qc_status; export; `role:admin,production_manager,quality`), `POST /mfg/qc/{order}` (`mfg.qc.decide`, `role:admin,quality`, ACTION `act.mfg.qc_decide` (quality)).

- [ ] **Step 1: Failing tests:** `test_close_creates_blocked_batch_in_factory_with_spec_expiry` (spec 180 days → expires produced+180); `test_close_falls_back_to_product_shelf_life`; `test_batch_cost_is_actual_materials_per_unit` (materials 1,230.00, output 1000 → batch cost 1.23, unit_cost 1.2300); `test_variance_per_material_qty_and_value` (std 36.750 at actual output, actual 40 → variance 3.250 and value at average cost); `test_close_twice_is_refused` (Review Focus 3, one batch only); `test_close_without_materials_is_refused`; `QcReleaseTest`: `test_quarantined_batch_is_not_available_until_released` (`Warehouse::availableFor` 0 → output after release) (Review Focus 4); `test_reject_keeps_batch_blocked_with_note`; `test_qc_needs_a_note`; `test_production_manager_cannot_decide_qc` (403); `test_qc_list_export`.
- [ ] **Step 2–4:** filter `CloseOrderTest|QcReleaseTest|RoleAccessTest` FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(mfg): close order into a quarantined batch, variance, QC release`.

### Task 7: Lot genealogy, recall screen, variance report

**Files:** `app/Services/Mfg/Genealogy.php`, `app/Services/Mfg/Variance.php`, `Mfg/TraceController.php`, `Mfg/VarianceController.php`, views `mfg/trace.blade.php`, `mfg/variance.blade.php`. NAV items `mfg.trace` (🔎), `mfg.variance` (📉), plus `mfg.lines` (🛤️), `mfg.boms` (🧾), `mfg.requirements` (🧮) if not added earlier. Test: `tests/Feature/Mfg/GenealogyTest.php`, `tests/Feature/Mfg/VarianceReportTest.php`.

**Interfaces:**
- `Genealogy::rawLotsFor(Batch $fg): Collection` of `{batch, product, qty, value}` (net per raw batch over the order(s) with `fg_batch_id` = this batch, or same product+batch_no).
- `Genealogy::finishedFor(Batch $raw): Collection<ProductionOrder>` (orders with net issue > 0 from this raw batch, with `fgBatch`).
- `Genealogy::recipientsFor(Batch $fg): Collection` of `{client, qty, via: invoice|sales_order, ref, date}` over every batch with the same `product_id` + `batch_no` (transfers to Maadi create such twins): `invoice_items.batch_id` → invoices (not cancelled if a status exists); `purchase_order_items.batch_id` → delivered purchase orders; plus `inVans`: `custody_items` open custody remaining qty per rep.
- `Variance::monthly(DateRange $range, ?int $productId): Collection` grouped by product + `produced_on` month: `{product, month 'Y-m', orders, planned_qty, output_qty, std_value, actual_value, variance_value, waste_pct}`; `Variance::lines(...)` per material for drill-down.
- Routes: `GET /mfg/trace?q=` (`mfg.trace`, `role:admin,production_manager,quality`): search batch_no among batches; finished product batch → raw lots + recipients; raw batch → finished batches (+ each one's recipients) — "which finished batches and which clients received them". `GET /mfg/variance` (`mfg.variance`, `role:admin,production_manager`; DateRange on `produced_on`; export).

- [ ] **Step 1: Failing tests:** `test_backward_finished_batch_to_raw_lots`; `test_forward_raw_lot_to_finished_batches`; `test_recall_lists_clients_that_received_the_batch_including_after_transfer` (invoice item on the Maadi twin batch counts); `test_trace_screen_shows_clients_for_a_raw_lot`; `test_variance_groups_by_product_and_month`; `test_variance_export_csv`; `test_unknown_batch_shows_empty_state`.
- [ ] **Step 2–4:** filter `GenealogyTest|VarianceReportTest|RoleAccessTest` FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(mfg): lot genealogy, recall screen, variance report`.

### Task 8: Production API (Lane 8 contract)

**Files:** `app/Http/Controllers/Api/ProductionApiController.php`, `routes/api.php`, `tests/Feature/ApiContractTest.php`, Test: `tests/Feature/Mfg/ProductionApiTest.php`.

**Interfaces:**
- Group `Route::prefix('production')->middleware('api.role:production_manager,admin')` inside the `api.token` group (other roles get 403, which Lane 8 reads as "feature off"):
  - `GET /api/production/orders?line=&status=` → `{orders: [row], lines: [{id, name}]}`; no status = planned + in_progress + finished; `status` must be one of `ProductionOrder::STATUSES` (422 otherwise); `line` = cost center id.
  - `POST /api/production/orders/{order}/start {idem_key}` → `{message, order: row}`.
  - `POST /api/production/orders/{order}/finish {idem_key, output_qty}` → `{message, order: row}`. Lane 8 sends `output_qty` as a decimal: validate `numeric|min:1`, then refuse a non-whole value with 422 `mfg.output_whole` (finished goods are whole pieces, D2).
  - Errors `422 {message}` (Rejected); validation 422 with `message` + `errors`.
- **row (shape fixed by Lane 8's plan `close-8 … 2026-10-19-closeout-8-app.md`):** `{id, code, product_name, line_id, line_name, status, status_label, planned_qty, output_qty, unit, started_at, finished_at}` — `code` = order number, names via `displayName()`, `unit` = `$product->unitLabel()`, timestamps ISO-8601 with offset (`toIso8601String()`, null when empty). Extra keys allowed and sent: `batch_no`, `planned_on` (Y-m-d), `hours`.
- `Idempotency::RULES` on `idem_key`. Calls `Orders::start/finish` from Task 4 (no second flow).

- [ ] **Step 1: Failing tests:** `ProductionApiTest`: `test_list_open_orders_filtered_by_line_and_status`; `test_start_and_finish_from_the_app`; `test_finish_repeated_with_same_key_returns_same_order` (one `finished_at`, one idempotency row); `test_finish_planned_order_is_422_with_message`; `test_sales_agent_gets_403`. `ApiContractTest::test_production_endpoints_follow_the_contract` (exact row keys above via `assertJsonStructure`, `lines.*.{id,name}`, `order` on start/finish, 422 `message`, 403 for `sales_agent` and `manager`) and production_manager added to the device-token role loop.
- [ ] **Step 2–4:** filter `ProductionApiTest|ApiContractTest` FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(mfg): production API for the app with idem_key`.

### Task 9: Verification and hand-off (lead, not a builder)

- [ ] Full suite `php artisan test` (baseline recorded before Task 1).
- [ ] `php artisan promax:i18n-check`; `python promax-skills/promax-feature-dev/scripts/static_check.py` from the worktree.
- [ ] QA: confirm Shopify/iTrack tokens empty on `promax_qa`; `php artisan migrate --env=qa`; browser check of the cycle on port 8010 (spec → BOM → order → issue → finish → close → QC → trace → variance).
- [ ] UAT cases `HANDOVER_2026-10\uat\_src\uat_cases_close6.py`; lane report `LANE_REPORTS_closeout\LANE_6.md`; one line in `HANDOFF_LIVE.md`.

## Interfaces for Lane 9 (costing)

- `production_orders`: `material_cost`, `std_material_cost`, `overhead_cost` (0 here), `total_cost`, `unit_cost`, `hours`, `line_id`, `closed_at`, `fg_batch_id`, `output_qty`, `overhead_method` (null), `overhead_flag` (null). Lane 9 sets overhead, recomputes `total_cost`/`unit_cost`, and updates `batches.cost` of `fg_batch_id`.
- `App\Services\Mfg\LineHours::forMonth(CostCenter $line, CarbonInterface $month): float`, `::forOrder(ProductionOrder $o): ?float` (null = no recorded hours → Lane 9 costs by kg and flags).
