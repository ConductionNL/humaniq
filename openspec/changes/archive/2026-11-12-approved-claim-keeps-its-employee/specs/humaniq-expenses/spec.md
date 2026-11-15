# humaniq-expenses

## ADDED Requirements

### Requirement: An approved expense claim SHALL keep its employee

Payroll pays an approved claim to the employee its `employeeId` names. On the approve write
(stored status `submitted`, saved status `approved`), the server SHALL save the stored
`employeeId`, whatever the write sends. On any write of a claim whose stored status is
`approved` or `reimbursed`, the server SHALL do the same. The stored status decides this,
never the status the write sends. A claim that is still open may change its employee, and
its `userId`, `managerUserId` and `administrationId` then follow the new employee.

#### Scenario: The approve write cannot move a claim to another employee

@e2e exclude Stamping is a backend write-path behaviour with no screen of its own; covered by ExpenseManagerStampListenerTest::testAnApprovedClaimKeepsItsEmployee
- **GIVEN** Tim's submitted claim
- **WHEN** the approve write sends `employeeId` of Pieter
- **THEN** the approved claim still names Tim's employee

#### Scenario: A decided claim keeps its employee on a later write

@e2e exclude Stamping is a backend write-path behaviour; covered by ExpenseManagerStampListenerTest::testAnApprovedClaimKeepsItsEmployee
- **GIVEN** Tim's approved claim, or his reimbursed claim
- **WHEN** a later write sends `employeeId` of Pieter
- **THEN** the claim still names Tim's employee and Tim's account

#### Scenario: An open claim may change its employee

@e2e exclude Stamping is a backend write-path behaviour; covered by ExpenseManagerStampListenerTest::testAnOpenClaimMayChangeItsEmployee
- **GIVEN** a draft claim of Tim
- **WHEN** it is saved with Sanne's employee
- **THEN** the claim carries Sanne's account and her manager Pieter
