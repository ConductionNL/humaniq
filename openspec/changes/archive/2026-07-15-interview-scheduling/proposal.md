---
kind: code
---

# Interview Scheduling (recruiting-interview events on a shared Nextcloud Calendar)

## Why

`recruiting-ats-basic` moves an `Application` through `nieuw -> screening -> gesprek -> aanbod -> aangenomen/afgewezen`, and the `uitnodigen` transition's own docblock states plainly: "The candidate is invited for an interview. No calendar/scheduling integration in the MVP." Today that invitation is a status flip with no artifact — nobody on the recruiting team can see, from a calendar, when and with whom a `gesprek` is actually happening. `leave-calendar-nc` already proved the exact mechanism hrmq needs: an on-demand service that projects register objects onto one configured shared Nextcloud calendar through a duck-typed `OCA\DAV\CalDAV\CalDavBackend`, idempotent per source object, degrading cleanly when the calendar is not configured. Interview scheduling forks that pattern for one new object type instead of two, closing the gap the ATS schema itself flags as deferred.

Two real integration paths exist and were investigated (design.md D1): OpenRegister's `CalendarLinkService::createAndLinkEvent()` is a hard dependency already available with zero duck-typing, but it resolves the target calendar via `findUserCalendar()` — the **acting user's own personal calendar** (`IUserSession::getUser()`), never a shared one. `LeaveCalendarService`'s pattern writes to a **configured shared calendar** (principal + URI from `SettingsService`), matching how hrmq already surfaces absence to the whole team. Interviews are the same kind of team artifact as absence — whoever is on the interview panel, and whoever else plans around it, needs to see it without depending on one recruiter's personal calendar being shared correctly. The design adopts the shared-calendar path (Option A) for that reason; the trade-off against the lower-code Option B is stated explicitly in design.md D1.

## What Changes

- **New `Interview` schema** (`lib/Settings/register.d/hr-ats.json`, alongside `Vacancy`/`Application`): one interview round for one `Application`, carrying `scheduledStart`/`scheduledEnd`, `interviewers`, `mode`, `location`, a `scheduled -> completed/cancelled` lifecycle on `status`, and a system-managed `calendarEventUid` set by the sync service.
- **New `InterviewCalendarService`** (`lib/Service/InterviewCalendarService.php`; fork of `LeaveCalendarService`): on-demand sync that upserts one VEVENT per `scheduled` Interview onto a configured shared calendar (dedicated config keys, separate from the leave calendar), updates the same event in place on reschedule, and removes the event on cancellation. One-way projection — hand edits made directly in the Calendar app are overwritten by the next sync, exactly like `leave-calendar-nc`. Idempotent by the stored `calendarEventUid`: the first sync creates the event and stamps the UID back onto the Interview; every later sync looks the event up by that stored UID rather than recomputing it, so a resync never duplicates. Degrades to a recorded `skipped-no-calendar` outcome (never an exception) when the backend or config is unavailable.
- **Two new config keys** via `SettingsService`: `interview_calendar_principal` / `interview_calendar_uri`, both defaulting empty (sync inert). Kept separate from `leave_calendar_principal`/`leave_calendar_uri` so an org can point interviews at the recruiting team's own calendar rather than the company-wide absence calendar.
- **New occ command `hrmq:interview:sync [--from DATE]`** (`lib/Command/InterviewCalendarSyncCommand.php`), mirroring `hrmq:calendar:sync`'s shape and exit codes, registered in `appinfo/info.xml` `<commands>`.
- **Guarded manifest action**: `InterviewDetail` gets a `POST /api/interviews/{id}/sync`-style action button ("Sync naar agenda", the `EmploymentContractDetail` generate-document action shape), server-guarded to admin/HR via the established `isAdminOrHr()` precedent (`PayrollController`/`LoonbeslagController`).
- **Manifest surface**: `Interviews`/`InterviewDetail` pages nested under the existing `OnboardingAtsGroup` ("Onboarding & ATS") — no new top-level menu item, per ADR-001. `ApplicationDetail` gains a FK-scoped "Gesprekken" list widget (`Interview.applicationId`) so recruiters navigate from the application to its interview rounds.
- **AVG boundary as a requirement, deliberately different from `leave-calendar-nc`'s**: unlike absence (where even the *kind* of leave is excluded), an interview event's whole purpose is context, so SUMMARY carries the candidate name and vacancy title. What still never reaches the calendar: candidate email/phone/`cvFile`/`motivation`/`talentPoolOptIn`/`rejectedDate`/`retentionExpiryDate` — none of that AVG-sensitive Application data is rendered into any ICS property, and interviewers appear as plain text (never as ATTENDEE/ORGANIZER, so no iMIP scheduling mail fires).
- **No corpus change**: interview scheduling is a recruiting-workflow convenience, not a statutory concern (design.md ADR-031 table).
- **Unit tests**: PHPUnit with a mocked calendar backend double, the `LeaveCalendarServiceTest` shape.

### Non-goals

- **Two-way sync** — the calendar is a projection; edits made in the Calendar app are overwritten by the next sync (same as `leave-calendar-nc`).
- **iMIP invitations / attendee scheduling mail** — interviewers are text, not ATTENDEE properties.
- **Interview-panel availability / free-busy lookups.**
- **Automatic sync on `uitnodigen`** — no event-listener/background-job wiring ships here; the same deferral rationale as `leave-calendar-nc` (`hrmq-rule-compliance-enforcement` owns future guard/event wiring). The occ command and the manifest action are both operator-triggered.
- **Multi-round rescheduling history** — rescheduling overwrites `scheduledStart`/`scheduledEnd` in place; there is no audit trail of prior slots beyond hrmq's existing object audit log.

## Capabilities

### New Capabilities

- `interview-scheduling`: an `Interview` object per Application interview round, an idempotent duck-typed VEVENT upsert/remove sync onto a configured shared calendar, the AVG summary boundary, the occ trigger, the guarded manifest action, and the recruiting-area manifest surface.

### Modified Capabilities

<!-- none — recruiting-ats-basic's Vacancy/Application schemas and lifecycle are untouched; this change only READS Application and adds a new Interview schema that references it -->

## Impact

- `lib/Settings/register.d/hr-ats.json` — NEW `Interview` schema (version `0.1.0`), alongside `Vacancy`/`Application`.
- `lib/Service/InterviewCalendarService.php` — NEW service (ADR-031 exception: external integration, same class as `LeaveCalendarService`/`PayrollGLPostService`).
- `lib/Service/SettingsService.php` — `getInterviewCalendarPrincipal()` / `getInterviewCalendarUri()` getters (empty defaults; docblocks document the keys, the shared-calendar expectation, and the one-way-projection caveat).
- `lib/Command/InterviewCalendarSyncCommand.php` — NEW occ command; `appinfo/info.xml` gains one `<command>` entry.
- `lib/Controller/InterviewController.php` — NEW controller with one guarded `sync()` action endpoint (admin/HR, `isAdminOrHr()` precedent) backing the manifest button; `appinfo/routes.php` gains one route.
- `src/manifest.json` — NEW `Interviews`/`InterviewDetail` pages under the existing `OnboardingAtsGroup`; `ApplicationDetail` gains a FK-scoped "Gesprekken" list widget.
- `tests/Unit/Service/InterviewCalendarServiceTest.php` — NEW unit tests (standalone suite, duck-typed double, no OCA\DAV dev dependency).
- Platform dependency (duck-typed, optional): `OCA\DAV\CalDAV\CalDavBackend`, same as `leave-calendar-nc` — **no** info.xml/composer dependency on the calendar or dav apps.
- No corpus changes, no new top-level menu group (ADR-001), no changes to the `recruiting-ats-basic` `Vacancy`/`Application` lifecycle.
