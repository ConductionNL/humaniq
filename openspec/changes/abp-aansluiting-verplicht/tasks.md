---
status: draft
---

# Tasks: ABP Aansluiting Verplicht

## Implementation Roadmap

### Phase 1: Data Layer & Schemas

- [ ] Create OpenRegister `lib/Settings/abp_register.json` with schemas:
  - [ ] `DeelnemerRegistratie` schema (participant register)
  - [ ] `UpaRecord` schema (monthly UPA log)
  - [ ] `PensioenPartner` schema (partner registry)
  - [ ] `AdieuMelding` schema (exit meldingen)
  - [ ] `RetourBericht` schema (ABP return messages)
  - [ ] `VplSaldo` schema (VPL annual balance)
  - [ ] `AbpPremiePercentages` schema (tariff table)
  - [ ] Validate all schemas against ADR-001 standards (PascalCase, schema.org vocabulary, explicit types + required flags)

- [ ] Populate seed data in `abp_register.json`:
  - [ ] 3–5 DeelnemerRegistratie objects (various FTE, regeling-codes, VPL-eligible/non-eligible)
  - [ ] 3–5 UpaRecord objects (confirmed, draft, rejected statuses)
  - [ ] 2–3 PensioenPartner objects (marriage, registered partnership, notarial contract)
  - [ ] 1–2 RetourBericht objects (Confirmation, Reject examples)
  - [ ] 1–2 AdieuMelding objects (pending, confirmed)
  - [ ] 2–3 VplSaldo objects (various year-to-date amounts)
  - [ ] All objects use `@self` envelope per ADR-001

- [ ] Register import into ConfigurationService:
  - [ ] Add `abp_register.json` to `importFromApp()` pipeline in `SettingsLoadService`
  - [ ] Implement idempotent match-by-slug logic to prevent duplicate imports on re-install
  - [ ] Test import/re-import cycle (seed data must not duplicate)

### Phase 2: ABP Enrollment Determination (REQ-001)

- [ ] Create `DeelnemerEnrollmentService`:
  - [ ] Method `determineAbpEligibility(Employee $emp): bool` checks employer ABP status and function category
  - [ ] Method `createDeelnemerRegistratie(Employee $emp): DeelnemerRegistratie` creates OpenRegister object
  - [ ] Implement CAO-specific `franchise-adjusted-percentage` override logic (some CAO functions have partial ABP membership)
  - [ ] Unit tests: eligibility rules, override logic, inter-agency transfer (reuse existing deelnemeringsnummer)

- [ ] Hook into employee-master workflow:
  - [ ] Create event listener `EmployeeHiredListener` → triggers `determineAbpEligibility` on new hire
  - [ ] Store ABP enrollment decision in employee record (`pension-scheme` field)
  - [ ] Log enrollment decision (audit trail)

- [ ] Implement mixed-scheme employer logic:
  - [ ] If employer has both ABP and non-ABP functions, function category MUST determine scheme
  - [ ] Test scenario: zorginstelling with education department (education → ABP, non-education → PFZW)

### Phase 3: Premium Calculation & UPA Generation (REQ-002, REQ-003)

- [ ] Extend `payroll-engine-nl` premium calculation:
  - [ ] Method `calculateAbpFranchise(year: int, voltijdsfactor: float): decimal` returns pro-rata franchise
  - [ ] Method `calculateAbpVoltijdsfactor(uren: int): float` returns uren / 36 (not 40)
  - [ ] Method `calculateAbpPremieGrondslag(loon: decimal, franchise: decimal, fte: float): decimal`
  - [ ] Method `calculateAbpEmployerPremium(grondslag: decimal, year: int, regelingCode: string): decimal`
  - [ ] Method `calculateAbpEmployeePremium(grondslag: decimal, year: int, regelingCode: string): decimal`
  - [ ] Load tariff table from OpenRegister `AbpPremiePercentages` by year
  - [ ] Unit tests: franchise pro-rata, FTE conversion, tariff table lookup, year-boundary handling

- [ ] Create `UpaGeneratorService`:
  - [ ] Method `generateUpaRecords(Payroll $period): array` creates UpaRecord objects for all ABP participants
  - [ ] Populate all 15 ABP-specific fields per design.md
  - [ ] Write to OpenRegister via ObjectService
  - [ ] Method `generateUpaFile(array $records): SplFileInfo` exports to UPA 2026.01 XML format
  - [ ] Validate generated XML against UPA 2026.01-ABP XSD (fetch from ABP/Pensioenfederatie)
  - [ ] Unit tests: full-time, part-time, mixed-salary, franchise accuracy, XSD validation

- [ ] GL posting for payroll:
  - [ ] Hook `saveObject()` in ObjectService → post GL entries on UpaRecord creation/update:
    - GL 4030 (employer premium) via `boekhouding-export`
    - GL 1610 (employee premium to-pay) via `boekhouding-export`
  - [ ] Ensure GL codes and amounts appear on payroll-engine-nl payslip

### Phase 4: VPL Administration (REQ-004)

- [ ] Create `VplCohortService`:
  - [ ] Method `isVplEligible(birthDate: DateTime): bool` returns true if born 1950–1972
  - [ ] Method `calculateVplAmount(pensioenLoon: decimal, fte: float): decimal` returns loon × fte × 0.02
  - [ ] On `DeelnemerRegistratie` create: set `vpl-eligible` boolean based on birthdate (immutable)

- [ ] Integrate VPL accrual into UPA generation:
  - [ ] For each VPL-eligible participant, populate `vpl-bedrag` in UpaRecord
  - [ ] Monthly update to `VplSaldo` OpenRegister object (cumulative YTD)
  - [ ] Unit tests: cohort identification, monthly accrual, YTD rollup

- [ ] Create `VplYearEndService`:
  - [ ] Method `generateVplBijlage(year: int): UpaVplBijlage` creates VPL attachment for January batch
  - [ ] Queries all VPL-eligible participants with 2026 `VplSaldo` records
  - [ ] Exports as separate section in UPA file (per UPA 2026.01 VPL-bijlage format)
  - [ ] Integration test: January 2027 UPA generation includes 2026 VPL totals

### Phase 5: Keuzepensioen Flexibility (REQ-005)

- [ ] Create `KeuzepensioenMutationService`:
  - [ ] Method `recordExtraInleg(DeelnemerRegistratie $p, decimal $amount, DateTime $effectiveDate)` enqueues premium increase
  - [ ] Method `recordDeelpensioen(DeelnemerRegistratie $p, int $percentage, DateTime $effectiveDate)` updates `deelnemingspercentage`
  - [ ] Store pending mutations in queue (separate from immediate UPA generation)
  - [ ] Apply mutations on next payroll period

- [ ] Employee portal integration (in employee-self-service app):
  - [ ] Create form for extra-inleg election (EUR amount, effective date)
  - [ ] Create form for deelpensioen drawdown (% elected, start date)
  - [ ] Form submission triggers `KeuzepensioenMutationService`

- [ ] UPA mutation inclusion:
  - [ ] On UpaRecord generation, check for pending mutations
  - [ ] If mutation effective same month, include `kp-flexibilisering-saldo` field
  - [ ] If deelpensioen effective same month, include temporary pending flag
  - [ ] Unit tests: extra-inleg increase on payslip, deelpensioen flag in UPA, mutation queuing

### Phase 6: Pension Partner Registration (REQ-006)

- [ ] Create `PensioenPartnerService`:
  - [ ] Method `registerPartner(DeelnemerRegistratie $p, string $bsn, DateTime $samenwonings-datum): PensioenPartner`
  - [ ] Validate BSN using 11-proef algorithm
  - [ ] Create OpenRegister object with status "pending"
  - [ ] Enqueue background job `SubmitPartnerToAbpJob`

- [ ] ABP REST API integration via OpenConnector:
  - [ ] `abp-rest` connector: `POST /partners` (new registration)
  - [ ] `abp-rest` connector: `PUT /partners/{id}` (update)
  - [ ] `abp-rest` connector: `DELETE /partners/{id}` (termination)
  - [ ] Background job `SubmitPartnerToAbpJob`: retry 3× on API failure, exponential backoff
  - [ ] On successful API response, update PensioenPartner status → "registered" and set `registratie-datum-bij-abp`

- [ ] Partner termination workflow:
  - [ ] Method `terminatePartner(PensioenPartner $p, string $reasonCode, DateTime $endDate)`
  - [ ] Set `einddatum` and `reden-einde-code` (divorce, death, separation)
  - [ ] Enqueue `TerminatePartnerToAbpJob` (DELETE to API)
  - [ ] Send notification email to employee (Wet VPS divorce asset division info)

- [ ] Notarial contract handling:
  - [ ] PensioenPartner form includes file upload for contract scan
  - [ ] Store scan URL in `contract-scan-url` field
  - [ ] When submitting to ABP, include contract-bewijs-vlag and attach file
  - [ ] Unit tests: BSN validation, API submission, contract attachment, termination workflow

### Phase 7: Adieu (Exit) Melding (REQ-007)

- [ ] Create `AdieuMeldingService`:
  - [ ] Method `createAdieuMelding(DeelnemerRegistratie $p, DateTime $lastDay, int $reasonCode): AdieuMelding`
  - [ ] Method `submitAdieuMelding(AdieuMelding $melding): bool`
  - [ ] Validate reason-code from ABP's 14-code catalog
  - [ ] Enqueue background job `SendAdieuMeldingJob` with 24-hour delay

- [ ] Exit workflow integration:
  - [ ] Hook into employee-master exit procedure
  - [ ] Create AdieuMelding form in HRMQ (triggered on final-workday confirmation)
  - [ ] Form requires:
    - Reason code (dropdown with 14 codes + descriptions in Dutch)
    - `eind-pensioengevend-loon` (snapshot, pre-filled from latest payroll; editable)
    - Optional `pensioenopbouw-doorlopend-vlag` (checkbox if transfer within 30 days)

- [ ] Five-day deadline enforcement:
  - [ ] Background job `SendAdieuMeldingJob` scheduled 24 hours after melding creation
  - [ ] Salarisadministrateur receives notification: "Approve Adieu loon snapshot for deelnemer X, last-day 2026-08-31"
  - [ ] If approved before deadline, submit immediately
  - [ ] If not approved by day 3 post-last-workday, auto-submit (set `approval-status = auto-approved`)
  - [ ] Alert salarisadministrateur if deadline approaching (day 4 of 5)

- [ ] ABP submission via SFTP/REST:
  - [ ] Convert AdieuMelding to ABP Adieu-melding format
  - [ ] Submit via OpenConnector `abp-sftp` or `abp-rest` (REST preferred for individual meldingen)
  - [ ] On ABP Confirmation response, update status → "confirmed"
  - [ ] On ABP Reject (e.g., `ABP-ADIEU-LATE`), update status → "rejected" and notify salarisadministrateur
  - [ ] Unit tests: deadline calendar calculation, reason-code validation, continuous-pension flag logic

### Phase 8: Retour-Bericht Admin Queue (REQ-008)

- [ ] Create retour-bericht polling cron task `app:abp:poll-retour-berichten`:
  - [ ] Runs every 30 minutes
  - [ ] Connects to SFTP (sftp.abp.nl/werkgever) via OpenConnector `abp-sftp`
  - [ ] Fetches all files in `retour/` directory (CRS format)
  - [ ] Parse CRS XML into RetourBericht objects (type, linked-upa-id, fout-code, fout-omschrijving)
  - [ ] Write to OpenRegister `abp-retour` register via ObjectService
  - [ ] Mark processed files (move to `processed/` folder on SFTP)
  - [ ] Log any fetch/parse errors

- [ ] Create `RetourBerichtParserService`:
  - [ ] Parse CRS XML format into RetourBericht properties
  - [ ] Extract `type` (Confirmation, Reject, Waarschuwing, Vraag)
  - [ ] Extract `fout-code` from ABP's 247-code catalog
  - [ ] Link to original `upa-record` or `adieu-melding` by reference number
  - [ ] Unit tests: CRS parsing, field extraction, linking logic

- [ ] Admin UI for retour-berichten:
  - [ ] List view using `CnDataTable` + OpenRegister `abp-retour` schema
  - [ ] Filters:
    - Status (open, in-behandeling, opgelost, gesloten-niet-oplosbaar)
    - Type (Confirmation, Reject, Waarschuwing, Vraag)
    - Fout-code (searchable)
    - Age (>14 days old = warning)
  - [ ] Each row shows:
    - Participant name + deelnemeringsnummer
    - Linked UPA period or Adieu melding
    - Fout-omschrijving (Dutch)
    - Status badge
    - "Maak correctie" button (visible for Rejects only)

- [ ] Correction workflow:
  - [ ] "Maak correctie" button opens payroll correction form for participant + period
  - [ ] Salarisadministrateur edits loon, franchise, FTE, etc.
  - [ ] On save, re-generates UPA record with `correction-flag = true` and `original-upa-record-id`
  - [ ] New UPA submitted to ABP via SFTP/REST
  - [ ] On ABP Confirmation, automatically update original retour-bericht status → "gesloten-opgelost"

- [ ] Age-based alerting:
  - [ ] Dashboard banner if >0 Rejects older than 14 days
  - [ ] Filter option "Show only >14 days" in list view
  - [ ] Optional scheduled alert email (weekly summary of old Rejects)

### Phase 9: Data Correction Workflow (REQ-009)

- [ ] Create `PayrollCorrectionService`:
  - [ ] Method `applyTwkCorrection(DeelnemerRegistratie $p, DateTime $periodStart, DateTime $periodEnd, decimal $correctionAmount): array`
  - [ ] For each month in correction range, generate new UpaRecord with:
    - `correction-flag = true`
    - `original-upa-record-id = <prior month's UPA>`
    - Updated loon values
    - Recalculated premiums per correct tariff year
  - [ ] Return array of generated correction-UpaRecords
  - [ ] Unit tests: single-month correction, multi-month correction, year-boundary, negative grondslag detection

- [ ] Payroll UI integration:
  - [ ] Add "Terugwerkende-kracht Correctie" button/link in employee payroll history
  - [ ] Form fields:
    - Period (from-to month/year)
    - Reason (freetext, audit trail)
    - Correction type (loon increase, deelnemingspercentage, franchise override)
    - Amount/percentage
  - [ ] On submit:
    1. Calculate new premiums for all months in range
    2. Show preview of corrections (before/after per month)
    3. If negative grondslag detected, show warning dialog with "Toestaan" / "Annuleren" options
    4. If user approves, generate correction-UpaRecords and apply to current payslip (cumulative back-premiums)

- [ ] Correction submission to ABP:
  - [ ] Batch all correction-UpaRecords for a participant
  - [ ] Submit to ABP via SFTP/REST (same UPA generation as REQ-002)
  - [ ] Track correction status per month until ABP confirms each

- [ ] Audit trail:
  - [ ] AuditTrailService automatic logging (per ADR-001) of all correction-UpaRecord creates
  - [ ] Store reason, user, datetime, before/after values

### Phase 10: Workload Reporting (REQ-010)

- [ ] Create `AbpWorkloadReportService`:
  - [ ] Method `generateMonthlyReport(DateTime $month): MonthlyWorkloadReport`
  - [ ] Query all UpaRecords for month + organisation
  - [ ] Aggregate totals: participant count, total loon, total premie
  - [ ] Calculate ABP % of payroll
  - [ ] Group by cost-center (sub-totals per cost center)
  - [ ] Unit tests: aggregation logic, cost-center grouping, percentage calculation

- [ ] Create `AbpAnnualReportService`:
  - [ ] Method `generateAnnualReport(int $year): AnnualWorkloadReport`
  - [ ] Aggregate all months of year
  - [ ] Breakdown by `regeling-code` (AP, KP, OP, etc.) with counts + premiums
  - [ ] Segmentation: VPL-cohort vs. non-VPL, showing VPL-bedrag totals
  - [ ] Comparison to prior year (if available)
  - [ ] Unit tests: scheme-code grouping, VPL segmentation, year-over-year

- [ ] Report UI:
  - [ ] Create dashboard page (or report page) with date selectors (month/year)
  - [ ] Display KPI cards: total premium, participant count, % of payroll
  - [ ] Table view grouped by cost center (expandable)
  - [ ] Excel export button (XLSX format per REQ-010-003)

- [ ] Excel export (audit-level detail):
  - [ ] Method `exportToXlsx(ReportData $report): SplFileInfo`
  - [ ] Columns: Name, deelnemeringsnummer, cost center, department, pensioengevend-loon, voltijdsfactor, premiegrondslag, werkgeverspremie, werknemerspremie
  - [ ] One row per participant
  - [ ] Data sortable/filterable in Excel
  - [ ] Formula-driven calculations for audit verification
  - [ ] Unit tests: XLSX structure, column mapping, formula correctness

### Phase 11: Integration & Quality Gates

- [ ] ADR compliance audit:
  - [ ] Verify all ABP data entities stored in OpenRegister (not custom Eloquent models) ✓ ADR-001
  - [ ] Verify all schemas use PascalCase + schema.org vocabulary ✓ ADR-001
  - [ ] Run `hydra-gate-spdx` for @license + @copyright tags on all new PHP files
  - [ ] Run static analysis (PHPSTAN, Psalm) on all new backend code

- [ ] UPA XSD validation:
  - [ ] Test `UpaGeneratorService` against official UPA 2026.01-ABP schema
  - [ ] Verify all 15 ABP fields present and correctly formatted
  - [ ] Integration test: generate 5-participant UPA, validate XSD pass

- [ ] ABP sandbox/acceptance environment testing:
  - [ ] Deploy to ABP acceptance SFTP/API (if available)
  - [ ] Submit test UPA batch with known valid data
  - [ ] Verify ABP returns Confirmation (not Reject)
  - [ ] Test Adieu melding submission
  - [ ] Test partner registration via REST API

- [ ] Performance testing:
  - [ ] Generate UPA for 5,000 participants: target <5 minutes
  - [ ] Generate annual report for 50,000 transactions: target <2 minutes
  - [ ] Admin queue filtering with 10,000 retour-berichten: <2 seconds response
  - [ ] Profile and optimize if thresholds exceeded

- [ ] End-to-end scenario test:
  - [ ] Create test employee, enroll in ABP, generate UPA, submit to SFTP
  - [ ] Simulate ABP Confirmation response, verify status update
  - [ ] Update employee partner info, verify partner-registration API call
  - [ ] Process exit, verify Adieu melding submitted within 5-day window
  - [ ] Process correction, verify correction-UPA generated and corrected in admin queue

### Phase 12: Documentation & Training

- [ ] Write README section for ABP module:
  - [ ] Overview of ABP requirements and NL pension law references
  - [ ] Data model diagram (entities + relationships)
  - [ ] API endpoints (if any custom controllers; otherwise note OpenRegister-driven)
  - [ ] SFTP/API integration points (ABP endpoints, credentials management)
  - [ ] Configuration (tariff table import, premium percentages)
  - [ ] Troubleshooting guide (common errors, ABP fault codes reference)

- [ ] Create admin guide for salarisadministrateur:
  - [ ] How to generate monthly UPA batch
  - [ ] How to check for retour-berichten and handle rejections
  - [ ] How to approve Adieu meldingen before auto-send
  - [ ] How to process corrections (terugwerkende-kracht)
  - [ ] How to generate workload reports

- [ ] Update employee-portal documentation:
  - [ ] Particle number visibility
  - [ ] How to register/update pension partner
  - [ ] How to elect Keuzepensioen flexibility (extra-inleg, deelpensioen)

---

## Deduplication Check

Reviewed OpenRegister services and existing HRMQ modules:

- `ObjectService` (create/read/update/delete, search, relations) — fully leveraged for all ABP entities; NO custom CRUD built
- `ConfigurationService` (schema/register import, tariff tables) — used for `abp_register.json` and `AbpPremiePercentages` import
- `AuditTrailService` — automatic tracking of all changes (correction-UpaRecord creates, partner updates, etc.)
- `NotificationService` — alerts for Adieu approval window, retour-bericht corrections
- `FileService` — contract scans for partner registration
- Existing `payroll-engine-nl` premium framework — extended, not duplicated
- Existing `boekhouding-export` GL posting — reused for ABP premium GL codes (4030, 1610)

**Conclusion:** No existing capability is duplicated. All new code extends platform services per ADR-001; no custom entity/mapper/controller patterns introduced.

---

## Success Criteria

- [ ] All six ABP entities (DeelnemerRegistratie, UpaRecord, PensioenPartner, AdieuMelding, RetourBericht, VplSaldo) created and seeded
- [ ] Monthly UPA generation passes ABP XSD validation with real test data
- [ ] Premium calculations (22.5%/4.5%) verified against ABP manual calculation
- [ ] Five-day Adieu deadline never violated in integration test
- [ ] Retour-bericht polling and admin queue operational (manual or automated test)
- [ ] Correction workflow end-to-end: payroll error → rejection → correction → re-submission → confirmation
- [ ] Workload report generated for test month + year, auditable to participant-level detail
- [ ] All static analysis (PHPSTAN, Psalm, PHPCS) passing
- [ ] Zero custom entities (all OpenRegister); zero custom form components (all CnFormDialog)
- [ ] Documentation complete (README, admin guide, API integration)
