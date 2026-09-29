# employee-relations-cases Specification

## Purpose
Grievance, warning and disciplinary cases on the personnel file, readable only by HR, with a retention date. Built by people-employee-relations-cases (archived 2026-09-29).

## Requirements

### Requirement: HR SHALL record employee relations cases (REQ-ERC-001)

humaniq SHALL hold an `EmployeeRelationsCase` per matter with the employee, the kind
(grievance, conflict, written warning, disciplinary measure, other), the facts, the measure
and its period, the outcome and the documents, moving from opened through in progress to
closed. Closing SHALL require an outcome and a closing date.

Rows: `ppl-employee-relations` (humaniq matrix).

#### Scenario: HR records a written warning
- **GIVEN** an HR adviser on `EmployeeDetail` for Mark Visser
- **WHEN** they open a case of kind written warning, attach the letter and close it with
  its outcome
- **THEN** the case shows as closed on the employee's relations list with the letter

@e2e exclude the stamping and the closing rule are decided server-side; covered by RelationsCaseListenerTest::testANewCaseIsStampedWithTheAccountsItsAuthorizationReads and RelationsCaseDeclarationTest::testClosingNeedsAnOutcomeAndADate

#### Scenario: A case cannot close without an outcome
- **GIVEN** a case in progress with no outcome
- **WHEN** HR tries to close it
- **THEN** the transition is refused

@e2e exclude OpenRegister's TransitionEngine enforces the declared inputs; covered by RelationsCaseDeclarationTest::testClosingNeedsAnOutcomeAndADate

### Requirement: Only HR SHALL read a case's content (REQ-ERC-002)

The case's facts, measure, outcome and documents SHALL be readable only by the HR role and,
for a closed written warning or disciplinary measure, by the employee it concerns. The
employee's manager SHALL see only that a case exists, its kind and status. OpenRegister
SHALL enforce this for every page and API.

Rows: `ppl-employee-relations` (humaniq matrix).

#### Scenario: The manager sees a case exists, not what it says
- **GIVEN** a closed warning on an employee in a manager's team
- **WHEN** the manager opens the employee through the OpenRegister objects API
- **THEN** the case's kind and status are returned and its facts, measure, outcome and
  documents are not

@e2e exclude OpenRegister enforces the authorization; covered by RelationsCaseDeclarationTest::testHrReadsAllTheManagerTheCaseAndTheSubjectAClosedMeasure and RelationsCaseListenerTest::testAHandSetAccountIsPutBack

#### Scenario: The employee sees their own closed measure
- **GIVEN** the same closed warning
- **WHEN** the employee opens `MijnMaatregelen`
- **THEN** they see the warning with its facts and letter, and see no open grievance cases

@e2e exclude OpenRegister enforces the authorization; covered by RelationsCaseDeclarationTest::testHrReadsAllTheManagerTheCaseAndTheSubjectAClosedMeasure

### Requirement: A closed case SHALL carry a retention date (REQ-ERC-003)

Closing a case SHALL set `retainedUntil` to two years after the closing date unless HR sets
another date, and a case kept past its date SHALL be flagged by the retention check.

Rows: `ppl-employee-relations` (humaniq matrix).

#### Scenario: An old warning is flagged for removal
- **GIVEN** a warning closed more than two years ago
- **WHEN** the rules audit runs
- **THEN** the case is flagged as past its retention date

@e2e exclude the rules audit runs from occ; covered by NlDossierRetentionChecksTest::testAClosedRelationsCaseKeptPastItsDateIsFlagged and RelationsCaseListenerTest::testClosingSetsTheRetentionDateTwoYearsOn
