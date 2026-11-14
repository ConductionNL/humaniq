## 1. Periods

- [x] 1.1 Add `PayPeriod` (D1). Verify: unit tests for `2026-05`, `2026-P13` holding ISO week
      53, `2026-P14`, `2026-W53`, and `2025-P14` refused because 2025 has no week 53.
  - Done: `lib/Payroll/PayPeriod.php`. `parse()` answers `tijdvak`, `firstDay`, `lastDay`, `shareOfYear()`, `periodsPerYear()` and `week53`, and refuses any other id by name. `P13` keeps weeks 49 to 52 as its own tijdvak and flags `week53` in a year that has one, so the run can extend it (D3). `P14` and `W53` exist only in such a year. Tests: `tests/Unit/Payroll/PayPeriodTest.php` (19).
- [x] 1.2 Add `payFrequency` and `week53Handling` to `hrAdministration` and switch the four month
      patterns to `PayPeriod`. Verify: unit tests that a monthly administration refuses
      `2026-P05` and a four-weekly one accepts it.
  - Done: `hrAdministration.payFrequency` (`monthly` default, `four-weekly`, `weekly`) and `week53Handling` (`extend-period-13` default, `separate-period-14`) in `hr-administratie.json` (schema 1.8.0). `PayPeriod::forFrequency()` checks an id against them (period 14 only with `separate-period-14`). `PayrollRunService::runFor()` refuses a period of another frequency by name; the flow node's config check parses any `PayPeriod` id (the run then applies the frequency). The pro-forma payslip and the retro correction read the id through `PayPeriod` but stay month-only until their month conversions move to `shareOfYear` (2.3). Tests: 3 in `PayPeriodTest`, `PayrollRunServiceTest::testARunTakesOnlyPeriodsOfTheAdministrationsFrequency`.

## 2. Engine

- [x] 2.1 Teach `RefResolver` `@period.tijdvak` and `@period.lastDay` for every id. Verify:
      unit tests for each grain.
  - Done: `RefResolver::period()` parses the id through `PayPeriod`: `@period.year` is the id's (ISO) year, `@period.lastDay` the period's last day, `@period.tijdvak` `maand`, `vierweken` or `week`; an unknown id or a week 53 the year lacks is refused as before. Tests: `RefResolverTest::testEachGrainResolvesItsPeriodFields` (6 grains), `testAWeek53TheYearLacksIsRejected`.
- [x] 2.2 Replace the monthly factor and caps in the pack with tijdvak bindings, add
      `maximumpremieloon.week` and new golden vectors. Verify: the nine fixtures pass unchanged
      and the four-weekly and weekly vectors pass through `PackValidator` gate 5.
  - Done: binding `tijdvakFactor` = `@table.loonheffing.tijdvakFactoren[@period.tijdvak]` feeds `annualised`, `loonheffing` and `arbeidskorting`; `premieloonCap` and the 30%-ruling cap index `maximumpremieloon` and `aftoppingsgrens` by `@period.tijdvak`. The tables gain `maximumpremieloon.week` (1527.10 = jaar / 52) and `aftoppingsgrens.vierweken`/`.week` (jaar x 4 / 52, jaar / 52). The ten monthly fixtures pass unchanged; four inline vectors (`2026-P05` and `2026-W20`, below and above the cap) pass gate 5, and the uncapped ones reproduce the anchor's loonheffing x 12 / 13 and x 12 / 52. Tests: `NlPackTest`.
- [x] 2.3 Switch the run's monthly conversions to `shareOfYear` (D4). Verify: unit test that a
      monthly payslip is byte-identical to before.
  - Done: `PayrollRunService` scales by `PayPeriod::periodsPerYear()` (12/13/52, the same as `shareOfYear`): `hoursWorked` = hours a week x 52 / periods, the bijtelling = yearly / periods, the salaried gross and the garnishment's `beslagvrijeVoet` = monthly x 12 / periods (unchanged for a month); the holiday calendar range and the sick-pay year-one test use the period's first and last day; `ChecksPeriodCoverage` covers a four-week period or a week by its days (other inputs read as before). A non-monthly payslip records `tijdvakFactor` (new `Payslip` property, schema 0.19.0; absent on a monthly one). Tests: `PayrollRunServiceTest::testAMonthlyPayslipIsUnchangedByThePeriodShare` (golden written by the code before the change: car, garnishment, hours), `testAFourWeeklyPayslipScalesTheMonthlyFigures`. Left: an hourly employee's approved hours are still selected per month (`HoursPayService` reads month-keyed timesheets).
- [x] 2.4 Add week-53 handling for both settings (D3). Verify: unit tests that an extended
      `2026-P13` payslip carries a four-week and a one-week calculation, and a `2026-P14` run
      covers week 53 only.

  - Done: `separate-period-14`: `2026-P14` parses as week 53 alone with tijdvak `week`, so the run pays a week's salary and the weekly loonheffing (2.2/2.3). `extend-period-13`: `PayrollRunService::withWeek53()` calculates week 53 as `YYYY-W53` on a quarter of the four weeks' salary and car benefit, `CalculationResult::combined()` sums both calculations into the payslip's amounts, and the payslip's new `week53` block (schema 0.19.0) shows each part with its weeks and tijdvak. Left: an hourly employee's week 53 is not calculated (approved hours are selected per month). Tests: `PayrollRunServiceTest::testASeparatePeriod14IsWeek53TaxedAsOneWeek`, `testAnExtendedPeriod13AddsWeek53AsAOneWeekCalculation`, `CalculationResultCombinedTest` (2).

## 3. Opening balances

- [x] 3.1 Add `PayrollOpeningBalance` with its uniqueness rule and the `OpeningBalances` page
      with mass import. Verify: `occ app:update` imports it and `npm run check:manifest` exits
      0.
  - Done: schema `PayrollOpeningBalance` (D5 fields) in `register.d/hr-opening-balance.json`, register 0.64.0. `PayrollOpeningBalanceUniqueGuard` refuses a second balance per employee, administration and year on create and update, and fails closed. Pages `OpeningBalances` (index, mass import on) and `OpeningBalanceDetail` in `manifest.d/hr-opening-balance.json`, menu entry under Payroll; en/nl strings. `npm run check:manifest` passes. Tests: `PayrollOpeningBalanceUniqueGuardTest` (5). Not run here: `occ app:update` (needs a live instance).
- [ ] 3.2 Add opening balances to `upsertJaaropgaaf()` and `WkrService`. Verify: unit tests
      that six months of opening balance plus six humaniq payslips give a twelve-period
      statement.
- [ ] 3.3 Add `nl-openingsbalans-overlap` and `nl-openingsbalans-ontbreekt`. Verify:
      `occ humaniq:rules:audit` reports a seeded overlap.

## 4. Seed

- [ ] 4.1 Seed `ADM-005` and the opening balance. Verify: `npm run check:seed-refs` exits 0.

## 5. Verification

- [ ] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: calculate `2026-P13` for `ADM-005` and generate the
      2026 statement for the employee with an opening balance; record screenshots in the PR.
