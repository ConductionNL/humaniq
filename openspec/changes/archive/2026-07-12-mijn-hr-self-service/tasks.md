# Tasks — mijn-hr-self-service

- [x] 1. Schema: add `nextcloudUserId` (string, nullable) to `Employee` in `lib/Settings/register.d/hr-objects.json` + bump Employee version to 0.2.0 per REQ-MHS-001
- [x] 2. Schema: add optional `userId` (string, nullable) to `Payslip` (`hr-objects.json`, → 0.2.0), `Timesheet` (`hr-timesheet.json`, → 0.3.0), `Expense` (`hr-expense.json`, → 0.2.0) and `LeaveRequest` (`hr-leave.json`, → 0.2.0) per REQ-MHS-002
  - property descriptions document the approvedBy plain-NC-user-id convention and (Payslip) payroll-side population per design.md D3
- [x] 3. Manifest: add the `Dashboard` menu entry (icon `view-dashboard`, order 10) and the `MijnHrGroup` menu group (label `Mijn HR`, icon `account`, order 20, children MijnUren/MijnDeclaraties/MijnVerlof/MijnLoonstroken) to `src/manifest.json` — existing groups untouched — per REQ-MHS-003
- [x] 4. Manifest: add the `MijnUren`, `MijnDeclaraties` and `MijnVerlof` index pages with `config.filter: { "userId": "@me" }`, columns and sort per REQ-MHS-004
- [x] 5. Manifest: add the read-only `MijnLoonstroken` index page (`@me` filter + `actionToggles` hiding Add/edit/delete/copy) per REQ-MHS-004
- [x] 6. Manifest: add the `Dashboard` page (`type: dashboard`, route `/dashboard`) with the six widgets (3 @me stats, 2 approver stats, 1 @me object-table) and layout per REQ-MHS-005
- [x] 7. Seed data: add the `employee-jansen` Employee (`nextcloudUserId: "admin"`), stamp `userId: "admin"` on `timesheet-jansen-2026-05` + `expense-jansen-hotel`, and add the `leave-jansen-zomer` LeaveRequest + `payslip-jansen-2026-05` Payslip to `lib/Settings/register.d/hr-seed.json` per REQ-MHS-006 (placeholders only; De Vries/Bakker stay unstamped)
- [x] 8. Quality gates: `npm run check:manifest` exits 0 and `composer check:strict` stays green (no PHP touched — guard against accidental drift)
- [x] 9. Verify: `npm run check:manifest` (real Ajv validation against the vendored app-manifest-v2 schema) passes for the new pages/menu/widgets; the `@me` resolution path was traced against nc-vue HEAD source (`useSelfFetchList.js` / `resolveFilterTokens.js` / `sentinelFilterToken`); seed data was checked for internal consistency (Jansen stamped `userId: "admin"`, De Vries/Bakker left unstamped). A full click-through login-as-admin browser pass was NOT performed in this headless run — the only running Nextcloud instance reachable from this environment (`nextcloud` container) mounts a *different*, unrelated hrmq checkout (branch `push-icons`, unrelated commit history) and deploying this branch onto it would violate the no-deploy-to-shared-dev-instance rule. Recorded as an open follow-up for a session with an isolated hrmq dev instance.

Acceptance criteria (plain reminders, not tasks):
- every new schema property is optional + nullable; no `required` list changes; existing seed objects still validate
- the `@me` filter uses the page `config.filter` map (self-fetch base filter), NOT `defaultFilters` (which users can clear)
- menu labels are the Dutch strings above but any NEW translatable keys stay English per ADR-007 (`hrmq-i18n-locale-completeness` owns locale files)
- do not take over the `/` route — Dashboard lives at `/dashboard` (root-route decision belongs to `hrmq-ia-navigation-alignment`)
