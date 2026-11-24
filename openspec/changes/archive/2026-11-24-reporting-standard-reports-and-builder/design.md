# Design: more ready-made reports, and reports people build and share themselves

## Context

Read at `development` af702f78.

- `Reports` (`src/manifest.json:816`, `type: reports`, commit cc2d18ab) lists three cards,
  `WorkforceReport`, `AbsenceReport`, `PerformanceReport`, each a `type: dashboard` page of
  `stat` and `chart` widgets over the register; the page note records that only the scalar
  equality filter of OpenRegister's aggregation endpoint is available.
- `@conduction/nextcloud-vue` 2.40.0 `CnIndexPage` offers saved views (`allowSavedViews`,
  OpenRegister's saved-search views API, `CnSavedViewsControl`, `CnSaveViewDialog` with a
  public toggle) and a native export menu (`allowExport` together with a schema's `exportable`
  flag, `CnMassExportDialog`). No humaniq page switches either on today.
- The trends endpoint (`AnalyticsController::trends`) serves payroll cost and, with
  `reporting-department-figures`, per-unit series.
- Data for the new reports: `PayrollRun` (`period`, `status`, totals), `Payslip` components,
  `Employee.startDate`/`endDate`, `LeaveBalance` (`entitledHours`, `bovenwettelijkHours`,
  `usedHours`, `expiryDate`, `year`, `leaveType`).

## Goals / Non-Goals

**Goals**

- The reports a payroll officer and a controller expect, without code.
- A user-built report is a saved view anyone can reopen, share and export.

**Non-Goals**

- A new reporting engine. Everything here uses the library and the aggregation endpoint.

## Decisions

### D1. Ready-made reports as dashboards

`WageCostReport`, `PayrollRunsReport`, `TurnoverReport`, `LeaveBalanceReport`: dashboard pages
of `stat`, `chart` and `object-table` widgets. Where the aggregation endpoint cannot express a
figure (a remaining balance is entitled plus extra minus used), the report shows the stored
fields side by side rather than inventing a calculation, and the balance projection already
keeps `usedHours` current.

### D2. Saved views are the report builder

`allowSavedViews: true` and `allowExport: true` on `Employees`, `EmploymentContracts`,
`Payslips`, `LeaveRequests`, `SickLeaveCases`, `TimeEntries`, `Expenses`; `exportable: true` on
their schemas. Alternative considered: a humaniq report object with its own designer. Rejected:
it would duplicate OpenRegister's views and the library's controls (ADR-022).

### D3. My reports on the Reports page

The `Reports` page gains a category "My reports" whose cards are the caller's saved views and
the public ones over humaniq's schemas, each opening its index page with the view applied. If the
`reports` page type cannot list views declaratively, a small host section on `Reports` reads
OpenRegister's views API and renders the cards through the library's card grid.

### D4. Export respects field access

Export returns what OpenRegister returns to the caller, so the property authorization of
`compliance-roles-and-field-access` applies to exported columns too.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| ready-made reports | declarative dashboard pages | existing widgets |
| saved views and export | library opt-in flags and schema `exportable` | library features |
| listing saved views on Reports | declarative, or one host section if the page type cannot | the fallback is named |

## Seed data

- One public saved view "Contracts ending this quarter" on `EmploymentContracts` for the seed
  administration, so "My reports" shows a shared report.

## Risks / Trade-offs

- [Export of sensitive fields] → handled by OpenRegister's field authorization, D4.
- [Shared views naming a filter the reader may not use] → a view only stores filters; the reader
  sees what their own rights return.

## Open Questions

- None.
