---
status: draft
change: rostering-planning
---

# Rostering & Planning — Design

## Data Model & Schemas

All entities live in the `hrmq` openregister as first-class objects with full audit, RBAC, and lifecycle support.

### Schema: `roster-period`

**Purpose**: Planning unit (normally week, configurable per employer). The container for all shifts in a time window.

**Fields**:
```json
{
  "employer_id": "uuid (openregister ref: employer-master)",
  "location_id": "uuid (openregister ref: employee-master location)",
  "period_start": "date (ISO 8601)",
  "period_end": "date (ISO 8601)",
  "status": "enum: draft | in_review | published | locked | archived",
  "published_at": "datetime UTC (nullable)",
  "published_by": "uuid ref: actor (employee/user)",
  "locked_at": "datetime UTC (nullable)",
  "notes": "string (planner notes, not visible to employees)",
  "cost_projection": {
    "total_hours": "number",
    "total_loonkosten_estimate": "number (EUR)",
    "toeslag_breakdown": {
      "avond_minutes": "number",
      "nacht_minutes": "number",
      "weekend_minutes": "number",
      "feestdag_minutes": "number"
    }
  },
  "atw_violation_count": "number (unresolved violations)",
  "swap_count": "number (approved swaps in period)"
}
```

**Indexes**: `(employer_id, period_start)`, `(employer_id, status)`

**Lifecycle States** (x-openregister-lifecycle):
- `draft` → `in_review` (planner indicates ready for review)
- `in_review` → `published` (approved, if zero unresolved ATW violations)
- `in_review` → `draft` (if violations discovered)
- `published` → `locked` (after 24h, prevents unsanctioned edits)
- Any status → `archived` (retention cleanup, ~13 weeks post-period)

---

### Schema: `shift`

**Purpose**: One planned working block. The atom of the rooster.

**Fields**:
```json
{
  "roster_period_id": "uuid (required)",
  "employee_id": "uuid (nullable if open-shift, else ref: employee-master)",
  "position_id": "uuid (ref: role/function from employee-master)",
  "location_id": "uuid",
  "cost_centre_id": "uuid (ref: cost-centre from payroll-engine-nl)",
  "start_datetime": "datetime UTC",
  "end_datetime": "datetime UTC",
  "paid_break_minutes": "number",
  "unpaid_break_minutes": "number",
  "expected_toeslag_minutes": {
    "avond": "number (cumulative across all hours)",
    "nacht": "number (22:00–06:00 NL time)",
    "weekend": "number (Saturday + Sunday hours)",
    "feestdag": "number (statutory holiday)"
  },
  "expected_overtime_minutes": "number (above 38 contractual hours for the week)",
  "atw_validation_state": "enum: clean | warning | violation_overridden",
  "atw_violation_codes": ["array of violation codes: ATW_DAILY_MAX_EXCEEDED, ATW_INSUFFICIENT_REST, ATW_CONSECUTIVE_NIGHT_MAX, etc."],
  "atw_override_reason": "string (nullable, required if violation_overridden)",
  "atw_override_by": "uuid ref: actor (who overrode)",
  "swap_request_id": "uuid (nullable, if shift is part of pending swap)",
  "published_to_employee": "boolean (true if employee has been notified)",
  "notification_dispatched_at": "datetime UTC (nullable)"
}
```

**Indexes**: `(roster_period_id, employee_id, start_datetime)`, `(location_id, start_datetime)`, `(status, atw_validation_state)` partial

**Lifecycle States** (x-openregister-lifecycle):
- `draft` → `open` (unassigned shift available for assignment or swap)
- `draft` → `assigned` (employee_id is set)
- `assigned` → `locked` (roster period published, unsanctioned edits blocked)
- `assigned` → `needs_replan` (availability-exception overlaps or ATW revalidation fails)
- Any state → `cancelled` (shift removed, typically post-publication with employee notification)

**Calculations** (x-openregister-calculations):
- `workMinutes`: (end_datetime - start_datetime) - (paid_break_minutes + unpaid_break_minutes)
- `totalToeslaginutes`: sum of expected_toeslag_minutes across all categories
- `isOverCapacity`: expected_overtime_minutes > 0
- `atw_display_badge`: human-readable violation summary for grid cell

---

### Schema: `shift-template`

**Purpose**: Reusable pattern for recurring shifts. Stamped into shifts when period is rolled forward.

**Fields**:
```json
{
  "name": "string (e.g. 'Saturday evening kassa')",
  "position_id": "uuid",
  "location_id": "uuid",
  "recurrence_rule": "string (RFC 5545 RRULE, e.g. 'FREQ=WEEKLY;BYDAY=SA;BYHOUR=18')",
  "start_time": "time (HH:MM, NL local)",
  "end_time": "time (HH:MM, NL local)",
  "breaks": {
    "paid_minutes": "number",
    "unpaid_minutes": "number"
  },
  "default_cost_centre_id": "uuid",
  "active": "boolean"
}
```

**Indexes**: `(location_id, active)`, `(position_id, active)`

---

### Schema: `swap-request`

**Purpose**: Structured request from one employee to another to exchange shifts (or one-way takeover).

**Fields**:
```json
{
  "initiator_employee_id": "uuid",
  "initiator_shift_id": "uuid",
  "counterparty_employee_id": "uuid (nullable for broadcast)",
  "counterparty_shift_id": "uuid (nullable for one-way coverage)",
  "swap_type": "enum: one_way_takeover | two_way_swap | broadcast",
  "status": "enum: proposed | counterparty_accepted | manager_review | approved | rejected | cancelled",
  "manager_decision_reason": "string (nullable, filled on approval/rejection)",
  "created_at": "datetime UTC",
  "resolved_at": "datetime UTC (nullable)",
  "notification_thread_id": "uuid (links to activity/notification engine)"
}
```

**Indexes**: `(status, created_at)`, `(initiator_employee_id, status)`, `(counterparty_employee_id, status)`, partial on manager_review

**Lifecycle States** (x-openregister-lifecycle):
- `proposed` → `counterparty_accepted` (if two_way_swap, counterparty accepts; if broadcast, eligible pool sees it)
- `counterparty_accepted` → `manager_review` (routed to manager's approval queue)
- `manager_review` → `approved` (manager approves, if no blocking ATW violations)
- `manager_review` → `rejected` (manager denies, with reason)
- Any state → `cancelled` (initiator or counterparty withdraws)

**Notifications** (x-openregister-notifications):
- On `proposed`: counterparty (if two_way) or eligible pool (if broadcast) receives PWA + email
- On `counterparty_accepted`: manager receives notification with side-by-side shift preview
- On `approved`: both employees receive confirmation + updated rooster link
- On `rejected`: initiator receives reason + counter-offer suggestion

---

### Schema: `availability-exception`

**Purpose**: One-off blocks (vacation, sickness, training) not covered by recurring `availability-window`. Canonical "the rooster cannot place X here" feed.

**Fields**:
```json
{
  "employee_id": "uuid",
  "start_datetime": "datetime UTC",
  "end_datetime": "datetime UTC",
  "exception_type": "enum: leave_approved | leave_pending | sickness | training | other_unavailable | extra_available",
  "source_id": "uuid (ref: leave-absence record where applicable)",
  "notes": "string (planner notes; never stores sickness reason per AVG)"
}
```

**Indexes**: `(employee_id, start_datetime, end_datetime)`, `(exception_type, status)`

**Lifecycle Events** (x-openregister-notifications):
- On creation: if overlaps with existing shifts, flag those shifts `needs_replan` and notify planner with affected list

---

### Schema: `coverage-requirement`

**Purpose**: Minimum/maximum staffing by location, position, time. Drives cell highlighting and publication gate enforcement.

**Fields**:
```json
{
  "location_id": "uuid",
  "position_id": "uuid",
  "weekday": "enum: monday | tuesday | ... | sunday",
  "start_time": "time (HH:MM)",
  "end_time": "time (HH:MM)",
  "min_staff": "number",
  "max_staff": "number (nullable, no ceiling if null)",
  "effective_from": "date",
  "effective_until": "date (nullable = ongoing)",
  "skill_tags": ["array of skills required (e.g. 'kassa_training', 'flexi_license')"]
}
```

**Indexes**: `(location_id, weekday)`, `(effective_from, effective_until)` partial on effective status

**Aggregations** (x-openregister-aggregations):
- `currentCoverage(location_id, weekday, time)`: count of assigned staff matching position + skill-tags for this location/time slot
- `coverageGaps()`: list of (location, weekday, time, min_staff, current_staff) rows where current < min

---

## Data Relationships

```
roster-period
  └─→ shift (1:N) [roster_period_id]
  └─→ swap-request (1:N) [initiator_shift_id, counterparty_shift_id]
  └─→ coverage-requirement (1:N) [location_id, period within effective date range]

shift
  ├─→ employee-master [employee_id, nullable]
  ├─→ position [position_id, from employee-master]
  ├─→ cost-centre [cost_centre_id, from payroll-engine-nl]
  ├─→ availability-exception (N for overlaps, read-only)
  ├─→ time-attendance [employee_id, for actual clock-in comparison]
  └─→ swap-request (0:1) [swap_request_id]

swap-request
  ├─→ employee-master [initiator_employee_id, counterparty_employee_id]
  ├─→ shift [initiator_shift_id, counterparty_shift_id]
  └─→ activity/notification [notification_thread_id]

availability-exception
  ├─→ employee-master [employee_id]
  └─→ leave-absence [source_id, for vacation/sickness records]
```

---

## Reuse Analysis

The platform provides comprehensive abstractions that this change leverages:

- **ObjectService** (CRUD, search, locking) — all shift/swap/coverage operations delegate to this
- **CnDataTable + CnIndexPage** — roster grid view
- **CnDetailPage + CnDetailGrid** — shift/swap detail views
- **ImportService/ExportService** — CSV/Excel import of roster templates, export as ICS for employee calendars
- **FileService** — rosters archived as PDF in docudesk
- **AuditTrailService** — audit log of every shift mutation, swap, override (automatic via openregister)
- **NotificationService** — PWA push + email dispatch on publication, swap resolution, shift change
- **ActivityService** — swap-request discussion thread
- **AuthorizationService** — role-based access (planner, manager, employee, OR inzage)
- **VectorizationService** — semantic search across rooster notes and swap-request reasons (future)

No custom service classes are required for lifecycle transitions, aggregations, calculations, or notifications — all declared in schema metadata per ADR-031.

---

## Design Decisions

### 1. Why openregister objects instead of custom tables?

Per ADR-001 (data-layer), all domain data lives in OpenRegister. This gives:
- Automatic audit trail (every mutation logged)
- RBAC per entity + field
- Real-time websocket sync across multiple planners on the same period
- CloudEvents for n8n automations
- GraphQL queryability for custom dashboards

### 2. Why not persist shift assignment history?

Shift history is queryable via the audit trail (AuditTrailService). The current `shift.employee_id` is the source-of-truth; all past values are recovered from the audit log. This avoids duplicating state and keeps the schema compact.

### 3. Why separate roster-period and shift schemas?

A period is a planning container with publication state, cost projection, and ATW violation count—information that applies to the whole window. A shift is a single working block with employee, start/end, and breaks. Separating them allows:
- Bulk operations on the period (publish all, lock all, archive)
- Period-level cost dashboard without summing shifts each read
- Filtering/searching shifts by roster-period context

### 4. Why nullable employee_id on shift?

Open shifts (unassigned slots) are placeholders for the planner to fill or broadcast to the employee pool. They satisfy coverage-requirements until a concrete employee is assigned. Nulling employee_id allows the planner to "un-assign" a problematic shift and re-broadcast without recreating the shift record.

### 5. Declarative vs. Imperative Business Logic

**Lifecycle transitions** (draft → in_review → published → locked) are declarative via `x-openregister-lifecycle` in the schema register. No `RosterPeriodService.transition*()` methods.

**ATW validation** is a hybrid:
- **Declarative**: Basic checks (daily max, rest min, consecutive nachtdiensten) are computed in `x-openregister-calculations` as a derived `atw_validation_state` field.
- **Imperative**: Complex checks (CAO-specific exceptions, sector overrides, multi-day interactions) are computed in a PHP guard (`Atw ValidationGuard`) called *by* the lifecycle engine on shift save.

This hybrid keeps the simple rules declarative (auditable, no code review) while allowing complex rules to live in code.

**Aggregations** (coverage gaps, period totals) are declarative via `x-openregister-aggregations`.

**Notifications** are declarative via `x-openregister-notifications` — recipient resolution, channel fan-out, and templating are engine-provided.

---

## Seed Data

Example objects for development and QA:

### roster-period (Filiaal Utrecht, week 22/2026)
```json
{
  "@self": {
    "register": "hrmq",
    "schema": "roster-period",
    "slug": "filiaal-utrecht-week-22-2026"
  },
  "employer_id": "uuid-mock-employer-001",
  "location_id": "uuid-filiaal-utrecht",
  "period_start": "2026-05-25",
  "period_end": "2026-05-31",
  "status": "draft",
  "notes": "Whitsun period, koopzondag 31-May",
  "cost_projection": {
    "total_hours": 185,
    "total_loonkosten_estimate": 4850.75,
    "toeslag_breakdown": {
      "avond_minutes": 120,
      "nacht_minutes": 240,
      "weekend_minutes": 480,
      "feestdag_minutes": 0
    }
  },
  "atw_violation_count": 0,
  "swap_count": 1
}
```

### shift (Kassa, Saturday evening, assigned to Anna)
```json
{
  "@self": {
    "register": "hrmq",
    "schema": "shift",
    "slug": "filiaal-utrecht-shift-anna-sat-evening-week22"
  },
  "roster_period_id": "uuid-filiaal-utrecht-week-22-2026",
  "employee_id": "uuid-anna-de-vries",
  "position_id": "uuid-position-kassa",
  "location_id": "uuid-filiaal-utrecht",
  "cost_centre_id": "uuid-cc-retail-main",
  "start_datetime": "2026-05-29T18:00:00Z",
  "end_datetime": "2026-05-30T00:30:00Z",
  "paid_break_minutes": 30,
  "unpaid_break_minutes": 0,
  "expected_toeslag_minutes": {
    "avond": 240,
    "nacht": 30,
    "weekend": 360,
    "feestdag": 0
  },
  "expected_overtime_minutes": 0,
  "atw_validation_state": "clean",
  "published_to_employee": false,
  "notification_dispatched_at": null
}
```

### shift (Open kassa, Sunday morning)
```json
{
  "@self": {
    "register": "hrmq",
    "schema": "shift",
    "slug": "filiaal-utrecht-shift-open-sun-morning-week22"
  },
  "roster_period_id": "uuid-filiaal-utrecht-week-22-2026",
  "employee_id": null,
  "position_id": "uuid-position-kassa",
  "location_id": "uuid-filiaal-utrecht",
  "cost_centre_id": "uuid-cc-retail-main",
  "start_datetime": "2026-05-31T09:00:00Z",
  "end_datetime": "2026-05-31T13:00:00Z",
  "paid_break_minutes": 0,
  "unpaid_break_minutes": 15,
  "expected_toeslag_minutes": {
    "avond": 0,
    "nacht": 0,
    "weekend": 225,
    "feestdag": 0
  },
  "expected_overtime_minutes": 0,
  "atw_validation_state": "clean"
}
```

### shift-template (Kassa, Saturday evening recurring)
```json
{
  "@self": {
    "register": "hrmq",
    "schema": "shift-template",
    "slug": "filiaal-utrecht-template-kassa-sat-evening"
  },
  "name": "Kassa Saturday evening",
  "position_id": "uuid-position-kassa",
  "location_id": "uuid-filiaal-utrecht",
  "recurrence_rule": "FREQ=WEEKLY;BYDAY=SA;BYHOUR=18;BYMINUTE=0;INTERVAL=1",
  "start_time": "18:00",
  "end_time": "00:30",
  "breaks": {
    "paid_minutes": 30,
    "unpaid_minutes": 0
  },
  "default_cost_centre_id": "uuid-cc-retail-main",
  "active": true
}
```

### swap-request (Anna requests to swap Saturday evening for Sunday afternoon)
```json
{
  "@self": {
    "register": "hrmq",
    "schema": "swap-request",
    "slug": "swap-anna-sat-for-jan-sun-week22"
  },
  "initiator_employee_id": "uuid-anna-de-vries",
  "initiator_shift_id": "uuid-shift-anna-sat-evening",
  "counterparty_employee_id": "uuid-jan-de-jong",
  "counterparty_shift_id": "uuid-shift-jan-sun-afternoon",
  "swap_type": "two_way_swap",
  "status": "counterparty_accepted",
  "created_at": "2026-05-23T14:32:00Z",
  "resolved_at": null,
  "notification_thread_id": "uuid-activity-thread-001"
}
```

### availability-exception (Sem on training, Tuesday afternoon)
```json
{
  "@self": {
    "register": "hrmq",
    "schema": "availability-exception",
    "slug": "sem-training-tuesday-afternoon-week22"
  },
  "employee_id": "uuid-sem-de-jong",
  "start_datetime": "2026-05-26T13:00:00Z",
  "end_datetime": "2026-05-26T17:00:00Z",
  "exception_type": "training",
  "source_id": null,
  "notes": "POS system recertification"
}
```

### coverage-requirement (Kassa Sunday, koopzondag minimum 2 staff)
```json
{
  "@self": {
    "register": "hrmq",
    "schema": "coverage-requirement",
    "slug": "filiaal-utrecht-kassa-sunday-min2"
  },
  "location_id": "uuid-filiaal-utrecht",
  "position_id": "uuid-position-kassa",
  "weekday": "sunday",
  "start_time": "09:00",
  "end_time": "18:00",
  "min_staff": 2,
  "max_staff": 4,
  "effective_from": "2026-01-01",
  "effective_until": null,
  "skill_tags": []
}
```

---

## Integration Points

### time-attendance
- **Read**: `availability-window` (recurring availability) and actual clock events (`clock_in`, `clock_out`)
- **Write**: Rooster publishes `shift` records; time-attendance compares actual vs. planned and surfaces deviations

### employee-master
- **Read**: `employee_id`, `position_id`, `manager_id`, `contractual_hours`, `cao_id`, `skills`
- **Write**: None (rooster is read-only view of employee data)

### leave-absence
- **Read**: Approved vacation and sickness records to generate `availability-exception` entries
- **Subscribe**: When a leave-absence record transitions to `approved`, generate corresponding `availability-exception` and flag overlapping shifts for replan

### payroll-engine-nl
- **Read**: CAO matrix for toeslag and overtime calculation
- **Write**: Publish `expected_toeslag_minutes` and `expected_overtime_minutes` per shift; publish period cost projection for cash-flow planning

### n8n
- **Subscribe**: `roster.shift.published`, `roster.swap.approved`, `roster.coverage.gap` events for customer-built automations

### docudesk
- **Write**: Archive published rosters as PDF in location dossier for AVG/CAO audit trail

---

## Accessibility & NL Design

- Grid uses `nldesign` theming when employer enables it (standard government blue palette)
- Shift cells include colour-blind safe tints (no red-only warnings)
- Keyboard navigation for grid (arrow keys, tab, enter/space to edit)
- Screen-reader labels for every cell, swap-request, and violation indicator
- Dutch date/time formatting (DD-MM-YYYY, 24-hour HH:MM)
- Toeslag categories and ATW violations use Dutch labels only (no English fallback in UI)

---

## API & Export

### REST Endpoints (inherited from ObjectService + OpenAPI 3.0)
- `POST /api/registers/hrmq/schemas/shift` — create shift
- `GET /api/registers/hrmq/schemas/shift/{id}` — fetch shift with audit trail
- `PATCH /api/registers/hrmq/schemas/shift/{id}` — update shift, trigger ATW re-validation
- `GET /api/registers/hrmq/schemas/roster-period/{id}/aggregations/coverage-gaps` — list unmet coverage slots
- `POST /api/registers/hrmq/schemas/swap-request` — create swap-request, route to counterparty/manager

### ICS Export
- Roster-period exportable as RFC 5545 calendar with shifts as VEVENT entries (employee-specific, containing only their assigned shifts)
- Importable into personal calendar apps (iOS, Google Calendar, Outlook) with recurring availability overlaid

### CSV Export
- Roster grid exportable as CSV (one row per shift, columns: employee, position, date, time, hours, projected toeslag, ATW status)

---

## Security & Compliance

### RBAC
- **Planner role**: Can view all shifts/periods for their location(s), create/edit shifts, publish periods, override ATW warnings
- **Employee role**: Can view only their own shifts, request swaps, view personal rooster and availability exceptions
- **Manager role**: Can approve/reject swap-requests and publication-blocking violations
- **OR role** (`or_inzage`): Can view anonymized audit log of all shifts/swaps for WOR instemmingsrecht

### ATW Override Audit
- Every ATW override is logged with `atw_override_reason` (why the violation was accepted) and `atw_override_by` (who approved)
- Audit log is hash-chained and tamper-evident per AuditTrailService

### Sickness Data Protection
- Sickness reason is never stored in `availability-exception.notes` (special-category per AVG article 9)
- Only the date/time window is recorded; reason lives only in leave-absence app (HR-scoped)

### Vacation Reason Redaction
- Vacation reason field is restricted to HR role and OR (under WOR instemmingsrecht)
- Planners see only "on leave" label, not reason

---

## Performance Targets

- Roster grid (7×50 shift cells) renders in <500ms on page load
- Drag-to-assign shift commits and syncs to peers <1s via websocket
- ATW re-validation completes <2s for a single employee across a week
- Period cost projection recalculates <3s on shift mutation
- Swap-request approval propagates to both employees <30s (email may take longer)

Indexes on `shift (roster_period_id, employee_id, start_datetime)` and `swap_request (status, created_at)` ensure these queries stay fast.
