---
status: design
change: org-chart-basic
created_at: 2026-05-23
author: Specter Intelligence
---

# Organisational Chart (Basic) — Design

## Context

Currently, hrmq models reporting relationships as a denormalized single foreign-key on Employee.manager_id. This works for simple single-parent hierarchies but breaks:

1. **History**: No record of past org structures (before reorgs, restructuring)
2. **Effective-dating**: Can't answer "who reported to whom on 2025-09-01?"
3. **Cost-center routing**: Finance apps can't map cost-center codes to org-units or current managers
4. **Audit**: HR can't produce org-structure reports as-of a given date
5. **Manager self-service**: Team scope must re-query based on date context

We model OrgUnit as the primary entity (represents a department/cost-center/team), with OrgAssignment linking employees to units over time and specifying reporting lines.

## Goals

**Goals:**
- Store org-unit hierarchies with full versioning (effective-dating from/until)
- Support historical queries ("what was the structure on date X?") via cached snapshots
- Enable cost-center routing to finance apps (cost-code → unit → manager)
- Provide interactive D3.js visualization with collapsible tree
- Export org-chart as PNG/PDF/SVG/JSON for presentations and integrations
- Support reorg workflows (bulk re-parent units, close old validity, open new records)
- Derive manager_id via API for backwards compatibility and manager self-service

**Non-Goals:**
- Matrix/dotted-line reporting (separate org-chart-matrix spec)
- Org-unit-level roles or permission boundaries (handled by role-management spec)
- Spans-of-control analytics or org-design advisory (separate specs)
- Holaspirit-style role-based orgs (separate role-based-org spec)
- Multi-administratie scoping at OrgUnit level (OrgUnits are global; administraties are filter-context only)

## Decisions

### Decision 1: Effective-Dated Entities (OrgUnit + OrgAssignment)

**Approach:**  
Both OrgUnit and OrgAssignment use `valid_from` / `valid_until` date ranges (nullable until = open-ended / current). On any change (reparent, reassign), the old record closes (`valid_until = yesterday`) and a new record opens (`valid_from = today`).

**Rationale:**  
- Audit trail: all past structures remain queryable
- Historical analysis: finance/HR can report "org cost by date"
- Supports reorg workflows: bulk-close old, bulk-open new in atomic transaction
- Snapshot caching: any historical date can compute tree from closed + open records

**Trade-off:**  
- More storage (N versions per unit/assignment instead of 1)
- Query complexity increases (all queries filter by date range)
- Mitigation: OrgChartSnapshot materializes frequently-used dates (today, month-ago, etc.)

### Decision 2: Separate OrgUnit and OrgAssignment

**Approach:**  
OrgUnit represents organizational structure (hierarchy of departments, cost-centers, teams). OrgAssignment links employees to units and specifies reporting lines. Employee can have multiple assignments (e.g., "primary" at ENG-001, "dotted" to ENG-002 is future matrix work); only one assignment can be primary at a time.

**Rationale:**  
- OrgUnits are stable; assignments are frequent (moves, reorgs, offboarding)
- Cost-center management (finance) cares about OrgUnit; employee assignment is separate
- Reporting-line can be unit-derived (via head_user_id chain) or explicit (reports_to_user_id)
- Decouples unit hierarchy from employee movement

**Trade-off:**  
- Two lookups to answer "who is your manager?" (OrgAssignment → OrgUnit → head_user_id chain)
- Mitigation: Reporting-line resolution API handles this; caching layers absorb cost

### Decision 3: Snapshot Caching

**Approach:**  
OrgChartSnapshot materialized table stores computed trees (as nested JSON) for common query dates (today, start-of-month, start-of-year, plus any manual snapshots). Queries for historical dates check cache first; if miss, compute on-demand and cache.

**Rationale:**  
- D3.js tree rendering is CPU-intensive (sorting, nesting, depth-first traversal)
- Most queries are for "today" or "as-of reorg date"; caching hits ~95%
- Avoids per-request computation on every org-chart page load

**Trade-off:**  
- Cache invalidation: changes to OrgUnit/OrgAssignment must invalidate snapshots for today onward
- Storage: snapshot stores full tree JSON (3KB–50KB per entry, depending on org size)
- Mitigation: Async snapshot compute (post-write), TTL-based cleanup (keep last 90 days)

### Decision 4: D3.js Horizontal Tree

**Approach:**  
Frontend renders using D3.js v7 tree layout (horizontal orientation, left-to-right or top-to-bottom). Each node shows:
- Unit name + code
- Headcount actual/budget
- Unit head photo + name
- Click-to-expand children, double-click-to-navigate-to-detail

**Rationale:**  
- D3 tree layout handles layout math automatically
- Horizontal better for >3 levels (vertical trees wrap to paper width)
- Collapsible nodes reduce cognitive load on large orgs

**Trade-off:**  
- D3 requires SVG rendering (no mobile zoom optimization) — acceptable for HD desktop use
- Slow for >500 units (need virtual scrolling or sub-tree focus mode)
- Mitigation: For large orgs, focus-mode on a unit and its subtree; search/filter UI

### Decision 5: Cost-Center Routing via Open API

**Approach:**  
Finance apps (shillinq, expense-management) call REST endpoint: `GET /api/cost-centers/{code}` → returns OrgUnit with current head_user_id. OrgUnit.cost_center_code is the query key.

**Rationale:**  
- Finance apps don't own Employee.manager_id; OrgUnit.head_user_id is source of truth for cost-center approvers
- Decouples finance domain model from org-structure versioning
- Enables cost-center rebalancing (change head without re-assigning employees)

**Trade-off:**  
- New external API surface; versions must be stable
- Cost-center owner and employee assignments can diverge (design choice; acceptable for this tier)

### Decision 6: Backwards Compatibility via Employee.manager_id

**Approach:**  
Employee.manager_id remains; new code uses OrgAssignment.reports_to_user_id. Migration query derives manager_id from primary OrgAssignment for bulk-migration; old direct-manager updates are no longer used (deprecated but not deleted).

**Rationale:**  
- Avoids breaking changes to employee-master schema
- Allows gradual migration of dependent code (manager-self-service, onboarding, etc.)

**Trade-off:**  
- Dual source of truth for one reporting cycle (maintenance burden)
- Old code still reading Employee.manager_id will get stale data
- Mitigation: Feature flag to toggle which one is queried; dashboard warns of drift

## Seed Data

### OrgUnit Examples (as-of 2026-05-23)

```json
{
  "id": "ou-001",
  "name": "VDG Gemeente Amsterdam",
  "code": "VDGA",
  "unit_type": "company",
  "parent_unit_id": null,
  "head_user_id": "user-mayor",
  "cost_center_code": "CC-0001",
  "headcount_budget": 450,
  "valid_from": "2024-01-01",
  "valid_until": null
}
```

```json
{
  "id": "ou-002",
  "name": "Dienst Burgerzaken",
  "code": "DBC",
  "unit_type": "department",
  "parent_unit_id": "ou-001",
  "head_user_id": "user-dir-burgerzaken",
  "cost_center_code": "CC-BC",
  "headcount_budget": 85,
  "valid_from": "2024-01-01",
  "valid_until": null
}
```

```json
{
  "id": "ou-003",
  "name": "Team Paspoorten",
  "code": "DBC-PASS",
  "unit_type": "team",
  "parent_unit_id": "ou-002",
  "head_user_id": "user-lead-passports",
  "cost_center_code": null,
  "headcount_budget": 22,
  "valid_from": "2024-01-01",
  "valid_until": null
}
```

```json
{
  "id": "ou-004",
  "name": "Dienst Financiën",
  "code": "DF",
  "unit_type": "department",
  "parent_unit_id": "ou-001",
  "head_user_id": "user-cfo",
  "cost_center_code": "CC-FIN",
  "headcount_budget": 45,
  "valid_from": "2024-01-01",
  "valid_until": null
}
```

```json
{
  "id": "ou-005",
  "name": "Team Begroting & Planning",
  "code": "DF-BPA",
  "unit_type": "team",
  "parent_unit_id": "ou-004",
  "head_user_id": "user-lead-budget",
  "cost_center_code": null,
  "headcount_budget": 12,
  "valid_from": "2024-01-01",
  "valid_until": null
}
```

### OrgAssignment Examples (as-of 2026-05-23)

```json
{
  "id": "oa-100",
  "employee_user_id": "user-anna-jansen",
  "org_unit_id": "ou-003",
  "reports_to_user_id": "user-lead-passports",
  "role_in_unit": "Medeweker Burgerlijke Stand",
  "is_primary": true,
  "valid_from": "2025-03-01",
  "valid_until": null
}
```

```json
{
  "id": "oa-101",
  "employee_user_id": "user-bert-kok",
  "org_unit_id": "ou-003",
  "reports_to_user_id": "user-lead-passports",
  "role_in_unit": "Senior Medeweker Paspoorten",
  "is_primary": true,
  "valid_from": "2024-01-01",
  "valid_until": null
}
```

```json
{
  "id": "oa-102",
  "employee_user_id": "user-cindy-de-vries",
  "org_unit_id": "ou-005",
  "reports_to_user_id": "user-lead-budget",
  "role_in_unit": "Begrotingsmedewerker",
  "is_primary": true,
  "valid_from": "2025-06-01",
  "valid_until": null
}
```

### OrgChartSnapshot Examples (materialized for performance)

Snapshots are generated for:
- Today (00:00 UTC)
- Start-of-month
- Start-of-year
- Any manual snapshots (e.g., post-reorg)

Cache entry:
```json
{
  "snapshot_date": "2026-05-23",
  "tree_json": {
    "id": "ou-001",
    "name": "VDG Gemeente Amsterdam",
    "headcount_actual": 157,
    "headcount_budget": 450,
    "children": [
      {
        "id": "ou-002",
        "name": "Dienst Burgerzaken",
        "headcount_actual": 52,
        "headcount_budget": 85,
        "children": [
          {
            "id": "ou-003",
            "name": "Team Paspoorten",
            "headcount_actual": 22,
            "headcount_budget": 22,
            "children": []
          }
        ]
      },
      {
        "id": "ou-004",
        "name": "Dienst Financiën",
        "headcount_actual": 39,
        "headcount_budget": 45,
        "children": [
          {
            "id": "ou-005",
            "name": "Team Begroting & Planning",
            "headcount_actual": 12,
            "headcount_budget": 12,
            "children": []
          }
        ]
      }
    ]
  },
  "generated_at": "2026-05-23T12:30:45Z"
}
```

## Data Model Diagram

```
OrgUnit
├─ id (uuid)
├─ name (string)
├─ code (string, unique within parent)
├─ unit_type (enum: company | division | department | team | cost-center | project)
├─ parent_unit_id (uuid, nullable, no cycles)
├─ head_user_id (uuid, nullable → User)
├─ cost_center_code (string, nullable)
├─ headcount_budget (int)
├─ valid_from (date)
└─ valid_until (date, nullable)

OrgAssignment
├─ id (uuid)
├─ employee_user_id (uuid → User)
├─ org_unit_id (uuid → OrgUnit)
├─ reports_to_user_id (uuid, nullable → User)
├─ role_in_unit (string)
├─ is_primary (boolean, constraint: ≤1 per employee at time T)
├─ valid_from (date)
└─ valid_until (date, nullable)

OrgChartSnapshot (materialized view)
├─ snapshot_date (date, PK)
├─ tree_json (jsonb)
└─ generated_at (timestamp)
```

## API Surface

### OrgUnit CRUD

- `POST /api/org-units` — Create (admin)
- `GET /api/org-units/{id}` — Read
- `PATCH /api/org-units/{id}` — Update
- `DELETE /api/org-units/{id}` — Soft-delete (set valid_until = today)
- `GET /api/org-units` — List (filter by parent, unit_type, valid_as_of_date)

### OrgAssignment CRUD

- `POST /api/org-assignments` — Create (admin)
- `GET /api/org-assignments/{id}` — Read
- `PATCH /api/org-assignments/{id}` — Update
- `DELETE /api/org-assignments/{id}` — Soft-delete (set valid_until = today)
- `GET /api/org-assignments` — List (filter by employee, unit, valid_as_of_date)

### Org-Chart Queries

- `GET /api/org-chart/tree?as_of_date=2026-05-23` — Full tree JSON
- `GET /api/org-chart/tree/{unit-id}/subtree?as_of_date=2026-05-23` — Subtree
- `GET /api/org-chart/reporting-line?employee_id=X&as_of_date=Y` — Manager chain
- `POST /api/org-chart/export` — Export (format: png | pdf | svg | json)

### Cost-Center Routing

- `GET /api/cost-centers/{code}` — Resolve code → OrgUnit with current head_user_id

## Constraints & Rules

1. **Cycle detection**: parent_unit_id cannot create a cycle (up to root)
2. **Unique code within parent**: No two children of the same parent share a code
3. **Primary assignment constraint**: Max 1 is_primary per employee at any valid_from/valid_until range
4. **No future overlap**: Cannot have two is_primary assignments with overlapping validity
5. **Head validity**: head_user_id must be valid Employee in openregister.users (foreign-key)
6. **Snapshot invalidation**: Any write to OrgUnit/OrgAssignment invalidates snapshots from today onward
7. **Reporting-line null handling**: If OrgAssignment.reports_to_user_id is null, query falls back to OrgUnit.head_user_id chain (transitive, max 5 levels)

## Integration Points

- **employee-management**: Employee.user_id joins OrgAssignment.employee_user_id
- **manager-self-service**: Derives team_scope from OrgAssignment.reports_to_user_id valid-as-of today
- **openregister (org)**: OrgUnit + OrgAssignment schemas shared with finance apps
- **n8n onboarding**: Workflow creates OrgAssignment for new hire
- **n8n offboarding**: Workflow closes OrgAssignment (sets valid_until = today)
- **finance apps** (shillinq, expense-management): Query cost-center → OrgUnit → head_user_id for approval routing

## Performance Considerations

- Index on `(parent_unit_id, valid_from, valid_until)` for tree traversal
- Index on `(employee_user_id, is_primary, valid_from, valid_until)` for manager lookups
- Index on `(valid_from, valid_until)` for date-range snapshot cache hits
- Snapshot cache kept for last 90 days; async cleanup nightly
- Reporting-line resolution caches in-request (5-level lookup at most)
