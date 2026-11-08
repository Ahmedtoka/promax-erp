# Roles & Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Custom roles (base role + live overrides) managed from the UI, a 4-level permission matrix with tri-state, descriptions and risk, and reference views (role sheet, who-has, compare, history).

**Architecture:** A custom role is a `roles` row with an immutable `base_role`. Its users keep `users.role = base_role` (so all hardcoded checks and data scope are unchanged) plus `users.custom_role_id`. `Access` gains one resolution layer (`role_permissions` rows keyed `c:<id>`) between the user override and the base-role override, plus a `LOCKED` list that ignores override rows. The UI is a rebuilt `erp.perms` screen driven by a `PermissionMatrix` service.

**Tech Stack:** Laravel (PHP 8), Blade + Alpine (existing `layouts.system`), MySQL, PHPUnit on `promax_test`.

**Spec:** `docs/superpowers/specs/2026-11-09-roles-permissions-design.md`

## Global Constraints

- Never run migrate:fresh/seed/wipe or HTTP requests against DB `promax` (live data). Tests use `promax_test` (phpunit.xml). Click-through uses `promax_qa` (`.env.qa`, port 8010).
- No hardcoded Arabic in PHP logic: all text in `lang/ar/*.php` + `lang/en/*.php`.
- Views extend `layouts.system`; nav links guarded with `Access::allows`.
- `erp.worktime*` stays ADM-001 only (WorkTimeGate, checked before admin bypass); `agent.*` stays AiGate-only. Matrix can never grant them.
- Custom role key format in `role_permissions.role`: `c:<id>` (column is varchar 30).
- A custom role's `base_role` ∈ `User::ROLES` minus `admin`; immutable after create.
- Roles/permissions screens: `role:admin` (all admins), and LOCKED (override rows ignored).
- Behaviour with zero custom roles must be identical to today; the full suite (~1840) stays green.
- Commit on master, only files you changed (shared checkout). Never commit secrets (public repo).
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Review Focus

1. A custom role whose base role later gets a new `role_permissions` allow → custom-role users gain it (live inheritance) unless the custom role explicitly denies it.
2. Deleting/deactivating a role while a user (active *or* inactive) points at it → refused with a clear message, never orphaned users.
3. A user on a deactivated custom role (deactivated before this rule, or via SQL) → falls back to base-role behaviour, no 500.
4. An override row inserted directly in DB for `erp.perms`, `erp.roles.*`, `erp.worktime*`, `agent.*` → still not reachable (sidebar, `EnsureRole`, `Access::action`).
5. A custom role with no allowed web screens and `web_login = true` → login lands on a friendly "no screens" page, not a 403 loop.

---

### Task 1: Roles table, model, user link

**Files:**
- Create: `database/migrations/2026_11_09_000100_create_roles_table.php`
- Create: `database/migrations/2026_11_09_000110_add_custom_role_id_to_users.php`
- Create: `app/Models/Role.php`
- Modify: `app/Models/User.php` (relation, helpers, `roleLabel()`)
- Modify: `app/Providers/AppServiceProvider.php` (`AUDITED` += `Role::class`)
- Modify: `lang/ar/audit.php`, `lang/en/audit.php` (`model_Role` label)
- Test: `tests/Feature/CustomRoleModelTest.php`

**Interfaces:**
- Produces:
  - `roles`: `id, name_ar varchar(60), name_en varchar(60), base_role varchar(20), cloned_from varchar(30) null, web_login bool, app_login bool, description_ar text null, description_en text null, active bool default 1, created_by FK users null, timestamps`.
  - `users.custom_role_id` nullable FK → `roles.id`, `restrictOnDelete`.
  - `Role::key(): string` → `'c:'.$id`; `Role::displayName(): string` (locale-aware, falls back to the other name); `Role::users(): HasMany`; `Role::BASE_ROLES(): list<string>` static = `User::ROLES` keys minus `admin`; `Role::maps(): array<int, Role>` cached forever under `custom_roles_map`, `Role::flush()`; `Role::fromKey(string $k): ?Role` (parses `c:<id>`).
  - `User::customRole(): BelongsTo`; `User::activeCustomRole(): ?Role` (null if none or inactive, via `Role::maps()`); `User::permRoleKey(): ?string` (`activeCustomRole()?->key()`).
  - `User::roleLabel()` returns `activeCustomRole()->displayName()` when set, else current behaviour.

- [ ] **Step 1: Write failing tests** in `CustomRoleModelTest`:
  - `test_role_key_is_c_colon_id` — `Role::create([...])->key() === 'c:'.$role->id`.
  - `test_user_on_custom_role_shows_custom_label_but_keeps_base_role` — user `role='warehouse_keeper'`, `custom_role_id` = role «مدير المخزن» → `roleLabel()==='مدير المخزن'` (app locale ar), `$user->role==='warehouse_keeper'`, `isAdmin()` false.
  - `test_inactive_custom_role_falls_back_to_base_label` — role `active=false` → `activeCustomRole()` null, `roleLabel()` = base label.
  - `test_role_with_users_cannot_be_hard_deleted` — deleting the row throws `QueryException` (FK restrict).
  - `test_role_create_is_audited` — `ActivityLog` row with `subject_type` for Role after create.
- [ ] **Step 2: Run** `php artisan test --filter=CustomRoleModelTest` → FAIL (table missing).
- [ ] **Step 3: Implement** migrations, `Role` model (casts bools, `$fillable`, `saved`/`deleted` hooks call `flush()`), User helpers, AUDITED entry, lang label.
- [ ] **Step 4: Run** `php artisan test --filter=CustomRoleModelTest` → PASS.
- [ ] **Step 5: Commit** `feat(roles): roles table, Role model, users.custom_role_id`.

### Task 2: Access resolution with custom layer + LOCKED

**Files:**
- Modify: `app/Support/Access.php` (`allows`, `action`, new `customOverride`, `LOCKED`, `isLocked`, `isWebRole`, `home`)
- Modify: `app/Http/Middleware/EnsureRole.php`
- Create: `resources/views/errors/no-screens.blade.php` (+ `lang/*/perm.php` keys `no_screens_title`, `no_screens_body`)
- Modify: login redirect that calls `Access::home` (find with `grep -rn "Access::home" app`)
- Test: `tests/Feature/CustomRoleAccessTest.php`; extend `tests/Feature/RoleAccessTest.php`

**Interfaces:**
- Consumes: `User::permRoleKey()`, `User::activeCustomRole()`, `Role::fromKey()` (Task 1).
- Produces:
  - `Access::LOCKED = ['erp.perms', 'erp.roles', 'erp.worktime', 'agent']` (prefixes).
  - `Access::isLocked(string $key): bool` — true for any key matching a LOCKED prefix (route names; `act.` keys whose covered routes are all locked; `nav.` never).
  - `Access::customOverride(?User $user, string $routeName): ?bool` — `resolveMap(RolePermission::mapFor($user->permRoleKey()), $route)`; null if no custom role.
  - `Access::effectiveFor(string $roleKey, string $routeName): bool` — for `c:<id>`: custom map ?? base `roleEffective`; for built-in: `roleEffective`. Used by matrix/sheet/compare.
  - `Access::actionFor(string $roleKey, string $actKey): bool` — same layering for actions (no user layer).
  - `Access::home(?User)`: base `HOME` if `allows()`, else first allowed NAV route, else `'perms.no_screens'` (new GET route `/no-screens` named `no_screens`, not LOCKED, always allowed for logged-in web users).
  - `Access::isWebRole(?User)`: custom role → `web_login`; else current.
- Resolution order in `allows()`: WorkTimeGate → (`isLocked` → skip all override layers, go straight to admin bypass / role default) → admin → user → custom → base-role override → base default. `action()`: admin → user → custom → base-role map → code default; locked action keys skip override layers. `EnsureRole`: user override → custom override → base-role override → `role:` list; all three override checks skipped when `isLocked($routeName)`.

- [ ] **Step 1: Write failing tests** in `CustomRoleAccessTest` (users created with factory on `promax_test`, `RolePermission::flush()`/`Role::flush()` in setUp):
  - `test_custom_role_inherits_base_screens_live` — base `warehouse_keeper`; assert `allows($u,'wh.index')`; then add `RolePermission(role='warehouse_keeper', perm='erp.reports', allow=1)` + flush → `allows($u,'erp.reports')` true.
  - `test_custom_deny_beats_base_allow` — `c:<id>` deny `wh.index` → false while plain keeper true.
  - `test_custom_allow_passes_role_middleware` — custom allow `erp.purchasing.approve` → `EnsureRole` with `role:admin` passes (call middleware directly with a fake request bound to that route name, as `RoleAccessTest` does).
  - `test_user_override_beats_custom` — user deny `wh.index` with custom allow → false.
  - `test_locked_keys_ignore_override_rows` — for each of `erp.perms`, `erp.roles.store`, `erp.worktime`, `agent.ask`: insert allow rows for the custom key, the base role and the user → `allows` false for a non-admin, `EnsureRole` 403.
  - `test_inactive_custom_role_behaves_as_base` — custom deny on `wh.index`, role `active=false` → true.
  - `test_web_login_flag` — custom on `sales_agent` with `web_login=true` → `isWebRole` true; same role `web_login=false` on base `accountant` → false.
  - `test_home_falls_back_when_base_home_denied` — keeper custom denies `wh.index` → `home()` returns first allowed NAV route; deny everything → `'no_screens'`.
  - `test_sidebar_matches_middleware_for_custom_roles` — for a custom role with mixed overrides, every route in `navFor()` passes `EnsureScreenAccess` + `EnsureRole` (reuse RoleAccessTest helper pattern).
- [ ] **Step 2: Run** `php artisan test --filter=CustomRoleAccessTest` → FAIL.
- [ ] **Step 3: Implement** the Access/EnsureRole changes, no-screens route + view.
- [ ] **Step 4: Run** `php artisan test --filter="CustomRoleAccessTest|RoleAccessTest|AccessTest"` → PASS.
- [ ] **Step 5: Commit** `feat(access): custom-role layer, LOCKED keys, home fallback`.

### Task 3: Role management (create / clone / edit / deactivate / delete)

**Files:**
- Create: `app/Http/Controllers/RoleController.php`
- Modify: `routes/web.php` (next to `perms` routes, all `->middleware('role:admin')`)
- Create: `lang/ar/role.php`, `lang/en/role.php`
- Test: `tests/Feature/RoleManagementTest.php`

**Interfaces:**
- Consumes: `Role`, `Access::isLocked`, `RolePermission::flush`.
- Produces routes: `erp.roles.store` POST `/erp/roles`, `erp.roles.clone` POST `/erp/roles/clone` (`source` = built-in key or `c:<id>`, `name_ar`, `name_en`, `web_login`, `app_login`), `erp.roles.update` PUT `/erp/roles/{role}` (names, descriptions, web/app flags — never `base_role`), `erp.roles.toggle` POST `/erp/roles/{role}/toggle`, `erp.roles.destroy` DELETE `/erp/roles/{role}`.
- Rules: `name_ar`/`name_en` required, max 60, unique among roles and not equal to a built-in label; `base_role` in `Role::BASE_ROLES()`. Clone from built-in X: base = X, no rows, `cloned_from = X`. Clone from `c:<id>`: base = source base, copy its `role_permissions` rows, `cloned_from = 'c:<id>'`. Toggle-to-inactive and destroy refused (back with error `role.has_users`, count shown) when `users()->exists()` (active or not). Destroy deletes its `role_permissions` rows in the same transaction. Each write calls `ActivityLog::record('roles.cloned' | ...)` only where AUDITED observer doesn't already cover it (clone records the source).

- [ ] **Step 1: Write failing tests**: `test_admin_creates_role_with_base`, `test_clone_builtin_has_no_rows_and_same_effective_access` (for every NAV route: `effectiveFor('c:<id>', r) === effectiveFor('warehouse_keeper', r)`), `test_clone_custom_copies_rows_with_same_base`, `test_base_role_cannot_change_on_update`, `test_admin_base_is_rejected`, `test_cannot_deactivate_or_delete_with_users` (incl. inactive user), `test_delete_removes_permission_rows`, `test_non_admin_gets_403_even_with_override_row` (accountant + allow row on `erp.roles.store`).
- [ ] **Step 2: Run** `php artisan test --filter=RoleManagementTest` → FAIL.
- [ ] **Step 3: Implement** controller, routes, lang.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(roles): create, clone, edit, deactivate, delete custom roles`.

### Task 4: Assigning users; login; "web user" lists

**Files:**
- Modify: `app/Http/Controllers/ErpController.php` (`userRules`, `storeUser`, `updateUser`, password route handler)
- Modify: `resources/views/erp/team.blade.php` (role dropdown ~L217, filter ~L41, row data ~L99, Alpine `role`)
- Modify: `app/Http/Controllers/Api/AuthApiController.php` (`login`, `userPayload`)
- Modify: `app/Http/Controllers/ActivityController.php:109,160`, `app/Http/Controllers/WorkTimeController.php:185`, `app/Http/Controllers/PermissionController.php` users list → web-user query helper
- Modify: `app/Models/User.php` — `scopeWebUsers(Builder $q)`: `role in WEB_ROLES` (excluding users whose active custom role has `web_login=0`) OR active custom role with `web_login=1`.
- Test: `tests/Feature/CustomRoleAssignmentTest.php`

**Interfaces:**
- Consumes: `Role`, `Access::isWebRole`.
- Produces: form value for a custom role is `c:<id>`; `userRules()` accepts `in(User::ROLES keys) ∪ active c:<id>`; a prepare step maps `c:<id>` → `role = base_role`, `custom_role_id = id`; built-in choice sets `custom_role_id = null`. Base-role-dependent rules (`required_if:role,warehouse_keeper`) evaluate on the mapped base. Branch required when the chosen custom role's base is in `User::FIELD_ROLES` or `branch_manager` (spec §4 caveat).
- Non-admin actors (holders of `act.team.manage`): cannot choose `admin`, cannot choose any `c:<id>`, cannot edit/reset password of a user whose `role==='admin'` → 403.
- API: `login` refuses (403 `api.no_app_access`) when `activeCustomRole()?->app_login === false`; otherwise unchanged. `userPayload` adds `'custom_role' => activeCustomRole()?->displayName()`; `role` stays the base key (app unchanged), `role_label` already custom via Task 1.

- [ ] **Step 1: Write failing tests**: `test_admin_assigns_custom_role_sets_base_and_id`, `test_switching_back_to_builtin_clears_custom_role`, `test_keeper_based_custom_role_requires_warehouse`, `test_field_based_custom_role_requires_branch`, `test_team_manager_cannot_assign_admin_or_custom_or_edit_admin` (accountant with user override `act.team.manage` allow), `test_api_login_blocked_when_app_login_off`, `test_api_payload_keeps_base_role_and_custom_label`, `test_web_users_scope_includes_web_enabled_field_custom_role`.
- [ ] **Step 2: Run** `php artisan test --filter=CustomRoleAssignmentTest` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `--filter="CustomRoleAssignmentTest|Team|AuthApi"` → PASS.
- [ ] **Step 5: Commit** `feat(roles): assign custom roles to users, app/web login flags`.

### Task 5: Screen level + action inventory

**Files:**
- Modify: `app/Support/Access.php` — `screensOf(string $page): list<string>`, `PUBLIC_GROUP = 'nav.group_public'`, `PUBLIC_PAGES` (named web routes outside NAV that users reach: `notifications`, `attendance.punch`, `docs`, …), new `act.` keys
- Modify: blades whose buttons map to new act keys (wrap in `@if (\App\Support\Access::action(auth()->user(), 'act.x'))`)
- Modify: `lang/*/perm.php` (labels `perm.act_*` for new keys, `nav.group_public` in `lang/*/nav.php`)
- Test: `tests/Feature/PermissionInventoryTest.php`

**Interfaces:**
- Produces: `Access::screensOf($page)` = named GET routes `r` with `r !== $page`, `matches(r, $page)`, not LOCKED, not covered by any action's routes, sorted; computed from `Route::getRoutes()` once per request (static cache). `Access::ACTION_EXEMPT: array<string,string>` route ⇒ reason for non-GET routes that need no action key (e.g. self-service like `hr.leave.store`).
- Inventory rule (enforced by test): every named non-GET route that lies under a NAV page (or PUBLIC_PAGES) is covered by some `ACTIONS[*][3]` entry, or is in `ACTION_EXEMPT`, or is LOCKED. Fix failures by adding `act.` keys (label + page + default roles copied from the route's `role:` middleware: `role:admin` only → `[]`; same audience as page → `null`; else list) and wrapping the button; or by exempting with a reason when the route is self-service.

- [ ] **Step 1: Write failing tests**: `test_every_write_route_under_a_page_is_an_action_or_exempt` (lists offenders), `test_new_action_defaults_match_route_middleware` (for each act key: every built-in role for which `action()` default is true passes the route's `role:` list), `test_screens_of_clients_include_show_but_not_writes`.
- [ ] **Step 2: Run** `php artisan test --filter=PermissionInventoryTest` → FAIL with the offender list.
- [ ] **Step 3: Implement** `screensOf`, public group, then work the offender list to zero.
- [ ] **Step 4: Run** `--filter="PermissionInventoryTest|RoleAccessTest"` → PASS.
- [ ] **Step 5: Commit** `feat(perms): screen level and full button/action inventory`.

### Task 6: Reference catalog (descriptions + risk)

**Files:**
- Create: `app/Support/PermissionCatalog.php`
- Create: `lang/ar/permref.php`, `lang/en/permref.php`
- Test: `tests/Feature/PermissionCatalogTest.php`

**Interfaces:**
- Consumes: `Access::NAV`, `ACTIONS`, `screensOf`, `PUBLIC_PAGES`.
- Produces: `PermissionCatalog::keys(): list<string>` (groups, pages, screens, actions), `PermissionCatalog::risk(string $key): string` ∈ `low|medium|high|critical` (defaults: group/page/screen = `medium`, action = `high`; explicit `PermissionCatalog::RISK` overrides — `critical` at least for `act.overview.wipe`, `act.overview.demo`, `act.prices.propagate`, `act.gl.admin`, `act.costing.admin`, `act.hr.payroll_approve`, `act.hr.settings`, `act.team.manage`, `act.import.run`, `act.custody.adjust`; `low` for `docs`, `notifications`, `attendance.punch`, `hr.leave`, `hr.permissions`), `PermissionCatalog::describe(string $key): array{what:string, data:?string}` from `permref.<key with dots → _>`; screens fall back to their page's text.
- `permref` entries required for every group, page and action key: `what` (one sentence: what it opens/does) and `data` (what data it shows or changes), Egyptian-Arabic in ar, plain English in en.

- [ ] **Step 1: Write failing tests**: `test_every_group_page_and_action_has_ar_and_en_description`, `test_every_key_has_a_valid_risk`, `test_screens_fall_back_to_page_description`.
- [ ] **Step 2: Run** → FAIL listing missing keys.
- [ ] **Step 3: Write** the catalog and all descriptions.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(perms): permission reference catalog with risk levels`.

### Task 7: PermissionMatrix service (tree, save with diff, audit)

**Files:**
- Create: `app/Services/PermissionMatrix.php`
- Modify: `app/Http/Controllers/PermissionController.php` (use the service; `saveRole` accepts `c:<id>`; validKeys = `PermissionCatalog::keys()` minus locked)
- Test: `tests/Feature/PermissionMatrixTest.php`

**Interfaces:**
- Consumes: Tasks 2, 5, 6.
- Produces:
  - `PermissionMatrix::forRole(string $roleKey): array` and `forUser(User $u): array` → list of groups `{key, label, override, effective, source, pages:[{key, label, icon, override, effective, source, risk, what, data, locked, screens:[…same row shape], actions:[…same row shape]}]}`; `source` ∈ `code|base|role|user`. `override` = row in the layer being edited (null = inherit); `effective` = result after all layers for that subject.
  - `PermissionMatrix::diff(string|User $subject, array $perms): list<array{key, before:?bool, after:?bool, effective_before:bool, effective_after:bool, risk}>` (only changed keys).
  - `PermissionMatrix::save(string|User $subject, array $perms): list<diff rows>` — transaction; ignores unknown and LOCKED keys; writes/deletes rows; flushes caches; one `ActivityLog::record('perms.saved', ['subject_type' => 'Role'|'User'|'BuiltinRole', 'subject_id' => id|null, 'title' => role key or user name, 'changes' => json diff])`.
  - Routes: `erp.perms.diff` POST (JSON preview, `role:admin`, LOCKED by prefix), existing `perms.role.save` / `perms.save` call `save()`.

- [ ] **Step 1: Write failing tests**: `test_source_badges_code_base_role_user` (built-in default = code; base override visible in custom matrix as `base`; custom row = `role`; user row = `user`), `test_diff_lists_only_changes_with_effective_before_after`, `test_save_ignores_locked_and_unknown_keys`, `test_save_writes_one_activity_row_with_diff`, `test_save_on_custom_role_does_not_touch_base_rows`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `--filter="PermissionMatrixTest|Perm"` → PASS.
- [ ] **Step 5: Commit** `feat(perms): matrix service with effective/source, diff and audit`.

### Task 8: The UI

**Files:**
- Modify/replace: `resources/views/erp/perms.blade.php` → split into `resources/views/erp/perms/index.blade.php` + partials `_roles.blade.php`, `_matrix.blade.php`, `_sheet.blade.php`, `_who.blade.php`, `_compare.blade.php`, `_history.blade.php`
- Modify: `PermissionController` (actions `index` with `?tab=roles|matrix|sheet|who|compare|history`, `sheet` print view, `sheetExcel`, `who`, `compare`, `history`)
- Modify: `lang/*/perm.php`
- Test: `tests/Feature/PermissionScreenTest.php`

**Interfaces:**
- Consumes: Tasks 3, 6, 7. Excel export follows the existing per-table export pattern (find with `grep -rn "xlsx\|Excel" app/Http/Controllers | head`).
- UI behaviour (spec §9): roles tab = table of all roles (built-in + custom) with base, web/app badges, user count, "differences from base" count, status, actions (open, clone, edit, deactivate, delete — disabled with tooltip reason when it has users), "new role" modal (name ar/en → base → web/app → optional clone source; when base ∈ `User::FIELD_ROLES` or `branch_manager` and web is on, show the warning `perm.scope_branch_warning`: web screens will show the user's whole branch). Matrix tab = search (label/description/route), collapse/expand all, per-row segmented control سماح/منع/وراثة, effective dot, source badge, risk chip, inline `what` (expand for `data`), filters "only differences" / "high+critical only", bulk set per group, 🔒 rows read-only with reason, sticky save bar → calls `erp.perms.diff` → modal listing changes with risk → confirm submits. Sheet tab = printable (`@media print`) per role, grouped, allowed ✓ / denied ✗, with descriptions + risk, Excel button. Who tab = key picker (searchable) → roles table (effective + source) and users with overrides. Compare tab = two role pickers, side-by-side, "only differences" toggle. History tab = `perms.saved` + Role activity rows, newest first, expandable diff.

- [ ] **Step 1: Write failing tests**: `test_each_tab_renders_for_admin` (200 + a known label per tab), `test_non_admin_403`, `test_sheet_marks_allowed_and_denied_for_keeper`, `test_who_has_lists_role_and_user_override`, `test_compare_shows_only_differences`, `test_sheet_excel_downloads`.
- [ ] **Step 2: Run** `php artisan test --filter=PermissionScreenTest` → FAIL.
- [ ] **Step 3: Implement** (use the `impeccable`/frontend-design guidance for visual polish, keeping the existing system look).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Click-through on QA** — start the QA server from `.claude/launch.json` (port 8010, `.env.qa`, DB `promax_qa`), log in as an admin on QA, create «مدير المخزن» by cloning أمين المخزن, grant `erp.reports`, view diff, save, assign a QA user, log in as that user and confirm sidebar + screens; screenshot each tab.
- [ ] **Step 6: Commit** `feat(perms): roles & permissions UI — matrix, sheet, who-has, compare, history`.

### Task 9: Full suite, docs, deploy notes

- [ ] **Step 1:** `php artisan test` (background) → all green; fix regressions.
- [ ] **Step 2:** Add a short in-app guide entry if `docs` screen has a permissions section (`grep -rn "perms" resources/views/docs`), in ar/en.
- [ ] **Step 3:** Commit; write deploy commands for Ahmed:

```
git pull
php artisan migrate --force --path=database/migrations/2026_11_09_000100_create_roles_table.php
php artisan migrate --force --path=database/migrations/2026_11_09_000110_add_custom_role_id_to_users.php
php artisan optimize:clear
```
