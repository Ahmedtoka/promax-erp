# Close-out Lane 10: training and handover pack 1.0-rc3

**Goal:** bring the offline handover pack (`C:\xampp\htdocs\ProMax\HANDOVER_2026-10`, outside git) up to date with lanes 1 to 8, fill the gaps listed for rc2, and ship 1.0-rc3 (zip + UAT PDF + report).

**Branch:** `close-10-training` (from `integration` 5b4e296). Only this plan file is committed. The pack, its shots, videos and secrets are never committed (the repo is public).

**Environment:** demo server `php artisan serve --env=demo --port=8031` from this worktree (`.env.demo` copied, git-excluded). DB `promax_demo` only. `migrate --env=demo` is allowed. Never `promax`, never `promax_qa` writes, never port 8020, never start or stop MySQL. Demo logins are read from `HANDOVER_2026-10\.secrets\demo_logins.csv` at runtime and never printed.

## Inputs

- New NAV items (diff of `Access::NAV` on integration vs `screens/screens.json`): 26 web screens.
  - Lane 1: `handover.index`.
  - Lane 3: `gl.golive.index`, `gl.mapping`.
  - Lane 4: `gl.safes`, `gl.recon`, `gl.expense_categories`, `gl.assets`.
  - Lane 5: `erp.purchase_requests`, `erp.purchasing.returns`, `erp.supplier_contracts`, `wh.raw`.
  - Lane 6: `mfg.orders`, `mfg.qc`, `mfg.specs`, `mfg.boms`, `mfg.lines`, `mfg.requirements`, `mfg.trace`, `mfg.variance`.
  - Lane 7: `hr.employees`, `hr.attendance.supervisor`, `hr.attendance.punches`, `hr.leave`, `hr.advances`, `hr.payroll`, `hr.settings`.
  - Role changes: S090 to S092 gain `hr`.
- App 2.6.0+13 (`_wt/close-8`): StaffHome, My Leave (+ request sheet), Leave Approvals, Production Orders (+ finish dialog), StaffShortcuts, admin attendance card.
- UAT sources `uat/_src/uat_cases_close2..8.py` (101 cases), written colloquially with route names as screens.

## Tasks

1. **Demo data (promax_demo only).** `migrate --env=demo`; `promax:handover-seed`, `promax:cost-centers-seed --maadi-warehouse=WHK`, `promax:hr-employees-seed`, `promax:hr-leave-seed`; GL/cash/HR GL seeds only if the demo chart of accounts allows it. Demo users for driver, hr, production_manager, quality, rnd and handover_reviewer with the demo password from the secrets file; append them to `demo_logins.csv`. Demo-only sample rows (tagged DEMO-L10) so new screens are not empty: a spec, BOM, line, production orders, purchase request with quotes, supplier contract, safe, expense, fixed asset, leave request, advance, payroll draft.
2. **Inventory.** Append S103 to S128 to `screens/screens.json` (same shape) and new app ids A074+ to `screens/screens_app.json`. Lane 9 screens get placeholder entries marked `placeholder: true` (no shot, no video, content says the screen is under construction).
3. **Web shots and videos** for S103 to S128 with the existing `shots/_work/capture.mjs` + `annotate.py` and `videos/scripts/record_screens.mjs`. Recapture the rc2 empty or cut shots where demo data now exists.
4. **App.** Flutter web build of `close-8` against the demo (8033 proxy). Capture the new app screens and as many of the 31 missing ones as the demo allows (driver account, check-in through granted geolocation). App screen videos: a guided walkthrough recorded over each annotated app shot with the same overlay effects (ring, caption, cursor), labelled as such in the manifest.
5. **Content.** `site/content_parts/close10.json` with formal Arabic explanations (purpose, who, how, fields, rules, related) for every new web and app screen, plus English titles; merge into `content.json`.
6. **UAT.** Rewrite the lane case files to formal Arabic and S-ids/app keys, add a handover-reviewer file (Lane 1) and cases for the 27 rc2 web screens without a case; import all in `gen.py` (new prefixes, roles, flows, app keys); regenerate; unify the verdict labels.
7. **Review leftovers** where feasible: video chapter labels from the new shot labels, A005 roles in the app inventory, open UAT wording minors, recaptures.
8. **Build.** Rename the old zip to `-rc2`, `python site/build_data.py --version 1.0-rc3`, new `UAT_PROMAX.pdf`, new zip, smoke over file://, update `PACK_REPORT.md` and every `STATUS.md`.
9. **Report.** `LANE_REPORTS_closeout\LANE_10.md` and one Arabic line in `HANDOFF_LIVE.md`.

## Done when

- Every new NAV screen has inventory, content, shot and video; every app screen of 2.6.0 has content, and a shot and video wherever the demo can reach it; anything missing is listed with the reason.
- `gen.py` imports every close-out case file and validates; the PDF and the zip are rebuilt; the smoke walk has 0 JS errors; no password in any output.
