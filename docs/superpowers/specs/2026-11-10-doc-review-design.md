# Document-by-document review (المراجعة — مستند مستند) — Design (2026-11-10)

Status: **approved by Ahmed 2026-11-10 («موافق، ابدأ») — all defaults in §11
accepted.** Q3 (which paper number a PO serial holds) was not answered: the PO
field is a generic «السيريال الورقي», free text, same as invoices.

Built notes: the client-side per-table Excel covers the queue (it is not
paginated), so there is no server export; analytics tab sits next to the docs
tab; after an action the page refreshes only that row and the overall bar.

## 1. Intent

After tonight's price fix (`PRICE_DECISIONS_2026-11-10.md`: excl.-VAT base,
everyone taxable, every invoice and PO repriced in place, 4-dp unit prices),
the accountant sits with the paper copies and matches the system **one
document at a time**:

> «المحاسب يفتح المراجعة، يشوف كل السلاسل والعملاء اللي من غير سلسلة، ويراجع
> كل فاتورة وكل أمر توريد واحد واحد على الورق… ويعلّم ✓ اتراجع. ولو فيه غلط
> يصلّحه في نفس الشاشة من غير ما يدوّر في صفحات تانية.»

What makes it special for Ahmed is the **audit**: he wants to see that the
accountant really checked each document, not clicked ✓ ✓ ✓.

Scale (local live copy, 10/11): 442 invoices (426 with a paper serial), 218
non-cancelled POs (188 delivered, 4 arrived, 26 pending), 84 chains, 33 mother
companies, 154 clients without a chain. One accountant + 6 admins.

## 2. Goals / non-goals / anti-goals

Goals
- One screen, **`/erp/review/docs`**, a 5th tab of the existing review module
  («المستندات»). Every invoice + every non-cancelled PO, chain and solo clients.
- Find a document in one keystroke-and-type: system number, paper serial,
  branch/client name or code, phone, chain, amount.
- Filters: mother → chain → client, rep, date range (`_range` partial), doc
  type, review status (pending / reviewed / has issue / changed after review).
- Per document: paper fields **side by side** with system values; ✓ reviewed,
  ✗ issue (+ note); inline fixes: paper serial, lines (add / remove / qty),
  rep, date. After a line fix the document **reprices from the client's
  correct list** with the pricing helpers the price-fix session lands on master.
- Progress to 100%: overall, per mother, per chain, per solo client. Per row:
  #invoices, #POs, money (excl / VAT / incl), pieces, distinct products,
  reviewed vs left.
- Full audit: every ✓ / ✗ / reopen / edit is (a) in the activity centre
  (`erp.activity`) and (b) in an append-only review event log with
  before → after values and the money delta.
- Reviewer analytics (`/erp/review/docs/people`): per user per day — count,
  median seconds between consecutive reviews, fast-streak flags, edits, money
  changed; drill into a user's timeline.
- Keyboard-first, fast, built for a 4-hour session.

Non-goals
- No new pricing logic. Repricing = the existing / landing helpers
  (`InvoiceRepricer`, `PoRepricer`, `FullResync`, `Pricing`, `Tax::line`).
- No ETA upload, no GL redesign (GL is reposted the way existing edits do it).
- Returns, gifts and collections are not reviewed here (later, same pattern).
- The mobile app is untouched.

Anti-goals (would be a failure even if it "works")
- A ✓ that can be given without opening the document (bulk ✓). **There is no
  bulk ✓.** ✓ is only on the open document.
- An edit that changes money without a before → after record.
- Silent stock damage: an edit that moves custody/warehouse stock on a day
  whose stock was already re-counted from paper (see §6.3).
- Slowness: each ✓ must round-trip in < 300 ms and jump to the next document.

## 3. Who

Default (Q2): review + inline edits = **accountant and admin** (and any custom
role granted the new keys). Reopen a reviewed document = **admin only**.
Manager / branch_manager keep seeing the existing four tabs; they see the docs
tab read-only unless granted.

New permission keys in the custom-roles catalog (`Access.php` `act.` list,
ar/en descriptions + risk):
- `act.review.docs` — open the docs tab and mark ✓ / ✗ (low risk).
- `act.review.docs_edit` — inline fixes: serial, lines, rep, date (high risk:
  changes money). Routes: `erp.review.docs.serial|lines|rep|date`.
- `act.review.docs_reopen` — clear a ✓ (medium). Default admin only.
- `erp.review.docs.people` analytics page — admin only by default.
The `role:` middleware on each route matches the defaults (RoleAccessTest).

## 4. Data model

### 4.1 Status — reuse `review_checks`
`entity_type` gains `invoice` and `po`; `phase = 'docs'`; `item = 'doc'`.
One row per document (the existing unique key fits). `status` ok | issue,
`note`, `user_id`, and two snapshots taken at ✓ time:
`system_net` = net excl. VAT, `system_balance` = grand total incl.
Already in `AppServiceProvider::AUDITED`, so every ✓ shows in `erp.activity`.

Derived state shown on screen:
- **pending** — no row.
- **reviewed ✓** — status ok and the document's grand total still equals the
  snapshot.
- **changed after review ⚠** — status ok but grand total ≠ snapshot (someone
  edited it elsewhere, or a later reprice moved it). Counts as *not* done.
- **issue ✗** — status issue (note required).

### 4.2 New `doc_review_events` (append-only)
| col | |
|---|---|
| id, created_at | |
| user_id | who |
| doc_type, doc_id | invoice / po |
| action | `ok`, `issue`, `reopen`, `serial`, `lines`, `rep`, `date`, `open` |
| before, after | JSON (serial; lines `[{product, qty, unit_excl, net, tax}]` + totals; rep id/name; date) |
| net_delta, grand_delta | decimal(14,2), money moved by this event (0 for non-money) |
| seconds_since_prev | seconds since this user's previous `ok`/`issue` event (null for first of a session gap > 30 min) |
| open_ms | how long the document was open on screen before the ✓ (sent by the page; informative) |
| note | |

Never updated or deleted (no update route; model refuses `updating`/`deleting`).
Each event is also written to `activity_logs` via `ActivityLog::record` with
a readable title («✓ راجع INV-1407», «عدّل بنود INV-1407: 1,973.58 → 1,940.10»),
because `InvoiceItem` / `PurchaseOrderItem` are not audited models.

`open` events (document opened) are recorded only as `open_ms` on the next
`ok`/`issue` — not as rows — to keep the table small. (My call.)

### 4.3 `purchase_orders.paper_ref`
New nullable `string(30)` + index, mirroring `invoices.paper_ref` (Q3 decides
what paper number it holds).

Migration: `2026_11_11_000100_doc_review.php` (additive only: one table, one
column). Safe on live.

## 5. Screen

Route `GET /erp/review/docs` → `ReviewDocsController@index`. Two-pane layout
(RTL aware), full height:

```
┌ tabs: لوحة · بيانات · حسابات · مخزن · [المستندات] ───────────────────────┐
│ progress: ███████░░░ 62% (409/660) · فواتير 300/442 · أوامر 109/218 · ✗ 7 │
├───────────────────────────────┬──────────────────────────────────────────┤
│ 🔎 [ search: رقم/سيريال/فرع/تليفون ]│  INV-1407 · فاتورة · 22/10 · آجل      │
│ filters: أم ▾ سلسلة ▾ عميل ▾ مندوب ▾│  العميل CL-372 كارفور المعادي (سلسلة…) │
│ النوع ▾  الحالة ▾  من–إلى          │  ┌ الورق ───────┬ السيستم ─────────┐  │
│ ───────────────────────────── │  │ سيريال [1145 ]│ ⚠ مكرر مع INV-1425│  │
│ chain group header  38/52 ███ │  │ التاريخ       │ 22/10 [✎]         │  │
│ ● INV-1407 1145  1,973.58 ✓   │  │ المندوب       │ أحمد س. [✎]       │  │
│ ○ INV-1425 1145  1,973.58     │  └───────────────┴──────────────────┘  │
│ ○ PO-0218  —    12,400.00 ✗   │  lines: صنف · كمية · سعر غير شامل ·     │
│ …  (virtualised, grouped)     │   ضريبة · شامل · [vs list price ✓/≠]    │
│                               │  totals: net · VAT · incl  (= paper?)   │
│                               │  [✓ مطابق  Enter] [✗ مشكلة  X] [✎ بنود E]│
│                               │  history: who/when/what (events)        │
└───────────────────────────────┴──────────────────────────────────────────┘
```

Left — the queue
- Grouped mother → chain → branch (solo clients under «من غير سلسلة»); each
  group header shows its own progress bar and reviewed/total.
- Row: status chip, system number, paper serial (or «ناقص» chip), date,
  client, incl. total, flags (duplicate serial, missing serial, price ≠ list,
  changed after review, locked).
- Default sort: client group, then date, then number — the order the paper
  files are usually in. Toggle sort by paper serial.
- Default filter: **pending** (like the existing tabs).

Right — the document
- Header with type, system number, status, payment, client (+ chain, mother,
  list name), PO status for POs.
- **Paper vs system** block. Paper serial is an input (Enter saves). Date and
  rep show with a ✎ that turns into a date input / rep select.
- Lines table: product, qty, unit excl. (4 dp), VAT, incl., and the client's
  **current list price** next to it with ✓ or ≠ so price mismatches jump out.
  Excl / VAT / incl columns follow the price-fix session's VAT toggle partial
  (`ops/_vat_lines_toggle`) once it is on master.
- Totals: net, VAT, incl.; an optional «إجمالي الورق» input — if typed, shows
  the difference (stored on the ✓ as `paper_net` style note; informative).
- Edit lines (E): the table becomes editable (qty inputs, remove ×, add product
  with type-ahead from the client's priced products). **Preview** shows the new
  totals from the client's list before saving; Save writes, reprices, logs.
- Actions: ✓ مطابق (Enter), ✗ فيه مشكلة (X, note required), reopen (admin).
- Event history of this document at the bottom.

Keyboard
`/` search · `J` / `↓` next · `K` / `↑` previous · `Enter` ✓ and go to next ·
`X` issue · `S` focus serial · `D` date · `R` rep · `E` edit lines · `Esc`
cancel edit · `?` shortcut sheet. Shortcuts are off while typing in an input.

Mechanics
- Server-rendered Blade like the rest of the module; the right pane loads via
  `GET /erp/review/docs/{type}/{id}` (HTML fragment) and actions POST JSON,
  then the page swaps the fragment and advances. URL keeps `?doc=inv-1407` so
  refresh/back lands on the same document.
- Queue loads only ids + display columns (one query per type, no N+1); the
  pane loads one document's lines.
- Per-table Excel export of the filtered queue (house style), with status,
  reviewer, reviewed-at and seconds-on-document.

## 6. Inline fixes — behaviour

All fixes: inside one DB transaction; guards first; one `doc_review_events`
row + one activity row; if the document was ✓, the ✓ is **cleared** back to
pending (default, Q2) and the event says so.

Common guards (same family as the existing tools): ETA exported/submitted →
locked; `PeriodGuard::assertOpen` on the document date (and on the new date
for a date change); consignment invoice → lines locked. Locked documents show
a lock chip with the reason and can still be ✓ / ✗.

### 6.1 Paper serial
Save on Enter. Trim; empty = clear. **Duplicates warn, don't block** (default
Q4): the live copy already has 10 serials used twice — some are two reps' books
(1371: rep 17 in Sep, rep 9 in Oct), some look like the same invoice entered
twice (1145: same client, same 1,973.58, 22/10 and 27/10). The warning links to
the other document; "duplicate serial" is also a filter.

### 6.2 Rep
Invoice: `user_id`; PO: `assigned_to`. Select limited to field reps
(`User::FIELD_WORK_ROLES`, active, scope-checked with `Scope::assertRep`).
Moves who gets the sale in KPIs/commission; no stock move (see 6.3).

### 6.3 Lines — the stock question (Q1)
The existing invoice editor (`OpsController::editInvoiceItems`) moves goods in
and out of the rep's **open** custody and refuses when that day's custody is
closed — which is the case for almost every document the accountant will
touch. Delivered PO lines have no editor at all.

**Default (recommended): review edits fix the document and its money only;
stock is not moved.** Reason: warehouses and rep custody were zeroed and
re-counted from paper on 7/11, so the stock on the system is already the paper
stock; moving it again for an old document would double-count. The edit:
1. rewrites the lines (qty, add, remove; PO: `qty` and, for delivered POs,
   `delivered_qty`),
2. reprices the document from the client's current list via the landed
   helpers (invoice: `InvoiceRepricer::plan/apply`; PO: `PoRepricer` /
   `FullResync::po`), unit cost kept from the old line or `Pricing::costFor`,
3. updates the sale transaction (and the linked cash collection for cash
   invoices, as `repriceInvoice` does), `client->recalculate()`, GL repost,
4. logs before → after lines and totals.
The pane says plainly: «التعديل ده بيصلّح المستند والفلوس بس — المخزن
ماتحرّكش». For a document dated **after** the stock reset this is flagged on
the event so Ahmed can see it.
Not allowed: emptying a document (use the existing delete tool), a product
with zero list price (rejected, as everywhere), returns linked to the invoice
(blocked, as everywhere).

Pending (undelivered) POs: line edits go through the same rebuild the PO
editor uses (`fillPoItems`) — no stock has moved yet; an approved PO goes
back to the accounting queue exactly as `updatePo` does. (Builder: reuse, do
not copy-paste; extract only if needed and coordinate with the price session.)

### 6.4 Date
Invoice: `created_at` day (time kept) + its transactions' `date`, same as
`redateInvoice`. PO: `delivered_at` for delivered POs (the sale date) + its
transactions' `date`; `due_at` for pending POs. Not after today.

## 7. Progress and per-group figures

For every node (overall, mother, chain, solo client): invoices, POs, net excl,
VAT, incl, transactions (sale rows), pieces, distinct products, reviewed,
issue, changed-after-review, left, % = reviewed / total. Computed in two
grouped queries per doc type keyed by client, folded up in PHP (same pattern
as `MonthFigures`). Shown in the queue group headers and on the review
dashboard as a new card «المستندات».

## 8. Reviewer analytics — `/erp/review/docs/people`

Per user per day: documents ✓/✗, median and p10 seconds between consecutive
reviews, median open time, **fast flags**, edits by kind, money changed
(sum |grand_delta| and net), issues raised. Click a user → timeline of events
with the gap column coloured.

Fast-click rule (default, my call): a review is *fast* if
`seconds_since_prev < 8` **and** the document has ≥ 1 line; a **streak** is
≥ 5 fast reviews in a row → red flag on that day with the list of documents,
so Ahmed can pull those papers and spot-check. Thresholds live in one constant.

## 9. Testing

Feature tests on `promax_test` (`tests/Feature/Review/DocReview*Test.php`):
access per role + custom-role keys (RoleAccessTest stays green); ✓ / ✗ /
reopen; ✓ snapshot → «changed after review»; serial save + duplicate warning;
rep change; date change (closed period refused); line edit reprices from the
list, keeps stock untouched, updates transactions and balance, writes event
with before/after and deltas, clears ✓; locked documents; fast-streak
computation; search finds by serial / number / phone / branch; progress
numbers. Full suite green before commit.

QA click-through on `promax_qa` (port 8010): review 10 documents with the
keyboard, one of each fix, check the activity centre and the people page.

## 10. Delivery

One commit on master (my files only), after pulling the price session's work.
Server: `git pull` · `php artisan migrate --force --path=database/migrations/2026_11_11_000100_doc_review.php` · `php artisan optimize:clear`.

## 11. Questions for Ahmed (answers change what gets built)

1. **Stock on line edits** — default: fix the document + money only, no stock
   move (stock was re-counted 7/11). Alternative: move rep custody like the
   current admin editor (blocked on most old documents).
2. **Who** — default: accountant + admin review and edit; only admin reopens a
   ✓; any edit on a ✓ document clears the ✓ back to pending.
3. **PO paper serial** — which paper number is it: our delivery invoice serial,
   or the chain's PO number?
4. **Duplicate paper serial** — default: warn + link to the other document,
   don't block.

## 12. Calls I made (check while reading)
- Reuse `review_checks` for status + new append-only `doc_review_events`.
- Cancelled POs excluded; pending POs included.
- No bulk ✓, ever.
- Fast-click threshold 8 s / streak 5.
- `open` time recorded only on the ✓ event.
- Analytics page admin-only by default.

---

## Addendum — 2026-11-12: rework after Ahmed's first look

Ahmed: «الجداول بايظة والزراير كبيرة… لما بدوس مطابق المفروض يعد ويغير لونها… اربطها ببعض… اظبط السجل والاسم… اهم شيء الفلترات وسهولة العمل»
and «هي السورس الوحيد لنجاح السيستم ومراجعة الداتا والاعتماد عليه». No new migration.

**Built (fixes):**
- Compact density on all review tabs (scoped to pages with the review tabs), segmented tab strip, one shared
  server pager (`erp.review._pager`: «عرض ١–٢٥ من ٢٢٢», 25/50/100/200) on بيانات العملاء, الحسابات والشهور, المستندات.
- المستندات: one table, chain header rows; ✓ recolours the row, updates chain counters, status chips and overall
  % at once and opens the next *pending* document; chain progress counts all its documents (was the status-filtered
  list, so it sat at 0/n). Filters apply on change; month picker; serial Enter saves then the next Enter = ✓.
- Linking: الحسابات والشهور shows each chain/client balance now, how much of its document money is reviewed
  (% + bar), and 📄 ok/total per month — each opens the queue filtered to that chain/client (and month); the queue
  shows a chain/client strip with balance + reviewed share + ↩ back.
- Activity log: one readable row per action — type «فاتورة/أمر توريد» with link, number + client in the title,
  «حقل: قبل ← بعد» in Arabic. Duplicate request rows and «ReviewCheck #5» noise removed; rejected attempts still logged.

**Built (trust features, no process change):**
- Auto-flags with counts that filter the queue: no serial, duplicate serial, odd date (future / before Aug), rep ≠ the
  client's rep, repriced since the price fix (10/11, from the change log), changed after ✓. Icons beside the number.
- Automatic checklist in the pane (serial, date, rep, list prices, total, repriced). A red check makes the first
  Enter a warning; a second Enter within 5 s confirms ✓ (keeps one-key speed, stops blind ✓).
- Pace: today vs a daily target (150, constant `ReviewerStats::DAILY_TARGET`), 5-day average, remaining ≈ working
  days to 100%, per reviewer — on the queue and the dashboard.
- Open issues list (✗ documents, oldest first, note + who) on the dashboard, linking into the queue.
- Second-reviewer spot check: on المراجِعين each reviewer-day has «🔎 5» → a fixed random sample of the documents
  they ✓'d that day; a wrong one is reopened and marked ✗ (existing admin reopen).
- Exports: queue CSV with the current filters (all pages, reviewer/time/flags/note) and the full review trail CSV
  (admin) — UTF-8 with BOM for Excel.

**Asked Ahmed (process changes, defaults applied until he answers; both switchable in `config/review.php` / `.env`):**
1. Block «متطابق» on a client/month unless all documents are reviewed and the balance agrees? — default: show, don't block.
2. Five manual ticks per document vs automatic checks + one ✓? — default: automatic checks + one ✓ (built).

**Not built / left:** issue owner + resolved status as separate fields (an ✗ is resolved by re-marking ✓; needs a
column if he wants owners); daily target per person as a setting; the ERP layout itself has no phone breakpoint.

Switches (`config/review.php`): `REVIEW_CONFIRM_RED` (default true), `REVIEW_MONTH_NEEDS_ALL_DOCS` (default false =
warn in the month dialog; true = server refuses ✓ on the month until its documents are all ✓), `REVIEW_DAILY_TARGET` (150).

## Addendum 3 — PO proof of delivery (2026-11-12, Ahmed)

Chains pay a purchase order only against proof it was delivered: their own receiving
note (إذن استلام) or a signature/stamp on our delivery paper.

- Columns on `purchase_orders` (migration `2026_11_12_000100_po_delivery_proof`):
  `receipt_note` bool, `receipt_note_no` string(40) nullable, `paper_signed` bool.
- PO pane, paper column: «يوجد إذن استلام» (+ optional note number) and
  «إمضاء / ختم على الورقة». Saved instantly (keys `1`, `2`, `N`); a note number
  implies the receiving note. Logged as a `proof` event with before → after.
  Removing all proof from a ✓ PO reopens it; adding proof leaves ✓ alone.
- Check chip «إثبات استلام» on delivered POs (info on undelivered). Default soft
  (red, confirmed by the second press); `REVIEW_PO_PROOF_REQUIRED=true` makes it block ✓.
- Queue: warning flag `noproof` (counts in «أي تنبيه»), positive filters `receipt`
  and `signed`; search matches the note number. Dashboard card per chain/client:
  delivered, proven, unproven and unproven value. CSV export has three proof columns.

## Addendum 4 — Returns as a third doc type (12/11, Ahmed)

"زي ما فيه مبيعات فيه مرتجعات" — client returns (`returns` / `ClientReturn`) join the
same queue next to invoices and POs.

- **Queue**: type chips «فواتير · أوامر توريد · مرتجعات»; row key `ret-{id}`, tag «م»,
  amount shown as −grand. Same mother → chain → branch grouping. Each row carries its
  original invoices (from `return_items.invoice_id`) — searchable, in the CSV, and
  linked in the pane (opens the invoice in the queue, ↗ opens the invoice screen).
- **Paper vs system**: `returns.paper_ref` = paper return-note serial, required for ✓
  like invoices. Its own serial book: a return «12» is not a duplicate of invoice «12»
  (`DocReview::serialKey` prefixes `R:`). Date, rep, lines, paper total vs credit total.
- **Proof**: `returns.paper_signed` = «إمضاء / ختم العميل» on the return note. The goods
  came back and a credit was booked, so the client's signature is the proof. It shares
  the `noproof` flag and `signed` tag with POs. Soft by default;
  `REVIEW_RETURN_PROOF_REQUIRED=true` makes it a hard ✓ condition.
- **Prices**: each line is compared with the sale it reverses (its invoice line's
  price), or with the client list price when it has no invoice line (pre-system stock).
  A mismatch raises the `ret_price` flag and the red "prices" check.
- **Inline line edit**: quantities only. Repricing goes through the existing
  `FullResync::clientReturn` rule (original invoice line price, else the client's
  current price). Then the `return` credit and the cash `refund` debit get the new
  grand, the client is recalculated, and GL entries are reposted. Custody and stock do
  not move (same decision as 10/11). New products are added as good, unlinked lines.
- **Money**: progress counts returns in every % (overall, per chain/client). Stats
  money = sales − returns, plus a separate returns value. The money tab shows
  sales − returns = net, and the reviewed net. That net matches the client balance
  built from the same documents.
- Migration: `2026_11_12_000200_return_doc_review.php` (add-only: `paper_ref`, `paper_signed`).

## Addendum 5 — Live review fixes (13/11, Ahmed on live)

- **Undo own mark**: a reviewer can undo their own ✓/✗ on the same calendar day.
  Ways in: «↶ تراجع عن المراجعة» in the pane, an Undo button in the toast right after ✓,
  and the key U. It is logged as `undo` in doc_review_events and the activity log.
  Older marks, or someone else's, still go to an admin reopen.
- **Cash toggle double collection** (INV-1017 / paper 0560): a credit invoice already
  paid by a field collection was switched to cash. The toggle added a second linked
  collection, and the client ended at −5,420.60.
  `OpsController::cashToggleBlocker` now refuses when the client's ledger balance minus
  the invoice would go below −1. The cash = sale + linked collection doctrine is
  unchanged.
- **Invoice proof**: invoices get `receipt_note` / `receipt_note_no` / `paper_signed`
  (migration `2026_11_13_000100_invoice_delivery_proof.php`), the same as POs.
  `REVIEW_INVOICE_PROOF_REQUIRED` defaults to soft. Proof stats now cover sales
  documents (invoices + delivered POs), with the `type=sales` filter.
- **Search**: the queue filter form had no submit button, so Enter did nothing.
  A real submit button plus an Enter handler fix it. A search with no hits in the
  current status jumps to «الكل». `Client::search` now also matches document numbers
  (INV-1017 / 1017), paper serials ignoring leading zeros (560 = 0560), and Arabic digits.
- **`php artisan review:audit`**: read-only report, saved to
  storage/app/private/review-audit as JSON + CSV per check. It covers duplicate ledger
  rows, double collections from the cash toggle, doc vs ledger, totals vs lines,
  balance vs ledger, duplicate serials, ✓ changed after review, and every review-pane
  edit before → after. `--fix` is a dry-run; `--fix --apply` takes a snapshot, then
  removes exact duplicate rows and reverts double-collected toggled invoices to credit.
