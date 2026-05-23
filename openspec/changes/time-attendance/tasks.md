# Tasks: Time & Attendance — Klokken, Urenstaten, Project-Tijdregistratie

**Status**: tasks  
**Date**: 2026-05-23  
**Change ID**: time-attendance

## Data Model & Schema Setup (Phase 1)

- [ ] Create `hrmq/clock-event` schema in register template
  - Fields: employee_id, event_type, timestamp, source, gps_lat/lon, accuracy, location_label, geofence_id, geofence_match, device_fingerprint, correction_reason, correction_by
  - Indexes: (employee_id, timestamp), (device_fingerprint, timestamp)
  - Document immutability: append-only, corrections create new events

- [ ] Create `hrmq/time-sheet` schema in register template
  - Fields: employee_id, iso_week, status (enum), submitted_at/by, approved_at/by, exported_at, payroll_batch_id, total_regular_hours, total_overtime_hours, toeslag per category, exception_codes, approval_override_reason, notes
  - Unique constraint: (employee_id, iso_week)
  - Indexes: (employee_id, iso_week) unique, (status, approved_at), (employee_id, status)
  - Document state machine: draft → submitted → approved/rejected, approved → exported → locked

- [ ] Create `hrmq/time-sheet-entry` schema in register template
  - Fields: time_sheet_id, date, clock_in_event_id, clock_out_event_id, break_event_ids, gross_hours, break_minutes (paid/unpaid), regular_hours, overtime_hours, toeslag per category, project_allocations array, atw_violations, geofence_violations
  - Indexes: (time_sheet_id, date) unique
  - Document materialization process

- [ ] Create `hrmq/time-allocation` schema in register template
  - Fields: time_sheet_entry_id, project_id, task_id, cost_centre_id, minutes_allocated, billable, client_id
  - Indexes: (time_sheet_entry_id), (project_id, time_sheet_entry_id), (client_id, billable)
  - Document validation rules (sum = net working minutes)

- [ ] Create `hrmq/availability-window` schema in register template
  - Fields: employee_id, weekday, start_time, end_time, effective_from/until, recurrence_rule (RFC 5545)
  - Document round-trip with rostering-planning RRULE

- [ ] Add seed data to register template (3–5 objects per schema)
  - ClockEvent: web, mobile, kiosk, API import, manual correction samples
  - TimeSheet: draft, submitted, approved, exported statuses
  - TimeSheetEntry: with valid allocation, MISSING_CLOCK_OUT, ALLOCATION_MISMATCH examples
  - TimeAllocation: billable + non-billable samples
  - AvailabilityWindow: Mon–Fri + evening shift examples
  - Use realistic Dutch values (Groningen site, Amsterdam kitchen, Rotterdam retail)

---

## Backend Services & Aggregation (Phase 1–2)

- [ ] Create `ClockEventService` class
  - `createClockEvent(employee_id, event_type, source, gps?, geofence_id?, correction_reason?, correction_by?)` → persists event
  - `validateClockEvent(event)` → check geofence_match logic, GPS accuracy, duplicate detection (< 1min same employee same type)
  - `getEventsByEmployee(employee_id, date_from, date_to)` → query by index
  - `getEventsByDevice(device_fingerprint, date_from, date_to)` → detect kiosk usage patterns

- [ ] Create daily aggregation background job (`AggregateClockEventsJob`)
  - Runs at cutoff time (default 03:00, configurable per employer)
  - For each employee with clock events since last run:
    - Fetch clock_in/out + break events for the day
    - Compute gross_hours = (clock_out − clock_in) − unpaid_breaks
    - If missing clock_out by cutoff: gross_hours=null, exception MISSING_CLOCK_OUT
    - Apply break rules (paid_minutes, unpaid_minutes per CAO)
    - Detect ATW violations (daily_max > 12h per ATW §4, rest period < 11h)
    - Create `TimeSheetEntry` with computed values
    - Emit audit log entry (action=daily_aggregation, actor=system)

- [ ] Create weekly aggregation job (`AggregateTimesheetEntriesJob`)
  - Runs on Sunday 23:59 in employee local timezone
  - For each employee:
    - Fetch or create `TimeSheet` for iso_week
    - Aggregate daily entries: sum gross_hours → total_regular_hours, sum toeslag categories
    - Set status=draft (read-only for system)
    - Emit notification: "Uw urenstaat is klaar voor controle"
    - Audit log entry

- [ ] Create `OvertimeCalculationService` (pluggable per CAO)
  - Load CAO module from employee-master.cao_assignment
  - For daily entry: compute overtime_hours above contractual_hours per CAO rules
  - For weekly entry: aggregate daily overtime + apply CAO weekly multipliers (e.g. Horeca compensation mode)
  - Return: { overtime_hours, toeslag_codes_per_category }
  - Interface for CAO-specific strategies:
    - `CaoBouwStrategy` (daily 8.5h threshold, weekly no threshold, overuren multiplier)
    - `CaoHorecaStrategy` (weekly 38h threshold, feestdag all-or-nothing, avond/nacht multipliers)
    - `CaoVvtStrategy` (zorg-specific sleep services, ORT-regeling)
    - `CaoRetailStrategy` (koopzondagtoeslag, jaarurensystematiek)
    - `GenericFallbackStrategy` (40h week, 100% overtime)

- [ ] Create `ToeslagCalculationService` (pluggable per CAO)
  - Input: clock_in/out timestamps, CAO module
  - Output: { avond_minutes, nacht_minutes, weekend_minutes, feestdag_minutes, payroll_codes }
  - Integrate Dutch public-holiday calendar (via external API or bundled reference)
  - Logic:
    - For each hour in shift, determine windows (avond 19:00–23:00, nacht 23:00–06:00 or CAO-defined)
    - Detect Saturday/Sunday (weekend_minutes)
    - Detect feestdag (entire shift receives feestdag rate, overrides component rates per CAO)
    - Overlapping minutes counted in multiple categories (multiplicative)
    - Per-CAO premium matrix applied during payroll export (service returns minute buckets)

- [ ] Create `AllocationValidationService`
  - `validateAllocation(time_sheet_entry, allocations)` → checks:
    - sum(allocations.minutes_allocated) = entry.net_working_minutes
    - Each allocation references valid planix project (via relation query)
    - If mismatch: return { valid: false, error: "...", unallocated_minutes: N }
  - Called during timesheet submission; blocks if invalid

- [ ] Create `GeofenceMatchingService` (optional, configurable per employer policy)
  - `isGeofenceEnabled(employer_id)` → check setting
  - `getGeofenceForEvent(geofence_id)` → load polygon/radius
  - `evaluateGeofenceMatch(gps_lat, gps_lon, accuracy_meters, geofence)` → returns:
    - `match=true` if within boundary AND accuracy acceptable
    - `match=false` if outside boundary AND accuracy acceptable
    - `match=null` if accuracy > 100m OR geofence policy disabled
  - Integrate reverse-geocoding API (e.g. OpenStreetMap, Google Maps) to populate location_label
  - Fallback: location_label=null if API fails (non-critical)

- [ ] Create `TimeSheetLockService`
  - `lockTimesheet(timesheet_id)` → transition status exported → locked, prevent edits
  - `unlockForCorrection(timesheet_id, actor, reason)` → HR admin only, log reason
  - Hook into ObjectService to block updates on locked timesheets

---

## API Endpoints (Phase 1–2)

- [ ] Implement `POST /api/time-attendance/events`
  - Accepts: employee_id, event_type, source (from client context), gps (optional), geofence_id (optional)
  - Validates per REQ-TA-001 (all sources produce identical ClockEvent)
  - Returns: event_id, timestamp, geofence_match_result
  - RBAC: employee can POST own events; admin can POST for any employee

- [ ] Implement `POST /api/time-attendance/events/import`
  - RESTful API for external time-clock devices (Nedap, etc.)
  - Auth: service-account Bearer token (OAuth 2.0)
  - Accepts: JSON array of events, optional device_id in header
  - Validates payload against schema; returns 400 if invalid
  - Idempotency: detect duplicate (same employee, timestamp, type) → return 409 or 200 with existing ID
  - Returns: { batch_id, count_created, count_skipped, errors[] }
  - Audit: log API import device, count, timestamp

- [ ] Implement `GET /api/time-attendance/timesheets`
  - Query params: employee_id, iso_week, status (draft/submitted/approved/exported/locked)
  - Returns: list of TimeSheet objects with summary (total_hours, exception_codes, manager name if approved)
  - RBAC: employee sees own; manager sees direct reports; HR sees all

- [ ] Implement `PATCH /api/time-attendance/timesheets/{id}`
  - Employee can edit only draft timesheets (notes, allocations)
  - Triggers allocation validation
  - Rejects if status != draft
  - Audit log each change

- [ ] Implement `POST /api/time-attendance/timesheets/{id}/submit`
  - Employee action: transition draft → submitted
  - Validation: all entries complete, allocation sums valid, no blocking exceptions
  - Returns: 200 if success, 422 if validation fails (with reasons)
  - Notification sent to manager

- [ ] Implement `POST /api/time-attendance/timesheets/{id}/approve`
  - Manager action: transition submitted → approved
  - Request body: { override_reason? } (required if exceptions present)
  - RBAC: manager must be in org tree above employee
  - Audit: log approval, override reason if present
  - Emit payroll-export-ready event to openregister bus

- [ ] Implement `POST /api/time-attendance/timesheets/{id}/reject`
  - Manager action: transition submitted → rejected
  - Request body: { comment }
  - Returns: 200, rejected status, comment saved
  - Notification sent to employee

- [ ] Implement `POST /api/time-attendance/timesheets/export-payroll`
  - HR admin action (or background job trigger)
  - Query params: iso_week (optional; if absent, current week)
  - Logic: find all timesheets with status=approved in iso_week
  - Generate batch_id, emit event to payroll-engine-nl
  - Transition → exported, set payroll_batch_id, lock sheets
  - Returns: { batch_id, count_exported, payroll_event_id }
  - Audit: log batch generation, actor

- [ ] Implement `GET /api/time-attendance/audit-trail`
  - Query params: employee_id, iso_week (or date range), action (optional)
  - RBAC: HR admin sees all; employee sees own; manager sees direct reports
  - Returns: array of audit entries with actor, action, timestamp, old/new hash, hash_chain to previous
  - Hash verification: compute hash of current entry + previous entry hash, compare with stored hash_chain

- [ ] Implement `POST /api/time-attendance/clock-events/{id}/correct`
  - HR admin action: create new clock-event with source=manual_correction
  - Request body: { timestamp, event_type, correction_reason, gps? }
  - Creates new event; original remains in audit trail
  - Triggers re-aggregation of affected timesheet entry
  - Returns: new event_id, audit trail updated

---

## Frontend Components & Views (Phase 1–2)

- [ ] Create `<TimeClockInView>` component
  - Mobile-first PWA view + web fallback
  - Display: location (reverse-geocoded or "GPS unavailable"), geofence status (inside/outside/unknown), device fingerprint
  - Button: "Klok In" / "Klok Out" (toggle based on last event)
  - Optional: break_start / break_end buttons
  - On submit: POST /api/time-attendance/events, show confirmation + timestamp
  - Error handling: duplicate detection (show "Already clocked in?"), GPS low accuracy warning, network error retry

- [ ] Create `<TimesheetDailyView>` component
  - List of entries per week (Mon–Sun), each showing:
    - Date, day of week
    - Clock-in / clock-out times
    - Gross hours, net hours (after breaks), regular/overtime split
    - Break status (if any break detected)
    - Exception flags: MISSING_CLOCK_OUT, GEOFENCE_OUTSIDE, ATW_DAILY_MAX, etc.
  - Expandable row per day showing: clock events timeline, toeslag breakdown, project allocations
  - Edit button (enabled if status=draft) → opens allocation editor modal

- [ ] Create `<AllocationEditor>` modal
  - Drag-grid or input table: project, task, cost_centre, minutes_allocated
  - Validation: sum displayed vs. net_working_minutes; visual highlight mismatch
  - Read from planix API (projects + tasks) + autocomplete
  - Checkbox: billable (auto-filled from project metadata)
  - Save button: validates, persists, recalculates entry summary

- [ ] Create `<TimesheetWeeklySummary>` view
  - Table: 7 rows (Mon–Sun) + totals row
  - Columns: date, gross_hours, unpaid_break_min, regular_hours, overtime_hours, toeslag (avond/nacht/weekend/feestdag)
  - Color-coded exceptions: red for blocking (MISSING_CLOCK_OUT), yellow for warnings (ATW_EXCEEDED, GEOFENCE_OUTSIDE)
  - "Submit" button (enabled if no blocking exceptions, allocations valid)
  - Confirmation dialog before submit showing: all data, manager name, submission timestamp

- [ ] Create `<TimesheetApprovalQueue>` view (manager-only)
  - List of submitted timesheets: employee name, week, gross_hours, exception_count, submitted_date
  - Filters: status (submitted/approved/rejected), exception (GEOFENCE, ATW, etc.), date range
  - Click row → detail view
  - Columns sortable (date, hours)
  - Badge: "⚠️ 3 exceptions" on rows with exception_codes

- [ ] Create `<TimesheetApprovalDetail>` view (manager)
  - Full daily breakdown (same as daily view above)
  - Exception details: for each exception, show what triggered it (e.g. "Geofence outside by 350m" with coordinates)
  - Comments section: free text from employee + space for manager comment
  - "Approve" button: if exceptions present, shows modal for override_reason (required)
  - "Reject" button: opens comment dialog
  - After action: redirect to queue, refresh list

- [ ] Create `<AuditTrailView>` view (HR admin + self-service for employees)
  - Query form: employee_id (dropdown), iso_week, action filter (optional)
  - Table: timestamp, actor, action, object_type (clock-event, timesheet), description, old_hash, new_hash
  - Expandable row: show full before/after JSON (if available)
  - Hash chain visualization: show "hash_0 → hash_1 → hash_2" indicating tamper-evidence
  - Export: CSV of audit log with all fields
  - RBAC: HR sees all; employee sees own only; manager sees direct reports only

---

## Configuration & CAO Module Integration (Phase 2–3)

- [ ] Define CAO-specific configuration schema
  - location: `lib/Settings/hrmq_cao_rules.json` or `lib/CAO/{CaoCode}/rules.json`
  - Per CAO: daily_hour_threshold, weekly_hour_threshold, overtime_multiplier, toeslag_rules (windows + rates per time-of-day)
  - Example (CAO Bouw):
    ```json
    {
      "cao_code": "bouw-infra",
      "daily_threshold_hours": 8.5,
      "weekly_threshold_hours": 40,
      "overtime_mode": "premium_pay",
      "toeslag_rules": {
        "avond": { "start": "19:00", "end": "23:00", "multiplier": 1.15 },
        "nacht": { "start": "23:00", "end": "06:00", "multiplier": 1.25 },
        "weekend": { "saturday": true, "sunday": true, "multiplier": 1.50 },
        "feestdag": { "applies_to": "all_hours", "multiplier": 1.75 }
      }
    }
    ```

- [ ] Create CAO Strategy pattern (interface + implementations)
  - Interface: `ICaoStrategy` with methods:
    - `calculateOvertimeHours(daily_worked_hours, weekly_worked_hours, contractual_hours, shifts[])` → { daily_overtime, weekly_overtime, notes }
    - `calculateToeslagMinutes(timestamp, gps_point)` → { avond_min, nacht_min, weekend_min, feestdag_min }
    - `getPayrollCodes()` → array of codes for wage calculation
  - Implementations: `CaoBouwStrategy`, `CaoHorecaStrategy`, `CaoVvtStrategy`, `CaoRetailStrategy`, `GenericFallbackStrategy`
  - Factory: `CaoStrategyFactory.forEmployee(employee_id)` → loads from employee-master.cao_assignment

- [ ] Integrate with employee-master for CAO assignment
  - Query: `employee-master.objects[{schema: employee, object_id: emp_id}]` → get `cao_assignment` field
  - Fallback: if null, use generic 40h strategy
  - Cache: CAO rules + employee CAO assignment in request context to avoid N+1 lookups

- [ ] Create reverse-geocoding integration (GPS location labeling)
  - External service: OpenStreetMap Nominatim API (free) or Google Maps Geocoding (paid)
  - Fallback: "Unknown location" if API fails (not critical, but helpful)
  - Caching: cache location_labels for GPS coords within 100m tolerance (reduce API calls)
  - Privacy: only reverse-geocode if geofence policy enabled AND GPS permission granted

- [ ] Integrate Dutch public-holiday calendar
  - Source: bundled CSV or annual YAML with Dutch holidays (Eerste Kerstdag, Tweede Pinksterdag, etc.)
  - Usage: in `ToeslagCalculationService`, check if date is holiday → apply feestdag toeslag instead of component rates
  - Update annually (January) with new year's holidays

---

## Integration with Downstream Systems (Phase 3)

- [ ] Implement payroll-engine-nl integration
  - Endpoint contract (provided by payroll team): `POST /api/payroll/events/import`
  - Payload: { batch_id, iso_week, employer_id, timesheets: [ { employee_id, iso_week, total_regular_hours, total_overtime_hours, toeslag_per_category, event_ids[], payroll_codes[] } ] }
  - Response: { batch_id, import_status, settled_at? }
  - Idempotency: payroll-engine uses batch_id for deduplication (same batch_id → skip)
  - On success: timesheet status → exported, set payroll_batch_id

- [ ] Implement pipelinq integration (billable-hours export)
  - Event emission: on timesheet approval (or as separate export job)
  - Event schema: { allocation_id, client_id, project_id, date, minutes_billable, rate?, invoice_line_id? }
  - For amendments: delta_minutes event with same allocation_id
  - pipelinq uses allocation_id for idempotency (same ID → update, not duplicate)
  - Audit: log each emission

- [ ] Implement planix integration (project burndown export)
  - Event emission: on timesheet approval (or separate export job)
  - Event schema per allocation: { project_id, task_id, employee_id, date, minutes_worked, billable }
  - planix reads these for burndown calculation
  - Non-blocking: if planix API unavailable, log warning but don't fail timesheet export

- [ ] Implement rostering-planning comparison (future, Phase 4)
  - Query rostering-planning for planned shifts: `/api/rostering/shifts?employee_id&iso_week`
  - Compare actual clock events to planned shifts
  - Flag deviations: LATE_START, EARLY_END, NO_SHOW, UNPLANNED_SHIFT
  - Add exception codes to timesheet entry (non-blocking, for manager review)

- [ ] Implement docudesk integration (correction reason archival)
  - On manual correction: if correction_reason length > 100 chars, archive to docudesk via dossier route
  - Also archive approval PDF (weekly summary + manager approval) on timesheet lock
  - Audit: log archival timestamp + docudesk reference ID

- [ ] Implement shillinq integration (timesheet PDF export)
  - On-demand: HR admin triggers "Export timesheet PDF for external stakeholder"
  - Output: PDF with employee name, week, daily breakdown, manager approval signature (image or facsimile), watermark "Confidential"
  - Delivery: email to client or download link (per shillinq contract)

- [ ] Implement n8n webhook events
  - Emit events to n8n for customer-built integrations:
    - `clock.event.created` (source, device, GPS, geofence)
    - `timesheet.submitted` (week, employee, gross_hours)
    - `timesheet.approved` (week, employee, manager, override_reason if present)
    - `timesheet.exported` (week, employee, batch_id, payroll_reference)
  - Webhook registration: n8n user can subscribe to events via Settings › Integrations › Webhooks
  - Format: CloudEvents standard (per ADR)

---

## Seed Data Generation & Registration (Phase 1)

- [ ] Generate seed objects in register template
  - ClockEvent (5 objects): variety of sources + geofence match states
  - TimeSheet (3 objects): draft, submitted, approved, exported states
  - TimeSheetEntry (4 objects): with allocations, exceptions, clean entries
  - TimeAllocation (3 objects): billable + non-billable, multi-allocation entry
  - AvailabilityWindow (2 objects): Mon–Fri + evening shifts
  - Use realistic Dutch names, addresses, postcodes, BSNs (11-proef valid)

- [ ] Register schema + seed via OpenRegister import
  - `ConfigurationService::importFromApp('hrmq', register_template, version, force=false)`
  - Idempotency: match by slug; re-import skips existing objects
  - Verify: objects queryable in ObjectService after import

---

## Deduplication Check (Phase 1)

- [ ] Search existing OpenRegister services for overlap
  - ObjectService, RegisterService, SchemaService, ConfigurationService ✓
  - Vue components: CnDataTable, CnDetailPage, CnFormDialog, CnFilesTab, CnAuditTrailTab (all provided) ✓
  - AuditTrailService (provided; time-attendance uses it for mutation logging) ✓
  - WorkflowEngineRegistry (provided; time-attendance uses for status transitions) ✓

- [ ] Document reuse in design.md
  - List existing services leveraged (no custom code needed)
  - Identify custom business logic only (aggregation, CAO calculation, geofence matching, allocation validation)
  - Deduplication check: no overlap with verlof, payroll-engine, rostering-planning, planix, pipelinq ✓

---

## Testing (Phase 1–3)

- [ ] Unit tests: `OvertimeCalculationService` per CAO
  - CAO Bouw: daily 8.5h threshold, 10.5h input → 2.5h overtime
  - CAO Horeca: weekly 38h threshold, 48h input → 10h overtime with compensation mode
  - Generic fallback: 40h threshold, 42h input → 2h overtime
  - Test data: realistic shift patterns per sector

- [ ] Unit tests: `ToeslagCalculationService` per CAO
  - Avond (19:00–23:00): 22:00–23:00 shift → 60 min avond toeslag
  - Nacht (23:00–06:00): 23:00–06:00 shift → 420 min nacht toeslag
  - Weekend: Saturday shift → all hours weekend toeslag
  - Feestdag: Tweede Pinksterdag → all hours feestdag toeslag (overrides avond/nacht)
  - Overlap: Friday 22:00–Saturday 06:00 → avond (2h) + nacht (6h, but crosses midnight) + weekend (6h)

- [ ] Unit tests: `AllocationValidationService`
  - Valid: 3h + 5h = 8h ✓
  - Mismatch: 3h + 4h = 7h < 8h → validation error + unallocated_minutes=60
  - Overrun: 3h + 6h = 9h > 8h → validation error
  - Billable flag inheritance from project metadata

- [ ] Unit tests: `GeofenceMatchingService`
  - Inside geofence: 185m < 200m radius → match=true
  - Outside geofence: 350m > 200m radius → match=false
  - Low accuracy: accuracy_meters=120 > 100 → match=null
  - Policy disabled: geofence_match=null for all events

- [ ] Integration tests: daily aggregation job
  - Input: clock events (in, out, breaks)
  - Output: TimeSheetEntry with correct hours, toeslag, exceptions
  - Test missing clock-out → MISSING_CLOCK_OUT exception
  - Test ATW violation (13h day) → ATW_DAILY_MAX_EXCEEDED exception

- [ ] Integration tests: weekly aggregation job
  - Input: 5 daily entries (Mon–Fri)
  - Output: TimeSheet with status=draft, totals aggregated, notification sent
  - Test incomplete week (missing one day) → entries still aggregated, exception flagged

- [ ] Integration tests: approval workflow
  - Employee submits clean sheet → manager queue updated
  - Manager approves clean sheet → payroll event emitted
  - Manager approves with exceptions → override_reason required
  - Manager rejects → sheet returns to draft, employee notified

- [ ] Integration tests: payroll export
  - First export: batch_id generated, timesheets → exported, lock applied
  - Re-export: same batch_id, timesheets skipped, no duplicates
  - Partial export: only approved sheets exported, submitted/draft skipped

- [ ] E2E tests (Playwright or Cypress)
  - Employee PWA clock-in → event persisted, geofence evaluated
  - Employee daily review + allocation → validation works, submission blocked if invalid
  - Manager approval queue → sees exceptions, overrides blocked without reason
  - Payroll export → batch generated, idempotency works

- [ ] Security tests
  - Unauthorized user cannot approve (RBAC check fails)
  - Exported timesheet locked (cannot edit)
  - Hard delete of clock-event rejected (tombstone created instead)
  - Audit trail immutable (hash chain verification)

---

## Documentation (Phase 3)

- [ ] Write deployment guide
  - Schema registration steps
  - Background job setup (daily cutoff, weekly aggregation, payroll export)
  - CAO configuration (define per-sector toeslag rules)
  - Reverse-geocoding API integration (optional, with fallback)
  - Downstream integration URLs (payroll-engine-nl, pipelinq, planix endpoints)

- [ ] Write user documentation
  - Employee guide: clock-in via web/mobile/kiosk, daily review, allocation, submission
  - Manager guide: approval queue, exception handling, override justification
  - HR admin guide: audit trail, correction flow, payroll export, re-export guard

- [ ] Write API documentation (OpenAPI 3.0)
  - Endpoints: clock events, timesheet CRUD, submission, approval, export
  - Auth: session cookies (employee/manager), service-account token (API import)
  - Error codes: 409 duplicate, 422 validation failure, 403 unauthorized, etc.

---

## Rollout & Monitoring (Phase 3)

- [ ] Set up alerts
  - Aggregation job failures (email to ops)
  - Payroll export batch generation (log with count)
  - API import device errors (per-device summary)
  - Geofence API failures (rate-limiting, timeout)

- [ ] Monitor metrics
  - Clock events per day (by source: web, mobile, kiosk, API)
  - Timesheet submission rate (submitted / draft created)
  - Approval rate (approved / submitted)
  - Exception rate (entries with GEOFENCE, ATW, ALLOCATION issues)
  - Export batch idempotency (re-exports skipped)

- [ ] Gradual rollout
  - Week 1: pilot with 5–10 SMB employers (construction sector, CAO Bouw)
  - Collect feedback: clock-in UX, exception flags accuracy, manager approval workload
  - Week 2: expand to 50 employers, add CAO Horeca support
  - Week 3: public GA, all CAOs live, monitor for critical issues
