---
status: specs
title: Multi-administratie (Multi-tenant Payroll & HR Partitioning)
author: Specter Intelligence
date: 2026-05-23
---

# Multi-administratie: Specifications

## REQ-001: Tenant-isolatie op data-niveau

**Description:** No query, list call, search, or export may display data from an administratie for which the current user does not hold an `AdministratieRol`.

### Scenario: Role-based list filtering

- **GIVEN** a user with role `hr_manager` on administratie A
- **WHEN** they call `GET /api/medewerkers`
- **THEN** the response contains only employees with `administratie_id = A`
- **AND** the response includes a `X-Tenant-Context: adm-001` header

### Scenario: Direct access to cross-tenant object returns 404

- **GIVEN** a user without any role on administratie B
- **WHEN** they attempt `GET /api/medewerkers/{id-of-B-employee}` directly
- **THEN** the API responds with `404 Not Found`
- **AND** no indication that the employee exists is leaked (not `403 Forbidden`, which confirms existence)

### Scenario: Full-text search respects tenant scope

- **GIVEN** a full-text search index containing documents from both administratie A and B
- **AND** the active user has access to A only
- **WHEN** they perform a search query (e.g., `?q=jan`)
- **THEN** the response contains only search hits from administratie A
- **AND** any filtering/faceting respects tenant scope

### Scenario: Export respects tenant scope

- **GIVEN** a user requesting a bulk export (CSV/Excel) of medewerkers
- **AND** they have access to administraties A and B
- **AND** the active `administratie_id` is A
- **WHEN** the export is generated
- **THEN** the export file contains only data from administratie A

## REQ-002: Administratie-switcher in de UI

**Description:** The top-bar displays the active administratie persistently. Switching is one-click and triggers a full app context reload to the new administratie.

### Scenario: Switcher shows accessible administraties

- **GIVEN** a user with access to three administraties (A, B, C)
- **WHEN** they click the Administratie Switcher component in the top-bar
- **THEN** a dropdown appears showing all three administraties
- **AND** they are sorted by most-recently-used (MRU)
- **AND** the current active administratie is visually highlighted

### Scenario: Switching context reloads app state

- **GIVEN** a user on `/medewerkers/123` (employee from administratie A)
- **AND** they click to switch to administratie B
- **WHEN** the switch completes
- **THEN** the app navigates to `/medewerkers` (the list view)
- **AND** the page reloads to clear any A-specific state
- **AND** no 404 or stale detail-view is shown

### Scenario: Switch is audited

- **GIVEN** a user switches from administratie A to administratie B
- **WHEN** the switch is triggered
- **THEN** an `AdministratieSwitch` record is written with:
  - `gebruiker_id`, `van_administratie_id`, `naar_administratie_id`
  - `tijdstip` (current timestamp)
  - `sessie_id` (from the session)
  - `via` = `"ui"` (sourced from UI switch action)

### Scenario: Switcher hides archived administraties

- **GIVEN** an administratie is archived (`actief_tot` is in the past)
- **WHEN** the user opens the administratie switcher
- **THEN** the archived administratie is not shown in the dropdown
- **AND** the user cannot switch to it via the UI

## REQ-003: Intercompany detachering met dubbele goedkeuring

**Description:** An employee can be detached from administratie A to administratie B. Both parties must explicitly approve before activation.

### Scenario: Initiation and approval workflow

- **GIVEN** an HR manager of administratie A initiates a detachering for employee X to administratie B
- **WHEN** they submit the detachering form with details (`vanaf`, `tot`, `doorbelasting_bedrag_per_maand`, etc.)
- **THEN** the Detachering record is created with `status = "concept"`
- **AND** a notification is sent to at least one HR manager of administratie B requesting approval

### Scenario: Dual approval required for activation

- **GIVEN** both administraties have approved the detachering
- **AND** the `vanaf` date is today or in the past
- **WHEN** the system checks the detachering status (e.g., during a payroll run)
- **THEN** the status automatically transitions from `approved` to `actief`
- **AND** the employee is now visible and actionable in both administraties

### Scenario: Payroll cost assignment

- **GIVEN** a detachering is `actief` with `payroll_blijft_bij = van` (costs stay with original administratie)
- **AND** `doorbelasting_bedrag_per_maand = 2500`
- **WHEN** the payroll run for the destination administratie B is executed
- **THEN** the employee's gross salary is deducted from B's payroll
- **AND** the hourly/monthly cost to B is recorded
- **AND** the system can generate an intercompany invoice from B to A for the doorbelasting amount

### Scenario: End-date auto-termination

- **GIVEN** an active detachering with `tot = 2026-08-31`
- **WHEN** the system date reaches 2026-09-01
- **THEN** the detachering automatically transitions to `afgerond`
- **AND** the employee is no longer visible in the destination administratie's payroll scope

## REQ-004: Per-administratie loonheffingsnummer en aangiftes

**Description:** Each administratie has a unique loonheffingsnummer. Payroll filings (loonaangifte, pensioenaangifte) are generated and submitted independently per administratie.

### Scenario: Independent filing per administratie

- **GIVEN** three administraties with three distinct loonheffingsnummers (ending in L01, L02, L03)
- **WHEN** the monthly loonaangifte job is triggered
- **THEN** three separate XML aangifte files are generated
- **AND** each is submitted to Digipoort via the correct loonheffingsnummer
- **AND** the submission receipts are logged separately per administratie

### Scenario: Failure isolation

- **GIVEN** the loonaangifte submission for administratie A fails (e.g., validation error)
- **WHEN** this failure is detected
- **THEN** administraties B and C proceed normally
- **AND** only the admin team of A is notified of the failure
- **AND** a retry can be triggered for A without re-processing B and C

### Scenario: Sector code routing

- **GIVEN** administratie A has `sector_code = 22` (information services)
- **AND** a sick-leave notification is submitted
- **WHEN** the UWV ziekmelding is generated
- **THEN** it is routed to the UWV branch corresponding to sector 22 (not a different sector's branch)

## REQ-005: Holding-consolidatie

**Description:** Users with consolidation-level access can view aggregated metrics across multiple administraties, with optional elimination of intercompany transactions.

### Scenario: Consolidated reporting across administraties

- **GIVEN** a `ConsolidatieGroep` containing administraties A, B, and C
- **WHEN** a user with `consolidatie_groep` access opens the consolidation dashboard
- **THEN** they see:
  - Total FTE (sum across A, B, C)
  - Total headcount
  - Total personnel costs (sum of all payroll)
  - Total leave taken (sum of sick days, vacation days)
- **AND** a breakdown per administratie
- **AND** a totals row

### Scenario: Intercompany transaction elimination

- **GIVEN** the consolidation groep has `eliminatie_intercompany = true`
- **AND** there is an active detachering from A to B with `doorbelasting_bedrag_per_maand = 2500`
- **WHEN** the consolidated personnel cost is calculated
- **THEN** the 2500/month is not counted twice (once as cost in B, once as revenue in A)
- **AND** only the net flow (or the cost in the payroll-bearing administratie) is included

### Scenario: Hierarchical consolidation

- **GIVEN** a parent `ConsolidatieGroep` (Holding) with child groups (Werkmaatschappij A, Werkmaatschappij B)
- **WHEN** a DGA views the parent consolidation level
- **THEN** they see both the top-level aggregate AND the ability to drill down to each werkmaatschappij
- **AND** each level correctly aggregates its children

## REQ-006: Per-administratie autorisatie en role-scoping

**Description:** User roles are always administratie-specific. No global role exists except an explicit `superadmin` (for platform owners only).

### Scenario: Role-based access differs per administratie

- **GIVEN** a user is `hr_manager` on administratie A and `leesrechten` on administratie B
- **WHEN** they are in context A
- **THEN** they can create, read, and update medewerkers
- **AND** when they switch to context B
- **THEN** they can only read; mutations return `403 Forbidden`

### Scenario: Unauthorized mutation attempt logs audit event

- **GIVEN** a user attempts to modify a medewerker in administratie B via the API
- **AND** they hold only `leesrechten` on B
- **WHEN** the API endpoint is invoked
- **THEN** the mutation is rejected with `403 Forbidden`
- **AND** an audit event is written logging the unauthorized access attempt

### Scenario: Superadmin bypass

- **GIVEN** a user with global `superadmin` role
- **WHEN** they access any administratie (without holding an explicit AdministratieRol)
- **THEN** they have full access to all administraties
- **AND** the audit trail marks superadmin usage

## REQ-007: Per-administratie huisstijl en branding op uitgaande communicatie

**Description:** Output documents and emails carry the correct administratie's branding (logo, color, company name, contact info).

### Scenario: Payslip branding

- **GIVEN** administratie A has `logo_uri = "https://cdn.example.com/logo-a.png"` and `huisstijl_kleur = "#0066CC"`
- **WHEN** a payslip PDF is generated for an employee of administratie A
- **THEN** the PDF header displays logo-a.png at correct dimensions (e.g., 100×40px)
- **AND** accent bars and highlights use the color #0066CC
- **AND** the footer includes A's full legal name, address, and bank details

### Scenario: Email branding

- **GIVEN** a contract-renewal reminder email is auto-triggered for an employee of administratie B
- **WHEN** the email is composed and sent
- **THEN** the email subject/body includes administratie B's `naam_juridisch`
- **AND** the email signature includes B's address, phone, and bank account
- **AND** the reply-to address is B's main contact email

### Scenario: Multi-administratie email sends don't mix branding

- **GIVEN** a batch email job triggers reminders for employees across administraties A and B
- **WHEN** the job runs
- **THEN** employees from A receive emails branded with A's details
- **AND** employees from B receive emails branded with B's details
- **AND** no employee receives cross-branded email

## REQ-008: Per-administratie boekjaar en valuta

**Description:** Each administratie can have a custom fiscal year start and (in future) currency. Most are EUR and calendar year; some require non-calendar boekjaar.

### Scenario: Non-calendar boekjaar affects reporting periods

- **GIVEN** administratie A has `boekjaar_start = "01-07"` (July to June fiscal year)
- **WHEN** annual reports and tax filings are generated
- **THEN** the reporting period is July 1 – June 30 of the next calendar year
- **AND** not January 1 – December 31

### Scenario: Currency field enables multi-currency support (future)

- **GIVEN** administratie X has `valuta = "USD"`
- **WHEN** payroll amounts are displayed or exported
- **THEN** they are shown and filed in USD
- **AND** currency conversion logic (if applicable) uses the configured currency

## REQ-009: Soft-delete en archivering van administraties

**Description:** Administraties can be archived (not hard-deleted) by setting `actief_tot` to a past date. Data remains readable but mutations are blocked.

### Scenario: Archived administratie is hidden from UI

- **GIVEN** administratie C is archived (`actief_tot = "2024-12-31"`)
- **WHEN** a user views the administratie switcher dropdown
- **THEN** administratie C is not shown
- **AND** the user cannot switch to it via the UI

### Scenario: Mutation to archived administratie is rejected

- **GIVEN** an archived administratie C
- **WHEN** a user (with historical read rights) attempts to POST/PUT a new payslip or contract for C
- **THEN** the API responds with `409 Conflict — administratie is archived`
- **AND** the mutation is not applied

### Scenario: Historical data remains readable

- **GIVEN** an archived administratie C with 3 years of payroll data
- **WHEN** an accountant with `leesrechten` on C queries past payroll records
- **THEN** all historical data is returned correctly
- **AND** no data loss has occurred

## REQ-010: API-tokens zijn altijd administratie-scoped

**Description:** API tokens issued to external integrations are bound to a single administratie. No token can access cross-tenant data unless explicitly issued as a consolidation-level token.

### Scenario: Administratie-scoped token restricts data access

- **GIVEN** an API token issued for administratie A only
- **WHEN** an external service calls `GET /api/medewerkers` with this token
- **THEN** the response contains only employees from administratie A
- **AND** no cross-checking of token-issuer's other administraties occurs

### Scenario: Token revocation is immediate

- **GIVEN** a token is revoked for security reasons
- **WHEN** a subsequent API call is made with the revoked token
- **THEN** the API responds with `401 Unauthorized`
- **AND** the revocation is enforced without caching delay

### Scenario: Consolidation-level token (read-only)

- **GIVEN** a token is issued with `is_consolidation_token = true` for a consolidation group
- **WHEN** the token is used to call `GET /api/consolidation/overview`
- **THEN** the response includes aggregated data across all administraties in the group
- **AND** any mutation attempt (POST/PUT/DELETE) returns `403 Forbidden`

## Acceptance Criteria (Overall)

- All ten requirements are testable via API integration tests and UI scenario tests.
- Tenant isolation is verified via penetration testing (cross-tenant data-access attempts must fail).
- Consolidation reporting meets performance SLA (<2 seconds for groups up to 20 administraties).
- All GDPR/AVG audit logs are generated and retained per Dutch legal requirements.
- The feature set aligns with Wet op de loonbelasting 1964 and Belastingdienst Handboek Loonheffingen.
