---
status: draft
app: hrmq
spec: manager-self-service
target_users: [line-manager, team-lead, department-head, cost-center-owner]
estimated_effort: L
depends_on: [employee-management, leave-administration, expense-management, performance-reviews, org-chart-basic]
---

# Manager Self-Service Portal

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Dashboard (manager-widgets) + scoped acties in Verlof, Salarissen, Declaraties

**Rationale:** Manager-rol view.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

A dedicated manager-facing portal that surfaces only the data and actions a line-manager needs for their direct team, separated from the HR-admin view (which sees everyone) and the employee self-service view (which sees only themselves). Managers today either get full HR-admin rights they should not have (privacy risk, AVG-exposure) or get nothing and must email HR for every leave approval — both fail. This spec defines a scoped role with cost-center / org-chart based data filtering and the four operational workflows managers actually do daily: approvals, team-overview, verzuim-monitoring, and performance reviews.

## Data Model

**Role.manager** (new system role, in addition to `admin`, `hr-admin`, `employee`):
- Scope: derived from `OrgChart.reports_to_user_id` matching the manager's user_id (transitive: indirect reports visible up to N levels, configurable)
- Permissions: read team data, approve/reject team requests, initiate performance reviews for direct reports

**ApprovalRequest** (existing entity, extended):
- `manager_decision`: enum (`pending`, `approved`, `rejected`, `escalated`)
- `manager_comment`: text
- `manager_decided_at`: timestamp
- `manager_decided_by_user_id`: uuid
- `escalation_target`: enum (`hr-admin`, `skip-level-manager`, `works-council`)

**ManagerDashboardConfig** (per-user preferences):
- `widget_order`: array
- `verzuim_threshold_alert`: decimal (default 5.0% — Verbaan-norm trigger)
- `budget_alert_threshold_pct`: int (default 80)

**CostCenterBudget** (existing, surfaces in manager view):
- `cost_center_id`, `fiscal_year`, `budget_eur`, `spent_ytd_eur`, `committed_eur`

## Requirements

### REQ-001: Manager scope derivation from org-chart

**GIVEN** a user with the `manager` role
**WHEN** they log in
**THEN** the system queries OrgChart for all users with `reports_to_user_id` matching the manager's user_id (direct reports), plus optionally N levels deeper (default 1 — direct only), and stores this list as the manager's `team_scope` for the session

### REQ-002: Approve/reject leave requests

**GIVEN** a manager with pending leave-requests in their team_scope
**WHEN** they open the "Approvals" tab
**THEN** they see a list of pending requests with employee name, leave type, dates, total days, remaining balance after approval, and any scheduling conflicts within the team; they can approve/reject inline with required comment on reject; the decision triggers a notification to the employee and updates the leave-balance ledger

### REQ-003: Approve/reject expense claims

**GIVEN** a manager with pending expense claims in their team_scope
**WHEN** they open the "Expenses" tab
**THEN** they see pending claims with receipt thumbnails, amounts, expense categories, cost-center charge, and policy-flag warnings (e.g. "exceeds daily meal limit"); they approve/reject inline; approved claims flow to the finance-export queue, rejected claims return to the employee for correction

### REQ-004: Team overview

**GIVEN** a manager
**WHEN** they open "Team"
**THEN** they see a grid of direct reports with: photo, name, role, contract-end date (if applicable), current leave-balance, YTD verzuim-percentage, last performance-review date, and a status pill (active / on-leave / sick / off-boarding); each row links to a read-only employee detail view

### REQ-005: Verzuim monitoring

**GIVEN** a manager
**WHEN** they open "Verzuim"
**THEN** they see team verzuim-cijfers: total sick-days YTD, verzuim-percentage (sick-hours / available-hours), meldingsfrequentie, average duration, and a list of currently-sick employees with start-date and Wet Verbetering Poortwachter milestone deadlines; alerts trigger when team-percentage exceeds the configured threshold

### REQ-006: Performance review initiation

**GIVEN** a manager and a direct report whose last performance review is older than the organisation-configured cycle (default 12 months)
**WHEN** the manager navigates to the employee's profile
**THEN** an "Initiate review" button is prominently displayed; clicking it opens the review template, pre-fills the employee + manager + period, and saves a draft that can be completed asynchronously over multiple sessions before submission to HR

### REQ-007: Cost-center budget overview

**GIVEN** a manager who owns one or more cost-centers
**WHEN** they open "Budget"
**THEN** they see per-cost-center: budget for current fiscal year, YTD spent (salaris + expense-claims + commitments), variance %, and a 3-month forecast based on YTD pace; alerts when spent+committed exceeds the configured threshold percentage

### REQ-008: Privacy fence — no peers, no skip-levels by default

**GIVEN** a manager
**WHEN** they query the API for any employee outside their team_scope
**THEN** the API returns 403 with a clear "outside your team scope" error, no employee data is returned in error responses or logs, and the access attempt is recorded in the audit log; HR-admins explicitly granting cross-team access must do so via a documented delegation workflow (separate spec)

## Standards & References

- **Wet Verbetering Poortwachter** — verzuim milestone deadlines (week 6 probleemanalyse, week 8 plan van aanpak, week 42 melding UWV, etc.)
- **AVG / GDPR Art. 5(1)(c)** — data minimisation; manager sees only what is necessary for the management task
- **NEN 7510** — segregation of duties between HR-admin and line-management
- **Verbaan-norm** — verzuim-percentage benchmark (default alert at 5%)

## Cross-app Coordination

- **org-chart-basic** — manager scope derives from OrgChart reporting structure; this spec depends on org-chart being implemented first
- **performance-reviews** — manager triggers review cycles; performance-reviews app owns the template engine
- **openregister** — `Role` and `ApprovalRequest` schemas live in hrmq register; cost-center budgets live in shared `org` register so finance apps can read them
- **mydash** — manager-portal landing page can be replaced by a mydash dashboard for managers who prefer KPI-tile view
- **n8n** — escalation flows (e.g. auto-escalate to skip-level after 5 days pending) run as n8n workflows

## Target Users

Primary: Line-managers, team-leads (1-15 direct reports), department-heads (15-50 indirect). Secondary: cost-center owners (project-managers without formal reports but with budget responsibility). Out of scope: executive dashboards (separate spec), skip-level / HRBP delegation (separate spec).
