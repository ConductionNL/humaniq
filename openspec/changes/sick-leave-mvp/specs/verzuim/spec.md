# Spec: Verzuim (Sick Leave Management)

## ADDED Requirements

---

### REQ-SLM-001: Sick leave case registration (Ziekmelding)

An HR manager can register a new sick leave case for an employee. The case records the first sick day, whether a wachtdag applies, and the cause category. The case starts in the `sick` lifecycle state.

#### Scenario SLM-001-A: Create sick leave case with wachtdag

- **GIVEN** the user is authenticated and has the `sick-leave.report-sick` action permission
- **AND** an Employee object exists in the system
- **WHEN** the user submits a new SickLeaveCase with `startDate`, `reportedDate`, `employee` reference, and `wachtdagApplies: true`
- **THEN** a SickLeaveCase object is created with `status: sick`
- **AND** `wachtdagApplies` is stored as `true`
- **AND** the case is linked to the Employee via an OpenRegister relation
- **AND** the response returns HTTP 200 with the created object including its generated `id`

#### Scenario SLM-001-B: Create sick leave case without wachtdag

- **GIVEN** the user has the `sick-leave.report-sick` action permission
- **WHEN** the user submits a new SickLeaveCase with `wachtdagApplies: false`
- **THEN** the case is created with `status: sick` and `wachtdagApplies: false`

#### Scenario SLM-001-C: Reject creation without required fields

- **GIVEN** the user has the `sick-leave.report-sick` action permission
- **WHEN** the user submits a new SickLeaveCase without `startDate` or without `employee`
- **THEN** the API returns HTTP 400
- **AND** the response body contains a `message` field identifying the missing field
- **AND** no object is created

#### Scenario SLM-001-D: Reject creation without action permission

- **GIVEN** the user is authenticated but does NOT have the `sick-leave.report-sick` action permission
- **WHEN** the user submits a POST to `/api/sick-leave-cases`
- **THEN** the API returns HTTP 403
- **AND** the response body contains a static `message` field (`"Not authorized"`)

---

### REQ-SLM-002: Recovery registration (Hersteldmelding)

An HR manager can register the recovery of an employee by transitioning an existing sick leave case and setting the recovery date. The transition changes the status to `recovered` or `partially_recovered`.

#### Scenario SLM-002-A: Full recovery

- **GIVEN** a SickLeaveCase exists with `status: sick` or `status: long_term_sick`
- **WHEN** the user triggers the `report_recovery` lifecycle transition and provides an `endDate`
- **THEN** the case status changes to `recovered`
- **AND** `endDate` is stored on the case
- **AND** `durationDays` (calculated) reflects the difference between `startDate` and `endDate`

#### Scenario SLM-002-B: Partial recovery

- **GIVEN** a SickLeaveCase exists with `status: sick` or `status: long_term_sick`
- **WHEN** the user triggers the `partial_recovery` transition and provides an `endDate`
- **THEN** the case status changes to `partially_recovered`
- **AND** `endDate` is stored on the case

#### Scenario SLM-002-C: Recovery transition rejected without endDate

- **GIVEN** a SickLeaveCase exists with `status: sick`
- **WHEN** the user triggers the `report_recovery` transition without providing `endDate`
- **THEN** the transition is rejected with HTTP 422
- **AND** the case status remains `sick`

#### Scenario SLM-002-D: Relapse (reopen)

- **GIVEN** a SickLeaveCase exists with `status: recovered`
- **WHEN** the user triggers the `reopen` lifecycle transition
- **THEN** the case status changes to `sick`
- **AND** `endDate` is cleared (set to null)

---

### REQ-SLM-003: Wachtdag display

The wachtdag flag is visible on the case detail and list views so HR can determine whether the first day affects payment.

#### Scenario SLM-003-A: Wachtdag shown in case detail

- **GIVEN** a SickLeaveCase with `wachtdagApplies: true`
- **WHEN** an authenticated user views the case detail page
- **THEN** the wachtdag field is displayed with a clear label in Dutch (`"Wachtdag van toepassing"`)

#### Scenario SLM-003-B: No wachtdag shown when false

- **GIVEN** a SickLeaveCase with `wachtdagApplies: false`
- **WHEN** an authenticated user views the case detail page
- **THEN** the field is shown as `"Geen wachtdag"` or equivalent

---

### REQ-SLM-004: 70%/70% payment percentage display

The system calculates and displays the statutory wage-continuation percentage on every sick leave case. Year 1 (≤365 days) and year 2 (>365 days) both show 70%.

#### Scenario SLM-004-A: Payment percentage shown in year 1

- **GIVEN** a SickLeaveCase with `startDate` that results in `durationDays ≤ 365`
- **WHEN** an authenticated user views the case
- **THEN** `paymentPercentage` is displayed as `70%`
- **AND** the year indicator shows `"Jaar 1"` or equivalent

#### Scenario SLM-004-B: Payment percentage shown in year 2

- **GIVEN** a SickLeaveCase with `durationDays > 365` (i.e., `isInYear2: true`)
- **WHEN** an authenticated user views the case
- **THEN** `paymentPercentage` is displayed as `70%`
- **AND** the year indicator shows `"Jaar 2"` or equivalent

---

### REQ-SLM-005: UWV 42-week notification tracking

The system notifies HR managers when the 42-week UWV deadline is approaching and allows them to record that the notification has been sent to UWV.

#### Scenario SLM-005-A: Advance notification at 40 weeks

- **GIVEN** a SickLeaveCase where `durationDays` reaches 280 (40 weeks)
- **AND** `uwvNotificationSent` is `false`
- **WHEN** the notification trigger fires
- **THEN** a Nextcloud notification is dispatched to the `hr-managers` group
- **AND** the notification message identifies the employee and the 14-day deadline
- **AND** the case shows `uwvDeadlineApproaching: true`

#### Scenario SLM-005-B: Deadline notification at 42 weeks

- **GIVEN** a SickLeaveCase where `durationDays` reaches 294 (42 weeks)
- **AND** `uwvNotificationSent` is `false`
- **WHEN** the notification trigger fires
- **THEN** a Nextcloud notification is dispatched to the `hr-managers` group
- **AND** the notification message states the UWV deadline has been reached
- **AND** `uwvDeadlineReached` is `true` on the case

#### Scenario SLM-005-C: Mark UWV notification as sent

- **GIVEN** a SickLeaveCase with `uwvNotificationSent: false`
- **AND** the user has the `sick-leave.mark-uwv-sent` action permission
- **WHEN** the user marks the UWV notification as sent and provides `uwvNotificationSentDate`
- **THEN** `uwvNotificationSent` is updated to `true`
- **AND** `uwvNotificationSentDate` is stored
- **AND** `uwvDeadlineApproaching` and `uwvDeadlineReached` return `false`

#### Scenario SLM-005-D: UWV deadline date calculated correctly

- **GIVEN** a SickLeaveCase with `startDate: 2026-01-15`
- **WHEN** the case is read
- **THEN** `uwvDeadlineDate` is `2026-10-28` (2026-01-15 + 294 days)

---

### REQ-SLM-006: Sick leave case list view

HR managers can view a paginated, filterable list of all sick leave cases.

#### Scenario SLM-006-A: List with pagination

- **GIVEN** there are more than 20 sick leave cases
- **WHEN** the user navigates to the sick leave index page
- **THEN** cases are displayed with pagination controls
- **AND** the default page size is 20
- **AND** the total count is shown

#### Scenario SLM-006-B: Filter by status

- **GIVEN** sick leave cases with various statuses exist
- **WHEN** the user filters by `status: sick`
- **THEN** only cases with `status: sick` are shown in the list

#### Scenario SLM-006-C: Filter by UWV deadline approaching

- **GIVEN** some cases have `uwvDeadlineApproaching: true`
- **WHEN** the user applies the "UWV deadline approaching" filter
- **THEN** only cases where `uwvDeadlineApproaching` is `true` are shown

---

### REQ-SLM-007: Sick leave case detail view

An authenticated user can view the full details of a sick leave case including all field values, the lifecycle status, and the WVP timeline.

#### Scenario SLM-007-A: Detail page shows all fields

- **GIVEN** a SickLeaveCase exists with all fields populated
- **WHEN** the user navigates to the case detail page
- **THEN** all fields are shown: `startDate`, `endDate`, `reportedDate`, `status`, `wachtdagApplies`, `causeCategory`, `durationDays`, `paymentPercentage`, `uwvDeadlineDate`, `uwvNotificationSent`

#### Scenario SLM-007-B: Lifecycle stage bar shown

- **GIVEN** a SickLeaveCase with `status: long_term_sick`
- **WHEN** the user views the detail page
- **THEN** a `CnTimelineStages` component shows the lifecycle progression with `long_term_sick` as the active state

#### Scenario SLM-007-C: Audit trail accessible

- **GIVEN** a SickLeaveCase that has been modified at least once
- **WHEN** the user opens the audit trail tab in `CnObjectSidebar`
- **THEN** the change history is shown with timestamps and user UIDs (not display names)

---

### REQ-SLM-008: Verzuim dashboard

HR managers can view a dashboard summarising the current sick leave situation across all employees.

#### Scenario SLM-008-A: Dashboard shows four KPI cards

- **GIVEN** there are sick leave cases with various statuses
- **WHEN** the user navigates to the verzuim dashboard
- **THEN** four `CnStatsBlock` cards are visible:
  1. Number of currently open cases (`status: sick | partially_recovered | long_term_sick`)
  2. Number of cases with `uwvDeadlineApproaching: true`
  3. Number of long-term sick cases (`isInYear2: true`)
  4. Number of recoveries this calendar month

#### Scenario SLM-008-B: Dashboard renders when there are zero cases

- **GIVEN** no sick leave cases exist
- **WHEN** the user navigates to the verzuim dashboard
- **THEN** all KPI cards show `0`
- **AND** no error or empty-state crash occurs

---

### REQ-SLM-009: Authorization and data isolation

Access to sick leave cases is controlled by action-level permissions (ADR-023) and data-layer RBAC (ADR-005). Unauthenticated and unauthorized requests are rejected.

#### Scenario SLM-009-A: Unauthenticated request rejected

- **GIVEN** no authenticated Nextcloud session
- **WHEN** a request is made to any `/api/sick-leave-cases` endpoint
- **THEN** the API returns HTTP 401
- **AND** no sick leave data is included in the response

#### Scenario SLM-009-B: IDOR prevention on update

- **GIVEN** a SickLeaveCase was created by HR manager A
- **AND** user B is authenticated but is not an HR manager and did not create the case
- **WHEN** user B sends a PUT to `/api/sick-leave-cases/{id}` with a modified field
- **THEN** the API returns HTTP 403
- **AND** the case data is not modified

#### Scenario SLM-009-C: Admin can perform all actions

- **GIVEN** the user has Nextcloud admin rights
- **WHEN** the user creates, updates, transitions, or deletes any sick leave case
- **THEN** the request succeeds regardless of who originally created the case

---

### REQ-SLM-010: Internationalisation

All user-visible strings must be available in English and Dutch per ADR-007.

#### Scenario SLM-010-A: Dutch translations present

- **GIVEN** a Nextcloud instance configured for Dutch (`nl`)
- **WHEN** the user views any sick leave page or notification
- **THEN** all labels, status values, KPI card titles, and notification messages are shown in Dutch
- **AND** no raw translation keys (e.g. `"sick_leave_case"`) are displayed

#### Scenario SLM-010-B: English fallback

- **GIVEN** a Nextcloud instance configured for English (`en`)
- **WHEN** the user views any sick leave page
- **THEN** all labels are shown in English sentence case
- **AND** `l10n/en.json` covers every key used in `l10n/nl.json`
