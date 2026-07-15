---
sidebar_position: 5
description: Raw per-day clock records with Arbeidstijdenwet (working-time) compliance checks.
---

# Time & attendance

`AttendanceRecord` gives HRMQ a raw clock surface with Dutch
working-time-law depth: a per-employee, per-day clock-in/clock-out record,
checked against the Arbeidstijdenwet (ATW).

**Attendance is not Timesheets.** `AttendanceRecord` is raw clock data —
what time someone actually clocked in and out. `Timesheet` remains the
per-period *approved-hours* record used for payroll and manager approval.
Aggregating attendance into timesheets automatically is an explicitly
deferred follow-up; today the two are separate objects.

## The record and its lifecycle

Each `AttendanceRecord` carries `employeeId`, `date` (the working day —
including for night shifts whose clock-out falls after midnight),
`clockIn`, `clockOut` (null while the day is open), `breakMinutes`,
`workedHours`, and an optional `location` (`kantoor` / `thuis` / `klant` /
`anders`).

```
open → gesloten
  ↕
(heropenen)
```

`sluiten` closes the day and supplies `clockOut` and `workedHours` on the
same write; `heropenen` reopens a closed day for correction, staying
under the audit trail. There are no approval states on
`AttendanceRecord` — approval lives on `Timesheet`.

### `workedHours` never masks a violation

`workedHours` is a **stored, writer-maintained** field — OpenRegister's
declarative calculation vocabulary can't express a date-time subtraction,
so it isn't computed automatically. It exists purely for presentation and
aggregation convenience. Every Arbeidstijdenwet check below computes
directly from the raw `clockIn` / `clockOut` / `breakMinutes` fields, so a
stale or hand-edited `workedHours` can neither hide nor fabricate a
compliance violation.

## Arbeidstijdenwet checks

Three machine-checkable rules (domain `labour`, framework
`nl-arbeidstijdenwet`, source ATW, effective since 1996-01-01) are
enforced by `NlAttendanceChecks`:

1. **`nl-atw-dagelijkse-rust`** (ATW art. 5:3 lid 2) — at least 11 hours
   of unbroken rest between consecutive working days. Passes vacuously
   when there's no previous-day record to compare against.
2. **`nl-atw-max-werkdag`** (ATW art. 5:7 lid 1) — a single dienst may not
   exceed 12 hours. Passes vacuously while the day is still open
   (`clockOut` is null — shift length isn't decidable yet).
3. **`nl-atw-pauze`** (ATW art. 5:4 lid 1) — statutory break tiers: more
   than 5.5 hours worked requires at least 30 minutes' break, more than
   10 hours requires at least 45 minutes. The tiers are carried as rule
   *parameters*, not hard-coded PHP constants, so they can be
   re-versioned without a code change.

The once-per-7-days 8-hour daily-rest reduction allowed by the ATW is not
modeled — the check applies the strict 11-hour default norm.

```bash
occ hrmq:rules:audit
```

## Pages

`AttendanceRecords` (under "Aanwezigheid" in the Verlof & verzuim menu)
lists `employeeId`, `date`, `clockIn`, `clockOut`, `workedHours`, and
`status`, filterable by status and location. `AttendanceRecordDetail`
exposes exactly the `sluiten` / `heropenen` lifecycle actions. A
`MijnAanwezigheid` `@me`-scoped page under Mijn HR mirrors the same
pattern as `MijnUren` — see [Self-service](/docs/hr/self-service).

Clock-in channels (PWA, kiosk, API), GPS/geofence verification, and CAO
overtime/toeslag calculation are explicitly out of scope for this MVP.
