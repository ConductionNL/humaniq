# humaniq-expenses

## ADDED Requirements

### Requirement: An expense claim of any category SHALL be approved the way hours are

An expense claim SHALL get its approver the same way a timesheet does, whatever its category:
travel, accommodation, meals, supplies, software, training or other costs. The server SHALL
stamp `managerUserId` with the employee's unique manager from the shared resolution
(`HoursRegisterGateway::uniqueManagerUserIdFor`). That resolution climbs to the parent unit
when the employee manages their own unit. It stays inside the employee's administration, and
gives null when no single manager resolves. The stamp SHALL be resolved for the claim's
`expenseDate`, else today.

The server SHALL also copy `userId` and `administrationId` from the employee. Client input to
these three fields SHALL be ignored. When the employee cannot be read, the stored values stay.

The stamp SHALL be renewed on every write of a claim that is not approved or reimbursed. So a
submit, and a resubmit after a rejection, go to today's manager. Approved and reimbursed claims
keep the stamp they were decided under. humaniq's own writes SHALL leave the stamp as it is.

The claim SHALL then follow the hours route. It shows in the approvals inbox of its manager and
of every deputy standing in for that manager today. The `expense-submitted` notification goes
to the same people. `NoSelfApprovalGuard` refuses the claimant's own approve or reject, and the
inbox never lists a claim for its own claimant.

#### Scenario: A meals claim goes to the employee's manager

@e2e exclude Stamping is a backend write-path behaviour with no screen of its own; covered by ExpenseManagerStampListenerTest::testAClaimOfEveryCategoryGetsTheResolvedApprover
- **GIVEN** Tim is placed in Consultancy, managed by Sanne
- **WHEN** Tim saves a meals claim that names `managerUserId: "tim"`
- **THEN** the claim carries `managerUserId: "sanne"` and `userId: "tim"`

#### Scenario: A manager's own claim goes to the manager above

@e2e exclude Stamping is a backend write-path behaviour; covered by ExpenseManagerStampListenerTest::testAManagersOwnClaimGoesToTheManagerAbove
- **GIVEN** Sanne manages Consultancy and Pieter manages Directie above it
- **WHEN** Sanne saves a claim
- **THEN** the claim carries `managerUserId: "pieter"`

#### Scenario: The approver is resolved for the claim's date

@e2e exclude Stamping is a backend write-path behaviour; covered by ExpenseManagerStampListenerTest::testTheApproverIsResolvedForTheClaimsDate
- **GIVEN** Tim sat in Support, managed by Kees, until 31 May, and in Consultancy since 1 June
- **WHEN** Tim saves a claim dated 15 May
- **THEN** the claim carries `managerUserId: "kees"`

#### Scenario: A submit goes to today's manager, a decided claim keeps its stamp

@e2e exclude Stamping is a backend write-path behaviour; covered by ExpenseManagerStampListenerTest::testASubmitIsRestampedAndEarlierStampsAreKept and testDecidedClaimsKeepTheirStamp
- **GIVEN** a draft claim of Tim stamped `managerUserId: "kees"`
- **WHEN** Tim submits it
- **THEN** the claim carries `managerUserId: "sanne"`
- **AND** when Sanne approves it, the stamp stays `sanne`
