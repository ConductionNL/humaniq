---
kind: code
---

# Put an interview in the interviewers' calendars

## Why

Ruben decided on 12 Nov (decision 390) that an interview is a calendar event with the
interviewers as attendees. The shipped spec does the opposite: REQ-INTV-005 writes the
interviewers as plain text into one shared calendar and forbids ATTENDEE and ORGANIZER, so no
interviewer sees the interview in their own calendar.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `hir-interview-scheduling` | the interview reaches each interviewer's own calendar as an invitation |

## What changes

- `interviewers` on `Interview` names Nextcloud users, not free text.
- The synced event carries the configured shared calendar as organiser and each interviewer as
  an attendee, so Nextcloud's calendar scheduling puts it in their calendars and keeps it in step
  when the interview moves or is cancelled.
- The candidate and the Application's personal data still never reach the event.

## Impact

- `lib/Settings/register.d/hr-ats.json`, `lib/Service/InterviewCalendarService.php`,
  `lib/Service/InterviewSyncEngine.php`, the InterviewDetail page in `src/manifest.d/hr-ats.json`.
- Not built yet: the matrix row moves to `specified` with this change as its plan.
