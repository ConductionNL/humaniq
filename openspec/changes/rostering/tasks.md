# Tasks — rostering

> MVP: define shifts, assign per period, publish, ATW cross-check reusing the three existing corpus
> rules. Verify against HEAD, not this brief. Deeper WFM (auto-optimisation, forecasting) is a
> Non-Goal — an openconnector integration, not code here.

- [ ] 1. Config: `lib/Settings/register.d/hr-roster.json` — `Shift` schema (name, startTime, endTime
  HH:MM, breakMinutes, orgUnitId `$ref OrgUnit`, active) with the night-shift convention per REQ-ROST-001
- [ ] 2. Config: `Roster` schema (period, orgUnitId, administrationId, status) with
  `x-openregister-lifecycle` on `status` (`concept → gepubliceerd` publiceren, `gepubliceerd →
  concept` intrekken, initial concept) per REQ-ROST-002
- [ ] 3. Config: `RosterAssignment` schema (rosterId `$ref Roster`, employeeId `$ref Employee`,
  shiftId `$ref Shift`, date, plannedStart, plannedEnd, plannedBreakMinutes, userId) per REQ-ROST-003
- [ ] 4. Projection: on assignment write, compose plannedStart/plannedEnd (`date` + shift HH:MM,
  night-shift roll-over) and plannedBreakMinutes from the referenced Shift per REQ-ROST-003 (design D2)
- [ ] 5. Checks: `lib/Standards/Checks/NlRosterChecks.php` — register `nl-atw-dagelijkse-rust`,
  `nl-atw-max-werkdag`, `nl-atw-pauze` predicates on `RosterAssignment`, projecting planned-clock
  fields and REUSING NlAttendanceChecks' MIN_REST_HOURS/MAX_SHIFT_HOURS + corpus breakTiers per REQ-ROST-004
- [ ] 6. Checks: same vacuous-pass discipline as NlAttendanceChecks (null plannedEnd, absent/open
  sibling) — no new corpus rule added to labour.json per REQ-ROST-004
- [ ] 7. Context: `RuleAuditService::buildRosterContext()` → `rostering.plannedClockByEmployeeDate`
  from `gepubliceerd`-roster assignments only; wire into `audit()` per REQ-ROST-004 (design D4)
- [ ] 8. Service: `lib/Service/RosterCheckService.php` — resolve roster + assignments via
  ObjectService, run RuleEngine over that set (any publish status, on demand), return per-assignment
  violations + mandatory/advisory counts, never-throw per REQ-ROST-005
- [ ] 9. Command: `lib/Command/RosterCheckCommand.php` (`hrmq:roster:check --roster | --period
  [--administration]`, per-assignment output, non-zero exit on mandatory) + register in
  `appinfo/info.xml` per REQ-ROST-005
- [ ] 10. Controller: `lib/Controller/RosterController.php` (`check`, `#[NoAdminRequired]`,
  RBAC-resolve rosterId first → 404, delegate to service) + route in `appinfo/routes.php` BEFORE the
  SPA catch-all per REQ-ROST-005
- [ ] 11. Manifest: `Shifts` index + `ShiftDetail` per REQ-ROST-006
- [ ] 12. Manifest: `Rosters` index + `RosterDetail` with `lifecycleActions` (publiceren/intrekken),
  the "ATW-controle" `api-call` action (`params:{rosterId:"@objectId"}`), and the FK-scoped
  `RosterAssignments` child object-list per REQ-ROST-002/-ROST-006
- [ ] 13. Manifest: `RosterAssignments` date-sorted index + `RosterAssignmentDetail`; menu entry
  under the planning group; `npm run check:manifest` passes per REQ-ROST-006
- [ ] 14. README: Rostering (MVP) section stating the scope and the deeper-WFM openconnector non-goal
  per REQ-ROST-007
- [ ] 15. Tests: `NlRosterChecksTest` (all three ATW predicates over projected assignments incl.
  vacuous scopes, the 7h-rest violation case) + `RosterCheckServiceTest` (mocked ObjectService:
  roster+assignment resolution, mandatory/advisory counts, concept-vs-published scope) per REQ-ROST-004/-ROST-005
- [ ] 16. Quality gates: `composer lint` green, PHPUnit suite green, `npm run check:manifest` PASS,
  `npm run build` green; SPDX + `@spec` tags on every new PHP method (gate-16); i18n keys ENGLISH,
  Dutch strings only in manifest labels
