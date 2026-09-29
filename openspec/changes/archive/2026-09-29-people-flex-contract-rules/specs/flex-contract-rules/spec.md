# flex-contract-rules

## ADDED Requirements

### Requirement: HR SHALL be warned before a fixed-term chain turns permanent (REQ-FLX-001)

humaniq SHALL treat an employee's fixed-term contracts that follow each other with gaps of
at most six months as one chain (BW 7:668a) and SHALL signal a live contract when it is the
third in its chain, or when its chain will pass 36 months within 60 days. The contract
number, the month limit, the gap and the window SHALL be parameters of the rule.

Rows: `ppl-chain-rule` (humaniq matrix).

#### Scenario: A third contract is flagged before renewal
- **GIVEN** an employee on her third fixed-term contract, each following the last within
  two months
- **WHEN** an HR adviser runs `occ humaniq:rules:audit` or opens the contract
- **THEN** the chain signal is raised for that contract, and `EmploymentContractDetail`
  shows position 3 of 3 and the date the chain turns permanent on renewal

@e2e exclude the chain is composed server-side and the signal is a rule predicate; covered by NlFlexContractSignalsTest::testALiveThirdContractIsFlagged, ContractChainServiceTest::testTheThirdContractOfAChainIsThreeOfThree and FlexContractControllerTest::testTheThirdContractsChainIsThreeOfThree

#### Scenario: A long gap restarts the chain
- **GIVEN** an employee whose previous fixed-term contract ended eight months before the
  current one started
- **WHEN** the chain of the current contract is read through
  `GET /api/contracts/{id}/chain`
- **THEN** the position is 1 and no chain signal is raised

@e2e exclude the chain is composed server-side; covered by ContractChainServiceTest::testAGapOfMoreThanSixMonthsRestartsTheChain and NlFlexContractSignalsTest::testAFirstContractAndARestartedChainAreNotFlagged

### Requirement: humaniq SHALL know on-call contracts and their average hours (REQ-FLX-002)

`EmploymentContract` SHALL accept the type `oproep`. humaniq SHALL show, for every on-call
contract, the average approved hours worked per week and per month over a period the user
chooses, and SHALL export that list to CSV.

Rows: `dm-oncall-average-hours` (humaniq matrix).

#### Scenario: HR reads the average for the offer
- **GIVEN** an on-call worker with twelve months of approved hours averaging 18 hours a
  week
- **WHEN** an HR adviser opens the on-call overview for the last twelve months
- **THEN** the worker's row shows 18 hours per week, and the CSV export carries the same
  figure

@e2e exclude the average is computed server-side; covered by OnCallAverageServiceTest::testTwelveMonthsOfApprovedHoursAverageEighteenAWeek and FlexContractControllerTest::testHrReadsTheOnCallAverageAndTheCsvCarriesIt

#### Scenario: Unapproved hours do not count
- **GIVEN** the same worker with one week of hours still waiting for approval
- **WHEN** the overview is read for a period covering that week
- **THEN** the average leaves that week's hours out

@e2e exclude the average is computed server-side; covered by OnCallAverageServiceTest::testTwelveMonthsOfApprovedHoursAverageEighteenAWeek

### Requirement: A due fixed-hours offer SHALL be signalled (REQ-FLX-003)

humaniq SHALL signal an on-call contract that started more than twelve months ago and has
no recorded fixed-hours offer (BW 7:628a lid 5).

Rows: `dm-oncall-average-hours` (humaniq matrix).

#### Scenario: A thirteen-month on-call contract without an offer
- **GIVEN** an on-call contract that started thirteen months ago with `vasteUrenAanbodOp`
  empty
- **WHEN** the rules audit runs
- **THEN** the offer signal is raised for that contract, and it clears once HR records the
  offer date and hours

@e2e exclude the signal is a rule predicate; covered by NlFlexContractSignalsTest::testAThirteenMonthOnCallContractWithoutAnOfferIsFlagged
