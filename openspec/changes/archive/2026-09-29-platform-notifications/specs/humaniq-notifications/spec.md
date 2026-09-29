# humaniq-notifications

## ADDED Requirements

### Requirement: A submitted request SHALL notify its approver (REQ-NTF-001)

When a leave request, timesheet or expense claim is submitted, humaniq SHALL notify the
Nextcloud user in the request's `managerUserId` through a declared
`x-openregister-notifications` rule, in the Nextcloud notifications and by email.

Rows: `ess-notifications` (humaniq matrix).

#### Scenario: A manager learns a leave request waits
- **GIVEN** an employee whose leave request carries their manager's uid in `managerUserId`
- **WHEN** the employee submits it on `MijnVerlof`
- **THEN** the manager receives a notification that a leave request waits, which opens the
  request

@e2e exclude a notification is dispatched by OpenRegister's engine from the declared rule; the rule is covered by the self-service-approvals-inbox tests (ManagerOrDeputyRecipientResolverTest) and validated with OpenRegister's NotificationAnnotationValidator

### Requirement: A decision SHALL notify the requester (REQ-NTF-002)

When a leave request, timesheet, expense claim or leave transaction is approved or rejected,
when an expense is reimbursed and when a performance review is finalised, humaniq SHALL
notify the Nextcloud user in the record's `userId`. A rejection notification SHALL carry the
rejection reason.

Rows: `ess-notifications` (humaniq matrix).

#### Scenario: An employee hears their claim was rejected
- **GIVEN** an employee's submitted expense claim
- **WHEN** their manager rejects it with the reason "receipt missing"
- **THEN** the employee receives a notification that the claim was rejected, showing "receipt
  missing"

@e2e exclude dispatched by OpenRegister's engine; covered by DecisionNotificationRulesTest::testTheDecisionReachesTheRequester (expense rejected carries {{rejectionReason}}) and OpenRegister's NotificationAnnotationValidator

### Requirement: Every notification SHALL be switchable per rule (REQ-NTF-003)

Each notification SHALL be a separate declared rule with a default the employer sets, and each
user SHALL be able to switch any humaniq rule off or on for themselves in the humaniq settings
dialog, without affecting other users.

Rows: `plt-configurable-notifications` (humaniq matrix).

#### Scenario: A manager turns off email for waiting requests
- **GIVEN** a manager receiving `expense-submitted` notifications
- **WHEN** they switch that rule off in the humaniq settings dialog
- **THEN** the next submitted claim in their queue produces no notification for them, and
  another manager still receives theirs

@e2e exclude the per-user switch is OpenRegister's notification preferences, rendered by the library's CnNotificationPreferences; covered by DecisionNotificationRulesTest::testTheMenuOpensTheUserSettings

#### Scenario: The settings show only humaniq's rules
- **GIVEN** a user with access to several apps' schemas
- **WHEN** they open the notification settings in humaniq
- **THEN** only humaniq's rules are listed

@e2e exclude scoping by cnAppId is library behaviour (CnNotificationPreferences filters on application); every humaniq rule carries originApp humaniq, asserted by DecisionNotificationRulesTest
