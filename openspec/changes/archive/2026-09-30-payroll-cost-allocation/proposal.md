---
kind: code
---

# Allocate wage costs to cost centres and projects

## Why

A controller at a municipality closes the month and wants the wage costs of each department
and each subsidised project. humaniq posts one payroll journal per run to the ledger: gross
wages, employer charges, wage tax payable and net wages payable, each as one total. The
ledger in shillinq can book on cost centres and projects, humaniq knows each employee's
department and its cost-centre code (`OrgUnit.costCenter`), and every time entry already
carries the cost centre and project it was booked on. None of it reaches the journal. The
controller splits the wage costs again in a spreadsheet.

The payroll journal change named this as its follow-up: "per-employee/kostenplaats dimensions
are a follow-up".

This change allocates each payslip's cost to cost centres and projects, by a fixed split, by
the hours booked, or by the employee's department, and posts the wage-cost lines of the
journal per cost centre and project.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `pay-cost-allocation` | Allocate wage costs to cost centres or projects. | `no`, built.state `none`: the run and payslips carry no cost centre or project; the journal has one line per total |

### Competitors rated yes

- `pay-cost-allocation`, AFAS Profit: "a formatieverdeling per function spreads wage costs in
  Profit Payroll" (https://help.afas.nl/help/NL/SE/Hrm_Scorfi_Job_Format.htm).
- `pay-cost-allocation`, Visma Raet Youforce: "Cost Allocations (HR Core Loonverdeling)
  distribute an employee over cost centres, an employee can have multiple cost allocations"
  (https://vr-api-integration.github.io/youforce-api-documentation/enterprise_api_intro.html).
- `pay-cost-allocation`, HR2day: "percentage cost allocation over all dimensions"
  (https://www.hr2day.com/nieuws/hr2day-jaguar/).
- `pay-cost-allocation`, Loket.nl: "a journal allocation per employment spreads wage costs over
  cost centres and cost units, based on actual hours or fixed weights"
  (https://developer.loket.nl/ApiDocs#tag/Journal-allocation/operation/PostJournalAllocationByEmploymentId).

### Recorded non-goals this change picks up

- payroll-glpost-shillinq (`openspec/changes/archive/2026-07-12-payroll-glpost-shillinq/proposal.md`):
  "Per-employee splits: one journal per run; per-employee/kostenplaats dimensions are a
  follow-up." This change adds the cost-centre and project dimension. It keeps one journal per
  run and does not add a line per employee.
- org-chart-basic: "reporting-line/cost-center REST wrappers (ADR-022: consume OpenRegister's
  object API)" and "primary-assignment uniqueness". This change reads `OrgUnit.costCenter`
  through the object API, and handles an employee with more than one placement by splitting
  equally rather than by inventing a primary one.

## What Changes

- **A cost allocation per employee.** A new `CostAllocation` says how an employee's wage costs
  are split over a period: a fixed split (cost centre, optional project, percentage, adding up
  to 100) or by hours (the approved hours booked per cost centre and project in the period).
- **A default from the organisation.** Without an allocation, the employee's cost goes to the
  cost centre of the unit they are placed in during the period. Two placements split equally,
  and the payslip says so.
- **Allocated on every payslip.** Each calculation writes the payslip's allocation lines: cost
  centre, project, percentage, and the allocated gross, employer charges and total cost.
- **The journal per cost centre.** The journal's gross-wage and employer-charge debit lines are
  split per cost centre and project, each line carrying the cost-centre and project codes the
  ledger books on. The liability lines stay totals. The journal still balances.
- **Wage costs per cost centre.** A page shows the allocated wage costs per cost centre and per
  period, from a declared aggregation.

## Capabilities

### New Capabilities

- `payroll-cost-allocation`: wage costs allocated per payslip to cost centres and projects,
  posted per cost centre in the payroll journal and reported per period.

## Impact

- `lib/Settings/register.d/hr-objects.json` (or a new `hr-costalloc.json` fragment): new
  schemas `CostAllocation` and `WageCostAllocation`, the latter with an
  `x-openregister-aggregations` entry `wageCostByCostCenter`.
- `lib/Service/CostAllocationService.php` (new): resolves the split per payslip.
- `lib/Service/PayrollRunService.php`: writes the allocation lines after each payslip save.
- `lib/Service/PayrollGLPostService.php`: split debit lines with `costCenterCode` and
  `projectCode`.
- `src/manifest.d/`: allocation pages on the employee, the lines on the payslip, and a wage
  costs page under Payroll.
- `lib/Settings/register.d/hr-seed.json`: one fixed split over two cost centres.

## Out of scope

- A journal line per employee.
- Allocating the liability lines (wage tax and net pay payable).
- Formation and budget planning per cost centre: `reporting-personnel-budget-and-scenarios`.
- Maintaining the cost centres themselves: shillinq's analytical dimensions.

## Cross-app dependencies

- shillinq: accept `costCenterCode` and `projectCode` on the lines of the draft journal entry
  humaniq creates, and carry them onto the ledger lines it materialises on posting. shillinq's
  ledger line (`GLLine`) already has `costCenterCode` and `projectCode`
  (`bookkeeping-cost-centers-dimensions`); the journal entry humaniq writes must pass them
  through. humaniq's cost-centre codes must match shillinq's cost-centre dimension codes.
