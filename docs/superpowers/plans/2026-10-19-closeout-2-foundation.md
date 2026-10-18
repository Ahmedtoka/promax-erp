# Lane 2 — Foundation (cost centers, product kinds, safes) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the three shared foundations every later close-out lane builds on — cost centers on GL lines, product kinds with a sellable scope applied to every sale surface, and the safes register — with the exact interfaces fixed in the master plan.

**Architecture:** Additive guarded migrations; two new models (`CostCenter`, `Safe`); one resolver service (`App\Services\Gl\CostCenterResolver`); `Ledger` gains one persisted column; `Product::scopeSellable` gains the kind filter so every existing `sellable()` / `isSellable()` / `SellableProduct` caller inherits it, and every sale listing still using a raw `where('active', true)` is switched to `sellable()`. One idempotent artisan command seeds centers and safes.

**Tech Stack:** Laravel 12, MariaDB 10.4, PHPUnit feature tests on `promax_test_2`.

**Spec:** `docs/superpowers/specs/2026-10-19-promax-closeout-design.md` §2, §3.2, §3.5 (raw-material store), §4.1 (cost center dimension, `cash_main`/`bank`/`rep_cash`); master plan `docs/superpowers/plans/2026-10-19-closeout-master.md` → "Interfaces fixed now".

## Global Constraints

(binding, copied from the master)
- Doctrine in `promax-system` is binding. GL writes only through `App\Services\Gl\Ledger`. Prices only through `App\Services\Pricing`. Money writes in `DB::transaction`.
- Every migration guarded (`hasTable` / `hasColumn`). Time columns `dateTime()`, never `timestamp()` — so `created_at`/`updated_at` are `dateTime(...)->nullable()` (Laravel's `timestamps()` emits `timestamp`; the 17/10 idempotency migration set this pattern).
- Every UI string in `lang/ar` and `lang/en`, same key; `php artisan promax:i18n-check` clean.
- DB `promax` read-only. Tests on `promax_test_2`. QA checks on `promax_qa` (`--env=qa`). Never port 8020. Never start/stop MySQL.
- Repo is public: no client data, passwords, lane reports in commits.
- Additive only. One legal company, EGP only (`currency` fixed to `EGP`).
- **Names below are fixed by the master. Do not rename.**

## Review Focus

1. **(Master line 4 — owned here) A raw-material or packaging product never appears where finished goods are sold:** catalog API, client prices API, price lists, van load/handout, pick edit, online product links, invoices (app + manual + edit lines), replenishment, goods requests, PO handout. Pinned by `tests/Feature/SellableKindTest.php` (Tasks 2–3), one test per surface.
2. **Receipts and transfers still accept raw materials** (purchasing/raw-material store must keep working): `test_receipt_accepts_a_raw_material` (Task 1).
3. **Seed run twice changes nothing; dry-run writes nothing:** `test_seed_twice_creates_nothing_the_second_time`, `test_dry_run_writes_nothing` (Task 7).
4. **No Maadi warehouse/branch in the data** (true on local live copy: all five warehouses are `factory`, `branches` empty): seed must still create `MAADI`, link nothing silently, and print what is unlinked: `test_seed_without_a_maadi_warehouse_creates_the_center_unlinked` (Task 7).
5. **Reversal / closed-period correction keeps the cost center of the line it copies:** `test_reverse_keeps_cost_center` (Task 4).

## Decisions made by Lane 2 (owner asleep; recorded in the lane report too)

- **D1 `scopeSellable` keeps `active`.** It already existed as `where('active', true)` (17/8 draft rule) with ~15 callers. New body: `where('active', true)->where('kind', 'finished')`. `isSellable()` mirrors it. Satisfies the master (`where('kind','finished')`) without re-opening the draft-product hole.
- **D2 `SellableProduct` rule** gains `public function __construct(public bool $anyKind = false)`. Warehouse receipt and transfer (`WarehouseController::storeReceipt` / `storeTransfer`) pass `anyKind: true` — they move stock, not sell it, and Lane 5's raw-material store needs them. Their product pickers keep `where('active', true)`.
- **D3 Not filtered (not sale surfaces):** supplier orders (`SupplierController`), warehouse receipts/transfers, warehouse stock admin (`WarehouseAdminController::stock`), product admin list (`ErpController::products`), reports that look up products by id, AI inventory tool, console importers.
- **D4 `warehouses.cost_center_id`** (nullable FK) is added, because the master links `FAC` to *every* factory warehouse and `cost_centers.warehouse_id` holds one. `forWarehouse()` order: `warehouse.cost_center_id` → center with `warehouse_id = w.id` → `branch` center with `branch_id = w.branch_id` → `null`.
- **D5 `company()` / `maadi()` never return null:** `firstOrCreate` on code `CO` / `MAADI` (MAADI parent = CO, kind `branch`).
- **D6 Production lines are not seeded** — Lane 6 owns production lines; `lines()` is ready for them.
- **D7 Maadi resolution in the seed:** warehouse with code `MAADI`, else `--maadi-warehouse=CODE`; branch with code `MAADI` (if any). Factory warehouses = every `type=factory` warehouse except the Maadi one, or `--factory=CODE,CODE` to restrict. On the live copy all warehouses are `factory` and finished goods sit in `WHK`, so the owner should run with `--maadi-warehouse=WHK` (or fix in wizard step 3) — otherwise sales from WHK resolve to `FAC`. Reported, not guessed.
- **D8 `Safe::balance()`** = Σ(debit − credit) on `gl_lines` of `gl_account_id` for entries dated ≤ `asOf` (cash/bank are debit-natured). `gl_account_id` null → `0.0`. The seed fills `gl_account_id` from `GlAccount` `system_key` `cash_main` / `bank` when present (only fills nulls). Lane 4 owns movements.
- **D9 Resolver "sales and collections" fallback** applies to: `Transaction` kinds `sale, collection, return, refund, rebate, settlement, taxded`, and sources `Invoice`, `PurchaseOrder`, `ClientReturn`, `RepSettlement`. Not to supplier, expense or cash movements (they must carry their own center — Lane 4).
- **D10 Resolver warehouse discovery:** source `warehouse_id` → `custody_id`→custody warehouse → `Invoice`: first item's batch warehouse → `Transaction`: its `source` (one hop). Employee step: `employee_id` on the source when `App\Models\Employee` exists and has `cost_center_id` (Lane 7 will add it); otherwise skipped.
- **D11 Product form** gets a `kind` select (new + edit) so raw/packaging items can be created from the UI.

## File map

- Create: `database/migrations/2026_10_19_000200_products_kind.php`, `..._000210_cost_centers.php` (cost_centers + `warehouses.cost_center_id`), `..._000220_gl_lines_cost_center.php`, `..._000230_safes.php`
- Create: `app/Models/CostCenter.php`, `app/Models/Safe.php`, `app/Services/Gl/CostCenterResolver.php`, `app/Services/Gl/FoundationSeed.php`, `app/Console/Commands/CostCentersSeed.php`
- Modify: `app/Models/Product.php`, `app/Rules/SellableProduct.php`, `app/Models/Warehouse.php`, `app/Models/Gl/GlLine.php`, `app/Services/Gl/Ledger.php`, sale controllers listed in Tasks 2–3, `resources/views/erp/_product_form.blade.php`, `app/Http/Controllers/ErpController.php`, `lang/{ar,en}/stock.php`, `lang/{ar,en}/gl.php`
- Tests: `tests/Feature/ProductKindTest.php`, `tests/Feature/SellableKindTest.php`, `tests/Feature/Gl/CostCenterTest.php`, `tests/Feature/Gl/SafeTest.php`, `tests/Feature/Gl/CostCentersSeedTest.php`

---

### Task 1: `products.kind`, the sellable scope and the rule

**Interfaces — Produces:**
- Column `products.kind` varchar(20) NOT NULL default `'finished'`.
- `Product::KINDS = ['finished','raw','packaging']`, `'kind'` in `$fillable`.
- `Product::scopeSellable($q)` → `where('active', true)->where('kind','finished')` (D1); `Product::isSellable(): bool` same rule.
- `Product::kindLabel(): string` → `__('stock.product_kind_'.$kind)` (`stock.kind` already means transfer direction).
- `App\Rules\SellableProduct::__construct(bool $anyKind = false)`; raw/packaging fails with `__('api.product_not_for_sale', ['product' => name])` unless `anyKind`.

- [ ] **Step 1: failing tests** `tests/Feature/ProductKindTest.php`:
  - `test_new_products_are_finished_by_default` → `makeProduct()->fresh()->kind === 'finished'`
  - `test_sellable_excludes_raw_packaging_and_drafts` → 4 products (finished, raw, packaging, finished inactive); `Product::sellable()->pluck('id')` == [finished id]
  - `test_is_sellable_false_for_raw` 
  - `test_sellable_rule_rejects_raw_with_the_not_for_sale_message` → `Validator::make(['p'=>$raw->id], ['p'=>[new SellableProduct]])` fails, message contains raw name
  - `test_any_kind_rule_accepts_raw_but_still_rejects_drafts`
  - `test_receipt_accepts_a_raw_material` → admin POST `wh.receipts.store` with a raw line → no `lines.0.product_id` error (Review Focus 2)
  - `test_product_form_saves_kind` → admin POST `erp.products.store` with `kind=raw` → row kind raw; invalid `kind=xyz` → session error `kind`
- [ ] **Step 2:** `php artisan test --filter=ProductKindTest` → FAIL (column missing).
- [ ] **Step 3:** migration (guarded), model, rule, `WarehouseController` receipt/transfer `new SellableProduct(anyKind: true)`, `ErpController::productRules` `'kind' => ['sometimes','required', Rule::in(Product::KINDS)]` + saved on store/update, form select, lang keys `stock.product_kind`, `stock.product_kind_finished|raw|packaging|hint`, `api.product_not_for_sale` (ar+en).
- [ ] **Step 4:** filter → PASS; `--filter="PricingTest|UnitsOfMeasureTest|CoreFlowsTest"` still green.
- [ ] **Step 5: commit** `feat(products): kind (finished/raw/packaging) and sellable scope filters it`.

### Task 2: sellable on the mobile/API sale surfaces

**Interfaces — Consumes:** Task 1 scope/rule. Test helper (private, in the test): `raw()` = `makeProduct(['kind'=>'raw'])`, `finished()`.

Surfaces (grep of `Product::where('active', true)` / `isSellable` / `SellableProduct` in `app/Http/Controllers/Api`):

| # | Surface | Code | Change | Test in `SellableKindTest` |
|---|---|---|---|---|
| A1 | `GET /api/clients/{c}/catalog` | `FieldApiController::catalog` | `Product::sellable()` | `test_catalog_api_excludes_raw` |
| A2 | `GET /api/clients/{c}/prices` | `clientPrices` (isSellable) | inherited | `test_client_prices_api_excludes_raw_in_custody` |
| A3 | `GET /api/clients/{c}/returnable` | `returnable` | `Product::sellable()` | `test_returnable_api_excludes_raw` |
| A4 | bootstrap custody items | `bootstrap` (isSellable) | inherited | covered by A2 helper (same filter) |
| A5 | `POST /api/invoices` | `storeInvoice` (rule) | inherited | `test_app_invoice_with_raw_is_refused` (422 `items.0.product_id`) |
| A6 | `POST /api/goods-requests` | `storeGoodsRequest` (rule) | inherited | `test_goods_request_with_raw_is_refused` |
| A7 | promoter products list | `PromoterApiController::bootstrap` (line 149) | `Product::sellable()` | `test_promoter_bootstrap_excludes_raw` |
| A8 | `POST /api/visits/{v}/replenishment` | promoter rule (line 439) | inherited | `test_replenishment_request_with_raw_is_refused` |
| A9 | PO deliver / gifts / returns API | rule | inherited | (same rule as A5 — no extra test) |

- [ ] **Step 1:** write the A-tests (each creates one finished + one raw product, asserts raw id absent / 422).
- [ ] **Step 2:** run `--filter=SellableKindTest` → A1, A3, A7 FAIL; A2/A5/A6/A8 pass already through Task 1 (they prove inheritance).
- [ ] **Step 3:** switch A1, A3, A7 to `Product::sellable()`.
- [ ] **Step 4:** filter → PASS; `--filter="ApiContractTest|MobileIdempotencyTest"` green.
- [ ] **Step 5: commit** `feat(products): raw/packaging never reach the app sale surfaces`.

### Task 3: sellable on the web sale surfaces and online links

| # | Surface | Code | Change | Test |
|---|---|---|---|---|
| W1 | price list index/show counts & rows, bulk | `PriceListController` 31/47/73/215, `PriceList::missing()` | `Product::sellable()` | `test_price_list_screen_excludes_raw` |
| W2 | client card priced table | `ErpController::client` 'priced' | `sellable()` | `test_client_card_priced_excludes_raw` |
| W3 | van handout picker + store | `CustodyHandoutController::index/store` | `sellable()`; store rejects non-sellable keys with `stock.not_for_sale` | `test_handout_screen_excludes_raw`, `test_handout_store_refuses_raw` |
| W4 | pick edit picker + update | `PickOrderController::show/update` | same | `test_pick_screen_excludes_raw` |
| W5 | replenishments screen picker | `ChannelController::replenishments` | `sellable()` (assign already whitelists via sellable) | `test_replenishment_screen_excludes_raw` |
| W6 | PO handout picker + edit | `OpsController::poHandoutView`, `updatePo` | same guard as W3 | `test_po_handout_excludes_raw` |
| W7 | invoice edit lines | `OpsController::editInvoiceItems` | `Rule::exists('products','id')->where('kind','finished')` | `test_edit_invoice_items_refuses_raw` |
| W8 | returns screen picker | `ReturnController::index` | `sellable()` | `test_returns_screen_excludes_raw` |
| W9 | online sync + products picker | `OnlineOrderController::sync` / `products` | `sellable()` | `test_online_products_screen_excludes_raw` |
| W10 | online link writes | `productsSave`, `productsBundle`, `itemLink` | `Rule::exists(...)->where('kind','finished')` | `test_online_link_to_raw_is_refused` |
| W11 | Shopify SKU auto-match | `ShopifyOnline` line 293 | `->where('kind','finished')` | `test_shopify_sku_never_matches_raw` |
| W12 | manual doc / quotation / KPI focus / ops adjust | already `sellable()` | inherited | `test_manual_doc_screen_excludes_raw` |

Guard helper: `Product::firstNotSellable(array $ids): ?Product` (first id among `$ids` that exists and is not `isSellable()`); W3/W4/W6 return `back()->withErrors(['qty' => __('stock.not_for_sale', ['name' => …])])` when it is non-null, before any write.

- [ ] **Step 1:** W-tests. Screens: GET as admin, `assertDontSee($raw->code)` + `assertSee($finished->code)` (codes are unique per test).
- [ ] **Step 2:** filter → the changed surfaces FAIL, W12 passes (inheritance).
- [ ] **Step 3:** apply the changes in the table; lang `stock.not_for_sale` (ar+en).
- [ ] **Step 4:** `--filter="SellableKindTest|Online|PickReplanTest|CustodyTest|ReturnsCycleTest|RoleAccessTest"` green.
- [ ] **Step 5: commit** `feat(products): raw/packaging never reach web sale screens or online links`.

### Task 4: cost centers + `gl_lines.cost_center_id` + Ledger persistence

**Interfaces — Produces (fixed by master):**
- Table `cost_centers`: `id`, `code` unique (varchar 20), `name`, `name_en` nullable, `parent_id` nullable self-FK nullOnDelete, `kind` varchar(20), `branch_id` nullable FK, `warehouse_id` nullable FK, `active` bool default true, `created_at`/`updated_at` dateTime nullable.
- `warehouses.cost_center_id` nullable FK (D4).
- `App\Models\CostCenter` (`HasBilingualName`): `KINDS = ['company','factory','line','branch']`; `company(): self`; `forWarehouse(?Warehouse $w): ?self`; `maadi(): self`; `lines(): HasMany` (children, `kind='line'`); plus `parent(): BelongsTo`, `children(): HasMany`.
- `gl_lines.cost_center_id` nullable FK nullOnDelete; `GlLine` fillable + `costCenter(): BelongsTo`.
- `Ledger::writeLines` persists `$l['cost_center_id'] ?? null`; `writeEntry` maps it; `reverse()` and `overrideAccount()` (closed-period branch) copy `cost_center_id` from the original line.

- [ ] **Step 1: failing tests** `tests/Feature/Gl/CostCenterTest.php`:
  - `test_company_is_a_single_root` → `company()` twice → same id, `parent_id` null, count of `CO` = 1
  - `test_maadi_is_a_branch_under_company`
  - `test_for_warehouse_prefers_own_then_center_row_then_branch` (three warehouses, three expectations, unknown → null, null → null)
  - `test_lines_are_line_children_only`
  - `test_post_persists_cost_center_from_rule_lines` → bind a `Rules` subclass returning two lines with `cost_center_id` → `Ledger::post($tx)` → lines carry it
  - `test_manual_entry_persists_cost_center`
  - `test_reverse_keeps_cost_center` (Review Focus 5)
- [ ] **Step 2:** `--filter=CostCenterTest` → FAIL.
- [ ] **Step 3:** migrations `000210`, `000220`, model, Ledger edits.
- [ ] **Step 4:** `--filter="CostCenterTest|Gl"` green (all existing GL tests).
- [ ] **Step 5: commit** `feat(gl): cost centers and gl_lines.cost_center_id`.

### Task 5: `CostCenterResolver`

**Interfaces — Produces:** `App\Services\Gl\CostCenterResolver::for(Model $source): ?int` (static), order per master + D9/D10. Consumes Task 4.

- [ ] **Step 1: failing tests** (in `CostCenterTest`):
  - `test_resolver_uses_the_source_warehouse_center` (PurchaseOrder with `warehouse_id` linked to FAC → FAC id)
  - `test_resolver_follows_invoice_batch_warehouse` (Invoice item batch in a linked warehouse)
  - `test_resolver_follows_transaction_source`
  - `test_sales_without_a_warehouse_fall_back_to_maadi` (bare `sale` and `collection` Transaction → `maadi()->id`)
  - `test_supplier_and_expense_without_warehouse_resolve_null`
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS.
- [ ] **Step 5: commit** `feat(gl): CostCenterResolver`.

### Task 6: safes

**Interfaces — Produces (fixed):** table `safes` (`id`, `code` unique varchar 20, `name`, `name_en` nullable, `kind` varchar(10) ∈ `cash|bank`, `branch_id` nullable FK, `cost_center_id` nullable FK, `currency` char(3) default `EGP`, `custodian_user_id` nullable FK users, `gl_account_id` nullable FK gl_accounts, `bank_name` nullable, `account_no` nullable varchar(60), `active` bool default true, dateTime timestamps). `App\Models\Safe`: `KINDS = ['cash','bank']`, `LEGACY = ['cash_main' => 'MAADI-MAIN', 'bank' => 'BANK-1']`, `forLegacy(string $paidFrom): ?self`, `balance(?Carbon $asOf = null): float` (D8), relations `branch`, `costCenter`, `custodian`, `glAccount`.

- [ ] **Step 1: failing tests** `tests/Feature/Gl/SafeTest.php`:
  - `test_for_legacy_maps_cash_main_and_bank_and_not_rep_cash` (after seeding via `FoundationSeed`)
  - `test_balance_sums_gl_lines_up_to_the_date` (manual entries 1000 Dr cash on 09-01, 300 Cr cash on 09-10 → balance(09-05)=1000, balance()=700)
  - `test_balance_is_zero_without_a_gl_account`
- [ ] **Step 2–4:** FAIL → implement migration + model → PASS.
- [ ] **Step 5: commit** `feat(cash): safes register and Safe model`.

### Task 7: seed command `promax:cost-centers-seed`

**Interfaces — Produces:** `App\Services\Gl\FoundationSeed::run(bool $dryRun = false, ?string $maadiWarehouse = null, ?array $factoryCodes = null): array{created:list<string>, linked:list<string>, unlinked:list<string>}` — everything in one `DB::transaction`; dry-run rolls back. Command `promax:cost-centers-seed {--dry-run} {--maadi-warehouse=} {--factory=}` prints the three lists and returns SUCCESS. Creates `CO` (company), `FAC` (factory, parent CO), `MAADI` (branch, parent CO, `branch_id` of branch `MAADI` if any, `warehouse_id` of Maadi warehouse if resolved); sets `warehouses.cost_center_id` = FAC for factory warehouses and MAADI for the Maadi warehouse **only when null**; safes `MAADI-MAIN` (cash, CO→MAADI center, branch of MAADI, gl `cash_main`) and `BANK-1` (bank, gl `bank`). Never overwrites a non-null field (owner edits survive).

- [ ] **Step 1: failing tests** `tests/Feature/Gl/CostCentersSeedTest.php`:
  - `test_seed_creates_company_factory_maadi_and_two_safes`
  - `test_seed_links_factory_warehouses_to_fac_and_maadi_warehouse_to_maadi`
  - `test_seed_twice_creates_nothing_the_second_time` (second `created` = [], row counts equal) — RF3
  - `test_dry_run_writes_nothing` (counts all 0 after; command output lists `CO`) — RF3
  - `test_seed_keeps_owner_edits` (rename FAC, relink a warehouse → unchanged after re-seed)
  - `test_seed_without_a_maadi_warehouse_creates_the_center_unlinked` (output mentions unlinked) — RF4
  - `test_maadi_warehouse_option_links_that_warehouse`
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: commit** `feat(gl): promax:cost-centers-seed (idempotent, --dry-run)`.

### Task 8: lane verification (no commit unless fixes)

- [ ] `php artisan test` (full, `promax_test_2`) → all green or failures listed by name vs baseline.
- [ ] `php artisan promax:i18n-check` → clean.
- [ ] On QA: confirm Shopify/iTrack tokens are NULL/blank in `promax_qa.settings` and `.env.qa`; `php artisan migrate --env=qa`; `php artisan promax:cost-centers-seed --dry-run --env=qa` → lists; zero rows written.
- [ ] UAT cases → `HANDOVER_2026-10/uat/_src/uat_cases_close2.py` (outside repo): kinds on product form, raw absent from catalog/price list/handout/online links, seed dry-run.
- [ ] Lane report `LANE_REPORTS_closeout/LANE_2.md`; `HANDOFF_LIVE.md` line.
