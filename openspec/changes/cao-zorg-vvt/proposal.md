---
status: draft
---

# Proposal: CAO VVT (Verpleeg-, Verzorgingshuizen, Thuiszorg)

## Why

The Dutch healthcare sector (verpleeghuizen, verzorgingshuizen, thuiszorg) operates under the CAO VVT 2024-2026, which covers ~450,000 employees and mandates a highly complex compensation system centered on irregular shift allowances (ORT — Onregelmatigheidstoeslag). The current hrmq lacks the data model, calculation engine, and regulatory integrations to correctly implement this CAO. Without it, care organizations cannot:

1. Calculate per-minute ORT stacking rules (e.g., evening + nightshift + holiday can stack up to 85% on a single shift)
2. Ensure ATW (Arbeidstijdenwet) and CAO-specific work-hour limits are enforced during roster planning
3. File mandatory pension contributions to PFZW (Pensioenfonds Zorg en Welzijn)
4. Comply with Wet zorg en dwang (Wzd) requirements for duty-of-care staff qualification
5. Handle retroactive pay adjustments when the CAO is ratified months after its effective date

This spec implements the complete CAO VVT 2024-2026 engine, making hrmq compliant for the largest care-sector payroll market in the Netherlands.

## What Changes

### New Capabilities

- **ORT calculation engine**: Per-minute assignment of ORT rules with stackability logic per day/time band; supports 25+ ORT rule combinations including evening, night, Sunday, holiday, and feestdag rates
- **Shift data model**: Full shift tracking with ORT decomposition per hour, total hours, paid hours, pauses, department, and role; enables precise loonstrook detail
- **Standby (bereidheidsdienst) registry**: Tracks on-call hours at €3.50/hour with automatic conversion to paid work when activated; includes travel-time compensation
- **Sleep shift (slaapdienst) registry**: Fixed €24.50 nightly rate with automatic upgrade to paid work upon disturbance
- **ATW + CAO work-hour enforcement**: Real-time limits during roster planning: max 52 hrs/week (CAO), max 7 consecutive night shifts, min 11 hrs daily rest post-12hr shift
- **Wage step progression (FWG functiewaardering)**: 46+ function grades (FWG 35–80) with 4–14-period salary scales; automatic re-grading on func-revaluation
- **Retroactive pay adjustment**: Batch recalculation of all pay back to CAO effective date when the CAO is ratified late; SEPA export for former employees
- **PFZW mandatory pension**: Auto-enrollment of CAO VVT employees into PFZW; UPA message dispatch on hire/mutation/exit
- **Overtime regulation (50% / 100%)**: First 4 hours/week at 50% toeslag, remainder at 100%; swappable for time-off at 1.5x and 2.0x multipliers
- **Travel cost reimbursement**: Fixed allowance for woon-werk over 10 km + €0.23/km for duty travel (care-worker visits); tax-free per WKR

### Modified Capabilities

- **Salary engine**: Extended to accept ORT component bundles and retroactive batch recalculations
- **Roster planning**: Enhanced with real-time ATW/CAO guardrail enforcement and ORT-cost preview per shift
- **Employee master**: Extended with FWG grade, PFZW enrollment, Wzd qualification + date, BIG-register link
- **Leave admin**: CAO VVT-specific accrual rules (legal + sectoral leave, shift-rooster leave, study leave, long-care leave)

## Impact

- **New entities**: CAO_VVT_Versie, ORT_Regel, Shift (with ORT decomposition), Bereidheidsdienst, Slaapdienst, Werkurenbewaking_ATW (new schema)
- **Modified entities**: Employee (add FWG, PFZW, Wzd), Salary batch (add retroactive-batch-id), Leave accrual (add CAO-variant rules)
- **New integrations**: PFZW (UPA message), BIG-register (daily sync), CAK (declaration), rooster-planning (ATW guardrails)
- **UI surface**: SETTING under Configuratie › CAO's & regelingen (no new top-level menu per ADR-001 rule 1)
- **Estimated effort**: 180 dev days (core engine 60d, seed data + validation 40d, integrations 50d, QA 30d)

## Placement

**IA Type**: `SETTING`  
**Location**: Configuratie › CAO's & regelingen  
**Rationale**: CAO VVT is configuration data (rate tables, ORT ruleset, PFZW rules) consumed by payroll-engine and rooster-planning. It is not a workflow and does not warrant a top-level menu (ADR-001 rule 1).

## Target Users (Persona Summary)

1. **HR-medewerker zorginstelling** (daily): registers employees, processes mutations, validates BIG/Wzd, advises on CAO rules
2. **Roosterplanner** (daily): builds weekly rosters with real-time ORT-cost and ATW guardrails
3. **Salarisadministrateur** (monthly): executes loonruns, validates ORT calculations, processes retroactives
4. **Manager/teamleider** (weekly): approves leave, monitors ORT costs and utilization
5. **Zorgmedewerker** (self-service): views payslips, files leave requests, swaps duties
6. **Controller** (quarterly): reports on payroll costs, ORT spend, utilization
7. **OR / vakbond** (ad-hoc): monitors anonymized wage/ORT trends for bargaining
