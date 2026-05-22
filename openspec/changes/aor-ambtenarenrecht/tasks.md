---
status: tasks
---

# AOR Ambtenarenrecht — Implementation Tasks

## Deduplication Check

- [x] OpenRegister services: ObjectService (CRUD), RelationService (linking), IndexService (search), NotificationService (alerts), AuthorizationService (ACL), ArchivalService (retention) — all provided; NO custom duplication needed
- [x] Lifecycle management: OpenRegister `x-openregister-lifecycle` available (EmploymentCase, CaseStep, Klokkenluidermelding, Besluit transitions) per ADR-031
- [x] Notification dispatch: OpenRegister `x-openregister-notifications` available (case-state-change alerts, termijn reminders) per ADR-031
- [x] Calculations/Aggregations: OpenRegister `x-openregister-calculations` + `x-openregister-aggregations` available (termijn-countdown, case-counts) per ADR-031
- [x] Custom app-layer services identified: TermijnCalculationService, TransitionevergoedingCalculationService, UWVIntegrationService, DossierBundlingService, RetaliationCheckService, BesluittemplateService, EscalatietoCollageService, PayrollMutationService, AccessControlEnforcementService (NOT duplicating OR)
- [x] Compliance-level: Archival/retention handled via OpenRegister ArchivalService + ScheduledWorkflow (n8n), NOT custom app-level job

---

## Schema & Data Layer (ADR-001 Compliance)

### EmploymentCase Schema

- [ ] Define `EmploymentCase` schema in `lib/Settings/aor-ambtenarenrecht_register.json` with:
  - [ ] caseNumber (unique, pattern: `^[A-Z]{2}\d{4}-\d{5}$`)
  - [ ] employeeId (reference to employee-master)
  - [ ] caseType enum: ontslag|integriteit|tucht|disciplinair|escalatie|beroep
  - [ ] subType (string, workflow-variant selector)
  - [ ] status enum: concept|in_behandeling|besluitvorming|afgerond|ingetrokken
  - [ ] openedAt, closedAt (timestamps)
  - [ ] caseHandlerId (reference to user)
  - [ ] legalBasis[] (array of legal citations)
  - [ ] summary (max 500 chars)
  - [ ] confidentialityLevel enum: standaard|vertrouwelijk|geheim
  - [ ] accessControlList[] (role + userId tuples for ACL enforcement)
  - [ ] dossierFolderId (link to docudesk Files folder)
  - [ ] retentionClass (ontslag-75j-post-geboorte | integriteit-7j | tucht-10j-post-verwijdering | disciplinair-5j)
  - [ ] auditTrail[] (immutable state-transition log per ObjectService)

### CaseStep Schema

- [ ] Define `CaseStep` schema with:
  - [ ] caseId (reference to EmploymentCase)
  - [ ] stepCode (pattern: `^[A-Z]{3}-\d{3}$`, e.g. ONT-001)
  - [ ] name_nl, name_en (Dutch + English step names)
  - [ ] dueDate (date format)
  - [ ] completedAt (timestamp)
  - [ ] assigneeId (user reference)
  - [ ] outputDocumentId (docudesk document reference, optional)
  - [ ] slaCategory enum: termijn-wettelijk|termijn-operational|alert-T7|alert-T2|alert-T1
  - [ ] status enum: pending|in_progress|completed

### Besluit Schema

- [ ] Define `Besluit` schema with:
  - [ ] caseId (reference to EmploymentCase)
  - [ ] besluitType enum: ontslagbesluit|schorsing-besluit|loonstop-besluit|tuchtbesluit|disciplinair-besluit
  - [ ] bevoegdGezag (string, e.g. "HR-directeur" | "College van B&W")
  - [ ] signedById, signedAt
  - [ ] bezwaartermijn (integer days), bezwaarDeadline (auto-calculated date)
  - [ ] effectiveDate
  - [ ] documentId (docudesk PDF reference)
  - [ ] notificationLog[] (delivery records per Digitale Akte channel)

### Klokkenluidermelding Schema

- [ ] Define `Klokkenluidermelding` schema with:
  - [ ] caseId (optional reference to parent EmploymentCase)
  - [ ] melderType enum: intern|extern|anoniem
  - [ ] meldingChannel enum: afdelingshoofd|vertrouwenspersoon|HvK|toezichthouder
  - [ ] subject (disclosure category string)
  - [ ] summary (de-identified summary)
  - [ ] protectedUntil (auto-set to now + 7 years)
  - [ ] melder_identity (encrypted object: {fullName, email} — visible only to ≤2 persons per ACL)
  - [ ] retaliationCheckLog[] (array of {timestamp, hrAction, assessorId, verdict})
  - [ ] huisVoorKlokkenluidersRef (external tracking ID if escalated)

### Transitievergoeding Schema

- [ ] Define `Transitievergoeding` schema with:
  - [ ] caseId (reference to ontslag-type EmploymentCase)
  - [ ] salaryComponents (object with frozen salary snapshot)
  - [ ] serviceYears, ageAtTermination
  - [ ] formula (string description of calculation)
  - [ ] grossAmount, overheidsToeslag, bovenwettelijkeComponent, netEstimate (all numbers)
  - [ ] exclusionReason (string, e.g. "kleine-werkgever-uitzondering")
  - [ ] exclusionCitation (BW article reference)
  - [ ] paidAt (timestamp)
  - [ ] approverIds[] (two signatories if manual override)

### IntegrityRegister Schema

- [ ] Define `IntegrityRegister` schema with:
  - [ ] quarter (pattern: `^\d{4}-Q[1-4]$`)
  - [ ] meldingCount, externalEscalationCount, retaliationChecksTriggered, retaliationRisksIdentified (all integers)
  - [ ] meldingCountBySubject (object with category counts)
  - [ ] complianceNotes (string for bestuur reporting)

### Seed Data in Register File

- [ ] Add 3-5 realistic EmploymentCase objects per `@self` envelope (zaak-ontslag, zaak-integriteit, zaak-escalatie)
- [ ] Add 3-5 realistic CaseStep objects (hoorzitting, werkgevers-onderbouwing, retaliatie-check, etc.)
- [ ] Add 3-5 realistic Besluit objects (ontslagbesluit, disciplinair-besluit, tuchtbesluit)
- [ ] Add 2-3 realistic Klokkenluidermelding objects (anoniem, intern via HvK, with retaliation-checks)
- [ ] Add 2-3 realistic Transitievergoeding objects (standard + with exclusion-reason)
- [ ] Verify all seed data cross-references valid (caseId ↔ EmploymentCase.id, etc.)
- [ ] Verify Dutch values realistic (street names, postcodes, municipality codes, BSNs passing 11-proef)

---

## Lifecycle & State Management (ADR-031 — Declarative)

### EmploymentCase Lifecycle

- [ ] Define `x-openregister-lifecycle` block in schema:
  - [ ] States: concept → in_behandeling → besluitvorming → afgerond | ingetrokken
  - [ ] Transitions: createCase → openCase (concept→in_behandeling) → finalizeBesluit (→besluitvorming) → executeDecision (→afgerond) | withdrawCase (→ingetrokken)
  - [ ] State-specific RBAC: only caseHandlerId can transition; bestuur-level cases require college-secretary approval on certain steps
  - [ ] Guards (via PHP classes in `lib/Lifecycle/`):
    - [ ] `CanOpenCaseGuard` — verify caseType + legalBasis valid, employeeId resolves
    - [ ] `CanTransitionToDecisionGuard` — verify all required CaseSteps completed
    - [ ] `CanAffirm AffordingGuard` — for escalated cases, college-decision must exist

### CaseStep Lifecycle

- [ ] Define simple `x-openregister-lifecycle` block:
  - [ ] States: pending → in_progress → completed
  - [ ] Transitions: assignStep (pending→in_progress) → completeStep (→completed)
  - [ ] Guards: only assigneeId can transition

### Klokkenluidermelding Lifecycle

- [ ] Define `x-openregister-lifecycle` block:
  - [ ] States: registered → under_assessment → escalated | closed
  - [ ] Transitions: registerMelding → assessMelding → escalateMelding (→escalated) | closeAssessment (→closed)
  - [ ] Guards: only vertrouwenspersoon + integriteitscoördinator can transition

### Besluit Lifecycle

- [ ] Define `x-openregister-lifecycle` block:
  - [ ] States: concept → signed → notified → active | bezwaar_opened | beroep_opened
  - [ ] Transitions: draftBesluit → signBesluit → notifyEmployee (→notified) → [monitor for bezwaar/beroep]
  - [ ] Guards: only bevoegdGezag (HR-directeur or college) can sign

---

## Notifications & Escalations (ADR-031 — Declarative)

### Case-State-Change Notifications

- [ ] Define `x-openregister-notifications` block on EmploymentCase:
  - [ ] On status → in_behandeling: notify caseHandlerId ("Case opened")
  - [ ] On status → besluitvorming: notify caseHandlerId + HR-director ("Decision preparation required")
  - [ ] On status → afgerond: notify HR-director + archival-coordinator ("Case ready for archival")
  - [ ] On status → ingetrokken: notify all involved parties ("Case withdrawn")
  - [ ] Recipient resolver: role-based routing (caseHandlerId lookup, HR-director group lookup)

### Termijn-Reminder Notifications

- [ ] Define `x-openregister-notifications` block on Besluit:
  - [ ] At bezwaarDeadline - 7 days: notify caseHandlerId (channel: in-app)
  - [ ] At bezwaarDeadline - 2 days: notify caseHandlerId + teamlead (channel: in-app + email)
  - [ ] At bezwaarDeadline - 1 day: notify caseHandlerId + teamlead + HR-director (channel: email + SMS)
  - [ ] At bezwaarDeadline: notify director + archival-coordinator (channel: email)

### Klokkenluider Escalations

- [ ] Define `x-openregister-notifications` on Klokkenluidermelding:
  - [ ] On retaliationCheckLog entry created: notify integriteitscoördinator ("Retaliation risk assessment required")
  - [ ] On escalation → escalated: notify Huis voor Klokkenluiders (external email via openconnector)

---

## Calculations & Aggregations (ADR-031 — Declarative)

### Derived Fields (x-openregister-calculations)

- [ ] On EmploymentCase:
  - [ ] `daysUntilDueDate`: calculate from latest CaseStep.dueDate
  - [ ] `riskStatus`: enum based on termijn proximity (safe | warning | critical | expired)
  - [ ] `isEscalationEligible`: boolean per bestuur-escalation rules (role-level | €50k+ threshold | integrity-link)

- [ ] On Klokkenluidermelding:
  - [ ] `isProtected`: boolean = now < protectedUntil
  - [ ] `daysSinceMelding`: calculate from createdAt
  - [ ] `retaliationRiskLevel`: enum based on retaliationCheckLog (none | low | high)

- [ ] On Transitievergoeding:
  - [ ] `totalAmount`: sum of grossAmount + overheidsToeslag + bovenwettelijkeComponent
  - [ ] `isOverridden`: boolean if approverIds[] non-empty

### Aggregations (x-openregister-aggregations)

- [ ] On EmploymentCase (for mydash KPI widgets):
  - [ ] `countByType`: count-by-caseType (ontslag | integriteit | tucht | disciplinair | escalatie | beroep)
  - [ ] `countByStatus`: count-by-status (concept | in_behandeling | besluitvorming | afgerond | ingetrokken)
  - [ ] `avgDaysToCompletion`: average elapsed days from openedAt to closedAt (per caseType)
  - [ ] `countEscalatedToCollege`: count where bestuur-escalation occurred

- [ ] On Klokkenluidermelding (for integriteitscoördinator reporting):
  - [ ] `countByYear`: annual count for trend-reporting to bestuur
  - [ ] `countBySubject`: count-by-subject-category (integriteitsschending | veiligheid | milieu | etc.)
  - [ ] `retaliationChecksTriggered`: count of retaliationCheckLog entries
  - [ ] `externalEscalations`: count where huisVoorKlokkenluidersRef is set

- [ ] On Besluit (for compliance dashboards):
  - [ ] `countByOutcome`: count of bezwaar-outcomes (toewijzing | afwijzing | not-filed)
  - [ ] `countByAppealOutcome`: count of CRvB-verdict outcomes (toewijzing | afwijzing)

---

## Custom Service Layer (ADR-003/022 Compliance)

### TermijnCalculationService

- [ ] Create `lib/Service/TermijnCalculationService.php`:
  - [ ] `calculateOpzegtermijn(caseType, legalBasis, employeeRole)`: returns days per BW art. 7:657
  - [ ] `calculateBezwaartermijn(besluitType, context)`: returns days per Awb §6:5 (default 6 weeks)
  - [ ] `calculateCRvBDeadline(zittingsdatum)`: returns verweerschrift deadline (zittingsdatum - 14d), nadere-stukken (zittingsdatum - 7d)
  - [ ] `calculateRetentionExpiry(caseType, dateReference)`: returns expiry-date per Selectielijsten
  - [ ] Unit-tests for all BW/Awb combinations

### TransitionevergoedingCalculationService

- [ ] Create `lib/Service/TransitionevergoedingCalculationService.php`:
  - [ ] `calculateSeverance(salarySnapshot, serviceYears, formula, cao)`: returns {grossAmount, bovenwettelijkeComponent, total}
  - [ ] `applyExclusionCheck(ageAtTermination, employerSize, contraBasis)`: detects kleine-werkgever or AOW-grens, returns exclusion-reason + citation
  - [ ] `applyLegalInterest(originalAmount, startDate, endDate)`: calculates 6% per-annum interest for back-pay scenarios
  - [ ] Integration-test with payroll-engine-nl payload-generation

### UWVIntegrationService

- [ ] Create `lib/Service/UWVIntegrationService.php`:
  - [ ] `sendFormuliereenset(caseId)`: via openconnector adapter, POST formulier A+B to UWV portal
  - [ ] `pollUWVDecision(caseId)`: periodic check for UWV-beslissing (bedrijfseconomisch / langdurige-arbeidsongeschiktheid)
  - [ ] `importUWVDecision(uwvResponse)`: parses UWV response, creates follow-up CaseStep
  - [ ] Background job: n8n workflow scheduled to poll UWV daily for pending cases

### DossierBundlingService

- [ ] Create `lib/Service/DossierBundlingService.php`:
  - [ ] `bundleProcesdossier(caseId, for: 'CRvB')`: retrieves docudesk documents, orders chronologically, redacts third-party names
  - [ ] `generateCRvBIndex()`: creates cover-sheet + indexed page-numbers per CRvB-instructies
  - [ ] `exportForArchief(caseId)`: generates RiC-format XML metadata + PDF package per Archiefwet
  - [ ] `anonimizeDocument(docId, rules)`: deterministic pseudonymisation (name → Melder M1, date → Q[year], BSN → deleted)

### RetaliationCheckService

- [ ] Create `lib/Service/RetaliationCheckService.php`:
  - [ ] `checkForRetaliationRisk(melderCaseId, newHRActionType)`: detects if new action falls within 24-month protection window
  - [ ] `createRetaliationCheckLog(melderCaseId, hrActionType)`: logs entry with auto-escalation to integriteitscoördinator
  - [ ] `assessRetaliationRisk(checkLogId, assessor, verdict)`: gates HR-action until assessed
  - [ ] Integration: triggers notification via `x-openregister-notifications`

### BesluittemplateService

- [ ] Create `lib/Service/BesluittemplateService.php`:
  - [ ] `getBesluittemplates(caseType, legalBasis)`: returns template library per BW/Awb/Barp sections
  - [ ] `prefillJuridischeMotivaering(template, caseData)`: auto-populates incident-summary, BW/CAO basis, employee-history
  - [ ] `generateBesluittemplateVariants(caoCode)`: returns CAO-specific template adjustments (Gemeenten vs Rijk vs provincies)
  - [ ] Seed: load 10+ templates per caseType into docudesk template-library

### EscalatietoCollageService

- [ ] Create `lib/Service/EscalatietoCollageService.php`:
  - [ ] `detectEscalationEligibility(caseData)`: evaluates role-level + €50k+ threshold + integrity-bestuur links
  - [ ] `generateCollegevoorstel(caseId, template)`: creates voorstel per gemeente-specific template
  - [ ] `submitToiBabs(voorstelData)`: sends to iBabs/Notubiz integration, reserves B&W-agenda slot
  - [ ] `registerCollegebesluit(caseId, collegebesluitnummer, decision)`: links decision back to EmploymentCase
  - [ ] Config: organisation-specific template-paths (Gemeenten, Waterschappen variant)

### PayrollMutationService

- [ ] Create `lib/Service/PayrollMutationService.php`:
  - [ ] `generateSuspensionInstruction(caseId, type: 'loonopschorting'|'loonstop')`: creates payroll-engine-nl instruction
  - [ ] `generateSeveranceInstruction(transitievergoedingId)`: sends payment-payout instruction with amount + method
  - [ ] `generateReversalWithInterest(originalCaseId, rente%)`: calculates back-pay + interest, sends reversal-instruction
  - [ ] Integration-test with payroll-engine-nl stubbed API

### AccessControlEnforcementService

- [ ] Create `lib/Service/AccessControlEnforcementService.php`:
  - [ ] `encryptMelderIdentity(melder_data)`: encrypts full name + email, rotated quarterly per key-rotation schedule
  - [ ] `decryptMelderIdentity(caseId, actor)`: requires explicit permission + audit-log entry, logs business-justification
  - [ ] `enforceACLGate(caseId, actor, action)`: blocks view/edit/delete if ACL fails, logs incident with ID
  - [ ] `generateExportWithPseudonymisation(caseId, exportRules)`: redacts BSN, names, DoB per DPIA guidelines, encrypts output

---

## Frontend Components & Views

### Case List & Search

- [ ] Create `src/components/CnCaseList.vue`:
  - [ ] Uses `CnIndexPage` + `ObjectService.findAll()` for case discovery
  - [ ] Filters: caseType, status, caseHandlerId, confidentialityLevel (respects ACL)
  - [ ] Sort: openedAt (DESC), daysUntilDueDate (ASC for risk-triage)
  - [ ] ACL enforcement: hides vertrouwelijk/geheim cases from non-ACL users
  - [ ] Status-badges per workflow state + risk-color (green | yellow | red)

### Case Detail Page

- [ ] Create `src/views/CaseDetail.vue`:
  - [ ] Uses `CnDetailPage` with sections: Overview | Stappen | Besluit | Dossiering | Archival
  - [ ] Lifecycle-state-machine UI: displays current state + available transitions per guard-rules
  - [ ] CaseStep checklist with assignee + dueDate + status per step
  - [ ] Termijn-countdown widget (T-7 to T-0 color-change)
  - [ ] Bestuurlijke-escalation indicator (badge if escalation-eligible or already escalated)
  - [ ] ACL enforcement: read-only if confidential + no explicit grant

### Ontslag-Procedure Wizard

- [ ] Create `src/components/OnslagProcedureWizard.vue`:
  - [ ] Step 1: Select grond (a-i per BW 7:669) with descriptions
  - [ ] Step 2: Auto-select subType + workflow-variant (standard | kantonrechter-route | UWV-route)
  - [ ] Step 3: Review pre-filled CaseSteps + termijnenglas
  - [ ] Step 4: Confirm opzegtermijn calculation + bezwaartermijn
  - [ ] Submit → creates EmploymentCase with status=concept

### Integriteitsmelding Register

- [ ] Create `src/components/KlokkenluiderMeldingForm.vue`:
  - [ ] Radio select: melderType (intern | extern | anoniem) + meldingChannel (vertrouwenspersoon | HvK | toezichthouder)
  - [ ] Text-area: subject + summary (de-identified guidance)
  - [ ] Auto-sets protectedUntil = now + 7 years
  - [ ] Auto-encrypts melder_identity on submit
  - [ ] ACL auto-grants to: vertrouwenspersoon + integriteitscoördinator (fixed roles)
  - [ ] Confirmation: "Melder identity protected for 7 years per Wet bescherming klokkenluiders"

### Retaliation Check Widget

- [ ] Create `src/components/RetaliationCheckWidget.vue`:
  - [ ] Triggered when new EmploymentCase.employeeId matches existing Klokkenluidermelding within 24mo
  - [ ] Shows melding-summary (de-identified) + risk-assessment form
  - [ ] Assessor (integriteitscoördinator) selects verdict: retaliation-risk-high | retaliation-risk-low | safe-to-proceed
  - [ ] Reasoning text-field (mandatory)
  - [ ] Gates parent HR-action until verdict submitted

### Transitievergoeding Calculator

- [ ] Create `src/components/TransitionevergoedingCalculator.vue`:
  - [ ] Inputs: salary-snapshot (pre-filled from employee-master), serviceYears (auto-calculated), CAO-variant selector
  - [ ] Display: statutory-amount + bovenwettelijke-component + total with citations
  - [ ] Exclusion-detection: shows if kleine-werkgever or AOW-grens applies
  - [ ] If override: two-signer approval form (hr-director + finance-manager)
  - [ ] Output: Transitievergoeding object saved to register

### Termijn-Dashboard Widget

- [ ] Create `src/components/TermijnDashboard.vue`:
  - [ ] Lists all cases with bezwaartermijn or dueDate upcoming
  - [ ] Sorts by daysRemaining (ASC), color-coded: green (>14d) | yellow (7-14d) | red (<7d) | black (expired)
  - [ ] Filter: by handler | by status | by risk-level
  - [ ] Quick-action: "Mark as Addressed" (→ CaseStep completed)
  - [ ] Integrates with termijn-tracking daily-job output

### Integriteitscoördinator Trend Report

- [ ] Create `src/components/IntegrityTrendWidget.vue`:
  - [ ] Uses `IntegrityRegister` aggregations (quarterly counts, retaliation-check trends, external-escalation counts)
  - [ ] Chart: annual melding-count by subject-category (line or bar)
  - [ ] Stats-block: total meldingen (YTD), retaliation-checks-triggered, resolved-vs-pending
  - [ ] Exportable summary for bestuur-reporting (anonimised per Wet bescherming klokkenluiders)

### College-Voorstel Drafting

- [ ] Create `src/components/CollgevoorstelBuilder.vue`:
  - [ ] Auto-selects voorstel-template per gemeente-config + caseType
  - [ ] Pre-fills: case-summary, legal-basis, financial-impact, recommendation-options
  - [ ] Submits to iBabs/Notubiz integration with B&W-agenda-slot reservation
  - [ ] Confirmation: collegebesluitnummer expected by [next-B&W-date]

### Archival & Retention Manager

- [ ] Create `src/components/ArchivalManager.vue`:
  - [ ] Lists cases ready-for-archival (status=afgerond, retentionClass assigned)
  - [ ] Displays archival-date + retention-expiry-date
  - [ ] One-click: "Schedule for Archival" → triggers n8n workflow scheduling
  - [ ] Admin-view: upcoming-anonimisering queue + destruction-log (immutable)

---

## Authorization & Security (ADR-005)

### ACL Enforcement Middleware

- [ ] Create `lib/Middleware/CaseAccessMiddleware.php`:
  - [ ] Intercepts EmploymentCase reads/writes
  - [ ] Evaluates confidentialityLevel + accessControlList per actor
  - [ ] Blocks view/edit/delete with logging + incident-ID on denial
  - [ ] Notifies caseHandlerId of access-denial attempts

### Melder-Identity Encryption

- [ ] Create `lib/Service/MelderIdentityService.php`:
  - [ ] Encrypt on register-write, decrypt on read (with audit-log)
  - [ ] Key-rotation scheduled quarterly (migrates old encrypted values to new key)
  - [ ] Decryption requires explicit permission per role + business-justification entry

### Audit-Trail Immutability

- [ ] Enforce via OpenRegister AuditTrailService (immutable appends only, no retroactive deletes)
- [ ] Immutable logging of: case-state-transitions, termijn-expirations, ACL-denials, melder-identity-access, archival-operations

---

## Integrations & External Systems

### UWV Portal Integration (openconnector Adapter)

- [ ] Create `lib/Integration/UWVAdapter.php`:
  - [ ] POST formulier A+B to UWV endpoint (credentials from app-config)
  - [ ] Implements polling-loop: daily check for UWV-beslissing status
  - [ ] Handles UWV response: bedrijfseconomisch-approved | langdurige-arbeidsongeschiktheid-approved | afwijzing
  - [ ] On success: create follow-up CaseStep "Implement UWV Decision"

### Huis voor Klokkenluiders Export (openconnector)

- [ ] Create `lib/Integration/HuisVoorKlokkenluidersExporter.php`:
  - [ ] Sanitises Klokkenluidermelding data (melder-pseudonym, redacted supporting-evidence)
  - [ ] Generates encrypted PDF per HvK-requirements
  - [ ] Sends via openconnector email-adapter to HvK@huisvoorklokkenluiders.nl
  - [ ] Registers tracking-reference (huisVoorKlokkenluidersRef) for 2-way correlation

### Gemeentelijke Bestuurssystemen Integration (iBabs/Notubiz)

- [ ] Create `lib/Integration/iBabsAdapter.php`:
  - [ ] POST collegevoorstel to iBabs/Notubiz endpoint (credentials per gemeente-config)
  - [ ] Reserves B&W-agenda slot (verifies next available meeting)
  - [ ] Polls for collegebesluit registration post-meeting
  - [ ] Imports collegebesluitnummer back to EmploymentCase

### Payroll Engine Integration (payroll-engine-nl)

- [ ] Create `lib/Integration/PayrollMutationAdapter.php`:
  - [ ] Transforms EmploymentCase state → payroll-instruction (suspend | reduce | terminate | backpay-with-rente)
  - [ ] Sends via openconnector deterministic instruction-set
  - [ ] Polls for payroll-engine-nl confirmation of instruction-processing
  - [ ] On failure: escalates to finance-manager + retry scheduled

### Docudesk Integration (FileService + docudesk)

- [ ] Leverage existing `FileService` for document-storage within dossierFolderId
- [ ] Create `lib/Integration/DossierFolderManager.php`:
  - [ ] Creates folder per case (naming: caseNumber + caseType)
  - [ ] Sets folder ACL per EmploymentCase.confidentialityLevel + accessControlList
  - [ ] Bundles procesdossier on CRvB-route (export-to-PDF via DossierBundlingService)

---

## Background Jobs & Scheduling

### Daily Termijn-Tracking Job

- [ ] Create n8n workflow (scheduled daily 00:30 UTC):
  - [ ] Query all Besluit entities with bezwaarDeadline set
  - [ ] Calculate daysRemaining for each
  - [ ] Trigger OpenRegister `x-openregister-notifications` for T-7, T-2, T-1, T-0 alerts
  - [ ] Log expired-termijn cases with immutable timestamp
  - [ ] Error-handling: log job failure + alert operations

### Quarterly Archival & Retention Job

- [ ] Create n8n workflow (scheduled Q1 Jan-1, Q2 Apr-1, Q3 Jul-1, Q4 Oct-1, midnight UTC):
  - [ ] Query EmploymentCase entities with retentionClass + archival-eligible status
  - [ ] For each: invoke ArchivalService.anonimizeOrDestroyPerRetentionClass()
  - [ ] Pseudonymise or delete fields per retentionClass (ontslag-75j-post-geboorte | integriteit-7j | tucht-10j-post-verwijdering)
  - [ ] Log immutably: archival-date, records-affected, destruction-method, completion-status
  - [ ] Error-handling: alert compliance-officer + retry next-quarter

### UWV Status-Polling Job

- [ ] Create n8n workflow (scheduled daily 06:00 UTC):
  - [ ] Query EmploymentCase with caseType=ontslag + subType ∈ {h-grond, i-grond}
  - [ ] Invoke UWVIntegrationService.pollUWVDecision() per case
  - [ ] On UWV-beslissing received: import decision + create follow-up CaseStep
  - [ ] Error-handling: log poll-failure + retry daily until decision or case-closure

---

## Testing & QA

### Unit Tests (PHPUnit)

- [ ] TermijnCalculationService: test all BW/Awb termijn scenarios (20+ cases)
- [ ] TransitionevergoedingCalculationService: test 1/3 rule, CAO overlays, exclusions (15+ cases)
- [ ] RetaliationCheckService: test 24-month window detection, risk-assessment (10+ cases)
- [ ] AccessControlEnforcementService: test ACL gates, melder-identity encryption (8+ cases)
- [ ] MelderIdentityService: test encrypt/decrypt, key-rotation (5+ cases)

### Integration Tests

- [ ] OpenRegister schema validation: schemas register correctly, seed-data imports idempotently
- [ ] Case-lifecycle transitions: concept → in_behandeling → besluitvorming → afgerond per guards
- [ ] Klokkenluidermelding: creation + 7-year protection auto-set + ACL-restricted-access
- [ ] Termijn-tracking: Besluit.bezwaarDeadline auto-calculated, daily-job triggers alerts at T-7/T-2/T-1
- [ ] UWV integration (stubbed): formuliereenset sent, UWV-response imported, follow-up CaseStep created
- [ ] Archival job (stubbed): cases anonimisered per retentionClass on expiry-date
- [ ] Payroll integration (stubbed): case-state → instruction-payload transformation correct

### Persona Testing (test-persona-* skills)

- [ ] **HR-Jurist (Annemarie / Jan-Willem)**: ontslag-procedure workflow, BW-grond selection, termijn-tracking
- [ ] **Integriteitscoördinator (Fatima)**: klokkenluider-melding registration, retaliation-checks, trend-reporting
- [ ] **Vertrouwenspersoon (Low-literate Migrant — Priya)**: melder-intake form (clarity + accessibility), identity-protection confirmation
- [ ] **HR-Director (Henk)**: college-escalation initiation, severance-calculation override, bestuurlijke-reporting
- [ ] **Lijnmanager (Mark)**: disciplinary-measure initiation, termijn-reminders, hoor-en-wederhoor workflow

### Browser Testing (test-app, test-accessibility)

- [ ] Case-list rendering: 100+ cases, search-filter performance, ACL-applied
- [ ] Case-detail: all tabs load (Overview | Stappen | Besluit | Dossiering | Archival), edit-form save works
- [ ] Ontslag-wizard: all steps navigable, grond-selection updates CaseStep-variants
- [ ] Klokkenluider-form: melder-identity field encrypted on submit, verify via audit-log
- [ ] Termijn-widget: countdown colors change (green → yellow → red) at T-7, T-2, T-1
- [ ] Accessibility (WCAG AA): all forms keyboard-navigable, colour not sole conveyor, alt-text on images, 320-1920px responsive

---

## Documentation & Deployment

### Schema & Register Documentation

- [ ] Write `docs/aor-ambtenarenrecht-schemas.md`: overview of 5 schemas + seed-data examples
- [ ] Write `docs/workflows.md`: lifecycle state-machines + transition guards per caseType
- [ ] Write `docs/legal-basis.md`: BW/Awb/Beroepswet references + statutory termijn-calculations

### API Documentation

- [ ] Document REST endpoints per ADR-002 (OpenRegister CRUD + custom service endpoints: TermijnCalculationService, UWVIntegrationService, etc.)
- [ ] Document `x-openregister-lifecycle/-notifications/-calculations/-aggregations` blocks in schema-register

### Admin Configuration Guide

- [ ] Write `docs/admin-configuration.md`:
  - [ ] CAO-variant selection (Gemeenten | Rijk | provincies | waterschappen | SGO)
  - [ ] Bestuur-escalation threshold configuration (€50k+ customisation, role-level auto-escalation)
  - [ ] B&W-agenda integration setup (iBabs/Notubiz endpoint + credentials per gemeente)
  - [ ] Retentionklasse customisation per Selectielijsten local-variant
  - [ ] UWV portal credentials (forms-endpoint, polling-interval)

### Rollout Plan

- [ ] Phase 1 (Week 1-2): Deploy schema-register + seed-data, manual testing on staging
- [ ] Phase 2 (Week 3): Deploy TermijnCalculationService + TransitionevergoedingCalculationService, integration-tests
- [ ] Phase 3 (Week 4-5): Deploy Klokkenluider + RetaliationCheckService, persona-testing
- [ ] Phase 4 (Week 6): Deploy external integrations (UWV, Huis voor Klokkenluiders, iBabs)
- [ ] Phase 5 (Week 7-8): Deploy archival + retention-jobs, final QA + go-live approval
- [ ] Phase 6 (Week 8+): Pilot with 2-3 organisations, feedback-collection, roadmap refinement

---

## Success Criteria

- [ ] All 10 features (F-001 to F-010) functional end-to-end
- [ ] All 40 requirement-specs (REQ-001-001 to REQ-010-003) acceptance-criteria met
- [ ] Zero procedural-violations detected by hydra-gates (ADR-001 compliance + ADR-031 declarative-first + ADR-005 ACL enforcement)
- [ ] 100+ realistic seed-data objects load idempotently on app-install
- [ ] Persona-testing: all 5 personas complete primary workflows without blockers
- [ ] Browser-test pass-rate ≥95% (accessibility, performance, cross-browser)
- [ ] Compliance audit: immutable audit-trails logged for all state-transitions + access-denials
- [ ] Termijn-tracking: daily-job alert accuracy (T-7, T-2, T-1 timing ±1 day tolerance)
- [ ] Archival: quarterly-job executes without data-loss, anonimisering deterministic (reproducible for audit)
