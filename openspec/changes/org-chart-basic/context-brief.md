---
status: draft
app: hrmq
spec: org-chart-basic
target_users: [hr-admin, manager, employee, executive]
estimated_effort: M
depends_on: [employee-management]
---

# Organisational Chart (Basic)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Medewerkers › Organogram

**Rationale:** Org-chart view.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

A first-class organisational-structure entity with hierarchical reporting relationships, cost-centers, and departments, plus an interactive visualisation. Currently hrmq stores `Employee.manager_id` as a single foreign-key — flat, unversioned, and impossible to answer questions like "what did the structure look like before the December reorg?". This spec defines the OrgChart entity with effective-dating so historical structures are queryable, plus the rendering surface (D3.js tree) and the export formats (PNG, PDF, SVG, JSON for round-tripping into Visio / Lucidchart).

This is the "basic" tier — supports a single primary hierarchy. Matrix / dotted-line / multi-hierarchy support is deferred to a separate `org-chart-matrix` spec.

## Data Model

**OrgUnit** (new entity, represents a department / cost-center / team):
- `id`, `name`, `code` (short identifier, e.g. "ENG-001")
- `unit_type`: enum (`company`, `division`, `department`, `team`, `cost-center`, `project`)
- `parent_unit_id`: uuid nullable (root = null)
- `head_user_id`: uuid nullable (unit-lead)
- `cost_center_code`: string nullable (for finance integration)
- `headcount_budget`: int
- `valid_from`: date
- `valid_until`: date nullable (open-ended = current)

**OrgAssignment** (new entity, links employee to org-unit + manager over time):
- `id`, `employee_user_id`, `org_unit_id`, `reports_to_user_id`
- `role_in_unit`: string (e.g. "Senior Engineer", "Lead")
- `is_primary`: boolean (employee may have multiple assignments; one is primary for reporting)
- `valid_from`: date
- `valid_until`: date nullable

**OrgChartSnapshot** (materialised view, cached):
- `snapshot_date`, `tree_json` (full nested structure as of date), `generated_at`

## Requirements

### REQ-001: Create org-unit hierarchy

**GIVEN** an HR-admin building an initial org-chart
**WHEN** they create OrgUnits via the UI or import a CSV
**THEN** the system validates parent_unit_id references exist (or null for root), enforces no cycles, enforces unique codes within a parent, and stores valid_from=today by default

### REQ-002: Assign employees to org-units

**GIVEN** an OrgUnit and an employee
**WHEN** an HR-admin creates an OrgAssignment
**THEN** the system creates the assignment with valid_from=today, marks it as is_primary if the employee has no other open primary assignment, and triggers re-computation of any cached OrgChartSnapshots for today onward

### REQ-003: Visualisation — interactive tree

**GIVEN** any user with read access to the org-chart
**WHEN** they open the OrgChart view
**THEN** a D3.js horizontal tree renders with collapsible nodes, each node showing org-unit name, code, headcount (actual / budget), and the unit-head's photo + name; clicking a node expands its children, double-clicking navigates to the unit detail page

### REQ-004: Effective-dating — historical view

**GIVEN** any user viewing the OrgChart
**WHEN** they use the date-picker to select a historical date (e.g. 2025-09-01)
**THEN** the tree re-renders showing the structure as of that date, derived from OrgUnit + OrgAssignment records whose validity range includes that date; cached OrgChartSnapshots are used if available, else computed on-demand and cached

### REQ-005: Reorg — bulk re-parent

**GIVEN** an HR-admin executing a reorg
**WHEN** they select multiple OrgUnits and choose "Re-parent to..."
**THEN** the system closes the existing validity (valid_until=yesterday) for affected parent-links and opens new validity records (valid_from=reorg-date) with the new parent, preserving the historical view; affected OrgAssignments retain their employee links unless explicitly transferred

### REQ-006: Cost-center surfacing

**GIVEN** an OrgUnit with `cost_center_code` set
**WHEN** finance apps (shillinq, expense-management) query for cost-center budgets or charges
**THEN** they can resolve cost_center_code to OrgUnit, get the current head_user_id (= cost-center owner), and route approval requests / invoice charges accordingly

### REQ-007: Export formats

**GIVEN** any user viewing the OrgChart
**WHEN** they choose Export
**THEN** they can download: PNG (raster screenshot of current view), PDF (A3 landscape with all expanded), SVG (vector for embedding in slides), or JSON (full tree with all metadata for round-tripping into Visio / Lucidchart / Holaspirit)

### REQ-008: Reporting-line derivation

**GIVEN** an employee with an OrgAssignment
**WHEN** any app queries `who is this employee's manager?`
**THEN** the API resolves OrgAssignment.reports_to_user_id for the primary assignment valid as-of the query date (default: today); if reports_to_user_id is null, falls back to the head_user_id of the assigned OrgUnit; if still null, returns the head of the parent OrgUnit (transitive lookup, max 5 levels)

## Standards & References

- **ISO 30414** — Human capital reporting (org-structure as required disclosure for large orgs)
- **SHRM** — Org-design best practices
- **D3.js v7** — tree-layout API for visualisation
- **OrgChart standards** — no formal standard, but OrgChartJS JSON schema and Holaspirit's org-structure export are de-facto interchange formats

## Cross-app Coordination

- **employee-management** — Employee.user_id is the join key for OrgAssignment
- **manager-self-service** — depends on this spec; manager team_scope derives from OrgAssignment.reports_to_user_id
- **openregister** — OrgUnit + OrgAssignment schemas live in the shared `org` register so finance / project apps can reference cost-centers
- **mydash** — KPI tiles can group by org-unit (headcount, salary-cost, verzuim) using OrgUnit hierarchy
- **n8n** — onboarding workflow creates OrgAssignment as part of new-hire flow; offboarding closes validity

## Target Users

Primary: HR-admins (build + maintain), managers (consume — see their team), employees (consume — see where they fit). Secondary: executives (export PDF for board decks), finance (cost-center routing). Out of scope: matrix / dotted-line reporting (separate spec), Holaspirit-style role-based org (separate spec), spans-of-control analytics (separate spec).
