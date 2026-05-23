---
status: proposed
date: 2026-05-23
---

# BHV Organisatie — Proposal

## Overview

Bedrijfshulpverlening (BHV) management for organisations subject to Arbowet article 15. A centralized register of BHV personnel per location with automatic certificate expiry monitoring, staffing coverage calculation, evacuation plan management, drill registration, emergency inventory tracking, and compliance reporting for Dutch labour inspectorates (SZW).

## Features

| Feature | Demand | Priority | Description |
|---------|--------|----------|-------------|
| **Location & Personnel Register** | 9/10 | MUST | Central register of BHV members per location with roles (bhv_basis, hoofd_bhv, ploegleider, ehbo, aed_operator, ontruimingsleider) and availability patterns |
| **Certificate Lifecycle** | 9/10 | MUST | Track BHV certifications (bhv_basis, bhv_herhaling, hoofd_bhv, ehbo_oranje_kruis, reanimatie_aed, specialistisch) with expiry dates, automatic warning at 90 days, status transitions |
| **Staffing Coverage Calculation** | 9/10 | MUST | Enforce Arbowet minimum (1 BHV per 50 attendees + 1 extra per 100) per location per day with risk-multipliers for high-risk activities; daily 06:00 batch calculates coverage and notifies safety officers when red |
| **Roster & Scheduling** | 8/10 | MUST | Weekly roster generation respecting availability patterns, fair distribution, vacation coordination (via verlof-administratie), conflict detection for overlapping assignments |
| **Evacuation Plan Library** | 8/10 | MUST | Document management for ontruimingsplannen per location with versioning, automatic update notifications to BHV members, QR-code access for on-site room-specific plans in NL/EN with exit marking |
| **Drill Registration** | 8/10 | MUST | Record announced/unannounced evacuations and incidents with evacuation time, participant count, evaluation template, lessons learned, yearly compliance check |
| **EHBO/AED Inventory** | 8/10 | MUST | Track first-aid kits and AED devices per location with inspection schedules (6-monthly EHBO, 12-monthly AED), condition status, expiry tracking, replacement order workflow |
| **Alarm Flow & Incident Logging** | 8/10 | MUST | Receive alarms (manual, IoT, building system) with push notification to on-call BHV members, response timeline logging, incident report generation with SZW audit trail |
| **Compliance Reporting** | 9/10 | MUST | Arbowet audit-ready reports: per-location coverage vs required (rolling 12mo), certificate overview, drill history, inventory status, incident log; OR/board trend reports; offboarding alerts |
| **Mobile App for BHV Personnel** | 7/10 | SHOULD | Standalone view of assigned shifts, upcoming expirations, evacuation plans, location-specific safety info via QR scan, SOS button, alarm acceptance with location sharing (consent-gated) |

## User Stories

### Story 1: Safety Officer Monitors Daily Coverage
**GIVEN** a safety officer logs in to the BHV app
**WHEN** they view today's schedule
**THEN** they see per-location required vs assigned BHV count, color-coded (groen/geel/rood), with a list of assigned members and their current certification status
**AND** if coverage is red, they receive a notification at 06:00 with 1-click access to find replacement

---

### Story 2: Certificate Expiry Alert Triggers Bijscholing Request
**GIVEN** a BHV member has a bhv_basis certification expiring in 90 days
**WHEN** the daily alert job runs
**THEN** the member and their safety officer receive a reminder
**AND** a recommended training slot is proposed from the training-opleidingen app
**AND** accepting the recommendation creates a provisional enrollment

---

### Story 3: Evacuation Plan Update Requires Member Sign-Off
**GIVEN** a safety officer uploads a new ontruimingsplan for a location
**WHEN** the document is accepted
**THEN** all BHV members assigned to that location receive a notification
**AND** they must confirm "read and understood" before the plan counts as active
**AND** members can scan the QR code on-site to see room-specific exit routes

---

### Story 4: Drill Compliance Check on Yearly Anniversary
**GIVEN** a location has not recorded a drill in 12 months
**WHEN** the annual compliance check runs
**THEN** the location is flagged red in compliance reports
**AND** the safety officer receives an action to schedule and execute a drill
**AND** leadership is notified of the gap

---

### Story 5: Mobile App Provides Instant On-Site Safety Information
**GIVEN** a BHV member is on a new floor of their location
**WHEN** they scan a room's QR code via the mobile app
**THEN** they see: nearest EHBO kit, nearest AED, closest emergency exit, ontruimingsplan excerpt for that floor
**AND** they do not need to log in to the central system

---

### Story 6: AED Fault Alert Triggers Backup Activation
**GIVEN** an AED device reports a fault via IoT webhook
**WHEN** the alert is received
**THEN** the AED status changes to defect, a high-priority Slack/email notification goes to safety officer
**AND** if a backup AED is configured, it is automatically designated as active
**AND** a replacement order is auto-proposed with 7-day SLA

---

### Story 7: Offboarding Flags Missing Hoofd-BHV
**GIVEN** a hoofd-BHV exits the organisation
**WHEN** their offboarding is processed via employee-master
**THEN** the system flags the hoofd-BHV role as vacant
**AND** a compliance risk alert is created for the safety officer
**AND** a training request is pre-suggested to develop a successor

---

### Story 8: SZW Audit Report Export
**GIVEN** a safety officer prepares for an Inspectie SZW audit
**WHEN** they request the compliance-export
**THEN** a PDF/JSON package is generated with: per-location coverage trends (rolling 12mo), certificate overview per member, drill history, inventory status, incident timeline, certifications vs roles, bijscholing compliance

---

## Acceptance Criteria

- [ ] All 7 core entities (Location, BhvMember, Certification, BhvSchedule, Drill, InventoryItem, AlarmEvent) are created in OpenRegister
- [ ] Daily batch jobs (06:00 coverage check, expiry alerts, compliance checks) are scheduled and logged
- [ ] Mobile app supports QR scanning for room-level ontruimingsplan access without login
- [ ] Audit-ready compliance reports match SZW baseline requirements (Arbowet art. 15, Arbobesluit 2.5b–2.5g)
- [ ] Consent flow for location sharing and mobile contact sharing is fully implemented and auditable
- [ ] Integration with training-opleidingen, verlof-administratie, docudesk, employee-master, openconnector working end-to-end
- [ ] All requirements REQ-001 through REQ-010 pass functional testing
