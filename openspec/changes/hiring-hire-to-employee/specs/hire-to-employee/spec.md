# hire-to-employee

## ADDED Requirements

### Requirement: A hired application SHALL become an employee and an onboarding case in one action (REQ-HTE-001)

On an application with status `aangenomen`, a `Create employee` action SHALL create an
`Employee` from the application's name, e-mail, phone and administration and the start date
HR confirms, SHALL create an `Onboarding` case at `aangenomen` with that start date, and
SHALL link the application to the employee. It SHALL NOT change the application's status.
A second use SHALL return the linked employee and create nothing. The action SHALL be
available to admins and HR only and SHALL answer 404 for an application the caller may not
read.

Rows: `hir-hire-to-employee` (humaniq matrix).

#### Scenario: An HR adviser hires without typing the name again
- **GIVEN** an application from "Sanne de Boer" with e-mail and phone, status `aangenomen`
- **WHEN** an HR adviser presses `Create employee` on `ApplicationDetail` and confirms start
  date 1 November with first name "Sanne" and last name "de Boer"
- **THEN** an employee "Sanne de Boer" exists with that e-mail, phone and start date, an
  onboarding case for her starts at `aangenomen`, and the application shows `Open employee`

#### Scenario: A double click creates one employee
- **GIVEN** an application already linked to an employee
- **WHEN** `POST /api/applications/{id}/hire` is called again
- **THEN** the answer names the linked employee and no second employee or case exists

### Requirement: A returning person SHALL be offered their existing record (REQ-HTE-002)

Before creating an employee, humaniq SHALL look for existing employees with the same BSN,
the same last name and date of birth, or the same private e-mail, and SHALL show each match
with the key that matched and whether the person has left. When matches exist, humaniq SHALL
NOT create a new employee until HR chooses one or explicitly chooses a new record. Attaching
to a former employee SHALL clear their `endDate`, set the new start date and create the
onboarding case on that record, leaving earlier contracts unchanged.

Rows: `dm-rehire` (humaniq matrix).

#### Scenario: A former employee comes back
- **GIVEN** a former employee Pieter Smit, born 3 March 1985, with `endDate` in 2023, and a
  hired application from Pieter Smit where HR enters date of birth 3 March 1985
- **WHEN** the HR adviser presses `Create employee`
- **THEN** the dialog lists the 2023 record as a match on last name and date of birth, and
  choosing it gives that record a new start date, no end date and a new onboarding case,
  with no second Pieter Smit in `Employees`

#### Scenario: Same name, different person
- **GIVEN** an existing Jan de Vries born in 1970 and a hired Jan de Vries born in 1996
- **WHEN** the HR adviser creates the employee with the 1996 birth date
- **THEN** no match is shown and a new employee is created

### Requirement: A supplied document SHALL fill the new employee's empty fields for HR to check (REQ-HTE-003)

`Read document` on `OnboardingDetail` SHALL send a file attached to the case to filinq's
extraction as an employment contract or an identity document, SHALL record the attempt with
the values read, their confidence and the fields written, and SHALL write only employee
fields that are empty. Contract values SHALL be kept on the record and SHALL become an
`EmploymentContract` only when HR presses `Create contract from document`. Without filinq the
attempt SHALL be recorded as skipped and nothing SHALL be written.

Rows: `dm-document-to-employee` (humaniq matrix), roadmap https://loket.nl/roadmap/.

#### Scenario: A signed contract fills the gaps
- **GIVEN** a new employee with no date of birth and a signed contract PDF on their
  onboarding case
- **WHEN** an HR adviser presses `Read document` as an employment contract
- **THEN** the extraction record shows the start date, hours and wage it read, the empty
  fields it filled, and `Create contract from document` becomes available

#### Scenario: A value HR typed is never replaced
- **GIVEN** an employee whose last name HR already entered as "de Boer"
- **WHEN** an identity document reading returns "DE BOER-JANSEN"
- **THEN** the last name stays "de Boer" and the record lists last name as read but not
  applied
