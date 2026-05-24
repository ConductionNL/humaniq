# Proposal: DGA Gebruikelijk-loon Flow

**Status**: active  
**Change ID**: dga-gebruikelijk-loon  
**Authored**: 2026-05-24  
**Target**: hrmq  
**Information Architecture**: SETTING + ACTION

## Summary

Implement DGA (Directeur-Grootaandeelhouder) gebruikelijk-loon-toets engine and compliance workflow to support Dutch self-employed director-shareholder payroll rules. The feature enables HR administrators and payroll processors to run periodic compliance checks, compute guideline salary thresholds, and flag pseudo-salary distribution attempts.

## Problem Statement

Dutch DGA regulations require that director-shareholders of BV corporations distribute "gebruikelijk loon" (customary/guideline salary) annually — currently EUR 56,000 or higher per market standards. Failure to meet this threshold triggers loonheffing (payroll tax) recalculation across the entire year. 

Competitor systems (AFAS, ADP, Easy-Loon, Employes) offer dedicated DGA compliance engines. hrmq lacks this capability, creating a gap for Dutch mid-market and enterprises with DGA-heavy payroll (holding structures, related-company distributions).

**Evidence:**
- 30 competitor products reviewed; 8+ explicitly feature DGA gebruikelijk-loon
- NL-specific regulatory gap (non-transferable to other locales)
- Blocking feature for deal qualification in Dutch segment

## Market Evidence

| Vendor | Feature | Type |
|--------|---------|------|
| AFAS HRM | DGA-administratie + Gebruikelijk-loon DGA | Built-in |
| ADP NL | Global Payroll (NL native) + DGA rules | Built-in |
| Easy-Loon | DGA salarisrun + Spaarloon | Built-in |
| Employes | DGA-flow + Gebruikelijk-loon | Built-in |
| Centric Payroll | NL payroll engine (CAR-UWO, zorg-CAOs) | Built-in |

## Features

### Feature 1: DGA Medewerker Identification

**Demand**: MUST  
**Description**: Flag employee records as DGA (director-shareholder of own BV) based on ownership percentage and structural designation. Store DGA marker on medewerker + link to BV structure (vennootschap).

**Related entities**: Medewerker, Vennootschap (related company), Eigenaarschap (ownership)

### Feature 2: Gebruikelijk-loon Threshold Calculation

**Demand**: MUST  
**Description**: Compute annual guideline-salary threshold (2026: EUR 56,000) based on current market norms, tenure, and sector. Generate threshold report as of run date.

**Related entities**: DGA-medewerker, Salarissrun, Guideline-salary table

### Feature 3: Gebruikelijk-loon-toets (Audit Run)

**Demand**: MUST  
**Description**: Periodic compliance check run that compares actual bruto salary (YTD) against guideline threshold. Flag underpayments and over-distributions for review or auto-correction.

**Related entities**: Salarisrun, Loonstrook (payslip), Guideline report

### Feature 4: Related-Company Distribution Tracking

**Demand**: SHOULD  
**Description**: Track inter-company distributions (dividends, management fees, loan repayments) to vennootschap (holding structure) alongside DGA salary, to prevent pseudo-salary-as-dividend schemes.

**Related entities**: Vennootschap, Dividend-run, Loonstrook, Journal entry

### Feature 5: Configuration & Rule Settings

**Demand**: MUST  
**Description**: Admin setting page under Configuratie / DGA-regels to set: threshold year, threshold amount (EUR), eligible salary components, audit frequency, and alert thresholds.

**Related entities**: AppConfig, DGA-configuration

## User Stories

### Story 1: DGA Administrator Marks Employee as DGA

**GIVEN** a medewerker record in hrmq  
**WHEN** the HR administrator opens the personnel detail and selects "DGA mode" + optionally links to a vennootschap record  
**THEN** the medewerker is flagged as DGA and the yearly gebruikelijk-loon threshold is pre-populated from the DGA-regels configuration

### Story 2: Payroll Processor Runs Monthly Gebruikelijk-loon-toets

**GIVEN** one or more DGA medewerkers and a completed salarisrun for the month  
**WHEN** the payroll processor navigates to Salarissen > Loonruns and clicks "Toets gebruikelijk-loon"  
**THEN** the system calculates YTD salary per DGA-medewerker, compares against threshold, and displays a report with:
- Threshold (EUR, yearly)
- YTD actual bruto (EUR)
- Remaining margin (EUR) or shortfall (EUR)
- Flag status (green = compliant, yellow = margin < 10%, red = shortfall)

### Story 3: HR Manager Reviews DGA Compliance Report

**GIVEN** a generated gebruikelijk-loon-toets report  
**WHEN** the HR manager opens the report page  
**THEN** they can:
- Download the report (PDF or Excel)
- Review flagged DGA medewerkers
- Tag medewerkers for manual review or auto-correction
- Export for auditor / tax consultant

### Story 4: System Auto-Flags Pseudo-Salary Distribution

**GIVEN** a DGA medewerker with a vennootschap linked  
**WHEN** a dividend payment or management fee is recorded in the same month as a salary reduction  
**THEN** the system flags this as a potential pseudo-salary scheme and adds a note to the DGA compliance report for review

## Stakeholders

**Payroll Administrator**  
- Role: Configure DGA rules, set threshold, audit frequency
- Goal: Ensure compliance with Dutch DGA regulations
- Pain point: Manual spreadsheet-based threshold tracking across years

**Payroll Processor / HR Assistant**  
- Role: Run monthly toets, review flagged cases, export for tax consultant
- Goal: Reduce month-end payroll processing time
- Pain point: Identifying shortfall cases manually, high error risk

**HR Manager / Compliance Officer**  
- Role: Review DGA compliance, escalate to tax advisor
- Goal: Audit-ready documentation of DGA compliance
- Pain point: No centralized report; scattered across payroll + accounting systems

**Tax Consultant / Auditor (External)**  
- Role: Receive compliance report for DGA review during annual tax filing
- Goal: Verify guideline-salary compliance, identify risk areas
- Pain point: Data arrives in multiple formats, manual consolidation

## Information Architecture

**Placement**: SETTING + ACTION

- **SETTING** (Configuration): Configuratie / DGA-regels — page for threshold configuration
- **ACTION** (Run interface): Action button on DGA-medewerker detail ("Toets gebruikelijk-loon") or on Salarisrun list ("Toets DGA medewerkers")

**Rationale**: Aligns with ADR-001 rule: **payroll-engine rules are SETTING (configuration) + ACTION (periodic run), not top-level menu entries.**

## Dependencies

- **payroll-core-basic**: Salarisrun, loonstroken, bruto salary calculation engine
- **contract-management**: Employee contract history (to determine DGA designation timeline)
- **OpenRegister**: Vennootschap schema (related company structure)
- **Standard ADRs**: ADR-001 (IA placement), ADR-010 (NL Design System), ADR-001 data-layer (OpenRegister)

## Success Criteria

1. DGA medewerkers flagged and queryable in medewerker list
2. Gebruikelijk-loon-toets runs in < 5s for 100 DGA medewerkers
3. Configuration page accessible in Configuratie / DGA-regels
4. Report downloadable (PDF/Excel) with audit-ready formatting
5. Threshold compliance flagging > 95% accuracy vs. manual calculation
6. Related-company distribution tracking (UI + backend) functional
7. Zero false-positives in pseudo-salary detection (spec approval gate)

## Out of Scope

- Tax filing / belastingaangifte integration (separate spec)
- Historical threshold lookups (2020–2025); baseline is 2026
- DGA salary auto-correction (manual intervention only)
- API for external auditor access (not in MVP)
