# Tasks: DGA Gebruikelijk-loon Flow

**Change ID**: dga-gebruikelijk-loon  
**Version**: 1.0  
**Authored**: 2026-05-24

## Work Breakdown

### Phase 1: Data Model & Backend

#### Task 1.1: Define OpenRegister Schemas

- [ ] Create `DgaConfiguration` schema in hrmq_register.json
  - Fields: threshold_year, threshold_amount_eur, eligible_salary_components, audit_frequency, alert_thresholds, detect_pseudo_salary, enabled
  - Add to `components.schemas`
  - Mark as application type (not mock)

- [ ] Create `DgaMedewerker` schema in hrmq_register.json
  - Fields: medewerker_id, dga_type, ownership_percentage, vennootschap_id, designation_date, guideline_salary_threshold, eligible_salary_components, notes
  - Add foreign-key pattern (object references, not embeddings)

- [ ] Create `DgaToetsRun` schema
  - Fields: run_date, period_end, triggered_by, configuration_snapshot, medewerker_results (array), flagged_count, report_status, audit_trail
  - Sub-object: DgaToetsResult (medewerker_id, name, threshold, ytd, margin, flag_status, vennootschap_dist, pseudo_risk, notes)

- [ ] Extend `Vennootschap` schema with DGA fields
  - Add optional: `dga_medewerkers[]`, `dividend_policy` (enum)
  - Verify backward compatibility (new fields optional)

- [ ] Verify schema.org vocabulary compliance and PascalCase naming

---

#### Task 1.2: Implement DgaComplianceService

**Class**: `lib/Service/DgaComplianceService.php`

- [ ] Method: `calculateYtdSalary(medewerker_id, period_end): float`
  - Query loonstroken (payslips) for year-to-date
  - Filter by medewerker, eligible salary components (from config)
  - Sum bruto (before tax/deductions)
  - Cache result for 1 hour

- [ ] Method: `runToets(DgaConfiguration, period_end, triggered_by_user): DgaToetsRun`
  - Fetch all DGA-marked medewerkers from OpenRegister
  - For each: call calculateYtdSalary()
  - Compare against threshold (per DGA-medewerker or global config)
  - Determine flag status (green/yellow/red) based on alert thresholds
  - If detect_pseudo_salary: call detectPseudoSalary() for each DGA with vennootschap
  - Create DgaToetsRun object with results array
  - Log audit trail entry
  - Return completed run object

- [ ] Method: `detectPseudoSalary(dga_id, vennootschap_id, period_end): risk_level`
  - Query vennootschap distributions (dividends, mgmt fees) in period
  - Query DGA salary (3-month moving average)
  - Calculate risk: none/low/medium/high based on distribution ratio
  - Return result

- [ ] Write unit tests (DgaComplianceServiceTest.php)
  - Test YTD calculation with mock payslips
  - Test toets flag status (green/yellow/red boundaries)
  - Test pseudo-salary detection logic
  - Test for 0 DGA medewerkers (no-op)

---

#### Task 1.3: Implement DgaConfigurationService

**Class**: `lib/Service/DgaConfigurationService.php`

- [ ] Method: `getActiveConfiguration(year): DgaConfiguration`
  - Query OpenRegister for DgaConfiguration with matching year
  - Return first enabled config (or null if none)

- [ ] Method: `updateConfiguration(payload): DgaConfiguration`
  - Validate payload (threshold >= 40000, year > 0, etc.)
  - Save to OpenRegister via ObjectService::saveObject()
  - Log audit trail

- [ ] Method: `importDefaultsForYear(year): DgaConfiguration`
  - Create DgaConfiguration with hardcoded 2026 defaults
  - (2026: 56000 EUR, base_salary + bonus + allowance_*, monthly audit)
  - Save and return

- [ ] Write unit tests

---

#### Task 1.4: Implement DgaMedewerkerService

**Class**: `lib/Service/DgaMedewerkerService.php`

- [ ] Method: `markAsDga(medewerker_id, dga_type, vennootschap_id, notes): DgaMedewerker`
  - Validate medewerker exists (via ObjectService)
  - Create DgaMedewerker object in OpenRegister
  - Set guideline_salary_threshold from active configuration
  - Log audit trail

- [ ] Method: `unmarkDga(medewerker_id): void`
  - Delete DgaMedewerker object
  - Log audit trail

- [ ] Method: `getDgaMedewerker(medewerker_id): ?DgaMedewerker`
  - Query OpenRegister by medewerker_id

- [ ] Method: `listDgaMedewerkers(filters): array`
  - Query OpenRegister with filters: dga_type, ownership_percentage, vennootschap_id
  - Return paginated list

- [ ] Write unit tests

---

#### Task 1.5: Implement DgaReportService

**Class**: `lib/Service/DgaReportService.php`

- [ ] Method: `generatePdfReport(toets_run_id): Stream`
  - Fetch DgaToetsRun object
  - Build PDF layout:
    - Company header + logo
    - Title + run metadata
    - Summary table (total, flagged by status)
    - Per-medewerker table (paginated at 50 rows/page)
    - Legal disclaimer
  - Use TCPDF or mPDF library
  - Return stream for download

- [ ] Method: `generateExcelReport(toets_run_id): Stream`
  - Fetch DgaToetsRun object
  - Create workbook with sheets:
    - Summary (threshold, config snapshot, counts)
    - Results (full per-medewerker grid, sortable columns)
    - Audit (audit trail entries, if any)
  - Use PhpSpreadsheet
  - Return stream for download

- [ ] Method: `exportForAuditor(toets_run_id, format): Stream`
  - Call generatePdfReport() + generateExcelReport()
  - Compress into ZIP
  - Add README with instructions
  - Return ZIP stream

- [ ] Write unit tests (mock PDF/Excel output)

---

#### Task 1.6: Backend API Controller

**Class**: `lib/Controller/DgaController.php`

Endpoints:

- [ ] `POST /api/dga/configuration`
  - Update DGA configuration
  - Authorization: payroll_admin
  - Call DgaConfigurationService::updateConfiguration()

- [ ] `GET /api/dga/configuration`
  - Fetch active configuration
  - Authorization: payroll_admin
  - Call DgaConfigurationService::getActiveConfiguration()

- [ ] `POST /api/dga/configuration/defaults`
  - Import default configuration for year
  - Authorization: payroll_admin
  - Call DgaConfigurationService::importDefaultsForYear()

- [ ] `POST /api/dga/medewerker/{medewerker_id}`
  - Mark medewerker as DGA
  - Request body: dga_type, ownership_percentage, vennootschap_id, notes
  - Authorization: payroll_admin
  - Call DgaMedewerkerService::markAsDga()

- [ ] `DELETE /api/dga/medewerker/{medewerker_id}`
  - Unmark DGA
  - Authorization: payroll_admin
  - Call DgaMedewerkerService::unmarkDga()

- [ ] `GET /api/dga/medewerker`
  - List DGA medewerkers (with filtering, pagination)
  - Authorization: payroll_admin
  - Call DgaMedewerkerService::listDgaMedewerkers()

- [ ] `POST /api/dga/toets`
  - Run gebruikelijk-loon-toets
  - Request body: period_end (date), medewerker_ids[] (optional; if absent, run all DGA)
  - Authorization: payroll_admin
  - Call DgaComplianceService::runToets()
  - Return DgaToetsRun object (async polling supported via status field)

- [ ] `GET /api/dga/toets/{run_id}`
  - Fetch toets run result
  - Authorization: payroll_admin

- [ ] `GET /api/dga/reports`
  - List recent toets runs
  - Authorization: payroll_admin
  - Pagination support

- [ ] `GET /api/dga/reports/{run_id}/pdf`
  - Download PDF report
  - Authorization: payroll_admin
  - Call DgaReportService::generatePdfReport()

- [ ] `GET /api/dga/reports/{run_id}/excel`
  - Download Excel report
  - Authorization: payroll_admin
  - Call DgaReportService::generateExcelReport()

- [ ] `GET /api/dga/reports/{run_id}/auditor`
  - Download ZIP (PDF + Excel + README)
  - Authorization: payroll_admin
  - Call DgaReportService::exportForAuditor()

- [ ] `PATCH /api/dga/reports/{run_id}`
  - Update report status (draft → reviewed)
  - Authorization: payroll_admin

---

#### Task 1.7: Seed Data Generation

- [ ] Add 3–5 realistic DgaMedewerker objects to hrmq_register.json (seed data section)
  - Example: Anne Bakker (eigenaar, 100%, bakery), Jan Visser (aandeelhouder, 75%, consultancy), Petra Jong (vennoot, 50%, architecture)
  - Realistic salary ranges (EUR 2000–5000/month base)
  - Link to fictional vennootschap records

- [ ] Add DgaConfiguration seed data (3 variants: standard, conservative, disabled-for-testing)

- [ ] Add sample DgaToetsRun with realistic results (all flagged = green for seed)

- [ ] Verify idempotency: re-importing seed data doesn't create duplicates

---

### Phase 2: Frontend (Vue/UI)

#### Task 2.1: DGA Configuration Page (Configuratie / DGA-regels)

**File**: `src/views/DgaConfiguration.vue`

- [ ] Use `CnDetailPage` component as wrapper
- [ ] Form fields:
  - [ ] Threshold year (number input, min: 2020)
  - [ ] Threshold amount EUR (number input, min: 40000, max: 100000)
  - [ ] Eligible salary components (multi-select checkboxes)
    - Fetch available components from payroll-core-basic API
  - [ ] Audit frequency (dropdown: monthly/quarterly/biannual/annual)
  - [ ] Alert thresholds: warning % and critical %
  - [ ] Toggle: "Detect pseudo-salary distributions"
  - [ ] Toggle: "Enabled"

- [ ] Action buttons:
  - [ ] Save (calls DgaConfigurationService::updateConfiguration)
  - [ ] Import defaults for [year] (calls importDefaultsForYear)
  - [ ] Test run (calls DgaComplianceService::runToets, displays preview modal)

- [ ] Form validation:
  - [ ] Threshold amount >= 40000
  - [ ] At least one eligible component selected
  - [ ] Alert thresholds: warning < critical

- [ ] Success/error toasts on save

- [ ] Loading state on Save, Import, Test Run buttons

- [ ] Use NL Design System tokens for styling

---

#### Task 2.2: DGA Tab on Medewerker Detail

**File**: `src/components/DgaTab.vue` (embedded in medewerker detail)

- [ ] Display current DGA status (if marked)
  - [ ] DGA type, ownership %, linked vennootschap (with link)
  - [ ] Guideline threshold (EUR)
  - [ ] YTD status badge (green/yellow/red) + YTD amount
  - [ ] Last toets date

- [ ] Buttons:
  - [ ] "Mark as DGA" (if not marked) → opens form modal
  - [ ] "Unmark DGA" (if marked) → confirmation dialog
  - [ ] "Run toets for this medewerker" (if marked) → executes single-medewerker run

- [ ] Form modal (when "Mark as DGA" clicked):
  - [ ] DGA type dropdown (eigenaar/aandeelhouder/vennoot)
  - [ ] Ownership % (0–100)
  - [ ] Vennootschap autocomplete/lookup
  - [ ] Notes field (optional)
  - [ ] Save/Cancel buttons

- [ ] Edit mode: Allow changing DGA type, ownership %, vennootschap, threshold

- [ ] Audit trail widget (embedded) showing all DGA-related changes for this medewerker

---

#### Task 2.3: DGA Toets Report View

**File**: `src/views/DgaReports.vue`

- [ ] List of recent toets runs
  - [ ] Columns: Run date, period-end, flagged count, status (draft/reviewed/exported)
  - [ ] Sortable by date, flagged count
  - [ ] Filterable by status
  - [ ] Row click → detail view

- [ ] Detail view for single toets run:
  - [ ] Header: Run metadata (date, period-end, triggered-by, config snapshot)
  - [ ] Summary section:
    - [ ] Total DGA medewerkers
    - [ ] Flagged count + breakdown (green/yellow/red)
    - [ ] Alert threshold config (for this run)

  - [ ] Results grid (paginated at 50 rows):
    | Name | Threshold | YTD Salary | Margin € | Margin % | Status | Last Salary | Pseudo-Risk | Notes |
    - [ ] Sortable columns (click header)
    - [ ] Filterable by status (green/yellow/red)
    - [ ] Searchable by medewerker name
    - [ ] Row drill-down (click name): show medewerker detail with contract history + salary components breakdown

  - [ ] Action buttons:
    - [ ] Download PDF
    - [ ] Download Excel
    - [ ] Print
    - [ ] Mark as "reviewed" (disabled if already reviewed)
    - [ ] Export for auditor (ZIP download)

  - [ ] Status badge (green/yellow/red) uses NL Design tokens for color

- [ ] Audit trail widget at bottom (showing all changes to this run)

---

#### Task 2.4: DGA Medewerker List Filter (in Medewerkers view)

**File**: `src/components/MedewerkerListFilters.vue` (extend existing)

- [ ] Add filter: "DGA status" (None / Yes / No)
- [ ] Add column to medewerker list table:
  - [ ] DGA status badge (if marked, show icon + type)
  - [ ] Ownership % (if DGA)
  - [ ] YTD status (if DGA, show green/yellow/red + EUR)

- [ ] Bulk action: "Run toets for selected" (if 1+ DGA medewerkers selected)

---

#### Task 2.5: Run Toets Dialog / Confirmation

**File**: `src/components/DgaToetsDialog.vue`

- [ ] Modal dialog when user clicks "Run toets" (from anywhere)
- [ ] Fields:
  - [ ] Period-end date picker (defaults to today)
  - [ ] If single medewerker: show name + current YTD
  - [ ] If multiple: show "Selected: N medewerkers"

- [ ] Buttons: Cancel, Run

- [ ] On Run:
  - [ ] Call API endpoint `/api/dga/toets`
  - [ ] Show loading spinner + estimated time ("Processing... < 5s for 100 medewerkers")
  - [ ] On success: redirect to report detail view
  - [ ] On error: show error toast + error details

- [ ] Support async polling (toets may be background job)
  - [ ] Poll status every 1s until complete

---

### Phase 3: Integration & Testing

#### Task 3.1: Integration with Salarisrun & Loonstroken

- [ ] Update SalarisrunService to expose:
  - [ ] Method: `getYtdSalary(medewerker_id, period_end, component_filter)` for DGA use
  - [ ] Query loonstroken (payslips) by medewerker + year-to-date

- [ ] Verify integration with payroll-core-basic:
  - [ ] Salary component definitions exposed via API
  - [ ] Payslips available for querying (finalized status)

- [ ] Create integration test:
  - [ ] Create test medewerker + payslips
  - [ ] Mark as DGA
  - [ ] Run toets
  - [ ] Verify YTD calculation matches payslips

---

#### Task 3.2: Integration with Vennootschap (Distributions)

- [ ] Identify where dividend runs, management fees, loan repayments are recorded
- [ ] Create method to query vennootschap distributions:
  - [ ] Query dividend-run module (if separate) or journal entries (if accounting-driven)
  - [ ] Filter by vennootschap + period
  - [ ] Sum amounts

- [ ] Integration test:
  - [ ] Create vennootschap + linked DGA medewerker
  - [ ] Record dividend in period
  - [ ] Run pseudo-salary detection
  - [ ] Verify risk level is calculated

---

#### Task 3.3: Data Migration (if needed)

- [ ] Check if existing medewerkers have DGA markers (legacy data)
  - [ ] If yes: create migration script to map to DgaMedewerker schema
  - [ ] If no: skip

---

#### Task 3.4: Automated Tests

**File**: `tests/Service/DgaComplianceServiceTest.php`

- [ ] Test calculateYtdSalary:
  - [ ] Correct sum with 5 payslips
  - [ ] Filter by eligible components
  - [ ] Zero for no payslips
  - [ ] Cache hit (second call returns cached result)

- [ ] Test runToets:
  - [ ] Correct flag status (green/yellow/red) for given margin
  - [ ] Detects all 3 DGA medewerkers in test set
  - [ ] Snapshot captures config immutably

- [ ] Test detectPseudoSalary:
  - [ ] Risk = none (no distributions)
  - [ ] Risk = low (< 50% threshold)
  - [ ] Risk = medium (50–99% threshold)
  - [ ] Risk = high (>= threshold)

- [ ] Test configuration:
  - [ ] Import defaults for 2026
  - [ ] Update configuration + audit trail
  - [ ] Persist across requests

**File**: `tests/Controller/DgaControllerTest.php`

- [ ] Test POST /api/dga/configuration (update)
- [ ] Test GET /api/dga/configuration (fetch)
- [ ] Test POST /api/dga/medewerker/{id} (mark DGA)
- [ ] Test DELETE /api/dga/medewerker/{id} (unmark)
- [ ] Test POST /api/dga/toets (run toets)
- [ ] Test GET /api/dga/reports/{id}/pdf (download)

---

#### Task 3.5: Browser / E2E Tests

**File**: `tests/E2E/DgaFlowTest.php` (Nextcloud browser-test framework)

- [ ] Scenario 1: Mark employee as DGA, run toets, verify report
- [ ] Scenario 2: Update configuration, re-run toets, verify new threshold applied
- [ ] Scenario 3: Download PDF report, verify format + content
- [ ] Scenario 4: Detect pseudo-salary, verify risk badge

---

### Phase 4: Documentation & Deployment

#### Task 4.1: API Documentation

- [ ] Document all DGA endpoints in OpenAPI/Swagger format
- [ ] Example requests/responses for each endpoint
- [ ] Authorization requirements per endpoint
- [ ] Error codes

---

#### Task 4.2: User Documentation

- [ ] README: DGA Gebruikelijk-loon Feature
  - [ ] Placement & access (Configuratie / DGA-regels, Medewerker detail, Salarissen)
  - [ ] How to mark employee as DGA
  - [ ] How to configure thresholds
  - [ ] How to run toets
  - [ ] How to interpret report + export

- [ ] FAQ (e.g., "Why did my threshold change?", "What does pseudo-salary risk mean?")

---

#### Task 4.3: Admin Configuration Guide

- [ ] Step-by-step: Set up DGA rules for 2026
- [ ] Select eligible salary components (best practices)
- [ ] Set alert thresholds (conservative vs. standard)
- [ ] Enable/disable pseudo-salary detection

---

#### Task 4.4: Deduplication Check

- [ ] Search `openspec/specs/` and `openregister/lib/Service/` for overlap with:
  - [ ] ObjectService (CRUD for DgaMedewerker, DgaConfiguration, DgaToetsRun)
  - [ ] ObjectService.findAll, search (DGA medewerker list filtering)
  - [ ] AuditTrailService (change logging)
  - [ ] FileService (PDF/Excel export storage)
  - [ ] ReportService (generic report generation)

- [ ] Document findings:
  - [ ] ObjectService: Use for all DGA object CRUD (no custom impl)
  - [ ] AuditTrailService: Use for all audit logging (no custom audit table)
  - [ ] FileService: Use for storing PDF/Excel exports
  - [ ] CnDetailPage, CnDataTable: Use for configuration page and results grid (no custom components)
  - [ ] **No deduplication conflicts found** ✓

---

#### Task 4.5: Compliance Verification

- [ ] Verify ADR-001 (Information Architecture) compliance:
  - [ ] Placement type: SETTING + ACTION ✓
  - [ ] No new top-level menu (lives under Configuratie) ✓
  - [ ] No sibling portal (single config surface) ✓

- [ ] Verify ADR-010 (NL Design System) compliance:
  - [ ] All UI uses nldesign tokens (not hardcoded colors) ✓
  - [ ] Theme switching supported ✓
  - [ ] WCAG AA conformance (a11y testing) ✓

- [ ] Verify ADR-001 data-layer compliance:
  - [ ] All domain data in OpenRegister (not custom Entity) ✓
  - [ ] Use ObjectService, AuditTrailService, FileService (no custom CRUD) ✓
  - [ ] Seed data includes 3–5 realistic objects per schema ✓

---

#### Task 4.6: Ship & Deploy

- [ ] Merge PR to main branch
- [ ] Create GitHub Release (tag: v1.0.0-dga-gebruikelijk-loon)
- [ ] Deploy to staging
- [ ] Deploy to production
- [ ] Update CHANGELOG
- [ ] Announce feature in release notes

---

## Deduplication Check (Summary)

| Capability | Provider | Reuse Status |
|---|---|---|
| DGA-medewerker CRUD | ObjectService | ✓ Use directly |
| DGA-configuration CRUD | ObjectService | ✓ Use directly |
| DGA-toets-run CRUD | ObjectService | ✓ Use directly |
| Change logging | AuditTrailService | ✓ Automatic via OpenRegister |
| List filtering + pagination | ObjectService + CnDataTable | ✓ Use directly |
| Report generation (PDF/Excel) | FileService + export libs | ✓ Use for storage; custom for template |
| Configuration form | CnDetailPage | ✓ Use directly |
| Results grid | CnDataTable | ✓ Use directly |
| Detail page | CnDetailPage | ✓ Use directly |

**Conclusion**: No custom CRUD, search, audit, or state management required. All provided by platform. Only custom code: DgaComplianceService (domain-specific YTD + flag logic), report template generation (PDF layout + Excel formatting).

---

## Seed Data Generation Task

- [ ] Write seed data import task:
  - [ ] Load 3 DgaConfiguration objects (standard, conservative, test-disabled)
  - [ ] Load 3 DgaMedewerker objects (Anne, Jan, Petra with realistic salary)
  - [ ] Load 1 DgaToetsRun (May 2026 with all-green results)
  - [ ] Verify idempotency (re-import skips existing objects)
  - [ ] Document in design.md under "Seed Data" section ✓

---

## Summary

**Total tasks**: 23 (sub-tasks: ~70)  
**Estimated effort**:
- Phase 1 (Backend): 40% — 16 days
- Phase 2 (Frontend): 35% — 14 days
- Phase 3 (Testing): 15% — 6 days
- Phase 4 (Docs): 10% — 4 days

**Total**: ~40 development days (5–6 weeks with team of 2)

**Critical path**: Task 1.1 (schemas) → 1.2–1.5 (services) → 2.1–2.5 (UI) → 3.1–3.5 (tests) → 4.1–4.6 (ship)
