---
status: draft
---

# Specifications: ABP Aansluiting Verplicht

## Overview

This specification document formalizes the ten core requirements from the context brief into testable scenarios using GIVEN/WHEN/THEN (BDD) format.

## REQ-001: ABP Enrollment Determination

The system MUST automatically determine ABP enrollment eligibility for each new hire based on employer category and function, and create an `abp-deelnemer-registratie` record if eligible.

### REQ-001-001: Automatic enrollment on new hire

**GIVEN** an employer with `is-abp-plichtig = true`
**WHEN** a new hire is recorded for a function outside ABP exceptions (not intern, not callout-only with exclusion flag)
**THEN** an `abp-deelnemer-registratie` MUST be created with:
- `aansluitings-datum` = employment start date
- `abp-deelnemersnummer` = null (awaiting ABP assignment on first UPA batch)
- `regeling-code` = "AP" (default; can be overridden manually)
- `partnerpensioen-keuze` = "opbouw" (default)

### REQ-001-002: Mixed-scheme employer (partial ABP, partial PFZW)

**GIVEN** an employer with multiple pension schemes (e.g., zorginstelling with education department)
**WHEN** a new hire is recorded with function category matching ABP scope (e.g., education function)
**THEN** ABP enrollment MUST be triggered (not PFZW), even though the employer has other functions under PFZW
**AND** a flag in the employee record MUST mark `pension-scheme = ABP` to prevent duplicate PFZW enrollment

### REQ-001-003: Inter-agency ABP transfer (existing deelnemeringsnummer)

**GIVEN** a werknemer transferring from ABP employer A to ABP employer B, both using HRMQ
**WHEN** the hire date at employer B is recorded with reference to prior ABP deelnemeringsnummer from employer A
**THEN** the system MUST:
1. Reuse the existing `abp-deelnemersnummer` (no new ABP enrollment needed)
2. NOT send an ABP-Aanmelding (new enrollment) to ABP
3. Set `pensioenopbouw-doorlopend-vlag = true` on the final Adieu at employer A

## REQ-002: Monthly UPA Generation with ABP Fields

The UPA output for each payroll period MUST include all fifteen ABP-specific fields and conform to UPA 2026.01 XSD with ABP extensions.

### REQ-002-001: Full UPA record generation per payroll period

**GIVEN** a completed payroll period for an employer with 1,247 ABP participants
**WHEN** the UPA generator is triggered (manually or scheduled)
**THEN** a valid UPA 2026.01 file MUST be generated with:
- 1,247 `<deelnemer>` records (one per active participant)
- All fifteen ABP-specific fields populated per design.md
- XSD validation pass against UPA 2026.01-ABP extension schema
- SFTP upload to ABP (sftp.abp.nl/werkgever) or Logius Digipoort

### REQ-002-002: Part-time FTE conversion (36-hour factor)

**GIVEN** a part-time participant contracted for 28 hours/week
**WHEN** the ABP-UPA record is generated
**THEN** `voltijdsfactor-abp` MUST equal 28 ÷ 36 = 0.7778 (NOT 28 ÷ 40 = 0.70)
**AND** this FTE is used for franchise pro-rata and premium-grondslag calculations

### REQ-002-003: Pension-bearing salary per ABP definition

**GIVEN** a payroll period in which a participant received a one-time payment (annual bonus, back-pay) classified as pension-bearing per ABP rules
**WHEN** the ABP-UPA record is generated
**THEN** `pensioengevend-loon-abp` MUST include the one-time payment, even if it differs from fiscal gross salary
**AND** the system MUST apply the ABP-specific definition (differs from generic UPA or fiscal treatment)

## REQ-003: Premium Calculation (Werkgever 22.5%, Werknemer 4.5%)

ABP premiums MUST be calculated per participant per period over the pension-bearing salary (minus franchise, pro-rated for FTE) and appear as payroll deductions and GL entries.

### REQ-003-001: Full-time premium calculation

**GIVEN** a full-time participant (1.0 FTE) with monthly pension-bearing salary EUR 4,500 in January 2026
**WHEN** payroll is processed
**THEN**:
- Monthly franchise = EUR 17,545 ÷ 12 = EUR 1,462.08 (per ABP 2026 tariff table, pro-rata FTE 1.0)
- Premium grondslag = EUR 4,500 − EUR 1,462.08 = EUR 3,037.92
- Employer premium = EUR 3,037.92 × 0.225 = EUR 683.53 (GL 4030)
- Employee premium = EUR 3,037.92 × 0.045 = EUR 136.71 (appear on payslip, GL 1610)

### REQ-003-002: Part-time franchise pro-rata

**GIVEN** a part-time participant (0.6 FTE) with monthly pension-bearing salary EUR 2,700
**WHEN** payroll is processed
**THEN**:
- Pro-rata franchise = EUR 1,462.08 × 0.6 = EUR 877.25
- Premium grondslag = EUR 2,700 − EUR 877.25 = EUR 1,822.75
- Employer premium = EUR 1,822.75 × 0.225 = EUR 410.13
- Employee premium = EUR 1,822.75 × 0.045 = EUR 82.03

### REQ-003-003: Premium tariff table updates (year-boundary)

**GIVEN** a payroll period spanning January 2027 (when ABP 2027 premiums were announced in December 2026)
**WHEN** payroll is processed
**THEN** the system MUST:
1. Load the correct `abp-premie-tarief-tabel` row for year 2027
2. Apply 2027 percentages and franchise amounts to all January 2027 periods
3. Not retroactively apply 2027 rates to prior months in 2026

## REQ-004: VPL-Bedrag Administration

For each participant born between 1 January 1950 and 31 December 1972, a VPL amount MUST be tracked annually and reported to ABP via the UPA-VPL bijlage.

### REQ-004-001: VPL cohort identification and annual accrual

**GIVEN** a participant born on 15 June 1965 (within VPL cohort 1950–1972)
**WHEN** the January payroll period is processed
**THEN** a `vpl-saldo` record MUST be created for year 2026 with:
- `bedrag-tot-nu-toe` = 0
- Flag marked for inclusion in UPA-VPL bijlage
**AND** each monthly `upa-record` MUST include:
- `vpl-bedrag` = `pensioengevend-loon-abp` × (voltijdsfactor-abp) × 0.02 (2.0% regular ABP VPL rate)
- Cumulative total updated in `vpl-saldo` monthly

### REQ-004-002: Non-VPL cohort exclusion

**GIVEN** a participant born on 3 September 1980 (outside VPL cohort)
**WHEN** the payroll period is processed
**THEN** NO `vpl-bedrag` MUST be calculated or reported
**AND** the system MUST NOT create a `vpl-saldo` record for this participant

### REQ-004-003: Year-end VPL reporting

**GIVEN** the year-end close process running in January 2027 (for 2026 VPL)
**WHEN** the UPA batch for January 2027 is generated
**THEN** the UPA-VPL-bijlage MUST include:
- Total 2026 VPL amount per VPL-cohort participant (cumulative across all 12 months)
- Separate line per participant with deelnemeringsnummer and bedrag
- Submitted with the January 2027 UPA batch to ABP

## REQ-005: ABP-Keuzepensioen Flexibility

Participants MUST be able to elect Keuzepensioen flexibility options (extra contributions, partial-pension drawdown, retirement-age flexibility), with mutations reflected in UPA records.

### REQ-005-001: Extra-inleg (additional contributions) election

**GIVEN** a participant who elects extra-inleg-KP of EUR 100/month via employee portal
**WHEN** the choice is confirmed
**THEN**:
1. Employee premium on next payslip MUST increase by EUR 100 (deducted from net salary)
2. `upa-record` for that period MUST include `kp-flexibilisering-saldo` mutation: +EUR 100
3. ABP recognizes the extra inleg in the Keuzepensioen account

### REQ-005-002: Partial pension (deelpensioen) drawdown

**GIVEN** a participant requesting 50% partial-pension effective 1 January 2027
**WHEN** the drawdown request is processed (before year-end 2026)
**THEN**:
1. `deelnemingspercentage` in `abp-deelnemer-registratie` MUST be set to 50% effective 1 January 2027
2. UPA records from January 2027 onward MUST reflect 50% accrual
3. A temporary flag MUST appear in the January 2027 `upa-record` signaling ABP that a deelpensioen mutation is pending (ABP must confirm)
4. Once ABP confirms, the flag is cleared and status updated to "confirmed"

### REQ-005-003: Flexibility election queuing outside UPA cycle

**GIVEN** a participant who modifies extra-inleg between UPA submission deadlines (e.g., mid-month)
**WHEN** the modification is saved
**THEN** the system MUST queue the change for the next payroll cycle
**AND** NOT attempt to inject it into an already-submitted UPA batch (violates integrity)

## REQ-006: Pension Partner Registration

Partner registration or termination changes MUST be transmitted to ABP, with required fields (partner BSN, samenwonings-datum, reden-einde) enforced per *Wet VPS* and ABP rules.

### REQ-006-001: Marriage or registered partnership registration

**GIVEN** a werknemer who reports a marriage via employee portal with partner BSN and marriage date
**WHEN** HR confirms the marriage notification
**THEN** a `abp-pensioenpartner` record MUST be created with:
- `partner-bsn` (validated against 11-proef)
- `samenwonings-datum` = marriage date
- `partner-registration-status` = "pending"
**AND** a background job MUST POST to ABP's mijnabp-werkgever REST API (v6.1) within 24 hours
**AND** once ABP confirms, `registratie-datum-bij-abp` MUST be populated and status → "registered"

### REQ-006-002: Notarial cohabitation contract (samenlevingscontract)

**GIVEN** a werknemer providing a notarial samenlevingscontract (no marriage/registered partnership)
**WHEN** HR processes the registration
**THEN**:
1. An upload field MUST appear for the contract PDF scan
2. `samenwonings-datum` MUST be manually set (signature date of contract, not today)
3. The partner-registration request to ABP MUST include a `contract-bewijs-vlag` and attach the contract scan
4. `partner-registration-status` = "pending" until ABP confirms receipt

### REQ-006-003: Divorce/separation termination with Wet VPS notification

**GIVEN** a werknemer with registered `abp-pensioenpartner` who reports a divorce/separation with end date
**WHEN** HR confirms the separation notification
**THEN**:
1. `abp-pensioenpartner.einddatum` MUST be set to the separation date
2. `reden-einde-code` MUST be selected (divorce, death, separation)
3. A background job MUST POST a termination request to ABP's REST API
4. The werknemer MUST receive an email notification explaining:
   - Partner pension accrual ends as of the separation date
   - Rights to pension division under *Wet VPS* (if applicable)
   - Possible need for notarial pension-division agreement

## REQ-007: Adieu (Exit) Melding on Separation

An Adieu melding MUST be submitted to ABP within five working days of the employee's final workday, with mandatory fields (reason code, eind-pensioengevend-loon, continuous-pension flag if applicable).

### REQ-007-001: Five-day deadline enforcement

**GIVEN** a werknemer with final workday 31 August 2026 (Friday)
**WHEN** the exit procedure concludes on 25 August 2026 (Monday before final day)
**THEN**:
1. An `abp-adieu-melding` record MUST be created with `status` = "pending-approval"
2. A background job `SendAdieuMeldingJob` MUST be scheduled with 24-hour delay (for loon verification)
3. Salarisadministrateur is notified to approve `eind-pensioengevend-loon` snapshot
4. If approval not received by 28 August (3 days post-last-workday), auto-send flag MUST trigger
5. Final deadline: **5 working days post-31 August** = by 7 September 2026 (Friday)
6. Adieu melding MUST be submitted to ABP by 7 September; if missed, ABP returns rejection `ABP-ADIEU-LATE`

### REQ-007-002: Mandatory reason code from 14-code catalog

**GIVEN** an exit melding form without a reason code selected
**WHEN** salarisadministrateur attempts to submit
**THEN** form submission MUST be blocked with error: `ABP-ADIEU-REASON-REQUIRED`
**AND** a dropdown MUST display the 14-code catalog with Dutch descriptions:
- 1: Ontslag door werkgever
- 2: Ontslag op eigen verzoek
- 3–14: [other termination reasons per ABP catalog]

### REQ-007-003: Continuous-pension flag for inter-agency transfers

**GIVEN** a werknemer separating from ABP employer A on 31 August 2026, with hire date at ABP employer B on 1 October 2026 (within 30 days overlap)
**WHEN** the Adieu melding for employer A is created
**THEN**:
1. `pensioenopbouw-doorlopend-vlag` MUST be set to true
2. Employer B's intake system MUST auto-link the existing `abp-deelnemersnummer` (no new enrollment)
3. Pension accrual at employer A is honored; no break in continuous service

## REQ-008: ABP Retour-Bericht Admin Queue

All ABP return messages (Confirmation, Reject, Waarschuwing, Vraag) MUST appear in a dedicated admin queue with filtering, status tracking, and correction-workflow support.

### REQ-008-001: Retour-bericht visibility and filtering

**GIVEN** ABP publishes a daily batch of retour-berichten (1 Confirmation, 2 Rejects, 1 Waarschuwing) at 07:00 on 2 February 2026
**WHEN** the cron task `app:abp:poll-retour-berichten` runs every 30 minutes
**THEN** within 1 hour (by 08:00):
1. All four retour-berichten MUST appear in the admin queue UI
2. Each MUST be filterable by:
   - `verwerkings-status` (open, in-behandeling, opgelost, gesloten-niet-oplosbaar)
   - `type` (Confirmation, Reject, Waarschuwing, Vraag)
   - `fout-code` (e.g., `ABP-PG-001`)
   - Date range (age of message)
3. Each record MUST display:
   - Linked participant name and deelnemeringsnummer
   - Linked UPA record or Adieu melding
   - Fout-omschrijving in Dutch
   - Linked corrective action (if applicable)

### REQ-008-002: Reject correction workflow

**GIVEN** a Reject retour-bericht with fout-code `ABP-PG-001` (pensioengevend-loon invalid)
**WHEN** salarisadministrateur opens the retour-bericht in the admin queue
**THEN**:
1. A "Maak correctie" (Make correction) button MUST appear
2. Clicking the button opens the payroll correction form for the participant + period
3. Salarisadministrateur corrects the loon and re-generates the UPA record
4. The corrected UPA record MUST cross-reference the original via `original-upa-record-id`
5. Upon re-submission to ABP and ABP's Confirmation, the original retour-bericht status → "gesloten-opgelost" (auto-closed)

### REQ-008-003: Age-based alerting for old Rejects

**GIVEN** an admin queue with 50 open Reject-berichten, oldest from 20 days ago
**WHEN** salarisadministrateur opens the admin dashboard
**THEN**:
1. A warning banner MUST appear: "50 open rejections; 12 older than 14 days"
2. A filter "Show only >14 days old" MUST be available
3. The system MUST escalate Rejects aging beyond 14 days (unresolved for 2 weeks = near-compliance risk)

## REQ-009: ABP Data Correction (Terugwerkende-kracht Mutations)

Historical corrections (retroactive loon, deelnemingspercentage, franchise changes) MUST be submitted via correction-flag UPA records with correct-period designation, and premium adjustments booked to current payslip.

### REQ-009-001: Retroactive loon correction

**GIVEN** a TWK (terugwerkende-kracht) loonsverhoging of EUR 200/month effective 6 months prior (e.g., May 2026 salary increase applied backdated to December 2025)
**WHEN** payroll processes the correction in June 2026
**THEN**:
1. For each prior month (December 2025 – May 2026), a correction-UPA record MUST be generated with:
   - `correction-flag` = true
   - `original-upa-record-id` = prior UPA record for that month
   - Updated `pensioengevend-loon-abp` = EUR 200 increase
   - Recalculated `premiegrondslag` and premiums
2. On the June 2026 payslip, cumulative back-premiums MUST appear as loon deduction (employee) and GL entry (employer)
3. All six correction-UPA records MUST be submitted to ABP in a single batch, or over consecutive months

### REQ-009-002: Retroactive correction spanning calendar year

**GIVEN** a correction spanning December 2025 – February 2026 (crosses year boundary)
**WHEN** the correction is processed
**THEN**:
1. For December 2025 and January 2026: apply 2025 premium percentages
2. For February 2026: apply 2026 premium percentages
3. The system MUST NOT apply 2026 rates to 2025 periods retroactively

### REQ-009-003: Negative premium-grondslag warning

**GIVEN** a correction that would result in negative premium-grondslag (e.g., high fraudulent back-loon adjustment)
**WHEN** salarisadministrateur attempts to book the correction
**THEN**:
1. A warning dialog MUST appear: "Correction would result in negative grondslag EUR -500; allow?"
2. Salarisadministrateur has two options:
   - "Annuleren" (Cancel) — discard the correction
   - "Toestaan" (Allow) — set `negatieve-grondslag-toegestaan-vlag` = true and proceed
3. If allowed, the correction is submitted but flagged for audit review

## REQ-010: Employer Workload Reporting (22.5%)

Monthly and annual workload reports MUST show ABP employer premium as a percentage of gross payroll, breakdowns by cost center and CAO segment, and audit-level detail (participant × premium).

### REQ-010-001: Monthly workload report generation

**GIVEN** a monthly close with 1,247 ABP participants and EUR 6,250,000 gross payroll
**WHEN** the monthly workload report is generated
**THEN** the report MUST display:
- Total ABP employer premium: EUR 1,406,640 (22.5% × EUR 6,250,000)
- Participant count: 1,247
- ABP percentage of payroll: 22.5%
- Grouped by cost center with subtotals (e.g., HR department EUR 40,000, Finance EUR 55,000, etc.)
- All figures auditable down to individual participant premium

### REQ-010-002: Annual report with segmentation by scheme and VPL cohort

**GIVEN** the year-end 2026 close
**WHEN** the annual workload report is generated
**THEN** the report MUST include:
- Breakdown by `regeling-code` (AP, KP, OP, VP, etc.): participant counts and premiums per scheme
- Segmentation: VPL-cohort vs. non-VPL participants, showing VPL-bedrag totals
- Total 2026 employer premium and percentage of annual gross payroll
- Comparison to prior year (if available)

### REQ-010-003: Audit-level detail export

**GIVEN** a compliance audit requesting verification of a specific month's ABP premiums
**WHEN** the controller downloads the monthly report as XLSX
**THEN** the XLSX MUST include:
- One row per participant
- Columns: Name, deelnemeringsnummer, cost center, department, pensioengevend-loon, voltijdsfactor, premiegrondslag, werkgeverspremie, werknemerspremie
- Each figure traceable to the underlying upa-record and payroll period
- Sortable/filterable by cost center, deelnemeringsnummer, loon range

---

## Acceptance Criteria Summary

All ten REQ-XXX requirements MUST achieve:

1. **Data Integrity**: No ABP-mandatory field omitted or incorrectly calculated.
2. **Regulatory Compliance**: UPA batches pass ABP's XSD validation; five-day Adieu deadline never missed.
3. **Audit Auditability**: All corrections, partner changes, and premium adjustments logged with before/after snapshots.
4. **User Visibility**: Salarisadministrateur can filter and correct Rejects within one working day of receipt.
5. **Integration**: All ABP SFTP/REST operations via OpenConnector; no custom ABP API code in HRMQ core.
6. **Performance**: Monthly UPA generation for 5,000 participants completes within 5 minutes; report generation within 2 minutes.
