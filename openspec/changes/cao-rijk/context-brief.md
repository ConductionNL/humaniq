---
status: draft
---
# CAO Rijk — Rijksambtenaren Arbeidsvoorwaarden Module

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › CAO's & regelingen

**Rationale:** CAO-ruleset.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `cao-rijk` capability implements the full collective labour agreement for civil servants employed by the Dutch national government (Rijksambtenaren), the largest single CAO population in the Netherlands at roughly 130,000 employees spread across 11 ministries, the High Councils of State, the Council of State, and a long tail of inspectorates and agencies. Unlike sectoral CAOs negotiated between employer associations and unions, the CAO Rijk is concluded between the Minister of the Interior and Kingdom Relations (BZK) acting as central employer and the joint federations of civil servant unions, and it has the additional constitutional weight of binding every body that falls under the scope of the Ambtenarenwet 2017 / Wnra. The module must therefore not only reproduce the salary tables and leave entitlements, but encode the structural peculiarities that distinguish Rijk employment from private-sector or municipal civil-servant employment: the Individueel Keuzebudget Rijk (IKB-Rijk) at 16.37 percent rather than the private-sector 8 percent, the mandatory affiliation with pension fund ABP rather than a sectoral fund, the Functiewaarderingssysteem FUWASYS rather than ORBA or HR21, and the bovenwettelijke Werkloosheidsregeling Rijk (BWR) on top of statutory WW.

The module powers hrmq-rijk-employer customers, primarily the central HR shared service organisations P-Direkt and the Uitvoeringsorganisatie Bedrijfsvoering Rijk (UBR), but also the smaller Rijk-affiliated bodies that run their own HR (Hoge Raad, AIVD, Raad van State, Algemene Rekenkamer). It is consumed by payroll-engine-nl for monthly salary runs, by the leave-administration capability for IKB-spending and verlofkaart calculations, by the rostering capability for shift differentials in operational services (DJI prison guards, Belastingdienst, KMar), and by the contract-generation capability when employees move between departments via the verplichte interne mobiliteit framework. The brief deliberately scopes only the CAO-Rijk arbeidsvoorwaardelijke laag; the underlying Wnra-conversie (de juridische omzetting van ambtelijke aanstelling naar arbeidsovereenkomst per 1 januari 2020) is assumed as a precondition and modelled in the separate `wnra-conversion` capability.

## Data Model

The core entity is `CaoRijkEmployment`, which extends the base `Employment` entity with rijks-specific fields: `salarisschaal` (BBRA-schaal 1 through 18, with subscales for chief positions), `salarisnummer` (anciënniteitstrede within the schaal, 0-12 with extensions), `functiefamilie` (one of the 53 generic function families defined in the FGR — Functiegebouw Rijk), `functietypering` (the specific role within the family, mapped to a FUWASYS-score and resulting schaal-indicatie), `ministerie` and `dienstonderdeel` (organisational placement), `aanvangsdatum-overheidsdienst` (used for jubilea, BWR-entitlement and wachtgeld), `aanvangsdatum-huidige-functie` (used for in-functie-anciënniteit and verticale mobiliteit), and `werktijdfactor` (a decimal between 0.0 and 1.0 expressing parttime ratio, where 1.0 equals the 36-hour normweek).

Supporting entities include `FuwasysScore` (the formal point-score result with sub-scores for kennis, complexiteit, contacten, sturing, afbreukrisico, bezwarende werkomstandigheden, lichamelijke inspanning and oogvereisten), `IkbBudget` (with line-items for vakantietoelage, eindejaarsuitkering, levensloopbijdrage-restant and bovenwettelijke vakantie-uren bought or sold), `BwrEntitlement` (the bovenwettelijke werkloosheidsregeling claim with diensttijdjaren and resulting duration in months), `WachtgeldEntitlement` (the legacy entitlement for ambtenaren with appointments predating the 2020 Wnra-conversie), and `DetacheringsBesluit` (capturing both detachering binnen Rijk and detachering buiten Rijk with their distinct doorbetalings-regimes).

Reference data lives in `BbraSalarisTabel` (refreshed at each CAO-akkoord with effective-from dates), `FgrFunctiefamilie` (the 53 families with their schaal-bandbreedte), and `AbpPremietabel` (mirrored from ABP for the OP-pensioenpremie, AAOP-arbeidsongeschiktheidspensioen, ANW-hiaat and pre-pensioenregeling-overgangsrecht). All money fields use `Money` value objects with currency EUR and round-half-to-even arithmetic; all date fields use `LocalDate` to avoid timezone drift for dates that are inherently civil-calendar (aanstellingsdatum, peildatum).

The model deliberately separates `Aanstelling` (the legal employment relationship) from `Functievervulling` (the specific role being performed), because rijksambtenaren can be in tijdelijke andere functie (TAF) or waarnemen without their formal aanstelling changing — a distinction that matters for salary continuation, BWR-rights and pension accrual.

## Requirements

### REQ-001 — BBRA salary table lookup by schaal and salarisnummer

The module SHALL resolve a gross monthly salary for any combination of BBRA-schaal (1-18, including the subscales 15a, 16a, 17a, 18a for chiefs), salarisnummer (0-12 with documented extensions 13-15 for legacy cases), werktijdfactor and peildatum. The lookup MUST honour the effective-from date of the relevant CAO-akkoord and apply structurele loonsverhogingen, eenmalige uitkeringen are handled by REQ-007.

- GIVEN a fulltime employee in schaal 11 salarisnummer 6 on peildatum 2026-01-15, WHEN the salary is resolved, THEN the gross monthly amount equals the BBRA-2025-akkoord schaal-11-trede-6 value (currently EUR 5,124.43) multiplied by werktijdfactor 1.0.
- GIVEN a 0.7-werktijdfactor employee in schaal 9 salarisnummer 3 on peildatum 2025-12-31, WHEN the salary is resolved, THEN the gross monthly amount equals the BBRA-2024-akkoord value multiplied by 0.7, rounded to the nearest cent half-to-even.
- GIVEN a request for schaal 19 (which does not exist in BBRA), WHEN the lookup is performed, THEN a `SchaalNotFoundException` is raised with the list of valid schalen.

### REQ-002 — IKB-Rijk budget calculation at 16.37 percent

The module SHALL calculate the Individueel Keuzebudget Rijk as 16.37 percent of the salarissom over the IKB-jaar, where the salarissom comprises the 12 monthly BBRA-salarissen plus structural toelagen (TOD, garantietoelage, persoonlijke toelage) but excludes incidentele uitkeringen and overuren. The percentage MUST be configurable per CAO-akkoord with a minimum of 16.37 as the 2024-akkoord floor.

- GIVEN an employee with 12 monthly salarissen of EUR 4,000 and a structural TOD of EUR 200 per month, WHEN the annual IKB is calculated, THEN the budget equals (12 × 4,200) × 0.1637 = EUR 8,250.48.
- GIVEN an employee who joined Rijk on 2026-04-01 with a monthly salary of EUR 3,800, WHEN the IKB for calendar year 2026 is calculated, THEN the budget equals (9 × 3,800) × 0.1637 = EUR 5,598.54 with the pro-rata factor reflecting the partial year.
- GIVEN an IKB-spend on extra verlof of 36 hours at the employee's uurloon, WHEN the budget is updated, THEN the remaining budget reflects the deduction at the uurloon-conversie-factor of 1/156 of the monthly salary per hour for a 36-hour normweek.

### REQ-003 — FUWASYS function valuation and resulting schaal-indicatie

The module SHALL convert a FUWASYS-puntenscore (the sum of nine deelscores) into a salarisschaal-indicatie using the official conversietabel published in the FGR-handleiding. The indication MUST be a single schaal for scores within a uniek-schaal-bandbreedte and a schaal-range for scores on the bandgrens, in which case the manager's motivatie selects the definitive schaal.

- GIVEN a FUWASYS-totaalscore of 38 punten, WHEN the schaal-indicatie is resolved, THEN the result is schaal 11 (within the bandbreedte 36-40) with no manager-discretion required.
- GIVEN a FUWASYS-totaalscore of exactly 40 punten on the bandgrens between schaal 11 and schaal 12, WHEN the schaal-indicatie is resolved, THEN the result is a schaal-range [11, 12] requiring a documented motivatie before payroll-finalisatie.
- GIVEN a FUWASYS-score that lacks a sub-score for bezwarende werkomstandigheden, WHEN validation runs, THEN an `IncompleteFuwasysException` is raised listing the missing deelscore.

### REQ-004 — Mandatory ABP affiliation and premie calculation

The module SHALL enforce that every CaoRijkEmployment has an active ABP-affiliation from the first day of aanstelling and SHALL calculate the OP-pensioenpremie, AAOP-premie and ANW-hiaat-premie using the current ABP-premiepercentages, with the employer-aandeel and werknemer-aandeel split per the CAO-akkoord (currently 70/30 for OP).

- GIVEN a new aanstelling per 2026-06-01 with a maandsalaris of EUR 4,500, WHEN the June payroll runs, THEN the ABP-aansluiting is registered with ingangsdatum 2026-06-01 and the OP-premie is calculated over the pensioengrondslag (salaris minus franchise) at the current 24.7 percent total, with werknemersdeel 30 percent.
- GIVEN an attempted aanstelling without ABP-aansluiting, WHEN the employment is created, THEN a `MissingAbpAffiliationException` is raised and the aanstelling is not persisted.
- GIVEN an employee crossing the ABP-franchise threshold (currently EUR 17,545 for 2026), WHEN the pensioengrondslag is calculated, THEN only the salaris boven de franchise enters the grondslag.

### REQ-005 — Wachtgeld entitlement for legacy ambtenaren

The module SHALL determine wachtgeld-aanspraak for employees whose original aanstelling preceded the Wnra-conversie of 2020-01-01 and who therefore retain the overgangsrechtelijke wachtgeldregeling. The aanspraak depends on diensttijdjaren-bij-Rijk and leeftijd-bij-ontslag, with the regeling distinguishing between leeftijdsgebonden wachtgeld (for those 50+ with sufficient diensttijd) and reguliere wachtgeld.

- GIVEN an employee born 1968-03-12 with aanstellingsdatum 1995-06-01, ontslagdatum 2026-09-30 and ontslaggrond reorganisatie, WHEN the wachtgeld is calculated, THEN the entitlement covers de periode tot aan AOW-leeftijd (the leeftijdsgebonden variant) at 70 percent of laatstgenoten bezoldiging for the first year decreasing per the official staffel.
- GIVEN an employee aanstellingsdatum 2022-04-01 (post-Wnra), WHEN wachtgeld is requested, THEN the response is `NotEligibleForWachtgeld` with reason `aanstelling-na-wnra-conversie` and a pointer to the BWR-regeling instead.
- GIVEN an employee with ontslaggrond eigen-verzoek, WHEN wachtgeld is calculated, THEN the entitlement is zero regardless of diensttijd.

### REQ-006 — Loondoorbetaling bij ziekte conform Rijks-regime

The module SHALL implement the Rijks-loondoorbetalingsregeling bij ziekte, which deviates from the wettelijke 70-percent-rule by guaranteeing 100 percent in jaar 1 and 70 percent in jaar 2, both calculated over de bezoldiging (salaris + structurele toelagen, IKB excluded for the bezoldigingsbasis).

- GIVEN a ziekmelding on 2026-02-15 of an employee with monthly bezoldiging EUR 4,800, WHEN the loondoorbetaling for the month following the wachtdag is calculated, THEN the doorbetaling equals EUR 4,800 (100 percent in jaar 1).
- GIVEN the same employee still ziek on 2027-02-16 (start of jaar 2), WHEN the loondoorbetaling for the next maand is calculated, THEN the doorbetaling equals EUR 3,360 (70 percent), with the IKB-opbouw continuing at the full bezoldiging-grondslag.
- GIVEN a re-integratie tweede-spoor-traject met loonwaarde 40 percent on 2027-05-01, WHEN the loondoorbetaling is calculated for that maand, THEN the doorbetaling combines the 70-percent-bezoldigingsdoorbetaling for the niet-loonwaarde-deel and the actual verdiensten in het tweede spoor.

### REQ-007 — BWR — Bovenwettelijke Werkloosheidsregeling Rijk

The module SHALL determine the BWR-aanspraak op aansluitende uitkering en aanvullende uitkering bovenop de wettelijke WW, with duration depending on diensttijd-bij-Rijk and leeftijd-bij-ontslag.

- GIVEN an employee with 18 diensttijdjaren and leeftijd 47 at ontslag wegens reorganisatie, WHEN the BWR-aanspraak is calculated, THEN the aansluitende uitkering covers the periode na de WW-duur tot een totaalduur gelijk aan diensttijdjaren plus aanvullende uitkering for the first 6 months bringing the totaaluitkering tot 78 percent van laatstverdiende loon.
- GIVEN an employee 60 jaar met 25 diensttijdjaren, WHEN the BWR-aanspraak is calculated, THEN de aansluitende uitkering covers tot AOW-leeftijd at the regulated percentage.
- GIVEN an employee aged 35 with 4 diensttijdjaren, WHEN the BWR-aanspraak is calculated, THEN only de aanvullende uitkering applies (6 maanden, geen aansluitende), because diensttijd is below the threshold for aansluitende uitkering.

### REQ-008 — RVU-reiskostenforfait and reiskostenvergoeding

The module SHALL calculate the reiskostenvergoeding woon-werkverkeer using the RVU-reiskostenforfait-staffel for openbaar-vervoer-traject and the kilometervergoeding for eigen-vervoer, with the gerichte vrijstelling capped at the fiscally allowed EUR 0.23 per kilometer for 2026 and the bovenmatige deel taxed as loon.

- GIVEN an employee with woon-werk-enkelreis 28 km met eigen vervoer, WHEN the maandvergoeding is calculated, THEN the vergoeding equals (28 × 2 × 214 werkdagen / 12 maanden) × EUR 0.23 = EUR 229.81.
- GIVEN the same employee with bovenmatige werkgevers-vergoeding van EUR 0.30 per km, WHEN de fiscale verwerking runs, THEN EUR 0.07 per km is gerapporteerd als belast loon op de loonstrook.
- GIVEN an employee met OV-jaartrajectkaart van EUR 2,400, WHEN de vergoeding wordt verwerkt, THEN de volledige vergoeding is onbelast under de OV-vrijstelling regardless van kilometerafstand.

### REQ-009 — Detacheringsregels binnen en buiten Rijk

The module SHALL distinguish detachering-binnen-Rijk (waar de uitlenende dienst de doorbetaling continueert en de inlener factuurt) van detachering-buiten-Rijk (waar de werkgever-werknemer-relatie ongewijzigd blijft maar de werkplek extern is) en SHALL toepassen de juiste premie- en pensioenregels.

- GIVEN an interne detachering van Ministerie A naar Ministerie B voor 1 jaar, WHEN de detacheringsbesluit wordt vastgelegd, THEN salaris-, IKB- en ABP-aansluiting blijven op Ministerie A en B ontvangt een doorbelasting op basis van werkelijke loonkosten + opslag.
- GIVEN een externe detachering naar een niet-Rijks-organisatie, WHEN de doorbetaling wordt berekend, THEN ABP-opbouw, IKB-opbouw en BWR-rechten blijven volledig doorlopen op kosten van de uitlenende dienst.
- GIVEN a poging tot detachering zonder vastgelegde einddatum, WHEN validatie loopt, THEN een `OpenEindeDetacheringException` wordt gegooid omdat detacheringsbesluiten verplicht een einddatum hebben conform de Rijks-detacheringsrichtlijn.

### REQ-010 — Generieke functie versus sectorgebonden functie attribuut

The module SHALL classify every functievervulling as either generiek (uit de FGR — Functiegebouw Rijk, beschikbaar voor alle ministeries) of sectorgebonden (specifiek voor een dienstonderdeel zoals Belastingdienst-inspecteur, DJI-bewaarder of KMar-onderofficier) en SHALL koppelen sectorgebonden functies aan de juiste aanvullende-cao-bepalingen.

- GIVEN a functievervulling "Senior beleidsmedewerker" gekoppeld aan FGR-functiefamilie "Beleid", WHEN de classificatie loopt, THEN het resultaat is `generiek` met FGR-referentie 14.2.
- GIVEN a functievervulling "Penitentiair inrichtingswerker A" bij dienstonderdeel DJI, WHEN de classificatie loopt, THEN het resultaat is `sectorgebonden` met verwijzing naar de aanvullende DJI-cao-bepalingen voor onregelmatige dienst en piket.
- GIVEN een conflict tussen FGR-classificatie en dienstonderdeel (bijv. een FGR-beleidsmedewerker bij DJI met geclaimde piketdienst), WHEN validatie loopt, THEN een `FunctieClassificatieConflictException` wordt gegooid met de aanbeveling tot herclassificatie.

## Standards & Sources

The implementation is grounded in primary sources only, never afgeleide handboeken. The canonical CAO-tekst is the **CAO Rijk 2024-2025** as published by BZK on rijksoverheid.nl, met de laatste tussenakkoorden gepubliceerd in de Staatscourant. Het **Bezoldigingsbesluit Burgerlijke Rijksambtenaren (BBRA 1984)** levert de salaristabellen en de structuur van schalen en salarisnummers; hoewel het BBRA per Wnra-conversie zijn publiekrechtelijke status verloor, leeft de inhoud voort in de CAO-bijlagen. Het **Functiegebouw Rijk (FGR)** en de bijbehorende **FUWASYS-handleiding** worden onderhouden door UBR/HR en zijn beschikbaar via het FGR-portaal. De **ABP-pensioenreglement** en **ABP-premietabel** worden jaarlijks vastgesteld door het ABP-bestuur en gepubliceerd op abp.nl. De **Werkloosheidsregeling Rijk (BWR)** en de **Wachtgeldregeling Burgerlijke Rijksambtenaren** zijn beide opgenomen als bijlage bij de CAO Rijk. De **Wnra (Wet normalisering rechtspositie ambtenaren)** en de bijbehorende **Aanpassingswet Wnra** liggen ten grondslag aan de huidige privaatrechtelijke aanstellingsstructuur. Voor de loonbelastingaspecten geldt het **Handboek Loonheffingen 2026** van de Belastingdienst, met name de paragrafen over gerichte vrijstellingen voor reiskosten en de werkkostenregeling. De **Verzamelwet SZW 2026** levert de wettelijke loondoorbetalingsbasis bij ziekte. Vergelijkbare CAO-bouwstenen in andere capabilities (cao-gemeenten, cao-provincies, cao-waterschappen) volgen hetzelfde patroon en delen waar mogelijk de FUWASYS- en ABP-componenten via shared kernels.

## Cross-app integration

`cao-rijk` exposes a stable read-model API consumed by `payroll-engine-nl` for the monthly salarisrun (the engine calls `resolveSalary(employmentId, peildatum)` and receives a fully decomposed bezoldigingsspecificatie). The `leave-administration` capability subscribes to `IkbBudgetUpdated` events to keep the verlofkaart synchronised when employees spend IKB op extra verlofuren. The `rostering-planning` capability consumes `FunctieClassificationChanged` events to know when a sectorgebonden DJI-bewaarder is eligible voor piketinroostering. The `contract-generation` capability calls `cao-rijk` for the salarisindicatie en arbeidsvoorwaardenpakket when generating an arbeidsovereenkomst bij indiensttreding of interne mobiliteit. The `wnra-conversion` capability is a prerequisite peer that must have run for any employee with aanstellingsdatum before 2020-01-01. The `abp-aansluiting-verplicht` capability handles the daadwerkelijke koppeling met ABP via de A&O Services interface; `cao-rijk` declares the aansluiting maar delegeert de feitelijke gegevensuitwisseling. Voor sectorgebonden onderdelen koppelt `cao-rijk` aan `cao-dji`, `cao-belastingdienst`, en `cao-kmar` voor aanvullende bepalingen die buiten de generieke CAO Rijk vallen. Cross-app integration with `hrmq-core` is via the standard `Employment` aggregate; `cao-rijk` is one van meerdere CAO-modules die een polymorfe `caoSpecificDetails` property leveren.

## Target users

The primary user is the **HR-administrateur** bij een Rijks-werkgever (P-Direkt-medewerker, ministerieel HR-adviseur, of HR-medewerker bij een Rijks-ZBO), die nieuwe aanstellingen registreert, salarisschalen toekent, IKB-bestedingen verwerkt en mutaties in functievervulling vastlegt. De secundaire user is de **payroll-controller** die maandelijks de salarisrun valideert en correcties doorvoert voor naheffingen of terugvorderingen. De **leidinggevende** is een tertiaire user voor het goedkeuren van IKB-bestedingsverzoeken en het registreren van TAF-besluiten. **CAO-beleidsmedewerkers bij BZK** zijn de bronhouders van de regelingen en hebben configuratierechten voor het updaten van schaaltabellen, IKB-percentages en BWR-staffels na een nieuw CAO-akkoord. **Auditors** (ADR — Auditdienst Rijk en de Algemene Rekenkamer) zijn read-only consumenten die compliance-rapportages opvragen, met name over de juiste toepassing van FUWASYS-classificaties en de rechtmatigheid van wachtgelduitkeringen. De **medewerker zelf** ziet de uitkomsten via het self-service portaal voor het bekijken van de loonstrook, het besteden van IKB en het indienen van verlofverzoeken, maar interacteert nooit direct met de cao-rijk module — alle medewerker-interacties lopen via de hrmq-frontend laag die `cao-rijk` als read-model raadpleegt.
