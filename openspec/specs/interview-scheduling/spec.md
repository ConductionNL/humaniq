---
capability: interview-scheduling
status: done
built_by: openspec/changes/archive/2026-07-15-interview-scheduling
---

# interview-scheduling Specification

**Status**: done
**Scope**: humaniq (NC-platform leaf; writes into the host Nextcloud instance's own CalDAV store — no other app dependency)
**OpenSpec changes**:
- [interview-scheduling](../../changes/archive/2026-07-15-interview-scheduling/) _(archived 2026-07-15)_ — a new `Interview` schema per Application interview round, `InterviewCalendarService` upserting one timed VEVENT per `scheduled` Interview into a configured shared Nextcloud calendar via a duck-typed, container-resolved `OCA\DAV\CalDAV\CalDavBackend`, a STORED `calendarEventUid` idempotency marker, the AVG summary boundary, `skipped-no-calendar` degradation, the occ trigger `humaniq:interview:sync`, and a guarded single-Interview manifest action (kind: code)

## Purpose

Close the gap `recruiting-ats-basic`'s `uitnodigen` transition docblock states plainly ("No calendar/scheduling integration in the MVP"): today an interview invitation is a bare status flip with no artifact, so nobody on the recruiting team can see from a calendar when and with whom a `gesprek` is actually happening. `leave-calendar-nc` already proved the mechanism humaniq needs — an on-demand service that projects register objects onto one configured shared Nextcloud calendar through a duck-typed `CalDavBackend`, degrading cleanly when unconfigured. Interview scheduling forks that pattern for a second object type, with one deliberate divergence: because interviews are scheduled/rescheduled/cancelled as individual, direct actions (not swept as one whole set every run), `InterviewCalendarService` PERSISTS the derived `calendarEventUid` back onto the Interview after first sync — an inspectable "has this ever reached the calendar" marker — rather than only deriving it at render time. AVG boundary: unlike absence (where even the kind of leave is excluded), an interview event's whole purpose is telling the panel who and what role, so SUMMARY carries the candidate name and vacancy title; everything else Application-sensitive (email, phone, cvFile, motivation, talentPoolOptIn, rejectedDate, retentionExpiryDate) never reaches the render path, and interviewers appear as plain DESCRIPTION text, never as an ATTENDEE/ORGANIZER (so no iMIP scheduling mail ever fires).

## Requirements

@e2e exclude backend occ/service/controller integration with the Nextcloud CalDAV store; no e2e suite exists yet for humaniq's manifest surfaces (tracked by active change humaniq-test-coverage-baseline)

### REQ-INTV-001: Interview is a scheduling record tied to one Application

`Interview` (`lib/Settings/register.d/hr-ats.json`) is a new schema — not fields bolted onto `Application` — carrying `applicationId` (uuid, `$ref: Application`, required), `scheduledStart` and `scheduledEnd` (`date-time`, required), `interviewers` (nullable free text), `mode` (enum `onsite|phone|video`, default `onsite`), `location` (nullable), `status` (enum `scheduled|completed|cancelled`, default `scheduled`, governed by an `x-openregister-lifecycle` state machine with transitions `voltooien` (`scheduled -> completed`) and `annuleren` (`scheduled -> cancelled`)), and `calendarEventUid` (nullable, system-managed, never user-editable). A separate schema is required because a single Application can go through more than one interview round, each with its own lifecycle and calendar identity.

#### Scenario: Interview references its Application
- **GIVEN** an Application in status `gesprek`
- **WHEN** an Interview is created with that Application's id as `applicationId`
- **THEN** the Interview is created with `status: scheduled` and `calendarEventUid: null`

#### Scenario: Interview lifecycle exposes exactly two transitions
- **GIVEN** a scheduled Interview
- **WHEN** the available lifecycle transitions are inspected
- **THEN** only `voltooien` and `annuleren` are available, both terminal, and no other status edge exists

### REQ-INTV-002: A configured shared calendar SHALL be the sync target, resolved duck-typed

`SettingsService` exposes `getInterviewCalendarPrincipal()` (app-config key `interview_calendar_principal`) and `getInterviewCalendarUri()` (key `interview_calendar_uri`), both defaulting to the empty string and kept separate from `leave_calendar_principal`/`leave_calendar_uri` so interviews and absence can target different shared calendars. `InterviewCalendarService` reaches the CalDAV store exclusively through a duck-typed, container-resolved `OCA\DAV\CalDAV\CalDavBackend` (string class name, try/catch, `method_exists` probes) — the same investigated OCP surface that blocks `leave-calendar-nc` (create-only, rejects duplicate UIDs on NC 28-34) blocks an idempotent interview upsert too, and OpenRegister's `CalendarLinkService::createAndLinkEvent()` is rejected as the sync path because it resolves the target calendar from the acting user's own session, never a configured shared calendar.

#### Scenario: Dedicated config keys are independent of the leave calendar
- **GIVEN** `leave_calendar_principal`/`leave_calendar_uri` are configured to a company-wide absence calendar
- **AND** `interview_calendar_principal`/`interview_calendar_uri` are unset
- **WHEN** `occ humaniq:interview:sync` runs
- **THEN** the run ends `skipped-no-calendar` even though the leave calendar sync would succeed

### REQ-INTV-003: Scheduling SHALL upsert one deterministic event, identified by a stored calendarEventUid

For each Interview with `status: scheduled`, `InterviewCalendarService` upserts one VEVENT into the configured calendar: `calendarEventUid` null derives UID `hrmq-interview-{uuid}` and object URI `hrmq-interview-{uuid}.ics` (uuid = the Interview's OpenRegister object id), creates the calendar object, and persists the derived UID back onto the Interview's `calendarEventUid` field (the `OfferApplicationRepository::save()` PUT-semantic field-merge idiom — every other Interview field is carried forward unchanged); `calendarEventUid` already set looks the event up by that stored UID and calls `updateCalendarObject` only when the rendered DTSTART/DTEND/SUMMARY/LOCATION differ from the stored event, so a no-op resync writes nothing. Running the sync twice in a row with no data change produces zero calendar writes on the second run.

#### Scenario: First sync creates and stamps the UID
- **GIVEN** a configured calendar and a scheduled Interview with `calendarEventUid: null`
- **WHEN** the sync runs
- **THEN** the calendar contains one VEVENT with UID `hrmq-interview-{uuid}` and the Interview's `calendarEventUid` is persisted as that same value

#### Scenario: Rescheduling updates the same event, never duplicates
- **GIVEN** the Interview above already has `calendarEventUid` set and its event exists
- **WHEN** `scheduledStart`/`scheduledEnd` are changed and the sync runs again
- **THEN** the same UID's event now reflects the new DTSTART/DTEND and the calendar still contains exactly one `hrmq-interview-{uuid}` object

#### Scenario: Second consecutive sync with no changes is a no-op
- **GIVEN** a scheduled Interview whose event already matches its current fields exactly
- **WHEN** the sync runs twice in a row
- **THEN** the second run reports `unchanged` for that Interview and issues no create/update call

### REQ-INTV-004: Cancellation SHALL remove the event; completion SHALL keep it as history

The sync deletes the calendar object of any Interview whose `status` is `cancelled` (via `deleteCalendarObject`, looked up by the stored `calendarEventUid`), and does NOT re-upsert or delete the calendar object of any Interview whose `status` is `completed` — a completed Interview's last-synced event stays on the calendar as completed history. For Interviews hard-deleted from the register, the sync reconciles orphans by listing the configured calendar's objects and deleting every `hrmq-interview-*.ics` URI whose embedded uuid is not among the complete live Interview-id set, regardless of any `--from` bound; objects without the `hrmq-interview-` URI prefix are never touched.

#### Scenario: Cancelling an Interview removes its event
- **GIVEN** a scheduled Interview whose event exists on the calendar
- **WHEN** its status transitions to `cancelled` and the sync runs
- **THEN** the `hrmq-interview-{uuid}` calendar object is deleted and the Interview's own record is retained (not deleted from the register)

#### Scenario: Completing an Interview leaves its event in place
- **GIVEN** a scheduled Interview whose event exists on the calendar
- **WHEN** its status transitions to `completed` and the sync runs
- **THEN** the calendar object is left unchanged and is not deleted

#### Scenario: Deleted Interview is reconciled
- **GIVEN** an event `hrmq-interview-{uuid}.ics` whose Interview object was deleted from the register
- **WHEN** the sync runs
- **THEN** the orphaned event is deleted and manually created user events on the same calendar are untouched

### REQ-INTV-005: AVG boundary — candidate name and role are shown, Application PII is not

Events render SUMMARY exactly `Sollicitatiegesprek — {candidateName} ({vacancyTitle})`, resolved from the referenced Application and its Vacancy, falling back to `Sollicitatiegesprek — kandidaat` when the Application cannot be resolved. `interviewers` renders as plain text inside the DESCRIPTION property, and no event carries an ATTENDEE or ORGANIZER property (so no iMIP scheduling mail is ever triggered). No event of any Interview carries the referenced Application's `email`, `phone`, `cvFile`, `motivation`, `talentPoolOptIn`, `rejectedDate`, or `retentionExpiryDate` in any ICS property — the loading path (`InterviewRepository::loadApplicationIndex()`) never reads those fields into memory at all.

#### Scenario: Summary carries name and role, nothing else from the Application
- **GIVEN** a scheduled Interview for an Application with `candidateName: "Sam de Vries"`, `email`, `phone`, and `motivation` all populated, for a Vacancy titled "Backend Developer"
- **WHEN** its event is rendered
- **THEN** the ICS SUMMARY is `Sollicitatiegesprek — Sam de Vries (Backend Developer)` and no property anywhere in the ICS contains the email, phone, or motivation text

#### Scenario: Interviewers never become scheduling attendees
- **GIVEN** a scheduled Interview with `interviewers: "Els Bakker, Jan Smit"`
- **WHEN** its event is rendered
- **THEN** the DESCRIPTION contains the interviewer names as plain text and the ICS contains no ATTENDEE or ORGANIZER property

#### Scenario: Unresolvable Application falls back safely
- **GIVEN** a scheduled Interview whose `applicationId` no longer resolves to an Application
- **WHEN** its event is rendered
- **THEN** the SUMMARY is `Sollicitatiegesprek — kandidaat` and no raw id or uuid appears in the SUMMARY

### REQ-INTV-006: Absent calendar stack SHALL degrade to a recorded skip

Availability is duck-typed per run: `OCA\DAV\CalDAV\CalDavBackend` resolvable from the container AND `getCalendarByUri(principal, uri)` returning a calendar for the configured `interview_calendar_principal`/`interview_calendar_uri` values. Any miss (backend unresolvable, either config key unset, calendar deleted or URI wrong) ends the run `skipped-no-calendar` with an explanatory message — never an exception, nothing above INFO in the log. humaniq gains no info.xml or composer dependency on the calendar or dav apps from this change.

#### Scenario: Unconfigured instance skips cleanly
- **GIVEN** a fresh install where `interview_calendar_principal` and `interview_calendar_uri` are unset
- **WHEN** `occ humaniq:interview:sync` runs
- **THEN** the command exits `0`, reports `skipped-no-calendar`, and no calendar object is created, updated, or deleted

#### Scenario: Misconfigured calendar URI
- **GIVEN** `interview_calendar_uri` pointing at a calendar that does not exist on the configured principal
- **WHEN** `occ humaniq:interview:sync` runs
- **THEN** the command exits `0`, reports `skipped-no-calendar` with the unresolved principal/URI, and throws no exception

### REQ-INTV-007: An occ command SHALL mirror the leave-calendar sync trigger

`lib/Command/InterviewCalendarSyncCommand.php` registers `humaniq:interview:sync` with optional `--from DATE` in `appinfo/info.xml` `<commands>` (next to `humaniq:calendar:sync`). `--from` bounds the upsert set to Interviews whose `scheduledStart` is on or after DATE; omitted means all `scheduled` Interviews (capped by the ObjectService load limit). Reconciliation always uses the full live-id set regardless of `--from`. The command prints one outcome line per touched Interview (`created|updated|removed|unchanged|failed|skipped`) plus a summary, and exits `0` when no source ended `failed` (a fully skipped run is a healthy `0`) and `1` otherwise.

#### Scenario: Bounded sync
- **GIVEN** scheduled Interviews starting 2026-06-01 and 2026-08-14
- **WHEN** `occ humaniq:interview:sync --from 2026-07-01` runs
- **THEN** only the August Interview is upserted, and the June Interview's existing event (if any) is not deleted by reconciliation

#### Scenario: Failure surfaces in the exit code
- **GIVEN** a configured calendar where the backend write throws for one Interview
- **WHEN** the command runs
- **THEN** that Interview's line shows `failed` with the error message and the command exits `1`

### REQ-INTV-008: A guarded manifest action SHALL trigger a single-Interview sync

`InterviewDetail` exposes an `api-call` action ("Sync naar agenda") that calls `POST /api/interviews/sync` (`interviewId` as a body param, the established `request-offer-signature`/`payroll#calculate` shape used by every other guarded humaniq manifest action) backed by `InterviewController::sync()`. The endpoint rejects the request unless the acting user is an admin or a member of the established HR-role check (the `isAdminOrHr()` precedent shared with `PayrollController`/`LoonbeslagController`/`OfferController`) — BEFORE any resolve; the posted `interviewId` then resolves through OpenRegister's ObjectService under the caller's ambient RBAC (unresolvable/unauthorized collapses to the same 404, the `OfferController::authorizeApplication()` no-admin-idor shape). An authorized call invokes `InterviewCalendarService::syncOne()` for exactly the one Interview identified and returns its outcome.

#### Scenario: Admin/HR user syncs one interview from the detail page
- **GIVEN** an admin or HR-group user viewing a scheduled Interview's detail page with a configured calendar
- **WHEN** they trigger the "Sync naar agenda" action
- **THEN** the endpoint upserts that Interview's event and the action reports success

#### Scenario: Non-admin, non-HR user is rejected
- **GIVEN** a user who is neither an admin nor in the HR role viewing an Interview's detail page
- **WHEN** they call the sync endpoint directly
- **THEN** the request is rejected and no calendar object is created or modified

### REQ-INTV-009: Interview pages SHALL nest under the existing recruiting menu group

`Interviews` (list) and `InterviewDetail` pages are children of the existing `OnboardingAtsGroup` menu group in `src/manifest.json`, alongside `Onboardings`/`Vacancies`/`Applications`/`Offboardings` — no new top-level menu group is added for this change, per ADR-001. `ApplicationDetail` gains an `object-list` widget ("Gesprekken") resolving `Interview.applicationId` for the current Application, so an interview round is reachable from its Application without a separate navigation path.

#### Scenario: Interview pages appear under Onboarding & ATS
- **GIVEN** the humaniq navigation menu
- **WHEN** the "Onboarding & ATS" group is expanded
- **THEN** it lists `Onboardings`, `Vacatures`, `Sollicitaties`, an Interview entry, and `Offboardings`, with no new top-level menu item added anywhere else

#### Scenario: An Application's interview rounds are reachable from its detail page
- **GIVEN** an Application with two Interview objects referencing it
- **WHEN** its detail page is opened
- **THEN** both Interviews appear in the "Gesprekken" list widget, each linking to its own InterviewDetail page
