---
status: draft
---
# ABP Aansluiting Verplicht voor Overheidssector

## Purpose

Het Algemeen Burgerlijk Pensioenfonds (ABP) is het verplichte bedrijfstak-pensioenfonds voor werknemers in de overheid en het onderwijs in Nederland. Iedere werkgever die valt onder de werkingssfeer van het Pensioenreglement van het ABP - dat wil zeggen elke gemeente, provincie, waterschap, ministerie, zelfstandig bestuursorgaan met overheidsstatus, alle openbaar onderwijs (PO/VO/MBO/HBO/WO) en het overgrote deel van het bijzonder onderwijs - is wettelijk verplicht (Wet privatisering ABP, 1996) tot aansluiting en tot maandelijkse aanlevering van premie- en deelnemersgegevens via de UPA-standaard (Uniforme Pensioenaangifte).

De UPA-aanlevering voor ABP is echter structureel verschillend van de reguliere UPA die voor de meeste andere bedrijfstak-pensioenfondsen (PFZW, BPF Bouw, PMT, et cetera) gebruikt wordt. ABP-UPA kent vijftien extra velden, een afwijkende voltijdsfactor-definitie (ABP rekent in voltijds-equivalenten van 36 uur, niet 40), een eigen segmentering voor het ABP-Keuzepensioen, en een verplichte VPL-bedrag-allocatie per deelnemer (Voorwaardelijk Pensioen op grond van de Wet aanpassing fiscale behandeling VUT/prepensioen). Bovendien koppelt ABP de UPA aan de Adieu-melding bij uitdiensttreding, die strikter is dan de generieke UPA-uit-melding: ABP eist binnen 5 werkdagen na laatste dienstdag, met einddatum-pensioenopbouw, reden-uitdiensttreding (catalogus van 14 codes), en uitkering-doorlopend-bij-vertrek-vlag.

Zonder een ABP-specifieke implementatie kan HRMQ geen overheidsklant bedienen. De `payroll-engine-nl` levert reeds de generieke UPA-uitvoer; deze brief specificeert het ABP-deel: extra veldenset, premieberekening, VPL, Keuzepensioen-flexibilisering, pensioenpartner-registratie, Adieu-melding, en een foutmeldingen-queue voor de ABP-retourberichten. Het ABP retourneert dagelijks Confirmations en Reject-berichten in de UPA-CRS-formaat (Centrale Retourberichten Standaard); fouten moeten in een admin-queue verschijnen met een lees- en correctie-workflow.

De target users zijn salarisadministrateurs en pensioenadministrateurs bij overheidswerkgevers, met een verwachte caseload van 500-5000 deelnemers per administrateur. Voor grotere gemeenten (Amsterdam, Rotterdam, Den Haag, Utrecht) en provincies kan de set 5000-15000 deelnemers betreffen; voor ministeries en grote ZBO's tot 30000. De module moet schaalbaar zijn voor maandelijkse batch-verwerking van tienduizenden deelnemers binnen het ABP-aanleveringsvenster (uiterlijk 10e werkdag na loontijdvak).

## Data Model

Het centrale entity is `abp-deelnemer-registratie`. Per werknemer in dienst bij een ABP-plichtige werkgever wordt een deelnemer-record bijgehouden met velden: employee-id (FK naar employee-master), abp-deelnemersnummer (door ABP toegekend, ontvangen bij eerste aanmelding), aansluitings-datum (= datum-in-dienst of latere datum bij latere ABP-toetreding), deelnemingspercentage (0-100, gebaseerd op voltijdsfactor x ABP-deelnamefactor), regeling-code (`AP` = ABP-Pensioen, `KP` = Keuzepensioen-variant, `OP` = Ouderdoms-Plus, en zes andere), partnerpensioen-keuze (`opbouw` / `uitruil-bij-pensioendatum`), en arbeidsongeschiktheidspensioen-toepassing.

Per loontijdvak wordt een `abp-upa-record` aangemaakt met de vijftien ABP-specifieke velden bovenop de generieke UPA. Kerngegevens: pensioengevend-loon-abp (volgens ABP-definitie, die afwijkt van fiscaal-loon in behandeling van eindejaarsuitkering en eenmalige uitkeringen), voltijdsfactor-abp (uren gedeeld door 36, geen 40), franchise-deel (deel van loon waarover geen premie verschuldigd is, jaarlijks vastgesteld - 2026: EUR 17.545), premiegrondslag (loon minus franchise, x voltijdsfactor), werkgeverspremie (22.5% in 2026 voor regeling AP), werknemerspremie (4.5% in 2026 - 30% van totaal), VPL-bedrag (per deelnemer apart vastgesteld voor wie tussen 1950-1972 geboren is), KP-flexibilisering-saldo (mutaties op het Keuzepensioen-spaardeel als de deelnemer voor flexibilisering kiest), en arbeidsongeschiktheidspensioen-premie (apart, 0.4% in 2026).

De `abp-pensioenpartner` entity registreert partner-gegevens. ABP eist actieve partner-registratie - de werkgever moet de partner aanmelden zodra de werknemer een geregistreerde partner of huwelijkspartner heeft, anders bouwt geen partnerpensioen op (een veel-voorkomende bron van pensioenklachten). Velden: deelnemer-id (FK), partner-bsn, partner-geboortedatum, samenwonings-datum (huwelijk / geregistreerd-partnerschap / notarieel-samenlevingscontract), registratie-datum-bij-ABP, en einddatum (bij scheiding of overlijden, met reden-code).

De `abp-adieu-melding` entity is het uittredings-bericht. Verplichte velden: deelnemer-id, laatste-werkdag, reden-uitdienst-code (van 1: ontslag-werkgever t/m 14: einde-dienstverband-onder-WIA), pensioenopbouw-doorlopend-vlag (bij overstap naar andere ABP-werkgever binnen 1 maand kan opbouw doorlopen), tijdelijk-vlag (voor uitkering-zonder-uitdiensttreding-situaties), en eind-pensioengevend-loon. Een Adieu-melding MOET binnen 5 werkdagen na laatste-werkdag bij ABP aankomen, anders volgt een Reject met code `ABP-ADIEU-LATE` en moet de werkgever via een correctieflow alsnog aanleveren.

De `abp-retour-bericht` entity is de inbox voor wat ABP retour-stuurt: Confirmation, Reject (met fout-code uit de catalogus van 247 ABP-foutcodes), Waarschuwing (acceptatie-met-vlag), en Vraag (om verduidelijking via terug-naar-werkgever-bericht). Per retour-bericht: ontvangen-datum, type, gerelateerd-upa-record-id of adieu-id, fout-code, fout-omschrijving, en `verwerkings-status` (open / in-behandeling / opgelost / gesloten-niet-oplosbaar).

Het `vpl-saldo` entity is een per-deelnemer running balance voor de Voorwaardelijke Pensioenaanspraak (VPL). Deelnemers geboren tussen 1 januari 1950 en 31 december 1972 hebben een VPL-recht dat in 2023 onvoorwaardelijk werd. ABP vraagt om jaarlijkse VPL-bedrag-bevestiging per deelnemer; werkgever moet aanleveren via UPA-bijlage VPL-update.

## Requirements

### REQ-001: ABP-aansluitings-determinatie

Het systeem MUST bij elke nieuwe indienst-treding bepalen of de werknemer onder de ABP-werkingssfeer valt, gebaseerd op werkgever-categorie en functie-categorie, en zo ja een `abp-deelnemer-registratie` aanleggen.

- GIVEN een werkgever met `is-abp-plichtig = true`, WHEN een nieuwe indienst-melding wordt vastgelegd voor een functie buiten de ABP-uitzonderingen (geen stagiair, geen oproepkracht-met-uitsluiting), THEN MUST een abp-deelnemer-registratie worden aangemaakt met aansluitings-datum = datum-in-dienst.
- GIVEN een werkgever met deels-ABP-deels-PFZW situatie (bijvoorbeeld een zorginstelling met onderwijs-tak), WHEN HR een werknemer registreert met functie-categorie `onderwijs`, THEN MUST de ABP-registratie aangemaakt worden in plaats van PFZW.
- GIVEN een werknemer die overstapt van een ABP-werkgever naar een andere ABP-werkgever met overlap-vrije aansluiting, WHEN de indienst-melding wordt verwerkt, THEN MUST het bestaande abp-deelnemersnummer worden overgenomen en geen nieuwe ABP-aanmelding worden gestuurd.

### REQ-002: Maandelijkse UPA-aanlevering met ABP-velden

De UPA-aanlevering MUST per loontijdvak alle ABP-deelnemers van de werkgever bevatten, inclusief de vijftien ABP-specifieke velden, geformatteerd volgens UPA versie 2026.01 met ABP-extensions.

- GIVEN een afgesloten loontijdvak voor een werkgever met 1.247 ABP-deelnemers, WHEN de UPA-generator wordt getriggerd, THEN MUST een ABP-UPA-bestand worden gegenereerd met 1.247 deelnemer-records en valid XSD volgens UPA 2026.01.
- GIVEN een deelnemer met deeltijd-arbeidsovereenkomst van 28 uur, WHEN het ABP-UPA-record wordt gegenereerd, THEN MUST de voltijdsfactor-abp = 28 / 36 = 0.7778 worden gerekend (NIET 28/40 = 0.7).
- GIVEN een loontijdvak waarin een deelnemer een eenmalige uitkering ontving die volgens ABP-definitie pensioengevend is, WHEN het record wordt gegenereerd, THEN MUST de uitkering in het pensioengevend-loon-abp worden opgenomen ook al is dit anders dan de fiscale grondslag.

### REQ-003: Premieberekening werkgever en werknemer

De ABP-premies (werkgever 22.5%, werknemer 4.5% in 2026 voor regeling AP) MUST per loontijdvak worden berekend over de premiegrondslag (loon minus franchise, gecorrigeerd voor voltijdsfactor), en als loonstrook-regels en grootboek-posten verschijnen.

- GIVEN een fulltime deelnemer met pensioengevend-loon EUR 4.500/maand in 2026, WHEN payroll runs, THEN MUST de maandelijkse premiegrondslag = EUR 4.500 - (EUR 17.545 / 12) = EUR 4.500 - EUR 1.462,08 = EUR 3.037,92 zijn; werkgeverspremie = EUR 683,53; werknemerspremie op loonstrook = EUR 136,71.
- GIVEN een deeltijd-deelnemer (0.6 voltijdsfactor) met pensioengevend-loon EUR 2.700/maand, WHEN payroll runs, THEN MUST de franchise pro rata worden toegepast: franchise-deel = EUR 1.462,08 * 0.6 = EUR 877,25, premiegrondslag = EUR 2.700 - EUR 877,25 = EUR 1.822,75.
- GIVEN een loontijdvak in januari 2027 nadat ABP-premie-percentages zijn gewijzigd, WHEN payroll runs, THEN MUST de nieuwe percentages worden toegepast op basis van de `abp-premie-tarief-tabel` waarvan de geldigheid op het loontijdvak gebaseerd is.

### REQ-004: VPL-bedrag administratie

Voor elke deelnemer geboren tussen 1 januari 1950 en 31 december 1972 MUST een VPL-bedrag per jaar worden geadministreerd en jaarlijks aan ABP worden gerapporteerd via de UPA-VPL-bijlage.

- GIVEN een deelnemer geboren 1965 (binnen VPL-cohort), WHEN het januari-loontijdvak wordt verwerkt, THEN MUST een VPL-bedrag-mutatie worden geregistreerd op basis van de ABP-formule (pensioengevend loon over kalenderjaar minus franchise x VPL-percentage 2.0% voor reguliere ABP-deelnemers).
- GIVEN een deelnemer geboren 1980 (buiten VPL-cohort), WHEN het loontijdvak wordt verwerkt, THEN MUST GEEN VPL-bedrag-mutatie worden geregistreerd.
- GIVEN het einde van een kalenderjaar, WHEN de jaarafsluitings-batch wordt gedraaid, THEN MUST per VPL-cohort-deelnemer een totaal-VPL-bedrag van dat jaar worden berekend en in de UPA-VPL-bijlage van het januari-tijdvak van het volgende jaar worden opgenomen.

### REQ-005: ABP-Keuzepensioen flexibilisering

Deelnemers MUST de mogelijkheid hebben hun Keuzepensioen te flexibiliseren: extra inleg, deelpensioen-aanvragen, of pensioenleeftijd-flexibilisering. Werkgever-mutaties moeten via UPA worden doorgegeven.

- GIVEN een deelnemer die via het employee-portal extra-inleg-KP van EUR 100/maand kiest, WHEN de inleg-keuze wordt vastgelegd, THEN MUST de werknemerspremie op de eerstvolgende loonstrook met EUR 100 worden verhoogd EN MUST het KP-flexibilisering-saldo in het UPA-record met +EUR 100 worden gemuteerd.
- GIVEN een deelnemer die deelpensioen (50%) per 1 januari 2027 aanvraagt, WHEN de aanvraag wordt verwerkt, THEN MUST het deelnemingspercentage in de abp-deelnemer-registratie naar 50% worden gezet per ingangsdatum EN MUST een tijdelijk-vlag in het UPA-record komen tot ABP de mutatie bevestigt.
- GIVEN een wijziging in de extra-inleg, WHEN de wijziging wordt vastgelegd buiten de UPA-aanlever-cyclus om, THEN MUST de wijziging worden gequeued tot het volgende loontijdvak.

### REQ-006: Pensioenpartner-registratie

Bij wijzigingen in burgerlijke staat (huwelijk, geregistreerd partnerschap, notarieel samenlevingscontract, scheiding) MUST de werkgever de partner-gegevens registreren of beeindigen via een dedicated ABP-mutatiebericht.

- GIVEN een werknemer die via het employee-portal een huwelijk meldt met partner-BSN en datum, WHEN HR de melding bevestigt, THEN MUST een abp-pensioenpartner-record worden aangemaakt en een partner-aanmeldingsbericht aan ABP worden gestuurd binnen 30 dagen.
- GIVEN een werknemer met geregistreerde partner die scheiding meldt, WHEN HR de scheiding bevestigt met einddatum, THEN MUST de abp-pensioenpartner-record worden beeindigd EN MUST een partner-beeindigings-bericht aan ABP worden gestuurd EN MUST een attentie-bericht aan de werknemer worden gemaild over verdeling-ouderdomspensioen volgens Wet VPS.
- GIVEN een werknemer die een notarieel samenlevingscontract aandraagt zonder dit eerder te hebben geregistreerd, WHEN HR de registratie verwerkt, THEN MUST een upload-veld voor het contract verschijnen (scan PDF), MUST de registratie-datum vrij invulbaar zijn, en MUST het ABP-bericht inclusief contract-bewijs-vlag worden verzonden.

### REQ-007: Adieu-melding bij uitdiensttreding

Bij uitdiensttreding MUST binnen 5 werkdagen een Adieu-melding aan ABP worden verzonden met laatste-werkdag, reden-uitdienst-code (uit de 14-code catalogus), en de eind-pensioengevend-loon-snapshot.

- GIVEN een werknemer met laatste werkdag 2026-08-31, WHEN de uitdienst-procedure op 2026-08-25 wordt afgerond, THEN MUST uiterlijk 2026-09-07 (5 werkdagen na 2026-08-31) een Adieu-melding bij ABP zijn aangeleverd.
- GIVEN een uitdienst-melding zonder reden-code, WHEN HR de Adieu-melding wil versturen, THEN MUST de submit worden geblokkeerd met error `ABP-ADIEU-REASON-REQUIRED` en de catalogus van 14 codes met omschrijvingen worden getoond.
- GIVEN een uitdienst direct gevolgd door indienst-melding bij een andere ABP-werkgever binnen 30 dagen, WHEN beide werkgevers HRMQ gebruiken, THEN MUST de Adieu-melding `pensioenopbouw-doorlopend-vlag = true` zetten EN MUST de nieuwe werkgever bij intake het bestaande abp-deelnemersnummer overnemen.

### REQ-008: ABP-retour-berichten admin queue

Alle ABP-retour-berichten (Confirmation, Reject, Waarschuwing, Vraag) MUST in een admin-queue zichtbaar zijn met filter-op-foutcode, leeftijd-van-melding, en gerelateerde werknemer; de queue MUST een werkflow voor correctie bieden.

- GIVEN een Reject-bericht met fout-code `ABP-PG-001` (pensioengevend-loon negatief), WHEN het bericht in de queue verschijnt, THEN MUST de gerelateerde deelnemer en het UPA-record zichtbaar zijn met een "correctie maken" actie die het mutaties-formulier opent.
- GIVEN een queue met 50 open Reject-berichten ouder dan 14 dagen, WHEN een admin het dashboard opent, THEN MUST een waarschuwings-banner verschijnen met aantal te oude meldingen, en MUST de meldingen kunnen worden gefilterd op leeftijd.
- GIVEN een afgehandeld Reject met succesvolle correctie-UPA, WHEN de correctie door ABP wordt geaccepteerd, THEN MUST het oorspronkelijke retour-bericht naar status `gesloten-opgelost` overgaan zonder admin-actie.

### REQ-009: ABP-aansluitings-data-correctie

Historische correcties (terugwerkende-kracht-mutaties op pensioengevend loon, deelnemingspercentage, of franchise) MUST via een aparte UPA-mutatiebericht met `correctie-vlag = true` worden aangeleverd, met correctie-tijdvak-aanduiding.

- GIVEN een TWK-loonsverhoging van EUR 200/maand met ingangsdatum 6 maanden terug, WHEN payroll de correctie boekt, THEN MUST voor elk van de 6 eerdere maanden een correctie-UPA-record worden gegenereerd met de verhoogde grondslag EN MUST de extra werkgeverspremie en werknemerspremie worden berekend en op de huidige loonstrook geboekt.
- GIVEN een correctie die over een jaargrens heen loopt, WHEN de correctie wordt verwerkt, THEN MUST de premiepercentages van het oude jaar worden toegepast voor de tijdvakken in dat jaar.
- GIVEN een correctie die de premiegrondslag negatief maakt (bijvoorbeeld door fictieve loon-correctie boven het franchise-bedrag), WHEN de correctie wordt geprobeerd te boeken, THEN MUST een waarschuwing aan de salarisadministrateur worden getoond met de optie om alsnog te boeken (met `negatieve-grondslag-toegestaan-vlag`) of de correctie te annuleren.

### REQ-010: Werkgeverslasten-rapportage 22.5%

Per maand MUST een werkgeverslasten-rapportage worden gegenereerd waarin de ABP-werkgeverspremie als percentage van de bruto-loonsom zichtbaar is, per kostencentrum en per CAO-segment.

- GIVEN een maandelijkse afsluiting met 1.247 deelnemers, WHEN het werkgeverslasten-rapport wordt gegenereerd, THEN MUST de totale ABP-werkgeverspremie, het aantal deelnemers, en het percentage van de bruto-loonsom worden getoond, gegroepeerd per kostencentrum.
- GIVEN een jaarafsluiting, WHEN het jaar-rapport wordt gegenereerd, THEN MUST een breakdown per regeling-code (AP / KP / OP / etc.) en per VPL-cohort vs niet-VPL worden getoond.
- GIVEN een audit-vraag van de controller over een specifieke maand, WHEN de controller het rapport download als XLSX, THEN MUST per deelnemer-regel de premiegrondslag, premie, en kostencentrum-toewijzing worden vermeld.

## Standards & Sources

Wettelijke basis: Wet privatisering ABP (Wet PRABP, Stb. 1995, 639), Pensioenwet (Stb. 2006, 705), Wet aanpassing fiscale behandeling VUT/prepensioen (Wet VPL, Stb. 2005, 115) voor de VPL-rechten, Wet verevening pensioenrechten bij scheiding (Wet VPS, Stb. 1994, 342), en het ABP-Pensioenreglement (jaarlijks geactualiseerd; versie 2026 als referentie).

Technische standaarden: UPA versie 2026.01 (Uniforme Pensioenaangifte, Stichting Pensioenregister + Pensioenfederatie), met ABP-extension-schema versie 2026.01-ABP; CRS (Centrale Retourberichten Standaard) versie 2.4 voor retour-berichten; ABP-koppelvlak-specificatie 8.2 voor de aanlevering en koppelvlak-specificatie 6.1 voor partner-mutatieberichten.

Foutcode-catalogi: ABP-CRS-foutcatalogus versie 2025-Q4 met 247 foutcodes; UPA-Generieke-foutcatalogus met 89 codes (PFZW, BPF Bouw, et cetera gebruiken een subset). Operationele referenties: ABP-Handboek-Werkgever (jaarlijks), ABP-Servicewebsite voor werkgevers (mijnabp.nl/werkgever), en de Pensioenfederatie-publicatie `UPA-handreiking-ABP-versus-PFZW`.

Concurrent-analyse en reference implementations: AFAS Profit Pensioen ABP-module, Visma RAET Beaufort ABP-Add-on, Centric Persoonsregister met ABP-aanlevering, en Cipers (Centric IPO-Salaris, gebruikt door veel provincies). De `tender-analysis` toonde dat 100% van overheids-payroll-aanbestedingen ABP-aansluiting als KO-criterium hadden (specter database, query op tenders waar `werkgever-categorie = overheid`).

## Cross-app Integration

Upstream dependencies: `payroll-engine-nl` levert het bruto-loon, eenmalige-uitkering-vlaggen, voltijdsfactor (in 40-urige eenheid; deze module rekent om naar 36); `employee-master` levert werknemer-stamdata, geboortedatum (cruciaal voor VPL-cohort), burgerlijke-staat, partner-bsn, dienstverband-historie; `cao-gemeenten` en zes andere overheids-CAO-modules (`cao-rijk`, `cao-provincies`, `cao-waterschappen`, `cao-po`, `cao-vo`, `cao-mbo`) leveren CAO-specifieke ABP-deelnamefactor-uitzonderingen (sommige CAO-functies zijn slechts 50% deelnemend); `franchise-tarief-tabel` (gedeeld met PFZW-module) levert de jaarlijkse franchise-bedragen.

Downstream consumers: `boekhouding-export` boekt ABP-werkgeverspremie op grootboek 4030 (sociale lasten pensioen) en werknemerspremie op grootboek 1610 (te-betalen pensioenpremie); `wnt-disclosure` neemt ABP-pensioenpremie mee in het topfunctionaris-beloningstotaal; `jaaropgave-generator` verwerkt de werknemerspremie in de jaaropgave als ingehouden-pensioenpremie.

Externe integraties: ABP via SFTP-koppelvlak (productie: sftp.abp.nl/werkgever) voor UPA-upload; ABP via REST-koppelvlak voor partner-mutaties (mijnabp-werkgever API versie 6.1); Logius (Digipoort) als alternatief upload-kanaal voor werkgevers die SFTP niet rechtstreeks willen. De Conduction `openconnector` regelt deze koppelvlakken met dedicated connectoren `abp-sftp` en `abp-rest`, beide met production en acceptatie endpoints.

Voor de retour-bericht-flow: ABP plaatst dagelijks (07:00) een batch retour-berichten in de SFTP-out-directory; HRMQ pollt elke 30 minuten via een cron-taak; binnen 1 uur na publicatie zijn de berichten in de admin-queue zichtbaar.

## Target Users

Primaire gebruiker is de salarisadministrateur bij een ABP-plichtige werkgever, met een typische caseload van 500-5000 actieve deelnemers. Deze gebruiker draait maandelijks de UPA-batch, verwerkt retour-berichten, en handelt mutaties (in dienst, uit dienst, partner, deelpensioen). Bij grotere overheidsorganisaties is dit een specialistische pensioenadministrateur die alleen ABP-aansluitingen behandelt, niet payroll als geheel.

Secundaire gebruikers: HR-administrateurs (intake nieuwe medewerkers, eerste partnerregistratie), de controller (werkgeverslasten-rapportage, jaarafsluiting), werknemers zelf via het employee-portal (deelnemersnummer-inzage, extra-inleg-KP keuzes, partnerregistratie-verzoeken), en de uitdienst-procedure-eigenaar (typisch HR of management-secretaris, die de Adieu-melding triggert).

Tertiaire gebruikers: accountant (controle ABP-afdrachten als onderdeel van loonkostencontrole), interne audit (correctheid VPL-administratie), en het overheids-pensioen-overleg-orgaan (POO) bij die werkgevers die hun ABP-implementatie aan een commissie verantwoorden. De ABP-helpdesk zelf is geen directe gebruiker maar wel referent voor foutcode-interpretatie.
