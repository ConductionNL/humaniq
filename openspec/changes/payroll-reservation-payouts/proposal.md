---
kind: code
---

# Pay out holiday allowance, a thirteenth month and the individual choice budget

## Why

Every payslip humaniq calculates reserves 8% holiday allowance (vakantiegeld). The reserve is
stored on the payslip and summed on the annual statement. Then nothing pays it. In May a payroll
officer has to work out each employee's reserve by hand and pay it outside humaniq, and a leaver
leaves with the reserve still on paper. A thirteenth month (eindejaarsuitkering) does not exist
at all.

Paying these amounts needs one thing the engine does not have: the special rate for one-off
payments (bijzonder tarief, the tabel voor bijzondere beloningen). A holiday allowance paid in
May is not taxed as May's wage; it is taxed at a percentage that follows the employee's annual
wage. The engine design named this its fast-follow, and it is why the reserve was never paid.

Municipal employees have a third reserve: the individual choice budget (IKB) of the CAO
Gemeenten. It accrues every month and the employee spends it on a payout, extra leave, a union
fee or a bicycle. humaniq has leave buying and selling, but no budget that accrues and no way to
spend it. Sudwest-Fryslan asks for it (E5.38).

This change adds the special-rate path to the engine and uses it to pay out holiday allowance,
a thirteenth month and IKB payouts, on a schedule and on leaving.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `pay-holiday-allowance` | Reserve and pay holiday allowance and a thirteenth month. | `partial`, built.state `built`: the pack reserves 8% per period and the payslip stores it; nothing pays it out and no thirteenth month exists |
| `td-ikb` | Let employees spend their individual choice budget under the municipal collective agreement. | `partial`, built.state `built`: leave buy and sell settles leave hours in pay; no budget that accrues a percentage and can be spent on several goals |

### Demand

- `td-ikb`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E5.38, Individueel Keuzebudget volgens CAO Gemeenten).

### Competitors rated yes

- `pay-holiday-allowance`, AFAS Profit: "the reservation of holiday allowance is shown on the
  payslip" and "holiday allowance and end-of-year payment (eindejaarsuitkering) on leaving"
  (https://help.afas.nl/help/NL/SE/Hrm_WgImpl_HoliDy_FAQ_Quit.htm).
- `pay-holiday-allowance`, Visma Raet Youforce: "reservation and payment of vakantietoeslag
  including a per-period payment option"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases).
- `pay-holiday-allowance`, HR2day: "reservations for holiday allowance and year-end payment
  calculated in payroll" (https://data.maglr.com/1697/issues/41191/512820/index.html).
- `pay-holiday-allowance`, Loket.nl: "central definition of calculation bases for schemes such
  as holiday pay at cao, wage model and administration level" (https://loket.nl/roadmap/).
- `td-ikb`, Visma Raet Youforce: "Individueel Keuze Budget dashboard to spend IKB on bought
  leave, study costs, union fees, bicycle, fitness or payout"
  (https://www.ssc-ons.nl/content/uploads/2024/07/Handleiding-IKB-Kampen.pdf).
- `td-ikb`, HR2day: "fully supports the IKB for municipalities via the benefits screen with
  money and time balances" (https://www.hr2day.com/gemeenten/).

### Recorded non-goals this change picks up

- payroll-core-engine (`openspec/changes/archive/2026-07-14-payroll-core-engine/proposal.md`,
  named fast-follows): "CAO rules, bijzonder tarief (vakantiegeld payout), 30%-ruling
  netto-operation, pension premie calculation, Zvw-inhouding mode ...: the calculator's
  table-driven, pure-function shape is the extension point." This change adds the bijzonder
  tarief.
- payroll-core-schema: "Bijzonder-tarief table (bijzondere beloningen): not in the tables file;
  vakantiegeld is reserved in the MVP, its May payout at bijzonder tarief is a follow-up." This
  change adds the table and the payout.
- proforma-payslip: its one-off payment is "explicitly labelled as NOT the statutory bijzonder
  tarief". Once this change lands the pro-forma can use the real path; that switch is left to a
  later change.
- jurisdiction-packs, binding: "VCR: the DSL is per-period pure and cannot express cross-period
  state." Respected: the annual wage that selects the special rate is an input the run service
  resolves, not state the pack reads across periods.
- leave-buy-sell: "Batch/period-wide settlement" and "Deriving hourlyRate from the payroll
  engine" stay its own follow-ups. IKB leave is credited as hours, not bought with money.

## What Changes

- **The special rate in the engine.** The 2026 tables gain the tabel voor bijzondere
  beloningen, sourced and marked verified or not. The pack gains two inputs, a special payment
  and the annual wage it is judged against, a new declarative `band` op that picks a percentage
  from a band table, and a step that withholds wage tax on the special payment at that
  percentage. With no special payment the pack computes exactly what it computes today; its nine
  golden vectors keep passing and new vectors cover the special path.
- **The annual wage.** The run service resolves it per employee: last calendar year's wage when
  the employee was paid all of that year, otherwise the expected wage for this year from the
  regular period wage plus the holiday allowance rate.
- **Holiday allowance paid out.** A payout schedule per administration: once a year in a chosen
  month (default May, over June to May) or every period. The payout pays the reserved amount of
  the basis period as a special payment and records it as a `ReservationPayout`, so the reserve
  still open is always reserved minus paid.
- **A thirteenth month.** A contract, or its CAO once confirmed, can carry an end-of-year rate.
  The pack reserves it per period like the holiday allowance, and the schedule pays it out
  (default December).
- **Leaving pays everything open.** The final period of a leaver pays every open reservation,
  and the offboarding case shows the amounts.
- **The IKB.** An `IkbBudget` per employee and year accrues on every payslip at the contract's
  or the confirmed CAO's rate. The employee requests a spend (`IkbSpendRequest`): a payout, extra
  leave hours, or a goal HR has configured with its tax treatment. A manager or HR approves with
  separation of duties. A payout is paid as a special payment, leave is credited as hours, an
  exempt goal is paid untaxed with a WKR row. What is left in December is paid out when the
  administration says so.

## Capabilities

### New Capabilities

- `payroll-reservation-payouts`: the special-rate path in the engine, and scheduled and
  on-leaving payouts of holiday allowance, an end-of-year payment and the individual choice
  budget.

## Impact

- `lib/Standards/tables/nl-2026.json` and `lib/Standards/tables/SCHEMA.md`: the
  `bijzondereBeloningen` group.
- `lib/Payroll/Dsl/Ops/BandOp.php` (new), `lib/Payroll/Dsl/Ops/OpRegistry.php`,
  `lib/Payroll/Dsl/Vocabulary.php`, `lib/Payroll/PackValidator.php`: the `band` op.
- `lib/Standards/packs/nl-2026.pack.json`: inputs `specialPayment`, `annualWageReference`,
  `endOfYearRate`; the special-rate binding and step; the end-of-year reserve; new golden
  vectors; `packVersion` 1.2.0.
- `lib/Payroll/CalculationInput.php`, `CalculationInputMapper.php`, `CalculationResult.php`,
  `PayrollCalculator.php`: the new inputs and outputs.
- `lib/Service/PayrollRunService.php` and `lib/Service/ReservationPayoutService.php` (new):
  annual wage, due payouts, IKB accrual and settlement.
- `lib/Settings/register.d/hr-objects.json`: `Payslip` gains `specialPayment`,
  `specialTaxRate`, `specialLoonheffing`, `eindejaarsuitkeringReserved`, `ikbAccrued`;
  `EmploymentContract` gains `endOfYearRate`, `ikbRate`; new schemas `ReservationPayout`.
- `lib/Settings/register.d/hr-administratie.json`: the payout schedule on `hrAdministration`.
- `lib/Settings/register.d/hr-leave.json`: new schemas `IkbBudget` and `IkbSpendRequest`.
- `src/manifest.d/hr-objects.json`, `hr-leave.json`, `05-menu.json`: payout and IKB pages and a
  self-service page.

## Out of scope

- VCR (cumulative calculation). The special rate follows the tijdvak method humaniq uses.
- Converting other one-off payments (bonuses, retro back pay, leave sales) to the special rate.
  They can use the path this change adds; each needs its own change.
- The CAO Gemeenten IKB percentage itself. It stays a placeholder in the corpus until someone
  transcribes it; until then the rate is set per contract.
