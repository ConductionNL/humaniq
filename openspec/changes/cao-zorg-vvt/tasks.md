---
status: draft
---

# Implementation Tasks: CAO VVT 2024-2026

## 1. Data Model & Schema

- [ ] 1.1 Create database migration: `CAO_VVT_Versie` table with columns: caoId, caoCode, versieNummer, ingangsdatum, einddatum, actiznBron, pfzwAansluitingVerplicht, werkurenMaximumPerWeek, ortVanToepassing, slaapdienstVergoedingTarief, bereidheidsVergoedingPerUur
- [ ] 1.2 Create database migration: `ORT_Regel` table with columns: ortId, caoVersieId, regelNaam, dagenVanDeWeek (array), tijdVan, tijdTot, ortPercentage, prioriteit, stapelbaarMet (array), uitsluitendBij (array), geldigVanaf, caoArtikel, feestdagenLijst (JSON)
- [ ] 1.3 Create database migration: `Shift` table extended with columns: shiftId, medewerkerId, datum, startTijd, eindTijd, shiftType, afdeling, functieTijdensShift, totaalUren, pauzeUren, betaaldeUren, ortBerekening (JSON array), totaalOrtBedrag, uurtarief, basisloon, totaalShiftLoon
- [ ] 1.4 Create database migration: `Bereidheidsdienst` table with columns: bereidheidsId, medewerkerId, startTijd, eindTijd, totaalUren, vergoedingPerUur, totaalVergoeding, oproepen (JSON array), totaalUitbetaling
- [ ] 1.5 Create database migration: `Slaapdienst` table with columns: slaapdienstId, medewerkerId, datum, startTijd, eindTijd, totaalUren, vasteVergoeding, ortOverActieveOproepen (boolean), actieveOproepen (JSON array), totaalUitbetaling
- [ ] 1.6 Create database migration: `Werkurenbewaking_ATW` table with columns: medewerkerId, weekNummer, geplandeUren, gewerkteUren, maximumCao, maximumAtw, consecutieveNachtdiensten, maximumConsecutieveNachten, rustperiodeNa12UurDienst, atwViolations (JSON), caoViolations (JSON)
- [ ] 1.7 Extend `Employee` table: add columns fwgGrade, fwgSalarisSchaal, periodiekeInschaling, pfzwAanmelding, pensioenuitvoerder (locked to "PFZW" for CAO VVT), wzdBevoegdheid (nullable), wzdExpiryDate, bigRegistratieNummer
- [ ] 1.8 Create database migration: `FWG_Schaal` table with columns: fwgGrade, fwgBeschrijving, periodiekenPerSchaal, effectiefVanaf, effectiefTot, schaalBedragen (JSON array with periodiek, year, bruto_maand)
- [ ] 1.9 Create database migration: `Loonsverhoging_CAO` table with columns: caoId, datum, percentage, geldigVanaf (for tracking wage-step history)
- [ ] 1.10 Create database migration: `Retroactieve_Loonrun` table with columns: retroactiveBatchId, caoId, periodeVan, periodeTot, effectieveDatum, status (draft/approved/processed), createdAt, processedAt

---

## 2. ORT Calculation Engine

- [ ] 2.1 Implement `OrtCalculationEngine` class with method `calculateORT(shift: Shift, ortRegels: ORT_Regel[]): OrtBerekening[]`
- [ ] 2.2 Implement time-decomposition algorithm: split shift into 1-minute (or configurable granularity) segments
- [ ] 2.3 Implement rule-matching logic: for each segment, find all applicable ORT rules based on day-of-week, time-of-day, holiday
- [ ] 2.4 Implement stackability resolution: when multiple rules apply to a segment, check `stapelbaarMet` rules; if allowed, sum percentages (cap per CAO); if conflict, use `prioriteit` to select highest
- [ ] 2.5 Implement feestdag-list lookup: check if shift date falls on a CAO-feestdag (Nieuwjaarsdag, Kerstdagen, etc.) and apply feestdag ORT rule
- [ ] 2.6 Implement time-boundary handling: correctly split ORT when shift crosses day-boundary or time-rule boundary (e.g., 22:00 → 02:00 crosses avond→nacht boundary at 00:00)
- [ ] 2.7 Write unit tests covering REQ-001 scenarios: evening only, night+feestdag stacking, Sunday (no evening-ORT), transitions
- [ ] 2.8 Write integration test: feed the design.md example shift (kerstavond 20:00→08:00 kerstdag) and verify output matches expected OrtBerekening rows

---

## 3. Wage Step Progression & FWG Scales

- [ ] 3.1 Load seed data: import FWG_Schaal table with grades 35–80 and salary steps from actiz.nl CAO-VVT-2024-2026 annex 6
- [ ] 3.2 Implement `FWGSalaryResolver` class with method `getSalaryBedrag(fwgGrade: string, periodiek: int, year: int): decimal`
- [ ] 3.3 Implement automatic periodiek progression: on each anniversary of hire-date, auto-advance to next periodiek (max = periodiekenPerSchaal)
- [ ] 3.4 Implement `retroactiveWageAdjustment()`: when CAO enters active status with future loonsverhogingen, mark all open loonruns for recalculation
- [ ] 3.5 Implement batch re-grading UI in HR module: when FWG grade is updated on a function-master, generate mutation-proposal for all active employees in that function
- [ ] 3.6 Write unit test: verify FWG 50 salary progression from periodiek 1→12 with year-on-year increases (3%, 2.5%, 2%)

---

## 4. Retroactive Pay Calculation & Batch Reprocessing

- [ ] 4.1 Implement `RetroactiveLoonrunEngine` class with method `processRetroactiveRun(retroactiveBatchId: UUID, periodeVan: Date, periodeTot: Date, effectieveDatum: Date): RetroactiveLoonrun`
- [ ] 4.2 Implement salary-history lookup: for each affected employee, retrieve all pay-data (salary, ORT, toeslagen) from the old loonrun
- [ ] 4.3 Implement delta calculation: (new bruto per period − old bruto per period) × affected periods = nabetaling
- [ ] 4.4 Implement SEPA routing: for employees who exited during the retroactive period, route nabetalingen to separate "Departed Employee" SEPA batch
- [ ] 4.5 Implement notification system: generate emails for each affected employee with explanatory letter + amended loonstroken
- [ ] 4.6 Implement audit-log: each retroactive loonrun is tagged with retroactive_batch_id; all loonstroken in the batch are marked "amended" and regenerated with corrected amounts
- [ ] 4.7 Write integration test: simulate REQ-002 scenario (CAO ratified April 2024, effective Jan 2024; 3% increase; verify nabetaling = delta × 4 months)

---

## 5. Bereidheidsdienst (Standby) Registry & Oproep Tracking

- [ ] 5.1 Implement `BereidheidsDienstService` with methods:
  - `createBereidheidsDienst(medewerkerId, startTijd, eindTijd): Bereidheidsdienst`
  - `addOproep(bereidheidsId, startTijd, endTijd, reistijdHeen, reistijdTerug): Oproep`
  - `calculateTotalCompensation(): decimal`
- [ ] 5.2 Implement dual-rate calculation: base fee (totaalUren × vergoedingPerUur) + active-work compensation
- [ ] 5.3 Implement ORT application to active-work: when oproep is logged, apply relevant ORT rule (nacht, etc.) to the active-work hours
- [ ] 5.4 Implement travel-time inclusion: when reistijd is specified, add it to compensated hours (not as separate uren, but as part of the €/hour calculation)
- [ ] 5.5 Implement ATW count-with-discount: bereidheidsdienst hours count as 0.5× toward ATW 52-hour limit (CAO 7.3(b))
- [ ] 5.6 Write unit test: REQ-003 scenario 1 (10 hours standby, no call) → €35.00
- [ ] 5.7 Write unit test: REQ-003 scenario 2 (10 hours standby, 1.5 hours work + 0.5 travel call) → €35 + €98 = €133

---

## 6. Slaapdienst (Sleep Shift) Registry

- [ ] 6.1 Implement `SlaapDienstService` with methods:
  - `createSlaapDienst(medewerkerId, datum, startTijd, endTijd): Slaapdienst`
  - `addActieveOproep(slaapdienstId, startTijd, endTijd): Oproep`
  - `calculateCompensation(): decimal`
- [ ] 6.2 Implement fixed-fee logic: base compensation = vasteVergoeding (€24.50), independent of hours
- [ ] 6.3 Implement disturbance handling: when actieveOproepen are logged, calculate hours worked + ORT, add to fixed fee
- [ ] 6.4 Implement ATW count-as-rest: slaapdiensten count as "rust-uren" (not work-uren) in ATW calculation, unless disturbed
- [ ] 6.5 Implement loonstrook line-items: display separate rows for fixed fee and active-work (if any)
- [ ] 6.6 Write unit test: REQ-004 scenario 1 (8 hours sleep, no disturbance) → €24.50
- [ ] 6.7 Write unit test: REQ-004 scenario 2 (8 hours sleep, 1.5 hours active nacht-work) → €24.50 + €54.06 = €78.56

---

## 7. ATW + CAO Work-Hour Guardrails in Roster Planning

- [ ] 7.1 Implement `AtwGuardrailService` with method `validateShiftGuardrails(shift: Shift, employee: Employee, week: Week): GuardrailResult`
- [ ] 7.2 Implement CAO 52-hour/week check: sum all planned hours in the week; block if + proposed shift > 52 hours
- [ ] 7.3 Implement 7-consecutive-night check: count nights in proposed + past 6 days; block if ≥ 8
- [ ] 7.4 Implement 11-hour daily rest check: calculate rest-time after previous shift; block if < 11 hours before new shift (and previous shift was 12+ hours)
- [ ] 7.5 Implement warning-mode: for cost-control alerts (ORT budget, utilization), return severity='warning' to allow override
- [ ] 7.6 Integrate with rooster-planning API: calls `validateShiftGuardrails()` before persisting shift; blocks on severity='blocking', warns on severity='warning'
- [ ] 7.7 Write unit test: REQ-005 scenarios (48 + 8 → blocked, 7 nights + 1 → blocked, 10 hours rest → blocked)

---

## 8. PFZW Mandatory Enrollment & UPA Dispatch

- [ ] 8.1 Implement `PfzwEnrollmentService` with methods:
  - `enrollEmployee(medewerkerId): PfzwEnrollment`
  - `dispatchUpaMessage(medewerkerId, mutationType): UpaMessage`
- [ ] 8.2 Implement field-lock: Employee.pensioenuitvoerder = read-only "PFZW" for CAO VVT contracts (HR UI prevents editing)
- [ ] 8.3 Implement auto-dispatch on hire: post-hire, queue UPA intake-message to PFZW
- [ ] 8.4 Implement auto-dispatch on mutation: on contract-change (deeltijdfactor, salary scale), queue UPA mutation-message
- [ ] 8.5 Implement auto-dispatch on exit: on employment-end, queue UPA exit-message
- [ ] 8.6 Implement UPA message generation: build PFZW-compliant XML/JSON with employee data, effective-date, pension-salary
- [ ] 8.7 Implement PFZW transmission: SFTP or secure HTTPS to PFZW intake-endpoint; log dispatch-status in audit-trail
- [ ] 8.8 Implement waarde-overdracht-request (value-transfer): for employees switching from other pension-fund, auto-generate waarde-overdracht after 6-month waiting-period
- [ ] 8.9 Write unit test: REQ-006 scenario 1 (new hire → PFZW locked, UPA dispatched)
- [ ] 8.10 Write unit test: REQ-006 scenario 2 (deeltijdfactor mutation → UPA dispatched with new values)

---

## 9. Overtime Regulation (50% / 100% Toeslag)

- [ ] 9.1 Implement `OvertimeService` with methods:
  - `calculateOvertimeHours(employee: Employee, week: Week): OverTimeBreakdown`
  - `applyOvertimeToeslag(overtimeHours: int, baseRate: decimal): decimal`
  - `swapOvertimeForLeave(overtimeHours: int): LeaveCredit`
- [ ] 9.2 Implement tiered toeslag: first 4 hours/week @ 50%, remainder @ 100%
- [ ] 9.3 Implement time-off swap: first 4 hours → 1.5x leave, remainder → 2.0x leave
- [ ] 9.4 Implement ORT-stacking with overtime: when overtime occurs during nacht-shift, add 50% (or 100%) to existing nacht-ORT (38%) = 88% or 138% total
- [ ] 9.5 Implement employee preference: allow switch between "pay-out" and "swap for leave" modes
- [ ] 9.6 Write unit test: REQ-008 scenario 1 (38 contracted + 6 actual = 4 @ 150% + 2 @ 200%)
- [ ] 9.7 Write unit test: REQ-008 scenario 2 (6 overtime swapped for leave: 4 → 6 hours + 2 → 4 hours = 10 hours leave)
- [ ] 9.8 Write unit test: REQ-008 scenario 3 (2-hour overtime during nacht → 188% total toeslag)

---

## 10. Travel-Cost Reimbursement (WKR-Exempt)

- [ ] 10.1 Implement `TravelReimbursementService` with methods:
  - `calculateWoonWerkReimbursement(employee: Employee, workDays: int): decimal`
  - `processDutyTravelDeclaration(declaration: DutyTravelDeclaration): decimal`
- [ ] 10.2 Implement woon-werk calculation: (distance − 10 km) × 2 (round-trip) × 5 days × 4 weeks × €0.23/km
- [ ] 10.3 Implement duty-travel calculation: declared km × €0.23/km (capped per daily limit, if any)
- [ ] 10.4 Implement WKR-exempt flagging: both reimbursement types are marked as non-taxable per Werkkostenregeling
- [ ] 10.5 Implement company-car transition: when leaseauto is assigned, stop kilometer-reimbursement and apply bijtelling rules
- [ ] 10.6 Write unit test: REQ-009 scenario 1 (15 km woon-werk → 5 km excess × 2 × 5 × 4 × €0.23 = €46)
- [ ] 10.7 Write unit test: REQ-009 scenario 2 (80 km duty-travel → 80 × €0.23 = €18.40)

---

## 11. Wet Zorg en Dwang (Wzd) Qualification Registry

- [ ] 11.1 Implement `WzdQualificationService` with methods:
  - `registerWzdQualification(medewerkerId, issueDate, expiryDate): WzdQualification`
  - `validateWzdRequirements(shift: Shift, department: Department): WzdValidationResult`
  - `flagRenewalReminders(): List<Employee>`
- [ ] 11.2 Implement qualification-tracking: store Wzd issue-date and 3-year expiry; auto-flag expiring qualifications at 6-month mark
- [ ] 11.3 Implement roster-validation: when shift is created in a Wzd-indicated department, check if ≥1 Wzd-qualified employee is assigned; warn if not
- [ ] 11.4 Implement qualification-expiry blocking: on expiry, remove from "Wzd-qualified" list; rooster-planning treats as unqualified
- [ ] 11.5 Implement care-record linking: when Wzd-measure is logged, store reference to shift and care-record for audit
- [ ] 11.6 Write unit test: REQ-010 scenario 1 (shift in Wzd-dept without qualified employee → warning)
- [ ] 11.7 Write unit test: REQ-010 scenario 2 (Wzd-qualification expires after 3 years; renewal reminder at 6 months)
- [ ] 11.8 Write unit test: REQ-010 scenario 3 (Wzd-measure logged → shift linked to care-record)

---

## 12. Seed Data & Configuration Import

- [ ] 12.1 Create migration script to load seed CAO_VVT_Versie record
- [ ] 12.2 Create migration script to load 25+ ORT_Regel seed rows from openspec/changes/cao-zorg-vvt/seeddata.json
- [ ] 12.3 Create migration script to load FWG_Schaal seed rows (FWG 35–80 with periodieken tables)
- [ ] 12.4 Create migration script to load feestdag-list (nationale + CAO-feestdagen 2024–2026)
- [ ] 12.5 Create example employee + shift records for loonstrook demo/test
- [ ] 12.6 Document seed-data refresh procedure (e.g., when ActiZ publishes CAO amendments)

---

## 13. Loonrun Integration

- [ ] 13.1 Extend `LoonrunEngine` to accept ORT-component bundle from cao-zorg-vvt
- [ ] 13.2 Implement retroactive-loonrun branching: loonrun creation UI includes "retroactive?" toggle; if yes, use `RetroactiveLoonrunEngine`
- [ ] 13.3 Implement loonstrook line-item generation: separate rows for basisloon, ORT, bereidheidsdienst, slaapdienst, overuren, reiskost, PFZW-premie
- [ ] 13.4 Implement delta-calculation for amended loonstroken: regenerated loonrun shows "ursprüngliches bruto" vs. "aangepast bruto" and "nabetaling"
- [ ] 13.5 Write integration test: full loonrun flow with ORT calculation, retroactive adjustment, and SEPA export

---

## 14. Rooster-Planning Integration

- [ ] 14.1 Integrate `AtwGuardrailService` API into rooster shift-creation endpoint
- [ ] 14.2 Implement ORT-cost preview: when manager/planner hovers over a shift, show estimated ORT-cost impact (helps budget-aware rostering)
- [ ] 14.3 Implement Wzd-validation in shift-creation: block/warn if Wzd-requirements not met
- [ ] 14.4 Write integration test: roostering workflow with guardrail checks, Wzd validation, and ORT-cost preview

---

## 15. UI & Configuration

- [ ] 15.1 Extend Configuratie › CAO's & regelingen SETTING page to include CAO VVT read-only display of:
  - CAO version, effective dates, loonsverhogingen schedule
  - ORT-rule table (read-only, editable only by admin/ActiZ-sync)
  - FWG scales (read-only)
- [ ] 15.2 Extend Employee master to show/lock pensioenuitvoerder field (PFZW for CAO VVT)
- [ ] 15.3 Extend Employee master to show FWG grade, periodiek, Wzd qualification, BIG-registration
- [ ] 15.4 Add "Retroactieve loonrun" option to loonrun creation UI (date-range picker, effective-date)
- [ ] 15.5 Extend loonstrook preview to show ORT decomposition (segment-by-segment breakdown)

---

## 16. Testing & Validation

- [ ] 16.1 Write comprehensive unit-test suite covering all REQ-001 through REQ-010 scenarios (as listed in specs.md)
- [ ] 16.2 Write integration tests for full workflows: hire → loonrun → loonstrook → retroactive-adjustment → amended loonstrook
- [ ] 16.3 Write performance test: ORT-calculation on 10,000 shifts (target <5s)
- [ ] 16.4 Load-test PFZW UPA dispatch (1,000 UPA-messages/day capacity)
- [ ] 16.5 Write accessibility test: loonstrook display is WCAG 2.1 AA compliant (important for zorgmedewerkers with varying digital literacy)
- [ ] 16.6 Write mobile-UX test: medewerker self-service (payslip view, leave request) is mobile-first friendly

---

## 17. Documentation

- [ ] 17.1 Write internal design-doc: ORT calculation algorithm (time-decomposition, rule-matching, stackability)
- [ ] 17.2 Write admin guide: how to import new CAO amendments, how to manage retroactive loonruns
- [ ] 17.3 Write HR guide: how to enroll employees in CAO VVT, how to request Wzd-qualification renewal
- [ ] 17.4 Write rooster-planner guide: how to understand ATW/CAO guardrails, how to request exceptions
- [ ] 17.5 Write medewerker guide (Dutch + English): how to view loonstroken, understand ORT decomposition, submit travel-declarations
- [ ] 17.6 Generate e2e test scenarios as user journey docs (per target-user persona from proposal.md)

---

## 18. Compliance & Audit

- [ ] 18.1 Implement audit-log: every ORT calculation, every retroactive-run, every Wzd-measure is logged with timestamp, user, delta
- [ ] 18.2 Implement data-export for IGJ (Inspectie Gezondheidszorg en Jeugd) compliance reporting
- [ ] 18.3 Implement data-export for branche-organizations (ActiZ, Zorgthuisnl) for trend-analysis
- [ ] 18.4 Write compliance test: verify all loonstroken for CAO VVT employees include ORT-audit-trail

---

## Effort Estimate

| Category | Days | Notes |
|----------|------|-------|
| Data model + schema | 8 | 10 migration scripts |
| ORT engine | 15 | Core calculation logic + unit tests |
| Wage progression & FWG | 10 | Salary scales, periodiek progression |
| Retroactive pay | 12 | Batch reprocessing, SEPA, notifications |
| Bereidheidsdienst | 8 | Dual-state, ORT, ATW integration |
| Slaapdienst | 6 | Fixed fee, disturbance handling |
| ATW guardrails | 10 | Validation logic, integration with rooster |
| PFZW enrollment | 8 | UPA dispatch, field-lock, mutations |
| Overtime | 6 | Tiered toeslag, time-off swap |
| Travel reimbursement | 5 | WKR-exempt, duty-travel, company-car transition |
| Wzd registry | 7 | Qualification tracking, roster validation |
| Seed data | 5 | Migration scripts, example records |
| Loonrun integration | 10 | ORT bundling, delta-calculation, amendments |
| Rooster integration | 8 | Guardrail API, ORT-cost preview |
| UI & config | 12 | CAO settings, employee master extensions, loonstrook display |
| Testing & validation | 20 | Unit, integration, performance, accessibility, mobile |
| Documentation | 10 | Design docs, guides, journey docs |
| Compliance & audit | 8 | Logging, exports, audit-trail |
| **Total** | **172** | **~180 with contingency** |

