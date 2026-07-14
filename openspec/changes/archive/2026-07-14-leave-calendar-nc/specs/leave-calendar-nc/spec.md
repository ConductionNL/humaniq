# Spec delta: leave-calendar-nc

## ADDED Requirements

@e2e exclude backend occ/service integration with the Nextcloud CalDAV store; no UI surface is added and hrmq has no app-level e2e suite yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: A configured shared calendar is the sync target (REQ-LC-001)

`SettingsService` SHALL expose `getLeaveCalendarPrincipal()` (app-config key `leave_calendar_principal`, e.g. `principals/users/hr`) and `getLeaveCalendarUri()` (key `leave_calendar_uri`), both defaulting to the empty string. The sync SHALL treat an empty value in either key as "not configured" and end the run as `skipped-no-calendar` without touching any calendar. The getter docblocks SHALL document both keys, that the target is expected to be a calendar the owning account shares with the team, and that the calendar is a one-way projection (manual event edits are overwritten by the next sync).

#### Scenario: Unconfigured instance skips cleanly

- GIVEN a fresh install where `leave_calendar_principal` and `leave_calendar_uri` are unset
- WHEN `occ hrmq:calendar:sync` runs
- THEN the command exits `0`, reports `skipped-no-calendar`, and no calendar object is created, updated, or deleted

### Requirement: Approved leave upserts one deterministic all-day event (REQ-LC-002)

`LeaveCalendarService` (`lib/Service/LeaveCalendarService.php`) SHALL derive, per `LeaveRequest` with `status: approved`, the deterministic UID `hrmq-leave-{uuid}` and object URI `hrmq-leave-{uuid}.ics` (uuid = the OpenRegister object id) and upsert one all-day VEVENT into the configured calendar: `DTSTART;VALUE=DATE` = `startDate`, `DTEND;VALUE=DATE` = `endDate` plus one day (RFC 5545 exclusive DTEND), SUMMARY `Verlof — {firstName} {lastName}` of the resolved Employee. The upsert SHALL probe `getCalendarObjectByUID` first and call `createCalendarObject` when absent or `updateCalendarObject` when present, so re-syncing an approved request whose dates changed updates the existing event and never creates a duplicate. The service SHALL reach the CalDAV store exclusively through a duck-typed, container-resolved `OCA\DAV\CalDAV\CalDavBackend` (string class name, try/catch, `method_exists` probes) — the investigated OCP surface (`ICreateFromString`, since 23.0.0) is create-only on NC 28–34 and rejects duplicate UIDs, so it cannot implement this upsert.

#### Scenario: Approval lands on the calendar

- GIVEN a configured calendar and an approved LeaveRequest 2026-08-03…2026-08-14
- WHEN the sync runs
- THEN the calendar contains exactly one VEVENT with UID `hrmq-leave-{uuid}`, DTSTART `20260803`, DTEND `20260815`, SUMMARY `Verlof — {employee name}`

#### Scenario: Re-sync after a date change updates, never duplicates

- GIVEN the event above already exists and the request's endDate moved to 2026-08-21
- WHEN the sync runs again
- THEN the same UID's event now ends at DTEND `20260822` and the calendar still contains exactly one `hrmq-leave-{uuid}` object

### Requirement: Sickness absence syncs with rolling/closed spans (REQ-LC-003)

Per `SickLeaveCase`, the service SHALL upsert one VEVENT with UID `hrmq-sick-{uuid}`: for `status: gemeld` (open) the span SHALL run from `firstSickDay` through the sync day (rolling DTEND = sync day + 1, re-extended by every sync); for `status: hersteld` the span SHALL close at `recoveredDate` plus one day and the event SHALL be kept as completed history, not removed. A `hersteld` case whose `recoveredDate` is null SHALL be skipped with a per-source warning (inconsistent data), never guessed.

#### Scenario: Open case rolls forward

- GIVEN an open SickLeaveCase with firstSickDay 2026-05-25 and a sync on 2026-07-13
- WHEN the sync runs
- THEN the case's event spans 2026-05-25 through 2026-07-13 (DTEND `20260714`), and a sync on a later day extends the same UID's DTEND accordingly

#### Scenario: Recovered case keeps its final footprint

- GIVEN a SickLeaveCase recovered on 2026-05-08 whose event exists
- WHEN the sync runs
- THEN the event's DTEND is `20260509` and the event remains on the calendar

### Requirement: AVG privacy — sickness shows 'Afwezig' and no event carries a reason (REQ-LC-004)

Events derived from a `SickLeaveCase` SHALL render SUMMARY exactly `Afwezig — {firstName} {lastName}` — no sickness nature, diagnosis, case status, or WvP detail in any ICS property. No event of either source type SHALL carry `reason`, `rejectionReason`, `leaveType` (its enum includes the health-adjacent `sick`/`care`/`parental`), a DESCRIPTION property, or any ATTENDEE/ORGANIZER (which would additionally trigger iMIP scheduling mail). When the Employee cannot be resolved, the summary SHALL fall back to the role word (`Verlof — medewerker` / `Afwezig — medewerker`) rather than exposing a raw id or slug on the shared calendar.

#### Scenario: Sick event is reason-free

- GIVEN an open SickLeaveCase for an employee
- WHEN its event is rendered
- THEN the ICS contains SUMMARY `Afwezig — {employee name}` and contains no DESCRIPTION, no reason text, no case field, and no ATTENDEE/ORGANIZER property

#### Scenario: Leave type never reaches the calendar

- GIVEN an approved LeaveRequest with `leaveType: care` and a filled `reason`
- WHEN its event is rendered
- THEN the ICS SUMMARY is `Verlof — {employee name}` and neither the leave type nor the reason appears anywhere in the calendar data

### Requirement: Out-of-scope sources are removed idempotently (REQ-LC-005)

The sync SHALL delete the calendar object of any `LeaveRequest` that is no longer `approved` (the status enum is `draft|submitted|approved|rejected` — there is no `cancelled` state; any non-approved status leaves the sync scope). For sources hard-deleted from the register, the sync SHALL reconcile orphans by listing the configured calendar's objects and deleting every `hrmq-leave-*.ics` / `hrmq-sick-*.ics` URI whose embedded uuid is not among the complete live source-id sets; objects without the `hrmq-` URI prefix SHALL never be touched. Reconciliation SHALL always compare against the full live-id sets even when `--from` bounds the upsert set, so a bounded sync can never mis-classify an old event as orphaned. The whole sync SHALL be idempotent: running it twice in a row produces zero changes on the second run.

#### Scenario: Rejection clears the calendar

- GIVEN an approved LeaveRequest whose event exists, whose status then changes to `rejected`
- WHEN the sync runs
- THEN the `hrmq-leave-{uuid}` object is deleted and manually created user events on the same calendar are untouched

#### Scenario: Deleted source is reconciled

- GIVEN an event `hrmq-leave-{uuid}.ics` whose LeaveRequest was deleted from the register
- WHEN the sync runs
- THEN the orphaned event is deleted

### Requirement: Absent calendar stack degrades to a recorded skip (REQ-LC-006)

Availability SHALL be duck-typed per run: `OCA\DAV\CalDAV\CalDavBackend` resolvable from the container AND `getCalendarByUri(principal, uri)` returning a calendar for the configured values. Any miss (backend unresolvable, config unset per REQ-LC-001, calendar deleted or URI wrong) SHALL end the run `skipped-no-calendar` with an explanatory message — never an exception, nothing above INFO in the log. hrmq SHALL gain no info.xml or composer dependency on the calendar or dav apps.

#### Scenario: Misconfigured calendar URI

- GIVEN `leave_calendar_uri` pointing at a calendar that does not exist on the configured principal
- WHEN `occ hrmq:calendar:sync` runs
- THEN the command exits `0`, reports `skipped-no-calendar` with the unresolved principal/URI, and throws no exception

#### Scenario: Calendar configured later supersedes the skip

- GIVEN a previous run that ended `skipped-no-calendar`
- WHEN the operator sets both config keys to a real shared calendar and re-runs the sync
- THEN the run upserts all in-scope sources normally (the skip was never persisted as terminal state)

### Requirement: An occ command is the MVP trigger (REQ-LC-007)

`lib/Command/CalendarSyncCommand.php` SHALL register `hrmq:calendar:sync` with optional `--from DATE` in `appinfo/info.xml` `<commands>` (next to `hrmq:glpost:run`). `--from` SHALL bound the upsert set to sources whose absence period ends on or after DATE; omitted means all sources (capped by the ObjectService load limit). The command SHALL print one outcome line per touched source (`created|updated|removed|unchanged|failed|skipped`) plus a summary, and SHALL exit `0` when no source ended `failed` (a fully skipped run is a healthy `0`) and `1` otherwise. No event listener or background job SHALL ship in this change — event-driven sync is deferred to the lifecycle wiring owned by `hrmq-rule-compliance-enforcement`.

#### Scenario: Bounded sync

- GIVEN approved leaves ending 2026-03-31 and 2026-08-14
- WHEN `occ hrmq:calendar:sync --from 2026-06-01` runs
- THEN only the August leave is upserted, and the March leave's existing event (if any) is not deleted by reconciliation

#### Scenario: Failure surfaces in the exit code

- GIVEN a configured calendar where the backend write throws for one source
- WHEN the command runs
- THEN that source's line shows `failed` with the error message and the command exits `1`
