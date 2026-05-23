# Design: Time & Attendance — Klokken, Urenstaten, Project-Tijdregistratie

**Status**: design  
**Date**: 2026-05-23  
**Change ID**: time-attendance

## Data Model Overview

Five OpenRegister schemas live in the `hrmq` register: `clock-event`, `time-sheet`, `time-sheet-entry`, `availability-window`, and `time-allocation`. All schemas inherit hrmq's tenant/employee permission model from `employee-master`.

### Schema: ClockEvent

**OpenRegister ID**: `hrmq/clock-event`

Atomic record of a clock action. Append-only; corrections create new events with `correction_reason` rather than mutating the original.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| employee_id | Relation: `hrmq/employee-master` | Yes | Reference to employee |
| event_type | Enum: `clock_in`, `clock_out`, `break_start`, `break_end` | Yes | Type of clock action |
| timestamp | DateTime (ISO 8601, UTC) | Yes | UTC timestamp of event |
| source | Enum: `web`, `mobile_pwa`, `kiosk`, `manual_correction`, `api_import` | Yes | Channel of entry |
| gps_latitude | Decimal | No | GPS latitude (WGS84) if location permission granted |
| gps_longitude | Decimal | No | GPS longitude (WGS84) if location permission granted |
| gps_accuracy_meters | Integer | No | GPS accuracy in meters |
| location_label | String | No | Reverse-geocoded location label (e.g. "Bouwplaats Groningen, Delfzijlstraat 12") |
| geofence_id | Relation: `hrmq/geofence` | No | Reference to configured geofence (optional) |
| geofence_match | Boolean | No | Whether GPS coordinates fall within geofence polygon; null if GPS low-accuracy or geofence disabled |
| device_fingerprint | String (hash) | Yes | Device/kiosk fingerprint or API client identifier |
| correction_reason | String | No | Required when source=manual_correction; reason for the correction |
| correction_by | Relation: `hrmq/user` | No | User who performed manual correction; required when source=manual_correction |

**Indexes**:
- `(employee_id, timestamp)` — find events for an employee in a time window
- `(device_fingerprint, timestamp)` — find all events from a kiosk/device

**Audit**: Every insertion logged with source, timestamp, actor (for manual_correction), GPS accuracy. Tamper-evident audit chain via openregister audit service.

---

### Schema: TimeSheet

**OpenRegister ID**: `hrmq/time-sheet`

Weekly aggregation that a manager approves. Holds aggregate metrics (total_regular_hours, total_overtime_hours, toeslag per category) computed from daily entries.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| employee_id | Relation: `hrmq/employee-master` | Yes | Reference to employee |
| iso_week | String (ISO 8601 week) | Yes | Week identifier, e.g. "2026-W21" |
| status | Enum: `draft`, `submitted`, `approved`, `rejected`, `exported`, `locked` | Yes | Workflow status |
| submitted_at | DateTime (ISO 8601, UTC) | No | When employee submitted |
| submitted_by | Relation: `hrmq/user` | No | Employee who submitted (usually employee_id but via user context) |
| approved_at | DateTime (ISO 8601, UTC) | No | When manager approved |
| approved_by | Relation: `hrmq/user` | No | Manager (from employee_master org tree) who approved |
| exported_to_payroll_at | DateTime (ISO 8601, UTC) | No | When payroll export ran |
| payroll_batch_id | String | No | Batch ID from payroll export (idempotent key: employer_id-iso_week-run_seq) |
| total_regular_hours | Decimal | No | Sum of regular_hours across all daily entries |
| total_overtime_hours | Decimal | No | Sum of overtime_hours across all daily entries |
| toeslag_minutes_avond | Integer | No | Total evening premium minutes (19:00–23:00 or CAO-defined) |
| toeslag_minutes_nacht | Integer | No | Total night premium minutes (23:00–06:00 or CAO-defined) |
| toeslag_minutes_weekend | Integer | No | Total weekend premium minutes (Saturday/Sunday) |
| toeslag_minutes_feestdag | Integer | No | Total public-holiday premium minutes (Dutch calendar) |
| exception_codes | Array of Enum | No | Exceptions flagged during aggregation: `MISSING_CLOCK_OUT`, `MISSING_CLOCK_IN`, `GEOFENCE_OUTSIDE`, `GPS_LOW_ACCURACY`, `ATW_DAILY_MAX_EXCEEDED`, `ATW_WEEKLY_MAX_EXCEEDED`, `BREAK_MISSING`, `BREAK_TOO_SHORT`, `LATE_START`, `EARLY_END`, `UNPLANNED_SHIFT`, `NO_SHOW`, `ALLOCATION_MISMATCH` |
| approval_override_reason | String | No | Manager's justification when approving a sheet with exception codes (ATW violations, geofence outside, etc.); required if exceptions present |
| notes | String | No | Free-text notes from employee or manager |

**Unique constraint**: `(employee_id, iso_week)` — one timesheet per employee per week.

**State machine**:
- draft → submitted (employee action)
- submitted → approved OR rejected (manager action)
- approved → exported (automated payroll export)
- exported → locked (automatic after export; only admin correction-run can unlock)
- rejected → draft (employee re-submits)

**Indexes**:
- `(employee_id, iso_week)` unique
- `(status, approved_at)` — find approved sheets in a window for payroll export
- `(employee_id, status)` — find sheets by workflow state

---

### Schema: TimeSheetEntry

**OpenRegister ID**: `hrmq/time-sheet-entry`

Per-day row inside the timesheet, materialised from clock events. Created daily after cutoff (default 03:00) if clock events exist.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| time_sheet_id | Relation: `hrmq/time-sheet` | Yes | Reference to parent timesheet |
| date | Date (ISO 8601) | Yes | Date of this entry |
| clock_in_event_id | Relation: `hrmq/clock-event` | No | First clock_in event of the day |
| clock_out_event_id | Relation: `hrmq/clock-event` | No | Last clock_out event of the day |
| break_event_ids | Array of Relations: `hrmq/clock-event` | No | All break_start/break_end pairs |
| gross_hours | Decimal | No | Total elapsed time from first clock_in to last clock_out (includes breaks); null if missing clock_out |
| paid_break_minutes | Integer | No | Minutes of paid break (varies by CAO and shift length) |
| unpaid_break_minutes | Integer | No | Minutes of unpaid break |
| regular_hours | Decimal | No | Hours counted as regular (up to contractual daily/weekly threshold) |
| overtime_hours | Decimal | No | Hours counted as overtime (above threshold) |
| toeslag_minutes_avond | Integer | No | Evening premium minutes for this day |
| toeslag_minutes_nacht | Integer | No | Night premium minutes for this day |
| toeslag_minutes_weekend | Integer | No | Weekend premium minutes for this day |
| toeslag_minutes_feestdag | Integer | No | Public-holiday premium minutes for this day |
| project_allocations | Array of Objects (inline time-allocation-like) | No | Array of project/task/cost-centre allocations; see time-allocation schema below |
| atw_violations | Array of Enum | No | Arbeidstijdenwet violations detected: `DAILY_MAX_EXCEEDED` (max 12h per ATW §4), `WEEKLY_MAX_EXCEEDED` (max 60h per ATW §4), `REST_PERIOD_VIOLATED` (less than 11h between shifts per ATW §5) |
| geofence_violations | Array of Relations: `hrmq/clock-event` | No | Clock events with geofence_match=false |

**Indexes**:
- `(time_sheet_id, date)` unique
- `(date)` — query all entries for a date (for daily aggregation)

**Materialization**: Created by daily aggregator after cutoff. Not manually edited; corrections via new clock-event with `source=manual_correction`.

---

### Schema: TimeAllocation

**OpenRegister ID**: `hrmq/time-allocation`

Split of working time across projects/tasks. Inline array within time-sheet-entry; also stored as separate objects for direct querying by planix/pipelinq.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| time_sheet_entry_id | Relation: `hrmq/time-sheet-entry` | Yes | Reference to parent entry |
| project_id | Relation: `planix/project` | No | Planix project (optional; required if billable) |
| task_id | Relation: `planix/task` | No | Planix task within project (optional) |
| cost_centre_id | String | Yes | Cost centre from hrmq org tree (for internal cost allocation) |
| minutes_allocated | Integer | Yes | Minutes of the entry allocated to this project/task |
| billable | Boolean | Yes | Whether this allocation is billable to a customer |
| client_id | Relation: `pipelinq/customer` | No | Customer (denormalised from project if billable=true) |

**Validation**:
- Sum of `minutes_allocated` across all allocations in an entry MUST equal net working minutes of the entry (gross_hours − unpaid_break_minutes, rounded).
- If sum < net working minutes, entry gains exception code `ALLOCATION_MISMATCH`; submission blocked until corrected.
- If sum > net working minutes, submission blocked with error `ALLOCATION_OVERRUN`.

**Indexes**:
- `(time_sheet_entry_id)` — find all allocations for an entry
- `(project_id, time_sheet_entry_id)` — find entries allocated to a project (for planix burndown)
- `(client_id, billable)` — find billable entries for a customer (for pipelinq invoicing)

---

### Schema: AvailabilityWindow

**OpenRegister ID**: `hrmq/availability-window`

Recurring availability used by `rostering-planning` to propose shifts. Included in time-attendance register because it shares the employee dimension and is used by both.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| employee_id | Relation: `hrmq/employee-master` | Yes | Reference to employee |
| weekday | Integer (0–6, ISO 8601) | Yes | 0=Monday, 6=Sunday |
| start_time | Time (HH:MM) | Yes | Shift start time (local employer timezone) |
| end_time | Time (HH:MM) | Yes | Shift end time (local employer timezone) |
| effective_from | Date (ISO 8601) | Yes | Date this window becomes effective |
| effective_until | Date (ISO 8601) | No | Date this window expires (open-ended if null) |
| recurrence_rule | String (RFC 5545 RRULE) | No | iCalendar recurrence rule (e.g. "FREQ=WEEKLY;BYDAY=MO,TU,WE" for Mon/Tue/Wed every week) |

**Purpose**: Rostering-planning uses this to propose shifts; time-attendance uses it to flag deviations (LATE_START, EARLY_END, NO_SHOW, UNPLANNED_SHIFT when comparing actual clock events to planned shifts).

**Round-trip**: RRULE syntax matches RFC 5545 so it integrates with planix calendar integration and rostering grid.

---

## Seed Data

### ClockEvent Seed Objects

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "clock-event",
    "slug": "ce-2026-05-21-hendrik-clockin"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-hendrik-jansen" },
  "event_type": "clock_in",
  "timestamp": "2026-05-21T07:55:00Z",
  "source": "mobile_pwa",
  "gps_latitude": 53.2246,
  "gps_longitude": 6.5700,
  "gps_accuracy_meters": 8,
  "location_label": "Bouwplaats Groningen, Delfzijlstraat 12",
  "geofence_id": { "register": "hrmq", "schema": "geofence", "slug": "gf-groningen-site-200m" },
  "geofence_match": true,
  "device_fingerprint": "iPhone-13-Pro-UUID-abc123"
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "clock-event",
    "slug": "ce-2026-05-21-hendrik-clockout"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-hendrik-jansen" },
  "event_type": "clock_out",
  "timestamp": "2026-05-21T17:05:00Z",
  "source": "mobile_pwa",
  "gps_latitude": 53.2250,
  "gps_longitude": 6.5705,
  "gps_accuracy_meters": 12,
  "location_label": "Bouwplaats Groningen, Delfzijlstraat 12",
  "geofence_id": { "register": "hrmq", "schema": "geofence", "slug": "gf-groningen-site-200m" },
  "geofence_match": true,
  "device_fingerprint": "iPhone-13-Pro-UUID-abc123"
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "clock-event",
    "slug": "ce-2026-05-21-henriette-kiosk"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-henriette-bakker" },
  "event_type": "clock_in",
  "timestamp": "2026-05-21T14:00:00Z",
  "source": "kiosk",
  "gps_latitude": null,
  "gps_longitude": null,
  "location_label": "Horeca Kitchen Amsterdam Centrum",
  "geofence_id": { "register": "hrmq", "schema": "geofence", "slug": "gf-amsterdam-kitchen" },
  "geofence_match": true,
  "device_fingerprint": "Kiosk-NCR-POL90-SN12345"
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "clock-event",
    "slug": "ce-2026-05-20-fons-correction"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-fons-willemsen" },
  "event_type": "clock_in",
  "timestamp": "2026-05-20T09:30:00Z",
  "source": "manual_correction",
  "location_label": "Retail Rotterdam Zuidpark",
  "device_fingerprint": "correction-admin-001",
  "correction_reason": "Employee forgot to clock in this morning; confirmed with manager",
  "correction_by": { "register": "hrmq", "schema": "user", "slug": "usr-hans-boekhouder" }
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "clock-event",
    "slug": "ce-2026-05-19-api-import-logistiek"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-youssef-ahmed" },
  "event_type": "clock_in",
  "timestamp": "2026-05-19T06:15:00Z",
  "source": "api_import",
  "location_label": "Logistiek Centrum Utrecht",
  "device_fingerprint": "TimeClockDevice-XYZ-789"
}
```

### TimeSheet Seed Objects

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "time-sheet",
    "slug": "ts-2026-W21-hendrik-jansen"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-hendrik-jansen" },
  "iso_week": "2026-W21",
  "status": "approved",
  "submitted_at": "2026-05-24T18:00:00Z",
  "submitted_by": { "register": "hrmq", "schema": "user", "slug": "usr-hendrik-jansen" },
  "approved_at": "2026-05-25T09:30:00Z",
  "approved_by": { "register": "hrmq", "schema": "user", "slug": "usr-petra-leidinggevende" },
  "exported_to_payroll_at": null,
  "payroll_batch_id": null,
  "total_regular_hours": 39.5,
  "total_overtime_hours": 2.5,
  "toeslag_minutes_avond": 0,
  "toeslag_minutes_nacht": 0,
  "toeslag_minutes_weekend": 120,
  "toeslag_minutes_feestdag": 0,
  "exception_codes": [],
  "approval_override_reason": null,
  "notes": "Normale werkweek, zaterdag begint met zomertijd"
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "time-sheet",
    "slug": "ts-2026-W21-henriette-bakker"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-henriette-bakker" },
  "iso_week": "2026-W21",
  "status": "submitted",
  "submitted_at": "2026-05-24T20:15:00Z",
  "submitted_by": { "register": "hrmq", "schema": "user", "slug": "usr-henriette-bakker" },
  "approved_at": null,
  "approved_by": null,
  "exported_to_payroll_at": null,
  "payroll_batch_id": null,
  "total_regular_hours": 38.0,
  "total_overtime_hours": 8.5,
  "toeslag_minutes_avond": 420,
  "toeslag_minutes_nacht": 240,
  "toeslag_minutes_weekend": 0,
  "toeslag_minutes_feestdag": 0,
  "exception_codes": ["ATW_DAILY_MAX_EXCEEDED"],
  "approval_override_reason": null,
  "notes": null
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "time-sheet",
    "slug": "ts-2026-W20-fons-willemsen"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-fons-willemsen" },
  "iso_week": "2026-W20",
  "status": "exported",
  "submitted_at": "2026-05-17T18:30:00Z",
  "submitted_by": { "register": "hrmq", "schema": "user", "slug": "usr-fons-willemsen" },
  "approved_at": "2026-05-18T11:00:00Z",
  "approved_by": { "register": "hrmq", "schema": "user", "slug": "usr-marco-manager" },
  "exported_to_payroll_at": "2026-05-22T02:00:00Z",
  "payroll_batch_id": "payroll-2026-W20-run-001",
  "total_regular_hours": 40.0,
  "total_overtime_hours": 0.0,
  "toeslag_minutes_avond": 0,
  "toeslag_minutes_nacht": 0,
  "toeslag_minutes_weekend": 0,
  "toeslag_minutes_feestdag": 0,
  "exception_codes": [],
  "approval_override_reason": null,
  "notes": null
}
```

### TimeSheetEntry Seed Objects

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "time-sheet-entry",
    "slug": "tse-2026-05-21-hendrik-jansen"
  },
  "time_sheet_id": { "register": "hrmq", "schema": "time-sheet", "slug": "ts-2026-W21-hendrik-jansen" },
  "date": "2026-05-21",
  "clock_in_event_id": { "register": "hrmq", "schema": "clock-event", "slug": "ce-2026-05-21-hendrik-clockin" },
  "clock_out_event_id": { "register": "hrmq", "schema": "clock-event", "slug": "ce-2026-05-21-hendrik-clockout" },
  "break_event_ids": [],
  "gross_hours": 9.17,
  "paid_break_minutes": 0,
  "unpaid_break_minutes": 30,
  "regular_hours": 8.67,
  "overtime_hours": 0.5,
  "toeslag_minutes_avond": 0,
  "toeslag_minutes_nacht": 0,
  "toeslag_minutes_weekend": 0,
  "toeslag_minutes_feestdag": 0,
  "project_allocations": [
    {
      "project_id": { "register": "planix", "schema": "project", "slug": "proj-groningen-kantoor" },
      "task_id": { "register": "planix", "schema": "task", "slug": "task-elektriciteit" },
      "cost_centre_id": "cc-019",
      "minutes_allocated": 470,
      "billable": true,
      "client_id": { "register": "pipelinq", "schema": "customer", "slug": "cust-bouwfirma-devries" }
    }
  ],
  "atw_violations": [],
  "geofence_violations": []
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "time-sheet-entry",
    "slug": "tse-2026-05-21-henriette-bakker"
  },
  "time_sheet_id": { "register": "hrmq", "schema": "time-sheet", "slug": "ts-2026-W21-henriette-bakker" },
  "date": "2026-05-21",
  "clock_in_event_id": { "register": "hrmq", "schema": "clock-event", "slug": "ce-2026-05-21-henriette-kiosk" },
  "clock_out_event_id": null,
  "break_event_ids": [],
  "gross_hours": null,
  "paid_break_minutes": 0,
  "unpaid_break_minutes": 0,
  "regular_hours": null,
  "overtime_hours": null,
  "toeslag_minutes_avond": 0,
  "toeslag_minutes_nacht": 0,
  "toeslag_minutes_weekend": 0,
  "toeslag_minutes_feestdag": 0,
  "project_allocations": [],
  "atw_violations": [],
  "geofence_violations": []
}
```

### TimeAllocation Seed Objects

(Inlined in TimeSheetEntry.project_allocations above; shown separately for direct schema object):

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "time-allocation",
    "slug": "ta-2026-05-21-hendrik-groningen-kantoor"
  },
  "time_sheet_entry_id": { "register": "hrmq", "schema": "time-sheet-entry", "slug": "tse-2026-05-21-hendrik-jansen" },
  "project_id": { "register": "planix", "schema": "project", "slug": "proj-groningen-kantoor" },
  "task_id": { "register": "planix", "schema": "task", "slug": "task-elektriciteit" },
  "cost_centre_id": "cc-019",
  "minutes_allocated": 470,
  "billable": true,
  "client_id": { "register": "pipelinq", "schema": "customer", "slug": "cust-bouwfirma-devries" }
}
```

### AvailabilityWindow Seed Objects

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "availability-window",
    "slug": "aw-hendrik-jansen-mo-fr"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-hendrik-jansen" },
  "weekday": 0,
  "start_time": "07:00",
  "end_time": "17:00",
  "effective_from": "2026-01-01",
  "effective_until": null,
  "recurrence_rule": "FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR"
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "availability-window",
    "slug": "aw-henriette-bakker-evening-shifts"
  },
  "employee_id": { "register": "hrmq", "schema": "employee-master", "slug": "emp-henriette-bakker" },
  "weekday": 3,
  "start_time": "14:00",
  "end_time": "23:00",
  "effective_from": "2026-03-15",
  "effective_until": null,
  "recurrence_rule": "FREQ=WEEKLY;BYDAY=WE,TH,FR,SA"
}
```

---

## User Journey Flows

### Journey 1: Employee Clocks In/Out

**Trigger**: Employee arrives at workplace (mobile PWA), supervisor logs hours (web), kiosk asks for PIN (shared tablet), external time-clock POSTs API event.

**Wireframe**:
1. **Mobile PWA clock-in screen**: Map card showing current location + geofence match status, large "Klok In" button, device fingerprint + timestamp confirmation below
2. **Web browser clock-in**: Form with employee dropdown (for admin use), event_type selector, manual GPS toggle, "Klok In" button
3. **Kiosk PIN screen**: 4-digit PIN pad, device pre-populates geofence_id, "Klok In" button
4. **API endpoint**: `POST /api/time-attendance/events/import` with service-account token, event payload schema

**System actions**:
- Persist `clock-event` with all fields populated
- Geofence match evaluated if policy enabled and GPS available
- Reverse-geocoding applied if GPS accurate enough
- Device fingerprint captured/validated
- Audit log entry created

**Error handling**:
- GPS low accuracy (> 100m): log warning, set geofence_match=null, flag exception code GPS_LOW_ACCURACY
- Geofence mismatch: persist with geofence_match=false (never reject)
- Duplicate clock-in (same employee, < 1 min): prompt user confirmation or reject
- API validation failure: return 400 with schema validation errors

---

### Journey 2: Employee Reviews & Submits Timesheet

**Trigger**: Day cutoff (03:00) aggregates events into daily entry; week-end (Sunday 23:59) aggregates daily entries into weekly timesheet.

**Wireframe**:
1. **Daily timesheet view**: Cards per day showing clock-in/out, break time, gross hours, regular/overtime split, exceptions flagged
2. **Allocation editor**: Grid where employee allocates hours to projects (drag sliders or input boxes), validation shows remaining unallocated time
3. **Weekly summary**: Table showing all 7 days + totals (regular, overtime, toeslag categories)
4. **Submit button**: Locked if allocation mismatch or unresolved exceptions; confirmation dialog shows all data before submission

**System actions**:
- Daily aggregator (runs at cutoff): creates `time-sheet-entry` from clock events, computes gross_hours, applies break rules, flags exceptions
- Overtime calculator: applies CAO rules from employee's CAO assignment + employee-master.contractual_hours
- Toeslag calculator: applies CAO premium matrix to each hour-window (avond, nacht, weekend, feestdag)
- Allocation validator: checks sum of minutes_allocated = net working minutes
- Weekly aggregator (Sunday 23:59): creates `time-sheet` with status=draft, aggregates daily totals
- Notification: sends to employee "Uw urenstaat is klaar voor controle"

**Error handling**:
- Missing clock-out by 03:00: create entry with gross_hours=null, exception MISSING_CLOCK_OUT, block submission
- Allocation mismatch: create exception code ALLOCATION_MISMATCH, block submission until corrected
- ATW violations: create exception codes (DAILY_MAX_EXCEEDED, WEEKLY_MAX_EXCEEDED, REST_PERIOD_VIOLATED), allow submission but flag for manager review

---

### Journey 3: Manager Approves Timesheet Queue

**Trigger**: Employee submits weekly timesheet (status: draft → submitted); manager receives notification.

**Wireframe**:
1. **Approval queue**: List of submitted timesheets, filterable by employee/week/exception codes, columns showing employee, week, gross hours, exception flags, submitted date
2. **Timesheet detail view**: Full daily breakdown + allocations, exception details (geofence coordinates, ATW calculation), comments field
3. **Override reason field**: Mandatory text input if sheet has exception codes and manager approves anyway
4. **Approve/Reject buttons**: Approve locks the form; Reject opens comment dialog and returns to draft

**System actions**:
- Approval action (manager): transition status submitted → approved, set approved_at, approved_by, emit payroll-export-ready event
- Rejection action: transition status submitted → rejected, add comment, notify employee, allow re-submission
- Override approval (with exceptions): require approval_override_reason, log to audit trail with override flag
- Employee lock: employee cannot edit submitted or approved timesheets

**Error handling**:
- Manager attempts to approve timesheet locked (already exported): show error, refresh from server
- Network error during approval: save draft locally, retry on reconnect
- Permission check: verify manager is in org tree above the employee

---

### Journey 4: Timesheet Flows Downstream

**Trigger**: Manager approves timesheet (status: submitted → approved); payroll-export runs (automated daily or HR admin triggers).

**System actions**:
1. **Payroll export**:
   - Find all timesheets with status=approved in iso_week
   - Generate batch_id = `{employer_id}-{iso_week}-{run_seq}` (idempotent)
   - Emit payroll-event to payroll-engine-nl with event IDs embedded
   - Transition timesheets status: approved → exported
   - Set exported_to_payroll_at, payroll_batch_id
   - Lock timesheets (status: exported → locked)
   - Audit log: payroll export batch created

2. **Billable-hours export** (if allocations marked billable):
   - For each approved time-allocation with billable=true
   - Emit billable-hours event to pipelinq with allocation_id, client_id, minutes_billable
   - Pipelinq turns into invoice line items

3. **Project burndown export** (planix integration):
   - For each time-allocation (billable or not)
   - Emit project-burndown event to openregister bus for planix to consume
   - Updates project KPIs and task estimates

4. **Audit & retention**:
   - Every mutation logged with before/after hashes
   - Timesheet tagged with "7-year fiscal" retention class
   - Openregister AVG retention engine purges atomically at retention end

**Constraints**:
- Idempotency: re-export of same iso_week/run produces same batch_id, no duplicates
- Re-export guard: if payroll_batch_id already set, skip timesheet unless explicit admin re-export flag
- Lock after export: only admin correction-run can create new events after export
- Event immutability: no hard deletes; corrections create new events with correction_reason

---

## Integration Points

### Upstream (Data consumed)

| System | Schema | Usage | Contract |
|--------|--------|-------|----------|
| **employee-master** | `employee`, `organization` | Employee context, manager lookup, contractual hours, CAO assignment, cost-centre | Read-only; time-attendance does not write |
| **rostering-planning** (future) | `shift`, `availability-window` | Compare actual clock events to planned shifts; flag deviations | REST API: `/api/rostering/shifts?employee_id&week=YYYY-Www` |
| **planix** | `project`, `task` | Project/task references for allocations; project metadata (billable flag, customer) | OpenConnector source; `/api/objects/planix/project`, `/api/objects/planix/task` |
| **Dutch public-holiday calendar** | External | Identify Tweede Pinksterdag, Eerste Kerstdag, etc. for feestdag toeslag | CSV or API; built into CAO module |

### Downstream (Data produced)

| System | Event/Export | Contract | Trigger |
|--------|--------------|----------|---------|
| **payroll-engine-nl** | Timesheet batch with event IDs | Idempotent batch_id, event ID chain, no re-keying | Manager approves + payroll export runs |
| **pipelinq** | Billable-hours event per allocation | allocation_id for idempotency, client_id, minutes_billable | Timesheet exported |
| **planix** | Project burndown event per allocation | project_id, task_id, minutes, date | Timesheet exported |
| **shillinq** | Timesheet PDF render (on demand) | Week + employee, PDF with daily breakdown + manager approval | HR admin "Stuur urenstaat naar opdrachtgever" |
| **docudesk** | Correction reasons + approval PDF | Long correction_reason, weekly approval PDF, 7-year retention | Timesheet locked after export |
| **n8n (webhooks)** | Events for customer integrations | `clock.event.created`, `timesheet.submitted`, `timesheet.approved`, `timesheet.exported` | Respective actions |
| **openregister audit** | Audit trail | Tamper-evident before/after hashes, actor, timestamp | Every mutation |

---

## Reuse Analysis

**Existing OpenRegister capabilities leveraged (no custom code needed)**:

| Capability | OpenRegister Service | Usage |
|------------|---------------------|-------|
| CRUD operations | `ObjectService.saveObject()`, `deleteObject()` | Create/update clock events, timesheets, entries |
| List/filtering | `ObjectService.findAll()` + `CnDataTable` | Timesheet queue, employee daily view, audit log |
| Search & faceting | `IndexService` + `CnFacetSidebar` | Search timesheets by week, employee, exception codes |
| Detail views | `CnDetailPage` with `CnDetailGrid` | Timesheet detail, entry breakdown |
| Audit log | `AuditTrailService` + `CnObjectSidebar` | Automatic mutation tracking, no custom audit code |
| Workflows | `WorkflowEngineRegistry` | Timesheet status transitions (draft → submitted → approved → exported) |
| File management | `FileService` + PDF generation | Approval PDF, correction reason archival to docudesk |
| Notifications | `NotificationService` + `ActivityService` | Employee/manager submission/approval alerts |
| Relations | OpenRegister relation mechanism | Foreign keys between schemas (clock-event → employee, timesheet → timesheet-entry, etc.) |

**Custom business logic required**:

- Daily/weekly aggregation algorithms (clock event → entry → timesheet)
- CAO-specific overtime & toeslag calculation (pluggable per CAO module)
- Geofence polygon evaluation (optional per policy)
- ATW violation detection
- Allocation validation (minutes sum = net working)
- Payroll batch idempotency logic
- Reverse-geocoding for GPS coordinates

---

## Deduplication Check

**Search**: Searched `openspec/specs/` and relevant OpenRegister services.

**Findings**:
- **Timesheet/leave tracking**: `verlof` (leave) spec exists; does not overlap. Verlof is absence (approved time off), time-attendance is presence (worked time). No duplication.
- **Payroll integration**: `payroll-engine-nl` consumes timesheets; time-attendance produces them. Clear separation of concerns. No duplication.
- **Rostering**: `rostering-planning` produces planned shifts; time-attendance compares actuals. No duplication; complementary.
- **Project allocation**: `planix` owns projects/tasks; time-attendance reads and emits burndown. No duplication.
- **Audit logging**: OpenRegister `AuditTrailService` handles all mutation tracking; time-attendance reuses. No duplication.
- **Clock-in UI patterns**: No existing time-clock spec; first implementation.

**Conclusion**: No material duplication. Time-attendance is a new capability with clear domain boundaries.

---

## Implementation Phasing

### Phase 1 (MVP — Week 1–3)
- [ ] Clock-event schema + web/mobile UI (no geofence, no GPS initially)
- [ ] Daily aggregation (gross hours, break deduction, exceptions)
- [ ] Timesheet schema + employee daily review UI
- [ ] Approval workflow (submitted → approved/rejected)

### Phase 2 (Week 4–5)
- [ ] GPS + geofence verification (opt-in)
- [ ] CAO overtime calculation (Bouw + generic 40h fallback)
- [ ] Payroll export (batch generation, idempotency)
- [ ] Integration: payroll-engine-nl event emission

### Phase 3 (Week 6–7)
- [ ] Toeslag calculation (avond, nacht, weekend, feestdag)
- [ ] Project-time allocation (planix integration)
- [ ] Billable-hours export (pipelinq integration)
- [ ] Burndown event emission (planix integration)

### Phase 4 (Future — Post-MVP)
- [ ] Additional CAOs (Horeca, VVT, Retail)
- [ ] Shift matching vs rostering-planning
- [ ] Mobile app offline support + PWA caching
- [ ] Geofence polygon editor
- [ ] Bulk correction-run (admin)
