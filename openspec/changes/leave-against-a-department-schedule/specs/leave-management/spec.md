# leave-management

## ADDED Requirements

### Requirement: A leave type SHALL be an administered object, not an enum (REQ-LVM-T01)

humaniq SHALL provide a `LeaveType` schema in register `hrmq` carrying a `code`, a
label, `drawsFromBalance`, `requiresReason`, `requiresDocument`, an optional
`maxNoticeDays` and an `active` flag. `LeaveRequest.leaveType` SHALL reference a
`LeaveType` rather than carry an enum value, and an existing request SHALL resolve to
the type whose `code` equals the value it already holds, so no request loses its
meaning.

A type with `active` false SHALL NOT be offered on a new request and SHALL still
resolve on requests that already carry it.

Candidate C-parties-and-contacts-11 (`parties-and-contacts.tsv:15`). The lane author
rated it **`not`, reading dossiq**, with the note "this is humaniq's job, not the
case system's". It is admitted here under decision D17 and labelled under decision
D21. Driven passer: huly, `plugins/hr` `RequestType` and `Request`.

#### Scenario: A new leave kind is added without a release
- **GIVEN** an employer that needs "zorgverlof"
- **WHEN** an administrator writes a `LeaveType` with that code
- **THEN** it is offered on new requests, and no schema was changed to allow it

#### Scenario: A type that draws no balance posts nothing
- **GIVEN** a `LeaveType` with `drawsFromBalance` false
- **WHEN** a request of that type is approved
- **THEN** the `LeaveBalance` behind it is unchanged

#### Scenario: A retired type keeps old requests readable
- **GIVEN** approved requests carrying a type later set to `active` false
- **WHEN** those requests are read
- **THEN** they still resolve their type and its label, and the type is absent from
  the picker on a new request

### Requirement: A type's own conditions SHALL be enforced on submission (REQ-LVM-T02)

Submitting a `LeaveRequest` SHALL be refused when its type requires a reason and none
is given, when its type requires a document and none is attached, or when its type
declares `maxNoticeDays` and the request starts further ahead than that. The refusal
SHALL name the condition that failed.

These conditions SHALL be checked on `submit` only. A request may be saved as a draft
without them.

#### Scenario: A calamiteitenverlof without a reason does not submit
- **GIVEN** a type with `requiresReason` true and a request with an empty reason
- **WHEN** the request is submitted
- **THEN** the transition is refused and the refusal names the missing reason

#### Scenario: A draft is not judged yet
- **GIVEN** the same request left in `draft`
- **WHEN** it is saved
- **THEN** it is stored with no refusal

### Requirement: An org unit's leave SHALL be readable as one schedule over a period (REQ-LVM-S01)

humaniq SHALL answer, for one org unit and a date range, every `LeaveRequest` in
status `submitted` or `approved` that holds a member of that unit, composed on read
from the requests themselves. No schedule object SHALL be stored.

An entry SHALL carry the employee, the period, the status and the type. A reader who
may not see a given request SHALL see that the person is unavailable in that period
and nothing further, matching the boundary `leave-calendar-nc` draws for the
Nextcloud calendar. Which requests a reader may see SHALL be decided by the existing
team-scope rules, unchanged.

#### Scenario: August is read in one view
- **GIVEN** an afdeling of six with four approved and two submitted requests in
  August
- **WHEN** the schedule is asked for that unit and that month
- **THEN** all six appear with their periods and statuses

#### Scenario: A withdrawn request leaves the schedule at once
- **GIVEN** a submitted request showing on the schedule
- **WHEN** it is withdrawn
- **THEN** the next read does not contain it, with no sync in between

#### Scenario: A manager does not see another department through it
- **GIVEN** a manager scoped to one org unit
- **WHEN** they open the schedule for a unit they are not scoped to
- **THEN** they receive no request detail, and the existing team-scope rules decide
  it rather than a rule of this view's own

### Requirement: Thin coverage SHALL warn the approver, and SHALL NOT refuse (REQ-LVM-S02)

An org unit MAY carry an administered minimum number of members present, per weekday.
When approving a request would take the unit below that minimum on any date in its
period, humaniq SHALL warn the approver before the transition, naming the dates, the
number present after approval, the minimum, and who else is away on those dates.

The approver SHALL be able to approve regardless. When they do, the warning they
accepted SHALL be recorded on the request, so the decision has a trail. Approval
SHALL NOT be refused on coverage grounds.

#### Scenario: The first week of August is said before it happens
- **GIVEN** an afdeling with a Monday minimum of two, one member already away, and a
  pending request covering that Monday
- **WHEN** the approver opens the approval
- **THEN** they are shown the date, one present against a minimum of two, and the
  name of the colleague already away

#### Scenario: The manager who knows better is not blocked
- **GIVEN** that warning
- **WHEN** the approver approves anyway
- **THEN** the request reaches `approved` and the accepted warning is recorded on it

#### Scenario: No minimum means no warning
- **GIVEN** an org unit with no administered minimum
- **WHEN** any request for it is approved
- **THEN** no coverage warning is produced

### Requirement: Approving from the schedule SHALL drive the existing transitions (REQ-LVM-S03)

The schedule view SHALL offer approve and reject, and SHALL call the transitions
already declared for `LeaveRequest`: `approve` and `reject` from `submitted`, guarded
by the existing `NoSelfApprovalGuard`. No transition SHALL be added, renamed or
re-guarded to serve this view, and the `LeaveApproval` queue SHALL keep working
unchanged beside it.

#### Scenario: Two surfaces, one lifecycle
- **WHEN** the manifest's `lifecycleActions.transitions` are compared to the
  `x-openregister-lifecycle` in the leave register fragment
- **THEN** they match action-for-action, with no action added for the schedule

#### Scenario: The self-approval guard still holds on the new surface
- **GIVEN** an approver looking at their own request on the schedule
- **WHEN** they attempt to approve it
- **THEN** the existing guard refuses it, exactly as it does from the queue
