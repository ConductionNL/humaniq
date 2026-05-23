---
status: draft
---
# Time & Attendance — Klokken, Urenstaten, Project-Tijdregistratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Verlof & verzuim › Tijdregistratie

**Rationale:** Klokken+uren.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `time-attendance` capability brings web- and mobile-based time tracking into hrmq for the segments of the Dutch labour market that still run on hour-based pay: bouw, horeca, retail, schoonmaak, beveiliging, logistiek and zorg. For these sectors a salaried-employee model is the exception; the rule is hourly compensation, CAO-driven shift premiums (toeslagen), and overtime that has to be calculated correctly before payroll can run. Existing HR suites in this niche either ship dedicated time-clock hardware (Nedap, Easy Systems, Protime) or a closed mobile app (Shiftbase, L1NDA, Eitje); hrmq instead treats time-attendance as a first-class openregister schema with a thin clock UI on top, so the same record drives payroll, billable-hours export, project costing and dossier evidence.

The capability covers four user journeys end-to-end. First, the employee clocks in and out from a browser, a PWA on a personal phone or a shared tablet in a kiosk; clock events are written to openregister with optional GPS coordinates and reverse-geocoded location label. Second, the system aggregates events into a daily timesheet that the employee can review and submit. Third, a manager approves submitted timesheets in a queue view, with exception flagging for missing pauzes, ATW violations, or geofence mismatches. Fourth, approved timesheets flow downstream — to `payroll-engine-nl` for wage calculation, to `pipelinq` for billable hours invoicing, to `planix` for project burndown, and to `shillinq` for client-facing timesheet reports.

The architectural goal is to make every clock event a queryable, attributable, immutable record. No "the app ate my hours" black box: every mutation is logged, every approval has a signer, every payroll export references the underlying events by ID. This matches the AVG principle that personeelsadministratie data must be traceable, and it matches the CAO Bouw and CAO Horeca obligation to retain timesheets for at least seven years.

The capability does not own rostering — that lives in `rostering-planning`. It does not own payroll calculation — that lives in `payroll-engine-nl`. It owns clock events, timesheet aggregation, approval workflow, and the contract surface for downstream consumers. It is therefore a "pipe" capability: thin domain, deep integration.

## Data Model

Five openregister schemas live in the `hrmq` register: `clock-event`, `time-sheet`, `time-sheet-entry`, `availability-window`, and `time-allocation`. All schemas inherit hrmq's tenant/employee permission model from `employee-master`.

**`clock-event`** is the atomic record of a clock action. Fields: `employee_id` (ref employee-master), `event_type` (enum: clock_in, clock_out, break_start, break_end), `timestamp` (UTC, ISO 8601), `source` (enum: web, mobile_pwa, kiosk, manual_correction, api_import), `gps_latitude` and `gps_longitude` (optional decimals), `gps_accuracy_meters` (integer), `location_label` (reverse-geocoded string, optional), `geofence_id` (ref, optional), `geofence_match` (boolean), `device_fingerprint` (hash), `correction_reason` (string, required when source=manual_correction), `correction_by` (ref user, required when source=manual_correction). Clock events are append-only: corrections create a new event with `correction_reason` rather than mutating the original.

**`time-sheet`** is the weekly aggregation a manager approves. Fields: `employee_id`, `iso_week` (e.g. "2026-W21"), `status` (enum: draft, submitted, approved, rejected, exported, locked), `submitted_at`, `submitted_by`, `approved_at`, `approved_by`, `exported_to_payroll_at`, `payroll_batch_id`, `total_regular_hours` (decimal), `total_overtime_hours` (decimal), `total_toeslag_hours` per category (jsonb: avond, nacht, weekend, feestdag), `exception_codes` (array of enum), `notes`.

**`time-sheet-entry`** is a per-day row inside the timesheet, materialised from clock events. Fields: `time_sheet_id`, `date`, `clock_in_event_id`, `clock_out_event_id`, `break_event_ids` (array), `gross_hours` (decimal), `paid_break_minutes`, `unpaid_break_minutes`, `regular_hours`, `overtime_hours`, `toeslag_minutes` per category, `project_allocations` (jsonb array of `time-allocation`), `atw_violations` (array of codes), `geofence_violations` (array of event_ids).

**`availability-window`** captures recurring availability used by `rostering-planning` to propose shifts; included here because it shares the employee dimension. Fields: `employee_id`, `weekday`, `start_time`, `end_time`, `effective_from`, `effective_until`, `recurrence_rule` (RFC 5545 RRULE).

**`time-allocation`** is the split of working time across projects/tasks. Fields: `time_sheet_entry_id`, `project_id` (ref planix project, optional), `task_id` (ref planix task, optional), `cost_centre_id` (string), `minutes_allocated`, `billable` (boolean), `client_id` (ref pipelinq customer when billable).

Indexes: `clock_event (employee_id, timestamp)`, `time_sheet (employee_id, iso_week)` unique, `time_sheet_entry (time_sheet_id, date)` unique, `time_allocation (project_id, time_sheet_entry_id)`.

## Requirements

### REQ-TA-001: Multi-channel clock-in
The system SHALL accept clock events from web browser, PWA mobile app, shared kiosk tablet, and authenticated REST API import. All four channels SHALL produce identical `clock-event` records distinguishable only by the `source` field.

- **GIVEN** an employee with a valid hrmq session on mobile **WHEN** they tap "Klok in" **THEN** a clock_in event SHALL be persisted with source=mobile_pwa, current UTC timestamp, and GPS coordinates if location permission was granted.
- **GIVEN** a kiosk tablet with a configured device fingerprint **WHEN** an employee enters their 4-digit PIN and taps "Klok in" **THEN** the event SHALL be persisted with source=kiosk and the kiosk's geofence_id pre-populated.
- **GIVEN** an external time-clock device posting to `/api/time-attendance/events/import` with a service-account token **WHEN** the payload validates against the import schema **THEN** events SHALL be persisted with source=api_import and the device identifier in device_fingerprint.

### REQ-TA-002: Optional GPS verification with geofence
The system SHALL support optional geofence verification per employer policy. When enabled for a job site, clock events SHALL be tagged with geofence_match=true/false based on whether GPS coordinates fall within the configured polygon; events SHALL never be rejected on geofence failure (managers triage during approval).

- **GIVEN** a construction site geofence with a 200m radius **WHEN** an employee clocks in 350m away **THEN** the event SHALL persist with geofence_match=false and the timesheet entry SHALL gain exception code GEOFENCE_OUTSIDE.
- **GIVEN** GPS coordinates with accuracy_meters > 100 **WHEN** the event is evaluated **THEN** geofence_match SHALL be null and the event SHALL gain exception code GPS_LOW_ACCURACY rather than a false match.
- **GIVEN** an employer with geofence policy disabled **WHEN** any clock event arrives **THEN** geofence_match SHALL be null and no geofence exception codes SHALL be raised.

### REQ-TA-003: Daily and weekly timesheet aggregation
The system SHALL materialise clock events into `time-sheet-entry` rows once per day after the configured day-cutoff, and roll entries into a weekly `time-sheet` with status=draft until submitted.

- **GIVEN** clock-in 07:55, clock-out 17:05, break_start 12:30, break_end 13:00 **WHEN** the daily aggregator runs **THEN** the entry SHALL show gross_hours=9.17, unpaid_break_minutes=30, regular_hours=8.67 (rounded per employer rounding policy).
- **GIVEN** an open clock-in with no matching clock-out by 03:00 the next day **WHEN** the aggregator runs **THEN** the entry SHALL be created with exception code MISSING_CLOCK_OUT and gross_hours=null pending correction.
- **GIVEN** a week of approved daily entries **WHEN** Sunday 23:59 passes **THEN** the weekly time-sheet SHALL transition from draft to ready-for-submission and the employee SHALL receive a notification.

### REQ-TA-004: Overtime calculation per CAO
The system SHALL apply CAO-specific overtime rules to each daily entry, configurable per employee via their assigned CAO module. At minimum the system SHALL support CAO Bouw & Infra, CAO Horeca, CAO VVT (zorg), CAO Retail Non-Food, and a generic 40-hour week fallback.

- **GIVEN** an employee on CAO Bouw with a worked entry of 10.5 hours and a contractual 8-hour day **WHEN** overtime is computed **THEN** overtime_hours SHALL equal 2.5 and a toeslag_minutes.bouw_overuren entry SHALL be created.
- **GIVEN** an employee on CAO Horeca with a worked week of 48 hours and contractual 38 hours **WHEN** weekly overtime is computed **THEN** the surplus 10 hours SHALL be flagged for compensation in time-off or premium pay per the contractual choice on employee-master.
- **GIVEN** an employee on the generic 40-hour fallback with no CAO assigned **WHEN** overtime is computed **THEN** anything above 40 weekly hours SHALL be marked overtime at the statutory 100% rate with no additional CAO multipliers.

### REQ-TA-005: Toeslag calculation (avond/nacht/weekend/feestdag)
The system SHALL compute time-window-based premiums against the active CAO premium matrix, supporting overlapping premiums (e.g. zaterdagavond = weekend + avond).

- **GIVEN** an employee on CAO Horeca with a shift 22:00–06:00 on a Friday **WHEN** premiums are computed **THEN** the entry SHALL show toeslag_minutes.avond=120 (22:00–24:00), toeslag_minutes.nacht=360 (00:00–06:00), and the appropriate CAO multipliers SHALL be recorded for payroll.
- **GIVEN** a shift on Tweede Pinksterdag **WHEN** the Dutch public-holiday calendar resolves **THEN** the full shift SHALL receive toeslag_minutes.feestdag at the CAO feestdag-multiplier, and weekend/avond premiums SHALL NOT stack on top per CAO Horeca §A12.
- **GIVEN** a 04:00–13:00 shift on a Sunday **WHEN** premiums are computed **THEN** toeslag_minutes.nacht=120, toeslag_minutes.weekend=540, with both rates applied per CAO matrix.

### REQ-TA-006: Project-time allocation linked to planix
The system SHALL allow employees to split a daily entry across one or more planix projects/tasks, with the sum of allocated minutes equal to net working minutes. Allocations SHALL be optional for employees not on project-coded work.

- **GIVEN** an 8-hour entry **WHEN** an employee allocates 3h to project A and 5h to project B **THEN** two `time-allocation` rows SHALL persist with sum minutes_allocated=480 and the entry SHALL pass allocation validation.
- **GIVEN** an entry with allocations summing to 7.5h while net working hours is 8h **WHEN** the employee tries to submit the timesheet **THEN** submission SHALL be blocked with error ALLOCATION_MISMATCH and the unallocated 30 minutes SHALL be highlighted.
- **GIVEN** a project marked `billable=true` in pipelinq with a customer reference **WHEN** an allocation is made against it **THEN** the time-allocation SHALL inherit billable=true and client_id SHALL be denormalised from the project for downstream invoicing.

### REQ-TA-007: Approval workflow employee → manager
The system SHALL implement a two-tier approval workflow: employee submits, direct manager (per employee-master org tree) approves or rejects with a comment. Rejected timesheets return to draft and notify the employee.

- **GIVEN** a draft weekly timesheet with no exception codes **WHEN** the employee taps "Indienen" **THEN** status SHALL transition draft→submitted, an entry SHALL appear in the manager's approval queue, and the employee SHALL be locked from editing.
- **GIVEN** a submitted timesheet **WHEN** the manager taps "Goedkeuren" **THEN** status SHALL transition submitted→approved, approved_at and approved_by SHALL be set, and a payroll-export-ready event SHALL be emitted on the openregister bus.
- **GIVEN** a submitted timesheet with exception code ATW_DAILY_MAX_EXCEEDED **WHEN** the manager taps "Goedkeuren" **THEN** the system SHALL require a free-text justification stored in `approval_override_reason` before allowing the transition, and the justification SHALL be retained in the audit log.

### REQ-TA-008: Payroll export
The system SHALL produce a payroll export batch consumable by `payroll-engine-nl`, containing all approved timesheets in the export window, with idempotent batch IDs and a re-export guard.

- **GIVEN** ten approved timesheets in week 21 **WHEN** the payroll exporter runs **THEN** a single batch SHALL be produced with batch_id derived from (employer_id, iso_week, run_seq), all ten timesheets SHALL transition to exported, and payroll_batch_id SHALL be set.
- **GIVEN** a timesheet already in status=exported **WHEN** the exporter runs again with the same iso_week **THEN** the timesheet SHALL be skipped and a re-export warning SHALL be logged unless an explicit re-export flag is set by an HR admin.
- **GIVEN** an exported timesheet **WHEN** anyone attempts to edit its entries **THEN** the edit SHALL be rejected with error TIMESHEET_LOCKED; only a documented correction-run by an HR admin SHALL be permitted and SHALL produce a correction batch downstream.

### REQ-TA-009: Billable-hours export to pipelinq
The system SHALL emit billable-hours events to pipelinq for each approved time-allocation marked billable, with sufficient detail for hourly invoicing per client.

- **GIVEN** an approved entry with 4h billable to client X at project Y **WHEN** the pipelinq export runs **THEN** a billable-hours event SHALL be emitted containing client_id, project_id, employee_id, date, minutes_billable=240, and a stable allocation_id for invoice line-item idempotency.
- **GIVEN** an entry where billable allocation is later corrected from 4h to 3h **WHEN** the correction is approved **THEN** a billable-hours-amend event SHALL be emitted referencing the original allocation_id with delta_minutes=-60.
- **GIVEN** a non-billable allocation **WHEN** the pipelinq export runs **THEN** no event SHALL be emitted for that allocation and the burndown SHALL only land in planix.

### REQ-TA-010: Audit log and immutability
The system SHALL log every clock-event creation, correction, timesheet status transition, and approval action to a tamper-evident audit log retained for the CAO-mandated period (minimum seven years).

- **GIVEN** any mutation on a `time-sheet` or `clock-event` **WHEN** the mutation commits **THEN** an audit-log row SHALL be written with actor_id, target_id, action, timestamp, old_value_hash, and new_value_hash.
- **GIVEN** an HR auditor querying timesheet history for an employee **WHEN** they request the audit log for week 21 of 2025 **THEN** the system SHALL return every mutation chronologically with a verifiable hash chain to the previous entry.
- **GIVEN** an attempt by any user (including admins) to delete a clock-event **WHEN** the delete is invoked **THEN** the system SHALL reject hard deletion and SHALL instead append a tombstone event referencing the original ID, preserving the original record.

## Standards & Sources

- **Arbeidstijdenwet (ATW)** wetten.overheid.nl/BWBR0007671 — statutory ceilings on working time consumed via `rostering-planning` rules; time-attendance flags violations after the fact (REQ-TA-007 exception path).
- **Arbeidstijdenbesluit (ATB)** wetten.overheid.nl/BWBR0008197 — sector-specific exceptions (zorg, vervoer, horeca) that override base ATW; surfaced as overrides on the CAO module.
- **CAO Bouw & Infra** (cao-bouwnijverheid.nl) — overuren-multiplikatoren, ploegentoeslagen, reisuren rules consumed by REQ-TA-004 and REQ-TA-005.
- **CAO Horeca Nederland** (khn.nl/cao) — feestdagtoeslagen, nachtdiensttoeslag, jeugdloon-grenzen.
- **CAO VVT** (caovvt.nl) — zorgtoeslagen, ORT-regeling, slaapdiensten.
- **CAO Retail Non-Food** (inretail.nl) — koopzondagtoeslag, jaarurensystematiek.
- **AVG (GDPR) art. 5 lid 1 onder f** — integriteit en vertrouwelijkheid; underpins REQ-TA-010 audit immutability.
- **Wet bescherming persoonsgegevens werknemer** (AP guidance on workplace monitoring) — bounds GPS use in REQ-TA-002; geofence is opt-in per employer policy and employee must be informed in writing per the kennisgevingsverplichting.
- **eHerkenning + DigiD** — kiosk PIN auth in REQ-TA-001 mirrors the Nedap convention but does not depend on a smart-card reader; PIN length and lockout policy follow the NCSC password baseline.
- **ISO 8601** — timestamp format for clock events; UTC normalised at write time, employer timezone applied for display only.
- **W3C Geolocation API** — browser GPS source; PWA fallback to native Capacitor geolocation plugin on iOS/Android wraps.
- **RFC 5545 (iCalendar RRULE)** — recurrence syntax for availability-window so it round-trips with the planix kalenderintegratie and the rostering grid.
- **Forum Standaardisatie pas-toe-of-leg-uit lijst** — `OpenAPI 3.0` for the public clock-event import endpoint, `OAuth 2.0` for the PWA, `JSON` payloads throughout.

Competitor references for scope calibration: **Shiftbase** (clock-in PWA + geofence, no project allocation), **L1NDA / Eitje** (horeca-only, no construction CAO), **Nedap PEP** (hardware-locked), **Protime** (closed-source enterprise), **Connecting-Expertise / NMBRS** (payroll-first, weak project tracking). hrmq differentiates by being open, register-native, and integrated with project & invoicing in the same Conduction stack.

## Cross-app integration

- **employee-master** (hrmq) — `employee_id` is the foreign key on every schema; CAO assignment, contractual hours, manager hierarchy and lookup of cost-centre live there. Time-attendance reads, never writes.
- **payroll-engine-nl** (hrmq) — consumes approved timesheets per REQ-TA-008. Owns wage codes, loonheffingen, journaalpost. Time-attendance owes payroll a clean, immutable batch; payroll owes time-attendance back a payroll_batch_id and a settled-at timestamp.
- **rostering-planning** (hrmq, new) — produces planned shifts and availability-windows. Time-attendance compares actual clock events to planned shifts and surfaces deviations as exception codes (LATE_START, EARLY_END, NO_SHOW, UNPLANNED_SHIFT).
- **planix** — projects and tasks consumed by REQ-TA-006. The integration is via an OpenConnector source pointing at planix's `/api/objects/planix/project` and `/task` endpoints; allocations write back project-burndown events on the openregister bus.
- **pipelinq** — billable-hours consumer per REQ-TA-009. Pipelinq turns these into invoice line items on its existing recurring-invoice run, so an hourly construction contractor can bill the client every two weeks without rekeying.
- **shillinq** — receives a client-facing timesheet PDF render on demand (HR admin "stuur urenstaat naar opdrachtgever") for staffing-bureau / detachering scenarios.
- **docudesk** — clock-event correction reasons longer than a sentence, and the weekly approval PDF, are archived in the employee dossier through docudesk's contract-archive route.
- **openregister AVG retention engine** (shared via `document-dossier-avg`) — clock events and timesheets are tagged with the seven-year fiscal retention class; the engine purges atomically at retention end with an audit-log entry.
- **n8n** — exposes `clock.event.created`, `timesheet.submitted`, `timesheet.approved`, `timesheet.exported` events for customer-built integrations (e.g. push to a hardware time-clock at the bouwplaats, sync to a sector-specific payroll bureau).

## Target users

Primary: SMB employer (~10–250 medewerkers) in bouw, horeca, retail, zorg, schoonmaak or beveiliging who today runs hours on a Whatsapp-group, Excel sheet or expensive closed time-clock. Secondary: the medewerker zelf, who needs a phone app that is fast, doesn't drain battery on background GPS, and lets them see "wat krijg ik betaald deze week" before payroll runs. Tertiary: the HR admin / boekhouder who has to ship a payroll batch to Loonbedrijf X every two weeks and currently retypes hours from three sources. Quaternary: the OR (ondernemingsraad), who under the WOR has an instemmingsrecht on monitoring tooling and needs to see the audit log to sign off on the GPS feature.

Non-users: large corporates with SAP SuccessFactors or Workday already in place — they don't need hrmq. The fit is the long Dutch SMB tail that the enterprise tools price out and the spreadsheet world cannot serve safely.
