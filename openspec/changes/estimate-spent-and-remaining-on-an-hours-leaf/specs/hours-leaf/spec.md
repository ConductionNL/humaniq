# hours-leaf

## ADDED Requirements

### Requirement: An expected effort SHALL be recordable against any host object (REQ-HL-EST-001)

humaniq SHALL provide a `TimeEstimate` schema in register `hrmq` holding the expected
hours for one host object. It SHALL identify the host object the way humaniq already
stores one: `domainObjectType` is the `<app>:<schema>` literal and `domainObjectRef`
is the object's uuid. It SHALL carry `estimatedHours`, an optional `role`, and an
`enforced` flag defaulting to false.

An object MAY carry one estimate per role plus at most one estimate with no role. A
second estimate for the same object and the same role SHALL be refused.

Candidate C-reporting-17 (`reporting.tsv:36`), relevance `could`, driven passers
gitlab, kanboard and request-tracker. GitLab's evidence: `time_estimate` beside
`total_time_spent`, readable in human form both ways. The lane names ledger row
10.13.

#### Scenario: A bezwaar is estimated per role
- **GIVEN** a case estimated at six juridisch hours and two vakafdeling hours
- **WHEN** the estimates are written
- **THEN** both exist against the same object, each naming its role

#### Scenario: One role, one estimate
- **GIVEN** an object already carrying a juridisch estimate
- **WHEN** a second juridisch estimate is written for it
- **THEN** the write is refused

### Requirement: Remaining effort SHALL be derived and never stored (REQ-HL-EST-002)

humaniq SHALL answer remaining effort as estimated hours minus hours booked, computed
on read from the time entries that exist at that moment. Remaining SHALL be
answerable per role and in total. No object SHALL hold a stored remaining figure.

#### Scenario: Deleting an entry moves the remainder at once
- **GIVEN** an object estimated at eight hours with six booked, so two remain
- **WHEN** a two-hour entry is deleted
- **THEN** the next read reports four remaining, with no recomputation job in between

#### Scenario: An overrun reads as a negative remainder, not as zero
- **GIVEN** an unenforced estimate of four hours with six booked
- **WHEN** remaining is read
- **THEN** it reports minus two, so the overrun is visible rather than clipped

### Requirement: The hours surface SHALL show estimated and remaining beside spent (REQ-HL-EST-003)

The KPI tile specified by this capability SHALL show estimated hours and remaining
hours beside the total it already shows. Where an estimate carries a role, the tile
SHALL be able to show the figures per role.

When the host object has no estimate, the tile SHALL show the spent total, SHALL say
that no estimate is set, and SHALL NOT render a remaining figure. A remaining of zero
means the estimate is used up, and an object with no estimate has not used anything
up.

#### Scenario: Three numbers on a case that has an estimate
- **GIVEN** a case estimated at eight hours with three booked
- **WHEN** the leaf renders
- **THEN** the tile shows eight estimated, three spent and five remaining

#### Scenario: No estimate is said, not shown as zero
- **GIVEN** a case with two hours booked and no estimate
- **WHEN** the leaf renders
- **THEN** the tile shows two spent, says no estimate is set, and shows no remaining
  figure

#### Scenario: The timer still owns the tile while it runs
- **GIVEN** a case with an estimate and a running timer
- **WHEN** the leaf renders
- **THEN** the existing running-timer presentation is unchanged and the estimate
  figures do not replace it

### Requirement: An enforced estimate SHALL refuse a booking past its ceiling (REQ-HL-EST-004)

When a `TimeEstimate` carries `enforced` true, humaniq SHALL refuse a new time entry
whose hours would carry the matching total past `estimatedHours`. The refusal SHALL
happen in the write path and SHALL name the estimate, the ceiling and the hours left.
An estimate with `enforced` false SHALL refuse nothing.

Stopping a running timer SHALL always land, even past the ceiling, and the overrun
SHALL be recorded. Time already worked is a fact, and a register that refuses a fact
reports a smaller number than the truth.

Candidate C-reporting-23 (`reporting.tsv:21`), relevance `could`. **No driven
passer.** The evidence is easy-redmine's documented "How to restrict logging more
time than the estimate", admitted under decision D21 and labelled documented here.

#### Scenario: A booking past a capped estimate is refused with the numbers in it
- **GIVEN** an enforced estimate of four hours with three booked
- **WHEN** a two-hour entry is written
- **THEN** the write is refused and the refusal names the estimate, the four-hour
  ceiling and the one hour left

#### Scenario: A ceiling on one role does not block another
- **GIVEN** an enforced juridisch estimate that is used up, and an unenforced
  vakafdeling estimate on the same object
- **WHEN** a vakafdeling entry is written
- **THEN** it is accepted

#### Scenario: A timer stopped over the ceiling records the truth
- **GIVEN** an enforced estimate of four hours and a timer that has run for five
- **WHEN** the timer is stopped
- **THEN** the entry is written with five hours, the remainder reads minus one, and
  nothing is discarded

#### Scenario: The default refuses nothing
- **GIVEN** an estimate written without `enforced`
- **WHEN** any booking is made against the object
- **THEN** it is accepted whatever the total becomes

### Requirement: A consuming app SHALL write the estimate and SHALL NOT hold the model (REQ-HL-EST-005)

A consuming app MAY write a `TimeEstimate` for an object it owns, from its own
policy: a case type's budgeted hours, a project's planned effort. The expected number
belongs to the consuming app. The object, the derived remainder and the enforcement
belong to humaniq. A consuming app SHALL NOT hold its own estimate field and SHALL
NOT query the `hrmq` register to read one.

#### Scenario: A case type's budget becomes an estimate on creation
- **GIVEN** a case type declaring eight budgeted hours
- **WHEN** a case of that type is created
- **THEN** the consuming app writes one `TimeEstimate` against the new case, and
  holds no hours field of its own

#### Scenario: The refusal reads the same wherever the booking is made
- **GIVEN** an enforced estimate that is used up
- **WHEN** a booking is attempted from the leaf and from the consuming app's own
  screen
- **THEN** both receive humaniq's refusal, naming the same ceiling
