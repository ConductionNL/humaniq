# training-and-lms-sync

## ADDED Requirements

### Requirement: HR SHALL plan training per employee and register attendance (REQ-TRN-001)

humaniq SHALL provide a `TrainingRecord` per employee and training with its title,
provider, planned date, status `gepland`, `gevolgd` or `niet-gevolgd`, completion date,
validity, cost, study-cost agreement and source. `EmployeeDetail` SHALL list an employee's
records, and a `Trainingen` index SHALL let HR register attendance for several employees at
once. A record that reaches `gevolgd` with a competence code SHALL create or extend that
`EmployeeCompetence` and SHALL never shorten one.

Rows: `tal-training` (humaniq matrix).

#### Scenario: An HR adviser registers who came to the first-aid course
- **GIVEN** planned BHV refresher records for three employees, competence code `bhv`, valid
  one year
- **WHEN** an HR adviser marks two as `gevolgd` and one as `niet-gevolgd` on `Trainingen`
- **THEN** the two have a current `bhv` competence valid for a year, the third has none, and
  each personnel file lists the training with its status

#### Scenario: A study-cost agreement is on file
- **GIVEN** an employee starting a paid post-graduate course
- **WHEN** HR records it with a cost of 2,400 euros and a study-cost agreement
- **THEN** `TrainingRecordDetail` shows the cost, the agreement and its repayment terms

### Requirement: humaniq SHALL publish one minimal people feed for learning platforms (REQ-TRN-002)

`GET /api/learning/people` SHALL return active employees with their name, Nextcloud account,
current org unit and role, managers and employment dates, and SHALL return no other employee
field. It SHALL accept `modifiedSince` and return only employees whose record, placement or
unit changed after it. It SHALL return only employees the caller may read.

Rows: `td-lms-sync` (humaniq matrix), tender https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: A move to another team reaches the learning platform
- **GIVEN** an employee moved from Finance to HR yesterday
- **WHEN** the learning platform's account calls the feed with `modifiedSince` two days ago
- **THEN** the employee is returned with org unit HR and HR's manager, and without BSN or
  salary

#### Scenario: A leaver drops out
- **GIVEN** an employee whose `endDate` passed last week
- **WHEN** the feed is read
- **THEN** the employee is not in it

### Requirement: Completed learniq credentials SHALL become training records (REQ-TRN-003)

When learniq issues a credential to a learner whose Nextcloud account belongs to an
employee, humaniq SHALL write one `TrainingRecord` with status `gevolgd`, the issue date as
completion date, the expiry as validity, source `learniq` and the credential's id. It SHALL
write nothing for an account that belongs to no employee, and nothing twice for one
credential.

Rows: `tal-training`, `td-lms-sync` (humaniq matrix).

#### Scenario: A privacy course completed in learniq lands on the personnel file
- **GIVEN** learniq installed and an employee with Nextcloud account `a.visser`
- **WHEN** learniq issues `a.visser` a credential for "Privacy basics" expiring in two years
- **THEN** `EmployeeDetail` lists "Privacy basics" as `gevolgd` with that expiry and source
  learniq
