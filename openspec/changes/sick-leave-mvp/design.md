# Design: Sick Leave / Verzuim MVP (70%/70%, UWV)

## Context

HRMQ's `employee-master` dependency provides the Employee entity. This change adds the `SickLeaveCase` entity on top of it. All domain data is stored as OpenRegister objects (ADR-001); no custom Entity/Mapper is written. Business logic — lifecycle transitions, derived field calculations, and UWV deadline notifications — is declared in the schema register file (`lib/Settings/hrmq_register.json`) using `x-openregister-*` extensions per ADR-031. Custom PHP code is limited to a thin CRUD controller and an `ActionAuthService` for action-level RBAC per ADR-023.

## Goals / Non-Goals

**Goals:**
- Register and track sick leave cases (ziekmelding, hersteldmelding) per employee
- Track wachtdag (first unpaid day) per case
- Display 70% wage-continuation percentage (year 1 and year 2)
- Alert HR at week 40 (advance) and week 42 (statutory deadline) for UWV notification
- Track UWV 42-week notification submission status per case
- Provide a verzuim dashboard with actionable KPIs

**Non-Goals (deferred to future changes):**
- Full WVP timeline with all statutory milestones (week 6 plan van aanpak, week 8 evaluation, week 52 second-year assessment)
- Arbo-dienst (occupational health) integration
- Re-integration plan (Plan van Aanpak) management
- Payroll export of the 70% deduction into a salary run
- Automated UWV API submission — MVP tracks manual submission status only

---

## Schema Definition

### Entity: SickLeaveCase

Schema.org type: `schema:Event` (a sick leave period is a dated workplace event with a start and optional end).

#### Properties

| Property | schema.org | Type | Required | Description |
|---|---|---|---|---|
| `startDate` | `schema:startDate` | date | yes | First day of sick leave (eerste ziektedag) |
| `endDate` | `schema:endDate` | date | no | Recovery date (hersteldag); `null` while the employee is still sick |
| `reportedDate` | `schema:dateCreated` | date | yes | Date the sick report was registered in the system |
| `reportedBy` | `schema:agent` | string | yes | Nextcloud UID of the user who created the report |
| `employee` | `schema:participant` | relation | yes | Reference to the Employee object (register: hrmq, schema: Employee) |
| `status` | `schema:status` | string (enum) | yes | `sick` \| `partially_recovered` \| `recovered` \| `long_term_sick` |
| `wachtdagApplies` | *(custom — no schema.org equiv; see ADR-011 exception for app-specific workflow state)* | boolean | yes | Whether the first day is an unpaid waiting day per the employment contract |
| `causeCategory` | `schema:additionalType` | string (enum) | no | `unknown` \| `illness` \| `accident` \| `work_related` \| `pregnancy` |
| `uwvNotificationSent` | *(custom)* | boolean | no | Whether the 42-week UWV notification has been submitted to UWV |
| `uwvNotificationSentDate` | *(custom)* | date | no | Date on which the UWV notification was submitted |
| `notes` | `schema:description` | string | no | Free-text notes on the case |

#### Calculated fields (`x-openregister-calculations`)

| Field | Computation | Description |
|---|---|---|
| `durationDays` | `(endDate ?? @today) - startDate` in calendar days | Total duration of sick leave; live count if no endDate yet |
| `uwvDeadlineDate` | `startDate + 294` days (42 × 7) | Statutory date by which UWV must be notified |
| `paymentPercentage` | `70` (constant for MVP) | Statutory 70% wage-continuation (both year 1 and year 2 in MVP) |
| `isInYear2` | `durationDays > 365` | True when sick leave has exceeded one full year |
| `uwvDeadlineApproaching` | `uwvDeadlineDate - @today ≤ 14 AND NOT uwvNotificationSent` | True when the deadline is within 14 days and notification not yet sent |
| `uwvDeadlineReached` | `durationDays ≥ 294 AND NOT uwvNotificationSent` | True when 42 weeks have passed but UWV notification has not been sent |

#### Lifecycle (`x-openregister-lifecycle`)

Initial state: `sick` (set at creation by the `report_sick` transition).

| Transition | From states | To state | Precondition |
|---|---|---|---|
| `report_sick` | *(initial / no state)* | `sick` | none |
| `partial_recovery` | `sick`, `long_term_sick` | `partially_recovered` | `endDate` must be set |
| `report_recovery` | `sick`, `partially_recovered`, `long_term_sick` | `recovered` | `endDate` must be set |
| `escalate_long_term` | `sick` | `long_term_sick` | `durationDays ≥ 294` |
| `reopen` | `recovered` | `sick` | none (relapse) |

#### Notifications (`x-openregister-notifications`)

| Notification id | Trigger condition | Recipients | Message |
|---|---|---|---|
| `uwv_42week_advance` | `durationDays = 280` (40 weeks) | `hr-managers` group | "UWV 42-week notification due in 14 days for {employee.name} (case since {startDate})" |
| `uwv_42week_due` | `durationDays = 294` (42 weeks) | `hr-managers` group | "UWV 42-week notification deadline reached for {employee.name} — submit now to avoid a fine" |

---

## Declarative-vs-imperative decisions (ADR-031)

| Behaviour | Decision | Rationale |
|---|---|---|
| Status lifecycle (ziek → hersteld etc.) | **Declarative** — `x-openregister-lifecycle` | Straightforward finite state machine; OR provides audit-trailed transitions, per-state RBAC, and automatic CloudEvents at no cost |
| Duration, deadline, and payment calculations | **Declarative** — `x-openregister-calculations` | All derived from object fields + current date; no external state or side effects |
| UWV 42-week notifications | **Declarative** — `x-openregister-notifications` | Threshold-triggered, single recipient group; OR notification engine handles delivery, deduplication, and scheduling |
| UWV API submission | **Not in MVP — would be imperative** | External API integration requires authentication credentials and HTTP calls to UWV systems; no OR extension covers external API calls (ADR-031 exception 1). When this lands, it will be a separate change (`sick-leave-uwv-api`) with a PHP `UwvApiService`. |

---

## Reuse Analysis (ADR-001 / ADR-022)

No custom CRUD, search, pagination, file management, or audit logic is written. The following platform services are used:

| Platform service | Used for |
|---|---|
| `ObjectService.saveObject()` / `deleteObject()` | CRUD on SickLeaveCase objects |
| `ObjectService.findAll()` with filter params | Fetching paginated lists of cases |
| `CnIndexPage` + `useListView` | Sick leave case list with search, sort, filter, pagination |
| `CnDetailPage` + `CnDetailCard` + `CnObjectSidebar` | Case detail view with audit trail, notes, files tabs |
| `CnFormDialog` (schema-driven) | Create and edit sick leave case (fields auto-generated from schema) |
| `CnDashboardPage` + `CnStatsBlock` + `CnChartWidget` | Verzuim dashboard |
| `CnTimelineStages` | Lifecycle status progression bar on case detail page |
| `AuditTrailService` (automatic via OR) | Full change history per case |
| `AuthorizationService` (OR) | Data-layer RBAC on SickLeaveCase objects |
| `createObjectStore` + `lifecyclePlugin` | Pinia store for sick leave cases with lifecycle transition support |
| `NotificationService` (OR-triggered) | UWV deadline alerts to HR managers |

No duplication with existing OpenRegister core services or shared Vue components was found. The Employee schema from `employee-master` is referenced via OpenRegister relations; it is not modified.

---

## Seed Data

Included in `lib/Settings/hrmq_register.json` under `components.objects[]` with `@self` envelopes per ADR-001. Five realistic Dutch seed objects cover all statuses and edge cases for dev and QA.

### SickLeaveCase — 5 seed objects

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "SickLeaveCase",
      "slug": "sick-case-jan-de-vries-2026-01"
    },
    "startDate": "2026-01-15",
    "endDate": "2026-02-03",
    "reportedDate": "2026-01-15",
    "reportedBy": "hr.manager@gemeente-demo.nl",
    "status": "recovered",
    "wachtdagApplies": true,
    "causeCategory": "illness",
    "uwvNotificationSent": false,
    "notes": "Griep; volledig hersteld na 19 dagen. Wachtdag van toepassing conform cao."
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "SickLeaveCase",
      "slug": "sick-case-maria-janssen-2025-07"
    },
    "startDate": "2025-07-01",
    "reportedDate": "2025-07-01",
    "reportedBy": "hr.manager@gemeente-demo.nl",
    "status": "long_term_sick",
    "wachtdagApplies": false,
    "causeCategory": "work_related",
    "uwvNotificationSent": true,
    "uwvNotificationSentDate": "2026-02-10",
    "notes": "RSI-klachten; WVP-traject gestart. 42-weekmelding verstuurd aan UWV op 10 februari."
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "SickLeaveCase",
      "slug": "sick-case-pieter-van-den-berg-2026-04"
    },
    "startDate": "2026-04-20",
    "reportedDate": "2026-04-20",
    "reportedBy": "teamleider@gemeente-demo.nl",
    "status": "sick",
    "wachtdagApplies": true,
    "causeCategory": "unknown",
    "uwvNotificationSent": false,
    "notes": ""
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "SickLeaveCase",
      "slug": "sick-case-sophie-bakker-2026-02"
    },
    "startDate": "2026-02-10",
    "endDate": "2026-05-01",
    "reportedDate": "2026-02-10",
    "reportedBy": "hr.manager@gemeente-demo.nl",
    "status": "partially_recovered",
    "wachtdagApplies": false,
    "causeCategory": "illness",
    "uwvNotificationSent": false,
    "notes": "Gedeeltelijk hersteld; 50% werkhervatting per 1 mei 2026."
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "SickLeaveCase",
      "slug": "sick-case-ahmed-el-farouq-2025-08"
    },
    "startDate": "2025-08-12",
    "reportedDate": "2025-08-12",
    "reportedBy": "hr.manager@adviesbureau-demo.nl",
    "status": "long_term_sick",
    "wachtdagApplies": false,
    "causeCategory": "illness",
    "uwvNotificationSent": true,
    "uwvNotificationSentDate": "2026-03-05",
    "notes": "Langdurig verzuim; tweede-jaar-traject start augustus 2026. 42-weekmelding op 5 maart verstuurd."
  }
]
```

---

## Action matrix seed data (ADR-023)

Default: all actions restricted to `admin` on first install. HR managers group can be granted access by an administrator.

```json
{
  "sick-leave.report-sick":     ["admin", "hr-managers"],
  "sick-leave.report-recovery": ["admin", "hr-managers"],
  "sick-leave.mark-uwv-sent":   ["admin", "hr-managers"],
  "sick-leave.delete-case":     ["admin"],
  "settings.write":             ["admin"]
}
```

---

## API design

Routes follow ADR-002 conventions. Base path: `/index.php/apps/hrmq/api/sick-leave-cases`.

| Method | Route | Description | Auth attribute |
|---|---|---|---|
| GET | `/api/sick-leave-cases` | List cases (paginated, filterable by status/employee/date) | `#[NoAdminRequired]` |
| GET | `/api/sick-leave-cases/{id}` | Fetch a single case | `#[NoAdminRequired]` |
| POST | `/api/sick-leave-cases` | Create new case (ziekmelding) | `#[NoAdminRequired]` + action `sick-leave.report-sick` |
| PUT | `/api/sick-leave-cases/{id}` | Update case fields | `#[NoAdminRequired]` + per-object auth |
| DELETE | `/api/sick-leave-cases/{id}` | Delete case | `#[NoAdminRequired]` + action `sick-leave.delete-case` |
| POST | `/api/sick-leave-cases/{id}/transitions/{transition}` | Trigger lifecycle transition | `#[NoAdminRequired]` + action check per transition |

Every `#[NoAdminRequired]` mutation endpoint carries a per-object authorization check (ADR-005 Rule 3) to prevent IDOR: fetch object → verify `reportedBy === $user->getUID()` OR user is in `hr-managers` OR `isAdmin()` → throw `OCSForbiddenException` otherwise.

---

## Frontend structure

```
src/
  manifest.json                     # pages: dashboard, sick-leave-cases, sick-leave-detail
  views/
    SickLeaveDashboard.vue          # CnDashboardPage + CnStatsBlock KPIs + CnChartWidget
    SickLeaveCaseIndex.vue          # CnIndexPage + useListView
    SickLeaveCaseDetail.vue         # CnDetailPage + CnObjectSidebar + CnTimelineStages
  store/
    modules/
      sickLeaveCase.js              # createObjectStore('sickLeaveCase') + lifecyclePlugin
```

Dashboard KPI cards (4):
1. Open cases (status = sick | partially_recovered | long_term_sick)
2. UWV deadline approaching (uwvDeadlineApproaching = true)
3. Long-term sick (isInYear2 = true)
4. Recovered this month (status = recovered AND endDate in current month)
