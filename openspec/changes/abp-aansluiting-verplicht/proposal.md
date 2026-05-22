---
status: draft
---

# Proposal: ABP Aansluiting Verplicht voor Overheidssector

## Why

Every Dutch public sector employer (municipalities, provinces, water boards, ministries, public schools, higher education institutions) is legally required by the *Wet Privatisering ABP* (1996) to affiliate with the ABP (Algemeen Burgerlijk Pensioenfonds) and submit monthly UPA (Uniforme Pensioenaangifte) records. Without an ABP-specific implementation, HRMQ cannot serve government customers, who represent 100% of government payroll tenders.

The ABP-UPA differs significantly from generic UPA: fifteen extra fields, 36-hour full-time equivalents (not 40), VPL (Voorwaardelijke Pensioenaanspraak) cohort tracking, partner pension registration, and five-day-maximum Adieu (exit) meldingen. Moreover, ABP returns daily confirmation and rejection messages in CRS format with 247 distinct fault codes, requiring an admin queue and correction workflow.

Currently, `payroll-engine-nl` provides only generic UPA output, leaving a critical gap.

## What Changes

### New Capabilities

- **ABP Deelnemer Registration**: Automatic enrollment determination for new hires based on employer category and function; tracking of ABP participant number, enrollment date, participation percentage, and pension scheme code (AP, KP, OP, etc.).
- **ABP-UPA Monthly Generation**: Per-payroll-period generation of UPA records with all fifteen ABP-specific fields (pension-bearing salary per ABP definition, VPL allocations, Keuzepensioen flexibility saldo, AOV premiums) formatted to UPA 2026.01 with ABP extensions.
- **Premium Calculation (Werkgever 22.5%, Werknemer 4.5%)**: Calculation of ABP premiums over the pension-bearing salary (minus franchise, adjusted for ABP's 36-hour factor), appearing as loon-strip rules and GL entries.
- **VPL Administration**: Per-participant tracking of Voorwaardelijke Pensioenaanspraak for cohorts born 1950–1972, with annual VPL amount confirmation and UPA reporting.
- **Keuzepensioen Flexibility**: Support for extra contributions, partial pension drawdowns, and retirement-age flexibility, with saldo mutations in monthly UPA.
- **Pension Partner Registration**: Registration, amendment, and termination of pension partners (spouse, registered partner, notarial cohabitation contract) with ABP notification and compliance with *Wet VPS* (pension asset division on divorce).
- **Adieu (Exit) Meldingen**: Five-day-maximum exit meldingen with reason code (14-code catalog), pension-bearing salary snapshot, and optional continuous-pension flag for inter-agency transfers within 30 days.
- **ABP Retour-Bericht Admin Queue**: Dashboard inbox for ABP confirmations, rejections, warnings, and queries (247 fault codes); filter by error code, age, and participant; correction workflow with resubmit capability.
- **Employer Premium Reporting**: Monthly and annual workload reports showing ABP premium as percentage of gross payroll, by cost center and CAO segment.

### Modified Capabilities

- `payroll-engine-nl` premium calculation extended with ABP-specific franchise pro-rata, 36-hour factor, and VPL-cohort logic.
- Payroll correction flow enhanced to support terugwerkende-kracht (retroactive) UPA corrections with multi-month corrections and year-boundary handling.

## Impact

**New entities (OpenRegister schemas):**
- `abp-deelnemer-registratie` (ABP participant register)
- `abp-upa-record` (monthly UPA generation log)
- `abp-pensioenpartner` (pension partner registry)
- `abp-adieu-melding` (exit meldingen)
- `abp-retour-bericht` (return message inbox)
- `vpl-saldo` (VPL balance per participant)

**Modified files/modules:**
- `payroll-engine-nl`: Franchise pro-rata, VPL cohort logic, 36/40 hour conversion
- `employee-master`: Pension partner tracking, burgerlijke-staat mutations
- `boekhouding-export`: ABP premium GL codes (4030 employer, 1610 employee)
- `wnt-disclosure`: Pension premium in topfunctionaris calculations
- `jaaropgave-generator`: Employee pension premium in annual tax statement

**Integrations:**
- ABP SFTP upload (`sftp.abp.nl/werkgever`) for UPA batches
- ABP REST API (mijnabp-werkgever v6.1) for partner mutations
- Logius Digipoort as alternative upload channel
- Conduction OpenConnector with `abp-sftp` and `abp-rest` connectoren

## Stakeholders

- **Salarisadministrateur** (primary): Monthly UPA batch, retour-bericht correction, participant mutations (500–15,000 active participants)
- **Pensioenadministrateur** (large org specialist): ABP-only administration
- **HR Administrator** (secondary): Intake, first partner registration
- **Controller**: Workload reporting, year-end settlement
- **Employee**: Portal access to participant number, extra-inleg KP choices, partner registration requests
- **Exit procedure owner**: Triggering Adieu meldingen on separation

## Success Criteria

1. ABP participant enrollment determinism: 100% of new hires at ABP-obligated employers automatically enrolled within one payroll cycle.
2. UPA compliance: Generated UPA records validate 100% against UPA 2026.01 XSD with ABP extensions.
3. Premium correctness: Calculated premiums match ABP's own calculation rules (franchise pro-rata, 36-hour factor) within ±0.01 EUR per participant per period.
4. Five-day Adieu: 100% of exit meldingen delivered to ABP within five working days of `laatste-werkdag`.
5. Retour-bericht visibility: 100% of ABP return messages in admin queue within one hour of ABP publication (07:00 daily).
6. No regulatory breach: Zero rejected UPA files due to missing mandatory ABP fields, incorrect VPL cohort identification, or stale premium tariff tables.
