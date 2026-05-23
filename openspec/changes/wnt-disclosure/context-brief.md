---
status: draft
---
# WNT Disclosure - Wet Normering Topinkomens Rapportage

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Aangiftes & compliance › WNT-publicatie

**Rationale:** WNT-rapport.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

De Wet Normering Topinkomens (WNT, in werking sinds 1 januari 2013, laatste integrale herziening 2017) normeert en publiceert de bezoldiging van topfunctionarissen in de (semi-)publieke sector. De wet geldt voor alle organisaties op de WNT-staat: ministeries en zelfstandige bestuursorganen, provincies, gemeenten, gemeenschappelijke regelingen, waterschappen, hogescholen en universiteiten, MBO-, VO- en PO-besturen, ziekenhuizen en GGZ-instellingen, woningcorporaties, omroep-organisaties, en alle organisaties die meer dan 50% van hun inkomsten van de overheid ontvangen of een wettelijke publieke taak hebben.

Voor 2026 zijn de bezoldigingsnormen: WNT-norm 1 (algemeen plafond) = EUR 246.000; WNT-norm 2 (klasse-indeling onderwijs en zorg, met klasse-A laagste t/m klasse-G hoogste, waarbij klasse-G gelijk is aan norm 1) = EUR 207.000 voor de meest voorkomende middenklasse. Elk bedrag boven deze norm dat een topfunctionaris ontvangt is per definitie onverschuldigd betaald (Artikel 1.6 WNT) en MOET door de organisatie worden teruggevorderd; gebeurt dat niet, dan kan de Auditdienst Rijk (ADR) of de Inspectie van Onderwijs / Inspectie Gezondheidszorg en Jeugd (afhankelijk van sector) de organisatie aanspreken en zelfs het aanhouden van de overheidssubsidies opschorten.

De WNT-rapportage MOET jaarlijks worden opgenomen in het jaarverslag (verplicht digitaal te publiceren sinds 2020), met per topfunctionaris: beloning, belastbare onkostenvergoedingen, voorzieningen voor beloningen betaalbaar op termijn (pensioenpremie werkgever, levenslooppremie, individueel keuzebudget), ontslagvergoedingen (per geval gemaximeerd op EUR 75.000 of een jaarsalaris), en alle natura-beloningen (lease-auto, woning, et cetera) gewaardeerd tegen marktwaarde. Ook de fictieve dienstbetrekking (interimmers via een BV of als ZZP'er, waarbij de werkelijke arbeidsverhouding als dienstverband wordt gekwalificeerd) valt onder de WNT.

Topfunctionarissen zijn niet alleen bestuurders maar ook de hoogst-leidinggevende laag, leden van het toezichthoudend orgaan (raden van toezicht, raden van commissarissen), en interim-bestuurders. Voor de eerste twaalf maanden van een interim-bestuurder geldt een aparte norm (EUR 31.671 per maand in 2026) plus een externe-inhuur-staffel.

De huidige HRMQ-modules registreren bezoldiging-componenten in `employee-master` en `payroll-engine-nl`, maar markeren niet welke werknemers topfunctionaris zijn, aggregeren niet de WNT-bezoldiging-formule (die anders is dan fiscale loonsom), berekenen niet automatisch overschrijdingen, en produceren geen jaarverslag-bijlage. Deze brief specificeert de aanvulling: een WNT-disclosure-module die per kalenderjaar de WNT-rapportage genereert, terugvorderings-administratie bijhoudt, en bovenstap-detectie automatiseert.

Target users zijn de controller, de jaarverslag-redacteur, de accountant, en de Auditdienst Rijk-contactpersoon van WNT-plichtige organisaties; in totaal in Nederland circa 2.500 organisaties met gemiddeld 5-50 topfunctionarissen elk.

## Data Model

Het centrale entity is `wnt-topfunctionaris-aanwijzing`. Per topfunctionaris-rol een record met: employee-id (FK naar employee-master, mag NULL zijn bij externe interimmer zonder dienstverband), functie-naam (bestuurslid, voorzitter raad van toezicht, lid raad van toezicht, interim-bestuurder, et cetera), aanvangsdatum, einddatum (NULL als nog actief), aanwijzings-grond (Artikel 1.1 WNT: bestuurder / toezichthouder / leidinggevende-topstructuur), wnt-norm-toepasselijk (`norm-1` of `norm-2-klasse-X`), fictieve-dienstbetrekking-vlag (true voor interimmers ingehuurd via BV/ZZP), en bezoldigings-grondslag (`werknemer` / `extern-via-bv` / `extern-zelfstandig`).

De `wnt-bezoldiging-component` entity verzamelt per topfunctionaris per kalenderjaar alle WNT-relevante bezoldigingscomponenten. Per component een record met: topfunctionaris-aanwijzing-id, kalenderjaar, component-type (`beloning` / `belastbare-onkostenvergoeding` / `voorziening-pensioen` / `voorziening-levensloop` / `voorziening-ikb` / `natura-leaseauto` / `natura-woning` / `natura-overig` / `ontslagvergoeding` / `bonus` / `gratificatie`), bedrag-eur (jaarlijkse som), bron-administratie (`payroll-engine-nl` met run-id, of `manuele-invoer` met admin-id), en `wnt-meetelt-vlag` (een aantal componenten zoals reiskosten woon-werk tellen niet mee per Uitvoeringsregeling WNT).

De `wnt-jaar-rapportage` entity is de geaggregeerde regel per topfunctionaris per jaar. Velden: topfunctionaris-aanwijzing-id, kalenderjaar, totaal-bezoldiging-wnt (som van wnt-meetelt-componenten), totaal-bezoldiging-fiscaal (ter vergelijking), wnt-norm-bedrag (afhankelijk van norm en klasse), overschrijdings-bedrag (max(0, totaal-bezoldiging-wnt - wnt-norm-bedrag)), reden-overschrijdings-mogelijk-toegestaan (uitsterf-constructie / wettelijk-overgangsrecht-pre-2013 / individuele-uitzondering-minister), terugvordering-vereist-vlag, terugvordering-bedrag, terugvordering-status (`niet-vereist` / `te-vorderen` / `in-vordering` / `voldaan` / `oninbaar`), en publicatie-status (`concept` / `door-rvb-vastgesteld` / `gepubliceerd-jaarverslag`).

De `wnt-ontslagvergoeding` entity is een sub-administratie omdat ontslagvergoedingen een eigen plafond hebben (EUR 75.000 of een jaarsalaris, lager van de twee) onafhankelijk van de jaar-bezoldigings-norm. Per uitkering: topfunctionaris-aanwijzing-id, uitkerings-datum, totaal-bedrag, bedrag-binnen-wnt-plafond, bedrag-boven-plafond (te-vorderen), reden-uitkering (vrijwillig-vertrek / ontslag-werkgever / contract-niet-verlengd / overlijden), en samenstelling (transitievergoeding / gouden-handdruk / outplacement / juridische-bijstand).

Het `wnt-klasse-indeling` entity is de jaarlijkse klasse-indeling voor onderwijs en zorg. Per organisatie per kalenderjaar: ingedeelde-klasse (A t/m G), klasse-bepalende-factoren (voor zorg: opbrengsten + aantal bedden + bekostigingscategorie; voor onderwijs: aantal leerlingen + budget + sector-multiplier), wnt-norm-bedrag-voor-dit-jaar, en `indeling-vastgesteld-door` (raad-van-toezicht-besluitnummer).

De `wnt-publicatie-versie` entity is de versie-controle op de jaarverslag-bijlage. Per kalenderjaar kunnen er meerdere versies bestaan (concept-voor-accountant, accountants-review-versie, definitieve-versie-voor-publicatie, herziene-versie-na-correctie). Velden: kalenderjaar, versie-nummer, gegenereerd-op, gegenereerd-door, document-id (FK naar document-store, gegenereerd via document-template-engine), accountantsverklaring-id, en publicatie-url (link naar het jaarverslag op de organisatie-website).

## Requirements

### REQ-001: Topfunctionaris-aanwijzing en lifecycle

Het systeem MUST de mogelijkheid bieden om werknemers of externe interimmers aan te wijzen als topfunctionaris, met aanvangs- en einddatum en juiste norm-toepassing.

- GIVEN een nieuwe bestuurder die op 2026-03-01 aantreedt bij een ziekenhuis ingedeeld in WNT klasse-V (norm EUR 235.000 in 2026), WHEN HR de aanwijzing vastlegt, THEN MUST een wnt-topfunctionaris-aanwijzing-record worden aangemaakt met norm-2-klasse-V toepasselijk en alle bezoldigings-componenten vanaf 2026-03-01 in de WNT-aggregatie worden meegenomen.
- GIVEN een topfunctionaris met einddatum 2026-09-30, WHEN het jaarrapport-2026 wordt gegenereerd, THEN MUST de norm pro-rata worden berekend (9 van 12 maanden) en de bezoldiging tegen de pro-rata norm worden afgezet.
- GIVEN een interim-bestuurder ingehuurd via een BV voor 8 maanden, WHEN HR de aanwijzing vastlegt met fictieve-dienstbetrekking-vlag, THEN MUST de aparte interimmer-norm (per maand) worden toegepast en de externe-inhuur-staffel worden gebruikt voor maand 7 en 8.

### REQ-002: WNT-bezoldigings-aggregatie

Per kalenderjaar MUST per topfunctionaris alle WNT-meetelt-componenten worden geaggregeerd uit payroll, voorzieningen, en handmatige invoer (voor natura-componenten).

- GIVEN een topfunctionaris met regulier bruto-jaarsalaris EUR 180.000, IKB EUR 28.800, werkgever-pensioenpremie EUR 22.000, en lease-auto natura EUR 9.500, WHEN de jaarsom wordt gegenereerd, THEN MUST totaal-bezoldiging-wnt = EUR 240.300 zijn.
- GIVEN een topfunctionaris die reiskosten woon-werk en bijdrage-kinderopvang ontvangt, WHEN de jaarsom wordt gegenereerd, THEN MUST deze componenten NIET in totaal-bezoldiging-wnt worden meegenomen, conform Uitvoeringsregeling WNT Artikel 2.
- GIVEN een topfunctionaris die in december 2026 een eenmalige bonus EUR 30.000 ontvangt over prestaties 2026, WHEN de jaarsom wordt gegenereerd, THEN MUST de bonus in totaal-bezoldiging-2026 worden meegenomen, NIET in 2025 of 2027.

### REQ-003: Overschrijdings-detectie en signaalgeving

Het systeem MUST per maand per topfunctionaris een prognose-overschrijding berekenen (year-to-date bezoldiging geextrapoleerd naar jaareinde) en bij dreigende overschrijding een waarschuwing aan de controller sturen.

- GIVEN een topfunctionaris met norm EUR 246.000 die per einde-juni een YTD-bezoldiging van EUR 130.000 heeft, WHEN de maandelijkse prognose-batch loopt, THEN MUST de geextrapoleerde jaar-bezoldiging EUR 260.000 zijn en MUST een waarschuwing aan de controller worden gestuurd met onderwerp "WNT-norm dreigt overschreden bij [naam]".
- GIVEN een topfunctionaris die in oktober een eenmalige uitkering ontvangt waardoor overschrijding zeker is, WHEN de uitkering wordt geboekt, THEN MUST een real-time alert aan de controller en HR-directeur worden gestuurd EN MUST een terugvorderings-record in concept-status worden aangelegd.
- GIVEN een topfunctionaris met aangetoond uitsterf-constructie-recht (op 2013-01-01 al boven WNT-norm, met uitsterf-clausule), WHEN overschrijdings-detectie loopt, THEN MUST geen waarschuwing worden gestuurd maar wel de overschrijding in het jaarverslag worden gemeld onder vermelding van de uitsterf-grond.

### REQ-004: Terugvordering bovenstap

Voor elke overschrijdings-bedrag boven WNT-norm MUST een terugvorderings-administratie worden bijgehouden met deadlines (terugvordering moet voor einde van het jaar volgend op het overtredings-jaar zijn afgerond) en escalatie indien niet voldaan.

- GIVEN een vastgestelde overschrijding van EUR 8.500 over kalenderjaar 2026, WHEN de jaarafsluiting wordt vastgelegd, THEN MUST een wnt-terugvorderings-record worden aangemaakt met bedrag EUR 8.500, deadline 2027-12-31, en status `te-vorderen`.
- GIVEN een terugvorderings-record dat 2027-09-01 nog niet voldaan is, WHEN de kwartaal-bewakings-cron draait, THEN MUST de controller een herinnering ontvangen met de tekst "Terugvordering vereist voor jaareinde 2027".
- GIVEN een terugvorderings-record dat per 2028-01-01 nog niet voldaan is, WHEN de jaarwisseling wordt verwerkt, THEN MUST de status naar `oninbaar` of `te-melden-aan-toezicht` worden gezet EN MUST een hard-blocker op het nieuwe jaarverslag worden geactiveerd tot escalatie is afgehandeld.

### REQ-005: Fictieve dienstbetrekking en interim-norm

Voor interimmers ingehuurd via een BV of als ZZP'er waar de feitelijke arbeidsverhouding als dienstverband kwalificeert MUST de fictieve-dienstbetrekking-norm worden toegepast (EUR 31.671/maand in 2026 voor de eerste 6 maanden, daarna een dalende staffel).

- GIVEN een interim-bestuurder ingehuurd voor 4 maanden tegen EUR 35.000/maand, WHEN de aanwijzing wordt vastgelegd met fictieve-dienstbetrekking-vlag, THEN MUST per maand de overschrijding berekend worden (EUR 35.000 - EUR 31.671 = EUR 3.329 per maand) en een terugvorderings-record per maand worden aangelegd.
- GIVEN een interim-bestuurder voor maand 7-12 (na de eerste 6 maanden), WHEN de bezoldigings-rapportage wordt gegenereerd, THEN MUST de externe-inhuur-staffel-norm voor maand 7 en verder worden toegepast volgens Bijlage 2 WNT.
- GIVEN een interim-bestuurder die langer dan 12 maanden actief is, WHEN de aanwijzing een 13e maand bereikt, THEN MUST de norm-toepassing automatisch terugvallen op de normale jaar-bezoldigings-norm (norm-1 of norm-2-klasse) en MUST een waarschuwing aan de controller worden gestuurd over de overgang.

### REQ-006: Klasse-indeling onderwijs en zorg

Voor organisaties in onderwijs en zorg MUST jaarlijks de WNT-klasse-indeling worden vastgelegd, gebaseerd op de wettelijke-klasse-bepalende-factoren, en deze klasse MUST de toepasselijke norm aansturen.

- GIVEN een ziekenhuis met opbrengsten EUR 142M, 240 bedden, en categorie-ziekenhuis-academisch, WHEN de klasse-indeling-cron op 1 januari draait, THEN MUST de klasse-bepaling worden uitgevoerd volgens de Regeling indeling WNT-zorg en MUST de norm voor het hele kalenderjaar daarop worden gebaseerd.
- GIVEN een onderwijsinstelling die in 2026 een fusie ondergaat waardoor leerling-aantal verdubbelt, WHEN HR de fusie verwerkt met effectieve-datum 2026-08-01, THEN MUST een tussentijdse klasse-herziening worden voorgesteld voor het volgende kalenderjaar (per 2027-01-01) en MUST de norm-2026 ongewijzigd blijven.
- GIVEN een ingedeelde klasse die door RvT-besluit wordt vastgelegd, WHEN het besluit-document wordt geupload, THEN MUST de klasse-indeling als definitief worden gemarkeerd en MUST de RvT-besluit-referentie worden vastgelegd in het wnt-klasse-indeling-record.

### REQ-007: Ontslagvergoeding-administratie

Ontslagvergoedingen MUST apart worden geregistreerd met hun eigen WNT-plafond (EUR 75.000 of een jaarsalaris, lager van de twee), met automatische detectie van bovenstap en terugvorderings-record.

- GIVEN een topfunctionaris met jaarsalaris EUR 180.000 die een ontslagvergoeding van EUR 90.000 ontvangt, WHEN de uitkering wordt geboekt, THEN MUST het plafond worden bepaald als min(EUR 75.000, EUR 180.000) = EUR 75.000 en MUST een terugvorderings-record voor EUR 15.000 worden aangelegd.
- GIVEN een topfunctionaris met jaarsalaris EUR 50.000 die een ontslagvergoeding van EUR 60.000 ontvangt, WHEN de uitkering wordt geboekt, THEN MUST het plafond worden bepaald als min(EUR 75.000, EUR 50.000) = EUR 50.000 en MUST een terugvorderings-record voor EUR 10.000 worden aangelegd.
- GIVEN een ontslagvergoeding die uit meerdere componenten bestaat (transitievergoeding wettelijk EUR 30.000, gouden-handdruk EUR 45.000, outplacement EUR 8.000), WHEN de uitkering wordt geboekt, THEN MUST per component zichtbaar zijn welk deel binnen en buiten plafond valt en MUST documentatie voor afzonderlijke componenten worden bewaard.

### REQ-008: Jaarverslag-bijlage publicatie

Per kalenderjaar MUST een WNT-bijlage worden gegenereerd in het wettelijk voorgeschreven format (per topfunctionaris met alle WNT-velden), klaar voor opname in het jaarverslag, en de bijlage MUST digitaal publicabel zijn.

- GIVEN een organisatie met 12 topfunctionarissen over kalenderjaar 2026, WHEN de WNT-jaar-rapportage-generator wordt gedraaid, THEN MUST een PDF-document worden geproduceerd met per topfunctionaris alle voorgeschreven velden (naam, functie, aanvangsdatum, einddatum, deeltijdfactor, totaal-bezoldiging, individuele toepasselijke bezoldigingsmaximum, et cetera) conform het Uitvoeringsregeling WNT format.
- GIVEN een gegenereerde bijlage in concept-status, WHEN de RvB de bijlage vaststelt via een formele goedkeurings-actie, THEN MUST de bijlage in status `door-rvb-vastgesteld` over gaan en MUST een PDF-versie worden vastgevroren (immutable storage).
- GIVEN een vastgestelde bijlage die later moet worden herzien wegens correctie, WHEN de controller een herziening initieert, THEN MUST een nieuwe versie worden aangemaakt met versie-nummer +1 en MUST de oorspronkelijke versie als historisch toegankelijk blijven met `vervangen-door`-link.

### REQ-009: Toezicht door Auditdienst Rijk / accountant

De WNT-administratie MUST gegevens-export en read-only toegang ondersteunen voor de Auditdienst Rijk (bij rijksoverheid en agentschappen) of de externe accountant (bij andere WNT-organisaties), met audit-trail per data-access.

- GIVEN een ADR-controleur die een export aanvraagt voor controlejaar 2026, WHEN de export wordt gegenereerd, THEN MUST een ZIP-bundel worden geleverd met de WNT-bijlage, de onderliggende component-detail per topfunctionaris, de payroll-runs als bronbestand, en een checksum-cover-blad; en MUST de export-aanvraag worden gelogd met requester-id en timestamp.
- GIVEN een accountant met read-only role `wnt-auditor`, WHEN de accountant het WNT-dashboard opent, THEN MUST alle topfunctionarissen, hun bezoldigings-componenten, en de berekende totalen zichtbaar zijn zonder mutatie-mogelijkheden EN MUST elke detail-view-actie worden gelogd in `wnt-access-audit`.
- GIVEN een ADR-controleur die om een specifiek bron-bewijs voor een natura-component vraagt (bijvoorbeeld de lease-overeenkomst-PDF die de EUR 9.500 waardering onderbouwt), WHEN de controleur het bewijs aanvraagt via de UI, THEN MUST het document direct toegankelijk zijn vanuit de WNT-component-detail-view (via document-store-link).

### REQ-010: Historische correcties en meerjaren-vergelijking

Het systeem MUST correcties op historische jaren ondersteunen (bijvoorbeeld een bonus die over voorgaand jaar gaat) met versie-historie, en een meerjaren-vergelijkingsoverzicht bieden.

- GIVEN een bonus over 2025 die pas in maart 2026 wordt uitgekeerd, WHEN de uitkering wordt geboekt met `betreft-jaar = 2025`, THEN MUST de bonus aan de wnt-bezoldiging-componenten van 2025 worden toegevoegd EN MUST een herziening van de WNT-jaar-rapportage-2025 worden gegenereerd in concept-status.
- GIVEN een correctie op een 2024-bezoldigings-component die de overschrijdings-status wijzigt, WHEN de correctie wordt vastgelegd, THEN MUST de wnt-jaar-rapportage-2024 een nieuwe versie krijgen en MUST een attentie aan de controller worden gestuurd over de impact op de reeds gepubliceerde jaarverslag-cijfers 2024.
- GIVEN een meerjaren-rapportage-aanvraag (5 jaar terug), WHEN de controller dit aanroept, THEN MUST een trend-overzicht per topfunctionaris over de gevraagde jaren worden getoond met bezoldiging, norm, en overschrijding, en MUST een export naar XLSX mogelijk zijn.

## Standards & Sources

Wettelijke basis: Wet normering bezoldiging topfunctionarissen publieke en semipublieke sector (WNT, Stb. 2012, 583), Reparatiewet WNT (Stb. 2017, 432), Wet uitbreiding personele werkingssfeer WNT-2 (Evaluatiewet WNT 2017), Uitvoeringsregeling WNT (Stcrt. 2013, jaarlijks geactualiseerd; versie 2026 als referentie), Regeling indeling WNT-zorg (jaarlijks), en Regeling indeling WNT-onderwijs (jaarlijks). De jaarlijkse vaststelling van WNT-norm-bedragen en klasse-bedragen wordt door het Ministerie van Binnenlandse Zaken en Koninkrijksrelaties (BZK) in november voorafgaand aan het kalenderjaar gepubliceerd via een Regeling.

Format-standaarden: Modeltekst WNT-verantwoording (jaarlijks door BZK gepubliceerd, met voorbeeldtabellen per topfunctionaris); SBR-Taxonomie (Standard Business Reporting) voor digitale jaarverslag-publicatie bij Belastingdienst / Kamer van Koophandel met WNT-extension-element-set 2026; Richtlijnen voor de Jaarverslaggeving (RJ) Hoofdstuk 271.7 over de WNT-bijlage in het bestuursverslag.

Toezicht en handhavings-bronnen: Auditdienst Rijk (ADR) toetsingsdocument WNT (jaarlijks bijgewerkt); Toezichtdocument WNT van Inspectie van Onderwijs voor onderwijs-organisaties; Handvat Toetsingskader WNT van Inspectie Gezondheidszorg en Jeugd voor zorg-organisaties; Aanwijzingen WNT van het Centraal Fonds Volkshuisvesting voor woningcorporaties; en de jaarlijkse WNT-trendrapportage van BZK over geconstateerde overschrijdingen.

Concurrent-analyse: AFAS Profit WNT-Tooling, Visma RAET HR Core Education met WNT-add-on, Centric Persoonsregister WNT-functie, en specifieke WNT-rapportage-providers zoals Pelican Reports en Onguard WNT-Reporter (laatste twee zijn standalone reporting-tools die uit payroll-data van derden voeden). Conduction tender-analyse: 89% van overheids- en onderwijs-HR-aanbestedingen noemen WNT-rapportage expliciet, voor zorg-aanbestedingen ligt dit op 76% (specter database, query op tenders binnen WNT-werkingssfeer).

## Cross-app Integration

Upstream dependencies: `employee-master` levert basis-werknemer-data en de mogelijkheid een werknemer aan te merken als topfunctionaris (`is-topfunctionaris` boolean met validatie tegen functie-categorie); `payroll-engine-nl` levert alle reguliere bezoldigings-componenten (bruto-loon, IKB, ontslagvergoedingen, bonussen) per loontijdvak met `wnt-relevant`-vlag; `document-template-engine` rendert de WNT-bijlage in het wettelijke format met juiste tabel-structuur; `voorzieningen-administratie` (gedeeld met pensioen-modules) levert de werkgever-pensioenpremies en levenslooppremie-toedelingen.

Optioneel upstream: `lease-auto-administratie` levert natura-waarderingen voor lease-autos op basis van leeftijd-en-categorie tabel; `huisvesting-administratie` levert natura-waarderingen voor woning-faciliteiten; `inhuur-administratie` levert interim-bestuurder-contracten als wijkgevingen-bron voor fictieve-dienstbetrekking-aanwijzingen.

Downstream consumers: `jaarverslag-generator` neemt de WNT-bijlage op als appendix in het bestuursverslag-jaarverslag; `terugvordering-administratie` (gedeeld met onverschuldigde-betaling-modules) verwerkt de WNT-terugvorderings-records als specifieke type met verkort-incassotraject; `boekhouding-export` boekt overschrijdings-bedragen als kostenplaats `wnt-overschrijding` voor zichtbaarheid in management-rapportages.

Externe integraties: SBR-koppelvlak (Standard Business Reporting via Digipoort) voor digitale jaarverslag-publicatie inclusief WNT-bijlage in de XBRL-extension-formaat; BZK-publicatie-portaal (topinkomensregister.nl) voor optionele directe aanlevering (sommige sectoren publiceren via een centraal register); accountants-software-koppelvlakken (Audition, CaseWare) voor accountants-controle-werkpapieren.

## Target Users

Primaire gebruiker is de controller / financieel-directeur van de WNT-plichtige organisatie. Deze gebruiker is verantwoordelijk voor de juistheid van de jaar-rapportage, monitort overschrijdingen real-time, initieert terugvorderingen, en geeft de definitieve goedkeuring aan de gegenereerde WNT-bijlage voorafgaand aan de RvB-vaststelling. Typisch portfolio: 5-50 topfunctionarissen, jaarlijkse rapportage-cyclus met intensievere activiteit in Q1 (jaarafsluiting) en Q2-Q3 (jaarverslag-publicatie en accountantscontrole).

Secundaire gebruikers: jaarverslag-redacteur / communicatie-manager (genereert en redigeert de bijlage voor opname in het jaarverslag), HR-directeur (verifieert topfunctionaris-aanwijzingen en houdt overzicht over bezoldigings-componenten), payroll-administrateur (registreert WNT-relevante mutaties zoals bonussen en ontslagvergoedingen met juiste vlaggen), en interne audit (controleert jaarlijks op compleetheid van topfunctionaris-aanwijzingen en bezoldigings-aggregatie).

Tertiaire / read-only gebruikers: externe accountant (jaarlijkse controle van WNT-rapportage als onderdeel van controleverklaring; krijgt `wnt-auditor`-role tijdens controleperiode); Auditdienst Rijk (bij rijksoverheid en ZBO's) voor jaarlijkse of incidentele steekproef-controle; toezichthoudende inspecties (Onderwijs / Gezondheidszorg en Jeugd / Sociale Zaken) bij sectoriele toezicht-acties; en de leden van de raad van toezicht / raad van commissarissen die de WNT-bijlage formeel vaststellen.

Niet-direct-gebruikers maar belangrijke stakeholders: de topfunctionarissen zelf (hebben recht op inzage in hun eigen WNT-aggregatie via het employee-portal); de werknemersondernemingsraad (ontvangt in sommige organisaties de geaggregeerde WNT-rapportage als onderdeel van WOR-informatieplicht); en de minister van BZK (ontvangt geaggregeerde WNT-data via SBR-publicatie als beleidsinformatie).
