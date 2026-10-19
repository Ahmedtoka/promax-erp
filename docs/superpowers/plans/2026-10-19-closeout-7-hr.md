# Close-out Lane 7: HR, attendance for all, leave and payroll — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An employee file separate from users, attendance for every employee (app, dashboard button, supervisor on behalf of workers without a login), leave with balances and manager approval (web + API), and a monthly payroll (draft → admin approval → paid from a safe/bank → payslips) posted to the GL per employee cost center.

**Architecture:** New `App\Models\Employee` (optional `user_id`) is the anchor. `App\Services\Attendance` stays the only writer of attendance rows and learns `employee_id`, `source` and the supervisor path. Leave lives in `App\Services\Hr\Leave`. Payroll math is pure (`App\Services\Hr\PayrollMath`), the run lifecycle is `App\Services\Hr\Payroll`, and GL lines come from `App\Services\Hr\PayrollGl`, reached from `Rules::linesFor` and `Ledger::sources` through one line each (Lanes 3/4 also edit those files).

**Tech Stack:** Laravel 12, MariaDB 10.4, Blade, PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-10-19-promax-closeout-design.md` §3.6, §3.1.2 (insurance/tax borne per employee), §4, §4.1. Master plan: `docs/superpowers/plans/2026-10-19-closeout-master.md` (Global Constraints, Interfaces, API contract for Lane 8). Lane 8 response shapes: `_wt/close-8/docs/superpowers/plans/2026-10-19-closeout-8-app.md` "Server contract".

## Global Constraints

- Doctrine in `promax-skills/promax-system/SKILL.md` is binding (Scope first, `DB::transaction` for money, `Ledger` only GL writer, `Access::action` on buttons, new screens in `Access::SCREENS` + `Access::NAV`).
- Every migration guarded (`hasTable`/`hasColumn`); time columns `dateTime()` never `timestamp()`; Africa/Cairo.
- Every UI string in `lang/ar` and `lang/en`, same key; `php artisan promax:i18n-check` clean. New HR keys go in a new file pair `lang/{ar,en}/hrm.php`; attendance keys stay in `hr.php`.
- Every table screen: `App\Support\DateRange` from/to on its business date + server export `?export=1` (`App\Support\Csv::download`, same filtered query before pagination).
- Mobile document actions accept `idem_key` (`App\Support\Idempotency`).
- Error JSON key is `message`, never `error`. Rejections throw `App\Exceptions\Rejected` inside the transaction and become 422 (API) or `back()->withErrors` (web).
- Additive only. Existing punch flow, payloads and routes keep working; `POST /api/attendance/punch` stays open to every token (admin without an employee record keeps punching, `ApiContractTest::test_the_routes_nobody_calls_are_gone`).
- Money `decimal(14,2)`, rates `decimal(7,4)`; EGP only.
- Tests run on `promax_test_7` (phpunit.xml, skip-worktree). Never write to DB `promax`, never touch port 8020, no push/merge.
- Seeded legal values (insurance rates, insurable wage min/max, tax brackets, leave days) are **editable defaults the owner must verify**: the settings screen shows a warning banner `hrm.defaults_verify` until the admin saves the screen once (`hr_rates_verified_at` setting).

## Review Focus

1. **Employee without a login punched by a supervisor who is not their manager** → 403 (Scope), no punch row. Owner: Task 2 (`test_supervisor_cannot_punch_someone_elses_worker`).
2. **Leave request that overlaps an existing pending/approved request, or exceeds the remaining balance** → 422 with the reason; the balance never goes negative even when two approvals race. Owner: Task 3 (`test_overlapping_request_is_refused`, `test_approval_beyond_balance_is_refused`).
3. **Same mobile leave request sent twice with the same `idem_key`** → one row, second call returns the first `request`. Owner: Task 3 (`test_same_idem_key_returns_the_same_request`).
4. **Payroll for an employee hired mid-month, with company-borne insurance/tax, or insurable wage above the max** → prorated basic, net equals the agreed amount, insurance on the capped wage; the GL entry balances to the piaster. Owner: Tasks 4, 6, 7.
5. **Payroll approved or paid into a closed GL month from the web** → rejected with the month name (`Ledger::assertOpen`); paying twice → second call refused, one payment entry. Owner: Task 6/7 (`test_paying_twice_is_refused`, `test_approve_in_closed_period_is_refused`).

---

## File structure

- `database/migrations/2026_10_19_000700_hr_employees.php` — employees.
- `database/migrations/2026_10_19_000710_attendance_for_employees.php` — attendance columns.
- `database/migrations/2026_10_19_000720_hr_leave.php` — leave_balances, leave_requests.
- `database/migrations/2026_10_19_000730_hr_advances.php` — employee_advances, hr_penalties.
- `database/migrations/2026_10_19_000740_hr_payroll_runs.php` — payroll_runs, payslips, payslip_lines, payroll_payments.
- Models: `app/Models/Employee.php`, `LeaveBalance.php`, `LeaveRequest.php`, `EmployeeAdvance.php`, `HrPenalty.php`, `PayrollRun.php`, `Payslip.php`, `PayslipLine.php`, `PayrollPayment.php`.
- Services: `app/Services/Hr/Employees.php` (seed from users), `app/Services/Hr/Leave.php`, `app/Services/Hr/HrSettings.php`, `app/Services/Hr/PayrollMath.php`, `app/Services/Hr/Payroll.php`, `app/Services/Hr/PayrollGl.php`, `app/Services/Hr/PayrollCost.php`; modify `app/Services/Attendance.php`.
- Controllers: `app/Http/Controllers/Hr/EmployeeController.php`, `Hr/AttendanceHrController.php` (dashboard punch, supervisor, punches report), `Hr/LeaveController.php`, `Hr/PayrollController.php`, `Hr/AdvanceController.php`, `Hr/HrSettingsController.php`, `Api/LeaveApiController.php`; modify `Api/AttendanceApiController.php`.
- Commands: `app/Console/Commands/HrEmployeesSeed.php` (`promax:hr-employees-seed`), `HrLeaveSeed.php` (`promax:hr-leave-seed`), `HrGlSeed.php` (`promax:hr-gl-seed`).
- Views: `resources/views/hr/*.blade.php`, `resources/views/partials/_punch.blade.php`; view composer `app/View/Composers/PunchComposer.php`.
- Wiring: `routes/web.php` (group `hr.` + `attendance.punch`), `routes/api.php` (leave), `app/Support/Access.php`, `app/Models/User.php` (role `hr`), `lang/{ar,en}/hrm.php`, `lang/{ar,en}/enums.php` (role label), `app/Support/Idempotency.php` (const), `app/Providers/AppServiceProvider.php` (composer), `app/Services/Gl/Rules.php` (one match arm), `app/Services/Gl/Ledger.php` (one concat in `sources`).
- Tests: `tests/Feature/Hr/EmployeeTest.php`, `AttendanceForAllTest.php`, `LeaveTest.php`, `PayrollMathTest.php`, `AdvancesTest.php`, `PayrollRunTest.php`, `PayrollGlTest.php`; additions to `tests/Feature/ApiContractTest.php`.

---

### Task 1: Employee file, `hr` role, employee step of the resolver

**Files:** migration `000700`, `app/Models/Employee.php`, `app/Services/Hr/Employees.php`, `app/Console/Commands/HrEmployeesSeed.php`, `app/Http/Controllers/Hr/EmployeeController.php`, `resources/views/hr/employees.blade.php`, `resources/views/hr/employee_form.blade.php` (or a dialog in the index), routes, Access, User, lang. Test `tests/Feature/Hr/EmployeeTest.php`.

**Interfaces (produces):**
- Table `employees`: `id`, `code` varchar(20) unique, `user_id` nullable unique FK users nullOnDelete, `name` varchar(120), `name_en` nullable, `cost_center_id` nullable FK cost_centers nullOnDelete, `department` varchar(80) nullable, `job_title` varchar(80) nullable, `manager_id` nullable FK employees nullOnDelete, `hire_date` date nullable, `end_date` date nullable, `contract_path` nullable, `national_id` varchar(14) nullable, `insurance_no` varchar(20) nullable, `pay_method` varchar(10) default `cash` ∈ `cash|bank`, `bank_account` varchar(40) nullable, `basic_salary` decimal(14,2) default 0, `allowances` decimal(14,2) default 0, `insurable_wage` decimal(14,2) nullable, `insured` bool default true, `taxable` bool default true, `employee_share_borne_by` varchar(10) default `employee` ∈ `employee|company`, `attendance_required` bool default true, `active` bool default true, timestamps.
- `Employee::PAY_METHODS = ['cash','bank']`, `Employee::BORNE_BY = ['employee','company']`; relations `user()`, `costCenter()`, `manager()`, `reports()` (HasMany manager_id); `displayName()` (HasBilingualName); `static forUser(?User $u): ?self` (memoized per request is fine); `isManagedBy(User $u): bool` (true when `$u->role` ∈ admin|hr, or `$u`'s employee is this employee's `manager_id`); `scopeVisibleTo($q, User $u)` (admin/hr/accountant: all; otherwise self + direct reports); `static nextCode(): string` (`EMP-1001`, digits per feature-dev §2).
- `User::ROLES['hr'] = 'موارد بشرية'`; `lang/*/enums.php` role label; `Access::WEB_ROLES += 'hr'`, `HOME['hr'] = 'hr.employees'`, `SCREENS['hr'] = ['hr.', 'erp.attendance', 'attendance.punch', '!hr.payroll.approve', '!hr.payroll.pay', '!hr.settings.save']`.
- `NAV['nav.group_hr']` gains (keep the three existing items first): `hr.employees` 🪪 `hrm.nav_employees`; `hr.attendance.supervisor` 👷 `hrm.nav_supervisor`; `hr.attendance.punches` 📍 `hrm.nav_punches`; `hr.leave` 🌴 `hrm.nav_leave`; `hr.advances` 💵 `hrm.nav_advances`; `hr.payroll` 🧾 `hrm.nav_payroll`; `hr.settings` ⚙️ `hrm.nav_settings`. (Later tasks add their routes; this task registers only `hr.employees*` NAV/SCREENS lines it can test, the others land with their task.)
- Routes (`Route::prefix('hr')->name('hr.')`, inside the `auth`,`screen` group): `GET /hr/employees` `hr.employees` (`role:admin,hr,accountant`), `POST /hr/employees` `hr.employees.store`, `PUT /hr/employees/{employee}` `hr.employees.update`, `GET /hr/employees/{employee}/contract` `hr.employees.contract` (download) — writes `role:admin,hr`, action key `act.hr.employees` = `['hr']` covering store/update.
- `App\Services\Hr\Employees::seedFromUsers(bool $dryRun = false): array{created:int,linked_days:int,skipped:int}` — one employee per active user without one (`code` = user code or `nextCode()`, names copied, `cost_center_id` = `CostCenter::forWarehouse($user->warehouse)?->id` when the user has `warehouse_id`, else null), then backfill `attendance_days.employee_id`/`attendance_punches.employee_id` where `user_id` matches and `employee_id` is null (only once Task 2's columns exist: `Schema::hasColumn` guard). Idempotent. Command `promax:hr-employees-seed {--dry-run}` prints the counts.
- Screen `hr.employees`: searchbar (q, cost center, department, active), DateRange on `hire_date`, `?export=1` CSV (code, name, cost center, department, job title, manager, hire date, pay method, insured, taxable, borne by, basic, allowances, active). National id and insurance number show on the edit form only, not in the list or export. Create/edit dialog with `enctype="multipart/form-data"` for the contract (stored `storage/app/hr/contracts`, served only through `hr.employees.contract` with `Employee::visibleTo`).

- [ ] **Step 1: Failing tests** in `tests/Feature/Hr/EmployeeTest.php`:
  - `test_hr_creates_an_employee_with_defaults`: POST store as role `hr` (name, code empty) → 302; row has `insured=1`, `taxable=1`, `employee_share_borne_by='employee'`, `pay_method='cash'`, code `EMP-1001`.
  - `test_employee_without_login_is_allowed`: created with `user_id` null → exists, `forUser(null)` null.
  - `test_manager_must_be_another_employee`: `manager_id` = own id on update → validation error `manager_id`.
  - `test_accountant_sees_list_but_cannot_store`: GET 200, POST 403.
  - `test_export_respects_date_range`: two employees hired 2026-01-10 and 2026-09-01, `?from=2026-09-01&export=1` → CSV contains the second code only.
  - `test_seed_from_users_is_idempotent`: 2 active users → `['created'=>2,...]`; second run `created` 0.
  - `test_resolver_uses_employee_cost_center`: a non-sales model (e.g. `new \App\Models\Expense(['employee_id'=>$e->id])`, attribute set with `forceFill`) with no warehouse → `CostCenterResolver::for()` returns `$e->cost_center_id`.
  - `test_hr_role_gets_403_outside_its_screens`: GET `/gl/accounts` as `hr` → 403; `/hr/employees` → 200.
- [ ] **Step 2:** `php artisan test --filter=EmployeeTest` → FAIL (table missing).
- [ ] **Step 3:** Implement migration, model, service, command, controller, view, routes, Access, role, lang keys.
- [ ] **Step 4:** `php artisan test --filter="EmployeeTest|RoleAccessTest|AccessTest"` → PASS; `php artisan promax:i18n-check` clean.
- [ ] **Step 5: Commit** `feat(hr): employee file, hr role, resolver employee step`.

### Task 2: Attendance for every employee (source, dashboard button, supervisor, punches report)

**Files:** migration `000710`, modify `app/Models/AttendanceDay.php`, `app/Models/AttendancePunch.php`, `app/Services/Attendance.php`, `app/Http/Controllers/Api/AttendanceApiController.php`; create `app/Http/Controllers/Hr/AttendanceHrController.php`, `app/View/Composers/PunchComposer.php`, `resources/views/partials/_punch.blade.php`, `resources/views/hr/supervisor.blade.php`, `resources/views/hr/punches.blade.php`; include the partial in `layouts/system.blade.php` topbar; register composer in `AppServiceProvider`. Test `tests/Feature/Hr/AttendanceForAllTest.php` + `ApiContractTest`.

**Interfaces:**
- Consumes: `Employee::forUser`, `Employee::isManagedBy`, `Employee::visibleTo` (Task 1).
- Migration: `attendance_days.employee_id` nullable FK employees cascadeOnDelete + unique `(employee_id, date)`; `attendance_days.user_id` and `attendance_punches.user_id` become nullable (`->nullable()->change()`, guarded by reading `Schema::getColumns` nullability); `attendance_punches`: `employee_id` nullable FK, `source` varchar(12) default `app` indexed, `device` varchar(190) nullable, `ip` varchar(45) nullable, `recorded_by` nullable FK users nullOnDelete. Existing rows keep `source='app'`.
- `AttendancePunch::SOURCES = ['app','dashboard','supervisor']`, `sourceLabel(): string` (`hrm.source_*`), relation `employee()`, `recorder()`. `AttendanceDay::employee()`.
- `Attendance::today(User $user, ?string $date = null)` sets `employee_id` on create (`Employee::forUser`), behaviour otherwise unchanged.
- `Attendance::punch(User $user, string $type, ?float $lat = null, ?float $lng = null, bool $auto = false, string $source = 'app', array $meta = [])` — `$meta` keys `device`, `ip`, `recorded_by`; the created punch stores `employee_id` (day's), `source`, meta. All existing callers unchanged.
- `Attendance::todayForEmployee(Employee $e, ?string $date = null): AttendanceDay` — employee with a user → `today($e->user)`; without → `firstOrCreate(['employee_id'=>…, 'date'=>…], ['status'=>open])`.
- `Attendance::punchEmployee(Employee $e, string $type, User $by): array{0:?string,1:AttendanceDay}` — refuses (`hrm.supervisor_has_login`) an employee with `user_id`; same state machine (`ALLOWED`), no open-work guard (no login = no visits); source `supervisor`, `recorded_by` = `$by->id`.
- `autoClose()` handles `user_id` null (skips warehouse/visit closing, punch keeps `employee_id`).
- `Attendance::payload()` adds `'source' => $p->source` to each punch (only addition; Lane 8 shape `{attendance: …}` unchanged).
- API `POST /api/attendance/punch`: unchanged validation and response, passes `source='app'`, `device` = `User-Agent` (cut 190), `ip`.
- Web `POST /attendance/punch` name `attendance.punch` (outside `erp.` prefix, in the `auth`,`screen` group): `{type: in|break|back|out}`; refuses with `hrm.no_employee_record` when `Employee::forUser($user)` is null; source `dashboard`, device = User-Agent, ip = `$request->ip()`, lat/lng null; redirects back with flash. Added to SCREENS of manager, branch_manager, accountant, warehouse_keeper, hr (never handover_reviewer).
- Partial `_punch`: shown in the topbar only when the composer finds an employee for the user; shows state (`hr.state_*` existing keys if present, else `hrm.state_*`) and the next allowed buttons (in / break / back / out) as small POST forms. Composer computes state with `AttendanceDay` read-only (`peekStates`-style, no `firstOrCreate` on page view).
- Supervisor screen `GET /hr/attendance/supervisor` `hr.attendance.supervisor` (`role:admin,hr,manager,branch_manager,warehouse_keeper`): active employees with `user_id` null that `isManagedBy($user)` (admin/hr: all), with today's state and buttons; `POST /hr/attendance/supervisor/punch` `hr.attendance.supervisor.punch` `{employee_id, type}` → `Scope`-style check `isManagedBy` else 403.
- Report `GET /hr/attendance/punches` `hr.attendance.punches` (`role:admin,hr,manager,branch_manager,accountant`): one row per punch — date, time `h:i A`, employee (employee name, else user name), type, source label (supervisor rows show "سجّله المشرف: name"), recorded by, location link (`https://maps.google.com/?q=lat,lng`) or "—", device, ip. Filters: employee, source, DateRange on `at` (default `month`), visibility: `Employee::visibleTo` (+ field users without employee via user scope: admin/hr/accountant all, manager team). `?export=1` CSV same columns.

- [ ] **Step 1: Failing tests** `tests/Feature/Hr/AttendanceForAllTest.php`:
  - `test_app_punch_records_source_app_and_employee`: user with employee, POST `/api/attendance/punch` `{type:in}` → 200, punch `source='app'`, `employee_id` = employee, response `attendance.punches.0.source == 'app'`.
  - `test_keeper_with_employee_can_punch_from_app`: role `warehouse_keeper` → 200.
  - `test_dashboard_punch_records_device_and_ip`: accountant with employee, `withHeader('User-Agent','UA-test')->post('/attendance/punch',['type'=>'in'])` → 302, punch `source='dashboard'`, `device='UA-test'`, `ip` not null.
  - `test_dashboard_punch_without_employee_is_refused`: no employee → session has errors, 0 punches.
  - `test_dashboard_punch_follows_the_state_machine`: `in` twice → second refused, 1 punch.
  - `test_supervisor_punches_worker_without_login`: manager user with employee M, worker W (`user_id` null, `manager_id`=M) → POST supervisor punch → day for W with `employee_id`, `user_id` null, punch `source='supervisor'`, `recorded_by` = manager.
  - `test_supervisor_cannot_punch_someone_elses_worker`: worker managed by another → 403, 0 punches.
  - `test_supervisor_cannot_punch_an_employee_with_a_login`: → error `hrm.supervisor_has_login`.
  - `test_auto_close_closes_a_worker_day_without_user`: worker `in` yesterday, `Attendance::autoClose($day)` → true, last punch `out`, `employee_id` kept.
  - `test_punches_report_filters_by_source_and_exports`: 1 app + 1 supervisor punch → `?source=supervisor&export=1` contains the worker name only.
  - existing `AttendanceTest`/`ApiContractTest` stay green.
- [ ] **Step 2: ApiContractTest addition** `test_attendance_punch_shape_is_stable`: punch `in` → JSON has `attendance.state`, `attendance.worked_minutes`, `attendance.open_since`, `attendance.punches.*.type|label|at|source`.
- [ ] **Step 3:** `php artisan test --filter="AttendanceForAllTest|ApiContractTest"` → FAIL.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** `php artisan test --filter="AttendanceForAllTest|ApiContractTest|Attendance|RoleAccessTest"` → PASS; i18n clean.
- [ ] **Step 6: Commit** `feat(hr): attendance for every employee — source, dashboard punch, supervisor, punches report`.

### Task 3: Leave — types, balances, requests, approval (web + API)

**Files:** migration `000720`, `app/Models/LeaveBalance.php`, `app/Models/LeaveRequest.php`, `app/Services/Hr/Leave.php`, `app/Console/Commands/HrLeaveSeed.php`, `app/Http/Controllers/Hr/LeaveController.php`, `app/Http/Controllers/Api/LeaveApiController.php`, `resources/views/hr/leave.blade.php`, `resources/views/hr/leave_balances.blade.php`, routes (web + api), Access, `Idempotency::LEAVE_REQUEST = 'leave_request'`, lang. Test `tests/Feature/Hr/LeaveTest.php` + `ApiContractTest`.

**Interfaces:**
- Tables: `leave_balances` (`id`, `employee_id` FK cascade, `year` smallint, `type` varchar(10), `entitled` decimal(6,1), `used` decimal(6,1) default 0, `note` nullable, timestamps, unique `(employee_id, year, type)`); `leave_requests` (`id`, `employee_id` FK cascade, `type`, `from` date, `to` date, `days` decimal(6,1), `note` varchar(300) nullable, `status` varchar(10) default `pending` indexed, `requested_by` nullable FK users, `decided_by` nullable FK users, `decided_at` dateTime nullable, `decision_note` varchar(300) nullable, timestamps).
- `LeaveRequest::TYPES = ['annual','casual','sick','unpaid']`, `STATUSES = ['pending','approved','rejected','cancelled']`, `typeLabel()`, `statusLabel()`, `apiRow(): array` → exactly `{id, type, type_label, from:'Y-m-d', to:'Y-m-d', days:float, note, status, status_label, decision_note, employee_name, created_at: ISO8601}`.
- Settings defaults (`HrSettings::DEFAULTS`, Task 4 owns the class; this task adds the keys through it, or creates `HrSettings` with `get(string $key): string` reading `Setting::read` with these fallbacks): `hr_leave_annual=21`, `hr_leave_casual=6`, `hr_leave_sick=30`, `hr_leave_unpaid=0` (0 = no balance check), `hr_weekend_days=5` (comma list of `Carbon::dayOfWeek`, 5 = Friday).
- `Leave::days(Carbon $from, Carbon $to): float` — calendar days in range minus weekend days.
- `Leave::balances(Employee $e, int $year): array` → list of `{type, label, entitled, used, remaining}` for the 4 types (missing row → entitled from settings, used 0; unpaid → entitled 0, remaining null-safe 0, no check).
- `Leave::request(Employee $e, User $by, string $type, Carbon $from, Carbon $to, ?string $note): LeaveRequest` — throws `Rejected` on `to < from`, overlap with a pending/approved request of the same employee (`hrm.leave_overlap`), days 0 (`hrm.leave_zero_days`), or days > remaining for a balanced type (`hrm.leave_no_balance`); notifies the manager's user (`AppNotification::send`, closures, link to `hr.leave`) when there is one.
- `Leave::decide(LeaveRequest $r, User $by, string $decision, ?string $note): LeaveRequest` — only `pending`; `$r->employee->isManagedBy($by)` else `Rejected` 403-equivalent (`hrm.leave_not_your_team`); a manager cannot decide their own request; approve inside `DB::transaction` with `lockForUpdate` on the balance row (created from defaults if missing), re-checks remaining, `used += days`; notifies the requester.
- `Leave::cancel(LeaveRequest $r, User $by)` — requester, pending only.
- `Leave::seedOpening(int $year, bool $dryRun = false): array{created:int}` — a balance row per active employee per type from defaults where missing; command `promax:hr-leave-seed {--year=} {--dry-run}`.
- Web: `GET /hr/leave` `hr.leave` (all web roles except handover_reviewer: my requests + new-request dialog + "awaiting my approval" tab), DateRange on `from`, `?export=1`; `POST /hr/leave` `hr.leave.store`; `POST /hr/leave/{leaveRequest}/decide` `hr.leave.decide`; `POST /hr/leave/{leaveRequest}/cancel` `hr.leave.cancel`; `GET /hr/leave/balances` `hr.leave.balances` + `POST /hr/leave/balances/{leaveBalance}` `hr.leave.balances.update` (`role:admin,hr`, action `act.hr.leave_balances`).
- API (inside `api.token`, outside the `attendance` guard): `GET /api/leave/balances` → `{balances:[…], year}`; `POST /api/leave/requests` `{type, from, to, note, idem_key}` → 201 `{request: row}` (replay with same key → 200 same row); `GET /api/leave/requests?mine=1` → `{requests:[…]}` (mine = own employee; without `mine` same for now); `GET /api/leave/approvals` → `{requests:[pending of my team]}` (admin/hr: all pending); `POST /api/leave/requests/{leaveRequest}/decide` `{decision: approved|rejected, note}` → `{request: row}`; deciding an already-decided request with the same decision → 200 same row, a different one → 422. A user with no employee record gets 403 `{message: hrm.no_employee_record}` on balances/requests (Lane 8 hides the card on 403); approvals returns `{requests: []}`.

- [ ] **Step 1: Failing tests** `tests/Feature/Hr/LeaveTest.php`:
  - `test_days_skip_the_weekend`: Thu 2026-10-22 → Sat 2026-10-24 = 2.0.
  - `test_balances_default_from_settings`: annual entitled 21, remaining 21.
  - `test_request_and_manager_approval_updates_balance`: 3-day annual → approve by manager → `used` 3, remaining 18, status approved, decided_by set.
  - `test_rejection_keeps_the_balance`.
  - `test_overlapping_request_is_refused`.
  - `test_request_beyond_balance_is_refused`: casual 7 days with 6 → 422/`Rejected`.
  - `test_approval_beyond_balance_is_refused`: two pending 4-day casual requests (created before the first approval, balance 6) → first approve OK, second approve refused, `used` 4.
  - `test_unpaid_has_no_balance_check`: 40 days unpaid → created.
  - `test_other_manager_cannot_decide`: 403/`Rejected`, status pending.
  - `test_seed_opening_is_idempotent`.
  - `test_admin_edits_a_balance`.
- [ ] **Step 2: ApiContractTest additions:**
  - `test_leave_endpoints_follow_the_app_contract`: employee user → `GET /api/leave/balances` 200, `balances.*` has `type,label,entitled,used,remaining`; POST request → 201 `request.id|type|type_label|from|to|days|status|status_label|employee_name`; `GET /api/leave/requests?mine=1` → `requests` count 1; manager `GET /api/leave/approvals` → that request; decide `approved` → `request.status == 'approved'`.
  - `test_same_idem_key_returns_the_same_request`: two POSTs same `idem_key` → same id, 1 row.
  - `test_leave_without_employee_record_is_403`.
- [ ] **Step 3:** `php artisan test --filter="LeaveTest|ApiContractTest"` → FAIL.
- [ ] **Step 4:** Implement.
- [ ] **Step 5:** same filter + `RoleAccessTest` → PASS; i18n clean.
- [ ] **Step 6: Commit** `feat(hr): leave balances, requests and manager approval (web + API)`.

### Task 4: HR settings and payroll math (insurance, income tax, gross-up)

**Files:** `app/Services/Hr/HrSettings.php`, `app/Services/Hr/PayrollMath.php`, `app/Http/Controllers/Hr/HrSettingsController.php`, `resources/views/hr/settings.blade.php`, routes, Access, lang. Test `tests/Feature/Hr/PayrollMathTest.php`.

**Interfaces:**
- `HrSettings::DEFAULTS` (strings, read via `Setting::read($k, DEFAULTS[$k])`; JSON for lists) — **editable defaults, verify**:
  - `hr_si_employee_rate=0.11`, `hr_si_employer_rate=0.1875`, `hr_si_min_wage=2700`, `hr_si_max_wage=16700` (law 148/2019, 2026 limits — verify).
  - `hr_tax_exemption=20000` (annual personal exemption).
  - `hr_tax_brackets` JSON `[[0,40000,0],[40000,55000,0.10],[55000,70000,0.15],[70000,200000,0.20],[200000,400000,0.225],[400000,1200000,0.25],[1200000,null,0.275]]` (`[from,to,rate]` on annual taxable after exemption, law 7/2024 — verify).
  - `hr_tax_elimination` JSON `[[600000,1],[700000,2],[800000,3],[900000,4],[1200000,5]]` — `[over, upto_bracket_index]`: when annual taxable > `over`, all income up to the top of bracket `upto` is taxed at that bracket's rate (the highest matching `over` wins).
  - `hr_month_days=30`, `hr_day_minutes=480`, `hr_shift_start=09:00`, `hr_late_grace_minutes=15`, `hr_overtime_rate=1.35`, plus the four leave keys and `hr_weekend_days` from Task 3, and `hr_rates_verified_at` (empty).
  - `HrSettings::get(string $k): string`, `num(string $k): float`, `json(string $k): array`, `all(): array`.
- `PayrollMath::insurance(float $wage, float $empRate, float $erRate, float $min, float $max): array{wage:float,employee:float,employer:float}` — wage clamped to [min,max], each share `round(…, 2)`.
- `PayrollMath::annualTax(float $annualTaxable, array $brackets, array $elimination): float` — progressive; elimination per the rule above; `round(…, 2)`; ≤ 0 → 0.
- `PayrollMath::monthlyTax(float $monthlyTaxable, array $brackets, array $elimination, float $exemption): float` = `round(annualTax(max(monthly*12 - exemption, 0)) / 12, 2)`.
- `PayrollMath::grossUp(float $net, float $employeeInsurance, callable $taxOf): float` — smallest gross `G` (2 dp) with `G - employeeInsurance - $taxOf(G - employeeInsurance) >= net`, bisection on `[net, net*2 + employeeInsurance + 1]`, 60 iterations, then round up to 2 dp.
- Screen `GET /hr/settings` `hr.settings` (admin, hr read) and `POST /hr/settings` `hr.settings.save` (`role:admin`, action `act.hr.settings` = `[]`): numeric inputs + bracket rows; warning banner `hrm.defaults_verify` while `hr_rates_verified_at` empty; save writes `Setting::writeMany` and stamps `hr_rates_verified_at`.

- [ ] **Step 1: Failing tests** `PayrollMathTest` (unit-style inside a feature test class, exact values):
  - `test_insurance_clamps_to_min_and_max`: wage 1000 → wage 2700, employee 297.00, employer 506.25; wage 20000 → wage 16700, employee 1837.00, employer 3131.25.
  - `test_annual_tax_progressive`: 100000 → 0 + 1500 + 2250 + 6000 = 9750.00.
  - `test_annual_tax_elimination_over_600k`: 650000 → 55000×0.10 + 15000×0.15 + 130000×0.20 + 200000×0.225 + 250000×0.25 = 5500+2250+26000+45000+62500 = 141250.00.
  - `test_monthly_tax_uses_exemption`: monthly 10000 → annual taxable 100000 → 9750/12 = 812.50.
  - `test_zero_income_zero_tax`.
  - `test_gross_up_hits_the_net`: net 10000, employee insurance 1100, tax via the default settings → `G - 1100 - monthlyTax(G-1100) == 10000.00` within 0.01 and `G > 11100`.
  - `test_settings_banner_until_saved`: GET shows `hrm.defaults_verify` text; admin POST → banner gone; `hr` POST → 403.
- [ ] **Step 2:** `php artisan test --filter=PayrollMathTest` → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** PASS; i18n clean.
- [ ] **Step 5: Commit** `feat(hr): payroll settings (verify-me defaults) and insurance/tax math`.

### Task 5: Advances and penalties

**Files:** migration `2026_10_19_000730_hr_advances.php`, `app/Models/EmployeeAdvance.php`, `app/Models/HrPenalty.php`, `app/Http/Controllers/Hr/AdvanceController.php`, `resources/views/hr/advances.blade.php`, routes, Access, lang. Test `tests/Feature/Hr/AdvancesTest.php`.

**Interfaces:**
- `employee_advances`: `id`, `number` unique (`ADV-1001`), `employee_id` FK, `date` date, `amount` decimal(14,2), `installments` smallint ≥1, `installment_amount` decimal(14,2), `start_period` char(7) `YYYY-MM`, `recovered` decimal(14,2) default 0, `safe_id` nullable FK safes, `status` ∈ `open|closed|void` default `open`, `note`, `created_by`, timestamps.
- `hr_penalties`: `id`, `employee_id` FK, `date` date, `amount` decimal(14,2), `reason` varchar(200), `payslip_id` nullable (set when deducted), `created_by`, timestamps.
- `EmployeeAdvance::remaining(): float`, `installmentFor(string $period): float` (= `min(installment_amount, remaining)` when `status=open` and `start_period <= period`, else 0).
- Web: `GET /hr/advances` `hr.advances` (tabs advances / penalties, DateRange on `date`, `?export=1`), `POST /hr/advances` `hr.advances.store` (`employee_id`, `date`, `amount`, `installments`, `start_period`, `safe_id` required active safe), `POST /hr/advances/{advance}/void` (only when `recovered == 0`), `POST /hr/penalties` `hr.penalties.store`, `POST /hr/penalties/{penalty}/delete` (only when `payslip_id` null). Writes `role:admin,hr`, action `act.hr.advances` = `['hr']`.
- The advance's GL entry is posted in Task 7 (this task only creates the row inside `DB::transaction`, rejecting a closed GL month via `app(Ledger::class)->assertOpen($date)`).

- [ ] **Step 1: Failing tests** `AdvancesTest`: `test_advance_installment_amount_is_amount_over_installments` (3000/3 → 1000.00), `test_installment_never_exceeds_remaining` (recovered 2500 → 500), `test_installment_waits_for_start_period`, `test_void_only_before_recovery`, `test_penalty_store_and_delete_until_deducted`, `test_advances_export_respects_range`.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS + i18n.
- [ ] **Step 5: Commit** `feat(hr): employee advances and penalties`.

### Task 6: Monthly payroll run — draft, edit, approve, pay, payslips

**Files:** migration `2026_10_19_000740_hr_payroll_runs.php`, `app/Models/PayrollRun.php`, `Payslip.php`, `PayslipLine.php`, `PayrollPayment.php`, `app/Services/Hr/Payroll.php`, `app/Http/Controllers/Hr/PayrollController.php`, views `hr/payroll.blade.php`, `hr/payroll_run.blade.php`, `hr/payslip_print.blade.php`, routes, Access, lang. Test `tests/Feature/Hr/PayrollRunTest.php`.

**Interfaces:**
- Consumes: `PayrollMath`, `HrSettings` (Task 4), `EmployeeAdvance::installmentFor`, `HrPenalty` (Task 5), attendance days (Task 2), approved `LeaveRequest` (Task 3), `RepKpis::forMonth(User, Carbon)['commission'|'points_money']`.
- Tables: `payroll_runs` (`id`, `period` char(7) unique, `date` date = last day of period, `status` ∈ `draft|approved|paid` default draft, `gross`, `net`, `employer_cost` decimal(14,2) default 0, `approved_by`, `approved_at` dateTime, `paid_at` dateTime, `created_by`, timestamps); `payslips` (`id`, `payroll_run_id` FK cascade, `employee_id` FK, `cost_center_id` nullable (snapshot), `pay_method`, `borne_by`, `basic`, `gross`, `insurance_wage`, `insurance_employee`, `insurance_employer`, `tax`, `advances`, `deductions`, `net` decimal(14,2), `payroll_payment_id` nullable, timestamps, unique `(payroll_run_id, employee_id)`); `payslip_lines` (`id`, `payslip_id` FK cascade, `code` varchar(20), `kind` ∈ `earning|deduction|employer`, `qty` decimal(10,2) nullable, `amount` decimal(14,2), `manual` bool default false, `note`); `payroll_payments` (`id`, `payroll_run_id` FK, `safe_id` FK, `kind` cash|bank, `date` date, `amount` decimal(14,2), `paid_by`, timestamps).
- Line codes: earnings `basic`, `allowances`, `commission`, `incentive`, `overtime`, `company_borne` (gross-up); deductions `absence`, `lateness`, `unpaid_leave`, `penalty`, `advance`, `insurance`, `tax`; employer `insurance_employer`.
- `Payroll::draft(string $period, User $by): PayrollRun` — creates or refreshes a **draft** run (refused when approved/paid): for every active employee hired on/before the period end and not ended before its start, rebuild non-manual lines, keep manual lines, then `recompute`. Rules:
  - daily rate = (basic+allowances)/`hr_month_days`; minute rate = daily/`hr_day_minutes`.
  - `basic`/`allowances` prorated by `days employed in period / days in month` when hired or ended inside the period.
  - attendance (only when `attendance_required`): for each workday (not weekend) from max(period start, hire) to min(period end, today, end_date): no attendance day with `sessions > 0` and not covered by an approved paid leave → `absence` (qty days); covered by approved `unpaid` leave → `unpaid_leave`; `first_in_at` later than `hr_shift_start` + grace → `lateness` qty = minutes after shift start; payable minutes (approved_minutes ?? worked_minutes) above `hr_day_minutes` → `overtime` qty minutes × minute rate × `hr_overtime_rate`. Days are matched by `employee_id` or the employee's `user_id`.
  - `commission`/`incentive`: only when the employee has a user in `User::FIELD_WORK_ROLES`, from `RepKpis::forMonth`.
  - `penalty`: sum of unlinked `hr_penalties` dated in the period; `advance`: sum of `installmentFor(period)`.
- `Payroll::recompute(Payslip $s): Payslip` — `earned = Σ earnings − absence − lateness − unpaid_leave − penalty`; insurance on `insurable_wage ?? (basic+allowances)` when `insured`; tax on `earned − insurance_employee` when `taxable` (`monthlyTax`); borne by `employee`: `gross = earned`, `net = earned − insurance_employee − tax − advance`; borne by `company`: `gross = grossUp(earned, insurance_employee, tax)` with a `company_borne` earning line = `gross − earned`, tax recomputed on `gross − insurance_employee`, `net = earned − advance`; `insurance_employer` line; totals rolled up on the run. All money `round(…, 2)`; `gross − insurance_employee − tax − advances == net` always.
- `Payroll::setLine(Payslip $s, string $code, float $amount, ?string $note, User $by)` — draft only, marks `manual`, recompute.
- `Payroll::approve(PayrollRun $r, User $by)` — draft → approved, `assertOpen($r->date)`, then (Task 7) GL post.
- `Payroll::pay(PayrollRun $r, ?Safe $cash, ?Safe $bank, Carbon $date, User $by): PayrollRun` — approved only (`hrm.payroll_not_approved`; paid → `hrm.payroll_already_paid`), `assertOpen($date)`, inside `DB::transaction` with `lockForUpdate` on the run: one `PayrollPayment` per method that has slips (missing safe for a method that has slips → `Rejected`), links slips, marks penalties `payslip_id`, adds advance installments to `recovered` (closing advances that reach the amount), status `paid`, `paid_at`.
- Web (`role:admin,hr,accountant` read): `GET /hr/payroll` `hr.payroll` (runs, DateRange on `date`, `?export=1`), `POST /hr/payroll` `hr.payroll.draft` (`role:admin,hr`), `GET /hr/payroll/{run}` `hr.payroll.show` (slips table with per-line edit dialog in draft, `?export=1`), `POST /hr/payroll/slips/{payslip}/line` `hr.payroll.line` (`role:admin,hr`), `POST /hr/payroll/{run}/approve` `hr.payroll.approve` (`role:admin`, `act.hr.payroll_approve` = `[]`), `POST /hr/payroll/{run}/pay` `hr.payroll.pay` (`role:admin`, `act.hr.payroll_pay` = `[]`; dialog picks cash safe, bank safe, date), `GET /hr/payroll/slips/{payslip}/print` `hr.payroll.print` (print view: company header from `Setting::docHeader()`, employee, period, earnings/deductions table, employer share, net, signature lines; the topbar print button).

- [ ] **Step 1: Failing tests** `PayrollRunTest` (settings defaults; Friday weekend; period `2026-09`):
  - `test_draft_basic_employee_without_attendance_tracking`: basic 8000 + allowances 2000, `attendance_required=false`, insured, taxable, borne employee → insurance employee 1100.00, employer 1875.00, tax = `monthlyTax(8900)`, net = 10000 − 1100 − tax.
  - `test_absence_and_lateness_from_attendance`: attendance_required, one workday absent, one day first in 09:40 → absence = 1 × 10000/30, lateness = 40 × 10000/30/480.
  - `test_approved_paid_leave_is_not_absence_but_unpaid_is_deducted`.
  - `test_mid_month_hire_is_prorated`: hired 2026-09-16 → basic = 8000 × 15/30.
  - `test_company_borne_net_equals_earned`: borne company → `net == 10000.00 − advances`, `gross > 10000`, `gross − ins − tax − adv == net`.
  - `test_insured_off_and_taxable_off`: no insurance or tax lines.
  - `test_commission_for_field_employee` (RepKpis stub data: one invoice + commission tier) → commission line > 0.
  - `test_manual_line_survives_redraft`.
  - `test_cannot_redraft_an_approved_run`.
  - `test_pay_links_advances_and_penalties`: advance 3000/3 → after pay recovered 1000; penalty linked.
  - `test_paying_twice_is_refused`: second pay → error, 1 payment row per method.
  - `test_pay_requires_bank_safe_when_bank_slips_exist`.
  - `test_hr_cannot_approve`: 403; accountant can view and print.
  - `test_payslip_print_shows_net`.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** PASS + `RoleAccessTest` + i18n.
- [ ] **Step 5: Commit** `feat(hr): monthly payroll run, approval, payment and payslips`.

### Task 7: GL posting per cost center and payroll cost for Lane 9

**Files:** `app/Services/Hr/PayrollGl.php`, `app/Services/Hr/PayrollCost.php`, `app/Console/Commands/HrGlSeed.php`, modify `app/Services/Gl/Rules.php` (one arm), `app/Services/Gl/Ledger.php` (one concat in `sources`), `app/Services/Hr/Payroll.php` (post after approve/pay), `AdvanceController`/advance creation (post). Test `tests/Feature/Hr/PayrollGlTest.php`.

**Interfaces:**
- `PayrollGl::ACCOUNTS` (`[code, name, name_en, parentCode, system_key]`, all postable): `1203` سلف العاملين / Employee advances / `12` / `employee_advances`; `2102` مرتبات مستحقة / Salaries payable / `21` / `salaries_payable`; `2202` تأمينات اجتماعية مستحقة / Social insurance payable / `22` / `social_insurance_payable`; `2203` ضريبة كسب العمل مستحقة / Payroll tax payable / `22` / `payroll_tax_payable`; `5108` حصة الشركة في التأمينات / Employer social insurance / `5` / `expense_social_insurance`. Reuses `expense_salaries` (5104) and `expense_commissions` (5105).
- `PayrollGl::ensureAccounts(): array{created:int}` — by `system_key` first; a taken code gets the suffix `-HR`; parent missing → root of the same digit. Posting rules (`GlPostingRule::firstOrCreate`): `hr.payroll` (dr `expense_salaries`, cr `salaries_payable`), `hr.payroll.commission` (dr `expense_commissions`, cr `salaries_payable`), `hr.payroll.employer_si` (dr `expense_social_insurance`, cr `social_insurance_payable`), `hr.payroll.employee_si` (dr `salaries_payable`, cr `social_insurance_payable`), `hr.payroll.tax` (dr `salaries_payable`, cr `payroll_tax_payable`), `hr.payroll.advance` (dr `salaries_payable`, cr `employee_advances`), `hr.payroll.pay` (dr `salaries_payable`, cr safe), `hr.advance` (dr `employee_advances`, cr safe). Command `promax:hr-gl-seed {--dry-run}`.
- `PayrollGl::handles(Model $m): bool` (PayrollRun approved/paid, PayrollPayment, EmployeeAdvance not void) and `PayrollGl::lines(Model $m): array` returning the `Rules::linesFor` shape `{rule, memo, needs_review, lines:[{slot, account_id, debit, credit, cost_center_id}]}`:
  - **PayrollRun:** per payslip, from its lines: commission+incentive → `hr.payroll.commission` amount; the rest of `gross` → `hr.payroll`; `insurance_employer` → `hr.payroll.employer_si`; `insurance_employee`, `tax`, `advances` → their rules. Lines grouped by `(account_id, cost_center_id, side)` and summed; every line carries the payslip's `cost_center_id`. Balanced: Σdr == Σcr.
  - **PayrollPayment:** dr `salaries_payable` / cr the safe's `gl_account_id`, or `cash_main`/`bank` by kind when the safe has none (`needs_review` true then). `cost_center_id` = the safe's.
  - **EmployeeAdvance:** dr `employee_advances` (employee's center) / cr safe account.
  - Missing/inactive account → `suspense` + `needs_review` (same rule as `Rules::safeAccount`).
- `Rules::linesFor`: add `\App\Services\Hr\PayrollGl::handles($source) => \App\Services\Hr\PayrollGl::lines($source),` before `default`.
- `Ledger::sources`: add `->concat(\App\Services\Hr\PayrollGl::sources($d))` where `PayrollGl::sources(string $fromDate): iterable` yields approved/paid runs by `date`, payments by `date`, non-void advances by `date`.
- Posting: `Payroll::approve` and `Payroll::pay` and advance creation call `PayrollGl::post(Model $m): void` after commit = `try { app(Ledger::class)->post($m) } catch (\Throwable $e) { Log::error(...) }`; advance void → `unpost`.
- **Lane 9 interface:** `App\Services\Hr\PayrollCost::forMonth(Carbon $month): array<int|string, array{cost_center_id:?int, gross:float, employer_insurance:float, total:float, employees:int}>` keyed by cost_center_id (`'none'` for null), from payslips of the approved/paid run of that period (works with `gl_enabled` off). Also GL-side: sum of `gl_lines` on `expense_salaries` + `expense_commissions` + `expense_social_insurance` grouped by `cost_center_id` for entries with `source_type = PayrollRun`.

- [ ] **Step 1: Failing tests** `PayrollGlTest` (`gl_enabled=1`, `GlSeeder` + `PayrollGl::ensureAccounts`):
  - `test_approve_posts_a_balanced_entry_per_cost_center`: two employees on FAC and MAADI → one auto entry for the run, Σdebit == Σcredit, `expense_salaries` lines on both centers with the right amounts, liabilities on `social_insurance_payable` = Σ(employee+employer), `payroll_tax_payable` = Σtax.
  - `test_company_borne_entry_balances`.
  - `test_payment_posts_safe_credit`: pay → entry dr `salaries_payable` = Σnet, cr the safe's gl account.
  - `test_advance_posts_and_void_unposts`.
  - `test_rebuild_keeps_payroll_entries`: `Ledger::rebuild(from, dryRun:false)` → report ok, the run/payment entries exist again.
  - `test_approve_in_closed_period_is_refused`: `GlPeriod` closed for 2026-09 → approve redirects with the month message, run stays draft.
  - `test_payroll_cost_for_month_by_center`: `PayrollCost::forMonth(2026-09)` keys = both centers, `total = gross + employer_insurance`.
  - `test_ensure_accounts_is_idempotent`.
- [ ] **Step 2:** FAIL. **Step 3:** implement. **Step 4:** `php artisan test --filter="PayrollGlTest|LedgerTest|Gl"` PASS.
- [ ] **Step 5: Commit** `feat(hr): payroll GL posting per cost center, payroll cost for costing`.

### Task 8 (controller): verification, QA, UAT, report

- [ ] Full suite `php artisan test`, `php artisan promax:i18n-check`, `python promax-skills/promax-feature-dev/scripts/static_check.py`.
- [ ] QA: check `promax_qa` tokens (`shopify_admin_token`, `itrack_token`, `itrack_password_md5`) are empty; `php artisan migrate --env=qa`; `promax:hr-employees-seed --dry-run --env=qa`; browser on port 8010: employees list, dashboard punch, supervisor, punches report, leave request/approve, settings banner, payroll draft/print.
- [ ] UAT file `HANDOVER_2026-10\uat\_src\uat_cases_close7.py`, `LANE_REPORTS_closeout\LANE_7.md`, one line in `HANDOFF_LIVE.md`.
