---
kind: code
depends_on: [compliance-roles-and-field-access, people-job-framework-maintenance]
---

# The gender pay gap report the pay transparency law asks for

## Why

The EU Pay Transparency Directive (2023/970), which the Netherlands is bringing into force as the
Wet loontransparantie, requires employers to compare the pay of women and men doing equal work or
work of equal value, category by category, and to report the gap. humaniq holds the pay, the
hours and the job framework, but has no gender on the employee record, no notion of which
functions count as work of equal value, and no report that compares groups. HR2day and Personio
already ship such a report; AFAS and Visma Raet announce it.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-pay-transparency` | Compare the pay of employees doing equal work, by job category and gender, to prepare the pay gap report the pay transparency law requires. | `no`, none: nothing compares groups; `SalaryBand` checks one salary against a band |

### Demand

- `dm-pay-transparency`, changelog: https://www.hr2day.com/nieuws/nieuwe-release-llama/ (AFAS
  also names job categories for equal work, https://klant.afas.nl/update/profit-8/payroll).

### Competitors rated yes

- `dm-pay-transparency`, HR2day: "Loonkloof overview in HR Analytics" and "reports on hourly pay
  and by education, age group, service years and salary scale"
  (https://www.hr2day.com/nieuws/nieuws-wet-loontransparantie-2027/).
- `dm-pay-transparency`, Personio: "Gender pay gap template with automated calculations for
  gender pay data according to EU directive requirements, segmentable by job family, grade, level"
  (https://support.personio.de/hc/en-us/articles/34905268434717-Report-on-gender-pay-gap-compliance).

## What Changes

- **Gender for reporting.** `Employee` gains a gender field used only for this report, readable
  by HR and payroll and the employee, under the field authorization of
  `compliance-roles-and-field-access`.
- **Categories of equal work.** `Normfunctie` gains a `payCategory`: the employer's grouping of
  functions that count as equal work or work of equal value, set with gender-neutral criteria.
- **The report.** For an administration and a year humaniq computes, from the payslips and
  contracts, the mean and median gender pay gap in hourly pay overall and per category, the gap in
  variable and complementary pay, the share of each gender receiving it, and the share of each
  gender per pay quartile. Categories with too few people of one gender are shown as too small
  rather than as a number.
- **Where it lives.** A "Pay transparency" report on the Reports page, exportable to CSV.

## Capabilities

### New Capabilities

- `pay-transparency`: the gender pay gap per category of equal work, computed yearly from the
  payroll, with small groups suppressed.

## Impact

- `lib/Settings/register.d/hr-objects.json`: `Employee.gender`;
  `lib/Settings/register.d/hr-hr21.json`: `Normfunctie.payCategory`.
- `lib/Service/PayTransparencyService.php` (new), `lib/Controller/PayTransparencyController.php`
  (new), `appinfo/routes.php`: `GET /api/reports/pay-transparency?year`.
- `src/manifest.json`: a report card and page.

## Out of scope

- An employee's individual right to information (directive article 7). A later change can answer
  an employee's request from the same service.
- Joint pay assessments and their follow-up measures (article 10).
- Filing the report with a public authority; the export is what the employer submits.
