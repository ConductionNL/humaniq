---
sidebar_position: 6
description: Forward-looking shift planning that pre-checks a roster against the same Arbeidstijdenwet rules Humaniq already enforces on realised clock data.
---

# Rostering

Humaniq administers who is employed, what they clocked, and what they
claim, but historically had no forward-looking plan. Rostering fills
that gap: define reusable shifts, assign employees per period, publish
the resulting roster, and — the differentiator — **check the planned
roster against the same Arbeidstijdenwet (working-time law) rules
already enforced on realised clock data, before publication**, when a
violation is still cheap to fix.

## Shifts, assignments, and publishing

- **`Shift`** — a reusable definition (name, start/end time, break,
  optional org-unit scope). A shift whose end time is not after its
  start time denotes a night shift crossing midnight.
- **`RosterAssignment`** — places one employee on one shift on one date
  within a `Roster`, projecting the shift's times onto the date.
- **`Roster`** — carries a real `concept → gepubliceerd` lifecycle
  (`publiceren`/`intrekken`). Publishing freezes the plan and makes it
  the team's roster.

## The ATW cross-check reuses existing rules

The roster check does not invent a new working-time rule — it runs the
**same three corpus rules** already enforced on realised attendance
(`nl-atw-dagelijkse-rust`, `nl-atw-max-werkdag`, `nl-atw-pauze`) over
the *planned* assignments instead:

```bash
occ humaniq:roster:check --roster <id>
occ humaniq:roster:check --period 2026-W28 --administration ADM-001
```

Exits non-zero on any mandatory violation. The same check is available
as an "ATW-controle" action from a roster's detail page, and published
assignments also join the standing `occ humaniq:rules:audit`.

## What this is not

Deeper workforce management — auto-optimisation, demand forecasting,
rule-based auto-scheduling, a drag-and-drop planbord,
availability/preferences, skills-matching, open-shift bidding/shift-swap,
and coverage alerts — is deliberately out of scope. Humaniq owns the plan
of record and the ATW compliance view, not a workforce-management
optimiser; deeper WFM tooling is meant to integrate via
[openconnector](https://openconnector.conduction.nl), not be rebuilt
here.

There is also no automation between a published roster and realised
`AttendanceRecord`/`Timesheet` hours — rostering is a planning surface,
not a clock-in system. See [Time & attendance](/docs/hr/time-attendance)
for the realised side.
