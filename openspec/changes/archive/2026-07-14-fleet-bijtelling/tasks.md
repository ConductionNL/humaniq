# Tasks — fleet-bijtelling

> Spec-only change (openspec-ff): no code has been written yet. Verify every referenced file
> against HEAD before implementing, not against this brief — `payroll-core-engine` is merged, but
> line numbers in `PayrollRunService.php` may have shifted since 2026-07-15.

- [x] 1. Schema: `lib/Settings/register.d/hr-fleet.json` (new fragment, `x-hrmq-fragment:
  hr-fleet`) — `Vehicle` (`cataloguswaarde`, `fuelType`, `bijtellingCategorie`, `name`, `kenteken`,
  `active`, `administrationId`) and `CarAssignment` (`vehicleId`, `employeeId`, `effectiveFrom`,
  `effectiveTo`, `eigenBijdrage`, `administrationId`), every property titled + described (gate-28)
  per REQ-FLEET-001
- [x] 2. Table data: `lib/Standards/tables/nl-2026.json` — `parameters.bijtellingPrivegebruikAuto`
  (`standardPercent` 22, `evReducedPercent` 18, `evReducedCataloguswaardeCap` 30000), each leaf
  `{value, source, verified: true}`, plus the Belastingdienst citation in `basedOn`; bump
  `RuleCatalogue::VERSION` per REQ-FLEET-002
- [x] 3. Service: `lib/Service/PayrollRunService.php` — open-CarAssignment index
  (`openCarAssignmentsByEmployeeKey()`, the `openSickCasesByEmployeeKey()` precedent), the D3
  bijtelling formula (standaard vs elektrischGeplafonneerd two-tier), the gross fold inserted
  immediately after the sick-pay substitution and immediately before `CalculationInput` is
  constructed, `Payslip.bijtelling` / `Payslip.carAssignmentId` payload fields (null when no
  covering assignment) per REQ-FLEET-003
- [x] 4. Schema: `lib/Settings/register.d/hr-objects.json` — `Payslip.bijtelling` (nullable
  number) + `Payslip.carAssignmentId` (nullable `$ref` CarAssignment), version bump, per
  REQ-FLEET-003
- [x] 5. Checks: `lib/Standards/Checks/NlFleetChecks.php` (auto-discovered, no registration
  wiring) — `nl-bijtelling-auto-privegebruik` predicate (vacuous-scope guard when
  `carAssignmentId` null; re-derive and cents-compare against `Payslip.bijtelling`) +
  `RuleAuditService` context enrichment (`fleet.vehiclesById`, `fleet.carAssignmentsById`) per
  REQ-FLEET-004
- [x] 6. Rule corpus: `lib/Standards/rules/payroll.json` — new entry `nl-bijtelling-auto-
  privegebruik` (domain `tax`, framework `nl-bijtelling-auto`, source Wet LB 1964 art. 13bis,
  `machineCheckable: true`) per REQ-FLEET-004
- [x] 7. Golden fixture: `tests/fixtures/payroll-2026/bijtelling-anchor.json` — the design.md D4
  hand-computed case (€3.800 salary + €500 bijtelling → €4.300 taxable gross; cataloguswaarde
  €45.000, standaard 22%, eigenBijdrage €325,00), byte-matching every figure in D4 (also pinned
  digit-for-digit by a dedicated `PayrollCalculatorTest` method). The floored-at-zero case (large
  eigenBijdrage) and the no-assignment case live in `PayrollRunServiceTest` instead of as separate
  calculator fixtures -- `eigenBijdrage`/`CarAssignment` resolution is `PayrollRunService`'s
  concern, not `PayrollCalculator`'s (the calculator only ever sees the final `tvl`, so neither
  concept is expressible as a bare calculator fixture) per REQ-FLEET-003
- [x] 8. Tests: `PayrollRunServiceTest` (bijtelling fold present/absent/floored, mocked
  ObjectService) + `NlFleetChecksTest` (clean, tampered, vacuous-scope cases) +
  `PayrollCalculatorTest` extended with the bijtelling-anchor fixture (proves `tvl`-in equals the
  D4 chain with zero calculator changes) per REQ-FLEET-003/-FLEET-004
- [x] 9. Manifest: `Vehicles`/`CarAssignments` index + detail pages (fleet admin surface,
  `allowCreate` per the `Asset`/`AssetAssignment` convention); `npm run check:manifest` passes
- [x] 10. Quality gates: `composer lint`, full PHPUnit suite, `npm run check:manifest`,
  `npm run build` all green; confirm `PayrollCalculator.php`/`CalculationInput.php`/
  `CalculationResult.php` are byte-identical to HEAD (diff, not just "no PR comment touches them")

Acceptance criteria (plain reminders, not tasks):
- `PayrollCalculator` and its two value objects are NEVER edited by this change — the bijtelling
  fold lives entirely in `PayrollRunService`, upstream of the `calculate()` call
- `Vehicle`/`CarAssignment` carry zero references to `Asset`/`AssetAssignment` and vice versa
- the D4 anchor is hand-computed in design.md BEFORE implementation — the fixture must match it,
  not the other way around (the payroll-core-engine self-consistency-risk mitigation, reused)
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH; Dutch strings
  only in manifest labels/messages (existing convention)
- grijs-kenteken/bestelauto regimes, the ≤500km-privé exemption, the hydrogen/fully-solar uncapped
  rate, 60-month DET re-rating and an overlap/single-active-assignment guard are OUT of scope —
  named follow-ups, not silently dropped
