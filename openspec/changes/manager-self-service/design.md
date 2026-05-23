# Design: Manager Self-Service Portal

## Architecture Overview

The Manager Self-Service Portal is built using OpenRegister entities and role-based access control. It does not introduce a new app; instead, it:
1. Adds a new system role (`manager`) with org-chart-derived scope
2. Extends existing entities (ApprovalRequest, Dashboard) with manager decision fields
3. Adds manager-scoped views and widgets to existing modules (Verlof, Salarissen, Declaraties, Dashboard)
4. Implements privacy-fence authorization at the API level via PropertyRbacHandler

### Data Model

#### New Schema: ManagerDashboardConfig

```json
{
  "name": "ManagerDashboardConfig",
  "description": "Per-user manager portal preferences and alert thresholds",
  "type": "object",
  "properties": {
    "id": { "type": "uuid" },
    "user_id": { "type": "uuid", "description": "Reference to User" },
    "widget_order": { "type": "array", "items": { "type": "string" }, "default": ["leave-pending", "team-status", "verzuim", "budget"] },
    "verzuim_threshold_alert": { "type": "number", "default": 5.0, "description": "Verzuim % threshold for alert (Verbaan-norm)" },
    "budget_alert_threshold_pct": { "type": "integer", "default": 80, "description": "Cost-center budget % threshold for alert" },
    "max_report_depth": { "type": "integer", "default": 1, "description": "Transitive report levels (1=direct only)" },
    "created_at": { "type": "string", "format": "date-time" },
    "updated_at": { "type": "string", "format": "date-time" }
  },
  "required": ["id", "user_id"]
}
```

#### Extended Schema: ApprovalRequest

Add to existing `ApprovalRequest` schema:

```json
{
  "manager_decision": { "type": "string", "enum": ["pending", "approved", "rejected", "escalated"], "description": "Manager approval decision" },
  "manager_comment": { "type": "string", "description": "Manager comment on rejection or escalation" },
  "manager_decided_at": { "type": "string", "format": "date-time", "description": "When manager made decision" },
  "manager_decided_by_user_id": { "type": "uuid", "description": "Which manager made the decision" },
  "escalation_target": { "type": "string", "enum": ["hr-admin", "skip-level-manager", "works-council"], "description": "Where escalated to (if applicable)" }
}
```

#### Existing Schemas (Extended)

- **Role** — add new system role: `manager` (in addition to `admin`, `hr-admin`, `employee`)
- **OrgChart** — already has `reports_to_user_id` (used for scope derivation)
- **CostCenterBudget** — already exists; surfaced in manager "Budget" tab
- **Employee** — existing; linked via team scope
- **ApprovalRequest** — existing; extended with manager fields above

### Role-Based Access Control

#### New Role: `manager`

- **Scope derivation**: OrgChart.reports_to_user_id = manager_user_id (transitive, configurable depth per ManagerDashboardConfig.max_report_depth)
- **Permissions**:
  - Read: all employees in team scope, their leave/expense requests, their performance review history
  - Approve/reject: leave requests, expense claims (within team scope)
  - Initiate: performance reviews for direct reports
  - Budget: view and forecast cost-centers they own
  - Cannot: bulk edit payroll, adjust CAO settings, create users, delete records, access outside team scope
- **Privacy fence**: All queries filtered by team scope; out-of-scope requests return 403

### UI Layout

#### Dashboard (Verlof - Salarissen - Declaraties)

Manager-role gets default widget order (customizable via ManagerDashboardConfig):
1. **Leave Pending** — count of pending leave requests, quick-approve actions
2. **Team Status** — grid of team members (status pill, leave balance, verzuim%)
3. **Verzuim** — team sick-days YTD, %, alert if >threshold
4. **Budget** — cost-center spend-to-date vs. budget forecast

#### Verlof module — "Approvals" sub-page

- List of pending leave requests in team scope
- Columns: employee name, leave type, dates, total days, remaining balance after approval, team conflicts
- Inline actions: Approve, Reject (with comment), Escalate
- Filters: by leave type, date range, status

#### Salarissen module — "Expenses" sub-page (scoped to manager)

- List of pending expense claims in team scope
- Columns: employee name, amount, category, cost-center, policy warnings
- Inline actions: Approve, Reject (with comment)
- Receipt preview (thumbnail)

#### Team page (Manager view of Medewerkers)

- Data-grid with: photo, name, role, contract-end date, leave-balance, YTD verzuim%, last review date, status pill
- Sortable and filterable by status, role, contract-end
- Click row → read-only employee detail view

#### Verzuim tab (Verlof & verzuim module — manager view)

- Summary: total sick-days YTD, team verzuim-%, meldingsfrequentie, avg duration
- Alert: if team% > threshold
- List: currently-sick employees with start-date, expected-return, WVP milestone status

#### Budget page (Manager-owned cost-centers)

- Table per cost-center: budget YTD, spent, committed, variance-%, 3-month forecast
- Alerts: highlight if (spent+committed) > threshold%
- Drill-down: click cost-center → breakdown by expense category

### Integration Points

1. **org-chart-basic** — used for scope derivation; manager scope = transitive reports_to
2. **leave-administration** — manager approves before HR sign-off (optional chain)
3. **expense-management** — manager pre-checks before finance export
4. **performance-reviews** — manager initiates, performance-reviews app owns template
5. **openregister** — ManagerDashboardConfig stored here; ApprovalRequest extended here
6. **audit-trail** — all manager decisions logged via standard AuditTrailService
7. **n8n** — optional: escalation workflows (auto-escalate if pending >5 days)

### Authorization Workflow

```
Manager leave approval flow:
Employee submits leave → ApprovalRequest.state = "pending_manager"
Manager approves → ApprovalRequest.manager_decision = "approved", ApprovalRequest.state = "approved_manager"
(optional) HR signs off → ApprovalRequest.state = "approved" (final)
Employee notified at each step

Manager expense approval flow:
Employee submits expense → ApprovalRequest.state = "pending_manager"
Manager approves → ApprovalRequest.manager_decision = "approved", ApprovalRequest.state = "approved_manager"
Finance exports → ApprovalRequest.state = "posted"
(if rejected) Employee corrects and re-submits
```

## Seed Data

### Organization Context

All seed data uses Dutch organization types and realistic names from the Netherlands:

- **Municipality (Gemeente Zwolle)** — 450 employees, 3 departments, 12 cost-centers
- **Consultancy (Nexus Consulting)** — 80 employees, 4 teams
- **Transit Agency (RET Rotterdam)** — 200 employees, transport operations

### ManagerDashboardConfig

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "ManagerDashboardConfig",
      "slug": "config-manager-001-zwolle"
    },
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "user_id": "d9f10f5c-1234-4567-89ab-cdef01234501",
    "widget_order": ["leave-pending", "team-status", "verzuim", "budget"],
    "verzuim_threshold_alert": 5.0,
    "budget_alert_threshold_pct": 80,
    "max_report_depth": 1,
    "created_at": "2026-04-15T09:30:00Z",
    "updated_at": "2026-05-10T14:22:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "ManagerDashboardConfig",
      "slug": "config-manager-002-nexus"
    },
    "id": "550e8400-e29b-41d4-a716-446655440002",
    "user_id": "a1b2c3d4-5678-90ab-cdef-0123456789ab",
    "widget_order": ["budget", "leave-pending", "team-status"],
    "verzuim_threshold_alert": 6.5,
    "budget_alert_threshold_pct": 75,
    "max_report_depth": 2,
    "created_at": "2026-04-20T10:15:00Z",
    "updated_at": "2026-05-08T11:45:00Z"
  }
]
```

### ApprovalRequest (Extended with Manager Fields)

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "ApprovalRequest",
      "slug": "leave-req-001-petra-zwolle"
    },
    "id": "660e8400-e29b-41d4-a716-446655440001",
    "employee_id": "e5f67890-1234-5678-9abc-def012345601",
    "request_type": "leave",
    "leave_type": "vakantie",
    "start_date": "2026-06-01",
    "end_date": "2026-06-10",
    "total_days": 8,
    "state": "pending_manager",
    "manager_decision": "approved",
    "manager_comment": "Goedgekeurd. Team dekking geregeld.",
    "manager_decided_at": "2026-05-22T13:45:00Z",
    "manager_decided_by_user_id": "d9f10f5c-1234-4567-89ab-cdef01234501",
    "escalation_target": null,
    "created_at": "2026-05-20T09:00:00Z",
    "updated_at": "2026-05-22T13:45:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "ApprovalRequest",
      "slug": "leave-req-002-jan-zwolle"
    },
    "id": "660e8400-e29b-41d4-a716-446655440002",
    "employee_id": "e5f67890-1234-5678-9abc-def012345602",
    "request_type": "leave",
    "leave_type": "ziekteverlof",
    "start_date": "2026-05-19",
    "end_date": null,
    "total_days": null,
    "state": "pending_manager",
    "manager_decision": "pending",
    "manager_comment": null,
    "manager_decided_at": null,
    "manager_decided_by_user_id": null,
    "escalation_target": null,
    "created_at": "2026-05-19T08:30:00Z",
    "updated_at": "2026-05-19T08:30:00Z"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "ApprovalRequest",
      "slug": "expense-req-001-anouk-nexus"
    },
    "id": "660e8400-e29b-41d4-a716-446655440003",
    "employee_id": "e5f67890-5678-9abc-def0-123456789abc",
    "request_type": "expense",
    "amount": 145.50,
    "currency": "EUR",
    "category": "meal",
    "cost_center_id": "cc-nexus-001",
    "policy_warnings": ["Exceeds daily meal limit EUR 50"],
    "state": "pending_manager",
    "manager_decision": "rejected",
    "manager_comment": "Dagelijks limiet overschreden. Correctie nodig.",
    "manager_decided_at": "2026-05-21T16:00:00Z",
    "manager_decided_by_user_id": "a1b2c3d4-5678-90ab-cdef-0123456789ab",
    "escalation_target": null,
    "created_at": "2026-05-21T09:15:00Z",
    "updated_at": "2026-05-21T16:00:00Z"
  }
]
```

### Role (Manager System Role)

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "Role",
      "slug": "system-role-manager"
    },
    "id": "550e8400-e29b-41d4-a716-446655440099",
    "name": "manager",
    "display_name": "Manager",
    "description": "Line-manager: approves team leave/expenses, monitors verzuim, initiates reviews",
    "permissions": [
      "leave:approve_team",
      "expense:approve_team",
      "performance:initiate_review",
      "team:view_team_data",
      "budget:view_owned_costcenters"
    ],
    "scope_type": "team_based",
    "scope_derivation": "org_chart_reports_to",
    "created_at": "2026-04-01T00:00:00Z"
  }
]
```

### Employee Data (Seed Team Members)

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "Employee",
      "slug": "emp-petra-dijk-zwolle"
    },
    "id": "e5f67890-1234-5678-9abc-def012345601",
    "first_name": "Petra",
    "last_name": "Dijk",
    "email": "petra.dijk@zwolle.nl",
    "phone": "+31612345601",
    "role": "Coördinator HR",
    "department_id": "dept-zwolle-hr",
    "manager_id": "d9f10f5c-1234-4567-89ab-cdef01234501",
    "contract_end_date": null,
    "current_leave_balance": 16.5,
    "ytd_verzuim_percentage": 2.3,
    "status": "active"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Employee",
      "slug": "emp-jan-visser-zwolle"
    },
    "id": "e5f67890-1234-5678-9abc-def012345602",
    "first_name": "Jan",
    "last_name": "Visser",
    "email": "jan.visser@zwolle.nl",
    "phone": "+31687654302",
    "role": "Specialist Financiën",
    "department_id": "dept-zwolle-finance",
    "manager_id": "d9f10f5c-1234-4567-89ab-cdef01234501",
    "contract_end_date": null,
    "current_leave_balance": 12.0,
    "ytd_verzuim_percentage": 5.8,
    "status": "sick",
    "sick_since": "2026-05-19"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Employee",
      "slug": "emp-anouk-berg-nexus"
    },
    "id": "e5f67890-5678-9abc-def0-123456789abc",
    "first_name": "Anouk",
    "last_name": "Berg",
    "email": "anouk.berg@nexus.nl",
    "phone": "+31612345789",
    "role": "Junior Consultant",
    "department_id": "dept-nexus-consulting",
    "manager_id": "a1b2c3d4-5678-90ab-cdef-0123456789ab",
    "contract_end_date": "2026-12-31",
    "current_leave_balance": 18.5,
    "ytd_verzuim_percentage": 0.5,
    "status": "active"
  }
]
```

### CostCenterBudget (Manager View)

```json
[
  {
    "@self": {
      "register": "org",
      "schema": "CostCenterBudget",
      "slug": "cc-budget-zwolle-hr-2026"
    },
    "id": "550e8400-e29b-41d4-a716-446655440010",
    "cost_center_id": "cc-zwolle-hr",
    "cost_center_name": "HR Diensten",
    "fiscal_year": 2026,
    "budget_eur": 150000,
    "spent_ytd_eur": 98500,
    "committed_eur": 12000,
    "variance_pct": 73.7,
    "manager_id": "d9f10f5c-1234-4567-89ab-cdef01234501",
    "updated_at": "2026-05-23T00:00:00Z"
  },
  {
    "@self": {
      "register": "org",
      "schema": "CostCenterBudget",
      "slug": "cc-budget-nexus-consulting-2026"
    },
    "id": "550e8400-e29b-41d4-a716-446655440011",
    "cost_center_id": "cc-nexus-consulting",
    "cost_center_name": "Consulting Services",
    "fiscal_year": 2026,
    "budget_eur": 250000,
    "spent_ytd_eur": 156000,
    "committed_eur": 18500,
    "variance_pct": 69.8,
    "manager_id": "a1b2c3d4-5678-90ab-cdef-0123456789ab",
    "updated_at": "2026-05-22T00:00:00Z"
  }
]
```

## Reuse Analysis

### Existing OpenRegister Services Leveraged

- **ObjectService** — CRUD for ManagerDashboardConfig, ApprovalRequest (extended)
- **AuthorizationService** — role-based access control; manager scope derivation via `PropertyRbacHandler`
- **AuditTrailService** — all manager decisions logged automatically (no custom audit code)
- **NotificationService** — employee notifications on approve/reject (no custom notification service)
- **WorkflowEngineRegistry** — optional: escalation n8n workflows (separate spec)
- **CnDetailPage** — employee detail view (read-only for managers, uses standard detail-page component)
- **CnDataTable** — leave/expense approval lists (uses standard data-table with bulk actions)
- **CnFormDialog** — performance review initiation dialog (schema-driven)
- **CnDashboardPage** — manager dashboard widgets (GridStack-based, uses standard dashboard component)

### No Duplication

- **Search**: Verzuim filtering uses IndexService + CnFilterBar (no custom search)
- **Budget forecasting**: 3-month forecast is a calculated field in CostCenterBudget schema (declarative, not a new service)
- **Approval workflows**: ApprovalRequest state machine is declared in OpenRegister lifecycle (not a custom workflow engine)
- **Team scope**: Derived from OrgChart.reports_to via `_rbac` query filter (not a custom scope service)

All manager-specific behavior is achieved through:
1. Role-based access control (via AuthorizationService)
2. Schema extensions (manager_decision, manager_comment fields)
3. UI layout variants (dashboard widget order, scoped approval lists)
4. Declarative business logic in OpenRegister (see "Declarative-vs-imperative decision" section below)

## Declarative-vs-Imperative Decision (ADR-031)

### Manager Decision Lifecycle (Declarative)

**Behavior**: ApprovalRequest state transitions for manager approval.

**Path**: Declarative (OpenRegister schema-based).

**Why**: ApprovalRequest already has a state-machine lifecycle (pending → approved → signed-off). Manager decision is an additional state dimension that fits naturally into the existing declarative lifecycle pattern. Adding a custom `ManagerApprovalService` would duplicate state-transition logic already owned by openregister.

**Declaration** (in `lib/Settings/hrmq_register.json`):
```json
{
  "x-openregister-lifecycle": {
    "ApprovalRequest": {
      "states": ["pending", "pending_manager", "approved_manager", "approved", "rejected"],
      "transitions": [
        { "from": "pending", "to": "pending_manager", "trigger": "submit_to_manager" },
        { "from": "pending_manager", "to": "approved_manager", "trigger": "manager_approve" },
        { "from": "pending_manager", "to": "rejected", "trigger": "manager_reject" },
        { "from": "pending_manager", "to": "escalated", "trigger": "manager_escalate" },
        { "from": "approved_manager", "to": "approved", "trigger": "hr_signoff" }
      ],
      "fields": {
        "manager_decision": { "trigger": "manager_approve|manager_reject|manager_escalate", "set": "decision_type" },
        "manager_decided_at": { "trigger": "*", "set": "now()" },
        "manager_decided_by_user_id": { "trigger": "*", "set": "current_user.id" },
        "manager_comment": { "required_on": "manager_reject" }
      }
    }
  }
}
```

### Verzuim Alert Logic (Declarative)

**Behavior**: Team verzuim-percentage compared to threshold; alert if exceeded.

**Path**: Declarative (calculated field in schema + CnStatsBlock widget).

**Why**: The calculation (sick-hours / available-hours * 100) is a pure aggregation with no side effects. The alert is a UI condition (show red if threshold exceeded) that belongs in the widget, not a service.

**No custom `VerzuimAlertService` needed.**

### Performance Review Auto-Prompt (Declarative, with Scheduled Task)

**Behavior**: "Initiate review" button appears if last_review_date + cycle_months < today.

**Path**: Declarative with scheduled task.

**Why**: The condition (now() > last_review_date + organization.review_cycle_months) is a simple date comparison that can be calculated on the employee detail page. A scheduled job (n8n or ScheduledWorkflow) can optionally send proactive reminders, but the manager-initiated button does not need a service.

**Scheduled job declaration** (separate n8n spec):
```json
{
  "x-openregister-notifications": {
    "PerformanceReviewDue": {
      "trigger": "daily",
      "condition": "now() > last_review_date + cycle_months AND role == manager",
      "action": "send_notification",
      "template": "performance_review_due_reminder"
    }
  }
}
```

### Team Scope Derivation (Declarative via RBAC)

**Behavior**: Manager queries automatically filtered to team scope.

**Path**: Declarative via `PropertyRbacHandler` role-based filters.

**Why**: Scope derivation is a query-time filter (org-chart transitive reports_to), not a business rule. All data access is filtered by role; adding a custom `ManagerScopeService` would duplicate AuthorizationService.

**No custom service; authorization is handled at the API controller level.**

### Cost-Center Budget Forecasting (Declarative)

**Behavior**: 3-month forecast based on YTD pace.

**Path**: Declarative (calculated field in CostCenterBudget schema).

**Why**: Forecast is a deterministic calculation (spent_ytd / months_elapsed * 12) with no side effects. It belongs in the schema as a computed field, not a service.

**No custom `ForecastService` needed.**

### Summary

All behaviors are declarative:
- Lifecycle / state transitions → `x-openregister-lifecycle`
- Aggregations (verzuim%) → calculated fields
- Alerts / notifications → schema-declared conditions + UI widgets
- Access control / scope filtering → `PropertyRbacHandler` (RBAC)
- Scheduled reminders → n8n workflows (separate spec)

**No imperative services required.** Manager portal is purely declarative configuration layered on existing OpenRegister infrastructure.
