# Tasks — mileage-rules

- [ ] 1. Schema: add `travelType` (enum `business`/`commute`, nullable) and `distanceKm` (number,
  nullable, minimum 0) to `Expense` in `lib/Settings/register.d/hr-expense.json`; bump
  `Expense.version` 0.3.0 to 0.4.0 (verify the current value fresh at HEAD before bumping) per
  REQ-MILE-001
- [ ] 2. Register: bump `lib/Settings/hrmq_register.json` `info.version` (verify the current
  value fresh at HEAD before bumping) per REQ-MILE-001
- [ ] 3. Corpus: add `nl-reiskosten-onbelast-tarief` to `lib/Standards/rules/payroll.json`
  (domain `tax`, framework `nl-loonheffingen`, severity `mandatory`, `machineCheckable: true`,
  `effectiveDate: 2026-01-01`, `parameters.rateEurPerKm: 0.23`, the Belastingdienst
  kilometervergoeding `sourceUrl`) per REQ-MILE-002
- [ ] 4. Corpus: bump `RuleCatalogue::VERSION` (verify the current value fresh at HEAD,
  `SCHEMA.md`'s bump-on-any-change rule) per REQ-MILE-002
- [ ] 5. Checks: new `lib/Standards/Checks/NlReiskostenChecks.php` implementing `CheckProvider`
  — `checks()['Expense']['nl-reiskosten-onbelast-tarief']` with the vacuous-scope guard (wrong
  category, missing/invalid `travelType`, absent or non-positive `distanceKm`, non-numeric
  `amount`, or unreadable catalogue `parameters` all pass vacuously) and `seedSpec()` per
  REQ-MILE-003
- [ ] 6. Checks: confirm (no code change expected) that `RuleEngine::providers()`'s existing
  `Checks/*.php` discovery glob picks up the new provider with zero edits to `RuleEngine.php`;
  record the verification per REQ-MILE-003
- [ ] 7. Seed: add one compliant mileage `Expense` (category `travel`, `travelType: "business"`,
  a `distanceKm`/`amount` pair at or under EUR 0,23/km) to
  `lib/Settings/register.d/hr-seed.json` per design.md's Seed Data note (REQ-MILE-003)
- [ ] 8. Lifecycle: confirm `Expense.configuration.x-openregister-lifecycle` (fields, initial,
  terminal, the four transitions, `NoSelfApprovalGuard`) is byte-identical after this change per
  REQ-MILE-004
- [ ] 9. Tests: `NlReiskostenChecksTest` — compliant mileage, over-rate violation, vacuous
  non-travel category, vacuous missing `travelType`, vacuous missing/zero `distanceKm`, vacuous
  missing catalogue parameters per REQ-MILE-002 / REQ-MILE-003
- [ ] 10. Tests: `RuleAuditService` integration — `occ hrmq:rules:audit` reports
  `nl-reiskosten-onbelast-tarief` as enforced (machine-checkable and enforced coverage both count
  it) and flags the over-rate fixture per REQ-MILE-003
- [ ] 11. Manifest: confirm the existing generic `ExpenseDetail` data widget renders the two new
  nullable fields with no manifest edit (schema-driven rendering); `npm run check:manifest` stays
  green per REQ-MILE-001
- [ ] 12. Quality gates: `composer lint`, full PHPUnit suite, and `npm run check:manifest` all
  green; this change adds no new Vue component, route, or controller
