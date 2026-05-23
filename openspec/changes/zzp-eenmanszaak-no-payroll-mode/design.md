# Design: ZZP Eenmanszaak Mode (Geen Werknemers)

## Context

hrmq currently assumes all organizations operate in employer mode with full payroll infrastructure. The application shell navigation includes Payroll, Loonbelasting-aangifte, CAO-management, Verzekeringen, and manager/team-oriented workflows. For ZZP's and eenmanszaak-houders, this default is overwhelming: they need simplified tax reporting (inkomstenbelasting instead of loonbelasting), hour tracking tied to the urencriterium (1225 billable hours = self-employed profit deduction eligibility), kilometer logging for business expense valuation, and single-user workflows where "select employee" is never asked.

The organisation-level mode flag allows hrmq to adapt its entire UX and data model to this segment without forking code or creating a separate app. Historical data (hours, declarations, assets) remains unchanged; mode switching from ZZP→employer is transparent.

## Goals

- Adapt hrmq navigation and workflows for single-person, non-payroll use cases
- Surface urencriterium tracking prominently so ZZP's can verify tax-deduction eligibility year-round
- Provide kilometer logging and IB-tax export for boekhouder handoff or Mijn Belastingdienst filing
- Allow seamless upgrade from ZZP mode to full employer mode when the business hires staff
- Make mode selection during onboarding intuitive (legal form picker)
- Reduce UI clutter for solo workers (hide employee selectors, team features, payroll modules)

## Non-Goals

- Create a separate hrmq-zzp application (violates ADR-001 Rule 4)
- Support multi-person VOF partnerships (separate spec planned)
- Automate boekhouder/tax filing submission (hand-off point is jaaroverzicht PDF/CSV export)
- Implement advanced personal tax planning (zelfstandigenaftrek calculation is static per Wet IB 2001)
- Support legacy FOR new contributions post-2023 (continuation only, via warning)

## Decisions

### Decision 1: Organisation.legal_form enum drives hrm_mode computation

**Approach:**  
Add `Organisation.legal_form` as an enum field (source: KvK canonical codes) with values: eenmanszaak, vof, bv_dga, bv_employer, stichting, vereniging, cooperatie.  
Derive `Organisation.hrm_mode` as a computed field:
- `zzp` if legal_form ∈ {eenmanszaak, vof, bv_dga} AND employee_count ≤ 0
- `dga_only` if legal_form = bv_dga AND employee_count = 1 (future enhancement; defaults to zzp for now)
- `employer` otherwise (default)

**Rationale:**  
Legal form is the authoritative classification (from KvK register); employee count creates the two-dimensional state. Computation avoids denormalization and keeps mode in sync with actual org structure. When an org hires its first employee or changes legal form, hrm_mode updates automatically.

**Tradeoffs:**  
- Employee add → immediate mode switch (cannot have gradual "preview payroll mode" state)
- Mitigation: onboarding flow explains this path clearly; mode upgrade step is explicit

### Decision 2: TaxContext entity encapsulates per-tenant IB/LB configuration

**Approach:**  
Create `TaxContext` table with columns:
- `id, organisation_id, context_type (enum: ib | lb), fiscal_partner (bool), for_active (bool), lijfrente_premiebank (decimal), urencriterium_target (int, default 1225), created_at, updated_at`

`context_type` is selected at onboarding based on hrm_mode:
- `ib` for zzp/dga_only modes
- `lb` for employer mode

Switching hrm_mode from zzp → employer updates context_type to lb and unhides payroll config prompts (CAO, loonheffingennummer).

**Rationale:**  
Tax rules (IB vs LB deductions, withholding mechanics, reporting deadlines) differ fundamentally. Bundling IB-specific config (FOR-active, lijfrente-premiebank, urencriterium_target) in one entity keeps them queryable as a unit and makes validation easier (e.g., "FOR deductions only if context_type=ib and for_active=true").

**Tradeoffs:**  
- Requires migration to add table (non-breaking, defaults to existing behaviour)
- One record per org (no multi-administrative fragmentation; TaxContext is org-scoped, not per administratie)

### Decision 3: KilometerLog is new lightweight entity

**Approach:**  
Create `KilometerLog` table:
- `id, organisation_id, user_id, date (date), from_address (text), to_address (text), kilometers (decimal), purpose (enum: zakelijk | commute), rate_eur_per_km (decimal, default 0.23), created_at, updated_at`

Quick-log form (mobile-friendly) accepts date + from/to address + purpose, optionally geocoded for kilometer computation, or manual entry. IB-export sums kilometers × rate to derive business-expense total for jaaroverzicht.

**Rationale:**  
Kilometer logging is entirely absent from hrmq today. A lightweight, optional KilometerLog keeps it decoupled from HourLog (which may or may not be journeyed); ZZP's without complex travel patterns can skip it. The rate-per-km field allows annual updates (0.23 EUR/km for 2025, per Belastingdienst).

**Tradeoffs:**  
- Does not include advanced trip planning or mileage optimization (out of scope)
- Geocoding integration (optional; fallback to manual entry) requires external API call (deferred to Tasks phase)

### Decision 4: HourLog.qualifies_for_urencriterium flag filters hour type

**Approach:**  
Add boolean flag `qualifies_for_urencriterium` (default true) to HourLog.  
When true, the hour counts toward the 1225-hour annual target. Billable, acquisition (BD/marketing), and admin hours count; commuting does not.

The urencriterium dashboard widget sums all HourLog where `qualifies_for_urencriterium=true` and date is in current fiscal year (Jan 1 - Dec 31).

**Rationale:**  
The IB urencriterium is a binary test: ≥1225 billable + qualifying hours in a fiscal year → self-employed profit deduction eligible. The flag makes filtering trivial and allows admins to reclassify hours (e.g., "this commute was partly work-related" → flip flag).

**Tradeoffs:**  
- Requires migration to add column (non-breaking, defaults to true)
- Does not automate classification of hour type (assumes HourLog.type is already captured separately, e.g., billable | acquisition | admin | commute)

### Decision 5: Navigation is conditionally rendered based on hrm_mode

**Approach:**  
In the app's main navigation shell (Vue component or server-side template), wrap menu items with visibility guards:
```
Payroll menu → visible only if hrm_mode = employer
CAO management → visible only if hrm_mode = employer
Verzekeringen → visible only if hrm_mode = employer
ARBO-coordinator → visible only if hrm_mode = employer
Performance-cycles → visible only if hrm_mode = employer (multi-person workflows)
Org-chart → visible only if hrm_mode = employer
Manager-portal → visible only if hrm_mode = employer (or deduce from role)
```

API endpoints for hidden modules (e.g., `/api/payroll/runs`) reject writes with 403 + response body `{ "error_code": "mode_restriction", "message": "Payroll is not available in ZZP mode" }`. Reads may be allowed for audit trails; writes are forbidden.

**Rationale:**  
Conditional rendering eliminates cognitive load for ZZP users. API enforcement prevents bypassing the UI (e.g., curl requests). Mode_restriction error code allows clients to distinguish mode-based denials from permission errors.

**Tradeoffs:**  
- Navigation structure is centralized (easy to maintain); changes to visibility rules require one code location
- Error messaging must be clear so users understand why a feature is unavailable (not a permission error, but mode mismatch)

### Decision 6: Single-user list views hide employee filter

**Approach:**  
For organisations with exactly one user (hrm_mode=zzp and user_count=1):
- HourLog list view: hide "employee" column, "employee" filter; implicitly scoped to current user
- ExpenseClaim list view: same treatment
- LeaveRequest list view: same treatment
- Create workflows: pre-fill "subject" (claimed by) to current user, skip role-based "submit-on-behalf-of" workflows
- Approval workflows: self-approval automatically granted with audit-log entry (not a permission gate, but workflow state auto-transition)

**Rationale:**  
Reduces UI redundancy when there is only one actor. A solo freelancer should not see a "who is this for?" dialog on every action. Audit trail preserves accountability.

**Tradeoffs:**  
- If a second user is added (e.g., bookkeeper), employee filter must re-appear dynamically (requires re-render on user-add event)
- Self-approval for one user is semantically odd (no peer review possible); acceptable for ZZP use case

### Decision 7: IB-export (jaaroverzicht) is PDF + CSV

**Approach:**  
End-of-year export generates two files:
1. **PDF jaaroverzicht**: formatted for boekhouder handoff or Mijn Belastingdienst Zakelijk portal. Includes:
   - Total billable revenue (from shillinq invoices if integrated; else manual summary)
   - Total billable + qualifying hours (from HourLog, urencriterium widget summary)
   - Business kilometers total + EUR valuation (from KilometerLog)
   - FOR-opbouw (if for_active=true), with 9904 EUR/2025 cap
   - Lijfrente-premies (aggregate of TaxContext.lijfrente_premiebank contributions)
   - Urencriterium pass/fail (≥1225 hours = pass)
   - Org name, address, KvK, fiscal year

2. **CSV export**: tabular data (one row per transaction) suitable for Excel pivot or further processing by boekhouder software

Both include validation summary (missing data, warnings).

**Rationale:**  
Boekhouders expect PDF (professional presentation) or CSV (system import). Two formats serve both workflows.

**Tradeoffs:**  
- PDF generation adds dependency (e.g., TCPDF library); requires care for layout stability
- Revenue data from shillinq integration is optional (fallback: user enters manual summary); coupling to shillinq for urencriterium is assumed (invoices imply billable hours)

### Decision 8: Mode upgrade (zzp→employer) is explicit, non-destructive

**Approach:**  
When a user navigates to Settings > Organisation > Legal Form and changes legal_form from eenmanszaak to bv_employer (or adds a second user):
1. System detects change in hrm_mode (zzp → employer)
2. Modal: "You are adding an employee. This switches hrmq to full payroll mode. Your historical ZZP data (hours, declarations, assets) is preserved."
3. User is prompted for missing payroll config: CAO (picker from list), sector, loonheffingennummer
4. TaxContext.context_type switches from ib to lb
5. Payroll menus un-hide in navigation
6. Historical HourLog, KilometerLog, declarations remain intact (no deletion)
7. Audit log entry: "Mode upgrade: zzp→employer (legal_form: bv_dga→bv_employer, triggered by: user, date: 2026-11-15)"

**Rationale:**  
Transparently communicates the impact and preserves data continuity. ZZP's should not fear growing their business because mode-switch doesn't destroy their history.

**Tradeoffs:**  
- Requires multi-step onboarding workflow (payroll config setup), which is UX-heavy; mitigation is clear messaging
- Reverse migration (employer→zzp) is not supported (out of scope; assumed one-way growth)

---

## Seed Data

### Organisation seed objects

```json
{
  "@self": {
    "register": "organisations",
    "schema": "Organisation",
    "slug": "freqli-consultancy"
  },
  "name": "FreqLi Consultancy",
  "kvk": "84715291",
  "legal_form": "eenmanszaak",
  "hrm_mode": "zzp",
  "address": "Prinsengracht 255, 1016 GW Amsterdam",
  "phone": "+31 20 530 9999",
  "email": "info@freqli.nl"
}
```

```json
{
  "@self": {
    "register": "organisations",
    "schema": "Organisation",
    "slug": "zorg-en-welzijn-bv"
  },
  "name": "Zorg en Welzijn BV",
  "kvk": "67840229",
  "legal_form": "bv_dga",
  "hrm_mode": "zzp",
  "address": "Herenstraat 15, 3511 LS Utrecht",
  "phone": "+31 30 234 5678",
  "email": "admin@zorgbv.nl"
}
```

### TaxContext seed objects

```json
{
  "@self": {
    "register": "tax_contexts",
    "schema": "TaxContext",
    "slug": "freqli-ib-2025"
  },
  "organisation_id": "freqli-consultancy",
  "context_type": "ib",
  "fiscal_partner": false,
  "for_active": true,
  "lijfrente_premiebank": 2500.00,
  "urencriterium_target": 1225
}
```

```json
{
  "@self": {
    "register": "tax_contexts",
    "schema": "TaxContext",
    "slug": "zorgbv-ib-2025"
  },
  "organisation_id": "zorg-en-welzijn-bv",
  "context_type": "ib",
  "fiscal_partner": true,
  "for_active": false,
  "lijfrente_premiebank": 0.00,
  "urencriterium_target": 1225
}
```

### KilometerLog seed objects

```json
{
  "@self": {
    "register": "kilometre_logs",
    "schema": "KilometerLog",
    "slug": "freqli-amsterdam-rotterdam-2025-03-15"
  },
  "organisation_id": "freqli-consultancy",
  "user_id": "user-alice-1",
  "date": "2025-03-15",
  "from_address": "Prinsengracht 255, Amsterdam",
  "to_address": "Eendrachtsplein 8, Rotterdam",
  "kilometers": 78,
  "purpose": "zakelijk",
  "rate_eur_per_km": 0.23
}
```

```json
{
  "@self": {
    "register": "kilometre_logs",
    "schema": "KilometerLog",
    "slug": "freqli-office-client-2025-03-16"
  },
  "organisation_id": "freqli-consultancy",
  "user_id": "user-alice-1",
  "date": "2025-03-16",
  "from_address": "Office: Prinsengracht 255, Amsterdam",
  "to_address": "Client: Kalverstraat 50, Amsterdam",
  "kilometers": 12,
  "purpose": "zakelijk",
  "rate_eur_per_km": 0.23
}
```

### HourLog modifications (example)

Existing HourLog objects gain `qualifies_for_urencriterium` flag:

```json
{
  "@self": {
    "register": "hour_logs",
    "schema": "HourLog",
    "slug": "freqli-billable-2025-03-17"
  },
  "organisation_id": "freqli-consultancy",
  "user_id": "user-alice-1",
  "date": "2025-03-17",
  "hours": 8,
  "type": "billable",
  "description": "Client project: API design",
  "qualifies_for_urencriterium": true
}
```

```json
{
  "@self": {
    "register": "hour_logs",
    "schema": "HourLog",
    "slug": "freqli-commute-2025-03-17"
  },
  "organisation_id": "freqli-consultancy",
  "user_id": "user-alice-1",
  "date": "2025-03-17",
  "hours": 1,
  "type": "commute",
  "description": "Commute to office",
  "qualifies_for_urencriterium": false
}
```

---

## Reuse Analysis

This change leverages existing OpenRegister infrastructure heavily:

- **ObjectService** (CRUD): TaxContext, KilometerLog are standard schemas; no custom save/delete logic required
- **CnDetailPage**: Organisation detail gains legal_form enum picker; TaxContext config screen uses auto-generated form
- **CnIndexPage**: KilometerLog list view (with optional map visualization via future enhancement)
- **ImportService/ExportService**: IB-export mechanism builds on existing export pipeline
- **AuditTrailService**: Mode upgrade events logged automatically; HourLog modifications tracked
- **SchemaService**: legal_form enum, TaxContext, KilometerLog are schema-defined; no bespoke entity classes
- **AuthorizationService**: Mode-restriction check is authz-layer concern (endpoint Authorization.IsAllowed check)

**No new abstraction layers required** — the change is schema + navigation visibility + export template.

---

## Technical Debt & Limitations

- **Geocoding**: KilometerLog from/to address lookup requires external API (Google Maps, OpenStreetMap, TravelTime). Deferred to Tasks phase; manual entry is fallback.
- **shillinq integration**: IB-export assumes shillinq invoices (if active) are queryable for revenue summary. Cross-app contract must be explicit in Tasks.
- **FOR dotation logic**: 9.44% of winst calculation is static per 2025 Belastingdienst tables. If winst calculation is deferred (non-goal), FOR-opbouw is advisory only.
- **Reverse migration**: Downgrading employer→zzp is not supported; one-way upgrade only.
