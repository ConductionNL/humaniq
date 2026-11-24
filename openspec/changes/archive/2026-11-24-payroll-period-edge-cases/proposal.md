---
kind: code
---

# Four-weekly and weekly pay with a 53rd week, and a mid-year start with carried-over totals

## Why

Two payroll situations come back every few years, and humaniq handles neither. Both rows were
rated `unknown` in the matrix because nobody had traced the engine; this change traced it.

**The 53rd week.** An employer that pays every four weeks, or every week, meets a year with 53
ISO weeks (2026 is one). The extra week has to be paid and taxed as its own week. humaniq cannot
reach the problem yet: it pays per month only. The run refuses any period that is not `YYYY-MM`
(`PayrollRunService.php:255`, and the same pattern in the flow's calculate node, the pro-forma
and the retro service), the pack's period reference parses only a month, and the pack divides by
the monthly tijdvak factor in three places. The tables already carry the four-weekly factor (13)
and the weekly factor (52); nothing uses them. So a four-weekly employer cannot run payroll in
humaniq at all, let alone week 53.

**The mid-year start.** An employer that moves to humaniq on 1 July can run July: the engine
computes each month on its own. But the year's totals start at zero. The annual statement adds
up only humaniq's payslips, so it shows six months of wage and tax; the WKR assessment sums only
humaniq's gross, so the free margin is half what it should be. There is no way to carry over
the year-to-date figures from the previous package.

This change adds four-weekly and weekly pay periods with the 53rd week as its own tijdvak, and
opening balances that carry a previous package's year-to-date figures into the annual
statement and the WKR assessment.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-week-53` | Handle the 53rd week in a year when paying weekly or four-weekly. | `unknown`, built.state `none`. Traced for this change: not handled, because humaniq pays monthly only (the run accepts `YYYY-MM` periods only and the pack uses the monthly tijdvak factor) |
| `dm-midyear-payroll-start` | Start running payroll in the middle of a year when switching from another payroll package, carrying over the year to date figures. | `unknown`, built.state `none`. Traced for this change: a mid-year run computes, but the annual statement and the WKR assessment sum only humaniq payslips and nothing carries figures over |

### Demand

- `dm-week-53`, changelog: https://loket.nl/roadmap/ (Loket.nl, "53e week", launched Q1 2026).
- `dm-midyear-payroll-start`, changelog: https://klant.afas.nl/update/profit-7/hrm (AFAS
  Profit 7, go live on the date you switch packages).

### Competitors rated yes

- `dm-week-53`, AFAS Profit: "Voor de verwerking van week 53 is periode 13 automatisch
  verlengd", for weekly and four-weekly payers, with the tax handling explained
  (https://help.afas.nl/help/NL/SE/Hrm_WgImpl_Week53.htm).
- `dm-week-53`, Loket.nl: "the setup for the 53rd week is rebuilt so it is fully available in
  Loket" (https://loket.nl/roadmap/).
- `dm-midyear-payroll-start`, AFAS Profit: "Profit 7 lets you go live with Payroll on the date
  you switch packages instead of only on 1 January"
  (https://klant.afas.nl/update/profit-7/hrm).
- `dm-midyear-payroll-start`, Personio: "the YTD import of cumulative amounts from the previous
  provider when starting Personio Payroll"
  (https://support.personio.de/hc/en-us/articles/34084487430685-Year-to-date-implementation-of-Personio-Payroll).

### Recorded non-goals this change respects

- jurisdiction-packs, binding: "VCR (voortschrijdend cumulatief rekenen): the DSL is per-period
  pure and cannot express cross-period state." Respected: opening balances feed annual totals,
  not the per-period tax calculation, and the tijdvak reaches the pack as a period property.
- payslip-pdf-docudesk: "Year-to-date cumulatieven on the loonstrook: follow-up once
  payroll-core owns cumulatives." Unchanged: the payslip shows no running totals. The annual
  statement includes the carried-over figures.

## What Changes

- **A pay frequency per administration.** `hrAdministration` gains `payFrequency` (`month`,
  `four-weeks`, `week`), changeable only from 1 January.
- **Period ids for each frequency.** A run's period is `YYYY-MM`, `YYYY-Pnn` (four-weekly,
  on ISO weeks) or `YYYY-Wnn` (weekly). The run, the flow's calculate node, the pro-forma and
  the retro service accept the id that matches the administration's frequency.
- **The tijdvak reaches the pack.** The pack's period reference learns the tijdvak and the
  period's last day for every id. The three divisions by the monthly factor, the monthly
  maximum premium wage and the monthly 30%-ruling cap take the period's own value. The tables
  gain the weekly maximum premium wage. The run's own monthly conversions (hours, bijtelling,
  and the monthly salary for a non-monthly payer) use the period's share of a year.
- **Week 53 as its own tijdvak.** In a year with ISO week 53, four-weekly payers pay week 53
  either extending period 13 on the same payslip or as a separate period 14, per an
  administration setting; weekly payers get period W53. Either way the week-53 wage is taxed
  and insured as a one-week tijdvak.
- **Opening balances.** A new `PayrollOpeningBalance` per employee, year and administration
  holds the previous package's year-to-date figures up to its last period: wage, wage tax,
  Zvw, premium wage, holiday allowance reserved and paid, pension and period count. They are
  imported with the library's mass import.
- **Totals that include them.** The annual statement adds the opening balance to humaniq's
  payslips and says so; the WKR assessment adds its wage to the fiscal wage bill. A payslip in
  a period the opening balance already covers is flagged.

## Capabilities

### New Capabilities

- `payroll-period-edge-cases`: four-weekly and weekly pay periods with the 53rd week taxed as a
  one-week tijdvak, and opening balances that carry year-to-date figures into annual totals.

## Impact

- `lib/Settings/register.d/hr-administratie.json`: `payFrequency`, `week53Handling`.
- `lib/Payroll/PayPeriod.php` (new, pure): parses period ids and answers tijdvak, first and
  last day, share of a year, and whether a period holds week 53.
- `lib/Service/PayrollRunService.php`, `lib/Flow/PayrollCalculateNode.php`,
  `lib/Service/ProformaPayslipService.php`, `lib/Service/RetroAdjustmentService.php`: accept
  the new ids through `PayPeriod`.
- `lib/Payroll/Dsl/RefResolver.php`: `@period.tijdvak`, and `@period.lastDay` for every id.
- `lib/Standards/packs/nl-2026.pack.json`: tijdvak-driven factor and caps, new golden vectors;
  `lib/Standards/tables/nl-2026.json`: `maximumpremieloon.week`.
- `lib/Settings/register.d/hr-objects.json`: `PayrollRun.period` and `Payslip.period`
  patterns; new schema `PayrollOpeningBalance`.
- `lib/Service/HrDocumentService.php` (`upsertJaaropgaaf()`), `lib/Service/WkrService.php`:
  add opening balances.
- `lib/Standards/rules/payroll.json` and a check provider: the overlap and missing-balance
  rules.
- `src/manifest.d/`: an opening balances page with mass import, the frequency on the
  administration.

## Out of scope

- VCR (cumulative calculation) and year-to-date totals on the payslip.
- Changing pay frequency in the middle of a year.
- Carrying over leave balances or loan and garnishment states from the previous package.
- Quarterly or daily tijdvakken.
