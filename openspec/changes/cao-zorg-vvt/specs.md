---
status: draft
---

# Specifications: CAO VVT 2024-2026

## ADDED Requirements

### REQ-001: ORT-engine met stapelbare toeslagen

The system MUST decompose every shift into per-minute (or finer granularity) time-segments, assign applicable ORT rules per segment based on day-of-week, time-of-day, and holiday status, apply stackability logic per CAO article 7.1, and output the calculated toeslag-bedrag (in EUR) for inclusion in the loonstrook.

#### Scenario: Evening shift on 24 December (not a CAO-feestdag)

- **GIVEN** a medewerker works from 18:00 to 23:00 on 24 December 2024
- **WHEN** the ORT calculation is executed
- **THEN** the system identifies "Avond doordeweeks" rule (22% ORT, applies to weekdays 18:00–00:00)
- **AND** the system applies 22% ORT to all 5 hours (24 Dec is not a CAO-feestdag; feestdag is 25–26 Dec)
- **AND** the system outputs: toeslag = 5 hours × €26.00/hour × 22% = €28.60

#### Scenario: Night shift spanning two days (nachtdienst on kerstdag with stacking)

- **GIVEN** a medewerker works from 00:00 to 08:00 on 25 December 2024 (eerste Kerstdag)
- **WHEN** the ORT calculation is executed
- **THEN** the system identifies two segments:
  - Segment 1 (00:00–06:00): "Nacht allen" (38%) + "Feestdag" (47%) → stapelbaarMet="feestdag" → stacked = 85% ORT
  - Segment 2 (06:00–08:00): "Feestdag" only (47% ORT; nacht rule ends at 06:00)
- **AND** the system outputs:
  - Segment 1: 6 hours × €26.00 × 85% = €132.60
  - Segment 2: 2 hours × €26.00 × 47% = €24.44
  - Total ORT: €157.04

#### Scenario: Sunday shift (no evening ORT on weekends)

- **GIVEN** a medewerker works from 14:00 to 22:00 on a Sunday (not a feestdag)
- **WHEN** the ORT calculation is executed
- **THEN** the system applies rules:
  - 14:00–18:00 (4 hours): "Zondag dag" (38% ORT)
  - 18:00–22:00 (4 hours): "Zondag avond" (note: avond-ort is not applied on weekend; weekday-avond-ort excludes Sunday per uitsluitendBij)
- **AND** the system outputs:
  - Segment 1: 4 hours × €26.00 × 38% = €39.52
  - Segment 2: 4 hours × €26.00 × 0% = €0.00
  - Total ORT: €39.52

---

### REQ-002: Loonsverhoging in stappen met retroactieve berekening

The system MUST apply CAO wage step-increases on the scheduled dates (3% on 2024-01-01, 2.5% on 2025-01-01, 2% on 2026-01-01) to all salary scales and FWG-based components. When the CAO is ratified retroactively (e.g., ratified on 2024-04-15 with effective date 2024-01-01), the system MUST recalculate all pay periods from the effective date to the ratification date and generate a "nabetaling" line item on the next non-retroactive loonrun.

#### Scenario: Automatic wage increase on CAO effective date

- **GIVEN** the CAO enters "active" status with ingangsdatum=2024-01-01 and loonsverhogingen=[3%]
- **WHEN** a medewerker's FWG 50 periodiek 4 salary was €3,200 pre-CAO
- **THEN** the system applies 3% increase: new salary = €3,200 × 1.03 = €3,296
- **AND** all loonruns from 2024-01-01 onward include this new salary
- **AND** employees hired before 2024-01-01 see the increase on their 2024-01-01 pay

#### Scenario: Retroactive CAO ratification with back-pay

- **GIVEN** the CAO VVT 2024-2026 is officially signed on 2024-04-15 (2 months after effective date 2024-01-01)
- **GIVEN** a medewerker earned €3,200/month under provisional rates Jan–Mar 2024
- **GIVEN** the definitive CAO retroactively increases the schaal to €3,296/month (3%)
- **WHEN** the salarisadministrateur creates a retroactive loonrun for the period 2024-01-01 to 2024-03-31
- **THEN** the system recalculates:
  - Gross Jan–Mar under provisional rates: €9,600
  - Gross Jan–Mar under definitive CAO rates: €9,888
  - Delta: €288
- **AND** the system generates a "nabetaling" line item of €288 on the May 2024 loonrun
- **AND** the loonrun is tagged with retroactive_batch_id = "retro-cao-vvt-2024-01-01"
- **AND** all affected loonstroken are regenerated with the corrected amounts and mailed to the medewerker

#### Scenario: Retroactive pay for departed employee

- **GIVEN** a medewerker exited employment on 2024-02-29 (before the retroactive CAO took effect in April)
- **GIVEN** they were eligible for the CAO's retroactive 3% increase for Jan–Feb 2024
- **WHEN** the retroactive loonrun is processed
- **THEN** the system detects the medewerker is no longer active
- **AND** generates a separate SEPA payment (nabetaling) to their bank account on file
- **AND** emails the medewerker a letter explaining the retroactive payment with details

---

### REQ-003: Bereidheidsdienst (standby) met oproep-tracking

The system MUST register standby duties (consignatiedienst) as a separate entity with a fixed hourly rate (€3.50/hour per CAO). When the employee is called during standby, actual work-hours must be recorded, recalculated with full ORT + uurtarief, and added to the standby fee on the loonstrook.

#### Scenario: Standby without activation

- **GIVEN** a medewerker has bereidheidsdienst from 22:00 to 08:00 (10 hours)
- **WHEN** no actual work occurs during this period
- **THEN** the system records: bereidheidsdienst = 10 hours × €3.50 = €35.00
- **AND** the loonstrook displays a single line: "Bereidheidsvergoeding (consignatie): 10 uur × €3.50 = €35,00"

#### Scenario: Standby with activation and travel time

- **GIVEN** a medewerker has bereidheidsdienst from 22:00 to 08:00 (10 hours)
- **WHEN** the employee is called at 03:15 to provide care, works until 04:45, and travels 0.5 hours round-trip
- **THEN** the system extracts active-work: 1.5 hours care + 0.5 hours travel = 2.0 compensated hours
- **AND** applies nacht-ORT (38%) to the 2 hours: €49.00/hour × 2 hours = €98.00
- **AND** the loonstrook displays two lines:
  - "Bereidheidsvergoeding (consignatie): 10 uur × €3.50 = €35,00"
  - "Oproepwerk nachtdienst: 2 uur × €49,00 (38% ORT) = €98,00"
- **AND** the total for this bereidheidsdienst = €133.00

#### Scenario: Standby hours counted in weekly work-hour totals

- **GIVEN** a medewerker has 3 bereidheidsdiensten in week 51 (30 hours total)
- **WHEN** the weekly ATW check is executed
- **THEN** the system counts bereidheidsdiensten using CAO rule 7.3(b): each standby hour = 0.5× work-hour for ATW purposes
- **AND** the total work-hours = 30 × 0.5 = 15 hours counted toward the 52-hour CAO limit
- **AND** if other shifts total 38 hours, total week = 38 + 15 = 53 hours → exceeds CAO max → warning/blocking

---

### REQ-004: Slaapdienst (sleep shift) met vaste vergoeding

The system MUST register sleep shifts (medewerker overnacht in instelling) with a fixed nightly fee (€24.50 per CAO 2024). If the employee is disturbed and must provide active care, the system MUST automatically upgrade the affected hours to paid work-hours with full ORT and uurtarief, while retaining the base sleep-shift fee.

#### Scenario: Sleep shift without disturbance

- **GIVEN** a medewerker has slaapdienst from 23:00 to 07:00 without any disturbance
- **WHEN** the sleep shift is closed
- **THEN** the system records: slaapdienst_vergoeding = €24.50 (fixed)
- **AND** the loonstrook displays: "Slaapdienst 23:00–07:00: €24,50"
- **AND** no additional hours are calculated (sleep shift is not hour-based)

#### Scenario: Sleep shift with disturbance and active work

- **GIVEN** a medewerker has slaapdienst from 23:00 to 07:00
- **WHEN** the employee is awakened at 02:30 for an acute care-need, provides care until 04:00
- **THEN** the system records:
  - Base slaapdienst fee: €24.50 (retained)
  - Active work-hours: 1.5 hours (02:30–04:00)
  - ORT applied: nacht-ort (38%) applies to 02:30–04:00 segment
  - Active-work compensation: 1.5 hours × €26.00 × 138% = €54.06 (base + 38% nacht)
- **AND** the loonstrook displays two lines:
  - "Slaapdienst 23:00–07:00: €24,50"
  - "Oproepwerk nachtdienst: 1.5 uur × €35,88 (38% ORT) = €54,06"
- **AND** total = €78.56

#### Scenario: Sleep shifts counted as rest-hours in ATW

- **GIVEN** a medewerker completes 4 slaapdiensten in one calendar month
- **WHEN** the monthly ATW compliance check runs
- **THEN** the system counts slaapdiensten as "rust-uren" (rest/recovery time), not as "werk-uren"
- **AND** they do NOT count toward the 52-hour/week or 60-hour/week maximums
- **AND** note: if slaapdiensten are disturbed, only the active-work portion counts as work-hours

---

### REQ-005: ATW + CAO-werktijdgrenzen met roostering-validatie

The system MUST enforce work-hour limits during roster-planning in real-time. Blocking violations prevent shift-creation; warning violations alert the manager but allow override with justification.

#### Scenario: CAO weekly hour limit

- **GIVEN** a medewerker already has 48 hours scheduled in week 51
- **WHEN** the rooster-planner attempts to add a shift of 8 hours
- **THEN** the system calculates: 48 + 8 = 56 hours > CAO limit of 52 hours
- **AND** the system blocks the shift-addition with error: "CAO-maximum 52 werkuren per week overschreden. Kies ander personeelslid of dienst."
- **AND** the planner is offered alternatives: (a) assign different employee, (b) shorten the shift, (c) file formal ATW-exception request

#### Scenario: Consecutive night-shift limit

- **GIVEN** a medewerker has worked 7 consecutive night-shifts (Tue–Sun)
- **WHEN** the rooster-planner attempts to schedule another night-shift for Monday
- **THEN** the system detects this would create 8 consecutive nights > CAO limit of 7
- **AND** the system blocks the shift with error: "CAO-regel: maximaal 7 achtereenvolgende nachtdiensten. Volgende dag moet rustdag of dagdienst zijn."
- **AND** the planner sees a calendar view highlighting the 7-night stretch and the blocked Monday

#### Scenario: Minimum daily rest period

- **GIVEN** a medewerker works a 12-hour shift ending at 23:00
- **GIVEN** a new shift is proposed starting at 09:00 the next morning (10 hours rest)
- **WHEN** the rooster-planner submits the shift
- **THEN** the system detects: 10 hours < minimum 11 hours required after a 12+ hour shift (CAO + ATW)
- **AND** the system blocks the shift: "Onvoldoende dagelijkse rusttijd — minimum 11 uur na dienst van 12+ uur."
- **AND** suggests moving the shift to 10:00 (11 hours rest)

---

### REQ-006: PFZW pensioenaansluiting verplicht

The system MUST automatically enroll all employees under CAO VVT into PFZW (Pensioenfonds Zorg en Welzijn) and enforce this selection. On every contract mutation, the system MUST dispatch a UPA (Uitvoering Pensioenaangifte) message to PFZW with updated employee data. Pension contribution percentages are derived from the FWG salary scale per CAO article 14.

#### Scenario: Mandatory PFZW enrollment at hire

- **GIVEN** an HR-medewerker registers a new employee under CAO VVT
- **WHEN** the employee-master form is opened, the "pensioenuitvoerder" field is pre-set to "PFZW" (read-only, locked)
- **THEN** the HR-medewerker cannot change this field to another pension fund
- **AND** on save, the system auto-generates a PFZW intake-record and queues a UPA-message dispatch
- **AND** the loonrun template includes "PFZW-pensioenpremie 10.25%" (example rate per FWG 50)

#### Scenario: Contract mutation triggers UPA dispatch

- **GIVEN** a medewerker's contract is mutated: deeltijdfactor increases from 0.8 to 1.0
- **WHEN** the mutation is saved
- **THEN** the system detects a pension-relevant change (full-time equivalence increases)
- **AND** automatically generates a UPA-bericht with:
  - Employee ID, name, BSN, hire-date
  - Old deeltijdfactor: 0.8
  - New deeltijdfactor: 1.0
  - Recalculated pension-salary
  - Effective date
- **AND** dispatches the UPA-message to PFZW via secure SFTP or HTTPS
- **AND** logs the dispatch in the audit-trail

#### Scenario: New employee with prior pension fund

- **GIVEN** a medewerker comes from another sector with BPF Vervoer pension enrollment
- **GIVEN** they hire into a CAO VVT care organization on 2024-06-01
- **WHEN** their CAO VVT contract starts
- **THEN** the system enrolls them in PFZW (mandatory)
- **AND** generates a "waardeover-drachtverzoek" (value-transfer request) to PFZW after the 6-month waiting-period (2024-12-01)
- **AND** PFZW will coordinate with BPF Vervoer to transfer accumulated pension rights

---

### REQ-007: Functieschalen FWG (Functiewaardering Gezondheidszorg)

The system MUST support FWG function grades 35–80 (healthcare sector standard) with associated salary scales. Each scale has 4–14 salary steps (periodieken) with annual progression per the CAO. When a function is re-evaluated, the system MUST support batch re-grading of all employees in that role.

#### Scenario: FWG grade assignment at hire

- **GIVEN** a function is FWG-rated at "FWG 50 — Verpleegkundige niveau 4"
- **WHEN** a medewerker is hired into this function
- **THEN** the system auto-assigns FWG 50 with:
  - Initial periodiek: 1 (entry point)
  - Associated salary-scale with 12 periodieken for FWG 50
  - Monthly salary from periodieken-table for year 2024
- **AND** the loonrun uses the FWG 50 salary-scale to calculate bruto-salary

#### Scenario: Automatic periodiek progression

- **GIVEN** a medewerker is in FWG 50, periodiek 4, on 2024-12-15
- **WHEN** their hire-anniversary date (2023-12-15) passes and auto-progression is enabled
- **THEN** the system auto-advances to periodiek 5
- **AND** the next loonrun (January 2025) uses periodiek 5 salary
- **AND** generates a notification to HR: "Maria Garcia promoted to FWG 50 periodiek 5 — salary increase € 96/month"

#### Scenario: Function re-evaluation with batch re-grading

- **GIVEN** the "Verzorgende IG (Intellectual Disability)" function is re-evaluated from FWG 45 to FWG 50
- **GIVEN** there are 12 active employees in this function
- **WHEN** the HR-medewerker updates the function-master record with the new FWG 50 grade
- **THEN** the system generates a batch-mutation proposal:
  - Lists all 12 employees
  - Shows old salary (FWG 45 periodiek X)
  - Shows new salary (FWG 50 periodiek Y, using horizontal-connectivity rule from CAO)
  - Calculates salary increases per employee
- **AND** the HR-medewerker reviews and approves the batch-mutation
- **AND** all 12 employees are reclassified, with effective-date and back-pay (if applicable)

---

### REQ-008: Overurenregeling 50% en 100%

The system MUST track overtime hours (uren boven de contractueel geplande uren) and apply tiered rates: first 4 hours/week at 50% toeslag, remainder at 100% toeslag. Overtime may alternatively be swapped for time-off at 1.5x and 2.0x multipliers per employee preference.

#### Scenario: Overtime tiered pay-out

- **GIVEN** a medewerker works 38 contracted hours/week and logs 6 actual overtime hours in week 51
- **WHEN** the loonrun is executed
- **THEN** the system calculates:
  - First 4 overtime hours: 4 × €26.00 × 150% = €156.00
  - Next 2 overtime hours: 2 × €26.00 × 200% = €104.00
  - Total overtime toeslag: €260.00
- **AND** the loonstrook shows:
  - "Overuren (eerste 4 uur): 4 uur × €39,00 (150%) = €156,00"
  - "Overuren (boven 4 uur): 2 uur × €52,00 (200%) = €104,00"

#### Scenario: Overtime swapped for time-off

- **GIVEN** a medewerker logs 6 overtime hours in week 51
- **GIVEN** the employee has selected "swap for time-off" mode instead of pay-out
- **WHEN** the loonrun is executed
- **THEN** the system calculates:
  - First 4 hours overtime → 4 × 1.5x = 6 hours time-off credited
  - Next 2 hours overtime → 2 × 2.0x = 4 hours time-off credited
  - Total: 10 hours verlof credited to employee's leave-balance
- **AND** the loonstrook shows:
  - "Overuren (eerste 4 uur) omgezet in verlof: 4 uur → 6 uur verlof"
  - "Overuren (boven 4 uur) omgezet in verlof: 2 uur → 4 uur verlof"
- **AND** the loonstrook contains no cash overtime pay

#### Scenario: Overtime during night-shift with ORT stacking

- **GIVEN** a medewerker works a night shift (nacht-ORT 38%) and 2 hours are overtime
- **WHEN** the loonrun calculates overtime toeslag
- **THEN** the system stacks: base 38% (nacht) + 50% (first-tier overtime) = 88% total toeslag
- **AND** outputs: 2 hours × €26.00 × 188% = €97.76
- **AND** note: the 50% is additive; the highest applicable rate is not capped

---

### REQ-009: Reiskostenvergoeding woon-werk en dienstreizen

The system MUST calculate travel-cost reimbursement per CAO: fixed allowance for woon-werk distance >10 km, and €0.23/km for duty-travel (care-worker site visits). Both are processed as tax-free benefits under the Werkkostenregeling (WKR).

#### Scenario: Fixed woon-werk travel-cost reimbursement

- **GIVEN** a medewerker has a home-to-work distance of 15 km single-trip
- **GIVEN** they work 5 days/week on average
- **WHEN** the monthly travel-reimbursement is calculated
- **THEN** the system determines:
  - Excess distance: 15 km − 10 km = 5 km
  - Monthly: 5 km (excess) × 2 (round-trip) × 5 days × 4 weeks = 200 km
  - Reimbursement: 200 km × €0.23 = €46.00/month
- **AND** the loonstrook displays: "Reiskostenvergoeding woon-werk: €46,00"
- **AND** this amount is marked as WKR-exempt (tax-free)

#### Scenario: Duty-travel reimbursement (thuiszorg site visits)

- **GIVEN** a thuiszorg (home-care) medewerker visits 4 clients on a workday, totaling 80 km
- **WHEN** they submit a travel-declaration
- **THEN** the system calculates:
  - 80 km × €0.23 = €18.40
- **AND** approves the declaration (assuming 80 km is reasonable and within daily-limit)
- **AND** adds €18.40 to the loonstrook as "Dienstreiskostenvergoeding"
- **AND** marks it as WKR-exempt (tax-free)

#### Scenario: Transition to company car (leaseauto)

- **GIVEN** a medewerker receives a company car for work
- **WHEN** their travel-agreement is updated
- **THEN** the system:
  - Stops the woon-werk kilometer-vergoeding
  - Begins calculating bijtelling (personal use of company car) per fiscale regelingen
  - If applicable, includes bijtelling amount on loonstrook as taxable income
- **AND** duty-travel (thuiszorg visits) remains compensated if business km are tracked separately

---

### REQ-010: Wet zorg en dwang (Wzd) inzet-registratie

The system MUST track Wzd (legal authority to provide involuntary care) qualifications per employee with expiry dates. During roster planning, the system MUST ensure each shift with Wzd-indicated clients has at least one Wzd-qualified employee and warn if not. The system MUST log Wzd applications to the care-record for audit.

#### Scenario: Wzd qualification requirement during rostering

- **GIVEN** a department has clients with Wzd-indication (involuntary care flag)
- **GIVEN** a shift is scheduled in that department
- **WHEN** the rooster-planner submits the shift
- **THEN** the system checks: is there at least one Wzd-qualified employee on this shift?
- **AND** if no qualified employee is assigned, the system warns: "Let op: deze dienst heeft Wzd-indicatie maar geen Wzd-bevoegd personeelslid. Voeg een Wzd-medewerker toe of markeer dienst als Wzd-vrij."
- **AND** allows the planner to:
  - Add a Wzd-qualified medewerker
  - Mark the shift as "Wzd-vrij" (no involuntary care expected)
  - Escalate to manager

#### Scenario: Wzd qualification tracking and renewal

- **GIVEN** a medewerker completes a Wzd-training course and receives a certificate dated 2024-05-15
- **WHEN** the HR-medewerker registers the qualification in the employee-master
- **THEN** the system records:
  - Qualification: "Wzd-bevoegdheid"
  - Issue-date: 2024-05-15
  - Expiry-date: 2027-05-14 (3 years per course standard)
- **AND** 6 months before expiry (2026-11-15), the system sends a notification to HR:
  - "Maria Garcia's Wzd-bevoegdheid vervalt over 6 maanden. Zorg voor herscholing."
- **AND** on expiry (2027-05-15), the employee is automatically removed from "Wzd-qualified" lists
- **AND** rooster-planning treats them as unqualified

#### Scenario: Wzd application logging to care-record

- **GIVEN** a medewerker applies involuntary-care measure to a client during a shift
- **WHEN** the measure is registered in the care-documentation system
- **THEN** a reference is generated: "Wzd-toepassing: [medewerker name], [shift-id], [tijd], [type maatregel]"
- **AND** the shift-record is updated with a link to the care-record entry
- **AND** compliance-audit can later pull all Wzd-toepassingen per employee, department, month for oversight

