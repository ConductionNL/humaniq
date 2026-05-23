---
status: draft
app: hrmq
spec: stagiair-bbl-admin
version: 0.1.0
owners: [hrmq-team]
target-users: [hr-admin, stagebegeleider, opleidingscoordinator, finance-admin]
deps: [employee-master, contract-management]
standards: [SBB, WVA-opvolger-subsidie-praktijkleren, BW-7-titel-10, CAO-Beroepsonderwijs]
---

# Stagiair & BBL-leerling Administratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Medewerkers › Stagiairs & BBL

**Rationale:** Aparte filter/view.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Nederlandse organisaties die opleidingsplaatsen bieden onderscheiden juridisch en administratief twee fundamenteel verschillende leerwerkrelaties: de **stagiair** (HBO/WO/MBO-BOL) zonder dienstverband en zonder loonheffing, en de **BBL-leerling** (MBO-BBL) met regulier dienstverband, loon en CAO-toepassing. Beide vereisen een door SBB erkend leerbedrijf, een ondertekende praktijkleerovereenkomst (POK), en aparte fiscale, verzekerings- en subsidie-administratie.

Deze spec definieert een dedicated module voor het beheren van stagiairs en BBL-leerlingen als distinct entity-type binnen hrmq, gescheiden van de reguliere `Employee` master. Reden: een stagiair is geen werknemer in de zin van BW-7 titel 10; toepassing van payroll-flows, CAO-staffels of verlofadministratie zou onjuist zijn. Een BBL-leerling is wel werknemer maar krijgt een afwijkende loonschaal (BBL-staffel) en geeft recht op de Subsidieregeling Praktijkleren (€2.700/jaar/leerling).

De module dekt: SBB-erkenning-check van het bedrijf, registratie van de onderwijsinstelling, POK-lifecycle (opstellen, ondertekenen door drie partijen, archiveren), stagevergoeding (onbelast tot grens), BBL-payroll-koppeling, vouchers en subsidie-aanvraag bij RVO, ziektewet- en aansprakelijkheidsverzekering, voortgangsgesprekken en beoordeling, en uitstroom met diploma-registratie.

## Data Model

Twee hoofdschemas, beide met POK als gedeelde sub-entity:

- **Stagiair** — `bsn`, `naam`, `geboortedatum`, `onderwijsinstelling` (ref), `opleiding`, `niveau` (HBO/WO/MBO-BOL), `studierichting`, `stagetype` (snuffel/meeloop/afstudeer), `startdatum`, `einddatum`, `stagebegeleider_intern` (ref Employee), `stagebegeleider_extern`, `stagevergoeding_per_maand`, `reiskosten_vergoeding`, `aantal_dagen_per_week`, `pok_ref`, `verzekering_status`, `beoordeling_eindcijfer`, `diploma_behaald`.
- **BBLLeerling** — `bsn`, `naam`, `geboortedatum`, `roc_instelling` (ref), `crebo_code`, `niveau` (2/3/4), `werknemer_ref` (ref Employee — wel arbeidscontract), `leerbedrijf_erkenning_ref`, `pok_ref`, `bbl_staffel_jaar` (1/2/3), `vouchers_toegekend`, `subsidie_praktijkleren_aangevraagd`, `subsidie_bedrag_uitgekeerd`, `praktijkbeoordelaar` (ref Employee).
- **PraktijkLeerOvereenkomst (POK)** — `partij_leerbedrijf`, `partij_onderwijsinstelling`, `partij_deelnemer`, `ondertekend_datum_3_partijen`, `leerdoelen` (json), `praktijkbeoordelaar`, `aantal_begeleidingsuren_per_week`, `ingangsdatum`, `einddatum`, `tussentijdse_evaluaties` (array), `archief_url`.
- **SBBErkenning** — `kvk`, `erkenningsnummer`, `erkende_crebos` (array), `erkenningsdatum`, `vervaldatum`, `praktijkopleider_named` (ref Employee).
- **SubsidieAanvraagPraktijkleren** — `bbl_leerling_ref`, `studiejaar`, `aangevraagd_datum`, `rvo_referentienr`, `bedrag_aangevraagd`, `bedrag_toegekend`, `uitkeringsdatum`, `bewijsstukken` (POK + urenregistratie + diploma).

## Requirements

### REQ-001: Distinct entity-scheiding stagiair vs werknemer

**GIVEN** een nieuwe stagiair wordt geregistreerd  
**WHEN** de HR-admin het stagiair-formulier opslaat  
**THEN** wordt een record aangemaakt in de `Stagiair`-store, NIET in `Employee`; de stagiair krijgt GEEN payroll-entry, GEEN verlofbalans, GEEN CAO-toepassing, en wordt expliciet uitgesloten van de FTE-telling op het Employee-dashboard.

### REQ-002: BBL-leerling krijgt wel Employee-record

**GIVEN** een nieuwe BBL-leerling wordt geregistreerd  
**WHEN** de HR-admin het BBL-formulier opslaat  
**THEN** wordt zowel een `BBLLeerling`-record als een gelinkt `Employee`-record aangemaakt; het Employee-record krijgt contractvorm `bbl-arbeidsovereenkomst`, salaris volgens BBL-staffel jaar 1, en wordt opgenomen in payroll en CAO-toepassing.

### REQ-003: SBB-erkenning is harde voorwaarde

**GIVEN** een organisatie wil een BBL-leerling of MBO-stagiair registreren  
**WHEN** het CREBO-nummer van de opleiding wordt ingevuld  
**THEN** controleert het systeem of de organisatie een geldige `SBBErkenning` heeft voor dat specifieke CREBO; ontbreekt deze of is vervallen, dan blokkeert het systeem opslag met een actionable foutmelding ("Vraag erkenning aan via s-bb.nl/erkenning").

### REQ-004: POK-driepartijen-ondertekening verplicht

**GIVEN** een stagiair of BBL-leerling is geregistreerd  
**WHEN** de startdatum nadert (T-7 dagen)  
**THEN** controleert het systeem dat de bijbehorende `PraktijkLeerOvereenkomst` is ondertekend door alle drie de partijen (leerbedrijf, onderwijsinstelling, deelnemer); ontbreekt een ondertekening, dan wordt een blocker-taak aangemaakt voor de stagebegeleider met de melding "POK niet compleet — instroom kan niet plaatsvinden".

### REQ-005: Stagevergoeding zonder loonheffing

**GIVEN** een stagiair krijgt een maandelijkse stagevergoeding  
**WHEN** de finance-admin de uitkering verwerkt  
**THEN** wordt de vergoeding geboekt als "onkostenvergoeding stagiair" zonder inhouding van loonheffing of premies werknemersverzekeringen, mits het bedrag onder de fiscale onbelaste grens blijft; het systeem waarschuwt bij overschrijding ("Bedrag overstijgt onbelaste stagevergoeding — risico op fiscale herkwalificatie als dienstbetrekking").

### REQ-006: BBL-staffel-progressie per leerjaar

**GIVEN** een BBL-leerling heeft 12 maanden in dienst gewerkt en is bevorderd naar leerjaar 2  
**WHEN** de stagebegeleider de leerjaar-overgang registreert  
**THEN** wordt automatisch een payroll-mutation aangemaakt die het Employee-salaris ophoogt naar BBL-staffel jaar 2 per de eerste van de volgende maand; finance ontvangt een approval-taak.

### REQ-007: Subsidie Praktijkleren-aanvraag per BBL-leerling

**GIVEN** een BBL-leerling heeft een volledig studiejaar (40 begeleidingsweken) afgerond  
**WHEN** het studiejaar eindigt (default 31 juli)  
**THEN** genereert het systeem automatisch een `SubsidieAanvraagPraktijkleren`-concept met als bewijsstukken de POK, de urenregistratie en het tussentijdse-evaluatieformulier; finance ontvangt een taak om de aanvraag in te dienen bij RVO voor de deadline (default 16 september).

### REQ-008: Verzekering-status bij instroom

**GIVEN** een stagiair of BBL-leerling staat op het punt te starten  
**WHEN** de startdatum is bereikt  
**THEN** controleert het systeem dat de verzekering-status van de deelnemer (aansprakelijkheid via onderwijsinstelling voor stagiair; ziektewet + ongevallen via werkgever voor BBL-leerling) is gevalideerd; ontbrekende verzekering blokkeert instroom en triggert een taak voor de HR-admin.

### REQ-009: Voortgangsgesprekken-tracking met audit-trail

**GIVEN** een stagiair of BBL-leerling is actief  
**WHEN** de afgesproken evaluatiemomenten (default 25%, 50%, 75% van looptijd) worden bereikt  
**THEN** ontvangt de stagebegeleider een taak om het evaluatiegesprek te voeren en het resultaat te registreren in de POK-`tussentijdse_evaluaties`-array; ontbrekende evaluaties blokkeren de uitstroom-procedure en de subsidie-aanvraag.

### REQ-010: Uitstroom met diploma-registratie

**GIVEN** een stagiair of BBL-leerling bereikt de einddatum  
**WHEN** de stagebegeleider de eindbeoordeling registreert  
**THEN** wordt het diploma-veld bijgewerkt, voor BBL-leerlingen wordt de Employee-status op `uit-dienst` gezet (met optionele triggering van regulier sollicitatie-proces voor vaste aanstelling), en de POK wordt definitief gearchiveerd met retentie volgens Archiefwet (7 jaar na uitstroom).

## Standards & Compliance

- **SBB (Samenwerkingsorganisatie Beroepsonderwijs Bedrijfsleven)** — erkenningssysteem voor leerbedrijven; integratie via s-bb.nl publieke registers.
- **Subsidieregeling Praktijkleren** — RVO-regeling; €2.700 per leerling per studiejaar (BBL/HBO-duaal/3e leerweg); aanvraag tussen 2 juni en 16 september.
- **BW-7 titel 10** — definitie arbeidsovereenkomst; stagiair valt eronder als feitelijk werk wordt verricht zonder leeroogmerk; module bewaakt deze grens.
- **CAO Beroepsonderwijs** voor BBL-staffel-bedragen; per branche-CAO mogelijk afwijkend (Bouw, Metaal, Zorg).
- **AVG** — BSN-verwerking voor stagiairs alleen toegestaan op grond van fiscale verplichting; voor stagiairs zonder vergoeding is BSN-opslag verboden.
- **Archiefwet** — POK + beoordeling 7 jaar bewaren na uitstroom.

## Cross-app Dependencies

- **employee-master** — bron voor stagebegeleider-ref en praktijkbeoordelaar-ref; voor BBL-leerlingen wordt een gekoppeld Employee-record aangemaakt.
- **contract-management** — POK is een contracttype binnen contract-management; reuse van signing-flow en archiefretentie.
- **payroll-engine-nl** — BBL-staffel-toepassing; stagevergoeding-uitkering als niet-loon-component.
- **document-storage** — POK-pdf, diploma-pdf, SBB-erkenningsbewijs.
- **task-management** — instroomblockers, evaluatieherinneringen, subsidie-deadlines.
- **finance-export** — subsidieontvangst RVO; stagevergoeding-uitkering.

## Target Users

- **HR-admin** — registreert stagiair/BBL-leerling, beheert POK, doet uitstroom.
- **Stagebegeleider** — voert evaluatiegesprekken, registreert voortgang, geeft eindbeoordeling.
- **Opleidingscoordinator** — bewaakt SBB-erkenningen per CREBO, plant capaciteit, monitort dashboard "actieve leerwerkplekken".
- **Finance-admin** — vraagt Subsidie Praktijkleren aan, verwerkt stagevergoeding, verwerkt BBL-payroll-mutaties.
- **Directie** — ziet dashboard met aantallen, subsidie-opbrengsten, doorstroom van BBL/stagiair naar vast contract (talent-pipeline-KPI).
