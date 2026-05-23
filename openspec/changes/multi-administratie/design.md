---
status: design
title: Multi-administratie (Multi-tenant Payroll & HR Partitioning)
author: Specter Intelligence
date: 2026-05-23
---

# Multi-administratie: Design

## Context

hrmq currently operates as a single-BV payroll suite. The Dutch accountancy, holding, and franchise markets require out-of-the-box multi-tenant support with strict data isolation, context switching, intercompany workflows, and consolidated reporting. Multi-administratie is the foundational capability that enables every other hrmq feature to be multi-tenant-aware.

## Goals

**Goals:**
- Partition a single hrmq instance into isolated tenants scoped by `administratie_id`
- Enable users to hold different roles across different administraties
- Support intercompany medewerker movements (detachering, secondment, outplacement)
- Provide consolidated reporting and cost allocation across related administraties
- Ensure strict data isolation at the database, API, and UI layers
- Support tax/legal compliance (per-administratie loonheffingsnummer, sector code, boekjaar)
- Make tenant-context switching explicit and auditable
- Allow administraties to be archived without data loss (fiscal retention)

**Non-Goals:**
- Multi-administratie at the **schema/register level** (that is, separate OpenRegister databases per tenant). Tenant isolation is purely row-level via WHERE clauses and RBAC.
- Automatic data replication or failover across administraties
- Tenant creation/deletion via the UI (that is, administratie management is for owner/accountant roles via API only)
- Hard-delete of archived administraties within the 7-year fiscal retention window
- Separate branded UI skins per administratie (branding applies to output documents, not the app UI itself)

## Decisions

### Decision 1: Row-level Tenant Scoping via Database Middleware

**Approach:**
- Every hrmq entity (Medewerker, Contract, Loonrun, etc.) gets a `administratie_id` foreign key.
- A `TenantScopingMiddleware` intercepts all queries and injects `WHERE administratie_id IN (...allowed administraties for the active user)` at the database level.
- No query is issued from application code without this scoping; the middleware is non-bypassable.
- Cross-tenant references use OpenRegister relation syntax (register/schema/objectId), not foreign keys.

**Rationale:**
- Database-level enforcement is stronger than application-level RBAC; accidental developer errors cannot leak data.
- Row-level security fits OpenRegister's schema-agnostic design better than multi-schema isolation.
- Single database/schema simplifies migration and backup strategies.
- Aligns with Dutch healthcare (NEN 7510) and general GDPR best practice.

**Tradeoffs:**
- Row-level scoping adds modest query overhead (one WHERE clause per query).
- Query complexity increases for cross-administratie reporting (consolidation queries must explicitly NOT scope).

### Decision 2: Per-User Multi-Role Assignment via AdministratieRol

**Approach:**
- A user's access to administraties and their capabilities within each is modeled via the `AdministratieRol` entity.
- One user can have, e.g., `hr_manager` on Administratie A, `payroll_admin` on Administratie B, and no row at all for Administratie C (forbidden).
- The `active_administratie_id` session state determines which administratie the current request is scoped to.
- Switching administraties updates the session and triggers a UI reload to the equivalent screen in the new administratie.

**Rationale:**
- Mirrors real-world organizational structures (HR person in charge of multiple entities with different responsibilities).
- Simplifies role queries (check for AdministratieRol existence = access granted).
- Integrates cleanly with Nextcloud's existing user/session system.

**Tradeoffs:**
- No support for **time-limited roles** yet (e.g., temporary contractor access). Versioning via `vanaf`/`tot` fields allows future extension.

### Decision 3: Intercompany Medewerker Movements via Detachering

**Approach:**
- The `Detachering` entity models four types: detachering (temporary), secondment (contract term), uitleen (loan with cost allocation), definitieve_overplaatsing (permanent).
- A detachering starts in `concept` status. The originating administratie submits a form; a notification goes to the destination administratie.
- Both parties must approve (`goedgekeurd_door_van` and `goedgekeurd_door_naar`). Once approved and the `vanaf` date is reached, status auto-transitions to `actief`.
- A detachering specifies `payroll_blijft_bij` (van or naar) to determine which administratie books payroll costs.
- If `payroll_blijft_bij = van`, a `doorbelasting_bedrag_per_maand` and `doorbelasting_type` (kostprijs/marktconform/geen) determine intercompany invoicing.
- The employee is visible in both administraties during active detachering (unless explicitly hidden by role).

**Rationale:**
- Dual approval prevents accidental medewerker-loss and enforces accountability.
- Per-administratie payroll cost assignment matches Dutch tax and accounting requirements (de werkgever bepaalt).
- Allows real-world workflows (centrale HR initiates move; destination HR confirms).

**Tradeoffs:**
- Detachering creates medewerker **visibility in multiple administraties**, which complicates some list views (must show detached employees with clear labeling).
- Intercompany invoicing reconciliation is manual (no automatic journal posting).

### Decision 4: Holding-Level Consolidation via ConsolidatieGroep

**Approach:**
- A `ConsolidatieGroep` is a tree structure (`parent_groep_id`) grouping multiple Administraties for aggregation.
- A consolidation query aggregates FTE, headcount, costs, and leave across all Administraties in a groep.
- `eliminatie_intercompany = true` excludes detachering doorbelastingen within the same groep (prevents double-counting).
- `consolidatie_methode` (volledig/proportioneel/equity) determines how subsidiary costs roll up (Dutch IFRS/RJ 217).
- Only users with `ConsolidatieGroep` read rights can see the consolidated view.

**Rationale:**
- Holding structures naturally aggregate subcompanies for DGA/management reporting.
- Intercompany elimination aligns with Dutch consolidated accounting standards.
- Tree hierarchy allows multi-level holdings (Moeder BV → Werk BV → Payroll BV).

**Tradeoffs:**
- Consolidation queries are compute-intensive (union of multiple aggregates); caching or materialized views may be needed at scale.

### Decision 5: Persistent Administratie Switch in Top-Bar

**Approach:**
- Top-bar includes an **Administratie Switcher** component showing the active Administratie's `naam_juridisch` and logo (16×16).
- Clicking opens a dropdown listing all Administraties the user has access to (via AdministratieRol), sorted by `most_recently_used`.
- Selecting a new administratie:
  1. Updates session `active_administratie_id`
  2. Writes an `AdministratieSwitch` audit record
  3. Navigates to the equivalent route in the new context (e.g., `/medewerkers` instead of `/medewerkers/123` if that medewerker doesn't exist in the new administratie)
  4. Reloads the page to clear any stale state
- The switcher is always visible and persistent.

**Rationale:**
- Explicit switching makes context changes auditable and reduces user confusion ("which BV am I in?").
- Top-bar is the standard location for global context (NcTopNav).
- Dropdown sort-by-recency improves UX for power users toggling between 2–3 administraties frequently.

**Tradeoffs:**
- Takes up ~80px of top-bar real estate.
- Reload may feel slow if the app is large; mitigated by caching and incremental state updates.

### Decision 6: Tax/Legal Attributes per Administratie

**Approach:**
- Each `Administratie` row includes:
  - `loonheffingsnummer` (11 digits NNNNNNNNNLNN) — unique per Administratie.
  - `sector_code` — references WW-sectorrisicogroep (determines UWV ziekmelding routing).
  - `rechtsvorm` — legal entity type (BV/NV/Stichting/Coöperatie/Eenmanszaak/VOF/Maatschap).
  - `boekjaar_start` (default 01-01) — enables non-calendar fiscal years.
  - `valuta` (default EUR) — future support for BES-eilanden.
  - `cao_code` — references the CAO/sector ruleset that applies to payroll.
  - `pensioenfonds_code` — BPF or internal pension fund identifier.
  - `aansluitnummer_uwv` — UWV registration number (required for sick-leave/WAZO).
  - Logo URI and brand colour for output documents.
- Payroll engine, loonaangifte, and pensioenaangifte processors read these values to generate correct filings.

**Rationale:**
- Dutch tax law requires one loonheffingsnummer per inhoudingsplichtige (per juridische entiteit).
- Sector code determines WW contributions and ziekmelding routing to correct UWV branch.
- Boekjaar and CAO are input to payroll and reporting, not hardcoded.

**Tradeoffs:**
- None significant; metadata storage.

### Decision 7: Per-Administratie Branding

**Approach:**
- Output documents (loonstroken, jaaropgaven, contracten, mails) reference `Administratie.logo_uri` and `huisstijl_kleur` to render with tenant-specific branding.
- The Administratie's `naam_juridisch` and contact details appear on payslips/statements/letters.
- Email signatures include the active Administratie's name, contact, and bank details.
- The app UI itself (menus, buttons, background) does **not** change per administratie (violates UX coherence if a user has 10 administraties open in tabs).

**Rationale:**
- Customers expect invoices, payslips, and letters to be branded with their company identity.
- Branding the app UI per administratie causes confusion when switching; keeping UI consistent is better UX.

**Tradeoffs:**
- Logo upload and asset management; CDN or local storage required.
- Template rendering complexity (conditionally inject Administratie metadata).

### Decision 8: API Token Scoping

**Approach:**
- When issuing API tokens (e.g., for Twinfield sync, payroll upload channels), the token is bound to a specific `administratie_id`.
- The token middleware checks `token.administratie_id` and injects it into the request context, scoping all queries to that administratie.
- Optional `is_consolidation_token = true` flag allows a token to read across multiple administraties (read-only; no mutations).
- Token revocation is immediate; no token caching.

**Rationale:**
- Prevents accidental cross-tenant data exposure through compromised or misconfigured integrations.
- Mirrors best practice for multi-tenant SaaS (Stripe, AWS, etc.).

**Tradeoffs:**
- Requires token re-issuance if tenant scope changes.

### Decision 9: Soft-Delete and Archival

**Approach:**
- An `Administratie` is never hard-deleted. Instead, `actief_tot` is set to a past date to mark it archived.
- Archived administraties are filtered out of UI lists and context switchers, but all data remains readable.
- API returns `409 Conflict` if a user attempts to mutate a document in an archived administratie.
- Fiscal retention queries (7-year compliance) can still access archived administraties via a read-only role.

**Rationale:**
- Dutch law requires 7-year retention of payroll and accounting records (Grondslag Belastingdienst).
- Archival without hard-delete simplifies compliance audits and recovery.

**Tradeoffs:**
- None; archival is standard practice.

## Seed Data

### Administratie

```
[
  {
    "id": "adm-001",
    "slug": "demobv-amsterdam",
    "naam_juridisch": "Demobv Amsterdam B.V.",
    "naam_handelsnaam": "DemoBV",
    "rechtsvorm": "BV",
    "kvk_nummer": "34567890",
    "vestigingsnummer": "000000001234",
    "rsin": "123456789",
    "loonheffingsnummer": "12345678901L01",
    "btw_nummer": "NL123456789B01",
    "sector_code": "22",
    "aansluitnummer_uwv": "20064567",
    "cao_code": "cao-root",
    "pensioenfonds_code": "BPF-General",
    "arbodienst": "Arbo Centraal BV",
    "boekjaar_start": "01-01",
    "valuta": "EUR",
    "taal_default": "nl",
    "vestigingsadres": {
      "straat": "Keizersgracht",
      "huisnummer": "42",
      "postcode": "1015CX",
      "plaats": "Amsterdam",
      "land": "NL"
    },
    "correspondentieadres": {
      "straat": "Keizersgracht",
      "huisnummer": "42",
      "postcode": "1015CX",
      "plaats": "Amsterdam",
      "land": "NL"
    },
    "bankrekening_iban": "NL91ABNA0417164300",
    "bankrekening_bic": "ABNANL2A",
    "logo_uri": "https://storage.example.com/demobv-logo.png",
    "huisstijl_kleur": "#0066CC",
    "actief_vanaf": "2026-01-01",
    "actief_tot": null,
    "parent_administratie_id": null,
    "consolidatie_groep_id": "groep-holding-demo"
  },
  {
    "id": "adm-002",
    "slug": "demobv-rotterdam",
    "naam_juridisch": "Demobv Rotterdam B.V.",
    "naam_handelsnaam": "DemoBV Werk",
    "rechtsvorm": "BV",
    "kvk_nummer": "45678901",
    "vestigingsnummer": "000000002345",
    "rsin": "234567890",
    "loonheffingsnummer": "12345678902L02",
    "btw_nummer": "NL234567890B01",
    "sector_code": "22",
    "aansluitnummer_uwv": "20064568",
    "cao_code": "cao-root",
    "pensioenfonds_code": "BPF-General",
    "arbodienst": "Arbo Centraal BV",
    "boekjaar_start": "01-01",
    "valuta": "EUR",
    "taal_default": "nl",
    "vestigingsadres": {
      "straat": "Coolsingel",
      "huisnummer": "88",
      "postcode": "3011GK",
      "plaats": "Rotterdam",
      "land": "NL"
    },
    "correspondentieadres": {
      "straat": "Coolsingel",
      "huisnummer": "88",
      "postcode": "3011GK",
      "plaats": "Rotterdam",
      "land": "NL"
    },
    "bankrekening_iban": "NL40RABO0336065264",
    "bankrekening_bic": "RABONL2U",
    "logo_uri": "https://storage.example.com/demobv-logo.png",
    "huisstijl_kleur": "#CC0000",
    "actief_vanaf": "2026-01-01",
    "actief_tot": null,
    "parent_administratie_id": "adm-001",
    "consolidatie_groep_id": "groep-holding-demo"
  },
  {
    "id": "adm-003",
    "slug": "archived-firma",
    "naam_juridisch": "Archived Firma B.V.",
    "naam_handelsnaam": "Archived",
    "rechtsvorm": "BV",
    "kvk_nummer": "56789012",
    "vestigingsnummer": "000000003456",
    "rsin": "345678901",
    "loonheffingsnummer": "12345678903L03",
    "btw_nummer": "NL345678901B01",
    "sector_code": "85",
    "aansluitnummer_uwv": "20064569",
    "cao_code": "cao-root",
    "pensioenfonds_code": "BPF-General",
    "arbodienst": "Arbo Centraal BV",
    "boekjaar_start": "01-07",
    "valuta": "EUR",
    "taal_default": "nl",
    "vestigingsadres": {
      "straat": "Handelskade",
      "huisnummer": "150",
      "postcode": "2595AA",
      "plaats": "Den Haag",
      "land": "NL"
    },
    "correspondentieadres": {
      "straat": "Handelskade",
      "huisnummer": "150",
      "postcode": "2595AA",
      "plaats": "Den Haag",
      "land": "NL"
    },
    "bankrekening_iban": "NL65INGD0003459012",
    "bankrekening_bic": "INGDNL2A",
    "logo_uri": null,
    "huisstijl_kleur": "#333333",
    "actief_vanaf": "2020-01-01",
    "actief_tot": "2024-12-31",
    "parent_administratie_id": null,
    "consolidatie_groep_id": null
  }
]
```

### AdministratieRol

```
[
  {
    "id": "rol-001",
    "gebruiker_id": "user-alice",
    "administratie_id": "adm-001",
    "rol": "hr_manager",
    "vanaf": "2026-01-01",
    "tot": null,
    "door_gebruiker_id": "user-admin"
  },
  {
    "id": "rol-002",
    "gebruiker_id": "user-alice",
    "administratie_id": "adm-002",
    "rol": "leesrechten",
    "vanaf": "2026-02-01",
    "tot": null,
    "door_gebruiker_id": "user-admin"
  },
  {
    "id": "rol-003",
    "gebruiker_id": "user-bob",
    "administratie_id": "adm-001",
    "rol": "payroll_admin",
    "vanaf": "2026-01-01",
    "tot": null,
    "door_gebruiker_id": "user-admin"
  },
  {
    "id": "rol-004",
    "gebruiker_id": "user-bob",
    "administratie_id": "adm-002",
    "rol": "payroll_admin",
    "vanaf": "2026-01-01",
    "tot": null,
    "door_gebruiker_id": "user-admin"
  },
  {
    "id": "rol-005",
    "gebruiker_id": "user-medewerker",
    "administratie_id": "adm-001",
    "rol": "medewerker_zelf",
    "vanaf": "2026-01-01",
    "tot": null,
    "door_gebruiker_id": "user-admin"
  }
]
```

### Detachering (Example)

```
[
  {
    "id": "detach-001",
    "medewerker_id": "emp-042",
    "van_administratie_id": "adm-001",
    "naar_administratie_id": "adm-002",
    "type": "detachering",
    "vanaf": "2026-06-01",
    "tot": "2026-08-31",
    "doorbelasting_type": "marktconform",
    "doorbelasting_bedrag_per_maand": 2500.00,
    "intercompany_contract_uri": "document://contracts/detach-001-contract.pdf",
    "goedgekeurd_door_van": "2026-05-23T14:30:00Z",
    "goedgekeurd_door_naar": "2026-05-24T09:15:00Z",
    "payroll_blijft_bij": "van",
    "status": "approved"
  }
]
```

### ConsolidatieGroep

```
[
  {
    "id": "groep-holding-demo",
    "naam": "DemoBV Holding",
    "parent_groep_id": null,
    "consolidatie_methode": "volledig",
    "eliminatie_intercompany": true
  }
]
```

## Reuse Analysis

- **OpenRegister** — Administratie, AdministratieRol, Detachering, ConsolidatieGroep and AdministratieSwitch are domain objects stored in OpenRegister. No custom Entity/Mapper required.
- **TenantScopingMiddleware** — New middleware for database-level row filtering. Integrates with OpenRegister's QueryBuilder.
- **AuthorizationService** — Leveraged for per-administratie role checks. AdministratieRolService builds on top.
- **AuditTrailService** — AdministratieSwitch events logged automatically via audit trail.
- **CnTopNav / TopBar components** — Administratie Switcher is a new component integrated into existing top-bar layout.
- **ExportService / ImportService** — Per-administratie export/import with administrative_id scoping.

## Deduplication Check

- **Multi-tenancy** — Some apps (e.g., OpenRegister, ProCEST) support multi-tenancy at the schema level. hrmq uses row-level isolation; no duplication.
- **RBAC** — AuthorizationService provides generic RBAC. AdministratieRolService is hrmq-specific role scoping; no duplication.
- **Audit logging** — AuditTrailService is generic. AdministratieSwitch is a new auditable event type; extends existing audit, not duplication.
- **Consolidation reporting** — No existing consolidation engine found. ConsolidatieGroep and aggregation queries are new.

**Conclusion:** No duplicative functionality detected. All design decisions reuse existing platform services appropriately.
