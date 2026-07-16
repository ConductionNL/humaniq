# Design — interview-scheduling

## Context

**hrmq side (verified at HEAD):** `Application` (`lib/Settings/register.d/hr-ats.json`) carries `vacancyId` ($ref `Vacancy`), `candidateName`, `email`, `phone`, `cvFile`, `motivation`, `status` (`nieuw|screening|gesprek|aanbod|aangenomen|afgewezen`), `rejectedDate`, `talentPoolOptIn`, `retentionExpiryDate`. Its `x-openregister-lifecycle` `uitnodigen` transition (`screening -> gesprek`) carries the docblock "No calendar/scheduling integration in the MVP" — the explicit gap this change closes. `Vacancy` carries `title`, referenced for interview-summary context. There is no separate `Candidate` entity (AVG data-minimisation, `recruiting-ats-basic` design D2) — PII lives directly on `Application`.

`leave-calendar-nc` (archived, `lib/Service/LeaveCalendarService.php`) is the proven precedent: duck-typed, container-resolved `OCA\DAV\CalDAV\CalDavBackend` (string FQCN, `mixed`, try/catch + `method_exists` probes), hand-built RFC 5545 ICS, `SettingsService`-driven principal+URI config with empty defaults, an occ command trigger, and a `skipped-no-calendar` degradation path that is a healthy `exit 0`, never a thrown exception. That investigation (OCP's `ICreateFromString` is create-only on NC 28-34 and rejects a duplicate UID with `BadRequest` — verified against `apps/dav/lib/CalDAV/CalDavBackend.php`) is binding here too: an idempotent upsert/remove contract still requires the DAV backend, not the public OCP surface.

**Two real options for the calendar target were investigated (this is the decision this design must make, D1):**

- **Option A — mirror `LeaveCalendarService`:** duck-type `CalDavBackend`, resolve the target calendar from `SettingsService`-configured principal + URI (a calendar the HR/recruiting account explicitly shares with the team), write/update/delete VEVENTs there. No `use` import, no info.xml/composer dependency on the dav app.
- **Option B — OpenRegister `CalendarLinkService::createAndLinkEvent(string $objectUuid, int $registerId, int $schemaId, array $eventData): CalendarLink`** (verified at `openregister/lib/Service/CalendarLinkService.php`): a single call creates the VEVENT (via the legacy `CalendarEventService`, tagging it with `X-OPENREGISTER-*` properties) **and** a `CalendarLink` row in one step. OpenRegister is a hard composer/info.xml dependency of hrmq already, so this needs no duck-typing at all — genuinely less code. But `createAndLinkEvent()` resolves the calendar exclusively through `$this->userSession->getUser()` (`resolveCalendarUriForId()` / the create path never accepts a principal or calendar URI parameter) — the event always lands on **whichever user's session is acting**, i.e. the recruiter who happened to click the button, on *their own personal* CalDAV calendar. There is no shared-target parameter anywhere in its public surface.

### D1 — Option A: write through the duck-typed DAV backend to a configured SHARED calendar

**Decision: Option A.** An interview is a team artifact exactly like approved absence: the panel, the hiring manager, and whoever else plans around the interview slot need to see it without depending on one recruiter's personal Nextcloud calendar being shared with the right people (and staying shared, and that recruiter staying the one who scheduled every future interview). Option B's `createAndLinkEvent()` would put every interview on a different personal calendar depending on who is logged in when the sync runs — the opposite of hrmq's "wie is er wanneer weg" team-visibility precedent from `leave-calendar-nc`, and a worse fit than the lower-code savings justify. `InterviewCalendarService` therefore forks `LeaveCalendarService` directly: `CalDavBackend` resolved duck-typed by string FQCN from the container, guarded by `method_exists()` probes on the same five methods (`getCalendarByUri`, `getCalendarObjectByUID`, `createCalendarObject`, `updateCalendarObject`, `deleteCalendarObject`, `getCalendarObjects`), target resolved from `SettingsService`-configured principal + URI. Any resolution miss (backend unavailable, config unset, calendar deleted/URI wrong) degrades to `skipped-no-calendar` — never an exception, nothing above INFO in the log. hrmq gains no info.xml/composer dependency on the dav app (same reasoning as `leave-calendar-nc` D1: the dav app ships with every Nextcloud server; its absence just means the probe skips).

Trade-off, stated honestly: this is more code than Option B (a whole duck-typed service instead of one hard-dependency call) and `CalDavBackend` remains private OCA API. Both are accepted for the same reason `leave-calendar-nc` accepted them: the unit tests pin the exact call shapes so drift surfaces as a test-visible contract change, and the feature's whole point — a genuinely *shared* calendar — is unreachable through Option B's `IUserSession`-bound resolution. **Dedicated config keys** (`interview_calendar_principal`/`interview_calendar_uri`, D6), separate from `leave_calendar_principal`/`leave_calendar_uri`: an org may reasonably want interviews on the recruiting team's own shared calendar, distinct from the company-wide absence calendar the whole staff sees — reusing the leave keys would force those two audiences onto one calendar with no opt-out.

## Goals / Non-Goals

**Goals:** every `scheduled` Interview visible as a timed event on one configured shared Nextcloud calendar; idempotent per Interview object (reschedule updates in place, never duplicates); the event removed when the Interview is cancelled; a hard AVG boundary on what candidate/Application data reaches the calendar; graceful `skipped-no-calendar` degradation; operator- and UI-triggered (occ command + guarded manifest action), event-listener-free.

**Non-Goals:** two-way sync (calendar is a projection; manual edits are overwritten), iMIP invitations/ATTENDEE scheduling mail, interview-panel free/busy lookups, automatic sync on the `uitnodigen` transition (deferred to `hrmq-rule-compliance-enforcement`'s future guard/event wiring, the `leave-calendar-nc` D6/`payroll-glpost-shillinq` D5 deferral precedent), and a reschedule history (only the current slot is kept; hrmq's object audit log is the change trail).

## Decisions

### D2 — Interview is a new schema, not fields on Application

**Decision:** a new `Interview` schema referencing `Application` via `applicationId` ($ref), not new fields bolted onto `Application` itself. Reasons: (1) a single `Application` can go through more than one interview round (an initial screening call and a later panel round are both "gesprek"-stage events) — a one-to-many relationship models naturally as separate objects, the same shape as `LeaveRequest`/`SickLeaveCase` each pointing at one `Employee` via `employeeId`; scalar fields on `Application` could only ever represent one slot at a time. (2) Rescheduling or cancelling one interview round must never touch `Application`'s own pipeline lifecycle (`nieuw -> screening -> gesprek -> ...`) — keeping scheduling data on its own object keeps the two lifecycles orthogonal. (3) `calendarEventUid` needs to be per-*event*, not per-*application*; a second interview round needs its own UID, which a single scalar field on `Application` cannot hold.

**Interview schema shape** (`lib/Settings/register.d/hr-ats.json`, alongside `Vacancy`/`Application`):

| Property | Type | Notes |
|---|---|---|
| `applicationId` | `string` (uuid, `$ref: Application`) | required; the interview round belongs to exactly one Application |
| `scheduledStart` | `string` (`date-time`) | required |
| `scheduledEnd` | `string` (`date-time`) | required |
| `interviewers` | `string`, nullable | free-text name(s); never rendered as ATTENDEE (D5) |
| `mode` | `string` enum `onsite\|phone\|video`, default `onsite` | drives how `location` is interpreted |
| `location` | `string`, nullable | physical location/room, or a video-call URL when `mode: video` |
| `status` | `string` enum `scheduled\|completed\|cancelled`, default `scheduled` | governed by `x-openregister-lifecycle` |
| `calendarEventUid` | `string`, nullable | system-managed (D3); set by `InterviewCalendarService` on first sync, never user-editable |

`x-openregister-lifecycle`: `field: status`, `initial: scheduled`, `terminal: [completed, cancelled]`, transitions `voltooien` (`scheduled -> completed`) and `annuleren` (`scheduled -> cancelled`, D4). Rescheduling is deliberately **not** a lifecycle transition — it is a plain field update to `scheduledStart`/`scheduledEnd` while `status` stays `scheduled`; the next sync then updates the existing calendar event in place (D3).

### D3 — Idempotent identity: a STORED `calendarEventUid`, not a purely derived one

Unlike `LeaveCalendarService` (which derives `hrmq-leave-{uuid}`/`hrmq-sick-{uuid}` at render time and never persists it, because that service always sweeps the *entire* live set on every run), `InterviewCalendarService` **persists** the UID back onto the Interview object after the first successful create. Rationale for the deviation: Interviews are scheduled/rescheduled/cancelled as individual, direct actions (occ `--from`-bounded runs, and the single-object manifest sync action), so the object itself needs an inspectable "has this ever reached the calendar" signal — `calendarEventUid: null` means "never synced", a non-null value means "synced, and this is the exact identity to look up". The stored value is still deterministic (`hrmq-interview-{uuid}`, same prefix convention as `hrmq-leave-`/`hrmq-sick-`) — persisting it does not change *what* the UID is, only that the object carries its own answer instead of every caller recomputing it.

Upsert algorithm: `calendarEventUid` on the Interview is null ⇒ derive `hrmq-interview-{uuid}` / object URI `{uid}.ics`, probe `getCalendarObjectByUID` (defensive — covers a prior sync that created the event but failed to persist the UID back), absent ⇒ `createCalendarObject`, then persist `calendarEventUid` back onto the Interview via `ObjectService`. `calendarEventUid` already set ⇒ probe `getCalendarObjectByUID` with the stored UID; found ⇒ diff rendered DTSTART/DTEND/SUMMARY/LOCATION against the stored event and `updateCalendarObject` only when they differ (the `veventUnchanged()` no-op-avoids-etag-churn idiom, verbatim from `LeaveCalendarService`); not found (deleted out-of-band) ⇒ re-create and re-persist the same deterministic UID. Running the sync twice in a row with no data change therefore produces zero calendar writes on the second run.

### D4 — Cancellation REMOVES the calendar event; completion leaves it as history

Two terminal states, two different calendar outcomes, mirroring `LeaveCalendarService` D2/D4's precedent of "removal vs. keep as history":

- `annuleren` (`-> cancelled`): the sync **deletes** the calendar object via `deleteCalendarObject` (the D5's brief said "removes/tombstones"; this design resolves that ambiguity as removal, not a visible "CANCELLED" marker event). Rationale: a cancelled interview still shown on the shared team calendar — even relabelled — risks someone showing up to a meeting that no longer exists; deleting it is unambiguous and matches how `LeaveCalendarService` handles a rejected `LeaveRequest` (REQ-LC-005: delete, don't relabel). `calendarEventUid` on the Interview object itself is left as-is (a historical pointer to the now-deleted calendar object — hrmq's own audit trail on the Interview object is the record of what happened, not the calendar).
- `voltooien` (`-> completed`): the event is **kept** — completed history, the mirror of `LeaveCalendarService` D4's "hersteld case keeps its final footprint". No further sync changes it (status is terminal, so the upsert path's `scheduled`-only scope no longer selects it — see D6).
- **Orphan reconciliation** (Interview hard-deleted from the register): same `LeaveCalendarService` D2 pattern — list the configured calendar's objects, delete every `hrmq-interview-*.ics` URI whose embedded uuid is not among the complete live Interview-id set; objects without the `hrmq-interview-` prefix (hand-created by a user) are never touched.

### D5 — AVG boundary: candidate name IS the point, but nothing else Application-sensitive leaves the register

Deliberately different balance from `leave-calendar-nc` D5, and stated as its own decision rather than copied blind: an interview event's entire purpose is telling the panel *who* and *what role*, so excluding the candidate's name (the way leave excludes `leaveType`) would defeat the feature. SUMMARY renders `Sollicitatiegesprek — {candidateName} ({vacancyTitle})`, resolved by loading the referenced `Application` and its `Vacancy` once per sync run (the `buildEmployeeIndex()`/`buildRelatedContext()` idiom). `location` renders into the `LOCATION` ICS property; `interviewers` renders as plain text inside `DESCRIPTION` — **never** as an `ATTENDEE`/`ORGANIZER` property, so the Sabre scheduling plugin never fires iMIP invitation mail to anyone named there (the exact rationale `LeaveCalendarService` D3 used to justify zero ATTENDEE/ORGANIZER). What never reaches any ICS property, from either `Interview` or the referenced `Application`: `email`, `phone`, `cvFile`, `motivation`, `talentPoolOptIn`, `rejectedDate`, `retentionExpiryDate` — none of that Application-level PII is loaded into the render path at all, so there is no property it could leak into. A resolution failure on `applicationId` (deleted/unreadable Application) falls back to `Sollicitatiegesprek — kandidaat` rather than leaking a raw id, mirroring `LeaveCalendarService`'s `— medewerker` fallback.

### D6 — Config: dedicated principal + calendar URI; triggers: occ command AND a guarded manifest action

`SettingsService` gains `getInterviewCalendarPrincipal()` (key `interview_calendar_principal`) and `getInterviewCalendarUri()` (key `interview_calendar_uri`), both defaulting `''`; either empty ⇒ the whole run is `skipped-no-calendar` (REQ-INTV-006). The upsert set is every Interview with `status: scheduled`; `completed`/`cancelled` are handled by D4, not re-upserted. Trigger 1: `occ hrmq:interview:sync [--from DATE]` (`InterviewCalendarSyncCommand`, registered in `appinfo/info.xml` next to `hrmq:calendar:sync`) — `--from` bounds the upsert set to Interviews whose `scheduledStart` is on/after DATE; reconciliation always uses the full live-id set, same `--from` semantics as `leave-calendar-nc` D6. Trigger 2: a guarded manifest action on `InterviewDetail` (`POST /api/interviews/{id}/sync`, the `EmploymentContractDetail` "Genereer arbeidsovereenkomst" `api-call` action shape) backed by `InterviewController::sync()`, gated server-side by the established `isAdminOrHr()` precedent (`PayrollController`/`LoonbeslagController`) so only an admin or HR-group user can push a single interview onto the calendar on demand — an ordinary employee viewing the page cannot trigger a calendar write. Output/exit-code contract for the occ command mirrors `hrmq:calendar:sync` exactly: one outcome line per touched Interview (`created|updated|removed|unchanged|failed|skipped`) plus a summary, exit `0` when nothing `failed` (a fully skipped run is a healthy `0`), `1` otherwise.

### D7 — Manifest surface: no new top-level menu (ADR-001)

`Interviews` (list) and `InterviewDetail` pages nest under the existing `OnboardingAtsGroup` ("Onboarding & ATS") children array, alongside `Onboardings`/`Vacancies`/`Applications`/`Offboardings` — no new top-level menu group, per ADR-001's sub-page-under-an-existing-group rule (the same rule `performance-reviews-mvp`/`goals-okr`/`comp-cycles` cite for anchoring under `EmployeesGroup`). `ApplicationDetail` additionally gains a FK-scoped "Gesprekken" list widget (`Interview.applicationId`, `rowRoute: InterviewDetail`) — the same shape as `VacancyDetail`'s existing "Sollicitaties" related widget resolving `Application.vacancyId` — so a recruiter reaches an application's interview rounds without a separate navigation path. `InterviewDetail` itself carries the data widget (`scheduledStart`/`scheduledEnd`/`interviewers`/`mode`/`location`/`calendarEventUid`, excluding `applicationId` since a Related panel resolves it), `lifecycleActions` exposing exactly `voltooien`/`annuleren` (no invented edges), and the guarded "Sync naar agenda" action from D6.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| VEVENT upsert/remove sync into the CalDAV store | **imperative `InterviewCalendarService`** | **ADR-031 exception: external integration** — the Nextcloud calendar stack is external to OpenRegister; a multi-step duck-typed write into another subsystem's store cannot be a declarative lifecycle action on an hrmq schema (same exception class as `LeaveCalendarService`/`PayrollGLPostService`) |
| Target-calendar configuration | imperative `SettingsService` getters | established config idiom (`leave_calendar_*`, `glpost_account_*`, `netpay_*`) |
| Interview scheduled/completed/cancelled | declarative `x-openregister-lifecycle` on `Interview.status` | a plain three-state workflow with no side effects of its own — the calendar write is a separate, explicitly-triggered pass, not a lifecycle side effect |
| Sync trigger (occ + manifest action) | imperative occ command + guarded controller endpoint | operator/UI-demand sync; no lifecycle hook exists to hang a declarative action on (D6) |
| Rule corpus | **no new rules** | interview scheduling is a recruiting-workflow convenience, **not a statutory concern** — the corpus stays universal law (SCHEMA.md discipline); deliberately stated rather than silently omitted |
| Manifest / navigation | `OnboardingAtsGroup` sub-pages, no new top-level menu | ADR-001 |

### Mixed-spec rationale (kind: code)

`kind: code` is unambiguous here: one new schema, one new PHP service, one occ command, one controller endpoint, config getters, manifest pages/widgets, and unit tests — no cross-app or spec-only deltas riding along.

## Seed Data (ADR-001)

**No new seed file.** The existing `hr-ats.json`/`hr-seed.json` seeds carry `Vacancy`/`Application` fixtures but no `Interview` fixtures yet; this change intentionally ships the schema and services without seeding Interview rows, so a fresh install stays `skipped-no-calendar` (config keys unset) until an operator both configures the calendar keys and schedules a real interview through the UI or API — mirroring `leave-calendar-nc`'s "fragment objects going live on import" caution (`portal-schemas` lesson): no seeded Interview could otherwise spray an event onto anyone's calendar on import. A follow-up change may add one `Interview` fixture (e.g. tied to a seeded `Application` moved to `gesprek`) once seed coverage for the ATS fragment is revisited as a whole.

## Risks / Trade-offs

- **Private-API surface** (D1, inherited from `leave-calendar-nc`) — accepted with probe + tests + recorded-failure degradation; Option B verifiably cannot reach a shared calendar.
- **More code than Option B** — accepted because Option B's `IUserSession`-bound resolution cannot satisfy "team-visible interview", the feature's actual requirement.
- **Calendar edits are overwritten** (one-way projection) — documented on both config-key docblocks and the occ command help, same as `leave-calendar-nc`.
- **Stored `calendarEventUid` can drift from the calendar** if the calendar object is deleted out-of-band without going through the sync (e.g. deleted by hand in the Calendar app) — the next sync's `getCalendarObjectByUID` probe returns not-found and re-creates under the same deterministic UID, so this self-heals rather than silently going stale.
- **"Sollicitatiegesprek — {candidateName}"** on a shared calendar discloses that this named person is a job candidate to whoever can see the calendar — accepted because that fact is the entire operational purpose of the calendar entry (same class of accepted residual disclosure as `leave-calendar-nc` D5's "Afwezig implies sickness").
- **No reschedule history** — only the current slot is retained on the Interview object; hrmq's existing object audit log is the change trail, not a dedicated history field.

## Open Questions

- None blocking. Automatic sync on `uitnodigen` tracks with `hrmq-rule-compliance-enforcement`'s future guard/event wiring; an `Interview` seed fixture is a follow-up alongside broader ATS seed-coverage work.
