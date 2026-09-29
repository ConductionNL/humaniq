# approvals-inbox Specification

## Purpose
A manager, or a deputy standing in for one, sees every waiting leave request, timesheet, expense claim and leave trade in one inbox and decides on it there, and can look back at what they decided with each request's timeline. Built by self-service-approvals-inbox (archived 2026-09-29).

## Requirements

### Requirement: A manager SHALL have one inbox for every waiting request (REQ-API-001)

humaniq SHALL offer a `MijnGoedkeuringen` page listing every submitted leave request, timesheet,
expense claim and leave transaction waiting for the caller, with the employee, the dates, the
waiting time and the status, filterable by kind, with approve and reject in place. A second view
SHALL list the requests the caller decided with each request's timeline.

Rows: `dm-approvals-dashboard` (humaniq matrix).

#### Scenario: A manager clears the week's requests
- **GIVEN** a manager with two leave requests, three timesheets and one expense claim waiting
- **WHEN** they open `MijnGoedkeuringen`
- **THEN** all six are listed oldest first, and approving one there applies the same
  transition as on the team page

@e2e exclude the inbox is composed server side under the caller's rights; covered by ApprovalsInboxServiceTest::testAManagerSeesAllSixWaitingRequestsOldestFirst and ApprovalsControllerTest, the page by check:manifest

#### Scenario: Looking back at a decision
- **GIVEN** a leave request the manager rejected last week
- **WHEN** they open the decided view
- **THEN** the request shows when it was submitted, when it was rejected, by whom and why

@e2e exclude the timeline is composed server side from the decision stamps; covered by ApprovalsInboxServiceTest::testTheDecidedViewShowsWhenWhoAndWhy and ApprovalDecisionStampListenerTest::testARejectionStampsWhoDecidedAndWhen

### Requirement: A manager SHALL be able to hand their approvals to a deputy for a period (REQ-API-002)

A manager, or HR, SHALL be able to record a deputy with a start and end date. During that period
the deputy's inbox SHALL hold the manager's waiting requests and the deputy SHALL receive the
submit notifications; after it the deputy SHALL receive neither. A manager SHALL NOT be their own
deputy.

Rows: `td-manager-deputy` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: A deputy covers the summer holiday
- **GIVEN** a manager who records a colleague as deputy from 14 July to 1 August
- **WHEN** an employee of the team submits leave on 20 July
- **THEN** the deputy is notified and sees the request in their inbox, and on 2 August the
  deputy no longer sees the manager's requests

@e2e exclude the deputy period is resolved server side on every read and notification; covered by ApprovalsInboxServiceTest::testADeputySeesTheManagersRequestsOnlyDuringThePeriod and ManagerOrDeputyRecipientResolverTest::testTheActiveDeputyIsNotifiedAndTheExpiredOneIsNot
