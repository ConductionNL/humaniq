# Annual Compensation Planning Cycle — Tasks

**Spec:** comp-planning-cycle  
**App:** hrmq  
**Version:** 0.1.0  
**Date:** 2026-05-23

---

## Phase 1: Core Workflow (MVP)

### Backend: Data Model & Register Patching

- [ ] **Create OpenRegister schema definitions** (register patch in `lib/Settings/hrmq_register.json`):
  - [ ] CompCycle schema with properties: cyclus_id, jaar, effectief_per, status, totaal_loonsverhoging_budget_pct, totaal_bonus_budget_pct, pay_equity_check_status, transparency_disclosure_brief_url
  - [ ] BudgetAllocatie schema with properties: cyclus_ref, kostenplaats_of_unit_ref, verantwoordelijke_manager_ref, loonsverhoging_budget_eur, bonus_budget_eur, besteed_*, restant_eur, over_budget_flag
  - [ ] CompVoorstelEmployee schema with properties: cyclus_ref, employee_ref, huidge_salaris, huidge_band_ref, huidge_compa_ratio, performance_input_ref, voorgestelde_loonsverhoging_pct, voorgestelde_loonsverhoging_eur, nieuw_salaris, nieuwe_compa_ratio, voorgestelde_bonus_eur, promotie_voorstel_bool, nieuwe_rol_ref_optional, nieuwe_band_ref_optional, equity_flag, manager_onderbouwing, status
  - [ ] SalarisBand schema (reference data) with properties: band_code, functie_familie, niveau, min_eur, mid_eur, max_eur, valuta, geldig_per_datum, bron
  - [ ] PayEquityCheck schema with properties: cyclus_ref, dimensie, band_ref, groep_a_label, groep_a_gemiddelde_eur, groep_b_label, groep_b_gemiddelde_eur, gap_pct, gap_signaal, actie_aanbeveling
  - [ ] CompensationLetter schema with properties: cyclus_ref, employee_ref, letter_versie, oud_salaris, nieuw_salaris, loonsverhoging_pct, bonus_eur, promotie_text_optional, effectief_per, gegenereerd_datum, verstuurd_datum, acknowledged_door_employee_datum, pdf_url

- [ ] **Declare lifecycle state machine** (x-openregister-lifecycle in register for CompCycle and CompVoorstelEmployee):
  - [ ] CompCycle lifecycle: planning → in-progress → cfo-approval → letters → effectuering → closed
  - [ ] CompVoorstelEmployee lifecycle: concept → manager-submit → hrbp-review → [committee-review] → cfo-approval → letters-generated → payroll-submitted → payroll-approved → effectuated
  - [ ] Transition guards: compa-ratio-validation, budget-check, equity-check (Phase 2), committee-gate (if equity_flag OR promotie)
  - [ ] Lifecycle events: "status.transitioned" CloudEvent emitted per transition (for task creation, notifications)

- [ ] **Declare calculated fields** (x-openregister-calculations):
  - [ ] CompVoorstelEmployee.nieuwe_compa_ratio = (huidge_salaris + voorgestelde_loonsverhoging_eur) / band.mid_eur
  - [ ] BudgetAllocatie.restant_eur = loonsverhoging_budget_eur - besteed_loonsverhoging_eur (salary) + (bonus_budget_eur - besteed_bonus_eur)
  - [ ] BudgetAllocatie.over_budget_flag = (besteed_* > budget_*) ? true : false
  - [ ] CompVoorstelEmployee.equity_flag = (nieuwe_compa_ratio > max-compa-for-level) ? true : false (auto-set on create/update)

- [ ] **Declare aggregations** (x-openregister-aggregations for pay-equity analysis):
  - [ ] PayEquityAgg: for given band + cyclus + dimensie, compute avg-salary per group (gender, age-cohort, nationality)
  - [ ] BudgetAgg: for given cyclus, compute total-spend, unit-spend, average-raise-pct per band

- [ ] **Declare notifications** (x-openregister-notifications):
  - [ ] On CompVoorstelEmployee.status → manager-submit: create task for HR-BP "Review Proposals"
  - [ ] On CompVoorstelEmployee.status → hrbp-review returned: notify employee (if configured) + create task for manager
  - [ ] On CompCycle.status → letters: notify employees "Compensation letter available in portal"
  - [ ] On CompCycle.status → closed: notify ExCo + OR with cycle report

### Backend: Services & Integrations

- [ ] **Create CompensationLetterGenerationService:**
  - [ ] Method: `generateLetter(CompVoorstelEmployee): CompensationLetter`
  - [ ] Retrieves organization template (configurable in settings)
  - [ ] Renders PDF with variables: oud-salaris, nieuw-salaris, %, bonus, promotie-text, effectief-per, org-name, CFO-sig block
  - [ ] Saves PDF to document-storage (S3 via FileService)
  - [ ] Returns CompensationLetter record (pdf_url, gegenereerd_datum)
  - [ ] Error handling: logs and reports failed generations to Reward Manager UI

- [ ] **Create PayrollMutationAdapterService:**
  - [ ] Method: `buildMutationBatch(cyclus_id, [CompVoorstelEmployee]): PayrollBatch`
  - [ ] Maps each approved proposal to payroll-engine-nl schema:
     - employee-id, old-salary (from payroll-engine-nl API call), new-salary, bonus, cost-center, effective-date, batch-ref
  - [ ] Method: `submitBatch(PayrollBatch): batch-id, acknowledgment`
  - [ ] Handles two-stage approval flow: stage → await approval → submit
  - [ ] Handles webhook from payroll-engine-nl on completion

- [ ] **Create PayEquityAuditService (Phase 1 stub, Phase 2 full):**
  - [ ] Method: `runPayEquityCheck(cyclus_id, band_ref, [CompVoorstelEmployee]): [PayEquityCheck]`
  - [ ] Computes avg-salary per demographic group (gender, age, nationality)
  - [ ] Calculates gap-pct and assigns signaal (green <3%, yellow 3–5%, red >5%)
  - [ ] Returns PayEquityCheck records (stored in cycle for audit)
  - [ ] Phase 1: runs manually via admin action; Phase 2: auto-trigger on HR-BP review

- [ ] **Create BudgetTrackingService:**
  - [ ] Method: `validateBudget(BudgetAllocatie, [CompVoorstelEmployee]): budget-remaining, overage`
  - [ ] Sums proposed salary/bonus against allocation
  - [ ] Returns remaining budget and overage amount (if any)
  - [ ] Used in form validation (live) and submit-block (hard gate)

- [ ] **Create CycleOrchestrationJob (Background):**
  - [ ] On `effectief_per` - 7 days: stage payroll mutation batch (T-7)
  - [ ] On `effectief_per` - 1 day: send reminder to Finance (T-1)
  - [ ] On `effectief_per` + 7 days: auto-close cycle if all mutations are effectuated (T+7)

### Frontend: UI Components

- [ ] **Manager Proposal Form Component** (`src/components/CompProposalForm.vue`):
  - [ ] Displays employee current state (salary, compa, band, performance)
  - [ ] Input fields: raise %, bonus €, promotion (boolean), underbouwing (textarea if flagged)
  - [ ] Auto-calculated fields: nieuw-salaris, nieuwe-compa-ratio (read-only, live-updated)
  - [ ] Budget bar (live): remaining €, color-coded (green/yellow/red)
  - [ ] Save (draft) and Submit buttons
  - [ ] Form validation: required fields, range checks, underbouwing if equity_flag
  - [ ] Integrates with ObjectService for CRUD on CompVoorstelEmployee

- [ ] **Cycle Budget Summary Widget** (`src/components/CycleBudgetSummary.vue`):
  - [ ] Displays unit-level budget allocation
  - [ ] Pie chart: allocated vs. remaining vs. overage (if any)
  - [ ] Table: per-manager spend + remaining
  - [ ] Totals: company-wide salary/bonus spend vs. budget

- [ ] **Proposal List & Workflow View** (`src/views/CompProposalList.vue`):
  - [ ] Filterable table: employee, current-salary, proposed-raise %, status, equity-flag, underbouwing
  - [ ] Sortable by: employee, raise %, compa-ratio
  - [ ] Bulk actions: (once in codebase) mark-for-promotion, flag-for-committee, return-to-draft
  - [ ] Status badge: concept, manager-submit, hrbp-review, committee-review, cfo-approval, letters-generated, effectuated
  - [ ] Row click → detail view (CompProposalDetail.vue)

- [ ] **Compensation Letter Preview** (`src/components/CompLetterPreview.vue`):
  - [ ] Read-only display of generated letter (PDF embed or text summary)
  - [ ] Download button → PDF
  - [ ] Send button (admin only)
  - [ ] Acknowledgment status & timestamp

- [ ] **Cycle Dashboard Tab** on Medewerkers › Functie & comp:
  - [ ] Embedded as detail-tab per ADR-001 Rule 6
  - [ ] Tabs: My Decision (employee), My Band (employee), Gender Gap (employee), Manage Cycle (manager/admin)
  - [ ] Each tab context-aware by role + employee

### Frontend: Employee Self-Service (Mijn HR)

- [ ] **Compensation Portal Screen** (`src/views/MyCompensation.vue` in `mijn-hr` wrapper):
  - [ ] **Tab 1: My Compensation Decision**
    - [ ] Displays old salary, new salary, raise %, bonus, promotion text, effective-date
    - [ ] Download letter link
    - [ ] Placeholder: "Your compensation decision is effective on [date]"
  - [ ] **Tab 2: My Role Band**
    - [ ] Displays role, band-code, band-range (min–mid–max)
    - [ ] Own compa-ratio (current and post-raise)
    - [ ] Band interpretation guide (what 0.95–1.05 means, what >1.10 means)
  - [ ] **Tab 3: Gender Pay Gap** (if band ≥6 per gender):
    - [ ] "View Gender Pay Gap Information" button (audit-logged click)
    - [ ] On click: displays anonymized summary (female avg, male avg, gap %)
    - [ ] Note: "Data is 6-month lagged; k-anonimity threshold is 5+"
    - [ ] If <6 per gender: "Insufficient data; contact HR for details"

### Testing & QA

- [ ] **Unit tests for BudgetTrackingService:**
  - [ ] Test: budget-OK, remaining-shows-correctly
  - [ ] Test: budget-overage-detected, flags over_budget_flag
  - [ ] Test: multiple-employees, cumulative-spend-correct

- [ ] **Unit tests for PayEquityAuditService (Phase 1 stub):**
  - [ ] Test: gender gap <3% returns green signaal
  - [ ] Test: gender gap 4% returns yellow signaal
  - [ ] Test: gender gap >5% returns red signaal

- [ ] **Integration tests:**
  - [ ] Create cycle → auto-create budget-allocatie per unit
  - [ ] Manager enters proposal → live budget-bar updates
  - [ ] Manager submit over-budget → blocked, options offered
  - [ ] Manager submit in-budget → status → manager-submit

- [ ] **Manual test script (QA):**
  - [ ] Create test cycle (fiscal 2025)
  - [ ] Assign 10 test employees to 3 cost centers
  - [ ] 3 managers each enter proposals (mix: in-budget, over-budget, flagged)
  - [ ] Verify budget-validation, compa-ratio-validation
  - [ ] Manager submit in-budget → HR-BP sees proposals
  - [ ] Letter generation (mock; Phase 1 PDF stub)
  - [ ] Verify audit trail logged per action

- [ ] **Load test:**
  - [ ] 500+ employees, 50 managers, 1 cycle
  - [ ] All managers submit within same 2-hour window
  - [ ] Budget calculations / aggregations stay <500ms per request
  - [ ] No database lock contention on budget-allocatie updates

---

## Phase 2: Pay-Equity & Governance

### Backend: Pay-Equity Engine

- [ ] **Enhance PayEquityAuditService:**
  - [ ] Auto-trigger on HR-BP review start (was manual in Phase 1)
  - [ ] Pre-populate `PayEquityCheck` records per band × dimension
  - [ ] Block cycle advance if red gaps exist without mitigation text
  - [ ] Mitigation text field: required if gap >5%
  - [ ] Store mitigation in PayEquityCheck.actie_aanbeveling

- [ ] **Create EquityRemediationService:**
  - [ ] Suggest corrective raises per band if gap >5%
  - [ ] Example: "Gap 8.1% female–male. Consider +€3k raise for 3 female employees to close to <5%"
  - [ ] HR-BP can accept suggestions or enter custom mitigation
  - [ ] Re-run audit after mitigation proposals to verify gap-close

### Frontend: Governance

- [ ] **HR-BP Review Interface** (`src/views/CompHRBPReview.vue`):
  - [ ] Proposal list (filtered to HR-BP's scope)
  - [ ] Pay-equity summary card: red/yellow/green gaps per band
  - [ ] For each red gap: mitigation-text textarea (required)
  - [ ] Return-to-draft, approve buttons (status transitions)

- [ ] **Reward Committee Review Interface** (`src/views/CompCommitteeReview.vue`):
  - [ ] Filtered list: only equity-flag + promotion proposals
  - [ ] Manager underbouwing + performance context visible
  - [ ] Approve/return buttons

- [ ] **CFO Dashboard** (`src/views/CompCFOApproval.vue`):
  - [ ] Unit-level aggregate summary (total spend, budget, %)
  - [ ] Drill-down per unit: proposal count, avg-raise, budget-utilization
  - [ ] Read-only (all details pre-validated)
  - [ ] Approve/return buttons per unit

### Testing & QA

- [ ] **Pay-equity scenario tests:**
  - [ ] Band with 8F, 10M: 8% gap (red) → suggest mitigation → re-run → gap <5% (resolved)
  - [ ] Band with 3F, 20M: <5 in one group (green, k-anon OK)
  - [ ] Age cohort: 4 <30yo, 15 40+yo → green (different cohort sizes)

---

## Phase 3: Letter Generation & Payroll Integration

### Backend: Letter & Payroll

- [ ] **CompensationLetterGenerationService (Full Implementation):**
  - [ ] Retrieve org template from settings (configurable template-path)
  - [ ] Render PDF with correct variable substitution
  - [ ] Batch generate 100+ letters (async, with progress tracking)
  - [ ] Retry failed generations with exponential backoff
  - [ ] Archive PDFs in document-storage with cycle-id + employee-id key

- [ ] **Payroll Integration (Full Two-Stage Flow):**
  - [ ] T-7 days: auto-stage mutation batch (CycleOrchestrationJob)
  - [ ] Finance/HR approves batch (manual action via UI)
  - [ ] Submit to payroll-engine-nl (PayrollMutationAdapterService)
  - [ ] Receive webhook on completion; update CompVoorstelEmployee status → effectuated
  - [ ] Handle retry if payroll-engine-nl is temporarily unavailable

- [ ] **Cyclus Retrospective Report Service:**
  - [ ] Generate PDF with budget performance, raise distribution, promotion summary, equity stand, outlier count, timeline adherence
  - [ ] Email to ExCo, OR, Reward Manager
  - [ ] Summary card for Dashboard
  - [ ] Archive in document-storage (7-year retention per NL tax law)

### Frontend: Admin & Employee

- [ ] **Letter Generation Control Panel** (`src/views/CompLetterGeneration.vue`):
  - [ ] Button: "Generate All Letters"
  - [ ] Progress bar: "Generating 47/125 letters..."
  - [ ] Failed-generation alert + retry options
  - [ ] Download all letters as ZIP
  - [ ] Send button (triggers email dispatch via notifications)

- [ ] **Payroll Submission & Approval** (`src/views/CompPayrollApproval.vue`):
  - [ ] Shows staged batch (read-only summary)
  - [ ] Approve / Reject buttons (requires Finance role)
  - [ ] On approve: status → payroll-submitted, dispatch to payroll-engine-nl

- [ ] **Cyclus Retrospective Report View** (`src/views/CompCycleReport.vue`):
  - [ ] Displays generated report (PDF embed + sections as tabs)
  - [ ] Download, email, archive buttons
  - [ ] Filterable by unit / cost-center

- [ ] **Employee Transparency Screens:**
  - [ ] Enhanced Mijn HR tabs (Tab 3: Gender Gap with audit-logging)
  - [ ] On-demand disclosure click → audit-trail logged

### Testing & QA

- [ ] **Letter generation test:**
  - [ ] Generate 50 test letters (mix of languages, promotion/no-promotion, bonus/no-bonus)
  - [ ] Verify PDF content accuracy
  - [ ] Verify PDF URLs are correct S3 paths

- [ ] **Payroll integration test:**
  - [ ] Stage batch (T-7 mock date)
  - [ ] Finance approves
  - [ ] Mock payroll-engine-nl response
  - [ ] Verify mutations queued correctly

- [ ] **Employee transparency test:**
  - [ ] Employee clicks "View Gender Pay Gap"
  - [ ] Audit trail logged
  - [ ] Gap data displayed (or k-anon rejection)
  - [ ] No unauthorized access

---

## Seed Data Generation

- [ ] **Create seed-data import file** (`lib/Settings/hrmq_register.json` components.objects[]):
  - [ ] 5 SalarisBand objects (SA-2, FIN-2, ENG-3, HR-1, DIR-1)
  - [ ] 1 CompCycle (2025, planning)
  - [ ] 3 BudgetAllocatie (Engineering, Finance, HR units)
  - [ ] 4 CompVoorstelEmployee (adevries, sjongen, pvandenbosch, jgronewagen)
  - [ ] 2 PayEquityCheck (band-sa-2 gender, band-fin-2 age)
  - [ ] 1 CompensationLetter (adevries, generated)
  - All objects use `@self` envelope with `register: "hrmq"`, `schema: "..."`, `slug: "..."`
  - All slugs use realistic Dutch identifiers (emp-adevries, band-sa-2-hay, etc.)

- [ ] **Verify seed data on install:**
  - [ ] Deploy app
  - [ ] Seed objects auto-imported via ConfigurationService.importFromApp()
  - [ ] OpenRegister dashboard shows 6 CompVoorstelEmployee (4 seeded + 2 test manual)
  - [ ] BudgetAllocatie sums correct (250k + 180k + 65k salary budget)

---

## Deduplication Check

- [ ] **Verify no overlap with existing hrmq services:**
  - [ ] Search `lib/Service/` for any existing compensation/salary/budget services
  - [ ] Confirm: no CompensationService, BudgetService, PayrollMutationService exist
  - [ ] Confirm: no custom workflow engine (use x-openregister-lifecycle)
  - [ ] Confirm: no custom aggregation logic (use x-openregister-aggregations)

- [ ] **Verify OpenRegister abstractions are used:**
  - [ ] All entities in hrmq_register.json (not custom Entities)
  - [ ] CRUD via ObjectService, not custom mapper
  - [ ] Forms via CnFormDialog (schema-driven), not custom form code
  - [ ] List views via CnDataTable + IndexService, not custom list component
  - [ ] Audit trail via AuditTrailService (automatic), not custom logging
  - [ ] Notifications via x-openregister-notifications, not custom NotificationService call

---

## Standards & Compliance Verification

- [ ] **WOR Consultation:**
  - [ ] Document cycle design (state machine, equity checks, workflow) in compliance log
  - [ ] Obtain OR approval before go-live (or document opt-out if org has no OR)
  - [ ] Include OR in cyclus-report distribution (if applicable)

- [ ] **EU Pay Transparency Directive 2023/970 Compliance:**
  - [ ] Verify implementation by 2026-06-07 deadline
  - [ ] Checklist:
    - [ ] Employees see salary band range (✓ REQ-009 Tab 2)
    - [ ] Employees see own compa-ratio (✓ REQ-009 Tab 2)
    - [ ] Gender pay gap audit per band (✓ REQ-005 PayEquityCheck)
    - [ ] Anonymized disclosure (k-anonimity ≥5) (✓ REQ-009 Tab 3)
    - [ ] Compensation letter per employee (✓ REQ-007)

- [ ] **AVG Compliance:**
  - [ ] Salary data access controlled via RBAC (managers see own team, HR-BP sees scope, CFO sees aggregate)
  - [ ] Gender/age/nationality in gap reports anonymized (k-anonimity >5)
  - [ ] Audit trail logged for all salary reads and disclosures
  - [ ] Retention: cyclus-report 7 years (tax law), detail records 3 years (policy)

- [ ] **Wet Gelijke Behandeling:**
  - [ ] Pay-equity-audit detects wage gaps by gender/age/nationality
  - [ ] Red gaps (>5%) require mitigation before cycle advance
  - [ ] No proposals with unaddressed discrimination-risk proceed

---

## Documentation & Handoff

- [ ] **User Documentation:**
  - [ ] Manager Compensation Proposal Guide (en-US, nl-NL translations)
  - [ ] HR-BP Review Workflow (en-US, nl-NL)
  - [ ] CFO Approval Process (en-US, nl-NL)
  - [ ] Employee FAQ (Mijn HR transparency, band interpretation, pay-gap request)

- [ ] **Admin Documentation:**
  - [ ] Cyclus orchestration (T-0, T-7, T-1, T+7 milestones)
  - [ ] Configuration settings (budget % per org, template path, effective-date default)
  - [ ] Disaster recovery (rollback cycle, regenerate letters, payroll-revert procedures)

- [ ] **API Documentation:**
  - [ ] OpenAPI 3.0 spec for cyclus endpoints
  - [ ] Webhook schema for payroll-engine-nl completion
  - [ ] Example request/response bodies (JSON)

- [ ] **Training & Handoff:**
  - [ ] Record walkthrough video (demo cycle creation → manager proposal → HR-BP review → letters → payroll)
  - [ ] Run live training with pilot users (2–3 managers, 1 HR-BP, 1 Reward Manager)
  - [ ] Gather feedback; iterate UI/UX before full rollout

---

## Go/No-Go Checklist

- [ ] All Phase 1 tasks complete (core workflow)
- [ ] All Phase 2 tasks complete (pay-equity, governance)
- [ ] All Phase 3 tasks complete (letters, payroll, reporting)
- [ ] Unit + integration tests pass (>80% coverage)
- [ ] Load test pass (500+ employees, <500ms per request)
- [ ] WOR consultation complete & approval documented
- [ ] EU Directive 2023/970 compliance verified
- [ ] AVG compliance verified (RBAC, anonymization, audit trail)
- [ ] User documentation complete & reviewed
- [ ] Training delivery complete & feedback collected
- [ ] Seed data loaded successfully
- [ ] Pilot cycle completed (2–3 teams, 30–50 employees)
- [ ] Pilot feedback incorporated
- [ ] Production go-live approved by product owner

---

**Completion:** All artifacts (proposal.md, design.md, specs.md, tasks.md) finalized. Ready for review by architecture, legal (WOR/AVG), and product leadership before development sprint kickoff.
