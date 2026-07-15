# Tasks — wkr-administration

> Verify against HEAD, not this brief. The Payslip WKR fields (`wkrUsed`, `grossPay`,
> `administrationId`), the tables corpus mechanism, the CheckProvider auto-discovery and the
> `RuleAuditService` cross-object context idiom all already exist — this change adds the
> administration-level layer above them.

- [ ] 1. Table data: add the `wkr` parameter group to `lib/Standards/tables/nl-2026.json`
  (`vrijeRuimteTranche1Percent` 2,00, `vrijeRuimteTranche1Grens` 400000, `vrijeRuimteTranche2Percent`
  1,18, `eindheffingPercent` 80 — sourced Belastingdienst leaves) and add the WKR page to `basedOn`
  per REQ-WKR-002
- [ ] 2. Corpus: add rule `nl-wkr-eindheffing-exposure` (machineCheckable, conditional,
  administration-level) to `lib/Standards/rules/payroll.json` and tighten the `nl-wkr-vrije-ruimte`
  statement to cite the `wkr` table group (drop "(verify percentage)") per REQ-WKR-002/-WKR-004
- [ ] 3. Version: bump `RuleCatalogue::VERSION` (table + rule change) per REQ-WKR-002
- [ ] 4. Schema: add `WkrDeclaration` to `lib/Settings/register.d/hr-objects.json` (administrationId,
  year, date, description, amount, `wkrCategory` enum, nullable `employeeId` `$ref`, sourceReference)
  per REQ-WKR-001
- [ ] 5. Schema: add `WkrAssessment` to the same fragment (fiscaleLoonsom, vrijeRuimte,
  vrijeRuimteUsed, vrijeRuimteRemaining, excess, eindheffingRate, eindheffingDue, status,
  engineVersion, assessedAt — idempotent on administrationId+year) per REQ-WKR-003
- [ ] 6. Context: add `RuleAuditService::buildWkrContext()` — `[administrationId][year] => {loonsom,
  payslipWkrUsed, vrijeRuimteDeclared, eindheffingDeclared}` from `loadAll('Payslip')` +
  `loadAll('WkrDeclaration')`, graceful-degrade to empty per REQ-WKR-003
- [ ] 7. Context: inject `$context['wkr'] = buildWkrContext()` in both `audit()` and
  `auditPayrollRunScope()` per REQ-WKR-004
- [ ] 8. Check: NEW `lib/Standards/Checks/NlWkrChecks.php` (`CheckProvider`) registering
  `nl-wkr-eindheffing-exposure` keyed to `WkrAssessment` — reads `$context['wkr']`, recomputes
  available from the `wkr` table group, vacuous when the aggregate is absent, violation when
  used>available and the exposure was not recorded per REQ-WKR-004
- [ ] 9. Service: NEW `lib/Service/WkrService.php` `assess(administrationId, year)` — tranche
  arithmetic (integer cents), idempotent upsert of `WkrAssessment` via ObjectService, engineVersion +
  assessedAt stamp, never recompute payroll per REQ-WKR-003
- [ ] 10. Service: `--all` path — iterate distinct (administrationId, year) pairs across payslips per
  REQ-WKR-005
- [ ] 11. Command: NEW `lib/Command/WkrAssessCommand.php`
  (`hrmq:wkr:assess --administration ADM --year YYYY [--all]`, outcome print) + register in
  `appinfo/info.xml` per REQ-WKR-005
- [ ] 12. Manifest: `WkrDeclarations` index + `WkrDeclarationDetail` (allowCreate:true) under
  `PayrollGroup` per REQ-WKR-006
- [ ] 13. Manifest: `WkrAssessments` index + `WkrAssessmentDetail` (stat KPIs + "Beoordelen" action)
  under `PayrollGroup`, no new top-level menu (ADR-001); `npm run check:manifest` passes per
  REQ-WKR-006
- [ ] 14. Seed: one `WkrDeclaration` + one `WkrAssessment` (within-vrije-ruimte, zero violations)
  consistent with the seeded Payslip per REQ-WKR-001/-WKR-003
- [ ] 15. Tests: `tests/Unit/Service/WkrServiceTest.php` (mocked ObjectService: tranche math, idempotent
  upsert, used>available → status/eindheffingDue, `--all`) per REQ-WKR-003
- [ ] 16. Tests: `tests/Unit/Standards/NlWkrChecksTest.php` (within-budget satisfied, over-budget +
  exposure recorded satisfied, over-budget + exposure NOT recorded → violation, absent-aggregate
  vacuous) driving the REAL RuleEngine + catalogue + nl-2026 tables per REQ-WKR-004
- [ ] 17. Quality gates: `composer lint` + full PHPUnit green, `npm run check:manifest` PASS,
  `npm run build` green; SPDX + `@spec` on every new PHP method (gate-16); i18n keys ENGLISH (Dutch
  only in manifest labels/messages)

Acceptance criteria (plain reminders, not tasks):
- the vrije-ruimte percentages live ONLY in the `nl-2026.json` `wkr` group — a 2027 change is a new
  table file + a VERSION bump, no PHP edit
- the eindheffing-exposure check is keyed to a persisted `WkrAssessment` and reads the cross-object
  `$context['wkr']` aggregate — it is reached by `occ hrmq:rules:audit` with no bespoke caller (no
  orphaned capability)
- `WkrService` reads Σ persisted `grossPay` for the loonsom; it never invokes `lib/Payroll/` — the
  assessment cannot drift from the engine
- MVP is administration + reporting + exposure alert only: no automated eindheffing payment/filing
