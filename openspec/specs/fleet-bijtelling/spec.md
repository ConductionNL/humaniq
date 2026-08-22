---
capability: fleet-bijtelling
status: done
built_by: openspec/changes/archive/2026-07-14-fleet-bijtelling
---

# fleet-bijtelling Specification

**Status**: done
**Scope**: humaniq (`depends_on: []`)
**OpenSpec changes**:
- [fleet-bijtelling](../../changes/archive/2026-07-14-fleet-bijtelling/) _(archived 2026-07-14)_
  — the fiscal addition (bijtelling privégebruik auto) for private use of a company car: a
  dedicated `Vehicle`/`CarAssignment` schema pair, the versioned 2026 rate/cap table data, the
  monthly bijtelling folded into the taxable gross BEFORE `PayrollCalculator` runs (a genuine
  engine-input change, unlike the post-tax folds of `retro-adjustments`/`leave-buy-sell`/
  `loonbeslag`), the `Payslip.bijtelling` record, and machine-checkable corpus enforcement via
  `NlFleetChecks`.
- [hrmq-asset-fleet-merge](../../changes/hrmq-asset-fleet-merge/) — **Status**: in-progress —
  retires `Vehicle`/`CarAssignment` as standalone schemas; their fields move onto `Asset`/
  `AssetAssignment` (asset-management) in English names; `PayrollRunService`/`NlFleetChecks`/
  `RuleAuditService` are rewired to the merged shape with the same formula and rule id

## Purpose

Bijtelling privégebruik auto (Wet LB 1964 art. 13bis) is not a post-tax adjustment. Unlike
`retro-adjustments`/`leave-buy-sell`/`loonbeslag` — which fold an already-decided external fact onto
`Payslip.nettoPay` without ever re-invoking the calculator — bijtelling is a percentage of the car's
cataloguswaarde that the employer must add to the taxable wage before loonheffing/premies are
computed: it raises `PayrollCalculator`'s taxable gross itself, not just the net outcome. Before
this change, `hr-assets.json`'s `Asset` schema (category `voertuig`) carried a `kenteken` field but
explicitly disclaimed fiscal semantics, naming this exact follow-up as engine-blocked until
`payroll-core-engine` landed. This change adds a dedicated `Vehicle`/`CarAssignment` schema pair
(deliberately decoupled from the custody-tracking `Asset`/`AssetAssignment`), the versioned 2026
bijtelling rate/cap table data, the `PayrollRunService` gross-fold inserted immediately after the
sick-pay substitution and immediately before `CalculationInput` is constructed, and the
`NlFleetChecks` corpus enforcement — all while leaving `PayrollCalculator`, `CalculationInput`, and
`CalculationResult` byte-identical: the entire integration is upstream of the calculator call.

## Requirements

### Requirement: The 2026 bijtelling percentages and EV cataloguswaarde cap SHALL be versioned, sourced table data (REQ-FLEET-002)

`lib/Standards/tables/nl-2026.json` SHALL carry a `parameters.bijtellingPrivegebruikAuto` group
with leaves `standardPercent` (22), `evReducedPercent` (18) and `evReducedCataloguswaardeCap`
(30000), each `{value, source, verified: true}` per the tables `SCHEMA.md` leaf shape, citing the
Belastingdienst "Bijtelling privégebruik auto 2026" page in `basedOn`. A future tax year's rates
SHALL be added as a new `nl-{year}.json` file (data-only, `RuleCatalogue::VERSION` bumped) with no
change to any PHP file that consumes it.

#### Scenario: The 2026 table carries verified bijtelling parameters
- **GIVEN** `lib/Standards/tables/nl-2026.json` at HEAD after this change
- **WHEN** `parameters.bijtellingPrivegebruikAuto` is read
- **THEN** `standardPercent.value` is 22, `evReducedPercent.value` is 18,
  `evReducedCataloguswaardeCap.value` is 30000, and all three leaves carry `verified: true` with a
  `source` naming the Belastingdienst 2026 bijtelling page

### Requirement: PayrollRunService SHALL add the monthly bijtelling to the taxable gross before the calculator runs (REQ-FLEET-003)

For each employee, `lib/Service/PayrollRunService.php::generate()` SHALL resolve the open `AssetAssignment` covering the period whose referenced `Asset.category` is `vehicle` (the `coveringContract()`/`openSickCaseFor()` id/slug/employeeNumber resolution precedent) and, when one exists, compute `monthlyBijtellingCents = max(0, round(base_cents / 12) − employeeContributionCents)` where `base` is `listPrice × standardPercent/100` for `companyCarTaxCategory: "standard"`, or `min(listPrice, evReducedCataloguswaardeCap) × evReducedPercent/100 + max(0, listPrice − evReducedCataloguswaardeCap) × standardPercent/100` for `evReducedCapped`. This amount SHALL be added to `grossMonthlySalaryCents` immediately after any sick-pay substitution and immediately before `CalculationInput` is constructed, so `PayrollCalculator::calculate()` SHALL receive a `tvl` that already includes it and SHALL NOT be modified in any way. The generated `Payslip` SHALL carry `bijtelling` (the computed monthly amount, null when no assignment covers the period, unchanged by this change) and `assetAssignmentId` (a nullable `$ref` to the `AssetAssignment` used — renamed from `carAssignmentId`, whose `$ref` target no longer exists, null under the same condition).

A referenced `Asset` whose `category` is not `vehicle`, or whose `listPrice` is not numeric (the pre-existing "dangling/missing reference" defensive posture, now also covering "wrong category"), SHALL yield `monthlyBijtellingCents = 0` — no new category-branch is required in the arithmetic itself, since a non-vehicle `Asset` never carries a numeric `listPrice` (REQ-AST-001's conditional-required block guarantees this) and the existing `is_numeric()` guard already degrades that to zero.

#### Scenario: The bijtelling-anchor case reproduces the hand-computed figures (design.md D4)

- GIVEN an employee earning €3.800,00 monthly (wit, korting applied, below AOW, Awf low, Aof laag, Whk 1,52%) with an open `AssetAssignment` referencing an `Asset` of `category: "vehicle"`, `listPrice` €45.000,00 and `companyCarTaxCategory: "standard"`, `employeeContribution` €325,00, covering the period, and the `nl-2026` tables
- WHEN the run generates the payslip
- THEN `bijtelling` is €500,00, `grossPay` is €4.300,00, `loonheffing` is €970,83, `arbeidskorting` is €441,33, `zvw` is €262,30, `werknemersverzekeringen` is €474,29, `vakantiegeldReserved` is €344,00 and `nettoPay` is €3.329,17 — cents-identical to the pre-merge figures, since only field names moved, not the formula

#### Scenario: No covering CarAssignment leaves the payslip unchanged

- GIVEN an employee with no `AssetAssignment` covering the period whose referenced `Asset.category` is `vehicle`
- WHEN the run generates the payslip
- THEN `bijtelling` and `assetAssignmentId` are both null and `grossPay`/`nettoPay` are byte-identical to the pre-change shape

#### Scenario: An open AssetAssignment on a non-vehicle Asset does not contribute a bijtelling

- GIVEN an employee's only open `AssetAssignment` covering the period references an `Asset` with `category: "laptop"`
- WHEN the run generates the payslip
- THEN `bijtelling` is null (the non-numeric `listPrice` on the laptop Asset degrades the calculation to zero, then to null per the existing null-when-no-eligible-assignment posture) and `grossPay`/`nettoPay` are unaffected — a laptop uitgifte never contributes a company-car tax addition

#### Scenario: A large eigen bijdrage floors the bijtelling at zero, never negative

- GIVEN an `AssetAssignment` whose `employeeContribution` exceeds the computed `base/12`
- WHEN the bijtelling is computed
- THEN `bijtelling` is €0,00, not a negative number, and the taxable gross is unchanged from the employee's plain salary

### Requirement: A machine-checkable, auto-discovered rule SHALL verify the recorded bijtelling matches the formula (REQ-FLEET-004)

`lib/Standards/Checks/NlFleetChecks.php` SHALL implement `CheckProvider` (auto-discovered by `RuleEngine::providers()` — no registration wiring) and register the `nl-bijtelling-auto-privegebruik` predicate (Payslip): vacuous when `assetAssignmentId` is null; else it SHALL re-derive `monthlyBijtellingCents` from the referenced `AssetAssignment.employeeContribution` and its `Asset.listPrice`/`companyCarTaxCategory` (via `RuleAuditService::buildRelatedContext()`'s general `related.AssetAssignment.byId` / `related.Asset.byId` indexes, extended by this change to carry the fields this predicate needs — the dedicated `context['fleet']['vehiclesById']`/`['carAssignmentsById']` indexes and the `buildFleetContext()` method that built them are retired as redundant with the general index) using the REQ-FLEET-003 formula, and SHALL flag a violation on any cents-mismatch against the recorded `Payslip.bijtelling`. `lib/Standards/rules/payroll.json`'s `nl-bijtelling-auto-privegebruik` corpus entry keeps its `id`, `domain`, `framework`, `severity`, and `sourceUrl`; only its `statement` prose is updated to the renamed field vocabulary (`listPrice` for "cataloguswaarde", `employee contribution` for "eigen bijdrage" — both common nouns being renamed by this change, not the retained Dutch statutory scheme name "bijtelling privégebruik auto").

#### Scenario: A correctly computed payslip audits clean

- GIVEN the bijtelling-anchor payslip from REQ-FLEET-003
- WHEN `occ humaniq:rules:audit` (or the run-scoped `humaniq:payroll:verify`) runs
- THEN no `nl-bijtelling-auto-privegebruik` violation is reported for that payslip

#### Scenario: A tampered bijtelling value fails the check

- GIVEN the bijtelling-anchor payslip with `bijtelling` hand-edited to €600,00 while its `AssetAssignment`/`Asset` still compute to €500,00
- WHEN the audit runs
- THEN an `nl-bijtelling-auto-privegebruik` violation is reported for that payslip

#### Scenario: Hand-entered payslips with no CarAssignment stay out of scope

- GIVEN a pre-existing hand-entered Payslip with `assetAssignmentId: null`
- WHEN the audit runs
- THEN no `nl-bijtelling-auto-privegebruik` violation is reported for it

#### Scenario: The renamed context indexes produce the identical violation count as before the merge

- GIVEN a fixture set of Payslips/AssetAssignments/Assets equivalent to the pre-merge Payslip/CarAssignment/Vehicle fixture set used to pin this rule (one clean, one tampered, one out-of-scope)
- WHEN the audit runs against each fixture set (pre-merge Dutch-named schemas; post-merge merged schemas)
- THEN both runs report the identical violation count (one) for `nl-bijtelling-auto-privegebruik` — asserted equal, not merely "the tests pass" (the wave-1 brief's silent-empty failure mode: a rule reading a field that moved does not throw, it silently stops matching and reports zero, which reads as compliant)
- @e2e exclude before/after count parity is a PHPUnit/CLI assertion (`NlFleetChecksTest`/`RuleAuditServiceTest`), not a UI flow

### Requirement: The company car's fiscal facts and holding period SHALL be declared on the merged Asset/AssetAssignment schemas (REQ-FLEET-001)

`Vehicle` and `CarAssignment` SHALL NOT exist as standalone schemas after this change; the fiscal facts and holding period they carried SHALL be declared on `Asset`/`AssetAssignment` instead, in English field names.

The dedicated `Vehicle`/`CarAssignment` schema pair (`lib/Settings/register.d/hr-fleet.json`) is retired; `hr-fleet.json` is deleted. The fiscal facts bijtelling needs — `listPrice` (number, nullable; was `Vehicle.cataloguswaarde`), `fuelType` (enum `gasoline|diesel|hybrid|fullyElectric|hydrogen|other`, nullable; was `Vehicle.fuelType`), `companyCarTaxCategory` (enum `standard|evReducedCapped`, nullable; was `Vehicle.bijtellingCategorie`) — are declared on `Asset` (`lib/Settings/register.d/hr-assets.json`), required exactly when `category === "vehicle"` via a schema-level conditional (see the modified `asset-management` capability, REQ-AST-001). The holding period — `employeeContribution` (number, default `0`; was `CarAssignment.eigenBijdrage`) — is declared on `AssetAssignment` alongside its pre-existing `assetId`/`employeeId`/`issuedOn`/`returnedOn` (was `CarAssignment.vehicleId`/`employeeId`/`effectiveFrom`/`effectiveTo`; see REQ-AST-002). No property on `Asset`/`AssetAssignment` newly references anything outside the existing `Asset`/`Employee` `$ref` pair — the merge adds fields, not relations.

This closes the deliberate decoupling `Vehicle`'s own description named at the time ("an employee's company car MAY also be tracked as an Asset for custody purposes, or may not be — neither schema references the other"): the decoupling existed because `Asset`/`AssetAssignment` predated the fiscal-facts requirement (fleet-bijtelling was "engine-blocked" until `PayrollRunService`'s gross-fold existed), not because the underlying real-world thing was actually two things. Once both schemas modelled the same held-item concept end to end (`AssetAssignment.uitgifteDatum`/`innameDatum` and `CarAssignment.effectiveFrom`/`effectiveTo` are the same two dates under different names), keeping them apart was pure duplication.

#### Scenario: A vehicle Asset carries the fiscal facts a bijtelling assignment needs

- GIVEN an `Asset` with `category: "vehicle"`, `listPrice: 45000`, `fuelType: "gasoline"`, `companyCarTaxCategory: "standard"`
- WHEN an `AssetAssignment` referencing it (`assetId`) is created for an `Employee` with `employeeContribution: 325`
- THEN both references resolve as UUID `$ref` fields to existing `Asset` and `Employee` schemas, and no property on either schema references a `Vehicle` or `CarAssignment` schema (neither exists)
