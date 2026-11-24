---
kind: config
---

# More ready-made reports, and reports people build and share themselves

## Why

humaniq's Reports page offers three ready-made reports (workforce, leave and absence,
performance). A payroll officer or a controller finds nothing about pay: no wage cost report,
no payroll run overview, no turnover over the year, no leave balances. And no one can make a
report of their own: the index pages cannot save a chosen set of filters and columns as a report
for colleagues, and most of them cannot export. Six of the seven compared systems ship payroll
and absence reports as standard, and five let users build and share their own and export them to
a spreadsheet.

The matrix row for ready-made reports read "no" on 2026-09-26; this pass corrected it to
partial, because the Reports page with three reports predates that read (cc2d18ab).

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `rep-standard-reports` | Pick from ready-made HR reports. | `partial` (corrected in this pass): three ready-made reports on `Reports`; none on pay, turnover or leave balances |
| `rep-report-builder` | Build your own report and export it. | `no`, none: no saved report, no export on the index pages |

### Competitors rated yes

- `rep-standard-reports`, AFAS Profit: "Profit ships standard reports" and "report sets per
  employer from the payroll cockpit" (https://help.afas.nl/help/NL/SE/142548.htm).
- `rep-standard-reports`, Visma Raet Youforce: "direct access to the most used reports"
  (https://youforce.nl/product/rapportages-analyse).
- `rep-standard-reports`, HR2day: "standard reports for leave, absence, salary and formation
  without configuration" (https://www.hr2day.com/features/hr-analytics/control-intelligence/).
- `rep-standard-reports`, Loket.nl: "ready-made reports on payroll processing, absence,
  mutations, dossiers and training" (https://loket.nl/functionaliteiten/rapportages/).
- `rep-standard-reports`, Personio: "ready-made templates for org composition, time off,
  attendances, retention, compensation" (https://support.personio.de/hc/en-us/articles/19300731976093-Summary-of-templates-available-for-reporting).
- `rep-standard-reports`, OrangeHRM: a leave entitlement and usage report and time reports
  (orangehrm@v5.9 src/plugins/orangehrmLeavePlugin/config/routes.yaml:203).
- `rep-report-builder`, AFAS Profit: "the new RapportGenerator (Profit 8) creates and saves your
  own reports" (https://help.afas.nl/help/NL/SE/136026.htm).
- `rep-report-builder`, Visma Raet Youforce: "build your own reports from one place, also for
  managers" (https://youforce.nl/product/rapportages-analyse).
- `rep-report-builder`, HR2day: "build your own reports with own KPIs and fields, export to
  Excel, PDF or BI tools" (https://www.hr2day.com/features/hr-analytics/data-rapportage/).
- `rep-report-builder`, Loket.nl: "build your own reports and exports on HR and salary data"
  (https://loket.nl/functionaliteiten/rapportages/).
- `rep-report-builder`, Personio: "build reports with filters, segments and aggregations, share
  them and export as Excel or CSV" (https://support.personio.de/hc/en-us/articles/15718133638685-Create-a-report).

## What Changes

- **Four more ready-made reports** on the Reports page, each a declarative dashboard over the
  register: wage costs (per period, per component, per unit through the trends endpoint),
  payroll runs (runs, totals and status per period), turnover (starters, leavers and headcount
  per month), and leave balances (entitlement, used and remaining per leave type, with the hours
  that expire this year).
- **Own reports from the index pages.** The main index pages (employees, contracts, payslips,
  leave requests, sickness cases, time entries, expenses) switch on the library's saved views and
  export: a user filters, sorts and chooses columns, saves the result as a named view, shares it
  publicly within the instance if they choose, and exports it to CSV or Excel.
- **Own reports on the Reports page.** A "My reports" category lists the user's saved views and
  the shared ones, so a built report is found where the ready-made ones are.

## Capabilities

### New Capabilities

- `hr-reports`: the ready-made HR, payroll and leave reports and the user-built saved-view
  reports with export.

## Impact

- `src/manifest.json`: four report cards and four `type: dashboard` report pages; a "My reports"
  category on `Reports`.
- `src/manifest.d/*.json`: `allowSavedViews` and `allowExport` on the seven index pages.
- `lib/Settings/register.d/*.json`: `exportable` on the seven schemas.

## Out of scope

- A free-form query designer across schemas. A report is one schema's filtered, sorted and
  column-chosen view, or one of the ready-made dashboards.
- Scheduled report delivery by email.
