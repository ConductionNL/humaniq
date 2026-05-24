# Specifications: DGA Gebruikelijk-loon Flow

**Change ID**: dga-gebruikelijk-loon  
**Authored**: 2026-05-24  
**Spec Version**: 1.0

## Requirements

### REQ-DGA-001: DGA Medewerker Marking

**GIVEN** an HR administrator viewing a medewerker record  
**WHEN** they navigate to the DGA tab and click "Mark as DGA"  
**THEN**
- A form appears with fields: DGA type (eigenaar/aandeelhouder/vennoot), ownership %, related vennootschap (optional)
- Upon save, the medewerker is flagged as DGA in OpenRegister
- The DGA-medewerker schema object is created with reference to the medewerker
- An audit trail entry is created: "DGA status: added by {user} on {date}"

**Acceptance Criteria:**
- DGA type is required; ownership % and vennootschap are optional
- Ownership % must be 0–100
- Unmarking DGA deletes the DGA-medewerker object and logs removal
- Medewerker can be queried by DGA status in list view

---

### REQ-DGA-002: DGA Configuration Page

**GIVEN** an admin opening Configuratie / DGA-regels  
**WHEN** they view the current configuration  
**THEN**
- The page displays:
  - Threshold year (read-only; auto-set to current year, editable)
  - Threshold amount in EUR (default: 56000, editable)
  - Eligible salary components (multi-select checkboxes)
  - Audit frequency (dropdown: monthly/quarterly/biannual/annual)
  - Alert thresholds: warning margin % and critical margin % (editable)
  - Toggle: "Detect pseudo-salary distributions"
- A "Save" button persists changes
- A "Import defaults for [year]" button resets to Dutch statutory defaults
- A "Test run" button executes a toets on all DGA medewerkers for preview

**Acceptance Criteria:**
- Threshold amount must be >= 40000 EUR
- Eligible components list includes all salary component slugs available in payroll-core-basic
- Changes logged via AuditTrailService
- Configuration snapshot is immutable once a toets run is executed (for audit)

---

### REQ-DGA-003: Gebruikelijk-loon-toets Execution

**GIVEN** a payroll administrator at the toets run trigger point (Salarissen > Loonruns or medewerker detail)  
**WHEN** they click "Run gebruikelijk-loon-toets"  
**THEN**
- A confirmation dialog appears: "Run toets as of which date? [date picker, defaults to today]"
- Upon confirmation, the system:
  1. Retrieves active DgaConfiguration for the current year
  2. For each DGA-marked medewerker:
     - Queries YTD salary (sum of eligible components) as of the selected date
     - Compares against threshold
     - Calculates remaining margin (EUR and %)
     - Flags status: green (compliant), yellow (margin < warning %), red (margin < critical %)
  3. If "Detect pseudo-salary" is enabled: queries vennootschap for distributions (dividends, mgmt fees) in same period
  4. Creates a DgaToetsRun object with results
  5. Redirects to report detail view
- A toast notification: "Toets completed: {count} DGA medewerkers, {flagged} flagged"

**Acceptance Criteria:**
- Toets completes in < 5 seconds for 100 DGA medewerkers
- YTD calculation uses only eligible salary components (per configuration)
- Threshold per medewerker is customizable (per DGA-medewerker record)
- Configuration state captured in run snapshot (immutable)
- Pseudo-salary detection marks risk level: none/low/medium/high
- Run is async; status updated via WebSocket or polling

---

### REQ-DGA-004: DGA Toets Report View

**GIVEN** a completed DGA-toets-run  
**WHEN** the user opens the report detail page  
**THEN**
- The report displays:
  - Run date, period-end date, triggered-by user
  - Summary: total DGA medewerkers, flagged count, status breakdown (green/yellow/red)
  - Per-medewerker grid:
    | Name | Threshold | YTD Salary | Margin € | Margin % | Status | Last Salary | Pseudo-Risk | Notes |
    | — | — | — | — | — | — | — | — | — |
  - Each row is sortable and filterable by status
  - Drill-down: Click medewerker row → detail with contract history, salary components breakdown, related vennootschap info
- Action buttons:
  - Download PDF (audit-ready format with company header + legal disclaimer)
  - Download Excel (one sheet per toets, each with full per-medewerker detail)
  - Print
  - Mark as "reviewed" (button sets report_status to "reviewed")
  - Export for auditor (PDF + Excel, compiled format for tax consultant)

**Acceptance Criteria:**
- Status badge colors use NL Design tokens (success/warning/error)
- PDF includes company name, report date, threshold config snapshot, disclaimer
- Excel includes audit-trail notes column
- Medewerker names are clickable → redirect to medewerker detail with DGA tab
- Report is read-only once marked "reviewed" (but audit trail remains editable)
- Large reports (500+ medewerkers) paginate at 50 per page

---

### REQ-DGA-005: YTD Salary Calculation

**GIVEN** a DGA-medewerker and a period-end date  
**WHEN** DgaComplianceService.calculateYtdSalary() is called  
**THEN**
- The system sums salary components from all loonstroken (payslips) for the year to date (Jan 1 — period-end)
- Only components listed in DGA-configuration.eligible_salary_components are included
- Deductions (tax, social contributions) are NOT subtracted (bruto only)
- Result is EUR, rounded to nearest 0.01

**Acceptance Criteria:**
- Calculation filters by medewerker, salary year, and component type
- Uses SalarisrunService to fetch finalized payslips (status: "finalized")
- Excludes draft/cancelled payslips
- Caches result for 1 hour to avoid repeated DB queries during report generation

---

### REQ-DGA-006: Pseudo-Salary Detection

**GIVEN** a DGA-medewerker with a linked vennootschap  
**WHEN** DgaComplianceService.detectPseudoSalary() is called during toets  
**THEN**
- The system queries the vennootschap record for distributions in the same period:
  - Dividend payments (via dividend-run or journal entry)
  - Management fees (coded as "mgmt_fee")
  - Loan repayments (coded as "loan_repay")
- Pseudo-salary risk is flagged if:
  - Distributions > 0 AND salary decreased month-on-month (compared to prior 3 months average), OR
  - Distributions > 50% of guideline threshold in same month
- Risk level: none (< threshold), low (50–75%), medium (75–99%), high (>= threshold)
- Result included in DgaToetsResult

**Acceptance Criteria:**
- Pseudo-salary detection is optional (can be disabled per configuration)
- Risk calculation is deterministic and auditable
- Distribution sum is logged in toets result for review

---

### REQ-DGA-007: Related-Company Vennootschap Extension

**GIVEN** an existing Vennootschap record  
**WHEN** OpenRegister schemas are loaded for hrmq  
**THEN**
- Vennootschap schema is extended with:
  - `dga_medewerkers[]`: array of DGA-medewerker object IDs (for display/linking)
  - `dividend_policy`: enum ("conservative" | "standard" | "aggressive") for risk scoring
- Existing vennootschap records migrate smoothly (new fields are optional)
- UI displays linked DGA medewerkers on vennootschap detail (read-only widget)

**Acceptance Criteria:**
- Schema extension is non-breaking (new fields are optional)
- Vennootschap detail page shows related DGA medewerkers
- DGA-medewerker detail shows linked vennootschap (clickable link)

---

### REQ-DGA-008: Configuration Import & Defaults

**GIVEN** an admin on the DGA-regels page  
**WHEN** they click "Import defaults for 2026"  
**THEN**
- A pre-filled DgaConfiguration is loaded:
  - Threshold year: 2026
  - Threshold amount: 56000 EUR (per statutory 2026 standard)
  - Eligible components: base_salary, bonus, allowance_housing, allowance_transport
  - Audit frequency: monthly
  - Warning margin: 10%
  - Critical margin: 25%
  - Detect pseudo-salary: enabled
- The form is populated and marked as "unsaved" (dirty state)
- User can edit before saving

**Acceptance Criteria:**
- Defaults are configurable per year (hardcoded for 2026, extensible for future years)
- Import does not overwrite current config if user cancels
- Audit log: "DGA defaults imported for 2026 by {user}"

---

### REQ-DGA-009: DGA List & Filter in Medewerkers

**GIVEN** the Medewerkers list view  
**WHEN** user applies a filter for "DGA status = yes"  
**THEN**
- The list displays only DGA-marked medewerkers
- Each row shows: Name, DGA type, ownership %, vennootschap (if linked), current YTD status
- Quick action: "Run toets for selection" (runs toets for checked rows only)

**Acceptance Criteria:**
- Filter persists in session
- Bulk action works for 50+ medewerkers
- Results show current cached YTD (not stale)

---

### REQ-DGA-010: Audit Trail & Change Logging

**GIVEN** any DGA-related change (DGA marking, configuration update, toets run)  
**WHEN** the change is persisted  
**THEN**
- AuditTrailService automatically logs:
  - Object type (DgaMedewerker, DgaConfiguration, DgaToetsRun)
  - User ID and timestamp
  - Before/after snapshot
  - Change description (e.g., "DGA type: changed from 'eigenaar' to 'aandeelhouder'")
- Audit trail is immutable and visible to payroll admins

**Acceptance Criteria:**
- All DGA-related objects support audit trails (auto via OpenRegister)
- Audit entries include object ID, property name, old/new value
- Export report includes audit summary

---

### REQ-DGA-011: Report Export Formats

**GIVEN** a DGA-toets report  
**WHEN** user clicks "Download PDF" or "Download Excel"  
**THEN**
- **PDF**: Single-page or multi-page report with:
  - Company logo/header (configurable per tenant)
  - Title: "DGA Gebruikelijk-loon-toets Report"
  - Run date, period-end, triggered-by user
  - Summary table: total, flagged breakdown
  - Per-medewerker table (2-page layout if needed)
  - Legal disclaimer: "This report is for internal compliance review only"
  - Footer: page numbers, export date
- **Excel**: Multi-sheet workbook:
  - Sheet 1: Summary (counts, thresholds, configuration)
  - Sheet 2: Per-medewerker results (all columns exportable)
  - Sheet 3: Audit notes (if any)
- File naming: `dga-toets-{period_end}-{run_date}.pdf` / `.xlsx`

**Acceptance Criteria:**
- PDF renders correctly on A4 (portrait); no page breaks mid-row
- Excel uses NL locale (date format: dd-mm-yyyy, number separator: comma)
- Both formats include medewerker names and company context
- Exports are generated asynchronously (>100 rows)

---

## Acceptance Test Scenarios

### Scenario 1: Mark Employee as DGA, Run Toets, Verify Compliance

**Setup**: Employee "Jan Visser" with base salary EUR 3000/month

**Steps**:
1. Open Jan Visser medewerker detail
2. Go to DGA tab
3. Click "Mark as DGA", select type "aandeelhouder", ownership 75%, save
4. Navigate to Configuratie / DGA-regels
5. Verify threshold is 56000 EUR
6. Return to payroll, click "Run toets as of May 31, 2026"
7. Confirm toets execution

**Expected**:
- Toets completes
- Report shows Jan: threshold 56000, YTD (5 months × 3000) = 15000, margin 41000 EUR, status GREEN
- Export PDF successful

---

### Scenario 2: Detect Pseudo-Salary Distribution

**Setup**: Anne Bakker (DGA, linked to vennootschap "Bakkerij Innovatie") with EUR 2000/month salary, receives EUR 10000 dividend in May

**Steps**:
1. Run toets as of May 31, 2026 with "Detect pseudo-salary" enabled
2. View report detail

**Expected**:
- Report shows Anne: YTD salary 10000 (5 × 2000), but pseudo-salary risk = "medium" (dividend = 50% of month salary)
- Notes column shows: "May distribution: EUR 10000 + salary EUR 2000 same period; monitor for avoidance scheme"

---

### Scenario 3: Configuration Override for Conservative Firm

**Setup**: HR admin for firm with strict DGA compliance policy

**Steps**:
1. Open Configuratie / DGA-regels
2. Modify threshold to 60000 EUR (conservative)
3. Set warning margin to 15%, critical to 30%
4. Save changes
5. Run toets

**Expected**:
- Configuration saved with audit trail
- New toets uses 60000 threshold for all DGA medewerkers
- Historical toets (with 56000 threshold) preserves old config in snapshot

---

### Scenario 4: Export for Tax Consultant

**Setup**: Quarterly audit required for holding structure with 3 DGA medewerkers

**Steps**:
1. Run toets as of 2026-06-30
2. Review report, mark as "reviewed"
3. Click "Export for auditor"
4. Select format: PDF + Excel
5. Save to folder

**Expected**:
- Files generated: `dga-toets-2026-06-30-pdf.pdf` and `.xlsx`
- PDF includes company details, compliance summary, per-medewerker status, disclaimer
- Excel includes full audit trail + notes
- Files are archive-ready for tax consultant

---

## Non-Functional Requirements

### Performance

- Toets run for 100 DGA medewerkers: < 5 seconds (cached YTD queries)
- Report view (grid of 100 rows): < 2 seconds (paginated at 50)
- Export (PDF for 100 medewerkers): < 10 seconds (async)

### Accessibility

- WCAG AA conformance
- Status badges have text labels (not color-only)
- Keyboard navigable (all buttons, filters, drills)
- Screen reader support for results grid

### Localization

- All text in Dutch (NL)
- Date format: dd-mm-yyyy
- Number format: 12.345,67 (NL locale)
- Currency: EUR (€ symbol)

### Security

- Only payroll_admin role can run toets or edit configuration
- Only hr_manager role can view (read-only) reports
- Exports are PII-containing (require download, not inline preview)
- Audit trail immutable

---

## Out of Scope (Noted for Future)

- Real-time YTD updates (calculated on-demand per toets run)
- Automatic salary correction (manual review + HR approval required)
- Tax filing integration (separate spec: tax-reporting-integration)
- Historical threshold lookups (2020–2025); baseline is 2026 forward
- API for external auditor access (future enhancement)
