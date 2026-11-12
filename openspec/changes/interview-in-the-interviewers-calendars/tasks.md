## 1. Build

- [ ] 1.1 `interviewers` on `Interview` in `lib/Settings/register.d/hr-ats.json` becomes a list of Nextcloud user ids, checked on save (REQ-INTV-010).
- [ ] 1.2 `lib/Service/InterviewCalendarService.php` writes ORGANIZER and one ATTENDEE per interviewer, and never the candidate (REQ-INTV-010, REQ-INTV-005).
- [ ] 1.3 Reschedule and cancel in `lib/Service/InterviewSyncEngine.php` reach the attendees' copies (REQ-INTV-010).
- [ ] 1.4 A user picker for interviewers on InterviewDetail in `src/manifest.d/hr-ats.json`.
- [ ] 1.5 Unit tests in `tests/Unit/Service/` for the attendee rendering and the PII boundary.

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
- [ ] 2.2 When archiving, drop the no ATTENDEE or ORGANIZER rule and its scenario from REQ-INTV-005 in `openspec/specs/interview-scheduling/spec.md`; its headings are not `Requirement:` headers, so a MODIFIED delta cannot reach them.
