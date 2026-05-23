---
status: draft
change: rostering-planning
---

# Rostering & Planning — Implementation Tasks

## Phase 1: Data Model & Infrastructure

- [ ] **Create openregister schema: `roster-period`**
  - [ ] Define fields per design.md (employer_id, location_id, period_start/end, status, cost_projection, atw_violation_count, swap_count)
  - [ ] Add `x-openregister-lifecycle` with states: draft → in_review → published → locked → archived
  - [ ] Add indexes: (employer_id, period_start), (employer_id, status)
  - [ ] Register in `lib/Settings/hrmq_register.json`

- [ ] **Create openregister schema: `shift`**
  - [ ] Define fields per design.md (roster_period_id, employee_id nullable, position_id, location_id, cost_centre_id, start/end datetime, breaks, toeslagen, overtime, ATW state/codes/override)
  - [ ] Add `x-openregister-lifecycle` with states: draft → open/assigned → locked/needs_replan/cancelled
  - [ ] Add `x-openregister-calculations` for: workMinutes, totalToeslaginutes, isOverCapacity, atw_display_badge
  - [ ] Add indexes: (roster_period_id, employee_id, start_datetime), (location_id, start_datetime)
  - [ ] Register in `lib/Settings/hrmq_register.json`

- [ ] **Create openregister schema: `shift-template`**
  - [ ] Define fields per design.md (name, position_id, location_id, recurrence_rule RFC 5545, start/end time, breaks, default_cost_centre_id, active)
  - [ ] Add indexes: (location_id, active), (position_id, active)
  - [ ] Register in `lib/Settings/hrmq_register.json`

- [ ] **Create openregister schema: `swap-request`**
  - [ ] Define fields per design.md (initiator_employee_id, initiator_shift_id, counterparty_employee_id nullable, counterparty_shift_id nullable, swap_type, status, manager_decision_reason, notification_thread_id)
  - [ ] Add `x-openregister-lifecycle` with states: proposed → counterparty_accepted → manager_review → approved/rejected, + cancelled
  - [ ] Add `x-openregister-notifications` for: proposed (counterparty/broadcast notification), counterparty_accepted (manager notification), approved/rejected (employee notifications)
  - [ ] Add indexes: (status, created_at), (initiator_employee_id, status), (counterparty_employee_id, status), partial on manager_review
  - [ ] Register in `lib/Settings/hrmq_register.json`

- [ ] **Create openregister schema: `availability-exception`**
  - [ ] Define fields per design.md (employee_id, start/end datetime, exception_type enum, source_id nullable, notes)
  - [ ] Add `x-openregister-notifications` to flag overlapping shifts + notify planner on creation
  - [ ] Add indexes: (employee_id, start_datetime, end_datetime), (exception_type, status)
  - [ ] Register in `lib/Settings/hrmq_register.json`

- [ ] **Create openregister schema: `coverage-requirement`**
  - [ ] Define fields per design.md (location_id, position_id, weekday, start/end time, min/max staff, effective_from/until, skill_tags)
  - [ ] Add `x-openregister-aggregations` for: currentCoverage(location, weekday, time), coverageGaps()
  - [ ] Add indexes: (location_id, weekday), (effective_from, effective_until) partial
  - [ ] Register in `lib/Settings/hrmq_register.json`

- [ ] **Add seed data to `lib/Settings/hrmq_register.json`**
  - [ ] 3–5 example `roster-period` objects (various statuses, locations, weeks)
  - [ ] 5–8 example `shift` objects (assigned, open, with ATW clean/warning states)
  - [ ] 2–3 example `shift-template` objects (recurring patterns)
  - [ ] 2–3 example `swap-request` objects (various swap_type and status values)
  - [ ] 2–3 example `availability-exception` objects (vacation, sickness, training)
  - [ ] 3–4 example `coverage-requirement` objects (different locations/positions/times)

---

## Phase 2: Shift Grid UI Component

- [ ] **Create Vue component: `CnRosterGrid.vue`**
  - [ ] Props: `roster-period-id` (UUID), `location-id` (UUID), `view-mode` ('week' | 'month'), `filter-options` (team, location, function, skill)
  - [ ] Render 7×N matrix (7 days, N = employees + open-shift row)
  - [ ] Week view: column headers date + day-of-week, row headers employee + position + manager
  - [ ] Cell content: shift display with time, position, breaks, ATW status colour (clean green, warning amber, violation red)
  - [ ] Coverage gaps: cell tinted per coverage-requirement (red = understaffed, amber = overstaffed)
  - [ ] Drag-and-drop: detect drop target, validate assignment, update shift.employee_id, trigger ATW re-validation, broadcast via websocket
  - [ ] Availability overlay: grey for unavailable times (from availability-window + availability-exception), green for available
  - [ ] Month view: aggregate hours per day, clickable drill-down to day/week editor
  - [ ] Filter bar: multiselect dropdowns for team/location/function/skill with AND logic, persist in URL query params

**Testing**:
  - [ ] Week grid renders <500ms with 50 shifts
  - [ ] Drag-drop updates shift in real time, websocket propagates to peer planners
  - [ ] Filtering by multiple criteria works correctly
  - [ ] Availability overlay matches combined availability-window + availability-exception

---

## Phase 3: ATW Validation Engine

- [ ] **Create PHP service: `AtwValidationGuard`**
  - [ ] Implement checks per ATW art. 5:3, 5:7, 5:8, + nachtdienst limits
  - [ ] **Daily max working hours** (12h gross per dienst): sum all shifts per calendar day, compare to 12h, block if exceeded
  - [ ] **Min rest between shifts** (11h): calculate rest from end of shift N to start of shift N+1, warn if <11h (unless ATB incidenteel exception applies)
  - [ ] **Max consecutive night shifts** (7): count consecutive shifts in 22:00–06:00 window, block if 8+
  - [ ] **Max night-work per 14-day cycle** (36h): sum night hours in rolling 14-day window, warn if >36h
  - [ ] **Max working hours per week** (60h): sum all shifts Mon–Sun, warn if >60h
  - [ ] Load active CAO from payroll-engine-nl to apply sector-specific exceptions (Arbeidstijdenbesluit)
  - [ ] Return violation codes + override_reason requirement per violation severity (block, warn, advisory)

**Called by**:
  - [ ] Shift lifecycle transition guard (on shift create/update)
  - [ ] Swap-request approval guard (re-validate both employees after swap)
  - [ ] Period publication gate (verify zero unresolved violations)

**Testing**:
  - [ ] Daily max: 22:00–06:00 (8h) + 14:00–22:00 (8h) → block, code ATW_DAILY_MAX_EXCEEDED
  - [ ] Min rest: shift ending 23:00, next shift starting 08:00 (9h rest) → warn, code ATW_INSUFFICIENT_REST
  - [ ] Consecutive nights: 7 nachtdiensten + 1 more → block, code ATW_CONSECUTIVE_NIGHT_MAX
  - [ ] CAO exception: incidenteel work 36h/14-day in CAO Horeca → allow with override reason

---

## Phase 4: Toeslag & Overtime Projection

- [ ] **Create PHP service: `ToeslaginputionService`**
  - [ ] Load CAO toeslag matrix from payroll-engine-nl (avond%, nacht%, weekend%, feestdag%)
  - [ ] **Toeslag categorization** per shift:
    - [ ] Avond (20:00–23:00): percentage per CAO
    - [ ] Nacht (23:00–06:00 NL local): percentage per CAO
    - [ ] Weekend (Saturday + Sunday): percentage per CAO
    - [ ] Feestdag (statutory holidays): percentage per CAO
  - [ ] **Overlap rules**: Apply CAO-specific rules (e.g., CAO Horeca: nacht overlaps avond, use nacht rate only)
  - [ ] **Overtime calculation**: Compare shift hours against employee contractual_hours/week, flag excess as overtime
  - [ ] **Projection atomicity**: Shift save updates both expected_toeslag_minutes (breakdown per category) and expected_overtime_minutes

**Called by**:
  - [ ] Shift lifecycle (on save, update expected_toeslag_minutes + expected_overtime_minutes)
  - [ ] Cost projection aggregation (sum per period for roster_period.cost_projection)

**Testing**:
  - [ ] Shift 22:00–06:00 Saturday (CAO Horeca): avond 60 min + nacht 360 min + weekend 480 min (with overlap rules)
  - [ ] Shift adding employee to 40h week (over 38h): expected_overtime_minutes = 120 min (2h)
  - [ ] Cost projection updates when shift saves

---

## Phase 5: Period Cost Dashboard

- [ ] **Create Vue component: `CnRosterCostPanel.vue`**
  - [ ] Props: `roster-period-id` (UUID)
  - [ ] Display `roster_period.cost_projection`:
    - [ ] Total loonkosten estimate (EUR)
    - [ ] Breakdown by cost-centre (table: cost-centre name, hours, cost)
    - [ ] Breakdown by position (table: position name, hours, count, cost)
    - [ ] Breakdown by toeslag category (table: avond, nacht, weekend, feestdag, each with minutes + cost)
  - [ ] Red banner if total cost exceeds period budget (optional per employer)
  - [ ] Refresh <3s when shifts are mutated (subscribe to shift lifecycle events)

**Testing**:
  - [ ] Cost breakdowns sum correctly
  - [ ] Panel updates when new shift is added
  - [ ] Budget alert displays if enabled

---

## Phase 6: Swap-Request Workflow

- [ ] **Create Vue component: `CnSwapRequestModal.vue`**
  - [ ] Props: `shift-id` (UUID), `employee-id` (UUID)
  - [ ] Render form:
    - [ ] Radio: "Two-way swap" / "One-way takeover" / "Broadcast to available"
    - [ ] Select counterparty (two-way) or eligible pool (broadcast)
    - [ ] Shift summary (both parties, if two-way)
  - [ ] On submit: create swap-request record with swap_type + status=proposed
  - [ ] Trigger notifications per lifecycle (counterparty or broadcast)

- [ ] **Create Vue component: `CnSwapApprovalQueue.vue` (manager view)**
  - [ ] Props: location scope, role=manager
  - [ ] Display table of swap-requests in status=manager_review
  - [ ] Per row: initiator, initiator shift summary, counterparty, counterparty shift summary, swap_type, actions (approve, reject)
  - [ ] Side-by-side shift comparison: both employees' other shifts that day + ATW impact summary
  - [ ] If approval would cause ATW violation: highlight red, require override_reason text field before approve button enables
  - [ ] On approve: trigger lifecycle transition → approved, swap shifts atomically, re-validate ATW, broadcast notifications

- [ ] **Create notification templates** (x-openregister-notifications in schema)
  - [ ] `proposed` (two-way): "Anna has requested to swap shifts with you on [Date]..."
  - [ ] `proposed` (broadcast): "A shift is open on [Date] [Time] at [Location]. Interested?"
  - [ ] `counterparty_accepted`: Manager receives summary with both shifts
  - [ ] `approved`: Both employees notified: "Your swap has been approved..."
  - [ ] `rejected`: Initiator notified with manager reason

**Testing**:
  - [ ] Two-way swap: initiator proposes, counterparty accepts, manager approves → shifts swap, both notified
  - [ ] Broadcast swap: first eligible employee claims, others see "claimed" message
  - [ ] ATW violation on approval: override required, reason is logged

---

## Phase 7: Availability Integration

- [ ] **Availability-exception lifecycle** (x-openregister-lifecycle)
  - [ ] On creation: trigger notification to planner with overlapping shift count
  - [ ] Query overlapping shifts: WHERE start < exception.end_datetime AND end > exception.start_datetime
  - [ ] Flag overlapping shifts: status → needs_replan, display banner in grid

- [ ] **Availability-exception notification** (x-openregister-notifications)
  - [ ] Sickness (same-day 06:30 exception for morning shift): HIGH priority to manager, broadcast swap-request created auto
  - [ ] Vacation: planner notification with "Vervang openzetten" button, no auto-broadcast (vacation is expected)
  - [ ] Training: planner notification, no broadcast (employee may handle shift)

- [ ] **CnAvailabilityOverlay component** integration into CnRosterGrid
  - [ ] Read availability-window from time-attendance (recurring) + availability-exception (ad-hoc)
  - [ ] Render grey overlay on unavailable times, green on available times
  - [ ] On drag-drop to unavailable time (non-vacation): show warning toast, require confirmation checkbox

**Testing**:
  - [ ] Vacation exception 1–7 July blocks assignment (hard block)
  - [ ] Declared unavailability Thu warns (soft block, override allowed)
  - [ ] Sickness same-day creates broadcast, manager notified
  - [ ] Vacation exception creates "Vervang openzetten" button

---

## Phase 8: Roster Publication

- [ ] **Period publication workflow** (x-openregister-lifecycle)
  - [ ] Transition: in_review → published
  - [ ] Gate: zero unresolved ATW violations (atw_violation_count == 0)
  - [ ] On publish: set published_at + published_by, update every shift.published_to_employee → true
  - [ ] Trigger notifications: every employee receives PWA push + email with deep link to personal rooster

- [ ] **Create Vue component: `CnRosterPublishDialog.vue`**
  - [ ] Pre-check: display ATW violation count
  - [ ] If violations: show red banner + violation table + "Resolve violations" button (navigate to first violation)
  - [ ] If clean: confirm "Publiceer week 22?" → trigger publication
  - [ ] On success: toast confirmation + update period status in grid

- [ ] **Notification dispatch** (x-openregister-notifications on roster-period)
  - [ ] Template: "Your rooster for [Period] is published. [Deep link to 'My Rooster' in time-attendance app]"
  - [ ] Channel: PWA push (primary), email fallback
  - [ ] Deadline: fire <5 min from publication

**Testing**:
  - [ ] Publication blocked if atw_violation_count > 0
  - [ ] On successful publication: all employees receive notification <5 min
  - [ ] Personal rooster view in time-attendance shows published shifts

---

## Phase 9: OR Audit Log Access

- [ ] **Authorization:** Create `or_inzage` role (OR member with read-only access to audit logs)
  - [ ] Define permission: `auditTrail:read` on hrmq register (with filters)
  - [ ] Restrict to pseudonymised data (employee IDs hashed consistently)

- [ ] **Audit trail filtering** per role:
  - [ ] Planner/Manager: full audit log with real employee names
  - [ ] OR inzage: pseudonymised audit log (EMP-00001 format), no personeelsdossier data, all override reasons verbatim

- [ ] **Create Vue component: `CnRosterAuditPanel.vue`** (OR view)
  - [ ] Props: roster period range (start/end date), employer_id
  - [ ] Table columns: timestamp, actor (role), target (shift/swap/period), action, before/after hash, rationale (if override)
  - [ ] Filter by action (create, update, override, publish) + date range
  - [ ] Hash chain verification: each row references previous row's after_hash (tamper-evident)
  - [ ] Export as CSV (anonymised)

**Testing**:
  - [ ] OR queries return anonymised data
  - [ ] Override reasons are visible
  - [ ] Hash chain is verifiable
  - [ ] Sensitive fields (salary, performance) are absent

---

## Phase 10: Integration with External Apps

- [ ] **Integration: time-attendance**
  - [ ] Read availability-window (recurring availability) — use ObjectService + relations
  - [ ] Read clock_in/clock_out events for comparison against planned shifts (future: variance detection)
  - [ ] Publish personal rooster view in time-attendance "My Rooster" tab (link to shift details, availability overlay)

- [ ] **Integration: employee-master**
  - [ ] Read employee, position, manager, contractual_hours, cao_id, skills
  - [ ] Use for filtering (function, skill, manager hierarchy)
  - [ ] No write (rooster is read-only)

- [ ] **Integration: leave-absence**
  - [ ] Subscribe to leave-absence lifecycle: when leave record transitions to `approved`, create corresponding availability-exception
  - [ ] Sync on daily interval (n8n workflow or scheduled task) to catch retroactive approvals

- [ ] **Integration: payroll-engine-nl**
  - [ ] Read CAO matrix (toeslag %, overtime multiplier, sector-specific rules)
  - [ ] Export projected toeslagen + overtime per shift, per period
  - [ ] Feed cost projection to payroll cash-flow dashboard (future)

- [ ] **Integration: n8n events**
  - [ ] Publish CloudEvents on shift lifecycle: `roster.shift.created`, `roster.shift.published`, `roster.shift.overridden`
  - [ ] Publish on swap: `roster.swap.approved`, `roster.swap.rejected`
  - [ ] Publish on publication: `roster.period.published`
  - [ ] Customers can subscribe and auto-sync to signage, notify Whatsapp groups, etc.

**Testing**:
  - [ ] Availability-window overlay renders correctly
  - [ ] CAO exceptions are applied during ATW validation
  - [ ] Cost projection appears in payroll dashboard
  - [ ] n8n receives CloudEvents on publication

---

## Phase 11: Accessibility & UX Polish

- [ ] **Keyboard navigation**
  - [ ] Arrow keys to move between grid cells
  - [ ] Enter/Space to open shift editor
  - [ ] Tab through form fields
  - [ ] ESC to close modals

- [ ] **Screen reader labels**
  - [ ] Every grid cell: "Monday 09:00–17:00, Anna de Vries, kassa, clean ATW"
  - [ ] Violation indicators: "ATW warning: insufficient rest"
  - [ ] Swap-request buttons: "Request to swap with Jan"

- [ ] **Colour-blind accessibility**
  - [ ] No red-only warnings (use icon + colour + text)
  - [ ] Test with colour-blind simulator (Daltonize)

- [ ] **Dutch localization**
  - [ ] All labels, buttons, error messages in Dutch
  - [ ] Date/time: DD-MM-YYYY, 24-hour HH:MM
  - [ ] Toeslag categories: avondtoeslag, nachttoesslag, weekendtoeslag, feestdagtoeslag
  - [ ] ATW violation codes with Dutch descriptions

**Testing**:
  - [ ] Keyboard-only navigation works end-to-end
  - [ ] NVDA screen reader reads grid correctly
  - [ ] All text is Dutch (no English fallbacks in UI)

---

## Phase 12: Testing & QA

### Unit Tests
- [ ] AtwValidationGuard: 10+ test cases (daily max, rest, consecutive nights, CAO exceptions)
- [ ] ToeslaginputionService: 5+ test cases (toeslag categories, overlap rules, overtime)
- [ ] Shift lifecycle transitions: 6+ test cases (status progressions, guards)

### Integration Tests
- [ ] Shift CRUD + ATW validation
- [ ] Swap-request workflow (propose → accept → approve)
- [ ] Availability-exception impact on shifts
- [ ] Period publication gate

### Browser Tests (manual)
- [ ] Week grid drag-drop (Chrome, Firefox, Safari, Mobile Safari)
- [ ] Month view drill-down
- [ ] Swap-request approval with side-by-side preview
- [ ] Cost dashboard updates on shift mutation
- [ ] Publication notification dispatch

### Performance Tests
- [ ] Grid render time: <500ms (7×50 shifts)
- [ ] Shift mutation + websocket propagation: <1s
- [ ] Cost projection recalculation: <3s
- [ ] ATW validation: <2s per shift

### Accessibility Tests
- [ ] WCAG 2.1 AA (colour contrast, keyboard nav, screen reader)
- [ ] Colour-blind simulator (red/green/blue/mono)
- [ ] Mobile responsive (PWA on iPhone 12, Android 12)

- [ ] **Deduplication check**
  - [ ] Verify no overlap with existing roster/scheduling apps (procest, pipelinq)
  - [ ] Confirm reuse of ObjectService, CnDataTable, ImportService, AuditTrailService, NotificationService
  - [ ] Document findings (even if "no overlap found")

---

## Phase 13: Documentation & Rollout

- [ ] **API Documentation (OpenAPI 3.0)**
  - [ ] Document REST endpoints for shift CRUD, swap-request creation, period publication
  - [ ] Example payloads for all schemas
  - [ ] Error codes (EMPLOYEE_ON_LEAVE, PUBLISH_BLOCKED_ATW, etc.)

- [ ] **User Documentation**
  - [ ] Planner guide: how to create a period, assign shifts, handle ATW violations, publish
  - [ ] Employee guide: how to request a swap, view personal rooster, manage availability
  - [ ] Manager guide: how to approve swaps, override ATW violations, handle sickness alerts
  - [ ] OR guide: how to access anonymised audit log

- [ ] **Training**
  - [ ] Record demo video (5 min planner walkthrough)
  - [ ] Conduct live training for pilot group (1 SMB retail location)

- [ ] **Pilot Rollout**
  - [ ] Deploy to 1–2 SMB test locations (retail, horeca, or zorg)
  - [ ] Monitor for 2 weeks: feedback on usability, ATW validation accuracy, notification dispatch
  - [ ] Collect feedback survey (SUS score, feature requests)
  - [ ] Fix high-priority bugs

- [ ] **GA Rollout**
  - [ ] Deploy to all locations
  - [ ] Monitor error rates, performance metrics, user adoption
  - [ ] Publish release notes

---

## Acceptance Criteria

**Functional**:
✓ All 10 requirements (REQ-RP-001 through REQ-RP-010) pass specification tests
✓ ATW validation blocks/warns correctly per art. 5:3, 5:7, 5:8 + CAO exceptions
✓ Toeslag projection matches CAO matrix (spot-check 3+ shifts per CAO)
✓ Swap-request workflow routes through manager approval
✓ Availability-exception impact (vacation hard-block, sickness auto-broadcast)
✓ Period publication broadcasts notifications <5 min
✓ Audit log is comprehensive, tamper-evident, OR-accessible

**Performance**:
✓ Grid renders <500ms (7×50 cells)
✓ Shift mutations propagate <1s via websocket
✓ Cost projection updates <3s
✓ ATW validation <2s per shift

**Accessibility**:
✓ WCAG 2.1 AA (colour contrast, keyboard nav, screen reader)
✓ Colour-blind accessible (no red-only)
✓ Full Dutch localization
✓ Mobile responsive (PWA)

**Quality**:
✓ Unit test coverage >80% (ATW, toeslag, lifecycle)
✓ Integration tests for shift CRUD, swaps, publication
✓ Browser tests across Chrome, Firefox, Safari, Mobile
✓ Deduplication check documented

---

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| ATW validation complexity | Start with basic rules (daily max, rest, consecutive nights); add CAO exceptions incrementally. Test against real CAO data. |
| Swap-request coordination bugs | Phase swap workflow carefully (propose → accept → approve). Test atomic shifts on Postgres transactions. |
| Notification delivery at scale | Use OpenRegister notification engine (proven in time-attendance). Test with 500+ employees. Monitor email fallback. |
| OR audit compliance | Start with full logging; anonymise only in the OR query layer. Hash-chain verification via AuditTrailService. Audit by OR member user testing. |
| Websocket sync issues | Use OpenRegister websocket bus (standard). Test concurrent edits (CAS conflict handling). Implement retry logic. |

---

## Rollout Checklist

- [ ] All tasks completed and tested
- [ ] Code review complete (builder + reviewer)
- [ ] Hydra gates pass (route-auth, spdx, modal-isolation, IDOR, etc.)
- [ ] Performance tests pass (<500ms grid, <1s mutations)
- [ ] Accessibility tests pass (WCAG AA, keyboard, screen reader)
- [ ] Pilot group trained and feedback collected
- [ ] High-priority bugs fixed
- [ ] Release notes written
- [ ] Documentation published
- [ ] GA deploy approved

---

## Future Enhancements (Out of Scope)

- Custom shift types (split shifts, on-call standby)
- Predictive staffing / demand-driven auto-scheduling
- Shift marketplace (employee-to-employee trading)
- Biometric geofence-tied rosters
- Integration with external roster systems (Shiftbase, Planday, Quinyx)
- Mobile app (currently PWA only)
- Advanced analytics (staffing trends, labor cost forecasts)
