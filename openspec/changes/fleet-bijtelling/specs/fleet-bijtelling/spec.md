# Delta — fleet-bijtelling

The fiscal addition (bijtelling privégebruik auto) for private use of a company car: a dedicated
`Vehicle`/`CarAssignment` schema pair, the versioned 2026 rate/cap table data, the monthly
bijtelling folded into the taxable gross BEFORE `PayrollCalculator` runs (a genuine engine-input
change, unlike the post-tax folds of `retro-adjustments`/`leave-buy-sell`/`loonbeslag`), the
`Payslip.bijtelling` record, and machine-checkable corpus enforcement.

## ADDED Requirements

### Requirement: Vehicle and CarAssignment schemas SHALL carry the fiscal facts bijtelling needs (REQ-FLEET-001)

`lib/Settings/register.d/hr-fleet.json` SHALL declare `Vehicle` (`cataloguswaarde` number
required, `fuelType` enum `benzine|diesel|hybride|volledigElektrisch|waterstof|overig` required,
`bijtellingCategorie` enum `standaard|elektrischGeplafonneerd` required, `name` string required,
`kenteken` string nullable, `active` boolean default true, `administrationId` nullable plain
string) and `CarAssignment` (`vehicleId` uuid `$ref Vehicle` required, `employeeId` uuid
`$ref Employee` required, `effectiveFrom` date required, `effectiveTo` date nullable,
`eigenBijdrage` number default 0, `administrationId` nullable plain string), decoupled from the
custody-tracking `Asset`/`AssetAssignment` schema pair (`hr-assets.json`) — neither schema
references the other. Every property SHALL carry a title and description (gate-28).

#### Scenario: A CarAssignment resolves to exactly one Vehicle and one Employee
- **GIVEN** a `CarAssignment` with `vehicleId` and `employeeId` set
- **WHEN** the fragment is loaded
- **THEN** both references resolve as UUID `$ref` fields to existing `Vehicle` and `Employee`
  schemas, and no property on either schema references `Asset` or `AssetAssignment`

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

For each employee, `lib/Service/PayrollRunService.php::generate()` SHALL resolve the open
`CarAssignment` covering the period (the `coveringContract()`/`openSickCaseFor()` id/slug/
employeeNumber resolution precedent) and, when one exists, compute
`monthlyBijtellingCents = max(0, round(base_cents / 12) − eigenBijdrageCents)` where `base` is
`cataloguswaarde × standardPercent/100` for `bijtellingCategorie: standaard`, or
`min(cataloguswaarde, evReducedCataloguswaardeCap) × evReducedPercent/100 + max(0, cataloguswaarde
− evReducedCataloguswaardeCap) × standardPercent/100` for `elektrischGeplafonneerd`. This amount
SHALL be added to `grossMonthlySalaryCents` immediately after any sick-pay substitution and
immediately before `CalculationInput` is constructed, so `PayrollCalculator::calculate()` SHALL
receive a `tvl` that already includes it and SHALL NOT be modified in any way. The generated
`Payslip` SHALL carry `bijtelling` (the computed monthly amount, null when no assignment covers
the period) and `carAssignmentId` (a nullable `$ref` to the `CarAssignment` used, null under the
same condition).

#### Scenario: The bijtelling-anchor case reproduces the hand-computed figures (design.md D4)
- **GIVEN** an employee earning €3.800,00 monthly (wit, korting applied, below AOW, Awf low, Aof
  laag, Whk 1,52%) with an open `CarAssignment` referencing a `Vehicle` of `cataloguswaarde`
  €45.000,00 and `bijtellingCategorie: standaard`, `eigenBijdrage` €325,00, covering the period,
  and the `nl-2026` tables
- **WHEN** the run generates the payslip
- **THEN** `bijtelling` is €500,00, `grossPay` is €4.300,00, `loonheffing` is €970,83,
  `arbeidskorting` is €441,33, `zvw` is €262,30, `werknemersverzekeringen` is €474,29,
  `vakantiegeldReserved` is €344,00 and `nettoPay` is €3.329,17

#### Scenario: No covering CarAssignment leaves the payslip unchanged
- **GIVEN** an employee with no `CarAssignment` covering the period
- **WHEN** the run generates the payslip
- **THEN** `bijtelling` and `carAssignmentId` are both null and `grossPay`/`nettoPay` are
  byte-identical to the pre-change shape

#### Scenario: A large eigen bijdrage floors the bijtelling at zero, never negative
- **GIVEN** a `CarAssignment` whose `eigenBijdrage` exceeds the computed `base/12`
- **WHEN** the bijtelling is computed
- **THEN** `bijtelling` is €0,00, not a negative number, and the taxable gross is unchanged from
  the employee's plain salary

### Requirement: A machine-checkable, auto-discovered rule SHALL verify the recorded bijtelling matches the formula (REQ-FLEET-004)

`lib/Standards/Checks/NlFleetChecks.php` SHALL implement `CheckProvider` (auto-discovered by
`RuleEngine::providers()` globbing `lib/Standards/Checks/*.php` — no registration wiring) and
register the `nl-bijtelling-auto-privegebruik` predicate (Payslip): vacuous when
`carAssignmentId` is null; else it SHALL re-derive `monthlyBijtellingCents` from the referenced
`CarAssignment.eigenBijdrage` and `Vehicle.cataloguswaarde`/`bijtellingCategorie` (via
`RuleAuditService::audit()` context enrichment `fleet.carAssignmentsById` / `fleet.vehiclesById`)
using the REQ-FLEET-003 formula, and SHALL flag a violation on any cents-mismatch against the
recorded `Payslip.bijtelling`. `lib/Standards/rules/payroll.json` SHALL carry the corresponding
corpus entry (domain `tax`, framework `nl-bijtelling-auto`, `machineCheckable: true`).

#### Scenario: A correctly computed payslip audits clean
- **GIVEN** the bijtelling-anchor payslip from REQ-FLEET-003
- **WHEN** `occ hrmq:rules:audit` (or the run-scoped `hrmq:payroll:verify`) runs
- **THEN** no `nl-bijtelling-auto-privegebruik` violation is reported for that payslip

#### Scenario: A tampered bijtelling value fails the check
- **GIVEN** the bijtelling-anchor payslip with `bijtelling` hand-edited to €600,00 while its
  `CarAssignment`/`Vehicle` still compute to €500,00
- **WHEN** the audit runs
- **THEN** an `nl-bijtelling-auto-privegebruik` violation is reported for that payslip

#### Scenario: Hand-entered payslips with no CarAssignment stay out of scope
- **GIVEN** a pre-existing hand-entered Payslip with `carAssignmentId: null`
- **WHEN** the audit runs
- **THEN** no `nl-bijtelling-auto-privegebruik` violation is reported for it
