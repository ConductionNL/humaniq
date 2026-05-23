---
status: tasks
change: org-chart-basic
created_at: 2026-05-23
author: Specter Intelligence
---

# Organisational Chart (Basic) — Implementation Tasks

## 1. Database Schema & Migrations

- [ ] 1.1 Create migration: `database/migrations/001_create_org_units_table.sql`
  - OrgUnit table: id (uuid), name, code, unit_type (enum), parent_unit_id, head_user_id, cost_center_code, headcount_budget, valid_from, valid_until
  - Indexes: (parent_unit_id, valid_from, valid_until), (cost_center_code), (valid_from, valid_until)
  - Constraints: NOT NULL on id/name/code/valid_from, unique(parent_unit_id, code) where valid_until IS NULL
  
- [ ] 1.2 Create migration: `database/migrations/002_create_org_assignments_table.sql`
  - OrgAssignment table: id, employee_user_id, org_unit_id, reports_to_user_id, role_in_unit, is_primary, valid_from, valid_until
  - Indexes: (employee_user_id, is_primary, valid_from, valid_until), (org_unit_id, valid_from, valid_until)
  - Constraints: NOT NULL on id/employee_user_id/org_unit_id/valid_from, FOREIGN KEY on org_unit_id, check constraint on is_primary (max 1 per employee at time T)

- [ ] 1.3 Create migration: `database/migrations/003_create_org_chart_snapshots_table.sql`
  - OrgChartSnapshot table: snapshot_date (date, PK), tree_json (jsonb), generated_at (timestamp)
  - Index: (snapshot_date)

- [ ] 1.4 Create seed data migration: `database/migrations/004_seed_org_chart_basic_sample_data.sql`
  - Insert 5 OrgUnits (VDGA, DBC, Team-Pass, Dienst-Fin, Team-Budget) with Dutch names
  - Insert 3 OrgAssignments with sample employees (Anna Jansen, Bert Kok, Cindy de Vries)
  - Generate and insert initial snapshots for today, start-of-month, start-of-year

## 2. Backend Models & Services

- [ ] 2.1 Create model `src/models/OrgUnit.php` (or `.ts`, `.py` per stack)
  - Properties: id, name, code, unit_type, parent_unit_id, head_user_id, cost_center_code, headcount_budget, valid_from, valid_until
  - Methods: toArray(), fromArray(), validate(), getChildren($date), getAncestors()

- [ ] 2.2 Create model `src/models/OrgAssignment.php`
  - Properties: id, employee_user_id, org_unit_id, reports_to_user_id, role_in_unit, is_primary, valid_from, valid_until
  - Methods: toArray(), fromArray(), validate()

- [ ] 2.3 Create model `src/models/OrgChartSnapshot.php`
  - Properties: snapshot_date, tree_json, generated_at
  - Methods: toArray()

- [ ] 2.4 Create service `src/services/OrgUnitService.php`
  - create($data): validates no cycles, unique code within parent, returns OrgUnit
  - update($id, $data): closes old record (valid_until=yesterday), opens new record (valid_from=today), invalidates snapshots
  - delete($id): soft-delete (set valid_until=today)
  - getById($id, $as_of_date): returns OrgUnit valid as-of date
  - getChildren($parent_id, $as_of_date): returns direct children
  - getTree($as_of_date): builds nested tree structure

- [ ] 2.5 Create service `src/services/OrgAssignmentService.php`
  - create($data): validates no duplicate primary, creates OrgAssignment, invalidates snapshots
  - update($id, $data): handles closing of old records
  - delete($id): soft-delete
  - getByEmployee($employee_id, $as_of_date): returns assignments valid as-of date
  - getPrimaryAssignment($employee_id, $as_of_date): returns single primary

- [ ] 2.6 Create service `src/services/OrgChartSnapshotService.php`
  - getOrCompute($date): queries cache; if miss, computes from DB, caches, returns
  - compute($date): builds full tree from OrgUnit/OrgAssignment records valid on date
  - invalidate($from_date): marks snapshots >= from_date as stale
  - cleanup(): deletes snapshots older than 90 days

- [ ] 2.7 Create service `src/services/ReportingLineService.php`
  - getManager($employee_id, $as_of_date): returns manager_id via OrgAssignment → unit head chain
  - getChain($employee_id, $as_of_date): returns full manager chain (up to 5 levels)

- [ ] 2.8 Create validator `src/validators/OrgUnitValidator.php`
  - validateNoCycles($parent_id): ensures acyclic hierarchy
  - validateUniqueCode($code, $parent_id, $excluding_id): ensures code unique within parent
  - validateHead($head_user_id): ensures head_user_id exists in users table

- [ ] 2.9 Create validator `src/validators/OrgAssignmentValidator.php`
  - validateNoPrimaryConflict($employee_id, $is_primary, $valid_from, $valid_until): ensures ≤1 primary at time T
  - validateEmployeeExists($employee_id): ensures employee in users table

## 3. API Endpoints

- [ ] 3.1 POST `/api/org-units` — Create OrgUnit (OrgUnitController@store)
  - Requires: `org-units:write` permission
  - Input: name, code, unit_type, parent_unit_id, head_user_id, cost_center_code, headcount_budget
  - Output: OrgUnit object with id, valid_from, valid_until
  - Status: 201 Created or 400/409 on validation error

- [ ] 3.2 GET `/api/org-units` — List OrgUnits (OrgUnitController@index)
  - Query params: parent_id, unit_type, as_of_date (default: today), limit, offset
  - Output: array of OrgUnit objects
  - Status: 200 OK

- [ ] 3.3 GET `/api/org-units/{id}` — Get OrgUnit (OrgUnitController@show)
  - Query param: as_of_date (default: today)
  - Output: OrgUnit object
  - Status: 200 OK or 404 Not Found

- [ ] 3.4 PATCH `/api/org-units/{id}` — Update OrgUnit (OrgUnitController@update)
  - Requires: `org-units:write` permission
  - Input: any fields to update
  - Behavior: closes old record, opens new record with valid_from=today
  - Output: updated OrgUnit
  - Status: 200 OK

- [ ] 3.5 DELETE `/api/org-units/{id}` — Delete OrgUnit (OrgUnitController@destroy)
  - Requires: `org-units:write` permission
  - Behavior: soft-delete (valid_until=today)
  - Status: 204 No Content

- [ ] 3.6 POST `/api/org-assignments` — Create OrgAssignment (OrgAssignmentController@store)
  - Requires: `org-assignments:write` permission
  - Input: employee_user_id, org_unit_id, reports_to_user_id, role_in_unit, is_primary, valid_from
  - Output: OrgAssignment object
  - Status: 201 Created or 409 on primary conflict

- [ ] 3.7 GET `/api/org-assignments` — List OrgAssignments (OrgAssignmentController@index)
  - Query params: employee_id, org_unit_id, as_of_date, is_primary, limit, offset
  - Output: array of OrgAssignment objects
  - Status: 200 OK

- [ ] 3.8 GET `/api/org-assignments/{id}` — Get OrgAssignment (OrgAssignmentController@show)
  - Query param: as_of_date
  - Output: OrgAssignment object
  - Status: 200 OK or 404

- [ ] 3.9 PATCH `/api/org-assignments/{id}` — Update OrgAssignment (OrgAssignmentController@update)
  - Requires: `org-assignments:write` permission
  - Input: fields to update
  - Behavior: closes old, opens new (or direct update if just closing)
  - Output: updated OrgAssignment
  - Status: 200 OK

- [ ] 3.10 DELETE `/api/org-assignments/{id}` — Delete OrgAssignment (OrgAssignmentController@destroy)
  - Requires: `org-assignments:write` permission
  - Behavior: soft-delete
  - Status: 204 No Content

- [ ] 3.11 GET `/api/org-chart/tree` — Get org-chart tree (OrgChartController@tree)
  - Query param: as_of_date (default: today)
  - Output: nested tree JSON (OrgChartSnapshot.tree_json)
  - Status: 200 OK

- [ ] 3.12 GET `/api/org-chart/tree/{unit_id}/subtree` — Get subtree (OrgChartController@subtree)
  - Query param: as_of_date
  - Output: subtree rooted at unit_id
  - Status: 200 OK or 404

- [ ] 3.13 GET `/api/org-chart/reporting-line` — Get manager (OrgChartController@reportingLine)
  - Query params: employee_id, as_of_date (default: today)
  - Output: { manager_id: "user-id" } or { manager_id: null }
  - Status: 200 OK

- [ ] 3.14 POST `/api/org-chart/reorg` — Bulk re-parent (OrgChartController@reorg)
  - Requires: `org-units:write` permission
  - Input: reorg_date, moves: [ { unit_id, new_parent_id } ]
  - Behavior: closes old OrgUnit records, opens new records with new parent
  - Output: reorg summary
  - Status: 200 OK or 400/409 on validation

- [ ] 3.15 POST `/api/org-chart/export` — Export tree (OrgChartController@export)
  - Query param: as_of_date, format (png|pdf|svg|json)
  - Output: file download (json returns JSON, others return binary)
  - Status: 200 OK

- [ ] 3.16 GET `/api/cost-centers/{code}` — Resolve cost-center (CostCenterController@show)
  - No auth required (called by external finance apps with API key)
  - Output: OrgUnit object with cost_center_code, head_user_id
  - Status: 200 OK or 404 Not Found

## 4. Frontend Components

- [ ] 4.1 Create page component `src/pages/OrgChart/OrgChartPage.vue` (or `.tsx`)
  - Renders org-chart tree via D3.js
  - Includes date-picker (defaults to today)
  - Includes export button (PNG/PDF/SVG/JSON)
  - Calls `GET /api/org-chart/tree` on mount and date change

- [ ] 4.2 Create D3 tree component `src/components/OrgChart/OrgChartTree.vue`
  - D3.js horizontal tree layout
  - SVG rendering with zoom/pan
  - Node click → expand/collapse
  - Node double-click → navigate to unit detail page
  - Tooltip on hover showing unit head photo + name

- [ ] 4.3 Create date-picker component `src/components/OrgChart/DatePicker.vue`
  - Calendar input defaulting to today
  - Quick links: today, start-of-month, start-of-year
  - Emits date change event

- [ ] 4.4 Create export modal `src/components/OrgChart/ExportModal.vue`
  - Radio buttons: PNG, PDF, SVG, JSON
  - Download button
  - Calls POST `/api/org-chart/export` with selected format

- [ ] 4.5 Create management page `src/pages/OrgChart/OrgUnitsManagement.vue`
  - List view of OrgUnits (filterable by parent, unit_type)
  - Create button → opens OrgUnit form modal
  - Edit button → OrgUnit detail page with form
  - Delete button → soft-delete with confirmation

- [ ] 4.6 Create OrgUnit form component `src/components/Forms/OrgUnitForm.vue`
  - Fields: name, code, unit_type dropdown, parent_unit_id (searchable), head_user_id (searchable), cost_center_code, headcount_budget
  - Validates: no cycles, unique code, required fields
  - Submits POST `/api/org-units` or PATCH `/api/org-units/{id}`

- [ ] 4.7 Create OrgAssignment form component `src/components/Forms/OrgAssignmentForm.vue`
  - Fields: employee_user_id (searchable), org_unit_id (searchable), reports_to_user_id (searchable), role_in_unit, is_primary checkbox
  - Validates: no primary conflicts
  - Submits POST `/api/org-assignments`

- [ ] 4.8 Create unit detail page `src/pages/OrgChart/OrgUnitDetail.vue`
  - Shows unit info: name, code, parent, head, cost-center, headcount
  - Edit button → opens form
  - Child units list
  - Assigned employees list

- [ ] 4.9 Integrate org-chart page into menu
  - Route: `/medewerkers/organogram`
  - Menu entry under "Medewerkers" menu (as SUB_PAGE per ADR-001)
  - Label: "Organogram"

## 5. Data Export

- [ ] 5.1 Create PNG export service `src/services/ExportService.php`
  - Takes SVG (from D3 tree) → converts to PNG via Puppeteer/Playwright
  - Returns PNG binary with filename

- [ ] 5.2 Create PDF export service `src/services/PdfExportService.php`
  - Takes SVG → converts to PDF with A3 landscape
  - Returns PDF binary

- [ ] 5.3 Create SVG export service `src/services/SvgExportService.php`
  - Returns D3 SVG as-is with filename

- [ ] 5.4 Create JSON export service `src/services/JsonExportService.php`
  - Returns tree_json with OrgChartJS schema
  - Ensures all fields included (no truncation)

## 6. Integration & Workflows

- [ ] 6.1 Integrate with n8n onboarding workflow
  - On new-hire creation, call POST `/api/org-assignments` with employee_id, org_unit_id, reports_to_user_id
  - Handle errors (unit not found, primary conflict) gracefully

- [ ] 6.2 Integrate with n8n offboarding workflow
  - On employee termination, PATCH `/api/org-assignments/{id}` with valid_until=termination_date
  - Preserve all history (no delete)

- [ ] 6.3 Create cost-center routing integration guide (docs)
  - Document API endpoint `GET /api/cost-centers/{code}`
  - Example curl calls for finance apps

- [ ] 6.4 Add manager self-service integration point
  - Ensure ReportingLineService.getManager() is called by manager-self-service dashboard
  - Test that team scope is derived correctly from OrgAssignment

## 7. Testing

- [ ] 7.1 Unit tests: OrgUnit validation (cycle detection, unique code)
  - Test: ValidatorOrgUnitTest@testNoCycles (various cycle depths)
  - Test: ValidatorOrgUnitTest@testUniqueCode (sibling, parent scopes)
  - Coverage: ≥95%

- [ ] 7.2 Unit tests: OrgAssignment validation (primary constraint)
  - Test: ValidatorOrgAssignmentTest@testPrimaryConstraint
  - Test: ValidatorOrgAssignmentTest@testMultiplePrimary (rejection)

- [ ] 7.3 Integration tests: OrgUnit CRUD
  - Test: OrgUnitControllerTest@testCreate (happy path + validation errors)
  - Test: OrgUnitControllerTest@testUpdate (closes old, opens new)
  - Test: OrgUnitControllerTest@testDelete (soft-delete)
  - Test: OrgUnitControllerTest@testGetTree (nested structure)

- [ ] 7.4 Integration tests: OrgAssignment CRUD
  - Test: OrgAssignmentControllerTest@testCreate (happy path + primary conflict)
  - Test: OrgAssignmentControllerTest@testUpdate
  - Test: OrgAssignmentControllerTest@testDelete

- [ ] 7.5 Integration tests: Org-chart snapshot caching
  - Test: OrgChartSnapshotTest@testCacheHit (snapshot exists, returns fast)
  - Test: OrgChartSnapshotTest@testCacheMiss (snapshot missing, computes, caches)
  - Test: OrgChartSnapshotTest@testInvalidation (write invalidates snapshots)

- [ ] 7.6 Integration tests: Reporting-line API
  - Test: ReportingLineTest@testDirectReportsTo
  - Test: ReportingLineTest@testTransitiveUnitHead
  - Test: ReportingLineTest@testHistoricalDate
  - Test: ReportingLineTest@testNullManager

- [ ] 7.7 Integration tests: Cost-center routing
  - Test: CostCenterControllerTest@testResolve
  - Test: CostCenterControllerTest@testNotFound
  - Test: CostCenterControllerTest@testHeadChange

- [ ] 7.8 Integration tests: Reorg workflow
  - Test: ReorgControllerTest@testBulkReparent
  - Test: ReorgControllerTest@testPreservesHistory

- [ ] 7.9 Frontend tests: D3 tree rendering
  - Test: OrgChartTreeTest@testRender (tree structure, node count)
  - Test: OrgChartTreeTest@testExpandCollapse (DOM updates)
  - Test: OrgChartTreeTest@testDatePickerChange (tree re-renders)

- [ ] 7.10 Frontend tests: Export modal
  - Test: ExportModalTest@testFormatSelection (PNG/PDF/SVG/JSON)
  - Test: ExportModalTest@testDownload (file fetched, correct type)

- [ ] 7.11 End-to-end test: Full workflow
  - Scenario: HR-admin creates org unit hierarchy, assigns employees, views tree, exports JSON
  - Verify: tree renders, export is complete, can re-import

## 8. Documentation

- [ ] 8.1 Create API documentation: openspec/docs/org-chart-api.md
  - Endpoint reference for all `/api/org-units`, `/api/org-assignments`, `/api/org-chart`, `/api/cost-centers` endpoints
  - Request/response examples (JSON)
  - Error codes

- [ ] 8.2 Create feature guide: docs/features/org-chart-basic.md
  - For HR-admins: creating hierarchies, assigning employees, managing reorgs
  - Screenshots of org-chart page, tree, export options
  - Date-picker historical view usage

- [ ] 8.3 Create integration guide: docs/integrations/cost-center-routing.md
  - For finance app developers
  - Cost-center API endpoint reference
  - How to query and use head_user_id for approval routing

- [ ] 8.4 Create data model reference: openspec/docs/org-chart-schema.md
  - OrgUnit, OrgAssignment, OrgChartSnapshot entity diagrams
  - Field descriptions, constraints, indexes

- [ ] 8.5 Create migration guide: docs/migration-guides/org-chart-migration.md
  - Bulk-migrate Employee.manager_id to OrgAssignment
  - Feature flag to toggle between old and new code
  - Warnings about data drift

## 9. Verification & QA

- [ ] 9.1 Manual QA: Create org unit hierarchy (root + 3 levels)
  - Verify: no cycles rejected, unique code enforced, tree renders correctly

- [ ] 9.2 Manual QA: Assign 10+ employees
  - Verify: primary constraint enforced, secondary assignments allowed

- [ ] 9.3 Manual QA: Date-picker historical view
  - Create snapshot for 2025-09-01
  - Query tree for that date
  - Verify: correct structure returned

- [ ] 9.4 Manual QA: Export formats
  - Export as PNG, PDF, SVG, JSON
  - Verify: files download, no corruption, JSON re-imports to Visio

- [ ] 9.5 Manual QA: Reorg workflow
  - Perform bulk re-parent
  - Verify: old structure preserved in history, new structure visible for new date

- [ ] 9.6 Manual QA: Reporting-line API
  - Query manager for various employees
  - Verify: correct manager returned, transitive lookup works

- [ ] 9.7 Manual QA: Cost-center routing
  - Finance app queries `/api/cost-centers/CC-BC`
  - Verify: correct OrgUnit + head_user_id returned

- [ ] 9.8 Performance testing
  - Load test org-chart with 500+ units
  - Verify: tree render < 500ms, date-picker change < 300ms

- [ ] 9.9 Accessibility testing
  - Keyboard navigation on tree (arrow keys, tab)
  - Screen reader support (aria labels on nodes)

- [ ] 9.10 Security testing
  - Verify: `org-units:write` permission enforced on CREATE/UPDATE/DELETE
  - Verify: cost-center API requires API key
  - Verify: no SQL injection on code/name inputs

## 10. Deployment & Cleanup

- [ ] 10.1 Run all database migrations (1.1–1.4)
  - Verify: tables created, indexes present, seed data inserted

- [ ] 10.2 Run all tests
  - Verify: unit tests pass (7.1–7.2), integration tests pass (7.3–7.8), e2e tests pass (7.11)

- [ ] 10.3 Deploy backend API (step 3)
  - Verify: all endpoints respond (curl tests)

- [ ] 10.4 Deploy frontend (step 4)
  - Verify: pages load, routes reachable, components render

- [ ] 10.5 Feature flag rollout (if using)
  - Enable for 10% of users, monitor for errors
  - Gradual increase: 25%, 50%, 100%

- [ ] 10.6 Monitor production
  - Check API latency, error rates, snapshot cache hit rate
  - Alert on cycle-detection errors, primary constraint violations

- [ ] 10.7 Notify dependent teams
  - Inform onboarding (n8n workflow can now create OrgAssignments)
  - Inform finance (cost-center API now available)
  - Inform manager self-service team (team scope derives from OrgAssignment)

- [ ] 10.8 Archive spec artifacts
  - Move org-chart-basic from openspec/changes/ to openspec/changes/archive/YYYY-MM-DD-org-chart-basic/
  - Update changelog
