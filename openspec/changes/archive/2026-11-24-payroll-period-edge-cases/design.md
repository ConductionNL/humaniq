# Design: four-weekly and weekly pay with a 53rd week, and a mid-year start

## Context

Read at `development` af702f78.

Traced for this change, because both rows were `unknown`:

- **Period grain.** `lib/Service/PayrollRunService.php:255` refuses any period that does not
  match `^\d{4}-(0[1-9]|1[0-2])$`; the same pattern guards `lib/Flow/PayrollCalculateNode.php:142`,
  `lib/Service/ProformaPayslipService.php:298` and `lib/Service/RetroAdjustmentService.php:118`.
  `PayrollRun.period` is described as "Wage period in YYYY-MM" (`hr-objects.json:248`);
  `Payslip.period` mentions `YYYY-Pnn` for four-weekly (line 107), which no code produces.
- **Pack.** `lib/Payroll/Dsl/RefResolver.php` (lines 196 to 223) resolves `@period.year` and
  `@period.lastDay` by parsing `period . '-01'`, a month only. `nl-2026.pack.json` divides by
  `@table.loonheffing.tijdvakFactoren.maand` (lines 188, 420, 431), caps premiums at
  `maximumpremieloon.maand` (line 280) and the 30%-ruling at `aftoppingsgrens.maand` (line 88).
- **Tables.** `lib/Standards/tables/nl-2026.json`: `tijdvakFactoren` has `kwartaal`, `maand`,
  `vierweken` (13), `week` (52), `dag` (lines 20 to 24); `maximumpremieloon` has `jaar`,
  `maand`, `vierweken` (line 99), no `week`.
- **Monthly conversions in the run.** `payslipPayload()` sets `hoursWorked` to
  `hoursPerWeek x 52 / 12` (line 699); `bijtellingCentsFor()` divides the yearly bijtelling by
  12 (line 1185); `coversPeriod()` (line 1579) builds the period from `period . '-01'`.
- **Annual totals.** `lib/Service/HrDocumentService.php:863` `upsertJaaropgaaf()` sums only
  `Payslip` rows of the year for the employee. `lib/Service/WkrService.php` sums
  `Payslip.grossPay` per administration and year as the fiscal wage bill (line 226). Neither
  reads any carried-over figure; `grep -rniE 'openingBalance|priorYtd|beginsaldo'` in
  `lib/` finds nothing.
- `hrAdministration` (`hr-administratie.json:5`) has no pay frequency.

## Goals / Non-Goals

**Goals**

- Run four-weekly and weekly payrolls, including a year with ISO week 53.
- Tax and insure week 53 as a one-week tijdvak.
- Carry a previous package's year-to-date figures into the annual statement and the WKR
  assessment.

**Non-Goals**

- VCR and running totals on the payslip.
- A frequency change within a year.

## Decisions

### D1. One pure period class for every grain

`PayPeriod::parse(id)` accepts `YYYY-MM`, `YYYY-Pnn` (01 to 13, 14 when week 53 is a separate
period) and `YYYY-Wnn` (01 to 53), and answers `tijdvak` (`maand`, `vierweken`, `week`),
`firstDay`, `lastDay`, `shareOfYear` (1/12, 4/52, 1/52) and `week53` (whether the period holds
ISO week 53). Four-weekly period n covers ISO weeks 4n-3 to 4n. Every place that matches the
month pattern today calls `PayPeriod` and checks the id against the administration's
`payFrequency`.

Alternative considered: accept any id and let each service parse it. Rejected: four copies of
one parser is how the month-only pattern spread in the first place.

### D2. The pack reads the tijdvak from the period

`RefResolver` resolves `@period.tijdvak` and `@period.lastDay` through `PayPeriod`. The pack
gains a binding `tijdvakFactor = match(@period.tijdvak, {maand, vierweken, week})` over the
tables' factors, and `premieloonCap` and the 30%-ruling cap become the same kind of match. The
three divisions use `@binding.tijdvakFactor`. With a monthly period every figure equals
today's, which the nine golden vectors prove; new inline vectors cover a four-weekly and a
weekly period. The tables gain `maximumpremieloon.week`, sourced.

Alternative considered: pass the tijdvak as an input. Rejected: it is a property of the period
the interpreter already receives, and an input could disagree with it.

### D3. Week 53 is always taxed as one week

`hrAdministration.week53Handling` is `extend-period-13` (default) or `separate-period-14`. For
weekly payers W53 is an ordinary weekly period. For four-weekly payers:

- `separate-period-14`: the run for `YYYY-P14` covers ISO week 53 alone, with tijdvak `week`;
- `extend-period-13`: the run for `YYYY-P13` computes two calculations per employee, weeks
  49 to 52 with tijdvak `vierweken` and week 53 with tijdvak `week`, and writes one payslip
  with both, their sums and a `week53` block.

The wage for week 53 is the period wage times one quarter for a four-weekly salary, or the
week's hours for hourly pay.

Alternative considered: treat an extended period 13 as a five-week tijdvak. Rejected: there
is no five-week tijdvak in the tables, and dividing by the four-weekly factor would tax five
weeks' wage as four.

### D4. The run's own monthly conversions use the period share

`hoursWorked` becomes `hoursPerWeek x 52 x shareOfYear`; the bijtelling becomes the yearly
amount times `shareOfYear`; `coversPeriod()` uses `PayPeriod`'s first and last day. For a
non-monthly administration the salary input is `grossMonthlySalary x 12 x shareOfYear` unless
the employee is hourly paid. The garnishment's protected amount, a monthly figure by law, is
scaled the same way, and the payslip records the factor used.

### D5. Opening balances are imported objects

`PayrollOpeningBalance`: `employeeId`, `administrationId`, `year`, `lastPeriodInPrevious`
(the previous package's last period), `grossWage`, `loonheffing`, `zvwWithheld`,
`zvwBijdrageloon`, `premieloon`, `vakantiegeldReserved`, `vakantiegeldPaid`,
`pensionContribution`, `payPeriodCount`, `source` (the package's name), `importedAt`. They are
created with the library's mass import on an `OpeningBalances` index page, like any other
object, so no endpoint is added (ADR-022). One balance per employee, administration and year;
a second is refused by a unique rule.

### D6. Annual totals add the opening balance and say so

`upsertJaaropgaaf()` adds the employee's opening balance for the year to the payslip totals,
counts its periods, and stamps `Jaaropgaaf.includesOpeningBalance` and its `source`.
`WkrService` adds the opening `grossWage` to the fiscal wage bill of the administration and
year. Two rules join the corpus: `nl-openingsbalans-overlap` flags a humaniq payslip in a
period on or before `lastPeriodInPrevious`, and `nl-openingsbalans-ontbreekt` flags an employee
whose first humaniq payslip of a year is not the year's first period and who has no opening
balance for that year.

Alternative considered: seed fake historical payslips for the months before the switch.
Rejected: they would look like humaniq calculated them, feed the anomaly baseline and the
payslip PDF, and carry engine fields humaniq never computed.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| tijdvak factor and caps | declarative pack bindings | the pack is the engine's configuration |
| period parsing | imperative, pure `PayPeriod` | one parser for every caller |
| week-53 double calculation | imperative, `PayrollRunService` | orchestration of two engine calls |
| opening balance entry | declarative object pages with the library's mass import | plain objects |
| totals including balances | imperative, the existing aggregation services | cross-schema sums |
| overlap and missing-balance | corpus rules with a check provider | machine-checkable |

## Seed data

- A new administration `ADM-005` with `payFrequency: four-weeks` and one employee, so a
  `2026-P13` run on a dev instance shows week 53 (2026 has an ISO week 53).
- One `PayrollOpeningBalance` for `employee-degroot`, year 2026, last period `2026-06` in the
  previous package.

## Risks / Trade-offs

- [Many services assume a month] -> `PayPeriod` is the one place the grain lives, and each
  caller is switched with a unit test on a monthly period that proves nothing changed.
- [The extended-period-13 layout is a convention] -> the payslip shows both calculations, so
  a reviewer sees what was taxed as which tijdvak.

## Open Questions

- Before implementation, confirm against the Belastingdienst's Handboek Loonheffingen that the
  week-53 wage paid with an extended period 13 is taxed with the week table. This design
  assumes it is.
