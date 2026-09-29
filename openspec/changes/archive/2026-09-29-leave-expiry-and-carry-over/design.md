# Design: leave that lapses on time and carries over by the rules

## Context

Read at `development` af702f78.

- `LeaveBalance` (`lib/Settings/register.d/hr-leave.json`, 0.3.1): `employeeId`, `year`,
  `leaveType`, `entitledHours` (statutory), `bovenwettelijkHours`, `usedHours`,
  `contractHoursPerWeek`, `expiryDate` (1 July of year + 1), `lastAccruedPeriod`,
  `administrationId`, `userId`. `remainingHours` is an `x-openregister-calculations` field,
  `entitledHours + bovenwettelijkHours - usedHours`, not materialised.
- `lib/Service/LeaveBalanceProjectionService.php:96` `projectForRequest()` recomputes
  `usedHours` for each calendar year the request's range touches, and only against the
  balance of that same year (`matchBalance()`, line 281). A request never draws on an earlier
  year's balance.
- `lib/Service/LeaveHoursCalculator.php:156` `requestHours()` gives a request's hours in one
  year: the explicit `hours` in its start year, otherwise working days times contract hours
  divided by five. `usedHoursFor()` (line 199) sums approved requests per balance.
- `lib/BackgroundJob/LeaveAccrualJob.php` runs daily (line 75), grants the full statutory
  entitlement on the year's first accrual, accrues bovenwettelijk monthly, and writes
  `expiryDate` (line 264). It never reads a previous year.
- `lib/Standards/Checks/NlLeaveChecks.php:135-149` `nl-verlof-vervaltermijn` checks only
  that `expiryDate` equals 1 July of year + 1. `nl-verlof-saldo-niet-negatief` checks
  `usedHours` against the entitlement.
- `LeaveType` (`hr-leave-types.json`, 0.1.0): `code`, `label`, `drawsFromBalance`,
  `requiresReason`, `requiresDocument`, `maxNoticeDays`, `active`. No carry-over rule.
- `LeaveBalances` (`src/manifest.d/hr-leave.json`, index at line 179) lists balances with
  their remaining hours.
- humaniq declares no `x-openregister-notifications` rule today; the dialect is adopted
  app-wide by `platform-notifications`.

## Goals / Non-Goals

**Goals**

- Leave taken uses the hours that would lapse first.
- A balance's unused hours stay usable after the year ends, until their own expiry.
- Statutory hours lapse on 1 July unless HR waives it, and nobody is surprised.

**Non-Goals**

- A second ledger of transactions per hour. The recompute-from-requests model stays.
- Changing how entitlement is granted.

## Decisions

### D1. Allocation is recomputed, bucket by bucket, in date order

`LeaveAllocationCalculator::allocate(array $balances, array $requests, array $leaveType):
array` is pure. Buckets are every balance's statutory part (`entitledHours`, lapsing on
`expiryDate`) and bovenwettelijk part (`bovenwettelijkHours`, lapsing on
`bovenwettelijkExpiryDate`). Approved requests are walked in start-date order, and each
request's hours per year (from `requestHours()`) go to the bucket that is valid on the leave
day (its year is not after the leave year and it has not lapsed) and lapses first. Hours
beyond every bucket land on the leave year's balance as an overdraft, which
`nl-verlof-saldo-niet-negatief` already flags.

`projectForRequest()` then recomputes every balance of that employee and leave type and
writes `usedStatutoryHours`, `usedBovenwettelijkHours` and their sum `usedHours`. Running it
twice lands on the same numbers, the property the current service already promises.

Alternative considered: moving last year's remainder into this year's balance on 1 January.
Rejected: it would lose which hours lapse when, and a request approved later for December
would have to reverse a transfer.

### D2. Carry-over follows the leave type

`LeaveType.carryOverRule` is `all` (default), `capped` or `none`; `carryOverCapHours` caps
what bovenwettelijk hours stay usable after 31 December; `bovenwettelijkExpiryYears`
(default 5, the limitation period) sets `bovenwettelijkExpiryDate` to 31 December of
`year + bovenwettelijkExpiryYears`. With `none` the bovenwettelijk bucket lapses on
31 December of its own year. Statutory hours always carry until `expiryDate`; no rule can
shorten that.

### D3. Lapse is written by the job that already walks every balance

`LeaveExpiryService::apply(string $today)` computes, for every balance, the hours left in
each bucket whose expiry is on or before today, and writes the total to `expiredHours`,
unless `expiryWaived` is true. It is a pure function of the balance, its allocation and the
date, so it is recomputed each run rather than accumulated. `LeaveAccrualJob::run()` calls it
after accrual. `remainingHours` becomes `entitledHours + bovenwettelijkHours - usedHours -
expiredHours`.

Alternative considered: a declared flow on a schedule. Rejected for now: the daily job
already loads every balance, and a shipped flow would arrive disabled, leaving lapses
unapplied on a fresh install.

### D4. The warning is a declared notification

Two calculated fields on `LeaveBalance`: `remainingStatutoryHours` (`entitledHours -
usedStatutoryHours`, floored at zero) and `statutoryExpiresSoon` (true when
`remainingStatutoryHours` is above zero and `expiryDate` is within 60 days). An
`x-openregister-notifications` rule with trigger `scheduled` (daily) and filter
`statutoryExpiresSoon: true` notifies the balance's `userId` and the HR group, with the
hours and the date in the subject. `LeaveBalances` gains a view filtered on `expiryDate`
between `@today` and `@today+90d`.

### D5. HR can waive a lapse, with a reason

`expiryWaived` and `expiryWaivedReason` (for example long-term sickness that prevented the
leave) keep the hours usable; the waiver is audited like any field write.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| remaining, remaining statutory, expires soon | declarative `x-openregister-calculations` | pure functions of the record and today |
| warning before lapse | declarative `x-openregister-notifications`, `scheduled` | the canonical dialect |
| allocation across years and buckets | imperative `LeaveAllocationCalculator` | ordered allocation over many records |
| writing the lapse | imperative, inside the existing daily `LeaveAccrualJob` | the job already walks every balance |
| lapse-soon view | declarative manifest filter | existing token grammar |

## Seed data

- One seed employee with a previous-year holiday balance holding 16 unused statutory hours
  and a current-year balance, and one approved February request of 24 hours: the previous
  year shows 16 used, the current year 8.
- One previous-year balance past 1 July with 8 hours left, showing `expiredHours` 8.
- One balance with `expiryWaived` true and a reason.

## Risks / Trade-offs

- [Existing balances change when this lands] → the first projection moves used hours from
  the current year to the previous year where it had room; the release note says so and the
  sums per employee do not change.
- [The engine's scheduled filter may not read calculated fields] → task 2.3 verifies it on a
  dev instance; if it cannot, the flag becomes a field the daily job writes.

## Open Questions

- Should the warning also be stamped on the balance (`expiryWarnedAt`) as proof that the
  employer informed the employee?

## Changes made while building (2026-09-29)

- D1: on a tie of lapse dates statutory goes first; with carry-over `none` the bovenwettelijk
  hours lapse on 31 December of their year, before the statutory hours, so they are drawn first.
  That is the "lapses first" rule; under the default rule statutory always goes first.
- D2: `capped` is two buckets: the hours above the cap lapse on 31 December, the capped part on
  `bovenwettelijkExpiryDate`.
- D3: no separate `LeaveExpiryService`. The calculator works out the lapse from the same buckets,
  `LeaveBalanceProjectionService::recomputeAll($today)` writes it, and `LeaveAccrualJob::run()`
  calls it daily whether or not accrual is on. A lapse is on the day after the expiry date.
- D4: the warning is a `calculatedChange` on the materialised `daysUntilStatutoryLapse` (lte 60,
  previously gt 60), the dialect people-dossier-completeness uses, instead of a scheduled filter.
  The "lapses soon" view is a menu entry on `LeaveBalances` filtered on the materialised
  `statutoryExpiresSoon` (statutory hours left, lapsing within 90 days), because the manifest has
  no date-window token.
- Seed: last year's balances for Jansen (8 hours lapsed) and Bakker (a waived lapse). The February
  request seed was left out: it would contradict the seeded 2026 figures until the first recompute.
