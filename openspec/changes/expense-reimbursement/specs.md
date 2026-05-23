---
status: specs
created: 2026-05-23
---

# Specs: Onkostendeclaratie Requirements & Scenarios

## REQ-EXP-001: Bewijsstuk-verplichting (Receipt Requirement)

The system SHALL require every `Declaratie` to be backed by at least one valid `Bonnetje` (scanned receipt) UNLESS the amount is ≤€10 AND the employee enters a `geen_bewijsstuk_reden` (e.g., "parkeerautomaat") that is explicitly approved by the approver.

### Scenario: Receipt Required for Amount >€10

- **WHEN** an employee submits a declaratie of €87.50 without attaching a receipt
- **THEN** the system rejects submission with message "Bewijsstuk verplicht voor bedragen boven €10"
- **AND** the form remains in edit mode (`status: bewerken`)

### Scenario: No Receipt Allowed for ≤€10 with Reason

- **WHEN** an employee submits a declaratie of €8.50 with `geen_bewijsstuk_reden: "parkeerautomaat, geen bon beschikbaar"`
- **THEN** the system accepts submission (`ingediend_op` is set, `status: wacht_op_approval`)
- **AND** the approver sees the reason in the detail view
- **AND** the approver's approval covers both the amount AND the no-receipt reason

---

## REQ-EXP-002: OCR-Voorvulling met Validatie (OCR Pre-Population & Validation)

The system SHALL, on `Bonnetje` upload via Nextcloud Mobile, trigger a docudesk OCR call, extract fields (datum, leverancier, totaal_incl_btw, btw_bedrag, btw_tarief), pre-fill the `Declaratie` form, store the OCR confidence score, and allow the employee to override each field with a `gecorrigeerd_door_gebruiker` audit flag.

### Scenario: High-Confidence OCR Auto-Fill

- **WHEN** an employee scans a receipt with docudesk OCR confidence 0.92
- **THEN** the system populates `datum_uitgave`, `leverancier`, `bedrag_incl_btw`, `btw_bedrag`, `btw_tarief` automatically
- **AND** each field shows a checkmark (high confidence) and is editable
- **AND** the form is ready for category + zakelijk_doel entry (≤2 fields remain)

### Scenario: Low-Confidence OCR with Manual Review

- **WHEN** an employee scans a receipt with docudesk OCR confidence 0.65 (faded date, blurred amount)
- **THEN** the system populates all fields but marks `bedrag_incl_btw` and `datum_uitgave` with a warning icon
- **AND** the form requires explicit review of the flagged fields before submission
- **AND** on override, the system sets `gecorrigeerd_door_gebruiker: true` in the audit record

---

## REQ-EXP-003: Duplicate-Detection (Duplicate Detection)

The system SHALL, on every `Bonnetje` upload, compute a SHA-256 hash of the file, compare it against all bonnetjes submitted by the same employee in the past 12 months, and block submission if a hash-match is found, referencing the prior declaratie ID.

### Scenario: Duplicate Receipt Detected

- **WHEN** an employee re-scans the same receipt on 2026-05-15 (originally submitted 2026-05-12)
- **THEN** the system rejects the upload with message "Deze bon is al ingediend als dec_01KCDE567HIJ op 2026-05-12"
- **AND** the prior declaratie is linked for review

---

## REQ-EXP-004: WKR-Classificatie Verplicht (Mandatory WKR Classification)

The system SHALL require every `Declaratie` to specify a `wkr_classificatie` chosen from:
- `intermediaire_kosten` (expenses, no wage component)
- `gerichte_vrijstelling` (targeted tax-exempt, per Belastingdienst art. 31a)
- `nihil_waardering` (in-kind benefit valued at €0)
- `vrije_ruimte` (WKR allowance, subject to year-end budget)
- `eindheffingsloon_80pct` (taxed as wage with 80% withholding)

The system SHALL pre-suggest a default classification based on the expense category + current Belastingdienst handreiking + prior declaraties from the same vendor. The employee MUST explicitly confirm or change the suggested class.

### Scenario: Default Suggestion Based on Category & Vendor History

- **WHEN** an employee enters `categorie: "telewerkvergoeding"` and Belastingdienst handreiking 2026 maps this to `gerichte_vrijstelling`
- **THEN** the system suggests `wkr_classificatie: "gerichte_vrijstelling"` with a link to the relevant art. 31a section
- **AND** if the employee has prior declaraties from leverancier "Ergon Werkmeubelen", the system also suggests the WKR-class from those prior entries (MRU logic)
- **AND** the employee can override with any other class, but must confirm the choice before submit

---

## REQ-EXP-005: Kilometervergoeding Tarieven (Kilometer Allowance Tariffs)

The system SHALL store indexed kilometer-allowance tariffs per calendar-year (€0.23/km tax-free for 2026, €0.21/km for 2027 per Belastingplan-indexatie). On submission of a `KilometerRit`, the system SHALL calculate:
- `bedrag_belastingvrij = afstand_km × tarief_belastingvrij_per_km`
- `bedrag_belast = max(0, bedrag_reisvergoed − bedrag_belastingvrij)`

The tax-free amount routes to intermediaire-kosten (AP); the taxed amount routes to payroll as wage bijtelling.

### Scenario: 100 km at €0.30/km Claimed, €0.23/km Tax-Free (2026)

- **WHEN** an employee submits a kilometer-rit of 100 km, claiming €0.30/km reimbursement, on 2026-05-10
- **THEN** the system fetches tarief 2026 (€0.23/km) and calculates:
  - `bedrag_belastingvrij = 100 × 0.23 = €23.00`
  - `bedrag_belast = (100 × 0.30) − 23.00 = €7.00`
- **AND** the declaratie routes €23.00 to shillinq AP + €7.00 to payroll bijtelling

---

## REQ-EXP-006: Configureerbare Approval-Workflow (Configurable Approval Workflow)

The system SHALL support per-BU approval-workflow configuration with rules on amount-thresholds, categories, WKR-classes, and employee roles. Each approval step MUST be tracked in an `ApprovalStap` record. On age > 5 workdays, the system SHALL auto-escalate to the next approver or to finance.

### Scenario: 1-Step Approval for Small Representation Expenses

- **WHEN** a declaratie of €45 with `categorie: "representatie"` and OCR confidence ≥0.85 is submitted
- **THEN** the approval-workflow rule (configured: < €50 + representatie + good-OCR → 1 step) routes to direct leidinggevende
- **AND** the declaratie enters `status: wacht_op_approval`, `huidige_approval_stap: 1`, `totaal_approval_stappen: 1`

### Scenario: 3-Step Approval for High-Amount Training Expenses

- **WHEN** a declaratie of €1200 with `categorie: "training"` is submitted
- **THEN** the approval-workflow rule (configured: "training" category → always 3 steps) routes:
  1. Step 1: direct leidinggevende (5-day timeout)
  2. Step 2: HR-officer (5-day timeout)
  3. Step 3: finance (5-day timeout)
- **AND** step 2 is created with `status: wacht` only after step 1 approval

### Scenario: Auto-Escalation After 5 Workdays

- **WHEN** an approval-step is pending for 5 workdays without action
- **THEN** the system escalates to the next approver or to finance
- **AND** the prior approver receives a "pending escalation" reminder

---

## REQ-EXP-007: Routering naar shillinq AP vs Payroll (Routing Post-Approval)

The system SHALL, on declaratie approval, route the declaratie to shillinq or payroll based on WKR-class:
- `intermediaire_kosten`, `gerichte_vrijstelling`, `nihil_waardering` → shillinq AP entry (creditor = employee IBAN, GL account per category)
- `vrije_ruimte` (if ≤ YTD budget) → shillinq AP
- `vrije_ruimte` (if > YTD budget) + `eindheffingsloon_80pct` → payroll as wage bijtelling

### Scenario: Gerichte-Vrijstelling Routes to AP

- **WHEN** a declaratie of €87.50 with `wkr_classificatie: "gerichte_vrijstelling"` (telewerkvergoeding) is approved
- **THEN** the system creates a shillinq AP entry:
  - Creditor: employee IBAN (from employee-master)
  - Amount: €87.50
  - GL account: 4310 (representatie/telewerkvergoeding)
  - Invoice ref: dec_01KCDE567HIJ
- **AND** the declaratie `status: gerouteerd_ap`, `uitbetaald_op` is set once shillinq confirms SEPA-batch dispatch

---

## REQ-EXP-008: WKR-Budget Tracking & Waarschuwingen (WKR Budget Tracking & Alerts)

The system SHALL maintain a `WKRBudget` record per calendar-year, tracking:
- `loonsom_grondslag` (from payroll-engine-nl)
- `vrije_ruimte_percentage_eerste_400k` (1.92% for 2026)
- `vrije_ruimte_beschikbaar` (calculated as loonsom × percentage)
- `vrije_ruimte_verbruikt_ytd` (running sum of approved vrije-ruimte declaraties)
- `vrije_ruimte_verbruikt_pct` (100 × verbruikt / beschikbaar)

The system SHALL send notifications to finance + HR at 75% and 100% consumption. At year-end, calculate 80% eindheffing on overage and deliver to shillinq.

### Scenario: 75% Consumption Warning

- **WHEN** YTD vrije-ruimte consumption reaches 75% of available budget
- **THEN** the system sends a notification to finance + HR:
  - "WKR-budget 75% verbruikt (€10.448 van €13.931 beschikbaar in 2026). Reclassificatie of eindheffing kan nodig zijn."
- **AND** the WKR-overzicht dashboard shows the warning in red

---

## REQ-EXP-009: Steekproef Vaste Vergoedingen (Sampling Audit for Fixed Allowances)

The system SHALL enforce a sampling-audit cycle (default: annual) for each `VasteVergoeding`. The employer MUST document proof of actual costs incurred, store it in `onderbouwing_document_id`, and on negative result, reclassify the allowance.

### Scenario: Annual Sampling Audit Due

- **WHEN** a `VasteVergoeding` (telewerkvergoeding) has `steekproef_volgende_vereist_voor: "2026-09-15"` and today is 2026-09-10
- **THEN** the system sends a reminder to HR: "Steekproef telewerkvergoeding emp_01HXYW987DEF is vervallen. Onderbouwing vereist voor voortgave."
- **AND** HR attaches cost-proof to `onderbouwing_document_id`

---

## REQ-EXP-010: Audit-Trail & Exporteerbaarheid (Audit Trail & Exportability)

The system SHALL maintain an immutable audit-trail for each declaratie, recording:
- Submission timestamp + employee + form snapshot
- OCR result + confidence + any manual overrides
- Every approval-step decision + timestamp + approver + comment
- WKR-budget impact (consumption delta, budget % at time of approval)
- Routing decision (AP entry ID, payroll run ID, SEPA-batch reference)
- Payment confirmation timestamp (when shillinq confirms SEPA dispatch)

The system SHALL export audit-trails in CSV/JSON format, filterable by employee, period, category, and WKR-class, for Belastingdienst review and internal audit.

### Scenario: Export Audit Trail for 2026-Q2

- **WHEN** finance requests an export for "2026-04-01 to 2026-06-30, all employees, all categories"
- **THEN** the system generates a CSV with columns:
  - declaratie_id, employee_id, datum_uitgave, bedrag_incl_btw, categorie, wkr_classificatie
  - ingediend_op, approver_1, approval_1_timestamp, approver_2, approval_2_timestamp, ...
  - goedgekeurd_op, routering_destination (AP|payroll), shillinq_entry_id, payroll_run_id
  - audit_trail (JSON sub-field with all state-changes)
- **AND** the CSV is downloadable + reproducible for audit-proof

---

## REQ-EXP-011: Valuta-Conversie voor Buitenlandse Declaraties (Foreign Currency Conversion)

The system SHALL accept `Declaratie` entries in non-EUR currencies. On submission, the system SHALL:
1. Auto-fetch the ECB reference-rate for the expense-date (`datum_uitgave`) from openconnector
2. Store both original currency + amount and EUR equivalent
3. Use EUR amount for approval-thresholds and WKR-classification
4. Display both amounts on approval screens and in audit-trail

If the ECB rate is unavailable (rare currency), the system SHALL prompt the employee for a manual rate + source and allow submission with a warning that the rate must be confirmed by finance before approval.

### Scenario: USD Declaratie with ECB Rate Lookup

- **WHEN** an employee submits a declaratie of 250 USD, dated 2026-04-15
- **THEN** the system calls openconnector for the ECB reference-rate on 2026-04-15 (e.g., 1.0892 EUR/USD)
- **AND** the system calculates EUR equivalent: 250 / 1.0892 = €229.49
- **AND** the form stores: `valuta: "USD"`, `bedrag_original: 250.00`, `bedrag_eur: 229.49`, `valuta_koers_eur: 1.0892`
- **AND** approval-thresholds use €229.49; the approver sees both amounts in the detail view

---

## REQ-EXP-012: Mobile-First Scan-tot-Indienen-Flow (Mobile Scan-to-Submit Workflow)

The system SHALL support a mobile-first workflow via Nextcloud Mobile that completes a declaratie submission in ≤4 taps:
1. Camera scan (receipt photo) → upload + OCR trigger
2. Category selection from MRU (most-recently-used) list, with suggestion-engine fallback for new vendors
3. Zakelijk_doel entry (pre-populated from prior declaraties for same vendor if available)
4. Submit button

All metadata fields except category + zakelijk_doel SHALL be pre-filled by OCR. The app SHALL show a progress bar ("1/4 → 2/4 → 3/4 → 4/4: Ingediend").

### Scenario: Repeat Vendor - MRU + Suggestion

- **WHEN** an employee returns to the Nextcloud Mobile declaratie-form after completing a prior declaratie from "Restaurant De Kas"
- **THEN** the form displays an MRU quick-select button "De Kas (laatste: 2026-05-12)"
- **AND** on tap, the form pre-fills `leverancier` + pre-suggests `categorie: "representatie"` (from prior entry)
- **AND** the employee can accept or override in 1 tap

### Scenario: New Vendor - OCR-Based Suggestion

- **WHEN** an employee scans a receipt from "Ergon Werkmeubelen" (not in prior history)
- **THEN** the system uses OCR (`leverancier` + `btw_tarief`) to suggest `categorie: "werkgeverskosten"` (heuristic: office-furniture + 21% VAT → likely workplace expense)
- **AND** the employee can accept or override in 1 tap

---

