---
status: draft
---

# CAO Rijk — Specifications

## REQ-001 — BBRA Salary Table Lookup by Schaal and Salarisnummer

**Narrative:** The module SHALL resolve a gross monthly salary for any combination of BBRA-schaal (1-18, including the subscales 15a, 16a, 17a, 18a for chief positions), salarisnummer (0-12 with documented extensions 13-15 for legacy cases), werktijdfactor, and peildatum. The lookup MUST honour the effective-from date of the relevant CAO-akkoord and apply structurele loonsverhogingen; eenmalige uitkeringen are handled separately by REQ-007.

### Scenarios

**001-01: Fulltime employee schaal 11 trede 6 at standard peildatum**
- GIVEN a fulltime employee (werktijdfactor 1.0) in schaal 11 salarisnummer 6 on peildatum 2026-01-15
- WHEN the salary is resolved via `resolveSalary(employmentId, peildatum)`
- THEN the gross monthly amount equals the BBRA-2025-akkoord schaal-11-trede-6 value (EUR 5,124.43) multiplied by werktijdfactor 1.0 = EUR 5,124.43

**001-02: Parttime employee with werktijdfactor 0.7**
- GIVEN a 0.7-werktijdfactor employee in schaal 9 salarisnummer 3 on peildatum 2025-12-31
- WHEN the salary is resolved
- THEN the gross monthly amount equals the BBRA-2024-akkoord schaal-9-trede-3 value multiplied by 0.7, rounded to the nearest cent half-to-even

**001-03: Invalid schaal lookup**
- GIVEN a request for schaal 19 (which does not exist in BBRA)
- WHEN the lookup is performed
- THEN a `SchaalNotFoundException` is raised with a list of valid schalen (1-18, 15a, 16a, 17a, 18a)

**001-04: Chief subscale schaal 15a**
- GIVEN a chief employee (directeur) in schaal 15a salarisnummer 4 on peildatum 2026-01-01
- WHEN the salary is resolved
- THEN the lookup uses the BBRA-chief-subscale table and returns the schaal-15a-trede-4 value

**001-05: CAO-akkoord effective-from date boundary**
- GIVEN a peildatum 2025-12-31 (prior to new CAO-2025-akkoord effective 2026-01-01)
- WHEN salary is resolved for the same schaal-salarisnummer combination
- THEN the value matches the BBRA-2024-akkoord (the prior akkoord), not the 2025-akkoord

**001-06: Extension salarisnummer 13-15 (legacy)**
- GIVEN an employee with documented aanstellingsdatum prior to 1990 with salarisnummer 15 (legacy extension)
- WHEN salary is resolved
- THEN the lookup respects the documented extension and returns the correct salary (not SchaalNotFoundException)

---

## REQ-002 — IKB-Rijk Budget Calculation at 16.37 Percent

**Narrative:** The module SHALL calculate the Individueel Keuzebudget Rijk as 16.37 percent of the salarissom over the IKB-jaar (calendar year), where the salarissom comprises the 12 monthly BBRA-salarissen plus structural toelagen (TOD, garantietoelage, persoonlijke toelage, etc.) but excludes incidentele uitkeringen and overuren. The percentage MUST be configurable per CAO-akkoord with a minimum floor of 16.37 as the 2024-akkoord baseline. Spend transactions update the remainingBudget in real-time.

### Scenarios

**002-01: Standard fulltime employee annual IKB**
- GIVEN an employee with 12 monthly salarissen of EUR 4,000 and a structural TOD of EUR 200 per month
- WHEN the annual IKB for calendar year 2026 is calculated
- THEN the salarissom = (12 × EUR 4,200) = EUR 50,400 and the budget = EUR 50,400 × 0.1637 = EUR 8,250.48

**002-02: Mid-year hire pro-rata IKB**
- GIVEN an employee who joined Rijk on 2026-04-01 with a monthly salary of EUR 3,800
- WHEN the IKB for calendar year 2026 is calculated
- THEN the salarissom = (9 × EUR 3,800) = EUR 34,200 and the budget = EUR 34,200 × 0.1637 = EUR 5,598.54 with the pro-rata factor reflecting the partial year (9/12)

**002-03: IKB-spend deduction on extra verlofuren**
- GIVEN an employee with current remaining IKB-budget EUR 7,000 and a uurloon of EUR 25
- WHEN an IKB-spend transaction for 36 hours of extra verlof is recorded
- THEN the deduction = 36 hours × EUR 25 / 156 hours per month = EUR 57.69 (rounding half-to-even) and the remaining budget becomes EUR 7,000 − EUR 57.69 = EUR 6,942.31

**002-04: IKB-percentage update per new CAO-akkoord**
- GIVEN a new CAO-akkoord effective 2027-01-01 with IKB-percentage 16.50%
- WHEN employees' IKB-budgets for IKB-jaar 2027 are calculated
- THEN the percentage used is 16.50%, not 16.37%

**002-05: Exclusion of incidentele uitkeringen from salarissom**
- GIVEN an employee with monthly salaris EUR 4,000, structural TOD EUR 200, and a one-off eenjarige uitkering EUR 500
- WHEN IKB-salarissom is calculated
- THEN only EUR 4,200 per month is included; the one-off EUR 500 is excluded, resulting in (12 × EUR 4,200) × 0.1637 = EUR 8,250.48

**002-06: Remaining budget cannot go negative**
- GIVEN an IKB-budget of EUR 5,000
- WHEN a spend transaction of EUR 6,000 is attempted
- THEN an `InsufficientIkbBudgetException` is raised and the transaction is rejected

---

## REQ-003 — FUWASYS Function Valuation and Resulting Schaal-Indicatie

**Narrative:** The module SHALL convert a FUWASYS-puntenscore (the sum of nine deelscores: kennis, complexiteit, contacten, sturing, afbreukrisico, bezwarende werkomstandigheden, lichamelijke inspanning, oogvereisten) into a salarisschaal-indicatie using the official conversietabel published in the FGR-handleiding. The indication MUST be a single schaal for scores within a uniek-schaal-bandbreedte and a schaal-range for scores on the bandgrens, in which case the manager's motivatie selects the definitive schaal.

### Scenarios

**003-01: Score within uniek-schaal-bandbreedte**
- GIVEN a FUWASYS-totaalscore of 38 punten (within the bandbreedte 36-40 for schaal 11)
- WHEN the schaal-indicatie is resolved
- THEN the result is schaal 11 (single, unambiguous) with no manager-discretion required

**003-02: Score exactly on bandgrens between scharlen**
- GIVEN a FUWASYS-totaalscore of exactly 40 punten on the bandgrens between schaal 11 and schaal 12
- WHEN the schaal-indicatie is resolved
- THEN the result is a schaal-range [11, 12] requiring a documented motivatie from the manager before payroll-finalisatie

**003-03: Missing deelscore validation**
- GIVEN a FUWASYS-score that lacks a sub-score for bezwarende werkomstandigheden (mandatory field)
- WHEN validation runs
- THEN an `IncompleteFuwasysException` is raised listing the missing deelscore and the submission is rejected

**003-04: All nine deelscores present and valid**
- GIVEN complete FUWASYS-deelscores: kennis=15, complexiteit=12, contacten=8, sturing=5, afbreukrisico=2, bezwarend=0, lichamelijk=0, oogvereisten=0
- WHEN totaalscore is computed = 42 punten
- THEN the schaal-indicatie resolves correctly using the FGR-conversietabel

**003-05: Score at lower boundary of first schaal**
- GIVEN a FUWASYS-score of 18 (at boundary for schaal 1 bandbreedte 16-22)
- WHEN the schaal-indicatie is resolved
- THEN if 18 is within a uniek-bandbreedte, schaal 1 is returned; if on bandgrens, schaal-range is returned

**003-06: Manager-motivatie required for bandgrens, must be persisted**
- GIVEN a score of 40 (bandgrens [11, 12]) with a manager motivatie "Rol veranderd naar meer sturing, schaal 12 justified"
- WHEN the FuwasysScore is saved
- THEN the managerMotivatie field is persisted and appears in payroll-audit-trail

---

## REQ-004 — Mandatory ABP Affiliation and Premie Calculation

**Narrative:** The module SHALL enforce that every CaoRijkEmployment has an active ABP-affiliation from the first day of aanstelling and SHALL calculate the OP-pensioenpremie, AAOP-arbeidsongeschiktheidspensioen, and ANW-hiaat-premie using the current ABP-premiepercentages, with the employer-aandeel and werknemer-aandeel split per the CAO-akkoord (currently 70% employer / 30% employee for OP). The pensioengrondslag is salaris minus franchise, with franchise threshold updated annually (EUR 17,545 for 2026).

### Scenarios

**004-01: New aanstelling with ABP-affiliation from first day**
- GIVEN a new aanstelling per 2026-06-01 with maandsalaris EUR 4,500
- WHEN the June payroll runs
- THEN the ABP-aansluiting is registered with ingangsdatum 2026-06-01 and the OP-premie is calculated over pensioengrondslag (EUR 4,500 − EUR 0 = EUR 4,500) at 24.7% total (70% employer = 16.29%, 30% employee = 7.41%)

**004-02: Aanstelling without ABP-aansluiting rejected**
- GIVEN an attempted aanstelling creation without a linked ABP-aansluiting
- WHEN the employment is saved
- THEN a `MissingAbpAffiliationException` is raised, a validation message is logged, and the aanstelling is not persisted

**004-03: Salary crosses ABP-franchise threshold**
- GIVEN an employee with salaris EUR 18,500 and ABP-franchise for 2026 = EUR 17,545
- WHEN pensioengrondslag is calculated
- THEN only EUR 18,500 − EUR 17,545 = EUR 955 enters the grondslag, and OP-premie = EUR 955 × 0.247 = EUR 236.09

**004-04: Franchise threshold updated per CAO-akkoord**
- GIVEN a new CAO-akkoord effective 2027-01-01 with ABP-franchise EUR 18,200
- WHEN pensioengrondslag for an employee earning EUR 19,000 is calculated for 2027
- THEN EUR 19,000 − EUR 18,200 = EUR 800 is used as the grondslag (using 2027 franchise, not 2026)

**004-05: AAOP en ANW-hiaat premies calculated alongside OP**
- GIVEN an employee with pensioengrondslag EUR 4,500, AAOP-percentage 2.15%, ANW-hiaat 0.98%
- WHEN monthly payroll deductions are calculated
- THEN total pension = EUR 4,500 × (0.247 + 0.0215 + 0.0098) = EUR 4,500 × 0.2783 = EUR 1,252.35 (split employer/employee per schijf)

**004-06: Franchise is zero or negative for low salaries**
- GIVEN an employee with salaris EUR 12,000 (below franchise EUR 17,545)
- WHEN pensioengrondslag is calculated
- THEN grondslag = max(0, EUR 12,000 − EUR 17,545) = EUR 0, and no pension-premie is deducted

---

## REQ-005 — Wachtgeld Entitlement for Legacy Ambtenaren

**Narrative:** The module SHALL determine wachtgeld-aanspraak for employees whose original aanstelling preceded the Wnra-conversie of 2020-01-01 and who therefore retain the overgangsrechtelijke wachtgeldregeling. The aanspraak depends on diensttijdjaren-bij-Rijk and leeftijd-bij-ontslag, with the regeling distinguishing between leeftijdsgebonden wachtgeld (for those 50+ with sufficient diensttijd) and reguliere wachtgeld. Employees hired post-Wnra are eligible for BWR (REQ-007) instead.

### Scenarios

**005-01: Leeftijdsgebonden wachtgeld for employee 50+ with sufficient diensttijd**
- GIVEN an employee born 1968-03-12 with aanstellingsdatum 1995-06-01, ontslagdatum 2026-09-30, ontslaggrond reorganisatie
- WHEN wachtgeld is calculated
- THEN the employee is 58 at ontslag, has 31 diensttijdjaren, and is eligible for leeftijdsgebonden wachtgeld covering the periode tot aan AOW-leeftijd (age 67.33 = ~85 months) at 70% of laatstgenoten bezoldiging in jaar 1, decreasing per official staffel in jaren 2+

**005-02: Employee aangesteld post-Wnra not eligible for wachtgeld**
- GIVEN an employee with aanstellingsdatum 2022-04-01 (post-Wnra-conversie 2020-01-01) requesting wachtgeld upon termination
- WHEN wachtgeld is requested
- THEN the response is `NotEligibleForWachtgeld` with reason `aanstelling-na-wnra-conversie` and a pointer to BWR-regeling instead

**005-03: Ontslag eigen-verzoek = zero wachtgeld regardless of diensttijd**
- GIVEN an employee with aanstellingsdatum 1998-01-01, 28 diensttijdjaren, ontslaggrond eigen-verzoek
- WHEN wachtgeld is calculated
- THEN the entitlement is EUR 0 (zero) regardless of age and diensttijd

**005-04: Reguliere wachtgeld for employee under 50**
- GIVEN an employee born 1980-05-15 with aanstellingsdatum 2002-01-01, ontslagdatum 2026-10-15, ontslaggrond discipline
- WHEN wachtgeld is calculated
- THEN the employee is 46 at ontslag and is eligible for reguliere wachtgeld (not leeftijdsgebonden) with a fixed duration per staffel (e.g., 12 months at 70% of laatstgenoten bezoldiging)

**005-05: Wachtgeld duration tapers from day of termination**
- GIVEN wachtgeld starting 2026-09-30 with duration 85 months (leeftijdsgebonden to AOW)
- WHEN the duration-end-date is calculated
- THEN it equals approximately 2032-10-30 (date when employee reaches AOW-leeftijd)

**005-06: Ontslaggrond medische-gronden eligible for wachtgeld**
- GIVEN an employee aangesteld 1999-03-01, aged 52, ontslaggrond medische-gronden
- WHEN wachtgeld eligibility is checked
- THEN the employee is eligible (ontslaggrond medische-gronden does not bar entitlement like eigen-verzoek)

---

## REQ-006 — Loondoorbetaling Bij Ziekte Conform Rijks-Regime

**Narrative:** The module SHALL implement the Rijks-loondoorbetalingsregeling bij ziekte, which deviates from the wettelijke 70-percent-rule by guaranteeing 100 percent in jaar 1 (first 12 months from ziekmelding) and 70 percent in jaar 2 (months 13+), both calculated over de bezoldiging (salaris + structurele toelagen; IKB excluded from the bezoldigingsbasis). IKB-opbouw continues at the full grondslag during year 2 reduced loondoorbetaling.

### Scenarios

**006-01: Year 1 loondoorbetaling at 100 percent bezoldiging**
- GIVEN a ziekmelding on 2026-02-15 of an employee with monthly bezoldiging (salaris + structurele toelagen) EUR 4,800, IKB-budget included but excluded from doorbetaling-basis
- WHEN the loondoorbetaling for the month of 2026-03-01 is calculated (first maand after wachtdag)
- THEN the doorbetaling equals EUR 4,800 (100% of bezoldiging in jaar 1)

**006-02: Year 2 loondoorbetaling at 70 percent, IKB-opbouw continues**
- GIVEN the same employee still ziek on 2027-02-16 (start of jaar 2, 12+ months post-ziekmelding)
- WHEN the loondoorbetaling for the next maand is calculated
- THEN the loondoorbetaling = EUR 4,800 × 0.70 = EUR 3,360 (70% of bezoldiging), and the IKB-opbouw continues at the full EUR 4,800 bezoldigingsbasis for that maand

**006-03: Re-integratietraject tweede-spoor loonwaarde 40 percent**
- GIVEN a re-integratie tweede-spoor-traject effective 2027-05-01 met loonwaarde 40% of the employee's regular bezoldiging (EUR 4,800 × 0.40 = EUR 1,920)
- WHEN the loondoorbetaling is calculated for 2027-05
- THEN the doorbetaling combines: (EUR 4,800 − EUR 1,920) × 0.70 = EUR 2,016 (the non-loonwaarde deel at 70%) + EUR 1,920 (actual verdiensten in het tweede spoor) = EUR 3,936 total

**006-04: Boundary between jaar 1 and jaar 2**
- GIVEN a ziekmelding on 2026-01-15
- WHEN calculating doorbetaling for 2026-12-15 (maand 12 after ziekmelding) and 2027-01-15 (maand 13)
- THEN 2026-12-15 uses 100% (still in jaar 1) and 2027-01-15 uses 70% (now in jaar 2)

**006-05: Re-integration return to work from tweede-spoor**
- GIVEN an employee returns to full capacity from tweede-spoor on 2027-08-01 (still within original année 2)
- WHEN loondoorbetaling is calculated from 2027-08-01 forward
- THEN the doorbetaling reverts to EUR 3,360 (70% of full bezoldiging, no longer reduced by loonwaarde)

---

## REQ-007 — BWR — Bovenwettelijke Werkloosheidsregeling Rijk

**Narrative:** The module SHALL determine the BWR-aanspraak op aansluitende uitkering en aanvullende uitkering bovenop de wettelijke WW, with duration and percentage depending on diensttijd-bij-Rijk and leeftijd-bij-ontslag. Employees with aanstelling prior to 2020-01-01 are eligible for wachtgeld (REQ-005) instead; post-Wnra employees fall under BWR.

### Scenarios

**007-01: Aansluitende uitkering with leeftijd 47 and 18 diensttijdjaren**
- GIVEN an employee with 18 diensttijdjaren and leeftijd 47 at ontslag wegens reorganisatie
- WHEN the BWR-aanspraak is calculated
- THEN the aansluitende uitkering covers the periode na de wettelijke WW-duur (e.g., 4 months WW) tot een totaalduur gelijk aan 18 months (diensttijdjaren), plus aanvullende uitkering for the first 6 months bringing the totaaluitkering tot 78% van laatstverdiende loon

**007-02: Aansluitende uitkering for employee 60+ with long diensttijd**
- GIVEN an employee aged 60 with 25 diensttijdjaren, ontslag wegens reorganisatie
- WHEN the BWR-aanspraak is calculated
- THEN the aansluitende uitkering covers tot AOW-leeftijd (approx. 7 jaar = 84 months) at the regulated percentage (e.g., 80% after WW-periode)

**007-03: Only aanvullende uitkering for young employee with short diensttijd**
- GIVEN an employee aged 35 with 4 diensttijdjaren, ontslag wegens reorganisatie
- WHEN the BWR-aanspraak is calculated
- THEN aansluitende uitkering does NOT apply (diensttijd threshold not met), only de aanvullende uitkering applies (6 maanden at regulated %, no aansluitende)

**007-04: Ontslaggrond own-request excludes BWR**
- GIVEN an employee with 20 diensttijdjaren and ontslaggrond eigen-verzoek
- WHEN BWR-aanspraak is requested
- THEN the result is `NotEligibleForBwr` with reason `ontslaggrond-eigen-verzoek`

**007-05: BWR duration tapers from WW-start**
- GIVEN WW-duration 4 months (statutory for this employee), BWR-aansluitende starting after WW-uitputting
- WHEN the total uitkeringsperiode is calculated
- THEN it equals WW-months (4) + BWR-aansluitende-months (e.g., 18) + BWR-aanvullende (6) = 28 months total, or until AOW-leeftijd, whichever is longer

**007-06: Ontslag discipline => reduced or no BWR**
- GIVEN an employee terminated for discipline reasons
- WHEN BWR-aanspraak is calculated
- THEN the aanspraak is reduced (e.g., by 50%) or zero depending on the severity flagged in ontslaggrond

---

## REQ-008 — RVU-Reiskostenforfait and Reiskostenvergoeding

**Narrative:** The module SHALL calculate the reiskostenvergoeding woon-werkverkeer using the RVU-reiskostenforfait-staffel for openbaar-vervoer-traject and the kilometervergoeding for eigen-vervoer, with the gerichte vrijstelling capped at EUR 0.23 per kilometer for 2026, the bovenmatige deel taxed as loon. OV-jaarpassen are treated as volledig onbelast under de OV-vrijstelling.

### Scenarios

**008-01: Eigen-vervoer 28 km daily commute, EUR 0.23/km capped**
- GIVEN an employee with woon-werk-enkelreis 28 km met eigen vervoer
- WHEN the maandvergoeding is calculated with 214 werkdagen per jaar
- THEN the vergoeding = (28 km × 2 × 214 werkdagen / 12 maanden) × EUR 0.23 = EUR 229.81/month (capped at EUR 0.23/km, the gerichte vrijstelling maximum for 2026)

**008-02: Werkgever vergoeding EUR 0.30/km with bovenmatige deel taxed**
- GIVEN the same 28 km employee with werkgever-vergoeding EUR 0.30/km
- WHEN de vergoeding wordt verwerkt
- THEN EUR 0.23/km is onbelast (gerichte vrijstelling) = EUR 229.81/month, and EUR 0.07/km is gerapporteerd als belast loon = EUR 70.21/month taxable wage

**008-03: OV-jaartrajectkaart volledig onbelast**
- GIVEN an employee with OV-jaartrajectkaart van EUR 2,400 (annual pass)
- WHEN de vergoeding wordt verwerkt
- THEN de volledige EUR 2,400 is onbelast under de OV-vrijstelling regardless van werkelijke kilometerafstand

**008-04: RVU-forfait update per CAO-akkoord**
- GIVEN a new CAO-akkoord effective 2027-01-01 updating the RVU-forfait-staffel for OV and kilometervergoeding
- WHEN reiskostenvergoeding is calculated for dates after 2027-01-01
- THEN the new staffel is used

**008-05: Short commute under RVU-minimum**
- GIVEN an employee with woon-werk-afstand 5 km, eigen-vervoer
- WHEN reiskostenvergoeding is calculated
- THEN if 5 km is below the RVU-minimum-afstand (e.g., 6 km), no vergoeding is issued

**008-06: Partial-year employee pro-rata reiskostenvergoeding**
- GIVEN an employee who started 2026-06-01 with 28 km eigen-vervoer commute
- WHEN reiskostenvergoeding for 2026 is calculated
- THEN the vergoeding is pro-rata for 7 months (06–12), not full 12 months

---

## REQ-009 — Detacheringsregels Binnen en Buiten Rijk

**Narrative:** The module SHALL distinguish detachering-binnen-Rijk (where the uitlenende dienst (doorbetaling continueert en de inlener factuurt) van detachering-buiten-Rijk (where the werkgever-werknemer-relatie ongewijzigd blijft but the werkplek is external) and SHALL apply the juiste premie- en pensioenregels for each type.

### Scenarios

**009-01: Interne detachering between ministeries for 1 year**
- GIVEN an interne detachering van Ministerie A naar Ministerie B effective 2026-03-01 tot 2027-02-28
- WHEN the detacheringsbesluit wordt vastgelegd
- THEN salaris, IKB-opbouw, ABP-aansluiting remain on Ministerie A's payroll, Ministerie B receives a doorbelasting on basis van werkelijke loonkosten + opslag (e.g., +15%), and the detachering ends on 2027-02-28

**009-02: Externe detachering naar niet-Rijks organisatie**
- GIVEN een externe detachering naar een NGO effective 2026-04-01 till 2027-03-31
- WHEN the doorbetaling wordt berekend
- THEN ABP-opbouw, IKB-opbouw, en BWR-rechten blijven volledig doorlopen on kosten van de uitlenende dienst (the Rijks-werkgever), the external org pays de daadwerkelijke werknemerskosten, and the Rijks-dienst covers pension/benefits

**009-03: Detachering without einddatum rejected**
- GIVEN a poging tot detachering-registration zonder vastgelegde einddatum
- WHEN validatie loopt
- THEN een `OpenEindeDetacheringException` is thrown met message "Detacheringsbesluiten verplicht een einddatum hebben conform de Rijks-detacheringsrichtlijn" and the record is not persisted

**009-04: Detachering einddatum change / extension**
- GIVEN an existing detacheringsbesluit with einddatum 2027-02-28
- WHEN the einddatum is extended to 2027-05-31 (before original end)
- THEN the detacheringsbesluit is updated, a new audit-trail entry is logged, and affected payroll/pension systems are notified

**009-05: Nested detachering (detachering of detached employee) prevented**
- GIVEN an employee currently in interne detachering
- WHEN an attempt is made to add a second detacheringsbesluit before the first ends
- THEN an `OverlappingDetacheringException` is raised and the second is rejected

**009-06: Pension contributions during externe detachering**
- GIVEN an employee on externe detachering with salaris EUR 4,000
- WHEN ABP-premie is calculated
- THEN the full pension premie (including franchise adjustment) continues at the uitlenende dienst's cost, not the external employer

---

## REQ-010 — Generieke Functie Versus Sectorgebonden Functie Attribuut

**Narrative:** The module SHALL classify every functievervulling as either generiek (uit de FGR — Functiegebouw Rijk, beschikbaar voor alle ministeries) of sectorgebonden (specifiek voor a dienstonderdeel such as Belastingdienst-inspecteur, DJI-bewaarder, KMar-onderofficier) en SHALL koppelen sectorgebonden functies aan de juiste aanvullende-cao-bepalingen.

### Scenarios

**010-01: Generiek FGR-functie "Senior beleidsmedewerker"**
- GIVEN a functievervulling "Senior beleidsmedewerker" gekoppeld aan FGR-functiefamilie "Beleid"
- WHEN de classificatie loopt
- THEN het resultaat is `generiek` met FGR-referentie 14.2, available to all ministeries, no sector-specific regels

**010-02: Sectorgebonden DJI-bewaarder functie**
- GIVEN a functievervulling "Penitentiair inrichtingswerker A" bij dienstonderdeel DJI
- WHEN de classificatie loopt
- THEN het resultaat is `sectorgebonden` met `sectorgebonden-cao` = "cao-dji", linked to aanvullende DJI-bepalingen voor onregelmatige dienst, piket, and gevaarsilkostentoeslag

**010-03: Conflict FGR-classification vs. dienstonderdeel**
- GIVEN een functievervulling "Senior beleidsmedewerker" (generiek FGR-functie 14.2) bij dienstonderdeel DJI with claimed piketdienst
- WHEN validatie loopt
- THEN een `FunctieClassificatieConflictException` is thrown met aanbeveling: "FGR-beleidsmedewerker is generiek; DJI-piketdienst is sectorgebonden. Please reclassify as DJI-specific function."

**010-04: Sectorgebonden Belastingdienst-inspecteur**
- GIVEN a functievervulling "Inspecteur Belastingdienst" bei dienstonderdeel Belastingdienst
- WHEN de classificatie loopt
- THEN het resultaat is `sectorgebonden` met `sectorgebonden-cao` = "cao-belastingdienst", linked to aanvullende bepalingen voor zakelijk-vervoermiddel-vergoeding en jeugd-werkloon-regeling

**010-05: KMar-onderofficier sectorgebonden**
- GIVEN een functievervulling "Onderofficier Matrozen" bij dienstonderdeel Koninklijke Marine (KMar)
- WHEN de classificatie wordt gevalideerd
- THEN het resultaat is `sectorgebonden` met `sectorgebonden-cao` = "cao-kmar", linked to militaire aanvullende bepalingen

**010-06: Functie reclassification from sectorgebonden to generiek**
- GIVEN a functievervulling previously classified as sectorgebonden "DJI-bewaarder"
- WHEN the role is changed to "Administratief medewerkerk" (FGR-generiek)
- THEN the functieClassificatie is updated to `generiek`, the `sectorgebonden-cao` field is set to null, and a FunctieClassificationChanged event is emitted to notify rostering-planning (which may disable piketinroostering if no longer applicable)
