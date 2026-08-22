# Delta — 30-procent-regeling

Consumes `jurisdiction-packs` (merged 2026-07-15, `depends_on`): the 30%-ruling (expatregeling,
Wet LB 1964 art. 31a) — a qualifying incoming employee's granted ruling reduces the taxable wage
the payroll engine computes over, via a new declarative pack binding rather than the `phpStep`
escape hatch, using the versioned 2026 rate/cap/norm parameters and a machine-checkable corpus
surface that flags a ruling applied beyond its term, above the cap, or below the salary norm.

## ADDED Requirements

### Requirement: The 2026 30%-ruling parameters SHALL be versioned, verified table data (REQ-30P-001)

`lib/Standards/tables/nl-2026.json` SHALL carry a `parameters.dertigProcentRegeling` group with leaves `percent` (30, flat for the full term), `maxDurationMonths` (60), `aftoppingsgrens` (`{jaar: 262000, maand: 21833.33}`, the WNT-norm), `salarisnormAlgemeen` (48013) and `salarisnormMasterOnder30` (36497), each `{value, source, verified: true}` per the tables `SCHEMA.md` leaf shape, citing the Belastingdienst Nieuwsbrief Loonheffingen 2026 and the Belastingdienst 30%-regeling beschikking-geldigheid page in `basedOn`.

The Belastingplan-2024 stepped 30/20/10 rate schedule SHALL NOT be modelled: it was reversed by the 2024 Voorjaarsnota coalition compromise before taking effect, so 2025 and 2026 both apply a flat 30% for the whole term. A future tax year (e.g. the flat 27% effective 2027) SHALL be added as a new `nl-{year}.json` file (data-only, `RuleCatalogue::VERSION` bumped) with no change to any PHP file or to `lib/Standards/packs/nl-2026.pack.json`'s step declarations.

#### Scenario: The 2026 table carries the verified 30%-ruling parameters
- **GIVEN** `lib/Standards/tables/nl-2026.json` at HEAD after this change
- **WHEN** `parameters.dertigProcentRegeling` is read
- **THEN** `percent.value` is 30, `maxDurationMonths.value` is 60, `aftoppingsgrens.value.jaar` is 262000, `salarisnormAlgemeen.value` is 48013, `salarisnormMasterOnder30.value` is 36497
- **AND** all five leaves carry `verified: true` with a `source` naming the Belastingdienst 2026 publication

#### Scenario: No stepped rate table exists
- **GIVEN** `parameters.dertigProcentRegeling` at HEAD after this change
- **WHEN** its leaves are enumerated
- **THEN** there is no `fase1`/`fase2`/`fase3` or equivalent stepped-percentage structure
- **AND** `percent` is a single flat value

### Requirement: A granted ruling SHALL be recorded on Employee with a start date, applied rate, end date and reduced-norm flag (REQ-30P-002)

`Employee` SHALL carry `thirtyPercentRulingGranted` (boolean, already present), `thirtyPercentRulingRate` (number, nullable, already present — the engine-consumed applied percentage), `thirtyPercentCappedAtWntNorm` (boolean, already present), and three NEW fields: `thirtyPercentRulingStartDate` (date, nullable — the beschikking's ingangsdatum), `thirtyPercentRulingEndDate` (date, nullable — the beschikking's einddatum), and `thirtyPercentRulingReducedNormApplies` (boolean, default `false` — whether the under-30-with-qualifying-master's reduced salary norm applies to this employee).

Every property SHALL carry a title and description (gate-28). The marker SHALL live on `Employee`, not `EmploymentContract` — consistent with the 3 pre-existing 30%-ruling fields and the `isDga`/`gebruikelijkloonJustification` precedent (`dga-payroll-mode`).

#### Scenario: A granted ruling carries its full lifecycle marker
- **GIVEN** an `Employee` with `thirtyPercentRulingGranted: true`, `thirtyPercentRulingRate: 30.0`, `thirtyPercentRulingStartDate: "2024-03-01"`, `thirtyPercentRulingEndDate: "2029-02-28"`, `thirtyPercentRulingReducedNormApplies: false`
- **WHEN** the record is loaded
- **THEN** all six 30%-ruling fields resolve as declared, and `thirtyPercentRulingEndDate` is exactly 60 months after `thirtyPercentRulingStartDate`

### Requirement: A granted ruling SHALL reduce the taxable wage the engine computes tax and premiums over, while leaving net pay's gross base and grossPay unchanged (REQ-30P-003)

`CalculationInput` SHALL gain one additive constructor parameter `thirtyPercentRulingRate` (float, default `0.0`), so every pre-existing named-argument call site is unaffected. `PayrollRunService` SHALL derive it as `Employee.thirtyPercentRulingGranted ? Employee.thirtyPercentRulingRate : 0.0` when constructing `CalculationInput`.

`lib/Standards/packs/nl-2026.pack.json` SHALL declare a `thirtyPercentExemption` binding computing `min(tvl, @table.dertigProcentRegeling.aftoppingsgrens.maand:cents) × thirtyPercentRulingRate / 100`, and a `belastbaarLoon` binding computing `max(0, tvl - thirtyPercentExemption)`. The `annualised` binding and the `vakantiegeld`/`zvw`/`awf`/`aof`/`wko`/`whk` steps' `base` parameter SHALL reference `belastbaarLoon` instead of `tvl`. The pack's `grossRef` SHALL remain `@binding.tvl` (unreduced), so the interpreter's `net = gross - sum(reduces-net)` fold and `CalculationResult::grossPayCents` both reflect the FULL gross, and `nettoPay` therefore RISES for a granted ruling (the tax saved on the exempted amount flows into net pay) rather than the exempted cash silently vanishing from it. `CalculationResult` SHALL keep its 18 fields — `jurisdiction-packs` REQ-JP-007's contract is not widened; `PayrollRunService` SHALL independently re-derive the exemption amount (the same formula, in PHP) to stamp the ONE new nullable `Payslip.thirtyPercentRulingExemption` field.

No `phpStep` handler SHALL be used or added for this computation.

#### Scenario: The 30%-ruling anchor reproduces the hand-computed figures (design.md D4)
- **GIVEN** an employee earning €3.800,00 monthly (wit, korting toegepast, below AOW, Awf low, Aof laag, Whk 1,52%), `thirtyPercentRulingGranted: true`, `thirtyPercentRulingRate: 30.0`, and the `nl-2026` tables
- **WHEN** the run generates the payslip
- **THEN** `thirtyPercentRulingExemption` is €1.140,00, `grossPay` is €3.800,00 (unchanged), `loonheffing` is €251,17, `arbeidskorting` is €451,58, `appliedTaxRate` is 6,61, `volksverzekeringen` is €194,27, `zvw` is €162,26, `werknemersverzekeringen` is €293,39, `vakantiegeldReserved` is €212,80, `employerCharges` is €455,65 and `nettoPay` is €3.548,83

#### Scenario: Net pay rises, not falls, relative to the same gross without the ruling
- **GIVEN** the same €3.800,00 monthly employee, once with `thirtyPercentRulingGranted: false` (or absent) and once with the REQ-30P-003 anchor's granted ruling
- **WHEN** both payslips are generated
- **THEN** `nettoPay` is €3.081,17 without the ruling and €3.548,83 with it — a rise, not a fall
- **AND** the rise (€467,66) equals exactly the drop in `loonheffing` (€718,83 → €251,17)

#### Scenario: No granted ruling leaves the payslip unchanged
- **GIVEN** an employee with `thirtyPercentRulingGranted` absent or `false`
- **WHEN** the run generates the payslip
- **THEN** `thirtyPercentRulingExemption` is null and every other component is byte-identical to the pre-change shape (`thirtyPercentRulingRate` defaults to `0.0`, so `cappedRate` naturally evaluates to zero and `belastbaarLoon` equals `tvl`)

#### Scenario: The WNT-aftoppingsgrens caps the exemption for a high earner
- **GIVEN** an employee earning €25.000,00 monthly with `thirtyPercentRulingGranted: true`, `thirtyPercentRulingRate: 30.0`
- **WHEN** the exemption is computed
- **THEN** it is €6.550,00 (`min(25.000,00, 21.833,33) × 30%`), not the uncapped €7.500,00

#### Scenario: The pack change ships as data only
- **GIVEN** `lib/Payroll/Dsl/PackInterpreter.php` and every `lib/Payroll/Dsl/Ops/*.php` file at HEAD before and after this change
- **WHEN** they are diffed
- **THEN** neither the interpreter nor any op class changed
- **AND** `lib/Standards/packs/nl-2026.pack.json`'s `packVersion` is bumped while its `dslVersion` is unchanged

### Requirement: A machine-checkable corpus rule SHALL flag a ruling applied beyond its term, above the cap, or below the salary norm (REQ-30P-004)

`lib/Standards/Checks/NlPayrollChecks.php` SHALL contribute three predicates: `nl-30-regeling-looptijd-5jaar` (Employee) — vacuous when `thirtyPercentRulingGranted` is not `true`; else flags when `thirtyPercentRulingEndDate` is absent, is more than `dertigProcentRegeling.maxDurationMonths` after `thirtyPercentRulingStartDate`, or is already in the past while the ruling is still marked granted. `nl-30-regeling-aftoppingsgrens-bedrag` (Payslip) — vacuous when `thirtyPercentRulingExemption` is null; else re-derives `min(grossPay, dertigProcentRegeling.aftoppingsgrens.maand) × employee.thirtyPercentRulingRate / 100` (via `RuleAuditService` context enrichment resolving the referenced Employee) and flags a cents-mismatch against the recorded amount. `nl-30-regeling-salarisnorm` (Employee) — vacuous when `thirtyPercentRulingGranted` is not `true` or `grossMonthlySalary` is non-numeric; else flags when `grossMonthlySalary × 12` is below `thirtyPercentRulingReducedNormApplies ? salarisnormMasterOnder30 : salarisnormAlgemeen`.

`lib/Standards/rules/payroll.json` SHALL carry the three corresponding corpus entries (domain `tax`, jurisdiction `NL`, framework `nl-loonheffingen`, source `Wet LB 1964 art. 31a`, `machineCheckable: true`). The pre-existing `nl-30-regeling-aftoppingsgrens` entry's placeholder `statement` ("verify cap amount") SHALL be fixed to cite the verified €262.000 WNT-norm (REQ-30P-001/D3).

#### Scenario: A ruling past its 5-year term is flagged
- **GIVEN** an `Employee` with `thirtyPercentRulingGranted: true`, `thirtyPercentRulingStartDate: "2019-01-01"`, `thirtyPercentRulingEndDate: "2024-12-31"` (already passed)
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** an `nl-30-regeling-looptijd-5jaar` violation is reported for that employee

#### Scenario: An end date beyond 60 months from the start date is flagged
- **GIVEN** an `Employee` with `thirtyPercentRulingGranted: true`, `thirtyPercentRulingStartDate: "2024-01-01"`, `thirtyPercentRulingEndDate: "2030-06-01"` (66 months later)
- **WHEN** the audit runs
- **THEN** an `nl-30-regeling-looptijd-5jaar` violation is reported

#### Scenario: A tampered exemption amount fails the cap-amount check
- **GIVEN** the REQ-30P-003 anchor payslip with `thirtyPercentRulingExemption` hand-edited to €1.300,00 while the formula still computes €1.140,00
- **WHEN** the audit runs
- **THEN** an `nl-30-regeling-aftoppingsgrens-bedrag` violation is reported for that payslip

#### Scenario: An uncapped-looking exemption on a high earner passes when it correctly reflects the cap
- **GIVEN** the REQ-30P-003 high-earner scenario's payslip with `thirtyPercentRulingExemption` recorded as €6.550,00
- **WHEN** the audit runs
- **THEN** no `nl-30-regeling-aftoppingsgrens-bedrag` violation is reported

#### Scenario: A granted ruling below the general salary norm is flagged
- **GIVEN** an `Employee` with `thirtyPercentRulingGranted: true`, `thirtyPercentRulingReducedNormApplies: false`, `grossMonthlySalary: 3500.00` (annualised €42.000, below €48.013)
- **WHEN** the audit runs
- **THEN** an `nl-30-regeling-salarisnorm` violation is reported for that employee

#### Scenario: A granted ruling below the general norm but above the reduced norm passes when the reduced norm applies
- **GIVEN** the same €3.500,00/month employee with `thirtyPercentRulingReducedNormApplies: true` (annualised €42.000, above the €36.497 reduced norm)
- **WHEN** the audit runs
- **THEN** no `nl-30-regeling-salarisnorm` violation is reported

#### Scenario: A non-granted employee is out of scope for all three checks regardless of salary or dates
- **GIVEN** an `Employee` with `thirtyPercentRulingGranted: false` (or absent) and `grossMonthlySalary: 1500.00`
- **WHEN** the audit runs
- **THEN** no `nl-30-regeling-looptijd-5jaar`, `nl-30-regeling-aftoppingsgrens-bedrag` or `nl-30-regeling-salarisnorm` violation is reported for that employee
