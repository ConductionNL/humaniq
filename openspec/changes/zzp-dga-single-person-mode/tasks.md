# Tasks: zzp-dga-single-person-mode

## Phase 1: Data Layer & Core Services

### Database Migrations

- [ ] **Migration 0001_add_dga_mode_to_organisation**
  - Add `mode` enum column to `hrmq_organisation` (values: standard, dga_single_person; default: standard)
  - Add `dga_employee_id` FK to `hrmq_organisation` (nullable, unique constraint when NOT null)
  - Backfill: set `mode = 'standard'` for all existing organisations
  - Reversible: drop columns in reverse migration

- [ ] **Migration 0002_create_hrmq_dga_profile**
  - Create `hrmq_dga_profile` table:
    - `id` UUID PK
    - `employee_id` UUID FK (unique, references hrmq_employee.id)
    - `aanmerkelijk_belang_percentage` decimal(5,2)
    - `gebruikelijk_loon_norm_year` int
    - `gebruikelijk_loon_norm_basis` enum (wettelijk_56000, meestverdienende_werknemer, vergelijkbare_dienstbetrekking, lager_aannemelijk_gemaakt)
    - `gebruikelijk_loon_norm_motivering` text
    - `for_saldo_opening` decimal(12,2)
    - `for_onttrekkingen` jsonb (timeline array)
    - `lijfrente_polissen` jsonb (array)
    - `box2_dividenduitkeringen` jsonb (timeline array)
    - `box2_verkrijgingsprijs` decimal(12,2)
    - `created_at`, `updated_at` timestamps
  - Indices: on `employee_id` (unique)

- [ ] **Migration 0003_create_hrmq_dga_ib_export**
  - Create `hrmq_dga_ib_export` table:
    - `id` UUID PK
    - `dga_employee_id` UUID FK (references hrmq_employee.id)
    - `calendar_year` int
    - `status` enum (concept, definitief_aangeleverd)
    - `accountant_endpoint_id` UUID FK (nullable, references delivery_target.id)
    - `pakket_blob_url` text
    - `generated_at`, `accountant_acknowledgement_at` timestamps
    - `created_at`, `updated_at` timestamps
  - Indices: on `(dga_employee_id, calendar_year)` (unique)

- [ ] **Migration 0004_create_hrmq_accountant_delegation**
  - Create `hrmq_accountant_delegation` table:
    - `id` UUID PK
    - `dga_employee_id` UUID FK (references hrmq_employee.id)
    - `accountant_user_id` UUID FK (references hrmq_user.id)
    - `granted_at` timestamp
    - `revoked_at` timestamp (nullable)
    - `permissions` text[] (array of permission strings)
    - `created_at`, `updated_at` timestamps
  - Indices: on `(dga_employee_id, revoked_at)` (for active delegations lookup)

- [ ] **Migration 0005_add_dga_seed_data**
  - Load seed objects via `ConfigurationService::importFromApp()`:
    - hrmq_organisation (dga-example-consultancy)
    - hrmq_employee (emp-001-dga-de-vries)
    - hrmq_dga_profile (2 examples with different norm basis)
    - hrmq_accountant_delegation (1 example)
    - hrmq_dga_ib_export (1 example)
  - Use `@self` envelope; slug-based deduplication

---

## Phase 2: Service Layer & Business Logic

### Organisation Service

- [ ] **DgaModeValidator service**
  - Validate organisation mode toggle:
    - When switching TO dga_single_person: ensure exactly 1 active employee with `is_dga = true`
    - When switching FROM dga_single_person: no validation (data preserved)
  - Emit `OrganisationModeChanged` event with old/new mode and dga_employee_id

- [ ] **OrganisationService::toggleDgaMode(organisationId, mode, dgaEmployeeId)**
  - Call validator
  - Update `hrmq_organisation.mode` and `hrmq_organisation.dga_employee_id`
  - Log audit trail entry
  - Emit event

### DGA Profile Service

- [ ] **DgaProfileService::createOrUpdateProfile(employeeId, data)**
  - Create/update `hrmq_dga_profile` record via ObjectService
  - Validate norm basis: if not `wettelijk_56000`, require non-empty `gebruikelijk_loon_norm_motivering`
  - For mode changes, validate exactly 1 DGA exists

- [ ] **DgaProfileService::getGebruikelijkloonNorm(employeeId, year)**
  - Return norm value from indexed Belastingdienst table (indexed annually; seed with 2026: EUR 56.000)
  - Support custom basis (meestverdienende_werknemer, etc.) with stored override

- [ ] **DgaProfileService::getGebruikelijkloonStatus(employeeId, year)**
  - Calculate YTD bruto from loonstroken for year
  - Compute norm from `getGebruikelijkloonNorm()`
  - Return: `{ ytd_bruto, norm, remaining, status: 'green'|'amber'|'red' }`
  - Logic: green ≥100%, amber 90–99%, red <90% (or <80% with <3 months remaining)

- [ ] **DgaProfileService::addForOntrekking(employeeId, date, amount, type)**
  - Append to `hrmq_dga_profile.for_onttrekkingen` jsonb array
  - Validate year ≥ 2023 (post-FOR-stoppage)
  - Update `updated_at`

- [ ] **DgaProfileService::addLijfrentePolis(employeeId, verzekeraar, polisnummer, factorA)**
  - Append to `hrmq_dga_profile.lijfrente_polissen` jsonb array
  - Store polis metadata (verzekeraar, polisnummer, factor-A)

- [ ] **DgaProfileService::calculateLijfrenteJaarruimte(employeeId, year)**
  - Fetch `hrmq_dga_profile` and loonstroken YTD bruto for year
  - Fetch all polissen factor-A values
  - Formula: `min(30% * premiegrondslag - sum(factor_a) * 6.27, 36077)`
  - Return: `{ available_ruimte, reserved_10yr }`

### Payroll Service (DGA Extensions)

- [ ] **PayrollService::generateDgaLoonstrook(employeeId, month)**
  - Call parent loonstrook generation (shared engine)
  - Fetch `DgaProfileService::getGebruikelijkloonStatus(employeeId, year)`
  - Append DGA section to PDF template:
    - YTD bruto
    - Norm
    - Remaining
    - Status indicator
  - File naming: `loonstrook_YYYY_MM.pdf`

- [ ] **PayrollService::generateDgaJaaropgaaf(employeeId, year)**
  - Validate all 12 loonstroken exist and are finalised for the year
  - Generate standard jaaropgaaf PDF (Belastingdienst XSD 2026 format)
  - Generate machine-readable JSON export with sections:
    - Standard (loon, heffing, ZVW, arbeidskorting)
    - FOR-saldo (opening + onttrekkingen + closing)
    - Lijfrente (polissen + stortingen + factor-A data)
    - Box-2 (dividends + verkrijgingsprijs)
  - Create `hrmq_dga_ib_export` record with status = `concept`
  - Lock jaaropgaaf (set status to `definitief_aangeleverd`)
  - File naming: `jaaropgaaf_YYYY.pdf`, `jaaropgaaf_YYYY.json`

### IB-Pakket Service

- [ ] **IbPakketService::generateIbBundle(exportId, year)**
  - Fetch `hrmq_dga_ib_export` and validate jaaropgaaf is finalised
  - Bundle into ZIP:
    - `jaaropgaaf_YYYY.pdf` + `jaaropgaaf_YYYY.json`
    - `for_overzicht_YYYY.json` (opening + onttrekkingen + closing)
    - `lijfrente_overzicht_YYYY.json` (polissen + stortingen + factor-A)
    - `aanmerkelijk_belang_YYYY.json` (dividends + verkrijgingsprijs)
    - `manifest.json` (index with metadata)
  - Upload to accountant endpoint (SFTP/Nextcloud/email, configurable)
  - Update `hrmq_dga_ib_export.pakket_blob_url` and `status = 'definitief_aangeleverd'`
  - File naming: `ib_pakket_YYYY.zip`

- [ ] **IbPakketService::acknowledgeIbPacket(exportId, accountantUserId)**
  - Verify delegation: accountant has `export_ib_pakket` permission for this DGA
  - Update `hrmq_dga_ib_export.accountant_acknowledgement_at = now()`
  - Log audit trail

### Accountant Delegation Service

- [ ] **AccountantDelegationService::inviteAccountant(dgaEmployeeId, accountantEmail, permissions)**
  - Find or create user record for accountant by email
  - Create `hrmq_accountant_delegation` with `granted_at = now()`, `revoked_at = null`
  - Send invitation email (templated) with acceptance link
  - On acceptance: delegation becomes active

- [ ] **AccountantDelegationService::revokeDelegation(delegationId, revokedBy)**
  - Set `revoked_at = now()`
  - Log audit trail (who revoked, when)
  - Accountant immediately loses access to this DGA's payroll

- [ ] **AccountantDelegationService::hasPermission(delegationId, permission)**
  - Fetch delegation, validate `revoked_at = null`
  - Check if `permission` in `permissions[]` array
  - Return boolean (used in service-layer RBAC checks)

### ZVW Service (DGA Extensions)

- [ ] **ZvwService::getYtdTotal(employeeId, year)**
  - Sum all `loonstrook.zvw_werkgeversbijdrage` for year
  - Return: `{ ytd_zvw, ceiling_2026: 75864, is_overpaid }`

- [ ] **ZvwService::isOverpaid(employeeId, year)**
  - Get YTD total; return `ytd_zvw > ceiling`

---

## Phase 3: API Endpoints & Service Layer Guards

### REST API Endpoints

- [ ] **PATCH /api/organisations/{id}/mode**
  - Input: `{ mode: 'dga_single_person'|'standard', dga_employee_id?: UUID }`
  - Call `OrganisationService::toggleDgaMode()`
  - Return updated organisation + audit log entry

- [ ] **GET/PUT /api/employees/{id}/dga-profile**
  - GET: fetch `hrmq_dga_profile` by employee_id
  - PUT: update profile (guardians: admin, self as DGA, delegated accountant with write_payroll)
  - Return DGA profile object

- [ ] **POST /api/employees/{id}/dga-profile/for-onttrekking**
  - Input: `{ date, amount, type }`
  - Call `DgaProfileService::addForOntrekking()`
  - Return updated FOR timeline

- [ ] **POST /api/employees/{id}/dga-profile/lijfrente-polis**
  - Input: `{ verzekeraar, polisnummer, factor_a }`
  - Call `DgaProfileService::addLijfrentePolis()`
  - Return updated polissen array

- [ ] **GET /api/employees/{id}/dga-profile/gebruikelijk-loon-status**
  - Call `DgaProfileService::getGebruikelijkloonStatus()`
  - Return status object for dashboard widget

- [ ] **GET /api/employees/{id}/dga-profile/lijfrente-jaarruimte**
  - Call `DgaProfileService::calculateLijfrenteJaarruimte()`
  - Return jaarruimte object

- [ ] **POST /api/employees/{id}/jaaropgaaf/generate**
  - Call `PayrollService::generateDgaJaaropgaaf()`
  - Return `hrmq_dga_ib_export` record with PDF/JSON URLs

- [ ] **POST /api/ib-export/{exportId}/generate-bundle**
  - Call `IbPakketService::generateIbBundle()`
  - Return ZIP download URL (or async job ID)

- [ ] **POST /api/accountant-delegations/invite**
  - Input: `{ dga_employee_id, accountant_email, permissions[] }`
  - Call `AccountantDelegationService::inviteAccountant()`
  - Return delegation record + send invitation email

- [ ] **DELETE /api/accountant-delegations/{id}**
  - Call `AccountantDelegationService::revokeDelegation()`
  - Return success response

### Service-Layer Guards (Middleware/Aspect)

- [ ] **DgaModeVisibilityMiddleware**
  - Intercept requests to multi-employee endpoints (Medewerkers, Verlof, approval queues)
  - If `organisation.mode = dga_single_person`: reject with 403 or return empty list
  - Otherwise: allow

- [ ] **AccountantDelegationMiddleware**
  - Before each endpoint: if user has role `accountant_of_record`, check:
    - Is delegation active (`revoked_at = null`)?
    - Does delegation have required permission?
  - If not: reject with 403

---

## Phase 4: Frontend & UI

### Vue Components

- [ ] **DgaModeToggleSetting component**
  - Place: `src/components/Settings/DgaModeToggleSetting.vue`
  - Props: `organisation`
  - Features:
    - Radio buttons: Standard / DGA Single-Person
    - Conditional dropdown: DGA Employee (filtered to `is_dga = true`)
    - "Save" / "Cancel" buttons
    - Confirmation dialog when switching modes
    - Validation error messages
  - Emits: `modeChanged` event

- [ ] **DgaDashboard component**
  - Place: `src/components/Dashboard/DgaDashboard.vue`
  - Layout: 4-widget grid
  - Imports:
    - `DgaGebruikelijkloonWidget.vue`
    - `DgaZvwWidget.vue`
    - `DgaForAndLijfrenteWidget.vue`
    - `DgaLoonstrokenAndExportWidget.vue`

- [ ] **DgaGebruikelijkloonWidget component**
  - Fetches: `/api/employees/{id}/dga-profile/gebruikelijk-loon-status`
  - Display: norm, YTD, remaining, status bar
  - Link: "View details" → detail screen

- [ ] **DgaZvwWidget component**
  - Fetches: `/api/employees/{id}/dga-profile/zvw-running-total`
  - Display: YTD, ceiling, %, overpayment flag

- [ ] **DgaForAndLijfrenteWidget component**
  - Fetches: `/api/employees/{id}/dga-profile` (FOR section) + `/api/employees/{id}/dga-profile/lijfrente-jaarruimte`
  - Display: FOR-saldo, lijfrente jaarruimte
  - Link: "View FOR timeline" → detail screen

- [ ] **DgaLoonstrokenAndExportWidget component**
  - Fetches: last 3 loonstroken from `/api/employees/{id}/loonstroken?limit=3`
  - List: PDF download links
  - Actions: "Genereer jaaropgaaf" / "Lever IB-pakket" buttons (disabled if conditions not met)
  - On button click: POST to `/api/employees/{id}/jaaropgaaf/generate` or `/api/ib-export/{exportId}/generate-bundle`

- [ ] **DgaProfileDetailTab component**
  - Place: `src/components/EmployeeDetail/DgaProfileDetailTab.vue`
  - Sections:
    - Fiscale Gegevens (edit aanmerkelijk-belang, norm basis + motivering)
    - FOR-saldo (timeline table + add button)
    - Lijfrente-polissen (table + add button)
    - Box-2 (verkrijgingsprijs + dividends timeline)
    - Delegatie (accountant table + invite button)
  - Calls: PUT `/api/employees/{id}/dga-profile` on save

- [ ] **AccountantInviteDialog component**
  - Modal form:
    - Accountant email (text input, autocomplete from user directory)
    - Permissions checkboxes (read_payroll, write_payroll, read_jaaropgaaf, export_ib_pakket)
    - "Send invitation" / "Cancel" buttons
  - On submit: POST `/api/accountant-delegations/invite`

- [ ] **ForOnttrekkingForm component**
  - Modal form:
    - Date picker
    - Amount (EUR decimal)
    - Type (dropdown: lijfrente, belast_inkomen)
    - "Add" / "Cancel" buttons
  - On submit: POST `/api/employees/{id}/dga-profile/for-onttrekking`

- [ ] **LijfrentePolisForm component**
  - Modal form:
    - Verzekeraar (text input)
    - Polisnummer (text input)
    - Stortingen (table of year/amount pairs; add row button)
    - Factor A (decimal, verzekeraar-provided)
    - "Add" / "Cancel" buttons
  - On submit: POST `/api/employees/{id}/dga-profile/lijfrente-polis`

### Routing & Navigation

- [ ] **Route: /dashboard (DGA mode)**
  - Conditionally render `DgaDashboard` if `organisation.mode = dga_single_person`
  - Otherwise render standard `Dashboard`
  - Guard: check org mode on component mount

- [ ] **Route: /settings/organisations/{id}/mode**
  - Render `DgaModeToggleSetting`

- [ ] **Route: /employees/{id}/dga-profile**
  - Render `DgaProfileDetailTab` (as detail view, not detail tab)
  - Guard: show only if `employee.is_dga = true` OR `organisation.mode != dga_single_person` (for preserved data after mode switch)

---

## Phase 5: Testing

### Unit Tests

- [ ] **Test DgaModeValidator**
  - Test: mode toggle to DGA with exactly 1 DGA employee → success
  - Test: mode toggle to DGA with 0 DGA employees → validation error
  - Test: mode toggle to DGA with 2+ DGA employees → validation error
  - Test: mode toggle back to standard → always succeeds

- [ ] **Test DgaProfileService**
  - Test `getGebruikelijkloonNorm()`: returns EUR 56.000 for 2026 (wettelijk basis)
  - Test `getGebruikelijkloonStatus()`: calculates YTD, remaining, status correctly
  - Test status logic: green ≥100%, amber 90–99%, red <90%
  - Test `addForOntrekking()`: appends to jsonb array, validates year ≥2023
  - Test `calculateLijfrenteJaarruimte()`: formula verification

- [ ] **Test ZvwService**
  - Test `getYtdTotal()`: sums all ZVW bijdrage for year
  - Test `isOverpaid()`: returns true if YTD > EUR 75.864

- [ ] **Test PayrollService (DGA extensions)**
  - Test `generateDgaLoonstrook()`: PDF includes DGA section with YTD/norm/status
  - Test `generateDgaJaaropgaaf()`: validates 12 loonstroken exist, creates ib_export record

- [ ] **Test IbPakketService**
  - Test `generateIbBundle()`: creates ZIP with correct contents (jaaropgaaf, FOR, lijfrente, box-2, manifest)
  - Test upload to SFTP endpoint (mock)

- [ ] **Test AccountantDelegationService**
  - Test `inviteAccountant()`: creates delegation record, sets granted_at
  - Test `revokeDelegation()`: sets revoked_at, accountant loses access
  - Test `hasPermission()`: correctly checks permission array

### Integration Tests

- [ ] **Test mode toggle workflow**
  - Create organisation with 1 DGA employee
  - Toggle to DGA mode → validate menus hidden
  - Add second employee → prompt to switch back to standard
  - Switch back → DGA data preserved as read-only tab

- [ ] **Test loonstrook generation with DGA section**
  - Create DGA with monthly EUR 4.500 bruto
  - Run payroll cycle
  - Verify PDF includes DGA section with YTD/norm/status
  - Verify filename: `loonstrook_YYYY_MM.pdf`

- [ ] **Test jaaropgaaf generation**
  - Create DGA with 12 finalised loonstroken
  - Click "Genereer jaaropgaaf"
  - Verify PDF + JSON generated
  - Verify FOR/lijfrente/box-2 JSON sections populated
  - Verify status = `definitief_aangeleverd`

- [ ] **Test IB-pakket export**
  - Create DGA with finalised jaaropgaaf + FOR/lijfrente/box-2 data
  - Click "Lever IB-pakket"
  - Verify ZIP created with correct contents
  - Verify upload to SFTP/Nextcloud/email (mock)
  - Verify manifest.json valid

- [ ] **Test accountant delegation**
  - DGA invites accountant with permissions
  - Accountant accepts invitation
  - Accountant can read payroll, generate jaaropgaaf, export IB-pakket
  - Accountant cannot access non-payroll data
  - DGA revokes delegation
  - Accountant loses access immediately

### E2E Tests

- [ ] **Persona: Sem (DGA, young digital native)**
  - Scenario: Sem is a one-person IT consultancy (De Vries Consultancy)
    1. Toggle organisation to DGA mode
    2. View DGA dashboard (gebruikelijk-loon, ZVW, FOR, lijfrente)
    3. Receive monthly loonstrook with DGA section
    4. At year-end, generate jaaropgaaf
    5. Export IB-pakket and send to accountant
  - Verify: all workflows succeed, data correct, no errors

- [ ] **Persona: Annemarie (accountant, standards architect)**
  - Scenario: Annemarie manages 5 DGA clients
    1. Each client invites her as accountant_of_record
    2. She switches between client organisations
    3. For each client, she reads payroll, generates jaaropgaaf, exports IB-pakket
    4. She reconciles IB-pakket data with her tax filing software (Unit4/Cygnus)
    5. She revokes delegation for one client at year-end
  - Verify: delegation filtering works, permissions enforced, data exported correctly

- [ ] **Persona: Jan-Willem (small business owner, grows from DGA to multi-employee)**
  - Scenario: Jan-Willem is in DGA mode with EUR 60k annual loon
    1. His business grows; he hires his first employee
    2. System prompts to switch to standard mode
    3. All DGA data preserved as read-only tab on his own employee record
    4. Multi-employee menus reappear
    5. Payroll continues; he now manages 2 employees
  - Verify: mode switch smooth, data preserved, no loss

### Persona Testing (Via Skills)

- [ ] **Run `/test-persona-sem` workflow** — DGA user journey (dashboard, loonstrook, jaaropgaaf, IB-export)
- [ ] **Run `/test-persona-annemarie` workflow** — accountant delegation, multi-tenant switching, IB-export reconciliation
- [ ] **Run `/test-persona-jan-willem` workflow** — DGA → standard mode transition

### Performance Tests

- [ ] **Dashboard widget load time**
  - Measure: DGA dashboard load with 4 widgets (gebruikelijk-loon, ZVW, FOR, loonstroken)
  - Target: <500ms with cached data
  - Test with 100 loonstroken in history

- [ ] **Jaaropgaaf generation**
  - Measure: time to generate PDF + JSON for 12 loonstroken
  - Target: <5s (async job acceptable for >10s)

- [ ] **IB-pakket ZIP creation**
  - Measure: time to bundle jaaropgaaf + FOR + lijfrente + box-2 + manifest
  - Target: <2s

---

## Phase 6: Documentation & Deployment

### Documentation

- [ ] **User Guide: DGA Mode Setup**
  - How to toggle organisation to DGA mode (prerequisites, validation)
  - Screenshots: mode toggle UI
  - Troubleshooting: "I have 2 DGAs, why can't I enable DGA mode?"

- [ ] **User Guide: DGA Dashboard & Widgets**
  - Explanation of each widget (gebruikelijk-loon, ZVW, FOR, lijfrente)
  - Links to Belastingdienst references (norm basis, ZVW regs, FOR-afbouw)

- [ ] **User Guide: Jaaropgaaf & IB-Pakket Export**
  - How to generate jaaropgaaf (prerequisites: 12 finalised loonstroken)
  - How to export IB-pakket for accountant
  - Accountant endpoint configuration (SFTP/Nextcloud/email)

- [ ] **User Guide: Accountant Delegation**
  - How to invite accountant (email, permissions)
  - How accountant accepts and uses delegation
  - How to revoke delegation

- [ ] **Integration Guide: Accountant Software (Unit4, Cygnus, Twinfield)**
  - IB-pakket ZIP structure and field mappings
  - JSON schema documentation
  - Sample import workflows

- [ ] **ADR: DGA Single-Person Mode Architecture**
  - Record: data model design, reversibility guarantee, mode toggle logic
  - Link to this spec

### Release Notes

- [ ] Draft release notes for MVP (Q3 2026):
  - New capability: DGA single-person mode
  - Supported: loonstrook, jaaropgaaf, IB-pakket export, accountant delegation
  - Known limitations: (none)
  - Belastingdienst compliance: 2026 rates used

### Deployment Checklist

- [ ] All migrations applied to staging, tested for reversibility
- [ ] Feature flag `feature.dga_single_person_mode` deployed (default: off)
- [ ] Pilot: enable flag for 5–10 accountant organisations
- [ ] Monitor: error logs, performance metrics (dashboard load, jaaropgaaf generation time)
- [ ] Gather feedback from pilot (usability, missed requirements)
- [ ] GA: enable flag for all tenants (Q4 2026)

---

## Deduplication Check

- [ ] Search `hrmq/lib/Service/` for existing loonstrook/jaaropgaaf services
  - Finding: PayrollService exists for standard mode loonstrook
  - Action: Extend existing service with DGA flavours, don't duplicate
- [ ] Search `hrmq/lib/Service/` for existing delegation/role models
  - Finding: RoleService exists for general RBAC
  - Action: Use existing for accountant_of_record role definition, extend with permission checks
- [ ] Search `openregister/lib/Service/` for object/schema CRUD
  - Finding: ObjectService provides generic save/find
  - Action: Use for DGA profile CRUD, no custom code needed
- [ ] Search `openregister/lib/` for existing PDF templating
  - Finding: (none in openregister; docudesk handles this)
  - Action: Extend docudesk templates for DGA loonstrook section

**Conclusion:** No component duplication detected. Changes are additive extensions to existing services.

---

## Estimated Effort

| Phase | Task Count | Story Points | Duration |
|-------|-----------|--------------|----------|
| 1: Data Layer | 5 migrations | 8 | 2 days |
| 2: Services | 12 services | 21 | 5 days |
| 3: API | 9 endpoints | 13 | 3 days |
| 4: Frontend | 11 components | 34 | 8 days |
| 5: Testing | 20 tests | 21 | 5 days |
| 6: Docs | 6 docs | 5 | 1 day |
| **Total** | **63 tasks** | **102** | **~4 weeks** |

---

## Dependencies & Blockers

- [ ] **Dependency: payroll-core-basic**
  - Blocks: Phase 2 (PayrollService extension assumes payroll-engine-nl is merged)
  - Status: Must be merged before starting Phase 2

- [ ] **Dependency: payroll-engine-nl rates**
  - Blocks: Phase 2 (ZVW ceiling, gebruikelijk-loon norm must be in rate tables)
  - Status: Belastingdienst 2026 rates finalized by 2025-12-01

- [ ] **Dependency: docudesk PDF templates**
  - Blocks: Phase 4 (DGA loonstrook section requires template parameter support)
  - Status: Verify docudesk supports named template sections

- [ ] **No architectural blockers detected.** Reversible design, reuse of existing services.
