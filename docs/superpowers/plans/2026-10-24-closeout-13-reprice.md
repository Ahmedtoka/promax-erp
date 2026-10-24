# Lane 13: price-change propagation + retro repricing + price change log — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A price change (price-list items, chain list, branch list, cash-van client list) is held as a *draft run*, previewed with exact money effect, and on confirm applied atomically to the master data and to every eligible existing invoice and purchase order, with a full audit log screen.

**Architecture:** A new engine `App\Services\Reprice\PriceChange` owns runs (`price_change_runs` + `price_change_docs`). The preview runs the *same apply code* inside `DB::beginTransaction()` … `rollBack()` so preview totals equal apply totals by construction; apply re-runs it in one `DB::transaction` and refuses if the result differs from the stored preview (data changed in between). Per-document repricing reuses the existing single-invoice tool and single-PO tool, extracted unchanged into `App\Services\Reprice\InvoiceRepricer` / `PoRepricer` so there is one pricing path. Prices come only from `App\Services\Pricing` (via `Pricing::quote` / `Client::priceFor`).

**Tech Stack:** Laravel 12, MySQL, Blade, PHPUnit feature tests (`php artisan test`), DB `promax_test_13`.

**Spec:** the coordinator's lane-13 brief (owner requirements A–G + money-safety rules), restated below.

## Global Constraints

- Worktree `C:\xampp\htdocs\ProMax\_wt\close-13`, branch `close-13-reprice`, base `integration` 7c964ed. Tests on `promax_test_13` (phpunit.xml, skip-worktree). Worktree `.env` points at `promax_test_13` (never `promax`).
- NEVER write DB `promax`; never `migrate:fresh`/seed on promax / promax_qa / promax_demo; never start/stop MySQL; no client data/secrets in commits; no push; no merge to master.
- Prices only via `App\Services\Pricing` (and `Client::priceFor`, which delegates to it). Discount only via `Client::effectiveDiscount()`. Tax only via `App\Services\Tax`. No second pricing path.
- Every money move: transactions rows + `Client::recalculate()` inside the same `DB::transaction`; GL via the existing repost (`Ledger::repost`), same as the single tool.
- Migrations guarded (`hasTable`/`hasColumn`), new time columns `dateTime()` never `timestamp()` (including created_at/updated_at — declare them as `dateTime(...)->nullable()`). Money `decimal(14,2)`.
- Every user-visible string in `lang/ar` AND `lang/en` (same keys; new file `lang/{ar,en}/reprice.php`), English strings with apostrophes in double quotes. `php artisan promax:i18n-check` clean.
- New screen in `Access::SCREENS` + `Access::NAV`; new action `act.prices.propagate` (default: admin only). Accountant can view the log.
- Comments in Arabic in the house style; no `*/` inside comments.

## Money-safety rules (decided by the coordinator — implement exactly)

1. Each invoice is repriced by the single-invoice tool's logic (`InvoiceRepricer`), each PO by the single-PO tool's logic (`PoRepricer`). No other pricing code.
2. Skipped with a reason (never changed): ETA exported/submitted (`eta_locked`), consignment (`consignment`), has a linked return (`has_return`), an item without a price in the client's current list (`not_priced`, detail = product name), invoice dated in a closed GL period (`closed_period`, detail = month label, via `PeriodGuard::isClosed($invoice->created_at)` regardless of request mode), cancelled PO (`po_cancelled`), delivered PO (`po_delivered`, text «أمر توريد مُسلَّم — راجعه يدوي» — the existing PO tool refuses delivered orders, so we do not invent a path), PO with agreed fixed net prices `price_mode in (old,new)` (`po_fixed_price`, same exclusion as `repriceCheck`), PO without client (`no_client`).
3. Cash invoices (already collected at sale): bulk reprice changes the `sale` entry only; the linked `collection` entry is never edited, so the difference stays on the client ledger as owed (+) or credit (−). (The single-invoice button keeps today's behaviour of moving the collection with it — that is an explicit admin action on one document.)
4. Custody quantities are never touched (repricing never changes qty; `unit_cost`/`cost_total` untouched).
5. Idempotent: a document whose recomputed money equals the stored money (every line `list_price`, `price`, `total`, `tax` within 0.005, and `discount_pct`) is "unchanged" — not written, not marked, not logged as changed. Re-running with no price change changes nothing.
6. Atomicity: **all-or-nothing per run** (one `DB::transaction` covering master change + all documents + all `recalculate()` + run/doc rows). Justification: live has 339 invoices / 138 POs / 888 clients (read 2026-10-24), the biggest list covers 94 invoices, so one transaction is seconds; one transaction is what makes "preview == apply" and "failure leaves nothing half-applied" true. Large runs: documents iterated with `chunkById(200)`, `set_time_limit(300)`, and a hard cap of 5000 documents per run (`reprice.too_many` — narrow with the "since" date).
7. Permission: `act.prices.propagate` (admin only by default) covers creating a re-sync run and confirming the retro apply. The "save for new sales only" button (= today's behaviour) stays with whoever could edit that thing before (creator of the draft or admin). Accountant (and admin) view the log.
8. Stale guard: apply compares its totals to the run's stored preview; any difference → `Rejected(__('reprice.preview_stale'))`, nothing written, user re-previews.

## Review Focus

1. A price-list edit by a **manager** (has `act.prices.edit`, not propagate): must still be able to save for new sales only; must get 403 on retro apply. (Task 4 test `test_manager_can_save_master_only_but_not_apply`.)
2. Client form saved with other field changes *and* a new price list: the other fields save immediately, only the list is held for the preview. (Task 4 test `test_client_update_holds_only_price_list`.)
3. Cash invoice repriced down: client balance becomes negative (credit) and equals Σdebit−Σcredit. (Task 3 test `test_cash_invoice_difference_lands_on_ledger`.)
4. Preview is truly side-effect free: after preview, prices/invoices/transactions/balances unchanged and `PriceList` memo caches flushed. (Task 3 test `test_preview_writes_nothing`.)
5. A second confirm of the same run (double click) must not apply twice. (Task 3 test `test_apply_twice_is_rejected`.)

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_10_24_000100_price_change_runs.php` | create `price_change_runs`, `price_change_docs`; add `invoices.repriced_at/repriced_by/price_change_run_id`, `purchase_orders.price_change_run_id` |
| `app/Models/PriceChangeRun.php`, `app/Models/PriceChangeDoc.php` | models, `HasDocumentNumber` (prefix `PCR-`), relations, label helpers |
| `app/Services/Reprice/InvoiceRepricer.php` | extracted single-invoice reprice (blocker / plan / apply) |
| `app/Services/Reprice/PoRepricer.php` | extracted single-PO reprice (+ `fillItems` moved from OpsController) |
| `app/Services/Reprice/InvoiceGl.php` | extracted `repostInvoiceGl` body |
| `app/Services/Reprice/PriceChange.php` | engine: draft / preview / apply / applyMasterOnly / cancel / recordSingle / chainListOf |
| `app/Http/Controllers/PriceChangeController.php` | preview page + actions (refresh, apply, master, cancel, re-sync draft) |
| `app/Http/Controllers/PriceChangeLogController.php` | log index/export/show/doc |
| `resources/views/erp/price_change.blade.php` | preview / result page |
| `resources/views/erp/price_changes.blade.php`, `price_change_run.blade.php`, `price_change_doc.blade.php` | log screens |
| `lang/{ar,en}/reprice.php` | all strings |
| modified: `OpsController` (delegation), `PriceListController` (savePrices/bulk → draft), `GroupController` (apply_price_list → draft, chain list display), `ErpController` (updateClient price list → draft), `Access.php`, `routes/web.php`, views `erp/price_list`, `erp/group`, `erp/client`, `ops/invoice`, `Invoice`/`PurchaseOrder` models | wiring |
| tests: `tests/Feature/Reprice/SingleRepriceTest.php`, `PriceChangeEngineTest.php`, `PriceChangeFlowTest.php`, `PriceChangeLogTest.php` | |

---

### Task 1: Schema, models, Access keys, lang skeleton  *(parallel with Task 2)*

**Files:**
- Create: migration above, `app/Models/PriceChangeRun.php`, `app/Models/PriceChangeDoc.php`, `lang/ar/reprice.php`, `lang/en/reprice.php`, `tests/Feature/Reprice/PriceChangeSchemaTest.php`
- Modify: `app/Models/Invoice.php` (fillable + casts + `priceChangeRun()` belongsTo), `app/Models/PurchaseOrder.php` (fillable + relation), `app/Support/Access.php`

**Interfaces — Produces:**
- `price_change_runs`: `id`, `number` string(20) unique, `user_id` FK users nullOnDelete, `trigger` string(16) (`list|chain|branch|client|invoice`), `status` string(16) (`draft|applied|master_only|cancelled`), `price_list_id`/`group_id`/`client_id`/`invoice_id` nullable FKs nullOnDelete, `scope` string(16) (`list|chain|branch|client|invoice`), `since` date nullable, `payload` json, `item_changes` json nullable, `preview` json nullable, `summary` json nullable, `clients_count`, `invoices_changed`, `pos_changed`, `skipped_count`, `unchanged_count` unsigned int default 0, `before_net/before_tax/before_grand/after_net/after_tax/after_grand/ledger_diff` decimal(14,2) default 0, `error` string(250) nullable, `applied_at` dateTime nullable, `created_at`/`updated_at` dateTime nullable. Index (`status`,`created_at`).
- `price_change_docs`: `id`, `run_id` FK cascadeOnDelete, `doc_type` string(8) (`invoice|po`), `doc_id` unsignedBigInteger, `number` string(40), `client_id` FK nullable nullOnDelete, `doc_date` date nullable, `payment` string(10) nullable, `outcome` string(10) (`changed|skipped`), `reason` string(30) nullable, `reason_detail` string(190) nullable, `before_net/before_tax/before_grand/after_net/after_tax/after_grand` decimal(14,2) nullable, `lines` json nullable, `created_at` dateTime nullable. Index (`doc_type`,`doc_id`), (`run_id`,`outcome`).
- `invoices`: `repriced_at` dateTime nullable, `repriced_by` FK users nullable nullOnDelete, `price_change_run_id` FK price_change_runs nullable nullOnDelete. `purchase_orders`: `price_change_run_id` same.
- `PriceChangeRun` (`$table`, fillable all above, casts json→array, decimals, `applied_at`/`since` dates; `HasDocumentNumber` with prefix `PCR-` — follow how `Quotation`/`Contract` declare it; relations `user()`, `priceList()`, `group()`, `client()`, `invoice()`, `docs()`; `triggerLabel(): string` = `__('reprice.trigger_'.$this->trigger)`; `statusLabel()`; `isDraft(): bool`).
- `PriceChangeDoc` (`timestamps` handled: `const UPDATED_AT = null`), relations `run()`, `client()`; `reasonLabel(): ?string` = `__('reprice.skip_'.$this->reason, ['detail' => $this->reason_detail])`; `document()` returns Invoice|PurchaseOrder|null.
- Access: `ACTIONS['act.prices.propagate'] = ['perm.act_prices_propagate', 'erp.prices', [], ['erp.prices.change.apply', 'erp.prices.change.resync']]`; `'erp.price_changes'` added to the `accountant` SCREENS list; NAV entry `['erp.price_changes', '🧾', 'reprice.log_title', 'erp.price_changes*', null]` in the same NAV group as `erp.prices` (line ~616), right after it. `perm.act_prices_propagate` label in `lang/{ar,en}/perm.php`.
- `lang/{ar,en}/reprice.php` keys at least: `log_title` («سجل تعديلات الأسعار» / "Price change log"), `trigger_list|chain|branch|client|invoice`, `status_draft|applied|master_only|cancelled`, `skip_eta_locked|skip_consignment|skip_has_return|skip_not_priced|skip_closed_period|skip_po_cancelled|skip_po_delivered` («أمر توريد مُسلَّم — راجعه يدوي»)`|skip_po_fixed_price|skip_no_client`, `too_many`, `preview_stale`, `not_draft`. Later tasks append keys.

- [ ] **Step 1: failing test** `tests/Feature/Reprice/PriceChangeSchemaTest.php` (RefreshDatabase): `test_tables_and_columns_exist` asserts `Schema::hasTable` for both tables and `hasColumn` for the four added columns; `test_run_number_sequence` creates two runs → numbers `PCR-1001`, `PCR-1002`; `test_propagate_action_is_admin_only` → `Access::allows($manager,'erp.prices.change.apply') === false`, `Access::allows($admin, ...) === true`, `Access::allows($accountant,'erp.price_changes') === true`, `Access::allows($salesAgent,'erp.price_changes') === false`.
- [ ] **Step 2:** `php artisan test --filter=PriceChangeSchemaTest` → FAIL.
- [ ] **Step 3:** implement migration (guarded, `down()` drops FKs before columns), models, Access, lang.
- [ ] **Step 4:** test PASS; `php artisan test --filter=AccessTest` and `RoleAccessTest` still PASS; `php artisan promax:i18n-check` clean.
- [ ] **Step 5: commit** `feat(reprice): schema, models and access keys for price change runs`.

### Task 2: Extract the single-invoice and single-PO reprice into services (no behaviour change)  *(parallel with Task 1; most capable model)*

**Files:**
- Create: `app/Services/Reprice/InvoiceRepricer.php`, `app/Services/Reprice/PoRepricer.php`, `app/Services/Reprice/InvoiceGl.php`, `tests/Feature/Reprice/SingleRepriceTest.php`
- Modify: `app/Http/Controllers/OpsController.php` — `repriceInvoice` (~5245), `repostInvoiceGl` (~4964), `repricePoCore` (~2254), `fillPoItems` (~2574), `expectedPoTotals` (~2305)

**Interfaces — Produces:**
- `InvoiceGl::repost(Invoice $invoice, string $event): bool` — the exact body of today's `repostInvoiceGl`; OpsController's private method becomes a one-line delegate.
- `InvoiceRepricer::blocker(Invoice $inv): ?array` → `null` or `['key' => 'eta_locked'|'consignment'|'has_return', 'detail' => null]` (the three checks from `repriceInvoice`, same order; NOT the period check).
- `InvoiceRepricer::plan(Invoice $inv): array` — read-only. Loads `items.product`, `items.batch`, `client`; per item `Pricing::quote($client, $product, $batch, qty)`, `Tax::rate`, `Tax::on`. Returns `['not_priced' => ?string /*product display name*/, 'changed' => bool, 'before' => ['net','tax','grand'], 'after' => ['subtotal','discount','net','tax','grand','discount_pct','discount_source','price_list'], 'lines' => [['item_id','product_id','name','qty','old_list','old_price','old_total','old_tax','list_price','price','total','tax_rate','tax']]]`. `changed` per money-safety rule 5.
- `InvoiceRepricer::apply(Invoice $inv, array $plan, bool $adjustCollection, bool $recalc): void` — writes exactly what `repriceInvoice` writes today (items, invoice totals, `eta_status`, sale entry `debit`/`tax`; collection entry `credit`/`tax` only when `$adjustCollection`; `$client->recalculate()` only when `$recalc`; then `InvoiceGl::repost($inv,'repriceInvoice')`). Must be called inside a transaction (it opens its own `DB::transaction`, nesting is fine).
- `PoRepricer::fillItems(PurchaseOrder $po, Client $client, array $qtyByProduct, string $priceMode): void` — today's `fillPoItems` moved verbatim; OpsController's `fillPoItems` delegates.
- `PoRepricer::blocker(PurchaseOrder $po): ?array` → `po_cancelled` | `po_delivered` | `no_client` | `po_fixed_price` (`price_mode` in old/new) | null.
- `PoRepricer::plan(PurchaseOrder $po): array` — read-only, same arithmetic as `expectedPoTotals` (`$client->priceFor`, `Tax::on`, `Tax::totals`) per line; returns `['not_priced' => ?string, 'changed' => bool, 'before' => [...], 'after' => [...], 'lines' => [['product_id','name','qty','old_price','old_total','price','total']]]`. `expectedPoTotals` delegates to it.
- `PoRepricer::apply(PurchaseOrder $po): ?string` — today's `repricePoCore` body after its status checks (rebuild items via `fillItems`, `price_mode`, `was_edited`, `edited_by`, `edited_at`); returns the Rejected message or null. `repricePoCore` = its existing status checks + delegate (single PO button behaviour identical: delivered/cancelled still refused with `ops.po_reprice_locked`).
- `repriceInvoice` controller keeps every message and order: blocker → same `back()->withErrors` keys/messages; `PeriodGuard::assertOpen`; plan; `not_priced` → `ValidationException` `reprice` with `ops.reprice_not_priced`; apply(`adjustCollection: true`, `recalc: true`) even when unchanged (today's behaviour).

- [ ] **Step 1: characterization tests first (must pass on the untouched code):** `SingleRepriceTest`:
  - `test_credit_invoice_reprice_updates_items_totals_and_sale_entry` — client on list A (price 10), invoice 3 pcs at 8 created via `sellApi` or direct rows like `InvoiceTaxLedgerTest`; set list price 12; POST `ops.invoices.reprice` as admin → item price 12, invoice total 36, sale tx debit = grand_total, client balance == Σdebit−Σcredit.
  - `test_cash_invoice_single_reprice_moves_collection` — cash invoice; after reprice collection credit == new grand, balance 0.
  - `test_reprice_refused_for_eta_exported_consignment_and_return` — three cases, session has error `reprice`, invoice unchanged.
  - `test_single_po_reprice_pending_and_refused_when_delivered` — POST `ops.pos.reprice`: pending PO grand changes; delivered PO → error, unchanged.
- [ ] **Step 2:** run `php artisan test --filter=SingleRepriceTest` on the untouched code → PASS (characterization). If one fails, fix the test, not the code.
- [ ] **Step 3:** extract into the services as specified; controllers delegate.
- [ ] **Step 4:** `SingleRepriceTest` PASS, plus `php artisan test --filter="OpsLeadScopeTest|InvoiceTaxLedgerTest|PricingTest|CoreFlowsTest|Gl"` PASS. Add `test_plan_reports_unchanged_when_prices_equal` (plan on a just-repriced invoice → `changed === false`) and the same for PO.
- [ ] **Step 5: commit** `refactor(reprice): single invoice/PO reprice as services (no behaviour change)`.

### Task 3: The engine `PriceChange` (draft, preview by rollback, atomic apply, skips, idempotency)  *(after 1+2; most capable model)*

**Files:**
- Create: `app/Services/Reprice/PriceChange.php`, `tests/Feature/Reprice/PriceChangeEngineTest.php`
- Modify: `lang/{ar,en}/reprice.php`

**Interfaces — Consumes:** Task 1 models/columns; Task 2 `InvoiceRepricer`, `PoRepricer`.
**Produces:**
- `PriceChange::draft(User $by, string $trigger, array $payload, ?string $since = null): PriceChangeRun` — validates payload, captures `item_changes`, creates run `status=draft`, then `refreshPreview()`. Payload shapes:
  - `list`: `['price_list_id' => int, 'prices' => [product_id => float]]` (only rows that differ from the stored price; `item_changes` = `[[product_id, name, old (0 if none), new]]`).
  - `chain`: `['group_id' => int, 'price_list_id' => ?int]` (null = re-sync documents to current pricing, no master change).
  - `branch`: `['client_id' => int, 'price_list_id' => ?int, 'scope' => 'branch'|'chain']`.
  - `client`: `['client_id' => int, 'price_list_id' => ?int]`.
  - For list-assignment triggers `item_changes` = `[[client_id, name, old_list_id, old_list_name, new_list_id, new_list_name]]`.
- `PriceChange::refreshPreview(PriceChangeRun $run): array` — `DB::beginTransaction()`; `simulate()`; `DB::rollBack()` in `finally`; `PriceList::flushCache()`; stores `preview` (the result array minus per-doc rows beyond 300 — keep full counts) and the money columns on the draft; returns result.
- `PriceChange::apply(PriceChangeRun $run, User $by): array` — `DB::transaction`: re-load run `lockForUpdate`, must be `draft` else `Rejected(__('reprice.not_draft'))`; `simulate($run, persist: true)`; compare `totals.all` and changed/skipped counts with stored preview (0.005 tolerance) else `Rejected(__('reprice.preview_stale'))`; write `price_change_docs` rows (changed + skipped), mark invoices (`repriced_at`, `repriced_by`, `price_change_run_id`) and POs (`price_change_run_id`), set run `status=applied`, `applied_at`, `summary`, money columns. Returns result.
- `PriceChange::applyMasterOnly(PriceChangeRun $run, User $by): void` — transaction: draft check, apply master change only, `status=master_only`, no documents touched.
- `PriceChange::cancel(PriceChangeRun $run): void` — draft → cancelled.
- `PriceChange::recordSingle(Invoice $inv, User $by, array $plan): PriceChangeRun` — one applied run `trigger=invoice`, `scope=invoice`, one changed doc row, marks the invoice (used by the single button, Task 4).
- `PriceChange::chainListOf(ClientGroup $g): array` → `['list' => ?PriceList, 'source' => 'contract'|'branches'|'none', 'count' => int /*branches on it*/, 'total' => int]` — live chain contract's `priceListRow` if active, else the most common `price_list_id` among the chain's branches.
- Result array shape (preview and apply): `['clients' => int, 'invoices_changed' => int, 'pos_changed' => int, 'unchanged' => int, 'skipped' => int, 'totals' => ['invoice' => T, 'po' => T, 'all' => T], 'ledger_diff' => float /* = invoice grand diff */, 'docs' => [doc rows as in price_change_docs], 'item_changes' => [...]]` where `T = ['before_net','before_tax','before_grand','after_net','after_tax','after_grand','diff_net','diff_tax','diff_grand']`.

**Algorithm of `simulate(PriceChangeRun $run, bool $persist)` (private):**
1. `set_time_limit(300)` when available. Apply master change: list → upsert `price_list_items` for each `prices` row and the reverse write to `products.price_old|price_new` when the list code is old/new (move today's `savePrices` loop body into `private static writeListPrices(PriceList, array)` and have `PriceListController` call it in Task 4); chain/branch-scope-chain → `Client::visibleTo($group->clients(), $by)->update(['price_list_id'=>, 'price_list'=> code])` (same two columns as `GroupController::update`); branch/client → that client's two columns (code column rule as `ErpController` 1776: only `old`/`new` codes go into `price_list`, else leave it — copy that rule). Then `PriceList::flushCache()`.
2. Clients in scope (fresh queries, `with(['contract','group.contract','priceListRow'])`, through `Client::visibleTo($q, $by)`): list → every client whose `Pricing::listRowFor($c)?->id === list id`; chain or branch-scope-chain → all branches; branch/client → that client.
3. Documents: invoices of those clients (`created_at >= since` when set) + POs of those clients; count first, > 5000 → `Rejected(__('reprice.too_many', ['n'=>…]))`. Iterate `chunkById(200)`.
4. Invoice: `InvoiceRepricer::blocker` → skip; `PeriodGuard::isClosed($inv->created_at)` → skip `closed_period` (detail `PeriodGuard::label`); `plan`; `not_priced` → skip; `!changed` → unchanged++; else `apply($inv, $plan, adjustCollection: false, recalc: false)`, record before (plan.before) / after (fresh invoice) and lines.
5. PO: `PoRepricer::blocker` → skip; `plan`; `not_priced` → skip; `!changed` → unchanged; else `apply` (string error → skip with `not_priced`/message), before/after from fresh PO.
6. `recalculate()` once per client that had a changed invoice.

- [ ] **Step 1: failing tests** `PriceChangeEngineTest` (RefreshDatabase, helpers from `Tests\TestCase`; build invoices + sale/collection transactions the same way `SingleRepriceTest` does; assert every client balance `== Σdebit − Σcredit` in `assertLedgerReconciles()` helper):
  - `test_list_trigger_reprices_every_client_on_the_list` — clients X,Y on list A, Z on list B; invoices for all three; draft list A price 10→12; apply → X,Y invoices repriced (`price_change_run_id` set), Z untouched; run `invoices_changed=2`, `clients_count=2`; ledger reconciles.
  - `test_preview_totals_equal_apply_totals` — preview `totals.all` == apply `totals.all` and == run columns.
  - `test_preview_writes_nothing` — snapshot `price_list_items`, `invoices`, `transactions`, `clients.balance` before/after `refreshPreview` → identical; `Pricing::priceForClient` returns old price after preview.
  - `test_chain_trigger_moves_all_branches_and_reprices` — group with 2 branches on A; chain payload list B → both branches `price_list_id=B`, their invoices at B prices.
  - `test_branch_trigger_branch_only_vs_whole_chain` — scope branch: only that branch changes; scope chain: both.
  - `test_client_trigger_cash_van_without_chain`.
  - `test_cash_invoice_difference_lands_on_ledger` — cash invoice 100 (sale 100 + collection 100, balance 0); price down so grand 80 → sale debit 80, collection credit still 100, balance −20, reconciles; price up variant → +diff.
  - `test_skipped_documents_have_reasons` — ETA exported, consignment, has return, closed period (create `GlPeriod` with status closed for the invoice month — see `tests/Feature/Gl` for how), not priced, delivered PO (reason `po_delivered`), cancelled PO, fixed-price PO; each appears as a skipped doc row with its reason and is unchanged in DB.
  - `test_pending_po_repriced_delivered_po_skipped` — pending PO grand changes, `was_edited` true, `price_change_run_id` set; no transaction rows created for it.
  - `test_idempotent_rerun_changes_nothing` — after apply, a chain re-sync draft (`price_list_id` null) → preview `invoices_changed=0`, `pos_changed=0`, apply leaves `invoices.updated_at` and `price_change_run_id` unchanged.
  - `test_apply_twice_is_rejected` — second `apply` on same run → `Rejected` (`not_draft`), no double effect.
  - `test_stale_preview_is_rejected` — preview, then change a price directly, then apply → `Rejected` (`preview_stale`), run still draft, nothing changed.
  - `test_master_only_changes_prices_not_documents`.
  - `test_atomic_failure_rolls_back_everything` — force an exception mid-run (e.g. bind a fake `InvoiceGl`/use a closure hook, or make the second invoice's client deleted) and assert no item/price changed.
- [ ] **Step 2:** run → FAIL.
- [ ] **Step 3:** implement.
- [ ] **Step 4:** engine tests PASS; Task 2 tests PASS.
- [ ] **Step 5: commit** `feat(reprice): price change engine with rollback preview and atomic apply`.

### Task 4: Wire the triggers, the preview/confirm page and the single-invoice log entry  *(after 3; most capable model)*

**Files:**
- Create: `app/Http/Controllers/PriceChangeController.php`, `resources/views/erp/price_change.blade.php`, `tests/Feature/Reprice/PriceChangeFlowTest.php`
- Modify: `routes/web.php` (next to the `/prices` group ~217), `PriceListController::savePrices/bulk`, `GroupController::update` (apply_price_list block ~517) and `show` (pass `chainList`), `ErpController::updateClient` (+ `client()` passes chain info), `OpsController::repriceInvoice` (call `PriceChange::recordSingle` after apply), views `erp/price_list.blade.php`, `erp/group.blade.php`, `erp/client.blade.php`, lang.

**Routes (all under the existing `erp.` web group, `auth`):**
- `GET  /prices/changes/{run}` → `show` name `erp.prices.change` (role:admin,manager,branch_manager; admin or run creator else 403)
- `POST /prices/changes/{run}/refresh` → `refresh` (`scope` in branch|chain for branch trigger, `since` nullable date) name `erp.prices.change.refresh` (same gate)
- `POST /prices/changes/{run}/apply` → `apply` name `erp.prices.change.apply` (role:admin — covered by `act.prices.propagate`)
- `POST /prices/changes/{run}/master` → `master` name `erp.prices.change.master` (admin or creator)
- `POST /prices/changes/{run}/cancel` → `cancel` name `erp.prices.change.cancel` (admin or creator)
- `POST /prices/changes/resync` → `resync` (`trigger` chain|client, `id`) name `erp.prices.change.resync` (role:admin — `act.prices.propagate`)
All controller actions catch `App\Exceptions\Rejected` → `back()->withErrors(['reprice' => msg])`.

**Trigger wiring:**
- `savePrices`/`bulk`: compute the target rows exactly as today (bulk's price arithmetic unchanged) but collect `[product_id => price]` of rows that differ; none → today's "nothing changed" flash; else `PriceChange::draft($user,'list',…)` → redirect `erp.prices.change`. The write loop moves to `PriceChange::writeListPrices` (used by apply/master).
- `GroupController::update`: other fields/apply_* keep working as today; `apply_price_list` no longer writes — creates a `chain` draft and redirects to it with the other messages flashed. `show` passes `PriceChange::chainListOf($group)`; `erp/group.blade.php` shows «قايمة أسعار السلسلة: X (N من M فرع)» and, for `act.prices.propagate`, a button «🏷 طبّق التسعيرة الحالية على مستندات الفروع» (POST resync chain) + link to the log filtered `?group_id=`.
- `ErpController::updateClient`: if submitted `price_list_id` differs from the client's current `price_list_id`, strip `price_list_id` and `price_list` from the saved fields, save the rest as today, then draft `branch` (client has `group_id`) or `client`, redirect to preview with `flash.client_saved`. Client page: re-sync button (POST resync client) for propagate holders + log link `?client_id=`.
- `repriceInvoice`: after the successful apply, `PriceChange::recordSingle(...)`; flash unchanged.

**Preview page `erp/price_change.blade.php`** (layout `layouts/system`, `.kpis`, `.card`, `.tablewrap`, `.num`, `.badge`, `.btn`): header with run number, trigger, scope, who/when, status badge; for `branch` trigger when the new list ≠ `chainListOf(group)` list: `.alert.warn` «القايمة دي مختلفة عن قايمة السلسلة (X) — تطبّقها على الفرع ده بس ولا على كل فروع السلسلة (N)؟» with two radio options posting to refresh; `since` date filter (refresh); KPIs: clients, invoices to change, POs to change, unchanged, skipped, ledger effect; totals table rows invoices / POs / total × before net/tax/grand, after net/tax/grand, diff (`.pos/.neg`); item changes table (product old→new, or client old list→new list); documents table (number linked to `ops.invoice` / `ops.po`, client, date, payment, before grand, after grand, diff) capped 300 + «+N»; skipped table with reason labels. Buttons while draft: «✅ تأكيد — طبّق على المستندات» (only when `Access::allows($user,'erp.prices.change.apply')`, JS confirm with the totals), «💾 احفظ الأسعار للبيع الجديد بس» (master), «✖ إلغاء» (cancel). After apply: same page, status applied, documents from `run->docs`, link «سجل تعديلات الأسعار» (`erp.price_changes.show`).

- [ ] **Step 1: failing tests** `PriceChangeFlowTest`:
  - `test_save_prices_creates_draft_and_changes_nothing` — admin POST `erp.prices.save` → redirect to `erp.prices.change`; list price unchanged; run draft.
  - `test_admin_confirm_applies_prices_and_documents` — POST apply → price saved, invoice repriced, page shows applied.
  - `test_manager_can_save_master_only_but_not_apply` — manager: apply 403; master 302 and price saved; invoice untouched.
  - `test_accountant_and_sales_cannot_create_or_apply` — accountant POST `erp.prices.save` 403, POST resync 403.
  - `test_other_users_draft_is_403` — manager B GET manager A's draft → 403.
  - `test_chain_update_apply_price_list_goes_through_preview` — branches unchanged until apply; after apply all branches on new list.
  - `test_client_update_holds_only_price_list` — PUT `erp.clients.update` with new name + new list → name saved, list not; draft `branch`/`client`.
  - `test_branch_preview_asks_branch_or_chain` — page contains the warn text when list ≠ chain list; refresh with scope chain → clients_count = branches.
  - `test_single_invoice_reprice_logs_a_run` — POST `ops.invoices.reprice` → one run trigger invoice applied with 1 changed doc; invoice `price_change_run_id` set.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** flow + engine + single tests PASS; `php artisan test --filter="ClientFlowTest|ClientFormIntegrityTest|PricingTest|ChainExportTest|RoleAccessTest|AccessTest"` PASS (update any existing test that asserted the old direct write of `price_list_id`/list prices to go through `master` — note each such change in the commit message).
- [ ] **Step 5: commit** `feat(reprice): price list, chain, branch and client triggers with money preview`.

### Task 5: «سجل تعديلات الأسعار» log screen, export, drill-down, links  *(after 4; standard model)*

**Files:**
- Create: `app/Http/Controllers/PriceChangeLogController.php`, `resources/views/erp/price_changes.blade.php`, `resources/views/erp/price_change_run.blade.php`, `resources/views/erp/price_change_doc.blade.php`, `tests/Feature/Reprice/PriceChangeLogTest.php`
- Modify: `routes/web.php`, `resources/views/ops/invoice.blade.php`, `erp/price_list.blade.php`, `erp/group.blade.php`, `erp/client.blade.php`, lang.

**Routes:** `GET /price-changes` `erp.price_changes` (role:admin,accountant), `GET /price-changes/{run}` `erp.price_changes.show`, `GET /price-changes/{run}/docs/{doc}` `erp.price_changes.doc` (404 if doc not in run).

**Index:** `DateRange::fromRequest($request)` on `created_at` (`@include('partials._range', …)`), filters `trigger`, `status` (default excludes `cancelled` drafts? — show all, filter available), `price_list_id`, `group_id`, `client_id`, `invoice` (number → runs having a doc with that number); KPIs over the filtered set (runs, invoices changed, POs changed, ledger diff); table: number (link), date, user, trigger, scope target name, status, invoices changed, POs changed, skipped, before grand, after grand, diff, ledger diff; `.pag` pagination 50; `?export=1` → `App\Support\Csv` (BOM) with the same columns over the full filtered set (follow `ActivityController` export usage).
**Run page:** header (who/when/trigger/scope/status/since), item changes table (old→new per item, or per client list change), totals table (invoice/PO/all × before/after/diff net/tax/grand), changed docs table (link to drill-down + to the document), skipped docs with reason labels.
**Doc page:** lines table: product, qty, old list/price/total/tax → new list/price/total/tax, and before/after net/tax/grand; link to the invoice/PO.
**Links:** invoice page: when `price_change_run_id` set, `.badge.b-purple` «🏷 اتعدلت بتعديل أسعار PCR-xxxx» linking to the doc drill-down (visible to roles allowed `erp.price_changes`), plus a small link «سجل تعديلات الأسعار» `?invoice=number`; price list page link `?price_list_id=`; chain page `?group_id=`; client page `?client_id=` (all guarded by `Access::allows(auth()->user(),'erp.price_changes')`).

- [ ] **Step 1: failing tests** `PriceChangeLogTest`: `test_admin_and_accountant_see_log`, `test_manager_and_sales_get_403`, `test_export_returns_csv_with_bom` (status 200, content starts with "\xEF\xBB\xBF", contains run number), `test_date_range_filters_runs` (run dated last month excluded by this-month range), `test_run_and_doc_pages_show_before_after`, `test_invoice_page_shows_repriced_badge`, `test_doc_from_other_run_404`.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS, `promax:i18n-check` clean, `tests/Feature/ScreenScriptsTest.php` and `BladePhpBlockTest.php` PASS.
- [ ] **Step 5: commit** `feat(reprice): price change log screen with export and drill-down`.

### Task 6: Verification (lead)

- [ ] `php artisan test` full suite green on promax_test_13 (record counts).
- [ ] `python C:\xampp\htdocs\ProMax\promax-skills\promax-feature-dev\scripts\static_check.py --erp .` clean (or only known noise).
- [ ] `php artisan promax:i18n-check` clean.
- [ ] promax-flow-guard: re-run the flows the impact matrix names for OpsController/Pricing/clients (cash-van sale, PO delivery, client pricing, client returns) via the existing tests.
- [ ] Whole-branch review subagent (most capable model) on `git diff integration...close-13-reprice`.
- [ ] QA: `php artisan migrate --env=qa`; in the browser on 8010 (worktree served `--env=qa`) a dry preview only (draft + cancel).
- [ ] Demo: `php artisan migrate --env=demo`; serve worktree `--env=demo --port=8048`; one real run (record list / chain / branch / cash-van client used).

### Task 7: Integrate and hand over (lead)

- [ ] Merge `close-13-reprice` into `integration` with `--no-ff` in `_wt\erp-integration`; full suite there on `promax_test_10`.
- [ ] `C:\xampp\htdocs\ProMax\PROMAX_UPDATE_2026-10-24_REPRICE.md` (Arabic): what's new, the three flows, migration, server commands (Ahmed's master has the local commit 486ca9d `.gitignore` + a dirty `system.blade.php` → stash-safe commands), the money-safety rules.
- [ ] `LANE_REPORTS_closeout\LANE_13.md`, one Arabic bullet in `HANDOFF_LIVE.md`.
