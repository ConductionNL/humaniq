---
status: specs
created: 2026-05-23
---

# CAO Primair Onderwijs — Specifications

## REQ-001-SalarisInschaling: Salary Scale Validation (L11/LB/LC/OOP/DIR)

The module SHALL validate salarisinschaling consistency between `salarisschaal`, `functiecategorie`, `bevoegdheidsstatus`, and `schoolsoort`, and prevent invalid combinations with suggested alternatives.

### REQ-001-001: L11 restricted to groepsleerkracht/specialist in BaO

**GIVEN** a new employment contract as groepsleerkracht in BaO with salary scale L11, trede 5, bevoegdheidsstatus "bevoegd", wtf 1.0, and effective date 2026-08-15  
**WHEN** the salary scaling validation runs  
**THEN** the validation succeeds, the monthly salary is derived from the L11-trede-5 table (€2,950), and the record is created with no errors.

### REQ-001-002: L11 forbidden in SbO/SO/VSO (special education)

**GIVEN** an attempt to enroll a groepsleerkracht in SO with salary scale L11  
**WHEN** the validation runs  
**THEN** a `SchaalNotApplicableForSchoolsoortException` is raised with message "L11 is not applicable for schoolsoort SO; use LB or LC instead" and the contract creation is aborted.

### REQ-001-003: LA/LB/LC forbidden for non-teacher function categories

**GIVEN** an attempt to enroll an onderwijsassistent with salary scale LA  
**WHEN** the validation runs  
**THEN** a `SchaalNotApplicableForFunctiecategorieException` is raised with message "LA is only valid for groepsleerkracht; suggested scales for onderwijsassistent: OOP-4, OOP-5, OOP-6, OOP-7" and the contract is not created.

### REQ-001-004: OOP scales apply only to onderwijsondersteunend personeel

**GIVEN** a new employment for leraarondersteuner with OOP-6 salary scale, trede 6, wtf 0.5, effective date 2026-10-01  
**WHEN** the validation and salary calculation run  
**THEN** the validation passes, the monthly salary is 0.5 × €2,050 = €1,025 (from OOP tabel), and the record is created.

### REQ-001-005: Salary scale mutation triggers salary recalculation

**GIVEN** an existing employee with L11-trede-4 (€2,875/month) being promoted to LC-trede-1 effective 2026-04-01  
**WHEN** the scale-change event is processed  
**THEN** the new salary is recalculated to the LC-trede-1 amount, retroactive adjustments are calculated for April payroll, and the change is logged in the audit trail.

---

## REQ-002-Werktijdfactor: Working Time Factor Calculation (1659-hour normjaartaak)

The module SHALL express werktijdfactor as a decimal quotient over the normjaartaak of 1,659 hours and scale all salary and leave components pro-rata. The module SHALL support wtf mutations with immediate payroll and leave impact.

### REQ-002-001: wtf decimal derivation from annual hours

**GIVEN** a leerkracht with agreed annual hours of 829.5 hours per school year  
**WHEN** the werktijdfactor is calculated  
**THEN** wtf = 829.5 / 1659 = 0.5, and the monthly salary is set to 0.5 × the full-time equivalent of the applicable scale-trede.

### REQ-002-002: wtf mutation (0.6 → 0.8) with payroll and leave adjustments

**GIVEN** an employee with wtf 0.6, monthly salary €1,770 (0.6 × €2,950), DUSP accrual 102 hours/year, convenants-leave 256.8 hours/year, effective change to wtf 0.8 per 2026-01-01  
**WHEN** the wtf-mutation is processed  
**THEN** the new monthly salary becomes 0.8 × €2,950 = €2,360 (retroactive adjustment for January), the DUSP budget is recalculated to 136 hours/year, convenants-leave becomes 342.4 hours/year, and the lesgebonden-uren allocation is marked for roster re-planning.

### REQ-002-003: wtf > 1.0 rejected with meerwerk alternative

**GIVEN** an attempt to set wtf to 1.05 (overtime)  
**WHEN** the validation runs  
**THEN** a `WtfBoundaryException` is raised with message "wtf cannot exceed 1.0; use MeerWerkClaim for overtime hours" and the contract is not created.

### REQ-002-004: Zero wtf and one-off work rejected

**GIVEN** an attempt to create a contract with wtf = 0.0  
**WHEN** the validation runs  
**THEN** a `WtfBoundaryException` is raised with message "wtf must be > 0.0; use separate one-off assignment for zero-hour roles" and the contract is not created.

### REQ-002-005: Pro-rata scaling of salary, DUSP, and leave

**GIVEN** an employee with wtf 0.75 and an active school year 2026-2027  
**WHEN** salary and leave entitlements are calculated  
**THEN** monthly salary = 0.75 × scale-trede amount, DUSP accrual = 127.5 hours (0.75 × 170), convenants-leave = 321 hours (0.75 × 428), and lesgebonden-uren = 705 (0.75 × 940).

---

## REQ-003-LerarenregisterKoppeling: Mandatory Teacher Registration (DUO)

The module SHALL enforce mandatory lerarenregisterId for bevoegde leerkrachten, synchronize registration status monthly, and auto-transition status for compliance failures.

### REQ-003-001: lerarenregisterId required for bevoegd status

**GIVEN** a new contract with bevoegdheidsstatus = "bevoegd" and no lerarenregisterId  
**WHEN** the contract is submitted for creation  
**THEN** a `LerarenregisterIdRequiredException` is raised with message "lerarenregisterId is mandatory for bevoegdheidsstatus=bevoegd" and the contract is rejected.

### REQ-003-002: Valid lerarenregisterId in DUO register

**GIVEN** a contract with bevoegdheidsstatus = "bevoegd" and lerarenregisterId = "DUO-PO-45823-2024" (valid in DUO for PO, groepsleerkracht, BaO)  
**WHEN** the monthly DUO synchronization runs  
**THEN** the registration status is confirmed as active, no alert is raised, and the employee record remains unchanged.

### REQ-003-003: Expired/revoked registration generates alert and transition

**GIVEN** an employee with previously-valid lerarenregisterId = "DUO-PO-31456-2019" now marked as "ingetrokken" (revoked) in the DUO register  
**WHEN** the monthly synchronization detects the revocation  
**THEN** a warning event `LerarenregisterStatusInvalid` is generated, the HR-administrateur is notified with a 30-day remediation window, and if the issue is not resolved by the deadline, the bevoegdheidsstatus is automatically transitioned to "niet-bevoegd-met-toestemming" with corresponding salary recalculation per CAO article 5.4.

### REQ-003-004: ZIO trainees exempt from bevoegdheidsstatus requirement

**GIVEN** a contract with bevoegdheidsstatus = "zij-instromer-ZIO", no lerarenregisterId, and an active LIOZIOTraject  
**WHEN** the validation runs  
**THEN** the validation passes without requiring a lerarenregisterId, and the employee is flagged for mandatory registration upon successful traject completion.

### REQ-003-005: LIO year-2/3 transition validation

**GIVEN** an LIO-werknemer in year-2 with pending final exam (einddatum-beoordeling 2026-06-30) and active lerarenregisterId as "in-opleiding"  
**WHEN** the exam date arrives  
**THEN** the system expects a conversion-status update (completed-succesful or completed-unsuccessful) and regenerates the lerarenregisterId requirement check.

---

## REQ-004-DuspBudget: DUSP Budget Accrual and Spending (57+)

The module SHALL accrue and manage DUSP budgets for employees 57+ with accrual of 170 hours/year (wtf 1.0, age 57-66) and 255 hours/year (age 67+), and support spending for work-time reduction, study leave, coaching, or IKB cash equivalent.

### REQ-004-001: DUSP accrual at age 57

**GIVEN** a fulltime leerkracht born 1969-09-01, reaching age 57 on 2026-09-01  
**WHEN** the DUSP-accrual process runs for school year 2026-2027  
**THEN** a DuspBudget record is created with accruedHours = 170, remainingHours = 170, effectiveDate = 2026-09-01, and duspStatus = "active".

### REQ-004-002: DUSP accrual pro-rata for partial school year

**GIVEN** a fulltime leerkracht reaching age 57 on 2026-12-01 (mid-school-year), entering school year 2026-2027  
**WHEN** the DUSP-accrual process calculates pro-rata for the remaining school-year months (Dec-Jul = 8 months / 12 months)  
**THEN** accruedHours = (170 × 8/12) = 113.33 hours (rounded to 113), effectiveDate = 2026-12-01.

### REQ-004-003: DUSP spending for work-time reduction

**GIVEN** a DUSP-eligible employee choosing to spend 80 hours on structuurWerktijdverkorting for school year 2026-2027  
**WHEN** the choice is processed  
**THEN** the DuspBudget.spendingCategories.structuurWerktijdverkorting is set to 80, remainingHours = 170 - 80 = 90, the lesgebonden-uren in the roster are proportionally reduced (80 / 1659 = 0.048 = 4.8% reduction), and the roster is marked for replacement-planning on those hours.

### REQ-004-004: DUSP spending for coaching/study leave

**GIVEN** an employee with 170 DUSP hours choosing to allocate 60 hours to studieverof  
**WHEN** the choice is registered  
**THEN** spendingCategories.studieverof = 60, remainingHours = 110, and a study-leave request is flagged for manager approval in the verlof-administratie system.

### REQ-004-005: DUSP IKB cash-out

**GIVEN** an employee with 50 remaining DUSP hours choosing IKB-equivalent cash-out at €25/hour (from DUSP-staffel)  
**WHEN** the choice is processed  
**THEN** spendingCategories.ikbUitruil = 50 EUR, a one-time bruto payment of 50 × €25 = €1,250 is generated for the next paycheck with proper payroll-tax withholding, remainingHours = 120, and the choice is logged in the audit trail.

### REQ-004-006: DUSP exhaustion and replenishment

**GIVEN** an employee with DUSP duspStatus = "active", remainingHours = 5 (near-exhausted) at the end of school year 2025-2026  
**WHEN** the new school year 2026-2027 begins and the employee is still age 57+  
**THEN** remainingHours is reset to 170 (fresh accrual), previous year's unspent hours are not carried forward (per CAO), and duspStatus remains "active".

---

## REQ-005-LIOZIOTraject: Training Trajectory Salary and Hours

The module SHALL apply specialized salary schedules for LIO and ZIO trajectories, guarantee training hours within the normjaartaak, and auto-convert to bevoegd-leraar regime upon successful completion.

### REQ-005-001: ZIO salary scaling and hour split

**GIVEN** a new ZIO-employment effective 2026-08-01, 2-year trajectory ending 2028-07-31, wtf 1.0  
**WHEN** the salary inschaling and hour allocation run  
**THEN** salary scale = LB-trede-2 (the ZIO-start scale), monthly salary is calculated from LB-trede-2 table, onderwijsuren = 1,219, garanteerdeOpleidingsuren = 440, totaal 1,659 hours, and the hour split is registered in LesgebondenUrenAllocatie.

### REQ-005-002: LIO year-dependent salary scaling

**GIVEN** a LIO-werknemer in year-2 of a 3-year PABO trajectory, wtf 0.6  
**WHEN** the salary for school year 2025-2026 is calculated  
**THEN** the salary scale is applied from the LIO-jaar-2 staffel (lower than full-time groepsleerkracht), monthly salary = 0.6 × LIO-jaar-2-trede-applicable-bedrag, and opleidingsuur allocation = 440 × 0.6 = 264 hours.

### REQ-005-003: Successful trajectory completion and conversion

**GIVEN** a LIO-werknemer with trajectType = "LIO", trajectEndDate = 2027-06-30, conversieStatus = "in-progress", currently at wtf 0.7, and receiving a successful completion signal (einddatum-beoordeling passed)  
**WHEN** the conversion process runs on 2027-07-01  
**THEN** conversieStatus = "completed-succesful", the employment is converted to bevoegdheidsstatus = "bevoegd", salary scale is stepped to the corresponding bevoegd-groepsleerkracht level (LB or L11), opleidingstrajectId is cleared, guaranteed-opleidingsuren are removed from the normjaartaak, and lerarenregisterId becomes required for the next paycheck.

### REQ-005-004: Unsuccessful trajectory completion

**GIVEN** a ZIO-werknemer with einddatum-beoordeling = 2027-06-30 and a signal of unsuccessful completion (beoordeling niet-voldoende)  
**WHEN** the conversion process runs  
**THEN** conversieStatus = "completed-unsuccessful", the HR-administrateur is notified with guidance for next steps (e.g., contract termination or alternative role placement), and the system blocks automatic salary-reclassification.

### REQ-005-005: Paused trajectories

**GIVEN** a ZIO-werknemer requesting a pause in the traject (e.g., maternity leave, secondment)  
**WHEN** the pause request is registered with a pause-startDate and expected-resumeDate  
**THEN** conversieStatus = "paused", the guarantee-opleidingsuren-accrual is temporarily stopped, and the system flags the HRB-administrateur with a reminder 2 weeks before the resumeDate.

---

## REQ-006-VervangingsClaim: Replacement Fund (VfPf) Claim Generation

The module SHALL automatically generate VfPf-claims for sick-leave substitutions at enrolled school boards, calculate pro-rata costs, manage claim status, and apply self-funding fallback for non-enrolled boards.

### REQ-006-001: Automatic claim generation for VfPf-enrolled boards

**GIVEN** a sick-leave notification for leerkracht A (fulltime, L11-trede-3 = €2,800/month) on 2026-11-10 at a school board with vervangingsregime = "vervangingsfonds-aangesloten", and invalkracht B hired as vervanger from 2026-11-11 to 2026-12-15 with wtf 0.6  
**WHEN** the claim-generation process runs  
**THEN** a VervangingsClaim is created with:
  - vervangingsperiode: 2026-11-11 to 2026-12-15 (35 days)
  - brutoSalariskosten = (€2,800 × 35 days / 21 working-days/month) × 0.6 wtf = pro-rata amount
  - claimStatus = "gegenereerd"
  - the claim is marked for submission to VfPf within the 8-week window

### REQ-006-002: Fallback to self-funding for eigen-risico-bestuur

**GIVEN** a sick-leave event for an employee at a school board with vervangingsregime = "eigen-risico-bestuur"  
**WHEN** the claim-generation process runs  
**THEN** no VervangingsClaim record is created, the salariskosten of the vervanger or the sick employee's continued salary remains entirely within the school board's budget, and no external VfPf interaction occurs.

### REQ-006-003: Claim submission to VfPf

**GIVEN** a VervangingsClaim with claimStatus = "gegenereerd" and submitDate approaching the 8-week deadline  
**WHEN** the submission process runs  
**THEN** claimStatus = "ingediend", vfpfReferentie is populated with a unique claim ID from the VfPf system, and dateSubmitted is recorded.

### REQ-006-004: Claim rejection handling

**GIVEN** a VervangingsClaim with claimStatus = "ingediend" and a notification from VfPf that the claim is rejected (e.g., vervanger failed to meet bevoegdheidseisen)  
**WHEN** the rejection is processed  
**THEN** claimStatus = "afgewezen", afwijzingReden = "Vervanger does not meet qualification requirements", the HR-administrateur is notified, and the financial liability reverts to the school board's budget (reconciliation adjustment in payroll).

### REQ-006-005: Claim approval and budget reconciliation

**GIVEN** a VervangingsClaim with claimStatus = "ingediend" and an approval notification from VfPf  
**WHEN** the approval is processed  
**THEN** claimStatus = "goedgekeurd", dateApproved is recorded, and the approved amount is tracked for financial reporting.

---

## REQ-007-ABPAansluiting: Mandatory Pension (ABP) Registration

The module SHALL enforce ABP registration for all education employees from the first employment day, calculate OP/AAOP/ANW premiums per CAO percentages, and manage ABP-OW transition rights.

### REQ-007-001: Mandatory ABP on first employment day

**GIVEN** a new LIO-aanstelling per 2026-08-15 with salary scale LB-trede-2  
**WHEN** the august payroll runs  
**THEN** ABP registration is activated with ingangsdatum = 2026-08-15, pensioen-grondslag is calculated over (salaris × wtf - ABP-franchise × wtf), and the OP-premie is withheld per CAO PO percentages (e.g., 8.5% werkgever + 8.5% werknemer).

### REQ-007-002: Rejection of employment without ABP

**GIVEN** an attempt to create an employment contract without an active ABP-aansluiting record  
**WHEN** the validation runs  
**THEN** a `MissingAbpAffiliationException` is raised with message "ABP registration is mandatory for all education employment; this system does not support non-ABP arrangements" and the contract is not created.

### REQ-007-003: ABP-OW (Onderwijs-Overgangsrecht) for pre-2006 hires

**GIVEN** an employee with employment start-date 1998-09-01 (pre-2006, eligible for ABP-OW) transitioning into early-retirement (FPU) per 2026-10-01  
**WHEN** the pension-overview is generated  
**THEN** two pension-entitlement lines are shown:
  1. Regular OP-aanspraak (standard occupation pension)
  2. ABP-OW-overgangsrechtelijke aanspraak (transition-rights from pre-2006 regime with potentially higher accrual)
  - both with indicatieve uitkeringsdatum projected

### REQ-007-004: Premium calculations per CAO-agreement

**GIVEN** an employee with monthly salary €2,950, wtf 1.0, and effective ABP-premietabel per 2024-2025 CAO-akkoord (OP 8.5% + AAOP + ANW, franchise €1,250/month)  
**WHEN** the August 2026 payroll runs  
**THEN** pensioen-grondslag = (€2,950 - €1,250) × 1.0 = €1,700, OP-premie-werkgever = €1,700 × 8.5% = €144.50, OP-premie-werknemer = €1,700 × 8.5% = €144.50, AAOP and ANW withheld per table, and all amounts appear on the loonstrook (payslip).

---

## REQ-008-LoonsverhogingStappen: Salary Increase Steps per CAO Agreement

The module SHALL apply contractual salary increases on CAO-agreed effective dates with retroactive payback calculation and handle concurrent trede-verhogingen cumulatively.

### REQ-008-001: CAO-wide salary increase with retroactive payback

**GIVEN** a CAO-akkoord effective 2026-04-01 with loonsverhoging 3.5 percent, and an employee with active employment on 2026-04-01  
**WHEN** the akkoord is loaded into the table-management module  
**THEN** for the April 2026 payslip:
  - the salary is recalculated 3.5% higher
  - retroactive payback for previous months (if akkoord was announced late) is calculated and included as a supplementary payment in April
  - the change is logged with effectiveDate = 2026-04-01

### REQ-008-002: No retroactive for employment ending before peildatum

**GIVEN** an employee with contract end-date 2026-03-31 (one day before the 3.5% CAO-increase on 2026-04-01)  
**WHEN** the loonsverhoging-process runs  
**THEN** this employee is excluded from the salary recalculation (contract no longer active on the peildatum), and no retroactive payment is generated.

### REQ-008-003: Cumulative trede-verhoging and CAO-loonsverhoging

**GIVEN** an employee with normal trede-verhoging scheduled for 2026-08-01 (summer step-increase, trede 3 → trede 4) and a CAO-loonsverhoging also effective 2026-08-01 per 2.8 percent  
**WHEN** the August 2026 salary is calculated  
**THEN** both adjustments are applied cumulatively:
  1. First the trede-verhoging is applied (new monthly amount = trede-4 salary)
  2. Then the 2.8% CAO-increase is applied to the new trede-4 amount
  - total percentage increase is > 2.8% (trede-jump + CAO combined)
  - the payslip shows both components separately in the "wijzigingen" section

### REQ-008-004: Historical data immutability

**GIVEN** a salary increase processed and monthly payrolls already paid for months prior to the peildatum  
**WHEN** an audit review occurs  
**THEN** the system does not retroactively alter previously-submitted payroll records; instead, retroactive payments are shown as supplementary gross amounts in the next paycheck post-agreement.

---

## REQ-009-ConvenantsVerlofEnSenioren: Collective Agreement Leave

The module SHALL administer convenantsverlof (428 hours/year fulltime) coupled to school holidays and manage senioren-verlof for pre-2014 hires not under DUSP.

### REQ-009-001: Convenantsverlof accrual and school-holiday coupling

**GIVEN** a fulltime leerkracht in BaO for school year 2026-2027 with 12 weeks of published school holidays  
**WHEN** the convenantsverlof-tegoed is calculated  
**THEN** tegoedUren = 428 (428/1659 × 1659 for wtf 1.0), and the balance is automatically depleted during school holidays as follows:
  - each school-holiday week (5 working days) allocates the proportional hours from the convenantsverlof pool
  - nominale uren per school-holiday week (5 × 8 = 40 uren) are covered by convenantsverlof (428/52 = 8.23 uren/week average) plus supplementary non-work weeks

### REQ-009-002: Convenantsverlof pro-rata for part-time

**GIVEN** a part-time leerkracht with wtf 0.6 and the same school-holiday calendar  
**WHEN** the convenantsverlof is calculated  
**THEN** tegoedUren = 428 × 0.6 = 256.8 hours (rounded to 257), allocated per proportional school-holiday periods.

### REQ-009-003: Senioren-verlof for pre-2014 hires (legacy overgangsrecht)

**GIVEN** an employee with employment start-date 2010-09-01 (pre-2014), age 60 in school year 2026-2027, not under DUSP  
**WHEN** the senioren-verlof-aanspraak is calculated  
**THEN** an additional SeniorenVerlofTegoed is created with:
  - tegoedUren = 170 (age 60 cohort per old BAPO regime)
  - bapoAfstemmingsPercentage = 35% (legacy rule: 35% of hours are deducted from net salary as offset)
  - status = active for this school-year only (subject to manual renewal if not converted to DUSP)

### REQ-009-004: Rejection of senioren-verlof for DUSP-eligible employees

**GIVEN** an employee with employment start-date 2010-09-01, age 57+, and already enrolled in DUSP  
**WHEN** a request for senioren-verlof is submitted  
**THEN** a `SeniorenVerlofNotApplicableException` is raised with message "This employee is under DUSP regime; use DUSP-budget for senior entitlements instead of legacy senioren-verlof" and the request is denied.

### REQ-009-005: School-holiday calendar integration

**GIVEN** the school-holiday calendar for 2026-2027 published by the Ministry of Education with 12 weeks total  
**WHEN** the convenantsverlof-balance is managed during the year  
**THEN** the system synchronizes with the school-holiday calendar (via schoolrooster-planning) and marks each holiday period, automatically allocating convenantsverlof hours to match the published schedule.

---

## REQ-010-SubsidieBanen: Wsw and ID-baan Transition Rights

The module SHALL support transition rights for remaining Wsw and ID-baan employees with UWV-subsidized salary tables and enforce hiring restrictions (no new ID-banen since 2004).

### REQ-010-001: Wsw salary table application

**GIVEN** a Wsw-werknemer in a schoonmaak-functie with salarisinschaling according to the Wsw-loontabel (not OOP-schaal) and wtf 0.5  
**WHEN** the monthly salary is calculated  
**THEN** the Wsw-loontabel is consulted (not the standard OOP-scale), the UWV-subsidie-amount is applied as a supplementary component to meet the Wsw-minimum-level, and the total salary (employee-paid + subsidy) is recorded with dual accounting (Wsw-eigenbijdrage for the school board).

### REQ-010-002: ID-baan overgangsrecht validation

**GIVEN** an ID-baan-werknemer with employment start-date 1998-04-01 (pre-2004), whose function has been grandfathered since the 2004 abolition of new ID-banen  
**WHEN** the monthly employment-verification runs  
**THEN** the system confirms the overgangsrecht status, applies the historic ID-loontabel, and tracks the rijkssubsidie-aanspraak for financial reporting.

### REQ-010-003: Rejection of new ID-banen

**GIVEN** an attempt to create a new employment with ID-baan designation for a werknemer with employment start-date 2026  
**WHEN** the validation runs  
**THEN** an `IdBaanNotAvailableForNewEmployeesException` is raised with message "ID-banen have been abolished for new employees since 2004; this employment cannot be designated as ID-baan" and the contract is rejected.

### REQ-010-004: UWV subsidy tracking and reporting

**GIVEN** a Wsw-werknemer generating a monthly salary with UWV-subsidie component (e.g., school pays €800, UWV subsidizes €300 to reach €1,100 total)  
**WHEN** the payroll completes  
**THEN** the UWV-subsidie-amount is tagged separately in the payslip and aggregated in the monthly UWV-subsidie-rapport for reimbursement reconciliation.

### REQ-010-005: Overgangsrecht expiration monitoring

**GIVEN** a Wsw-werknemer approaching a potential natural workforce-reduction or reorganization event  
**WHEN** the anniversary or life-event check runs  
**THEN** the system flags the HR-administrateur with guidance on whether the overgangsrecht status is still applicable and what transition-support measures may be required (e.g., retraining, re-assignment, or potential benefits termination per Wsw rules).
