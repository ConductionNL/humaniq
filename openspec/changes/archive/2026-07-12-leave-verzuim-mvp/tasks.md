# Tasks — leave-verzuim-mvp

- [x] 1. Schema: add `LeaveBalance` (employeeId/year/leaveType/entitledHours/bovenwettelijkHours/usedHours/contractHoursPerWeek/expiryDate + `x-openregister-calculations` remainingHours, materialise:true) to `lib/Settings/register.d/hr-leave.json` per REQ-LVM-003 — `LeaveRequest` untouched; register `info.version` 0.2.0 → 0.3.0
- [x] 2. Schema: new fragment `lib/Settings/register.d/hr-verzuim.json` with `SickLeaveCase` (case fields + 8 stored WVP milestone dates + `x-openregister-lifecycle` gemeld⇄hersteld with herstellen/heropenen carrying-write semantics) per REQ-VWP-001, no medical fields per REQ-VWP-002
- [x] 3. Corpus: new `lib/Standards/rules/labour.json` (`domain: labour`, version 2026-07) with `nl-verlof-wettelijk-minimum`, `nl-verlof-saldo-niet-negatief`, `nl-verlof-vervaltermijn`, `nl-wvp-milestone-derivation` (with `parameters.milestoneWeeks`), `nl-wvp-milestone-overdue`, `nl-loondoorbetaling-minimum` per REQ-LVM-004 + REQ-VWP-003; bump `RuleCatalogue::VERSION` to `2026-07`
- [x] 4. Checks: implement `lib/Standards/Checks/NlLeaveChecks.php` (wettelijk minimum via `contractHoursPerWeek` snapshot, vacuous pass when null; saldo; vervaltermijn = `<year+1>-07-01`) per REQ-LVM-005
- [x] 5. Checks: implement `lib/Standards/Checks/NlVerzuimChecks.php` (derivation with week offsets read from rule `parameters`, NOT hard-coded; overdue mandatory / ≤14-days advisory against the audit run date; loondoorbetaling ≥ 70 on open cases) per REQ-VWP-004
- [x] 6. Unit tests: PHPUnit coverage for the three leave checks — compliant balance, under-granted, over-used, null snapshot vacuous pass, wrong/missing expiryDate (extend `tests/Unit/`, bootstrap per `tests/bootstrap.php`)
- [x] 7. Unit tests: PHPUnit coverage for the three verzuim checks — correct/deviating/null-on-open derivation, overdue vs approaching vs done vs hersteld, loondoorbetaling below/at 70
- [x] 8. Manifest: `VerlofVerzuimGroup` menu group + `LeaveRequests`, `LeaveApproval` (defaultFilters status=submitted), `LeaveBalances` index pages + `LeaveRequestDetail` with lifecycleActions matching the EXISTING hr-leave.json lifecycle action-for-action per REQ-LVM-001/002/006
- [x] 9. Manifest: `SickLeaveCases` index + `SickLeaveCaseDetail` (Case + Poortwachter-milestones data widgets, files with no-medical-data warning, lifecycleActions Herstellen/Heropenen) + deepLinks for LeaveRequest/LeaveBalance/SickLeaveCase per REQ-VWP-005; `npm run check:manifest` passes
- [x] 10. Seed data: three LeaveBalances (compliant / over-used / under-granted) per REQ-LVM-006 and three SickLeaveCases (recovered flu / week-7 overdue / week-41 approaching, Due dates exact derivations) per REQ-VWP-006 in `lib/Settings/register.d/hr-seed.json` (placeholders only)
- [x] 11. Quality gates: `composer check:strict` green (lint/phpcs/phpmd/psalm/phpstan/phpunit — phpcs/psalm/phpstan configs absent in this checkout, skipped per builder instructions); ran the audit logic (RuleEngine::evaluate) against the seeded LeaveBalance/SickLeaveCase objects standalone (no live dev container in this environment) and confirmed exactly the expected violations: jansen balance clean, devries → `nl-verlof-saldo-niet-negatief`, bakker → `nl-verlof-wettelijk-minimum`; jansen sick case clean, devries + bakker → `nl-wvp-milestone-overdue`, no derivation/loondoorbetaling violations anywhere — no pre-existing rule regresses (74/74 PHPUnit tests pass)

Acceptance criteria (plain reminders, not tasks):
- LeaveRequest schema, lifecycle and NoSelfApprovalGuard remain byte-identical — this change only consumes them
- lifecycle edges exactly as REQ-VWP-001 (no gemeld→gemeld heropenen, no invented cancel/withdraw on LeaveRequest)
- `remainingHours` declared only in `x-openregister-calculations`, never as a stored property (validator shadowing)
- corpus rule ids/domains/frameworks/sources/sourceUrls exactly as the design.md table; milestone week offsets live in rule `parameters`
- no diagnosis/symptom/cause/medical field anywhere on SickLeaveCase (REQ-VWP-002); files widgets labeled for process documents only
- gate-28: title + description on every new property; SPDX docblocks on the two new check providers
- i18n: new page labels/actions use English keys per ADR-007 where keys apply (Dutch display strings follow the existing manifest convention; keep keys stable)
