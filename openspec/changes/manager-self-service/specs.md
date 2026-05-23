# Specs: Manager Self-Service Portal

## REQ-001: Manager scope derivation from org-chart

**Description**: When a manager logs in, the system derives their team-scope from the OrgChart reporting structure. This scope is used to filter all subsequent queries — managers see only their direct reports plus optionally N levels deeper (configurable; default 1 level = direct reports only).

### Scenario: Direct reports only (default)

- **WHEN** a manager with `user_id = "abc-123"` logs in
- **THEN** the system queries OrgChart for all users with `reports_to_user_id = "abc-123"`
- **AND** stores this list as the manager's `team_scope` for the session
- **AND** the manager can see only these users in all manager-scoped views (leave pending, team roster, verzuim monitoring)

### Scenario: Transitive scope (N levels deeper, if configured)

- **WHEN** a manager logs in and the app is configured for `max_report_depth = 2`
- **THEN** the system queries OrgChart recursively: direct reports + their reports (2 levels total)
- **AND** stores the full transitive list as the manager's `team_scope`
- **AND** budget views show only cost-centers where the manager's team has allocated headcount

### Scenario: Scope change via org-chart update

- **WHEN** a manager's org-chart relationship changes (e.g. transfer, promotion, merge)
- **THEN** on their next login, the system re-queries OrgChart and updates `team_scope`
- **AND** any in-flight approval decisions made by this manager remain intact (historical audit trail preserved)

---

## REQ-002: Approve/reject leave requests

**Description**: Managers with pending leave requests in their team scope can approve or reject them with comments. Approval updates the leave-ledger; rejection returns the request to the employee.

### Scenario: Manager reviews and approves leave

- **GIVEN** a manager viewing the "Approvals" tab in Verlof module
- **WHEN** they see a pending leave request from a direct report (employee name, leave type, dates, total days, remaining balance after approval, team conflicts)
- **THEN** they can click "Approve" (inline or bulk)
- **AND** the system updates `ApprovalRequest.manager_decision = "approved"`, `ApprovalRequest.manager_decided_at = now`, `ApprovalRequest.manager_decided_by_user_id = <manager_id>`
- **AND** the leave-ledger is updated (leave balance decremented)
- **AND** the employee receives a notification
- **AND** the request flows to the next step in the approval chain (HR sign-off if required by config, or direct to approved state)

### Scenario: Manager rejects leave with required comment

- **GIVEN** a manager viewing a pending leave request
- **WHEN** they click "Reject"
- **THEN** a comment field appears (required; error if empty)
- **AND** clicking "Submit" saves `ApprovalRequest.manager_decision = "rejected"`, `ApprovalRequest.manager_comment = "<text>"`, `ApprovalRequest.manager_decided_at = now`
- **AND** the request state reverts to draft (employee can re-submit with changes)
- **AND** the employee receives the rejection with the manager's comment

### Scenario: Manager escalates instead of approve/reject

- **GIVEN** a pending leave request that the manager cannot decide on (e.g. borderline policy question, conflict with skip-level guidance)
- **WHEN** they click "Escalate"
- **THEN** a dropdown offers: `hr-admin`, `skip-level-manager`, `works-council`
- **AND** clicking escalate saves `ApprovalRequest.escalation_target = "<target>"`, `ApprovalRequest.manager_decision = "escalated"`, `ApprovalRequest.manager_decided_at = now`
- **AND** the request is routed to the chosen escalation target
- **AND** the manager receives acknowledgment that the escalation was routed

### Scenario: Team scheduling conflicts are visible

- **GIVEN** a manager reviewing a leave request from employee A for dates 2026-06-01 to 2026-06-10
- **WHEN** employee B (also in the team) has approved leave for 2026-06-05 to 2026-06-08, or an approved event is scheduled
- **THEN** the manager sees a scheduling-conflict warning: "2 other team members already on leave 2026-06-05–06-08"
- **AND** the manager can still approve if the conflict is acceptable

---

## REQ-003: Approve/reject expense claims

**Description**: Managers review expense claims from their team with policy-flag warnings and can approve/reject for compliance. Approved claims flow to finance export; rejected claims return to employee.

### Scenario: Manager reviews expenses with policy warnings

- **GIVEN** a manager in the "Expenses" tab of Declaraties module
- **WHEN** they see a pending expense claim with receipt, amount, category, cost-center charge, and any policy warnings (e.g., "exceeds daily meal limit EUR 50")
- **THEN** they can review the attached receipt (thumbnail preview)
- **AND** approve or reject inline with optional comment (required on reject)
- **THEN** approval saves `ApprovalRequest.manager_decision = "approved"`, stores cost-center allocation, and routes claim to finance export
- **AND** rejection saves `ApprovalRequest.manager_decision = "rejected"`, `ApprovalRequest.manager_comment = "<reason>"`, and sends claim back to employee

### Scenario: Cost-center allocation by manager

- **GIVEN** an approved expense claim with a cost-center that the manager owns
- **WHEN** the claim moves to approved state
- **THEN** the cost-center budget is updated (`committed_eur` increases pending finance posting)
- **AND** the claim appears on the manager's "Cost-center budget" overview under "pending-finance"

---

## REQ-004: Team overview

**Description**: Managers view their team roster with current status and key metrics.

### Scenario: Manager views team grid

- **GIVEN** a manager navigating to "Team" in hrmq
- **WHEN** the page loads
- **THEN** they see a data-grid with columns: photo, name, role, contract-end date (if applicable), current leave-balance, YTD verzuim-percentage, last performance-review date, status pill
- **AND** status pill is one of: `active` (green), `on-leave` (blue), `sick` (orange), `off-boarding` (grey)
- **AND** clicking a row opens the employee's read-only detail view (not editable by manager)
- **AND** the grid is sortable by name, role, contract-end date, leave-balance, verzuim%

### Scenario: Filter team by status

- **GIVEN** a manager viewing the team grid
- **WHEN** they click "Filter" and select status = "on-leave"
- **THEN** the grid shows only employees currently on leave
- **AND** each row shows the leave end-date in a "returning" tooltip

---

## REQ-005: Verzuim monitoring

**Description**: Managers monitor team sick-days, verzuim percentages, and Wet Verbetering Poortwachter milestones.

### Scenario: Manager views verzuim dashboard

- **GIVEN** a manager in the "Verzuim" tab (Verlof & verzuim module)
- **WHEN** the page loads
- **THEN** they see summary stats: total sick-days YTD, team verzuim-percentage (sick-hours / available-hours), meldingsfrequentie (frequency of sick-leave reporting), average-duration per sick-episode
- **AND** an alert if team-percentage exceeds the configured threshold (default 5.0% — Verbaan-norm; configurable per ManagerDashboardConfig.verzuim_threshold_alert)
- **AND** a list of currently-sick employees: name, start-date, expected-return date, days-so-far, and WVP milestone status

### Scenario: WVP milestones tracked

- **GIVEN** an employee sick since 2026-04-10 (day 1 of illness)
- **WHEN** the manager views the employee in the verzuim list
- **THEN** they see milestone tracking:
  - **Day 6**: Probleemanalyse (analysis due)
  - **Day 8**: Plan van aanpak (action plan due)
  - **Day 42**: UWV melding (notification to UWV due)
- **AND** any overdue milestones are highlighted (red)
- **AND** a link to the sick-leave record to update status or upload required documents

### Scenario: Alert on high verzuim

- **GIVEN** a team with verzuim-percentage = 6.2% (above the Verbaan-norm default of 5.0%)
- **WHEN** the manager loads the verzuim tab
- **THEN** a prominent alert appears: "Team verzuim 6.2% exceeds threshold (5.0%)"
- **AND** the alert is configurable: manager can set `ManagerDashboardConfig.verzuim_threshold_alert` to a different value (e.g., 8.0%)

---

## REQ-006: Performance review initiation

**Description**: Managers can initiate performance reviews for direct reports when the review cycle is due.

### Scenario: Manager initiates performance review

- **GIVEN** a direct report with last performance-review date older than the organization-configured cycle (default 12 months)
- **WHEN** the manager navigates to the employee's detail page
- **THEN** an "Initiate review" button is prominently displayed (only if review is due)
- **AND** clicking it opens the review template, pre-fills employee + manager + period, and creates a draft
- **AND** the manager can close the dialog and return later; the draft is saved

### Scenario: Manager completes review over multiple sessions

- **GIVEN** a manager with a draft performance review open in the dialog
- **WHEN** they fill in feedback sections and close the dialog without submitting
- **THEN** the draft is saved in the `performance-review-initiations` register (OpenRegister entity)
- **AND** on next login, the manager sees "1 draft review in progress" and can re-open it
- **AND** once complete, they click "Submit to HR" and the review flows to the performance-reviews app for HR finalization

---

## REQ-007: Cost-center budget overview

**Description**: Cost-center owners view budget, YTD spending, commitments, variance, and forecasts.

### Scenario: Manager views cost-center budget

- **GIVEN** a manager who owns one or more cost-centers (e.g., department head)
- **WHEN** they open the "Budget" tab in the manager portal (or Dashboard widget)
- **THEN** they see a table per cost-center: budget for current fiscal year, YTD spent, committed (pending finance approval), variance-%, and 3-month forecast
- **AND** variance-% is (spent+committed) / budget * 100
- **AND** 3-month forecast is based on YTD pace: if 6 months have elapsed with 50% spend, forecast predicts 100% at year-end
- **AND** sorting by variance-% shows which cost-centers are at risk of overspend

### Scenario: Budget alert on threshold

- **GIVEN** a cost-center with (spent+committed) = EUR 82,000 / budget EUR 100,000 (82%)
- **WHEN** the manager's configured threshold (ManagerDashboardConfig.budget_alert_threshold_pct = 80) is exceeded
- **THEN** the cost-center row highlights (yellow) with an "80% spent" indicator
- **AND** the manager can adjust the threshold in settings

---

## REQ-008: Privacy fence — no peers, no skip-levels by default

**Description**: Managers cannot access data outside their team scope. All out-of-scope requests return 403 with audit logging.

### Scenario: Manager attempts to query peer team

- **GIVEN** a manager (user A) with team scope = [user-1, user-2, user-3]
- **WHEN** they attempt to view employee details for user-5 (belongs to a peer manager, not in scope)
- **THEN** the API returns HTTP 403 with error message "outside your team scope"
- **AND** no employee data is included in the error response
- **AND** the access attempt is logged to the audit trail: `{ user: "user-a", action: "view-employee", target: "user-5", result: "denied-out-of-scope", timestamp: "..." }`

### Scenario: Manager queries skip-level team (not authorized by default)

- **GIVEN** a manager of team B, whose manager (skip-level) oversees team A
- **WHEN** they attempt to list employees in team A
- **THEN** the API returns 403 "insufficient scope"
- **AND** a separate spec (skip-level-manager-delegation) defines how to grant explicit cross-team access

### Scenario: HR-admin has unrestricted access

- **GIVEN** an HR-admin user
- **WHEN** they query any employee
- **THEN** no privacy fence applies; they receive full access (existing behaviour preserved)

---

## Cross-requirement: Audit trail

All manager decisions (approvals, rejections, escalations, reviews, config changes) are logged with:
- `user_id`, `action`, `target_entity` (ApprovalRequest, Employee, etc.), `decision` (approved/rejected/escalated), `timestamp`, `changes` (before/after)
- Accessible to HR-admin and audit systems via the standard AuditTrailService (no special manager audit endpoint)

---

## Standards Compliance

- **AVG / GDPR Art. 5(1)(c)**: Data minimization — managers see only their team scope
- **NEN 7510**: Segregation of duties — manager role separate from admin; managers cannot bulk-edit payroll, adjust CAO settings, or create users
- **Wet Verbetering Poortwachter**: Milestones tracked and surfaced in verzuim monitoring (week 6, 8, 42)
- **Verbaan-norm**: 5.0% default verzuim alert threshold
