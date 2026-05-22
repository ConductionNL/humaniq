---
status: draft
version: 1.0
date: 2026-05-22
---

# Tasks: Asset Management voor HRMQ

## Phase 1a: Data Model & Architecture (Target: 2026-06-15)

### Data Model Design

- [ ] **ADR-001: Asset Data Model** — Document entity definitions (Asset, AssetAssignment, LeaseCarTaxRecord, AssetHistoryEntry, AssetCategory) with field constraints, relationships, and fiscal rules. Align with Wet inkomstenbelasting 2001 bijtelling requirements. Review with tax specialist.
  - [ ] Review article 3.145 Wet inkomstenbelasting 2001 for bijtelling basis
  - [ ] Define cataloguswaarde source (NHTV, manufacturer list, or manual entry)
  - [ ] Define privé_gebruik_verklaring enum and implications
  - [ ] Document CO2 emissions source (officially registered, manual, estimated)
  - [ ] Get stakeholder sign-off (tax, legal, HR lead)

- [ ] **ADR-002: Bijtelling Staffel Calculation Rules** — Document fiscal staffel tables per year (2017–2026 baseline, future extensibility). Define rule application by vehicle age, fuel type, cataloguswaarde bracket. Document overgangsrecht for vehicles >60 months old. Include test fixtures per rule variant.
  - [ ] Collate official Belastingdienst staffel data (2017–2026)
  - [ ] Map fuel types to staffel brackets (benzine, diesel, elektrisch, hybrid, hydrogen)
  - [ ] Define CO2-based brackets (where applicable)
  - [ ] Create staffel versioning scheme (fiscal year + release date)
  - [ ] Document overgangsrecht rules and edge cases (old vehicles, hybrid, wateroff)
  - [ ] Create test fixtures: 10+ scenarios per fuel type, boundary cases

- [ ] **ADR-003: Event Bus Integration** — Document RabbitMQ topic/routing for asset events (asset_assigned, asset_returned, lease_car_bijtelling_update) and payroll coupling. Define event schema (JSON), retry logic, and dead-letter handling.
  - [ ] Define event topic names and routing keys
  - [ ] Create event schema (JSON Schema / OpenAPI)
  - [ ] Document error handling: retry count, backoff strategy, DLQ logic
  - [ ] Define event idempotency key (for deduplication in payroll)
  - [ ] Coordinate with payroll-engine-nl team on event consumption

- [ ] **ADR-004: Access Control & GDPR Strategy** — Document role-based field visibility, anonymization rules for departed employees, and 7-year audit trail retention. Coordinate with legal/compliance.
  - [ ] Define roles: asset_manager, hr_admin, employee, auditor, manager
  - [ ] Map role → accessible fields (e.g., employee cannot see price, supplier)
  - [ ] Document anonymization trigger (2 years post-departure)
  - [ ] Define pseudo-ID scheme for anonymization
  - [ ] Define retention windows per entity (7 years for lease-auto history for fiscal reasons)
  - [ ] Get legal/compliance sign-off

### Database Schema Design

- [ ] **Schema: Asset table** — Define columns, data types, constraints, indexes. Include check constraints for enum values.
  - [ ] Create table with columns: id, type, serienummer, merk_en_model, leverancier_id, aankoopdatum, aankoopprijs_excl_btw, aankoopprijs_incl_btw, afschrijvingstermijn_maanden, status, eigendoms_type, lease_* fields
  - [ ] Add unique constraint: (serienummer, type) per organization (tenant scoping)
  - [ ] Add check constraint: lease_* fields not null if eigendoms_type in (*lease, huur)
  - [ ] Create indexes: (status), (created_at), (leverancier_id)
  - [ ] Add audit columns: created_at, created_by, updated_at, updated_by

- [ ] **Schema: AssetAssignment table** — Define columns, active-assignment constraint, temporal relationship.
  - [ ] Create table with columns: id, asset_id, employee_id, uitgifte_datum, uitgifte_door, inname_datum, inname_door, staat_bij_uitgifte, staat_bij_inname, opmerking_bij_inname
  - [ ] Add foreign keys: asset_id → Asset (CASCADE), employee_id → Employee
  - [ ] Add check constraint: uitgifte_datum < inname_datum OR inname_datum IS NULL
  - [ ] Create unique partial index: (asset_id) WHERE inname_datum IS NULL (only one active per asset)
  - [ ] Create indexes: (asset_id, inname_datum), (employee_id, inname_datum), (uitgifte_datum)

- [ ] **Schema: LeaseCarTaxRecord table** — Define 1:1 with Asset, immutable DET, calculated term-end date.
  - [ ] Create table with columns: id, asset_id (UNIQUE), kenteken, datum_eerste_tenaamstelling, cataloguswaarde, brandstoftype, co2_uitstoot_g_per_km, bijtellingspercentage_huidig, bijtelling_einddatum_termijn, privé_gebruik_verklaring
  - [ ] Add foreign key: asset_id → Asset (UNIQUE, type=lease_auto only)
  - [ ] Add trigger: on insert/update, calculate bijtelling_einddatum_termijn = datum_eerste_tenaamstelling + INTERVAL '60 months', mark immutable
  - [ ] Add check constraint: co2_uitstoot_g_per_km IS NOT NULL for (benzine, diesel), NULL for (elektrisch, waterstof)
  - [ ] Add check constraint: kenteken matches Dutch regex (pattern: 2-4 letters, 2-3 digits, 2 letters)
  - [ ] Create indexes: (asset_id), (kenteken)

- [ ] **Schema: AssetHistoryEntry table** — Append-only audit log with trigger to prevent updates/deletes.
  - [ ] Create table with columns: id, asset_id, employee_id, event_type, event_at, actor_id, note, previous_value (JSONB), new_value (JSONB)
  - [ ] Add foreign keys: asset_id → Asset (no cascade), employee_id → Employee (NULLABLE, no cascade)
  - [ ] Add trigger: BEFORE UPDATE OR DELETE → RAISE EXCEPTION (append-only enforcement)
  - [ ] Create indexes: (asset_id, event_at DESC), (employee_id, event_at DESC), (event_type, event_at DESC)
  - [ ] Add created_at (auto = now), no updated_at

- [ ] **Schema: AssetCategory table** — Optional grouping for procurement/analytics.
  - [ ] Create table with columns: id, name, description, organization_id, created_at, created_by
  - [ ] Add foreign key: organization_id (scoped to tenant)
  - [ ] Create index: (organization_id, name)

### API Contract Design

- [ ] **OpenAPI Spec: Asset endpoints** — Document GET /assets, POST /assets, PATCH /assets/{id}, DELETE /assets/{id}, GET /assets?filters
  - [ ] Define request/response schemas
  - [ ] Document validation rules per field
  - [ ] Document error responses (400, 403, 404, 409, 422)
  - [ ] Define pagination (limit, offset)
  - [ ] Define filtering (status, type, employee_id, category_id, created_date_range)

- [ ] **OpenAPI Spec: Assignment endpoints** — Document POST /assets/{id}/assignments, PATCH /assets/{id}/assignments/{aid}
  - [ ] Document state-transition logic (validation, status changes)
  - [ ] Document lease_car tax-record creation side-effect
  - [ ] Document event emission side-effect

- [ ] **OpenAPI Spec: Lease car endpoints** — Document POST/GET/PATCH /assets/{id}/lease-car-tax
  - [ ] Document privé_gebruik_verklaring update flow
  - [ ] Document bijtelling recalculation trigger

- [ ] **OpenAPI Spec: Employee self-service** — Document GET /employees/{id}/assets, POST /employees/{id}/assets/{aid}/damage-report, PATCH /employees/{id}/lease-cars/{lcid}/declare-usage
  - [ ] Document privacy filtering (employee cannot see cost, supplier, bijtelling)
  - [ ] Document damage-report schema (text, photo upload)
  - [ ] Document privé-gebruik declaration options

- [ ] **Event schema: asset_assigned** — Define JSON schema, emit trigger, examples
- [ ] **Event schema: asset_returned** — Define JSON schema, emit trigger, examples
- [ ] **Event schema: lease_car_bijtelling_update** — Define JSON schema, emit trigger, examples

---

## Phase 1b: Backend Implementation (Target: 2026-07-31)

### Backend Foundation

- [ ] **Setup: Node.js backend scaffold** — Initialize project, dependencies (Express, Sequelize or TypeORM, RabbitMQ client, OpenAPI), dev environment (Docker, nodemon, test framework).
  - [ ] npm init, install express, sequelize, pg (PostgreSQL), dotenv, joi (validation)
  - [ ] Setup ESLint, Prettier, Jest for testing
  - [ ] Create .env.example, docker-compose.yml for local development
  - [ ] Initialize database migration framework (Sequelize migrations)

- [ ] **Database migrations: Create tables** — Sequelize migrations for all five tables (Asset, AssetAssignment, LeaseCarTaxRecord, AssetHistoryEntry, AssetCategory).
  - [ ] Migration: 001_create_asset.js
  - [ ] Migration: 002_create_asset_assignment.js
  - [ ] Migration: 003_create_lease_car_tax_record.js
  - [ ] Migration: 004_create_asset_history_entry.js
  - [ ] Migration: 005_create_asset_category.js
  - [ ] Create indexes, constraints, triggers
  - [ ] Test migrations: up and down

- [ ] **Authentication & Authorization** — Integrate with HRMQ OAuth2/OIDC, implement role-based middleware (asset_manager, hr_admin, employee, auditor).
  - [ ] Setup OAuth2 client (code, token, refresh token flow)
  - [ ] Create middleware: requireRole([roles])
  - [ ] Implement user context extraction (user ID, roles, organization)
  - [ ] Document how roles are retrieved (from OAuth provider, internal role table, or attribute)

### Core Asset Management

- [ ] **API: POST /api/assets** — Create new asset with type-driven field validation.
  - [ ] Implement request validation (joi schema per asset type)
  - [ ] Implement leverancier lookup (OpenRegister Organisation API or local cache)
  - [ ] Implement serienummer uniqueness check (per type + org)
  - [ ] Implement lease-field validation (required if eigendoms_type in *lease)
  - [ ] Implement default afschrijvingstermijn by type
  - [ ] Insert Asset row, set status=ingenomen, created_at/by
  - [ ] Return 201 Created with asset ID

- [ ] **API: GET /api/assets/{id}** — Fetch asset detail with related data.
  - [ ] Query Asset, LeaseCarTaxRecord (if type=lease_auto), current active AssetAssignment
  - [ ] Calculate booked_value (linear depreciation from aankoopdatum, afschrijvingstermijn)
  - [ ] Calculate days_until_eol (if still active)
  - [ ] Return full asset object with calculated fields
  - [ ] Implement role-based field visibility (employee cannot see price, supplier)

- [ ] **API: PATCH /api/assets/{id}** — Update asset (limited fields: merk_en_model, leverancier_id, status overrides).
  - [ ] Validate requestor is asset_manager or hr_admin
  - [ ] Allow updates to merk_en_model (non-critical)
  - [ ] Allow status manual override (rare, logged to history)
  - [ ] Disallow updates to aankoopdatum, afschrijvingstermijn, serienummer (immutable)
  - [ ] Update row, set updated_at/by
  - [ ] Return 200 OK with updated asset

- [ ] **API: GET /api/assets** — List assets with filters and pagination.
  - [ ] Implement query filters: status, type, employee_id, category_id, created_date_range, leverancier_id
  - [ ] Implement pagination: limit (default 20, max 100), offset
  - [ ] Implement sorting: by creation date, status, type
  - [ ] Calculate booked_value for each row (performance: consider caching or on-demand)
  - [ ] Return paginated list with metadata (total_count, page_info)

- [ ] **API: DELETE /api/assets/{id}** — Soft-delete (archive) asset and related data.
  - [ ] Validate requestor is hr_admin
  - [ ] Mark Asset.archived_at = now
  - [ ] Set all AssetAssignments.inname_datum = now (close active assignments)
  - [ ] Set Asset.status = archived (or similar sentinel value)
  - [ ] Create AssetHistoryEntry: event_type = archive
  - [ ] Return 204 No Content or 200 OK

### Asset Assignment Workflow

- [ ] **API: POST /api/assets/{id}/assignments** — Create asset assignment (uitgifte).
  - [ ] Validate requestor is asset_manager or hr_admin
  - [ ] Validate Asset.status ∉ [vermist, afgeschreven, verkocht]
  - [ ] Validate no active AssetAssignment exists (WHERE inname_datum IS NULL)
  - [ ] Validate employee_id exists (query employee-master app)
  - [ ] Insert AssetAssignment with uitgifte_datum, asset_id, employee_id, staat_bij_uitgifte, uitgifte_door
  - [ ] Update Asset.status = ausgeleend
  - [ ] Create AssetHistoryEntry: event_type = uitgifte, new_value = {status: "ausgeleend"}
  - [ ] If Asset.type = lease_auto:
    - [ ] Create or query existing LeaseCarTaxRecord
    - [ ] Return 201 with assignment ID and lease_car_tax_record status (awaiting privé_gebruik_verklaring)
  - [ ] Else:
    - [ ] Emit event: asset_assigned { asset_id, employee_id, asset_type, assignment_date }
    - [ ] Return 201 with assignment ID

- [ ] **API: PATCH /api/assets/{id}/assignments/{aid}** — Close assignment (inname).
  - [ ] Validate requestor is asset_manager or hr_admin
  - [ ] Query AssetAssignment by id (validate ownership: asset_id match)
  - [ ] Validate inname_datum IS NULL (not already closed)
  - [ ] Validate staat_bij_inname in enum (goed, beschadigd, vermist)
  - [ ] Update: inname_datum = provided date (or today), staat_bij_inname, inname_door, opmerking_bij_inname (if provided)
  - [ ] Update Asset.status based on staat_bij_inname:
    - [ ] goed → ingenomen
    - [ ] beschadigd → in_reparatie
    - [ ] vermist → vermist (immutable, no future assignments)
  - [ ] Create AssetHistoryEntry: event_type = inname, previous_value, new_value, note = opmerking
  - [ ] If staat_bij_inname = beschadigd:
    - [ ] Create notification: asset_manager, "Asset beschadigd; reparatie nodig?"
  - [ ] Emit event: asset_returned { asset_id, employee_id, asset_type, return_date, condition, bijtelling_monthly = null }
  - [ ] Return 200 OK or 204 No Content

- [ ] **API: GET /api/assets/{id}/assignments** — Fetch assignment history (past and present).
  - [ ] Query all AssetAssignments for asset_id, sorted by uitgifte_datum DESC
  - [ ] Include current active assignment (if exists)
  - [ ] Return list with employee details (name, ID), dates, states

### Lease Car Tax Management

- [ ] **API: POST /api/assets/{id}/lease-car-tax** — Create or confirm LeaseCarTaxRecord on asset registration.
  - [ ] Validate Asset.type = lease_auto
  - [ ] Validate required fields: kenteken, datum_eerste_tenaamstelling, cataloguswaarde, brandstoftype
  - [ ] Validate kenteken format (Dutch regex)
  - [ ] Validate CO2 emissions (required for benzine/diesel, ignored for elektrisch)
  - [ ] Compute bijtelling_einddatum_termijn = DET + 60 months (via trigger)
  - [ ] Insert LeaseCarTaxRecord
  - [ ] Return 201 with record ID, awaiting privé_gebruik_verklaring

- [ ] **API: GET /api/assets/{id}/lease-car-tax** — Fetch current lease tax record with calculated bijtelling.
  - [ ] Query LeaseCarTaxRecord by asset_id
  - [ ] Query current staffel (by fiscal year)
  - [ ] Calculate bijtelling percentage (see REQ-004 logic):
    - [ ] Determine if within 60-month term (DET + 60mo ≤ today)
    - [ ] If expired: apply overgangsrecht (35% flat)
    - [ ] If within term: apply fiscal staffel for current year + fuel type + cataloguswaarde bracket
  - [ ] Calculate monthly amount = annual % × cataloguswaarde / 12, respecting privé_gebruik_verklaring:
    - [ ] none → €0
    - [ ] beperkt_500km → full amount (audit obligation)
    - [ ] vol → full amount
  - [ ] Return record with bijtelling_maand (calculated, not stored directly; or cached in db_field for perf)

- [ ] **API: PATCH /api/assets/{id}/lease-car-tax** — Update privé_gebruik_verklaring or corrective data.
  - [ ] Validate Asset.type = lease_auto
  - [ ] Allow updates: privé_gebruik_verklaring, kenteken, cataloguswaarde (rare, corrective), co2_uitstoot (rare)
  - [ ] Disallow updates: datum_eerste_tenaamstelling (immutable), bijtelling_einddatum_termijn (immutable)
  - [ ] On privé_gebruik_verklaring change:
    - [ ] Recalculate bijtelling monthly amount
    - [ ] Emit event: lease_car_tax_declaration_updated { asset_id, employee_id (if assigned), privé_gebruik_verklaring, new_bijtelling_monthly }
    - [ ] If employee currently assigned: notify payroll of change
  - [ ] Update row, set updated_at/by
  - [ ] Return 200 OK

### Bijtelling Calculation Service

- [ ] **Service: FiscalStaffelService** — Load and apply fiscal staffel rules.
  - [ ] Implement staffel_version table (year, publication_date, staffel_rules JSON)
  - [ ] Load current staffel (by fiscal year or latest)
  - [ ] Implement method: getStaffelPercentage(fuel_type, cataloguswaarde, year) → percentage
  - [ ] Handle multi-bracket logic (e.g., €30k first bracket @ 16%, rest @ 22%)
  - [ ] Implement overgangsrecht detection (vehicle > 60 months → 35%)
  - [ ] Write unit tests: 10+ scenarios per fuel type, boundary cases

- [ ] **Service: BijtellingsCalculatorService** — Calculate monthly bijtelling for a lease-car.
  - [ ] Method: calculateBijtelling(lease_car_tax_record, fiscal_year) → { percentage, monthly_amount, reason }
  - [ ] Call FiscalStaffelService for percentage
  - [ ] Apply privé_gebruik logic (none → €0, beperkt/vol → full)
  - [ ] Return structured result with audit note (e.g., "EV staffel 2026, ≤€30k bracket, 16%")
  - [ ] Implement caching (memoization) to avoid recalculation on each API call

- [ ] **Batch Job: Daily bijtelling staffel update** — Run at 02:00 UTC.
  - [ ] Query all LeaseCarTaxRecords
  - [ ] For each record:
    - [ ] Check if fiscal year changed (new staffel published) OR DET + 60mo ≤ today
    - [ ] If trigger detected:
      - [ ] Recalculate bijtelling (call BijtellingsCalculatorService)
      - [ ] If percentage changed:
        - [ ] Update LeaseCarTaxRecord.bijtellingspercentage_huidig
        - [ ] Create AssetHistoryEntry: event_type = bijtelling_staffelovergang, previous = {percentage, monthly}, new = {percentage, monthly}
        - [ ] Emit event: lease_car_bijtelling_update { asset_id, employee_id (if assigned), old/new percentage, new_monthly, reason }
        - [ ] Notify asset_manager (if threshold-worthy): "Bijtelling updated: 3 vehicles; 1 staffel change, 2 term-boundary resets"
  - [ ] Handle errors gracefully (log, alert on failure)

### Event Bus Integration

- [ ] **Service: EventPublisher** — Publish events to RabbitMQ.
  - [ ] Setup RabbitMQ connection (channel, exchange, queue bindings)
  - [ ] Implement method: publishEvent(event_type, payload) → Promise
  - [ ] Implement idempotency: assign event_id (UUID), deduplicate on retry
  - [ ] Implement retry logic: exponential backoff, max 5 retries, then DLQ
  - [ ] Implement transaction wrapper: publish event only if DB changes committed

- [ ] **Event: asset_assigned** — Publish on asset assignment creation.
  - [ ] Trigger: after AssetAssignment INSERT succeeds
  - [ ] Payload schema: { asset_id, employee_id, asset_type, assignment_date, bijtelling_monthly (if lease_auto, else null) }
  - [ ] Test: publish mock event, verify payroll-engine-nl receives correct schema

- [ ] **Event: asset_returned** — Publish on asset return.
  - [ ] Trigger: after AssetAssignment.inname_datum IS SET
  - [ ] Payload schema: { asset_id, employee_id, asset_type, return_date, condition, bijtelling_monthly = null }
  - [ ] Test: verify payroll-engine-nl ceases charging bijtelling after return

- [ ] **Event: lease_car_bijtelling_update** — Publish on staffel change or term boundary.
  - [ ] Trigger: after LeaseCarTaxRecord.bijtellingspercentage_huidig IS UPDATED (in batch job)
  - [ ] Payload schema: { asset_id, employee_id (if assigned), old_bijtelling_monthly, new_bijtelling_monthly, reason ("fiscal_staffel_YYYY" or "60mo_term_boundary") }
  - [ ] Test: verify payroll-engine-nl updates loon-in-natura amount

### Asset History & Audit Trail

- [ ] **Service: AssetHistoryService** — Create and query audit log entries.
  - [ ] Method: logEvent(asset_id, event_type, actor_id, employee_id, note, previous_value, new_value) → AssetHistoryEntry
  - [ ] Validate event_type in enum
  - [ ] Insert row (append-only trigger prevents updates)
  - [ ] Return created entry
  - [ ] Method: getAssetHistory(asset_id, limit, offset) → [AssetHistoryEntry]
  - [ ] Query by asset_id, sorted by event_at DESC

- [ ] **API: GET /api/assets/{id}/history** — Fetch asset history timeline.
  - [ ] Query AssetHistoryEntry by asset_id, sorted by event_at DESC
  - [ ] Implement pagination
  - [ ] Format response: event_at, event_type, actor_name, description (computed from event_type + values), note
  - [ ] Return structured history entries

### Access Control & Field Masking

- [ ] **Middleware: RoleBasedFieldVisibility** — Filter response fields based on user role.
  - [ ] For employee role: hide price, cost, leverancier details, bijtelling, serial (except for identification), booked_value, lease terms
  - [ ] For auditor role: show all read-only fields, hide edit buttons
  - [ ] For asset_manager/hr_admin: show all fields, enable edit buttons
  - [ ] Apply at API response serialization layer

- [ ] **Utility: SensitiveFieldMasker** — Mask/anonymize sensitive fields in exports.
  - [ ] Mask leverancier contact info (keep only company name)
  - [ ] Mask employee ID in GDPR subject-access exports (replace with pseudo-ID after 2 years)
  - [ ] Mask serial numbers for certain asset types (configurable)

### Validation & Error Handling

- [ ] **Validation schema (Joi)** — Define schemas per endpoint.
  - [ ] Asset creation schema: type, serienummer, merk_en_model, aankoopdatum, prices, afschrijvingstermijn, lease fields conditional
  - [ ] Assignment schema: asset_id, employee_id, uitgifte_datum, staat_bij_uitgifte
  - [ ] Return schema: staat_bij_inname, inname_datum, opmerking_bij_inname
  - [ ] Lease car tax schema: kenteken (regex), DET, cataloguswaarde, brandstoftype, CO2 (conditional), privé_gebruik

- [ ] **Error handler middleware** — Centralized error responses.
  - [ ] Format: { error: { code, message, details, timestamp } }
  - [ ] Handle validation errors (422 Unprocessable Entity)
  - [ ] Handle business logic errors (409 Conflict, 400 Bad Request)
  - [ ] Handle auth/authz errors (401 Unauthorized, 403 Forbidden)
  - [ ] Log errors with context (user, asset_id, etc.)
  - [ ] Hide sensitive error details from client (log internally)

### Testing

- [ ] **Unit tests** — Test individual services and utilities.
  - [ ] BijtellingsCalculatorService: 15+ test cases (staffel, brackets, privé_gebruik, edge cases)
  - [ ] FiscalStaffelService: staffel lookup, overgangsrecht, year transitions
  - [ ] AssetHistoryService: log and query operations
  - [ ] SensitiveFieldMasker: field hiding per role
  - [ ] Target: >80% coverage

- [ ] **Integration tests** — Test API endpoints and DB interactions.
  - [ ] POST /assets: create asset, validate DB insert, test serienummer uniqueness
  - [ ] GET /assets/{id}: fetch asset, calc booked_value, test role-based visibility
  - [ ] POST /assets/{id}/assignments: assign asset, validate status transitions, test event emission
  - [ ] PATCH /assets/{id}/assignments/{aid}: return asset, test conditional status updates
  - [ ] PATCH /assets/{id}/lease-car-tax: update privé_gebruik, test bijtelling recalc, test event emission
  - [ ] Batch job: test staffel update trigger, verify event emission and DB updates
  - [ ] Target: >80% coverage

- [ ] **End-to-end test scenarios** — Test complete workflows.
  - [ ] Scenario: Create laptop, assign to employee, return (goed), verify status transitions
  - [ ] Scenario: Create lease car, assign with privé_gebruik (vol), verify bijtelling, emit event, verify payroll event received
  - [ ] Scenario: Staffel year change, verify bijtelling updated via batch job
  - [ ] Scenario: Lease car reaches 60-month term boundary, verify overgangsrecht applied

---

## Phase 1c: Frontend UI & Employee Self-Service (Target: 2026-08-31)

### Asset Management UI (HR Admin)

- [ ] **Component: AssetForm** — Create/edit asset with type-driven fields.
  - [ ] Implement conditional rendering: if type=lease_auto, show lease fields
  - [ ] Implement leverancier autocomplete (OpenRegister lookup)
  - [ ] Implement price formatting (EUR, 2 decimals)
  - [ ] Implement date picker (ISO format)
  - [ ] Implement validation messages per field
  - [ ] Test: all asset types, edge cases (software_license without serial, lease fields required, etc.)

- [ ] **Component: AssetList** — Table view of assets with filters and pagination.
  - [ ] Implement columns: type, serial/kenteken, model, status, employee, booked_value, depreciation%, created_at
  - [ ] Implement filters: status dropdown, type dropdown, employee search, date range
  - [ ] Implement sorting (click headers)
  - [ ] Implement pagination (limit, prev/next)
  - [ ] Implement bulk actions (soft-delete, status override)
  - [ ] Implement export button (CSV) for auditor/hr_admin
  - [ ] Test: large dataset (1000+ assets), filter combinations

- [ ] **Component: AssetDetail** — Full asset view with history timeline and assignment UI.
  - [ ] Section: Asset info (type, serial, model, cost, depreciation, booked_value, status)
  - [ ] Section: Current assignment (employee, uitgifte_datum, condition, etc.)
  - [ ] Section: Lease car tax record (if type=lease_auto; kenteken, DET, bijtelling)
  - [ ] Section: History timeline (events, sorted by date DESC, with actor names)
  - [ ] Button: "Uitgifte" (if no active assignment) → open assignment modal
  - [ ] Button: "Innemen" (if active assignment) → open return modal
  - [ ] Button: "Edit" (if hr_admin) → open form
  - [ ] Button: "Delete" (if hr_admin) → confirm soft-delete
  - [ ] Test: all asset types, active/historical assignments, history pagination

- [ ] **Modal: AssetAssignmentForm** — Assign asset to employee (uitgifte).
  - [ ] Field: Employee search (autocomplete by name, ID)
  - [ ] Field: staat_bij_uitgifte (radio: nieuw, goed, gebruikt, beschadigd)
  - [ ] Field: uitgifte_datum (date picker, editable for backdating)
  - [ ] Button: "Bevestigen" (disabled until employee + state selected)
  - [ ] On success (lease_auto): follow-up modal for privé_gebruik_verklaring
  - [ ] Test: employee lookup, backdating, lease_auto workflow

- [ ] **Modal: AssetReturnForm** — Close assignment (inname).
  - [ ] Field: staat_bij_inname (radio: goed, beschadigd, vermist)
  - [ ] Field: inname_datum (date picker, optional backdating)
  - [ ] Field: opmerking_bij_inname (text area, optional)
  - [ ] Button: "Bevestigen"
  - [ ] Test: all states, notifications (beschadigd, vermist)

- [ ] **Modal: LeaseCarPrivéGebruikDeclaration** — Declare privé-gebruik for lease car.
  - [ ] Radio options: none / beperkt_500km / vol
  - [ ] Helper text explaining each option and audit obligations
  - [ ] Button: "Opslaan"
  - [ ] On success: confirmation, dismiss modal
  - [ ] Test: all options, payroll event emission

### Reporting & Auditing UI

- [ ] **Component: DepreciationReport** — Asset depreciation schedule.
  - [ ] Table: Asset, type, purchase_date, cost_incl_vat, term_months, monthly_depreciation, booked_value, end_date, status
  - [ ] Filters: date range, asset type, status
  - [ ] Sorting: by booked value, end date, type
  - [ ] Export button (CSV, PDF)
  - [ ] Notification: assets reaching EOL (status = "deprecated")
  - [ ] Test: large dataset, export formats

- [ ] **Component: BijtellingsReport** — Lease car bijtelling schedule for payroll audit.
  - [ ] Table: Asset (kenteken), employee, current_bijtelling_monthly, staffel_percentage, bijtelling_term_end, privé_gebruik, last_updated
  - [ ] Filters: employee, status, term_end_date_range
  - [ ] Sorting: by bijtelling amount, term end date
  - [ ] Export button (CSV) for payroll team
  - [ ] Notification: upcoming term-boundary resets
  - [ ] Test: accuracy of bijtelling calculations, export format

- [ ] **Component: AssetHistoryReport** — Comprehensive audit log (employees + assets).
  - [ ] Filters: employee name, asset type, event_type, date range
  - [ ] Table: employee, asset, event_type, event_at, actor, note
  - [ ] Export button (CSV/JSON for archival)
  - [ ] Test: GDPR filtering (anonymized records, data retention rules)

### Employee Self-Service UI

- [ ] **Component: EmployeeAssetList** — My assets view (limited visibility).
  - [ ] Display: type, model, assigned_date, status (active/returned)
  - [ ] Hide: price, cost, leverancier details, bijtelling, serial (except model identification)
  - [ ] Button: "Report Damage" (if active asset)
  - [ ] Button: "View Details" (view-only; no edit)
  - [ ] Test: privacy filtering, damage report flow

- [ ] **Modal: DamageReport** — Report asset damage.
  - [ ] Field: description (text area)
  - [ ] Field: photo upload (optional, max 5 MB)
  - [ ] Button: "Submit"
  - [ ] On success: asset_manager notified, confirmation shown to employee
  - [ ] Test: photo upload validation, notification logic

- [ ] **Component: EmployeeLeaseCar** — Lease car privé-gebruik declaration (if assigned).
  - [ ] Display: lease car model, kenteken, current privé_gebruik declaration
  - [ ] Button: "Update Declaration" (if not yet declared)
  - [ ] Opens LeaseCarPrivéGebruikDeclaration modal (reused from admin UI)
  - [ ] Confirmation: declaration saved, bijtelling impact shown
  - [ ] Test: role-based visibility (only leased cars visible), declaration workflow

### Offboarding Integration

- [ ] **Component: OffboardingAssetChecklist** — Asset return checklist in offboarding wizard.
  - [ ] Display: list of active assets (laptop, lease car, etc.) still assigned
  - [ ] Actions per asset: [Mark as Returned] [Mark as Missing]
  - [ ] On return: show condition selector (good / damaged / missing_damaged)
  - [ ] Validation: all assets must be marked before advancing
  - [ ] Test: multiple assets, various states, wizard flow

### General UI Components

- [ ] **Component: DatePicker** — Reusable date picker with ISO format handling.
  - [ ] Support: past dates (backdating), future dates, today shortcut
  - [ ] Format: display locale (e.g., "22 mei 2026"), internal value ISO (2026-05-22)
  - [ ] Test: various locales, edge dates

- [ ] **Component: EuroInput** — Price input with EUR formatting.
  - [ ] Input: numeric, comma/period decimal separator
  - [ ] Display: formatted with € and 2 decimals
  - [ ] Test: various inputs (0.00, 1.5, 1200.50, negative values)

- [ ] **Component: EnumSelect** — Dropdown for enum fields.
  - [ ] Support: status (enum), asset type (enum), fuel type (enum), etc.
  - [ ] Display: human-readable labels (e.g., "Goed" for enum value "goed")
  - [ ] Test: various enums, disabled state

### Styling & Responsive Design

- [ ] **Styling setup** — Vue 3 + Vuetify (consistent with HRMQ design system).
  - [ ] Import Vuetify components (Button, TextField, Select, Table, Modal, etc.)
  - [ ] Define custom theme (colors, typography, spacing consistent with HRMQ)
  - [ ] Test: light/dark mode toggle

- [ ] **Responsive design** — Mobile-friendly UI.
  - [ ] Test: AssetList table on mobile (collapsible columns, horizontal scroll, or card view)
  - [ ] Test: Forms on mobile (stacked inputs, full-width buttons)
  - [ ] Test: Barcode scanner on mobile (camera access, QR overlay)

### Testing

- [ ] **Component tests** — Test individual Vue components (Jest + Vue Test Utils).
  - [ ] AssetForm: test conditional field display, validation messages
  - [ ] AssetList: test filtering, sorting, pagination, export
  - [ ] AssetDetail: test all sections, assignment modals, history timeline
  - [ ] EmployeeAssetList: test privacy filtering, damage report modal
  - [ ] Target: >75% coverage

- [ ] **E2E tests** — Test complete user workflows (Cypress or Playwright).
  - [ ] Workflow: Create asset → assign to employee → return (goed) → verify status
  - [ ] Workflow: Create lease car → assign with privé_gebruik (vol) → verify bijtelling shown
  - [ ] Workflow: Employee views own assets, reports damage
  - [ ] Workflow: Offboarding checklist: mark assets returned, unlock eind-afrekening
  - [ ] Target: >70% coverage of critical paths

---

## Phase 1d: Integration Tests (Target: 2026-09-15)

### Payroll Integration

- [ ] **Test: Asset assignment → payroll bijtelling** — Verify end-to-end flow.
  - [ ] Setup: create lease car, assign to employee with bijtelling €390/month
  - [ ] Verify: asset_assigned event emitted with correct bijtelling
  - [ ] Verify: payroll-engine-nl consumes event (mock or live)
  - [ ] Verify: loon-in-natura added to payroll (€208 in April pro-rata, €390 in May+)
  - [ ] Verify: payroll calculation includes bijtelling correctly

- [ ] **Test: Staffel update → payroll update** — Verify bijtelling recalc triggers payroll change.
  - [ ] Setup: lease car with bijtelling €250/month
  - [ ] Trigger: batch job updates staffel (new year or term boundary)
  - [ ] Verify: LeaseCarTaxRecord.bijtellingspercentage_huidig updated
  - [ ] Verify: lease_car_bijtelling_update event emitted
  - [ ] Verify: payroll-engine-nl updates loon-in-natura to new amount

- [ ] **Test: Asset return → payroll cessation** — Verify bijtelling removed from payroll.
  - [ ] Setup: lease car assigned, bijtelling €390/month active
  - [ ] Action: return asset
  - [ ] Verify: asset_returned event emitted
  - [ ] Verify: payroll-engine-nl stops charging bijtelling from next month

### Employee-Master Integration

- [ ] **Test: Employee lookup** — Verify asset assignment validates employee exists.
  - [ ] Setup: non-existent employee ID
  - [ ] Action: attempt to assign asset
  - [ ] Verify: validation error (404 or "employee not found")

- [ ] **Test: Offboarding → asset checklist** — Verify offboarding triggers asset check.
  - [ ] Setup: employee with 2 active assets
  - [ ] Action: initiate offboarding via employee-master
  - [ ] Verify: offboarding wizard displays asset-check step
  - [ ] Verify: asset list shows correct active assignments
  - [ ] Action: mark assets returned/missing
  - [ ] Verify: wizard unlocks eind-afrekening step

### OpenRegister Integration

- [ ] **Test: Leverancier lookup** — Verify OpenRegister Organisation search works.
  - [ ] Action: create asset, search leverancier "Dell"
  - [ ] Verify: dropdown populates with matching organizations
  - [ ] Verify: selected org ID stored correctly

- [ ] **Test: Leasemaatschappij lookup** — Verify lease-company lookup.
  - [ ] Action: create lease_auto, search leasemaatschappij "Athlon"
  - [ ] Verify: matching org appears, can be selected
  - [ ] Verify: org ID stored in LeaseCarTaxRecord

### Event Bus Integration

- [ ] **Test: RabbitMQ event emission** — Verify events published correctly.
  - [ ] Setup: RabbitMQ test instance (Docker)
  - [ ] Action: trigger asset assignment
  - [ ] Verify: message published to correct exchange/queue
  - [ ] Verify: message schema matches contract (JSON)
  - [ ] Verify: idempotency key (event_id) present

- [ ] **Test: Event retry logic** — Verify events are retried on failure.
  - [ ] Setup: mock payroll-engine-nl subscriber that fails initially
  - [ ] Action: publish event
  - [ ] Verify: event retried (exponential backoff)
  - [ ] Verify: DLQ fallback if max retries exceeded

### Data Consistency

- [ ] **Test: Asset status transitions** — Verify status changes are atomic and correct.
  - [ ] Test: ingenomen → ausgeleend (on assignment)
  - [ ] Test: ausgeleend → ingenomen (on return, goed)
  - [ ] Test: ausgeleend → in_reparatie (on return, beschadigd)
  - [ ] Test: ausgeleend → vermist (on return, vermist; immutable)
  - [ ] Test: Attempt transition from vermist (should fail)

- [ ] **Test: AssetAssignment active-assignment uniqueness** — Verify only one active per asset.
  - [ ] Test: Create assignment, verify inname_datum = null
  - [ ] Test: Attempt second assignment → should fail (unique constraint or validation)
  - [ ] Test: Close first assignment, create second → should succeed

- [ ] **Test: LeaseCarTaxRecord consistency** — Verify 1:1 with lease_auto Asset.
  - [ ] Test: Create lease_auto, verify LeaseCarTaxRecord created or queued
  - [ ] Test: Attempt delete Asset, verify cascade behavior (disallow or cascade to tax record)

- [ ] **Test: AssetHistoryEntry append-only** — Verify no updates/deletes allowed.
  - [ ] Test: Create entry, query success
  - [ ] Test: Attempt update on entry → DB-level constraint should block
  - [ ] Test: Attempt delete on entry → DB-level constraint should block

### Performance

- [ ] **Test: Large asset count** — Verify system performs with 1000+ assets.
  - [ ] Setup: load 1000 assets + assignments
  - [ ] Test: GET /assets with pagination (limit 20) → <200ms
  - [ ] Test: GET /assets/{id}/history → <500ms
  - [ ] Test: Daily batch job (staffel update) → <60s
  - [ ] Verify: index utilization, no N+1 queries

- [ ] **Test: Concurrent assignments** — Verify race conditions handled.
  - [ ] Test: Simulate concurrent POST /assets/{id}/assignments for same asset
  - [ ] Verify: only one succeeds (unique constraint on active assignment)

---

## Phase 1e: Alpha Release & Pilot (Target: 2026-09-30)

### Pilot Deployment

- [ ] **Setup: 5 pilot customer instances** — Deploy to 5 HRMQ instances with real data.
  - [ ] Select diverse customers (1 large MKB, 2 small, 1 manufacturing, 1 services)
  - [ ] Import historical asset data via CSV bulk import
  - [ ] Train HR staff on asset-management UI and workflows
  - [ ] Setup monitoring and logging (error tracking, event lag)

- [ ] **Stakeholder communication** — Document pilot scope, timelines, feedback channels.
  - [ ] Send launch email to pilot customers with onboarding guide
  - [ ] Setup dedicated Slack channel for feedback
  - [ ] Schedule weekly sync calls to gather feedback

### Pilot Validation & Feedback Loop

- [ ] **Success metric: Adoption** — Verify customers actively use asset-management.
  - [ ] Target: ≥70% of pilot customers have ≥5 assets registered
  - [ ] Target: ≥3 assignments per customer during pilot

- [ ] **Success metric: Accuracy** — Verify bijtelling calculations are correct.
  - [ ] Target: No bijtelling calculation errors (0% error rate in pilot)
  - [ ] Spot-check: manually verify bijtelling for 5 lease cars against Belastingdienst staffel
  - [ ] Spot-check: compare bijtelling export with payroll records

- [ ] **Success metric: Payroll coupling** — Verify events are correctly processed by payroll.
  - [ ] Target: 100% of asset_assigned events consumed by payroll-engine-nl
  - [ ] Target: <5min lag between assignment and payroll update
  - [ ] Spot-check: verify payroll calculation includes bijtelling correctly

- [ ] **Feedback collection** — Gather qualitative & quantitative feedback.
  - [ ] Survey: pilot customers on usability (1-10 scale), pain points, feature requests
  - [ ] Collect: support tickets, error logs, performance issues
  - [ ] Identify: bugs, missing features, UX improvements

### Bug Fixes & Refinements

- [ ] **Critical bugs** — Fix any show-stopper issues found in pilot.
  - [ ] If bijtelling calculation wrong: coordinate with tax specialist, deploy corrected staffel
  - [ ] If payroll coupling failing: debug event flow, retry logic, verify message delivery
  - [ ] If performance issues: optimize queries, add indexes, cache results

- [ ] **UX refinements** — Adjust UI based on pilot feedback.
  - [ ] If form fields confusing: add tooltips, reorder fields, improve labels
  - [ ] If workflow cumbersome: streamline steps, add shortcuts, improve defaults

- [ ] **Feature prioritization** — Triage feature requests for Phase 2.
  - [ ] In-scope Phase 2: barcode/QR scan, manager approval workflow, expense integration
  - [ ] Out-of-scope: integrations with external asset tools, advanced reporting

### Pilot Sign-Off

- [ ] **Gate 3 Review** — Assess readiness for GA release.
  - [ ] Verify: REQ-001 through REQ-010 all implemented and tested
  - [ ] Verify: bijtelling calculations audited by tax specialist
  - [ ] Verify: payroll coupling validated with payroll-engine-nl team
  - [ ] Verify: pilot customer satisfaction ≥8/10
  - [ ] Verify: no critical/open bugs (P0/P1)
  - [ ] Decision: PROCEED to GA release or iterate further

### Documentation & Training

- [ ] **Admin guide** — Document asset-management for HR/asset staff.
  - [ ] Create: asset-management user guide (asset creation, assignment, bijtelling explanation)
  - [ ] Create: FAQ for common questions (serienummer format, privé_gebruik options, lease-car rules)
  - [ ] Create: troubleshooting guide (common errors, payroll coupling issues)
  - [ ] Create: staffel update procedure (how to update fiscal tables annually)

- [ ] **Employee guide** — Document self-service features.
  - [ ] Create: "My Assets" guide (view assets, report damage, declare privé_gebruik)
  - [ ] Create: FAQ (what assets can I see, what is bijtelling, privacy)

- [ ] **API documentation** — Generate OpenAPI spec and developer guide.
  - [ ] Export: OpenAPI 3.0 spec from code
  - [ ] Create: API reference (endpoints, schemas, examples)
  - [ ] Create: Integration guide for partners (e.g., openconnector, external lease-companies)

- [ ] **Training sessions** — Conduct training for pilot customers.
  - [ ] Webinar: asset-management overview (30 min, recorded)
  - [ ] Workshop: hands-on asset creation and assignment (60 min, live)
  - [ ] Q&A: respond to customer questions, document for FAQ

---

## Phase 2: GA Release & Beyond (Target: 2026-10-15 & later)

### Phase 2 Features (Out of Scope for Phase 1)

- [ ] **Barcode/QR generation & scanning** — Auto-generate QR codes for assets, scan via mobile app
- [ ] **Manager approval workflow** — Managers approve asset requests before HR executes assignment
- [ ] **openconnector integrations** — Auto-import lease-contract updates from Athlon, Leaseplan, etc.
- [ ] **Expense reimbursement linkage** — Link repair costs and EV charging reimbursements to assets
- [ ] **Depreciation schedule reports** — Detailed fixed-asset accounting schedules per period
- [ ] **Asset maintenance scheduling** — Maintenance calendar for regular inspections (tires, battery, etc.)
- [ ] **Advanced analytics** — Asset spend trends, depreciation curves, utilization rates per department

### Post-GA Monitoring

- [ ] **Ongoing performance monitoring** — Track system health and user satisfaction.
  - [ ] Monitor: event lag (asset_assigned → payroll update), API response times, DB query times
  - [ ] Monitor: error rates (payroll coupling failures, validation errors)
  - [ ] Monitor: user adoption (active instances, feature usage)
  - [ ] Alert: SLA breaches (e.g., >10min event lag, >1% payroll coupling failure rate)

- [ ] **Annual staffel update process** — Keep fiscal tables current.
  - [ ] Jan 1 (or before): Belastingdienst publishes new bijtelling percentages
  - [ ] Process: HRMQ tax specialist reviews, updates staffel_version table
  - [ ] Verification: batch job applies new staffel, audit logs changes
  - [ ] Communication: notify all instances of new staffel (in-app notification)

- [ ] **Regulatory compliance monitoring** — Stay aligned with changing laws.
  - [ ] Monitor: Belastingdienst guidance updates, case law changes
  - [ ] Process: assess impact on asset-management calculations (e.g., new fuel types, brackets)
  - [ ] Update: ADR-002 (staffel rules) as needed, communicate changes to customers

---

## Success Criteria Checklist

### Functional Completeness
- [ ] REQ-001: Asset registration with type-driven validation ✓
- [ ] REQ-002: Asset assignment with conflict prevention ✓
- [ ] REQ-003: Asset return with state tracking ✓
- [ ] REQ-004: Lease car bijtelling calculation per fiscal rules ✓
- [ ] REQ-005: Automatic staffel transition & term-boundary handling ✓
- [ ] REQ-006: Payroll event coupling (RabbitMQ) ✓
- [ ] REQ-007: Asset history & audit trail ✓
- [ ] REQ-008: Depreciation & end-of-life tracking ✓
- [ ] REQ-009: Bulk import & barcode scan (MVP version) ✓
- [ ] REQ-010: Role-based access control & GDPR ✓

### Quality & Compliance
- [ ] Bijtelling calculations audited by tax specialist, matching Belastingdienst 2026 staffel ✓
- [ ] Payroll coupling validated with payroll-engine-nl team ✓
- [ ] GDPR anonymization strategy reviewed by legal ✓
- [ ] Code test coverage: backend >80%, frontend >75% ✓
- [ ] E2E test coverage: critical workflows >70% ✓
- [ ] Performance benchmarks met (API <200ms, batch <60s) ✓
- [ ] No critical/open bugs (P0/P1) at GA release ✓

### Customer Validation
- [ ] Pilot 5 customer instances successfully deployed ✓
- [ ] Customer satisfaction ≥8/10 on primary use cases ✓
- [ ] Support ticket volume <5% of payroll tickets (baseline: 0 today) ✓
- [ ] Adoption: ≥70% of pilot customers using asset-management within 30 days ✓

### Documentation & Support
- [ ] Admin guide (asset creation, bijtelling explanation, staffel updates) complete ✓
- [ ] Employee guide (My Assets, damage reporting, privé_gebruik declaration) complete ✓
- [ ] API documentation (OpenAPI + developer guide) published ✓
- [ ] Training materials (webinar, workshop, FAQ) delivered ✓
- [ ] Support team trained and ready for GA escalations ✓

---
