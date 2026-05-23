---
status: proposal
created: 2026-05-23
---

# CAO Primair Onderwijs — PO Arbeidsvoorwaarden Module

## Executive Summary

The `cao-onderwijs-po` capability implements the collective labour agreement (CAO) for Dutch primary education (Primair Onderwijs), covering approximately 175,000 employees across ~6,500 schools and ~800 school boards. This module encodes eight major structural peculiarities distinct to PO education, including the working time factor (wtf) model, L11/LB/LC salary scales, mandatory teacher registration, DUSP budget for older workers, replacement fund claims, pension integration, and training trajectory management.

The module is a SETTING under `Configuratie › CAO's & regelingen` per ADR-001 Rule 1.

## Key Features

### 1. Salarisinschaling met LA/LB/LC/L11 en functiecategorie-validatie
**Demand:** 95  
**Description:** Validates salary scaling (L11/LB/LC/OOP/DIR) against function category, qualification status, and school type. Automatically calculates monthly salary from reference tables with effective-date tracking. Prevents invalid combinations (e.g., L11 in special education) with suggested alternatives.

### 2. Werktijdfactor (wtf) berekening over normjaartaak 1659 uur
**Demand:** 98  
**Description:** Expresses working time factor as a decimal quotient over the standard year task of 1,659 hours. Automatically scales all salary and leave components pro-rata. Supports wtf mutations with immediate payroll impact and corresponding DUSP/leave adjustments.

### 3. Lerarenregister-koppeling (mandatory for qualified teachers)
**Demand:** 92  
**Description:** Enforces mandatory teacher registration (DUO lerarenregister) for all employees with bevoegdheidsstatus="bevoegd". Synchronizes registration status monthly, generates alerts for expired/revoked registrations, and auto-transitions status for compliance failures.

### 4. DUSP-budget opbouw en besteding voor 57-plussers
**Demand:** 87  
**Description:** Administers Duurzame Inzetbaarheid Senioren Plus (DUSP) budget for employees 57+. Accrues 170 hours annually (wtf 1.0) with options to spend as work-time reduction, study leave, coaching, or cash equivalent. Generates roster markings for replacement planning.

### 5. LIO/ZIO-traject inschaling en opleidingsuren-garantie
**Demand:** 84  
**Description:** Manages teacher-in-training (LIO) and side-entry (ZIO) trajectories with specialized salary schedules, guaranteed training hours within the normjaartaak, and automatic conversion to qualified-teacher regime upon successful completion.

### 6. Vervangingsfonds-declaratie voor aangesloten besturen
**Demand:** 79  
**Description:** Automatically generates replacement-fund claims (VfPf) for sick-leave substitutions at enrolled school boards. Calculates pro-rata salary costs, manages claim status (submitted/approved/rejected), and falls back to employer self-funding for non-enrolled boards.

### 7. ABP-aansluiting verplicht voor alle onderwijspersoneel
**Demand:** 91  
**Description:** Enforces mandatory ABP pension registration for all education employees from first day of employment. Calculates OP-premie (occupational pension), AAOP-premie (supplementary), and ANW-hiaat (widow/orphan) according to CAO percentages. Manages ABP-OW (education transition rights) for pre-2006 employees.

### 8. Loonsverhoging-stappen per CAO-akkoord en peildatum
**Demand:** 75  
**Description:** Applies contractual salary increases on CAO-agreed effective dates with automatic retroactive payback calculation. Handles trede-verhogingen (seniority steps) concurrent with CAO-wide increases cumulatively.

### 9. Convenantsverlof en seniorenverlof bovenop wettelijk minimum
**Demand:** 82  
**Description:** Administers collective-agreement leave (convenantsverlof) coupled to school holidays (428 hours/year fulltime) and manages overgangsrechtelijke senioren-verlof for pre-2014 hires not under DUSP.

### 10. Subsidiebanen Wsw en in-/doorstroombanen overgangsrecht
**Demand:** 71  
**Description:** Supports transition rights for remaining Wsw (Wet sociale werkvoorziening) and ID-baan (subsidized entry jobs) employees from 1990s-era schemes with UWV-subsidized salary tables and restricted hiring rules (no new ID-banen since 2004).

## Stakeholders

| Role | Responsibility | Goal |
|------|-----------------|------|
| HR-administrateur | Registers new contracts, processes wtf-changes, manages DUSP choices | Accurate monthly admin with minimal compliance errors |
| Schoolleider | Authorizes wtf-changes, evaluates DUSP work-reduction feasibility, initiates sick-leave/replacement | On-time roster planning without budget surprises |
| Bestuurder | Approves personnel-cost budgets, reviews aggregated reports | Budget control and board accountability |
| Salarisadministrateur | Processes monthly payroll, handles CAO retroactive payments, submits replacement-fund claims | Timely, compliant salary delivery |
| HR-beleidsmedewerker | Updates salary tables, DUSP staffels after CAO agreements | Policy implementation across the sector |
| Auditor (jaarrekening/Inspectie) | Verifies personnel-cost legitimacy and teacher-qualification coverage | Audit compliance and budget rightfulness |
| Leerkracht (self-service) | Views own salary, DUSP budget + options, leave balance, registration status | Transparency in compensation and entitlements |
| Invalkracht (substitute) | Tracks sick-leave assignments and replacement-fund claim status | Clarity on pay and claim resolution |

## Customer Journey: New Teacher Onboarding

1. **HR receives vacancy filled notification** → registers new contract with school board
2. **System validates teacher registration** → checks DUO lerarenregister for bevoegdheidsstatus
3. **Salary scale applied** → selects L11/LB based on school-type and function category
4. **wtf set to 1.0** → calculates monthly salary, DUSP eligibility (if age 57+)
5. **ABP pension activated** → OP-premie begins accrual from first paycheck
6. **Leave setup** → convenantsverlof tied to school calendar, year-1 seniority DUSP (if applicable)
7. **Teacher sees payslip in self-service** → confirms salary components, registration status

## Customer Journey: Sick Leave & Replacement

1. **Teacher reports sick** → HR logs absence start-date with school board
2. **Replacement needed** → schoolrooster-planning marks lesgebonden-uren as vacant
3. **Substitute hired** → new employment record created for vervanger, wtf pro-rata to coverage period
4. **Salary processed** → payroll includes zieke werknemer's continued pay or vervanger's temporary salary
5. **Vervangingsfonds claim generated** (if enrolled) → declaratie submitted with salariskosten to VfPf
6. **Claim approved/rejected** → HR updates status, budget reconciliation if rejected
7. **Teacher returns** → absence closed, vervanger employment ends, rooster reverts

## Data Model Overview

**Core entity:** `CaoPoEmployment` extends `Employment` with:
- `salarisschaal` (LA, LB, LC, L10-L14, OOP, DIR)
- `salarisnummer` (seniority trede)
- `functiecategorie` (groepsleerkracht, IB-er, specialist, etc.)
- `werktijdfactor` (0.0–1.0 decimal)
- `schoolsoort` (BaO, SbO, SO, VSO)
- `bevoegdheidsstatus` (bevoegd, in-opleiding-LIO, zij-instromer-ZIO, niet-bevoegd-*)
- `lerarenregisterId` (DUO teacher register ID)
- `vervangingsregime` (eigen-risico-bestuur or vervangingsfonds-aangesloten)

**Supporting entities:**
- `LesgebondenUrenAllocatie` — annual lesgebonden/non-lesgebonden hour breakdown
- `DuspBudget` — accrued DUSP hours/euros for 57+, spending categories
- `LIOZIOTraject` — training agreement with end-date and salary rules
- `VervangingsClaim` — replacement-fund claim with status lifecycle
- `ConvenantsVerlofTegoed` — annual collective-agreement leave balance
- `SeniorenVerlofTegoed` — pre-DUSP transition leave (legacy)

## Integration

**Consumed by:**
- `payroll-engine-nl` — monthly salary calculation with wtf-scaling and DUSP withdrawals
- `schoolrooster-planning` — lesgebonden-uren validation and DUSP work-reduction roster markings
- `verlof-administratie` — ConvenantsVerlof and DUSP budget synchronization
- `vervangingsfonds-declaratie` — replacement-claim generation and submission

**Shares:**
- `lerarenregister-koppeling` (shared with cao-onderwijs-vo and cao-onderwijs-mbo)
- `abp-aansluiting` (shared kernel with cao-gemeenten, cao-rijk, cao-ziekenhuizen, cao-zorg-vvt)

## Implementation Notes

- **Placement:** SETTING under Configuratie › CAO's & regelingen (ADR-001 Rule 1)
- **School-year orientation:** All date-bound calculations use SchoolYear value object (01-08 to 31-07), not calendar-year
- **Entity boundary:** Separates `Aanstelling` (legal employment at school board) from `Schoolplaatsing` (actual assignment to school+group with lesgebonden-uren)
- **Reference data:** Salary tables, normjaartaak config, DUSP staffel, LIO/ZIO staffel, ABP premietabel, VfPf premietabel all populated from authoritative CAO and pension-fund sources post-agreement
- **Compliance:** All design and implementation must trace back to canonical CAO PO 2024-2025 text and relevant Wetten (WPO, WEC, Wet Beroep Leraar, Wet financiering primair onderwijs)
