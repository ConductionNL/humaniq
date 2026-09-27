# Design: announcements and policies for employees, and a daily team message in Talk

## Context

Read at `development` af702f78.

- No humaniq schema carries announcements or policies. `MijnHr`
  (`src/manifest.d/personal-dashboard.json:4`) is the employee's landing dashboard with widgets
  filtered on `userId: "@me"`.
- Audience: `OrgAssignment` places employees in `OrgUnit`s; `OrgResolutionService` resolves the
  units live on a date.
- Flows: humaniq contributes nodes through `lib/Flow/HumaniqFlowNodeListener.php` on
  OpenRegister's `RegisterFlowNodesEvent` (the payroll nodes, `payroll-run-as-a-flow`).
  OpenRegister ships `openregister.trigger-schedule` and `openregister.send-talk-message`
  (`openregister/lib/Service/Flow/Nodes/SendTalkMessageNode.php`), which posts as the flow's acting
  user and never joins that user into a conversation.
- Leave and sickness: approved `LeaveRequest` (`startDate`, `endDate`) and open `SickLeaveCase`.
  `leave-calendar-nc` keeps the AVG boundary: no leave type or reason leaves humaniq. `Employee`
  has `dateOfBirth`, a field `compliance-roles-and-field-access` restricts.
- `HrDocumentService` and the files widget attach documents to objects.

## Goals / Non-Goals

**Goals**

- HR reaches the right employees and knows who read a policy.
- The team message says who is away and whose birthday it is, and nothing more.

**Non-Goals**

- A newsletter editor. The text field is plain rich text.

## Decisions

### D1. Announcement schema

`Announcement`: `title`, `body`, `policyFile` (file, nullable), `audience` (`administration` or
`orgUnits`), `orgUnitIds`, `publishFrom`, `publishUntil`, `requiresConfirmation`, `status` with
lifecycle `publiceren`, `intrekken`, `administrationId`. `AnnouncementConfirmation`:
`announcementId`, `employeeId`, `userId`, `confirmedAt`. A confirmation is created by the
employee from `MijnMededelingen`; one per employee per announcement (pre-save listener refuses a
second).

### D2. Who sees an announcement

`MijnMededelingen` and the `MijnHr` widget read through
`GET /api/announcements/mine`, which returns published announcements in period whose audience
contains the caller's live units, with the caller's confirmation state. A declarative filter
cannot express "one of my units", which is why this is one small endpoint.

### D3. The digest is a flow

"Dagbericht": `openregister.trigger-schedule` on working days at a set time, then
`humaniq.team-digest` (config: `orgUnitId`, `includeChildren`) building a message from approved
leave covering today (name and last day), open sickness (name only, "afwezig") and birthdays
today of employees with `shareBirthday` true, then `openregister.send-talk-message` to the
configured conversation. The flow arrives disabled; adopting it means choosing the unit, the
conversation and a service account that is a participant of it.

### D4. Birthdays by consent

`Employee.shareBirthday` (default false), settable by the employee on `MijnGegevens` from
`people-record-change-approval`. The node reads the day and month through an internal read and
never puts the year in the message.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| announcement lifecycle | declarative `x-openregister-lifecycle` | a state machine |
| audience match | one imperative endpoint | unit membership is not a manifest filter |
| confirmation uniqueness | pre-save listener | a write-time rule |
| the daily message | declared flow with one humaniq node | the engine's scheduling and Talk nodes |

## Seed data

- A published announcement "Nieuwe declaratieregeling 2027" to the whole administration with a
  placeholder policy file and `requiresConfirmation`, confirmed by one seed employee.
- One seed employee with `shareBirthday` true.

## Risks / Trade-offs

- [Absence in a chat channel is personal data] → only name and return date, never type or
  reason; sickness shows as "away" like leave, so the message cannot tell them apart.
- [A flow posting as a user who left] → the Talk node fails the step with a reason; the flow run
  shows it.

## Open Questions

- Should an announcement also be pushed as a notification? Left to a notification rule.
