# Client route planner — port the leads planner to real clients — Design (2026-11-11)

Status: **approved by Ahmed 2026-11-11 («موافق، ابدأ») — all §10 calls accepted. Built same day.**

Built notes: route names kept (`ops.geo` = builder at `/ops/route-builder`, old
`/ops/geo-planner` redirects as `ops.geo.legacy`); new `ops.geo.save` and
`ops.journeys.week`; `ops.geo.zone/plan/unplan` and `ops.journeys.store/destroy/reorder`
removed. Follow-up also skips days before a plan was created (so a stop added
today is never "missed" yesterday). The board's plan table gives the per-table Excel.

## 1. Intent

Ahmed:

> «الفلو بتاع توزيع خطوط السير للعملاء المحتملين محترم جدا .. عاوز انقله هو هو
> بكل تفصيله في توزيع خطوط السير للعملاء الحقيقيين بقي .. والغي الـ موجود حاليا
> ولو فيه حاجة فيه مش موجوده في طريقة العملاء المحتملين فـ زودها»

The leads flow is three screens that read well together: a **map builder** (click
pins in order → numbered dashed route → «🧭 رتب بالأقرب» → Apply), a **week board**
(pool on the side, arm a card, tap a day, 🗺 per day), and a **week follow-up**
(planned / visited / missed per rep). Real clients get the same three screens,
with every client-only capability of today's «خطط السير» and «الخريطة الجغرافية
وخط السير» folded in. Then the two old screens go.

Scale (local live copy, 11/11): 790 active clients, 482 with a location, **66
active plan rows for 4 reps, all weekly, no start dates, no times, no client on
two days**. So the cut-over is cheap and low-risk.

## 2. The one big decision: what a "plan" means for clients

Leads use **dated rows** (`lead_plans`: rep × lead × real date) because a lead is
visited once. Clients are visited **forever on a rhythm** — weekly, every 2 weeks,
monthly. Today's `journey_plans` (rep × client × weekday × `every_weeks` ×
`starts_on` × `visit_at` × `sort`) already models that, and **eleven consumers
read it through `Journeys::forDay`**: the rep app journey, manager/promoter
bootstrap, Live board, rep day, rep page KPI, promoter `MerchAccess`, check-in
plan link, assignments card, demo/reset commands.

**Decision (my call): keep `journey_plans` as the storage. The leads UX is ported
on top of the weekly pattern.** A board column = a weekday (with this week's date
shown under it); a stop carries its frequency. Consequences:

- No data migration of plans, no new plan table. The 66 rows open as-is.
- The rep app payload does not change → **no APK needed** (see §7).
- Live, rep day, KPIs, check-in keep working untouched.

Rejected: dated rows for clients (would need a generator job, would break every
consumer above, and loses "every 2 weeks" as a concept).

## 3. Screens (all under the existing `ops.` names — see §8)

### 3.1 «راسم خط السير» — map builder — `ops.geo` (URI `/ops/route-builder`)

Same page as `leads_route.blade.php`, adapted:

- **Filters** (`.fl` captions, GET): search (name/code/phone, `Client::search`),
  governorate → zone (with counts, only zones that have clients, biggest first),
  channel, chain (group), current rep, manager (admin only), chips «غير مجدول
  فقط» and «متأخر +14 يوم». Count badge 📍.
- **KPI strip** (from the old geo screen, small cards): clients · not on any plan
  · no rep (→ `ops.assignments?only=orphans`) · no location
  (→ `erp.client_locations?show=no_location`).
- **Apply bar**: rep (required) · **weekday** (Sat…Fri) · frequency for *new*
  stops (أسبوعي / كل أسبوعين / شهري, default weekly) · optional first-visit date
  (locks the weekday, anchors the frequency) · optional time · selected count ·
  🧭 رتب بالأقرب · ✕ مسح · ✅ حفظ خط السير.
- **Picking rep + weekday loads that day's current route** pre-numbered on the
  map and in the chips. So the sequence on screen *is* the day: click to add at
  the end, click again to remove, ✕ on a chip removes, nearest-first reorders
  (first stop fixed, same equirectangular greedy chain as leads).
  Save = that rep's weekday becomes exactly this sequence (existing stops keep
  their frequency/start/time; new ones take the bar's values).
- **Map**: Leaflet + **OpenStreetMap tiles** (`{s}.tile.openstreetmap.org`, no
  CARTO). Unselected = 16px dot coloured by **channel** (12-colour palette by
  size, legend chips = exclusive filter, as leads do by category). Selected = 30px
  blue numbered badge. Dashed blue polyline. Clients on *another* rep's plan that
  day show a small 🔒 ring. Tooltip: name, code, chain, channel, zone, main rep,
  plan days («أحد · كل أسبوعين»), last visit (days), balance.
- Cap 1500 pins (482 located today). Clients without a location are listed under
  the map («بدون لوكيشن: n») with a link to fix them; they can still be added to
  the day from that list (they just don't draw on the map).
- **Save rules** (server, one transaction): every client passes
  `Scope::assertClient`, `sameTeam`, `inZone` for the rep (today's guards). A
  client on another rep's plan *that the viewer controls* on the same weekday is
  **moved** (as `geoPlan` does today); another team's plan is **skipped and
  named** in the flash (no silent skip — fixes the leads gap). If the client has
  no main rep (`rep_id` null) it is set to this rep; an existing main rep is
  **not** changed (my call — rep_id drives coverage/close-out).
- **Distribute unassigned** (the old geo "⚡ توزيع تلقائي" dialog: most clients in
  the zone → else least-loaded, sales agents, optional drivers, writes
  `clients.rep_id` only) moves to **`ops.assignments`** as a header button. It is
  assignment, not route planning; its routes stay `ops.geo.distribute/assign`.

### 3.2 «جدولة الأسبوع» — week board — `ops.journeys` (same URI)

Same page as `leads_planner.blade.php`, plus the old board's extras:

- Header: rep (no empty option, team-scoped via `fieldVisibleTo` — fixes the leads
  gap; manager appears as himself), week date, ‹ › arrows, «👤 المندوب ↗», links
  to the builder and the follow-up.
- **Pool panel** (left): the rep's clients not yet on his plan (today's pool
  query: `rep_id` = rep, or same manager pool, or rep's zone), search, chips
  «غير مجدول · n» / «الكل · n» / per channel, governorate → zone selects. Cards
  grouped chain branches first (under the chain name) then cash-van, each with
  zone, «عليه n» when balance > 0, red «متأخر» when last visit > 14 days.
- **Board**: 7 columns **Sat → Fri** (leads order), date under each, count badge,
  today highlighted, **> 8 stops = orange "over" warning**, empty Saturday shows
  «إجازة». Cards: «n. name», zone, frequency badge (٢ / ٤ أسابيع), 🕒 time, ✕.
- **Both interactions**: arm-and-tap (leads) **and** drag-and-drop (old board:
  pool → day at a position, card → another day/position).
- Click a card → small popover to edit **frequency, first-visit date, time**
  (today only the geo dialog can; now the board can too).
- 🗺 per day → read-only dialog: numbered pins, dashed line, chips (leads).
- «🧭 توزيع حسب الزون» (old board: largest zone → lightest day Sun–Fri, review
  then save), «📋 نسخ من مندوب» (copy dialog), «🗑 مسح خطط الفريق» (wipe, needs
  `act.field.wipe`).
- **Month view** tab (old board): pattern laid over the month with `dueOn`, colour
  by load, past days with a missed planned client in red, click a day → ✓ / ✗ / •.
- Unsaved changes: pulsing save button + `beforeunload` warning. One save = full
  sync of the rep's active plans (today's `sync`, keeps frequency/start/time by id).
- Per-table Excel on the board (rep's whole week: day, #, code, client, zone,
  frequency, time).

### 3.3 «متابعة الأسبوع» — follow-up — `ops.journeys.week` (new)

Same page as `leads_week.blade.php`, but on real visits:

- Week picker (Sat-start), rep filter, team-scoped (fixes the leads gap).
- Per rep card sorted by missed: 📅 planned (due stops via `dueOn`), ✓ visited
  (closed visit by that rep to that client that date), ⚠️ missed (past + not
  visited), ➕ off-plan visits, % done; 7-day grid of chips ✓ green / ✗ red / •
  pending; chip → client card; rep name → `ops.rep_day` for that date.
- Per-table Excel.

## 4. What gets replaced / removed

| Old | Fate |
|---|---|
| `ops.journeys` board view + `JourneyController::index` UI | rewritten as §3.2 (same route name, same `sync`/`copy`/`wipe` endpoints) |
| `ops.geo` geo tree + zone dialog + KPI cards | replaced by §3.1 (same route name, new URI `/ops/route-builder`; old URI redirects) |
| `ops.geo.zone`, `ops.geo.plan`, `ops.geo.unplan` | removed once §3.1 saves through a new `ops.geo.save` |
| `ops.journeys.store/destroy/reorder` (no UI calls them) | removed |
| geo distribute dialog | moved to `ops.assignments` |
| nav labels | «خطط السير» → «جدولة الأسبوع»; «الخريطة الجغرافية وخط السير» → «راسم خط السير»; new «متابعة الأسبوع» |

Untouched: Live, rep day, tracking, visits, open visits, assignments wizard,
`Journeys` service, rep app API.

Small server fix bundled: check-in links `visits.journey_plan_id` only when the
plan is actually **due** that day (`dueOn`), not just same weekday.

## 5. Data migration

None structural. One idempotent migration only if the click-through finds it
needed (e.g. index `journey_plans(user_id, active, weekday)`). The 66 live rows
are read as-is. `starts_on` / `visit_at` stay nullable.

## 6. Week conventions

Board and follow-up display Sat → Fri (leads). Weekday values stay Carbon
`dayOfWeek` (0 = Sun). `dueOn` keeps its existing anchor so no live fortnightly
parity shifts (all 66 are weekly anyway). "Day off" = Saturday when empty and
the 8-stop warning are kept as today.

## 7. Rep mobile app

`/api/bootstrap`, `/api/journey`, check-in/out payloads do not change; the app
reads the same `journey.stops` in `sort` order. **No Dart change, no APK.**
Optional later (not in this job): show `visit_at` on the stop card — the server
already sends it, `JourneyStop` ignores it.

## 8. Permissions

- Route names stay under `ops.` → manager keeps them via the `'ops.'` prefix;
  branch manager stays excluded (as today); admin always.
- Middleware `role:admin,manager` on all new/kept routes, so `RoleAccessTest`'s
  role-matching keeps passing; new `ops.journeys.week` gets the same.
- `act.field.plan` (page `ops.assignments`, default everyone with the page)
  covers `ops.geo.save` + `ops.journeys.sync/copy`; without it both screens render
  `inert` / read-only. `act.field.wipe` unchanged (CRITICAL).
- Custom-roles catalog: add `ops.journeys.week` to NAV/SCREENS and the catalog;
  remove the dropped route names from the act map; refresh
  `tests/fixtures/permission-parity.json`. Existing custom-role overrides on
  `ops.journeys` / `ops.geo` keep working because the names are kept.

## 9. Tests

New feature tests: builder loads the day pre-numbered; save replaces the day in
order; move vs skip-and-name for other teams; `rep_id` filled only when null;
board sync keeps frequency/start/time; popover edit; copy; follow-up counts
(planned/visited/missed/off-plan, fortnightly not-due week); team scoping (403
on another team's rep); `act.field.plan` deny → 403 on save; check-in links only
when due. Then the full suite in chunks, and a QA click-through on `promax_qa`
(8010) with screenshots.

## 10. Calls I made (Ahmed can overrule any)

1. Keep the weekly pattern table (`journey_plans`); no dated plans for clients.
2. Map pins coloured by channel (leads colour by category).
3. Saving a route never overwrites an existing main rep; only fills an empty one.
4. Distribute-unassigned moves to the Assignments screen.
5. Board week shown Sat → Fri like leads.
6. No APK this round.
7. **A client may sit on several days** (kept from today: unique key includes
   weekday). Saving a client onto rep R / weekday W only touches plans on W:
   another rep's plan on W that the viewer controls is moved to R; plans on other
   weekdays are left alone (= the client gains a day). The board pool defaults to
   «غير مجدول» and the «الكل» chip lets you drop an already-planned client on a
   second day (today's behaviour). Never silently deletes rows.
8. **The board and builder edit the weekly pattern**, not one week. ‹ › only
   changes the dates shown and what is "due". Stops not due in the shown week
   (every-2/monthly) are drawn greyed with «مش الأسبوع ده»; the count badge and
   the > 8 warning count **due** stops of that week; nearest-first orders every
   stop of the pattern day.
9. **Rep list = `User::FIELD_WORK_ROLES`** (as today's board: sales, driver,
   promoter, plus the manager himself), team-scoped. Guards per role as today.
10. **Follow-up "visited"** = a *closed* visit by the **planned rep** to that
    client on that date (promoters: that day's `merch_visits`, as the app does).
    Open visit = «في الزيارة» (not missed). Today's undone stops = pending, not
    missed. Visits by others / to non-due clients count as off-plan for the rep
    who made them.
11. **Leads screens are not touched** in this job (client pages are their own
    views/controller; no shared refactor). The leads gaps noticed (planner rep list
    not team-scoped, `plannerSave` without `assertRep`, week follow-up not
    team-scoped, possible unique-index error on `plannerSave`) are a separate
    follow-up.
