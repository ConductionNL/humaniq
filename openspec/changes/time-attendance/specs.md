# Specifications: Time & Attendance — Klokken, Urenstaten, Project-Tijdregistratie

**Status**: specs  
**Date**: 2026-05-23  
**Change ID**: time-attendance

## Requirements

### REQ-TA-001: Multi-channel clock-in

The system SHALL accept clock events from web browser, PWA mobile app, shared kiosk tablet, and authenticated REST API import. All four channels SHALL produce identical `clock-event` records distinguishable only by the `source` field.

**REQ-TA-001.1**: Web browser clock-in
- **GIVEN** an employee with a valid hrmq session in a web browser
- **WHEN** they tap "Klok In" on the time-attendance page
- **THEN** a clock_in event SHALL be persisted with:
  - source=web
  - current UTC timestamp (ISO 8601)
  - employee_id from session context
  - device_fingerprint = browser UserAgent + screen resolution hash
  - GPS optional (browser may not support geolocation)

**REQ-TA-001.2**: PWA mobile app clock-in
- **GIVEN** an employee with a valid hrmq session on a mobile PWA (iOS/Android)
- **WHEN** they tap "Klok In" button
- **THEN** a clock_in event SHALL be persisted with:
  - source=mobile_pwa
  - current UTC timestamp
  - GPS coordinates (latitude, longitude) if location permission was granted
  - gps_accuracy_meters if available
  - device_fingerprint = platform (iOS/Android) + device UUID
  - geofence_match evaluated if policy enabled

**REQ-TA-001.3**: Kiosk PIN authentication
- **GIVEN** a kiosk tablet with a configured device fingerprint (e.g. "Kiosk-NCR-POL90-SN12345")
- **WHEN** an employee enters their 4-digit PIN and taps "Klok In"
- **THEN** a clock_in event SHALL be persisted with:
  - source=kiosk
  - current UTC timestamp
  - employee_id resolved from PIN (PIN → employee mapping in settings)
  - device_fingerprint pre-populated from kiosk config
  - geofence_id pre-populated (kiosk location fixed)
  - geofence_match evaluated against kiosk geofence

**REQ-TA-001.4**: REST API import
- **GIVEN** an external time-clock device posting to `/api/time-attendance/events/import` with a service-account token
- **WHEN** the payload validates against the OpenAPI import schema
- **THEN** events SHALL be persisted with:
  - source=api_import
  - event data from payload (employee_id, timestamp, clock_in/out, GPS optional)
  - device_fingerprint = device identifier from X-Device-ID header or payload
  - Audit log entry recording the import, device ID, and actor (service account)

**REQ-TA-001.5**: Event idempotency
- **GIVEN** duplicate API import payloads (same employee, timestamp, event_type within 1 second)
- **WHEN** the second payload arrives
- **THEN** the system SHALL detect the duplicate and:
  - Return 409 Conflict (web/API) or show confirmation dialog (kiosk/PWA)
  - NOT create a second event
  - Preserve the original event_id for auditability

---

### REQ-TA-002: Optional GPS verification with geofence

The system SHALL support optional geofence verification per employer policy. When enabled for a job site, clock events SHALL be tagged with geofence_match=true/false based on whether GPS coordinates fall within the configured polygon; events SHALL never be rejected on geofence failure (managers triage during approval).

**REQ-TA-002.1**: Geofence match within boundary
- **GIVEN** a construction site geofence with a 200m radius polygon at coordinates (53.2246, 6.5700)
- **WHEN** an employee clocks in with GPS (53.2260, 6.5710) — 185m away
- **THEN** the event SHALL persist with:
  - geofence_match=true
  - geofence_id set
  - location_label reverse-geocoded
  - NO exception codes raised

**REQ-TA-002.2**: Geofence match outside boundary
- **GIVEN** the same 200m geofence
- **WHEN** an employee clocks in with GPS (53.2400, 6.5700) — 350m away
- **THEN** the event SHALL persist with:
  - geofence_match=false
  - geofence_id set
  - location_label reverse-geocoded
  - timesheet entry gains exception code GEOFENCE_OUTSIDE
  - event is NOT rejected; manager approves or flags during review

**REQ-TA-002.3**: GPS accuracy too low for match
- **GIVEN** GPS coordinates with accuracy_meters > 100 (e.g. 120m ±)
- **WHEN** the event is evaluated against a geofence
- **THEN** geofence_match SHALL be null (not false)
- AND the event SHALL gain exception code GPS_LOW_ACCURACY
- AND the timesheet entry SHALL flag GEOFENCE_UNDEFINED (no match possible)
- (Rationale: geofence can't be reliably evaluated with low-accuracy GPS)

**REQ-TA-002.4**: Geofence policy disabled
- **GIVEN** an employer with geofence policy disabled in settings
- **WHEN** any clock event arrives (web, mobile, kiosk, API)
- **THEN** geofence_match SHALL remain null for all events
- AND no GEOFENCE_* exception codes SHALL be raised
- AND location_label reverse-geocoding is skipped (saves API calls)

**REQ-TA-002.5**: GPS permission not granted
- **GIVEN** a mobile PWA user who denies location permission
- **WHEN** they clock in
- **THEN** the event SHALL persist with:
  - gps_latitude/gps_longitude = null
  - geofence_match = null
  - location_label = null
  - NO exception codes (GPS optional)
  - Event accepted normally

---

### REQ-TA-003: Daily and weekly timesheet aggregation

The system SHALL materialise clock events into `time-sheet-entry` rows once per day after the configured day-cutoff, and roll entries into a weekly `time-sheet` with status=draft until submitted.

**REQ-TA-003.1**: Daily aggregation — standard shift
- **GIVEN** clock-in 07:55, clock-out 17:05, break_start 12:30, break_end 13:00
- **WHEN** the daily aggregator runs (default cutoff 03:00)
- **THEN** the entry SHALL show:
  - gross_hours = (17:05 − 07:55) = 9h 10m = 9.17
  - unpaid_break_minutes = 30 (12:30–13:00)
  - regular_hours = 9.17 − 0.5 = 8.67 (rounded per employer rounding policy, default round-nearest)
  - date = the date of the entry (local timezone)
  - clock_in_event_id, clock_out_event_id, break_event_ids all set
  - no exception codes (clean shift)

**REQ-TA-003.2**: Daily aggregation — missing clock-out
- **GIVEN** clock-in 07:55 on 2026-05-21 with no matching clock-out by 03:00 on 2026-05-22
- **WHEN** the daily aggregator runs at 03:00 on 2026-05-22
- **THEN** the entry SHALL be created with:
  - gross_hours = null (incomplete; cannot calculate)
  - regular_hours = null
  - exception code MISSING_CLOCK_OUT
  - Employee notification: "Klok-uitmelding gemist op 21-05; controleer alstublieft"
  - Entry status allows employee to submit a manual correction

**REQ-TA-003.3**: Weekly aggregation — ready for submission
- **GIVEN** a week of 5 daily entries (Mon–Fri), all with no exceptions, total gross_hours = 40.5
- **WHEN** Sunday 23:59:59 passes in the employee's local timezone
- **THEN** the system SHALL:
  - Create a `time-sheet` with status=draft
  - Aggregate total_regular_hours=40.0, total_overtime_hours=0.5
  - Transition status: draft (read-only for system; employee can review)
  - Send notification: "Uw urenstaat week 21 is klaar voor controle"
  - Timesheet ready for employee submission

**REQ-TA-003.4**: Weekly aggregation — incomplete entries block submission
- **GIVEN** a week with one entry flagged MISSING_CLOCK_OUT
- **WHEN** the employee tries to submit the timesheet
- **THEN** submission SHALL be blocked with:
  - Error message: "Urenstaat kan niet ingediend worden tot gemiste klok-uitmelding is opgelost"
  - Highlight the problematic entry
  - Allow employee to add manual correction or contact manager

**REQ-TA-003.5**: Cutoff time configuration
- **GIVEN** an employer with configured day-cutoff = 06:00 (not default 03:00)
- **WHEN** an employee clocks out at 05:30 on day N
- **THEN** the aggregator (runs at 06:00) SHALL include this event in day N's entry
- NOT in day N+1

---

### REQ-TA-004: Overtime calculation per CAO

The system SHALL apply CAO-specific overtime rules to each daily entry, configurable per employee via their assigned CAO module. At minimum the system SHALL support CAO Bouw & Infra, CAO Horeca, CAO VVT (zorg), CAO Retail Non-Food, and a generic 40-hour week fallback.

**REQ-TA-004.1**: CAO Bouw — daily overtime above 8 hours
- **GIVEN** an employee on CAO Bouw with:
  - contractual_hours = 8.0 per day
  - worked entry: 10.5 hours on 2026-05-21
- **WHEN** overtime is computed
- **THEN** overtime_hours SHALL equal 2.5
- AND CAO Bouw toeslag_minutes.bouw_overuren SHALL be updated with 150 minutes (2.5 × 60)
- AND a payroll code mapping `CAO-Bouw-Overuren-100%` SHALL be recorded for the wage calculation

**REQ-TA-004.2**: CAO Horeca — weekly overtime above threshold
- **GIVEN** an employee on CAO Horeca with:
  - contractual_hours = 38.0 per week
  - worked week of 48 hours (Monday–Friday: 8h+8h+8h+9h+9h + Saturday: 6h)
- **WHEN** weekly overtime is computed
- **THEN** the surplus 10 hours SHALL be flagged for compensation:
  - If employee CAO choice = "time-off": convert to paid leave credit (10h × 1.5 = 15h leave, per CAO Horeca §A12)
  - If employee CAO choice = "premium-pay": apply premium multiplier (e.g. 125%) and pass to payroll
  - Audit log shows the choice applied

**REQ-TA-004.3**: Generic fallback — no CAO assigned
- **GIVEN** an employee with CAO = null (no CAO module assigned)
- **WHEN** overtime is computed
- **THEN** the system SHALL apply the generic 40-hour weekly fallback:
  - Regular hours up to 40 per week
  - Anything above 40 weekly hours marked overtime at 100% rate (no additional CAO multipliers)
  - Logged as fallback to auditor

**REQ-TA-004.4**: Daily vs. weekly overtime interaction
- **GIVEN** an employee on CAO Bouw with:
  - contractual_hours = 40.0 per week (8h/day × 5 days)
  - worked: Mon–Thu 8h each, Friday 10h
- **WHEN** overtime is computed
- **THEN** Friday's 2h overage is regular (< 8.5h threshold per CAO Bouw)
- AND 40h weekly is not exceeded (40h < 40h threshold is exact)
- AND no overtime triggered

**REQ-TA-004.5**: CAO Retail — jaarurensystematiek (annual hours)
- **GIVEN** an employee on CAO Retail Non-Food with jaarurensystematiek enabled
- **WHEN** overtime is computed for a given week
- **THEN** the system SHALL:
  - Not immediately mark hours as overtime
  - Accumulate hours against annual threshold
  - Flag weekly summary for review (jaaruren system doesn't resolve weekly)
  - Pass to payroll engine for settlement per Retail CAO rules

---

### REQ-TA-005: Toeslag calculation (avond/nacht/weekend/feestdag)

The system SHALL compute time-window-based premiums against the active CAO premium matrix, supporting overlapping premiums (e.g. zaterdagavond = weekend + avond).

**REQ-TA-005.1**: Overlapping avond + nacht premiums
- **GIVEN** an employee on CAO Horeca with a shift 22:00–06:00 on a Friday (2026-05-21)
- **WHEN** premiums are computed
- **THEN** the entry SHALL show:
  - toeslag_minutes.avond = 120 (22:00–24:00, 2 hours)
  - toeslag_minutes.nacht = 360 (00:00–06:00, 6 hours)
  - Both rates applied per CAO Horeca premium matrix (avond multiplier + nacht multiplier)
  - Payroll codes recorded for each toeslag category

**REQ-TA-005.2**: Feestdag (public holiday) overrides weekend + avond
- **GIVEN** a shift on Tweede Pinksterdag (2026-05-25, a Monday) with hours 22:00–04:00
- **WHEN** the Dutch public-holiday calendar resolves Tweede Pinksterdag
- **THEN** the full shift SHALL receive:
  - toeslag_minutes.feestdag = 360 (entire 6-hour shift)
  - NOT split into avond/nacht components (feestdag is all-or-nothing per CAO Horeca §A12)
  - NOT weekend premium (Monday, not weekend)
  - CAO Horeca feestdag multiplier applied

**REQ-TA-005.3**: Weekend + avond + nacht stacking
- **GIVEN** a 04:00–13:00 shift on a Sunday (2026-05-24) on CAO Horeca
- **WHEN** premiums are computed
- **THEN** toeslag_minutes SHALL show:
  - nacht = 120 (04:00–06:00)
  - weekend = 540 (06:00–13:00, full Sunday hours)
  - BOTH rates applied (multiplicative, per CAO)
  - Payroll receives separate codes for each

**REQ-TA-005.4**: CAO-specific premium matrix
- **GIVEN** an employee on CAO Bouw with a 22:00–06:00 shift on 2026-05-21 (Friday)
- **WHEN** premiums are computed using CAO Bouw matrix
- **THEN** toeslag SHALL apply per CAO Bouw rules (different multipliers than Horeca)
- AND CAO Retail Non-Food uses its own matrix (includes koopzondagtoeslag unique to Retail)

**REQ-TA-005.5**: Midnight boundary handling
- **GIVEN** a shift ending at 23:59:59 and next clock-out at 00:00:01
- **WHEN** premiums are allocated
- **THEN** the system SHALL correctly allocate:
  - avond toeslag to the minute before midnight
  - nacht toeslag to the minute after midnight
  - No double-counting at boundary

---

### REQ-TA-006: Project-time allocation linked to planix

The system SHALL allow employees to split a daily entry across one or more planix projects/tasks, with the sum of allocated minutes equal to net working minutes. Allocations SHALL be optional for employees not on project-coded work.

**REQ-TA-006.1**: Valid allocation — sum matches net working
- **GIVEN** an 8-hour entry (480 minutes) with 30 minutes unpaid break
- **WHEN** an employee allocates:
  - 180 minutes (3h) to project A, task Excavation
  - 300 minutes (5h) to project B, task Cabling
- **THEN** two `time-allocation` rows SHALL persist with:
  - sum minutes_allocated = 480 (matches net working minutes)
  - Entry validation passes
  - Allocations linked to planix projects via relation

**REQ-TA-006.2**: Allocation mismatch — employee can't submit
- **GIVEN** an entry with net working hours 8.0 (480 minutes)
- **WHEN** the employee allocates 450 minutes total (30 minutes unallocated)
- **WHEN** they try to submit the timesheet
- **THEN** submission SHALL be blocked with:
  - Error: "Urenstaat onvolledig: 30 minuten niet toegewezen aan een project"
  - Highlight the unallocated time in the UI
  - Allow employee to fix allocation and resubmit

**REQ-TA-006.3**: Allocation overrun — exceeds net working
- **GIVEN** an entry with net working 8.0 hours
- **WHEN** the employee allocates 9.0 hours total
- **THEN** submission SHALL be blocked with:
  - Error: "Allocatie overschrijdt werkuren (toewijzing 540 min > werkuren 480 min)"

**REQ-TA-006.4**: Billable allocation — customer reference inherited
- **GIVEN** a project marked `billable=true` in planix with customer reference "Bouwfirma de Vries"
- **WHEN** an allocation is made against this project
- **THEN** the time-allocation SHALL inherit:
  - billable=true
  - client_id automatically denormalised from project metadata
  - Ready for pipelinq invoice export

**REQ-TA-006.5**: Optional allocations for non-project staff
- **GIVEN** an employee with no project-coded roles assigned
- **WHEN** they review their timesheet
- **THEN** the allocation section SHALL be hidden or optional
- AND if they don't allocate, no exception codes raised
- AND timesheet submits normally

**REQ-TA-006.6**: Allocation modification after approval
- **GIVEN** a timesheet already approved by manager
- **WHEN** an employee tries to edit project allocations
- **THEN** the edit SHALL be rejected:
  - Error: "Urenstaat is reeds goedgekeurd. Neem contact op met de manager voor wijzigingen."

---

### REQ-TA-007: Approval workflow employee → manager

The system SHALL implement a two-tier approval workflow: employee submits, direct manager (per employee-master org tree) approves or rejects with a comment. Rejected timesheets return to draft and notify the employee.

**REQ-TA-007.1**: Submit clean timesheet
- **GIVEN** a draft weekly timesheet with:
  - All entries valid (no MISSING_CLOCK_OUT, no ALLOCATION_MISMATCH)
  - No exception codes
  - All days filled in
- **WHEN** the employee taps "Indienen" (Submit)
- **THEN** status SHALL transition draft → submitted
- AND submitted_at, submitted_by set to current time + employee user_id
- AND an entry appears in the manager's approval queue
- AND the employee IS locked from further editing
- AND notification sent to manager: "{employee} heeft urenstaat week 21 ingediend"

**REQ-TA-007.2**: Submit timesheet with exceptions
- **GIVEN** a draft timesheet with exception code ATW_DAILY_MAX_EXCEEDED (employee worked 13h on one day)
- **WHEN** the employee taps "Indienen"
- **THEN** status SHALL transition draft → submitted (exceptions don't block submission)
- AND the timesheet appears in manager queue with exception flags visible
- AND notification to manager includes: "⚠️ Arbeidstijdenwet-overtreding gedetecteerd"

**REQ-TA-007.3**: Manager approves clean sheet
- **GIVEN** a submitted timesheet with no exception codes
- **WHEN** the manager taps "Goedkeuren" (Approve)
- **THEN** status SHALL transition submitted → approved
- AND approved_at, approved_by set to current time + manager user_id
- AND a payroll-export-ready event emitted to openregister bus
- AND notification sent to employee: "Urenstaat week 21 is goedgekeurd"
- AND manager is NOT prompted for override reason

**REQ-TA-007.4**: Manager approves sheet with exceptions — requires justification
- **GIVEN** a submitted timesheet with exception code ATW_DAILY_MAX_EXCEEDED
- **WHEN** the manager taps "Goedkeuren"
- **THEN** a modal dialog SHALL appear requiring:
  - Free-text justification field (required, max 500 chars)
  - Example: "Noodgeval: ventilatie uitgevallen, extra uren nodig voor reparatie"
- AND the "Goedkeuren" button is disabled until text entered
- AND upon submit: status → approved, approval_override_reason stored, audit log entry flagged as override

**REQ-TA-007.5**: Manager rejects timesheet
- **GIVEN** a submitted timesheet
- **WHEN** the manager taps "Afkeuren" (Reject)
- **THEN** a comment dialog SHALL appear (free text)
- AND status SHALL transition submitted → rejected
- AND the timesheet returns to draft state (employee can edit)
- AND notification sent to employee: "Urenstaat week 21 is afgewezen met opmerking: {manager comment}"
- AND employee can resubmit after making corrections

**REQ-TA-007.6**: Manager authority check
- **GIVEN** an employee with manager M in their employee-master org tree
- **WHEN** a different user (not M) tries to approve the timesheet
- **THEN** the system SHALL check authorization:
  - Error if user is not the direct manager or a delegated approver
  - RBAC call to: employee-master.isManagerOf(user, employee)
  - Log unauthorized attempt

---

### REQ-TA-008: Payroll export

The system SHALL produce a payroll export batch consumable by `payroll-engine-nl`, containing all approved timesheets in the export window, with idempotent batch IDs and a re-export guard.

**REQ-TA-008.1**: Generate payroll batch — idempotent
- **GIVEN** ten approved timesheets for week 21 (iso_week="2026-W21") for employer "emp-001"
- **WHEN** the payroll exporter runs (automated daily or on-demand)
- **THEN** a single batch SHALL be produced:
  - batch_id = "emp-001-2026-W21-001" (derived from employer_id, iso_week, run_seq)
  - All ten timesheets linked to this batch_id
  - Status transition: approved → exported
  - exported_to_payroll_at set to export run time
  - payroll_batch_id persisted on each timesheet
  - Event emitted to payroll-engine-nl with all event IDs embedded (no re-keying)

**REQ-TA-008.2**: Re-export guard — idempotent key prevents duplicates
- **GIVEN** a timesheet already in status=exported with payroll_batch_id="emp-001-2026-W21-001"
- **WHEN** the payroll exporter runs again (e.g. retry, admin re-run with same parameters)
- **THEN** the timesheet SHALL be skipped:
  - Check: if timesheet.payroll_batch_id exists → skip
  - Log warning: "Timesheet {employee, week} already exported, skipping"
  - Batch count incremented once (not duplicated)
  - NO second event sent to payroll-engine-nl

**REQ-TA-008.3**: Admin re-export with explicit flag
- **GIVEN** an exported timesheet that needs correction (e.g. payroll calculation error in downstream system)
- **WHEN** an HR admin triggers re-export with flag force_re_export=true
- **THEN** the system SHALL:
  - Generate new batch_id with incremented run_seq: "emp-001-2026-W21-002"
  - Update payroll_batch_id on timesheet
  - Emit correction event to payroll-engine-nl flagging re-export
  - Log with actor + reason

**REQ-TA-008.4**: Lock exported timesheet — no edits
- **GIVEN** a timesheet with status=exported
- **WHEN** anyone (employee, manager, even admin) tries to edit entries or allocations
- **THEN** the edit SHALL be rejected:
  - Error: "Urenstaat is geëxporteerd naar salarissen (batch {batch_id}). Bewerk alleen via correctiestroom."
  - Status automatically transitions: exported → locked (read-only)

**REQ-TA-008.5**: Correction flow — HR admin only
- **GIVEN** an exported timesheet that requires a correction (e.g. one entry must be adjusted)
- **WHEN** an HR admin invokes the correction flow:
  - Unlock temporarily
  - Create new clock-event(s) with source=manual_correction
  - Regenerate daily entry
  - Generate new batch: "emp-001-2026-W21-002" (correction batch)
  - Re-export to payroll-engine-nl flagged as correction
- **THEN** audit log records:
  - Original event IDs retained
  - Correction reason + actor
  - New batch_id linked to original

---

### REQ-TA-009: Billable-hours export to pipelinq

The system SHALL emit billable-hours events to pipelinq for each approved time-allocation marked billable, with sufficient detail for hourly invoicing per client.

**REQ-TA-009.1**: Emit billable-hours event on approval
- **GIVEN** an approved entry with 4 hours billable to customer X at project Y
- **WHEN** the timesheet transitions to approved status
- **THEN** a billable-hours event SHALL be emitted to pipelinq containing:
  - allocation_id (stable UUID, same for amend events)
  - client_id (customer reference)
  - project_id
  - employee_id
  - date
  - minutes_billable = 240
  - rate (inherited from allocation or project; optional if pipelinq uses own rates)

**REQ-TA-009.2**: Billable-hours amendment on correction
- **GIVEN** an entry where billable allocation is later corrected:
  - Original: 4h billable (240 min)
  - Corrected: 3h billable (180 min)
  - Correction approved + new batch generated
- **WHEN** the correction batch exports to pipelinq
- **THEN** a billable-hours-amend event SHALL be emitted:
  - allocation_id (same as original)
  - delta_minutes = -60
  - Pipelinq applies delta to invoice line item (idempotent via allocation_id)

**REQ-TA-009.3**: Non-billable allocation skipped
- **GIVEN** an approved entry with allocations split:
  - 3h billable to customer X
  - 5h non-billable (internal project)
- **WHEN** pipelinq export runs
- **THEN** only the 3h billable event emitted
- AND the 5h non-billable is only in planix burndown (see REQ-TA-006)

**REQ-TA-009.4**: Multi-allocation per entry
- **GIVEN** a single entry allocated across 2 billable projects:
  - 2h to customer X / project Y
  - 2h to customer Z / project W
- **WHEN** the entry is approved
- **THEN** TWO separate billable-hours events emitted:
  - Event 1: allocation_id=uuid-1, client_id=X, minutes=120
  - Event 2: allocation_id=uuid-2, client_id=Z, minutes=120

---

### REQ-TA-010: Audit log and immutability

The system SHALL log every clock-event creation, correction, timesheet status transition, and approval action to a tamper-evident audit log retained for the CAO-mandated period (minimum seven years).

**REQ-TA-010.1**: Audit log entry on every mutation
- **GIVEN** any mutation on a `time-sheet`, `clock-event`, or `time-sheet-entry`
- **WHEN** the mutation commits
- **THEN** an audit-log row SHALL be written with:
  - actor_id (user who caused the mutation; system for automated aggregations)
  - target_id (object ID mutated)
  - action (created, approved, rejected, exported, manual_correction, etc.)
  - timestamp (UTC)
  - old_value_hash (hash of prior state; null for creations)
  - new_value_hash (hash of current state)
  - Hash chain: each entry includes hash of previous entry → tamper-evident

**REQ-TA-010.2**: Audit trail queryable by HR auditor
- **GIVEN** an HR auditor querying timesheet history for employee Hendrik Jansen, week 21 of 2026
- **WHEN** they request the audit log for this scope
- **THEN** the system SHALL return:
  - Every mutation chronologically
  - Clock-event creation (with source, GPS data if present)
  - Daily aggregation (system-generated entry creation)
  - Weekly timesheet creation (system-generated)
  - Employee submission (status: draft → submitted)
  - Manager approval (status: submitted → approved, override reason if present)
  - Payroll export (status: approved → exported, batch_id)
  - Verifiable hash chain from first entry to last
  - Actor logged for each human action

**REQ-TA-010.3**: No hard deletes — tombstone pattern
- **GIVEN** an attempt by any user (including admins) to DELETE a clock-event
- **WHEN** the delete is invoked (API, admin panel, etc.)
- **THEN** the system SHALL:
  - Reject hard deletion with error: "Klok-events kunnen niet worden verwijderd (audit-vereiste)."
  - Instead, append a tombstone event:
    - new clock-event with source=tombstone
    - original_event_id = UUID of deleted event
    - tombstone_reason = reason from deletion request
    - tombstone_by = actor
    - timestamp = current time
  - Original record remains queryable in audit trail
  - Downstream payroll/pipelinq systems receive deletion notification referencing original_event_id

**REQ-TA-010.4**: Seven-year retention — fiscal class
- **GIVEN** an approved timesheet with export_to_payroll_at timestamp
- **WHEN** the timesheet is locked (after export)
- **THEN** the system SHALL:
  - Tag the timesheet and all linked clock-events/entries with retention class = "7-year-fiscal"
  - Per CAO Bouw §2.1 and CAO Horeca §A3, timesheets retained minimum 7 years
  - Openregister AVG retention engine (document-dossier-avg) purges at retention end

**REQ-TA-010.5**: Audit log access control
- **GIVEN** an employee requesting their own audit trail
- **WHEN** they query via employee-self-service portal
- **THEN** they SHALL see only their own mutations (clock events, submissions, corrections)
- NOT see manager/admin corrections to their own records (unless explicitly shown)
- **GIVEN** an HR admin with compliance.audit_read permission
- **WHEN** they query
- **THEN** they SHALL see all mutations across all employees + actors
- **GIVEN** a regular manager
- **WHEN** they query
- **THEN** they SHALL see audit trail only for their direct reports

---

## Testing Scenarios

### Scenario 1: Employee clocks in via mobile PWA, geofence outside
1. Employee opens mobile app, taps "Klok In"
2. GPS returns coordinates 350m from job-site geofence
3. Event persists with geofence_match=false
4. Entry gains exception code GEOFENCE_OUTSIDE
5. Manager sees in approval queue with geofence warning
6. Manager approves with override reason: "Verkeer vertraging, later aankomst."
7. Timesheet exports normally

### Scenario 2: Employee forgets clock-out, manager corrects via UI
1. Employee clocks in at 07:55; no clock-out by cutoff (03:00)
2. Daily aggregator creates entry with MISSING_CLOCK_OUT
3. Employee submits timesheet; blocked ("gemiste klok-uitmelding")
4. Employee or manager adds manual_correction event (clock_out at 17:30)
5. Aggregator recalculates entry (gross_hours now valid)
6. Employee re-submits; manager approves
7. Payroll export includes corrected hours + audit trail linking original + correction

### Scenario 3: CAO Horeca night-shift premium calculation
1. Employee works 22:00 Friday → 06:00 Saturday shift
2. Aggregator splits toeslag:
   - avond: 22:00–24:00 = 120 min
   - nacht: 00:00–06:00 = 360 min
   - Both rates applied per CAO Horeca premium matrix
3. Entry shows toeslag_minutes.avond=120, toeslag_minutes.nacht=360
4. Payroll engine consumes event with both codes
5. Wage calculation applies Horeca multipliers correctly

### Scenario 4: Multi-project allocation with billable split
1. Employee works 8h; allocates:
   - 3h to Project A, Task Excavation, billable=true, client=Bouwfirma X
   - 5h to Project B, Task Internal, billable=false
2. Validation: 3h + 5h = 8h ✓
3. Timesheet approved + exported
4. Billable event to pipelinq: 180 min, client_id, allocation_id
5. Burndown event to planix: both projects get their hours
6. Invoicing sees only 3h billable

### Scenario 5: Re-export guard prevents duplication
1. Week 21 timesheets approved + first export: batch_id="emp-001-2026-W21-001"
2. Payroll system fails to process; retry triggered
3. Exporter runs again; skips timesheets already in exported status
4. No duplicate batch_id or events sent to payroll
5. Retry succeeds without creating mess

---

## Acceptance Criteria (All Requirements)

- [ ] REQ-TA-001: Clock events from all four sources (web, mobile, kiosk, API) create identical records distinguishable by source
- [ ] REQ-TA-002: Geofence match/mismatch evaluated; events never rejected; exceptions flagged for manager triage
- [ ] REQ-TA-003: Daily entries aggregated after cutoff; weekly timesheet created; submission blocked on incompleteness
- [ ] REQ-TA-004: Overtime calculated per CAO (Bouw, Horeca, VVT, Retail, generic 40h); passed to payroll with codes
- [ ] REQ-TA-005: Toeslag computed per CAO premium matrix; overlapping premiums (avond+nacht, weekend, feestdag) supported
- [ ] REQ-TA-006: Project allocations optional; sum validated = net working minutes; billable flag denormalised; submission blocked on mismatch
- [ ] REQ-TA-007: Two-tier workflow (employee submit → manager approve/reject); exceptions require justification; locked after approval
- [ ] REQ-TA-008: Payroll batch idempotent; re-export guarded; exported sheets locked; correction-run admin-only
- [ ] REQ-TA-009: Billable events emitted to pipelinq per allocation; amend events on correction; non-billable skipped
- [ ] REQ-TA-010: Every mutation logged with actor, hash chain, immutable (no hard deletes, tombstone pattern); 7-year retention tagged; audit trail queryable by role
