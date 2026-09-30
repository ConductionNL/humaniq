# candidate-assessment

## ADDED Requirements

### Requirement: Interviewers SHALL record a score and a note per criterion (REQ-CAS-001)

`Vacancy` SHALL carry a list of evaluation criteria. humaniq SHALL provide a
`CandidateEvaluation` schema holding, for one application and one evaluator, a 1 to 5 score
and a note per criterion, an overall recommendation and a note. The evaluator SHALL be set by
the server from the signed-in user. `ApplicationDetail` SHALL list the evaluations and the
average score per criterion. Deleting the application SHALL delete its evaluations.

Rows: `hir-candidate-evaluation` (humaniq matrix).

#### Scenario: Two interviewers disagree on paper
- **GIVEN** a vacancy with criteria "payroll knowledge" and "communication" and an
  application that had one interview
- **WHEN** a manager scores payroll knowledge 4 and an HR adviser scores it 2, each with a
  note
- **THEN** `ApplicationDetail` lists both evaluations with their evaluator and shows an
  average of 3 for payroll knowledge

#### Scenario: Evaluations go with the application
- **GIVEN** a rejected application with two evaluations whose retention date has passed
- **WHEN** HR deletes the application
- **THEN** neither evaluation remains

### Requirement: A vacancy SHALL show an explained match score for applicants and employees (REQ-CAS-002)

`GET /api/vacancies/{id}/matches` SHALL score active and talent-pool applicants and active
employees against the vacancy's requirements from 0 to 100, SHALL return the points earned
per requirement and what was missing, and SHALL be shown ranked on `VacancyDetail`. Employee
competences past their `validUntil` SHALL NOT count. The score SHALL NOT change any
application's status.

Rows: `dm-vacancy-matching` (humaniq matrix), changelog https://klant.afas.nl/update/profit-8/hrm.

#### Scenario: A colleague fits an open role
- **GIVEN** a vacancy requiring competences `loonheffing` and `excel-gevorderd`, and an
  employee holding a current `loonheffing` competence only
- **WHEN** an HR adviser opens the vacancy's matches
- **THEN** the employee is listed with 20 of 40 competence points and the breakdown names
  `excel-gevorderd` as missing

#### Scenario: A rejected applicant without consent is not proposed
- **GIVEN** a rejected applicant who did not opt in to the talent pool
- **WHEN** the matches are read
- **THEN** that applicant does not appear

### Requirement: Employees SHALL refer candidates and follow only their status (REQ-CAS-003)

An employee SHALL be able to refer a candidate to a published vacancy from Mijn HR. The
referral SHALL require the employee to confirm the candidate agreed, SHALL create an
application at `nieuw` with `source` `referral` and the referrer set by the server, and
SHALL be refused for a vacancy that is not published. The referrer SHALL see their own
referrals with the candidate's name, the vacancy and the status, and nothing else.

Rows: `hir-referrals` (humaniq matrix).

#### Scenario: An employee puts a friend forward
- **GIVEN** an employee without HR rights and a published vacancy
- **WHEN** they refer "Ahmed Yilmaz" with his e-mail and tick that he agreed
- **THEN** HR finds a new application from Ahmed Yilmaz with source referral and the
  employee as referrer, and the employee's `MijnAanbevelingen` shows it as `nieuw`

#### Scenario: A referral without consent is refused
- **GIVEN** the same form
- **WHEN** the employee submits without ticking that the candidate agreed
- **THEN** no application is created and the answer names the missing consent
