# Annual Compensation Planning Cycle — Specifications

**Spec:** comp-planning-cycle  
**App:** hrmq  
**Version:** 0.1.0  
**Date:** 2026-05-23

## REQ-001: CFO Opens Cycle with Top-Down Budget

**GIVEN** ExCo has approved an annual loonsverhogings-budget and bonus-budget per business unit (e.g., 3.5% base-salary pool + 2.0% discretionary bonus pool per unit)  
**WHEN** the Reward Manager selects "Create New Cycle" and enters: fiscal-year, target-effective-date (default 2026-01-01), and publishes the cycle  
**THEN**  
1. A new `CompCycle` record is created with status `planning`
2. For each business unit, a `BudgetAllocatie` record is generated with:
   - `loonsverhoging_budget_eur` = unit-headcount × avg-salary × pct% (from top-down allocation)
   - `bonus_budget_eur` = unit-headcount × bonus-pct% (from top-down allocation)
   - `besteed_*_eur` = 0 (tracking begins)
3. Each unit's responsible manager receives a task: "Compensation Cycle 2025 — Submit proposals by [deadline]"
4. Reward Manager receives confirmation email with cycle-ID and budget-summary per unit

---

## REQ-002: Manager Enters Compensation Proposal for Direct Reports

**GIVEN** a manager has an active compensation cycle for their team (status `planning` or `in-progress`)  
**WHEN** the manager navigates to Medewerkers › Functie & comp › [employee-name] › Comp Cycle tab and clicks "Enter Proposal"  
**THEN** the form displays:
- Employee current state:
  - Huidige salaris (read-only, from employee-master)
  - Huidige compa-ratio (read-only, calculated: current-salary ÷ band-mid)
  - Salarisband (read-only)
  - Performance input (read-only, from performance-mgmt-advanced cycle)
- Manager input fields:
  - Voorgestelde loonsverhoging % (0.0–8.0%, increment 0.1%)
  - Resulting nieuw salaris (auto-calculated)
  - Resulting nieuwe compa-ratio (auto-calculated)
  - Voorgestelde bonus € (0–[max-per-role], default 0)
  - Promotie voorstel? (boolean)
  - (If promotion) Nieuwe rol (role picker)
  - Manager onderbouwing (text, required if >5% raise or promotion)
- **Budget bar** (live):
  - "Budget Available: €X for salary, €Y for bonus"
  - "Current Spend: €A salary, €B bonus"
  - "Remaining: €(X-A) / €(Y-B)"
  - Color: green if remaining >10%, yellow if 0–10%, red if overrun
- Save and Submit buttons

---

## REQ-003: Compa-Ratio Validation Within Band

**GIVEN** a manager enters a salary increase proposal  
**WHEN** the system calculates `nieuwe_compa_ratio = (huidge_salaris + voorgestelde_loonsverhoging_eur) ÷ band_mid`  
**THEN**
1. If `nieuwe_compa_ratio` ≤ band-max-compa (typically 1.15 for mid-level roles):
   - Proposal advances normally, no flag
2. If `nieuwe_compa_ratio` > band-max-compa (outlier):
   - An `equity_flag` is set on the `CompVoorstelEmployee` record
   - Manager must enter `onderbouwing` in a required text field (min 50 chars)
   - Example accepted underbouwing: "Market-rate correction for scarce skill (AI architect); comparable external salary €75k"
   - Proposal can proceed with flag to HR-BP review
3. At HR-BP review stage: flagged outliers route to Reward Committee for additional scrutiny

---

## REQ-004: Budget Overage Blocks Manager Submit

**GIVEN** a manager has entered proposals for multiple direct reports  
**WHEN** the manager clicks "Submit All Proposals"  
**THEN**  
1. System sums `BudgetAllocatie.besteed_*_eur` (all submitted proposals in unit) against `BudgetAllocatie.*_budget_eur`
2. If sum ≤ budget:
   - All proposals transition to status `manager-submit` (terminal for manager)
   - HR-BP receives task "Review proposals — Unit [name], [N] employees"
3. If sum > budget by amount X:
   - Submit is blocked with modal: "Budget Exceeded by €X (Y%)"
   - Two options:
     - **(a) Reduce Proposals:** Modal shows sortable table of proposals by raise-amount; manager can edit (reduce) proposals in-place and resubmit
     - **(b) Request Budget Extension:** Manager fills text: "Justification for budget uplift" (required). Creates task for HR-BP: "Budget Extension Request — Unit [name], Requested +€X". Manager proposals remain in `concept` status pending HR-BP approval of uplift request.

---

## REQ-005: Pay-Equity Check Before HR-BP Approval

**GIVEN** a manager has submitted all proposals for their unit  
**WHEN** the HR-BP opens the "Review" tab for that unit  
**THEN**  
1. System auto-runs (if not already run this cycle): `PayEquityCheck` for each salary band within the unit on dimensions:
   - Gender (F vs. M; plus non-binary if >3 employees)
   - Age cohort (e.g., <30, 30–40, 40–55, 55+)
   - Nationaliteit (NL vs. non-NL)
2. For each dimension × band combo:
   - Compute avg-salary per group
   - Calculate gap %
   - Assign signaal:
     - Green (<3%): no action
     - Yellow (3–5%): note as caution; HR-BP reviews
     - Red (>5%): **blocks cycle advance** unless HR-BP enters mitigation
3. If any red gap:
   - HR-BP sees a "Pay Equity Summary" card with:
     - Red gaps listed (band, dimension, groups, %)
     - For each red gap, a text field: "Mitigation or Documented Acceptance"
   - Examples of mitigation: "Adjust salary for lower-paid group by €X", "Gap is due to tenure/start-date distribution (justified)", "Plan targeted raise for Group B next cycle"
   - Until red gaps are addressed with text, the cycle cannot advance to CFO-approval
4. After mitigation is entered, all checks transition to status `completed` and are archived in the cycle for audit

---

## REQ-006: Workflow — Manager → HR-BP → Reward Committee → CFO

**GIVEN** proposals exist in various states (concept, manager-submit, hrbp-review, etc.)  
**WHEN** an actor (manager, HR-BP, Reward Committee, CFO) changes a proposal's status  
**THEN**  
1. **Manager → Submit** (manager-submit):
   - All manager-level validation passes (budget, compa, underbouwing if flagged)
   - Status becomes `manager-submit`

2. **HR-BP Review** (hrbp-review):
   - HR-BP reviews aggregate equity, flags, underbouwings
   - HR-BP can:
     - **Return to draft:** status → `concept` + comment "Reason for return"
     - **Approve:** status → `committee-review` (if equity_flag=true OR promotion=true) OR → `cfo-approval` (if neither flag)
   - For budget-extension requests: HR-BP approves/rejects uplift; if approved, increases `BudgetAllocatie.*_budget_eur` and auto-approves pending proposals

3. **Reward Committee Review** (committee-review, conditional):
   - Only triggered if equity_flag=true OR promotie_voorstel=true
   - Committee reviews:
     - Manager underbouwing
     - Performance input context
     - Promotion readiness (if applicable)
   - Committee can:
     - **Approve:** status → `cfo-approval`
     - **Return to HR-BP:** status → `hrbp-review` + comment

4. **CFO Approval** (cfo-approval):
   - CFO reviews unit-aggregate spend against budget (read-only view, all details pre-validated)
   - CFO can:
     - **Approve:** status → `approved` (proposal ready for letter generation)
     - **Return to Committee/HR-BP:** status → (previous stage) + comment for reconsideration

5. **Letter Generation** (letters-generated):
   - Auto-triggered once all unit proposals are `approved`
   - System generates `CompensationLetter` per employee; status → `letters-generated`

6. **Payroll Effectuation** (effectuating):
   - T-7 days before effective-date: system stages mutation batch
   - Status → `payroll-submitted`
   - Finance approval: status → `payroll-approved`
   - Post-payroll-run: status → `effectuated`

7. **Cycle Closed** (closed):
   - After all effectuations complete and T+7 days has passed: status → `closed`
   - Data locked to audit-only read

Every transition is logged in audit-trail with: actor, timestamp, old-status, new-status, comment (if provided).

---

## REQ-007: Compensation-Letter Generation Per Employee

**GIVEN** all proposals in a unit have reached `cfo-approval` status  
**WHEN** the Reward Manager (or automated scheduler T-6 days before effect-date) triggers "Generate Letters"  
**THEN**  
1. For each `CompVoorstelEmployee` with status `cfo-approval`:
   - System retrieves the employee's preferred language (from employee-master; default NL)
   - Retrieves the organization's standard comp-letter template
   - Renders PDF with variables:
     - Oud salaris (from CompVoorstelEmployee.huidge_salaris)
     - Nieuw salaris (from CompVoorstelEmployee.nieuw_salaris)
     - Loonsverhoging % (calculated)
     - Bonus € (if >0)
     - Promotie text (if promotie_voorstel=true, template includes "De organisatie is trots u te promoveren naar [nieuwe_rol]")
     - Effectief per (from CompCycle.effectief_per)
     - Organisatie naam, CFO signature block
   - Saves PDF to document-storage; creates `CompensationLetter` record with:
     - `pdf_url` → s3://docs/letter-[employee-slug]-[version].pdf
     - `gegenereerd_datum` → today
     - `letter_versie` → 1
   - Status of parent `CompVoorstelEmployee` → `letters-generated`

2. Each letter is made available in the employee's portal (Mijn HR):
   - "View Compensation Decision 2025" link
   - Requires click-to-view (not auto-displayed; audit-logged access)
   - Verstuurd_datum defaults to send-date once accessed

3. If generation fails (missing data, template error):
   - Reward Manager receives alert
   - Failed letters are retryable from Reward Manager UI

---

## REQ-008: Payroll Effectuation (T-7 Days Before Effective-Date)

**GIVEN** all `CompensationLetter` records are generated and `acknowledged_door_employee_datum` is populated (employee has viewed letter)  
**WHEN** the system detects `effectief_per` minus 7 days = today  
**THEN**  
1. System stages a payroll mutation batch to `payroll-engine-nl` with:
   - Employee ID (from employee-master ref)
   - Old salary (from payroll-engine-nl current record)
   - New salary (from CompVoorstelEmployee.nieuw_salaris)
   - Bonus (if >0, amount in €)
   - Cost center (from BudgetAllocatie.kostenplaats_of_unit_ref)
   - Effective date (CompCycle.effectief_per)
   - Reference (cyclus_id + employee-id for audit trail)
   
2. Batch enters status `payroll-staged` (read-only; awaiting approval)

3. Finance/HR-Admin receives task: "Approve Payroll Mutations for Compensation Cycle 2025 — [N] employees, Batch-ID [ID]"
   - Can review batch summary (total salary outlay, bonus spend, cost-center distribution)
   - Approve or reject entire batch

4. If approved (status → `payroll-approved`):
   - Batch is dispatched to payroll-engine-nl via API
   - payroll-engine-nl acknowledges receipt and queues for next payroll run
   - hrmq records the dispatch timestamp and payroll-run-ID

5. Post-payroll-execution:
   - payroll-engine-nl sends webhook: "Mutations processed for batch [ID]"
   - hrmq updates `CompVoorstelEmployee` status → `effectuated`
   - `CompensationLetter.acknowledged_door_employee_datum` is timestamped (or confirmed by employee click)

---

## REQ-009: Employee Transparency (EU Pay Transparency Directive 2023/970)

**GIVEN** an employee has received their compensation letter and their compensation proposal is in status `cfo-approval` or later  
**WHEN** the employee navigates to Mijn HR › Salaris › Comp 2025 [Compensation tab]  
**THEN** the employee can view:

### Tab 1: My Compensation Decision
- Old salary (formatted: €X,XXX/year)
- New salary (formatted: €X,XXX/year)
- Raise % and amount
- Bonus € (if applicable)
- Promotion text (if applicable)
- Effective date
- Link to download PDF letter

### Tab 2: My Role Band
- Current role (read-only)
- Current band (e.g., "Software Architect 2")
- Band range (min–mid–max formatted as: "€58k–€65k–€78k")
- Own compa-ratio (current and post-raise)
- Band source (e.g., "Hay Methodology, 2025 survey")
- Guidance: "A compa-ratio of 1.0 represents the target mid-point for your band. 0.95–1.05 is 'on band'; <0.90 is developing; >1.10 is senior-in-band."

### Tab 3: Gender Pay Gap (Logged Request)
- **Only visible if employee explicitly clicks "View Gender Pay Gap Information"**
- This click is audit-logged with timestamp and employee-id
- If band has ≥6 employees of each gender:
  - Displays anonymized summary: "Your band's average female salary: €62k, Average male salary: €67.5k, Gap: 8.1%"
  - Note: "This information is based on [number of females / males] employees; data shown to 6-month lag"
- If band has <6 employees of either gender:
  - Displays: "Insufficient sample size to share this data (k-anonimity threshold is 5+). Contact HR for individual inquiry."
- Ensures GDPR compliance and EU Directive Art. 19–20 transparency rights

---

## REQ-010: Cycle Closure with Retrospective Reporting

**GIVEN** all compensation mutations have been effectuated in payroll (all `CompVoorstelEmployee` status = `effectuated`)  
**WHEN** the Reward Manager clicks "Close Cycle" (available T+7 days after effective-date)  
**THEN**  
1. System generates a final **Cycle Report** document (PDF + archive):
   - **Budget Performance:**
     - Total loonsverhogingsbudget vs. actual spend (€ and %)
     - Total bonusbudget vs. actual spend (€ and %)
     - Per-unit spend breakdown (cost-center table)
   - **Raise Distribution:**
     - Average raise % (company-wide, per unit)
     - Median raise %
     - Min–max range
     - Distribution histogram (# of employees at each raise-band: 0–2%, 2–4%, 4–6%, >6%)
   - **Promotion Summary:**
     - Total promotions (count)
     - Promotion list (employee, old-role, new-role)
   - **Pay Equity Stand:**
     - Before-cycle pay-gaps (gender, age, nationality) per band
     - After-cycle pay-gaps (gender, age, nationality) per band
     - Gap closures (list any gap that improved >1%)
     - Remaining red gaps (>5%) with mitigation applied
   - **Outlier Summary:**
     - Count of equity-flagged proposals
     - Count of promotions
     - Count of budget-extension-approved requests
   - **Timeline Adherence:**
     - Cyclus start date, scheduled vs. actual close date
     - Days spent in each workflow stage
   - **Audit Trail Snapshot:**
     - Sample: "N status transitions logged, Z return-to-drafts, Q budget extensions approved"

2. Report is exported as PDF and stored in document-storage with cycle-id as key

3. Report is emailed to:
   - ExCo members (distribution: configured in settings)
   - RvC members (if OR consultation was logged; see WOR compliance section)
   - Reward Manager + HR Director

4. Report summary (executive-only, 1-page version) is available in Dashboard

5. `CompCycle` status → `closed`; all underlying `CompVoorstelEmployee` records are locked to audit-only read (no further edits)

6. Next cycle can now be created (if scheduled)

---

## Standards & Compliance Requirements

### EU Pay Transparency Directive 2023/970/EU
- Employees have right-to-information on:
  - Own salary (✓ compensation letter)
  - Salary band range (✓ REQ-009 Tab 2)
  - Anonymized gender pay gap (✓ REQ-009 Tab 3, k-anonimiteit ≥5)
- Gender pay gap audit before implementation (✓ REQ-005 pay-equity-check)
- Organizations ≥100 employees must publish statistical gender pay gap reports (✓ cyclus-report includes)
- Deadline for compliance: 2026-06-07

### AVG (GDPR)
- Salary data is sensitive personal data → strict access control
- Field-level RBAC: only managers see their own reports; only HR-BP + CFO see aggregate reports
- Gender/age/nationality in pay-equity checks must be k-anonymized (threshold: ≥5 per group)
- Employee transparency clicks are logged (audit trail)
- Data retention: cyclus-report archived 7 years (per NL tax law); detail records archived 3 years (per archival policy)

### WOR (Wet Ondernemingsraden)
- System design for comp-cycle (state machine, workflow, equity-checks) requires OR consultation before deployment
- OR approval is documented in project's WOR-compliance log
- Cyclus-report is shared with OR (if applicable per org policy)

### Wet Gelijke Behandeling
- Pay-equity-check explicitly detects gender/age/nationaliteit wage gaps
- Gaps >5% require documented mitigation or acceptance (no silent proceeds)
- No proposal advances without equity-validation

---

## API & Data Contract Examples

### Create Cycle (POST /cycles)
```json
{
  "jaar": 2025,
  "effectief_per": "2026-01-01",
  "totaal_loonsverhoging_budget_pct": 3.5,
  "totaal_bonus_budget_pct": 2.0
}
```

### Get Proposals (GET /cycles/{cyclusId}/proposals?status=hrbp-review)
```json
[
  {
    "cyclus_ref": "comp-2025-nlm",
    "employee_ref": "emp-adevries",
    "huidge_salaris": 65000,
    "voorgestelde_loonsverhoging_pct": 4.0,
    "neue_compa_ratio": 1.02,
    "equity_flag": false,
    "status": "hrbp-review"
  }
]
```

### Submit Proposal (PATCH /proposals/{proposalId})
```json
{
  "status": "manager-submit",
  "comment": "Proposal submitted for review"
}
```

---

**Next:** tasks.md (implementation work items, seed data, testing)
