---
status: draft
app: hrmq
spec: zzp-eenmanszaak-no-payroll-mode
target_users: [zzp, eenmanszaak, freelancer, dga-solo]
estimated_effort: M
depends_on: [employee-management, leave-administration, expense-management]
---

# ZZP Eenmanszaak Mode (Geen Werknemers)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Administraties

**Rationale:** Eenmanszaak mode-switch.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

A configurable application-shell mode that adapts hrmq for self-employed persons (ZZP'ers, eenmanszaak, freelancers, and single-person BV/DGA's without payroll obligations). The default hrmq experience assumes an employer-employee relationship: payroll runs, loonbelasting/premies, sectorale CAO's, verzuimverzekering, ARBO-coordinator role, etc. For roughly 1.2 million Dutch ZZP'ers (CBS 2025) most of this is irrelevant or actively confusing. They need IB-tax-oriented features (urencriterium, zelfstandigenaftrek, MKB-winstvrijstelling, FOR/lijfrente) and a single-user UI.

This spec defines a mode flag at the organisation level that suppresses payroll modules entirely, switches tax computations from LB to IB-context, surfaces the urencriterium tracker prominently, and reshapes navigation so the single user is not asked "select employee" on every screen.

## Data Model

**Organisation.legal_form** (new enum field):
- `eenmanszaak` (sole proprietorship)
- `vof` (partnership)
- `bv_dga` (BV with sole director-shareholder)
- `bv_employer` (BV with employees — default payroll mode)
- `stichting`, `vereniging`, `cooperatie` (fall through to employer mode)

**Organisation.hrm_mode** (derived, computed from legal_form + employee count):
- `zzp` — single-person, no payroll
- `dga_only` — BV with only DGA on payroll (simplified mode, future)
- `employer` — full payroll (default)

**TaxContext** (new entity, per-tenant config):
- `context_type`: `ib` (inkomstenbelasting) | `lb` (loonbelasting)
- `fiscal_partner`: boolean
- `for_active`: boolean (FOR phased out for new builds since 2023 but legacy continuation allowed)
- `lijfrente_premiebank`: decimal
- `urencriterium_target`: int (default 1225)

**HourLog.qualifies_for_urencriterium** (boolean, default true) — billable + acquisition + admin all count, commuting does not.

**KilometerLog** (new entity for ZZP zakelijke kilometers):
- `date`, `from_address`, `to_address`, `kilometers`, `purpose`, `rate_eur_per_km` (default 0.23 for 2025)

## Requirements

### REQ-001: Mode flag at organisation onboarding

**GIVEN** a new tenant signs up for hrmq
**WHEN** they select "eenmanszaak" or "ZZP" in the legal-form picker during onboarding
**THEN** the organisation is created with `hrm_mode=zzp`, payroll modules are hidden from navigation, and the user is taken directly to the personal dashboard (no team-selection screen)

### REQ-002: Hide payroll modules in ZZP mode

**GIVEN** an organisation with `hrm_mode=zzp`
**WHEN** any user loads the application shell
**THEN** the navigation excludes Payroll, Loonbelasting-aangifte, CAO-management, Verzekeringen (collective), ARBO-coordinator, Performance-cycles (multi-person), Org-chart, and Manager-portal; the API rejects writes to these endpoints with 403 + `mode_restriction` error code

### REQ-003: Urencriterium dashboard widget

**GIVEN** a ZZP-mode user
**WHEN** they open the personal dashboard
**THEN** a prominent widget shows current-year billable + qualifying hours vs the 1225-hour target with a progress bar, projected year-end total based on YTD pace, and a warning if projection falls below target after October

### REQ-004: Kilometerregistratie

**GIVEN** a ZZP-mode user
**WHEN** they log a business trip via the mobile-friendly quick-log form (date, from, to, purpose)
**THEN** the system computes kilometers via geocoder lookup (or accepts manual entry), applies the current-year rate (0.23 EUR/km for 2025), and the total feeds the IB-export

### REQ-005: IB-tax export (jaaroverzicht)

**GIVEN** a ZZP-mode user at end of fiscal year
**WHEN** they request the IB-export
**THEN** a PDF + CSV is generated containing: total billable revenue (from invoicing if shillinq integration is active, else manual), business kilometers total + euro value, FOR-opbouw if active, lijfrente-premies, urencriterium status (pass/fail), and a summary suitable for handing to a boekhouder or pasting into Mijn Belastingdienst Zakelijk

### REQ-006: FOR / lijfrente tracking

**GIVEN** a ZZP-mode user with FOR-active=true (legacy continuation)
**WHEN** they navigate to Pensioen
**THEN** they see current FOR-stand, annual dotation limit (9.44% of winst, max 9904 EUR for 2025), and a warning that no new dotations are allowed for fiscal years 2024+ (only existing FOR may be continued or wound down)

### REQ-007: Single-user UI simplification

**GIVEN** a ZZP-mode organisation with exactly one user
**WHEN** any list-view loads (HourLog, ExpenseClaim, LeaveRequest)
**THEN** the "employee" filter column is hidden, "create new" pre-fills the current user as subject, and "approval workflow" screens are bypassed (self-approved with audit-log entry)

### REQ-008: Mode upgrade path (ZZP grows to employer)

**GIVEN** a ZZP-mode organisation that hires its first employee
**WHEN** the owner navigates to Settings > Organisation > Legal Form and changes to `bv_employer` (or adds a second user via the employer-onboarding flow)
**THEN** the system unhides payroll modules, prompts for missing payroll-config data (CAO, sector, loonheffingennummer), preserves all historical ZZP data (urencriterium, IB-exports), and switches future fiscal-year defaults from IB to LB context

## Standards & References

- **Wet IB 2001** Art. 3.6 (urencriterium), Art. 3.79 (zelfstandigenaftrek)
- **Belastingdienst** — Handboek Ondernemers 2025 (kilometervergoeding tarief)
- **CBS** — ZZP-Monitor 2025 (1.2M ZZP'ers, group sizing)
- **KvK** legal-form codes (used as canonical enum source)

## Cross-app Coordination

- **shillinq** — if active, billable hours from shillinq invoices flow into urencriterium computation; revenue feeds IB-export
- **openregister** — `hrm_mode` is a tenant-level config field on the organisation register, queried by all hrmq schemas via `IAppConfig`
- **opencatalogi** — hrmq-zzp variant gets a separate store listing ("hrmq voor ZZP") so the ZZP audience finds a focused install path
- **openconnector** — KvK lookup on onboarding to pre-fill legal_form

## Target Users

Primary: Solo freelancers, eenmanszaak-houders, ZZP'ers in IT/consultancy/zorg/bouw. Secondary: single-DGA BV's (simplified mode, post-MVP). Out of scope: VOF partnerships (separate spec), multi-person collectives.
