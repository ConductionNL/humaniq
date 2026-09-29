# payroll-annual-statement-action

## ADDED Requirements

### Requirement: One annual statement SHALL be generated from its page (REQ-JAO-001)

`JaaropgaafDetail` SHALL offer an action that generates that statement's PDF. The request SHALL
resolve the statement under the caller's RBAC before anything is rendered, and SHALL answer 404
for a statement the caller may not read. When the statement already has a generated PDF, the
action SHALL report that and SHALL render nothing new.

Rows: `pay-annual-statement` (humaniq matrix).

#### Scenario: A payroll officer generates one statement
- **GIVEN** a 2026 annual statement for one employee without a generated PDF
- **WHEN** a payroll officer chooses "Generate PDF" on its `JaaropgaafDetail` page
- **THEN** a generated document for that statement appears in the documents list

@e2e exclude the statement is resolved and rendered server-side; covered by DocumentControllerTest::testAReadableStatementIsGeneratedForItsEmployeeAndYear and HrDocumentServiceTest's jaaropgaaf cases

#### Scenario: A caller who cannot read the statement gets nothing
- **GIVEN** a user whose RBAC does not allow reading a statement
- **WHEN** they post `documentType: jaaropgaaf` with that statement's id
- **THEN** the response is 404 and nothing is rendered

@e2e exclude access is decided server-side; covered by DocumentControllerTest::testAnUnreadableStatementIs404AndNothingIsRendered

### Requirement: A year's statements SHALL be generated for everyone as a background job (REQ-JAO-002)

The `Jaaropgaven` index SHALL offer an action that queues the generation of the previous
calendar year's statements for every employee with payslips in that year. Only an administrator
or a member of the HR or payroll group SHALL be allowed to queue it. The request SHALL answer at once
with the number of employees queued, SHALL refuse the current or a future year, and running it
twice SHALL create no second document for the same statement.

Rows: `pay-annual-statement` (humaniq matrix).

#### Scenario: The January batch
- **GIVEN** 2026 payslips for 14 employees and no statements yet
- **WHEN** an HR adviser chooses "Generate last year's statements" on `Jaaropgaven` in
  January 2027
- **THEN** the request answers 202 with 14 queued, the page confirms the queue, and after the job
  has run the documents list shows 14 statements for 2026

@e2e exclude a queued background job cannot be observed from the page; covered by DocumentControllerTest::testHrQueuesLastYearWithTheCount and JaaropgaafYearJobTest::testTheJobRunsTheBacklogForItsYear

#### Scenario: An employee cannot start the batch
- **GIVEN** a user outside the HR and payroll groups
- **WHEN** they post to `/api/documents/jaaropgaven`
- **THEN** the response is 403 and no job is queued

@e2e exclude access is decided server-side; covered by DocumentControllerTest::testAnEmployeeCannotQueueTheYear

#### Scenario: The current year is refused
- **GIVEN** a date in 2027
- **WHEN** an HR adviser posts year 2027
- **THEN** the response is 400 because the year is not over

@e2e exclude the year check is server-side; covered by DocumentControllerTest::testTheCurrentYearIsRefused
