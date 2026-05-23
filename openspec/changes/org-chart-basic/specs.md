---
status: specs
change: org-chart-basic
created_at: 2026-05-23
author: Specter Intelligence
---

# Organisational Chart (Basic) — Specifications

## ADDED Requirements

### Requirement: REQ-001-ORGUNIT-CREATE

Create org-unit hierarchy with validation and effective-dating.

#### Scenario: REQ-001-01-Create-Root-Unit

- **GIVEN** an HR-admin on the org-chart management page with permission `org-units:write`
- **WHEN** they submit a form to create a new OrgUnit with:
  - name: "VDG Gemeente Amsterdam"
  - code: "VDGA"
  - unit_type: "company"
  - parent_unit_id: null (root)
  - head_user_id: "user-mayor"
  - cost_center_code: "CC-0001"
  - headcount_budget: 450
- **THEN** the system:
  - Validates head_user_id exists in openregister.users
  - Creates OrgUnit with valid_from=today, valid_until=null
  - Returns 201 Created with the new OrgUnit object
  - Invalidates org-chart snapshots from today onward

#### Scenario: REQ-001-02-Create-Child-Unit

- **GIVEN** an OrgUnit "VDGA" (id: ou-001) already exists
- **WHEN** they create a child unit with:
  - name: "Dienst Burgerzaken"
  - code: "DBC"
  - parent_unit_id: "ou-001"
  - head_user_id: "user-dir-burgerzaken"
- **THEN** the system:
  - Validates parent_unit_id exists and is not deleted
  - Creates OrgUnit with valid_from=today
  - Returns 201 Created
  - **AND** validates that no sibling of ou-001 has code "DBC" (code unique within parent)

#### Scenario: REQ-001-03-Reject-Cycle

- **GIVEN** an OrgUnit hierarchy: ou-001 (VDGA) → ou-002 (DBC) → ou-003 (Team-Pass)
- **WHEN** they attempt to set ou-001.parent_unit_id = "ou-003" (creating a cycle)
- **THEN** the system:
  - Detects the cycle (up to 5 ancestors)
  - Rejects with 400 Bad Request
  - Returns error: "Parent unit would create a cycle in the hierarchy"

#### Scenario: REQ-001-04-Reject-Duplicate-Code-Sibling

- **GIVEN** ou-002 (code "DBC") is a child of ou-001
- **WHEN** they attempt to create another child of ou-001 with code "DBC"
- **THEN** the system:
  - Rejects with 409 Conflict
  - Returns error: "Code 'DBC' is already in use by a sibling unit"

---

### Requirement: REQ-002-ORGASSIGNMENT-CREATE

Assign employees to org-units with effective-dating and primary-assignment enforcement.

#### Scenario: REQ-002-01-Create-Primary-Assignment

- **GIVEN** an HR-admin creating an OrgAssignment
- **WHEN** they submit:
  - employee_user_id: "user-anna"
  - org_unit_id: "ou-003"
  - reports_to_user_id: "user-lead-passports"
  - role_in_unit: "Medeweker Burgerlijke Stand"
  - is_primary: true
  - valid_from: today
- **THEN** the system:
  - Validates employee_user_id exists in openregister.users
  - Validates org_unit_id exists and is not deleted
  - Checks if employee already has an open primary assignment (max 1 at a time)
  - If user has no other open primary assignment, creates with is_primary=true
  - Returns 201 Created with new OrgAssignment
  - **AND** invalidates org-chart snapshots from today onward

#### Scenario: REQ-002-02-Reject-Multiple-Primary

- **GIVEN** employee "user-anna" has an open primary assignment to ou-003 (valid_until=null)
- **WHEN** they attempt to create another primary assignment to ou-005 with is_primary=true
- **THEN** the system:
  - Rejects with 409 Conflict
  - Returns error: "Employee cannot have more than one open primary assignment"

#### Scenario: REQ-002-03-Create-Secondary-Assignment

- **GIVEN** employee "user-anna" has an open primary assignment to ou-003
- **WHEN** they create a secondary assignment to ou-005 with is_primary=false, valid_from=today
- **THEN** the system:
  - Allows the secondary assignment (no constraint on non-primary)
  - Returns 201 Created
  - **AND** the reporting-line API returns reports_to_user_id from the primary assignment (ou-003)

#### Scenario: REQ-002-04-Close-Existing-Assignment-On-Move

- **GIVEN** employee "user-bert" has a primary assignment to ou-003 (valid_until=null) since 2024-01-01
- **WHEN** an offboarding workflow closes the assignment by PATCH with:
  - valid_until: "2026-05-23" (today)
- **THEN** the system:
  - Updates the existing record valid_until=2026-05-23
  - Returns 200 OK
  - **AND** future queries for today onward will not see this assignment
  - **AND** historical queries for 2025-05-23 will see this assignment

---

### Requirement: REQ-003-ORGCHART-VISUALIZATION

Interactive D3.js tree visualization with collapsible nodes.

#### Scenario: REQ-003-01-Render-Full-Tree-Today

- **GIVEN** an employee viewing the org-chart page with `org-chart:read` permission
- **WHEN** they load the page (no date-picker, defaults to today)
- **THEN** the system:
  - Queries org-chart snapshot cache for today
  - If cached, returns tree JSON immediately
  - If not cached, computes tree from OrgUnit/OrgAssignment records valid today
  - Renders D3.js horizontal tree with:
    - Root node expanded (VDGA)
    - Child nodes collapsed by default (click to expand)
    - Each node label: "CODE · Name (H: actual/budget)"
    - Unit-head photo + name on hover
  - **AND** headcount counts actual (from OrgAssignment) and budget (from OrgUnit.headcount_budget)

#### Scenario: REQ-003-02-Expand-Collapse-Nodes

- **GIVEN** the tree is rendered with root expanded, children collapsed
- **WHEN** they click on "DBC" node
- **THEN** the system:
  - Expands DBC to show children (Team Paspoorten, etc.)
  - Re-renders D3 layout (preserves scroll position)
  - **AND** double-clicking a node navigates to the unit detail page (`/medewerkers/org-units/ou-002`)

#### Scenario: REQ-003-03-Unit-Headcount-Calculation

- **GIVEN** Unit ou-003 (Team Paspoorten) has headcount_budget=22
- **WHEN** query returns OrgAssignments valid-as-of today:
  - oa-100 (user-anna, primary)
  - oa-101 (user-bert, primary)
  - oa-102 (user-cindy, primary) — assigned to ou-005, not ou-003
- **THEN** headcount_actual for ou-003 = 2 (count distinct employees with primary assignment)

---

### Requirement: REQ-004-ORGCHART-HISTORICAL

Historical org-chart view via date-picker with snapshot caching.

#### Scenario: REQ-004-01-View-Historical-Structure

- **GIVEN** a user on the org-chart page with date-picker visible
- **WHEN** they change the date to "2025-09-01"
- **THEN** the system:
  - Queries org-chart snapshot cache for 2025-09-01
  - If cached, returns tree immediately
  - If not cached, computes tree from OrgUnit/OrgAssignment records with:
    - valid_from <= 2025-09-01 AND (valid_until is null OR valid_until >= 2025-09-01)
  - Re-renders D3 tree as of that date
  - **AND** tree structure matches the org as it was on 2025-09-01

#### Scenario: REQ-004-02-Snapshot-Cache-Hit

- **GIVEN** snapshot already exists for date "2026-05-01" (start of month)
- **WHEN** they query org-chart tree for 2026-05-01
- **THEN** the system:
  - Hits cache (< 1ms lookup)
  - Returns cached tree_json
  - No database computation required

#### Scenario: REQ-004-03-Snapshot-Invalidation-On-Write

- **GIVEN** snapshots exist for today, yesterday, and last-month
- **WHEN** an HR-admin creates a new OrgUnit with valid_from=today
- **THEN** the system:
  - Invalidates snapshot for today (delete or mark stale)
  - Preserves snapshots for yesterday and earlier
  - **AND** next query for today will recompute from DB and re-cache

---

### Requirement: REQ-005-ORGCHART-REORG

Bulk re-parent units with effective-dating.

#### Scenario: REQ-005-01-Reorg-Bulk-Reparent

- **GIVEN** an HR-admin executing a reorg on 2026-06-01:
  - ou-003 (Team Paspoorten) currently under ou-002 (DBC)
  - ou-004 (Dienst Financiën) is separate
- **WHEN** they POST to `/api/org-chart/reorg` with:
  - reorg_date: "2026-06-01"
  - moves: [
      { unit_id: "ou-003", new_parent_id: "ou-004" }
    ]
- **THEN** the system:
  - Closes the old OrgUnit record: ou-003.valid_until = "2025-05-31"
  - Opens a new OrgUnit record: ou-003 (copy of old) with parent_unit_id="ou-004", valid_from="2026-06-01"
  - Returns 200 OK with reorg summary
  - **AND** OrgAssignments are NOT modified (employees stay; only unit parent changes)
  - **AND** snapshots for 2026-06-01 onward are invalidated

#### Scenario: REQ-005-02-Reorg-Preserves-History

- **GIVEN** reorg on 2026-06-01 moves ou-003 from ou-002 to ou-004
- **WHEN** they query tree for 2025-05-23 (before reorg)
- **THEN** ou-003 still appears under ou-002 (historical view)
- **AND** when they query tree for 2026-06-01 (after reorg)
- **THEN** ou-003 appears under ou-004

---

### Requirement: REQ-006-COSTCENTER-ROUTING

Cost-center surfacing for finance app integration.

#### Scenario: REQ-006-01-Resolve-Costcenter-To-Unit

- **GIVEN** OrgUnit ou-002 with cost_center_code="CC-BC"
- **WHEN** finance app (shillinq) calls `GET /api/cost-centers/CC-BC`
- **THEN** the system:
  - Returns OrgUnit ou-002 with current head_user_id, name, code, valid_from/until
  - Status 200 OK
  - Response includes the head_user_id for approval routing

#### Scenario: REQ-006-02-Costcenter-Not-Found

- **GIVEN** no OrgUnit has cost_center_code="CC-INVALID"
- **WHEN** finance app calls `GET /api/cost-centers/CC-INVALID`
- **THEN** the system:
  - Returns 404 Not Found
  - Error: "Cost center code not found"

#### Scenario: REQ-006-03-Costcenter-Change-Head

- **GIVEN** ou-004 with head_user_id="user-cfo" and cost_center_code="CC-FIN"
- **WHEN** they PATCH ou-004 to change head_user_id to "user-new-cfo"
- **THEN** the system:
  - Updates the head_user_id
  - Invalidates snapshots
  - **AND** next query to `GET /api/cost-centers/CC-FIN` returns new head_user_id
  - **AND** employee assignments are not affected (cost-center owner and assignments are independent)

---

### Requirement: REQ-007-ORGCHART-EXPORT

Export org-chart to PNG/PDF/SVG/JSON.

#### Scenario: REQ-007-01-Export-JSON

- **GIVEN** the org-chart tree is rendered for 2026-05-23
- **WHEN** they click "Export" and select "JSON"
- **THEN** the system:
  - Downloads a JSON file with full tree (all units, all headcount, all unit-heads)
  - Format matches OrgChartJS / Holaspirit interchange schema
  - File can be imported into Visio, Lucidchart, or Holaspirit
  - Status 200 OK, Content-Type: application/json
  - **AND** JSON is complete (no truncation; includes all nested children)

#### Scenario: REQ-007-02-Export-PNG

- **GIVEN** the tree is rendered and visible in the browser
- **WHEN** they click "Export" and select "PNG"
- **THEN** the system:
  - Renders the tree to SVG (via D3)
  - Converts SVG to PNG (via Playwright/Puppeteer or headless Chromium)
  - Downloads as "org-chart-2026-05-23.png"
  - Resolution: 1920x1080 (or full-size if larger)
  - Status 200 OK, Content-Type: image/png

#### Scenario: REQ-007-03-Export-PDF

- **GIVEN** the tree is rendered, possibly with >4 levels
- **WHEN** they click "Export" and select "PDF"
- **THEN** the system:
  - Renders all nodes (no collapse) to SVG
  - Converts SVG to PDF with A3 landscape orientation
  - All units visible (may span multiple pages if large org)
  - Downloads as "org-chart-2026-05-23.pdf"
  - Status 200 OK, Content-Type: application/pdf

#### Scenario: REQ-007-04-Export-SVG

- **GIVEN** the tree is rendered
- **WHEN** they click "Export" and select "SVG"
- **THEN** the system:
  - Downloads the D3.js SVG rendering as-is
  - File can be embedded in presentations or further edited
  - Downloads as "org-chart-2026-05-23.svg"
  - Status 200 OK, Content-Type: image/svg+xml

---

### Requirement: REQ-008-REPORTINGLINE-DERIVATION

Reporting-line API for backwards compatibility and manager self-service.

#### Scenario: REQ-008-01-Direct-Reports-To

- **GIVEN** employee "user-anna" has OrgAssignment with reports_to_user_id="user-lead-passports"
- **WHEN** any app calls `GET /api/org-chart/reporting-line?employee_id=user-anna&as_of_date=2026-05-23`
- **THEN** the system:
  - Returns { manager_id: "user-lead-passports" }
  - Status 200 OK

#### Scenario: REQ-008-02-Transitive-Unit-Head-Lookup

- **GIVEN** employee "user-anna" has OrgAssignment to ou-003 with reports_to_user_id=null
- **AND** ou-003.head_user_id="user-lead-passports"
- **WHEN** they query reporting-line for user-anna
- **THEN** the system:
  - Returns { manager_id: "user-lead-passports" }
  - (Falls back to unit head since reports_to_user_id is null)

#### Scenario: REQ-008-03-Transitive-Parent-Unit-Lookup

- **GIVEN** employee "user-alice" assigned to ou-003, ou-003.head_user_id=null, ou-003.parent_unit_id=ou-002
- **AND** ou-002.head_user_id="user-dir-burgerzaken"
- **WHEN** they query reporting-line for user-alice
- **THEN** the system:
  - Traverses parent chain: ou-003 → ou-002 (head found)
  - Returns { manager_id: "user-dir-burgerzaken" }
  - **AND** traversal stops after finding first non-null head_user_id (max 5 levels)

#### Scenario: REQ-008-04-Historical-Reporting-Line

- **GIVEN** employee "user-bob" was assigned to ou-003 on 2025-01-01, then moved to ou-005 on 2026-01-01
- **WHEN** they query reporting-line as_of_date=2025-06-01
- **THEN** the system:
  - Finds the assignment valid on 2025-06-01 (ou-003)
  - Returns manager from that assignment's unit/reports_to
  - **AND** querying as_of_date=2026-06-01 returns manager from ou-005 assignment

#### Scenario: REQ-008-05-No-Assignment-Returns-Null

- **GIVEN** employee "user-unassigned" has no OrgAssignment records
- **WHEN** they query reporting-line for user-unassigned
- **THEN** the system:
  - Returns { manager_id: null }
  - Status 200 OK (not 404; null is valid for unassigned employees)

---

### Requirement: REQ-009-PERMISSIONS

Access control for org-chart operations.

#### Scenario: REQ-009-01-Read-Permission

- **GIVEN** an employee with permission `org-chart:read` and any role
- **WHEN** they request GET /api/org-chart/tree
- **THEN** the system:
  - Returns 200 OK with full tree
  - Note: no read-scoping; all employees can see full org (org-chart is transparent to all)

#### Scenario: REQ-009-02-Write-Permission

- **GIVEN** an HR-admin with permission `org-units:write` and `org-assignments:write`
- **WHEN** they POST to create/update/delete org-units or assignments
- **THEN** the system:
  - Allows operation
  - Returns 201/200 OK

#### Scenario: REQ-009-03-Deny-Write-No-Permission

- **GIVEN** a manager without `org-units:write`
- **WHEN** they attempt POST to `/api/org-units`
- **THEN** the system:
  - Returns 403 Forbidden
  - Error: "Permission denied: org-units:write required"

---

## MODIFIED Requirements

### Requirement: REQ-010-EMPLOYEE-MANAGER-BACKWARDS-COMPATIBILITY

Backwards compatibility with Employee.manager_id.

#### Scenario: REQ-010-01-Bulk-Migration-Derives-Manager-ID

- **GIVEN** Employee records with manager_id foreign-key set
- **WHEN** migration runs (as part of deployment)
- **THEN** the system:
  - Creates OrgAssignments from Employee.manager_id (derive unit from manager's primary assignment)
  - Sets OrgAssignment.reports_to_user_id = Employee.manager_id
  - Maintains Employee.manager_id for backwards compatibility (not deleted)

#### Scenario: REQ-010-02-New-Code-Queries-Orgassignment

- **GIVEN** new code (manager-self-service, onboarding, etc.)
- **WHEN** code needs to query employee manager
- **THEN** the system:
  - Uses OrgAssignment.reports_to_user_id (via reporting-line API)
  - Does NOT directly read Employee.manager_id
  - (Old code may still use Employee.manager_id; feature flag toggles which is queried)

#### Scenario: REQ-010-03-Dual-Source-Warning

- **GIVEN** Employee.manager_id differs from OrgAssignment.reports_to_user_id (data drift)
- **WHEN** HR dashboard is viewed
- **THEN** the system:
  - Shows warning badge: "Manager data out of sync"
  - Links to sync utility (mass-update OrgAssignment from Employee.manager_id or vice versa)

---

## Non-Functional Requirements

### Performance

- **Req-001**: Org-chart tree render for <500 units should complete in < 500ms (snapshot cache)
- **Req-002**: Tree re-render on date-picker change < 300ms (snapshot cache)
- **Req-003**: Reporting-line API response < 50ms (in-request caching)
- **Req-004**: Export to PNG/PDF < 2s (async background job acceptable)

### Security

- **Req-001**: All CREATE/UPDATE/DELETE operations require `org-units:write` or `org-assignments:write` permission
- **Req-002**: Cost-center routing API (`/api/cost-centers/*`) accessible only to authenticated apps (API key or OAuth)
- **Req-003**: Org-chart data is not scoped by role (transparent to all employees); no field-level masking

### Compliance

- **Req-001**: All OrgUnit/OrgAssignment changes logged to audit-trail (created_by, created_at, updated_by, updated_at)
- **Req-002**: Org-chart snapshots retained for last 90 days (meets audit retention for reorg trace-back)
- **Req-003**: Cost-center changes audited (for finance & compliance reviews)
