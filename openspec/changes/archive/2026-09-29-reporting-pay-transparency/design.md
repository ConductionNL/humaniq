# Design: the gender pay gap report

## Context

Read at `development` af702f78, plus the two changes it depends on.

- `Employee` (`lib/Settings/register.d/hr-objects.json`) has no gender field; `grossMonthlySalary`
  is the monthly salary. `EmploymentContract` carries `hoursPerWeek`, `normfunctieId`,
  `hourlyWage`. `Payslip` carries `grossPay`, `hoursWorked`, `period`, and the one-off and
  variable parts through the engine's snapshot.
- `Normfunctie` (`hr-hr21.json`) has `functiegroep` and `caoSchaal`; after
  `people-job-framework-maintenance` it is maintained by the employer.
- `SalaryBand` (`hr-comp.json`) checks one salary against a band; nothing groups employees.
- Field authorization comes from `compliance-roles-and-field-access`.
- `lib/Service/Percentile.php` exists for percentile arithmetic (used by the dashboard).

## Goals / Non-Goals

**Goals**

- The directive's indicators, computed from what payroll actually paid.
- No figure that lets someone recognise an individual.

**Non-Goals**

- Deciding the categories. The employer assigns `payCategory`.

## Decisions

### D1. Hourly pay from payslips

Per employee and year: gross pay over the year divided by hours paid (`hoursWorked`, or the
contract's hours per week times weeks when `hoursWorked` is empty). Variable and complementary pay
from the payslip components that are not base salary. Alternative considered: annual salary from
`grossMonthlySalary`. Rejected: the directive speaks of pay actually received, including
complementary components.

### D2. Indicators

Mean gap = (mean men minus mean women) / mean men; median likewise, via `Percentile`. Per category
the same, plus the share of each gender receiving variable pay and the gender split per pay
quartile of the whole administration. `gender` values outside man and woman are counted in totals
but not in the gap, as the directive defines the gap between women and men.

### D3. Suppression

A category with fewer than five women or five men reports `tooSmall: true` and no figures; the
threshold is a setting.

### D4. Access

The endpoint answers the `hr` and `accountant` roles only (the `authorizeCaller()` precedent of
the analytics endpoint).

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| gender and category fields | declarative schema properties | data |
| gap indicators | imperative `PayTransparencyService` | cross-schema aggregation with medians and suppression |
| report page | declarative report page bound to the endpoint | existing widgets |

## Seed data

- `payCategory` on the seeded normfuncties ("Uitvoerend", "Adviserend", "Leidinggevend").
- Gender on the seed employees, with one category below the suppression threshold.

## Risks / Trade-offs

- [Gender is personal data] → collected only for this purpose, field-restricted, and named in the
  field's description; an empty value is allowed and counted as unknown.
- [Few people per category in small employers] → suppression rather than a misleading figure.

## Open Questions

- The Dutch act may fix reporting thresholds by employer size; the report works for any size and
  the obligation dates are the employer's to follow.

## Changes during the build (2026-09-29)

- **Variable pay** is what a payslip pays above the employee's `grossMonthlySalary`. The payslip
  carries no component split (the engine snapshot is not a stable contract), so D1's "components
  that are not base salary" is read as pay above the monthly salary.
- **Gender** is `woman`, `man`, `other` or empty (nullable enum, default null). Readable by HR,
  payroll and the employee, updated by HR, like `bsn`.
- **Rows** are read with RBAC off (`HoursRegisterGateway`), filtered to the caller's active
  administration. An accountant cannot read `gender` field by field, but the report only shows
  group figures at or above the threshold.
- **Suppression** applies to the whole administration too: below the threshold the overall
  figures and the quartiles are empty. The threshold is app config
  `pay_transparency_minimum_group` (default 5).
- **Page**: `PayTransparencyReport` (type dashboard) under a new Reports category "Pay", with a
  year page filter (`last` means the previous calendar year). The CSV is a header action that
  always exports the previous calendar year: CnDashboardPage passes header actions no workspace
  context, so the action cannot read the filter. Another year is `POST
  /api/reports/pay-transparency/export {year}`.
