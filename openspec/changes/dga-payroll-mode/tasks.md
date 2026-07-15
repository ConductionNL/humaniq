# Tasks — dga-payroll-mode

> Verify against HEAD, not this brief — `payroll-core-engine` (merged 2026-07-14) must exist at
> HEAD: `lib/Payroll/{PayrollCalculator,CalculationInput,CalculationResult,TaxTables}.php`,
> `lib/Standards/tables/nl-2026.json`, `lib/Service/PayrollRunService.php`.

- [ ] 1. Schema: `lib/Settings/register.d/hr-objects.json` `Employee` — add `isDga` (boolean,
  default `false`), `gebruikelijkloonJustification` (string, nullable); `Payslip` — add `isDga`
  (boolean) per REQ-DGA-001
- [ ] 2. `lib/Payroll/CalculationInput.php` — add 9th named constructor parameter
  `verzekeringsplichtig` (bool, default `true`); update the class docblock per REQ-DGA-001
- [ ] 3. `lib/Payroll/PayrollCalculator.php` step 9 — gate `awf/aof/wko/whk` computation on
  `$in->verzekeringsplichtig`; both branches feed the same
  `werknemersverzekeringenCents`/`employerChargesCents` assembly per REQ-DGA-001/REQ-DGA-002
  (verify: `verzekeringsplichtig: true` path is byte-identical to pre-change output for every
  existing fixture)
- [ ] 4. `lib/Service/PayrollRunService.php` — `CalculationInput` construction:
  `verzekeringsplichtig: (($employee['isDga'] ?? false) !== true)`; `payslipPayload()`:
  `'isDga' => (($employee['isDga'] ?? false) === true)` per REQ-DGA-001
- [ ] 5. `lib/Standards/tables/nl-2026.json` — add `parameters.gebruikelijkloon.jaarnorm`
  (`value: 58000`, sourced to the Belastingdienst *Loon en aanmerkelijk belang* page); add the
  citation to `basedOn` per REQ-DGA-003
- [ ] 6. `lib/Payroll/TaxTables.php` — add `gebruikelijkloon(): array` (`{jaarnormCents}`), same
  `leaf()` + `euroToCents()` pattern as `zvw()`/`wml()` per REQ-DGA-003
- [ ] 7. `lib/Standards/rules/payroll.json` — add `nl-gebruikelijkloon-norm` (framework
  `nl-gebruikelijkloonregeling`, severity `conditional`, sourced) per REQ-DGA-004
- [ ] 8. NEW `lib/Standards/Checks/NlDgaChecks.php` — `CheckProvider` implementation:
  `nl-gebruikelijkloon-norm` predicate on `Employee` (vacuous non-DGA / justified-present /
  no-salary-yet; else annualised `grossMonthlySalary × 12` vs `TaxTables::load(max(availableIds()))->gebruikelijkloon()['jaarnormCents']`);
  `seedSpec()` returns `[]` per REQ-DGA-004
- [ ] 9. Fixture: `tests/fixtures/payroll-2026/dga-anchor.json` — the design.md D2 anchor input
  (€3.800,00, `isDga: true`, otherwise identical to `anchor.json`) and the DGA-anchor expected
  component table (awf/aof/wko/whk/werknemersverzekeringen all €0,00; employerCharges €231,80;
  every other component byte-identical to the non-DGA anchor, `nettoPay` €3.081,17 unchanged) per
  REQ-DGA-001
- [ ] 10. Tests: `PayrollCalculatorTest` — add the DGA fixture to the table-driven run; add an
  explicit assertion that a `verzekeringsplichtig: true` call with the anchor input still produces
  the pre-existing anchor figures unchanged (regression guard for D1) per REQ-DGA-001/REQ-DGA-002
- [ ] 11. Tests: `BalancingInvariantTest` — extend the cross-fixture invariant
  (`werknemersverzekeringen = awf+aof+wko+whk`, net equation) to cover the DGA fixture; it must
  hold with all four addends at zero per REQ-DGA-002
- [ ] 12. Tests: `PayrollRunServiceTest` — an `isDga: true` employee produces a Payslip with
  `werknemersverzekeringen: 0.00`, `isDga: true`, and `nettoPay` unchanged from the same-gross
  non-DGA case; roll-up `totalEmployerCharges` reflects the zeroed premiums per REQ-DGA-001/-002
- [ ] 13. Tests: NEW `tests/Unit/Standards/Checks/NlDgaChecksTest.php` — vacuous for non-DGA;
  flags a below-norm DGA with no justification; passes a below-norm DGA WITH a non-empty
  `gebruikelijkloonJustification`; passes an at/above-norm DGA per REQ-DGA-004
- [ ] 14. Quality gates: `composer lint`, full PHPUnit suite, `npm run check:manifest` (no manifest
  change expected — schema-only + engine-internal; confirm no stale `_note` references need
  updating)

Acceptance criteria (plain reminders, not tasks):
- `verzekeringsplichtig: true` (the default) reproduces every pre-existing golden fixture
  byte-for-byte — this change must NOT alter the non-DGA path
- exactly `awfCents`/`aofCents`/`wkoCents`/`whkCents` zero for a DGA; `loonheffingCents`/
  `arbeidskortingCents`/`volksverzekeringenCents`/`zvwCents`/`vakantiegeldReservedCents` untouched
- `nettoPayCents` is IDENTICAL between the DGA and non-DGA anchor at the same gross (design.md D2
  grounding correction) — never assert or imply that DGA net pay "rises"
- `NlDgaChecks` is reachable with zero manual registration (auto-discovery only)
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH (ADR-007)
