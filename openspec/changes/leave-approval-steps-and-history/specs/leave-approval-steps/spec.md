# leave-approval-steps

## ADDED Requirements

### Requirement: Every leave decision SHALL be stamped on the request by the server (REQ-LAS-001)

On every status change of a `LeaveRequest`, humaniq SHALL stamp the decision server-side
from the signed-in user and the clock: `submittedAt` on submit, `approvedBy` and
`approvedAt` on approval and rejection, and one entry in `decisions` per decision with the
step, the outcome, the person, the time and the remark. Client-supplied values for these
fields SHALL be ignored. Earlier decisions SHALL be kept when a rejected request is
resubmitted.

Rows: `dm-leave-approval-history` (humaniq matrix), feature request https://github.com/orangehrm/orangehrm/issues/1770.

#### Scenario: An employee sees who rejected their leave
@e2e exclude the stamp is server-side; covered by LeaveRequestProcessStampListenerTest::testRejectingKeepsTheReasonAsRemarkAndField, and the timeline and Approval widgets are declarative manifest over those fields (live check in task 3.2)
- **GIVEN** a submitted leave request
- **WHEN** manager `m.bakker` rejects it with the reason "team is short that week"
- **THEN** `LeaveRequestDetail` shows `m.bakker` and the time under Approval, and the
  decisions timeline lists one rejection by `m.bakker` with that remark

#### Scenario: A forged approver is overwritten
@e2e exclude a write through the objects API, not a browser flow; covered by LeaveRequestProcessStampListenerTest::testApprovingStampsTheSessionUserNotTheClient
- **GIVEN** a manager approving a request through the objects API with `approvedBy` set to
  someone else
- **WHEN** the write is saved
- **THEN** `approvedBy` holds the manager's own user id

#### Scenario: History survives a resubmit
@e2e exclude covered by LeaveRequestProcessStampListenerTest::testSubmittingStampsTheTimeAndClearsTheVerdict, which keeps the earlier decisions on resubmit, and testApprovingStampsTheSessionUserNotTheClient for the approval after it
- **GIVEN** a rejected request with one rejection in `decisions`
- **WHEN** the employee corrects and resubmits it and it is approved
- **THEN** `decisions` lists the rejection and then the approval

### Requirement: Leave types that need HR SHALL be approved by HR after the manager (REQ-LAS-002)

A leave type SHALL be able to require an HR step, always or above a number of hours. For a
request that needs it, the manager's approval SHALL move it to `manager-approved`, and only
the HR role, and not the person who approved as manager, SHALL move it to `approved` or
`rejected`. A request that needs no HR step SHALL be approved by the manager as before. Only
`approved` requests SHALL draw from the leave balance.

Rows: `dm-leave-multi-step-approval` (humaniq matrix), feature request https://github.com/orangehrm/orangehrm/issues/1676.

#### Scenario: Unpaid leave waits for HR
@e2e exclude the edge choice is LeaveApprovalStepGuard's; covered by LeaveApprovalStepGuardTest::testATypeNeedingHrGoesThroughTheManagerStep, and the HR queue is a declarative index with a status query (live check in task 3.2)
- **GIVEN** the leave type `unpaid` with `requiresHrApproval` true and a submitted unpaid
  request
- **WHEN** the manager approves it
- **THEN** its status is `manager-approved`, it appears on `HR-verlofgoedkeuring`, and the
  employee's balance is unchanged

#### Scenario: HR completes the approval
@e2e exclude covered by LeaveApprovalStepGuardTest::testOnlyHrAndNotTheApprovingManagerTakesTheHrStep and LeaveRequestProcessStampListenerTest::testTheHrStepIsRecordedAsHr (live check in task 3.2)
- **GIVEN** that request at `manager-approved`
- **WHEN** an HR adviser approves it
- **THEN** its status is `approved` and `decisions` lists the manager's and the adviser's
  approvals in that order

#### Scenario: A short holiday skips the HR step
@e2e exclude covered by LeaveApprovalStepGuardTest::testATypeNeedingHrGoesThroughTheManagerStep, where a 40-hour holiday under the threshold is approved straight from submitted and a 120-hour one is not
- **GIVEN** the leave type `holiday` with `hrApprovalAboveHours` 80 and a 16-hour request
- **WHEN** the manager approves it
- **THEN** its status is `approved` directly
