---
kind: code
---

# Leave Calendar NC (approved absence on a shared Nextcloud calendar)

## Why

Spectr's canonical-feature scan flags `hrmq-canon-leave-calendar` at 3/9 competitor coverage: team-calendar visibility of approved absence is table stakes in every NL SMB HR suite (wie is er wanneer weg?), yet hrmq's approved `LeaveRequest`s and running `SickLeaveCase`s are visible only inside the app's own pages. Meanwhile the host platform ships a full CalDAV calendar stack that every Nextcloud user already lives in — hrmq is uniquely positioned (unlike SaaS competitors, who need Outlook/Google connectors) to make absence appear on a plain, shareable Nextcloud calendar with zero external integration.

Investigated against the Nextcloud server source (`lib/public/Calendar/`, NC 28–34 — hrmq's declared range in `appinfo/info.xml`): the public OCP surface can *discover* calendars (`IManager::getCalendarsForPrincipal`, since 23.0.0) and *create* events (`ICreateFromString::createFromString`, since 23.0.0) but has **no update or delete** — `CalDavBackend::createCalendarObject` rejects a duplicate UID with `BadRequest`, so re-syncing a changed leave via the OCP path is impossible (verified in `apps/dav/lib/CalDAV/CalDavBackend.php`). An idempotent upsert-and-remove sync therefore uses the DAV app's `CalDavBackend` (create/update/delete + UID lookup, long-stable across 28–34), resolved duck-typed from the container exactly like the app already resolves OpenRegister's ObjectService — inert with a recorded `skipped-no-calendar` outcome when the configured calendar cannot be resolved (design.md D1).

## What Changes

- **New `LeaveCalendarService`** (`lib/Service/LeaveCalendarService.php`; ADR-031 exception: external integration — the Nextcloud calendar stack is external to OpenRegister): an on-demand sync that upserts one all-day VEVENT per approved `LeaveRequest` (UID `hrmq-leave-{uuid}`) and per `SickLeaveCase` (UID `hrmq-sick-{uuid}`) into one configured shared calendar, updates events when the source changes, and removes events whose source is no longer in scope (rejected / back to draft / deleted). No event listeners, no background jobs — sync runs when the operator (or a cron wrapper) asks.
- **AVG privacy boundary as a requirement**: sickness events render as "Afwezig — {naam}" with **no** reason, diagnosis, or case detail; leave events render as "Verlof — {naam}" with no `reason` and no `leaveType` (the enum includes `sick`/`care`/`parental` — health-adjacent data that must not leave the register onto a shared calendar).
- **Two config keys** via `SettingsService` getters: `leave_calendar_principal` (e.g. `principals/users/hr`) and `leave_calendar_uri` (the calendar URI on that principal). Both default empty = sync inert.
- **New occ command `hrmq:calendar:sync [--from DATE]`** (`lib/Command/CalendarSyncCommand.php`), registered in `appinfo/info.xml` `<commands>` next to `hrmq:glpost:run`; prints per-source outcomes and a summary; exit `0` on success/skip, `1` on failure.
- **No corpus change**: shared-calendar visibility is a workplace convenience, not a statutory concern — the rule corpus stays untouched (stated explicitly in the design.md ADR-031 table).
- **Unit tests**: PHPUnit with a mocked calendar backend double (ICS construction, UID determinism, upsert/remove decisions, privacy invariants, skip path).

### Non-goals

- **Two-way sync** — the calendar is a projection; edits made in the Calendar app are overwritten by the next sync (documented on the config keys).
- **Free/busy integration** and availability lookups.
- **iMIP invitations / attendees** — events carry no ATTENDEE/ORGANIZER, so no scheduling mail is ever triggered.
- **Per-employee personal calendars** — one shared team calendar; fan-out per employee is a follow-up.
- **Event-driven sync on approval** — LeaveRequest transitions carry no server-side hook wiring hrmq owns today; deferred with the same rationale as payroll-glpost-shillinq D5 (the guard/event wiring belongs to `hrmq-rule-compliance-enforcement`).

## Capabilities

### New Capabilities

- `leave-calendar-nc`: the NC-platform leaf — configured target calendar, idempotent VEVENT upsert/remove sync for approved leave and sickness absence with the AVG summary boundary, duck-typed degradation, and the occ trigger.

### Modified Capabilities

<!-- none — leave-management / verzuim-wvp specs untouched; this change only READS LeaveRequest/SickLeaveCase/Employee -->

## Impact

- `lib/Service/LeaveCalendarService.php` — NEW service (ADR-031 exception: external integration, documented in design.md).
- `lib/Service/SettingsService.php` — `getLeaveCalendarPrincipal()` / `getLeaveCalendarUri()` getters (empty defaults; docblocks document the keys, the shared-calendar expectation, and the one-way-projection caveat).
- `lib/Command/CalendarSyncCommand.php` — NEW occ command; `appinfo/info.xml` gains one `<command>` entry.
- `tests/Unit/Service/LeaveCalendarServiceTest.php` — NEW unit tests (standalone suite per `tests/bootstrap.php`; the backend is a duck-typed double, so no OCA\DAV dev dependency is added).
- Platform dependency (duck-typed, optional): `OCA\DAV\CalDAV\CalDavBackend` from the always-shipped dav app + the OCP `\OCP\Calendar` read surface; **no** info.xml/composer dependency on the calendar UI app.
- No register/schema changes, no manifest changes, no corpus changes, no new seeds (the sync consumes the existing `hr-seed.json` leave/sick seeds).
