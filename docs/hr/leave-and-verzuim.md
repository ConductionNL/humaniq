---
sidebar_position: 3
description: Leave requests and balances, sickness (verzuim) case management, and the Wet verbetering Poortwachter milestone clock.
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

Automatic accrual and CAO bovenwettelijk rules are explicitly out of
scope — balances are entered and maintained directly.

Three machine-checkable rules audit every `LeaveBalance` (domain
`labour`, framework `bw7-10`, source BW art. 7:634/7:638/7:640a):

1. **`nl-verlof-wettelijk-minimum`** — `entitledHours` must be at least
   4× the snapshotted `contractHoursPerWeek`.
2. **`nl-verlof-saldo-niet-negatief`** — `usedHours` must not exceed
   `entitledHours + bovenwettelijkHours`.
3. **`nl-verlof-vervaltermijn`** — statutory hours must carry the correct
   1 July `expiryDate`.

```bash
occ hrmq:rules:audit
```

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
werknemer", HRMQ records **only** that and how long an employee is sick
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
occ hrmq:rules:audit
```

UWV wire transport and WIA / tweede-spoor flows are explicitly out of
scope — HRMQ tracks the case, not the UWV submission channel.

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
occ hrmq:calendar:sync
```

An unconfigured instance (no calendar principal/URI set) skips cleanly —
`skipped-no-calendar` — rather than failing.

## Dashboard analytics

The Dashboard surfaces four absence-analytics stat widgets — open
ziektegevallen, langdurig verzuim past the 42-weken UWV horizon,
verlofaanvragen in behandeling, and this month's approved verlofuren —
plus a `VerzuimOverzicht` open-cases werkvoorraad sorted by the UWV
42-weken deadline.
