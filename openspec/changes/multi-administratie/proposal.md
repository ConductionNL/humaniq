---
status: proposal
title: Multi-administratie (Multi-tenant Payroll & HR Partitioning)
author: Specter Intelligence
date: 2026-05-23
---

# Multi-administratie: Proposal

## Why

Dutch SMB and accountancy markets require true multi-tenant payroll administration as a foundational capability, not a luxury feature. A typical Dutch accountant runs payroll for 40–400 client BV's; holding structures routinely have 3–7 loonheffingsnummers under one operational team; franchise organisations have one centrale entiteit with dozens of independent franchisenemers. Without first-class multi-administratie support, hrmq cannot serve any of these segments and remains a single-BV toy. The feature is cross-cutting and foundational: every other hrmq capability (employee master, contracts, payroll engine, journaalposten, loonaangifte, pensioenaangifte, verzuim, declaraties, audit trail) depends on it as a prerequisite.

## What Changes

- **New tenant entity (`Administratie`)** — Root tenant context with legal/tax/branding metadata. Every Administratie has its own loonheffingsnummer, sector code, pension fund, bank details, boekjaar start, logo and styling.
- **Per-administratie role assignment (`AdministratieRol`)** — Users hold roles (eigenaar/hr_manager/hr_medewerker/payroll_admin/leesrechten/medewerker_zelf) per administratie, enabling a single user to be `hr_manager` at BV A and `payroll_admin` at BV B with no access to BV C.
- **Persistent administratie-switcher** — Top-bar indicator showing active Administratie; single-click switching with automatic context reload and auditability via `AdministratieSwitch` records.
- **Intercompany medewerker movements (`Detachering`)** — Support detachering, secondment, uitleen, and permanent overplaatsing between administraties with dual-approval workflow and payroll-cost assignment.
- **Holding-level consolidation (`ConsolidatieGroep`)** — Aggregated reporting across multiple administraties with optional intercompany transaction elimination for DGA and accountant visibility.
- **Strict data isolation at API/database layer** — All queries tenant-scoped with injected `WHERE administratie_id IN (...allowed)` at database level; no query may bypass-able from application code.
- **Per-administratie branding and communication** — Loonstroken, jaaropgaven, contracts, and outgoing emails carry the logo, colour scheme, and company name of the active administratie.
- **Per-administratie tax/statutory filings** — Loonaangifte and pensioenaangifte submitted separately per administratie, one per loonheffingsnummer.
- **API-token scoping** — External integrations (accounting software, payroll channels) receive tokens scoped to a single administratie with no cross-tenant leakage.
- **Archival and soft-delete** — Administraties can be marked inactive for historical record-keeping without data loss (7-year fiscal retention).

## Capabilities

### New Capabilities

- **Tenant-isolated data queries**: All GET/list/search calls respect the active administratie scope. Cross-tenant data never leaks to the UI or API response.
- **Multi-administratie context switching**: Users explicitly switch tenants via top-bar dropdown; each switch is logged. Switching updates the active-context and reloads the app UI to the equivalent screen in the new tenant.
- **Intercompany medewerker movements**: Workflow to move employees between administraties with cost allocation and dual sign-off.
- **Holding-level consolidation**: Dashboard view aggregating headcount, FTE, costs, and leave across a group of administraties with optional elimination of internal transfers.
- **Per-administratie branding**: PDF generation (payslips, statements), email templates, and UI carry tenant-specific logos and styling.
- **Per-administratie tax filings**: Loonaangifte and pensioenaangifte generated independently per administratie and submitted via Digipoort with separate loonheffingsnummers.
- **Role-scoped autorisatie**: Access control enforced per administratie; no role is global except an explicit superadmin.
- **API token scoping**: Tokens issued to integrations are administratie-scoped with optional consolidation-token for multi-tenant read-only access.

### Modified Capabilities

- **Employee master, Contract, Loonrun, Journaalpost, Loonaangifte, Pensioenaangifte, Verzuim, Declaratie** — All get `administratie_id` as a mandatory foreign key. Queries are transparently scoped. No capability works until multi-administratie lands.

## Impact

### Primary Files

- `lib/Service/AdministratieService.php` — CRUD, switching, tenant-scoping middleware
- `lib/Service/AdministratieRolService.php` — Role assignment and per-user access list
- `lib/Service/DetacheringService.php` — Intercompany movement workflows
- `lib/Service/ConsolidatieService.php` — Holding aggregation and reporting
- `lib/Db/Administratie.php`, `AdministratieRol.php`, `Detachering.php`, `ConsolidatieGroep.php`, `AdministratieSwitch.php` — Entity mappers
- `lib/Controller/AdministratieController.php` — API endpoints for tenant CRUD and switching
- `lib/Middleware/TenantScopingMiddleware.php` — Database-level query injection
- `src/components/TopBarAdministratieSwitcher.vue` — Top-bar UI component

### Secondary Impact

- **Every data-layer query** must be reviewed and scoped. Use of raw queries must be audited.
- **Every API endpoint** must validate `AdministratieRol` before returning data.
- **PDF/email templates** must reference the active Administratie for branding.
- **Seed data and migrations** must account for administratie_id in all rows.

## Standards & Rationale

- **Wet op de loonbelasting 1964** — One loonheffingsnummer per inhoudingsplichtige (per BV).
- **Belastingdienst Handboek Loonheffingen** — Aangiftes submitted per loonheffingsnummer independently.
- **AVG/GDPR art. 32 & 5(1)(f)** — Tenant isolation is a processor responsibility; cross-tenant leaks are reportable data breaches.
- **NEN 7510 / ISO 27001 A.9** — RBAC scoped per administratie required for healthcare-sector compliance.
- **BW Boek 2 Titel 9 & RJ 217** — Consolidation model follows Dutch consolidation accounting standards.

## Cross-app Integration

- **Foundation for all hrmq capabilities** — Foundational; every other spec inherits multi-administratie primitives.
- **employee-master, payroll-engine-nl, audit-trail-payroll** — Consume `administratie_id` for scoping and use Detachering visibility.
- **journaalpost-export** — Per-administratie boekingsbestanden for accountancy ERP.
- **openconnector** — Token scoping and per-administratie integrations.
- **openregister** — Administratie is the first tenant-context OpenRegister learns via dedicated register.

## Target Users

- **Accountantskantoor** (40–400 client BV's) — primary market; needs fast switching, strong isolation, consolidation.
- **Holding-DGA's** — 3–7 werkmaatschappijen; wants consolidated dashboard plus per-company detail.
- **HR-shared-service-centers** — Concern HR for sister companies; needs intercompany movements.
- **Franchise organisations** — Central + franchisenemers; central has reporting rights, not mutation rights on franchisenemer data.
- **Bestuurssecretariaten** — Multiple legal entities under one governance.
- **Employees themselves** — See only "their" administratie in self-service; multi-administratie visibility if detached.

## Success Metrics

1. **Tenant isolation**: 100% of queries scoped; zero cross-tenant data leaks in audit trail.
2. **User adoption**: accountancy cantoor can create 10+ administraties and switch between them in <5 clicks.
3. **Consolidation reporting**: DGA can view aggregate FTE/costs/leave across 5+ administraties in <2 seconds.
4. **Tax filing**: Per-administratie loonaangifte submission via Digipoort with correct loonheffingsnummers.
5. **Intercompany workflows**: Detachering process complete (proposal → approval → activation) in <20 minutes per employee.
