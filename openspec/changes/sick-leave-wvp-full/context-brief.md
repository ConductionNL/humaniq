---
status: draft
---
# Sick Leave - Wet Verbetering Poortwachter Full 2-Year Cycle

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Verlof & verzuim › WVP-dossiers

**Rationale:** WVP 2-jaars cyclus.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Dutch employment law imposes one of the most prescriptive sick-leave regimes in the European Union. The Wet Verbetering Poortwachter (WVP), in force since 2002 and substantially tightened in 2004 with the WIA, requires both employer and employee to follow a strict 104-week (two-year) re-integration trajectory the moment an employee reports sick. Failure to follow the prescribed timeline, document the required evidence, or demonstrate "voldoende re-integratie-inspanningen" exposes the employer to a loonsanctie - the Uitvoeringsinstituut Werknemersverzekeringen (UWV) extends the loondoorbetaling obligation by up to 52 additional weeks at full cost to the employer (so the employer pays salary for up to three years instead of two). For a salary of EUR 50.000 this represents a sanction of roughly EUR 35.000 per case; for senior staff easily over EUR 100.000.

The existing `sick-leave-mvp` spec covers ziekmelding intake, the Verzuimnorm registry-name, and basic absence accounting. It does not cover any of the WVP procedural milestones, does not separate medical data from HR data, and does not produce the Re-integratieverslag that UWV requires at week 91. This brief defines the replacement: a full WVP-compliant module that drives the two-year cycle from week 1 (ziekmelding) through week 104 (WIA-aanvraag of herstel), enforces every wettelijke termijn, segregates medical and HR data per AVG Artikel 9, and produces every artefact UWV, the bedrijfsarts, and the employee require.

The module replaces `sick-leave-mvp` rather than extending it because the AVG-required separation of medical and HR storage (Artikel 9 bijzondere persoonsgegevens) forces a different data model and a different authorisation model. Bolting that onto the MVP would leave a transitional surface where medical observations could leak into HR views; a clean replacement is safer and faster than a migration with a feature flag for grootboekgrenzen between medical and HR.

The target user population is broad: any Dutch employer with one or more employees who can become sick - which means every employer subject to the Burgerlijk Wetboek Boek 7 employment chapter. Most acutely, the module serves overheid (municipalities, ministeries, provincies, waterschappen) and (semi-)publieke onderwijs- and zorginstellingen, where the IGZ, IGJ, or accountant audits re-integration files; mid-size MKB employers between 25-250 employees, who lack a dedicated arbo-team and need the system to enforce the timeline; and arbodienstverleners themselves who use HRMQ as the casuistiek-platform for their klant-werkgevers.

## Data Model

The core entity is `wvp-case`. One case is opened per ziekmelding-event per employee. If the same employee meldt zich ziek, recovers, and meldt zich opnieuw ziek within four weeks, the cases are samengevoegd onder de 4-weken-regel (Wet Suwi Artikel 29b BeZaVa): the second period telt door op de teller van de eerste. The case carries employee-id (FK to employee-master), case-opening-date, eerste-ziektedag, expected-end-date (rolling), actual-end-date (null until herstel/exit), case-status (open / herstel / wia-aangevraagd / loonsanctie / overleden), and percentage-arbeidsongeschikt (0-100, can change weekly based on bedrijfsarts-advies).

The `wvp-milestone` entity is the spine of the spec. Eleven milestones are tracked, each with a due-date computed from eerste-ziektedag and a completion-status. Week 1: ziekmelding aan ARBO. Week 6: probleemanalyse bedrijfsarts (uiterlijk in week 6). Week 8: plan-van-aanpak (PvA) werkgever en werknemer (binnen 2 weken na probleemanalyse). Week 42: 42e-weeksmelding aan UWV (uiterlijk). Week 46-52: eerstejaarsevaluatie (rond 1 jaar ziek). Week 52: opschudmoment - is 1e spoor uitgeput, moet 2e spoor starten? Week 68: tussenmaal-evaluatie 2e spoor. Week 87: eindevaluatie en RIV-opmaak. Week 91: WIA-aanvraag uiterlijk indienen door werknemer (werkgever levert RIV). Week 104: einde loondoorbetalingsplicht; WIA-uitkering start. Each milestone stores due-date, completed-date, completed-by-user-id, evidence-document-id (FK to document-store), and a structured `gevolgen-bij-niet-naleven` description.

The `re-integratie-dossier` entity is the AVG-segregated medical container. It contains records that only the bedrijfsarts, the verzuimcoach, the employee themselves, and (with explicit consent) UWV-verzekeringsarts can read. HR-rollen, managers, and finance never see these records, only that they exist. Each dossier-entry has type (probleemanalyse / spreekuur-verslag / FML-functionele-mogelijkheden-lijst / IZP-inzetbaarheidsprofiel / medisch-advies / kosten-second-opinion), bedrijfsarts-author-id, date, encrypted-payload (server-side AES-256 at rest, key in HSM), and `share-with-uwv-bij-RIV` boolean (default false; toegevoegd aan RIV-export only if employee tickt vinkje af).

The `plan-van-aanpak` entity is the bilateral document tussen werkgever en werknemer. It is HR-visible (in tegenstelling tot the medische dossier). Fields: doelstelling-re-integratie (volledige werkhervatting eigen functie / aangepast werk eigen werkgever / extern werk / WIA-aanvraag), te-ondernemen-acties (array of action objects with verantwoordelijke and termijn), evaluatie-frequentie (default 6 weken), volgende-evaluatie-datum, casemanager-id (FK to employee-master, must be an employee with role `wvp-casemanager`), and signed-by-werkgever-on, signed-by-werknemer-on (both required for status `vastgesteld`).

The `tweede-spoor-traject` entity is opened op or before week 52 if 1e spoor (eigen werkgever) is uitgeput volgens bedrijfsarts. It points to an external `re-integratiebureau` (Conduction's `partner-registry`, een aparte spec), with contract-start-date, contract-end-date, contracted-amount-eur, and progress-rapportage-frequentie. Voortgangsrapporten van het bureau worden hier als documenten aangehangen.

The `loondoorbetaling-line` entity records the wettelijke uitkering: 70% of last-loon for the first 52 weeks (with a wettelijk minimum-loon as floor) and 70% for the next 52 weeks (without the minimum-loon floor in jaar 2 - dit is een AVV-kwestie per CAO; veel CAOs vullen aan tot 100% jaar 1 en 90% jaar 2). The line links naar `payroll-engine-nl` om de korting op het bruto-loon te berekenen.

## Requirements

### REQ-001: WVP-case lifecycle

A wvp-case MUST be created automatically when a ziekmelding is registered in HRMQ and the employee was not ziek in the preceding four weeks (otherwise the existing case is heropend with `samenvoeging-4-weken-regel = true`).

- GIVEN an employee with no open wvp-case and no closed wvp-case in the last 28 days, WHEN HR registers a ziekmelding with `eerste-ziektedag = today`, THEN a new wvp-case MUST be created with status `open` and all 11 milestone-due-dates MUST be computed from `eerste-ziektedag`.
- GIVEN a wvp-case that was closed (herstel) 14 days ago, WHEN the same employee meldt zich opnieuw ziek, THEN the existing case MUST be reopened, milestone-due-dates MUST remain anchored to the original `eerste-ziektedag`, and `samenvoeging-4-weken-regel` MUST be set to true.
- GIVEN a wvp-case at week 53, WHEN HR attempts to close the case via "herstel" without a bedrijfsarts-spreekuurverslag in the last 7 days, THEN the close MUST be rejected with error `WVP-CLOSE-REQUIRES-MEDICAL-CONFIRMATION`.

### REQ-002: Probleemanalyse week-6 deadline

The probleemanalyse bedrijfsarts MUST be received and registered before the end of week 6 (42 days from eerste-ziektedag). The system MUST send escalation reminders to the bedrijfsarts-organisation at day 28 and day 35.

- GIVEN a wvp-case at day 28, WHEN the milestone `probleemanalyse` has no completed-date, THEN a reminder MUST be emailed to the contracted bedrijfsarts-organisation and logged on the case.
- GIVEN a wvp-case at day 42, WHEN the milestone `probleemanalyse` is still incomplete, THEN the case MUST enter `loonsanctie-risico` status and an HR-notification MUST be generated naming the responsible bedrijfsarts and citing the WVP-artikel.
- GIVEN a probleemanalyse-document uploaded by a user without `bedrijfsarts` role, WHEN the upload completes, THEN the document MUST be rejected and logged with a security-event.

### REQ-003: Plan-van-aanpak week-8 deadline

The plan-van-aanpak MUST be opgesteld en getekend by both werkgever and werknemer within 2 weeks after the probleemanalyse (so practically at week 8 if probleemanalyse was at week 6).

- GIVEN a completed probleemanalyse on day-30, WHEN day-44 passes without a vastgesteld PvA, THEN the case MUST enter `loonsanctie-risico` and the casemanager MUST be notified.
- GIVEN a PvA in concept-status with werkgever-signature only, WHEN the werknemer accesses their employee-portal, THEN the PvA MUST be shown for review with a "akkoord en ondertekenen" button and a "niet akkoord, toelichting" path that creates a bezwaar-record.
- GIVEN a PvA waar werknemer "niet akkoord" markeert, WHEN this happens, THEN a deskundigenoordeel-aanvraag template MUST be generated voor UWV per Artikel 32 WIA.

### REQ-004: Plan-van-aanpak templates

The module MUST provide standaard PvA-templates aligned with the UWV-template "Plan van Aanpak WIA" version 2024-01 and MUST allow customisation per branche via `cao-specific-pva-template` configuration.

- GIVEN a wvp-case opened for an employee covered by CAO Gemeenten, WHEN the casemanager opens "PvA opstellen", THEN the template MUST pre-fill cao-specifieke clausules over re-integratiebudget (EUR 4.500 standaard) and inzetbaarheidsgesprek-cadans.
- GIVEN a wvp-case voor een werknemer in onderwijs (PO/VO), WHEN PvA opstellen wordt gestart, THEN de template MUST pre-fill de Vervangingsfondscondities en de gespreksschema's specifiek voor onderwijs.
- GIVEN a customised template uploaded via admin-config, WHEN the customisation breaks the verplichte velden van het UWV-format, THEN the upload MUST be rejected with a per-veld error-lijst.

### REQ-005: Eerstejaarsevaluatie (week 46-52)

A formele eerstejaarsevaluatie MUST be conducted between week 46 and week 52, resulting in een gezamenlijk besluit over voortzetting 1e spoor of starten 2e spoor.

- GIVEN a wvp-case at week 46, WHEN no eerstejaarsevaluatie-meeting is scheduled, THEN the casemanager MUST receive a daily reminder until either scheduled or completed.
- GIVEN an eerstejaarsevaluatie completed with besluit `start-2e-spoor`, WHEN the evaluatie is opgeslagen, THEN a `tweede-spoor-traject` entity MUST be created in concept-status awaiting selection of a re-integratiebureau.
- GIVEN an eerstejaarsevaluatie completed with besluit `voortzetting-1e-spoor`, WHEN week 52 passes, THEN the case MUST automatically flag the besluit voor heroverweging on the next 6-wekelijkse evaluatie.

### REQ-006: 2e spoor traject

When 1e spoor (eigen werkgever) is uitgeput, een 2e spoor traject MUST be started via een gecertificeerd re-integratiebureau (Blik op Werk-keurmerk of vergelijkbaar). Voortgang MUST quarterly worden gerapporteerd.

- GIVEN a tweede-spoor-traject in status `concept`, WHEN HR selects a re-integratiebureau and saves contract details, THEN the traject MUST move to status `actief` and the first voortgangsrapportage MUST be scheduled 90 days out.
- GIVEN a tweede-spoor-traject `actief`, WHEN 91 days pass without a voortgangsrapportage opload, THEN the case enters `2e-spoor-niet-bijgehouden-risico` and HR is alerted (this is a frequent loonsanctie-grond).
- GIVEN a 2e-spoor-traject met einddatum-contract verstreken en zonder nieuw contract, WHEN week 87 nadert, THEN het systeem MUST een waarschuwing tonen dat de eindevaluatie zonder lopend 2e-spoor-traject WIA-aanvraag zal vertragen.

### REQ-007: Eindevaluatie en RIV (week 87-91)

Bij week 87 MUST een eindevaluatie worden opgesteld en het Re-integratieverslag (RIV) MUST worden samengesteld uit alle WVP-artefacten. De werknemer dient de WIA-aanvraag in voor week 91; werkgever levert de RIV uiterlijk gelijktijdig.

- GIVEN a wvp-case at week 87, WHEN no eindevaluatie is started, THEN HR receives a critical alert and the case enters `RIV-deadline-imminent`.
- GIVEN an eindevaluatie completed and RIV-export requested, WHEN the export runs, THEN it MUST bundle: probleemanalyse, FML's, alle PvA-versies, eerstejaarsevaluatie, alle 6-wekelijkse bijstellingen, 2e-spoor-rapportages, en eindevaluatie, into one PDF-A document with a checksum-cover-blad.
- GIVEN an employee who has not signed the RIV-werknemersdeel by week 91, WHEN the deadline passes, THEN HR is notified that the employee may indienen "RIV met opmerkingen werkgever zonder werknemers-akkoord" with the UWV instructie-link.

### REQ-008: AVG Artikel 9 medische scheiding

Medische re-integratie-dossier-records MUST be physically segregated from HR-data: aparte database-schema, aparte row-level-security policies, encryption-at-rest with HSM-keys, and access-audit log per record-read.

- GIVEN an HR-user (role `hr-medewerker`) opening a wvp-case detail, WHEN the API returns the case payload, THEN the medische dossier-entries MUST appear only as count and date-range, never with content of medische velden.
- GIVEN a bedrijfsarts user opening the same wvp-case, WHEN the API returns the payload, THEN the medische dossier-entries MUST be returned in full, and each read MUST be logged in `medical-access-audit` with reader-id, record-id, timestamp, and IP.
- GIVEN a deletion-request for an employee 24 months after einddatum-dienstverband, WHEN the AVG-bewaartermijn cron runs, THEN medische dossier-entries older than 24 months MUST be hard-deleted and the audit-log entry MUST be retained per Belastingdienst-bewaartermijn.

### REQ-009: Loondoorbetaling 70% berekening

The loondoorbetaling MUST be computed at 70% of refundable loon for both jaar 1 and jaar 2, with the wettelijk minimum-loon as floor only during jaar 1. CAO-aanvullingen (suppleties) MUST be configurable per CAO and applied via `payroll-engine-nl`.

- GIVEN an employee in jaar 1 of ziekte with last-loon EUR 3.000/maand, WHEN payroll runs, THEN the gross-line MUST be max(EUR 3.000 * 0.70, wettelijk-minimum-loon).
- GIVEN an employee in jaar 2 of ziekte with last-loon EUR 1.500/maand, WHEN payroll runs, THEN the gross-line MUST be EUR 1.500 * 0.70 (no minimum-loon floor in jaar 2).
- GIVEN an employee covered by CAO Gemeenten with suppletie-regeling 100% jaar 1 / 90% jaar 2, WHEN payroll runs in jaar 1, THEN the gross-line MUST be 100% of last-loon, with the suppletie-deel als separate line `cao-suppletie-ziekte`.

### REQ-010: UWV poortwachterstoets bij WIA-claim

When de WIA-aanvraag bij UWV is ingediend, UWV doet binnen 6-8 weken een poortwachterstoets. HRMQ MUST be able to receive the toets-uitkomst and trigger a loonsanctie-administratie als UWV oordeelt onvoldoende re-integratie-inspanningen.

- GIVEN a wvp-case with WIA-aanvraag-indien-datum set, WHEN HR receives the UWV poortwachterstoets-uitslag en deze is `loonsanctie`, THEN the case MUST enter status `loonsanctie` met sanctie-duur (in weeks, default 52), en alle loondoorbetaling-lines MUST automatisch worden verlengd tot einde-sanctie.
- GIVEN een case in `loonsanctie` status, WHEN HR de sanctie aanvecht via bezwaarschrift, THEN het bezwaar MUST worden geregistreerd met deadlines, en de status MUST naar `loonsanctie-bezwaar-lopend`.
- GIVEN een case in `loonsanctie` en de werknemer herstelt voor einde-sanctie, WHEN het herstel wordt geregistreerd, THEN de loondoorbetaling-verlenging MUST direct stoppen en UWV MUST worden geinformeerd via de `bekorting-loonsanctie-aanvraag` flow.

## Standards & Sources

The spec is grounded in primary Dutch legislation: Burgerlijk Wetboek Boek 7 Artikelen 629 (loondoorbetaling bij ziekte), 658a (re-integratieverplichting werkgever), 660a (re-integratieverplichting werknemer); the Wet Verbetering Poortwachter (Stb. 2001, 628) and the Regeling procesgang eerste en tweede ziektejaar (Stcrt. 2002, 60); the Wet werk en inkomen naar arbeidsvermogen (WIA, Stb. 2005, 572); the Wet aanpassing en terugvordering bezoldigingen topfunctionarissen (BeZaVa, Stb. 2012, 322) for the 4-weken-regel; the Arbowet 1998 voor de positie van de bedrijfsarts; en de AVG / GDPR Artikel 9 voor de verwerking van bijzondere persoonsgegevens (gezondheidsgegevens).

Procedural and template sources include the UWV `Werkwijzer Poortwachter` (laatste editie 2024), the UWV-template `Plan van Aanpak WIA` versie 2024-01, the UWV-format `Eerstejaarsevaluatie` en `Eindevaluatie`, en de KNMG-richtlijn `Omgaan met medische gegevens` voor de inkadering van de bedrijfsarts-rol. Voor CAO-specifieke aanvullingen worden CAO Gemeenten (VNG), CAO Rijk, CAO Provincies (IPO), CAO Waterschappen, CAO PO en CAO VO (onderwijs), en CAO VVT (zorg) als configuratiesjablonen meegeleverd. Het Blik op Werk-keurmerk voor re-integratiebureaus levert de validatie-lijst voor REQ-006.

Reference implementations en concurrent-analyse: Visma RAET Beaufort Verzuim, Youforce Verzuim, AFAS Profit Verzuim, Verzuimsignaal (van VodW), en de UWV publieke documenten over loonsancties (jaarlijkse trendrapportage). Conduction's eigen `tender-analysis` toonde dat WVP-functionaliteit in 73% van overheids-HR-aanbestedingen als verplicht werd genoemd (bron: specter database, query 2025-Q4); zonder volledige WVP-cyclus is HRMQ niet meedingbaar in overheids-tenders.

## Cross-app Integration

The replacement spec retires `sick-leave-mvp` and migrates its data in a one-shot migration: every open absentie-record wordt geconverteerd naar een wvp-case met `migration-source = mvp`, met milestone-due-dates herrekend vanaf de bestaande eerste-ziektedag. Closed absentie-records older than 28 days are archived read-only.

Upstream dependencies: `employee-master` levert dienstverband, voltijdsfactor, last-loon, en de bedrijfsarts-toewijzing per medewerker; `payroll-engine-nl` consumeert de loondoorbetaling-lines en past CAO-suppleties toe; `document-template-engine` rendert PvA, eerstejaars-, en eindevaluatie-templates; `partner-registry` (separate brief) levert de re-integratiebureau-lijst met Blik op Werk-status; `notification-engine` verzendt reminders en escalations; `cao-engine` levert per-CAO suppletie-percentages en re-integratiebudget-defaults.

Downstream consumers: `wnt-disclosure` aggregeert loondoorbetaling als beloning voor topfunctionarissen; `verzuim-kpi-dashboard` (separate brief) toont voortschrijdend verzuim-percentage, gemiddelde verzuim-duur, en aantal cases in elk WVP-stadium; `boekhouding-export` boekt de loondoorbetaling-lines op grootboekrekening 4040 (sociale lasten ziekte) en alloceert naar het juiste kostencentrum.

Externe integraties: UWV Werkgevers-Portal voor 42e-weeksmelding en RIV-indiening; Arbodienst-portals (Arbo Unie, ArboNed, Zorg van de Zaak, HumanCapitalCare) voor probleemanalyse en spreekuur-verslag-uitwisseling via OAGI-standaard of HL7-FHIR werkgever-bedrijfsarts; Stigas (CAO Agrarisch) en Stichting OOM voor sectorale verzuim-fondsen. De Conduction `openconnector` regelt deze integraties via per-arbodienst connectoren.

## Target Users

The primary user is de `wvp-casemanager` - typisch een HR-business-partner met casuistiek-opleiding (RNVC-register, NVAB-link). One casemanager handelt 30-60 actieve cases gelijktijdig. De casemanager wil per case op een tijdlijn zien welke milestone aanstaande is, welke documenten ontbreken, en wat de gevolgen zijn van een missing milestone. Tweede primaire gebruiker is de bedrijfsarts (extern of intern), die via een ge-isoleerd medisch-portal de probleemanalyse, spreekuur-verslagen, en FML's vastlegt zonder ooit HR-data te zien.

Secundaire gebruikers: HR-medewerker (ziekmelding-intake, overzicht zonder medische details), werknemer (eigen-dossier-inzage via employee-portal, PvA-ondertekening, RIV-akkoord), leidinggevende (alleen werkgerelateerde afspraken uit PvA, geen medische data), en finance-administrateur (loondoorbetaling-rapportages voor maand- en jaarafsluiting).

Tertiaire / read-only gebruikers: accountant (read-only access tijdens controle, gefilterd op niet-medische velden), Auditdienst Rijk (overheid-context, voor WNT-cross-check), en UWV-verzekeringsarts (alleen met expliciete werknemers-toestemming, voor poortwachterstoets-onderbouwing).
