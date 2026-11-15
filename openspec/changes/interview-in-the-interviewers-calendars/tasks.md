## 1. Build

- [x] 1.1 `interviewers` on `Interview` in `lib/Settings/register.d/hr-ats.json` becomes a list of Nextcloud user ids, checked on save (REQ-INTV-010).
  > `Interview.interviewers` is an array of user ids (`items.referenceType: nextcloud-user`, Interview 0.2.0); `InterviewInterviewersListener` refuses free text and any id that is not a Nextcloud user, naming it, on create and update.
- [x] 1.2 `lib/Service/InterviewCalendarService.php` writes ORGANIZER and one ATTENDEE per interviewer, and never the candidate (REQ-INTV-010, REQ-INTV-005).
  > `InterviewAttendees` + `InterviewIcsRenderer`: ORGANIZER is the interview calendar owner's address, one ATTENDEE per interviewer with an email address; only Nextcloud users are ever named, the Application is not read. A changed attendee list counts as a change for the sync.
- [x] 1.3 Reschedule and cancel in `lib/Service/InterviewSyncEngine.php` reach the attendees' copies (REQ-INTV-010).
  > An event with attendees is written through `SchedulingCalendarWriter` (Nextcloud's `ICreateFromString::createFromString`, which runs the CalDAV scheduling plugin) instead of the raw `CalDavBackend`. That API refuses a UID it already holds, so a reschedule removes the stored copy through the backend (which sends nothing), sends a cancellation to any interviewer taken off the event, and sends the new version with a higher SEQUENCE; a cancel sends a STATUS:CANCELLED copy to every attendee and then removes it. A failed step puts the stored copy back so the next sync retries. Without the calendar manager, or for an event without attendees, the backend path is unchanged. Unit tests: `tests/Unit/Service/SchedulingCalendarWriterTest.php`, `tests/Unit/Service/InterviewSchedulingSyncTest.php`. Still to confirm on a live instance that the interviewers' calendars show, move and drop the event.
- [x] 1.4 A user picker for interviewers on InterviewDetail in `src/manifest.d/hr-ats.json`.
  > The picker comes from the schema: an array whose items declare `referenceType: nextcloud-user` renders as a multi user picker in the detail form, so `src/manifest.d/hr-ats.json` needs no change.
- [x] 1.5 Unit tests in `tests/Unit/Service/` for the attendee rendering and the PII boundary.
  > `tests/Unit/Service/InterviewAttendeesTest.php` (attendees, organiser, the rendered lines, no candidate, change detection, legacy text) and `tests/Unit/Listener/InterviewInterviewersListenerTest.php`.

## 2. Hand-over

- [x] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
  > `@spec` tags on the new and changed methods; each scenario carries an `@e2e exclude` naming the unit test that covers it, because delivery into another user's calendar needs live scheduling the e2e instance does not set up.
- [ ] 2.2 When archiving, drop the no ATTENDEE or ORGANIZER rule and its scenario from REQ-INTV-005 in `openspec/specs/interview-scheduling/spec.md`; its headings are not `Requirement:` headers, so a MODIFIED delta cannot reach them.
