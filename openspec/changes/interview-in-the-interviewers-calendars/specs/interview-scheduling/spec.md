# interview-scheduling

## ADDED Requirements

### Requirement: An interview SHALL be a calendar event with the interviewers as attendees (REQ-INTV-010)

`interviewers` on an `Interview` SHALL hold Nextcloud user ids. The synced event SHALL name the
configured interview calendar's principal as ORGANIZER and every interviewer as an ATTENDEE, so
Nextcloud's calendar scheduling delivers the interview to each interviewer's own calendar.
Moving the interview SHALL update their copies, and cancelling it SHALL cancel them. The
candidate SHALL never be an attendee, and the personal data boundary of REQ-INTV-005 stays.
This replaces the REQ-INTV-005 rule that no event carries an ATTENDEE or ORGANIZER.

Rows: `hir-interview-scheduling` (humaniq matrix).

#### Scenario: Each interviewer gets the interview in their own calendar
- **GIVEN** a scheduled Interview with interviewers `els` and `jan`
- **WHEN** the sync runs
- **THEN** the event carries an ATTENDEE for `els` and one for `jan`
- **AND** the interview shows in the personal calendar of both users

@e2e exclude Delivery into another user's calendar runs in the dav app's scheduling plugin on a live instance, which the e2e instance does not set up with mail addresses for its users. The ATTENDEE lines and the scheduling-aware create are asserted in tests/Unit/Service/InterviewAttendeesTest.php and tests/Unit/Service/InterviewSchedulingSyncTest.php.

#### Scenario: Moving the interview moves it for every interviewer
- **GIVEN** the Interview above has reached both calendars
- **WHEN** HR changes `scheduledStart` and the sync runs again
- **THEN** both interviewers' calendars show the new time and neither shows the old one

@e2e exclude Same live-scheduling dependency as above. tests/Unit/Service/InterviewSchedulingSyncTest.php asserts the stored copy is replaced through the scheduling-aware calendar with the new time and a higher SEQUENCE, and that a removed interviewer gets a cancellation.

#### Scenario: Cancelling the interview cancels it for every interviewer
- **GIVEN** the Interview above has reached both calendars
- **WHEN** it is cancelled and the sync runs
- **THEN** the interview is cancelled in both interviewers' calendars

@e2e exclude Same live-scheduling dependency as above. tests/Unit/Service/InterviewSchedulingSyncTest.php asserts a STATUS:CANCELLED copy is sent to every attendee and the event is then removed.

#### Scenario: An interviewer who is not a Nextcloud user is refused
- **WHEN** HR saves an Interview whose interviewers name a user id that does not exist
- **THEN** the save is refused with a message naming that interviewer

@e2e exclude The refusal is a server-side save listener with no page of its own; tests/Unit/Listener/InterviewInterviewersListenerTest.php asserts the refusal and the message naming the interviewer.

#### Scenario: The candidate is never an attendee
- **GIVEN** a scheduled Interview with interviewers `els` and `jan` for a candidate with an email address
- **WHEN** its event is rendered
- **THEN** the ICS has no ATTENDEE for the candidate's email address and still carries none of the Application's personal fields

@e2e exclude A property of the rendered ICS text, not of a page; tests/Unit/Service/InterviewAttendeesTest.php asserts no candidate address and none of the Application's fields reach the event.
