# Functiehuis HR21: Specifications

**Change ID:** functiehuis-hr21  
**Status:** specs  
**Date:** 2026-05-23  

## Requirements

### REQ-HR21-001: Volledige HR21-bibliotheek met ~150 normfuncties

**Description:** The system MUST contain all VNG/HR21-recognized standard functions, categorized into function families, with associated salary ranges, core tasks, competencies, and education requirements. Data is imported annually from the HR21 Leeuwendaal source and versioned.

**Acceptance Criteria:**

- **GIVEN** an HR advisor opens the function library overview, **WHEN** searching for "Beleidsmedewerker", **THEN** the system displays all Beleidsmedewerker functions (I, II, III, Senior) with their salary ranges and short descriptions.
  - Expected: ≥4 functions returned with proper family grouping
  - Search is case-insensitive and matches partial terms

- **GIVEN** a new HR21 version is published by VNG (e.g. 2025.2), **WHEN** the import process runs, **THEN** the system adds new standard functions and marks removed functions as "archived" without modifying existing assignments.
  - Expected: New functions appear as `geldigTot: null`; deprecated functions get `geldigTot: <date>`
  - Existing Functietoekenning records referencing archived functions remain valid

- **GIVEN** a function family "Beleidsadvisering" is selected, **WHEN** the user clicks through, **THEN** the system displays all 5 normfuncties within this family including the family's combined salary range 8-14.
  - Expected: All linked functions shown with consistent family header + range label
  - Career path navigation (I → II → III → Senior) is visually clear

- **GIVEN** an HR advisor exports the normfunctie library for external reporting, **WHEN** the export runs, **THEN** the system generates a CSV/Excel file with all functions, families, competencies, and current validity status.
  - Expected: Export includes version identifier and export-date stamp for audit purposes

**Implementation Notes:**
- Import endpoint: `POST /api/v1/functiehuis/normfuncties/import` accepts JSON array from HR21 Leeuwendaal
- Versioning: Store `versie` field; use semantic version comparison to detect upgrades/downgrades
- Read-only enforcement: No UI to create/edit/delete normfuncties (import-only)
- Search: Full-text on `functieNaam` + `korteOmschrijving` via IndexService

---

### REQ-HR21-002: Indelingsvoorstel door HR-adviseur

**Description:** HR advisors MUST be able to propose a classification (functieCode, schaal, periodiek, motivatie) that routes to the manager for approval.

**Acceptance Criteria:**

- **GIVEN** an HR advisor proposes a classification for a new employee, **WHEN** the chosen salary scale is outside the allowed range for the function (e.g. scale 12 for Beleidsmedewerker B range 9-11), **THEN** the system rejects the proposal and displays: "Schaal 12 valt buiten het HR21-bereik 9-11 voor Beleidsmedewerker B."
  - Expected: Form validation on scale entry; prevents submission
  - Error message references the function's `schaalBereik` min/max

- **GIVEN** an HR advisor submits a proposal, **WHEN** the motivatie (business reason) is shorter than 50 characters, **THEN** the system rejects submission and requests a more detailed justification.
  - Expected: Character counter in form; validation error on save
  - Requirement: Auditing needs sufficient detail to defend the choice in disputes

- **GIVEN** a proposal is submitted, **WHEN** the manager logs in, **THEN** the system displays the proposal in the manager's dashboard with options: approve, reject (with reason), or propose alternative (different scale/function).
  - Expected: Manager notification (mail + in-app); proposal card shows function name + proposed scale + motivatie + salary consequence
  - Manager can navigate to employee profile to review context

- **GIVEN** an HR advisor opens the workflow form, **WHEN** the function selector is queried with "Beleidsmedewerker", **THEN** the system displays matching normfuncties AND available maatwerkfuncties for the employee's organisation.
  - Expected: Search results ranked (exact match first, then prefix match); marked as [NORMFUNCTIE] or [MAATWERK]

**Implementation Notes:**
- Proposal form: CnFormDialog auto-generated from Indelingsworkflow schema + Functietoekenning draft properties
- Validation: Scale range check via relation lookup to HR21Normfunctie.schaalBereik
- Motivatie min-length: Requirement for Awb legal defensibility (audit trail)
- State transition: HR advisor action moves workflow from `voorstel` to `in_behandeling` when saved

---

### REQ-HR21-003: Goedkeuring leidinggevende met SLA-bewaking

**Description:** Manager approval MUST be enforced within a reasonable timeframe (default 7 business days). Overdue proposals escalate to the next-level manager with reminder mail.

**Acceptance Criteria:**

- **GIVEN** a proposal is submitted on 20 Feb with deadline 27 Feb, **WHEN** the manager has not responded by 28 Feb, **THEN** the system escalates to the next-level manager (manager's manager) and sends reminder mail to both levels.
  - Expected: SLA tracking via Indelingsworkflow.stappen[akkoord_leidinggevende].deadline
  - Escalation rule: Move `uitvoerder` to parent manager; send mail with subject "Urgent: Indelingsvoorstel approval overdue"

- **GIVEN** a manager rejects a proposal, **WHEN** no rejection reason is provided, **THEN** the system refuses the rejection and requires a motivatie of minimum 100 characters.
  - Expected: Modal form for rejection with required motivatie field; validation before save
  - Audit trail records: actor, timestamp, motivatie, and reason code (e.g. "onvoldoende_ervaring")

- **GIVEN** a manager proposes an alternative scale (e.g. scale 9 instead of 10), **WHEN** submitting the alternative, **THEN** the system creates a new proposal draft with the revised scale and routes it back to the HR advisor for review.
  - Expected: Stappen array shows `wijzigingsvoorstel` milestone; workflow returns to `in_behandeling` state
  - HR advisor receives mail: "Manager proposed alternative: please review and confirm or revise."

- **GIVEN** a manager has 3 outstanding proposals all overdue, **WHEN** they view their dashboard, **THEN** the system highlights overdue proposals in red with countdown timer (days remaining).
  - Expected: CnDataTable with conditional row styling; sorting by deadline
  - One-click "Approve all" action not allowed; individual approval required for audit trail

**Implementation Notes:**
- SLA tracking: Via `x-openregister-lifecycle` with deadline guards + cronjob to escalate on deadline miss
- Escalation target: Resolve via employee-master employee.manager.manager relation
- Reminder mail: Triggered by lifecycle state hook; uses DecisionNotificationService
- Scale change workflow: Validates new scale within range; creates audit trail entry

---

### REQ-HR21-004: Inzage en bezwaarrecht medewerker

**Description:** On every classification change, the employee MUST receive notice with decision details and a 6-week objection window (per Awb). System MUST track the objection deadline strictly.

**Acceptance Criteria:**

- **GIVEN** a classification decision is finalized for an existing employee, **WHEN** the decision is set to `vastgesteld` status, **THEN** the system mails the employee a PDF with the decision, motivatie, salary consequence, and Awb objection information (deadline + procedure steps).
  - Expected: PDF generated via docudesk; mail sent within 1 hour
  - PDF includes: decision date, new function/scale, salary impact, right to object within 6 weeks
  - Mail body: Plain-text summary + CTA link to Mijn HR for objection filing

- **GIVEN** an employee opens the classifications tab in Mijn HR, **WHEN** the page loads, **THEN** the system displays the full history of classifications with motivations and the ability to file a formal objection against recent decisions.
  - Expected: Timeline showing all Functietoekenning records (active + historical) with ingangsdatum, einddatum, status
  - "File objection" button appears only for decisions within the 6-week window (checked against Indelingsworkflow.verwachteAfrondingsDatum + 42 days)

- **GIVEN** an employee files a formal objection within the 6-week window, **WHEN** the bezwaar form is submitted, **THEN** the system initiates a formal procedure per Awb, blocks further changes to the contested classification, and notifies the HR objection committee.
  - Expected: Functietoekenning status changes to `betwist`; subsequent changes are blocked with error "Classificatie is betwist — wijziging niet toegestaan"
  - Bezwaarprocedure object created with status `ontvangen`; mail to HR committee + behandelaar assigned
  - Bezwaarprocedure.wettelijkeTermijnAfloop = now + 42 days (strict deadline per Awb art. 7:11)

- **GIVEN** an employee submits an objection against a decision made 50 days ago, **WHEN** the form is submitted, **THEN** the system rejects the filing with message: "Objection period (42 days) has expired as of [date]. Please contact HR for appeal options."
  - Expected: Form validation checks ingangsdatum of the decision; blocks late filings with clear messaging
  - Late filing is logged in audit trail for potential escalation

**Implementation Notes:**
- Objection deadline: Calculated from Indelingsworkflow.afrondingsdatum + 42 days (per Awb)
- Status blocking: Functietoekenning.status = `betwist` prevents mutations (enforced via RBAC)
- Bezwaarprocedure lifecycle: Draft → ontvangen → vooronderzoek → hoorzitting → advies → beslissing (per Awb workflow)
- PDF generation: Via docudesk with template: indelingsbesluit.jinja2

---

### REQ-HR21-005: Maatwerkfunctie met expliciete onderbouwing

**Description:** Custom (maatwerkfunctie) classifications are permitted only when no standard HR21 function fits, AND a detailed business case is provided AND executive approval is obtained (Director HR/CFO, not just direct manager).

**Acceptance Criteria:**

- **GIVEN** an HR advisor initiates maatwerkfunctie creation, **WHEN** the form is opened, **THEN** the system enforces a search-step: at least 3 normfuncties must be reviewed before maatwerk is allowed.
  - Expected: Modal wizard: "Search & Review" step forces user to search and click "Not suitable" on ≥3 functions before progressing to "Create Custom"
  - Audit trail records which functions were reviewed + "not suitable" reason per function

- **GIVEN** a maatwerkfunctie is proposed, **WHEN** submitted, **THEN** the system requires:
  - onderbouwingMaatwerk ≥250 characters (business case)
  - goedkeuring by Director HR or CFO (not direct manager)
  - afgeleidVanNormfunctie (reference to closest standard function if applicable)
  - schaalOnderbouwing (justification for proposed scale)
  - Expected: Executive approval workflow routes to Director HR; executive can approve/reject with motivatie
  - If rejected, returns to HR advisor with feedback; no resubmission without addressing feedback

- **GIVEN** a maatwerkfunctie is 3 years old, **WHEN** the annual review cycle runs, **THEN** the system flags the custom function as due for re-evaluation with prompt: "This custom function was created [date]. Has an HR21 normfunctie now become available?"
  - Expected: System generates report for HR Director; reminder in Functiehuis dashboard
  - reviewDatum field is set at creation; query flags records where now() >= reviewDatum

- **GIVEN** a user filters the function overview by "Maatwerk", **WHEN** results are displayed, **THEN** the system shows all custom functions with creation date, creator, occupancy (count of employees assigned), and next review date.
  - Expected: CnDataTable with columns: functieNaam | gemeenteCode | creators | personCount | reviewDatum | status
  - Allows sorting by personCount to identify highest-impact custom functions

**Implementation Notes:**
- Search-step enforcement: CnWizardDialog with mandatory "Not suitable" clicks before "Next" enabled
- Executive approval: Route to employee with role "Director HR" or "CFO" via AuthorizationService
- Review flagging: Cronjob or calculated field `isOverdueForReview` = `now() >= reviewDatum`
- Maatwerk count KPI: Aggregation query per gemeente; dashboard alerts if >10% of workforce in custom functions

---

### REQ-HR21-006: Hercategorisatie bij functiewijziging

**Description:** On promotion/reclassification, the system MUST automatically calculate salary consequences per the "horizontaal-aansluitende-bedrag" rule (no income reduction). Batch hercategorisaties (sector-wide reclassifications) require individual review + approval per employee.

**Acceptance Criteria:**

- **GIVEN** an employee in scale 9 periodiek 7 (monthly salary €3,450.00), **WHEN** a promotion to scale 10 is registered, **THEN** the system automatically determines the entry periodiek in scale 10 as the first periodiek meeting ≥€3,450.00 (in this example, periodiek 3 = €3,475.00 per CAO table).
  - Expected: Indelingsworkflow shows calculated salary consequence before finalization
  - Periodiek lookup via cao-gemeenten.schaalBereik[scale].periodes[periodiek].brutoMaandsalaris
  - Displays: "Huidigsalaris: €3.450,00 → Newsalaris: €3.475,00 (Periodiek 3, horizontaal aansluitend)"

- **GIVEN** a sector-wide reclassification raises all scale 9 employees to scale 10, **WHEN** the batch mutation is initiated, **THEN** the system generates individual proposals for each affected employee and requires HR approval before execution.
  - Expected: Batch workflow creates Indelingsworkflow.type = `hercategorisatie_collectief` for each employee
  - Summary report: [count] employees affected, salary budget impact (e.g., +€450k annually), completion timeline
  - Manager approval per employee; no single "approve all" action (audit requirement)

- **GIVEN** an employee moves to a lower-valued function (voluntary demotion), **WHEN** the reclassification is proposed, **THEN** the system flags the change as potential demotie and requires:
  - Explicit warning modal: "This is a demotion. Salary will be reduced from X to Y."
  - Employee sign-off statement: Employee MUST confirm in writing they accept the change
  - Manager confirmation that this is voluntary (not forced)
  - Expected: Motivatie field pre-populated with "[Employee name] has requested demotion to [function] per [date] conversation"; requires employee sign-off before HR submission

- **GIVEN** a manager submits a hercategorisatie proposal for one employee, **WHEN** submitted, **THEN** the system provides a salary-consequence preview before finalization.
  - Expected: Modal shows: current scale/periodiek + salary → new scale/periodiek + salary + delta
  - If delta is negative and >€100/month, triggers warning + employee sign-off requirement

**Implementation Notes:**
- Horizontaal-aansluitende-bedrag: Lookup current salary from cao-gemeenten; find first periodiek in target scale ≥ current salary
- Batch processing: Via Indelingsworkflow entries; status `hercategorisatie_collectief` with linked-records indicator
- Demotion flag: Check if newSchaalNummer < oldSchaalNummer; triggers different workflow path (requires employee sign-off)
- Salary delta calculation: Via cao-gemeenten queryService; cached for 1 day to avoid lookup storms

---

### REQ-HR21-007: Auditeerbare wijzigingen met behoud historie

**Description:** Every Functietoekenning change MUST be tracked with full audit trail (actor, timestamp, decision, motivation, salary consequences). History is retained indefinitely (no deletion, only archival).

**Acceptance Criteria:**

- **GIVEN** an HR professional requests the function history for an employee, **WHEN** the history report is generated, **THEN** the system displays all classifications since hire with: date, previous function, new function, scale change, motivatie, approvers.
  - Expected: CnDetailPage with `CnAuditTrailTab` showing full mutation history
  - Report exports as PDF with signature block for audit purposes
  - Timeline includes: proposal → manager approval → finalization → employee notification milestones

- **GIVEN** a former employee (separated 10 years ago) requests access to their function dossier (AVG inzageverzoek), **WHEN** the GDPR request is processed, **THEN** the system generates a complete PDF with all classifications, motivations, and audit records dating back to hire.
  - Expected: AVG response includes all Functietoekenning records (including `einddatum` != null)
  - Deletion is NOT permitted (data retention rules: 7 years post-separation minimum for payroll/tax; longer for pension)
  - PDF includes audit trail for each record + bezwaarprocedure history (if any)

- **GIVEN** a controller audits all maatwerkfuncties from the past year, **WHEN** the audit report is requested, **THEN** the system generates an overview with:
  - Per maatwerkfunctie: onderbouwing text, approver name/date, employee count, salary-budget impact
  - Trend: maatwerkfunctie growth (count + budget); comparison to prior year
  - Expected: Excel export with sortable columns; flags any maatwerkfunctie >3 years old without recent re-evaluation

- **GIVEN** an employee views their own function history in Mijn HR, **WHEN** they click a historical classification, **THEN** the system shows the motivatie + audit log (who approved, when) without exposing other employees' data.
  - Expected: Personal view scope (RBAC); no salary history visible to employee (HR-only)
  - Timeline shows: proposal date → manager approval → my notification date → objection deadline → final status

**Implementation Notes:**
- Audit trail: Automatic via AuditTrailService on ObjectService.saveObject()
- Data retention: Enforce via `x-openregister-lifecycle` legal-hold rules (no deletion endpoint exposed)
- PDF generation: Via docudesk template: functie-dossier.jinja2
- AVG export: Via SettingsController.dataExport() with scope=employee_id; includes all OR objects related to employee

---

### REQ-HR21-008: Sjabloon-functies versus maatwerk dashboards

**Description:** The system MUST provide dashboards showing the ratio of standard functions to custom functions, enabling governance oversight. Alert if custom-function usage exceeds acceptable thresholds (>10% of workforce).

**Acceptance Criteria:**

- **GIVEN** a Director HR opens the functiehuis dashboard, **WHEN** the overview is loaded, **THEN** the system displays:
  - Number of active normfuncties in use (e.g., "47 of 150 HR21 functions")
  - Number of maatwerkfuncties (e.g., "12 custom functions")
  - Percentage of employees in maatwerk (target <10%, e.g., "8.2% — within acceptable range")
  - Trend over last 3 years (line chart: maatwerkfunctie growth %)
  - Top 3 families by employee count
  - Expected: CnDashboardPage widget with real-time KPIs; export to PDF for reporting

- **GIVEN** a gemeente has >15% of employees in custom functions, **WHEN** the annual functiehuis review runs, **THEN** the system generates a warning report with recommendation: "Over-reliance on maatwerk detected. Recommend consultation with VNG on possible HR21 normfunctie additions."
  - Expected: Automated report triggers at 15% threshold; mailed to HR Director + Director Finance
  - Report includes: which maatwerkfuncties are highest-occupancy + salary-budget impact

- **GIVEN** a user filters the function overview on "Maatwerk" type, **WHEN** results are displayed, **THEN** the system shows all custom functions with:
  - functieNaam | gemeenteCode | aanmaakdatum | aanmaker name | employee count | review deadline | status
  - Sorting by employee count to identify most-impactful custom functions
  - Color-coding: green (active), yellow (overdue review), red (very old >5 years)
  - Expected: CnDataTable with 5 columns above; drill-down to maatwerkfunctie detail on row click

- **GIVEN** a HR advisor wants to know which normfuncties are NOT currently assigned to anyone, **WHEN** the report is requested, **THEN** the system lists unused functions with last-assigned date (if ever).
  - Expected: Report shows "Normfuncties not in use" with availability for reactivation
  - Supports business case for VNG feedback (which standard functions are underutilized in this sector cluster)

**Implementation Notes:**
- KPI dashboard: Aggregation queries via `x-openregister-aggregations`
- Maatwerkfunctie % calculation: COUNT(functietoekenning WHERE functieType='maatwerk') / COUNT(functietoekenning WHERE status='vastgesteld') × 100
- Threshold alerts: Cronjob on monthly refresh; triggers if ratio >15%
- Unused normfunctie report: Query normfunctie library; left-outer-join to functietoekenning; flag if einddatum=null but no active assignments

---

### REQ-HR21-009: Koppeling met CAO Gemeenten en salarisschalen

**Description:** On every classification, the system MUST query the cao-gemeenten module for the active salary table, ensuring scale and periodiek always reference the correct active CAO version.

**Acceptance Criteria:**

- **GIVEN** an HR advisor assigns function "HR21-BELEIDSMEDEWERKER-II" to an employee per 1 March 2024, **WHEN** scale 10 periodiek 4 is selected, **THEN** the system fetches the salary from cao-gemeenten version 2024-2026 and auto-fills €3,897.00.
  - Expected: Form field is read-only (populated from cao-gemeenten); shows CAO version label "CAO 2024-2026"
  - API call: GET /api/v1/cao/schaalBereik?caoid=cao-gemeenten-2024&schaal=10&periodiek=4

- **GIVEN** a new CAO version becomes active on 1 Jan 2025 with 3% raise, **WHEN** the annual recalculation runs, **THEN** the system updates all employees' salaries based on the new CAO without modifying the Functietoekenning record itself.
  - Expected: Salary field is NOT stored in Functietoekenning; it is looked up at read-time from cao-gemeenten
  - All payroll runs after 1 Jan automatically use new rates
  - Old Functietoekenning records remain valid; lookup history is implicitly tracked via cao-gemeenten version

- **GIVEN** a Functietoekenning uses a scale that no longer exists in the active CAO (e.g., scale 1 in 2024 when CAO only goes 4-19), **WHEN** a mutation is attempted, **THEN** the system blocks the mutation with error: "Schaal niet beschikbaar in actieve CAO — corrigeer eerst de Functietoekenning."
  - Expected: Validation on save checks cao-gemeenten.schaalBereik; returns error if scale is invalid
  - Escalation: HR must manually reconcile the orphaned classification (unlikely; indicates data import error)

- **GIVEN** the cao-gemeenten API is temporarily unavailable, **WHEN** an HR advisor tries to finalize a classification, **THEN** the system either:
  - (A) Shows a cached salary (up to 24h old) with warning "Salary from [timestamp]; CAO service temporarily unavailable. Verify before finalizing.", or
  - (B) Blocks finalization with message "CAO validation required; retrying in 5 seconds..." with exponential backoff (max 3 attempts)
  - Expected: Fallback mechanism prevents silent incorrect salary assignments; audit log records the outage

**Implementation Notes:**
- CAO lookup: Via cao-gemeenten.queryService.getSchaalPeriodiek(schaal, periodiek, caoid)
- Caching: Redis with 24h TTL on schaalBereik lookups
- Scale validation: On Functietoekenning.save(), check cao-gemeenten.activeCAO().schaalBereik.includes(scale)
- Fallback: If cao unavailable after 3 retries, block finalization; log incident + alert ops

---

### REQ-HR21-010: Functiefamilie-rapportages en loopbaanpaden

**Description:** The system MUST display career paths to employees based on function family, showing realistic next-step functions and competency gaps for professional development.

**Acceptance Criteria:**

- **GIVEN** an employee with function "Beleidsmedewerker B" opens the career-path tab, **WHEN** the page loads, **THEN** the system displays:
  - Possible next functions within same family: Beleidsmedewerker III, Senior Beleidsmedewerker
  - Related families: Strategie & Onderzoek, Beleidsadvisering variants
  - For each option: description, scale range, competency requirements
  - Expected: Visual card layout with "Explore this path" CTA → detail view of next function

- **GIVEN** an employee clicks on a potential next function (e.g., Senior Beleidsmedewerker), **WHEN** the detail is displayed, **THEN** the system shows:
  - Competency gap analysis: which competencies the employee already has vs. required (sourced from performance-management module if available, else template defaults)
  - Suggested development paths (via opleiding-en-ontwikkeling module recommendations)
  - Typical timeline: "Employees typically advance after [duration] in current role"
  - Expected: Comparison table: Current competencies | Required for next role | Gap | Development action

- **GIVEN** a manager opens team-loopbaan-overview, **WHEN** the report loads, **THEN** the system shows per team member:
  - Current function + family | Recommended next step (if applicable) | Career stagnation flag (>5 years in same function)
  - Flagging logic: IF (now - ingangsdatum) > 5 years, mark "Career development opportunity" with red flag
  - Trend: "3 team members are due for career conversation"
  - Expected: CnDataTable sortable by tenure; "Start development conversation" CTA → template mail to employee + manager

- **GIVEN** a user requests a "Career path history" report for talent planning, **WHEN** the report is generated, **THEN** the system exports a matrix showing:
  - Per employee: all historical functions + dates + families | Progression (up/lateral/down) | Average tenure per level
  - Aggregate: average career velocity (e.g., "Avg. 3.2 years to Senior Beleidsmedewerker from entry level")
  - Expected: Excel export with sortable columns; supports succession planning

**Implementation Notes:**
- Career path graph: Derived from HR21_Functiefamilie.normfunctiesInFamilie ordering (I → II → III → Senior → Strategisch)
- Gap analysis: Cross-reference employee performance-mgmt competencies (if available); fallback to function-template defaults
- Stagnation flag: Calculated field `isCareerStagnant` = `(now - lastIngangsdatum) > 1826 days` (5 years)
- Development CTA: Pre-populates mail template with employee's current function + recommended next step + competency gaps

---

## Cross-Reference Mapping

| Requirement | Entity | Lifecycle | Notifications | Integrations |
|---|---|---|---|---|
| REQ-HR21-001 | HR21_Normfunctie | Read-only (import) | None | HR21 Leeuwendaal source |
| REQ-HR21-002 | Indelingsworkflow | Voorstel → in_behandeling | HR submit confirmation | employee-master |
| REQ-HR21-003 | Indelingsworkflow | in_behandeling + SLA | Manager reminder (escalation) | OR escalation (manager.manager) |
| REQ-HR21-004 | Functietoekenning, Bezwaarprocedure | Vastgesteld → betwist | Employee notification (mail + Mijn HR) | docudesk (PDF), decidesk (formal procedure) |
| REQ-HR21-005 | Maatwerkfunctie | Draft → submitted → approved | Director HR approval | OR-portaal (instemmingsrecht) |
| REQ-HR21-006 | Functietoekenning (hercategorisatie variant) | Voorstel → vastgesteld (batch) | Manager approval (per employee) | cao-gemeenten (salary lookup) |
| REQ-HR21-007 | Functietoekenning (all mutations) | N/A (audit-layer) | None (passive logging) | AuditTrailService (automatic) |
| REQ-HR21-008 | Maatwerkfunctie (aggregation) | N/A (reporting) | Alert if >15% | Dashboard aggregations |
| REQ-HR21-009 | Functietoekenning (read-layer) | N/A (validation) | None | cao-gemeenten (schaalBereik lookup) |
| REQ-HR21-010 | HR21_Functiefamilie + Functietoekenning | N/A (discovery) | None (optional: dev conversation CTA) | performance-management (competency source) |

## Acceptance & Validation

All requirements are validated via:
1. **Automated tests** (browser + API)
2. **Manual QA** on 3+ customer municipalities (Tier 1 pilot municipalities)
3. **Legal review** of Awb compliance (req-hr21-004, req-hr21-007)
4. **Audit review** of data retention + immutability (req-hr21-007)
5. **CAO-gemeenten integration test** (req-hr21-009)

Success = zero compliance gaps + zero rework requests on data accuracy.
