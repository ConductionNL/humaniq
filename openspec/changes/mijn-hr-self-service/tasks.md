# Tasks — mijn-hr-self-service

- [ ] 1. Schema: add `nextcloudUserId` (string, nullable) to `Employee` in `lib/Settings/register.d/hr-objects.json` + bump Employee version to 0.2.0 per REQ-MHS-001
- [ ] 2. Schema: add optional `userId` (string, nullable) to `Payslip` (`hr-objects.json`, → 0.2.0), `Timesheet` (`hr-timesheet.json`, → 0.3.0), `Expense` (`hr-expense.json`, → 0.2.0) and `LeaveRequest` (`hr-leave.json`, → 0.2.0) per REQ-MHS-002
  - property descriptions document the approvedBy plain-NC-user-id convention and (Payslip) payroll-side population per design.md D3
- [ ] 3. Manifest: add the `Dashboard` menu entry (icon `view-dashboard`, order 10) and the `MijnHrGroup` menu group (label `Mijn HR`, icon `account`, order 20, children MijnUren/MijnDeclaraties/MijnVerlof/MijnLoonstroken) to `src/manifest.json` — existing groups untouched — per REQ-MHS-003
- [ ] 4. Manifest: add the `MijnUren`, `MijnDeclaraties` and `MijnVerlof` index pages with `config.filter: { "userId": "@me" }`, columns and sort per REQ-MHS-004
- [ ] 5. Manifest: add the read-only `MijnLoonstroken` index page (`@me` filter + `actionToggles` hiding Add/edit/delete/copy) per REQ-MHS-004
- [ ] 6. Manifest: add the `Dashboard` page (`type: dashboard`, route `/dashboard`) with the six widgets (3 @me stats, 2 approver stats, 1 @me object-table) and layout per REQ-MHS-005
- [ ] 7. Seed data: add the `employee-jansen` Employee (`nextcloudUserId: "admin"`), stamp `userId: "admin"` on `timesheet-jansen-2026-05` + `expense-jansen-hotel`, and add the `leave-jansen-zomer` LeaveRequest + `payslip-jansen-2026-05` Payslip to `lib/Settings/register.d/hr-seed.json` per REQ-MHS-006 (placeholders only; De Vries/Bakker stay unstamped)
- [ ] 8. Quality gates: `npm run check:manifest` exits 0 and `composer check:strict` stays green (no PHP touched — guard against accidental drift)
- [ ] 9. Live verify in the dev container: re-run the register Repair import, log in as `admin`, confirm each Mijn page lists exactly the Jansen records and the Dashboard "mine" KPIs count 1 while the approver KPIs count all submitted items

Acceptance criteria (plain reminders, not tasks):
- every new schema property is optional + nullable; no `required` list changes; existing seed objects still validate
- the `@me` filter uses the page `config.filter` map (self-fetch base filter), NOT `defaultFilters` (which users can clear)
- menu labels are the Dutch strings above but any NEW translatable keys stay English per ADR-007 (`hrmq-i18n-locale-completeness` owns locale files)
- do not take over the `/` route — Dashboard lives at `/dashboard` (root-route decision belongs to `hrmq-ia-navigation-alignment`)
