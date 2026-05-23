---
status: proposal
change: org-chart-basic
created_at: 2026-05-23
author: Specter Intelligence
---

# Organisational Chart (Basic) — Proposal

## Why

hrmq currently stores Employee.manager_id as a single foreign-key — flat, unversioned, and impossible to answer historical questions like "what did the structure look like before the December reorg?". This blocks manager self-service (which needs current reporting lines), cost-center routing for finance apps, and audit questions about org evolution. The org-chart is a first-class business entity that needs effective-dating, versioning, and a visual interface.

## What Changes

- **New entities:** OrgUnit (department/cost-center/team hierarchy), OrgAssignment (employee → unit + manager over time), OrgChartSnapshot (cached materialized views for fast historical queries)
- **New surface:** Sub-page under Medewerkers › Organogram with interactive D3.js tree visualization
- **New capabilities:** Create/manage org hierarchies, assign employees to units with effective-dating, view historical structures via date-picker, export as PNG/PDF/SVG/JSON
- **Integrations:** Finance apps (shillinq, expense-management) can resolve cost-center owners; manager self-service derives team scope from OrgAssignment; onboarding/offboarding workflows create/close OrgAssignments

## Capabilities

### New Capabilities

- `org-unit-management`: Create, edit, delete OrgUnits with hierarchy and cost-center codes; validate no cycles, unique codes within parent
- `org-assignment-management`: Assign employees to org-units with role/title and reporting-line; mark one as primary; effective-dating from/to dates
- `org-chart-visualization`: Interactive D3.js horizontal tree, collapsible nodes showing name/code/headcount/unit-head photo
- `org-chart-historical-view`: Date-picker to view structure as-of historical dates; caches snapshots for performance
- `org-chart-export`: Download PNG (screenshot), PDF (A3 landscape), SVG (vector), JSON (round-trip to Visio/Lucidchart)
- `org-hierarchy-reorg`: Bulk re-parent multiple units, close existing validity, open new validity records, preserve history
- `reporting-line-resolution`: Query API returns manager_id as-of query date; transitive lookup up to 5 levels if manager is null
- `cost-center-routing`: Finance apps resolve cost_center_code → OrgUnit → head_user_id for approval/charge routing

### Modified Capabilities

- `employee-master`: Retains Employee.manager_id for backwards compatibility; new code uses OrgAssignment.reports_to_user_id
- `manager-self-service`: Derives team_scope from OrgAssignment.reports_to_user_id instead of flat manager_id lookups
- `employee-onboarding`: Creates OrgAssignment as part of new-hire flow; offboarding closes validity

## Impact

- **openspec/architecture/**: ADR-031 (schema declarative logic) used for OrgUnit.parent_unit_id cycle-detection rules
- **openspec/**: New entities (OrgUnit, OrgAssignment, OrgChartSnapshot) added to org register (shared with finance apps)
- **src/models/**: Three new models (OrgUnit, OrgAssignment, OrgChartSnapshot)
- **src/pages/**: New page at Medewerkers › Organogram with D3.js tree component
- **src/api/**: New endpoints for CRUD OrgUnit, CRUD OrgAssignment, GET org-chart tree, POST org-chart export
- **src/utils/**: Org-chart snapshot caching logic, reporting-line resolution utilities
- **database/migrations/**: Schema for OrgUnit, OrgAssignment, OrgChartSnapshot tables with indexes on effective-date ranges
- **tests/**: Unit tests for cycle-detection, effective-dating logic, historical queries; integration tests for export formats
- **docs/**: Feature guide for HR-admins (creating hierarchies, reorg workflows), API guide for finance apps

## Depends On

- `employee-management` — Employee.user_id is the join key for OrgAssignment
- `ADR-031` (schema declarative business logic) — OrgUnit parent validation rules

## Related Specs (Future)

- `org-chart-matrix` — Matrix/dotted-line multi-hierarchy reporting (deferred)
- `manager-self-service` — Depends on this spec; team scope derives from OrgAssignment
- `spans-of-control-analytics` — Spans, breadth, depth KPIs (deferred)
- `role-based-org` — Holaspirit-style org-role model (deferred)

## Acceptance Criteria

- OrgUnit CRUD fully testable; parent-link cycles rejected with clear error
- OrgAssignment CRUD with effective-dating; primary assignment flag enforced (one per employee at a time)
- D3.js tree renders with collapsible nodes, 3+ levels deep without UI jank
- Date-picker historical view returns correct structure for any historical date within OrgUnit/OrgAssignment validity ranges
- Export to PNG/PDF/SVG/JSON without data loss; JSON round-trips to Visio/Lucidchart
- Reporting-line resolution API tested for null-handling, transitive lookup up to 5 levels
- Finance apps (shillinq) can query cost-center → OrgUnit → owner via stable API
- Manager self-service dashboard shows correct team scope derived from OrgAssignment
