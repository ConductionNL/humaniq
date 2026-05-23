# Specification: ZZP Eenmanszaak Mode (Geen Werknemers)

---

## REQ-001: Mode Flag at Organisation Onboarding

**Description:** When a new tenant signs up for hrmq and selects a self-employed legal form (eenmanszaak, VOF, or BV-DGA), the organisation is created with `hrm_mode=zzp` and the user is taken directly to the personal dashboard, bypassing the team-selection screen.

### Scenario 1.1: New ZZP Tenant Onboarding

- **GIVEN** a new user is signing up for hrmq
- **WHEN** they reach the "Organisation Type" step and select "Eenmanszaak" from the legal-form picker
- **THEN** the organisation is created with:
  - `legal_form = "eenmanszaak"`
  - `hrm_mode = "zzp"` (computed)
  - `employee_count = 0`
- **AND** they are redirected to the personal dashboard (no team-selection screen)
- **AND** a TaxContext record is created with:
  - `context_type = "ib"` (inkomstenbelasting)
  - `fiscal_partner = false` (default)
  - `for_active = false` (default, user may enable later)
  - `urencriterium_target = 1225`

### Scenario 1.2: New BV-DGA Tenant Onboarding

- **GIVEN** a new user is signing up for hrmq
- **WHEN** they select "BV (met alleenstaande DGA)" from the legal-form picker
- **THEN** the organisation is created with:
  - `legal_form = "bv_dga"`
  - `hrm_mode = "zzp"` (for now; future dga_only mode)
  - `employee_count = 0`
- **AND** they are redirected to the personal dashboard

### Scenario 1.3: New Employer Tenant Onboarding (Control)

- **GIVEN** a new user is signing up for hrmq
- **WHEN** they select "BV (met werknemers)" or "Stichting" from the legal-form picker
- **THEN** the organisation is created with:
  - `legal_form = "bv_employer"` (or equiv)
  - `hrm_mode = "employer"` (default payroll mode)
  - `employee_count = 0`
- **AND** they are redirected to the payroll onboarding flow (CAO selection, loonheffingennummer, etc.)
- **AND** a TaxContext record is created with:
  - `context_type = "lb"` (loonbelasting)

---

## REQ-002: Hide Payroll Modules in ZZP Mode

**Description:** When an organisation is in `hrm_mode=zzp`, the navigation excludes payroll-related modules and the API rejects writes to payroll endpoints.

### Scenario 2.1: Navigation Visibility in ZZP Mode

- **GIVEN** an organisation with `hrm_mode=zzp`
- **WHEN** any authenticated user loads the hrmq app
- **THEN** the navigation menu does NOT include:
  - "Salarissen" (Payroll / Loonruns)
  - "Loonbelasting-aangifte" (UPA / Tax filing)
  - "CAO's & regelingen" submenu item under Configuratie
  - "Verzekeringen (collective)" section
  - "ARBO-coordinator" role/page
  - "Performance-cycles" (multi-person workflows)
  - "Org-chart" / organogram
  - "Manager-portal" (if exists)
- **AND** these menus ARE visible for `hrm_mode=employer` orgs

### Scenario 2.2: API Rejection of Payroll Writes in ZZP Mode

- **GIVEN** an organisation with `hrm_mode=zzp`
- **WHEN** an authenticated user (even admin) attempts to POST/PUT/PATCH to `/api/payroll/runs` (or any payroll endpoint)
- **THEN** the server responds with:
  - HTTP 403 Forbidden
  - JSON body: `{ "error_code": "mode_restriction", "message": "Payroll features are not available in ZZP mode" }`
- **AND** no payroll record is created/modified

### Scenario 2.3: Payroll Reads in ZZP Mode (Audit Trail)

- **GIVEN** an organisation with `hrm_mode=zzp` that previously had payroll data (before mode switch)
- **WHEN** an admin user requests GET `/api/payroll/runs` (read-only)
- **THEN** the API MAY return historical payroll data (for audit/compliance purposes)
- **BUT** the UI does not surface it (navigation hidden)
- **AND** any write attempt (POST/PUT/PATCH) is rejected with 403 + mode_restriction

---

## REQ-003: Urencriterium Dashboard Widget

**Description:** A prominent dashboard widget displays the current-year billable + qualifying hours vs the 1225-hour target, with progress bar and year-end projection.

### Scenario 3.1: Urencriterium Widget Display

- **GIVEN** a ZZP-mode user on the personal dashboard in fiscal year 2025
- **WHEN** the dashboard loads
- **THEN** a widget titled "Urencriterium (1.225 uren)" appears with:
  - A progress bar showing: `current_qualifying_hours / 1225` (e.g., 450/1225 = 37%)
  - Text: "450 uren voltooid (Jan-May)" or similar date range
  - Projected year-end total based on YTD pace (e.g., "Op dit tempo: ~1,040 uren eind-jaar")
  - If projection < 1225 after October: a warning badge "⚠ Risico op doelstelling niet behaald"
  - A "Uren registreren" button linking to HourLog create form
  - A "Details" link showing breakdown by month and hour type (billable, acquisition, admin)

### Scenario 3.2: Projection Calculation

- **GIVEN** a user with 600 qualifying hours as of June 15, 2025
- **WHEN** the widget computes year-end projection
- **THEN** projection = (600 / (31+28+31+30+31+15)) × 365 = ~1,164 hours (approx)
- **AND** this is displayed as "Op dit tempo: ~1.164 uren eind-jaar"

### Scenario 3.3: Insufficient Hours Warning (Post-October)

- **GIVEN** a user with 800 qualifying hours as of October 31, 2025
- **WHEN** the dashboard computes projection
- **THEN** projection = (800 / 305) × 365 = ~956 hours (below 1225)
- **AND** a warning is displayed: "⚠ Risico op doelstelling niet behaald (geschat: 956 uren)"
- **AND** the warning disappears after October 31 or when target is reached

---

## REQ-004: Kilometerregistratie

**Description:** A mobile-friendly form logs business trips with date, from/to address, kilometers, and purpose. The system computes kilometers (via geocoder or manual entry) and applies the current-year rate (0.23 EUR/km for 2025).

### Scenario 4.1: Manual Kilometer Logging

- **GIVEN** a ZZP-mode user
- **WHEN** they navigate to "Verlof & verzuim" › "Kilometer Registration" (or Dashboard › "Log Kilometer")
- **THEN** a quick-entry form appears with fields:
  - Date (date picker, default today)
  - From address (text, autocomplete from recent routes)
  - To address (text, autocomplete)
  - Kilometers (number, optional if geocoding available)
  - Purpose (dropdown: zakelijk | commute)
  - Notes (optional, free text)
- **AND** a "Calculate" button computes kilometers if from/to are filled
- **AND** a "Save" button creates a KilometerLog record

### Scenario 4.2: Geocoded Kilometer Computation

- **GIVEN** a user fills "From: Amsterdam, Prinsengracht 255" and "To: Rotterdam, Eendrachtsplein 8"
- **WHEN** they click "Calculate"
- **THEN** the system queries a geocoding service (e.g., Google Maps Distance Matrix API)
- **AND** returns ~78 kilometers (actual road distance)
- **AND** displays: "78 km (geschat op basis van routeering)"
- **AND** the user may override if needed (manual entry)

### Scenario 4.3: Manual Override

- **GIVEN** the geocoder returns 78 km
- **WHEN** the user changes the kilometers field to 80 (e.g., return trip was slightly longer)
- **THEN** manual entry is stored as 80 km
- **AND** the "Calculate" button now shows: "80 km (handmatig ingevoerd)"

### Scenario 4.4: Kilometer Entry Validation & Storage

- **GIVEN** a user submits the form with:
  - Date: 2025-03-15
  - From/To: Amsterdam ↔ Rotterdam
  - Kilometers: 78
  - Purpose: zakelijk
- **WHEN** they click "Save"
- **THEN** a KilometerLog record is created:
  - `organisation_id` (implicit, from context)
  - `user_id` (implicit, from auth)
  - `date` = 2025-03-15
  - `from_address` = "Amsterdam, Prinsengracht 255"
  - `to_address` = "Rotterdam, Eendrachtsplein 8"
  - `kilometers` = 78
  - `purpose` = "zakelijk"
  - `rate_eur_per_km` = 0.23 (from TaxContext.default or Belastingdienst 2025 rate)
  - `created_at` = now, `updated_at` = now
- **AND** the form clears, shows "Kilometer entry saved"
- **AND** the record appears in the "Kilometer Log" list view

### Scenario 4.5: List View of Kilometer Logs

- **GIVEN** a user has logged 5 business trips
- **WHEN** they navigate to "Kilometer Log" list view
- **THEN** a table shows:
  - Date | From | To | Kilometers | Purpose | EUR Value | Actions
  - E.g.: "2025-03-15 | Amsterdam | Rotterdam | 78 | zakelijk | €17.94 | Edit/Delete"
- **AND** a subtotal row: "Total: 156 km = €35.88"
- **AND** export button (CSV) to download all entries

---

## REQ-005: IB-Tax Export (Jaaroverzicht)

**Description:** At fiscal year-end, the user requests an IB-export (jaaroverzicht) as PDF + CSV containing billable revenue, kilometers, FOR-opbouw, lijfrente-premies, and urencriterium status.

### Scenario 5.1: IB-Export Request

- **GIVEN** a ZZP-mode user in fiscal year 2025 (on or after 2025-12-31)
- **WHEN** they navigate to "Aangiftes & compliance" › "Inkomstenbelasting" › "Export Jaaroverzicht 2025"
- **THEN** a dialog appears with:
  - Date range (editable, default Jan 1 - Dec 31, 2025)
  - Format selector (PDF | CSV | Both)
  - Revenue source (if shillinq integrated: "Auto-fetch from invoices" | "Manual entry")
  - A preview of calculated figures
  - "Generate" button

### Scenario 5.2: PDF Jaaroverzicht Content

- **GIVEN** the user requests a PDF jaaroverzicht for 2025
- **WHEN** the export is generated
- **THEN** the PDF includes:
  - **Header**: Organisation name (e.g., "FreqLi Consultancy"), KvK (84715291), address, fiscal year (2025)
  - **Section 1: Billable Revenue**
    - Total revenue (from shillinq invoices if integrated, else user-entered)
    - Breakdown by quarter or month (if data available)
    - Total: €87,400
  - **Section 2: Business Hours & Urencriterium**
    - Total billable + qualifying hours: 1,245 hours
    - Target: 1,225 hours
    - Status: ✓ **Pass** (eligible for self-employed profit deduction)
  - **Section 3: Business Kilometers**
    - Total kilometers: 156 km
    - Rate: 0.23 EUR/km (per Belastingdienst 2025)
    - Total value: €35.88
  - **Section 4: Pension & Tax Savings**
    - FOR-opbouw (if for_active=true): Current stand €12,450 / max allowed €9,904 (year limit reached)
    - Lijfrente-premies: €2,500 paid in 2025
  - **Section 5: Summary for Boekhouder**
    - Suggested entries for IB-aangifte (zelfstandigenaftrek eligibility, kilometer valuation, FOR continuation, etc.)
  - **Signature line**: Generated on 2025-12-31 by FreqLi app
  - **Footer**: "Dit document is gegenereerd voor administratieve doeleinden. Raadpleeg uw boekhouder alvorens in te dienen bij de Belastingdienst."

### Scenario 5.3: CSV Jaaroverzicht Export

- **GIVEN** the user requests a CSV jaaroverzicht for 2025
- **WHEN** the export is generated
- **THEN** the CSV file contains:
  - Row headers: Date | Description | Amount (EUR) | Category | Reference
  - E.g.:
    ```
    2025-01-15 | Invoice SHI-001: Client project | 5000.00 | Revenue | shillinq-001
    2025-03-15 | Business kilometers: Amsterdam-Rotterdam | 17.94 | Expense | KM-001
    2025-06-30 | Lijfrente-premie (half-year) | 1250.00 | Pension | LJF-001
    ...
    ```
  - Summary rows at bottom:
    ```
    TOTALS | | |
    Total Revenue | | 87400.00 | | 
    Total Kilometer Expense | | 35.88 | |
    Total Pension Contributions | | 2500.00 | |
    FOR Current Stand | | 12450.00 | |
    ```

### Scenario 5.4: Revenue Source: Manual Entry (No shillinq)

- **GIVEN** a user without shillinq integration
- **WHEN** they request an IB-export
- **THEN** the dialog shows:
  - "Revenue source: Manual entry"
  - A text field: "Total revenue for 2025 (EUR)" with placeholder "e.g., 87400"
- **AND** they fill in "87400" (from their own invoicing system or bank statements)
- **AND** the export includes this figure

### Scenario 5.5: Revenue Source: shillinq Integration

- **GIVEN** a user with shillinq integration active
- **WHEN** they request an IB-export
- **THEN** the dialog shows:
  - "Revenue source: Auto-fetch from shillinq invoices"
  - A preview: "Total invoiced (shillinq): €87,400"
- **AND** the export automatically includes this figure
- **AND** the user may override if needed (e.g., "shillinq doesn't include cash payments")

### Scenario 5.6: Export file download

- **GIVEN** the export is successfully generated
- **WHEN** the user clicks "Download"
- **THEN** two files are offered:
  - `jaaroverzicht_2025_freqli.pdf` (formatted report)
  - `jaaroverzicht_2025_freqli.csv` (tabular data)
- **AND** both files are downloaded to the user's device

---

## REQ-006: FOR / Lijfrente Tracking

**Description:** For ZZP users with FOR-active=true (legacy continuation), a Pensioen screen shows current FOR-stand, annual dotation limit, and warnings about post-2024 restrictions.

### Scenario 6.1: FOR Dashboard (if for_active=true)

- **GIVEN** a ZZP-mode user with `TaxContext.for_active=true`
- **WHEN** they navigate to "Declaraties & assets" › "Pensioen" (or equivalent)
- **THEN** a screen displays:
  - Title: "Pensioen: FOR (Fonds voor Eigen Rekening)"
  - Current FOR-stand: €12,450
  - Annual dotation limit for 2025: €9,904 (9.44% × winst, capped)
  - Year-to-date dotation (2025): €4,650
  - Remaining budget: €5,254 (if winst is sufficient)
  - Status badge: "✓ Actief (alleen voortzetting)" (Active, continuation only)
  - Warning box: "🔴 **Geen nieuwe dotaties toegestaan na 2023.** Uw FOR mag alleen voortgezet of afgebouwd worden. Raadpleeg uw boekhouder voor details."
  - For reference: link to Belastingdienst FAQ on FOR phaseout

### Scenario 6.2: FOR Disabled (for_active=false)

- **GIVEN** a ZZP-mode user with `TaxContext.for_active=false`
- **WHEN** they navigate to Pensioen
- **THEN** the FOR section is hidden or shown as "Niet actief (geen FOR)"
- **AND** only Lijfrente section is prominent

### Scenario 6.3: Lijfrente Tracking

- **GIVEN** a ZZP-mode user at any time
- **WHEN** they navigate to Pensioen
- **THEN** a "Lijfrente" section shows:
  - Premiebank balance: €2,500 (from TaxContext.lijfrente_premiebank)
  - Description: "Ontvangen lijfrente-premies in 2025"
  - IB eligibility: ✓ Tax deductible (up to annual limit per Belastingdienst)
  - Link to update premiebank amount (admin-only)

---

## REQ-007: Single-User UI Simplification

**Description:** In a ZZP organisation with exactly one user, list views hide the employee filter column, create workflows pre-fill the current user, and approval workflows are auto-approved.

### Scenario 7.1: HourLog List (Single User)

- **GIVEN** a ZZP organisation with exactly one user (Alice)
- **WHEN** Alice navigates to "Verlof & verzuim" › "Uren"
- **THEN** the list view shows:
  - Columns: Date | Type | Hours | Description | Actions
  - NO "Employee" column (it would always be "Alice" anyway)
  - NO employee filter in the filter bar
  - All rows implicitly scoped to Alice

### Scenario 7.2: Create HourLog (Single User, Pre-filled)

- **GIVEN** Alice clicks "Create new hour entry"
- **WHEN** the form opens
- **THEN** the "Subject" / "Employee" field is:
  - Pre-filled with "Alice" (current user)
  - Read-only (grayed out)
  - No dropdown to select another user
- **AND** the form shows: Date, Type (billable | acquisition | admin | commute), Hours, Description, "Save" button

### Scenario 7.3: ExpenseClaim Self-Approval (Single User)

- **GIVEN** Alice submits an expense claim for €150 (e.g., office supplies)
- **WHEN** she clicks "Submit"
- **THEN** the claim transitions to "Approved" state immediately (no manager approval needed)
- **AND** an audit-log entry is created: "Claim APP-001 auto-approved (self-approval in single-user mode), 2025-03-20 14:32 by Alice"
- **AND** the claim is eligible for reimbursement or salary offset

### Scenario 7.4: List Views in Multi-User Scenario (Regression)

- **GIVEN** an Alice-only ZZP org
- **WHEN** a second user (Bob, bookkeeper) is added
- **AND** Alice reloads the HourLog list
- **THEN** the UI dynamically re-renders:
  - "Employee" column re-appears
  - Filter bar includes employee filter
  - Alice can now view/filter hours by employee (currently only Alice and Bob)

---

## REQ-008: Mode Upgrade Path (ZZP → Employer)

**Description:** When a ZZP organisation hires its first employee or changes legal form to an employer type, the system unhides payroll modules, prompts for missing payroll config, and preserves all historical ZZP data.

### Scenario 8.1: Mode Upgrade via Legal Form Change

- **GIVEN** a ZZP organisation (Alice's eenmanszaak)
- **WHEN** Alice navigates to "Configuratie" › "Administraties" and clicks "Edit Organisation"
- **AND** changes "Legal Form" from "Eenmanszaak" to "BV (met werknemers)"
- **AND** clicks "Save"
- **THEN** the system detects:
  - Old: `legal_form="eenmanszaak"`, `hrm_mode="zzp"`
  - New: `legal_form="bv_employer"`, `hrm_mode="employer"`
- **AND** a modal dialog appears:
  - Title: "Overgang naar volledig salarisbeheer"
  - Message: "U bent uw organisatievorm aan het wijzigen naar BV met werknemers. Dit schakelt hrmq over naar volledige salarisbeheer. Uw historische gegevens (uren, declaraties) worden bewaard."
  - Fields to fill in:
    - CAO (dropdown: Gemeenten, Rijk, Onderwijs PO, etc.)
    - Sektor (text or dropdown)
    - Loonheffingennummer (text, format: 6 digits)
  - Buttons: "Cancel" | "Bevestigen"

### Scenario 8.2: Payroll Config Validation & Completion

- **GIVEN** the upgrade dialog is open
- **WHEN** Alice fills in:
  - CAO: "Gemeenten"
  - Sektor: "Publieke sector"
  - Loonheffingennummer: "123456"
- **AND** clicks "Bevestigen"
- **THEN** the system validates the inputs (non-empty, loonheffingennummer format correct)
- **AND** updates the organisation:
  - `legal_form = "bv_employer"`
  - `hrm_mode = "employer"`
  - CAO configuration is saved (as a separate CAO record or config)
- **AND** TaxContext is updated:
  - `context_type = "lb"` (switched from ib)
- **AND** an audit-log entry: "Mode upgrade: zzp→employer (legal_form: eenmanszaak→bv_employer, triggered by: user, CAO: Gemeenten, date: 2026-02-15)"

### Scenario 8.3: Data Preservation After Upgrade

- **GIVEN** Alice's org had before upgrade:
  - 1,200 qualifying hours logged in 2025
  - 156 km of business trips
  - 3 expense claims
  - IB-export PDF (jaaroverzicht 2024)
- **WHEN** the upgrade is complete
- **THEN** all historical data remains:
  - HourLog records are unchanged (qualifies_for_urencriterium still true/false as before)
  - KilometerLog records are unchanged
  - ExpenseClaim records are unchanged
  - Historical exports (PDF) are archived/downloadable

### Scenario 8.4: Navigation Un-hiding After Upgrade

- **GIVEN** Alice has upgraded to employer mode
- **WHEN** she reloads the hrmq app
- **THEN** navigation now includes:
  - "Salarissen" menu (Payroll, Loonruns, Slips, etc.)
  - "Loonbelasting-aangifte" (UPA filing)
  - "CAO's & regelingen" (now showing "Gemeenten" as active CAO)
  - "ARBO-coordinator" role option
  - "Org-chart" (now relevant if employee count > 1)

### Scenario 8.5: Mode Upgrade via Employee Add (Future)

- **GIVEN** Alice's ZZP org (no employees)
- **WHEN** she navigates to "Medewerkers" › "Add Employee" and adds Bob as a full-time employee
- **THEN** the system detects:
  - New `employee_count` = 1
  - `legal_form` is still "eenmanszaak" (unchanged)
  - But `hrm_mode` should switch to "employer" (because employee_count > 0)
- **AND** the same upgrade flow is triggered (mode promotion)

---

## Cross-functional Scenarios

### Scenario A: Full ZZP Fiscal Year Workflow

- **GIVEN** Alice starts a new ZZP in January 2025
- **WHEN** over 12 months she:
  1. Logs billable hours (800 hrs), acquisition (200 hrs), admin (250 hrs) → 1,250 hrs total
  2. Logs business kilometers (156 km) → €35.88 value
  3. Pays lijfrente-premies (€2,500)
  4. Maintains FOR continuation (€4,650 dotation in 2025)
- **AND** on 2025-12-31 she requests IB-export
- **THEN** the export shows:
  - ✓ 1,250 qualifying hours (pass urencriterium)
  - €35.88 business km deduction
  - €2,500 pension contributions
  - FOR-stand €12,450 (continuation only)
  - Status: eligible for full zelfstandigenaftrek
- **AND** she can hand this PDF to her boekhouder for IB-filing

### Scenario B: ZZP Growth to Employer

- **GIVEN** Alice's ZZP has grown; she wants to hire Bob as first employee
- **WHEN** she:
  1. Updates legal form to "BV met werknemers"
  2. System prompts for CAO selection
  3. She selects "Gemeenten" CAO
  4. Mode upgrades to employer, TaxContext switches to IB→LB
- **THEN** she can:
  - Add Bob as an employee
  - Run payroll (loonbelasting, premies, etc.)
  - Keep her historical ZZP data (hours, kilometers, FOR) intact
  - File UPA on behalf of Bob (now employer obligation)
  - Still access IB-export for personal tax purposes (if self-employed income continues)

