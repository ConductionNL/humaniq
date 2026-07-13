# Tasks — verzuim-analytics-widgets

- [x] 1. Dashboard widgets: add `dash-verzuim-open` (count SickLeaveCase status=gemeld → VerzuimOverzicht) and `dash-verzuim-langdurig` (count SickLeaveCase status=gemeld AND `firstSickDay: { "lte": "@today-294d" }` → VerzuimOverzicht) to the Dashboard `config.widgets` per REQ-VZA-001
- [x] 2. Dashboard widgets: add `dash-leave-pending` (count LeaveRequest status=submitted → LeaveApproval) and `dash-leave-hours-month` (sum of `hours` over LeaveRequest status=approved AND `startDate: { "gte": "@monthStart" }` → LeaveRequests; `_note` on nullable hours) per REQ-VZA-001
- [x] 3. Dashboard layout: append the 4 widgets as one row (4 × width-3, height 2) after the existing KPI rows, shift the recent-hours object-table down, and extend the page `_note` with the verzuim-row rationale — NO changes to any existing widget (the sibling `mss-team-scope` owns the approver re-scope; union-merge `config.widgets`/`config.layout` and re-flow `gridY` if it lands first) per REQ-VZA-001
- [x] 4. Page: add the `VerzuimOverzicht` index page (route `/verzuim`, SickLeaveCase, base `filter: { "status": "gemeld" }`, columns employeeId/firstSickDay/probleemanalyseDue/planVanAanpakDue/uwv42WeekMeldingDue/eerstejaarsevaluatieDue, sort `uwv42WeekMeldingDue` asc, description repeating the no-medical-data stance); `SickLeaveCases` stays byte-identical per REQ-VZA-002
- [x] 5. Menu: add the `VerzuimOverzicht` child ("Verzuimoverzicht", icon `ClipboardPulseOutline`) to `VerlofVerzuimGroup` after `SickLeaveCases` per REQ-VZA-002
- [x] 6. Seeds: add `sickcase-degroot-wvp42` to hr-seed.json (employee-degroot, firstSickDay 2025-06-02, gemeld, loondoorbetaling 70, dues 2025-07-14/2025-07-28/2026-03-23/2026-06-01 with done dates 2025-07-10/2025-07-24/2026-03-19/2026-05-28 — derivation AND overdue green by construction) per REQ-VZA-003
- [x] 7. Seeds: add `leave-jansen-juli` to hr-seed.json (employee-jansen, holiday, 2026-07-16→2026-07-17, 16 hours, approved by manager-pietersen, userId "admin", NO managerUserId) per REQ-VZA-003
- [x] 8. Quality gates: `npm run check:manifest` (Ajv PASS, 0 errors) + `npm run build` + `composer lint` + PHPUnit (full suite — no PHP changed, so zero test deltas expected) all green; verify against the seeded fixtures that the audit reports no new violations (degroot case + July leave contribute zero) and that each of the four widgets' filters matches at least one seed object (run `occ hrmq:rules:audit` and eyeball the live Dashboard when an instance is available, else the standalone RuleEngine-against-seed-fixtures verification plus manual filter evaluation against hr-seed.json)

Acceptance criteria (plain reminders, not tasks):
- ONLY `stat` widgets with the proven `content.source` shape — no chart/delta/endpointSource widgets anywhere in this change
- the 42-weken threshold is exactly `@today-294d` (42 × 7) — never a hardcoded date, never a comparison against the stored due-date field
- Bradford factor, frequency/duration trend charts and verzuimpercentage stay OUT — named non-goals with their technical reasons; follow-up `verzuim-trend-charts` is gated on Dashboard dateRange adoption
- no schema edits, no register version bump, no PHP, no corpus/RuleCatalogue change (kind: config — manifest + seeds only)
- widget ids stay in the `dash-verzuim-*`/`dash-leave-*` namespace (the sibling change owns `dash-team-*`/`dash-hr-*`)
- new seeds must not fire nl-wvp-milestone-derivation/-overdue, nl-loondoorbetaling-minimum, or any leave rule (NlLeaveChecks reads LeaveBalance only — verified)
- i18n: new labels follow the manifest's existing Dutch-label convention; keys stay stable for `hrmq-i18n-locale-completeness`
