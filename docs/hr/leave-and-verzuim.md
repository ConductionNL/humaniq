---
sidebar_position: 3
description: Leave requests and balances, automatic accrual, buy/sell of bovenwettelijk hours, sickness (verzuim) case management, and the Wet verbetering Poortwachter milestone clock.
---

# Leave & verzuim

The `Verlof & verzuim` menu group brings together leave requests, leave
balances, and Wet verbetering Poortwachter (WVP) sickness case management
— everything a Dutch employer needs to track absence, from a single day
off to a long-term sickness case.

## Leave requests and balances

`LeaveRequest` follows a submit → approve/reject lifecycle guarded by the
same `NoSelfApprovalGuard` used on Timesheets. `LeaveApproval` is the
pending-approval queue (`status == submitted`), and `LeaveRequestDetail`
exposes exactly the transitions declared on the schema — `submit` (from
`draft` or `rejected`), `approve`, `reject` — no invented edges.

`LeaveBalance` tracks per-employee, per-year, per-leave-type entitlements
with a **declaratively calculated** remainder — `remainingHours` is
computed via OpenRegister's `x-openregister-calculations` vocabulary
(`entitledHours + bovenwettelijkHours − usedHours`), not stored and
hand-updated:

- `entitledHours` — the statutory minimum is 4× contractual weekly hours
  (BW art. 7:634)
- `bovenwettelijkHours` — above-statutory leave, if any
- `expiryDate` — statutory hours lapse 1 July of the following year (BW
  art. 7:640a)

Three machine-checkable rules audit every `LeaveBalance` (domain
`labour`, framework `bw7-10`, source BW art. 7:634/7:638/7:640a):

1. **`nl-verlof-wettelijk-minimum`** — `entitledHours` must be at least
   4× the snapshotted `contractHoursPerWeek`.
2. **`nl-verlof-saldo-niet-negatief`** — `usedHours` must not exceed
   `entitledHours + bovenwettelijkHours`.
3. **`nl-verlof-vervaltermijn`** — statutory hours must carry the correct
   1 July `expiryDate`.

```bash
occ humaniq:rules:audit
```

## Automatic accrual

A monthly background job (`LeaveAccrualJob`) provisions and grows every
active employee's holiday `LeaveBalance` — balances are no longer
hand-typed test data.

- **Statutory entitlement is granted in full up front**, not built up in
  monthly slices. On the first accrual of a calendar year, an employee
  is provisioned at `4 × hoursPerWeek` immediately — because building it
  up gradually would violate the mandatory minimum from January through
  November, the job grants the whole statutory year at once and only
  ever grows or holds it, never lowers an already-granted year's
  entitlement.
- **Bovenwettelijk hours accrue 1/12 per month worked**, driven by a
  configurable annual figure (default 0 — statutory-only until an
  employer configures it).
- **Idempotent per `(employee, year, month)`** via a stored
  `lastAccruedPeriod` marker — the job can fire any number of times a
  month and only ever accrues once.
- **Operator-toggleable** (`leave_accrual_enabled`, default on) and
  skips — with a counted, logged reason — any employee it cannot
  resolve honestly (no covering contract, no `hoursPerWeek`), never
  computing wrong and never silently dropping someone.

## Buying and selling leave

Employees can convert bovenwettelijk (above-statutory) leave hours into
money, or money into hours — a small IKB-style flexibility standard in
many Dutch CAOs. A `LeaveTransaction` runs a declarative
`draft → submitted → approved/rejected → settled` request/approve
lifecycle with the same separation-of-duties guard used everywhere else
in Humaniq (`NoSelfApprovalGuard`): the approver may never be the
requesting employee.

**Only bovenwettelijk hours are sellable — the statutory floor is
structurally protected, not just checked at runtime.** A sell request
can never draw down `LeaveBalance.entitledHours`: no code path in this
capability ever writes that field, only `bovenwettelijkHours`. Because
the statutory-minimum rule is evaluated solely against `entitledHours`,
a sell settled through this flow cannot breach BW art. 7:634 by
construction — there is no writable path to the field the rule
protects, though a runtime sufficiency check (selling more
bovenwettelijk hours than are available is refused at approval) exists
too, belt-and-braces. A negative `bovenwettelijkHours` is additionally
an audit-time backstop violation if it ever arose some other way.

Settling a transaction — a guarded action, deliberately never a bare
lifecycle button — is idempotent (settling twice is a no-op) and writes
`bovenwettelijkHours` as the only balance field changed. The settled
euro amount then surfaces as a **current-run payroll input**: a
`settle`d transaction whose settlement period matches the next draft
run's period folds into that employee's `Payslip.leaveBuySell` and
`nettoPay` — a sold balance pays out, a bought balance deducts —
mirroring exactly how [retroactive
adjustments](/docs/payroll/retro-adjustments) fold into a run.
`PayrollCalculator` is never invoked to compute or verify the settled
amount.

```bash
occ humaniq:leave:settle --id <transactionId>
```

Employees see their own buy/sell requests on a self-service page (no
new top-level menu); an employee's transaction history also appears as
a row on their personnel-dossier detail page.

## Sickness case management (Wet verbetering Poortwachter)

`SickLeaveCase` models the **administrative** sickness case — from day
one of sickness through recovery — with a simple lifecycle:

```
gemeld ⇄ hersteld
```

`herstellen` moves a case to `hersteld` and stamps `recoveredDate`.
`heropenen` reopens within 4 weeks as a **samengesteld ziektegeval**
(BW art. 7:629 lid 10 — relapse within 4 weeks continues the same
104-week clock rather than starting a new case) and clears
`recoveredDate`.

### No medical data, ever

Per the AVG and the Autoriteit Persoonsgegevens beleidsregels "De zieke
werknemer", Humaniq records **only** that and how long an employee is sick
and the re-integration process facts — never the nature or cause of the
illness. The schema declares no diagnosis, symptom, cause, or medical-note
field of any kind, and the files widget on the case detail page is
explicitly scoped to gespreksverslagen and plan-van-aanpak documents, with
the no-medical-data warning repeated in its description.

### The Poortwachter milestone clock

Four milestone pairs (Due / Done) track the statutory re-integration
timeline, derived from `firstSickDay` but **stored** — like the
loonaangifte deadline, editability matters (UWV can grant deferral) and
correctness is enforced by rules, not recomputed away:

| Milestone | Week | Basis |
| --- | --- | --- |
| Probleemanalyse | 6 | Regeling procesgang art. 2 |
| Plan van aanpak | 8 | Regeling procesgang art. 4 |
| 42-weken ziekmelding (UWV) | 42 | ZW art. 38 |
| Eerstejaarsevaluatie | 52 | — |

Also tracked: `wachtdag` (an unpaid waiting day where CAO/contract
provides) and `loondoorbetalingPercentage` (default 70 — the BW art.
7:629 statutory minimum wage continuation for up to 104 weeks).

Three machine-checkable rules (domain `labour`, framework
`nl-poortwachter` for the milestones, `bw7-10` for loondoorbetaling)
enforce the clock:

1. **`nl-wvp-milestone-derivation`** — each Due must equal `firstSickDay`
   plus its rule-parameterised week offset; on an open case no Due may be
   null.
2. **`nl-wvp-milestone-overdue`** — an open case with a Due in the past
   and no matching Done is a mandatory violation; a Due within 14 days is
   flagged as approaching.
3. **`nl-loondoorbetaling-minimum`** — `loondoorbetalingPercentage` must
   be at least 70 on open cases.

```bash
occ humaniq:rules:audit
```

UWV wire transport and WIA / tweede-spoor flows are explicitly out of
scope — Humaniq tracks the case, not the UWV submission channel.

## Visible on a shared calendar

Approved leave and sickness absence sync onto a configured shared
Nextcloud calendar via `LeaveCalendarService` — "wie is er wanneer weg?"
without any external integration, since the host Nextcloud instance
already ships a full CalDAV stack. The sync writes only `Verlof —
{naam}` or `Afwezig — {naam}` — no reason, diagnosis, or leave type ever
reaches the calendar (the same AVG boundary as the case data itself).
Sync is a one-way projection: manual edits to synced events are
overwritten by the next run.

```bash
occ humaniq:calendar:sync
```

An unconfigured instance (no calendar principal/URI set) skips cleanly —
`skipped-no-calendar` — rather than failing.

## Dashboard analytics

The Dashboard surfaces four absence-analytics stat widgets — open
ziektegevallen, langdurig verzuim past the 42-weken UWV horizon,
verlofaanvragen in behandeling, and this month's approved verlofuren —
plus a `VerzuimOverzicht` open-cases werkvoorraad sorted by the UWV
42-weken deadline.
