# Lane 9: Costing, Internal Transfer, COGS, Overhead, 5-Level P&L, Wizard Steps 7 and 9

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cost every sale, return, internal transfer and production order with both valuation methods (batch and weighted average, the owner picks the official one), absorb factory overhead by line hours (kg default when there are no hours), post the results through `Ledger`, show a 5-level P&L (company → factory / each branch → family → product), finish the go-live wizard (step 7 settings, step 9 run).

> **Owner decision 2026-10-19:** no automatic purchase request on a production shortage. The extra task (D14, Task 2) was built, then reverted; the shortage flow stays notification-only exactly as Lane 6 left it.

**Architecture:** A derived **cost layer** in `app/Services/Costing/`:
- `cost_entries` (one row per costed origin: a sale/return `Transaction`, a received factory→branch `StockTransfer`, a closed `ProductionOrder`, a closed month's line variance) with `cost_lines` per product. Rows are keyed by `(kind, origin_type, origin_id)` and updated in place, so a re-sync never changes ids. Both methods' amounts are stored on every row; the official one is chosen at posting time.
- `CostEntry` is a `Ledger` source like `FixedAssetDepreciation`: one arm in `Rules::linesFor`, one concat in `Ledger::sources` (which re-syncs the layer first, inside the rebuild transaction).
- Overhead is computed from documents (expenses, payroll, depreciation on factory/line centers) and line hours, never from the GL, so it works before go-live.
- The P&L reads only `cost_entries`/`cost_lines` plus expense documents.

**Tech Stack:** Laravel 12, PHP 8.2, MariaDB 10.4, Blade (`layouts/system`), PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-10-19-promax-closeout-design.md` §2, §3.1.1 (steps 7–9), §3.1.2 (both methods, all sales to Maadi), §3.5 (costing, cost card, transfer price), §3.7 (5-level P&L), §4.1 (internal sale pair, overhead estimate and variance). Master plan `2026-10-19-closeout-master.md` (Global Constraints, Review Focus 3). Lane interfaces: `LANE_REPORTS_closeout/LANE_2|3|4|6|7.md`.

## Global Constraints

- Doctrine in `promax-system` / `promax-feature-dev` / `promax-accounting` / `promax-inventory` / `promax-numbers` is binding.
- GL writes only through `App\Services\Gl\Ledger` (post / repost / unpost / rebuild). Money writes inside `DB::transaction`. No sale price is computed here (`Pricing` untouched; the transfer price is a management number, not a sale price).
- Migrations guarded (`hasTable` / `hasColumn`), time columns `dateTime()`, money `decimal(14,2)`, unit costs `decimal(14,4)`, quantities `decimal(14,3)`, percents `decimal(7,2)`.
- Every UI string in `lang/ar/costing.php` and `lang/en/costing.php` (plus `nav.php`, `golive.php`, `perm.php`, `gl.php` where noted), same keys; English values with an apostrophe use double quotes. `php artisan promax:i18n-check` clean.
- Every table screen: `App\Support\DateRange` on its business date + `?export=1` via `App\Support\Csv::download` from the same filtered query.
- New screens in `Access::SCREENS` (matching `role:` middleware), `Access::NAV` (new group `nav.group_costing`), buttons behind `Access::action`.
- Additive only. Behaviour of existing flows is unchanged unless the costing GL seed has been run (new rules are not created by `GlSeeder`).
- Tests on `promax_test_9` only. DB `promax` read-only. Never port 8020. Never start/stop MySQL. No push, no merge. No client data in commits.
- Suite output to uniquely named files in the scratchpad (`lane9_*`).

## Decisions (owner can change any; logged in the lane report)

- **D1 Perpetual inventory, switched on by the costing seed.** `promax:costing-gl-seed` (and the step 7 button) creates the accounts and rules below. With rule `sup.invoice.stock` active, supplier invoices debit `inventory` instead of `expense_purchases`; with the `cost.*` rules active, COGS / transfer / production entries post. Without the seed nothing changes (existing tests and installs untouched). `GlMapping::REQUIRED_KEYS` is **not** extended; step 7 owns its own red state.
- **D2 Accounts** (system keys, `CashGlSeed`-style creation by key, suffix `.L9` on a taken code): `cogs` 5150 تكلفة المبيعات, `internal_cogs` 5151 تكلفة المبيعات الداخلية, `internal_elim` 5152 استبعاد هامش التحويل الداخلي, `overhead_applied` 5153 تكاليف تشغيل محمّلة على الإنتاج (credit nature), `production_variance` 5154 انحرافات تكاليف الإنتاج, `internal_sales` 4110 مبيعات داخلية للفروع.
- **D3 Rules** `[key, label, debit_key, credit_key]`: `cost.sale` (cogs / inventory), `cost.markup` (cogs / internal_elim), `cost.transfer` (internal_cogs / internal_sales), `cost.transfer_markup` (internal_elim / internal_sales), `cost.production` (inventory / inventory), `cost.overhead` (inventory / overhead_applied), `cost.variance` (production_variance / overhead_applied), `sup.invoice.stock` (inventory / payables).
- **D4 Internal sale with elimination.** Factory→branch transfer of a finished good (from-warehouse center kind `factory|line`, to-warehouse center kind `branch`, product kind `finished`, transfer `received`) at transfer price TP and real cost R:
  - transfer: Dr internal_cogs [factory] R · Cr internal_sales [factory] R · Dr internal_elim [company] (TP−R) · Cr internal_sales [factory] (TP−R)
  - branch sale of a product that has an approved TP on the sale date: Dr cogs [branch] R · Cr inventory [branch] R · Dr cogs [branch] (TP−R) · Cr internal_elim [company] (TP−R)
  - Result: factory = TP−R per unit transferred, branch cost = TP, company total = −R (the elimination account holds the unrealized margin of goods still in the branch). Negative TP−R flips that pair.
- **D5 Real cost R.** Batch method: invoice line `unit_cost` (already `Pricing::costFor`), transfer item `cost` (source batch), PO line `products.cost` (no batch on PO lines, same as `SalesSource::lines`), return line = original invoice item `unit_cost` else `products.cost`. Weighted average: `Valuation::wavg(product, date)` = Σ(qty_received × cost) / Σ qty_received over **origin batches** (not transfer twins: `goods_receipt_id` null or its receipt has `source_warehouse_id` null) with `cost > 0`, received on or before the date (receipt `received_on`, else `DATE(created_at)`); none → `products.cost`. Cumulative, deterministic, rebuildable. Production entries use the issued material values for both methods.
- **D6 Damaged returns** (`return_items.condition` not sound) get no cost reversal (the cost stays in COGS). Sound returns reverse at R (and TP pair). Consignment, gifts and stock-count differences are not costed in this lane (open decision for the owner; they leave GL inventory above stock value).
- **D7 Overhead pool per line per month** = posted expenses dated in the month (category type `asset` excluded — it is depreciated; type `payroll` included only when the month has no approved payroll run, so salaries are never counted twice) + `PayrollCost::forMonth` totals + `FixedAssetDepreciation` amounts, on the line center; plus the factory-center (kind `factory`) pool shared over its lines by hours (no hours in the month → by output kg; no output → stays on the factory center as variance).
- **D8 Overhead at close** (`Overhead::forClosing`): hours recorded and an estimate rate exists → hours × rate (`hours_estimate`). Estimate rate = average actual hourly rate of the line in the last 3 **closed costing months** that have a rate. No hours → kg × `cost_default_kg_rate`, flag `no_hours` (Review Focus 3). Hours but no history → kg default, flag `no_history`. Kg = output × approved-spec `net_weight_g`/1000, else product `net_content` (`g`→/1000, `kg`→×1, `ml`/`l` treated as g/kg), else output × `cost_unit_kg_fallback` (default 0.1) flag `no_weight`. Default rate missing/0 → overhead 0, flag `no_rate`. Never a division by zero, never an exception.
- **D9 Costing month close** (`CostMonth::close`, admin, month < current month, GL period of that month open): stores actual pools/hours/rates per line and posts one `variance` entry per line = pool − applied (applied = Σ `overhead_cost` of orders closed that month on the line), dated the month's last day. Closed orders and moved batches are never recomputed. A closed costing month cannot be closed again.
- **D10 Transfer price** lives in `product_transfer_prices` (history, effective date). Only an admin approval inserts a row; `TransferPrice::effective(product, date)`. The cost card's suggested TP = card unit cost × (1 + markup%/100); `cost_markup_pct` default 20. Alert levels: `above` (cost > TP), `near` (cost ≥ `cost_alert_pct`% of TP, default 90), `ok`, `none` (no TP). A production close whose unit cost reaches `near`/`above` notifies admins once.
- **D11 Branch overhead allocation** to families/products: by net sales share (`cost_branch_alloc = sales`, alternative `qty`).
- **D12 Live sync.** `CostTransactionObserver` (`$afterCommit = true`) syncs the entry of every sale/return transaction with an Invoice / PurchaseOrder / ClientReturn source and reposts it; `Ledger::repost(Transaction)` also re-syncs (admin invoice tools use repost); `StockTransfer::receive` and `Mfg\Closing::close` call the sync after commit. Full rebuild re-syncs everything from the start date first.
- **D13 Step 9** runs the approved preview's `RebuildDiff::execute` inside one outer transaction that also sets `gl_enabled=1` and `gl_golive_run_at`; any failure rolls everything back and the step shows the phase and reason. Progress goes to `Cache` key `golive_run_progress` (polled JSON).
- **D14 (withdrawn)** Automatic purchase requests on shortage: declined by the owner on 2026-10-19.

## Review Focus

1. **Production order closed with no recorded hours** → overhead = kg × default rate, `overhead_flag = no_hours`, batch cost includes it; no weight → fallback kg + `no_weight`; no default rate → 0 + `no_rate`; a line with 0 hours at month close → rate null, whole pool to variance — never a division by zero. Tests in Task 5.
2. **Rebuild twice with no data change** (cost entries included) → second diff 0/0/0; a re-sync keeps ids. Test in Task 3.
3. **Internal sale never inflates company figures:** P&L company revenue = external sales only, company COGS = real cost, and factory + branches + company center − elimination = company net. Tests in Tasks 4 and 8.
4. **Switching the official method** changes the posted COGS after rebuild and the other method stays visible in the P&L and the card. Tests in Tasks 3 and 8.
5. **Zero-cost products** (seen on live) cost 0, are counted and shown as a warning on the P&L and card, never hidden. Tests in Tasks 3 and 8.

---

## File Structure

- `database/migrations/2026_10_19_900100_costing.php` — all lane schema.
- Models: `app/Models/CostEntry.php`, `CostLine.php`, `CostMonth.php`, `CostMonthLine.php`, `ProductTransferPrice.php`.
- Services `app/Services/Costing/`: `CostingSettings.php`, `CostingGlSeed.php`, `Valuation.php`, `TransferPrice.php`, `CostSync.php`, `CostGl.php`, `Overhead.php`, `CostMonths.php`, `CostCard.php`, `ProfitLoss.php`.
- `app/Observers/Costing/CostTransactionObserver.php`; `app/Providers/CostingServiceProvider.php` (observer, wizard steps, rebuild check) registered in `bootstrap/providers.php`.
- `app/Console/Commands/CostingGlSeedCommand.php` (`promax:costing-gl-seed {--dry-run}`), `CostingSyncCommand.php` (`promax:costing-sync {--from=} {--dry-run}`).
- Controllers `app/Http/Controllers/Costing/`: `OverheadController.php`, `CardController.php`, `PnlController.php`; wizard: `app/Http/Controllers/Gl/GoLiveCostingController.php`.
- Steps: `app/Services/Gl/GoLive/Steps/CostingStep.php`, `RunStep.php`.
- Views: `resources/views/costing/{overhead,cards,pnl}.blade.php`, `resources/views/gl/golive/steps/{costing,run}.blade.php`.
- Modified: `app/Services/Gl/Rules.php` (one arm + supplier key), `app/Services/Gl/Ledger.php` (sources concat, repost hook), `app/Services/Mfg/Closing.php` (overhead before the batch cost), `app/Models/StockTransfer.php` (sync after receive), `routes/web.php`, `app/Support/Access.php`, `lang/{ar,en}/{costing,nav,golive,perm,gl,supplier}.php`.
- Tests `tests/Feature/Costing/*Test.php`.

---

### Task 1: Schema, models, settings and the costing GL seed

**Files:** migration, five models, `CostingSettings`, `CostingGlSeed`, `CostingGlSeedCommand`, `lang/{ar,en}/costing.php` (start), `lang/{ar,en}/gl.php` (`sk_<key>` labels). Test: `tests/Feature/Costing/CostingSchemaTest.php`.

**Interfaces (Produces):**
- `cost_entries`: id, kind varchar(20) ∈ `CostEntry::KINDS = ['sale','return','transfer','production','variance']`, origin_type varchar(120), origin_id bigint, date date, cost_center_id (FK cost_centers null), other_center_id (FK null; transfer → destination branch, production → FG warehouse center), qty decimal(14,3) default 0, revenue decimal(14,2) default 0 (sale/return net `total`, returns negative), amount_batch, amount_wavg, amount_transfer (TP total, null when no TP) decimal(14,2), materials, overhead decimal(14,2) default 0, flags varchar(120) null (comma list: `zero_cost`, `no_transfer_price`, …), timestamps; unique(kind, origin_type, origin_id); index(date), index(cost_center_id).
- `cost_lines`: id, cost_entry_id FK cascade, product_id FK, batch_id null, qty decimal(14,3), revenue decimal(14,2), unit_batch, unit_wavg decimal(14,4), unit_transfer decimal(14,4) null, amount_batch, amount_wavg decimal(14,2), amount_transfer decimal(14,2) null, zero_cost bool default false; index(product_id).
- `cost_months`: id, period char(7) unique, status varchar(20) default `closed`, closed_by FK users null, closed_at dateTime null, summary json null, timestamps.
- `cost_month_lines`: id, cost_month_id FK cascade, line_id FK cost_centers, direct, shared, payroll, depreciation, pool, applied, variance decimal(14,2), hours decimal(10,2), output_kg decimal(14,3), rate decimal(14,4) null, timestamps; unique(cost_month_id, line_id).
- `product_transfer_prices`: id, product_id FK, price decimal(10,2), unit_cost decimal(14,4), markup_pct decimal(7,2) null, effective_from date, approved_by FK users, approved_at dateTime, note varchar(255) null, timestamps; index(product_id, effective_from).
- `production_orders` += `overhead_rate` decimal(14,4) null, `overhead_kg` decimal(14,3) null.
- `CostEntry`: casts, `lines(): HasMany`, `costCenter()`, `otherCenter()`, `origin(): MorphTo`, `amount(string $method): float` (`batch`|`wavg`), `official(): float` (`amount(CostingSettings::method())`), `hasFlag(string): bool`.
- `CostingSettings` (all via `Setting::read`): `method(): string` (`cost_method`, `batch` default), `defaultKgRate(): ?float` (`cost_default_kg_rate`, null when blank/≤0), `unitKgFallback(): float` (0.1), `markupPct(): float` (20), `alertPct(): float` (90), `allocBasis(): string` (`sales`|`qty`), `save(array $values, User $by): void` (writes the six keys + `cost_settings_saved_at`), `KEYS` const.
- `CostingGlSeed::run(bool $dryRun = false): array{accounts_created:int, rules_created:int, notes:list<string>}` — D2/D3, `CashGlSeed` pattern (by key, existing account never changed, rules `firstOrCreate`, refuses a real run on an empty tree with the same message style as `promax:cash-setup`). `CostingGlSeed::ready(): bool` = every D2 key exists and every D3 rule exists and is active.
- `php artisan promax:costing-gl-seed {--dry-run}` prints the counts and notes.

- [ ] **Step 1: Failing tests** `CostingSchemaTest`: `test_tables_and_columns_exist`; `test_settings_defaults_and_save` (method `batch`, markup 20, alert 90, kg rate null; save `wavg`/12.5 → read back); `test_costing_seed_creates_accounts_and_rules_once` (after `GlSeeder`: 6 accounts with keys and types — `internal_sales` revenue, the rest expense —, 8 rules; second run 0/0; `ready()` true); `test_seed_dry_run_writes_nothing`; `test_seed_refuses_empty_tree`; `test_gl_seeder_alone_does_not_create_costing_rules` (`GlSeeder` → `GlPostingRule::forKey('cost.sale')` null).
- [ ] **Step 2:** `php artisan test --filter=CostingSchemaTest` → FAIL.
- [ ] **Step 3:** Implement migration, models, settings, seed, command, lang.
- [ ] **Step 4:** Same filter → PASS; `php artisan promax:i18n-check` clean.
- [ ] **Step 5: Commit** `feat(costing): schema, settings and costing GL seed`.

### Task 2: (withdrawn — owner decision 2026-10-19, no automatic purchase request on shortage)

### Task 3: Valuation, sale/return cost entries and the Ledger arm

**Files:** `Valuation.php`, `TransferPrice.php`, `CostSync.php` (sale/return part), `CostGl.php`, `CostTransactionObserver.php`, `CostingServiceProvider.php` (+ `bootstrap/providers.php`), `CostingSyncCommand.php`, modify `Rules.php`, `Ledger.php`. Test: `tests/Feature/Costing/SaleCostTest.php`.

**Interfaces:**
- `Valuation::wavg(Product|int $product, CarbonInterface $date): float` (D5, memoized per request; `Valuation::flush()`); `Valuation::batchUnit(?Batch $b, Product $p): float` (= `Pricing::costFor`).
- `TransferPrice::effective(int $productId, CarbonInterface $date): ?ProductTransferPrice` (latest `effective_from ≤ date`), `TransferPrice::approve(Product $p, float $price, User $by, ?float $unitCost, ?float $markupPct, ?string $note): ProductTransferPrice` (admin-only check in the controller; effective today).
- `CostSync::forTransaction(Transaction $tx): ?CostEntry` — kinds `sale` (source Invoice → `invoice_items`; PurchaseOrder → items with `COALESCE(delivered_qty, qty)`) and `return` (source ClientReturn, sound items only); upsert by `(kind, Transaction morph, id)`, lines replaced, flags `zero_cost` when any line has R = 0, `no_transfer_price` never set on sales (no TP = branch cost at R); center = `CostCenterResolver::for($tx)`; returns null (and deletes an existing entry) when the tx is not costable.
- `CostSync::run(CarbonInterface $from, bool $post = false): array{synced:int, deleted:int}` — every costable origin dated ≥ from (this task: transactions), deletes entries whose origin vanished; `$post` reposts each changed entry (false inside a rebuild).
- `CostSync::forgetTransaction(Transaction $tx): void` (unpost + delete).
- `CostGl::handles(Model $m): bool`, `CostGl::lines(CostEntry $e): array` (same shape as `Rules::linesFor`; slots `dr`/`cr` for the main pair, `mk_dr`/`mk_cr` for the markup pair, `oh_dr`/`oh_cr` for overhead; inactive or missing rule → that pair omitted; official amount = `$e->official()`; returns are the sale pair flipped), `CostGl::sources(string $date): \Generator` (runs `CostSync::run($from)` first, then cursors `CostEntry` by date, id).
- `Rules::linesFor` gets `CostGl::handles($source) => CostGl::lines($source)`; `Rules::supplier` uses `sup.invoice.stock` for `kind = invoice` when that rule exists and is active, else `sup.invoice`.
- `Ledger::sources` gets `->concat(CostGl::sources($d))`; `Ledger::repost` calls `CostSync::forTransaction($source)` first when `$source` is a `Transaction` (try/catch + log, before the `gl_enabled` early return).
- `CostTransactionObserver` (`$afterCommit = true`): created/updated → `CostSync::forTransaction` + `Ledger::repost($entry)`; deleted → `CostSync::forgetTransaction`. Failures logged, never thrown.
- `php artisan promax:costing-sync {--from=YYYY-MM-DD} {--dry-run}` (default from = `gl_start_date` or the first transaction date).

- [ ] **Step 1: Failing tests** (`GlSeeder` + `CostingGlSeed::run()`, `gl_enabled=1`, start 2026-08-01):
  - `test_invoice_sale_posts_cogs_at_batch_cost` (2 lines, unit_cost 10 and 4, qty 3/5 → entry amount_batch 50, GL Dr cogs 50 Cr inventory 50 on the MAADI center)
  - `test_weighted_average_amount_is_stored_and_used_when_official` (origin batches 10 @ 8 and 30 @ 12 → wavg 11; set `cost_method=wavg`, rebuild → cogs = qty × 11) (Review Focus 4)
  - `test_transfer_twin_batches_do_not_count_in_the_average`
  - `test_po_delivery_partial_uses_delivered_qty_and_product_cost`
  - `test_sound_return_reverses_cost_damaged_does_not`
  - `test_sale_with_transfer_price_posts_markup_pair` (TP 15, R 10, qty 2 → Dr cogs 20+10, Cr inventory 20, Cr internal_elim 10)
  - `test_zero_cost_line_is_flagged` (Review Focus 5)
  - `test_rebuild_twice_with_cost_entries_gives_empty_diff_and_keeps_ids` (two `RebuildDiff` previews/executes; ids of `cost_entries` unchanged; second summary 0/0/0) (Review Focus 2)
  - `test_admin_invoice_repost_resyncs_the_cost_entry` (`whereKey()->update` an item qty + `Ledger::repost($tx)` → entry amount follows)
  - `test_without_costing_seed_nothing_posts_and_supplier_invoice_stays_expense`
  - `test_supplier_invoice_debits_inventory_with_the_stock_rule`
  - `test_costing_sync_command_dry_run_writes_nothing`
- [ ] **Step 2–4:** filter `SaleCostTest|Gl` FAIL → implement → PASS (all existing `Gl` tests still green).
- [ ] **Step 5: Commit** `feat(costing): sale and return COGS by both methods through the ledger`.

### Task 4: Internal transfer at transfer price

**Files:** `CostSync.php` (transfer part), `CostGl.php` (transfer lines), `app/Models/StockTransfer.php` (after-commit call in `receive`). Test: `tests/Feature/Costing/InternalTransferTest.php`.

**Interfaces:**
- `CostSync::forTransfer(StockTransfer $t): ?CostEntry` — D4 condition; date = `received_on`; `cost_center_id` = from-warehouse center, `other_center_id` = to-warehouse center; per item `qty_received`, R batch = item `cost`, R wavg = `Valuation::wavg`, TP = `TransferPrice::effective(product, received_on)` (none → TP = R, flag `no_transfer_price`); `CostSync::run` includes received internal transfers.
- `CostGl::lines` for `transfer`: `cost.transfer` pair at R on the factory center + `cost.transfer_markup` pair (elim on `CostCenter::company()`, sales on factory) at TP−R.

- [ ] **Step 1: Failing tests:** `test_factory_to_maadi_transfer_posts_internal_pair` (qty 10, R 8, TP 11 → internal_sales Cr 110 on FAC, internal_cogs Dr 80 FAC, internal_elim Dr 30 CO); `test_raw_or_branch_to_branch_transfer_is_not_internal`; `test_missing_transfer_price_uses_cost_and_flags`; `test_company_pl_nets_internal_to_zero_after_branch_sells_everything` (transfer 10 then sell 10 → Σ internal_sales − internal_cogs − internal_elim(net) = 0 and cogs = 110 on MAADI with elim credit 30 → company P&L accounts sum = −80) (Review Focus 3); `test_receive_with_shortage_costs_received_qty_only`.
- [ ] **Step 2–4:** filter `InternalTransferTest|Transfer` FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(costing): factory to branch internal sale at transfer price with elimination`.

### Task 5: Overhead absorption, production cost entry, costing month close

**Files:** `Overhead.php`, `CostMonths.php`, `CostSync.php` (production + variance), `CostGl.php` (production/overhead/variance lines), modify `app/Services/Mfg/Closing.php`. Test: `tests/Feature/Costing/OverheadTest.php`.

**Interfaces:**
- Consumes: `LineHours::forOrder/forMonth`, `PayrollCost::forMonth`, `Expense`, `FixedAssetDepreciation`, `CostCenter::lines()`, `ProductSpec::approvedFor`.
- `Overhead::forClosing(ProductionOrder $o, int $outputQty): array{amount:float, method:string, flag:?string, rate:?float, kg:?float}` — D8; methods `hours_estimate|kg_default`.
- `Overhead::kgFor(Product $p, int $qty): array{kg:float, flag:?string}`.
- `Overhead::pools(CarbonInterface $month): array<int line_id, array{line:CostCenter, direct:float, shared:float, payroll:float, depreciation:float, pool:float, hours:float, output_kg:float, applied:float, rate:?float}>` plus key `'factory_unshared'` float — D7 (works with the GL off).
- `Overhead::estimateRate(CostCenter $line, CarbonInterface $month): ?float`.
- `Closing::close` computes overhead **before** creating the batch: `overhead_cost`, `overhead_method`, `overhead_flag`, `overhead_rate`, `overhead_kg`, `total_cost`, `unit_cost`, batch `cost = round(total/output, 2)`; after commit `CostSync::forProduction($row)` + `Ledger::post`, and the D10 admin alert.
- `CostSync::forProduction(ProductionOrder $o): ?CostEntry` (closed orders; materials = `material_cost`, overhead; `cost_center_id` = line, `other_center_id` = FG warehouse center; amount_batch = amount_wavg = total).
- `CostMonths::close(string $period, User $by): CostMonth` — D9 (Rejected `costing.month_not_past`, `costing.month_closed`, `gl.period_closed`); creates `cost_month_lines` and one `variance` `CostEntry` per line (origin `CostMonthLine`), posted.
- `CostGl::lines` for `production` (`cost.production` dr inventory [other] / cr inventory [line warehouse center or line] at materials; `cost.overhead` at overhead) and `variance` (`cost.variance`, flipped when negative, both on the line).

- [ ] **Step 1: Failing tests:**
  - `test_order_without_hours_is_costed_by_kg_at_default_rate_and_flagged` (spec 70 g × 1000 = 70 kg × 3.00 = 210 overhead, flag `no_hours`, batch cost = (materials + 210)/1000) (Review Focus 3)
  - `test_order_without_weight_uses_the_fallback_and_flags_no_weight`
  - `test_no_default_rate_costs_zero_overhead_flagged_no_rate_without_error`
  - `test_order_with_hours_uses_three_month_average_rate` (closed months rates 10, 20, 30 → 20 × 5 h = 100, method `hours_estimate`)
  - `test_hours_without_history_falls_back_to_kg_and_flags_no_history`
  - `test_pool_splits_factory_expenses_by_line_hours` (FAC expense 900, L1 2 h, L2 1 h → shared 600/300; line payroll + depreciation + direct expense added; asset-type expense excluded)
  - `test_month_close_posts_variance_pool_minus_applied` (pool 1000, applied 800 → variance 200 Dr production_variance Cr overhead_applied on the line; closed orders untouched)
  - `test_month_close_with_zero_hours_puts_whole_pool_in_variance` (rate null, no exception)
  - `test_month_close_refuses_current_month_and_twice`
  - `test_production_entry_posts_inventory_and_overhead_applied`
  - `test_unit_cost_near_transfer_price_notifies_admins_once`
- [ ] **Step 2–4:** filter `OverheadTest|CloseOrderTest|Mfg` FAIL → implement → PASS (Lane 6 close tests updated only where the batch cost now includes overhead with no default rate = unchanged).
- [ ] **Step 5: Commit** `feat(costing): overhead absorption by line hours, kg default, monthly variance`.

### Task 6: Costing screens — overhead by month and the NAV group

**Files:** `Costing/OverheadController.php`, `resources/views/costing/overhead.blade.php`, `routes/web.php` (group `costing.` `role:admin,accountant`), `Access.php` (accountant SCREENS `costing.`, NAV group `nav.group_costing` 💹 with `costing.pnl`, `costing.cards`, `costing.overhead`; ACTIONS `act.costing.admin` = admin only covering `costing.overhead.close`, `costing.cards.approve`, `costing.sync`), `lang/{ar,en}/{costing,nav,perm}.php`. Test: `tests/Feature/Costing/OverheadScreenTest.php`.

**Interfaces:**
- `GET /costing/overhead?month=Y-m` (`costing.overhead`): per-line table from `Overhead::pools` (direct, shared, payroll, depreciation, pool, hours, rate, estimate rate, applied, variance) + KPIs; orders closed in the DateRange (`closed_at`) with method, flag badge, kg, rate, overhead, unit cost; closed months list; `?export=1` exports the orders table.
- `POST /costing/overhead/close` (`costing.overhead.close`, `role:admin`, field `period`) → `CostMonths::close`.
- `POST /costing/sync` (`costing.sync`, `role:admin`, optional `from`) → `CostSync::run(from, post: true)`; flash counts.

- [ ] **Step 1: Failing tests:** `test_accountant_sees_the_screen_and_cannot_close` (200 / POST 403); `test_admin_closes_a_month_from_the_screen`; `test_flagged_orders_are_listed_with_their_flag`; `test_export_is_csv`; `test_sales_agent_gets_403`; `RoleAccessTest`/`AccessTest` green.
- [ ] **Step 2–4:** filter `OverheadScreenTest|RoleAccessTest|AccessTest` FAIL → implement → PASS, i18n clean.
- [ ] **Step 5: Commit** `feat(costing): overhead screen, month close and costing navigation`.

### Task 7: Cost card and transfer price approval

**Files:** `CostCard.php`, `Costing/CardController.php`, `resources/views/costing/cards.blade.php`, routes, lang. Test: `tests/Feature/Costing/CostCardTest.php`.

**Interfaces:**
- `CostCard::for(Product $p): array{product, bom:?Bom, raw:float, packaging:float, overhead:float, overhead_basis:string, unit_batch:float, unit_wavg:float, unit:float (official), markup_pct:float, suggested:float, tp:?ProductTransferPrice, status:'ok'|'near'|'above'|'none', lines:list<array{product, qty, last_price, avg_price, value}>}` — materials from the approved BOM std qty per unit (`Bom::stdFor` for 1000 ÷ 1000) × last price (latest origin batch cost of that material, batch method) or `Valuation::wavg` (wavg method); overhead per unit = latest closed order's `overhead_cost/output_qty` of the product, else kg/unit × default rate; finished products without an approved BOM fall back to `products.cost` with `bom = null`.
- `CostCard::all(): Collection` (finished products, active).
- `GET /costing/cards` (`costing.cards`; filter status, family; export); tab `?tab=history` = TP approvals with DateRange on `approved_at` + export.
- `POST /costing/cards/{product}/approve` (`costing.cards.approve`, `role:admin`, `price` required numeric > 0, `note`) → `TransferPrice::approve`.

- [ ] **Step 1: Failing tests:** `test_card_cost_from_bom_materials_and_overhead` (BOM 0.05 raw @ 40 + 1 pack @ 1.5 per unit, overhead per unit 0.7 → unit 4.20; markup 20 → suggested 5.04); `test_card_shows_both_methods`; `test_status_near_and_above_against_approved_price`; `test_only_admin_approves_and_history_lists_it` (accountant POST 403); `test_new_approval_does_not_change_past_transfers` (old transfer keeps its TP after a new approval and a re-sync); `test_cards_export_csv`.
- [ ] **Step 2–4:** filter `CostCardTest|RoleAccessTest` FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(costing): cost card with suggested transfer price and approval`.

### Task 8: Five-level P&L

**Files:** `ProfitLoss.php`, `Costing/PnlController.php`, `resources/views/costing/pnl.blade.php`, lang. Test: `tests/Feature/Costing/ProfitLossTest.php`.

**Interfaces:**
- `ProfitLoss::build(DateRange $range, ?string $method = null): array{company:array, factory:array, branches:list<array>, company_center:array, elimination:float, warnings:array{zero_cost:int, no_transfer_price:int, lines_without_items:float}}`.
  - Row shape: `{key, label, revenue, cost, cost_alt, gross, expenses, net, children?:list<row>}` (money rounded 2; `cost` = method given or official, `cost_alt` = the other).
  - Branch row per center kind `branch`: revenue = sale − return entries on that center, cost at TP where `amount_transfer` is set else R, expenses = branch center expenses + payroll + depreciation in the range; children = families (product family) → products, expenses allocated by D11.
  - Factory row: revenue = internal_sales (TP totals of transfer entries), cost = their R, expenses = pools of the months in range − applied (under/over absorption, i.e. variance for closed months and provisional for open ones); children = lines.
  - Company center: expenses on center kind `company` or null.
  - Company: revenue = Σ branch revenue, cost = Σ real R of sale/return entries, expenses = all, `net` = factory.net + Σ branch.net + company_center.net − elimination, elimination = Σ transfer (TP−R) − Σ branch sale (TP−R) in range.
- `GET /costing/pnl` (`costing.pnl`; DateRange default `month` on entry date; `method` select; expandable rows; KPIs revenue/gross/net; warnings bar; `?export=1` flat CSV with a `level` column).

- [ ] **Step 1: Failing tests:** `test_company_equals_sum_of_levels_minus_elimination` (one transfer, partial branch sale, a branch expense, a company expense, factory variance) (Review Focus 3); `test_branch_cost_is_transfer_price_company_cost_is_real`; `test_branch_expenses_allocated_to_products_by_sales_share` (two products 75/25 → expense 400 → 300/100); `test_alt_method_column_and_switch` (Review Focus 4); `test_zero_cost_and_missing_transfer_price_warnings` (Review Focus 5); `test_revenue_matches_sales_source_for_the_range` (Σ revenue = `SalesSource` sales − returns for the same range when every sale has items); `test_export_and_access` (accountant 200, sales_agent 403, CSV).
- [ ] **Step 2–4:** filter `ProfitLossTest` FAIL → implement → PASS, i18n clean.
- [ ] **Step 5: Commit** `feat(costing): five-level profit and loss`.

### Task 9: Wizard step 7 (costing settings) and step 9 (run)

**Files:** `Steps/CostingStep.php`, `Steps/RunStep.php`, `Gl/GoLiveCostingController.php`, views `gl/golive/steps/{costing,run}.blade.php`, `CostingServiceProvider` (register both steps; `RebuildDiff::addCheck('cost_cogs', …)`), `routes/web.php` (inside the `golive` admin group, before `{n}`), `lang/{ar,en}/golive.php`. Test: `tests/Feature/Costing/GoLiveCostingTest.php`; update `tests/Feature/Gl/GoLiveWizardTest.php` only where it asserted the placeholder text for step 9.

**Interfaces:**
- Step 7 (`key` `costing`, title `golive.s7_title`): red when `CostingGlSeed::ready()` is false, or `cost_default_kg_rate` is empty, or settings were never saved; yellow when finished products with an approved BOM have no approved TP (count + link to `costing.cards`); green otherwise. Data: settings form, seed preview (dry-run counts), products without TP.
- Routes: `POST /gl/golive/costing` (`gl.golive.costing.save`: `cost_method` in batch,wavg; `cost_default_kg_rate` numeric > 0; `cost_unit_kg_fallback` numeric > 0; `cost_markup_pct` 0–500; `cost_alert_pct` 1–100; `cost_branch_alloc` in sales,qty), `POST /gl/golive/costing/seed` (`gl.golive.costing.seed`, `dry_run` bool), `POST /gl/golive/run` (`gl.golive.run`), `GET /gl/golive/run/progress` (`gl.golive.run.progress`, JSON `{phase, done, total, status, message}`).
- `RunStep` (`key` `run`): `todo` + locked until the latest preview is approved (keeps showing `golive.s9_note_locked` / `golive.s9_note_ready`); green after an `execute` run with `status=ok`; red when the last execute failed (message = phase + reason). Data: last execute summary, links to trial balance, balance sheet, income, `costing.pnl`.
- Run (D13): outer `DB::transaction` → `Setting gl_enabled=1` → `RebuildDiff::execute($preview, $user, $progress)` → status must be `ok` else throw → `Setting gl_golive_run_at`; on failure roll back, then record the failed run row and the progress message after rollback. `set_time_limit(0)`.
- Check `cost_cogs`: expected = Σ official (sale − return) entries from start when `cost.sale` is active, actual = GL `cogs` balance from start minus markup lines; ok within 0.01; when not seeded expected = actual = 0, ok.

- [ ] **Step 1: Failing tests:** `test_step7_red_until_seeded_and_saved_then_yellow_without_transfer_prices_then_green`; `test_step7_save_validates_and_stores_settings`; `test_step7_seed_button_dry_run_then_real`; `test_step9_locked_until_preview_approved`; `test_step9_run_executes_enables_gl_and_turns_green` (seeded world: sale, transfer, closed order → after run: GL has cost entries, `gl_enabled=1`, trial balanced, step 9 green, page links to reports); `test_step9_failure_rolls_back_everything_and_shows_the_reason` (bind a failing check → no `gl_entries` change, `gl_enabled` unchanged, red with message); `test_progress_endpoint_returns_json`; `test_accountant_cannot_run` (403); `GoLiveWizardTest` green.
- [ ] **Step 2–4:** filter `GoLiveCostingTest|GoLiveWizardTest|RebuildDiffTest` FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(gl): go-live wizard step 7 costing settings and step 9 run`.

### Task 10: Verification, QA, UAT, report (lead)

- [ ] Full `php artisan test` (baseline 1571 passed / 2 skipped) → output to `scratchpad/lane9/full_*.txt`.
- [ ] `php artisan promax:i18n-check`; `python C:\xampp\htdocs\ProMax\promax-skills\promax-feature-dev\scripts\static_check.py --erp .`; `php artisan view:cache` then `view:clear`.
- [ ] `promax-flow-guard` impact check: invoice sale, PO delivery, returns, transfers, production close, supplier invoice (with and without the costing seed).
- [ ] QA on `promax_qa`: `php artisan migrate --env=qa`; `promax:costing-gl-seed --dry-run --env=qa` (expect refusal/notes: no GL tree on QA — do not seed GL there); `promax:costing-sync --dry-run --env=qa`; in-process GET of `costing.pnl|cards|overhead` (+export) and `gl.golive.show` 7 and 9 as an existing admin and accountant (no new users, no passwords, no writes).
- [ ] UAT cases `C:\xampp\htdocs\ProMax\HANDOVER_2026-10\uat\_src\uat_cases_close9.py` (prefix `COST`, format of `uat_cases_close6.py`).
- [ ] Report `C:\xampp\htdocs\ProMax\LANE_REPORTS_closeout\LANE_9.md` (first line `STATUS: …`) and one Arabic bullet in `HANDOFF_LIVE.md` «سجل التحديثات» (re-read just before editing).

## UAT additions (Task 10 writes them)

COST cases: costing seed and settings in step 7; sale COGS both methods; switching official method then preview/run; factory→Maadi transfer at TP and the elimination; order without hours costed by kg and flagged; month close variance; cost card alert and TP approval (admin only); 5-level P&L drill-down and export; step 9 run and failure rollback.

## Live rollout notes (for the report)

1. Upload, `php artisan migrate` (`2026_10_19_900100_costing`).
2. After the chart is adopted and mapped (wizard 2–5): `php artisan promax:costing-gl-seed --dry-run`, then real (or the step 7 button). Map the six new keys if the accountant's tree has its own accounts.
3. Step 7: choose the official method, kg default rate, markup, alert, allocation; approve transfer prices on `costing.cards`.
4. `php artisan promax:costing-sync --dry-run` to see counts; step 8 preview; step 9 run.
