# Design — leave-calendar-nc

## Context

**hrmq side (verified at HEAD):** `LeaveRequest` (`lib/Settings/register.d/hr-leave.json`) carries `employeeId`, `leaveType` (enum incl. `sick`, `care`, `parental`), `startDate`/`endDate` (ISO dates, inclusive), `reason`, and `status` enum `draft|submitted|approved|rejected` — there is **no `cancelled` state**; "no longer on the calendar" means any status other than `approved`, or deletion. `SickLeaveCase` (`hr-verzuim.json`) carries `employeeId`, `firstSickDay`, `recoveredDate` (nullable) and `status` enum `gemeld|hersteld`. `Employee` (`hr-objects.json`) carries `firstName`/`lastName` for the event summary. The app resolves OpenRegister's ObjectService duck-typed from the container (`RuleAuditService::objectService()` idiom) and registers occ commands in `appinfo/info.xml` `<commands>`.

**Nextcloud side (verified against the server source at `lib/public/Calendar/` and `apps/dav/lib/CalDAV/`; hrmq declares `<nextcloud min-version="28" max-version="34"/>`):**

- `\OCP\Calendar\IManager::getCalendarsForPrincipal(string $principalUri, array $calendarUris = [])` — since 23.0.0, available on the whole 28–34 range; returns `ICalendar[]`.
- `\OCP\Calendar\ICreateFromString::createFromString(string $name, string $calendarData)` — since 23.0.0 — is the **only public write**, and it is create-only: it routes through an embedded sabre server whose `createFile` path ends in `CalDavBackend::createCalendarObject`, which **throws `BadRequest` when the VEVENT UID already exists in the calendar** (verified, `CalDavBackend.php` ~L1357: explicit duplicate-UID count + throw). `createFromStringMinimal` is `@since 32.0.0` — unusable at min-version 28. There is **no OCP update or delete** for calendar objects anywhere on 28–34.
- `\OCP\Calendar\ICalendarProvider` (OCP flavour) registers virtual calendars for **IManager consumers only**; the Calendar app UI talks CalDAV to the DAV endpoint, which lists only real CalDAV calendars and DAV-integration providers (`OCA\DAV\CalDAV\Integration\ICalendarProvider`, private OCA API). A provider-based read-only projection would therefore not be a user-visible, shareable calendar — it fails the feature's whole point.
- `OCA\DAV\CalDAV\CalDavBackend` (dav app — always shipped with the server) carries the full long-stable surface an idempotent sync needs: `getCalendarByUri($principal, $uri)`, `getCalendarObjectByUID($principalUri, $uid, $calendarUri = null)`, `createCalendarObject`, `updateCalendarObject`, `deleteCalendarObject`, `getCalendarObjects($calendarId)` (returns `uri` per object — no `uid`, which D2 works around with deterministic object URIs).

**Integration pattern precedent:** `PayrollGLPostService` — duck-typed container resolution of another component's service (`mixed`, string class name, try/catch), recorded skip outcome instead of exceptions, occ-command trigger, unit tests against a hand-rolled double so the standalone PHPUnit suite (bare `php:8.3-cli`, `nextcloud/ocp` dev dep only — **no** OCA\DAV classes available) keeps running.

## Goals / Non-Goals

**Goals:** approved leave and sickness absence visible as all-day events on one configured shared Nextcloud calendar; idempotent per source object (re-sync updates, never duplicates); removal when a leave stops being approved; hard AVG boundary (no reason/type/diagnosis on the calendar); graceful `skipped-no-calendar` degradation; operator-triggered (occ), event-listener-free.

**Non-Goals:** two-way sync (the calendar is a projection; manual edits are overwritten), free/busy, iMIP invitations/attendees, per-employee personal calendars, event-driven sync on approval (deferred to the guard/event wiring owned by `hrmq-rule-compliance-enforcement`, the payroll-glpost-shillinq D5 rationale), and a persisted sync-state schema (the sync is stateless and idempotent — outcomes go to command output and the log, not the register).

## Decisions

### D1 — Write through the DAV backend, duck-typed; OCP alone cannot upsert

The investigation result (Context) is decisive: OCP gives discovery + create, never update/delete, and a same-UID re-create throws. An "upsert on re-sync, remove on reject" contract therefore cannot be met with public API on NC 28–34. `LeaveCalendarService` resolves `OCA\DAV\CalDAV\CalDavBackend` from the container by string class name into a `mixed` property (the exact ObjectService idiom), guarded by try/catch + `method_exists` probes. Availability check per sync run: backend resolvable AND `getCalendarByUri(principal, uri)` (both from config, D6) returns a calendar row. Any miss → the run ends `skipped-no-calendar` with a human message; no exception, nothing above INFO in the log. hrmq gains **no** info.xml or composer dependency: the dav app ships with every Nextcloud server, and when it is somehow absent the probe simply skips. The Calendar *app* is only the UI — its absence does not affect the CalDAV store, so "calendar app absent" degrades to "the configured calendar URI does not resolve", the same skip path.

Trade-off, stated honestly: `CalDavBackend` is private OCA API. Mitigations: the five methods used are verified long-stable (they are sabre backend contract methods predating NC 20), every call sits behind the duck-typed probe so an incompatible future signature degrades to a recorded failure rather than a fatal, and the unit tests pin the exact call shapes so a drift surfaces as a test-visible contract change.

### D2 — Deterministic identity: UID **and** object URI carry the source id

Per source object the service derives UID `hrmq-leave-{uuid}` / `hrmq-sick-{uuid}` and object URI `{uid}.ics` (uuid = the OpenRegister object id). Upsert algorithm per in-scope source: `getCalendarObjectByUID(principal, uid)` → not found ⇒ `createCalendarObject(calendarId, uri, ics)`; found ⇒ `updateCalendarObject(calendarId, uri, ics)` (write only when the rendered ICS differs from a cheap parse of the existing DTSTART/DTEND/SUMMARY — avoids etag churn on no-op syncs). Removal for a source that exists but left scope (leave now `rejected`/`draft`/`submitted`): same UID probe, then `deleteCalendarObject`. **Orphan reconciliation** (source hard-deleted from the register): `getCalendarObjects(calendarId)` returns object `uri`s; every `hrmq-leave-*.ics` / `hrmq-sick-*.ics` URI whose embedded uuid is not among the **complete** live source-id sets is deleted. The reconciliation pass always compares against all live ids regardless of `--from` (D6), so a bounded sync can never mis-classify an old event as orphaned. Events created by users by hand (URIs without the `hrmq-` prefix) are never touched.

### D3 — Event shape: all-day VEVENT, hand-built RFC 5545, no scheduling surface

Leave is day-granular (`startDate`/`endDate` are dates), so events are all-day: `DTSTART;VALUE=DATE:{startDate}`, `DTEND;VALUE=DATE:{endDate + 1 day}` (RFC 5545 DTEND is exclusive). The ICS is a hand-built string (BEGIN:VCALENDAR/VEVENT with UID, DTSTAMP, SUMMARY, TRANSP:TRANSP=…, no ATTENDEE/ORGANIZER — so the scheduling plugin never sends mail) with a small text-escaping helper for SUMMARY (`\` `;` `,` newline per RFC 5545 §3.3.11) because employee names are data. Rationale for not using Sabre\VObject: it is available at server runtime but **not** in the standalone unit suite (`composer.json` dev deps carry only `nextcloud/ocp`), and a 10-line escaped template is easier to pin in tests than a builder. `SEQUENCE` stays `0`: with no attendees there is no scheduling agent that consumes it, and the stateless sync has nowhere durable to count from (documented in the service docblock).

### D4 — Sick-case spans: open cases roll, recovered cases close and stay

- `status: gemeld` (open): `DTSTART = firstSickDay`, `DTEND = sync-day + 1` — a rolling "absent through today" bar re-extended by every sync. An open-ended RRULE would misrepresent recovery-in-between and survive stale after recovery; the rolling bar is exactly as true as the data.
- `status: hersteld`: final span `firstSickDay … recoveredDate + 1` (fallback: skip the case with a warning when `recoveredDate` is null — inconsistent data). The event is **kept**, not removed: a recovered case is completed history, the mirror of a past approved leave; deleting it would silently rewrite the team's absence record. This deliberately reads "open SickLeaveCases" from the proposal as "open cases roll; closed cases keep their final footprint" — removal remains reserved for un-approved leave and deleted sources (D2).

### D5 — AVG boundary: fixed-format summaries, nothing else leaves the register

Event content is exactly: SUMMARY `Verlof — {firstName} {lastName}` for LeaveRequests, SUMMARY `Afwezig — {firstName} {lastName}` for SickLeaveCases, plus dates. **Never** rendered into any ICS property: `reason`, `rejectionReason`, `leaveType` (its enum includes `sick`/`care`/`parental` — health-adjacent categories; a shared calendar must not disclose *which kind* of leave), any WvP/case field, or a DESCRIPTION at all. Residual inference risk, stated honestly: "Afwezig" vs "Verlof" lets a reader infer sickness-absence *as a fact*. That fact is operationally necessary on a team calendar (coverage planning) and is what every NL HR suite shows; the AVG-critical *health data* (nature, reason, diagnosis, WvP progress) never leaves the register. The employee-name resolution reads Employees once per run into an id/slug-keyed map (the `buildRelatedContext()` idiom); a source whose employee cannot be resolved syncs with summary `Verlof — medewerker` / `Afwezig — medewerker` rather than leaking an id.

### D6 — Config: principal + calendar URI; trigger: occ command

`SettingsService` gains `getLeaveCalendarPrincipal()` (key `leave_calendar_principal`, e.g. `principals/users/hr`) and `getLeaveCalendarUri()` (key `leave_calendar_uri` — the calendar URI on that principal, as shown in the Calendar app's link/edit dialog). Both default `''`; either empty ⇒ the whole run is `skipped-no-calendar`. The getter docblocks document the keys, that the calendar should be one the HR account shares with the team, and the one-way-projection caveat (D3/Non-Goals). Trigger is `occ hrmq:calendar:sync [--from DATE]` (`CalendarSyncCommand`, registered in `appinfo/info.xml` like `hrmq:glpost:run`): `--from` bounds the **upsert** set to sources whose absence period ends on/after DATE (default: unbounded, capped by the ObjectService load limit); reconciliation always uses the full live-id set (D2). Output: one line per touched source (`created|updated|removed|unchanged|failed|skipped`) + a summary; exit `0` when nothing `failed` (a fully skipped run is a healthy `0` — mirrors `skipped-no-shillinq`), `1` otherwise. No corpus rule and no lifecycle hook ship with this change: calendar visibility is not a statutory concern (see ADR-031 table) and LeaveRequest transitions have no hrmq-owned event wiring yet.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| VEVENT upsert/remove sync into the CalDAV store | **imperative `LeaveCalendarService`** | **ADR-031 exception: external integration** — the Nextcloud calendar stack is external to OpenRegister; a multi-step duck-typed write into another subsystem's store cannot be a declarative lifecycle action on an hrmq schema (same exception class as `PayrollGLPostService`) |
| Target-calendar configuration | imperative `SettingsService` getters | established config idiom (`glpost_account_*`, `netpay_*`) |
| Trigger | imperative occ command (D6) | operator-demand sync; no lifecycle exists on LeaveRequest to hang a declarative action on |
| Rule corpus | **no new rules** | shared-calendar visibility is a workplace convenience, **not a statutory concern** — the corpus stays universal law (SCHEMA.md discipline); deliberately stated rather than silently omitted |
| Schemas / manifest / seeds | untouched | the sync only reads LeaveRequest/SickLeaveCase/Employee; no new pages (the calendar IS the surface) |

### Mixed-spec rationale (kind: code)

`kind: code` is unambiguous here: the change is one PHP service + one occ command + config getters + unit tests, with zero schema/manifest/corpus deltas riding along.

## Seed Data (ADR-001)

**No new seeds.** The sync consumes what `hr-seed.json` already ships, and the design records exactly what that produces on a configured calendar:

- `leave-jansen-zomer` (2026-08-03…14) is seeded `status: submitted` — **zero leave events out of the box**; approving it (its normal lifecycle) and re-running `occ hrmq:calendar:sync` creates `hrmq-leave-{uuid}` spanning 03–14 Aug (DTEND 2026-08-15, exclusive). This is the manual verification path in tasks.md.
- `sickcase-devries-week7` (gemeld, first sick day 2026-05-25) and `sickcase-bakker-longterm` (gemeld, 2025-09-29) → two rolling `Afwezig — {naam}` bars through the sync day (D4).
- `sickcase-jansen-flu` (hersteld, 2026-05-04…recovered 2026-05-08) → one closed `Afwezig` span (DTEND 2026-05-09).

Config keys ship unset, so a fresh install stays `skipped-no-calendar` until an operator points the app at a real shared calendar — fragment objects going live on import (portal-schemas lesson) therefore cannot spray events onto anyone's calendar.

## Risks / Trade-offs

- **Private-API surface** (D1) — accepted with probe + tests + recorded-failure degradation; the alternative (OCP-only) verifiably cannot implement the feature.
- **Rolling open-sick events go stale without syncs** — an open case's bar ends at the *last* sync day. Documented on the command; a cron wrapper (plain `occ` invocation) is the operator's call, not shipped automation.
- **Calendar edits are overwritten** (one-way projection) — documented on both config-key docblocks and in the command help.
- **`Afwezig` implies sickness** — inference risk accepted and bounded (D5): the fact of absence is the feature; the health data never leaves the register.
- **Name resolution fallback** (`— medewerker`) trades calendar usefulness for never leaking raw ids/slugs on the shared surface.

## Open Questions

- None blocking. Event-driven sync on approval tracks with `hrmq-rule-compliance-enforcement`; per-employee personal calendars and free/busy are follow-up specs.
