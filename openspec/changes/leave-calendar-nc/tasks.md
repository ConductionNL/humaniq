# Tasks — leave-calendar-nc

- [ ] 1. Config: add `getLeaveCalendarPrincipal()` / `getLeaveCalendarUri()` to `lib/Service/SettingsService.php` (keys `leave_calendar_principal` / `leave_calendar_uri`, empty defaults; docblocks document the keys, the shared-calendar expectation, and the one-way-projection caveat) per REQ-LC-001
- [ ] 2. Service: scaffold `lib/Service/LeaveCalendarService.php` with the duck-typed `CalDavBackend` resolution (string class name, `mixed`, try/catch + `method_exists` probes) and the per-run availability check (`getCalendarByUri` on the configured principal/URI) → `skipped-no-calendar` per REQ-LC-006
- [ ] 3. Service: ICS rendering — hand-built RFC 5545 VCALENDAR/VEVENT (all-day DATE values, exclusive DTEND = end + 1 day, DTSTAMP, SEQUENCE 0, no ATTENDEE/ORGANIZER/DESCRIPTION) + the §3.3.11 text-escaping helper for SUMMARY per REQ-LC-002/REQ-LC-004 (design.md D3)
- [ ] 4. Service: employee-name map (id/slug-keyed, loaded once per run via ObjectService) with the `— medewerker` fallback per REQ-LC-004 (design.md D5)
- [ ] 5. Service: leave upsert — approved LeaveRequests → UID/URI `hrmq-leave-{uuid}(.ics)`, `getCalendarObjectByUID` probe → create or update, no-op skip when the rendered event is unchanged per REQ-LC-002 (design.md D2)
- [ ] 6. Service: sick upsert — UID `hrmq-sick-{uuid}`, `gemeld` rolling DTEND = sync day + 1, `hersteld` closed at recoveredDate + 1 and kept, null-recoveredDate warning-skip per REQ-LC-003 (design.md D4)
- [ ] 7. Service: removal + orphan reconciliation — delete events of non-approved leaves; list calendar objects and delete `hrmq-leave-*`/`hrmq-sick-*` URIs with no live source (full live-id sets, independent of `--from`); never touch non-`hrmq-` URIs per REQ-LC-005
- [ ] 8. Command: `lib/Command/CalendarSyncCommand.php` (`hrmq:calendar:sync [--from DATE]`, per-source outcome lines + summary, exit 0/1) + register in `appinfo/info.xml` `<commands>` per REQ-LC-007
- [ ] 9. Unit tests: `tests/Unit/Service/LeaveCalendarServiceTest.php` with a hand-rolled backend double (no OCA\DAV dev dep) — ICS shape + escaping, UID/URI determinism, upsert vs update vs unchanged decisions, rolling/closed sick spans, removal + orphan reconciliation, privacy invariants (no reason/leaveType/DESCRIPTION/ATTENDEE in any rendered ICS), skip paths (bootstrap per `tests/bootstrap.php`)
- [ ] 10. Quality gates: `composer check:strict` green; SPDX + `@spec` tags on every new/changed PHP method (gate-16)
- [ ] 11. Live verify in the dev container: create a shared calendar, set both config keys, run `occ hrmq:calendar:sync` against the seeded data — expect two rolling + one closed `Afwezig` event and zero leave events; approve `leave-jansen-zomer`, re-sync → `Verlof — Sam Jansen` 03–14 Aug appears; reject it, re-sync → event removed; unset config, re-sync → `skipped-no-calendar` exit 0 (design.md Seed Data)

Acceptance criteria (plain reminders, not tasks):
- no OCP-only write path pretending to upsert — the design's D1 investigation is binding
- no event ever carries reason, leaveType, diagnosis, DESCRIPTION, or ATTENDEE/ORGANIZER
- running the sync twice in a row changes nothing on the second run
- no info.xml/composer dependency on calendar/dav; i18n keys ENGLISH per ADR-007
