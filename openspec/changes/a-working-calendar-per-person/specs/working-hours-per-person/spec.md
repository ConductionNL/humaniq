# working-hours-per-person

## ADDED Requirements

### Requirement: An employee SHALL carry a dated working pattern (REQ-WHP-001)

humaniq SHALL provide a `WorkingPattern` schema in register `hrmq` holding, for one
employee, the contracted hours for each weekday, with a `validFrom` and an optional
`validUntil`. Hours SHALL be stored per weekday rather than as a full-time fraction,
because two people on the same fraction can work entirely different days.

A pattern SHALL NOT be edited to record a contract change. A change SHALL be a new
pattern, so a figure computed over a past period still divides by the contract that
was in force then. Two patterns for one employee whose periods overlap SHALL be
refused.

Candidate C-deadlines-3 (`deadlines.tsv:14`), relevance `should`, driven passer
openproject: Administration, Users, `resources :working_hours` and
`resources :non_working_times` under `/users/:id`, with `working_days_preview`.

#### Scenario: A part-timer's days are recorded, not their fraction
@e2e tests/e2e/spec-coverage/working-hours-per-person.spec.ts
- **GIVEN** an employee working eight hours on Monday, Tuesday and Wednesday and
  nothing on Thursday or Friday
- **WHEN** the pattern is read
- **THEN** it reports eight, eight, eight, zero and zero, and no full-time fraction
  is stored on it

#### Scenario: Last quarter still divides by last quarter's contract
@e2e exclude dated resolution over a past period, covered by WorkingHoursServiceTest::testAPastPeriodDividesByThePatternInForceThen
- **GIVEN** an employee on 24 hours until 1 July and 32 hours after it
- **WHEN** a capacity figure is computed over June
- **THEN** it divides by 24, and the figure does not move when the July pattern is
  written

#### Scenario: Two answers for one Tuesday are refused
@e2e tests/e2e/spec-coverage/working-hours-per-person.spec.ts (the write path), WorkingHoursServiceTest::testOverlappingPatternsAreRefused (the refusal)
- **GIVEN** an employee with a pattern valid from 1 January with no end
- **WHEN** a second pattern valid from 1 March is written without first ending the
  running one
- **THEN** the write is refused

### Requirement: An employee SHALL carry non-working times that are neither leave nor sickness (REQ-WHP-002)

humaniq SHALL provide a `NonWorkingTime` schema in register `hrmq` recording a period
or a recurring weekday on which one employee does not work, with a reason drawn from
an administered list. A non-working time SHALL NOT draw down any leave balance and
SHALL NOT open a sick-leave case.

#### Scenario: A standing free Wednesday costs no leave
@e2e tests/e2e/spec-coverage/working-hours-per-person.spec.ts
- **GIVEN** an employee with a recurring non-working Wednesday
- **WHEN** a Wednesday passes
- **THEN** the leave balance is unchanged and no leave request exists for it

#### Scenario: A re-integration schedule is recorded without becoming verlof
@e2e exclude part-day arithmetic, covered by WorkingHoursServiceTest::testAPartDayNonWorkingTimeSubtractsInsideItsWindow
- **GIVEN** an employee working reduced afternoons during re-integration
- **WHEN** the reduced hours are recorded as non-working time
- **THEN** they appear in the working-hours answer and nowhere in
  `leave-management`

### Requirement: One query SHALL answer how many hours a person works on a date (REQ-WHP-003)

humaniq SHALL expose one resolution point answering the contracted hours for one
employee on one date, and the same over a range. It SHALL apply, in order: the
`WorkingPattern` in force on that date, the employee's `NonWorkingTime` entries, and
the working calendar published by openregister.

Every humaniq figure that needs a denominator of contracted hours SHALL use this
query, so an absence percentage and a capacity percentage cannot disagree about the
same person in the same week.

#### Scenario: A national holiday on a working day answers zero
@e2e exclude calendar application, covered by WorkingHoursServiceTest::testAFeestdagOnAContractedDayAnswersZero
- **GIVEN** an employee contracted for eight hours on Monday and an openregister
  working calendar marking second Whitsun as non-working
- **WHEN** the hours for that Monday are asked
- **THEN** the answer is zero

#### Scenario: A range sums what the pattern and the calendar leave
@e2e exclude range arithmetic, covered by WorkingHoursServiceTest::testARangeSumsWhatThePatternAndCalendarLeave
- **GIVEN** an employee on 24 contracted hours a week and one feestdag in the week
  asked about
- **WHEN** the range is resolved
- **THEN** the answer is 24 minus that day's contracted hours

### Requirement: A missing working calendar SHALL be said, not guessed (REQ-WHP-004)

humaniq SHALL resolve openregister's working calendar duck-typed and
container-resolved behind a `class_exists()` guard, the way `hours-leaf` resolves
`ObjectService` and `leave-calendar-nc` resolves `CalDavBackend`. When the calendar
cannot be resolved, the answer SHALL be marked as pattern-only and a degradation
SHALL be recorded. humaniq SHALL NOT substitute a calendar of its own, and SHALL NOT
return a full working day for a date it cannot check.

#### Scenario: An instance without openregister still answers, and says how
@e2e exclude degradation path needs openregister ABSENT, which the e2e instance cannot be; covered by WorkingCalendarReaderTest and WorkingHoursServiceTest::testAnAnswerWithoutACalendarIsMarkedPatternOnly
- **GIVEN** openregister is not installed
- **WHEN** the hours for a Monday are asked
- **THEN** the answer comes from the pattern alone, is marked pattern-only, and a
  degradation is recorded

#### Scenario: humaniq never defines a feestdag
@e2e tests/e2e/spec-coverage/working-hours-per-person.spec.ts
- **WHEN** the working calendar is searched for in humaniq's own register
- **THEN** no humaniq schema holds a national holiday, and the only source is
  openregister's calendar

### Requirement: humaniq SHALL NOT own the calendar a term is counted against (REQ-WHP-005)

The working calendar a termijn is counted against, a second working calendar chosen
per record type, a freeze period in which work of a kind may not be scheduled, and
the choice of which week is week one all belong to openregister, under decision D19.
humaniq SHALL read the first and SHALL NOT hold any of the four.

#### Scenario: A consuming app counting a termijn does not read humaniq
@e2e exclude cross-app absence of a call, which no page in this repo can observe; the split is asserted in the register by tests/e2e/spec-coverage/working-hours-per-person.spec.ts
- **WHEN** a consuming app resolves the working days of a statutory term
- **THEN** it reads openregister's working calendar, and makes no call to humaniq

#### Scenario: The split holds in the register
@e2e tests/e2e/spec-coverage/working-hours-per-person.spec.ts
- **WHEN** humaniq's register fragments are read
- **THEN** they carry a working pattern and non-working times for people, and no
  organisation-level calendar, freeze period or week-numbering setting
