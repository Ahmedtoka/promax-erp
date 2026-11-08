# Roles & Permissions — Design (2026-11-09)

Status: draft for Ahmed's approval. Decisions by Ahmed: live inheritance (option 1); web only; field-base custom roles see branch-level data on web screens; roles screen stays for all admins.

## 1. Intent

Ahmed wants to shape who sees and does what **from the UI, without code**:

> «عندي أمين مخزن شايف شاشات معينة… حابب أعمل رول اسمها مدير المخزن وأخليه
> مسؤول عن حاجة من مدير المبيعات… أعمل الرول وأقولها شوفي الصفحات دي
> والشاشات دي والزراير دي ووافقي على الأكشن دي.»
>
> «ممكن مندوب بيعمل صلاحيات زيادة، فهكرر البرمشن بتاعه وأزوّد له شاشات
> وأسمّيها سوبر مندوب.»

Plus: every existing role documented in detail, every permission explained
(what it opens, what data, how risky), so the admin knows **what** they grant
and **to whom**.

## 2. Goals / non-goals / anti-goals

Goals
- Custom roles created from the UI: Arabic + English name, a **base role**,
  web login yes/no, app login yes/no. Clone any role (built-in or custom),
  rename, deactivate, delete (deactivate/delete only with zero users).
- One matrix at four levels: menu group → page (sidebar entry) → screen
  (sub-route) → action/button, with tri-state (allow / deny / inherit).
- Reference: per-role sheet, per-key description (ar/en) + risk level,
  "who has this permission", compare two roles, change history.
- Professional UI: search, collapsible groups, effective-value + source
  badges, diff before save.

Non-goals
- **No new mobile-app screens/permissions.** Decided by Ahmed («خلينا على
  الداش بورد بس»). A custom role's app behaviour = its base role's.
- No record-level rules editor (per-branch/per-warehouse rules). Data scope
  comes from the base role (see §4).
- No change to the 136 existing `$user->role === 'x'` / `role:` checks.

Anti-goals (would be a failure even if it "works")
- Any path where the matrix grants `erp.worktime` (WorkTimeGate) or the AI
  gate (AiGate) to anyone but ADM-001, or grants the roles/permissions screen
  itself (privilege escalation).
- A custom role silently seeing company-wide data its base role wouldn't.
- Sidebar showing a link the middleware then 403s (the `Access` invariant).
- Breaking the live system: existing users/roles must behave exactly as
  today after deploy with zero custom roles.

## 3. Current state (what we build on)

- `App\Support\Access`: `SCREENS` (per built-in role, prefixes + `!`
  exclusions), `NAV` (22 groups, 141 pages), `ACTIONS` (65 `act.` keys, each
  `[label, page, default roles|null|[], covered routes]`), `HOME`,
  `WEB_ROLES`; resolution `allows()` = WorkTimeGate → admin bypass → user
  override → role override → role default; `resolveMap()` (group deny →
  longest route prefix → action covering route → group allow).
- Tables `role_permissions(role varchar30, perm, allow)` and
  `user_permissions`; `RolePermission::maps()` cached forever, flushed on save.
- `EnsureRole` honours user and role overrides before the `role:` list.
- `PermissionController` + `erp/perms.blade.php` (🔐 `erp.perms`): roles tab
  and users tab, tri-state per group/page/action.
- `User::ROLES` (13), `users.role` VARCHAR(20); validation
  `ErpController::userRules()`; API login whitelist in
  `Api\AuthApiController::login`.
- Activity centre: `ActivityLog` + `AppServiceProvider::AUDITED` observers.

## 4. Core decision: custom role = base role + live overrides (Ahmed chose "1")

- A custom role **always has a base role** (any built-in except `admin`).
- A user on a custom role keeps `users.role = <base role>` and gets
  `users.custom_role_id = <id>`. Therefore every hardcoded check, `role:`
  gate, data scope (`seesAllBranches`, `canSeeBranch`, warehouse ownership,
  `Scope::*`), API role checks, and app home screen keep working unchanged:
  the user **is** a base-role user for behaviour.
- Only two things differ: (1) screens/buttons come from the matrix layer of
  the custom role, (2) the displayed role label is the custom role's name.
- **Live inheritance** (Ahmed picked option 1): a custom role stores only its
  *differences*. "Inherit" in its matrix = whatever the base role currently
  resolves to (code default + base role overrides). Add a screen to أمين
  المخزن later → مدير المخزن gets it too unless it explicitly denies it.
- **Clone**:
  - from a built-in role X → new custom role, base = X, no rows (identical to
    X at creation, and stays linked).
  - from a custom role C → new custom role, base = C's base, C's rows copied
    (a snapshot of C's differences; it does not follow later edits to C —
    chains of live links are not allowed, one level only).
- The base role is **immutable** after creation (changing it would silently
  change data scope for its users). To change: clone onto a new base.
- Built-in roles stay: editable in the matrix as today, not renamable or
  deletable.

Resolution order for `Access::allows($user, $route)` becomes:

1. WorkTimeGate / AiGate / **LOCKED keys** (see §6) — before everything.
2. admin bypass (unchanged; custom roles cannot have base `admin`).
3. user override (`user_permissions`).
4. **custom role override** (`role_permissions` where role = `c:<id>`).
5. base role override (`role_permissions` where role = base).
6. base role default (`SCREENS`).

Same chain for `Access::action()` and for `EnsureRole` (custom override
checked between user and base-role override). `RoleAccessTest`'s invariant
("`role:` middleware ⇔ SCREENS") is unaffected for built-ins.

Login / home:
- Web: `isWebRole` = custom role? `custom.web_login` : base in `WEB_ROLES`.
  A custom role on a field base (e.g. سوبر مندوب on `sales_agent`) can get
  web access this way.
- App: custom role with `app_login = false` is refused at API login; with
  `true` it follows the base role's existing whitelist rules (the app shows
  the base role's screens; payload adds the custom label).
- Home: base `HOME` if allowed for this user, else first allowed NAV page,
  else a friendly "no screens assigned" page (never a 403 on login).

Data-scope ceiling — Ahmed chose branch-level is acceptable (2026-11-09); no per-screen rewrite. Caveat (shown as a warning in the UI when creating a custom role
on a field base): web screens scope rows by branch (`branch_id`) for non
manager roles; a user with no branch sees all branches. The create/edit-user
form requires a branch for custom roles whose base is a field role or
`branch_manager`.

## 5. Data model

- `roles` (new): `id`, `name_ar`, `name_en`, `base_role` (varchar 20),
  `cloned_from` (nullable string: built-in key or `c:<id>`), `web_login`,
  `app_login`, `description_ar`, `description_en` (nullable), `active`,
  `created_by`, timestamps. Model `App\Models\Role` added to `AUDITED`.
- `users.custom_role_id` nullable FK → `roles.id` (restrict on delete).
- `role_permissions.role` holds `c:<id>` for custom roles (fits varchar 30).
  Deleting a role deletes its rows.
- User form: one role dropdown listing built-ins then active custom roles
  (value `c:<id>`); saving sets `role = base`, `custom_role_id = id`.
  `userRules()` accepts either. Existing `required_if:role,warehouse_keeper`
  style rules apply via the base role.

## 6. Locked keys (never grantable by the matrix)

`Access::LOCKED`: `erp.worktime*` (WorkTimeGate), the AI gate routes
(AiGate), `erp.perms*` and the new roles routes. LOCKED means **override
rows are ignored** for these keys (not a hard gate before the admin
bypass): WorkTimeGate/AiGate keep their own Jad-only gates; `erp.perms*`
follows its `role:admin` gate only — all admins, as today (Ahmed, 2026-11-09). LOCKED
must be enforced in `EnsureRole` too (today a role-override row on
`erp.perms` would pass `role:admin`), and in `Access::action()`. They render
in the matrix as 🔒 read-only with the reason; `save` rejects them
server-side. A test asserts no override row can make them reachable.

User management (builder's call, marked): `act.team.manage` stays
grantable (e.g. to HR), but a non-admin holder can never assign or edit the
`admin` role or an admin's account/password, and cannot assign custom
roles; enforced in `userRules()`/update, with tests.

Web-enabled custom users (builder's call, marked): every direct
`in_array($user->role, Access::WEB_ROLES)` used to mean "is a web user"
(activity centre silent users, WorkTime list, perms users tab,
`EnsureScreenAccess`) switches to `Access::isWebRole($user)`. Middleware
such as `RequireAttendance` / `RequireWarehouseVisit` keeps following the
base role, unchanged.

## 7. Matrix levels

1. **Group** — `nav.group_x` (existing).
2. **Page** — NAV route (existing).
3. **Screen** (new level in the UI) — named routes under a page's prefix
   that are not already covered by an action (e.g. `erp.clients.show`,
   `erp.clients.statement`). Derived at runtime from `Route::getRoutes()`;
   GET routes only (writes belong to actions). Keys are the route names —
   `resolveMap` already supports them (longest prefix wins).
4. **Action / button** — `act.*` (existing 65) **plus an inventory pass**:
   every POST/PUT/PATCH/DELETE route under a NAV page whose `role:` gate is
   narrower than the page's audience, or whose button is shown to a subset
   of the page's viewers, gets an `act.` key with a label, and the blade
   wraps the button in `Access::action()`. A new test enforces: every
   non-GET named route under a NAV page is covered by an action or
   explicitly listed in an allow-list with a reason.

Routes outside NAV (e.g. `notifications`, `attendance.punch`, `docs`) are
listed under a synthetic group «شاشات عامة» so nothing is unmanageable.

## 8. Reference catalog

- `lang/ar/permref.php` + `lang/en/permref.php`: for every key (group,
  page, screen, action): `what` (what it opens/does), `data` (what data it
  exposes or changes). Screens may fall back to their page text.
- Risk level in code, `Access::RISK` (or a `PermissionCatalog` class):
  `low` (read own work), `medium` (read company data), `high` (writes money
  / stock / clients), `critical` (wipe, period reopen, GL rebuild, price
  propagation, payroll approve, team/password management). Default by rule
  (GET page = medium, action = high) with explicit overrides.
- Tests: every key has `what` in both languages and a risk level.

## 9. UI (screen `erp.perms`, admin only, `layouts.system`)

Tabs:
1. **الرولز** — cards/table of all roles: name, base, web/app badges, user
   count, # differences from base, status. Actions: open matrix, clone,
   rename/edit, deactivate, delete (disabled with reason when users > 0),
   "new role" wizard (name ar/en → base → web/app → optional clone source).
2. **المصفوفة** (role or user) — collapsible group → page → screens/actions
   tree; search box filtering by name/description/route; per row: tri-state
   segmented control (سماح / منع / وراثة), the **effective** result, a
   **source badge** (كود / الرول الأساسية / الرول دي / استثناء يوزر), risk
   chip, and the description inline (expand for data/risk). Filters:
   "only differences", "only high/critical". Bulk set per group.
   Save → **diff modal** (key, before → after, risk) → confirm.
3. **ورقة الرول** — printable per-role sheet: everything allowed/denied,
   grouped, with descriptions and risk; Excel export per the per-table
   convention. This is the "every existing role documented" deliverable and
   is always current because it's computed from the same resolver.
4. **مين معاه الصلاحية** — pick a key → roles (effective + source) and
   users (including user overrides).
5. **مقارنة** — two roles side by side, differences highlighted, "only
   differences" toggle.
6. **السجل** — permission/role change history from the activity centre.

Audit: every role create/edit/clone/delete is logged via `AUDITED`
(`Role` model). Each matrix save writes **one** `ActivityLog` row
(event `perms.saved`, subject = role or user, `changes` = the diff) —
`RolePermission`/`UserPermission` deletes are query-builder deletes and
bypass observers, so explicit logging is required.

All text via `lang/ar` + `lang/en`; nav link guarded by `Access::allows`.

## 10. Patterns borrowed

- **Salesforce** — profile + permission-set idea: base role (profile) +
  layered differences; "effective access with source" display.
- **Odoo** — separation of *access rights* (menus/screens, our matrix) from
  *record rules* (data scope, our base role); group inheritance (implied
  groups) ↔ live inheritance from the base role.
- **Zoho** — clone role, roles list with user counts.
- **Jira / Atlassian** — "who has this permission" view.
- **GitHub / Terraform plan** — diff before save.

## 11. Tests

- Extend `RoleAccessTest`; new `CustomRoleTest`: create/clone/rename/
  deactivate/delete rules; live inheritance (base change propagates; explicit
  deny survives); clone-from-custom copies rows; user on custom role keeps
  base behaviour (scope, `role:` gates) and gains/loses matrix screens;
  sidebar ⇔ middleware for custom roles (no link that 403s); LOCKED keys
  unreachable; web/app login flags; home fallback; diff + audit row written;
  catalog completeness (ar/en + risk); action inventory coverage test.
- Full suite stays green on `promax_test`.

## 12. Deploy

Migrations: `create_roles_table`, `add_custom_role_id_to_users`.
```
git pull
php artisan migrate --force --path=database/migrations/<roles migration>.php
php artisan migrate --force --path=database/migrations/<users column migration>.php
php artisan optimize:clear
```
With no custom roles created, behaviour is identical to today.

## 13. Left to the builder

- Exact component styling (follow existing `layouts.system` look, `_range`
  partial conventions, per-table Excel).
- Screen-level derivation details and the allow-list of non-GET routes that
  legitimately need no action key.
- Risk defaults per key and the Arabic/English wording of descriptions.
- Caching for custom roles (same pattern as `RolePermission::maps()`).
