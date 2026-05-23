# WNT Disclosure - Implementation Tasks

## Phase 1: Data Model & Backend Infrastructure

### Database & Schema

- [ ] Create migration: `wnt_topfunctionaris_aanwijzing` table with fields (employee_id FK, functie_naam, aanvangsdatum, einddatum, aanwijzings_grond enum, wnt_norm_toepasselijk enum, fictieve_dienstbetrekking boolean, bezoldigings_grondslag enum, uitsterf_constructie_vlag boolean, audit fields)
- [ ] Create migration: `wnt_bezoldiging_component` table with fields (wnt_topfunctionaris_aanwijzing_id FK, kalenderjaar, component_type enum, bedrag_eur, bron_administratie enum, bron_id, wnt_meetelt_vlag boolean, audit fields)
- [ ] Create migration: `wnt_jaar_rapportage` table with fields (wnt_topfunctionaris_aanwijzing_id FK, kalenderjaar, totaal_bezoldiging_wnt, totaal_bezoldiging_fiscaal, wnt_norm_bedrag, overschrijdings_bedrag, reden_overschrijding, terugvordering_vereist_vlag, terugvordering_bedrag, terugvordering_status enum, publicatie_status enum, audit fields)
- [ ] Create migration: `wnt_ontslagvergoeding` table with fields (wnt_topfunctionaris_aanwijzing_id FK, uitkerings_datum, totaal_bedrag, jaarsalaris_op_uitkeringsdatum, wnt_plafond_bedrag, bedrag_binnen_plafond, bedrag_boven_plafond, reden_uitkering enum, samenstelling_json, audit fields)
- [ ] Create migration: `wnt_klasse_indeling` table with fields (organisation_id FK, kalenderjaar, ingedeelde_klasse enum A–G, klasse_bepalende_factoren_json, wnt_norm_bedrag, indeling_vastgesteld_door, indeling_datum, audit fields)
- [ ] Create migration: `wnt_publicatie_versie` table with fields (organisation_id FK, kalenderjaar, versie_nummer, gegenereerd_op, gegenereerd_door, document_id FK, accountantsverklaring_id, status enum, publicatie_url, vervangen_door_versie_id FK, audit fields)
- [ ] Create migration: `wnt_access_audit` table with fields (requester_id, timestamp, action enum, scope, export_file_id, details_json)
- [ ] Create indexes on common query paths: (organisation_id, kalenderjaar), (wnt_topfunctionaris_aanwijzing_id, kalenderjaar), (terugvordering_status)
- [ ] Add unique constraints: wnt_topfunctionaris_aanwijzing(organisation_id, employee_id, aanvangsdatum), wnt_jaar_rapportage(wnt_topfunctionaris_aanwijzing_id, kalenderjaar), wnt_klasse_indeling(organisation_id, kalenderjaar)

### PHP Service Layer

- [ ] Create `WntTopfunctionarisService` with methods:
  - `createDesignation($employee_id, $functie_naam, $aanvangsdatum, $wnt_norm, ...)` → returns designationId
  - `updateDesignation($designationId, $updates)` → updates record; re-aggregates affected years
  - `getDesignation($designationId)` → fetch one
  - `listDesignations($organisationId, $filters)` → fetch by org/function/tenure
  - `markAsInterim($designationId, $fictieve_flag)` → toggle interim mode
  - `applyExemption($designationId, $reden_overschrijding)` → set uitsterf/waiver

- [ ] Create `WntBezoldigingAggregationService` with methods:
  - `aggregateYear($wnt_designation_id, $year)` → fetch all components, sum with wnt_meetelt_vlag=true, store in wnt_jaar_rapportage
  - `getYtdCompensation($wnt_designation_id, $currentDate)` → sum components Jan–current-month
  - `projectYearEndCompensation($wnt_designation_id, $currentDate)` → extrapolate YTD to year-end
  - `reconcilePayrollComponents($payrollRunId)` → bulk-ingest payroll components for a run
  - `recordManualComponent($wnt_designation_id, $componentType, $amount, $year)` → manual natura entry

- [ ] Create `WntNormCalculationService` with methods:
  - `getNormForDesignation($wnt_designation_id, $year)` → returns applicable norm (norm-1 or norm-2-classX or interim-norm)
  - `calculateProRataNorm($norm_bedrag, $aanvangsdatum, $einddatum, $year)` → pro-rata by tenure
  - `calculateInterimNorm($monthsOfService, $monthlyRate, $year)` → apply declining tier for months 7+
  - `detectInterim12MonthTransition($wnt_designation_id, $currentDate)` → check if 12+ months elapsed; auto-escalate

- [ ] Create `WntOverspendDetectionService` with methods:
  - `detectMonthlyOverspend($wnt_designation_id, $currentMonth)` → YTD-vs-norm check; trigger alert if over
  - `projectMonthlyOverspend($wnt_designation_id, $currentMonth)` → extrapolate to year-end; threshold alert at >5% projected overspend
  - `recordRecovery($wnt_designation_id, $amount, $reason)` → create or update wnt_terugvordering record

- [ ] Create `WntSeveranceService` with methods:
  - `recordSeverancePayment($wnt_designation_id, $totalAmount, $jaarsalaris, $components_json)` → calc plafond; detect overage; create recovery
  - `getSeveranceDetails($wnt_designation_id)` → fetch all severance records

- [ ] Create `WntKlasseService` with methods:
  - `registerClassAssignment($organisation_id, $year, $klasse, $factors_json, $decision_ref)` → create/update klasse_indeling
  - `getApplicableNorm($organisation_id, $year)` → fetch norm for org's class
  - `notifyClassChangeForNextYear($organisation_id, $newFactors)` → propose revision to next year's class

- [ ] Create `WntReportGenerationService` with methods:
  - `generateAnnualReport($organisation_id, $year)` → aggregate all executives for year; render PDF via document-template-engine; save version 1 in wnt_publicatie_versie
  - `approveReport($wnt_publicatie_versie_id, $rvbDecisionRef)` → freeze PDF (document_id locked); change status to door_rvb_vastgesteld
  - `reviseReport($wnt_publicatie_versie_id)` → increment versie_nummer; regenerate PDF; mark old as superseded
  - `exportForAuditor($wnt_publicatie_versie_id)` → ZIP: report + component detail + payroll manifests + checksum

- [ ] Create `WntRecoveryManagementService` with methods:
  - `getOutstandingRecoveries($organisation_id, $dueSoonThreshold=30days)` → fetch upcoming deadline warnings
  - `escalateExpiredRecovery($wnt_terugvordering_id)` → change status to oninbaar/te_melden; create hard-blocker on next year report
  - `markRecoveryVoldaan($wnt_terugvordering_id, $evidence_doc_id)` → close recovery record; log completion

- [ ] Create `WntAuditLogService` with methods:
  - `logAccess($requester_id, $action, $scope, $details)` → write to wnt_access_audit
  - `getAuditLog($organisation_id, $filters)` → fetch audit entries for compliance review

### Integration with Upstream Systems

- [ ] Implement payroll-engine-nl integration:
  - Consume completed payroll run events; bulk-insert components into wnt_bezoldiging_component with bron_id=run-id
  - Query payroll-engine API for run details (employees, amounts, run-status)
  - Validate components: check wnt-relevant flag; exclude non-WNT items
  
- [ ] Implement employee-master integration:
  - Query employee by id; fetch name, function, employment status
  - Add `is_topfunctionaris` flag to employee record (tracks designation)
  - Listen for employee-deletion events; mark related wnt_topfunctionaris_aanwijzing as archived (soft-delete)

- [ ] Implement voorzieningen-administratie integration:
  - Query pension-premie and levensloop-toedelingen for employee+year
  - Bulk-insert as wnt_bezoldiging_component with bron_administratie=voorzieningen_administratie

- [ ] Implement document-template-engine integration:
  - Call render endpoint with WNT-specific template + data (executives, components, norms, overspends)
  - Receive PDF blob; store in document-store; reference via document_id in wnt_publicatie_versie

---

## Phase 2: API Endpoints & Frontend Scaffolding

### API Endpoints (REST/JSON)

- [ ] `POST /api/wnt/executives` — create designation
- [ ] `GET /api/wnt/executives/{id}` — fetch one
- [ ] `GET /api/wnt/executives?organisation_id=X&filters=...` — list with filters
- [ ] `PATCH /api/wnt/executives/{id}` — update designation
- [ ] `POST /api/wnt/executives/{id}/interim-mode` — toggle interim flag
- [ ] `POST /api/wnt/executives/{id}/exemptions` — set uitsterf/waiver
- [ ] `GET /api/wnt/compensation/{wnt_id}/{year}` — fetch aggregated total + components
- [ ] `POST /api/wnt/compensation/manual-entry` — record natura/manual component
- [ ] `GET /api/wnt/reports/dashboard` — YTD summary; overspend warnings; recovery deadlines
- [ ] `GET /api/wnt/reports/prognosis/{wnt_id}` — projected year-end compensation
- [ ] `POST /api/wnt/reports/annual/{year}` — trigger annual report generation
- [ ] `GET /api/wnt/reports/versions/{organisation_id}/{year}` — fetch all versions for a year
- [ ] `POST /api/wnt/reports/{version_id}/approve` — RvB approval workflow
- [ ] `POST /api/wnt/reports/{version_id}/revise` — create new version
- [ ] `POST /api/wnt/exports/audit` — generate ZIP for auditor
- [ ] `POST /api/wnt/exports/multi-year-trend` — XLSX trend export
- [ ] `GET /api/wnt/recoveries/{organisation_id}` — list outstanding recoveries
- [ ] `PATCH /api/wnt/recoveries/{recovery_id}/status` — update recovery status (e.g., voldaan)
- [ ] `GET /api/wnt/audit-log` — fetch access audit entries (auditors/regulators only)
- [ ] `GET /api/wnt/klasse/{organisation_id}/{year}` — fetch class assignment
- [ ] `POST /api/wnt/klasse` — register/update class assignment

### Vue.js Frontend Components

- [ ] `WntExecutiveRegistry.vue` — list/search executives; create/edit designations; mark interim/exemptions
- [ ] `WntExecutiveDetail.vue` — view one executive; compensation components; tenure timeline; pro-rata calc preview
- [ ] `WntCompensationDashboard.vue` — YTD per executive; progress bar toward norm; overspend warnings; recovery status
- [ ] `WntAnnualReportGenerator.vue` — trigger report gen; preview PDF; approve/publish workflow; versioning
- [ ] `WntRecoveryTracker.vue` — list outstanding recoveries; deadlines; escalation workflow; completion evidence upload
- [ ] `WntAuditExport.vue` — export wizard; ZIP download; access logging
- [ ] `WntKlasseAssignment.vue` — register/update education/healthcare class; store RvB decision ref
- [ ] `WntSeveranceForm.vue` — record severance payment; detect overage; component breakdown entry

### Notification / Alerting

- [ ] Implement monthly-overspend alert template (email + in-app notification):
  - Subject: "WNT Prognosis Alert: [Executive Name] may exceed EUR [norm] in 2026"
  - Recipients: controller, HR-directeur
  - Include: projected year-end amount, days remaining, recovery options

- [ ] Implement recovery-deadline-warning template (monthly warning, then quarterly, then escalation):
  - 30-day pre-deadline: "Recovery reminder: EUR [X] due by [date]"
  - 3-day pre-deadline: "URGENT: Recovery due in 3 days"
  - Post-deadline: "Recovery ESCALATED to unrecoverable status. RvB approval required."

- [ ] Implement interim-12-month-transition alert:
  - "Interim [Name] has reached 12-month tenure. WNT norm has automatically changed to [new-norm]. Please review."

### Role-Based Access Control

- [ ] Define role `wnt-admin`: full CRUD on all WNT records
- [ ] Define role `wnt-manager`: view all WNT data; create/update designations + components; approve/publish reports
- [ ] Define role `wnt-auditor`: read-only access to WNT dashboard, export, audit-log
- [ ] Define role `wnt-auditor-adr`: read-only + sector-specific regulatory dashboards
- [ ] Configure views to respect roles: e.g., auditor role hides edit buttons; non-auditor role hides export/audit-log

---

## Phase 3: Annual Workflow & Reporting

### Year-End Close & Report Generation

- [ ] Implement cron: `wnt-year-end-close` (trigger on 2026-12-31 00:00 UTC + org timezone offset)
  - For each organisation with WNT module enabled:
    - Finalize all payroll runs for the year
    - Aggregate compensation for all designations active in the year (incl. those ended mid-year with pro-rata)
    - Create wnt_jaar_rapportage records for each designation
    - Identify overspends; create terugvordering records with deadline = year-end-next-year
    - Generate annual report in concept status
    - Send controller alert: "2026 WNT annual close complete. [N] executives, [M] overspends. Report ready for review."

- [ ] Implement cron: `wnt-klasse-update-preview` (trigger 2025-11-01, before BZK publishes norms)
  - Fetch BZK-published norms for next year
  - Update wnt_klasse_indeling records for next year (if not yet formally assigned)
  - Flag organisations with healthcare/education for class re-assignment workflow

### Q1 Audit & Publication Cycle

- [ ] Implement workflow: Controller reviews annual report
  - Opens report in concept status
  - Verifies all executives, compensation totals, overspends
  - Flags any discrepancies for payroll/HR/manual entry correction
  - Once satisfied, submits to RvB

- [ ] Implement workflow: RvB formal approval
  - RvB votes/signs off on report (meeting record or e-signature)
  - Report status changes to door_rvb_vastgesteld; PDF frozen
  - Alert sent to jaarverslag-redacteur: "WNT report approved. Ready for inclusion in jaarverslag."

- [ ] Implement workflow: Accountant review (optional, external role)
  - Auditor assigned `wnt-auditor` role during control period
  - Auditor downloads export (ZIP with report + detail + source docs)
  - Auditor spots checks components; links to source documents
  - Auditor submits control findings; system logs all access

- [ ] Implement jaarverslag integration:
  - Fetch approved WNT report from wnt_publicatie_versie
  - Embed PDF as appendix in jaarverslag
  - Publish to organisation website

### SBR & Regulatory Export

- [ ] Implement SBR-Digipoort integration:
  - Transform approved WNT report to XBRL-extension format (WNT-specific taxonomy, BZK-standard namespaces)
  - Call Digipoort API to submit jaarverslag including WNT section
  - Log submission; store receipt/confirmation

---

## Phase 4: Ongoing Monitoring & Escalation

### Monthly Monitoring Cron Jobs

- [ ] Implement `wnt-monthly-aggregation` cron (run on 1st of month at 02:00 UTC):
  - For each organisation: pull latest payroll run
  - Aggregate YTD compensation for all active executives
  - Calculate prognosis (YTD / months-elapsed * 12)
  - Identify overspends (prognosis > norm); trigger overspend alerts
  - Log aggregation event (timestamp, dataset-checksum)

- [ ] Implement `wnt-recovery-deadline-watch` cron (run quarterly: Feb 1, May 1, Aug 1, Nov 1):
  - For each organisation: fetch outstanding recoveries
  - Identify those with deadline within 30 days
  - Send controller reminder: "EUR [X] recovery due by [date]"

- [ ] Implement `wnt-recovery-escalation` cron (run on 2028-01-01 at 00:00 UTC):
  - For each organisation: fetch recoveries with deadline < today
  - Update status to oninbaar (or te_melden_aan_toezicht if regulatory reporting required)
  - Create hard-blocker on next year's WNT report
  - Alert RvB & controller: "Unresolved recovery from [year] has escalated. Report freeze activated."

### Interim Transition Monitoring

- [ ] Implement `wnt-interim-12month-check` cron (run daily):
  - For each executive with fictieve_dienstbetrekking=true and no einddatum:
    - Calculate tenure from aanvangsdatum
    - If tenure >= 12 months AND current norm is interim-norm:
      - Auto-update wnt_norm_toepasselijk to standard (norm-1 or norm-2-classX)
      - Create alert: "[Name] interim tenure reached 12 months. Norm updated to [new-norm]."
      - Log transition event

---

## Phase 5: Testing & Compliance Validation

### Unit Tests

- [ ] Test `WntNormCalculationService`:
  - Pro-rata norm for partial-year tenure (9 of 12 months)
  - Interim-norm application (months 1–6 vs. 7+)
  - Exemption (uitsterf) suppression of alerts

- [ ] Test `WntBezoldigingAggregationService`:
  - Aggregate multiple components; sum only wnt_meetelt_vlag=true
  - Exclude non-WNT items (reiskosten, kinderopvang)
  - Recognize backdated bonuses to prior year; trigger re-aggregation

- [ ] Test `WntOverspendDetectionService`:
  - Monthly prognosis calculation (YTD / months-elapsed * 12)
  - Threshold detection (alert if prognosis > norm)
  - Recovery record creation on confirmed overspend

- [ ] Test `WntSeveranceService`:
  - Plafond calculation: min(75k, 1×salary)
  - Component breakdown allocation
  - Overage detection

### Integration Tests

- [ ] Test payroll-engine-nl integration:
  - Simulate payroll run completion event
  - Verify components are fetched and inserted into wnt_bezoldiging_component
  - Test retry logic if integration temporarily unavailable

- [ ] Test employee-master integration:
  - Create executive designation linked to employee
  - Verify employee marked is_topfunctionaris=true
  - Delete employee; verify designation archived (soft-delete)

- [ ] Test document-template-engine integration:
  - Generate annual report
  - Verify PDF is created, stored in document-store, referenced in wnt_publicatie_versie
  - Test with 5–50 executives; verify PDF readability

### Compliance & Regulatory Tests

- [ ] Verify annual report format conforms to Uitvoeringsregeling WNT 2026:
  - All required fields present per Modeltekst (executive name, function, tenure, compensation, norm, overspend reason)
  - Table structure matches template

- [ ] Verify recovery tracking adheres to WNT law:
  - Deadline enforcement (must recover by year-end following breach year)
  - Escalation triggers (auto-escalate to unrecoverable if missed)

- [ ] Verify audit trail covers all mutations:
  - All create/update/delete operations logged with user-id, timestamp, change-delta
  - Read operations logged only for auditor-role (per `wnt-access-audit`)

- [ ] Verify multi-tenancy isolation:
  - No data leakage between organisations
  - All queries implicitly filter by organisation_id

### Performance & Load Tests

- [ ] Measure annual report generation time (10, 50, 100+ executives):
  - Target: < 5 seconds for 50 executives
  - Identify bottlenecks (template rendering, PDF compression, etc.)

- [ ] Measure aggregation time for YTD compensation:
  - Target: < 500ms for retrieval + prognosis calculation

---

## Phase 6: Documentation & Rollout

### User Documentation

- [ ] Write user guide: "WNT Disclosure Module — Quick Start"
  - Designate an executive (step-by-step)
  - Monitor YTD compensation (dashboard walkthrough)
  - Generate annual report (workflow)
  - Export for auditor

- [ ] Write admin guide: "WNT Configuration & Multi-Tenancy"
  - Enable WNT module per organisation
  - Configure BZK norm-bedrag table (annual update)
  - Set up alerting recipients (controller, HR-directeur)
  - Multi-administratie scoping

- [ ] Write developer guide: "WNT API & Integration"
  - Overview of wnt-* services and endpoints
  - Payroll-engine integration specifics
  - Document-template customization (if needed)

### Release Notes & Communication

- [ ] Draft release notes: WNT-disclosure v1.0
  - Feature summary, known limitations, regulatory compliance statement
  - Migration path for existing customers (if any legacy WNT data exists)

- [ ] Draft communication to target users (controllers, HR directors):
  - Availability announcement
  - Key benefits (real-time monitoring, automated reporting, audit readiness)
  - Link to user guide; support contact

### Deployment & Rollout

- [ ] Run database migrations in staging
- [ ] Run integration tests against staging payroll-engine-nl, document-template-engine
- [ ] Conduct UAT with pilot customer (1–2 organisations)
  - Designate executives, generate report, verify annual close workflow
  - Collect feedback; iterate if needed
- [ ] Deploy to production (blue-green or canary)
- [ ] Monitor first week: alert queue, error rates, database performance
- [ ] Rollout to remaining customers (batch by sector: healthcare, education, municipalities, etc.)

---

## Phase 7: Post-Launch Support & Enhancement

### Monitoring & Operations

- [ ] Set up alerting for WNT-specific errors:
  - Payroll-engine integration failures (retry logic, fallback)
  - Document-template rendering errors
  - Recovery deadline escalation failures
  - Audit-log write failures (audit trail integrity)

- [ ] Set up dashboard for operations team:
  - % of organisations with active WNT module
  - Number of active executives by sector
  - Average report generation time
  - Audit export usage (number per month)

### Enhancement Backlog

- [ ] Support for multi-currency compensation (future: if expanding to Belgium, Germany, etc.)
- [ ] Advanced exemption workflows (minister waivers, detailed case tracking)
- [ ] BI/Analytics: WNT trends across all organisations (anonymized)
- [ ] AI-assisted anomaly detection: flag unusual compensation patterns for controller review
- [ ] Regulatory reporting automation: auto-submit to BZK topinkomensregister.nl
