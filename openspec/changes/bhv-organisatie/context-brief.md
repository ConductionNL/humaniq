---
status: draft
---
# BHV Organisatie — Bedrijfshulpverlening Management

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Verlof & verzuim › BHV

**Rationale:** BHV-register.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `bhv-organisatie` app implements bedrijfshulpverlening (BHV) management for organisations subject to the Arbowet, with a focus on the multi-location reality of municipalities, provincies, scholen, ziekenhuizen, and grotere bedrijven. Arbowet artikel 15 verplicht elke werkgever om een toereikend aantal BHV'ers te hebben opgeleid, beschikbaar te houden, en jaarlijks bij te scholen, met aantoonbare alarm- en ontruimingsprocedures per locatie. In de praktijk wordt dit vaak in losse Excels per pand bijgehouden, waardoor (a) certificaten ongemerkt verlopen, (b) op piek- of vakantiedagen onvoldoende BHV'ers aanwezig zijn, (c) bij een Inspectie SZW-bezoek de organisatie geen consistent overzicht kan tonen, en (d) bij een werkelijk incident de inzet vertraging oploopt omdat niemand weet wie er vandaag op locatie certified is.

Deze app biedt een centraal register van BHV'ers per locatie, automatische bewaking van certificaat-verloopdatums (jaarlijkse herhalingscursus, BHV-basisopleiding, hoofd-BHV, ploegleider, EHBO-Oranje Kruis), aanwezigheidsroosters die afdwingen dat de wettelijke minimumbezetting (1 BHV'er per 50 aanwezigen, plus 1 extra per 100) altijd is gehaald, een ontruimingsplan-bibliotheek per pand, oefen- en evaluatie-registratie (verplicht minstens 1x per jaar), en een digitale EHBO-koffer- en AED-inventarischeck.

Het is bewust een operationele tool — geen vervanging voor een RI&E of een fysiek noodbeheersingssysteem — die de administratieve kant van BHV waterdicht maakt en aansluit op `employee-master` voor de personele basisgegevens en op een nieuwe `training-opleidingen` module voor het beheer van certificaten en bijscholingsplekken. Het ontwerp houdt expliciet rekening met flex-werk, hybride werken en bezoekerspieken: de werkelijke aanwezigheid op een dag kan sterk afwijken van het personeelsbestand, dus de coverage-berekening is gekoppeld aan de feitelijk verwachte aanwezigheid (uit bezoekersregistratie, ruimtereserveringen, of een ingebouwde aanwezigheidsregel) in plaats van het statische medewerkersaantal.

## Data Model

Schemas in het `hrmq` register:

- `Location`: een locatie/pand. `code`, `name`, `address`, `gpsCoordinates`, `maxOccupancy`, `floors[]`, `safetyOfficerId`, `ontruimingsplanDocumentId`, `riEDocumentId`, `bezoekersRegistratiesourceId`.
- `BhvMember`: een gecertificeerde medewerker. `employeeId`, `primaryLocationId`, `roles[]` (bhv|hoofd_bhv|ploegleider|ehbo|aed_operator|ontruimingsleider), `availabilityPattern`, `consentSharingMobile`, `status` (actief|tijdelijk_inactief|uit_dienst).
- `Certification`: een specifieke certificering. `bhvMemberId`, `certType` (bhv_basis|bhv_herhaling|hoofd_bhv|ehbo_oranje_kruis|reanimatie_aed|specialistisch), `issuerName`, `issueDate`, `expiryDate`, `certificateDocumentId`, `creditedHours`, `status` (geldig|verloopt_binnenkort|verlopen|ingetrokken).
- `BhvSchedule`: rooster per locatie per dag. `locationId`, `date`, `slotStart`, `slotEnd`, `requiredCount`, `assignedMemberIds[]`, `expectedOccupancy`, `coverageStatus` (groen|geel|rood), `notes`.
- `Drill`: een geregistreerde oefening of evacuatie. `locationId`, `type` (aangekondigd|onaangekondigd|incident_echt), `scheduledFor`, `executedAt`, `evacuationDurationSeconds`, `participantCount`, `evaluationDocumentId`, `lessonsLearned[]`, `actionItems[]`.
- `InventoryItem`: een EHBO-koffer, AED, blusmiddel, vluchtmasker, etc. `locationId`, `itemType`, `position`, `serialNumber`, `lastInspectedAt`, `nextInspectionDue`, `expiryDate`, `replacementOrderId`, `condition`.
- `AlarmEvent`: een geactiveerd alarm. `locationId`, `triggeredBy`, `triggeredAt`, `alarmType` (brand|ontruiming|EHBO|veiligheid), `responseLog[]`, `closedAt`, `incidentReportDocumentId`.

Schema-relaties zorgen voor cascaderende validaties: een `BhvSchedule` met te weinig `assignedMemberIds` waarvan de bijhorende `Certification`s verlopen zijn telt niet mee voor coverage.

## Requirements

**REQ-001: Wettelijke minimumbezetting per locatie**
- GIVEN een `Location` met `expectedOccupancy = 240`, WHEN het systeem de vereiste BHV-bezetting berekent, THEN `requiredCount = ceil(240/50) + extra(240/100) = 5 + 2 = 7` BHV'ers wordt gehanteerd, met een instelbare overschrijdingsbuffer per organisatie.
- GIVEN een dag waarop het rooster minder dan `requiredCount` toont, WHEN de roosterbatch om 06:00 draait, THEN de status van die slot wordt op `rood` gezet en de safety officer en HR krijgen een notificatie voor 08:00.
- GIVEN een organisatie heeft hoog-risico activiteiten op een dag (bv. event, evenement, festiviteit), WHEN een tijdelijke risicoclassificatie wordt toegevoegd, THEN `requiredCount` wordt automatisch verhoogd volgens de configureerbare risk-multiplier (default x1.5).

**REQ-002: Certificaten met verloopbewaking**
- GIVEN een `Certification` met een `expiryDate` binnen 90 dagen, WHEN de dagelijkse signaaljob draait, THEN de status wordt `verloopt_binnenkort`, de BHV'er en de safety officer krijgen een herinnering, en een herhalingscursus wordt voorgesteld via `training-opleidingen`.
- GIVEN een certificaat op `expiryDate`, WHEN de jobs op die datum draaien, THEN de status wordt `verlopen`, de medewerker telt niet meer mee voor `BhvSchedule` coverage, en het rooster van de volgende 30 dagen wordt herrekend voor coverage-impact.
- GIVEN een nieuw certificaat wordt geupload, WHEN de upload wordt verwerkt, THEN de PDF/scan wordt OCR-gescand naar de naam van de cursist en datum, en bij mismatch wordt om bevestiging gevraagd voordat het bestand geaccepteerd wordt.

**REQ-003: Roosters en aanwezigheid**
- GIVEN een wekelijks roostervoorstel wordt gegenereerd, WHEN de planner het algoritme aanroept, THEN het respecteert beschikbaarheid (`availabilityPattern`), spreidt diensten eerlijk over BHV'ers, mijdt opeenvolgende dagen waar mogelijk, en houdt rekening met verlof uit `verlof-administratie`.
- GIVEN een BHV'er ziek meldt voor vandaag, WHEN dit wordt geregistreerd, THEN de slot van vandaag wordt herrekend, automatisch wordt gezocht naar een vervanger op basis van beschikbaarheid en certificering, en de uitkomst (gevonden/niet gevonden) wordt gerapporteerd aan de safety officer.
- GIVEN een dag met meerdere locaties, WHEN een BHV'er aan meerdere slots toegewezen zou worden in dezelfde tijdsblok, THEN dit wordt voorkomen en gemarkeerd als planningsconflict.

**REQ-004: Ontruimingsplan-bibliotheek**
- GIVEN een `Location`, WHEN een safety officer een ontruimingsplan uploadt, THEN het document wordt opgeslagen in `docudesk` met versiebeheer, en alle BHV'ers van die locatie ontvangen een notificatie van de update met confirmatie-vraag "gelezen".
- GIVEN een ontruimingsplan ouder is dan 12 maanden zonder revisie, WHEN de halfjaarlijkse compliance-check draait, THEN de safety officer krijgt een actie om het plan te herzien en de overige BHV'ers worden op de hoogte gebracht.
- GIVEN een gebruiker een QR-code op de muur scant, WHEN ze naar het ontruimingsplan worden geleid, THEN de juiste versie voor die ruimte wordt getoond, beschikbaar in NL en EN, met duidelijk de dichtstbijzijnde nooduitgang gemarkeerd.

**REQ-005: Oefen-registratie**
- GIVEN een oefening wordt gepland, WHEN deze wordt aangemaakt, THEN een `Drill` met type, geplande datum, deelnemende ploegen, en scenario wordt vastgelegd, en alle relevante medewerkers (incl. niet-BHV'ers indien aangekondigd) ontvangen een vooraankondiging.
- GIVEN een oefening wordt uitgevoerd, WHEN de uitkomst wordt geregistreerd, THEN ontruimingstijd, aantal deelnemers, knelpunten en leerpunten worden opgeslagen, en de evaluatie-template wordt automatisch gegenereerd voor invulling door de ploegleider.
- GIVEN een locatie heeft in de afgelopen 12 maanden geen `Drill` geregistreerd, WHEN het jaarlijkse compliance-rapport draait, THEN de locatie wordt rood gemarkeerd, een actie wordt aangemaakt voor de safety officer, en de directie wordt geinformeerd.

**REQ-006: EHBO-koffer en AED inventarischeck**
- GIVEN elke `Location` heeft `InventoryItem`s, WHEN een geplande inspectie aanbreekt (default 6 maandelijks voor EHBO, 12 maandelijks voor AED-batterij, conform fabrikant-spec), THEN de aangewezen inspecteur krijgt een checklist met scan-foto-upload mogelijkheid.
- GIVEN een inventarischeck registreert een ontbrekend of verlopen item, WHEN de check wordt afgesloten, THEN een vervangingsorder wordt voorgesteld via de inkoopflow, met SLA-deadline op basis van item-criticality (AED: 7 dagen, EHBO-pleisters: 30 dagen).
- GIVEN een AED meldt zelf via IoT-koppeling een storing, WHEN de webhook binnenkomt, THEN het item wordt direct op `condition = defect` gezet, de safety officer krijgt een hoge-prioriteit notificatie, en een back-up AED (indien geconfigureerd) wordt aangewezen.

**REQ-007: Alarmflow en incidentregistratie**
- GIVEN een alarm wordt geactiveerd (handmatig in de app, via IoT-koppeling, of via een aangrenzend gebouwbeheersysteem), WHEN het binnenkomt, THEN alle on-call BHV'ers ontvangen een push-notificatie met locatie, type, en een snelle accepteer-knop, en de responstijd wordt gemeten.
- GIVEN tijdens een alarm worden acties uitgevoerd, WHEN ze worden gelogd, THEN de tijdlijn (wie deed wat wanneer) wordt vastgelegd en is later beschikbaar voor incidentanalyse en verzekerings-/juridische follow-up.
- GIVEN een alarm wordt afgesloten, WHEN de afsluit-procedure draait, THEN een incidentrapport-template wordt gegenereerd met pre-fill van locatie, betrokkenen, en tijdlijn; het rapport wordt ondertekend door de safety officer en gearchiveerd.

**REQ-008: Compliance-rapportage Arbowet**
- GIVEN de Inspectie SZW een audit aankondigt, WHEN de safety officer het compliance-rapport opvraagt, THEN een PDF/JSON-pakket wordt gegenereerd met per locatie: actuele bezetting vs vereiste bezetting (rolling 12 maanden), certificaten-overzicht, oefen-historie, inventaris-status, en incident-log.
- GIVEN het jaarverslag voor de OR / bestuur, WHEN het wordt aangevraagd, THEN trend-grafieken (coverage %, certificaat-bijscholingspercentage, oefen-frequentie, gemiddelde responstijd) worden inbegrepen, met benchmarking tegen vorig jaar.
- GIVEN een hoofd-BHV neemt afscheid van de organisatie, WHEN de offboarding wordt geregistreerd, THEN het systeem identificeert dat de hoofd-BHV-rol vacant is, signaleert dit als compliance-risico, en stelt een opvolgings-traject voor.

**REQ-009: Mobiele app voor BHV'ers**
- GIVEN een BHV'er installeert de hrmq-mobiele app, WHEN ze inloggen, THEN ze zien hun aankomende diensten, certificaten met verloopdatum, de actuele ontruimingsplannen van hun locaties, en een SOS-knop.
- GIVEN een BHV'er op een nieuwe locatie is, WHEN ze de QR-code van een ruimte scannen, THEN ze zien lokale informatie (positie EHBO-koffer, dichtstbijzijnde AED, nooduitgang) zonder eerst te hoeven inloggen op de centrale UI.
- GIVEN een alarm binnenkomt in de app, WHEN de BHV'er accepteert, THEN hun locatie wordt (met expliciete consent) gedeeld met de ontruimingsleider tot het incident is afgesloten.

**REQ-010: Privacy en consent**
- GIVEN een medewerker wordt geregistreerd als BHV'er, WHEN ze zich aanmelden, THEN ze geven expliciet consent voor: het delen van hun mobiele nummer met collega-BHV'ers, het ontvangen van urgente alarmnotificaties, en optioneel locatie-delen tijdens een incident, met intrekkings-knop in profile.
- GIVEN een BHV'er hun consent voor mobiele bereikbaarheid intrekt, WHEN dit wordt verwerkt, THEN hun nummer wordt direct verwijderd uit de BHV-bel-lijst, ze tellen niet meer mee voor 24/7-bereikbaarheidseisen, en de safety officer krijgt een waarschuwing.
- GIVEN een ex-BHV'er hun gegevens verwijderd wil hebben, WHEN het AVG-verzoek binnenkomt, THEN persoonsgegevens worden verwijderd of geanonimiseerd; certificaat-historie blijft alleen in geanonimiseerde aggregatievorm bestaan voor compliance-rapportage.

## Standards & Sources

- **Arbeidsomstandighedenwet (Arbowet) art. 15** — verplichting tot BHV.
- **Arbobesluit art. 2.5b t/m 2.5g** — concrete uitwerking BHV-organisatie.
- **Arbeidsomstandighedenregeling** — kwalificatie-eisen en certificering.
- **NEN 4000** — bedrijfshulpverlening-organisatie (richtinggevend).
- **NEN 8112** — bedrijfsnoodorganisatie.
- **Oranje Kruis EHBO-richtlijnen** — EHBO-certificering en bijscholing.
- **NIBHV (Nederlands Instituut Bedrijfshulpverlening)** — opleidingsstandaarden.
- **NEN-EN 60601-2-4** — AED-onderhoudsspecificaties.
- **NEN-EN 12845 + NEN 2535** — brandmeldinstallaties (voor IoT-koppeling).
- **AVG / UAVG** — consent, dataminimalisatie, gezondheidsgegevens-bescherming.
- **Inspectie SZW Basisinspectiemodule Bedrijfshulpverlening** — auditeisen.
- **WCAG 2.2 AA** — toegankelijkheid van alarmpresentatie en ontruimingsinformatie.
- **NEN-EN 7510** — informatiebeveiliging zorginstellingen (relevant voor zorg-locaties met BHV-koppeling aan patiëntveiligheid).
- **Wet veiligheidsregio's** — afstemming met regionale brandweer en GHOR bij ontruimingsplannen.
- **Bouwbesluit 2012, afdeling 6.5 + 6.8** — vluchtroutes, ontruimingsalarm, signalering.
- **PGS-15** — opslag gevaarlijke stoffen (waar van toepassing voor specifieke locaties).
- **NIBHV richtlijn nascholing** — minimum 6 contacturen per jaar voor herhalingscursus.

## Cross-app Integration

- **employee-master**: bron voor medewerkergegevens, locatietoewijzing, in/uit-dienst events; offboarding triggert BHV-status update.
- **training-opleidingen** (nieuw): beheer van BHV- en EHBO-cursussen, planning van bijscholing, plekkenadministratie en factuur-koppeling.
- **verlof-administratie**: roosterplanning houdt rekening met goedgekeurd verlof; verloflagunes activeren coverage-herrekening.
- **docudesk**: opslag van ontruimingsplannen, RI&E-rapporten, oefenrapporten, incidentrapporten met retentie-regels.
- **openconnector**: koppelingen met gebouwbeheersystemen (BMS), brandmeldcentrales, AED-IoT-leveranciers, externe BHV-cursusaanbieders, en SOS-platforms.
- **mydash**: bestuurdashboard met coverage-KPI's, compliance-status, incidenttrends, oefen-frequentie per locatie.
- **opencatalogi**: publicatie van openbare delen van ontruimingsplannen (waar relevant voor bezoekers/burgers).
- **irma-digid-auth**: BHV-mobiele app authenticatie via Yivi/DigiD; gevoelige acties (alarm activeren, persoonsgegevens BHV'er aanpassen) vereisen step-up.

## Target Users

1. **Safety officer / coordinator bedrijfsveiligheid** (1–5 per organisatie) — primary user, bewaakt alles per locatie, ontvangt alle compliance-signalen.
2. **Hoofd-BHV** (1 per locatie of cluster) — verantwoordelijk voor BHV-organisatie, plant oefeningen, leidt evaluaties.
3. **Ploegleider** — leidt een BHV-ploeg tijdens een dienst, neemt operationele beslissingen tijdens incidenten.
4. **BHV'er / EHBO'er** (10–500 per organisatie) — ontvangt diensten via rooster, krijgt alarm-notificaties, beheert eigen certificaten.
5. **Bezoeker / burger op locatie** — anonieme gebruiker die via QR-code de relevante ontruimingsinformatie kan zien.
6. **OR-lid (commissie veiligheid)** — read-only inzage in compliance-rapporten en oefenresultaten.
7. **Facility manager** — eigenaarschap van pand-data, GPS, ruimtes, inventarispositie.
8. **Inspecteur SZW / interne auditor** — periodieke compliance-audits met export-pakketten.
9. **Externe cursusaanbieder** (NIBHV, Oranje Kruis, regionale aanbieders) — koppeling voor certificaten-uitgifte en plekkenboeking.
10. **Verzekeraar / arbo-dienst** — periodieke rapportage-export voor risico-beoordeling en premie-berekening.
