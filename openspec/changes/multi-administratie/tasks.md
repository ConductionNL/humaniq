---
status: tasks
title: Multi-administratie (Multi-tenant Payroll & HR Partitioning)
author: Specter Intelligence
date: 2026-05-23
---

# Multi-administratie: Implementation Tasks

## 1. Data Model & OpenRegister Setup

- [ ] 1.1 Create Administratie register schema and seed data in `lib/Settings/hrmq_register.json`
  - Include fields: id, slug, naam_juridisch, naam_handelsnaam, rechtsvorm, kvk_nummer, vestigingsnummer, rsin, loonheffingsnummer, btw_nummer, sector_code, aansluitnummer_uwv, cao_code, pensioenfonds_code, arbodienst, boekjaar_start, valuta, taal_default, vestigingsadres, correspondentieadres, bankrekening_iban, bankrekening_bic, g_rekening_iban, logo_uri, huisstijl_kleur, actief_vanaf, actief_tot, parent_administratie_id, consolidatie_groep_id
- [ ] 1.2 Create AdministratieRol register schema (gebruiker_id, administratie_id, rol, vanaf, tot, door_gebruiker_id)
- [ ] 1.3 Create Detachering register schema (medewerker_id, van_administratie_id, naar_administratie_id, type, vanaf, tot, doorbelasting_type, doorbelasting_bedrag_per_maand, intercompany_contract_uri, goedgekeurd_door_van, goedgekeurd_door_naar, payroll_blijft_bij, status)
- [ ] 1.4 Create ConsolidatieGroep register schema (naam, parent_groep_id, consolidatie_methode, eliminatie_intercompany)
- [ ] 1.5 Create AdministratieSwitch register schema (gebruiker_id, van_administratie_id, naar_administratie_id, tijdstip, sessie_id, via)
- [ ] 1.6 Ensure all schemas use OpenRegister composite keys (register+schema+slug)
- [ ] 1.7 Create database migration to add `administratie_id` column to all existing entities (Medewerker, Contract, Loonrun, Journaalpost, etc.) with NOT NULL constraint and default foreign key to "system" administratie

## 2. Tenant Scoping Middleware & Database Layer

- [ ] 2.1 Create `TenantScopingMiddleware` that:
  - Reads active `administratie_id` from session
  - Retrieves allowed administraties for the user via AdministratieRol
  - Injects `WHERE administratie_id IN (...)` into all SELECT/UPDATE/DELETE queries via QueryBuilder hook
  - Logs any attempt to bypass scoping
- [ ] 2.2 Create `TenantScope` service to fetch user's accessible administraties
- [ ] 2.3 Register middleware in `config/middleware.json` for all requests
- [ ] 2.4 Create database index on (`administratie_id`, `created`) for all multi-tenant tables
- [ ] 2.5 Audit and verify all raw SQL queries in the codebase; ensure none bypass tenant scoping
- [ ] 2.6 Create integration test: cross-tenant data access returns 404, not 403

## 3. Administratie Service & API

- [ ] 3.1 Create `AdministratieService` with methods:
  - `getAdministratie($slug)` — fetch single Administratie
  - `listAccessible($userId)` — return Administraties user has roles for
  - `createAdministratie($data)` — create new tenant
  - `updateAdministratie($id, $data)` — mutate tenant metadata
  - `archiveAdministratie($id)` — set `actief_tot` to today
  - `getActive()` — return current session's active Administratie
- [ ] 3.2 Create `AdministratieRolService`:
  - `assignRole($userId, $administratieId, $rol, $vanaf, $tot)`
  - `revokeRole($userId, $administratieId)`
  - `getRoles($userId)` — list all roles for a user
  - `canAccess($userId, $administratieId)` — boolean check
  - `hasPermission($userId, $administratieId, $action)` — action = create/read/update/delete
- [ ] 3.3 Create `AdministratieController` with endpoints:
  - `GET /api/administraties` — list accessible administraties
  - `GET /api/administraties/{id}` — fetch single (scoped)
  - `POST /api/administraties` — create (owner/superadmin only)
  - `PUT /api/administraties/{id}` — update (owner/superadmin only)
  - `DELETE /api/administraties/{id}` — soft-delete by setting `actief_tot`
  - `POST /api/administraties/{id}/switch` — change active context
- [ ] 3.4 Create `AdministratieRolController` for role assignment (owner/superadmin only):
  - `GET /api/administraties/{id}/roles` — list all roles for an administratie
  - `POST /api/administraties/{id}/roles` — assign role
  - `DELETE /api/administraties/{id}/roles/{userId}` — revoke role
- [ ] 3.5 Return `X-Tenant-Context` header in all API responses identifying active administratie
- [ ] 3.6 Create integration tests for all endpoints with role-based access validation

## 4. UI: Administratie Switcher Component

- [ ] 4.1 Create `TopBarAdministratieSwitcher.vue` component in `src/components/`:
  - Display current Administratie name and logo (16×16 thumbnail)
  - Click → dropdown list of accessible administraties (sorted MRU)
  - Visual highlight of currently active administratie
- [ ] 4.2 Integrate `TopBarAdministratieSwitcher` into existing top-bar layout (`NcTopNav`)
- [ ] 4.3 On selection, call `POST /api/administraties/{id}/switch` via API
- [ ] 4.4 On switch success:
  - Update Pinia store with new `active_administratie_id`
  - Navigate to equivalent route in new context (list view if detail doesn't exist)
  - Reload page to clear stale state
- [ ] 4.5 Create unit tests for switcher component (dropdown rendering, MRU sorting, navigation)
- [ ] 4.6 Create E2E test: switch between two administraties and verify data isolation

## 5. Administratie Switch Audit Logging

- [ ] 5.1 Create `AdministratieSwitchLogger` service that writes `AdministratieSwitch` records:
  - `gebruiker_id`, `van_administratie_id`, `naar_administratie_id`
  - `tijdstip` (server time), `sessie_id`, `via` (ui/api/impersonation)
- [ ] 5.2 Call logger in `AdministratieController.switch()` endpoint with `via = "api"`
- [ ] 5.3 Call logger in `TopBarAdministratieSwitcher` (frontend) → pass `via = "ui"` via API param
- [ ] 5.4 Create audit-trail view/export showing switch history for compliance
- [ ] 5.5 Create integration test: switch logged correctly with all fields

## 6. Intercompany Detachering Service

- [ ] 6.1 Create `DetacheringService` with methods:
  - `createDetachering($data)` — initiate with `status = "concept"`
  - `approveDetachering($id, $by_user_id, $side = "van"|"naar")` — set `goedgekeurd_door_{side}` timestamp
  - `activateDetachering($id)` — auto-transition from approved → actief when `vanaf` date reached
  - `terminateDetachering($id)` — set status to `afgerond` when `tot` date passed
  - `getDetacheringsFor($medewerker_id)` — list active/pending detacherings for employee
  - `isEmployeeVisibleInAdministratie($medewerker_id, $administratie_id)` — check if detached
- [ ] 6.2 Create `DetacheringController` endpoints:
  - `POST /api/detacheringen` — initiate (requires `hr_manager` on van_administratie)
  - `GET /api/detacheringen/{id}` — fetch (scoped to van or naar administratie)
  - `PUT /api/detacheringen/{id}` — update (before approval, scoped to initiating administratie)
  - `POST /api/detacheringen/{id}/approve` — approve (requires `hr_manager` on approving administratie)
  - `GET /api/detacheringen` — list all for accessible administraties
- [ ] 6.3 Create notification when detachering is initiated (notification sent to destination administratie)
- [ ] 6.4 Create background job to auto-transition detacherings to `actief` when `vanaf` date is reached
- [ ] 6.5 Create background job to auto-transition detacherings to `afgerond` when `tot` date is passed
- [ ] 6.6 Integrate with Medewerker service to include detached employees in lists when appropriate
- [ ] 6.7 Create integration tests for detachering workflow (propose → approve van → approve naar → activate)

## 7. Holding Consolidation Service

- [ ] 7.1 Create `ConsolidatieService` with methods:
  - `createConsolidatieGroep($data)` — create group
  - `getConsolidatieGroep($id)` — fetch group with all members
  - `getConsolidatedMetrics($groep_id)` — aggregate FTE, headcount, costs, leave across all administraties
  - `getConsolidatedBreakdown($groep_id)` — per-administratie breakdown
  - `includeIntercompany($groep_id)` — check if intercompany transactions should be eliminated
- [ ] 7.2 Implement intercompany elimination logic:
  - Sum all active detacherings in the group with `payroll_blijft_bij = van`
  - Subtract from destination administratie's costs, don't add to source administratie's revenue
  - (Or: use proportional elimination based on consolidatie_methode)
- [ ] 7.3 Create `ConsolidatieController` endpoints:
  - `GET /api/consolidatie/{groep_id}` — consolidated overview (requires consolidatie access)
  - `GET /api/consolidatie/{groep_id}/breakdown` — per-administratie metrics
  - `GET /api/consolidatie/{groep_id}/intercompany` — intercompany transaction detail
- [ ] 7.4 Create consolidation dashboard view (widget showing top-level metrics)
- [ ] 7.5 Create performance optimizations: caching or materialized views for consolidation queries (target <2s)
- [ ] 7.6 Create integration tests for consolidation metrics with multiple administraties and intercompany detacherings

## 8. Per-Administratie Loonheffingsnummer & Payroll Integration

- [ ] 8.1 Modify `LoonrunService` to:
  - Query active Administratie's `loonheffingsnummer`, `sector_code`, `cao_code`, `boekjaar_start`
  - Use these values for payroll calculations and UWV submissions
  - Ensure one loonrun per administratie per period (no cross-tenant mixing)
- [ ] 8.2 Modify loonaangifte (XML/Digipoort) generation to:
  - Generate separate aangifte file per administratie per loonheffingsnummer
  - Include Administratie's legal name, address, contact in XML
  - Submit each to Digipoort independently
- [ ] 8.3 Modify pensioenaangifte to use per-administratie `pensioenfonds_code` and `aansluitnummer_uwv`
- [ ] 8.4 Create LoonrunController endpoint: `POST /api/loonruns/{id}/submit-aangifte` scoped to active administratie
- [ ] 8.5 Create retry logic: if aangifte for A fails, B and C continue normally
- [ ] 8.6 Create integration tests: three administraties generate three separate aangiftes with correct loonheffingsnummers

## 9. Per-Administratie Branding (Documents & Email)

- [ ] 9.1 Modify payslip PDF generation:
  - Fetch active Administratie from session
  - Insert logo (from `logo_uri`) with correct dimensions
  - Use `huisstijl_kleur` for accent bars/highlights
  - Include Administratie's full legal name, address, phone, bank in footer
- [ ] 9.2 Modify email template service:
  - Fetch Administratie for recipient's administratie
  - Inject `naam_juridisch`, address, phone, email, signature into email footer
  - Use Administratie's reply-to email address
- [ ] 9.3 Modify letter/contract PDF generation similarly
- [ ] 9.4 Create test fixtures: generate payslip PDFs for multiple administraties and verify branding
- [ ] 9.5 Create email template tests: verify email body includes correct administratie details

## 10. Role-Based Authorization (Per-Administratie)

- [ ] 10.1 Create `AdministratieAuthorizationHandler` (implements AuthorizationService interface):
  - On any action (create/read/update/delete), check user's AdministratieRol for active administratie
  - Enforce role-based permissions: read (leesrechten), write (medewerker_zelf/hr_medewerker), admin (hr_manager/payroll_admin/eigenaar)
- [ ] 10.2 Integrate handler into API middleware to validate permissions before executing actions
- [ ] 10.3 Create role hierarchy:
  - `leesrechten` — read-only
  - `medewerker_zelf` — edit self profile only
  - `hr_medewerker` — create/read/update employees, view reports
  - `hr_manager` — approve leave, initiate detacherings, full HR access
  - `payroll_admin` — read payroll, approve loonruns, submit aangiftes
  - `eigenaar` — all of above + manage administratie settings and roles
  - `superadmin` (global) — unrestricted
- [ ] 10.4 Create tests: user with leesrechten cannot mutate; user with hr_manager can; mutation without permission logs audit event

## 11. API Token Scoping

- [ ] 11.1 Create `ApiTokenService` to:
  - Issue tokens bound to a single `administratie_id`
  - Support optional `is_consolidation_token` flag (read-only across group)
  - Store `administratie_id` in token payload (JWT claim or session lookup)
- [ ] 11.2 Create token middleware that:
  - Extracts `administratie_id` from token before routing request
  - Sets active context to that administratie
  - Validates token is not revoked
- [ ] 11.3 Modify all API endpoints to respect token-scoped administratie_id
- [ ] 11.4 Create token management UI/API for issuing and revoking tokens
- [ ] 11.5 Create tests: token for A cannot access B; consolidation token can read across group but not mutate

## 12. Archival & Soft-Delete

- [ ] 12.1 Modify Administratie queries to filter out archived administraties (`actief_tot IS NULL OR actief_tot >= TODAY()`)
  - Exception: historical read-only queries for compliance/audits
- [ ] 12.2 Create `POST /api/administraties/{id}/archive` endpoint (owner only) that sets `actief_tot = TODAY()`
- [ ] 12.3 Create API validation: reject POST/PUT/DELETE on archived administratie with `409 Conflict`
- [ ] 12.4 Modify top-bar switcher to exclude archived administraties from dropdown
- [ ] 12.5 Create "Archived Administraties" read-only view for compliance/audit (superadmin + accountant role)
- [ ] 12.6 Create integration tests: cannot mutate archived administratie; can read with appropriate role

## 13. Migration of Existing Data

- [ ] 13.1 Create migration script to assign all existing employees/contracts/payroll to a "default" administratie (slug: system/default)
- [ ] 13.2 Assign current user (admin) an `eigenaar` role on default administratie
- [ ] 13.3 Ensure migration is idempotent (can run multiple times safely)

## 14. Integration Tests

- [ ] 14.1 Create API test suite covering:
  - Tenant isolation (cross-tenant 404, same-tenant success)
  - Role-based access (leesrechten read-only, hr_manager write, etc.)
  - Context switching (active administratie changes, UI reloads correctly)
  - Detachering workflow (propose → approve van → approve naar → activate)
  - Consolidation reporting (aggregate metrics correct, intercompany eliminated)
  - Per-administratie filing (three aangiftes generated, three loonheffingsnummers)
  - Archival (reject mutation, allow read)
- [ ] 14.2 Create E2E test suite:
  - Create two administraties
  - Assign users different roles to each
  - Switch between administraties in UI
  - Verify data isolation and permission enforcement
  - Create and approve a detachering
  - View consolidated report
- [ ] 14.3 Create penetration test: attempt cross-tenant data access via direct ID manipulation
- [ ] 14.4 Benchmark consolidation queries (target <2s for 20 administraties)

## 15. Documentation & Training

- [ ] 15.1 Create architecture documentation explaining multi-administratie model
- [ ] 15.2 Create user guide: how to create an administratie, assign roles, switch tenants
- [ ] 15.3 Create admin guide: how to manage multi-administratie, archive administraties
- [ ] 15.4 Create developer guide: how to add `administratie_id` scoping to new features
- [ ] 15.5 Create compliance documentation: how tenant isolation meets GDPR/AVG/NEN 7510 requirements

## 16. Verify & Deployment

- [ ] 16.1 Run all unit, integration, and E2E tests; ensure 100% pass
- [ ] 16.2 Code review: verify tenant scoping middleware cannot be bypassed
- [ ] 16.3 Security audit: verify API authorization checks are in place
- [ ] 16.4 Performance testing: consolidation queries, list queries with 10K+ records per administratie
- [ ] 16.5 Backup and recovery testing: ensure archival doesn't affect data integrity
- [ ] 16.6 Deployment: phased rollout (dev → staging → prod) with monitoring of tenant-isolation violations
- [ ] 16.7 Post-launch audit: verify zero cross-tenant data leaks in first week of production

## Deduplication Check

- **Existing multi-tenancy in platform** — OpenRegister supports multi-schema isolation. hrmq uses row-level; complementary, no duplication.
- **Existing RBAC** — AuthorizationService is generic. AdministratieRolService extends it with administratie scoping; not duplication.
- **Existing consolidation/reporting** — No existing consolidation engine. ConsolidatieGroep and aggregation queries are new.
- **Existing audit logging** — AuditTrailService is generic. AdministratieSwitch is a new auditable event; extends, not duplication.

**Conclusion:** No duplicative functionality. All tasks build on existing platform services appropriately.
