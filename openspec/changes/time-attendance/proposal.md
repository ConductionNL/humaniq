# Proposal: Time & Attendance — Klokken, Urenstaten, Project-Tijdregistratie

**Status**: proposal  
**Date**: 2026-05-23  
**Change ID**: time-attendance  
**App**: hrmq  
**Placement**: SUB_PAGE under *Verlof & verzuim › Tijdregistratie*

## Executive Summary

The `time-attendance` capability brings web- and mobile-based time tracking to hrmq for the Dutch SMB labour market segments that run on hour-based pay: bouw, horeca, retail, schoonmaak, beveiliging, logistiek and zorg.

This is a "pipe" capability — thin domain, deep integration. It owns clock events, timesheet aggregation, approval workflow, and the contract surface for downstream consumers (payroll-engine-nl, pipelinq, planix, shillinq). It does not own rostering (rostering-planning) or payroll calculation (payroll-engine-nl).

**Architectural principle**: Every clock event is queryable, attributable, immutable. No "the app ate my hours" black box — every mutation is logged, every approval has a signer, every payroll export references underlying events by ID. This matches AVG traceability and CAO Bouw/Horeca 7-year retention.

## Market Rationale

**Problem**: SMB employers in construction, hospitality, retail, cleaning, security, logistics, and care today run hours on Whatsapp, Excel sheets, or expensive closed time-clocks (Nedap, Shiftbase, L1NDA, Protime). They need:
- Fast clock-in from phone/kiosk without draining battery
- Automatic overtime & premium calculation per their sector CAO
- Exception flags for missing breaks, geofence mismatches, ATW violations
- Payroll export that doesn't require re-keying
- Seven-year audit-trail for CAO compliance

**Opportunity**: hrmq differentiates as open, register-native, and integrated with project costing & invoicing in the same stack. One timesheet record drives payroll, billable-hours export, project burndown, and dossier evidence.

**Non-users**: Large corporates with SAP SuccessFactors or Workday — they don't need hrmq.

## User Journeys

### Journey 1: Employee clocks in/out (PWA, web, kiosk, API)
**Trigger**: Employee arrives at workplace (mobile PWA), supervisor logs hours (web), kiosk asks for PIN (shared tablet), external time-clock POSTs API event.  
**Pain point**: Lost time records, no GPS proof of location, manual data entry.  
**Outcome**: Clock event persisted with source (web/mobile_pwa/kiosk/api_import), UTC timestamp, optional GPS + geofence match, device fingerprint.

### Journey 2: Employee reviews & submits daily/weekly timesheet
**Trigger**: Day cutoff (03:00) aggregates events into daily entry; week-end (Sunday 23:59) aggregates daily entries into weekly timesheet.  
**Pain point**: Can't see what they worked before payroll runs; typos in hours can't be corrected in time.  
**Outcome**: Employee sees daily breakdown + running overtime; can flag missing clock-out for manual correction; submits weekly batch to manager once validated.

### Journey 3: Manager approves timesheet queue
**Trigger**: Employee submits weekly timesheet; manager receives notification.  
**Pain point**: No visibility into exceptions (missing pauzes, geofence outside site, ATW violations); approving risky sheets without justification.  
**Outcome**: Manager sees exception queue with details; flags overrides with mandatory free-text reason; approves clean sheets; returns rejected sheets with comment.

### Journey 4: Timesheet flows downstream
**Trigger**: Manager approves timesheet; payroll-export runs.  
**Pain point**: Payroll auditors can't trace hours to original events; invoicing requires manual billable-hour entry; project managers see no allocation.  
**Outcome**: Payroll batch emitted with event IDs baked in; billable allocations flow to pipelinq for invoicing; project burndown updated from planix allocations; audit trail queryable.

## Stakeholders & Responsibilities

| Role | Goals | Constraints |
|------|-------|-------------|
| **Employee (medewerker)** | Fast clock-in, see accrued pay before payroll, submit timesheet without friction | Mobile PWA, 4-digit PIN for kiosk, read-only after submission |
| **Manager (leidinggevende)** | Approve queue, spot exceptions, override with justification | Must see exception codes, geofence + GPS details, ATW violations |
| **HR Admin (boekhouder/HR)** | Export payroll batch, lock exported sheets, correct via admin-only correction-run, query audit trail | Idempotent batch IDs, re-export guards, seven-year retention |
| **Project Manager (projectleider)** | Allocate hours to planix projects, see burndown, bill customers | Allocations must sum to net working minutes; optional for non-project staff |
| **Payroll Service (loonbedrijf)** | Consume approved timesheet batch, reference underlying event IDs, settle batch, return payroll_batch_id | Clean event ID chain, no re-keying, idempotent import |
| **Compliance Officer (OR / works council)** | Sign off on GPS feature, query audit trail, verify CAO rules applied | Audit log with before/after hashes, GPS opt-in per policy, rule traceability |

## Features & Demand Signals

### Core Features (MVP)

| Feature | Demand | Description | Dependencies |
|---------|--------|-------------|--------------|
| **Multi-channel clock-in** | P0 | Web browser, PWA mobile app, kiosk PIN, REST API import; all produce identical clock-event records distinguishable by source | — |
| **GPS + geofence verification** | P0 | Optional per employer policy; flags geofence_match=true/false; never rejects events (manager triages) | — |
| **Daily/weekly timesheet aggregation** | P0 | Materialise clock events into daily entries after cutoff; roll into weekly draft until submitted | Clock-in/out |
| **Overtime & CAO calculation** | P0 | Apply CAO-specific rules (Bouw, Horeca, VVT, Retail, generic 40h fallback); compute overtime_hours & toeslag per category | Employee-master CAO assignment |
| **Toeslag (premium) calculation** | P0 | Avond, nacht, weekend, feestdag with overlapping support (e.g. zaterdagavond); use active CAO premium matrix | Clock events + Dutch public-holiday calendar |
| **Project-time allocation** | P0 | Split daily entry across planix projects/tasks; allocations must sum to net working minutes; optional for non-project staff | Planix project/task API |
| **Approval workflow** | P0 | Employee submits → manager approves/rejects; rejected sheets return to draft; approvals locked until exported | — |
| **Payroll export** | P0 | Batch of approved timesheets with idempotent batch_id; re-export guard; exported sheets locked | Payroll-engine-nl contract |
| **Billable-hours export** | P0 | Emit billable allocations to pipelinq for invoicing; support amend events for corrections | Pipelinq event contract |
| **Audit log & immutability** | P0 | Log every mutation with actor, timestamp, old/new hash; 7-year retention; no hard deletes (tombstones) | Openregister audit service |

### Future Features (not in MVP)

| Feature | Demand | Description |
|---------|--------|-------------|
| **Shift matching vs rostering-planning** | P1 | Compare actual clock events to planned shifts; flag LATE_START, EARLY_END, NO_SHOW, UNPLANNED_SHIFT | Requires rostering-planning MVP |
| **Bulk correction-run (admin)** | P1 | HR admin uploads CSV of corrections; system creates new events + correction batch downstream | Requires payroll audit workflow |
| **Mobile app offline support** | P1 | PWA caches clock events, syncs on reconnect | Capacitor/Cordova build |
| **Geofence polygon editor** | P1 | UI to draw job-site boundaries instead of radius-only | Map library integration |
| **Flexible break rules** | P1 | Configurable paid/unpaid break thresholds per CAO and job-type | Requires CAO module extension |

## Information Architecture Alignment

**Placement type**: `SUB_PAGE`  
**Lives at**: Verlof & verzuim › Tijdregistratie  
**Rationale**: Time-attendance is a clock + timesheet surface, sibling to leave and absence tracking within the same "Verlof & verzuim" domain. It is not a top-level menu per ADR-001 Rule 1 (CAOs are rulesets, not menus) and not a separate manager portal per Rule 2 (approval queue is scoped action within the module).

## Constraints & Standards

- **Arbeidstijdenwet (ATW)**: Flag violations after the fact; rostering-planning enforces ceilings (time-attendance is audit-trail, not gatekeeper)
- **CAO modules**: Bouw & Infra, Horeca, VVT (zorg), Retail Non-Food, generic 40h fallback
- **GPS & workplace monitoring**: Opt-in per employer policy; employee consent per kennisgevingsverplichting (AP guidance)
- **Data retention**: Seven-year fiscal class per CAO Bouw + Horeca; retention engine (openregister AVG) purges atomically
- **Audit log**: Tamper-evident with before/after hash chains; queryable by HR auditor + compliance officer
- **Downstream contracts**: Payroll-engine-nl event IDs, pipelinq allocation_id idempotency, planix project-burndown events

## Success Criteria

- [ ] All four user journeys (clock-in, daily review, manager approval, downstream export) flow end-to-end without manual re-keying
- [ ] Overtime & toeslag calculated correctly per CAO for at least 80% of test scenarios (CAO Bouw, Horeca, VVT)
- [ ] Audit trail queryable and hash-verified; no deletions possible
- [ ] Payroll batch idempotency: re-export of same week produces same batch_id, no duplicates
- [ ] Mobile PWA clock-in works offline; syncs when reconnected
- [ ] Geofence exception flagging (no hard rejections) works per policy
- [ ] Exported timesheets locked; only admin correction-run permits edits
- [ ] Employee can submit only after employee review; manager can override only with justification + audit log entry
