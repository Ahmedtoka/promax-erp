# Client route planner Implementation Plan

> Executed natively (inline) in the session that wrote it — Ahmed approved the spec («موافق، ابدأ») and asked to start building.

**Goal:** Replace «خطط السير» + «الخريطة الجغرافية وخط السير» with the leads-style route builder, week board and week follow-up for real clients.

**Architecture:** Storage stays `journey_plans` (weekly pattern). New `RoutePlannerController` (builder + save + follow-up) and a shared `App\Services\RoutePool` (team pool query + client JSON rows + last-visit map). The week board stays `JourneyController::index/sync/copyFrom/wipe` with a rewritten view and a `sync` that also writes frequency / first date / time.

**Spec:** `docs/superpowers/specs/2026-11-11-client-route-planner-design.md`

## Global Constraints
- Views extend `layouts.system`; `.fl` captions; small buttons; per-table Excel (`window.xlsx` pattern used in other screens).
- All strings in `lang/ar|en/journey.php`; no Arabic in PHP logic.
- Tiles `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png` only.
- Routes inside `role:admin,manager`, names `ops.geo`, `ops.geo.save`, `ops.journeys`, `ops.journeys.week`, `ops.journeys.sync|copy|wipe`, `ops.geo.distribute|assign`.
- Write actions gated by `act.field.plan`; wipe by `act.field.wipe`.
- Weekday values = Carbon `dayOfWeek`; display order Sat→Fri.

## Review Focus
- Save from builder for a client planned by another team on the same weekday → skipped and named, never 500.
- Builder save with an empty sequence → clears that rep/weekday (explicit confirm in UI).
- Fortnightly stop in its off week → greyed on board, not "planned" on follow-up.
- Manager viewing another team's rep (`?rep=`) → falls back / 403 on save.
- Client without location → listed under map, can be added, does not break the polyline.

## Tasks
1. `RoutePool` service extracted from `JourneyController::index` (pool query, `row()`, `lastVisits()`); index uses it. Test: existing suite unchanged.
2. Builder: `GET /ops/route-builder` (`ops.geo`), old `/ops/geo-planner` → redirect; `POST /ops/route-builder` (`ops.geo.save`): rep_id, weekday, every_weeks (new stops), starts_on?, visit_at?, ids[] ordered. Day becomes exactly `ids`. Tests `RouteBuilderTest`.
3. Board view rewrite (leads planner UX + drag/drop + popover + month tab + Excel); `sync` accepts `rows.*.every_weeks|starts_on|visit_at`. Tests in `RouteBoardTest`.
4. Follow-up `GET /ops/journeys/week` (`ops.journeys.week`) + `Journeys::weekFollow()`. Tests `RouteFollowTest`.
5. Distribute dialog on `ops.assignments`; remove `geo`, `geoZone`, `geoPlan`, `geoUnplan`, `store`, `destroy`, `reorder` + `geo_planner.blade.php`; check-in links plan only when due.
6. Access NAV/SCREENS, catalog, parity fixture, lang, ScopeGuard/ActionGoverned tests updated.
7. Full suite in chunks; QA click-through on promax_qa:8010; commit; server commands.
