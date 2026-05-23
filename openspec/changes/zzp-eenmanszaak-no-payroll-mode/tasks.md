# Tasks: ZZP Eenmanszaak Mode (Geen Werknemers)

---

## 1. Data Layer: Schemas & Migrations

- [ ] 1.1 Create migration: add `Organisation.legal_form` enum column (source: KvK codes)
  - Values: eenmanszaak, vof, bv_dga, bv_employer, stichting, vereniging, cooperatie
  - Populate existing orgs: default to bv_employer (maintain backward compatibility)

- [ ] 1.2 Create migration: add `Organisation.hrm_mode` computed field (virtual, not persisted)
  - Logic: zzp if legal_form in {eenmanszaak, vof, bv_dga} and employee_count ≤ 0
  - else: employer
  - Document computation logic in backend ADR

- [ ] 1.3 Create TaxContext schema in OpenRegister
  - Entity: TaxContext
  - Fields: id, organisation_id, context_type (enum: ib | lb), fiscal_partner (bool), for_active (bool), lijfrente_premiebank (decimal), urencriterium_target (int), created_at, updated_at
  - Register: tax_contexts
  - Validation: context_type is immutable per org (switch only on mode upgrade)

- [ ] 1.4 Create migration: seed TaxContext for all existing organisations
  - Existing orgs (hrm_mode=employer) → context_type=lb, for_active=false, lijfrente_premiebank=0, urencriterium_target=1225
  - Non-breaking; all values are safe defaults

- [ ] 1.5 Create KilometerLog schema in OpenRegister
  - Entity: KilometerLog
  - Fields: id, organisation_id, user_id, date (date), from_address (text), to_address (text), kilometres (decimal), purpose (enum: zakelijk | commute), rate_eur_per_km (decimal), created_at, updated_at
  - Register: kilometre_logs
  - Validation: kilometres > 0, date is in the past or today

- [ ] 1.6 Create migration: add `HourLog.qualifies_for_urencriterium` boolean column
  - Default: true (all existing hours qualify, backward compatible)
  - Non-breaking migration; all existing data remains as-is

---

## 2. Backend Services

- [ ] 2.1 Create OrganisationModeService
  - Method: `detectHrmMode(Organisation $org): string` (returns zzp | dga_only | employer)
  - Method: `isZzpMode(Organisation $org): bool`
  - Method: `canUpgradeToEmployer(Organisation $org): bool` (checks if legal form change is valid)
  - Method: `upgradeToEmployer(Organisation $org, string $cao, string $sector, string $loonheffingennummer): void` (with transaction, audit log)

- [ ] 2.2 Create TaxContextService
  - Method: `getOrCreateTaxContext(string $organisationId): TaxContext` (lazy create if missing)
  - Method: `updateContextType(string $organisationId, string $contextType): void` (on mode upgrade)
  - Method: `updateForActive(string $organisationId, bool $forActive): void`
  - Method: `updateLijfrenteBank(string $organisationId, float $amount): void`
  - All methods include audit logging

- [ ] 2.3 Create UrencriteriumService
  - Method: `getYearToDateHours(string $organisationId, int $year): int` (sum HourLog where qualifies_for_urencriterium=true and year(date)=$year)
  - Method: `getProjectedYearEndHours(string $organisationId, int $year): int` (extrapolate based on YTD pace)
  - Method: `meetsTarget(string $organisationId, int $year): bool` (>= 1225 hours)
  - Method: `getWarningThreshold(int $year): string` (post-October warning logic)

- [ ] 2.4 Create KilometerService
  - Method: `createKilometerLog(string $organisationId, string $userId, array $data): KilometerLog`
  - Method: `updateKilometerLog(string $kilometerId, array $data): KilometerLog`
  - Method: `deleteKilometerLog(string $kilometerId): void`
  - Method: `getYearTotalKilometers(string $organisationId, int $year): float`
  - Method: `getYearTotalEuro(string $organisationId, int $year): float` (km × rate)
  - All methods validate data and raise exceptions on invalid input

- [ ] 2.5 Create IbExportService
  - Method: `generateJaaroverzicht(string $organisationId, int $year): array` (returns data structure with revenue, km, hours, for, ljf, urencriterium status)
  - Method: `generatePdfReport(array $data): string` (binary PDF, uses TCPDF or equiv)
  - Method: `generateCsvReport(array $data): string` (CSV content)
  - Dependency: conditionally fetch revenue from shillinq if integration active (via IAppConfig)

- [ ] 2.6 Create NavigationVisibilityService
  - Method: `getVisibleMenus(string $organisationId): array` (filters menu items based on hrm_mode)
  - Method: `isMenuVisible(string $organisationId, string $menuKey): bool`
  - Returns: payroll-related menus only visible if hrm_mode=employer

- [ ] 2.7 Create GeocodeService (optional, for kilometer logging)
  - Method: `reverseGeocode(float $lat, float $lon): string` (lat/lon to address)
  - Method: `getDistance(string $fromAddress, string $toAddress): ?float` (address to address distance in km)
  - Integration: Google Maps API (configurable, fallback to no geocoding)
  - Document in external integrations list; add configuration key to IAppConfig

---

## 3. API: Controllers & Routes

- [ ] 3.1 Create KilometerLogController
  - `index()` — GET /api/kilometre-logs (paginated, scoped to org + user)
  - `show($id)` — GET /api/kilometre-logs/{id}
  - `create(Request $request)` — POST /api/kilometre-logs (create new)
  - `update($id, Request $request)` — PUT /api/kilometre-logs/{id} (update existing)
  - `delete($id)` — DELETE /api/kilometre-logs/{id}
  - All methods include @spec tags referencing this change
  - Validation: date in past/today, kilometres > 0, required fields

- [ ] 3.2 Create TaxContextController
  - `getConfig()` — GET /api/tax-context (read current org's tax config)
  - `updateContextType(Request $request)` — PUT /api/tax-context/context-type (admin-only, triggers on mode upgrade)
  - `updateForActive(Request $request)` — PUT /api/tax-context/for-active
  - `updateLijfrenteBank(Request $request)` — PUT /api/tax-context/lijfrente-premiebank
  - All methods: mode_restriction check (reject if payroll-related in ZZP mode)
  - Authorization: admin role required

- [ ] 3.3 Create IbExportController
  - `generateJaaroverzicht(Request $request)` — POST /api/ib-export/jaaroverzicht (generate for given year)
  - Accepts: fiscal_year, format (pdf | csv | both), revenue_source (auto | manual), manual_revenue (optional)
  - Returns: redirect to file download or JSON with download links
  - Includes: validation of requested year (must be completed, not future)

- [ ] 3.4 Create OrganisationController updates
  - Existing `update()` method: detect legal_form change, trigger mode upgrade flow
  - New method: `upgradeModeToEmployer(Request $request)` — POST /api/organisations/{id}/upgrade-mode
  - Accepts: cao, sector, loonheffingennummer, confirm (bool)
  - Validates payroll config completeness
  - Transactional: updates org, TaxContext, logs audit entry

- [ ] 3.5 Update PayrollController (existing)
  - All payroll endpoints: add mode_restriction check
  - If hrm_mode=zzp, reject with 403 + `error_code: "mode_restriction"`
  - Apply to: /api/payroll/*, /api/loonbelasting/*, /api/cao/*, /api/verzekeringen/*

- [ ] 3.6 Create routes in appinfo/routes.php
  - POST /api/kilometre-logs
  - GET /api/kilometre-logs
  - GET /api/kilometre-logs/{id}
  - PUT /api/kilometre-logs/{id}
  - DELETE /api/kilometre-logs/{id}
  - GET /api/tax-context
  - PUT /api/tax-context/context-type
  - PUT /api/tax-context/for-active
  - PUT /api/tax-context/lijfrente-premiebank
  - POST /api/ib-export/jaaroverzicht
  - POST /api/organisations/{id}/upgrade-mode
  - All routes: @spec tags

---

## 4. Frontend: Onboarding & Settings

- [ ] 4.1 Update onboarding: legal-form picker
  - Step: "Organisatievorm"
  - Options: Eenmanszaak | VOF | BV (DGA) | BV (met werknemers) | Stichting | etc.
  - On select "Eenmanszaak" or "BV (DGA)": redirect to personal dashboard (skip payroll setup)
  - On select "BV (met werknemers)": redirect to payroll onboarding (CAO, loonheffingennummer)
  - Component: form with radio or select, pre-filled from KvK lookup if available

- [ ] 4.2 Update Settings > Organisation > Legal Form editor
  - Show current legal_form as read-only
  - Allow change to other legal forms
  - On save: detect mode change (zzp ↔ employer)
  - If mode change: show modal dialog with upgrade confirmation + payroll config fields
  - Modal fields: CAO dropdown, sector text, loonheffingennummer
  - Buttons: Cancel | Upgrade (disabled until fields filled)

- [ ] 4.3 Update Configuratie › Administraties display
  - Show: organisation name, legal form, current mode (badge: ZZP | Employer)
  - Show: if mode=zzp, hide Payroll, Loonbelasting, CAO menus (visual hint)
  - Link: "Change legal form" (opens editor from 4.2)

---

## 5. Frontend: ZZP Workflows

- [ ] 5.1 Create Urencriterium Dashboard Widget
  - Component: CnDashboardWidget or custom Vue component
  - Display: progress bar (current / 1225 hours), YTD hours, projected year-end, warning if applicable
  - Data source: UrencriteriumService (backend)
  - Interactions:
    - "Uren registreren" button → links to /verlof-verzuim/uren/create
    - "Details" button → shows monthly breakdown or detail page
  - Position: prominent on personal dashboard (recommend top-left, above other widgets)
  - Styling: highlight in blue/green if on track, orange/red if behind or at risk

- [ ] 5.2 Create Kilometer Registration Form
  - Route: /verlof-verzuim/kilometers/create (or equivalent)
  - Fields:
    - Date picker (default today)
    - From address (text + autocomplete)
    - To address (text + autocomplete)
    - Kilometers (number, initially empty)
    - Purpose (radio: zakelijk | commute)
    - Notes (optional)
  - Actions:
    - "Calculate" button: calls GeocodeService, populates kilometers field
    - "Save" button: POSTs to /api/kilometre-logs
    - "Clear" button: resets form
  - Validation: non-empty from/to, positive kilometres, valid date
  - Success feedback: "Kilometer entry saved" toast, form resets

- [ ] 5.3 Create Kilometer Log List View
  - Route: /verlof-verzuim/kilometers
  - Columns: Date | From | To | Kilometers | Purpose | EUR Value | Actions
  - Subtotal: Total km, total EUR value (km × rate)
  - Sorting/filtering: by date, purpose
  - Actions per row: Edit | Delete
  - Export button: CSV download of all entries (date, from, to, km, purpose, eur_value)

- [ ] 5.4 Create IB-Export Dialog / Page
  - Route: /aangiftes-compliance/inkomstenbelasting/export (or equivalent)
  - Header: "Jaaroverzicht 2025" (dynamic year)
  - Sections:
    - Date range selector (default Jan 1 - Dec 31)
    - Format selector (radio: PDF | CSV | Both)
    - Revenue source selector (radio: Auto from shillinq | Manual)
    - If revenue source=Manual: text field "Total revenue (EUR)"
    - If revenue source=Auto: display "Fetching from shillinq..." or show total
    - Preview section: display calculated figures (km total, hours total, for, ljf, etc.)
  - Actions:
    - "Generate" button: calls /api/ib-export/jaaroverzicht, receives download link(s)
    - "Download PDF" / "Download CSV" buttons
    - "Cancel" button
  - Validation: year must be completed, all required fields filled

- [ ] 5.5 Create FOR / Lijfrente Dashboard Page
  - Route: /declaraties-assets/pensioen (or equivalent)
  - Sections:
    - **FOR (if for_active=true)**:
      - Current FOR-stand (EUR)
      - Annual limit (EUR, calculated from Belastingdienst 2025 tables)
      - YTD dotation (EUR)
      - Remaining budget (EUR)
      - Badge: "✓ Active (continuation only)"
      - Warning: "No new dotations allowed after 2023. Contact your boekhouder."
    - **Lijfrente**:
      - Premiebank balance (EUR)
      - Description: "Lijfrente premies paid in 2025"
      - Tax eligibility: ✓ Deductible
    - Admin-only section: buttons to update FOR-active, lijfrente-premiebank (if needed)
  - Conditional: if for_active=false, hide FOR section
  - No edit form for users; FOR config is managed by admin or boekhouder

---

## 6. Frontend: Single-User UI

- [ ] 6.1 Update HourLog list view to hide employee filter in single-user mode
  - Condition: hrm_mode=zzp AND user_count=1
  - Hide: "Employee" column, employee filter in filter bar
  - Show: all other columns/filters
  - Implicit scope: all rows are for the current user only

- [ ] 6.2 Update HourLog create form to pre-fill employee in single-user mode
  - Condition: hrm_mode=zzp AND user_count=1
  - Field: "Submitted by" / "Subject" is pre-filled with current user
  - Read-only: field is not editable (grayed out)
  - Remove: employee selector dropdown (if exists)

- [ ] 6.3 Update ExpenseClaim approval flow in single-user mode
  - Condition: hrm_mode=zzp AND user_count=1
  - Behavior: on submit, auto-approve (state = approved immediately)
  - Audit log: log the auto-approval ("self-approval in single-user mode")
  - No manager-approval step shown
  - Revert if: second user is added, ui must re-render to show approval flow again

- [ ] 6.4 Update LeaveRequest list/create in single-user mode
  - Same pattern as HourLog: hide employee filter, pre-fill current user, auto-approve

- [ ] 6.5 Dynamic re-render on user-add event
  - Listener: when Organisation.user_count changes from 1 to >1
  - Effect: refresh all list views to show employee column/filter again
  - Test: start as single-user, add a second user, verify UI updates without page reload

---

## 7. Navigation & Authorization

- [ ] 7.1 Update main navigation shell (Vue component or server template)
  - Wrap menu items with visibility guards: `v-if="isMenuVisible('salarissen')"`
  - Menu items hidden in ZZP mode:
    - Salarissen
    - Loonbelasting-aangifte
    - CAO's & regelingen (under Configuratie)
    - Verzekeringen
    - ARBO-coordinator
    - Performance-cycles
    - Org-chart
    - Manager-portal (if exists)
  - Data: fetch Organisation.hrm_mode from auth context or API

- [ ] 7.2 Update API authorization middleware
  - Create: ModeRestrictionMiddleware or add to existing AuthorizationService
  - Check: if endpoint is payroll-related and hrm_mode=zzp, reject with 403 + mode_restriction error
  - Apply to: all /api/payroll/*, /api/loonbelasting/*, /api/cao/*, /api/verzekeringen/* endpoints
  - Response format: `{ "error_code": "mode_restriction", "message": "..." }`

- [ ] 7.3 Document mode-restriction error in API docs
  - Add to API reference: what triggers mode_restriction, how clients should handle it
  - Example: when to show "this feature is not available in ZZP mode" vs generic "403 forbidden"

---

## 8. Integration with Other Apps

- [ ] 8.1 shillinq integration (optional, for revenue sync)
  - Feature: if shillinq is active on the tenant, auto-fetch total invoiced amount for IB-export
  - Implementation: IbExportService queries shillinq API endpoint (via openconnector or direct)
  - Fallback: if shillinq unavailable or no invoices, allow manual revenue entry
  - Config: add `shillinq_integration_enabled` flag to IAppConfig
  - Document: in Integrations menu, flag if shillinq is connected

- [ ] 8.2 Geocoding service integration (optional, for kilometer logging)
  - Feature: auto-compute distance from from_address to to_address
  - Implementation: GeocodeService integrates with Google Maps Distance Matrix API (or OpenStreetMap)
  - Fallback: if geocoding service unavailable, user manually enters kilometers
  - Config: add `geocoding_provider` (google | osm | none) and API key to IAppConfig
  - Cost awareness: Google Maps charges per request; monitor quota / add rate limiting
  - Document: in System Settings, toggle geocoding on/off, select provider

- [ ] 8.3 openregister integration (existing)
  - TaxContext, KilometerLog are stored in openregister
  - Use existing ObjectService, SchemaService for CRUD
  - No new abstraction layer needed

---

## 9. Testing

- [ ] 9.1 Unit tests: OrganisationModeService
  - Test: detectHrmMode for each legal_form + employee_count combo
  - Test: isZzpMode returns correct boolean
  - Test: canUpgradeToEmployer validates transition rules
  - Coverage: all enum values, edge cases (employee_count = 0, 1, 2)

- [ ] 9.2 Unit tests: UrencriteriumService
  - Test: getYearToDateHours sums correctly (filters qualifies_for_urencriterium=true)
  - Test: getProjectedYearEndHours extrapolates pace correctly (YTD / days_elapsed × 365)
  - Test: meetsTarget returns true iff hours ≥ 1225
  - Test: getWarningThreshold returns warning message if after October and projected < 1225

- [ ] 9.3 Unit tests: KilometerService
  - Test: createKilometerLog validates data (date, km > 0, required fields)
  - Test: getYearTotalKilometers sums correctly
  - Test: getYearTotalEuro multiplies by rate correctly

- [ ] 9.4 Integration tests: OrganisationModeService.upgradeToEmployer
  - Test: mode upgrade transaction (org updated, TaxContext updated, audit logged)
  - Test: audit trail entry has correct details (old/new mode, trigger, timestamp)
  - Test: all payroll endpoints now accept requests (no more 403 mode_restriction)

- [ ] 9.5 Integration tests: API endpoints
  - Test: POST /api/kilometre-logs with valid data → 201 + location header
  - Test: POST /api/kilometre-logs with invalid data → 400 + validation errors
  - Test: DELETE /api/kilometre-logs/{id} → 204 (no content)
  - Test: GET /api/tax-context in ZZP mode → 200 + ib context_type
  - Test: PUT /api/tax-context/for-active → updates TaxContext
  - Test: POST /api/payroll/runs in ZZP mode → 403 + mode_restriction

- [ ] 9.6 Feature tests: Urencriterium widget
  - Test: widget displays on personal dashboard in ZZP mode
  - Test: progress bar reflects current hours / 1225
  - Test: projected year-end updates with pace calculation
  - Test: warning appears if projection < 1225 after October

- [ ] 9.7 Feature tests: Kilometer registration form
  - Test: form submits valid data → creates KilometerLog
  - Test: form resets after successful save
  - Test: "Calculate" button calls geocoding service (if enabled)
  - Test: manual override of calculated distance works
  - Test: form rejects invalid data (missing fields, negative km, future date)

- [ ] 9.8 Feature tests: IB-export
  - Test: generate PDF for completed year → returns file
  - Test: PDF content includes: revenue, hours, km, FOR, ljf, urencriterium status
  - Test: generate CSV → returns tabular data
  - Test: export respects fiscal_year parameter
  - Test: revenue source selector (auto from shillinq | manual) works

- [ ] 9.9 Feature tests: Mode upgrade flow
  - Test: legal form change from eenmanszaak to bv_employer triggers modal
  - Test: modal validates CAO, sector, loonheffingennummer
  - Test: upgrade completes: org updated, TaxContext.context_type switches ib→lb, audit logged
  - Test: payroll menus un-hide after upgrade
  - Test: historical ZZP data (hours, km) remains intact

- [ ] 9.10 Feature tests: Single-user UI
  - Test: in single-user org, HourLog list hides employee column
  - Test: HourLog create form pre-fills current user (read-only)
  - Test: ExpenseClaim auto-approves (no manager workflow)
  - Test: add second user, UI re-renders with employee filter visible

- [ ] 9.11 End-to-end test: full ZZP fiscal year workflow
  - Setup: ZZP org with one user (Alice)
  - Actions:
    1. Log 1,200+ hours (billable, acquisition, admin)
    2. Log 150+ km of business trips
    3. Request IB-export on year-end
  - Verify:
    - Export includes correct figures
    - Urencriterium status = pass
    - Hours breakdown correct
    - File can be opened in PDF reader or Excel (CSV)

---

## 10. Documentation & Configuration

- [ ] 10.1 Backend ADR: OrganisationMode computation & TaxContext
  - Document: hrm_mode is computed from legal_form + employee_count
  - Document: TaxContext encapsulates IB/LB config, immutable except on mode upgrade
  - Document: mode upgrade is transactional, with audit trail

- [ ] 10.2 API documentation updates
  - Document: mode_restriction error code (when it occurs, how clients handle it)
  - Document: new endpoints (KilometerLog CRUD, TaxContext read/update, IB-export, mode-upgrade)
  - Document: visibility rules (which endpoints hidden in ZZP mode)

- [ ] 10.3 User documentation: ZZP mode onboarding
  - Guide: "Setting up hrmq for ZZP/eenmanszaak"
  - Steps: legal form selection, dashboard orientation, urencriterium tracking setup, kilometer logging
  - FAQ: "Why don't I see Payroll menus?" → "You're in ZZP mode; this is expected."
  - FAQ: "How do I grow from ZZP to employer?" → "Update legal form in Settings."

- [ ] 10.4 User documentation: IB-export guide
  - Guide: "Generating your jaaroverzicht for tax filing"
  - Steps: date range selection, revenue source, format, download
  - Context: what data is included, how to use with boekhouder
  - Note: this is advisory; always consult your boekhouder for official tax guidance

- [ ] 10.5 System Configuration: IAppConfig entries
  - Add: `shillinq_integration_enabled` (bool, default false)
  - Add: `geocoding_provider` (enum: none | google | osm, default: none)
  - Add: `geocoding_api_key` (string, sensitive, only if google selected)
  - Add: `kilometer_rate_eur_per_km` (decimal, default 0.23, updated annually by admin)

- [ ] 10.6 Compliance & Standards Reference
  - Document source: Wet IB 2001, Belastingdienst Handboek Ondernemers, CBS ZZP-Monitor
  - Urencriterium: cite Art. 3.6 (Wet IB 2001)
  - Kilometer rate: cite Belastingdienst 2025 tables (0.23 EUR/km)
  - FOR phaseout: note continuation-only post-2023 per Belastingdienst

---

## 11. Deduplication Check

- [ ] 11.1 Verify: OrganisationService handles legal_form without duplication
  - Check: Organisation entity already exists in openregister; adding legal_form as optional field is non-breaking
  - Check: Organisation is single source of truth for legal form (no duplication elsewhere)
  - Result: ✓ No duplication found; enhance existing schema

- [ ] 11.2 Verify: TaxContext doesn't duplicate CAOService or PayrollService config
  - Check: CAOService stores CAO rules (rate tables, regelingen); TaxContext stores IB/LB context
  - Check: No overlap; TaxContext is per-organisation, CAO is per-sector
  - Result: ✓ Complementary; no duplication

- [ ] 11.3 Verify: KilometerLog is new; no existing expense/kilometer tracking
  - Check: HourLog exists for time tracking; no kilometer tracking exists
  - Check: ExpenseClaim exists for manual expenses; KilometerLog is specific to business mileage
  - Result: ✓ New entity, no duplication

- [ ] 11.4 Verify: IB-export uses existing ExportService pattern
  - Check: ExportService provides CSV, Excel, JSON export; we add PDF format
  - Check: Reuse existing ImportService/ExportService infrastructure
  - Result: ✓ Leverage existing; extend with PDF template

- [ ] 11.5 Verify: UrencriteriumService uses existing HourLog queries; no duplication
  - Check: HourLog mapper exists; UrencriteriumService queries via mapper (no new query logic)
  - Check: qualifies_for_urencriterium flag is simple boolean filter (no new logic)
  - Result: ✓ Leverage existing mapper; add simple filter

---

## 12. Seed Data Loading

- [ ] 12.1 Generate hrmq_register.json seed objects
  - Seed 3 organisations: freelancer (eenmanszaak), BV-DGA, employer (control)
  - Seed TaxContext for each (ib for ZZP, lb for employer)
  - Seed 5 KilometerLog entries (variety: long trips, short trips, different purposes)
  - Seed 10 HourLog entries (with qualifies_for_urencriterium flag set correctly)
  - Include realistic Dutch values: KvK codes, addresses, rates

- [ ] 12.2 Create migration: import seed data
  - Use ConfigurationService::importFromApp('hrmq', seedData, version, force=false)
  - Idempotency: re-import does not create duplicates (match by slug)
  - Verify: on dev/test install, seed data is loaded alongside schemas

- [ ] 12.3 Test seed data completeness
  - Verify: all organisations load without errors
  - Verify: all schemas (Organisation, TaxContext, KilometerLog, HourLog) include seed objects
  - Verify: cross-references are valid (organisation_id, user_id match)

---

## 13. Verification & Acceptance

- [ ] 13.1 Functional verification: ZZP onboarding
  - [ ] 13.1.1 Register as eenmanszaak, land on personal dashboard (not payroll onboarding)
  - [ ] 13.1.2 Verify organisation has hrm_mode=zzp, TaxContext.context_type=ib
  - [ ] 13.1.3 Verify navigation excludes Salarissen, CAO's, etc.

- [ ] 13.2 Functional verification: Urencriterium widget
  - [ ] 13.2.1 Widget displays on dashboard with correct progress (hours / 1225)
  - [ ] 13.2.2 Projection updates as more hours are logged
  - [ ] 13.2.3 Warning appears if projection < 1225 after October

- [ ] 13.3 Functional verification: Kilometer logging
  - [ ] 13.3.1 Form submits valid data, creates KilometerLog
  - [ ] 13.3.2 Geocoding service (if enabled) auto-computes distance
  - [ ] 13.3.3 List view shows all entries with total km and EUR value

- [ ] 13.4 Functional verification: IB-export
  - [ ] 13.4.1 Export dialog allows selection of year, format, revenue source
  - [ ] 13.4.2 PDF export generates readable file with correct data
  - [ ] 13.4.3 CSV export is importable into Excel / boekhouder software

- [ ] 13.5 Functional verification: Mode upgrade
  - [ ] 13.5.1 Change legal form from eenmanszaak to bv_employer
  - [ ] 13.5.2 Modal prompts for CAO, sector, loonheffingennummer
  - [ ] 13.5.3 Upgrade completes: TaxContext.context_type = lb, payroll menus unhide
  - [ ] 13.5.4 Historical ZZP data (hours, km) remains intact and queryable

- [ ] 13.6 Regression verification: Employer mode unchanged
  - [ ] 13.6.1 Employer-mode orgs continue to work as before (payroll, CAO, etc.)
  - [ ] 13.6.2 New ZZP-specific widgets don't appear in employer mode
  - [ ] 13.6.3 API mode_restriction doesn't affect employer orgs

- [ ] 13.7 Security verification
  - [ ] 13.7.1 API mode_restriction correctly blocks payroll writes in ZZP mode
  - [ ] 13.7.2 Authorization checks on all new endpoints (GET /tax-context, PUT /*, etc.)
  - [ ] 13.7.3 No SQL injection in KilometerLog queries (use parameterized, OpenRegister queries)
  - [ ] 13.7.4 Audit trail captures mode upgrades, payroll-restriction rejections

- [ ] 13.8 Performance verification
  - [ ] 13.8.1 UrencriteriumService query (sum HourLog) is efficient on large datasets (indexed on qualifies_for_urencriterium, date)
  - [ ] 13.8.2 Dashboard widget loads in < 1s (or lazy-load if data is heavy)
  - [ ] 13.8.3 IB-export generation completes in reasonable time (< 10s for 1+ years of data)

---

## Notes for Implementers

- **Spec traceability**: All PHP classes and methods must have `@spec` tags referencing this change (`openspec/changes/zzp-eenmanszaak-no-payroll-mode/tasks.md#task-N`)
- **Transactions**: Mode upgrade (org + TaxContext + audit) must be atomic; use DB transaction
- **Audit logging**: All TaxContext updates, mode upgrades, and payroll-restriction rejections must be logged
- **Configuration**: Sensitive values (geocoding API key) must use `IAppConfig` with sensitive=true flag
- **Testing**: Integration tests must use real OpenRegister schemas (not mocks) to verify correct behavior
- **Documentation**: Update API docs, user guides, and admin guides for each feature
- **Backward compatibility**: All migrations must be non-breaking; existing data must be preserved

