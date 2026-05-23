# Proposal: ZZP Eenmanszaak Mode (Geen Werknemers)

## Why

hrmq is built for employer-employee relationships with full payroll infrastructure (loonbelasting, sectorale CAO's, verzuimverzekering, ARBO roles). For approximately 1.2 million Dutch ZZP'ers, freelancers, eenmanszaak-houders, and single-person DGA's, most of this is irrelevant or actively confusing. They need IB-tax-oriented features (urencriterium, zelfstandigenaftrek, MKB-winstvrijstelling, FOR/lijfrente) and a simplified single-user UI that doesn't ask "select employee" on every screen.

## What Changes

### Organization-Level Mode Flag

- New enum field `Organisation.legal_form` supporting: `eenmanszaak`, `vof`, `bv_dga`, `bv_employer`, `stichting`, `vereniging`, `cooperatie`
- Derived field `Organisation.hrm_mode` computed as: `zzp` (single-person, no payroll), `dga_only` (BV with sole DGA), or `employer` (default)
- Mode selection at onboarding with direct navigation to personal dashboard (no team-selection)

### Payroll Module Suppression

- Navigation excludes Payroll, Loonbelasting-aangifte, CAO-management, Verzekeringen, ARBO-coordinator, Performance-cycles, Org-chart, Manager-portal in ZZP mode
- API rejects writes to payroll endpoints with 403 + `mode_restriction` error code

### Tax Context & Self-Employment Features

- New `TaxContext` entity (per-tenant): context_type (ib | lb), fiscal_partner, FOR-active flag, lijfrente-premiebank, urencriterium_target (default 1225)
- New `KilometerLog` entity for business trip tracking with date, route, kilometers, purpose, per-km rate (default 0.23 EUR/km for 2025)
- `HourLog.qualifies_for_urencriterium` flag (billable, acquisition, admin count; commuting does not)

### Dashboard & Reporting

- Urencriterium progress widget on personal dashboard with YTD billable hours vs 1225-hour target, progress bar, year-end projection
- IB-tax export (jaaroverzicht) as PDF + CSV with: total billable revenue, business kilometers + euro value, FOR-opbouw, lijfrente-premies, urencriterium status
- FOR tracking screen (if legacy continuation active) showing current stand, annual dotation limit, warnings about post-2024 restrictions

### Single-User UI

- Hide "employee" filter column in list views (HourLog, ExpenseClaim, LeaveRequest)
- Auto-pre-fill current user on create operations
- Bypass approval workflows with self-approval + audit-log entry

### Growth Path

- Mode upgrade from ZZP → employer when first employee is hired
- Payroll modules unhide, prompts for CAO/loonheffingennummer, preserves all historical ZZP data
- Future fiscal-year defaults switch from IB to LB context

## Capabilities

### New Capabilities

- **Organisation.legal_form enum**: Selection during onboarding; source: KvK legal-form codes
- **ZZP mode detection**: Derived from legal_form + employee count
- **Urencriterium tracking**: Billable hour counter with 1225-hour target, dashboard widget, projection logic
- **Kilometer registration**: Quick-log form with geocoder + manual entry, per-km rate, euro valuation
- **IB-tax export**: PDF + CSV jaaroverzicht suitable for boekhouder or Mijn Belastingdienst Zakelijk
- **FOR/lijfrente dashboard**: Current stand, annual limits (9.44% winst, max 9904 EUR/2025), continuation-only warnings
- **Single-user list views**: Employee filter hidden, auto-pre-fill on create, self-approval workflow

### Modified Capabilities

- **Organisation onboarding**: Legal form picker replaces generic setup
- **Navigation shell**: Dynamic visibility based on hrm_mode; payroll menus hidden for zzp
- **Hour logging**: New qualifies_for_urencriterium flag to distinguish billable/acquisition/admin from commuting
- **Dashboard widget layout**: New urencriterium widget added to personal dashboard
- **Tax export flow**: IB context for ZZP, LB context for employer mode

## Impact

- **Organisation table**: Add legal_form enum, hrm_mode computed field
- **TaxContext table**: New entity with tenant-scoped config
- **KilometerLog table**: New entity for business mileage tracking
- **HourLog table**: Add qualifies_for_urencriterium boolean flag
- **Navigation & routing**: Conditional menu visibility based on Organization.hrm_mode
- **Dashboard service**: New urencriterium widget logic, projection calculation
- **Export service**: IB-mode jaaroverzicht generation (PDF + CSV)
- **Onboarding flow**: Legal form picker, mode-aware welcome screen
- **API & controllers**: New endpoints for KilometerLog CRUD, TaxContext config, IB-export

---

## Standards & References

- **Wet IB 2001** Art. 3.6 (urencriterium), Art. 3.79 (zelfstandigenaftrek)
- **Belastingdienst Handboek Ondernemers 2025** (kilometervergoeding tarief)
- **CBS ZZP-Monitor 2025** (1.2M ZZP'ers, market sizing)
- **KvK legal-form codes** (canonical enum source)

## Target Audience

**Primary**: Solo freelancers, eenmanszaak-houders, ZZP'ers in IT/consultancy/zorg/bouw  
**Secondary**: Single-DGA BV's (simplified mode, post-MVP)  
**Out of scope**: VOF partnerships (separate spec), multi-person collectives

## Placement

- **Type**: SETTING — configuration mode-switch
- **Location**: Configuratie › Administraties
- **Rationale**: Eenmanszaak mode is an organisation-level mode flag, not a top-level menu item
- **ADR**: ADR-001 Rule 4 — ZZP/DGA modes are mode-switches, not separate apps
