# hire-to-employee

## ADDED Requirements

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
