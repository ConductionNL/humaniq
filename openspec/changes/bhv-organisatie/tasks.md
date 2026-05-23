---
status: draft
---

# BHV Organisatie — Implementation Tasks

## Phase 1: Data Layer & Seed Data

- [ ] **Data Model Definition**
  - [ ] Create `bhv_organisatie_register.json` in `lib/Settings/`
  - [ ] Define 7 schemas: Location, BhvMember, Certification, BhvSchedule, Drill, InventoryItem, AlarmEvent
  - [ ] Add schema.org vocabulary alignment (Place, Person, Organization)
  - [ ] Define enum types: roles, certTypes, drillTypes, itemTypes, alarmTypes, coverageStatus, certStatus
  - [ ] Add all required and optional fields per design.md entity definitions
  - [ ] Mark register as `x-openregister.type: "application"`

- [ ] **Seed Data Generation**
  - [ ] Generate 3 realistic Location objects (Amsterdam HQ, Rotterdam depot, Utrecht training facility)
  - [ ] Generate 12 BhvMember objects with mixed roles and availability patterns
  - [ ] Generate 15 Certification records with varying statuses (valid, expiring-soon, expired)
  - [ ] Generate 20 BhvSchedule records for next 30 days with coverage status distribution
  - [ ] Generate 4 Drill records with full evaluation details
  - [ ] Generate 8 InventoryItem records (AED, EHBO kits, etc.)
  - [ ] Generate 2 AlarmEvent records with response timeline logs
  - [ ] All seed data uses realistic Dutch names, valid postcodes (NL format), and 2026 dates
  - [ ] Mark as `x-openregister.type: "mock"`
  - [ ] Add all seed objects to `components.objects[]` with `@self` envelope

- [ ] **Schema Import & Validation**
  - [ ] Test schema import via `ConfigurationService::importFromApp()`
  - [ ] Verify idempotency: re-import with `force: false` creates no duplicates
  - [ ] Verify seed data objects are created on first install
  - [ ] Test all enum constraints (role arrays, certification types, etc.)

## Phase 2: Backend Services & Jobs

- [ ] **Coverage Calculation Service** (`BhvCoverageService`)
  - [ ] Implement `calculateRequired(location.expectedOccupancy)` → returns requiredCount using formula ceil(occ/50) + floor(occ/100)
  - [ ] Implement `calculateCoverageStatus(schedule)` → return groen/geel/rood based on assignedMemberIds count
  - [ ] Add risk-multiplier logic for high-risk activities
  - [ ] Unit tests: test formula accuracy, edge cases (0 occupants, 1 occupant, 10000 occupants)

- [ ] **Daily Coverage Batch Job** (`BhvCoverageBatchJob`)
  - [ ] Schedule for 06:00 local time daily
  - [ ] Query all `BhvSchedule` records for today
  - [ ] For each schedule: call `BhvCoverageService::calculateCoverageStatus()`
  - [ ] If status = rood: send notification to location safety officer with 1-click roster edit link
  - [ ] Log all batch runs with counts (total schedules, rood count, notifications sent)

- [ ] **Certificate Expiry Monitoring** (`CertificationExpiryService`)
  - [ ] Query all `Certification` records daily
  - [ ] Implement 90-day warning logic: if expiryDate - today = 90 days → send notification (once only)
  - [ ] Implement auto-expiry transition: on expiryDate → status = verlopen, notify member + safety officer
  - [ ] Implement schedule recalculation trigger: on expiry, recalculate all `BhvSchedule` for next 30 days
  - [ ] Integrate with `training-opleidingen` to auto-propose training slot

- [ ] **Certificate Upload OCR Processing** (`CertificateUploadHandler`)
  - [ ] Use `FileService` to accept PDF/image uploads
  - [ ] Call text extraction service (OCR) to extract name and date from certificate
  - [ ] Compare OCR name to `BhvMember.name` with fuzzy matching (>85% threshold)
  - [ ] If match: auto-accept and create `Certification` record
  - [ ] If no match: return confidence score + require manual confirmation UI
  - [ ] Log all uploads: file, OCR confidence, decision, timestamp

- [ ] **Roster Generation Algorithm** (`RosterScheduler`)
  - [ ] Implement weekly roster proposal logic:
    - [ ] Respect `BhvMember.availabilityPattern` (recurring + exceptions)
    - [ ] Exclude members with active verlof via `verlof-administratie` integration
    - [ ] Implement fair distribution algorithm (minimize variance in shifts/member)
    - [ ] Avoid consecutive shifts for same member (configurable)
    - [ ] Ensure ≥1 hoofd_bhv assigned per location per shift
  - [ ] Return draft roster for safety officer approval
  - [ ] Lock approved roster for edit (only safety officer override with audit)
  - [ ] Unit tests: test fairness metric, availability respect, role constraints

- [ ] **Sick Leave Re-assignment** (`SickLeaveHandler`)
  - [ ] Receive absence notification (via HR integration or manual entry)
  - [ ] Mark affected `BhvSchedule` slot as `geel`
  - [ ] Query available `BhvMember` records with matching certifications for same day/time
  - [ ] If candidates found: auto-assign to first match + notify member
  - [ ] If no candidates: escalate to safety officer with suggestion list
  - [ ] Log outcome (found/not found, replacement details)

- [ ] **Ontruimingsplan Distribution** (`EvacuationPlanService`)
  - [ ] On plan upload: store in `docudesk` with version control
  - [ ] Query all `BhvMember` records assigned to location
  - [ ] Send notification to each: includes document name, effective date, "Mark as Read" button
  - [ ] Track confirmations in `Certification` or new `DocumentConfirmation` schema
  - [ ] Plan marked `active` only after all assigned members confirm (audit log of confirmations)
  - [ ] 12-month expiry reminder: auto-generated action for safety officer to review

- [ ] **Drill & Evaluation Workflow** (`DrillService`)
  - [ ] Create `Drill` record on creation with type, location, scheduled date
  - [ ] For announced drills: send pre-notification to all location personnel 24h prior
  - [ ] On execution: record `executedAt`, `evacuationDurationSeconds`, `participantCount`
  - [ ] Auto-generate evaluation form template with schema-driven fields
  - [ ] Calculate evaluation metrics (time vs 10min target, roll-call accuracy, etc.)
  - [ ] Store evaluation document via `FileService` + link to `Drill.evaluationDocumentId`
  - [ ] Annual compliance check: query all `Drill` by calendar year, flag locations with 0 drills

- [ ] **Inventory Inspection Scheduler** (`InventoryInspectionService`)
  - [ ] Daily morning job: query all `InventoryItem` records
  - [ ] For items with `nextInspectionDue <= today`: create task for designated inspector
  - [ ] Task includes checklist form (auto-generated via schema)
  - [ ] On inspection completion: update `condition`, `lastInspectedAt`, `nextInspectionDue`
  - [ ] If condition = defect or missing: auto-create replacement order with SLA-based deadline
  - [ ] Integrate with procurement flow (inkoopflow module)

- [ ] **AED IoT Webhook Handler** (`AedIotWebhookHandler`)
  - [ ] Receive webhook from openconnector on device status change
  - [ ] Parse device state (operational, defect, low-battery, etc.)
  - [ ] Update corresponding `InventoryItem.condition` in real-time
  - [ ] If defect: send HIGH-priority SMS/email to safety officer
  - [ ] If backup AED configured: auto-designate as primary via status update
  - [ ] Auto-create replacement order with 7-day SLA
  - [ ] Log all webhook receives (timestamp, device ID, old/new state)

- [ ] **Alarm Dispatch & Response Logging** (`AlarmDispatchService`)
  - [ ] Receive alarm trigger from manual app action, IoT webhook, or BMS integration
  - [ ] Create `AlarmEvent` record with `triggeredBy`, `triggeredAt`, `alarmType`
  - [ ] Query all on-call `BhvMember` records (today's `BhvSchedule` assignments + 24/7 opt-in members)
  - [ ] Send push notification (mobile), SMS (if tier-1 on-call), Slack (BHV channel) with location + type
  - [ ] Track response timeline: append to `AlarmEvent.responseLog` as members accept/act
  - [ ] If >2 min without acceptance: auto-escalate to safety officer + alternate contacts
  - [ ] On alarm close: auto-generate incident report template

- [ ] **Compliance Reporting Service** (`ComplianceReportService`)
  - [ ] Implement SZW audit export: query Location, BhvSchedule (rolling 12mo), Certification, Drill, InventoryItem, AlarmEvent
  - [ ] Calculate per-location metrics: coverage %, drill compliance, cert compliance
  - [ ] Generate PDF report (via template engine) + JSON data package
  - [ ] Digitally sign report (per `app-manifest` ADR)
  - [ ] Store in `docudesk` with 7+ year retention policy
  - [ ] Implement annual board report: trend graphs, benchmarking (via `CnChartWidget`)

- [ ] **Offboarding Alert Service** (`OffboardingAlertService`)
  - [ ] Receive offboarding event from `employee-master` integration
  - [ ] Query if departing employee is BhvMember with hoofd_bhv role
  - [ ] If yes: identify locations where they are sole hoofd_bhv
  - [ ] Create HIGH-priority action: "Appoint new Hoofd-BHV for Location X"
  - [ ] Suggest up-skilling candidates from roster
  - [ ] Link to training-opleidingen hoofd-BHV course proposal

- [ ] **Deduplication Check** (ADR-001 requirement)
  - [ ] Search `openregister/lib/Service/` for ObjectService, RegisterService, SchemaService, ConfigurationService coverage
  - [ ] Verify no custom ORM code needed (use platform services only)
  - [ ] Verify no custom notification handlers (use NotificationService)
  - [ ] Verify no custom webhook logic (use WebhookService + openconnector integration)
  - [ ] Document findings in design.md "Reuse Analysis" section (already done)

## Phase 3: Frontend UI & Components

- [ ] **Location List & Detail Page** (`CnDetailPage` + `CnIndexPage`)
  - [ ] List view: all Locations with safety officer, current coverage %, actions menu
  - [ ] Detail view: location info, assigned BhvMembers roster, upcoming schedules, inventory, drills, incidents
  - [ ] Use schema-driven `CnFormDialog` for create/edit location
  - [ ] Add fields: code, name, address, GPS, maxOccupancy, floors, safety officer assignment

- [ ] **BhvMember Management**
  - [ ] List view: all BhvMembers with roles, availability summary, certification status
  - [ ] Detail view: member profile, assigned locations, all certifications with expiry warnings, consent toggles
  - [ ] Use `CnFormDialog` for create/edit with role checkboxes, availability pattern editor
  - [ ] Availability pattern editor: recurring (day + time slots) + exceptions (date + override)

- [ ] **Certification Management**
  - [ ] List view: all certifications filtered by status (valid, expiring, expired)
  - [ ] Upload dialog: drag-drop PDF/image with OCR result preview + manual confirmation UI
  - [ ] Detail view: cert details, document preview, expiry timeline, training recommendation link

- [ ] **Roster Scheduler UI** (`CnAdvancedFormDialog` + custom scheduler widget)
  - [ ] Weekly grid view: locations (rows) × days (columns) × time slots
  - [ ] Each cell shows required count vs assigned count, color-coded (groen/geel/rood)
  - [ ] Click cell to open assign-members dialog with available candidates filtered by availability + certification
  - [ ] Drag-drop to reassign across days/locations
  - [ ] "Generate Proposal" button: runs algorithm, shows draft for approval
  - [ ] "Publish" button: locks roster and sends notifications to assigned members
  - [ ] Sick leave workflow: mark member absent, trigger re-assignment suggestion

- [ ] **Ontruimingsplan Library**
  - [ ] List view: all plans per location with version, upload date, member confirmation status
  - [ ] Upload interface: drop PDF, set effective date, auto-notify assigned members
  - [ ] Confirmation tracking: show % members confirmed, list of who hasn't confirmed yet
  - [ ] QR code generator: create short URL + static QR image per location
  - [ ] Public QR landing page: responsive, offline-capable, shows floor map + exits + nearest EHBO/AED

- [ ] **Drill Management**
  - [ ] Create drill: location, type, date, participants list, scenario
  - [ ] Pre-announcement notification (24h before for announced drills)
  - [ ] Execution form: record evacuation time, participant count, incidents
  - [ ] Evaluation template form: auto-generated from schema with sections
  - [ ] Evaluation storage: save to `docudesk`, link to `Drill.evaluationDocumentId`
  - [ ] Compliance dashboard: annual drill status per location (red if missing)

- [ ] **Inventory Checklist & Inspection**
  - [ ] List view: all inventory items per location with condition, last inspection, next due
  - [ ] Inspection task widget: shows due items with checklist form
  - [ ] Checklist form: item details, condition dropdown, missing-items checkboxes, photo upload
  - [ ] Replacement order workflow: auto-propose with SLA deadline based on criticality
  - [ ] Real-time AED status: show operational/defect badge, link to device last-reported timestamp

- [ ] **Alarm Dashboard** (safety officer view)
  - [ ] Active alarms: large cards showing location, type, trigger time, response timeline
  - [ ] Responder map: real-time location of on-call members accepting alarm (if location-sharing consent given)
  - [ ] Response log: append-only timeline of actions (notifications sent, members accepted, evacuation initiated, all-clear)
  - [ ] Close alarm button: triggers incident report generation
  - [ ] Historical alarms: searchable archive with incident report links

- [ ] **Mobile App (BHV Member View)**
  - [ ] Authentication: IRMA/DigiD login (via `irma-digid-auth`)
  - [ ] Home screen: next 7 shifts, my certifications, my locations
  - [ ] Shifts detail: expand to see location, time, co-assigned members, required role
  - [ ] Certifications: list with expiry countdown, link to training-opleidingen
  - [ ] Locations quick view: safety officer contact, evacuation plan link, nearest EHBO/AED
  - [ ] Alarm notification: push + modal with location, type, accept button
  - [ ] Alarm acceptance: start location sharing (if consent) + open response feed
  - [ ] QR scanner: integrated into app, resolves room-level evacuation plan (public URL)
  - [ ] Privacy settings: toggle location sharing, mobile contact sharing

- [ ] **Compliance Reporting Dashboard**
  - [ ] Export interface: buttons for SZW audit package, annual board report
  - [ ] Metrics dashboard: coverage %, cert %, drill compliance, avg response time (via `CnChartWidget`)
  - [ ] Per-location drilldown: trend graphs, incident timeline, certification status
  - [ ] Audit trail: searchable log of all exports + downloads

## Phase 4: Integration & External Services

- [ ] **employee-master Integration**
  - [ ] Sync BhvMember name, location assignments on employee updates
  - [ ] Offboarding trigger: receive event → update BhvMember status, flag hoofd-BHV vacancy
  - [ ] employeeId reference: validate foreign key on BhvMember create/update

- [ ] **training-opleidingen Integration** (awaits module availability)
  - [ ] Send training proposal on certificate expiry 90-day alert
  - [ ] Create provisional enrollment on member acceptance
  - [ ] Receive training completion event → create new Certification record
  - [ ] Link to bijscholing planning in training module

- [ ] **verlof-administratie Integration**
  - [ ] Query approved leave periods on roster generation
  - [ ] Exclude BhvMembers with active verlof from scheduler candidates
  - [ ] Recalculate roster if new verlof approved within current week

- [ ] **docudesk Integration**
  - [ ] Store ontruimingsplannen with version control
  - [ ] Store drill evaluation reports + incident reports
  - [ ] Store SZW compliance export packages with 7+ year retention
  - [ ] Retrieve documents for QR-code landing page + member confirmation UI

- [ ] **openconnector Integration**
  - [ ] Webhook receiver for AED IoT status changes (device ID → InventoryItem.iotDeviceId mapping)
  - [ ] Webhook receiver for brandmeldcentrale alarm triggers
  - [ ] Webhook receiver for BMS building occupancy updates
  - [ ] Outbound webhook on alarm close (notify BMS system)
  - [ ] Test webhook with mock device payloads

- [ ] **mydash Integration**
  - [ ] Coverage KPI widget: (assigned / required) × 100, per-location breakdown
  - [ ] Incident count widget: link to incident archive
  - [ ] Drill compliance widget: red flag if location missing annual drill
  - [ ] Average response time widget: mean + trend

- [ ] **opencatalogi Integration** (optional, if applicable)
  - [ ] Publish evacuation plan excerpts for public facilities (libraries, town halls)
  - [ ] Anonymize sensitive data (e.g., remove employee names, keep only exit routes + assembly point)

- [ ] **irma-digid-auth Integration** (mobile app)
  - [ ] Mobile login via Yivi/DigiD
  - [ ] Step-up authentication for sensitive actions (alarm activation, personal data edits)
  - [ ] Session lifecycle: auto-logout after 15 min inactivity

## Phase 5: Testing & Compliance

- [ ] **Unit Tests**
  - [ ] Coverage calculation algorithm (formula accuracy, edge cases)
  - [ ] Roster fairness metric (variance, role distribution)
  - [ ] Certification status transitions (expiry logic, schedule recalculation)
  - [ ] Schema validation (enum constraints, required fields, cross-entity refs)
  - [ ] Target: >90% code coverage

- [ ] **Integration Tests**
  - [ ] End-to-end roster generation → publication → notification
  - [ ] Expiry alert → training proposal → enrollment feedback loop
  - [ ] Alarm trigger → notification → response logging → incident report
  - [ ] Offboarding → hoofd-BHV vacancy alert → training suggestion
  - [ ] Test with mock data from seed dataset

- [ ] **Functional Testing** (QA team or persona testers)
  - [ ] Safety officer: daily coverage check, send roster notifications, handle sick leave
  - [ ] BhvMember: view shifts, see certifications, respond to alarms, update consent
  - [ ] Facility manager: manage inventory, schedule inspections, upload ontruimingsplan
  - [ ] Ploegleider: execute drill, record evaluation, log alarm response
  - [ ] Test with persona testers: Fatima (low-literacy), Annemarie (architect), Sem (digital native)

- [ ] **Security Testing**
  - [ ] Authorization: safety officer can only edit their own location(s), members see only own data
  - [ ] Audit trail: all changes tracked (who, what, when)
  - [ ] Consent enforcement: location sharing only if member consented, revocation immediate
  - [ ] File security: OCR-uploaded certificates scanned for malware, access logged
  - [ ] Mobile auth: IRMA/DigiD step-up for sensitive actions

- [ ] **Compliance Validation**
  - [ ] Arbowet art. 15 minimum enforcement: formula correctly applied
  - [ ] Arbobesluit 2.5b–2.5g requirements met (checklist)
  - [ ] NEN 4000 / NEN 8112 reference compliance
  - [ ] AVG consent flow: explicit opt-in, revocation, GDPR deletion workflow
  - [ ] WCAG 2.2 AA: evacuation plan QR landing page accessible (keyboard nav, color contrast, screen reader)
  - [ ] NEN-EN 7510: if applied to healthcare BHV, verify data security controls

- [ ] **Performance Testing**
  - [ ] QR landing page loads in <1s on 4G (monitored via WebPageTest or Lighthouse)
  - [ ] Roster generation algorithm completes in <5s for 500+ BhvMembers, 100+ locations
  - [ ] Daily batch jobs finish within 1-hour window (06:00 coverage + 07:00 alerts + 08:00 compliance checks)
  - [ ] Mobile app alarm notification delivery <2s from trigger

## Phase 6: Documentation & Rollout

- [ ] **User Documentation**
  - [ ] Safety officer handbook: roster management, coverage alerts, incident response, compliance export
  - [ ] BhvMember guide: shift assignment, certification tracking, mobile app, alarm response
  - [ ] Facility manager guide: inventory management, inspection workflow, ontruimingsplan versioning
  - [ ] Administrator guide: configuration (risk multipliers, inspection schedules, roles), backup/restore

- [ ] **API Documentation**
  - [ ] OpenAPI spec for all endpoints (auto-generated from code)
  - [ ] Example webhook payloads (AED status, alarm trigger, offboarding)
  - [ ] Integration guides: training-opleidingen, verlof-administratie, openconnector

- [ ] **Training Materials**
  - [ ] Video walkthrough: daily coverage check, roster publication
  - [ ] Video: evacuation plan QR access + on-site safety info
  - [ ] Webinar: compliance export + SZW audit preparation

- [ ] **Rollout Plan**
  - [ ] Beta pilot: 1 location (Amsterdam HQ) with 1 safety officer + 20 BhvMembers for 2 weeks
  - [ ] Gather feedback: coverage algorithm accuracy, notification UX, roster usability
  - [ ] Address issues in iterative updates
  - [ ] Full rollout: all locations, cascade training to safety officers

- [ ] **Monitoring & Observability**
  - [ ] Log all batch job runs with execution time, errors, item counts
  - [ ] Dashboard: job status (daily coverage check, expiry alerts, compliance checks)
  - [ ] Alert on failures: if coverage batch fails, notify admin + fallback manual escalation
  - [ ] Track mobile app crashes / platform errors via Sentry or similar

## Phase 7: Post-Launch Support

- [ ] **SZW Audit Readiness**
  - [ ] Full export package generated on first request
  - [ ] Verify all required fields present (coverage %, drills, certs, incidents)
  - [ ] Partner with external auditor to validate compliance package structure

- [ ] **Ongoing Maintenance**
  - [ ] Monitor IoT device integrations: AED webhook reliability, BMS occupancy data accuracy
  - [ ] Collect feedback: quarterly survey of safety officers + BhvMembers
  - [ ] Roadmap items: mobile app enhancements, mobile call-tree integration, video evacuation tutorials

---

## Summary

**Total tasks**: ~120 checkboxes across 7 phases  
**Est. effort**: ~8–12 developer-months (backend 40%, frontend 35%, integration 15%, testing 10%)  
**Key dependencies**: training-opleidingen module, openconnector AED/BMS setup, employee-master offboarding events  
**Risk**: AED IoT reliability, occupancy source accuracy, Arbowet regulatory changes
