# Lane 5: Purchasing (requests, quotes, supplier contracts, receipt QC, raw-material store) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put purchase requests, supplier quotes with a side-by-side comparison, supplier contracts with price/validity approval rules, quick QC at goods receipt (with supplier returns) and a raw-material store with a FEFO issue API on top of the existing `supplier_orders` flow.

**Architecture:** Everything is additive on top of `SupplierOrder` / `SupplierOrder::receive()` / `SupplierInvoice::record()` / `SupplierPayment::record()`. Raw and packaging stock live in the same `batches` / `warehouses` tables (spec §4). One service per concern: `App\Services\RawStock` (FEFO issue for Lane 6), `App\Services\PurchaseApproval` (contract/known-supplier rules). New screens sit in the existing `nav.group_purchasing` and `nav.group_wh` groups.

**Tech Stack:** PHP 8 / Laravel 12, MariaDB 10.4, Blade, PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-10-19-promax-closeout-design.md` §3.4, §3.5, §4, §4.1 and the master plan `docs/superpowers/plans/2026-10-19-closeout-master.md` (Global Constraints and Interfaces are binding). Supplier doctrine: `promax-skills/promax-suppliers/SKILL.md`.

## Global Constraints

- Master plan Global Constraints apply verbatim (DB::transaction for money/stock, `Ledger` only for GL, `Access::SCREENS`/`NAV`/`ACTIONS` for every new route and button, guarded migrations, `dateTime()` never `timestamp()`, Africa/Cairo, every string in `lang/ar` + `lang/en` with the same key, `promax:i18n-check` clean, DateRange + `?export=1` on every table screen, EGP only, additive only).
- **Naming trap:** `purchase_orders` = customer **Sales Orders**. Nothing in this lane touches them. Our order to a supplier is `supplier_orders` = **Purchase Order** in the UI. New tables are named `purchase_requests`, `purchase_quotes`, `supplier_contracts`, `supplier_returns` — never `purchase_order_*`.
- Stock quantities stay **integers** (all `batches` / `batch_locations` / `stocks` columns are `integer`). Raw materials are counted in their stock unit (define kg-based materials in grams). See D1.
- Lang file: new keys go in `lang/{ar,en}/supplier.php` (purchasing) and `lang/{ar,en}/stock.php` (raw store). `perm.*` labels go in `lang/{ar,en}/perm.php` (wherever the existing `perm.act_purchasing_manage` lives — grep it).
- Uploads: `$request->file(...)->store('<dir>', 'public')`, `mimes:jpg,jpeg,png,webp,pdf`, `max:8192`; forms with files need `enctype="multipart/form-data"`.
- Document numbers through `HasDocumentNumber::nextDocumentNumber(prefix, 1001)`: `PR-`, `SCON-`, `SRET-`.
- Tests live in `tests/Feature/Purchasing/`, use `RefreshDatabase` and the `TestCase` helpers (`makeAdmin`, `makeProduct`, `makeWarehouse`, …). Test DB is `promax_test_5`.

## Decisions (logged here and in the lane report)

- **D1 Integer stock.** `RawStock::issue` takes `float $qty` (binding signature) but rejects a non-whole quantity with `stock.raw_qty_not_whole`. Changing the batch columns to decimals would touch every `(int)` cast and `=== 0` in the stock code (not additive).
- **D2 Quotes "mandatory" for a new supplier/material = admin approval.** A direct order for a new supplier or a new material is not blocked; it waits for admin approval with the reason "new supplier/material, use quotes". Blocking would break the direct flow and the receipt/invoice paths for every first order.
- **D3 Admin's own order is approved on creation.** When the creator is an admin, the order is born `open` with `approval_status = approved`, `approved_by` = the admin and the reasons recorded. Only non-admin orders with reasons wait (`status = pending`).
- **D4 "Known"** = the supplier has an earlier supplier order (status not `cancelled`/`pending`) containing that product. "New supplier" = no such order at all.
- **D5 Contract match.** A line is covered when an `active` contract of that supplier has the product with `starts_on ≤ ordered_on ≤ ends_on`. A price ≤ contract price + 0.005 passes. Higher → `above_contract`. The product appears only on contracts that are not live (expired, future or inactive) → `contract_expired`.
- **D6 Failed QC lines** do not count as received (`received_qty` unchanged, `rejected_qty` += qty), are never invoiced (invoice = received × cost, unchanged), and land on one `supplier_returns` document per receipt. The return carries no ledger entry (no money moved). The order stays open so the supplier can resend, or the manager closes it.
- **D7 Raw/packaging passed lines need a lot and an expiry.** `batch_no` and `expires_on` are required for `kind ∈ raw|packaging`. Finished goods keep today's defaults (receipt number / shelf-life expiry).
- **D8 Raw issue does not need shelving.** `RawStock::issue` takes the unshelved part of a batch first, then its shelf rows (FEFO by shelf), so `qty_remaining ≥ SUM(batch_locations.qty)` stays true. Only `raw` and `packaging` products; finished goods keep `PickOrder`.
- **D9 Cost centers on supplier money.** `supplier_orders`, `supplier_invoices`, `supplier_payments` get `cost_center_id`. Order: request's center, else form choice, else `CostCenter::forWarehouse($warehouse)`. Invoice: the order's. Payment: form choice, else `CostCenter::company()`. `CostCenterResolver::for()` gets a first step "the source's own `cost_center_id`, or for a ledger row its source document's". `Rules::supplier()` puts it on both lines (Lane 3 does the general `linesFor` wiring; this is the supplier slice).
- **D10 Roles.** Requests: create = admin, manager, warehouse_keeper. Quotes: admin, manager. Approve quote / approve or reject order = **admin only** (`act.purchasing.approve` with `[]`). Contracts: admin, manager. Raw store screen: admin, manager, warehouse_keeper (`wh.` prefix). Supplier returns list: everyone who sees `erp.purchasing`.
- **D11 One material per request.** A purchase request is a single product line; the comparison screen compares suppliers for that line.

## Review Focus

1. **Two issues racing for the same last raw batch:** the second one is refused with `stock.raw_not_enough`, and stock never goes negative. Test: `RawStockTest::test_issue_locks_batches_and_never_goes_negative` (sequential double issue exceeding stock, plus `lockForUpdate` in the code path; reviewer checks the lock order by `id`).
2. **Manager creates an order 1 piaster above the contract price:** it waits for admin approval and cannot be received until approved. Test: `OrderApprovalTest::test_price_above_contract_waits_for_admin_and_cannot_be_received`.
3. **A receipt with one passed and one failed line:** only the passed line enters stock, the failed qty is on a supplier return, and the later invoice bills the passed qty only. Test: `ReceiptQcTest::test_mixed_receipt_invoices_only_passed_qty`.
4. **Approving a quote twice (double click):** one supplier order only. Test: `PurchaseRequestTest::test_approving_twice_creates_one_order`.
5. **An existing known supplier + known product with no contract, created by a manager:** born `open`, no approval columns set — today's flow unchanged. Test: `OrderApprovalTest::test_known_supplier_known_material_without_contract_keeps_direct_flow`.

---

## Task 1: RawStock — FEFO issue API for Lane 6

**Files:**
- Create: `database/migrations/2026_10_19_000500_raw_stock_moves.php`
- Create: `app/Models/RawStockMove.php`
- Create: `app/Services/RawStock.php`
- Modify: `lang/{ar,en}/stock.php`
- Test: `tests/Feature/Purchasing/RawStockTest.php`

**Interfaces:**
- Produces:
  - Table `raw_stock_moves`: `id`, `warehouse_id` FK, `product_id` FK, `batch_id` FK, `qty` integer, `unit_cost` decimal(12,4), `for_type` string nullable, `for_id` unsignedBigInteger nullable (index with for_type), `created_by` nullable FK users, `moved_at` dateTime, timestamps.
  - `App\Models\RawStockMove` (`for(): MorphTo`, `batch()`, `product()`, `warehouse()`).
  - `App\Services\RawStock::issue(Warehouse $w, Product $p, float $qty, Model $for): array` → `list<array{batch_id:int, qty:float, cost:float}>` (`cost` = the batch's unit cost). Throws `App\Exceptions\Rejected`.
  - `App\Services\RawStock::available(Warehouse $w, Product $p): int` (sum of `qty_remaining` on non-blocked, non-expired batches).
  - Lang keys `stock.raw_kind_only`, `stock.raw_qty_not_whole`, `stock.raw_not_enough` (`:product`, `:available`).

- [ ] **Step 1: Write failing tests** in `RawStockTest`:
  - `test_issue_takes_the_earliest_expiry_first`: raw product, batches A (expires +60d, qty 50, cost 2.5) and B (+30d, qty 40, cost 3) in warehouse W; `issue(W, p, 60, $for)` → `[['batch_id'=>B,'qty'=>40.0,'cost'=>3.0],['batch_id'=>A,'qty'=>20.0,'cost'=>2.5]]`; B `qty_remaining` 0, A 30; `qty_issued` incremented; 2 `raw_stock_moves` rows with `for_type`/`for_id` of `$for`.
  - `test_expired_and_blocked_batches_are_skipped`.
  - `test_issue_locks_batches_and_never_goes_negative`: stock 40; issue 30 ok; issue 30 → `Rejected` with `stock.raw_not_enough`; batch stays 10, no extra move row.
  - `test_finished_goods_are_refused` (`kind='finished'` → `Rejected` `stock.raw_kind_only`), `test_fractional_qty_is_refused` (2.5 → `stock.raw_qty_not_whole`), `test_zero_is_refused`.
  - `test_shelved_part_is_taken_after_the_unshelved_part`: batch qty 50 with 20 on a shelf (`BatchLocation::putAway`); issue 40 → shelf row left with 10, batch 10; `StockCounting::resync` ran (the `stocks` row for W/p equals 10).
  - `test_other_warehouse_batches_are_not_touched`.
  - `test_available_counts_only_usable_batches`.
  Use a stand-in `$for` model (e.g. a `GoodsReceipt` from its factory or a `User`).
- [ ] **Step 2:** Run `php artisan test --filter=RawStockTest`. Expected: FAIL (class missing).
- [ ] **Step 3: Implement** the guarded migration, the model and `RawStock`. Inside one `DB::transaction`: refuse kind not in `['raw','packaging']`, `$qty <= 0`, or `abs($qty - round($qty)) > 1e-9`; lock candidate batches with `Batch::where(warehouse, product)->sellable()->lockForUpdate()->get()` (`sellable()` already orders by `expires_on`, `id`); check the sum; for each batch take `min(need, qty_remaining)`: first from the unshelved part (`qty_remaining − SUM(locations)`), the rest from its `BatchLocation` rows ordered `is_pick_face desc, id` via `take()`; then `decrement('qty_remaining')`/`increment('qty_issued')`, write the move row (`moved_at = now()`, `created_by = auth()->id()`); finally `StockCounting::resync($p->id, $w->id)`. Docblock in Arabic explaining FEFO + D1 + D8.
- [ ] **Step 4:** Run the filter. Expected: all pass. Run `php artisan promax:i18n-check`. Expected: clean.
- [ ] **Step 5: Commit** `feat(raw): RawStock::issue FEFO API with move log`.

## Task 2: Supplier contracts

**Files:**
- Create: `database/migrations/2026_10_19_000510_supplier_contracts.php`
- Create: `app/Models/SupplierContract.php`, `app/Models/SupplierContractItem.php`
- Create: `app/Http/Controllers/SupplierContractController.php`
- Create: `resources/views/erp/supplier_contracts.blade.php` (list + create/edit dialog), `resources/views/erp/supplier_contract.blade.php` (one contract: items with fixed price, attachment link, validity badge)
- Modify: `routes/web.php` (inside the suppliers block), `app/Support/Access.php` (NAV item in `nav.group_purchasing`, SCREENS for manager, ACTIONS `act.contracts_sup.manage`), `app/Models/Supplier.php` (`contracts(): HasMany`), `lang/{ar,en}/supplier.php`, `lang/{ar,en}/perm.php`, `lang/{ar,en}/nav.php` if NAV labels live there
- Test: `tests/Feature/Purchasing/SupplierContractTest.php`

**Interfaces:**
- Produces:
  - Tables `supplier_contracts` (`id`, `number` unique, `supplier_id` FK, `starts_on` date, `ends_on` date, `attachment_path` nullable, `notes` text nullable, `active` bool default true, `created_by` nullable, timestamps) and `supplier_contract_items` (`id`, `supplier_contract_id` FK cascade, `product_id` FK, `unit_price` decimal(10,2), unique(contract, product)).
  - `SupplierContract::isLive(?Carbon $on = null): bool` (active and `starts_on ≤ on ≤ ends_on`), `statusKey(): string` ∈ `live|expired|future|inactive`, `nextNumber()` (`SCON-`).
  - `SupplierContract::liveItemFor(int $supplierId, int $productId, Carbon|string $on): ?SupplierContractItem` (latest `starts_on` wins when two overlap).
  - `SupplierContract::anyItemFor(int $supplierId, int $productId): bool`.
  - Routes (group `role:admin,manager`): `GET /erp/supplier-contracts` `erp.supplier_contracts` (DateRange on `starts_on`, filters supplier + status, `?export=1` CSV), `GET /erp/supplier-contracts/{supplierContract}` `.show`, `POST /erp/supplier-contracts` `.store`, `PUT /erp/supplier-contracts/{supplierContract}` `.update`, `POST /erp/supplier-contracts/{supplierContract}/items` `.items` (replaces the item set from `price[product_id]`; blank = removed).
  - ACTION `act.supplier_contracts.manage` → `['perm.act_supplier_contracts_manage', 'erp.supplier_contracts', ['manager'], ['erp.supplier_contracts.store','erp.supplier_contracts.update','erp.supplier_contracts.items']]`.
  - The item picker lists every active product (any kind, Lane 2 D3: supplier surfaces are unfiltered).

- [ ] **Step 1: Failing tests:** `test_manager_creates_contract_with_items_and_attachment` (multipart POST with `UploadedFile::fake()->create('c.pdf', 100, 'application/pdf')`, then items POST → 2 items, `attachment_path` stored on the fake `public` disk); `test_live_item_respects_validity_dates` (inside, before, after, inactive); `test_ends_before_starts_is_rejected` (validation error `ends_on`); `test_list_filters_and_exports_csv` (`?export=1` returns `text/csv` with the contract number); `test_accountant_and_keeper_get_403`.
- [ ] **Step 2:** `php artisan test --filter=SupplierContractTest`. Expected: FAIL.
- [ ] **Step 3: Implement** migration, models, controller, views (`ui-promax`: `.card`, `.tablewrap`, status badge `b-green` live / `b-gray` expired / `b-blue` future / `b-red` inactive), routes, Access and lang keys.
- [ ] **Step 4:** Run the filter plus `--filter="RoleAccessTest|AccessTest"` and `php artisan promax:i18n-check`. Expected: all green, clean.
- [ ] **Step 5: Commit** `feat(purchasing): supplier contracts with fixed prices and validity`.

## Task 3: Order approval rules (contract, price, new supplier/material)

**Files:**
- Create: `database/migrations/2026_10_19_000520_supplier_order_approval.php`
- Create: `app/Services/PurchaseApproval.php`
- Modify: `app/Models/SupplierOrder.php` (fillable, casts, `isPendingApproval()`, `approve(User)`, `reject(User, ?string)`, `statusClass/Label` for `pending`), `app/Http/Controllers/SupplierController.php` (`storeOrder`, new `approveOrder`, `rejectOrder`, `orders` filter `approval=pending`), `resources/views/erp/supplier_order.blade.php` (approval banner with reasons + admin buttons), `resources/views/erp/supplier_orders.blade.php` (pending badge + count), `routes/web.php`, `app/Support/Access.php`, `lang/{ar,en}/supplier.php`, `lang/{ar,en}/perm.php`
- Test: `tests/Feature/Purchasing/OrderApprovalTest.php`

**Interfaces:**
- Consumes: `SupplierContract::liveItemFor`, `SupplierContract::anyItemFor` (Task 2).
- Produces:
  - Columns on `supplier_orders`: `approval_status` varchar(20) nullable (`null` = not needed / legacy, `pending`, `approved`, `rejected`), `approval_reasons` json nullable, `approved_by` nullable FK users, `approved_at` dateTime nullable, `supplier_contract_id` nullable FK. On `supplier_order_items`: `contract_price` decimal(10,2) nullable.
  - New `supplier_orders.status` value `pending` (not received, not invoiced; `isOpen()` stays `open|received` so receipt is refused).
  - `App\Services\PurchaseApproval::assess(int $supplierId, array $lines, Carbon|string $on): array{reasons: list<array{code:string, product_id:int, price:?float, contract_price:?float}>, contract_id: ?int, contract_prices: array<int,float>}` — `$lines` = `[product_id => ['qty'=>int,'unit_cost'=>float]]`; codes `above_contract`, `contract_expired`, `new_supplier`, `new_material` (D4, D5).
  - `PurchaseApproval::reasonText(array $reason): string` (lang `supplier.reason_<code>` with `:product`, `:price`, `:contract`).
  - `SupplierOrder::approve(User $by): void` (locks, `pending` → `open`, `approval_status=approved`), `SupplierOrder::reject(User $by, ?string $note): void` (`pending` → `cancelled`, `approval_status=rejected`, note appended to `notes`). Both throw `Rejected(__('supplier.not_pending'))` otherwise.
  - Routes (`role:admin`): `POST /erp/purchasing/{supplierOrder}/approve` `erp.purchasing.approve`, `POST /erp/purchasing/{supplierOrder}/reject` `erp.purchasing.reject`. ACTION `act.purchasing.approve` → `['perm.act_purchasing_approve','erp.purchasing',[],['erp.purchasing.approve','erp.purchasing.reject']]`. Add `'!erp.purchasing.approve','!erp.purchasing.reject'` to the manager and warehouse_keeper SCREENS lists.
  - `storeOrder` behaviour: no reasons → unchanged (`open`, `approval_status` null). Reasons + admin creator → `open`, `approved`, `approved_by`, `approved_at` (D3). Reasons + non-admin → `status=pending`, `approval_status=pending`, flash `supplier.order_waits_approval`. Always store `contract_price` per item and `supplier_contract_id` when a live contract covered a line.

- [ ] **Step 1: Failing tests:**
  - `test_known_supplier_known_material_without_contract_keeps_direct_flow` (prior received order for the same supplier+product; manager creates → `open`, `approval_status` null).
  - `test_contract_price_within_validity_needs_no_approval` (new supplier but live contract item 10.00, order at 10.00 → `open`, null approval, `contract_price`=10.00, `supplier_contract_id` set).
  - `test_price_above_contract_waits_for_admin_and_cannot_be_received` (10.01 → `pending`; POST receive → error `supplier.order_not_open`, no batch).
  - `test_expired_contract_waits_for_admin`, `test_new_supplier_waits_for_admin`, `test_new_material_for_known_supplier_waits_for_admin` (reason codes asserted in `approval_reasons`).
  - `test_admin_created_order_is_approved_on_creation_with_reasons_logged`.
  - `test_admin_approves_then_it_can_be_received`; `test_admin_rejects_it_becomes_cancelled`; `test_manager_cannot_approve` (403).
  - `test_orders_list_filters_pending`.
- [ ] **Step 2:** `php artisan test --filter=OrderApprovalTest`. Expected: FAIL.
- [ ] **Step 3: Implement** as in Interfaces. The approve button uses `Access::action($user, 'act.purchasing.approve')`. Reasons render with `PurchaseApproval::reasonText` (no DB queries in the view: controller passes product names).
- [ ] **Step 4:** Filter + `RoleAccessTest|AccessTest` + i18n-check. Expected: green, clean.
- [ ] **Step 5: Commit** `feat(purchasing): contract-price and new-supplier approval on purchase orders`.

## Task 4: Purchase requests, RFQ quotes, comparison, approval → purchase order

**Files:**
- Create: `database/migrations/2026_10_19_000530_purchase_requests.php`
- Create: `app/Models/PurchaseRequest.php`, `app/Models/PurchaseQuote.php`
- Create: `app/Http/Controllers/PurchaseRequestController.php`
- Create: `resources/views/erp/purchase_requests.blade.php` (list + new-request dialog), `resources/views/erp/purchase_request.blade.php` (request header, add-quote dialog with attachment, **comparison table: one column per quote** with price, line total, lead days, payment terms, attachment, cheapest/fastest badges, approve button per column)
- Modify: `routes/web.php`, `app/Support/Access.php` (NAV item first in `nav.group_purchasing`, SCREENS for manager and warehouse_keeper, ACTIONS), `app/Models/SupplierOrder.php` (fillable `purchase_request_id`, `purchase_quote_id`, `cost_center_id`), `lang/{ar,en}/supplier.php`, `lang/{ar,en}/perm.php`
- Test: `tests/Feature/Purchasing/PurchaseRequestTest.php`

**Interfaces:**
- Consumes: `CostCenter` (Lane 2), `SupplierOrder` approval columns (Task 3).
- Produces:
  - `purchase_requests`: `id`, `number` unique (`PR-`), `product_id` FK, `qty` unsigned integer, `needed_by` date nullable, `requested_by` FK users, `cost_center_id` nullable FK, `status` varchar(20) ∈ `PurchaseRequest::STATUSES = ['open','quoting','ordered','cancelled']` default `open`, `notes` text nullable, `supplier_order_id` nullable FK, `approved_by` nullable, `approved_at` dateTime nullable, timestamps.
  - `purchase_quotes`: `id`, `purchase_request_id` FK cascade, `supplier_id` FK, `unit_price` decimal(10,2), `lead_days` unsigned smallint nullable, `payment_terms` varchar(120) nullable, `attachment_path` nullable, `notes` text nullable, `status` ∈ `pending|approved|rejected` default `pending`, `created_by` nullable, timestamps; unique(`purchase_request_id`,`supplier_id`).
  - Columns on `supplier_orders`: `purchase_request_id`, `purchase_quote_id`, `cost_center_id` (all nullable FKs).
  - `PurchaseQuote::approve(User $by, int $warehouseId): SupplierOrder` — one `DB::transaction`, locks the request row; refuses (`Rejected __('supplier.request_already_ordered')`) unless the request is `open|quoting`; creates the order (`status=open`, `approval_status=approved`, `approved_by`, `approved_at`, `ordered_on=today()`, `expected_on = today()+lead_days` when set, `cost_center_id` = request's, one item at the quote price, `total`), marks this quote `approved`, the others `rejected`, the request `ordered` with `supplier_order_id`.
  - Routes: group `role:admin,manager,warehouse_keeper`: `GET /erp/purchase-requests` `erp.purchase_requests` (DateRange on `created_at`, the request date; filters status/product; `?export=1`), `GET /erp/purchase-requests/{purchaseRequest}` `.show`, `POST /erp/purchase-requests` `.store`, `POST /erp/purchase-requests/{purchaseRequest}/cancel` `.cancel` (requester or admin/manager; only `open|quoting`). Group `role:admin,manager`: `POST /erp/purchase-requests/{purchaseRequest}/quotes` `.quotes.store` (multipart; adding a quote moves `open` → `quoting`). Group `role:admin`: `POST /erp/purchase-quotes/{purchaseQuote}/approve` `erp.purchase_quotes.approve` (field `warehouse_id` required).
  - ACTIONS: `act.purchase_requests.create` (`['manager','warehouse_keeper']`, store+cancel), `act.purchase_requests.quote` (`['manager']`, quotes.store), `act.purchase_quotes.approve` (`[]`, `erp.purchase_quotes.approve`). SCREENS: manager gets `erp.purchase_requests` (admin-only `erp.purchase_quotes.approve` is simply not listed); keeper gets `erp.purchase_requests` and `!erp.purchase_requests.quotes`.
  - The order page shows "from request PR-xxxx / quote" when linked.

- [ ] **Step 1: Failing tests:** `test_keeper_raises_a_request_for_a_raw_material_with_cost_center`; `test_manager_adds_three_quotes_and_comparison_shows_them_side_by_side` (GET show contains the 3 supplier names, the cheapest marked with `supplier.cheapest`); `test_admin_approves_a_quote_and_an_order_is_created_at_the_quote_price` (order item `unit_cost` = quote price, order `open`, `approval_status=approved`, others `rejected`, request `ordered`, `cost_center_id` copied); `test_approving_twice_creates_one_order`; `test_manager_cannot_approve_a_quote` (403); `test_duplicate_supplier_quote_is_rejected`; `test_quote_attachment_is_stored`; `test_requests_list_exports_csv`.
- [ ] **Step 2:** `php artisan test --filter=PurchaseRequestTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** Cost-center select lists active `CostCenter`s (`displayName` via `name`/`name_en` per locale). The product picker lists all active products with their `kindLabel()`.
- [ ] **Step 4:** Filter + `RoleAccessTest|AccessTest` + i18n-check. Expected: green, clean.
- [ ] **Step 5: Commit** `feat(purchasing): purchase requests, supplier quotes, comparison and approval to a purchase order`.

## Task 5: Quick QC at goods receipt and supplier returns

**Files:**
- Create: `database/migrations/2026_10_19_000540_receipt_qc.php`
- Create: `app/Models/ReceiptInspection.php`, `app/Models/SupplierReturn.php`, `app/Models/SupplierReturnItem.php`
- Create: `resources/views/erp/supplier_returns.blade.php` (list, DateRange on `returned_on`, `?export=1`), `resources/views/erp/supplier_return.blade.php` (printable document: supplier, order, receipt, lines with note and photo)
- Modify: `app/Models/SupplierOrder.php` (`receive()`), `app/Models/SupplierOrderItem.php` (fillable `rejected_qty`), `app/Http/Controllers/SupplierController.php` (`receiveOrder` validation + files, new `returns`, `returnShow`), `resources/views/erp/supplier_order.blade.php` (form `enctype`, per line: QC select pass/fail, note, photo; rejected column; returns card), `routes/web.php` (`/purchasing/returns` and `/purchasing/returns/{supplierReturn}` **before** `/purchasing/{supplierOrder}`), `app/Support/Access.php` (NAV item), `lang/{ar,en}/supplier.php`
- Test: `tests/Feature/Purchasing/ReceiptQcTest.php`

**Interfaces:**
- Produces:
  - `supplier_order_items.rejected_qty` integer default 0.
  - `receipt_inspections`: `id`, `goods_receipt_id` FK cascade, `supplier_order_item_id` FK, `product_id` FK, `qty` integer, `result` varchar(10) ∈ `pass|fail`, `note` varchar(500) nullable, `photo_path` nullable, `batch_id` nullable FK, `supplier_return_id` nullable FK, `inspected_by` nullable FK users, timestamps.
  - `supplier_returns`: `id`, `number` unique (`SRET-`), `supplier_id`, `supplier_order_id`, `goods_receipt_id`, `returned_on` date, `total` decimal(14,2), `notes` nullable, `created_by` nullable, timestamps. `supplier_return_items`: `id`, `supplier_return_id` FK cascade, `supplier_order_item_id`, `product_id`, `qty` integer, `unit_cost` decimal(10,2), `note` nullable, `photo_path` nullable.
  - `SupplierOrder::receive(array $lines, User $by): GoodsReceipt` keeps its signature; each line may now carry `qc` (`pass` default | `fail`), `qc_note`, `qc_photo` (stored path string — the controller stores the file). Pass → today's batch path (+ D7 checks for raw/packaging → `Rejected __('supplier.lot_required')` / `__('supplier.expiry_required')`) and an inspection row with `batch_id`. Fail → no batch, `rejected_qty += qty`, inspection row, a line on the receipt's single `SupplierReturn` (created lazily). Over-receipt guard: `received_qty + qty ≤ qty_ordered` for both results. Return `GoodsReceipt` (`$receipt->supplierReturn` relation `hasOne` via `goods_receipt_id`).
  - Same product can appear only once per receipt (lines keyed by `product_id`, unchanged).
  - Routes: `GET /erp/purchasing/returns` `erp.purchasing.returns`, `GET /erp/purchasing/returns/{supplierReturn}` `erp.purchasing.returns.show` (in the `role:admin,manager,warehouse_keeper` group).

- [ ] **Step 1: Failing tests:** `test_passed_line_enters_stock_as_a_batch_with_lot_and_expiry` (raw product, lot `L-77`, expiry given → batch with that `batch_no`/`expires_on`, inspection `pass`); `test_failed_line_does_not_enter_stock_and_creates_a_supplier_return` (no batch, `rejected_qty` 5, return SRET-1001 with 1 line at the order cost, note and photo path saved via `UploadedFile::fake()->image('bad.jpg')`); `test_mixed_receipt_invoices_only_passed_qty` (invoice subtotal = passed × cost); `test_raw_line_without_lot_or_expiry_is_refused` (nothing written); `test_finished_line_without_qc_fields_behaves_as_before` (no `qc` key → pass, batch_no defaults to GRN number); `test_rejected_qty_counts_against_over_receipt`; `test_returns_list_filters_by_date_and_exports_csv`; `test_keeper_of_other_warehouse_still_gets_403`.
- [ ] **Step 2:** `php artisan test --filter=ReceiptQcTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** Validation in `receiveOrder`: `lines.*.qc` `nullable|in:pass,fail`, `lines.*.qc_note` `nullable|string|max:500`, `lines.*.qc_photo` `nullable|image|max:8192`; store photos to `receipt-qc` on `public` before calling `receive()`, and delete them if `receive()` throws. In the view, a fail line hides nothing; the hint says failed qty goes to a supplier return.
- [ ] **Step 4:** Filter + `RoleAccessTest|AccessTest|FormStarIntegrityTest` + i18n-check. Expected: green, clean.
- [ ] **Step 5: Commit** `feat(purchasing): quick QC at goods receipt, failed lines become a supplier return`.

## Task 6: Cost centers on supplier orders, invoices and payments

**Files:**
- Create: `database/migrations/2026_10_19_000550_supplier_cost_centers.php`
- Modify: `app/Models/SupplierInvoice.php`, `app/Models/SupplierPayment.php` (fillable `cost_center_id`, `costCenter()`), `app/Http/Controllers/SupplierController.php` (`storeOrder` optional `cost_center_id`, `invoiceOrder` copies it, `pay` optional `cost_center_id` default company), `resources/views/erp/supplier_order_form.blade.php`, `resources/views/erp/supplier.blade.php` (pay dialog select), `app/Services/Gl/CostCenterResolver.php` (step 0), `app/Services/Gl/Rules.php` (`supplier()` lines carry `cost_center_id`), `lang/{ar,en}/supplier.php`
- Test: `tests/Feature/Purchasing/SupplierCostCenterTest.php`

**Interfaces:**
- Consumes: `CostCenter::forWarehouse`, `CostCenter::company()`, `Ledger`, `GlSeeder` (setup like `tests/Feature/Gl/CostCenterTest.php`).
- Produces:
  - Columns `supplier_invoices.cost_center_id`, `supplier_payments.cost_center_id` (nullable FKs). (`supplier_orders.cost_center_id` came in Task 4.)
  - `CostCenterResolver::for()` new first step: `$source->cost_center_id` if the column is set; else, when the source has `source_type`/`source_id` and that document has a non-null `cost_center_id`, that id. Existing sources (no such column) behave exactly as before.
  - Order center rule (D9): request's → form `cost_center_id` → `CostCenter::forWarehouse($warehouse)?->id` → null.

- [ ] **Step 1: Failing tests:** `test_order_takes_its_warehouse_center_when_none_is_chosen`; `test_invoice_posts_gl_lines_with_the_order_center` (gl_enabled; invoice the received order; both `gl_lines` of the entry for the invoice ledger row have that `cost_center_id`); `test_payment_posts_with_chosen_center_or_company`; `test_supplier_invoice_and_payment_still_post_to_payables` (invariant `payables` ok); `test_resolver_ignores_sources_without_the_column` (an `Invoice`-less Transaction still resolves to Maadi as before).
- [ ] **Step 2:** `php artisan test --filter=SupplierCostCenterTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Filter + `tests/Feature/Gl` + i18n-check. Expected: green, clean.
- [ ] **Step 5: Commit** `feat(purchasing): supplier orders, invoices and payments carry a cost center into the GL`.

## Task 7: Raw-material store screen

**Files:**
- Create: `app/Http/Controllers/RawStoreController.php`, `resources/views/wh/raw.blade.php`
- Modify: `routes/web.php` (`wh.` group, `GET /wh/raw` `wh.raw`), `app/Support/Access.php` (NAV item in `nav.group_wh`; `wh.` prefix already covers manager and keeper), `lang/{ar,en}/stock.php`, `lang/{ar,en}/nav.php` if needed
- Test: `tests/Feature/Purchasing/RawStoreScreenTest.php`

**Interfaces:**
- Consumes: `RawStockMove` (Task 1), `Product::KINDS`.
- Produces: `wh.raw` — tab 1 "batches": raw/packaging batches with `qty_remaining > 0`, filters warehouse, kind (`raw|packaging`), product search, DateRange on `expires_on`, in FEFO order (`expires_on`, `id`), columns product, kind, warehouse, lot, expiry badge (`expiryClass()`), qty, unit cost, value; KPIs from the same filtered query (lots, qty, value). Tab 2 "moves" (`?tab=moves`): `raw_stock_moves` with DateRange on `moved_at`. Both export with `?export=1`. Keeper sees only his warehouse (`warehouse_id` forced like the other `wh.` screens — reuse their guard).

- [ ] **Step 1: Failing tests:** `test_raw_store_lists_raw_and_packaging_batches_in_fefo_order_not_finished`; `test_moves_tab_shows_issues`; `test_export_returns_csv`; `test_keeper_sees_only_his_warehouse`; `test_accountant_gets_403`.
- [ ] **Step 2:** `php artisan test --filter=RawStoreScreenTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4:** Filter + `RoleAccessTest|AccessTest` + i18n-check. Expected: green, clean.
- [ ] **Step 5: Commit** `feat(raw): raw-material store screen with FEFO batches and issue log`.

## Task 8: Lane verification and hand-off (lane owner, not a builder)

- [ ] Full suite `php artisan test` green; `php artisan promax:i18n-check` clean; `python ../../promax-skills/promax-feature-dev/scripts/static_check.py` passes.
- [ ] QA: check `promax_qa` Shopify/iTrack tokens are NULL/blank, `php artisan migrate --env=qa`, serve on 8010 with `--env=qa`, walk: request → 2 quotes → compare → approve → receive with one fail line → return doc → invoice → raw store.
- [ ] UAT cases `HANDOVER_2026-10/uat/_src/uat_cases_close5.py` (prefix `PUR`), lane report `LANE_REPORTS_closeout/LANE_5.md` (first line STATUS), one line in `HANDOFF_LIVE.md`. Commit nothing outside the repo.
