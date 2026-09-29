# Design: absence, wage costs and occupancy per department, and a manager's own dashboard

## Context

Read at `development` af702f78, plus `people-formation-positions` (this pass) for occupancy.

- `lib/Controller/AnalyticsController.php`: `trends()` (:108) reads `metric` and `period`,
  authorises through `authorizeCaller()` (:195) which requires the active administration role
  to be `hr` or `accountant` (`ALLOWED_ROLES`, :71), and calls `AnalyticsService::getTrends()`.
- `lib/Service/AnalyticsService.php`: `getTrends()` (:156) dispatches to
  `absenceRateSeries()` (:190), `payrollCostSeries()` (:219), `billableRatioSeries()`,
  `headcountSeries()`, `approvalLeadTimeSeries()`; rows load through `loadFiltered()` by
  administration (:481).
- `lib/Service/AbsenceRateService.php`: FTE-weighted rate from `SickLeaveCase.absenceProgression`
  and contracts; its docblock (:12) records that the old frequency non-goal rested on data that
  now exists.
- `payrollCostSeries()` sums `totalGross + totalEmployerCharges` of finalised runs per period;
  payslips carry `grossPay` per employee and `payrollRunId`.
- Units: `OrgAssignment` places employees; `OrgResolutionService::isActiveOn()` and
  `activeUnits()`; `OrgUnit.managerId` references the managing `Employee`, whose
  `nextcloudUserId` identifies the manager.
- Pages: `Dashboard` and `AbsenceReport` in `src/manifest.json`; `MijnHr` in
  `src/manifest.d/personal-dashboard.json`; the chart widget binds to the trends endpoint through
  humaniq's `TrendChartWidget` (`src/widgets`).

## Goals / Non-Goals

**Goals**

- Every existing trend metric answerable per unit, plus absence frequency.
- A manager sees their units' totals and nothing else.

**Non-Goals**

- A second analytics service. The unit is a filter on the existing series.

## Decisions

### D1. Unit scope as a member set

`getTrends(metric, period, administrationId, ?orgUnitId)`: when a unit is given, the service
resolves per period the set of employee ids placed in that unit or its children on any day of
the period (`OrgResolutionService`), and every series filters its rows to that set. Wage cost per
unit sums payslip `grossPay` plus the employer charge share (the run's
`totalEmployerCharges / totalGross` ratio applied to the member payslips), because employer
charges are held per run, not per payslip.

### D2. Frequency

`absence-frequency`: the number of `SickLeaveCase` rows whose `firstSickDay` falls in the period,
divided by the average number of members in the period, annualised. Returned beside the rate, with
`null` (not zero) when there were no members, the `percentage: null` discipline the rate keeps.

### D3. Who may ask for what

`authorizeCaller()` keeps `hr` and `accountant` for any unit. A caller whose `nextcloudUserId`
is the manager of a unit (through `OrgUnit.managerId`) may ask for that unit and its children
only, and only for totals. Any other combination is 403.

### D4. The manager's page

`MijnAfdeling`: tiles and charts for absence rate, frequency, wage cost (trends endpoint with
the managed unit), net FTE against budget and vacant FTE (`GET /api/formation/occupancy` from
`people-formation-positions`), open vacancies (a `stat` over `Vacancy` with status published and
the unit's place). A manager of several units picks one.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| per-unit rate, frequency, wage cost | imperative, the existing analytics series | cross-schema and period-bound, as today |
| manager scope | imperative check in `authorizeCaller()` | a guarded endpoint, the existing pattern |
| pages and tiles | declarative manifest widgets | the existing chart and stat widgets |

## Seed data

No schema change. The seeded hierarchy and sickness cases give two teams with different rates;
one seed manager manages Team Burgerzaken.

## Risks / Trade-offs

- [Small units reveal individuals] → a unit with fewer than five members in a period returns the
  figures only to `hr` and `accountant`, and `null` with a reason to a manager.
- [Employees moving between units mid-period] → counted in each unit they were in, as the rate's
  FTE weighting already does per day.

## Open Questions

- The threshold of five for small units is a setting default; the employer may raise it.

## As built (2026-09-29)

- The unit series live in `UnitTrends` and the month windows in `TrendPeriods`, both split out of
  `AnalyticsService` so it stays under phpmd's complexity and coupling ceilings. `AnalyticsService`
  keeps the administration-wide series and delegates to `UnitTrends` when an `orgUnitId` is given.
- Access moved into `AnalyticsAccess` (hr and accountant read everything; a manager reads a unit
  they lead or one under it, held to the small-unit threshold). `AnalyticsController::trends()`
  uses it; the comparison, the manager's units and one unit's figures sit on a new
  `DepartmentFiguresController` (`GET /api/analytics/units`, `/my-units`, `/unit-figures`).
- The comparison shows the units under the top of the organisation when there is a single top
  unit, and returns the wage cost of people placed in no compared unit as `notPlaced`, so the
  parts add up to the total. A person in two compared units in one month is split between them.
- The threshold is app config `department_figures_minimum_members`, default 5. A withheld figure
  is `null` with `suppressed: "unit-too-small"`; the unit's size and open vacancies stay visible.
- `MijnAfdeling` (`/my-department`) lists the units the caller leads; a row opens
  `MijnAfdelingUnit` (`/my-department/:id`), which is how a manager of several units picks one.
- No seed change: the seeded units hold one or two people, so a live look shows the withheld state
  unless the threshold is lowered.
