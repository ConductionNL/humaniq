# functiehuis-hr21

## ADDED Requirements

### Requirement: HR SHALL maintain the function catalogue in the app (REQ-JFM-001)

HR SHALL be able to add, edit and retire `Normfunctie` rows on `Normfuncties`, including the
employer's own functions beside the HR21 reference functions, and to load many at once with
mass import. Each function SHALL record its source (`hr21` or `eigen`), its family and
optionally its salary band. A function SHALL be retired rather than deleted.

Rows: `ppl-job-framework` (humaniq matrix).

#### Scenario: HR adds an employer function
- **GIVEN** an HR adviser on `Normfuncties`
- **WHEN** they add "Adviseur informatiebeheer" with source `eigen`, schaal 10 and a salary
  band
- **THEN** the function is listed beside the HR21 functions and can be chosen on an
  employment contract

@e2e exclude create, edit and mass import are the library's index and detail actions switched on in the manifest; covered by npm run check:manifest and the live check in the PR

#### Scenario: A retired function keeps its contracts readable
- **GIVEN** a function linked from two live contracts
- **WHEN** HR retires it
- **THEN** it disappears from the default list, both contracts still show it, and it cannot
  be deleted

@e2e exclude the default filter and the missing delete are manifest toggles, and contracts keep their normfunctieId; covered by npm run check:manifest and the live check in the PR

### Requirement: A contract on a retired function SHALL be flagged (REQ-JFM-002)

The rules audit SHALL flag a live employment contract whose function is retired, so HR
assigns a current one.

Rows: `ppl-job-framework` (humaniq matrix).

#### Scenario: The audit points at the contract
- **GIVEN** a live contract on a retired function
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** the contract is flagged under `nl-hr21-vervallen-functie`

@e2e exclude the flag is a rule predicate; covered by NlHr21ChecksTest::testALiveContractOnARetiredFunctionIsFlagged and RuleAuditServiceTest::testAContractOnARetiredFunctionIsFlaggedThroughTheAudit
