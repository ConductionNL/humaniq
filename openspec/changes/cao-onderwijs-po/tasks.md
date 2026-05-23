---
status: tasks
created: 2026-05-23
---

# CAO Primair Onderwijs — Implementation Tasks

## Phase 1: Core Data Model & Validation

- [ ] **Create CaoPoEmployment entity** — extends Employment with salarisschaal, salarisnummer, functiecategorie, werktijdfactor, schoolsoort, bevoegdheidsstatus, lerarenregisterId, vervangingsregime, opleidingstrajectId. Add repository and migrations.
- [ ] **Implement salary-scale validation rules** — enforce L11 restriction to BaO+groepsleerkracht/specialist, prevent LA/LB/LC for non-teacher categories, validate OOP scales for onderwijsondersteunend only, suggest alternatives on constraint violation.
- [ ] **Create OnderwijsCaoSalarisTabel reference-data entity** — load L11/LB/LC/OOP/DIR tables from CAO 2024-2025, populate with monthly/annual salary amounts per trede, add filtering by schoolsoort and functiecategorie.
- [ ] **Implement werktijdfactor calculation engine** — convert annual hours → wtf decimal (hours / 1659), validate 0.0 < wtf ≤ 1.0, scale monthly salary pro-rata (salary × wtf), scale annual leave/DUSP entitlements.
- [ ] **Create NormjaartaakConfiguratie reference data** — set default 1,659 fulltime hours with 940 lesgebonden / 719 niet-lesgebonden breakdown, allow per-schoolyear override, add migration for CAO-agreed changes.
- [ ] **Implement wtf-mutation processing** — accept wtf change events, recalculate salary effective-date, trigger leave-balance adjustments, mark roster for re-planning, log all changes to audit trail.

## Phase 2: Lerarenregister Integration

- [ ] **Create DUO-lerarenregister API client** — implement OAuth-based authentication, query teacher by registerId and return status (active/verlopen/ingetrokken/in-opleiding), cache responses with configurable TTL (recommend 24 hours).
- [ ] **Implement lerarenregister-validation rule** — enforce mandatory lerarenregisterId for bevoegdheidsstatus="bevoegd", check DUO API at contract-creation and monthly-sync phases, raise LerarenregisterIdRequiredException with helpful message.
- [ ] **Create monthly DUO-synchronization job** — query all active bevoegde employees, compare local registerId status vs. DUO current status, detect status-changes (active → verlopen/ingetrokken).
- [ ] **Implement status-change alert generation** — on revocation/expiration, generate LerarenregisterStatusInvalid warning event, notify HR-administrateur with 30-day remediation window via email + in-app notification.
- [ ] **Implement auto-transition to niet-bevoegd** — if remediation deadline passes, automatically transition bevoegdheidsstatus → "niet-bevoegd-met-toestemming", recalculate salary per CAO art. 5.4, log change with reason "Lerarenregister expired/revoked".
- [ ] **Add LIO/ZIO exemption logic** — skip lerarenregisterId requirement for in-opleiding/zij-instromer status, flag for mandatory registration upon successful traject completion.

## Phase 3: DUSP Budget Management

- [ ] **Create DuspBudget entity** — accruedHours, remainingHours, spendingCategories (structuurWerktijdverkorting, studieverof, coachingBudget, ikbUitruil), duspStatus, audit trail.
- [ ] **Create DuspBudgetStaffel reference data** — define age-cohorts (57-66 = 170 hours, 67+ = 255 hours), IKB EUR-equivalent (e.g., €25/hour), allow per-schoolyear and CAO-agreement overrides.
- [ ] **Implement DUSP-eligibility check** — trigger on employment creation/update: if age ≥ 57, create DuspBudget record with accrual-date set to birthday or effective-date (whichever is later).
- [ ] **Implement pro-rata accrual for partial school-year** — if employee turns 57 mid-school-year, accrue months-remaining/12 × 170 hours (e.g., Dec → Jul = 8/12 × 170 = 113 hours).
- [ ] **Implement DUSP-spending acceptance** — accept choice of spendingCategory and amount, validate remaining-balance, update spendingCategories and remainingHours atomically.
- [ ] **Implement work-time-reduction workflow** — for structuurWerktijdverkorting spending, calculate lesgebonden-uren reduction (hours-spent / 1659 × current-lesgebonden), mark roster-assignment as "vervangingsnodig" for replacement-planning.
- [ ] **Implement study-leave workflow** — for studieverof spending, flag record for manager-approval in verlof-administratie system, upon approval mark the dates as study-leave on the roster.
- [ ] **Implement IKB cash-out workflow** — for ikbUitruil, calculate EUR-amount (hours × EUR-rate from staffel), generate one-time gross-payment event for next paycheck, apply payroll-tax withholding, update remainingHours.
- [ ] **Implement DUSP reset at school-year boundary** — on 2026-08-01 for each DUSP-eligible employee, if age still ≥ 57, reset remainingHours = accruedHours (new accrual), do NOT carry forward unspent hours (per CAO), log reset event.

## Phase 4: LIO/ZIO Training Trajectories

- [ ] **Create LIOZIOTraject entity** — trajectType (LIO/ZIO), startDate, endDate, salarisStelsel, garanteerdeOpleidingsuren, onderwijsuren, opleIdingsinstelling, einddatumBeoordeling, conversieStatus.
- [ ] **Create LIOZIOSalarisStaffel reference data** — define salary scales per traject-type and year (LIO-jaar-1/2/3, ZIO-jaar-1/2), populate with monthly-salary amounts lower than bevoegd-leraar scales.
- [ ] **Implement ZIO salary and hour allocation** — on traject creation, set salarisStelsel = "ZIO-jaar-1", onderwijsuren = 1,219 (for wtf 1.0), garanteerdeOpleidingsuren = 440, split recorded in LesgebondenUrenAllocatie.
- [ ] **Implement LIO year-dependent salary scaling** — on school-year boundary, check LIOZIOTraject.trajectStartDate and current date, determine year-in-traject (1/2/3), apply corresponding LIO-staffel salary scale.
- [ ] **Implement successful-completion workflow** — on conversieStatus → "completed-succesful" signal, convert employment to bevoegdheidsstatus = "bevoegd", auto-assign to corresponding bevoegd-scale (LB or L11 per schoolsoort), require lerarenregisterId from next paycheck.
- [ ] **Implement unsuccessful-completion workflow** — on conversieStatus → "completed-unsuccessful" signal, notify HR-administrateur with guidance, block automatic salary-reclassification, flag for manual intervention.
- [ ] **Implement paused-traject workflow** — accept pause-start and expected-resume dates, halt guaranteed-opleidingsuren accrual, generate reminder notification 2 weeks before resumeDate.
- [ ] **Implement hour allocation for traject-participation** — record guaranteed-opleidingsuren as non-reducible within normjaartaak (e.g., 440 hours protected even if lesgebonden-uren are reduced).

## Phase 5: Replacement Fund (VfPf) Claims

- [ ] **Create VervangingsClaim entity** — ziekmeldingId, vervangerId, schoolBestuurId, vervangingsperiode, brutoSalariskosten, wtfVervanger, claimStatus, vfpfReferentie, afwijzingReden, dateSubmitted, dateApproved, audit trail.
- [ ] **Implement claim-generation trigger** — on sick-leave notification (via verlof-administratie), if schoolBoard.vervangingsregime = "vervangingsfonds-aangesloten", automatically create VervangingsClaim with status = "gegenereerd".
- [ ] **Implement pro-rata salary calculation** — for claim, calculate brutoSalariskosten = (monthly-salary-of-sick-employee × working-days-in-absence / working-days-in-month) × wtf-of-vervanger.
- [ ] **Implement claim-submission workflow** — as 8-week deadline approaches, mark claim for submission, submit to VfPf via secure API (DigiD-authorized), set claimStatus = "ingediend", vfpfReferentie = unique-VfPf-claim-ID, dateSubmitted = today.
- [ ] **Implement claim-status polling** — daily query VfPf API for status updates on submitted claims, detect approval/rejection events, update claimStatus and corresponding fields.
- [ ] **Implement approval-reconciliation** — on claimStatus → "goedgekeurd", record dateApproved, mark claim for financial reporting, log approved-amount.
- [ ] **Implement rejection-handling** — on claimStatus → "afgewezen", populate afwijzingReden, notify HR-administrateur, generate budget-reconciliation adjustment (revert cost to school-board's payroll).
- [ ] **Implement self-funding fallback** — if schoolBoard.vervangingsregime = "eigen-risico-bestuur", skip VervangingsClaim creation entirely, all salariskosten remain within board's payroll.

## Phase 6: ABP Pension Registration

- [ ] **Create ABP-aansluiting validation rule** — on employment creation, require active ABP-registration effective on employment start-date, raise MissingAbpAffiliationException if missing.
- [ ] **Create AbpPremietabel reference data** — populate from CAO PO 2024-2025 agreement (OP-premie percentages, AAOP, ANW-hiaat, franchise amounts), allow per-schoolyear override.
- [ ] **Implement pensioen-grondslag calculation** — on each payroll, calculate (monthly-salary × wtf - ABP-franchise × wtf), use as basis for OP/AAOP/ANW premium withholding.
- [ ] **Implement ABP-OW (Onderwijs-Overgangsrecht) logic** — detect employment start-date, if pre-2006, flag for ABP-OW eligibility, store in employment record.
- [ ] **Implement ABP-OW pension-entitlement reporting** — when pension-overview is generated for pre-2006 hires, show two lines: (1) standard OP-aanspraak, (2) ABP-OW-overgangsrecht aanspraak with separate indicatieve-uitkeringsdatum.
- [ ] **Implement LIO/ZIO ABP requirements** — enforce ABP registration for LIO/ZIO employees from traject start-date, calculate pensioen-grondslag including guaranteed-opleidingsuren in the normjaartaak.

## Phase 7: Salary Increase Processing

- [ ] **Create CAO-akkoord management interface** — allow HR-beleidsmedewerker to load new CAO-agreement with loonsverhoging percentage, peildatum, and affected employment cohorts.
- [ ] **Implement salary-increase application job** — on peildatum, query all active employments, recalculate salary per loonsverhoging percentage, generate one-time supplementary payment for retroactive months (if any).
- [ ] **Implement retroactive-payback calculation** — if akkoord retroactively effective to month N but only announced/loaded in month M, calculate cumulative net difference for months N → M-1, include as supplementary gross in first paycheck after agreement-load.
- [ ] **Implement employment-status check** — before applying loonsverhoging, verify employment is active on peildatum (start-date ≤ peildatum AND end-date ≥ peildatum or null), skip employees whose employment ended before peildatum.
- [ ] **Implement cumulative-adjustment logic** — if trede-verhoging and CAO-loonsverhoging are both effective on same date, apply trede-increase first, then apply CAO-percentage to the new (post-trede) salary.
- [ ] **Implement historical-immutability protection** — once payroll is submitted for a month, do NOT retroactively alter already-paid amounts; instead, issue supplementary gross-payments in subsequent paychecks.

## Phase 8: Collective Agreement Leave

- [ ] **Create ConvenantsVerlofTegoed entity** — tegoedUren (pro-rata per wtf), benutteUren, restantUren, gekoppeldAanSchoolVakanties, statusPerDatum timeline.
- [ ] **Implement convenants-leave accrual** — on contract creation, set tegoedUren = 428 × wtf for the school-year, mark as coupled to school holidays.
- [ ] **Create school-holiday calendar integration** — import school-holiday schedule from Ministry of Education (published for each school-year), store as reference data.
- [ ] **Implement leave-balance management per school-holiday** — during each school-holiday period, automatically deduct proportional convenants-leave hours from tegoedUren, maintain restantUren balance.
- [ ] **Create SeniorenVerlofTegoed entity** — for legacy pre-2014 hires: tegoedUren (age-based, e.g., 170 for age 60), bapoAfstemmingsPercentage (35%), applicableOnlyIfNotUnderDusp flag.
- [ ] **Implement senioren-verlof accrual logic** — on school-year boundary, if employee hired pre-2014 and age 57-66, create SeniorenVerlofTegoed with 170 hours (if not already under DUSP).
- [ ] **Implement DUSP-vs-senioren-verlof mutual exclusivity** — if employee is under DUSP, prevent SeniorenVerlofTegoed creation/accrual, raise SeniorenVerlofNotApplicableException on request.
- [ ] **Implement bapo-afstemmings-deduction logic** — if senioren-verlof is used, deduct 35% of hours as net-salary offset (legacy accounting), record both gross and net impacts.

## Phase 9: Subsidiary Employment (Wsw & ID-baan)

- [ ] **Create Wsw-specific employment flag** — add isWswEmployment boolean to employment record, link to Wsw-loontabel (separate from OOP-scale).
- [ ] **Create Wsw-loontabel reference data** — populate from UWV-subsidized salary schedules, include minimum-net-level and UWV-subsidie component.
- [ ] **Implement Wsw salary calculation** — query Wsw-loontabel per function and trede, add UWV-subsidie-component to reach Wsw-minimum-level, record both school-board-paid and UWV-subsidized portions separately.
- [ ] **Create ID-baan employment flag** — add isIdBaan boolean and grandfathering check (employment start-date must be pre-2004).
- [ ] **Implement ID-baan hiring restriction** — on employment creation, if isIdBaan = true and employment start-date ≥ 2004, raise IdBaanNotAvailableForNewEmployeesException.
- [ ] **Create ID-loontabel reference data** — populate historic ID-salary scales, tag as legacy/frozen (no updates).
- [ ] **Implement UWV-subsidie reporting** — aggregate monthly UWV-subsidie-amounts for Wsw employees, generate monthly UWV-subsidie-rapport for reimbursement reconciliation.
- [ ] **Implement overgangsrecht-expiration monitoring** — on anniversary or life-event, flag HR-administrateur if Wsw/ID-baan employment approaches potential status-change or reorganization event.

## Phase 10: Integration & Testing

- [ ] **Integrate with payroll-engine-nl** — expose read-model API with CaoPoEmployment + reference-data (SalarisTabel, DuspStaffel, AbpPremietabel, VfPfPremietabel) for monthly salary calculation.
- [ ] **Integrate with schoolrooster-planning** — expose LesgebondenUrenAllocatie API for lesgebonden-uren validation and DUSP work-reduction roster-marking.
- [ ] **Integrate with verlof-administratie** — subscribe to and publish events (ConvenantsVerlofTegoedUpdated, DuspBudgetUpdated, SeniorenVerlofTegoedUpdated) for leave-balance synchronization.
- [ ] **Integrate with lerarenregister-koppeling** — consume monthly DUO-sync results, auto-transition bevoegdheidsstatus on status-change.
- [ ] **Integrate with vervangingsfonds-declaratie** — expose VervangingsClaim API for claim-generation and status-polling.
- [ ] **Integrate with contract-generatie** — expose salary-indication API for new hires, promotions, LIO/ZIO conversions, wtf-mutations.
- [ ] **Create comprehensive test suite** — unit tests for all validation rules, entity calculations, state transitions; integration tests for multi-system workflows (e.g., sick-leave → claim generation → VfPf submission); acceptance tests for end-to-end journeys (new hire, DUSP accrual, traject conversion).
- [ ] **Create test data fixtures** — seed databases with realistic employee cohorts (bevoegd, LIO, ZIO, OOP, Wsw, pre-2014 legacy) with corresponding salary/leave/DUSP/pension configurations.
- [ ] **Document API contracts** — OpenAPI/AsyncAPI specs for all exposed endpoints (CaoPoEmployment, reference-data, claim-generation, status-polling).
- [ ] **Audit trail validation** — ensure all state-changes (salary, wtf, bevoegdheidsstatus, DUSP, leave, claim-status) are logged with timestamp, actor, before/after values.

## Phase 11: Documentation & Rollout

- [ ] **Create end-user documentation** — user guides for HR-administrateur (contract-creation, wtf-mutations, DUSP-approval, claim-submission), schoolleider (DUSP work-reduction approval), salarisadministrateur (payroll workflows).
- [ ] **Create CAO-compliance documentation** — mapping of each REQ to the canonical CAO PO article/clause, audit trail for compliance traceability.
- [ ] **Create system-architecture documentation** — entity relationship diagrams, data-flow diagrams (employment lifecycle, DUSP accrual, claim generation, salary calculation), integration points.
- [ ] **Create reference-data-management guide** — how to load new CAO-agreement salary tables, DUSP-staffels, ABP-premietabels, school-holiday calendars.
- [ ] **Prepare rollout plan** — phased deployment per school-board cluster, data-migration strategy for legacy employment records (if system is replacing prior system), communication timeline for stakeholders.
- [ ] **Conduct UAT sessions** — with pilot school boards (mix of large/small, Wsw/ID-baan inclusion if applicable) to validate workflows under realistic conditions.
- [ ] **Create support-escalation guide** — common issues (invalid lerarenregisterId, DUSP-accrual edge cases, claim-rejection handling) with resolution steps.
