---
status: draft
---
# Rostering & Planning — Shift-roosters, Beschikbaarheid en ATW-Compliance

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Verlof & verzuim › Rooster-planning

**Rationale:** Shift-rosters.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `rostering-planning` capability gives hrmq the shift-rooster grid that retail, horeca, zorg, schoonmaak and beveiliging cannot operate without. In these sectors the planner's spreadsheet is the operational backbone: who is in store on koopzondag, who covers nachtdienst at the verpleeghuis, who staffs the kookeiland on Hemelvaart. Today this work happens in Shiftbase, L1NDA, Eitje, Quinyx, or — most commonly — Excel and Whatsapp. The result is repetitive ATW violations, last-minute swap chaos, payroll surprises because toeslagen weren't projected, and an OR that has no insight into how the rooster is built.

hrmq replaces that with a register-backed roster. Every planned shift is a first-class openregister object with employee, position, location, start, end, breaks, cost-centre, expected toeslagen and ATW-pre-check baked in at write time. Planners see a grid (week/month) that filters by team, location, function or skill. Employees see their personal rooster in the same PWA used for clocking in (`time-attendance`), with push notifications on publication, swap-request resolution and shift-change. Managers see an approval queue for swap-requests, vacation-impact warnings, and a one-click "publish week 22" action that sends notifications and locks the rooster for unsanctioned edits.

The capability is built around three guarantees. First, the rooster never silently breaks Arbeidstijdenwet limits — every planned shift is validated against ATW and the active CAO at write time, and violations either block save or require explicit manager override with reason. Second, the rooster projects toeslagen forward — when the planner drops an employee into a zaterdagavondshift, the projected loonkosten update live in the cost-centre dashboard, so a horeca-eigenaar knows what next week costs before the door opens. Third, swaps and absences propagate everywhere — a goedgekeurde swap rewrites the shift assignments, regenerates ATW checks for both employees, retracts publication notifications and re-issues them, and updates the downstream availability that `time-attendance` and `payroll-engine-nl` consume.

The capability owns shifts, swap-requests, publication state, and ATW/toeslag projection. It does not own attendance — actual clock events live in `time-attendance`. It does not own the employee — that's `employee-master`. It does not own absence balances — those live in `leave-absence` (separate brief). What it owns is the planned future: the rooster as the contract between employer and employee for the coming week or month.

## Data Model

Six openregister schemas in the `hrmq` register: `roster-period`, `shift`, `shift-template`, `swap-request`, `availability-exception`, `coverage-requirement`. The existing `availability-window` from `time-attendance` is read here, not duplicated.

**`roster-period`** is the planning unit, normally a week but configurable per employer. Fields: `employer_id`, `location_id`, `period_start` (date), `period_end` (date), `status` (enum: draft, in_review, published, locked, archived), `published_at`, `published_by`, `locked_at`, `notes`, `cost_projection` (jsonb: total_hours, total_loonkosten_estimate, toeslag_breakdown), `atw_violation_count`, `swap_count`.

**`shift`** is one planned working block. Fields: `roster_period_id`, `employee_id` (nullable when open-shift), `position_id` (ref: role/function from employee-master), `location_id`, `cost_centre_id`, `start_datetime` (UTC), `end_datetime` (UTC), `paid_break_minutes`, `unpaid_break_minutes`, `expected_toeslag_minutes` (jsonb per category), `expected_overtime_minutes`, `atw_validation_state` (enum: clean, warning, violation_overridden), `atw_violation_codes` (array), `atw_override_reason` (string), `atw_override_by` (ref user), `swap_request_id` (nullable), `published_to_employee` (boolean), `notification_dispatched_at`.

**`shift-template`** captures recurring patterns the planner reuses. Fields: `name`, `position_id`, `location_id`, `recurrence_rule` (RFC 5545 RRULE), `start_time`, `end_time`, `breaks`, `default_cost_centre_id`, `active`. Templates are stamped into `shift` rows when the period is rolled forward.

**`swap-request`** is the structured handover from one employee to another. Fields: `initiator_employee_id`, `initiator_shift_id`, `counterparty_employee_id` (nullable, can be open swap), `counterparty_shift_id` (nullable for one-way coverage), `swap_type` (enum: one_way_takeover, two_way_swap, broadcast), `status` (enum: proposed, counterparty_accepted, manager_review, approved, rejected, cancelled), `manager_decision_reason`, `created_at`, `resolved_at`, `notification_thread_id`.

**`availability-exception`** captures vacation, sickness, or one-off blocks not covered by the recurring `availability-window`. Fields: `employee_id`, `start_datetime`, `end_datetime`, `exception_type` (enum: leave_approved, leave_pending, sickness, training, other_unavailable, extra_available), `source_id` (ref leave-absence record where applicable), `notes`. This is the canonical "the rooster cannot place X this Thursday" feed.

**`coverage-requirement`** is the minimum staffing the rooster must meet, by location/time/position. Fields: `location_id`, `position_id`, `weekday`, `start_time`, `end_time`, `min_staff`, `max_staff`, `effective_from`, `effective_until`, `skill_tags` (array). Used to highlight under- and over-staffing in the grid and to drive the "open shifts" view employees can claim.

Indexes: `shift (roster_period_id, employee_id, start_datetime)`, `shift (location_id, start_datetime)`, `swap_request (status, manager_decision_reason)` partial on manager_review, `availability_exception (employee_id, start_datetime, end_datetime)`, `coverage_requirement (location_id, weekday)`.

## Requirements

### REQ-RP-001: Roster grid with week and month view
The system SHALL render a roster grid filterable by team, location, function and skill, with a week (default) and month view, drag-and-drop shift assignment, and a "open shifts" column for unassigned slots.

- **GIVEN** a planner opens roster-period week 22 for location "Filiaal Utrecht" **WHEN** the page loads **THEN** the grid SHALL render a 7×N matrix (N = active employees + open-shift row) with every existing `shift` placed in its time slot and coverage gaps tinted per the `coverage-requirement` for the location.
- **GIVEN** a planner drags an open shift onto employee X **WHEN** the drop commits **THEN** the `shift.employee_id` SHALL update, ATW validation SHALL re-run, the cost projection SHALL refresh, and the change SHALL appear in real time on any other planner's open grid via the openregister websocket bus.
- **GIVEN** the planner switches to the month view **WHEN** the period selector advances **THEN** the grid SHALL collapse to a 7×N or 31-row layout (configurable) showing aggregate hours per employee per day, with cell click-through to the day-level editor.

### REQ-RP-002: Per-employee availability calendar
The system SHALL surface each employee's combined availability (recurring `availability-window` + ad-hoc `availability-exception`) in the planner grid as a colour overlay, and SHALL block-or-warn shift placement that violates declared unavailability per employer policy.

- **GIVEN** employee Y has an `availability-window` only on Mon–Wed 09:00–17:00 **WHEN** the planner attempts to place Y on Thursday **THEN** the system SHALL show a warning "Buiten beschikbaarheid" and require explicit confirmation before saving.
- **GIVEN** employee Y has an approved vacation `availability-exception` covering 1–7 July **WHEN** the planner attempts to place Y on 3 July **THEN** the system SHALL block save with error EMPLOYEE_ON_LEAVE and SHALL not provide an override path (vacation is hard-blocking by AVG/CAO).
- **GIVEN** a sickness `availability-exception` is created mid-period **WHEN** the exception saves **THEN** every overlapping `shift` SHALL be flagged for replan and a planner notification SHALL fire with the list of affected shifts.

### REQ-RP-003: Swap-requests with manager approval
The system SHALL implement a swap-request workflow that supports one-way coverage (X asks Y to take over), two-way swap (X and Y swap shifts), and broadcast (X offers shift to anyone in eligible pool), all routed through manager approval before the rooster mutates.

- **GIVEN** employee X taps "Ruil verzoek" on a shift and selects employee Y as counterparty for a two-way swap **WHEN** Y accepts in their PWA **THEN** the swap-request status SHALL be counterparty_accepted and the manager's approval queue SHALL receive the item with a side-by-side preview of the two affected shifts.
- **GIVEN** a swap-request in counterparty_accepted state **WHEN** the manager approves **THEN** both `shift.employee_id` fields SHALL swap atomically, ATW SHALL re-validate both employees for the affected days, and both employees SHALL receive PWA push notifications confirming the swap.
- **GIVEN** a one-way takeover swap that would push the counterparty into an ATW violation **WHEN** the manager reviews **THEN** the violation SHALL display prominently in the approval card and approval SHALL require an explicit `atw_override_reason` before the swap commits.

### REQ-RP-004: ATW (Arbeidstijdenwet) compliance check
The system SHALL validate every shift mutation against ATW ceilings: max 12 working hours per dienst, max 60 hours per week, min 11 hours rust between diensten, max 7 consecutive nachtdiensten, max 36 hours night-work per 14-day cycle. Sector exceptions per ATB SHALL be honoured via the active CAO module.

- **GIVEN** an employee with a 22:00–06:00 shift planned Monday **WHEN** the planner adds a 14:00–22:00 shift the same Monday **THEN** the system SHALL block save with violation code ATW_DAILY_MAX_EXCEEDED (16h gross) and refuse override.
- **GIVEN** an employee finishing a shift at 23:00 **WHEN** the planner adds a shift starting 08:00 the next day **THEN** the system SHALL block save with ATW_INSUFFICIENT_REST (9h vs 11h required) unless the ATB exception for incidenteel work is invoked with documented reason.
- **GIVEN** an employee with 7 consecutive nachtdiensten already planned **WHEN** the planner attempts an 8th **THEN** the system SHALL block with ATW_CONSECUTIVE_NIGHT_MAX regardless of override; this is a hard-blocking statutory ceiling.

### REQ-RP-005: Toeslag and overtime projection
The system SHALL project expected toeslagen (avond/nacht/weekend/feestdag) and overtime against each shift at planning time using the same CAO matrix as `time-attendance`, and SHALL aggregate the projection into the roster-period cost dashboard.

- **GIVEN** a CAO Horeca shift planned 22:00–06:00 Saturday **WHEN** the shift saves **THEN** expected_toeslag_minutes SHALL show avond=120, nacht=360, weekend=480 (with the overlap rules from CAO Horeca §A12 applied), and the period cost projection SHALL recalculate.
- **GIVEN** an employee already at 38 contractual hours for the week **WHEN** the planner adds a 6h shift **THEN** expected_overtime_minutes SHALL show 360 and the period cost projection SHALL include the overtime multiplier per the employee's CAO.
- **GIVEN** the planner toggles "Toon kostenprognose" on the grid **WHEN** the panel opens **THEN** total_loonkosten_estimate SHALL render with breakdown per cost_centre, per position, and per toeslag category, refreshed on every shift mutation.

### REQ-RP-006: Roster publication and notification
The system SHALL implement a publish step that locks the period from non-trivial edits, dispatches per-employee notifications (PWA push + email fallback), and produces a published-at audit entry. Publication SHALL be blockable if ATW violations remain unresolved.

- **GIVEN** a roster-period in status=in_review with zero unresolved ATW violations **WHEN** the planner taps "Publiceer week 22" **THEN** status SHALL transition to published, published_at and published_by SHALL be set, and every affected employee SHALL receive a notification with a deep link to their personal rooster.
- **GIVEN** a roster-period with one unresolved ATW violation **WHEN** the planner taps publish **THEN** the action SHALL fail with PUBLISH_BLOCKED_ATW and the planner SHALL be shown the violation list with one-click navigation to each affected shift.
- **GIVEN** a published roster-period **WHEN** the planner edits a shift **THEN** the edit SHALL save but the change SHALL be marked `post_publication_change=true` and a fresh notification SHALL be dispatched to the affected employee(s) within five minutes.

### REQ-RP-007: Vacation and sickness impact on roster
The system SHALL react to incoming `availability-exception` records (vacation, sickness, leave) by flagging overlapping shifts, suggesting replacements from the eligible pool, and feeding the open-shift broadcast queue.

- **GIVEN** an approved vacation request from leave-absence triggers an `availability-exception` 1–7 July **WHEN** the exception persists **THEN** every overlapping shift SHALL move into status `needs_replan` with a banner in the grid, and the planner SHALL see a "vervang openzettenâ€ button.
- **GIVEN** a sickness `availability-exception` posted at 06:30 for the same-day morning shift **WHEN** the exception persists **THEN** the affected shift SHALL flip employee_id to null (open shift), a broadcast swap-request SHALL go to the eligible pool, and the manager SHALL receive a high-priority alert.
- **GIVEN** the planner taps "Vervang openzetten" **WHEN** the suggestion engine runs **THEN** the system SHALL propose ranked candidates filtered by skill, availability, ATW headroom and toeslag cost delta, and the planner SHALL be able to one-click assign or broadcast.

### REQ-RP-008: Coverage-requirement enforcement
The system SHALL highlight under- and over-staffing against `coverage-requirement` rows in the grid and SHALL block publication when any `min_staff` is unmet unless explicitly overridden with reason.

- **GIVEN** a coverage-requirement for "Filiaal Utrecht / kassa / zondag 12:00–18:00 / min_staff=2" **WHEN** only one employee is scheduled **THEN** the grid cell SHALL render red with tooltip "Onderbezet (1/2)" and the period dashboard SHALL show the gap in a "tekort" panel.
- **GIVEN** an unmet coverage gap at publish time **WHEN** the planner attempts to publish **THEN** the action SHALL prompt for an override reason, and on override the gap SHALL persist in the audit log as `coverage_override`.
- **GIVEN** scheduled staff exceeds `max_staff` **WHEN** the grid renders **THEN** the cell SHALL render amber with tooltip "Overbezet (4/3)" but SHALL not block save or publish (over-staffing is a cost flag, not a compliance gate).

### REQ-RP-009: Rolling templates and period generation
The system SHALL allow the planner to roll a previous period forward, generate from templates, or generate from forecasted demand, with employee-availability pre-assignment.

- **GIVEN** a planner taps "Rol week 21 door naar week 22" **WHEN** the rollover runs **THEN** every shift SHALL clone with adjusted dates, employee assignments SHALL be preserved where availability allows, and conflicts SHALL surface as a pre-publication report.
- **GIVEN** an active `shift-template` with RRULE "FREQ=WEEKLY;BYDAY=SA;BYHOUR=18" **WHEN** "Genereer uit templates" runs for week 22 **THEN** a new shift SHALL be created at the template times with the default position, location and cost-centre.
- **GIVEN** the rollover places employee Y on a Wednesday but Y now has a recurring Wednesday unavailability **WHEN** the rollover finishes **THEN** that shift SHALL be marked open and reported in the pre-publication conflicts panel.

### REQ-RP-010: Audit log for rooster changes
The system SHALL log every shift mutation, swap, publication, and override to a tamper-evident audit log retained per CAO retention class, viewable by the OR (ondernemingsraad) under their WOR-instemmingsrecht.

- **GIVEN** any mutation on `shift`, `roster_period`, or `swap_request` **WHEN** the mutation commits **THEN** an audit-log row SHALL be written with actor_id, target_id, action, before/after hash, and rationale (where required).
- **GIVEN** an OR member with the `or_inzage` role **WHEN** they query the rooster audit for a quarter **THEN** the system SHALL return a hash-chained, pseudonymised log suitable for OR review without exposing individual personeelsdossier data.
- **GIVEN** a manager override of an ATW warning **WHEN** the override commits **THEN** the audit-log entry SHALL include the override reason verbatim and a link to the affected shift for traceability.

## Standards & Sources

- **Arbeidstijdenwet (ATW)** wetten.overheid.nl/BWBR0007671 — the source-of-truth ceilings encoded in REQ-RP-004. Specifically art. 5:7 (max diensttijd), art. 5:3 (rusttijd), art. 5:8 (nachtdienst).
- **Arbeidstijdenbesluit (ATB)** wetten.overheid.nl/BWBR0008197 — sector-specific overrides loaded from the active CAO module.
- **Wet op de Ondernemingsraden (WOR) art. 27** — instemmingsrecht on werktijdregelingen; underpins REQ-RP-010 OR audit access.
- **CAO Horeca / VVT / Retail / Schoonmaak / Beveiliging** — each contributes toeslag-matrices, max-night-shift counts, koopzondag rules, slaap- en bereikbaarheidsdiensten. Same CAO modules as `time-attendance`.
- **AVG art. 5/6/9** — rooster data are personeelsgegevens; vacation reason field is restricted, sickness reason is special-category and never stored in `availability-exception.notes`.
- **AP — Boete- en handhavingsbeleid werknemersmonitoring** — informs the policy switches on geofence-tied rosters (we never derive shift performance metrics from GPS without explicit OR sign-off).
- **RFC 5545 (iCalendar)** — RRULE syntax for `availability-window` and `shift-template`; rooster periods exportable as ICS for personal calendar subscription per employee.
- **NEN 8045 (Werktijden in de zorg)** — sector-specific safety guidance for nachtdienstplanning; informs warning thresholds in REQ-RP-004 above the ATW minimums.
- **Forum Standaardisatie pas-toe-of-leg-uit** — OpenAPI 3.0 for the rooster export, OAuth 2.0 for swap-request and notification endpoints, JSON throughout.

Competitor calibration: **Shiftbase** (NL leader, good UX, weak ATW automation), **Eitje** (horeca-focused, good employee app, no zorg coverage), **L1NDA** (horeca + retail, no CAO library), **Quinyx** (Nordic, expensive, weak NL CAO depth), **Planday** (international, weak Dutch ATW), **Visma Roster** (zorg, integrated payroll but closed). hrmq differentiates with open data model, CAO-pluggable rule engine, register-native integration with payroll and time-attendance, and OR-grade audit transparency.

## Cross-app integration

- **employee-master** — supplies employee, position, manager hierarchy, contractual hours, CAO assignment, skills (used for filtering in REQ-RP-001 and matching in REQ-RP-007).
- **time-attendance** — produces actual clock events that are compared against planned shifts. The two capabilities share `availability-window`; deviation events (LATE_START, NO_SHOW, UNPLANNED_SHIFT) flow from time-attendance into the rostering exception view.
- **leave-absence** (sibling brief) — every approved leave or sickness produces an `availability-exception` here, driving REQ-RP-007.
- **payroll-engine-nl** — receives the projected toeslag and overtime forecast for cash-flow planning; receives actuals via time-attendance.
- **planix** — multi-day projects with required roles can publish a `coverage-requirement` automatically for the project's runtime (zorgproject, evenement, bouwproject).
- **pipelinq** — billable shifts (detachering, uitzendwerk) project a future invoice line so the staffing-bureau eigenaar can forecast revenue alongside loonkosten.
- **docudesk** — published rosters archive as PDF in the location's dossier for retroactive AVG/CAO compliance evidence.
- **openregister websocket bus** — drives real-time grid updates across multiple planners working on the same period.
- **n8n** — `roster.shift.published`, `roster.swap.approved`, `roster.coverage.gap` events for customer-built automations (e.g. push to a digital signage screen, sync to a marketing-rooster, notify a Whatsapp group).
- **nldesign theming** — the grid uses the rijksbreed kleurpalet when an employer enables nldesign, so gemeentelijke zorginstellingen recognise the look.

## Target users

Primary: the SMB planner — kapitein van een horecazaak, filiaalmanager retail, teamleider zorg — who spends 4–8 hours per week on the rooster today. Secondary: the medewerker who needs to see their shifts on their phone, request a ruil without an avond-Whatsapp-spree, and trust that the rooster respects their availability. Tertiary: HR/management who need cost-projection and ATW-compliance proof. Quaternary: the OR, with formal WOR-instemmingsrecht on werktijdregelingen, who today has no auditable view into how the rooster is constructed.

Non-users: solopreneurs (no rooster), white-collar 9–17 employers (no shift work), and large corporates with SAP/Workday-integrated workforce-management platforms already in place. hrmq fits the long Dutch SMB tail and the gemeentelijke zorg/retail/horeca arms that operate at SMB scale.
