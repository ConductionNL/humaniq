---
status: proposed
---

# Specifications: CAO Ziekenhuizen

## REQ-001 — FWG 3.0 Functiewaardering en Schaal-indicatie

**Requirement:** The module SHALL convert a FWG 3.0 totaalscore into an FWG-functiegroep and corresponding salarisschaal according to the official FWG conversion table from Stichting FWG. The valuation MUST be based on the complete set of nine sub-scores and the outcome MUST be a unique functiegroep (unlike FUWASYS, FWG has no overlapping band boundaries).

### Scenario 1.1: Valid FWG score maps to correct scale

**GIVEN** an FWG totaalscore of 38 points (kennisScore:12, zelfstandigheidScore:12, socialVaardigheidScore:8, risicoVerantwoordelijkheidInfluedScore:2, expressieVaardigheidScore:2, bewegingsVaardigheidScore:1, oplettendheidsScore:0, overigeFunctieEisenScore:0, inconvenientenScore:1)

**WHEN** scale derivation runs

**THEN** the functiegroep is FWG-40 with salarisschaal 40 (corresponding to the score range 36–40 per the FWG 3.0 conversion table)

### Scenario 1.2: Incomplete FWG score raises exception

**GIVEN** an FWG score report with bewegingsVaardigheidScore missing (null)

**WHEN** validation runs

**THEN** an `IncompleteFwgScoreException` is raised with a message indicating which sub-score is missing (bewegingsVaardigheid)

### Scenario 1.3: Reference function mismatch generates warning

**GIVEN** a functiebenaming "Verpleegkundige IC" for which the FWG-referentiefuncties database lists a fixed functiegroep FWG-50 (score range 46–50)

**WHEN** validation verifies the derived FWG score against reference functions

**THEN** if the calculated FWG score is 44 (outside the 46–50 range), a `FwgReferenceFunctionMismatch` warning is generated and flagged for manual review

## REQ-002 — ORT-berekening per Gewerkt Uur met Dag-Tijd-Tariefmatrix

**Requirement:** The module SHALL calculate onregelmatigheidstoeslagen (ORT) for each worked hour based on day-of-week and time-of-day, applying the current NVZ percentages: Monday–Friday 06:00–22:00 0%; Mon–Fri 00:00–06:00 and 22:00–24:00 47%; Saturday 00:00–06:00 and 22:00–24:00 49%; Saturday 06:00–22:00 38%; Sunday/holiday 00:00–24:00 60%. Transitions between day-time zones and DST boundaries MUST be computed minute-by-minute with strict timezone handling (Europe/Amsterdam).

### Scenario 2.1: Weekend shift with correct ORT percentage

**GIVEN** a worked hour on Saturday 14:00–15:00 by an employee with hourly rate EUR 22.50

**WHEN** ORT is calculated

**THEN** the ORT allowance for that hour is EUR 22.50 × 0.38 = EUR 8.55 added to the base salary

### Scenario 2.2: Multi-day shift with hour-by-hour ORT attribution

**GIVEN** a shift from Friday 22:00 to Saturday 07:00 with hourly rate EUR 25.00

**WHEN** total ORT is calculated

**THEN**
- Friday 22:00–24:00 (2 hours) receive 47% ORT = EUR 25.00 × 2 × 0.47 = EUR 23.50
- Saturday 00:00–06:00 (6 hours) receive 49% ORT = EUR 25.00 × 6 × 0.49 = EUR 73.50
- Saturday 06:00–07:00 (1 hour) receives 38% ORT = EUR 25.00 × 1 × 0.38 = EUR 9.50
- Total ORT = EUR 106.50

### Scenario 2.3: Holiday shift overrides day-of-week tariff

**GIVEN** Eerste Kerstdag (Christmas Day) falling on a Tuesday, 14:00–15:00, hourly rate EUR 28.00

**WHEN** ORT is calculated

**THEN** the hour receives the holiday tariff 60% ORT (not the Tuesday 0% tariff) = EUR 28.00 × 0.60 = EUR 16.80

### Scenario 2.4: DST boundary crossing (spring forward)

**GIVEN** a shift on the Sunday when DST begins (02:00 → 03:00, one hour lost), 01:30–03:30 Europe/Amsterdam, hourly rate EUR 20.00

**WHEN** ORT for the DST-boundary hour is calculated

**THEN** the interval 01:30–02:00 (30 min, 60% ORT) and 03:00–03:30 (30 min, 60% ORT) are both applied; the "lost hour" between 02:00–03:00 does not appear in the time series and is not double-counted

## REQ-003 — Bereikbaarheidsdienst-vergoeding voor Passieve Dienst

**Requirement:** The module SHALL register and reimburse availability services (whereby the employee remains at home and available for emergency calls) against a fixed hourly tariff per role and service type, plus a separate call-out fee for actual work (base salary + ORT for the hours worked, including travel time).

### Scenario 3.1: Availability shift without call-out

**GIVEN** a surgeon with an availability shift from Friday 18:00 to Monday 08:00 (62 hours) with no actual call-out, and a surgeon-availability rate of EUR 4.85/hour

**WHEN** reimbursement is calculated

**THEN** the passive reimbursement is 62 hours × EUR 4.85/hour = EUR 300.70

### Scenario 3.2: Availability shift with call-out

**GIVEN** the same surgeon with a call-out from Saturday 03:00 to 06:00 for an emergency operation (3 hours)

**WHEN** call-out reimbursement is calculated

**THEN**
- The 3 actual-work hours are reimbursed at base salary + 49% ORT (night Saturday) = EUR 35.00 + (EUR 35.00 × 0.49) = EUR 52.15/hour × 3 = EUR 156.45
- The remaining 59 hours of passive availability receive EUR 4.85/hour = EUR 285.85
- Total = EUR 442.30

### Scenario 3.3: Non-clinical role cannot have availability service

**GIVEN** an administrative employee (administratie) for whom availability service is not configured in the CAO

**WHEN** an attempt to register an availability shift is made

**THEN** a `BereikbaarheidsdienstNotApplicableException` is raised

## REQ-004 — Slaapdienst-conversie naar Loonuren

**Requirement:** The module SHALL convert sleep-duty hours (on-premises with sleep facility, non-active) to compensated wage-hours according to the FWG conversion table: the sleep block is counted as a fraction of a wage-hour (typically 0.4–0.6 depending on type), with call-out interruptions counted as full work-hours. The total converted wage-hours determine the reimbursement.

### Scenario 4.1: Sleep duty without interruption

**GIVEN** a sleep duty from 23:00 to 07:00 (8 hours) with no interruptions and a conversion factor of 0.5

**WHEN** wage-hour conversion runs

**THEN** the 8 sleep-hours convert to 8 × 0.5 = 4.0 wage-hours, reimbursed at the base hourly rate with the applicable ORT percentages (0–60% depending on hours)

### Scenario 4.2: Sleep duty with call-out interruption

**GIVEN** the same 8-hour sleep duty (23:00–07:00) with a call-out from 02:00 to 03:30 and conversion factor 0.5

**WHEN** wage-hour conversion runs

**THEN**
- Sleep period 23:00–02:00 (3 hours) converts to 1.5 wage-hours
- Call-out 02:00–03:30 (1.5 hours) counts as 1.5 full wage-hours
- Sleep period 03:30–07:00 (3.5 hours) converts to 1.75 wage-hours
- Total = 4.75 wage-hours, with ORT percentages applied per time-of-night

### Scenario 4.3: Sleep duty not permitted for certain roles

**GIVEN** a specialist-in-training (aios geneeskunde) for whom the Werktijdenbesluit geneeskundigen-in-opleiding (Wtb-GiO) prohibits sleep-duty

**WHEN** validation runs

**THEN** a `SlaapdienstNotPermittedException` is raised

## REQ-005 — Overuren Chirurgische Dienst aan 100 Percent

**Requirement:** The module SHALL, for employees in the surgical and anaesthetic departments (operatieassistenten, anesthesiemedewerkers, chirurgen in dienstverband), reimburse overtime at 100% of hourly salary (instead of the standard 50% after the 8th hour or 100% on weekends). Overtime is defined as hours worked above the agreed daily shift, not above the weekly contractual hours.

### Scenario 5.1: Surgical staff overtime on extended case

**GIVEN** an operating-theatre assistant with scheduled shift 08:00–16:30 who works until 19:00 due to a case running over (2.5 extra hours), hourly rate EUR 28.00

**WHEN** overtime reimbursement is calculated

**THEN** the 2.5 hours above the daily shift receive 100% of EUR 28.00 = EUR 70.00/hour × 2.5 = EUR 175.00 (plus applicable ORT if the hours fall outside 06:00–22:00)

### Scenario 5.2: Non-surgical staff with same shift extension

**GIVEN** a ward nurse (verpleegafdeling) with the same scheduled shift 08:00–16:30 working until 19:00, hourly rate EUR 26.00

**WHEN** overtime reimbursement is calculated

**THEN** the 2.5 hours receive the standard 50% overtime rate (not 100%) = EUR 26.00 × 0.5 × 2.5 = EUR 32.50

### Scenario 5.3: Voluntary overtime without operational necessity

**GIVEN** an operating-theatre assistant who chooses to stay after the case is complete and the OR is closed, without further scheduled work

**WHEN** the overtime claim is validated

**THEN** the hours are not recognized as legitimate overtime and an `OverurenZonderDienstNoodzaakException` is raised, requiring manager validation

## REQ-006 — PFZW-aansluiting en Premie-afdracht

**Requirement:** The module SHALL register each employment with PFZW (Pensioenfonds Zorg en Welzijn) on day one and calculate the PFZW premium (currently 25.8% over the pension benefit base) with a 50/50 employer-employee split and the annual franchise (currently EUR 17,545, pro-rata for part-time employees).

### Scenario 6.1: New employment PFZW registration and premium

**GIVEN** a new employment starting 2026-08-01 with monthly salary EUR 3,800, full-time (1.0)

**WHEN** august payroll runs

**THEN**
- PFZW registration is recorded with effective date 2026-08-01
- Annual benefit base = 12 × EUR 3,800 = EUR 45,600
- Taxable benefit base = EUR 45,600 − EUR 17,545 = EUR 28,055
- PFZW premium = EUR 28,055 × 0.258 = EUR 7,238.19/year = EUR 603.18/month
- Employer share = EUR 301.59/month, employee share = EUR 301.59/month

### Scenario 6.2: Part-time PFZW franchise adjustment

**GIVEN** a part-time employee with 0.6 contract factor and monthly salary EUR 2,400 (0.6 × EUR 4,000)

**WHEN** PFZW premium is calculated

**THEN**
- Annual benefit base = 12 × EUR 2,400 = EUR 28,800
- Adjusted franchise = 0.6 × EUR 17,545 = EUR 10,527
- Taxable benefit base = EUR 28,800 − EUR 10,527 = EUR 18,273
- PFZW premium = EUR 18,273 × 0.258 = EUR 4,714.43/year = EUR 392.86/month

### Scenario 6.3: Dual UMC + NVZ employment

**GIVEN** an employee with simultaneous employment at an NVZ hospital (PFZW) and a UMC (separate UMC pension scheme) in the same month

**WHEN** PFZW and UMC pension premiums are calculated

**THEN** both employments are registered separately; the PFZW premium is calculated on the NVZ benefit base alone and the UMC premium on the UMC base — no consolidation or franchise sharing between the two funds

## REQ-007 — Bovenwettelijke ADV-uren met Flex-Conversie

**Requirement:** The module SHALL administer supra-statutory working-time reduction (ADV) as an annual entitlement (typically 96 hours for full-time) accrued per hour worked, and SHALL allow conversion each quarter to cash (at current hourly salary), extra vacation days, or structured reduction of daily working hours.

### Scenario 7.1: ADV accrual for part-year employment

**GIVEN** a full-time employee with 6 months of employment (start date 2026-01-01, at mid-year 2026-06-30)

**WHEN** ADV accrual is calculated

**THEN** the available balance is 48 hours (half of the annual 96-hour entitlement for full-time)

### Scenario 7.2: ADV cash payout with payroll integration

**GIVEN** an ADV election to pay out 40 hours at hourly rate EUR 24.00

**WHEN** the election is processed in payroll

**THEN**
- ADV balance is reduced by 40 hours
- Payout amount EUR 40 × EUR 24.00 = EUR 960.00 is added to the next salary run
- Payout is subject to income tax withholding and PFZW/other statutory premiums

### Scenario 7.3: ADV converted to vacation for part-time employee

**GIVEN** a part-time employee (0.6 factor) with 80 hours ADV balance who elects to convert to vacation

**WHEN** the election is processed

**THEN**
- The employee receives 80 / 0.6 = 133.3 calendar-vacation-days-equivalent (because part-timers accrue and use vacation in day-equivalents per employment law)
- ADV balance is reduced by 80 hours
- The vacation days are added to the employee's vacation calendar

## REQ-008 — Tijd-voor-Tijd Compensatiebank

**Requirement:** The module SHALL maintain an alternative compensation bank where employees may elect, per shift, to bank ORT and overtime as time-off (valued at 1:1 work-hours plus the ORT fraction as a time-equivalent) instead of cash reimbursement. The balance MUST NOT exceed 80 hours and MUST be used (or automatically paid out) within 12 months of each credit.

### Scenario 8.1: ORT credit to time-off bank

**GIVEN** a worked Saturday hour 14:00–15:00 (1 hour, 38% ORT) where the employee elects time-off instead of cash

**WHEN** the election is processed

**THEN** the tijd-voor-tijd balance is credited with 1 + 0.38 = 1.38 hours and the corresponding cash (EUR 22.50 × 0.38 = EUR 8.55) is suppressed

### Scenario 8.2: Time-off bank overflow

**GIVEN** a current balance of 75 hours and a new shift that would add 8 hours

**WHEN** the new shift is processed

**THEN** the system rejects the time-off bank credit and raises a `TijdVoorTijdSaldoOverflowException`, forcing the employee to elect cash for that shift instead

### Scenario 8.3: Automatic payout of expired balance

**GIVEN** a time-off bank balance of 60 hours with oldest entry dated 2024-05-15 and today's date is 2025-05-23

**WHEN** the monthly expiry-check process runs

**THEN** the 60 hours, which have exceeded the 12-month use-by date, are automatically converted to cash at the current hourly rate (EUR 24.00) = EUR 1,440.00 and added to the next salary run; the balance is reset to 0

## REQ-009 — Diensttijdverhoudingen voor Parttime Medewerkers

**Requirement:** The module SHALL, for part-time employees (parttimePercentage < 1.0), apply pro-rata scaling to all nominal entitlements: salary, vacation accrual, ADV accrual, IKB-equivalent allowances, PFZW benefit base, and jubilee payouts. The specific rule is that ORT percentages are NOT scaled: a part-time employee earns the same ORT percentage as a full-time employee for the hours actually worked.

### Scenario 9.1: Part-time salary calculation

**GIVEN** a 0.5 part-time employee in FWG-50 scale step 8 with full-time equivalent EUR 4,200/month

**WHEN** monthly salary is calculated

**THEN** the salary is EUR 2,100 (0.5 × EUR 4,200)

### Scenario 9.2: Part-time ORT without percentage scaling

**GIVEN** the same 0.5 part-time employee working Sunday 12:00–16:00 (4 hours) at base rate EUR 18.00/hour

**WHEN** ORT is calculated

**THEN** the 4 hours receive the full Sunday 60% ORT = EUR 18.00 × 4 × 0.60 = EUR 43.20 (no pro-rata reduction of the 60% itself)

### Scenario 9.3: Part-time jubilee payout

**GIVEN** a 0.7 part-time employee with 25 years of service, full-time salary scale FWG-50 step 8 = EUR 4,200/month

**WHEN** jubilee payout is calculated

**THEN** the payout is 0.7 × EUR 4,200 = EUR 2,940 (one month pro-rata)

## REQ-010 — Loondoorbetaling bij Ziekte met Wettelijk Minimum plus CAO-aanvulling

**Requirement:** The module SHALL, on sick-leave, pay 100% of salary in year 1 and 90% in year 2 (CAO supplementation beyond the statutory 70% minimum). After the statutory maximum of 104 weeks, further CAO continuation is conditional on active participation in re-integration efforts; non-participation triggers a 30-percentage-point reduction (from 90% to 60%).

### Scenario 10.1: Sick-pay year 1 at 100 percent

**GIVEN** a sickness notification on 2026-03-10 by an employee with monthly salary EUR 4,000

**WHEN** April 2026 payroll is calculated (within the first year of sickness)

**THEN** sick-pay continuation is 100% = EUR 4,000

### Scenario 10.2: Sick-pay year 2 at 90 percent

**GIVEN** the same employee still sick on 2027-03-11 (start of year 2 of sickness)

**WHEN** April 2027 payroll is calculated

**THEN** sick-pay continuation drops to 90% = EUR 3,600

### Scenario 10.3: Non-cooperation penalty in year 2

**GIVEN** an employee in year 2 of sick-leave who is determined not to cooperate with a suitable second-track re-integration trajectory as documented by the occupational health service

**WHEN** the non-cooperation penalty is applied

**THEN** sick-pay is reduced from 90% to 60% (a 30-percentage-point deduction) and the employee receives formal notice with a right of appeal to the works council
