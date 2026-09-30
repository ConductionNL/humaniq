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

@e2e exclude the writes are server-side; covered by HireServiceTest::testAHireCreatesTheEmployeeTheCaseAndTheLink (payloads validated against the register schemas) and HireControllerTest::testMatchesAre409AndACreateIs201

#### Scenario: A double click creates one employee
- **GIVEN** an application already linked to an employee
- **WHEN** `POST /api/applications/{id}/hire` is called again
- **THEN** the answer names the linked employee and no second employee or case exists

@e2e exclude covered by HireServiceTest::testASecondCallCreatesNothing

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

@e2e exclude covered by HireServiceTest::testMatchesStopTheHireUntilHrChooses and HireServiceTest::testAttachingToAFormerEmployeeReopensTheRecord

#### Scenario: Same name, different person
- **GIVEN** an existing Jan de Vries born in 1970 and a hired Jan de Vries born in 1996
- **WHEN** the HR adviser creates the employee with the 1996 birth date
- **THEN** no match is shown and a new employee is created

@e2e exclude covered by HireMatchServiceTest::testTheSameNameWithAnotherBirthDateIsNoMatch
