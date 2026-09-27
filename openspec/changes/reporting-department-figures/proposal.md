---
kind: code
depends_on: [people-formation-positions]
---

# Absence, wage costs and occupancy per department, and a manager's own dashboard

## Why

humaniq's steering dashboard shows one absence rate and one wage cost per period for the whole
administration. An HR adviser cannot see which department drives the absence, nor how often
people report sick as opposed to how long they stay away, and a controller cannot split the wage
cost per department. A manager sees none of it: the figures are for HR and accountant roles only,
and there is no view of the manager's own department. A municipal tender asks for a manager
dashboard of absence, occupancy, vacancies and wage costs for the manager's own department.

The absence frequency was once a named non-goal, but `AbsenceRateService` itself records that the
reason (no partial-absence data) no longer holds.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `abs-rate` | See the absence rate and frequency per department over time. | `partial`: the overall rate over time; no department and no frequency |
| `rep-wage-costs` | See total wage costs per period and department. | `partial`: total per period; no department |
| `td-manager-dashboard` | Show a manager a dashboard of their own department's absence, occupancy, vacancies and wage costs. | `partial`: org-wide tiles for HR; nothing scoped to a manager |

### Demand

- `td-manager-dashboard`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E10.9 and E13.3, managersdashboard, rolgebaseerde dashboards).

### Competitors rated yes

- `abs-rate`, AFAS Profit: "absence per employee, organisation unit, function and cost centre,
  with configurable absence percentage methods" (https://help.afas.nl/help/NL/SE/138725.htm).
- `abs-rate`, Visma Raet Youforce: "direct access to verzuimcijfers, predict absence and compare
  departments" (https://youforce.nl/product/rapportages-analyse).
- `abs-rate`, HR2day: "filter and compare per team, department and period"
  (https://www.hr2day.com/features/hr-analytics/dashboards/).
- `abs-rate`, Personio: "Time Off Rate shows how frequently employees are absent over time, with
  Time Off Rate by Department and by Type"
  (https://support.personio.de/hc/en-us/articles/19296003063709-Access-key-insights-in-the-Metrics-tab).
- `rep-wage-costs`, AFAS Profit: "the standard dashboard Loonkosten" and costs spread "per
  department and cost centre" (https://www.afas.nl/software/salarisadministratie).
- `rep-wage-costs`, Visma Raet Youforce: "insight in loonkosten and compare departments"
  (https://youforce.nl/product/rapportages-analyse).
- `rep-wage-costs`, HR2day: "standard dashboards for wage costs, compared across teams,
  departments and periods" (https://www.hr2day.com/features/hr-analytics/dashboards/).
- `rep-wage-costs`, Loket.nl: "real-time insight in wage costs, FTE and turnover"
  (https://loket.nl/functionaliteiten/dashboarding/).
- `rep-wage-costs`, Personio: "compensation costs per employee ... and compensation costs by
  legal entity" (https://support.personio.de/hc/en-us/articles/19300731976093-Summary-of-templates-available-for-reporting).
- `td-manager-dashboard`, AFAS Profit: "filter authorisation applies row level security in the
  Power BI dashboards ... so a manager sees their own unit"
  (https://help.afas.nl/help/NL/SE/137717.htm).
- `td-manager-dashboard`, HR2day: "dashboards for leave, absence, wage costs and formation where
  managers see their own team data" (https://www.hr2day.com/features/hr-analytics/dashboards/).

## What Changes

- **A department dimension.** The trends endpoint takes an org unit; the absence rate and the
  wage cost are computed for the people placed in that unit (and its children) in each period.
- **Absence frequency.** A new metric counts sick reports per employee per year (the
  meldingsfrequentie CBS uses) per period and unit, beside the rate.
- **Department comparison.** The absence report and the dashboard gain a chart comparing units
  side by side for a chosen period.
- **A manager's dashboard.** A new `MijnAfdeling` page shows the manager, for the units they
  manage, the absence rate and frequency, the net FTE against formation, the open vacancies and
  the wage cost, all as totals for the unit, never per person.

## Capabilities

### New Capabilities

- `department-figures`: absence rate, absence frequency and wage cost per org unit, and a
  manager dashboard scoped to the units the manager leads.

## Impact

- `lib/Service/AnalyticsService.php`, `lib/Service/AbsenceRateService.php`: an `orgUnitId`
  scope and a `absence-frequency` metric; unit membership through
  `lib/Service/OrgResolutionService.php`.
- `lib/Controller/AnalyticsController.php`: the `orgUnitId` parameter and a manager branch in
  `authorizeCaller()` for units the caller manages.
- `src/manifest.json` (Dashboard, AbsenceReport) and `src/manifest.d/personal-dashboard.json`
  or a new fragment: the comparison chart and the `MijnAfdeling` page.

## Out of scope

- Per-person figures on a manager's dashboard. Only unit totals.
- Bradford factor and duration trends (`verzuim-analytics-widgets` non-goals).
