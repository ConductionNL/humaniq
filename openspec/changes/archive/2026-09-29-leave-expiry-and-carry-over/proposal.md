---
kind: code
depends_on: [platform-notifications]
---

# Leave that lapses on time and carries over by the rules

## Why

Every humaniq leave balance stores the day its statutory hours lapse: `expiryDate`, 1 July of
the following year, checked by `nl-verlof-vervaltermijn`. Nothing reads it. On 2 July an
employee's 60 unused statutory hours from last year are still on the balance, and nobody
told them in May that they would lose them. That warning matters twice: the employee should
get the chance to take the leave, and under European case law the hours only lapse if the
employer actually encouraged and informed them.

The year boundary is broken the other way too. `LeaveAccrualJob` grants each year a fresh
balance, and `LeaveBalanceProjectionService` counts a leave request only against the balance
of its own calendar year. Last year's remainder stays on last year's record. An employee who
takes a week off in February draws it from this year's new entitlement, while last year's
hours, which lapse first, sit untouched until they are lost. HR corrects this by hand, if at
all.

This change makes leave draw from the hours that lapse first, carries unused hours into the
next year by the rules, lapses statutory hours on their date, and warns the employee and HR
before they do.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `lve-expiry` | Have statutory leave expire after its legal term and warn before it does. | `no`, built.state `built`: `expiryDate` is stored and audited, never acted on; no warning |
| `lve-year-end` | Carry leave over to the next year according to the rules. | `no`: each year's balance stands alone; requests count only against their own year |

### Competitors rated yes

- `lve-expiry`, AFAS Profit: "the last booking date is the expiry date (statutory leave 2026
  expires after 30 June 2027)" and it "signals remaining statutory leave that is about to
  expire" (https://help.afas.nl/help/NL/SE/142593.htm,
  https://help.afas.nl/content/NL/SE/98228.htm).
- `lve-expiry`, Visma Raet Youforce: "shows which leave hours expire first"
  (https://youforce.nl/product/verlof).
- `lve-expiry`, HR2day: "expiry terms for leave, a signal on leave about to expire to prompt
  action, and a setting to block expired leave automatically"
  (https://data.maglr.com/1697/issues/64244/760000/index.html).
- `lve-year-end`, AFAS Profit: "link remaining balances of earlier years to the composite
  leave type, which are used until their expiry date"
  (https://help.afas.nl/help/NL/SE/Hrm_Config_Leave_AddYr.htm).
- `lve-year-end`, HR2day: "HR parameter to carry the balance over from the previous year"
  (https://data.maglr.com/1697/issues/32678/421472/index.html).
- `lve-year-end`, Loket.nl: "leave types carry balanceExceedsYear and the yearly leave
  overview shows balancePreviousYear" (https://developer.loket.nl/ApiDocs#tag/Leave).
- `lve-year-end`, Personio: "the carryover section lets unused entitlement carry to following
  years with a limit on amount and a usage limit in months"
  (https://support.personio.de/hc/en-us/articles/34330701068701-Create-time-off-policies).

### Recorded follow-ups this change picks up

- `2026-07-12-leave-verzuim-mvp` proposal, Non-goals: "No automatic monthly accrual job,
  accrual needs payroll periods; follow-up spec." Accrual shipped in `leave-accrual-job`;
  expiry and carry-over are the remaining half.
- `2026-07-14-leave-accrual-job` Non-goals list pro rata statutory and CAO-derived
  bovenwettelijk as fast-follows. This change does neither; it acts on balances as they are
  granted.
- `2026-07-12-mijn-hr-self-service` and `2026-07-13-performance-reviews-mvp` defer
  notifications app-wide to the dialect adoption; that adoption is `platform-notifications`,
  which this change depends on.

## What Changes

- **Leave draws from the hours that lapse first.** A request's hours are allocated, in date
  order, to the employee's balances of that leave type: first the bucket that lapses soonest
  and is still valid on the leave day. Last year's statutory hours go before this year's; this
  year's statutory hours go before any bovenwettelijk hours.
- **Two buckets per balance.** `LeaveBalance` keeps statutory and bovenwettelijk use apart
  (`usedStatutoryHours`, `usedBovenwettelijkHours`; `usedHours` stays their sum) and gains
  `bovenwettelijkExpiryDate`, five years after the year by default.
- **Carry-over by the leave type's rule.** `LeaveType` gains how bovenwettelijk hours carry
  over: all of them, up to a cap, or none, and after how many years they lapse. Statutory
  hours always carry until 1 July.
- **Lapse on the date.** When a bucket's expiry passes, the hours left in it are written to
  `expiredHours`, and `remainingHours` subtracts them. HR can waive a lapse for an employee
  who could not take the leave, with a reason.
- **A warning before it happens.** The employee and HR are notified when statutory hours
  will lapse within 60 days, through the canonical notification dialect. `LeaveBalances`
  gains a view of what lapses in the next 90 days.

## Capabilities

### New Capabilities

- `leave-expiry-and-carry-over`: allocation to the hours that lapse first, carry-over by the
  leave type's rule, lapse on the expiry date and a warning before it.

## Impact

- `lib/Settings/register.d/hr-leave.json`: `LeaveBalance` 0.4.0 gains
  `usedStatutoryHours`, `usedBovenwettelijkHours`, `bovenwettelijkExpiryDate`,
  `expiredHours`, `expiryWaived`, `expiryWaivedReason`, the calculated
  `remainingStatutoryHours` and `statutoryExpiresSoon`, a new `remainingHours` expression,
  and the warning rule.
- `lib/Settings/register.d/hr-leave-types.json`: `LeaveType` gains `carryOverRule`,
  `carryOverCapHours`, `bovenwettelijkExpiryYears`.
- `lib/Service/LeaveAllocationCalculator.php` (new, pure);
  `lib/Service/LeaveBalanceProjectionService.php` projects every balance of the employee and
  type instead of one year's.
- `lib/Service/LeaveExpiryService.php` (new), run by `lib/BackgroundJob/LeaveAccrualJob.php`.
- `src/manifest.d/hr-leave.json`: the lapse fields on the balance and a "lapses soon" view.

## Out of scope

- Paying out lapsed or remaining hours. The offboarding payout stays as it is.
- Pro rata statutory entitlement and CAO-derived bovenwettelijk accrual (the
  `leave-accrual-job` fast-follows).
- Which hours a request costs. That is `leave-hours-from-the-working-pattern`.
