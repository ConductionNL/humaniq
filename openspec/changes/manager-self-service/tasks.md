# Tasks: Manager Self-Service Portal

## Backend: Data Model & Schema Registration

- [ ] **Create ManagerDashboardConfig schema in hrmq register**
  - Add to `lib/Settings/hrmq_register.json`
  - Schema properties: id, user_id, widget_order[], verzuim_threshold_alert, budget_alert_threshold_pct, max_report_depth, timestamps
  - Register in `components.schemas` and export via REST
  - Add seed objects (3-5 manager configurations from design.md)

- [ ] **Extend ApprovalRequest schema with manager decision fields**
  - Add to existing ApprovalRequest in `lib/Settings/hrmq_register.json`
  - New fields: manager_decision (enum), manager_comment, manager_decided_at, manager_decided_by_user_id, escalation_target
  - Update OpenAPI schema in register
  - Migration: ALTER TABLE approval_request ADD COLUMN manager_decision VARCHAR DEFAULT 'pending' (non-breaking, additive)

- [ ] **Register manager role in system roles**
  - Add `Role::MANAGER = 'manager'` constant to Role enum/class
  - Define permissions in a migration or fixture:
    - `leave:approve_team`
    - `expense:approve_team`
    - `performance:initiate_review`
    - `team:view_team_data`
    - `budget:view_owned_costcenters`
  - Manager role is system-defined, not tenant-editable

- [ ] **Add team-scope RBAC filter to AuthorizationService**
  - When a user with role=manager queries employees, automatically filter by OrgChart.reports_to_user_id (transitive, depth from ManagerDashboardConfig.max_report_depth)
  - Implement `PropertyRbacHandler` for manager role: `returns 403 for out-of-scope employee queries`
  - Update `ObjectService::findAll()` to apply scope filter via RBAC
  - Test: manager A cannot see manager B's employees (403 response)

- [ ] **Implement performance-review-initiation schema (OpenRegister)**
  - Schema: id, manager_id, employee_id, period_start, period_end, template_data (JSON), status (draft|submitted), created_at, updated_at
  - Enables async draft creation without submitting to performance-reviews app yet
  - Register in `lib/Settings/hrmq_register.json`
  - CRUD via ObjectService (no custom service)

---

## Backend: Approval Workflows

- [ ] **Implement manager leave approval controller**
  - Endpoint: `POST /api/hrmq/approval-requests/{id}/manager-approve`
  - Request body: `{ decision: "approved" | "rejected" | "escalated", comment: string, escalation_target?: string }`
  - Updates ApprovalRequest: manager_decision, manager_comment, manager_decided_at, manager_decided_by_user_id
  - Validation: manager must be in the approval chain for the request (employee_manager_id == current_user || escalated_to == current_user)
  - On approve: trigger leave-ledger update (if leave type) or route expense to finance (if expense type)
  - On reject: revert request state to draft, notify employee
  - On escalate: route to escalation_target (hr-admin or skip-level), notify escalation_target
  - Audit: log all changes via AuditTrailService

- [ ] **Implement manager expense approval controller**
  - Endpoint: `POST /api/hrmq/approval-requests/{id}/manager-approve`
  - Same pattern as leave approval
  - On approve: update CostCenterBudget.committed_eur (pending finance posting)
  - On reject: return to employee, do not commit cost-center budget

- [ ] **Declarative lifecycle for ApprovalRequest in hrmq_register.json**
  - Define state machine: pending → pending_manager → approved_manager → approved (or rejected)
  - Field setters: manager_decision=triggered_action, manager_decided_at=now(), manager_decided_by_user_id=current_user.id
  - Fields required_on: manager_comment required if manager_decision=rejected
  - Transitions trigger notifications (employee, manager, HR-admin)

---

## Backend: Team Scope & Privacy Fence

- [ ] **Implement team scope derivation in SessionService or AuthLoadListener**
  - On manager login: query OrgChart for reports_to_user_id = manager_id (transitive per max_report_depth)
  - Cache result in session or JWT claims (manager_team_scope = [user_id, ...])
  - On org-chart change: invalidate session cache (force re-login or refresh via webhook listener)
  - Test: manager with 5 direct reports has team_scope = [id1, id2, id3, id4, id5]

- [ ] **Enforce privacy fence in API middleware**
  - All queries to employees/approval-requests: filter by manager_team_scope
  - If requesting outside scope: return 403 "Employee outside your team scope" (no details in error)
  - Log access violation to audit trail: { action: "access_denied", user: manager_id, target: employee_id, reason: "out_of_scope" }
  - HR-admin role bypasses scope filter (existing behavior preserved)

- [ ] **Test privacy fence edge cases**
  - Manager cannot enumerate all employees (404 if pagination without filter)
  - Manager cannot use API search to find out-of-scope employees
  - Skip-level manager (without delegation) cannot see peer team (403)
  - Audit log contains no sensitive data in error responses

---

## Frontend: Manager Dashboard & Widgets

- [ ] **Create dashboard widget: leave-pending**
  - Component: displays count of pending leave requests in team, quick links
  - Data: query `ApprovalRequest` filtered by (manager_id = current_user AND request_type = leave AND manager_decision = pending)`
  - Action: "Review All" → navigate to Verlof > Approvals sub-page
  - Customizable: widget_order in ManagerDashboardConfig

- [ ] **Create dashboard widget: team-status**
  - Component: data-grid of team members with status pills (active/on-leave/sick/off-boarding)
  - Columns: photo, name, role, leave-balance, verzuim%, last-review-date
  - Data: query Employee filtered by team_scope
  - Click row → navigate to employee detail (read-only)
  - Filter: by status, role

- [ ] **Create dashboard widget: verzuim-summary**
  - Component: stats block (total sick-days YTD, team verzuim-%, meldingsfrequentie, avg duration)
  - Alert: if team% > ManagerDashboardConfig.verzuim_threshold_alert
  - Data: aggregation over Employee.sick_leave records
  - Action: "View Details" → navigate to Verlof > Verzuim tab

- [ ] **Create dashboard widget: cost-center-budget**
  - Component: mini-chart or table of cost-centers owned by manager (budget, spent, committed, variance%, forecast)
  - Data: query CostCenterBudget filtered by manager_id = current_user
  - Alert: highlight if (spent+committed) > budget_alert_threshold_pct
  - Customizable: threshold in ManagerDashboardConfig

- [ ] **Create manager dashboard layout (default)**
  - Use CnDashboardPage (GridStack-based)
  - Default widget order: leave-pending, team-status, verzuim, budget
  - Order customizable via ManagerDashboardConfig.widget_order
  - Persist changes on drag-drop via ObjectService.saveObject(ManagerDashboardConfig)

---

## Frontend: Leave Approvals Sub-page (Verlof module)

- [ ] **Create Approvals list view in Verlof module**
  - Route: `/apps/verlof?tab=manager-approvals` (or similar, per hrmq IA)
  - Component: CnDataTable with bulk actions
  - Columns: employee-name, leave-type, dates, total-days, remaining-balance-after-approval, team-conflicts
  - Data: query ApprovalRequest filtered by (request_type = leave AND manager_decision = pending AND employee in team_scope)
  - Filters: by leave-type, date-range, status
  - Inline actions: Approve, Reject (modal for comment), Escalate (dropdown)
  - Bulk actions: Approve selected, Reject selected

- [ ] **Implement Approve action**
  - Button click → confirm dialog (optional)
  - POST `/api/hrmq/approval-requests/{id}/manager-approve` with decision=approved
  - UI feedback: optimistic update, then server response
  - On error: show error toast

- [ ] **Implement Reject action**
  - Button click → modal with comment field (required)
  - POST with decision=rejected, comment=<text>
  - Confirm: "Employee will be notified and can re-submit"

- [ ] **Implement Escalate action**
  - Button click → dropdown (hr-admin, skip-level-manager, works-council)
  - Select target → confirm modal with comment field (optional)
  - POST with decision=escalated, escalation_target=<target>, comment=<text>

- [ ] **Team scheduling conflicts UI**
  - When viewing a leave request, display warning if other team members are approved for overlapping dates
  - Text: "2 other team members already on leave 2026-06-05–06-08"
  - Color: yellow (informational, not blocking approval)

---

## Frontend: Expense Approvals Sub-page (Salarissen module)

- [ ] **Create Expenses list view (manager scoped)**
  - Route: `/apps/salarissen?tab=manager-approvals` or similar
  - Component: CnDataTable
  - Columns: employee-name, amount, category, cost-center, policy-warnings, receipt-preview
  - Data: query ApprovalRequest filtered by (request_type = expense AND manager_decision = pending AND employee in team_scope)
  - Inline actions: Approve, Reject (modal for comment)
  - Policy warnings: display as red text or icon (e.g., "exceeds daily meal limit")

- [ ] **Implement policy-flag warnings**
  - On load: check ApprovalRequest.policy_warnings array
  - Display each warning in expense row (yellow/red background)
  - Example: "Exceeds daily meal limit EUR 50" (current: EUR 145.50)
  - Manager can still approve (policy violations are flagged, not blocking)

- [ ] **Implement Approve action for expenses**
  - POST `/api/hrmq/approval-requests/{id}/manager-approve` with decision=approved
  - Side effect: update CostCenterBudget.committed_eur += amount
  - Confirm: "Expense approved. Will flow to finance for posting."

- [ ] **Implement Reject action for expenses**
  - Modal with comment field (required)
  - POST with decision=rejected, comment=<text>
  - Confirm: "Expense rejected. Employee notified."
  - Do NOT update CostCenterBudget.committed_eur

---

## Frontend: Team Overview Page (Medewerkers module)

- [ ] **Create Team tab in Medewerkers (manager view)**
  - Route: `/apps/medewerkers?tab=team` or `/apps/medewerkers/team`
  - Component: CnDataTable with manager-role defaults
  - Columns: photo, name, role, contract-end-date, leave-balance, YTD verzuim%, last-review-date, status-pill
  - Data: query Employee filtered by employee in team_scope, ordered by status then name
  - Status pill: active (green) | on-leave (blue) | sick (orange) | off-boarding (grey)
  - Sortable by: name, role, contract-end-date, leave-balance, verzuim%, status
  - Filterable by: status, role, contract-status
  - Click row → navigate to employee detail (read-only for manager)

- [ ] **Read-only employee detail view for managers**
  - Route: `/apps/medewerkers/{employee_id}` (existing component, manager gets view-only variant)
  - Authorization: return 403 if employee not in team_scope
  - Sections: bio (name, role, photo), contact-info, contract, leave-balance, verzuim-history, performance-reviews, manager-approval-status
  - Manager-specific action: "Initiate Performance Review" button (visible if last review > cycle_months)
  - Manager cannot edit any fields (all inputs disabled/hidden)

---

## Frontend: Verzuim Monitoring Tab (Verlof & verzuim module)

- [ ] **Create Verzuim tab (manager view)**
  - Route: `/apps/verlof?tab=verzuim` or similar
  - Component: CnStatsBlock (summary) + CnDataTable (current sick employees)
  - Summary stats:
    - Total sick-days YTD (count of distinct sick-leave days)
    - Team verzuim-% = sick-hours / available-hours * 100
    - Meldingsfrequentie = count of separate sick-episodes
    - Avg duration = total sick-hours / meldingsfrequentie
  - Alert: if team% > ManagerDashboardConfig.verzuim_threshold_alert, show prominent banner (red)
  - Alert text: "Team verzuim 6.2% exceeds threshold (5.0%). Configure threshold in Settings."

- [ ] **Sick employees list with WVP milestones**
  - Table: name, start-date, expected-return-date, days-so-far, WVP-status
  - WVP milestones (Wet Verbetering Poortwachter):
    - Day 6: Probleemanalyse (analysis) — show as "Pending" or "Completed" checkbox
    - Day 8: Plan van aanpak (action plan) — same
    - Day 42: UWV melding (notification) — same
  - Overdue milestones: highlight red
  - Click employee → navigate to sick-leave record (read-only, can view attachments/notes)
  - Manager can add notes or upload required documents (via standard file-upload in sidebar)

- [ ] **Configure verzuim threshold**
  - Settings dialog: ManagerDashboardConfig.verzuim_threshold_alert (input, default 5.0)
  - Stored in ObjectService
  - On save: clear session cache (re-calculate alerts)

---

## Frontend: Performance Review Initiation

- [ ] **Add "Initiate Performance Review" button to employee detail**
  - Visibility condition: (last_performance_review_date == null OR last_performance_review_date < today - organization.review_cycle_months)
  - Organization.review_cycle_months = configurable in org settings (default 12)
  - Button text: "Initiate Review" or "Review Due — Click to Start"
  - Button color: blue (normal) or orange (overdue)

- [ ] **Create performance review initiation modal**
  - Component: CnAdvancedFormDialog (schema-driven)
  - Schema: performance-review-initiation (from design.md)
  - Pre-filled: manager_id, employee_id, period_start, period_end (default last-review-date + 12 months)
  - Template field: dropdown of available review templates (from performance-reviews app)
  - On "Save Draft": POST to ObjectService, create ManagerPerformanceReviewDraft
  - Dialog closes, confirmation toast: "Draft saved. You can return to complete it later."
  - On "Submit": same as save, but adds workflow trigger to send to performance-reviews app (separate spec)

- [ ] **Show draft-in-progress indicator**
  - In employee detail: if manager has draft review for this employee, show "Review in progress" badge
  - Click badge → re-open modal to resume editing
  - Cancel button: delete draft (confirm modal)

---

## Frontend: Cost-Center Budget Overview

- [ ] **Create Budget page (manager cost-center owners)**
  - Route: `/apps/hrmq/budget` or tab in manager portal
  - Component: CnDataTable of cost-centers
  - Columns: cost-center-name, budget-ytd, spent-ytd, committed, variance-%, 3-month-forecast
  - Data: query CostCenterBudget filtered by manager_id = current_user, fiscal_year = current_year
  - Sortable by: spent, variance-%, forecast
  - Alert: if (spent+committed) / budget > budget_alert_threshold_pct, highlight row yellow
  - Click row → drill-down breakdown by expense-category

- [ ] **Implement 3-month forecast calculation**
  - Formula: spent_ytd / months_elapsed * 12 (at year-end, assumes linear pace)
  - Example: 6 months elapsed, EUR 100k spent → forecast EUR 200k for year
  - Update monthly (or query-time)
  - Display with color: green if forecast <= budget, yellow if forecast > budget

- [ ] **Budget configuration in settings**
  - ManagerDashboardConfig.budget_alert_threshold_pct (default 80, input field)
  - On save: persist via ObjectService

---

## Authorization & Access Control

- [ ] **Deduplication Check: Verify no overlap with existing authorization**
  - Review AuthorizationService methods: are there existing manager scope filters?
  - Check: PermissionHandler, PropertyRbacHandler, does org-chart-basic provide scope derivation?
  - Document findings in design.md "Reuse Analysis" (already done; verify no changes needed)
  - Result: no duplication — manager scope is a new dimension, uses existing RBAC infrastructure

- [ ] **Test authorization edge cases**
  - Manager cannot approve their own leave request (403 or UI-hidden)
  - Manager cannot view employees transferred to another manager (403)
  - Manager can see an employee during handoff window (if transfer pending)
  - HR-admin can override manager decisions (if needed in policy)
  - Performance review initiated by manager has manager_id recorded (audit trail)

---

## Seed Data

- [ ] **Generate seed data for ManagerDashboardConfig**
  - Create 3-5 example configurations (design.md provides 2 examples)
  - Insert via `importFromApp()` mechanism with `force: false` (idempotent)
  - Slug uniqueness: config-manager-001-zwolle, config-manager-002-nexus, etc.

- [ ] **Generate seed data for ApprovalRequest (extended)**
  - Create 3-5 examples: 2 leave requests (approved, pending), 1-2 expense requests (approved, rejected)
  - Insert via importFromApp() with slug-matching deduplication
  - Slugs: leave-req-001-petra-zwolle, expense-req-001-anouk-nexus, etc.

- [ ] **Generate seed data for Employee team members**
  - Create 3-5 employees in manager scope (design.md provides 3 examples: Petra, Jan, Anouk)
  - Manager relationships: all report to manager-id (for team-scope testing)
  - Varied statuses: active (Petra), sick (Jan), on-leave, off-boarding
  - Insert via importFromApp()

- [ ] **Generate seed data for Role (manager system role)**
  - Create single "manager" role with defined permissions (design.md)
  - Insert via migration or fixture (one-time, not per tenant)
  - Document in tasks: "Role is system-defined and not tenant-editable"

- [ ] **Generate seed data for CostCenterBudget**
  - Create 2-3 cost-center budgets owned by seed managers (design.md provides 2 examples)
  - Realistic spend: YTD spent ~65-75% of budget (interesting variance% for testing)
  - Insert via importFromApp()

---

## Testing

- [ ] **Unit tests: approval workflow logic**
  - Test manager approve/reject/escalate transitions
  - Test required-comment validation on reject
  - Test escalation routing (hr-admin, skip-level, works-council)
  - Test audit logging

- [ ] **Unit tests: team scope derivation**
  - Test transitive reports_to query (1 level, 2 levels)
  - Test scope cache invalidation on org-chart change
  - Test manager with no reports (empty scope)

- [ ] **Unit tests: privacy fence**
  - Test 403 response for out-of-scope queries
  - Test audit log entries for denied access
  - Test HR-admin bypass

- [ ] **Integration tests: dashboard widgets**
  - Test leave-pending widget count accuracy
  - Test team-status grid with varied statuses
  - Test verzuim-% calculation
  - Test cost-center budget forecast

- [ ] **Integration tests: approval flows**
  - Test end-to-end leave approval: submit → pending_manager → approved_manager → approved
  - Test expense approval with cost-center budget update
  - Test rejection and re-submission
  - Test escalation routing and notification

- [ ] **Browser tests (team)**
  - Test manager login → dashboard widgets visible
  - Test Approvals tab: approve/reject inline actions
  - Test Team tab: row click → employee detail (read-only)
  - Test Verzuim tab: alert visible if threshold exceeded
  - Test Performance Review: initiate button visible, draft saves

---

## Documentation & Rollout

- [ ] **Update user documentation: manager role onboarding**
  - Create markdown guide: "Manager Self-Service Portal — Get Started"
  - Cover: how to approve leave/expenses, team overview, verzuim monitoring, budget, performance reviews
  - Example screenshots
  - Compliance note: AVG minimization, what data manager can and cannot see
  - Place in `docs/manager-portal/` or app help center

- [ ] **Update architecture documentation**
  - Add section to `docs/ARCHITECTURE.md`: "Manager Role & Scope Derivation"
  - Reference ADR-001 (IA), ADR-005 (security, privacy fence), ADR-023 (action authorization)
  - Example: manager scope = transitive reports_to from org-chart

- [ ] **Create support ticket templates for manager issues**
  - "I can't approve leave"
  - "I see a different employee in my team"
  - "Budget alert not showing"

---

## Compliance & Audit

- [ ] **Verify AVG compliance**
  - Manager sees only necessary data (team scope, not all employees) ✓
  - Data minimization: no bulk-export of team data (only dashboard, no download-all) ✓
  - Audit trail: all approvals logged ✓
  - Retention: approval records retained per organizational policy ✓

- [ ] **Verify NEN 7510 segregation of duties**
  - Manager cannot edit payroll, CAO settings, or create users (role restrictions) ✓
  - Manager cannot access HR-admin functions (separate role) ✓
  - HR-admin can override manager decisions (if needed in workflow) ✓

- [ ] **Verify Wet Verbetering Poortwachter compliance**
  - WVP milestones tracked in verzuim monitoring ✓
  - Overdue milestones highlighted for manager action ✓
  - Documentation: link to WVP requirements in design.md ✓

---

## Rollout Phases

### Phase 1: Foundation (Weeks 1-2)
- Schema registration (ManagerDashboardConfig, ApprovalRequest extended, Role)
- Team scope derivation, privacy fence
- Seed data generation

### Phase 2: Approvals (Weeks 3-4)
- Leave approval controller & frontend
- Expense approval controller & frontend
- Declarative lifecycle & notifications

### Phase 3: Dashboards & Views (Weeks 5-6)
- Dashboard widgets (leave-pending, team-status, verzuim, budget)
- Team overview page
- Verzuim monitoring tab

### Phase 4: Advanced Features (Weeks 7-8)
- Performance review initiation
- Cost-center budget overview
- Advanced filtering & customization

### Phase 5: Testing & Rollout (Weeks 9-10)
- Full test suite
- Browser testing with personas
- Documentation & training
- Soft launch (opt-in for early users)
- GA rollout

---

## Success Criteria

- [ ] All 4 artifact files exist and are approved
- [ ] All tasks completed and tested
- [ ] Manager can approve/reject team leave and expenses
- [ ] Team overview page shows accurate data (status, balance, verzuim%)
- [ ] Privacy fence: manager cannot access out-of-scope employees (403)
- [ ] Audit trail: all manager actions logged
- [ ] Performance review initiation: button visible when due, draft saves asynchronously
- [ ] Cost-center budget: forecasts accurate, alerts fire on threshold
- [ ] User documentation complete
- [ ] Browser tests passing (team + individual roles)
- [ ] No regression in existing leave-administration, expense-management, or dashboard features
