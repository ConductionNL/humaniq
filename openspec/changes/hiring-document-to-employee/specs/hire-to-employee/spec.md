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

@e2e exclude The page steps are generic (an object-list create with a file property, two api-call actions); the scenario turns on filinq reading a PDF on the instance. `EmployeeDocumentExtractionServiceTest::testASignedContractMakesOneContract` asserts the values recorded and the one contract against filinq's own classes; `OnboardingDocumentControllerTest::testOneExtractionMakesAtMostOneContract` the answers.

#### Scenario: A value HR typed is never replaced
- **GIVEN** an employee whose last name HR already entered as "de Boer"
- **WHEN** an identity document reading returns "DE BOER-JANSEN"
- **THEN** the last name stays "de Boer" and the record lists last name as read but not
  applied

@e2e exclude A server-side rule on which fields are written. `EmployeeDocumentExtractionServiceTest::testAValueHrTypedIsNeverReplaced` asserts it with the MRZ reading DE BOER JANSEN.
