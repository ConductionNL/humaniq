# Design: allocate wage costs to cost centres and projects

## Context

Read at `development` af702f78.

- `OrgUnit` (`lib/Settings/register.d/hr-org.json:5`): `type` (`afdeling`, `team`,
  `kostenplaats`), `parentUnitId`, `costCenter` (line 47, "The key shillinq-side consumers
  resolve owners through"). `OrgAssignment` (line 60): `employeeId`, `orgUnitId`,
  `startDate`, `endDate`.
- `lib/Service/OrgResolutionService.php:153` `resolveCostCenters()` walks employee to active
  placement to unit cost centre; `uniqueOrNull()` (line 185) stamps a value only when it is
  unique.
- `TimeEntry` (`hr-timesheet.json:5`): `costCenter` (line 94, stamped by
  `TimeEntryStampListener` from the org chain, never typed) and `projectId` (line 79).
  `Timesheet.costCenter` is the homogeneous value or null.
- `lib/Service/PayrollGLPostService.php:242` `buildLines()`: two debit lines (gross, employer
  charges) and two credit lines (wage tax payable, net wages payable), each
  `{side, accountNumber, amount, description}`, from the run totals.
- `Payslip` (`hr-objects.json:97`): `grossPay`, `werknemersverzekeringen`, `zvw`; no cost
  centre.
- shillinq's register (`ConductionNL/shillinq`, development,
  `lib/Settings/shillinq_register.json`) defines `GLLine` with `costCenterCode`,
  `costCarrierCode`, `projectCode` and `dimensions`, and
  `lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json` declares per-cost-centre
  aggregations over those fields.

## Goals / Non-Goals

**Goals**

- Every payslip's cost has an allocation, explicit or by default, that adds up to the payslip.
- The journal books wage costs per cost centre and project.
- A controller reads wage costs per cost centre per period in humaniq.

**Non-Goals**

- A line per employee in the journal.
- Allocating liabilities.

## Decisions

### D1. An allocation is an effective-dated object per employee

`CostAllocation`: `employeeId`, `contractId` (optional), `basis` (`fixed` or `hours`),
`splits` (for `fixed`: a list of `{costCenter, projectId, percentage}` adding up to 100),
`startDate`, `endDate`, `administrationId`. A save whose fixed splits do not add up to 100 is
refused by a small guard. Overlapping allocations for the same employee are refused the way
`WorkingPatternOverlapListener` refuses overlapping patterns.

Alternative considered: a split field on the contract. Rejected: splits change more often
than contracts, and Visma and Loket both keep them as separate, dated records.

### D2. Resolution order: allocation, then hours, then placement

`CostAllocationService::splitFor(employee, period, runId)` returns shares:

1. a `fixed` allocation covering the period: its splits;
2. an `hours` allocation: the employee's approved time entries in the period, grouped by
   `costCenter` and `projectId`, as shares of the hours; entries without a cost centre fall
   back to step 3's cost centre;
3. no allocation: the cost centres of the placements covering the period, split equally when
   there is more than one, with `allocationSource: placement-equal-split`;
4. nothing resolves: one share with `costCenter: null`, `allocationSource: unallocated`.

Alternative considered: always allocate by hours. Rejected: salaried employees without
timesheets would have no allocation at all.

### D3. Allocation lines are objects, and the report is declared

After each payslip save the run replaces the payslip's `WageCostAllocation` objects:
`payslipId`, `payrollRunId`, `period`, `administrationId`, `employeeId`, `costCenter`,
`projectId`, `percentage`, `gross`, `employerCharges` (`werknemersverzekeringen + zvw`),
`totalCost`, `allocationSource`. Amounts are split in cents with the rounding remainder on the
largest share, so the lines add up to the payslip exactly. `WageCostAllocation` declares
`x-openregister-aggregations` `wageCostByCostCenter`: sum of `totalCost` grouped by
`costCenter` and `period`, which the wage costs page shows.

Alternative considered: an array field on the payslip. Rejected: an array cannot be aggregated
declaratively, and the report is a plain group-and-sum.

### D4. The journal splits its debit lines

`buildLines()` groups the run's `WageCostAllocation` rows by `(costCenter, projectId)` and
writes one gross debit line and one employer-charges debit line per group, each with
`costCenterCode` and `projectCode`. Unallocated amounts form one group with no codes. The two
credit lines stay totals. The debit groups add up to the run totals, so the journal balances as
before; a run without allocation rows produces the same four lines as today.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| splits add up to 100, no overlap | PHP guard and listener on save | preconditions the schema cannot express |
| split resolution per payslip | imperative `CostAllocationService` | cross-schema and date logic |
| wage costs per cost centre | declarative `x-openregister-aggregations` | a group-and-sum on one schema |
| journal lines | imperative, `PayrollGLPostService` | the journal is built there |

## Seed data

- A `fixed` allocation for `employee-devries`: 60% `CC-100`, 40% `CC-200`, from 2026-01-01.
- `employee-jansen` has none and falls back to the `CC-100` of `orgunit-consultancy`.

## Risks / Trade-offs

- [Cost-centre codes that do not exist in the ledger] -> the journal is created as a draft in
  shillinq and its own posting validation refuses unknown codes, as it does for accounts.
- [Hours booked after the run] -> the hours basis uses the approved entries the run saw; a
  late entry changes the next period's split, not a posted one.

## Open Questions

- Should a project allocation also carry shillinq's cost carrier (`costCarrierCode`)? This
  design passes only cost centre and project.

## As built (2026-09-30)

- **Amounts allocated.** The lines split the same gross and employer charges the run adds to its
  totals (the engine's gross and charges plus a retro adjustment's gross and charges), not
  `werknemersverzekeringen + zvw` of the payslip. That keeps the wage costs page equal to the
  journal, which books the run totals.
- **Where the run writes them.** `PayrollRunService` collects each saved payslip's figures and
  calls `CostAllocationService::allocateRun()` once after the loop. It removes every earlier line
  of the run first, so a payslip that no longer computes loses its lines too. A failing allocation
  is logged and never fails the run; the journal then books the totals without codes.
- **Hours with no hours.** An `hours` allocation with no approved hours in the period falls back to
  the placement, as a fixed allocation without valid splits does.
- **Placements covering the period.** A placement counts when it is active on the first day of the
  period or starts within it. Distinct cost centres split equally.
- **Guards.** One listener, `CostAllocationGuardListener`, refuses a fixed allocation without
  splits, fixed splits that do not add up to 100 (to the hundredth), and an allocation that
  overlaps another of the same employee (fail closed when the stored ones cannot be read).
- **Journal.** `buildLines()` takes the run's allocation lines; groups without a cost centre and
  whatever the lines do not cover form one debit line without codes, so the debits always equal
  the run totals. `PayrollGLPost.lines` declares `costCenterCode` and `projectCode` (schema 0.3.0).
- **Wage costs per cost centre.** Shown on the payroll run page as a bar chart summing
  `totalCost` per cost centre over the run's lines (the declared `wageCostByCostCenter`
  aggregation's shape), with a drill-down to the Wage costs page, which lists the lines and filters
  on period, cost centre and source. A run is one period, so the run page is the per-period view.
- **shillinq.** Its posting materialiser already copies `costCenterCode` and `projectCode` from the
  journal lines onto `GLLine` (`MaterialiseGlTransactionAction`), and its JournalEntry line schema
  accepts them (no `additionalProperties: false`). It does not declare them; a note for shillinq
  is drafted outside this repo.
