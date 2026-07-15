# Delta — dga-payroll-mode

Consumes `payroll-core-engine` (merged 2026-07-14, `depends_on`): the DGA (director-major-
shareholder) payroll mode — an Employee-level marker that skips werknemersverzekeringen in the
gross-to-net calculator while keeping loonheffing and Zvw, the sourced 2026 gebruikelijkloon norm
as versioned table data, and a machine-checkable, auto-discovered flag for a below-norm DGA.

## ADDED Requirements

### Requirement: A DGA marker on Employee SHALL drive `verzekeringsplichtig: false` in the calculator, zeroing werknemersverzekeringen while retaining loonheffing and Zvw (REQ-DGA-001)

`Employee.isDga` (boolean, default `false`) SHALL drive `CalculationInput.verzekeringsplichtig` (boolean, default `true`, additive constructor parameter) via `PayrollRunService::generate()`, setting `verzekeringsplichtig: !($employee['isDga'] ?? false)`.

`PayrollCalculator::calculate()` SHALL compute `loonheffingCents`, `arbeidskortingCents`,
`volksverzekeringenCents`, `zvwCents`, `vakantiegeldReservedCents` and `nettoPayCents` identically
regardless of `verzekeringsplichtig`. Generated payslips SHALL carry `Payslip.isDga` as a
denormalized copy of `Employee.isDga`, so downstream consumers see why werknemersverzekeringen
read zero.

#### Scenario: The DGA anchor reproduces the hand-computed figures with werknemersverzekeringen zeroed and netto unchanged
- **GIVEN** the anchor input €3.800,00 monthly, wit, korting applied, below AOW, Awf low, Aof
  laag, Whk 1,52, the `nl-2026` tables, and `verzekeringsplichtig: false` (a DGA)
- **WHEN** `calculate()` runs
- **THEN** `awf`/`aof`/`wko`/`whk` are each €0,00, `werknemersverzekeringen` is €0,00,
  `employerCharges` is €231,80 (Zvw only), while `loonheffing` is €718,83, `arbeidskorting` is
  €473,75, `volksverzekeringen` (informative) is €470,86, `zvw` is €231,80,
  `vakantiegeldReserved` is €304,00 and `nettoPay` is €3.081,17 — byte-identical to the non-DGA
  anchor's `loonheffing`/`nettoPay` (design.md D2: `nettoPay` never reduces for
  werknemersverzekeringen in this engine, so it does not change for a DGA either)

#### Scenario: The default (non-DGA) path is unchanged
- **GIVEN** the same €3.800,00 anchor input with `verzekeringsplichtig: true` (the default,
  omitted `isDga`)
- **WHEN** `calculate()` runs
- **THEN** every component matches the pre-existing `payroll-core-engine` anchor exactly:
  `werknemersverzekeringen` €419,14, `employerCharges` €650,94, `nettoPay` €3.081,17

### Requirement: Employer charges SHALL reflect zero Awf/Aof/Wko/Whk employer premiums for a DGA (REQ-DGA-002)

Because Awf, Aof, Wko and Whk are all drawn from the same `TaxTables::werknemersverzekeringen()` parameter bucket and the same capped premieloon, a DGA (`verzekeringsplichtig: false`) SHALL have ALL FOUR employer premium lines at €0,00 — not a partial subset.

So `werknemersverzekeringenCents = 0` and `employerChargesCents` reduces to `zvwCents` only.
Roll-up totals (`PayrollRun.totalEmployerCharges`) computed by `PayrollRunService` SHALL reflect
this reduced figure across a run containing DGA and non-DGA employees.

#### Scenario: A run mixing a DGA and a regular employee totals correctly
- **GIVEN** a draft run with one DGA employee (the DGA anchor) and one regular employee (the
  non-DGA anchor), both €3.800,00 gross
- **WHEN** the run is generated
- **THEN** `totalEmployerCharges` equals €231,80 (DGA) + €650,94 (regular) = €882,74, and neither
  payslip's `nettoPay` differs from the other's per-employee equivalent gross-only calculation

### Requirement: The 2026 gebruikelijkloon norm SHALL be shipped as versioned, sourced table data (REQ-DGA-003)

`lib/Standards/tables/nl-2026.json` `parameters.gebruikelijkloon.jaarnorm` SHALL carry the verified 2026 minimum gebruikelijkloon (`value: 58000`, EUR/jaar), sourced to the Belastingdienst *Loon en aanmerkelijk belang* page (gebruikelijkloonregeling, Wet LB 1964 art. 12a), in the same `{value, source, sourceUrl, verified}` leaf shape as every other `nl-2026.json` parameter.

`TaxTables::gebruikelijkloon(): array` SHALL expose `{jaarnormCents}` via the same `leaf()` +
`euroToCents()` pattern as `zvw()`/`wml()`.

#### Scenario: The getter returns the sourced 2026 figure in cents
- **GIVEN** `TaxTables::load('nl-2026')`
- **WHEN** `gebruikelijkloon()` is called
- **THEN** `jaarnormCents` is `5800000` (€58.000,00)

### Requirement: A machine-checkable, auto-discovered check SHALL flag a DGA paid below the gebruikelijkloon norm unless justified (REQ-DGA-004)

`lib/Standards/Checks/NlDgaChecks.php` SHALL implement `CheckProvider` (auto-discovered by `RuleEngine::providers()`, zero manual registration) contributing the `nl-gebruikelijkloon-norm` corpus rule (`lib/Standards/rules/payroll.json`, severity `conditional`) on `Employee`.

The predicate SHALL be vacuous when `isDga` is not `true`; vacuous when
`gebruikelijkloonJustification` is non-empty (MVP: presence-only exemption, content validation is
a named follow-up); vacuous when `grossMonthlySalary` is absent/non-numeric; else satisfied only
when `grossMonthlySalary × 12` is at least the loaded tables' `gebruikelijkloon().jaarnormCents`.

#### Scenario: A below-norm DGA with no justification is flagged
- **GIVEN** an `Employee` with `isDga: true`, `grossMonthlySalary: 3000.00` (annualised €36.000,
  below the €58.000 norm) and no `gebruikelijkloonJustification`
- **WHEN** the RuleEngine audits the corpus
- **THEN** an `nl-gebruikelijkloon-norm` violation is reported for that employee

#### Scenario: A below-norm DGA with a justification on file passes
- **GIVEN** the same below-norm DGA with `gebruikelijkloonJustification` set to a non-empty value
- **WHEN** the RuleEngine audits the corpus
- **THEN** no `nl-gebruikelijkloon-norm` violation is reported for that employee

#### Scenario: A non-DGA employee is out of scope regardless of salary
- **GIVEN** an `Employee` with `isDga: false` (or absent) and `grossMonthlySalary: 1500.00`
- **WHEN** the RuleEngine audits the corpus
- **THEN** no `nl-gebruikelijkloon-norm` violation is reported for that employee
