# Design: pay out holiday allowance, a thirteenth month and the individual choice budget

## Context

Read at `development` af702f78.

- `lib/Standards/packs/nl-2026.pack.json` (packVersion 1.1.0): `grossRef` is `@binding.tvl`
  (line 9); `inputs` (line 27) are the gross, table colour, heffingskorting flag, date of birth,
  Awf and Aof tariffs, Whk percentage, insurance flag and 30%-ruling rate; binding
  `premieloonCap` (line 275) is the monthly maximum premieloon; step `vakantiegeld` (line 310)
  reserves `vakantiegeldRate` of `belastbaarLoon` with incidence `reserve`; step `loonheffing`
  (line 416) is the only `reduces-net` step; the employer premiums (`zvw` line 454, `awf`
  line 467 onwards) are `cappedRate` steps capped at `premieloonCap`. `selfTest` (line 545)
  binds the nine golden fixtures under `tests/fixtures/payroll-2026/`.
- The tijdvak is always the month (`tijdvakFactoren.maand`), and every premium is capped per
  period, not cumulatively; the pack's `_notes.gaps` says so.
- `lib/Standards/tables/nl-2026.json`: `vakantiebijslag.minRatePercent` 8.0 (lines 127 to
  133). No table for special payments.
- The DSL vocabulary (`lib/Payroll/Dsl/Ops/`) has `bracket` (affine `(value - a) x
  percentage / 100 + c`, `BracketOp.php`), `match`, `rate`, `cappedRate`, `expr`, `taper`,
  `piecewiseAccrue`, `clamp`, `quantize` and the allow-listed `phpStep` hatch. None selects a
  rate from a band by a threshold without applying it to the threshold value.
- `lib/Service/PayrollRunService.php:682` stores `vakantiegeldReserved` and
  `vakantiegeldRate` on each payslip; `lib/Service/HrDocumentService.php:863` sums
  `totalVakantiegeldReserved` on the annual statement.
- `Offboarding` (`lib/Settings/register.d/hr-onboarding.json:170`) carries the checkboxes
  `verlofsaldoUitbetaald` (line 287) and `vakantiegeldAfgerekend` (line 293), set by hand.
- `LeaveTransaction` (`hr-leave.json:278`) settles bought or sold leave, which
  `PayrollRunService` folds into net pay after the calculation (line 536).
- `lib/Standards/cao/cao-gemeenten.json` `allowances` (line 44) is an empty placeholder leaf
  whose source note says the IKB percentage was not confirmed. `lib/Standards/cao/cao-rijk.json`
  carries a verified `allowances` leaf with `ikb` (`pct` 16.5, `minEuroPerMonth`,
  `ikbUrenPerJaar`), so a Rijk contract has a confirmed rate today.
- `lib/Service/EmploymentTermsResolver.php` resolves contract override first, CAO second, and
  null when the CAO is a placeholder.
- `hrAdministration` (`hr-administratie.json:5`) holds per-administration settings such as
  `mode` and `abpAansluitingsplichtig`.

## Goals / Non-Goals

**Goals**

- Tax a one-off payment at the special rate, inside the pack, with golden vectors.
- Pay the holiday allowance reserve and an end-of-year reserve on a schedule and on leaving.
- Accrue and spend the IKB, with each goal's tax treatment.

**Non-Goals**

- Cumulative (VCR) calculation.
- Moving other one-off payments onto the special rate.

## Decisions

### D1. The special rate is a pack path with its own table

The tables gain `bijzondereBeloningen`: per table colour and per AOW status, a list of bands
`{tot, percentage}` over the annual wage, for the columns with and without heffingskorting,
each a sourced leaf. The pack gains inputs `specialPayment` (cents, default 0) and
`annualWageReference` (cents), a binding `bijzonderPercentage` that selects the band's
percentage, and a step `loonheffingBijzonder = specialPayment x bijzonderPercentage / 100`
with incidence `reduces-net`. `grossRef` becomes `tvl + specialPayment`. The regular chain
keeps reading `belastbaarLoon` of the regular wage only. The employer premiums and the Zvw
take the regular wage plus the special payment as their base, under the same period cap.

With `specialPayment` 0 every figure is what the pack computes today, which the nine golden
vectors prove. New inline vectors cover a May holiday allowance for a wit-table employee below
and above the AOW age.

Alternative considered: add the special payment to the month's gross and use the regular
table, as the pro-forma estimate does. Rejected: the pro-forma itself labels that "NOT the
statutory bijzonder tarief"; it withholds the wrong amount.

### D2. A new declarative op, `band`

`band(value, table)` returns the `percentage` of the first row whose `tot` reaches or exceeds
`value`, a null `tot` matching the top band. It is added to `OpRegistry` and `Vocabulary`, and
`PackValidator` gate 2 learns it; an uploaded pack that uses it is validated like any other op.

Alternative considered: reuse `bracket` with `percentage` 0 and the band's rate in `c`.
Rejected: it would work only by a trick, and `c` goes through the euro-to-cents conversion.
Alternative considered: the `phpStep` hatch. Rejected: the NL pack uses no hatch, and a band
lookup is plainly declarative.

### D3. The annual wage is resolved by the run, not by the pack

`ReservationPayoutService::annualWageReference(employee, period)` returns the sum of the
employee's payslip gross over the previous calendar year when payslips exist for all twelve
months of it; otherwise the current period's regular wage times twelve plus the holiday
allowance rate. The payslip stores the figure used. The pack receives it as an input, so its
arithmetic stays per-period pure, as the jurisdiction-packs non-goal requires.

When the table's leaves are unverified or a placeholder, the special payment is not paid that
period and the employee is listed with `special-rate-unverified`. Nothing is taxed with a
guessed percentage.

### D4. Reserves are read from payslips; payouts are records

A reserve's open balance is the sum of the reserved amounts on the employee's payslips in the
basis period minus what `ReservationPayout` records already paid for it. `ReservationPayout`:
`employeeId`, `kind` (`vakantiegeld`, `eindejaarsuitkering`, `ikb`), `basisFrom`, `basisTo`,
`amount`, `payrollRunId`, `payslipId`, `status` (`calculated`, `paid`). The run writes one when
it pays; approval of the run sets it `paid` through the run-approved listener that the hours
and expenses changes introduce, or introduces it here if neither has landed.

Alternative considered: a running balance field on the employee. Rejected: a second copy of
what the payslips already hold, which drifts on every recalculation.

### D5. The schedule is per administration

`hrAdministration` gains `holidayAllowancePayout` (`yearly` or `per-period`),
`holidayAllowanceMonth` (default 5) and `endOfYearMonth` (default 12). In the payout month the
run pays the basis period's open reserve (June of last year to May for holiday allowance, the
calendar year for the end-of-year payment); `per-period` pays each period's reserve in the same
period. All payouts go through the special-rate path.

### D6. Leaving pays everything open

When an employee's end date falls in the run's period, the run pays every open reserve of that
employee in the same payslip. The offboarding case shows the amounts computed and ticks
`vakantiegeldAfgerekend` when the run is approved.

### D7. The thirteenth month is a second reserve

`EmploymentContract.endOfYearRate` (percent), or the CAO allowances leaf key
`eindejaarsuitkering` once confirmed, resolved contract first. The pack reserves it with a
`reserve` step `eindejaarsuitkering` on input `endOfYearRate` (default 0), stored as
`Payslip.eindejaarsuitkeringReserved`. A placeholder CAO resolves to nothing, so nothing is
reserved and the contract shows no rate.

### D8. The IKB accrues per payslip and is spent by request

`IkbBudget` (`employeeId`, `year`, `accrued`, `spent`, `balance`, `administrationId`) is
updated when a run is approved: `accrued` adds each payslip's `ikbAccrued`, which is
`ikbRate` (contract first, confirmed CAO leaf second) times the regular gross.
`IkbSpendRequest` (`employeeId`, `year`, `goal`, `amount` or `hours`, `status` `draft`,
`submitted`, `approved`, `rejected`, `settled`, `settlementPeriod`) has a declared lifecycle
with `NoSelfApprovalGuard` on approve and reject, and a guard that refuses approval above the
balance. On settlement:

- `payout`: a special payment in the settlement period, taxed at the special rate;
- `leave`: the hours are added to the holiday balance's `bovenwettelijkHours`, no money moves;
- a configured goal (for example a union fee or a bicycle): paid untaxed into net with a
  `WkrDeclaration` in the category HR set on the goal.

In the December run, when the administration's `ikbPayOutRemainder` is true, the remaining
balance is paid as a special payment.

Alternative considered: spend IKB on leave through `LeaveTransaction` `buy`. Rejected: a buy
deducts net pay, and an IKB leave purchase moves no money.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| special-rate tax and end-of-year reserve | declarative pack steps and a new `band` op | the pack is the engine's configuration |
| annual wage reference | imperative, `ReservationPayoutService` | cross-period, which the DSL must not do |
| spend request lifecycle | declarative `x-openregister-lifecycle` with guards | the engine's state machine |
| due payouts, IKB accrual and settlement | imperative, inside the run and on approval | cross-schema writes |
| pages | declarative manifest | index and detail pages |

## Seed data

- `employee-jansen` gains an IKB rate on `contract-jansen-vast` and one approved payout request
  settling in 2026-06.
- `hrAdministration` `ADM-001` gets the default schedule.

## Risks / Trade-offs

- [A wrong band percentage taxes every payout wrong] -> sourced leaves, golden vectors for the
  special path, and a refusal while a leaf is unverified.
- [Annual wage for an employee who changed hours mid-year] -> the Belastingdienst's basis is
  last year's wage; the payslip records the figure so a correction can see it.
- [Premiums on a large payout are capped per period] -> the same tijdvak method humaniq uses
  for every period; recorded in the pack's `_notes.gaps`.

## Open Questions

- Should leave sold through `LeaveTransaction` move onto the special rate too? It is folded
  into net today; this change leaves it as it is and names it for a follow-up.
