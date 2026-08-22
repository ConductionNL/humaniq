---
capability: leave-calendar-nc
status: done
built_by: openspec/changes/archive/2026-07-14-leave-calendar-nc
---

# leave-calendar-nc Specification

**Status**: done
**Scope**: humaniq (NC-platform leaf; writes into the host Nextcloud instance's own CalDAV store — no other app dependency)
**OpenSpec changes**:
- [leave-calendar-nc](../../changes/archive/2026-07-14-leave-calendar-nc/) _(archived 2026-07-14)_ — `LeaveCalendarService` upserting one all-day VEVENT per approved `LeaveRequest`/`SickLeaveCase` into a configured shared Nextcloud calendar via a duck-typed, container-resolved `OCA\DAV\CalDAV\CalDavBackend`, the AVG summary boundary, `skipped-no-calendar` degradation, and the occ trigger `humaniq:calendar:sync` (kind: code)

## Purpose

Make approved absence visible on a plain, shareable Nextcloud calendar —
table stakes in every NL SMB HR suite (wie is er wanneer weg?), and a
feature humaniq is uniquely positioned to deliver with zero external
integration because the host platform already ships a full CalDAV stack.
The public OCP calendar surface can discover calendars and create events but
has no update or delete and rejects a duplicate UID, so an idempotent
upsert/remove sync writes through the DAV app's `CalDavBackend` instead —
duck-typed and container-resolved exactly like humaniq resolves OpenRegister's
ObjectService, with a recorded `skipped-no-calendar` degradation when the
configured calendar cannot be resolved. AVG boundary: sickness events read
only "Afwezig — {naam}" and leave events only "Verlof — {naam}" — no reason,
diagnosis, or leave type ever reaches the calendar.

## Requirements

@e2e exclude backend occ/service integration with the Nextcloud CalDAV store; no UI surface is added and humaniq has no app-level e2e suite yet (tracked by active change humaniq-test-coverage-baseline)

### REQ-LC-001: A configured shared calendar SHALL be the sync target

`SettingsService` exposes `getLeaveCalendarPrincipal()` (app-config key `leave_calendar_principal`, e.g. `principals/users/hr`) and `getLeaveCalendarUri()` (key `leave_calendar_uri`), both defaulting to the empty string. The sync treats an empty value in either key as "not configured" and ends the run as `skipped-no-calendar` without touching any calendar. The getter docblocks document both keys, that the target is expected to be a calendar the owning account shares with the team, and that the calendar is a one-way projection (manual event edits are overwritten by the next sync).

#### Scenario: Unconfigured instance skips cleanly
- **GIVEN** a fresh install where `leave_calendar_principal` and `leave_calendar_uri` are unset
- **WHEN** `occ humaniq:calendar:sync` runs
- **THEN** the command exits `0`, reports `skipped-no-calendar`, and no calendar object is created, updated, or deleted

### REQ-LC-002: Approved leave SHALL upsert one deterministic all-day event

`LeaveCalendarService` (`lib/Service/LeaveCalendarService.php`) derives, per `LeaveRequest` with `status: approved`, the deterministic UID `hrmq-leave-{uuid}` and object URI `hrmq-leave-{uuid}.ics` (uuid = the OpenRegister object id) and upserts one all-day VEVENT into the configured calendar: `DTSTART;VALUE=DATE` = `startDate`, `DTEND;VALUE=DATE` = `endDate` plus one day (RFC 5545 exclusive DTEND), SUMMARY `Verlof — {firstName} {lastName}` of the resolved Employee. The upsert probes `getCalendarObjectByUID` first (scoped to the configured calendar via its third argument) and calls `createCalendarObject` when absent or `updateCalendarObject` when present — diffing the stored DTSTART/DTEND/SUMMARY first so a no-op sync never rewrites the event — so re-syncing an approved request whose dates changed updates the existing event and never creates a duplicate. The service reaches the CalDAV store exclusively through a duck-typed, container-resolved `OCA\DAV\CalDAV\CalDavBackend` (string class name, try/catch, `method_exists` probes) — the investigated OCP surface (`ICreateFromString`, since 23.0.0) is create-only on NC 28-34 and rejects duplicate UIDs, so it cannot implement this upsert.

#### Scenario: Approval lands on the calendar
- **GIVEN** a configured calendar and an approved LeaveRequest 2026-08-03…2026-08-14
- **WHEN** the sync runs
- **THEN** the calendar contains exactly one VEVENT with UID `hrmq-leave-{uuid}`, DTSTART `20260803`, DTEND `20260815`, SUMMARY `Verlof — {employee name}`

#### Scenario: Re-sync after a date change updates, never duplicates
- **GIVEN** the event above already exists and the request's endDate moved to 2026-08-21
- **WHEN** the sync runs again
- **THEN** the same UID's event now ends at DTEND `20260822` and the calendar still contains exactly one `hrmq-leave-{uuid}` object

### REQ-LC-003: Sickness absence SHALL sync with rolling/closed spans

Per `SickLeaveCase`, the service upserts one VEVENT with UID `hrmq-sick-{uuid}`: for `status: gemeld` (open) the span runs from `firstSickDay` through the sync day (rolling DTEND = sync day + 1, re-extended by every sync, never bounded by `--from`); for `status: hersteld` the span closes at `recoveredDate` plus one day and the event is kept as completed history, not removed. A `hersteld` case whose `recoveredDate` is null is skipped with a per-source warning (inconsistent data), never guessed.

#### Scenario: Open case rolls forward
- **GIVEN** an open SickLeaveCase with firstSickDay 2026-05-25 and a sync on 2026-07-13
- **WHEN** the sync runs
- **THEN** the case's event spans 2026-05-25 through 2026-07-13 (DTEND `20260714`), and a sync on a later day extends the same UID's DTEND accordingly

#### Scenario: Recovered case keeps its final footprint
- **GIVEN** a SickLeaveCase recovered on 2026-05-08 whose event exists
- **WHEN** the sync runs
- **THEN** the event's DTEND is `20260509` and the event remains on the calendar

### REQ-LC-004: AVG privacy — sickness SHALL show 'Afwezig' and no event SHALL carry a reason

Events derived from a `SickLeaveCase` render SUMMARY exactly `Afwezig — {firstName} {lastName}` — no sickness nature, diagnosis, case status, or WvP detail in any ICS property. No event of either source type carries `reason`, `rejectionReason`, `leaveType` (its enum includes the health-adjacent `sick`/`care`/`parental`), a DESCRIPTION property, or any ATTENDEE/ORGANIZER (which would additionally trigger iMIP scheduling mail). When the Employee cannot be resolved, the summary falls back to the role word (`Verlof — medewerker` / `Afwezig — medewerker`) rather than exposing a raw id or slug on the shared calendar.

#### Scenario: Sick event is reason-free
- **GIVEN** an open SickLeaveCase for an employee
- **WHEN** its event is rendered
- **THEN** the ICS contains SUMMARY `Afwezig — {employee name}` and contains no DESCRIPTION, no reason text, no case field, and no ATTENDEE/ORGANIZER property

#### Scenario: Leave type never reaches the calendar
- **GIVEN** an approved LeaveRequest with `leaveType: care` and a filled `reason`
- **WHEN** its event is rendered
- **THEN** the ICS SUMMARY is `Verlof — {employee name}` and neither the leave type nor the reason appears anywhere in the calendar data

### REQ-LC-005: Out-of-scope sources SHALL be removed idempotently

The sync deletes the calendar object of any `LeaveRequest` that is no longer `approved` (the status enum is `draft|submitted|approved|rejected` — there is no `cancelled` state; any non-approved status leaves the sync scope). For sources hard-deleted from the register, the sync reconciles orphans by listing the configured calendar's objects and deleting every `hrmq-leave-*.ics` / `hrmq-sick-*.ics` URI whose embedded uuid is not among the complete live source-id sets; objects without the `humaniq-` URI prefix are never touched. Reconciliation always compares against the full live-id sets even when `--from` bounds the upsert set, so a bounded sync can never mis-classify an old event as orphaned. The whole sync is idempotent: running it twice in a row produces zero changes on the second run.

#### Scenario: Rejection clears the calendar
- **GIVEN** an approved LeaveRequest whose event exists, whose status then changes to `rejected`
- **WHEN** the sync runs
- **THEN** the `hrmq-leave-{uuid}` object is deleted and manually created user events on the same calendar are untouched

#### Scenario: Deleted source is reconciled
- **GIVEN** an event `hrmq-leave-{uuid}.ics` whose LeaveRequest was deleted from the register
- **WHEN** the sync runs
- **THEN** the orphaned event is deleted

### REQ-LC-006: Absent calendar stack SHALL degrade to a recorded skip

Availability is duck-typed per run: `OCA\DAV\CalDAV\CalDavBackend` resolvable from the container AND `getCalendarByUri(principal, uri)` returning a calendar for the configured values. Any miss (backend unresolvable, config unset per REQ-LC-001, calendar deleted or URI wrong) ends the run `skipped-no-calendar` with an explanatory message — never an exception, nothing above INFO in the log. humaniq gains no info.xml or composer dependency on the calendar or dav apps.

#### Scenario: Misconfigured calendar URI
- **GIVEN** `leave_calendar_uri` pointing at a calendar that does not exist on the configured principal
- **WHEN** `occ humaniq:calendar:sync` runs
- **THEN** the command exits `0`, reports `skipped-no-calendar` with the unresolved principal/URI, and throws no exception

#### Scenario: Calendar configured later supersedes the skip
- **GIVEN** a previous run that ended `skipped-no-calendar`
- **WHEN** the operator sets both config keys to a real shared calendar and re-runs the sync
- **THEN** the run upserts all in-scope sources normally (the skip was never persisted as terminal state)

### REQ-LC-007: An occ command SHALL be the MVP trigger

`lib/Command/CalendarSyncCommand.php` registers `humaniq:calendar:sync` with optional `--from DATE` in `appinfo/info.xml` `<commands>` (next to `humaniq:glpost:run`). `--from` bounds the upsert set to sources whose absence period ends on or after DATE; omitted means all sources (capped by the ObjectService load limit). The command prints one outcome line per touched source (`created|updated|removed|unchanged|failed|skipped`) plus a summary, and exits `0` when no source ended `failed` (a fully skipped run is a healthy `0`) and `1` otherwise. No event listener or background job ships in this change — event-driven sync is deferred to the lifecycle wiring owned by `humaniq-rule-compliance-enforcement`.

#### Scenario: Bounded sync
- **GIVEN** approved leaves ending 2026-03-31 and 2026-08-14
- **WHEN** `occ humaniq:calendar:sync --from 2026-06-01` runs
- **THEN** only the August leave is upserted, and the March leave's existing event (if any) is not deleted by reconciliation

#### Scenario: Failure surfaces in the exit code
- **GIVEN** a configured calendar where the backend write throws for one source
- **WHEN** the command runs
- **THEN** that source's line shows `failed` with the error message and the command exits `1`
