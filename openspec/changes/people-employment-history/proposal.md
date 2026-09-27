---
kind: code
---

# One employment history per employee

## Why

An HR adviser who opens an employee in humaniq today reads that person's history by
opening ten separate list panels on `EmployeeDetail` (contracts, placements,
compensation proposals, payslips, reviews and more) and putting them in date order in
their head. Nothing shows when a contract started, when the person moved to another
unit and when their salary changed on one line. The one class with "timeline" in its
name, `EmployeeTimeline`, counts headcount for the Dashboard and never looks at a single
person.

The same gap shows for people with more than one job. humaniq already lets a person hold
several concurrent `EmploymentContract`s, each with its own scale and hours, but nothing
puts them side by side: HR cannot see the combined hours, which contract is the main one,
or the person's leave across both jobs in one place. Two municipal tenders ask for
exactly that.

This change adds one chronological history and one combined view of concurrent
employments to the employee page.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-employee-timeline` | See an employee's history of contracts, placements and changes on one timeline. | `no`, built.state `built`: `EmployeeTimeline` feeds the Dashboard headcount trend only; `EmployeeDetail` shows each record type in its own panel |
| `td-multiple-employments` | Hold several concurrent employments for one person, each with its own scale and hours, and see them together. | `partial`: several contracts per person work; no combined view and no leave overview across them |

### Demand

- `td-multiple-employments`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E5.12 and E23.05, meerdere gelijktijdige dienstverbanden, and W0.5,
  een verlofoverzicht; also Delft Support E16).

### Competitors rated yes

- `ppl-employee-timeline`, Personio: "the History tab in the employee profile shows a
  record of all data changes made to an employee's attributes"
  (https://support.personio.de/hc/en-us/articles/36539231813661-Summary-of-Employee-profile-and-Employment-details-permissions-New-experience).
- `td-multiple-employments`, AFAS Profit: "Profit supports several concurrent employments
  for one employee (for hospitals, care and schools), each its own dienstverband"
  (https://help.afas.nl/help/NL/SE/Hrm_SimCnt.htm).
- `td-multiple-employments`, Visma Raet Youforce: "a person can have multiple employments
  at the same time, each with its own Salary Details (payroll scale, seniority) and hours"
  (https://vr-api-integration.github.io/youforce-api-documentation/enterprise_api_intro.html).
- `td-multiple-employments`, HR2day: "an employee can have several employment relations
  and a process chooses which one" (https://www.hr2day.com/nieuws/hr2day-lion/).
- `td-multiple-employments`, Loket.nl: "list of employments for an employee, each with its
  own working hours and wage records" (https://developer.loket.nl/ApiDocs#tag/Employment).

## What Changes

- **A history read for one employee.** A new `EmployeeHistoryService` composes, on read,
  the dated events that belong to one employee: contract start and end, org placement
  start and end (`OrgAssignment`), applied compensation changes (`CompAdjustment` with
  `appliedAt`), approved leave and sickness periods, and review outcomes. Nothing is
  stored; the history is always the current records in date order.
- **A history section on `EmployeeDetail`.** The page gains a body section that renders
  the history with the library's `CnTimelineView`, newest first, each event linking to
  the record behind it.
- **A combined employments block.** When an employee holds more than one contract that is
  active on today's date, `EmployeeDetail` shows them side by side: scale, hours per week,
  CAO and type per contract, and the summed hours and FTE.
- **Leave across employments.** The same block shows the employee's leave balances for the
  current year once, with the note that humaniq keeps leave per person and not per
  contract, which is how Dutch statutory leave accrues (BW 7:634 counts per employee).
- **Read access follows the existing rules.** The history endpoint returns only what the
  caller may read under OpenRegister RBAC, through the existing `RbacObjectReader`.

## Capabilities

### New Capabilities

- `employee-history`: a composed, chronological history per employee and a combined view
  of concurrent employments on the employee page.

## Impact

- `lib/Service/EmployeeHistoryService.php` (new): composes events from `EmploymentContract`,
  `OrgAssignment`, `CompAdjustment`, `LeaveRequest`, `SickLeaveCase` and `PerformanceReview`.
- `lib/Controller/EmployeeHistoryController.php` (new) and `appinfo/routes.php`:
  `GET /api/employees/{id}/history` and `GET /api/employees/{id}/employments`.
- `src/manifest.d/hr-objects.json`: `EmployeeDetail` gains two `bodyWidgets` sections.
- `src/registry.js` and one host section component that hands the events to
  `CnTimelineView`.
- No schema change. `EmployeeTimeline` (the headcount helper) is untouched.

## Out of scope

- Field-level change history of the `Employee` record itself: OpenRegister's audit trail
  already records it and stays in the sidebar tab.
- Leave balances per contract. humaniq keeps one balance per person and leave type, and
  this change does not split it.
- Payroll per employment. The payroll engine reads `Employee.grossMonthlySalary`; paying
  two employments separately is a payroll change, not this one.
