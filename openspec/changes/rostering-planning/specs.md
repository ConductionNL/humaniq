---
status: draft
change: rostering-planning
---

# Rostering & Planning — Specifications

## REQ-RP-001: Roster Grid with Week and Month View

**Requirement**  
The system SHALL render a roster grid filterable by team, location, function and skill, with a week (default) and month view, drag-and-drop shift assignment, and an "open shifts" column for unassigned slots.

### REQ-RP-001-S1: Week view grid rendering
**GIVEN** a planner opens roster-period week 22 for location "Filiaal Utrecht"  
**WHEN** the page loads  
**THEN** the grid SHALL render a 7×N matrix (N = active employees at the location + 1 open-shift row) with:
- Column headers: date (DD-MMM), day-of-week (localized Dutch)
- Row headers: employee name + position + manager
- Each cell containing the scheduled shift (if any), colour-coded by status (clean ATW, warning, violation)
- Coverage gaps tinted per the `coverage-requirement` for the location (red = understaffed, amber = overstaffed)

**Acceptance**: Visual inspection on desktop and mobile (PWA) shows correct layout, colours, and labels.

---

### REQ-RP-001-S2: Drag-and-drop shift assignment
**GIVEN** a planner has an open shift in the grid  
**WHEN** the planner drags employee X from the roster sidebar onto the open shift cell and releases  
**THEN** the system SHALL:
1. Update `shift.employee_id` to employee X's ID
2. Re-run ATW validation (triggering shift→clean/warning/violation_overridden)
3. Recalculate `roster_period.cost_projection`
4. Broadcast the change via openregister websocket to all open planners on the same period
5. Display a confirmation toast "Shift assigned to [Employee X]"

**Error cases**:
- If the drag target is an employee already on a conflicting shift, the system SHALL display "Conflict: Anna already works 18:00–23:00 this day" and REJECT the drop.
- If the assignment would cause an ATW violation, the system SHALL display a warning card (not block the save) with the violation code and an override prompt.

**Acceptance**: Verified via browser testing (drag shift from open row onto employee row, confirm websocket propagation to second browser tab).

---

### REQ-RP-001-S3: Month view aggregation
**GIVEN** the planner switches to the month view **WHEN** the view selector changes  
**THEN** the grid SHALL collapse to a 7×~4.3 week layout showing:
- Aggregate hours per employee per calendar day (e.g. "16h" in a cell means 16 hours total that day)
- Each cell is clickable and opens the day-level editor (or week-level drill-down)
- Coverage gaps are displayed as "Onderbezet 1/2" badge in the cell (red)

**Acceptance**: Month view loads <1s, cell click opens correct day editor, coverage badges display correctly.

---

### REQ-RP-001-S4: Filtering by team, location, function, skill
**GIVEN** the planner opens the filter panel  
**WHEN** the planner selects "Kassa" (function) + "Filiaal Utrecht" (location) + "flexi_license" (skill)  
**THEN** the grid SHALL redraw showing only employees matching ALL filters (AND logic):
- Rows are filtered to employees with position = Kassa AND skill = flexi_license
- Shifts outside location are hidden
- Filter state persists across page navigation within the same period (via URL query params)

**Acceptance**: Filter results are accurate (manual spot-check of 5 employees), URL contains `?filter=function:kassa&location:filiaal-utrecht&skill:flexi_license`.

---

## REQ-RP-002: Per-employee Availability Calendar

**Requirement**  
The system SHALL surface each employee's combined availability (recurring `availability-window` + ad-hoc `availability-exception`) in the planner grid as a colour overlay, and SHALL block-or-warn shift placement that violates declared unavailability per employer policy.

### REQ-RP-002-S1: Availability overlay on grid
**GIVEN** employee Y has an `availability-window` only on Mon–Wed 09:00–17:00  
**WHEN** the planner views the grid for week 22  
**THEN** the grid cells for Y on Thu–Sun SHALL be tinted grey (unavailable) and cells Mon–Wed 09:00–17:00 tinted green (available)

**Acceptance**: Visual inspection confirms correct tinting. Open shifts on unavailable times are allowed (system is advisory, not restrictive, for open shifts).

---

### REQ-RP-002-S2: Hard block on approved leave
**GIVEN** employee Y has an approved vacation `availability-exception` covering 1–7 July (exception_type = leave_approved)  
**WHEN** the planner attempts to place Y on 3 July  
**THEN** the system SHALL:
1. Block the save with error code `EMPLOYEE_ON_LEAVE`
2. Display a modal: "Y is on approved leave 1–7 July. This assignment cannot be overridden."
3. NOT provide an override button (vacation is hard-blocking by AVG/CAO)
4. Suggest alternative employees or open shift alternatives

**Acceptance**: Save is blocked, error modal displays, no override option present, alt-employee suggestions appear.

---

### REQ-RP-002-S3: Warning on declared unavailability
**GIVEN** employee Y has an availability-window only on Mon–Wed 09:00–17:00  
**WHEN** the planner attempts to place Y on Thursday 10:00–14:00  
**THEN** the system SHALL:
1. Display a warning toast: "Buiten beschikbaarheid — Y typically unavailable Thursday"
2. Require explicit confirmation checkbox ("I confirm this is an exception")
3. Allow the assignment to proceed after confirmation (advisory, not blocking)

**Acceptance**: Warning toast displays, confirmation checkbox blocks save until ticked, shift saves after confirmation.

---

### REQ-RP-002-S4: Sickness flagging and replan
**GIVEN** a sickness `availability-exception` is created at 06:30 for the same-day morning shift (start 06:00, end 14:00)  
**WHEN** the exception persists in the database  
**THEN** the system SHALL:
1. Flag every overlapping `shift` with status → `needs_replan`
2. Display a red banner in the grid: "[Employee X] is sick today (06:30). [2 shifts need replan.]"
3. Fire a planner notification (PWA + email if offline): "Sickness alert: [Employee X] — [2 shifts open]"
4. Auto-broadcast a swap-request to the eligible pool (employees with matching position + available)

**Acceptance**: Overlapping shifts are marked red, planner notification fires <2 min, broadcast swap visible in swap panel.

---

## REQ-RP-003: Swap-requests with Manager Approval

**Requirement**  
The system SHALL implement a swap-request workflow that supports one-way coverage (X asks Y to take over), two-way swap (X and Y swap shifts), and broadcast (X offers shift to anyone in eligible pool), all routed through manager approval before the rooster mutates.

### REQ-RP-003-S1: Two-way swap initiation and counterparty acceptance
**GIVEN** employee X taps "Ruil verzoek" on a shift and selects employee Y as counterparty for a two-way swap  
**WHEN** Y accepts in their PWA  
**THEN** the swap-request record SHALL:
1. Transition status: `proposed` → `counterparty_accepted`
2. Route to the manager's approval queue with a side-by-side preview:
   - X's shift before/after summary
   - Y's shift before/after summary
   - Combined ATW impact (hours per day, weekly totals, rest periods affected)
3. Send manager a notification (PWA + email): "[X] and [Y] are swapping shifts on [Date]. Approve or deny?"

**Acceptance**: Swap-request transitions correctly, manager queue displays shift preview, notification fires.

---

### REQ-RP-003-S2: Manager approval atomicity
**GIVEN** a swap-request in `manager_review` status (two-way swap)  
**WHEN** the manager approves  
**THEN** the system SHALL atomically:
1. Swap both `shift.employee_id` fields (X→Y shift and Y→X shift)
2. Re-validate ATW for both employees across their updated week
3. Update `shift.published_to_employee` → false for both shifts (publication is retracted)
4. Send both employees PWA push notifications: "Your swap with [counterparty] has been approved. Updated rooster available."
5. Transition swap-request status → `approved`
6. Log the approval in the audit trail

**Error handling**: If ATW re-validation fails on approval (e.g., new violations detected), the approval SHALL be blocked with a human-reviewed flag "ATW conflict post-approval" and routed to manager as a retry prompt.

**Acceptance**: Shifts swap correctly, both employees receive notification <30s, audit log records swap + actor.

---

### REQ-RP-003-S3: One-way takeover with ATW override prompt
**GIVEN** a one-way takeover swap where Y taking over X's shift would push Y into an ATW violation (e.g., Y would exceed 60h/week)  
**WHEN** the manager reviews the swap-request  
**THEN** the swap approval card SHALL:
1. Display the violation prominently (red banner): "ATW_WEEKLY_MAX_EXCEEDED — Y would work 62 hours this week (max 60)"
2. Disable the "Approve" button until the manager fills in `atw_override_reason` (required text field)
3. On approval with override, log the reason verbatim in the audit trail

**Acceptance**: Violation displays, approve button is disabled, override reason is required, audit log records reason.

---

### REQ-RP-003-S4: Broadcast swap-request to eligible pool
**GIVEN** employee X taps "Ruil verzoek" and selects "Broadcast to available" (swap_type = broadcast)  
**WHEN** the swap-request is created  
**THEN** the system SHALL:
1. Identify eligible employees: same position + skill-tags + no overlapping availability-exception in the timeframe
2. Send each eligible employee a PWA notification: "Open shift available [Date] [Time] [Location]. Claim it?"
3. Allow the first claimant to accept (transition status → `counterparty_accepted` with their ID)
4. Retract notification from other eligibles ("This shift has been claimed")

**Acceptance**: Eligible employees receive notification, first claimant's acceptance transitions status, others see claim message.

---

## REQ-RP-004: ATW (Arbeidstijdenwet) Compliance Check

**Requirement**  
The system SHALL validate every shift mutation against ATW ceilings: max 12 working hours per dienst, max 60 hours per week, min 11 hours rust between diensten, max 7 consecutive nachtdiensten, max 36 hours night-work per 14-day cycle. Sector exceptions per ATB SHALL be honoured via the active CAO module.

### REQ-RP-004-S1: Daily max working hours
**GIVEN** an employee with a 22:00–06:00 shift planned Monday (8 hours)  
**WHEN** the planner adds a 14:00–22:00 shift the same Monday (8 hours)  
**THEN** the system SHALL:
1. Calculate gross working time: 8 + 8 = 16 hours (exceeds 12h max)
2. Block save with violation code `ATW_DAILY_MAX_EXCEEDED` and message "16 hours total — max 12 hours per day (ATW art. 5:7)"
3. NOT provide an override option (this is a hard-blocking statutory ceiling under art. 5:7)

**Acceptance**: Save is blocked, violation code matches spec, override is unavailable.

---

### REQ-RP-004-S2: Minimum rest between shifts
**GIVEN** an employee finishing a shift at 23:00  
**WHEN** the planner adds a shift starting 08:00 the next day (9 hours rest, vs. 11h required)  
**THEN** the system SHALL:
1. Calculate rest: 08:00 − 23:00 = 9 hours
2. Flag violation code `ATW_INSUFFICIENT_REST` (violation_state = warning)
3. Display a warning card: "Only 9 hours rest (min 11 required by ATW art. 5:3). Confirm to override?"
4. If employer's CAO has an incidenteel work exception (Arbeidstijdenbesluit), show: "Note: ATB exception for incidenteel work available — fill in reason to invoke"
5. Allow override only if manager fills in override reason

**Acceptance**: Warning displays, override button requires reason, ATB exception is offered if applicable.

---

### REQ-RP-004-S3: Max consecutive night shifts
**GIVEN** an employee with 7 consecutive nachtdiensten (22:00–06:00) already planned  
**WHEN** the planner attempts an 8th consecutive nachtdienst  
**THEN** the system SHALL:
1. Block save with violation code `ATW_CONSECUTIVE_NIGHT_MAX`
2. Display: "8 consecutive night shifts exceed the statutory max of 7 (ATW art. 5:8)"
3. NOT provide an override option (this is a hard-blocking statutory ceiling)
4. Suggest breaking the sequence with a day-shift or rest day

**Acceptance**: Save is blocked, no override option, suggestion appears.

---

### REQ-RP-004-S4: Sector-specific exceptions via CAO
**GIVEN** a retail employee with CAO Horeca assigned  
**WHEN** the planner attempts a shift that would exceed ATW limits but matches an exception in CAO Horeca (e.g., incidenteel night-shift work up to 36h/14-day vs. the 30h standard)  
**THEN** the system SHALL:
1. Load the active CAO's exception rules from payroll-engine-nl
2. Recognize the shift matches exception criteria
3. Display warning (not hard block): "CAO Horeca exception: incidenteel night work up to 36h/14-day. Confirm to apply exception?"
4. Require override reason: "Incidenteel work — [reason]"
5. Allow save on confirmation

**Acceptance**: CAO rule is recognized, warning displays, override reason is required.

---

## REQ-RP-005: Toeslag and Overtime Projection

**Requirement**  
The system SHALL project expected toeslagen (avond/nacht/weekend/feestdag) and overtime against each shift at planning time using the same CAO matrix as `time-attendance`, and SHALL aggregate the projection into the roster-period cost dashboard.

### REQ-RP-005-S1: Toeslag calculation for sector-specific shift
**GIVEN** a CAO Horeca shift planned 22:00–06:00 Saturday (8 hours, 0 breaks)  
**WHEN** the shift saves  
**THEN** expected_toeslag_minutes SHALL reflect:
- Avond (20:00–23:00): 60 min × toeslag% per CAO
- Nacht (23:00–06:00): 420 min × toeslag% per CAO
- Weekend (Saturday): 480 min × toeslag% per CAO
- Overlap rules: CAO specifies which category is cumulative vs. exclusive (e.g., nacht overlaps avond, use nacht only)

**Acceptance**: Expected toeslagen match CAO Horeca § A12 overlap rules (manual calculation verification).

---

### REQ-RP-005-S2: Overtime projection per contractual hours
**GIVEN** an employee with 38 contractual hours/week, already at 38h this week  
**WHEN** the planner adds a 6-hour shift  
**THEN** expected_overtime_minutes SHALL show:
1. 6h = 360 minutes, all flagged as overtime
2. `shift.expected_overtime_minutes` = 360
3. `roster_period.cost_projection` updates to include 360 min × overtime_multiplier (e.g., 1.25x per CAO)

**Acceptance**: Overtime minutes are calculated correctly, multiplier is applied, cost projection updates.

---

### REQ-RP-005-S3: Live cost dashboard
**GIVEN** the planner toggles "Toon kostenprognose" on the grid header  
**WHEN** the cost panel opens  
**THEN** the panel SHALL display:
- `total_loonkosten_estimate` (EUR)
- Breakdown per cost-centre (e.g., "Kassa: €1,200, Vloer: €850")
- Breakdown per position (e.g., "Kassamedewerkster: €1,500, Magazijnmedewerker: €550")
- Breakdown per toeslag category (e.g., "Avond: €200, Nacht: €450, Weekend: €900")
- Red banner if cost exceeds period budget (if budget is set)

**Acceptance**: Cost panel displays correctly, updates <3s on shift mutations, totals are mathematically correct.

---

## REQ-RP-006: Roster Publication and Notification

**Requirement**  
The system SHALL implement a publish step that locks the period from non-trivial edits, dispatches per-employee notifications (PWA push + email fallback), and produces a published-at audit entry. Publication SHALL be blockable if ATW violations remain unresolved.

### REQ-RP-006-S1: Publish with ATW check
**GIVEN** a roster-period in status=in_review with zero unresolved ATW violations (`atw_violation_count` = 0)  
**WHEN** the planner taps "Publiceer week 22"  
**THEN** the system SHALL:
1. Transition roster-period status → `published`
2. Set `published_at` timestamp and `published_by` (actor ID)
3. Dispatch notifications to every employee in the period:
   - PWA push: "Your rooster for [week 22] is published. [Deep link to personal rooster]"
   - Email fallback (if PWA failed): same message + plaintext shift list + PDF attachment
4. Update every shift: `published_to_employee` → true, `notification_dispatched_at` → [now]
5. Log publication in audit trail

**Acceptance**: Status transitions, notifications fire <5 min (email may be async), audit log records publication event.

---

### REQ-RP-006-S2: Publish block on unresolved ATW
**GIVEN** a roster-period with one unresolved ATW violation (violation_state = warning, no override_reason)  
**WHEN** the planner taps publish  
**THEN** the action SHALL fail with error code `PUBLISH_BLOCKED_ATW` and display:
1. A red banner: "Publication blocked — 1 unresolved ATW violation"
2. A table of violations: [Employee], [Shift Date/Time], [Violation Code], [One-click Navigate to Shift]
3. A button "Resolve violations" that scrolls to the first affected shift in the grid

**Acceptance**: Publication is blocked, violation list displays, navigation button works.

---

### REQ-RP-006-S3: Post-publication edits with re-notification
**GIVEN** a published roster-period  
**WHEN** the planner edits a shift (e.g., changes employee or time)  
**THEN** the system SHALL:
1. Allow the edit to save (no lock on published periods; edits are allowed for emergencies)
2. Mark the shift: `post_publication_change` = true (internal flag for audit)
3. Dispatch a fresh notification to the affected employee(s): "[Your shift on Date has changed.] [Updated rooster link]"
4. Log the edit in the audit trail with a note: "post-publication change"

**Acceptance**: Edit saves, affected employee receives notification <5 min, audit log notes post-publication change.

---

## REQ-RP-007: Vacation and Sickness Impact on Roster

**Requirement**  
The system SHALL react to incoming `availability-exception` records (vacation, sickness, leave) by flagging overlapping shifts, suggesting replacements from the eligible pool, and feeding the open-shift broadcast queue.

### REQ-RP-007-S1: Vacation flagging and replan button
**GIVEN** an approved vacation request from leave-absence triggers an `availability-exception` 1–7 July  
**WHEN** the exception persists  
**THEN** every overlapping shift SHALL:
1. Transition status → `needs_replan`
2. Display a red banner in the grid: "[Employee X] is on leave 1–7 July. [3 shifts need replan.]"
3. Planner sees a button "Vervang openzetten" that invokes the replacement engine

**Acceptance**: Overlapping shifts are marked, banner displays with count, button is clickable.

---

### REQ-RP-007-S2: Same-day sickness auto-broadcast
**GIVEN** a sickness `availability-exception` posted at 06:30 for the same-day morning shift (shift: 06:00–14:00)  
**WHEN** the exception persists  
**THEN** the system SHALL:
1. Flip `shift.employee_id` → null (open shift)
2. Create a swap-request (swap_type = broadcast) automatically
3. Notify the manager (high-priority alert): "URGENT: [Employee X] sickness — [Shift] is open"
4. Send eligible employees a PWA push: "URGENT: [Shift] [Location] [Date] [Time] — Claim it?"

**Acceptance**: Shift becomes open, broadcast swap is created, manager receives alert, eligible employees receive push.

---

### REQ-RP-007-S3: Replacement engine with suggestions
**GIVEN** the planner taps "Vervang openzetten" for vacation shifts  
**WHEN** the suggestion engine runs  
**THEN** the system SHALL:
1. Propose candidates ranked by:
   - Skill match (position_id + skill_tags from shift)
   - Availability (no overlapping availability-exception)
   - ATW headroom (hours left in week before violation)
   - Toeslag cost delta (prefer lower-cost candidates)
2. Display a panel: [Candidate 1] [Match score], [Candidate 2] [Match score], etc.
3. Allow one-click assignment or broadcast to a candidate
4. Log each suggestion in activity trail for OR audit

**Acceptance**: Suggestions are ranked, one-click assign works, activity is logged.

---

## REQ-RP-008: Coverage-requirement Enforcement

**Requirement**  
The system SHALL highlight under- and over-staffing against `coverage-requirement` rows in the grid and SHALL block publication when any `min_staff` is unmet unless explicitly overridden with reason.

### REQ-RP-008-S1: Understaffing highlighting and publication gate
**GIVEN** a coverage-requirement for "Filiaal Utrecht / kassa / sunday 12:00–18:00 / min_staff=2"  
**WHEN** only one employee is scheduled  
**THEN** the grid cell SHALL:
1. Render red with tooltip: "Onderbezet (1/2)"
2. The period dashboard SHALL show the gap in a "tekort" (shortage) panel: [Location], [Position], [Day/Time], [Current/Required]

**GIVEN** publication is attempted with an unmet coverage gap  
**WHEN** the planner taps publish  
**THEN** the action SHALL prompt: "Publication would leave [Location/Position/Time] understaffed (1/2). Confirm to override? Reason required."

**Acceptance**: Cell is red, gap appears in dashboard, publication prompt requires override reason.

---

### REQ-RP-008-S2: Overstaffing flagging (non-blocking)
**GIVEN** scheduled staff exceeds `max_staff` (e.g., 4 staff vs. max 3)  
**WHEN** the grid renders  
**THEN** the cell SHALL:
1. Render amber with tooltip: "Overbezet (4/3)"
2. NOT block save or publish (over-staffing is a cost flag, not a compliance gate)
3. Appear in the cost dashboard as a cost warning: "Kassa [Date] [Time] staffed at 133% of max — cost +€75"

**Acceptance**: Cell is amber, save/publish are unblocked, cost warning displays.

---

## REQ-RP-009: Rolling Templates and Period Generation

**Requirement**  
The system SHALL allow the planner to roll a previous period forward, generate from templates, or generate from forecasted demand, with employee-availability pre-assignment.

### REQ-RP-009-S1: Period rollover with conflict detection
**GIVEN** a planner taps "Rol week 21 door naar week 22"  
**WHEN** the rollover runs  
**THEN** the system SHALL:
1. Clone every shift from week 21 with adjusted dates (shift.start_datetime += 7 days)
2. Preserve employee_id where possible (if the employee's availability allows)
3. Flag conflicts in a pre-publication report:
   - "Anna assigned to Monday 09:00–17:00 in week 21, but now has availability Mon–Wed only (Thursday shift cannot be assigned)"
   - Offer automatic open-shift reassignment or manual override per conflict
4. Display a modal: "[3 conflicts found]. Review and confirm to proceed?"

**Acceptance**: Conflicts are detected and reported, user can confirm or edit before save.

---

### REQ-RP-009-S2: Template-based period generation
**GIVEN** an active `shift-template` with RRULE "FREQ=WEEKLY;BYDAY=SA;BYHOUR=18" (every Saturday at 18:00)  
**WHEN** "Genereer uit templates" runs for week 22  
**THEN** a new shift SHALL be created for each RRULE match in the period:
- Date/time: Saturday 18:00 (adjusted to UTC)
- Position: from template default
- Location: from template default
- Cost-centre: from template default
- Employee: unassigned (null)

**Acceptance**: Shifts are generated for each RRULE match, one per Saturday in week 22.

---

### REQ-RP-009-S3: Conflict handling in rollover
**GIVEN** the rollover places employee Y on Wednesday, but Y now has a recurring Wednesday unavailability  
**WHEN** the rollover finishes  
**THEN** that shift SHALL:
1. Be marked open (employee_id → null)
2. Be reported in the pre-publication conflicts panel: "Y has Wednesday unavailability — shift moved to open"
3. Be added to the broadcast queue for eligible replacement

**Acceptance**: Shift is open, conflict is reported, shift is available for broadcast.

---

## REQ-RP-010: Audit Log for Rooster Changes

**Requirement**  
The system SHALL log every shift mutation, swap, publication, and override to a tamper-evident audit log retained per CAO retention class, viewable by the OR (ondernemingsraad) under their WOR-instemmingsrecht.

### REQ-RP-010-S1: Comprehensive mutation logging
**GIVEN** any mutation on `shift`, `roster_period`, or `swap_request`  
**WHEN** the mutation commits  
**THEN** an audit-log row SHALL be written with:
- `actor_id` (user performing the action)
- `target_id` (shift/period/swap-request ID)
- `action` (e.g., create, update, publish, override)
- `before_hash` (content hash of the object before mutation)
- `after_hash` (content hash after mutation)
- `rationale` (reason text, required for overrides)
- `timestamp` (mutation timestamp UTC)

**Acceptance**: Audit logs exist for sample mutations, hashes differ before/after, rationale is present for overrides.

---

### REQ-RP-010-S2: OR inzage role with anonymization
**GIVEN** an OR member with the `or_inzage` role  
**WHEN** they query the rooster audit for a quarter  
**THEN** the system SHALL return:
1. A hash-chained, tamper-evident log (each row references the previous row's hash)
2. Employee names pseudonymised (e.g., "EMP-00001" instead of "Anna de Vries")
3. No personeelsdossier data (salary, performance notes, medical info) leaking into the log
4. Full shift mutation history: dates, times, position, location, cost-centre, toeslagen
5. All override reasons verbatim (ATW override reason, coverage override reason, etc.)

**Acceptance**: OR queries return anonymised log, pseudonymization is consistent, sensitive data is absent.

---

### REQ-RP-010-S3: Override reason traceability
**GIVEN** a manager override of an ATW warning (e.g., insufficient rest)  
**WHEN** the override commits  
**THEN** the audit-log entry SHALL include:
- `rationale`: "Incidenteel work — weekend staffing emergency"
- A deep link to the affected shift for traceability
- Timestamp + actor

**Acceptance**: Audit log records override reason verbatim, shift link works, OR can audit the decision trail.

---

## Cross-functional Scenarios

### Scenario A: Multi-day shift with ATW complexity
**Setup**: Employee X, CAO Horeca, 38h/week contractual  
**Monday**: 22:00–06:00 (8h nacht)  
**Tuesday**: Planner adds 14:00–22:00 (8h, 1h paid break = 7h)  
**Result**: Gross worktime Mon 8h + Tue 7h = 15h over 36h period = exceeds daily max on Monday+Tuesday boundary (ATW art. 5:3 rest min applies)

**Expected**: System flags `ATW_INSUFFICIENT_REST` (9h rest Mon 06:00 to Tue 14:00) and allows override with reason. Toeslag projection includes nacht (Mon 8h) + avond (Tue 1h 22:00–23:00) + partial nacht (Tue 0h). Overtime is 0 (under 38h/week).

---

### Scenario B: Sickness cascade
**Setup**: Employee Y assigned to 3 shifts this week (Mon, Wed, Fri morning)  
**Action**: Y calls in sick Wed 06:30 for the Wed shift (sickness exception created)  
**Expected**:
1. Wed shift → open, marked `needs_replan`
2. Planner notified (high-priority)
3. Broadcast swap-request created for eligible staff
4. Y's personal rooster updated (shift removed from "My Shifts" view)
5. Audit log: sickness exception created + shift flagged

---

### Scenario C: Swap approval with publication cascade
**Setup**: Period is draft, Anna and Jan propose two-way swap (Anna: Sat 18:00, Jan: Sun 14:00)  
**Manager approves**:
1. Shifts swap atomically
2. Both ATW re-validates (passes)
3. Both `published_to_employee` → false (retracted if previously published)
4. Swap-request → approved, audit log records approval
5. Both employees notified: "Your swap with [counterparty] approved"

**THEN planner publishes period**:
1. All shifts (including swapped ones) go to employees
2. Audit log records publication + actor

---

### Scenario D: OR access to compliance audit
**Setup**: OR member requests Q2 2026 rooster audit  
**Expected**:
1. System returns anonymised log for all shifts, swaps, overrides in Apr–Jun
2. Examples of entries:
   - "2026-04-15 [EMP-00042] assigned to Monday shift (kassa, 09:00–17:00) by [PLN-00003]"
   - "2026-04-22 ATW override: [EMP-00015] approved night-shift exception — reason: 'Incidenteel work — absence coverage'"
3. OR can cross-reference override reasons with leave/absence logs (separate system) to validate override justifications

---

## Acceptance Criteria Summary

✓ All shifts validate against ATW before save  
✓ Toeslag and overtime projections match CAO matrix (spot-check 3+ shifts)  
✓ Swap-requests transition through manager approval  
✓ Availability-exceptions block vacation, warn on declared unavailability  
✓ Publication broadcasts notifications to employees <5 min  
✓ Audit log is comprehensive, tamper-evident, and OR-accessible  
✓ Grid renders <500ms (7×50 cells on page load)  
✓ Shift mutations propagate to other planners <1s via websocket  
✓ Cost projection updates <3s on shift mutation  
✓ Coverage gaps are highlighted and publication-gated
