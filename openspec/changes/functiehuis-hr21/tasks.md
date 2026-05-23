# Functiehuis HR21: Tasks

**Change ID:** functiehuis-hr21  
**Status:** tasks  
**Date:** 2026-05-23  

## Task Breakdown

### Backend Schema & Data Layer

- [ ] **Create OpenRegister schemas in `lib/Settings/functiehuis-hr21_register.json`**
  - [ ] Define `HR21Normfunctie` schema with properties: functieCode, functieNaam, functieFamilie, niveau, schaalBereik, voorkeurschaal, korteOmschrijving, kerntaken, vereisteCompetenties, vereisteOpleiding, vereisteErvaring, fuwasysScore, functiewaarderingsmethode, geldigVanaf, geldigTot, vngBron, versie
  - [ ] Define `HR21Functiefamilie` schema with properties: familieCode, familieNaam, korteOmschrijving, normfunctiesInFamilie (array of functieCode), schaalBereikFamilie
  - [ ] Define `Maatwerkfunctie` schema with lifecycle states: draft → submitted → approved → archived
    - [ ] Properties: maatwerkFunctieId, functieCode, functieNaam, gemeenteCode, onderbouwingMaatwerk, afgeleidVanNormfunctie, voorgesteldeSchaal, schaalOnderbouwing, kerntaken, vereisteCompetenties, ingangsdatum, goedgekeurdDoor, goedkeuringsdatum, reviewDatum
    - [ ] Declare `x-openregister-lifecycle` with approval gate (requires HR Director or CFO)
  - [ ] Define `Functietoekenning` schema with lifecycle states: voorstel → in_behandeling → vastgesteld → betwist | gearchiveerd
    - [ ] Properties: toekenningId, medewerkerId, functieCode, functieType, ingangsdatum, einddatum, schaal, periodiek, afdeling, leidinggevende, fte, indelingsbesluitId, indelingsproces, status, vorigeToekenning, wijzigingsreden, auditTrail (via AuditTrailService)
    - [ ] Declare `x-openregister-relations` linking to Employee, HR21Normfunctie, Maatwerkfunctie, ManagerApproval, Indelingsworkflow, Bezwaarprocedure
  - [ ] Define `Indelingsworkflow` schema with lifecycle states: voorstel → in_behandeling → vastgesteld | afgewezen | geannuleerd
    - [ ] Properties: workflowId, medewerkerId, type, status, huidigeStap, stappen (array of WorkflowStep), ingediendOp, verwachteAfrondingsDatum, actualAfrondingsDatum
    - [ ] Declare `x-openregister-lifecycle` with SLA deadline guards + automatic escalation on overdue manager approval
    - [ ] Declare `x-openregister-notifications` for step transitions (mail to HR, manager, employee)
  - [ ] Define `Bezwaarprocedure` schema with lifecycle states: ontvangen → ontvankelijk → vooronderzoek → hoorzitting_commissie → advies_ontvangen → beslissing_gegeven | ingetrokken
    - [ ] Properties: bezwaarId, medewerkerId, tegenIndelingsbesluit, bezwaarsgrond, indieningsdatum, behandelaar, status, stappen, wettelijkeTermijnAfloop, commissieHoorzittingDatum, adviesCommissie, beslissingOpBezwaar
    - [ ] Declare `x-openregister-lifecycle` with Awb-driven state machine + 42-day deadline guard (wettelijkeTermijnAfloop)

- [ ] **Seed data: Load 3-5 example objects per schema into `components.objects[]`**
  - [ ] HR21_Normfunctie seed: Beleidsmedewerker B, BOA Medewerker, Civiel Werkvoorbereider
  - [ ] HR21_Functiefamilie seed: Beleidsadvisering, Infra & Onderhoud
  - [ ] Maatwerkfunctie seed: Amsterdam Data-ethiek Adviseur
  - [ ] Functietoekenning seed: Employee assignments to Beleidsmedewerker B and BOA roles
  - [ ] Use @self envelope with human-readable slug for idempotency on re-import

- [ ] **Create OrganisationUnit relation in employee-master linking to gemeente**
  - [ ] Verify OrganisationUnit schema has gemeenteCode field (coordinate with employee-master team)
  - [ ] Test: Maatwerkfunctie.gemeenteCode can link to OrganisationUnit via select/dropdown UI

### API Endpoints & Services

- [ ] **Implement HR21 normfunctie import endpoint: `POST /api/v1/functiehuis/normfuncties/import`**
  - [ ] Accept JSON array from HR21 Leeuwendaal source (format: array of HR21Normfunctie objects)
  - [ ] Validate: all required fields present, versie field follows semantic versioning
  - [ ] On version upgrade: mark old records with geldigTot = now, create new records with geldigVanaf = now
  - [ ] Idempotency: match by functieCode; re-importing same version skips duplicates
  - [ ] Audit: log import event (timestamp, import-source URL, version, record count, actor)
  - [ ] Return: success summary (added, updated, archived counts) + validation errors (if any)

- [ ] **Implement salary-consequence calculator service for hercategorisatie**
  - [ ] Service method: `calculateHorizontaalAansluitendPeriodiek(oldSchaal, oldPeriodiek, newSchaal, caoid) → newPeriodiek`
  - [ ] Logic: Fetch oldSalary from cao-gemeenten; find first periodiek in newSchaal where salary >= oldSalary
  - [ ] Return: proposed periodiek + new salary + delta for display in UI
  - [ ] Handle edge case: if newSchaal max salary < oldSalary, return error "Cannot apply horizontaal-aansluitend rule; salary reduction exceeds range"
  - [ ] Cache: CAO lookups with 24h TTL to avoid API storms

- [ ] **Implement SLA escalation job for overdue manager approvals**
  - [ ] Cronjob runs daily at 09:00 UTC
  - [ ] Query: Indelingsworkflow WHERE huidigeStap='akkoord_leidinggevende' AND deadline < now AND 1 day since last reminder
  - [ ] For each overdue: escalate to manager.manager (resolve via employee-master relation); send reminder mail
  - [ ] Audit: log escalation (actor: system, timestamp, overdue_days)
  - [ ] Notification: Mail template "Indelingsvoorstel approval overdue — escalating to [manager's manager name]"
  - [ ] Update: Set `stappen[akkoord_leidinggevende].escalated_to_parent = true`

- [ ] **Implement Awb bezwaarprocedure state machine (via `x-openregister-lifecycle`)**
  - [ ] Lifecycle graph in schema: `x-openregister-lifecycle` with transitions:
    - `ontvangen` → `ontvankelijkheid_toets` (auto-transition if indieningsdatum + 1 day)
    - `ontvankelijkheid_toets` → `ontvankelijk | niet_ontvankelijk` (requires behandelaar decision)
    - `ontvankelijk` → `vooronderzoek` → `hoorzitting_commissie` → `advies_commissie` → `beslissing_gegeven` (Awb workflow)
  - [ ] Deadline guard: wettelijkeTermijnAfloop (6 weeks from indieningsdatum); alert if beslis not given 5 days before deadline
  - [ ] State-change notifications: Mail to medewerkerId, behandelaar, commissie on each transition
  - [ ] Guards: bezwaarprocedure.status='ontvankelijk' blocks further Functietoekenning mutations on tegenIndelingsbesluit

- [ ] **Implement maatwerkfunctie search-step enforcement (wizard)**
  - [ ] Create `CnWizardDialog` component with steps: "Search & Review" → "Propose Custom" → "Executive Approval"
  - [ ] Step 1: Force ≥3 normfunctie searches with "Not suitable" click per function before "Next" is enabled
  - [ ] Audit: Record which functions were reviewed + "not suitable" reason per function in workflow log
  - [ ] Step 2: Form for onderbouwingMaatwerk (≥250 chars), afgeleidVanNormfunctie (required), voorgesteldeSchaal (with range validation)
  - [ ] Step 3: Route to Director HR for approval; show approval workflow status + rejection feedback (if applicable)

### Frontend Components & Pages

- [ ] **Create functiehuis-hr21 sub-page in Medewerkers menu**
  - [ ] Create `src/pages/functiehuis/index.vue` as main entry point
  - [ ] Sub-pages:
    - [ ] `normfuncties/` — Read-only library search + detail view
    - [ ] `maatwerkfuncties/` — CRUD management (HR advisor + Director HR approval workflow)
    - [ ] `indelingsworkflow/` — Dashboard for HR advisor (pending proposals), manager (approvals due), employee (own pending)
    - [ ] `bezwaarenprocedures/` — Formal objection tracking (HR + commissie workflow)

- [ ] **Implement HR21_Normfunctie search & browse page**
  - [ ] Use `CnFilterBar` + `CnFacetSidebar` for search (familia, niveau, schaalBereik facets)
  - [ ] Use `CnDataTable` to display functions: functieNaam | niveau | schaalBereik | famiglia | versie
  - [ ] Detail modal: Click row → show full function profile (kerntaken, competencies, education, experience, versie, vngBron link)
  - [ ] Export: "Download as CSV" button → ExportService for bulk function library export
  - [ ] Career path link: From detail view, show "Related functions in same family" with navigation arrows (I→II→III→Senior)

- [ ] **Implement Functietoekenning form (indelingsvoorstel creation)**
  - [ ] Use `CnFormDialog` auto-generated from Indelingsworkflow + Functietoekenning schema
  - [ ] Steps:
    1. Select function (normfunctie or maatwerkfunctie) via searchable dropdown
    2. Validate scale against function's schaalBereik; show error if out of range
    3. Propose periodiek (from cao-gemeenten active scales)
    4. Enter motivatie (≥50 chars) with character counter
    5. Review page: display function profile + proposed scale/periodiek + salary consequence (via hercategorisatie calculator)
    6. Submit → Indelingsworkflow created with status='voorstel', routes to manager
  - [ ] Notification: HR advisor gets confirmation; manager gets approval request

- [ ] **Implement manager approval dashboard (mobile-optimized)**
  - [ ] Widget on Dashboard (default for manager role) showing "Approvals due"
  - [ ] Card layout: Function name | Employee name | Proposed scale | Salary delta | Manager action buttons (Approve | Reject | Propose alternative)
  - [ ] Approve: 1-click approval → Indelingsworkflow status='in_behandeling', routes to HR
  - [ ] Reject: Open modal for motivatie (≥100 chars) → workflow status='afgewezen', back to HR advisor
  - [ ] Propose alternative: Open form to change scale/function → creates new proposal draft, routes to HR advisor
  - [ ] SLA: Highlight overdue approvals in red with countdown timer "3 days remaining"
  - [ ] Mobile: Responsive layout; allow approval via phone

- [ ] **Implement Functietoekenning detail page with audit trail**
  - [ ] Show current assignment: functieNaam, scale, periodiek, status, current manager
  - [ ] Tab: "History" — CnAuditTrailTab showing all mutations (date, actor, change, reason)
  - [ ] Tab: "Workflow" — Indelingsworkflow progression (timeline: proposal → manager approval → HR finalization)
  - [ ] Tab: "Bezwaar" — Bezwaarprocedure (if applicable) showing Awb timeline + current status
  - [ ] Actions: (HR advisor only) "Edit", "Reassign", "Archive"; (Manager) "View context"; (Employee) "View salary", "File bezwaar"

- [ ] **Implement employee self-service pages in Mijn HR**
  - [ ] Create `Mijn HR › Mijn Indeling` page
  - [ ] Display: Current function, scale, periodiek, manager, afdeling, effective date, salary (if permitted)
  - [ ] Motivatie display: "Classification rationale: [motivatie from Indelingsworkflow voorstel]"
  - [ ] Awb section: If within 6-week objection window, show "File bezwaar" CTA
  - [ ] History: Collapsed timeline of past assignments (click to expand)
  - [ ] "File bezwaar" button → modal wizard:
    1. Confirm decision being contested (ingangsdatum, previous function, current function)
    2. Enter bezwaarsgrond (reason for objection, ≥100 chars)
    3. Review page: Deadline, procedure steps, next steps
    4. Submit → Bezwaarprocedure created, confirmation mail sent

- [ ] **Implement maatwerkfunctie CRUD (HR advisor + Director HR)**
  - [ ] Create `CnWizardDialog` for maatwerkfunctie creation (see API section)
  - [ ] List page: `CnDataTable` showing functieNaam | gemeenteCode | creator | empCount | reviewDatum | status
  - [ ] Detail page: Display onderbouwingMaatwerk, afgeleidVanNormfunctie, kerntaken, competencies, approval status + reviewer feedback
  - [ ] Edit: HR advisor can re-open draft for revision (before executive submission)
  - [ ] Approval workflow: Director HR sees pending maatwerkfuncties in separate dashboard queue
  - [ ] Approval action: Approve → status='approved' (finalized); or Reject → status='draft' + feedback comment
  - [ ] Archive: HR can archive overdue-for-review maatwerkfuncties (status='archived')

- [ ] **Implement Functiehuis KPI dashboard (Director HR)**
  - [ ] Widget `CnKpiGrid` with cards:
    - "Normfuncties in use: 47 / 150" (percentage bar)
    - "Maatwerk count: 12" (badge with trend ↑↓)
    - "% employees in maatwerk: 8.2%" (with "Acceptable" ✓ or "Alert ⚠" if >10%)
    - "Active maatwerkfuncties overdue for review: 3" (red alert if any)
  - [ ] Chart: Line chart "Maatwerkfunctie trend (3 years)" — monthly growth %
  - [ ] Table: "Top families by employee count" — Family | Count | % workforce
  - [ ] Alert section: If >10% maatwerk → banner "Custom function usage exceeds threshold. Recommend VNG consultation."
  - [ ] Export: PDF report with snapshot date + all above metrics

- [ ] **Implement career path discovery page (employee self-service)**
  - [ ] Route: `Mijn HR › Loopbaanpaden` or accessible from Functietoekenning detail
  - [ ] Display: Current function → suggested next steps (via family hierarchy)
  - [ ] Card per family: "Beleidsadvisering" → possible next functions (Beleidsmedewerker III, Senior, Strategic)
  - [ ] Click function card → detail view:
    - Description + scale range + requirements
    - Competency gap table: Current (from performance-management module if available) | Required | Gap | Development action
    - "Start development conversation" CTA → pre-fill mail template to manager
  - [ ] Managers view: Team loopbaan overview showing career stagnation flags (>5 years in role)

- [ ] **Implement manager team loopbaan report**
  - [ ] Page: Manager dashboard tab "Team Loopbaan" (accessible from organogram or team view)
  - [ ] Table: Employee | Current function | Tenure | Recommended next step | Stagnation flag | CTA
  - [ ] Stagnation flag: Red alert if tenure >5 years
  - [ ] CTA: "Start conversation" → pre-fill template mail: "[Employee name], your current role [function] has been your focus for [tenure]. Let's discuss career development options including [recommended next step]."
  - [ ] Bulk export: Excel report for talent planning

### Integration & Workflow

- [ ] **Integrate with cao-gemeenten for salary scale validation & lookup**
  - [ ] On Functietoekenning.save(): Query cao-gemeenten.activeCAO().schaalBereik; validate schaal is within range
  - [ ] On indelingsvoorstel submit: Display salary consequence (currentSchaal → newSchaal via horizontaal-aansluitend calculator)
  - [ ] Cache: Redis with 24h TTL on CAO schaalBereik queries
  - [ ] Fallback: If cao unavailable, use cached value with warning "Salary from [timestamp]; CAO service temporarily unavailable"
  - [ ] Test: Verify salary updates reflect new CAO rates (on 1 Jan after new CAO goes live)

- [ ] **Integrate with employee-master for employee context**
  - [ ] Resolve medewerkerId → Employee object for name, manager, afdeling display
  - [ ] On manager escalation: Resolve manager.manager (parent) via employee-master relation
  - [ ] On employee notification: Use employee.email for mail delivery

- [ ] **Integrate with OR-portaal for instemmingsrecht notifications**
  - [ ] On Maatwerkfunctie.status='submitted': Send notification to OR with maatwerkfunctie details (functieNaam, onderbouwing, affectedEmployeeCount expected)
  - [ ] OR acknowledges receipt; tracks instemmingsrecht deadline (depends on OR module spec)

- [ ] **Integrate with docudesk for PDF generation**
  - [ ] Template: `indelingsbesluit.jinja2` — PDF letter with decision details, motivatie, salary consequence, Awb notice
  - [ ] Template: `hercategorisatie-brief.jinja2` — Reclassification letter with salary impact
  - [ ] Template: `bezwaarbeschikking.jinja2` — Formal objection decision letter (from Bezwaarprocedure.beslissingOpBezwaar)
  - [ ] Template: `functie-dossier.jinja2` — Complete function history export (for AVG inzageverzoek)
  - [ ] Trigger: On Functietoekenning.status='vastgesteld', generate indelingsbesluit PDF + mail to employee

- [ ] **Integrate with decidesk for formal objection procedure (if applicable)**
  - [ ] If Bezwaarprocedure is handled externally in decidesk: Create DeciskeDecision object on Functietoekenning status='vastgesteld'
  - [ ] If Bezwaarprocedure is handled within this module: Ensure state machine is Awb-compliant; coordinate with decidesk for escalation path if needed

### Testing & Validation

- [ ] **Unit tests: OpenRegister schema validation**
  - [ ] Test: HR21Normfunctie schema allows versie "2024.1", "2025.2" formats
  - [ ] Test: Functietoekenning schaal must be within function's schaalBereik
  - [ ] Test: Maatwerkfunctie onderbouwingMaatwerk must be ≥250 chars
  - [ ] Test: Indelingsworkflow.motivatie must be ≥50 chars

- [ ] **Integration tests: Workflow state transitions**
  - [ ] Test: Indelingsworkflow transitions: voorstel → in_behandeling → vastgesteld (via x-openregister-lifecycle)
  - [ ] Test: Functietoekenning status changes block mutations when status='betwist' (via RBAC)
  - [ ] Test: Bezwaarprocedure Awb deadline guard prevents decisions after wettelijkeTermijnAfloop
  - [ ] Test: SLA escalation job escalates after deadline (mock time + verify escalation email sent)

- [ ] **API tests: HR21 normfunctie import**
  - [ ] Test: POST /api/v1/functiehuis/normfuncties/import with valid payload → 200 OK, added/updated counts
  - [ ] Test: Re-import same version → 200 OK, no duplicates (idempotency)
  - [ ] Test: Import with new version → old records marked geldigTot=now, new records created
  - [ ] Test: Import with invalid schema → 400 Bad Request with validation errors

- [ ] **Browser tests: Indelingsvoorstel flow (E2E)**
  - [ ] Test: HR advisor creates proposal → manager receives notification + approves → employee notified
  - [ ] Test: Scale validation rejects scale outside range
  - [ ] Test: Motivatie <50 chars blocked
  - [ ] Test: Manager rejects without reason → error "motivatie required"
  - [ ] Test: Manager proposes alternative scale → workflow returns to HR advisor

- [ ] **Browser tests: Maatwerkfunctie creation (E2E)**
  - [ ] Test: Wizard step 1 requires ≥3 normfunctie reviews before "Next" enabled
  - [ ] Test: Wizard step 2 requires onderbouwingMaatwerk ≥250 chars
  - [ ] Test: Wizard step 3 routes to Director HR for approval
  - [ ] Test: Director HR rejection returns workflow to HR advisor with feedback

- [ ] **Browser tests: Employee bezwaar flow (E2E)**
  - [ ] Test: Employee views own indeling in Mijn HR
  - [ ] Test: "File bezwaar" button appears only within 6-week window
  - [ ] Test: Late filing (>6 weeks) rejected with clear message
  - [ ] Test: Bezwaar submission creates Bezwaarprocedure + mail sent to behandelaar
  - [ ] Test: Functietoekenning.status='betwist' blocks manager from making further changes

- [ ] **Browser tests: CAO integration**
  - [ ] Test: Salary field auto-populated from cao-gemeenten on schaal/periodiek selection
  - [ ] Test: CAO version label shown (e.g., "CAO 2024-2026")
  - [ ] Test: Invalid scale (not in active CAO) → error on save

- [ ] **Accessibility & mobile tests**
  - [ ] Test: WCAG 2.1 AA compliance on all pages (forms, buttons, tables)
  - [ ] Test: Manager approval flow responsive on iPhone 12 (mobile-first design)
  - [ ] Test: Employee Mijn HR pages work on mobile (font size, touch targets)

- [ ] **Performance tests**
  - [ ] Test: Normfunctie library search <1s response (500+ functions)
  - [ ] Test: Indelingsworkflow list load <2s (pagination, filtering)
  - [ ] Test: CAO scale lookup cached; cache hit <100ms, miss <500ms (with 24h TTL)

- [ ] **Legal compliance tests (Awb, AVG)**
  - [ ] Test: Bezwaarprocedure 42-day deadline enforced (wettelijkeTermijnAfloop)
  - [ ] Test: AVG inzageverzoek export includes all Functietoekenning records + audit trail (no deletion permitted)
  - [ ] Test: Former employee (separated 10 yrs ago) historical records included in export
  - [ ] Test: Audit trail immutable (no backdate, no deletion of audit records)

### Documentation & Training

- [ ] **Write API documentation (OpenAPI 3.0)**
  - [ ] Document: POST /api/v1/functiehuis/normfuncties/import
  - [ ] Document: GET /api/v1/functiehuis/normfuncties (search + pagination)
  - [ ] Document: POST /api/v1/functiehuis/indelingsworkflow (create proposal)
  - [ ] Document: POST /api/v1/functiehuis/bezwaarprocedure (file objection)

- [ ] **Write user guides (Dutch, PDF)**
  - [ ] HR Advisor guide: "Indelingsvoorstel indienen in 5 stappen"
  - [ ] Manager guide: "Indelingsvoorstel goedkeuren (mobile-friendly)"
  - [ ] Employee guide: "Mijn indeling inzien en bezwaar indienen"
  - [ ] Director HR guide: "Functiehuis dashboard & maatwerkfunctie governance"

- [ ] **Create training materials**
  - [ ] Video: "HR21 functiewaardering basis" (5 min)
  - [ ] Webinar: "Maatwerkfunctie creatie & approvalflow" (30 min, live with HR team)
  - [ ] FAQ: Common questions (bezwaarprocedure timeline, CAO versioning, horizontaal-aansluitend salary calculation)

### Deployment & Cutover

- [ ] **Prepare data migration (if syncing from legacy system)**
  - [ ] Extract: Historical Functietoekenning records from legacy (if applicable)
  - [ ] Transform: Map legacy classification codes to HR21 functieCode
  - [ ] Load: Via ImportService; validate all records post-import
  - [ ] Audit: Compare legacy vs. imported record counts + spot-check 10% of records

- [ ] **Deploy to Tier 1 pilot municipalities (3-5 large municipalities)**
  - [ ] Coordinate: Schedule cutover with customer HR teams (off-hours or low-activity period)
  - [ ] Rollback plan: If critical issues, revert to prior version (keep legacy HR21 library in read-only mode during pilot)
  - [ ] Monitoring: Watch for API errors, high-latency queries, audit log anomalies
  - [ ] Feedback: Collect pilot feedback from HR advisors, managers, employees

- [ ] **Go-live checklist**
  - [ ] [ ] All REQ-HR21-001 through REQ-HR21-010 acceptance criteria passing
  - [ ] [ ] Legal review completed (Awb compliance, AVG data retention)
  - [ ] [ ] Audit review completed (immutability, trail defensibility)
  - [ ] [ ] CAO-gemeenten integration validated (salary lookups accurate)
  - [ ] [ ] Pilot feedback incorporated (if applicable)
  - [ ] [ ] Rollback procedure tested
  - [ ] [ ] Support documentation published (FAQs, contact info for HR support)

---

## Task Dependencies

```
1. Backend Schema & Data Layer
   ├─ Create register.json schemas (blocks all frontend work)
   └─ Seed data loaded (needed for browser testing)

2. API Endpoints & Services
   ├─ HR21 import endpoint (needed before go-live)
   ├─ Salary calculator (needed for hercategorisatie UI)
   ├─ SLA escalation job (scheduled after indelingsworkflow frontend)
   ├─ Bezwaarprocedure state machine (needed for employee bezwaar UI)
   └─ Maatwerkfunctie wizard (needed for HR CRUD)

3. Frontend Components (depends on #1, #2)
   ├─ Functiehuis sub-page structure
   ├─ Normfunctie search page
   ├─ Indelingsvoorstel form → manager approval dashboard
   ├─ Functietoekenning detail page
   ├─ Employee self-service (Mijn HR)
   ├─ Maatwerkfunctie CRUD
   ├─ KPI dashboard
   └─ Career path discovery

4. Integration & Workflow (depends on #1, #2, #3)
   ├─ CAO-gemeenten integration
   ├─ Employee-master integration
   ├─ OR-portaal integration
   ├─ Docudesk integration
   └─ Decidesk integration (if applicable)

5. Testing & Validation (runs in parallel with #4)
6. Documentation & Training (after #4)
7. Deployment & Cutover (after #5 + #6)
```

---

## Estimate

**Effort breakdown (team of 3-4 developers):**

| Phase | Duration | Team |
|---|---|---|
| Backend schemas + seed data | 2 weeks | Backend (1 FTE) |
| API endpoints + services | 3 weeks | Backend (1 FTE) + Mid (0.5 FTE) |
| Frontend components | 4 weeks | Frontend (2 FTE) |
| Integration + E2E testing | 2 weeks | QA (1 FTE) + Devs (0.5 FTE) |
| Documentation + training | 1 week | Product (0.5 FTE) + Tech Writer (0.5 FTE) |
| **Total** | **12 weeks (3 months)** | 3.5 FTE avg |

**Pilot go-live:** Week 8  
**Full rollout:** Week 12

---

## Success Metrics

Upon completion:
- ✓ Zero Awb compliance violations (audit-safe)
- ✓ HR advisor <5 min per new hire classification
- ✓ 100% of customer municipalities using HR21 library within 3 months
- ✓ <3% rework due to scale mismatch
- ✓ Maatwerkfunctie usage <10% across customer base (monitored via dashboard)
- ✓ Zero data loss (audit trail immutable + full history retained)
