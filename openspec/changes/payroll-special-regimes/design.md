# Design: pay early-retirement benefits and political office holders

## Context

Read at `development` af702f78.

- `lib/Service/PayrollRunService.php` `generate()` (line 371) selects employees whose
  `startDate`/`endDate` cover the period and who have a covering contract (lines 415 to 428);
  a leaver is not selected. Payslips are upserted on `(payrollRunId, employeeId)`
  (`enginePayslipsByEmployeeId()`, line 798). `verzekeringsplichtig` is set from
  `!isDga` when the input is built (line 492 onwards).
- `lib/Payroll/PayrollCalculator.php:159` always returns `zvwMode: 'werkgeversheffing'`;
  `lib/Payroll/CalculationResult.php:43` documents `inhouding` as never emitted.
  `lib/Payroll/TaxTables.php:333` already reads `zvw.inhouding`, and
  `lib/Standards/tables/nl-2026.json` carries `zvw.inhouding` 4.85, verified (line 94).
- `lib/Standards/packs/nl-2026.pack.json`: step `zvw` (line 454) is a `cappedRate` with
  incidence `employer-cost`; the employee insurance steps from `awf` (line 467) are gated with
  `"when": "@input.verzekeringsplichtig"`; binding `wit` selects the green table's chain
  (arbeidskorting skipped) from `taxTableColor`.
- `Payslip.zvwMode` (`hr-objects.json:124`) already has the enum `werkgeversheffing`,
  `inhouding`.
- `Employee.publicSectorRegime` (`hr-objects.json:52`): `genormaliseerd` or `ambtenarenwet`.
  `EmploymentContract.type` (line 65): `permanent`, `temporary`, `agency`, `minijob`, `bbl`.
- `grep -rniE 'rvu|vervroegd|wethouder|burgemeester|ambtsdrager|wachtgeld' lib src` finds
  nothing.
- The dependency `payroll-reservation-payouts` adds the special rate for one-off payments
  (inputs `specialPayment` and `annualWageReference`).

## Goals / Non-Goals

**Goals**

- Pay a benefit to someone who is no longer employed, as its own income relationship.
- Keep every regime rule in sourced data, and refuse to pay on an unverified leaf.
- Withhold Zvw where a regime says so, and compute the RVU final levy.

**Non-Goals**

- Deciding entitlement (Appa duration and percentages, RVU eligibility).
- Pension for office holders.

## Decisions

### D1. A benefit is an entitlement object, paid in a second pass

`BenefitEntitlement`: `employeeId`, `kind` (`rvu`, `wachtgeld`), `decisionReference`,
`startDate`, `endDate`, `paymentMode` (`periodic`, `lump-sum`), `monthlyAmount` or
`referenceSalary` with `schedule` (`[{fromMonth, toMonth, percentage}]`),
`offsetIncomeMonthly`, `lumpSumAmount`, `lumpSumPeriod`, `status` (`draft`, `active`,
`ended`), `administrationId`. Lifecycle `activate` (`NoSelfApprovalGuard`) and `end`. After the
employee loop, `generate()` loops the active entitlements covering the period and computes one
payslip each, whether or not the person is still employed. The payslip key becomes
`(payrollRunId, employeeId, benefitEntitlementId)`, with null for a salary payslip, so a person
can have both.

Alternative considered: model the benefit as a contract. Rejected: a contract implies work,
hours and insurance; a benefit has none of these, and Visma models it as a second income
relationship too.

### D2. Regimes are a tables group, never code

`specialRegimes` in the tables has, per regime key (`rvu`, `wachtgeld`, `politiek-ambt`), leaves
for `taxTableColor`, `werknemersverzekeringen` (boolean), `zvwMode`, and for `rvu`
`thresholdPerMonth` and `finalLevyRate`. Each is `{value, source, verified}`. The run reads the
regime of an entitlement (its `kind`) or of a contract of type `political-office`, and passes the
values as inputs. When any leaf the regime needs is unverified or a placeholder, the payslip is
not calculated and the run lists the person with `regime-unverified`.

Alternative considered: hardcode each regime's treatment in the run service. Rejected: the
treatment is law that changes by year, which is exactly what the tables corpus is for.

### D3. The pack learns the Zvw withholding mode

Input `zvwMode` (`werkgeversheffing` default, `inhouding`). Step `zvw` gains
`"when": zvwMode == werkgeversheffing`; a new step `zvwInhouding` (`cappedRate` on the same
base and cap at `@table.zvw.inhouding`, incidence `reduces-net`) runs when the mode is
`inhouding`. `PayrollCalculator` reports the mode the pack used instead of a constant. With the
default every figure equals today's, which the golden vectors prove; new vectors cover a
withheld contribution.

### D4. The RVU final levy is a pack step

Input `rvuThreshold` (cents, default 0 meaning not an RVU). Step `rvuEindheffing`:
`max(0, gross - rvuThreshold) x finalLevyRate`, incidence `employer-cost`, gated on
`rvuThreshold > 0`. For a lump sum the run passes the sum as the special payment of the
dependency's special-rate path and the threshold times the months the lump sum covers.

### D5. Office holders are a contract type with a regime

`EmploymentContract.type` gains `political-office`, with `officeRole` (`burgemeester`,
`wethouder`, `raadslid`, `commissielid`, `statenlid`, `gedeputeerde`), `termStart` and
`termEnd`. The run passes the `politiek-ambt` regime's values, so employee insurance steps are
gated off the way the DGA path already gates them. When the term ends, an HR adviser records the
`wachtgeld` entitlement from the Appa decision; the contract page links to it.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| entitlement lifecycle | declarative `x-openregister-lifecycle` with `NoSelfApprovalGuard` | the engine's state machine |
| regime parameters | tables corpus data | law that changes by year |
| Zvw withholding and RVU levy | declarative pack steps | the pack is the engine's configuration |
| benefit pass and payslip key | imperative, `PayrollRunService` | orchestration over stored objects |

## Seed data

- `employee-degroot` gains an active monthly `rvu` entitlement from 2026-07 of 2000.00 a month.
- A new employee `employee-wethouder` with a `political-office` contract as `wethouder`.

## Risks / Trade-offs

- [Regime leaves unverified at first] -> payslips for those regimes are refused, visibly, until
  a maintainer confirms the figures; no benefit is paid with a guessed rule.
- [A person with salary and benefit in the same period] -> the extended payslip key keeps both,
  and the annual statement sums both until the filing change separates income relationships.

## Open Questions

- Should the annual statement show a benefit as a separate statement per income relationship?
  This design leaves the annual statement per person.
