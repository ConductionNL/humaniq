---
status: approved
placement_type: SUB_PAGE
placement_parent: "Verlof & verzuim › Rooster-planning"
affected_schemas:
  - roster-period
  - shift
  - shift-template
  - swap-request
  - availability-exception
  - coverage-requirement
cross_app_integration:
  - employee-master (supplies employee, position, manager, contractual hours, CAO, skills)
  - time-attendance (shares availability-window, receives actual clock events)
  - leave-absence (produces availability-exception records)
  - payroll-engine-nl (receives toeslag/overtime forecast)
  - planix (publishes coverage-requirement)
  - pipelinq (projects invoice lines)
  - docudesk (archives published rosters)
  - n8n (consumes roster events)
---

# Rostering & Planning — Proposal

## Executive Summary

The `rostering-planning` capability is a register-backed shift-roster grid for retail, horeca, zorg, schoonmaak, and beveiliging sectors where the planner's roster is the operational backbone. It replaces spreadsheet + Whatsapp workflows with a structured planning system that guarantees ATW (Arbeidstijdenwet) compliance, projects toeslagen and overtime, surfaces employee availability, and routes swap-requests through manager approval before mutating the roster.

**Demand Score:** Retail, horeca, zorg operators cite rooster planning as the #1 blocker to digitizing HR. Today planners work in Shiftbase, L1NDA, Eitje, Quinyx, or Excel—none Dutch-law-aware, none integrated with payroll. hrmq solves this.

**Key Differentiators:**
1. **ATW-native**: Every shift is validated at write time against Arbeidstijdenwet ceilings. Violations block save or require manager override with reason.
2. **CAO-aware**: Toeslag and overtime projection uses the same CAO matrices as time-attendance. Cost dashboard reflects loonkosten forecast before the roster publishes.
3. **Register-native**: Shifts are first-class openregister objects linked to employee, position, location, cost-centre. Real-time grid updates via openregister websocket bus.
4. **OR-transparent**: Full audit log of every shift mutation, swap, and override, tamper-evident and viewable by the OR under WOR article 27 (instemmingsrecht).

## Scope

The capability owns:
- **Shifts** (planned working blocks with ATW validation, toeslag projection, and publication state)
- **Swap-requests** (one-way takeover, two-way swap, broadcast through manager approval)
- **Publication** (locking the period, notification dispatch, audit entry)
- **ATW/toeslag projection** (live cost dashboard, CAO-aware per employee)

The capability does NOT own:
- **Attendance** (actual clock events—live in `time-attendance`)
- **Employee master** (that's `employee-master`)
- **Absence balances** (those live in `leave-absence`)

## Data Model Summary

Six openregister schemas in the `hrmq` register:

1. **`roster-period`** — the planning unit (week, configurable per employer). Tracks status (draft → in_review → published → locked → archived), cost projections, ATW violations, swap count.

2. **`shift`** — one planned working block. Employee (nullable for open shifts), position, location, cost-centre, start/end UTC, breaks, expected toeslagen per category, overtime minutes, ATW validation state + override reason.

3. **`shift-template`** — recurring patterns (RFC 5545 RRULE). Stamped into shifts when period rolls forward.

4. **`swap-request`** — structured handover between employees. One-way takeover, two-way swap, or broadcast. Routed through manager approval (proposed → counterparty_accepted → manager_review → approved/rejected).

5. **`availability-exception`** — vacation, sickness, training blocks. Overlaps with shifts trigger replan flags and broadcast swap-requests.

6. **`coverage-requirement`** — minimum staffing by location/position/time. Drives "under-staffed" cell highlighting and publication-gate enforcement.

## User Stories

### Story 1: Planner publishes week 22 with ATW check
**As** a filiaalmanager (retail)  
**I want to** see a 7×N shift grid, drag employees into time slots, and publish the week  
**So that** every employee gets notified of their shifts and I have proof the rooster respects ATW

**Acceptance:**
- GIVEN a planner opens roster-period week 22 WHEN the page loads THEN the grid renders with every existing shift and gaps tinted per coverage-requirement
- GIVEN a planner drags employee X onto an open shift WHEN the drop commits THEN shift.employee_id updates, ATW re-validates, cost projection refreshes, change propagates via websocket
- GIVEN the rooster has zero unresolved ATW violations WHEN the planner taps "Publiceer week 22" THEN status → published, notification → every employee with deep link

### Story 2: Employee requests a ruil (swap)
**As** a medewerker  
**I want to** tap "Ruil verzoek" on a shift, select a counterparty, and track approval status on my phone  
**So that** I can swap shifts without Whatsapp sprees and ATW violations don't surprise me

**Acceptance:**
- GIVEN an employee selects a shift and counterparty WHEN they tap "Ruil verzoek" THEN swap-request status → proposed, counterparty sees notification
- GIVEN the counterparty accepts THEN status → counterparty_accepted, manager approval queue receives item with side-by-side preview
- GIVEN the manager approves THEN both shift.employee_id fields swap atomically, ATW re-validates both employees, both receive push notification

### Story 3: Manager responds to sickness
**As** a manager  
**I want to** see a same-day sickness exception trigger a broadcast swap-request to eligible staff  
**So that** coverage gaps are filled without waiting for the planner to manually intervene

**Acceptance:**
- GIVEN a sickness availability-exception posted at 06:30 for same-day morning shift WHEN the exception persists THEN affected shift → open, broadcast swap-request → eligible pool, manager → high-priority alert
- GIVEN the broadcast resolves THEN coverage is restored and employees are notified

## Standards & Regulatory Context

- **Arbeidstijdenwet (ATW)** — max 12h dienst, max 60h week, min 11h rest between diensten, max 7 consecutive nachtdiensten, max 36h night-work per 14-day. Sector exceptions per ATB (Arbeidstijdenbesluit).
- **CAO Horeca / VVT / Retail / Schoonmaak / Beveiliging** — toeslag matrices, max nachtdiensten, koopzondag rules, slaap- en bereikbaarheidsdiensten.
- **WOR article 27** — OR instemmingsrecht on werktijdregelingen. Underpins audit log transparency.
- **AVG article 5/6/9** — rooster data are personeelsgegevens. Vacation reason field is restricted, sickness reason is special-category and never persisted.
- **RFC 5545** — iCalendar RRULE for templates and availability-window. Exportable as ICS for employee calendar subscription.
- **NEN 8045** — zorg-sector safety guidance for nachtdienstplanning.
- **Forum Standaardisatie** — OpenAPI 3.0 for rooster export, OAuth 2.0 for swap/notification endpoints.

## Success Metrics

1. **ATW compliance**: 100% of published rosters pass statutory validation; 0 silent violations.
2. **Swap time-to-resolution**: Mean 2 hours from request to manager approval (vs. next-day Whatsapp today).
3. **Cost accuracy**: Projected toeslagen ±5% of actual payroll (validated post-payrun).
4. **OR audit ready**: 100% of shifts logged with actor, action, before/after hash; OR access within 5 minutes of request.
5. **User adoption**: 90% of medewerkers view their personal rooster in app within 2 weeks of publication.

## Out of Scope

- Custom shift types (split shifts, on-call, standby) — treat as future enhancements
- Shift-swap marketplace (open-market trading) — use broadcast + manual assignment
- Rostering forecasts / demand-driven auto-scheduling — use simple period rollover + template generation
- Integration with external rooster systems (Shiftbase, Planday) — treat as future sync APIs
