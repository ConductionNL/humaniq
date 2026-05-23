---
status: proposed
placement: SETTING
placement_path: "Configuratie › CAO's & regelingen"
---

# Proposal: CAO Ziekenhuizen — NVZ Arbeidsvoorwaarden Module

## Summary

Implement the collective labour agreement (CAO) for general and categorical hospitals under the Nederlandse Vereniging van Ziekenhuizen (NVZ), covering approximately 200,000 employees across ~60 algemene and 8 categorale ziekenhuizen. The module encodes hospital-specific salary structures, shift allowances, special service arrangements, and pension administration.

## Features

### FWG 3.0 Functiewaardering en Schaal-indicatie
**Demand: 1** (Critical)

Convert FWG 3.0 (Functiewaardering Gezondheidszorg) totaalscores into FWG-functiegroepen and salarisschalen. The module must handle the complete 9-part scoring rubric (kennis, zelfstandigheid, sociale-vaardigheden, risico/verantwoordelijkheid/invloed, expressievaardigheid, bewegingsvaardigheid, oplettendheid, overige-functie-eisen, inconveniënten) with validation against FWG-referentiefuncties.

**Why**: FWG is the sector-standard classification for healthcare roles and determines salary placement across all downstream payroll calculations.

### ORT-berekening per Gewerkt Uur
**Demand: 1** (Critical)

Calculate onregelmatigheidstoeslagen (ORT) — shift allowances for irregular hours — using the day-of-week and time-of-day matrix. The module must apply the current NVZ percentages (0% Mon-Fri 06:00–22:00; 47% Mon-Fri 00:00–06:00 and 22:00–24:00; 38% Sat 06:00–22:00; 49% Sat 00:00–06:00 and 22:00–24:00; 60% Sun/holiday all hours) with minute-by-minute precision at shift transitions.

**Why**: ORT is the largest variable-pay component in hospital payroll and is timing-sensitive across DST boundaries (Europe/Amsterdam timezone).

### Bereikbaarheidsdienst-Vergoeding
**Demand: 0.9** (High)

Register and reimburse availability services (whereby staff remain available at home for emergency calls), with a fixed hourly rate per role and a separate call-out fee (base salary + ORT for actual hours worked including travel time).

**Why**: Availability is a 24/7 requirement for clinical roles but has no standard private-sector equivalent; without dedicated encoding, payroll teams miscalculate.

### Slaapdienst-Conversie
**Demand: 0.8** (High)

Convert sleep-duty hours (on-premises but inactive, with sleep facility) to compensated work-hours using the FWG conversion tables. The module must handle interruptions (call-outs) which count as full work-hours separately.

**Why**: Sleep-duty is labor law-compliant rest but requires careful hour-accounting to avoid overtime disputes.

### Overuren Chirurgische Dienst
**Demand: 0.85** (High)

For surgical and anaesthetic staff (operatieassistenten, anaesthesiemedewerkers, chirurgen in dienstverband), apply 100% hourly reimbursement for overtime above the daily shift (not weekly), in contrast to the standard 50% rule for non-surgical staff.

**Why**: Surgical teams face unpredictable case extensions and the CAO's 100% rule reflects labour-market pressure and safety-critical fatigue.

### PFZW-aansluiting en Premie-afdracht
**Demand: 1** (Critical)

Register each employment with PFZW (Pensioenfonds Zorg en Welzijn) on day one and calculate pension premiums (current 25.8% over the benefit base) with 50/50 employer-employee split and the annual franchise (current EUR 17,545, scaled pro-rata for part-time).

**Why**: PFZW is mandatory for all hospital employment and is shared across healthcare sectors (VVT, GGZ, gehandicaptenzorg, jeugdzorg); miscalculation triggers auditor findings.

### Bovenwettelijke ADV-uren met Flex-Conversie
**Demand: 0.9** (High)

Administer supra-statutory working-time reduction (ADV) as an annual entitlement (typically 96 hours for full-time) accrued per hour worked, with quarterly flexibility to convert to cash (at current salary), extra vacation days, or structured working-time reduction.

**Why**: ADV is a unique Dutch healthcare benefit with quarterly settlement deadlines; missing a quarter's election can trigger employee disputes.

### Tijd-voor-Tijd Compensatiebank
**Demand: 0.75** (Medium)

Maintain an alternative compensation bank where staff can elect to bank ORT and overtime as time-off (at 1:1 + ORT fraction) instead of cash, with a maximum balance (80 hours) and mandatory use within 12 months to avoid automatic payout.

**Why**: Many hospital staff prefer scheduled time-off to flat cash; without this option, payroll becomes a friction point.

### Diensttijdverhoudingen voor Parttime Medewerkers
**Demand: 0.95** (High)

Apply pro-rata scaling to all nominal entitlements (salary, vacation, ADV, pension base) for staff working less than full-time (0.0–1.0 factor), with the explicit rule that ORT percentages are NOT scaled (part-timers earn the same % ORT as full-timers for the hours they work).

**Why**: Part-time roles are growing in hospitals; incorrect scaling cascades into salary disputes and compliance failures.

### Loondoorbetaling bij Ziekte
**Demand: 0.8** (High)

Implement sick-pay continuation at 100% year 1 and 90% year 2 (beyond the statutory 70% minimum), with the CAO clause that after 104 weeks, continuation beyond statutory limits requires active re-integration cooperation (with 30-point deduction if cooperation lapses).

**Why**: Sick-pay is a complex compliance layer with WIA interaction; encoding the CAO rules avoids manual HR escalations.

## Placement

**Type**: SETTING  
**Path**: Configuratie › CAO's & regelingen  
**Rationale**: CAO Ziekenhuizen is configuration data (rate tables, salary scales, allowance rules) consumed by payroll-engine-nl and rostering-planning, not a user-facing workflow. It lives alongside CAO Rijk, CAO VVT, etc. as a single `Configuratie › CAO's & regelingen` setting.

## Stakeholders

- **HR-administrateur** (Primary): Registers new employments, classifies FWG functiegroepen, processes part-time changes, enters FWG classification disputes.
- **Roosterplanner** (Daily): Enters shift schedules via rostering-planning; must see projected ORT and availability costs before roster lock.
- **Salarisadministrateur** (Monthly): Validates payroll output, corrects ORT claims retroactively (common due to roster changes), handles ORT disputes.
- **Leidinggevende** (Approver): Authorizes overtime claims, validates availability call-outs, approves ADV elections.
- **Pensioen-specialist** (Specialist): Handles PFZW exceptions (dual UMC employment, jubilee calculations).
- **CAO-onderhandelaar** (Config owner): NVZ staff and hospital CAO-implementation teams set and maintain scale tables, ORT percentages, PFZW premiums.
- **Auditor** (Read-only): External accountants and insurers review aggregated payroll reports.
- **Medewerker** (Self-service): Views own payslip with ORT breakdown, ADV balance, time-off-bank, and ADV election options via hrmq self-service.

## Integration Points

- **payroll-engine-nl**: Reads employment, salary scale, ORT claims, availability, sleep-duty, overtime, ADV and pension data to generate monthly payroll runs.
- **rostering-planning**: Posts shift definitions that generate ORT claims, availability duty, and sleep-duty events; queries projected ORT cost for budget-validation.
- **verlof-administratie**: Consumes ADV and time-off-bank updates for leave-card synchronization.
- **contract-generatie**: Queries salary indicators at hire, internal moves, and part-time changes.
- **declaratie-aan-zorgverzekeraar**: Reports aggregated personnel costs and ORT-component separately for DBC rate-setting.
- **pfzw-aansluiting**: Shared pension-administration capability; cao-ziekenhuizen registers employment and calculates premiums; pfzw-aansluiting handles cross-sector deduplication and PFZW submissions.
- **pre-employment-screening**: Validates BIG registration for clinical roles (FWG-50+).

## Cross-sector Notes

- **UMC employment**: Academic hospitals (UMC's) follow separate CAO-UMC with different salary structures and PFZW pension arrangements; this spec does NOT apply to UMC staff. Employers with dual UMC+NVZ payroll will run separate employment records in cao-umc and cao-ziekenhuizen respectively.
- **CAO VVT / CAO GGZ**: Share the PFZW foundation but have materially different role classifications and allowance matrices; no code reuse is planned.
- **Compliance baseline**: The implementation must enforce the CAO exactly as published in the Staatscourant after algemeen verbindendverklaring (avv); deviations or early amendments are not accommodated — policy changes flow through CAO amendments and new Staatscourant registrations.

## Data Classification

Salary and benefit data is GDPR-sensitive; the module follows NEN 7510 (healthcare information security) and NEN 7512/7513 (audit-logging requirements) for all payroll-related operations.
