---
capability: time-attendance
status: in-progress
built_by: openspec/changes/time-attendance-mvp
---

# time-attendance Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [time-attendance-mvp](../../changes/time-attendance-mvp/) _(active)_ — `AttendanceRecord` per-day clock schema with open⇄gesloten lifecycle, 3 machine-checkable Arbeidstijdenwet rules (dagelijkse rust / max werkdag / pauze) under the new `nl-arbeidstijdenwet` framework with `NlAttendanceChecks` + the cross-record daily-rest audit context, attendance pages under `Verlof & verzuim` and `MijnAanwezigheid` under `Mijn HR` (kind: config)

## Purpose

Give hrmq a raw clock surface with Dutch working-time-law depth no Nextcloud
app has (Spectr `hrmq-insight-nc-ecosystem-gap`: German ArbZG time-compliance
apps exist in the ecosystem, nothing covers the Arbeidstijdenwet): a
per-employee/per-day `AttendanceRecord` (clockIn/clockOut/breakMinutes,
stored writer-maintained `workedHours`, declarative open⇄gesloten
lifecycle), three versioned machine-checkable ATW rules — ≥11h dagelijkse
rust between consecutive working days (art. 5:3 lid 2, a cross-record check
fed by a `RuleAuditService` attendance context), ≤12h per dienst (art. 5:7
lid 1), and the statutory pauze tiers (art. 5:4 lid 1) — enforced by
`NlAttendanceChecks`, plus the attendance pages under the existing
`Verlof & verzuim` menu group and a `MijnAanwezigheid` `@me` self-service
page. AttendanceRecord is raw clock data; `Timesheet` remains the per-period
approved-hours record — attendance→timesheet aggregation is an explicitly
deferred follow-up. Clock-in channels (PWA/kiosk/API), GPS/geofence
verification and CAO overtime/toeslag calculation from the 2026-05-23
`spec/time-attendance` draft are likewise out of scope here.

## Requirements

Detailed requirements (REQ-TA-001 … REQ-TA-006) are defined in the active
change's delta spec —
[`openspec/changes/time-attendance-mvp/specs/time-attendance/spec.md`](../../changes/time-attendance-mvp/specs/time-attendance/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: hrmq records per-day clock data as auditable AttendanceRecords checked against the Arbeidstijdenwet (REQ-TA-000)

The app MUST model per-day worked time as `AttendanceRecord` objects in the
OpenRegister `hrmq` register (one record per employee per working day, raw
`clockIn`/`clockOut`/`breakMinutes` facts under OpenRegister's audit trail)
and MUST audit them against machine-checkable Arbeidstijdenwet corpus rules
via `occ hrmq:rules:audit`, computing compliance from the raw clock fields —
never from derived convenience values — so every reported ATW violation is
traceable to the statute it cites.

#### Scenario: Clock day audited against the ATW corpus
- **GIVEN** an imported hrmq register with a closed AttendanceRecord whose raw clock fields breach an Arbeidstijdenwet norm
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a violation keyed to the corresponding `nl-atw-*` corpus rule id is reported for that object
