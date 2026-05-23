---
status: draft
---
# Functiehuis HR21 (functiewaardering voor gemeenten)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Medewerkers › Functiehuis

**Rationale:** HR21 functiewaardering (config-view).  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Implementeer het volledige HR21-functiehuis binnen hrmq als de canonieke functiebibliotheek voor de gemeentelijke sector, met ongeveer 150 normfuncties die de meest voorkomende gemeentelijke functies dekken — van Beleidsmedewerker en BOA tot Civiel Werkvoorbereider en Wijkbeheerder. HR21 is het door VNG geadopteerde sectorale functiewaarderingssysteem en vormt de basis voor de inschalingsbeslissingen onder de CAO Gemeenten: zonder correcte HR21-indeling kan geen rechtsgeldige salarisbepaling plaatsvinden.

Deze brief levert de datamodellen, indelingsworkflows en validatiemechanismen die nodig zijn om iedere gemeente — van een 40-medewerkers dorpsgemeente tot een G4-stad met 15.000 ambtenaren — haar functies HR21-conform te beheren. De module ondersteunt zowel het selecteren van een bestaande normfunctie als het creëren van een maatwerk-functie wanneer geen normfunctie passend is, met de daarbij vereiste auditeerbare onderbouwing en de mogelijkheid tot bezwaar door de medewerker conform de Algemene wet bestuursrecht (Awb).

De grootste uitdaging in HR21 is niet de datamodellering zelf, maar de **indelingsworkflow**: een nieuwe functie ontstaat typisch wanneer een leidinggevende een vacature wil openstellen of een bestaande functie wil herzien. De HR-adviseur stelt een indelingsvoorstel op (welke normfunctie + welke schaal), de leidinggevende moet akkoord geven, de medewerker (bij wijziging van bestaande functie) heeft inzage en bezwaarrecht, en uiteindelijk wordt de indeling vastgelegd met direct gevolg voor de salarisschaal via de koppeling met `cao-gemeenten`. Iedere stap in deze workflow moet auditeerbaar zijn — een functie-indeling kan jaren later nog ter discussie staan in een ontslagprocedure of bezwaarschrift.

Een tweede uitdaging is **hercategorisatie bij functiewijziging**: wanneer een medewerker doorgroeit van Beleidsmedewerker I naar Beleidsmedewerker II, of wanneer een functie inhoudelijk verandert door een reorganisatie, moet het systeem de wijziging traceerbaar vastleggen inclusief de salarisgevolgen (horizontaal-aansluitende periodiek, geen achteruitgang in inkomen). Bij collectieve hercategorisaties (bijvoorbeeld een sector-brede functieherwaardering) moet een batch-proces mogelijk zijn met per medewerker individuele review en goedkeuring.

Doel is dat een HR-adviseur in 5 minuten een functie kan toewijzen aan een nieuwe medewerker met de correcte schaalbepaling; dat een leidinggevende in 2 minuten een indelingsvoorstel kan goedkeuren via een mobile-vriendelijke interface; dat een medewerker te allen tijde zijn functie-indeling en de onderbouwing kan inzien; en dat een controller of accountant achteraf iedere functiewijziging kan herleiden naar een formeel besluit met de juiste handtekening en motivatie.

## Data Model (entities + Dutch JSON)

Het HR21-functiehuis kent vier kern-entiteiten: de **Normfunctie** (de ~150 voorgedefinieerde functies van HR21), de **Maatwerkfunctie** (gemeente-specifieke functies wanneer geen normfunctie past), de **Functietoekenning** (de koppeling tussen medewerker en functie) en de **Indelingsworkflow** (het proces van voorstellen, goedkeuren en eventueel bezwaar maken).

**HR21_Normfunctie** (centrale bibliotheek):
```json
{
  "functieCode": "HR21-BELEIDSMEDEWERKER-II",
  "functieNaam": "Beleidsmedewerker B",
  "functieFamilie": "Beleidsadvisering",
  "niveau": "II",
  "schaalBereik": {"min": 9, "max": 11},
  "voorkeurschaal": 10,
  "korteOmschrijving": "Ontwikkelt beleid op deelterreinen, adviseert bestuur en management.",
  "kerntaken": [
    "Beleidsvoorbereiding op afgebakend terrein",
    "Adviseren management en bestuur",
    "Schrijven van bestuursvoorstellen en raadsstukken",
    "Onderhouden contacten met externe partners",
    "Bijdragen aan beleidsuitvoering en evaluatie"
  ],
  "vereisteCompetenties": [
    {"competentie": "Analytisch vermogen", "niveau": "gevorderd"},
    {"competentie": "Schriftelijk uitdrukkingsvermogen", "niveau": "gevorderd"},
    {"competentie": "Bestuurlijke sensitiviteit", "niveau": "basis"},
    {"competentie": "Resultaatgerichtheid", "niveau": "gevorderd"},
    {"competentie": "Samenwerken", "niveau": "gevorderd"}
  ],
  "vereisteOpleiding": "HBO werk- en denkniveau, bij voorkeur in relevante studierichting",
  "vereisteErvaring": "Minimaal 3 jaar relevante werkervaring",
  "fuwasysScore": 42,
  "functiewaarderingsmethode": "ODRP",
  "geldigVanaf": "2020-01-01",
  "geldigTot": null,
  "vngBron": "https://hr21.nl/normfuncties/beleidsmedewerker-b",
  "versie": "2024.1"
}
```

**HR21_Functiefamilie** (groepering van normfuncties):
```json
{
  "familieCode": "BELEIDSADVISERING",
  "familieNaam": "Beleidsadvisering",
  "korteOmschrijving": "Functies gericht op beleidsontwikkeling, beleidsadvisering en beleidsondersteuning",
  "normfunctiesInFamilie": [
    "HR21-BELEIDSMEDEWERKER-I",
    "HR21-BELEIDSMEDEWERKER-II",
    "HR21-BELEIDSMEDEWERKER-III",
    "HR21-SENIOR-BELEIDSMEDEWERKER",
    "HR21-STRATEGISCH-BELEIDSADVISEUR"
  ],
  "schaalBereikFamilie": {"min": 8, "max": 14}
}
```

**Maatwerkfunctie** (gemeente-specifiek wanneer normfunctie ontbreekt):
```json
{
  "maatwerkFunctieId": "uuid",
  "functieCode": "MW-AMSTERDAM-DATA-ETHIEK-ADVISEUR",
  "functieNaam": "Adviseur Data-ethiek",
  "gemeenteCode": "GM0363",
  "onderbouwingMaatwerk": "Functie ontstaan in 2023 vanwege specifieke behoefte aan ethische advisering op data-vraagstukken; geen passende HR21-normfunctie beschikbaar.",
  "afgeleidVanNormfunctie": "HR21-SENIOR-BELEIDSMEDEWERKER",
  "voorgesteldeSchaal": 12,
  "schaalOnderbouwing": "Functie vereist combinatie van technische diepgang (datawetenschap) en ethische reflectie; vergelijkbaar met Senior Beleidsmedewerker maar met specialistische component.",
  "kerntaken": [
    "Adviseren over ethische aspecten van data-gebruik",
    "Opstellen van data-ethische kaders",
    "Beoordelen van algoritmes op fairness en bias",
    "Vertegenwoordigen gemeente in landelijke fora"
  ],
  "vereisteCompetenties": [
    {"competentie": "Analytisch vermogen", "niveau": "expert"},
    {"competentie": "Ethische reflectie", "niveau": "expert"},
    {"competentie": "Bestuurlijke sensitiviteit", "niveau": "gevorderd"}
  ],
  "ingangsdatum": "2024-03-01",
  "goedgekeurdDoor": "Directeur Bedrijfsvoering",
  "goedkeuringsdatum": "2024-02-15",
  "reviewDatum": "2027-03-01"
}
```

**Functietoekenning** (per medewerker, met historie):
```json
{
  "toekenningId": "uuid",
  "medewerkerId": "uuid",
  "functieCode": "HR21-BELEIDSMEDEWERKER-II",
  "functieType": "normfunctie",
  "ingangsdatum": "2024-03-01",
  "einddatum": null,
  "schaal": 10,
  "periodiek": 4,
  "afdeling": "Sociaal Domein",
  "leidinggevende": "uuid",
  "fte": 0.8889,
  "indelingsbesluitId": "uuid",
  "indelingsproces": "regulier",
  "status": "actief",
  "vorigeToekenning": "uuid",
  "wijzigingsreden": "Eerste indiensttreding",
  "auditTrail": [
    {
      "datum": "2024-02-20",
      "actie": "voorstel_ingediend",
      "doorGebruiker": "uuid",
      "details": "HR-adviseur stelt indeling Beleidsmedewerker B voor"
    },
    {
      "datum": "2024-02-25",
      "actie": "akkoord_leidinggevende",
      "doorGebruiker": "uuid",
      "details": "Leidinggevende akkoord met indeling en voorgestelde schaal"
    },
    {
      "datum": "2024-03-01",
      "actie": "vastgesteld",
      "doorGebruiker": "uuid",
      "details": "Indeling formeel vastgesteld bij indiensttreding"
    }
  ]
}
```

**Indelingsworkflow** (proces-record):
```json
{
  "workflowId": "uuid",
  "medewerkerId": "uuid",
  "type": "nieuwe_indeling",
  "status": "in_behandeling",
  "huidigeStap": "wacht_op_leidinggevende",
  "stappen": [
    {
      "stapNaam": "voorstel_door_hr",
      "status": "afgerond",
      "uitvoerder": "uuid",
      "afrondingsdatum": "2024-02-20",
      "voorstel": {
        "functieCode": "HR21-BELEIDSMEDEWERKER-II",
        "schaal": 10,
        "periodiek": 4,
        "motivatie": "Medewerker heeft 5 jaar relevante ervaring en past binnen profiel."
      }
    },
    {
      "stapNaam": "akkoord_leidinggevende",
      "status": "in_uitvoering",
      "uitvoerder": "uuid",
      "deadline": "2024-02-27"
    },
    {
      "stapNaam": "inzage_medewerker",
      "status": "wacht",
      "uitvoerder": "uuid",
      "bezwaartermijnDagen": 42
    },
    {
      "stapNaam": "definitief_vaststellen",
      "status": "wacht"
    }
  ],
  "ingediendOp": "2024-02-20",
  "verwachteAfrondingsDatum": "2024-04-10"
}
```

**Bezwaarprocedure** (bij bezwaar van medewerker tegen indeling):
```json
{
  "bezwaarId": "uuid",
  "medewerkerId": "uuid",
  "tegenIndelingsbesluit": "uuid",
  "bezwaarsgrond": "Medewerker is van mening dat functie als Senior Beleidsmedewerker (HR21-SENIOR-BELEIDSMEDEWERKER) ingedeeld moet worden wegens extra senior-taken.",
  "indieningsdatum": "2024-03-15",
  "behandelaar": "uuid",
  "status": "in_behandeling",
  "stappen": [
    {"stap": "ontvangstbevestiging", "datum": "2024-03-16"},
    {"stap": "vooronderzoek", "datum": "2024-03-20", "uitkomst": "ontvankelijk"},
    {"stap": "hoorzitting_commissie", "datum": "2024-04-10", "uitkomst": null},
    {"stap": "advies_commissie", "datum": null},
    {"stap": "beslissing_op_bezwaar", "datum": null}
  ],
  "wettelijkeTermijnAfloop": "2024-09-15",
  "uitkomst": null
}
```

## Requirements

### REQ-001: Volledige HR21-bibliotheek met ~150 normfuncties
Het systeem MOET alle door VNG/HR21 erkende normfuncties bevatten, gecategoriseerd in functiefamilies, met de bijbehorende schaalbereiken, kerntaken, competenties en opleidingseisen.

- **GIVEN** een HR-adviseur opent het functiebibliotheek-overzicht, **WHEN** wordt gezocht op "Beleidsmedewerker", **THEN** het systeem toont alle Beleidsmedewerker-functies (I, II, III en Senior) met hun schaalbereik en korte omschrijving.
- **GIVEN** een nieuwe versie van het HR21-functiehuis wordt gepubliceerd door VNG, **WHEN** de import draait, **THEN** het systeem voegt nieuwe normfuncties toe en markeert verwijderde functies als "gearchiveerd" zonder bestaande functietoekenningen te wijzigen.
- **GIVEN** een functiefamilie "Beleidsadvisering" wordt geselecteerd, **WHEN** de gebruiker doorklikt, **THEN** het systeem toont alle 5 normfuncties binnen deze familie inclusief het gezamenlijke schaalbereik 8-14.

### REQ-002: Indelingsvoorstel door HR-adviseur
Het systeem MOET HR-adviseurs in staat stellen een indelingsvoorstel op te stellen met functiecode, schaal, periodiek en motivatie, dat doorloopt naar de leidinggevende voor goedkeuring.

- **GIVEN** een HR-adviseur stelt een indelingsvoorstel op voor een nieuwe medewerker, **WHEN** de gekozen schaal buiten het toegestane bereik van de functie valt, **THEN** het systeem weigert het voorstel en toont de melding "Schaal 12 valt buiten het HR21-bereik 9-11 voor Beleidsmedewerker B."
- **GIVEN** een HR-adviseur dient een voorstel in, **WHEN** de motivatie korter is dan 50 tekens, **THEN** het systeem weigert indiening en vraagt om een uitgebreidere onderbouwing (kwaliteitseis voor auditeerbaarheid).
- **GIVEN** een voorstel is ingediend, **WHEN** de leidinggevende inlogt, **THEN** het systeem toont het voorstel in het dashboard van de leidinggevende met opties: akkoord, niet akkoord (met motivatie), of wijzigingsvoorstel.

### REQ-003: Goedkeuring leidinggevende met SLA-bewaking
Het systeem MOET de goedkeuring door de leidinggevende afdwingen binnen een redelijke termijn (default 7 werkdagen) en bij overschrijding escaleren naar de naasthogere leidinggevende.

- **GIVEN** een indelingsvoorstel is op 20 februari ingediend met deadline 27 februari, **WHEN** de leidinggevende op 28 februari nog niet heeft gereageerd, **THEN** het systeem escaleert automatisch naar de naasthogere leidinggevende en stuurt herinneringsmail.
- **GIVEN** een leidinggevende keurt het voorstel niet goed, **WHEN** geen motivatie wordt ingevoerd, **THEN** het systeem weigert de niet-akkoord-beslissing en eist een motivatie van minimaal 100 tekens.
- **GIVEN** een leidinggevende dient een wijzigingsvoorstel in (andere schaal), **WHEN** dit wordt verstuurd, **THEN** het systeem maakt een nieuw voorstel aan met de wijziging en stuurt terug naar de HR-adviseur voor herziening.

### REQ-004: Inzage en bezwaarrecht medewerker
Het systeem MOET bij iedere indelingswijziging de medewerker inzage geven in het besluit en een bezwaartermijn van 6 weken (42 dagen) bieden conform Awb.

- **GIVEN** een indelingsbesluit is vastgesteld voor een bestaande medewerker, **WHEN** het besluit definitief wordt, **THEN** het systeem stuurt een mail aan de medewerker met PDF-bevestiging, de motivatie, en informatie over de bezwaarmogelijkheid binnen 6 weken.
- **GIVEN** een medewerker logt in op het self-service portaal, **WHEN** de indelingsbesluiten-tab wordt geopend, **THEN** het systeem toont de volledige historie van indelingsbesluiten met motivaties en de mogelijkheid bezwaar in te dienen tegen recente besluiten.
- **GIVEN** een medewerker dient bezwaar in binnen de 6-wekentermijn, **WHEN** het bezwaar wordt geregistreerd, **THEN** het systeem start een formele bezwaarprocedure conform Awb, blokkeert wijzigingen aan de bestreden indeling en stelt de HR-bezwaarcommissie op de hoogte.

### REQ-005: Maatwerkfunctie met expliciete onderbouwing
Het systeem MOET maatwerkfuncties toestaan wanneer geen normfunctie passend is, mits een expliciete onderbouwing wordt opgegeven die uitlegt waarom de bestaande HR21-functies tekortschieten.

- **GIVEN** een HR-adviseur wil een maatwerkfunctie aanmaken, **WHEN** het formulier wordt gestart, **THEN** het systeem dwingt eerst een zoekstap af waarin minstens 3 normfuncties worden bekeken voordat maatwerk toegestaan wordt.
- **GIVEN** een maatwerkfunctie wordt voorgesteld, **WHEN** deze wordt ingediend, **THEN** het systeem vereist een onderbouwing van minimaal 250 tekens en goedkeuring door de Directeur HR of Bedrijfsvoering (niet alleen de directe leidinggevende).
- **GIVEN** een maatwerkfunctie bestaat al 3 jaar, **WHEN** de jaarlijkse review-cyclus draait, **THEN** het systeem signaleert dat de maatwerkfunctie tegen het licht gehouden moet worden — wellicht is inmiddels een passende HR21-normfunctie beschikbaar.

### REQ-006: Hercategorisatie bij functiewijziging
Het systeem MOET bij wijziging van een bestaande functie (promotie, demotie, herwaardering) automatisch de salarisconsequenties berekenen volgens de horizontaal-aansluitende-bedrag-regel, zonder achteruitgang in inkomen.

- **GIVEN** een medewerker zit in schaal 9 periodiek 7 met brutomaandsalaris € 3.450,00, **WHEN** een promotie naar schaal 10 wordt geregistreerd, **THEN** het systeem bepaalt automatisch de inschalingsperiodiek in schaal 10 als de eerste periodiek die ten minste € 3.450,00 bedraagt (volgens horizontaal-aansluitende-bedrag), in dit voorbeeld periodiek 3.
- **GIVEN** een functie wordt collectief geherwaardeerd van schaal 9 naar schaal 10 (sector-brede hercategorisatie), **WHEN** de batch-mutatie wordt gestart, **THEN** het systeem genereert per medewerker een individueel voorstel dat door HR moet worden goedgekeurd voordat het wordt doorgevoerd.
- **GIVEN** een medewerker maakt een functieverandering naar een lager-gewaardeerde functie (vrijwillig), **WHEN** de wijziging wordt voorgesteld, **THEN** het systeem signaleert dat sprake is van mogelijke demotie en vraagt expliciete bevestiging plus een persoonlijke verklaring van de medewerker dat hiermee wordt ingestemd.

### REQ-007: Auditeerbare wijzigingen met behoud historie
Iedere wijziging in functietoekenning MOET met volledige audit-trail worden vastgelegd, inclusief het besluit, de besluitnemers, de motivatie en de salarisconsequenties, en deze historie MOET onbeperkt bewaard blijven (geen verwijdering, alleen archivering).

- **GIVEN** een HR-medewerker vraagt de functiehistorie van een medewerker op, **WHEN** het rapport wordt gegenereerd, **THEN** het systeem toont alle functietoekenningen sinds indiensttreding met per wijziging: datum, vorige functie, nieuwe functie, schaal-wijziging, motivatie, goedkeurders.
- **GIVEN** een ex-medewerker (10 jaar geleden uit dienst) doet een verzoek tot inzage in zijn functiedossier, **WHEN** het AVG-inzageverzoek wordt verwerkt, **THEN** het systeem genereert een volledig PDF-dossier met alle functietoekenningen, motivaties en audit-records (verwijdering niet mogelijk vóór wettelijke bewaartermijnen verlopen zijn).
- **GIVEN** een controller voert een audit uit op alle maatwerkfuncties van afgelopen jaar, **WHEN** het rapport wordt opgevraagd, **THEN** het systeem genereert een overzicht met per maatwerkfunctie de onderbouwing, de goedkeurder, het aantal toegekende personen en de salariskosten.

### REQ-008: Sjabloon-functies versus maatwerk dashboards
Het systeem MOET dashboards bieden die de verhouding tussen normfuncties en maatwerkfuncties tonen, zodat de organisatie kan monitoren of het gebruik van maatwerk binnen acceptabele grenzen blijft.

- **GIVEN** een Directeur HR opent het functiehuis-dashboard, **WHEN** het overzicht wordt geladen, **THEN** het systeem toont: aantal normfuncties in gebruik, aantal maatwerkfuncties, percentage medewerkers in maatwerk (target < 10%), en trend over laatste 3 jaar.
- **GIVEN** een gemeente heeft >15% medewerkers in maatwerkfuncties, **WHEN** de jaarlijkse functiehuis-review draait, **THEN** het systeem genereert een waarschuwingsrapport met aanbeveling om met VNG/HR21 in gesprek te gaan over eventuele nieuwe normfuncties.
- **GIVEN** een gebruiker filtert het functieoverzicht op "maatwerk", **WHEN** de lijst wordt getoond, **THEN** het systeem toont alle maatwerkfuncties met aanmaakdatum, gebruiker-die-aanmaakte, aantal functiehouders en review-datum.

### REQ-009: Koppeling met CAO Gemeenten en salarisschalen
Het systeem MOET bij iedere functietoekenning de cao-gemeenten module raadplegen voor de bijbehorende salaristabel, zodat schaal en periodiek altijd verwijzen naar de juiste actieve CAO-versie.

- **GIVEN** een HR-adviseur kent functie "HR21-BELEIDSMEDEWERKER-II" toe aan een medewerker per 1 maart 2024, **WHEN** schaal 10 periodiek 4 wordt geselecteerd, **THEN** het systeem haalt het brutomaandsalaris op uit cao-gemeenten versie 2024-2026 en vult automatisch € 3.897,00 in.
- **GIVEN** een nieuwe CAO-versie wordt actief per 1 januari 2025 met een loonsverhoging van 3%, **WHEN** de jaarlijkse herrekening draait, **THEN** het systeem werkt het brutomaandsalaris van alle medewerkers bij naar de nieuwe schaalbedragen zonder de functietoekenning zelf te wijzigen.
- **GIVEN** een functietoekenning gebruikt een schaal die niet (meer) bestaat in de actieve CAO-versie, **WHEN** een mutatie wordt gepoogd, **THEN** het systeem blokkeert de mutatie en escaleert: "Schaal niet beschikbaar in actieve CAO — corrigeer eerst de functietoekenning."

### REQ-010: Functiefamilie-rapportages en loopbaanpaden
Het systeem MOET op basis van de functiefamilie-indeling loopbaanpaden tonen aan medewerkers, zodat zichtbaar is welke vervolgfuncties realistisch zijn vanuit de huidige functie.

- **GIVEN** een medewerker met functie "Beleidsmedewerker B" opent de loopbaan-tab, **WHEN** de pagina wordt geladen, **THEN** het systeem toont mogelijke vervolgfuncties binnen dezelfde familie (Beleidsmedewerker C, Senior Beleidsmedewerker) en aanverwante families (Beleidsadvisering, Strategie & Onderzoek).
- **GIVEN** een medewerker bekijkt een potentiële vervolgfunctie, **WHEN** wordt doorgeklikt, **THEN** het systeem toont de competentie-vereisten met een gap-analyse: welke competenties heeft de medewerker al, welke moeten ontwikkeld worden.
- **GIVEN** een manager wil zijn team analyseren, **WHEN** het team-loopbaan-overzicht wordt geopend, **THEN** het systeem toont per medewerker de mogelijke vervolgstappen en signaleert medewerkers die al >5 jaar in dezelfde functie zitten (mogelijk doorgroei-stagnatie).

## Standards & Sources

De `functiehuis-hr21` module steunt op de volgende officiële bronnen:

- **HR21-functiebibliotheek**, beheerd door Leeuwendaal namens VNG (Vereniging van Nederlandse Gemeenten), gepubliceerd via https://hr21.nl. Bevat ~150 normfuncties met kerntaken, competenties en schaalbereiken. Updates verschijnen typisch 1-2x per jaar.
- **ODRP-functiewaarderingsmethode** (Operationele Doel-Resultaat-Profielen), de methodiek waarmee HR21 functies waardeert via 14 kenmerken op 5 dimensies (kennis, zelfstandigheid, contacten, leiding geven, afbreukrisico).
- **CAO Gemeenten 2024-2026**, gepubliceerd door VNG, bepaalt de salarisschalen 1 t/m 19 die door HR21-indelingen worden toegekend.
- **Algemene wet bestuursrecht (Awb)**, voor de formele bezwaarprocedure tegen indelingsbesluiten (bezwaartermijn 6 weken, hoorplicht, beslistermijn).
- **Wet normalisering rechtspositie ambtenaren (Wnra)** per 1 januari 2020, die de rechtspositie van ambtenaren onder het Burgerlijk Wetboek brengt, met behoud van het bezwaarrecht via Awb voor bepaalde besluiten.
- **NSW (Normfuncties met Salarisschalen)**, de officiële vertaaltabel die per HR21-normfunctie de gangbare salarisschaal opgeeft.
- **FUWASYS**, het equivalent van HR21 voor de onderwijssector (basisonderwijs, voortgezet onderwijs), opgenomen als referentie maar niet binnen scope van deze module.
- **Wet versterking positie OR (Wet OR)**, voor het instemmingsrecht van de OR bij wijzigingen in het functiehuis (artikel 27 WOR).
- **AVG (Algemene Verordening Gegevensbescherming)**, voor de bewaartermijnen van personeels- en functiedossiers (typisch 7 jaar na uitdiensttreding voor de meeste gegevens, levenslang voor sommige loopbaangegevens i.v.m. pensioenaanspraken).
- **Wet open overheid (Woo)**, voor de openbaarheid van functieprofielen van bestuurders en hoge ambtenaren (vanaf schaal 15).

Iedere import van een nieuwe HR21-versie moet voorzien zijn van een verwijzing naar de bronpublicatie van Leeuwendaal/VNG en een SHA-256 hash van het brondocument; iedere wijziging in een normfunctie moet leiden tot een nieuw versie-record met behoud van alle voorgaande versies, zodat historische indelingen onveranderd blijven.

## Cross-app integration

De `functiehuis-hr21` module integreert nauw met andere hrmq-modules en met enkele externe systemen:

- **employee-master** (dependency): de centrale medewerker-administratie levert de basisgegevens (naam, BSN, indiensttredingsdatum, contactgegevens) waaraan functietoekenningen worden gekoppeld. Wijzigingen in functietoekenning triggeren updates in het medewerker-master-record.
- **cao-gemeenten** (linkage): de CAO-module raadpleegt het HR21-functiehuis voor schaalbereik-validatie bij salarisberekeningen, en biedt omgekeerd de actuele salaristabellen waar HR21-indelingen aan worden gekoppeld. Iedere functietoekenning verwijst naar de actieve CAO-versie.
- **werving-en-selectie**: bij het openstellen van een vacature wordt de gewenste HR21-functie geselecteerd, waarmee automatisch de competentievereisten, het schaalbereik en de standaard-functieomschrijving worden ingevuld.
- **opleiding-en-ontwikkeling**: koppelt aan de competenties uit HR21 en biedt opleidingsadvies op basis van de gap tussen huidige competenties en die van mogelijke vervolgfuncties (loopbaanpaden).
- **performancemanagement**: gebruikt de HR21-kerntaken als basis voor functioneringsdoelen en jaargesprekken, en koppelt beoordelingen aan eventuele doorgroei naar een hogere normfunctie.
- **OR-portaal**: voor het instemmingsproces bij wijzigingen in het functiehuis (toevoegen van maatwerkfuncties, structuurwijziging) conform artikel 27 WOR.
- **decidesk**: voor de formele bezwaarprocedures tegen indelingsbesluiten, met integratie naar de bezwaaradviescommissie en de uiteindelijke besluitname conform Awb.
- **docudesk**: voor het automatisch genereren van indelingsbesluiten, functieomschrijvingen, hercategorisatie-brieven en bezwaarbeschikkingen op basis van de HR21-data.
- **openconnector / hr21-leeuwendaal-koppeling**: voor de jaarlijkse synchronisatie van het HR21-functiehuis met de bron bij Leeuwendaal/VNG, inclusief detectie van nieuwe, gewijzigde of vervallen normfuncties.
- **softwarecatalog**: voor publieke transparantie over welke gemeenten HR21 gebruiken en welke versie, voor benchmarking en kennisuitwisseling tussen gemeenten.

## Target users

De primaire gebruikers van de `functiehuis-hr21` module zijn vier persona's binnen de gemeentelijke organisatie:

**HR-adviseur** (dagelijks gebruik): verantwoordelijk voor het opstellen van indelingsvoorstellen, advisering over functiestructuur, beheer van het lokale functiehuis (welke normfuncties zijn in gebruik, welke maatwerkfuncties zijn er) en begeleiding bij bezwaarprocedures. Heeft HBO werk- en denkniveau, specialisatie in arbeidsvoorwaarden en CAO-toepassing. Werkt typisch in gemeenten van 100-1000 medewerkers en heeft een complete kennis van HR21.

**Manager / leidinggevende** (wekelijks gebruik): keurt indelingsvoorstellen goed voor eigen medewerkers, doet promotievoorstellen, beoordeelt functioneren en signaleert ontwikkelbehoeftes. Heeft beperkte HR21-detailkennis en heeft behoefte aan een mobile-vriendelijke interface met heldere uitleg van de gevolgen van een keuze (welk salaris hoort hierbij, welke competenties zijn vereist).

**Directeur HR / hoofd P&O** (maandelijks gebruik): bewaakt de samenhang van het functiehuis op organisatie-niveau, keurt maatwerkfuncties en collectieve hercategorisaties goed, en levert rapportages aan het management. Heeft behoefte aan dashboard-views met trends en benchmarks (eigen organisatie versus vergelijkbare gemeenten).

**Medewerker** (self-service): bekijkt eigen functie-indeling met motivatie, dient eventueel bezwaar in, bekijkt loopbaanpaden vanuit huidige functie, en initieert ontwikkelgesprekken via koppeling met opleidingsmodule. Werkt veelal mobile en heeft behoefte aan eenvoudige interface met heldere uitleg ("wat betekent deze functie?", "hoe kom ik in een hogere schaal?").

Secundaire gebruikers zijn de **OR / vakbondsvertegenwoordiger** (instemmingsrecht bij wijzigingen functiehuis), de **bezwaarcommissie HR** (behandeling van bezwaarschriften tegen indelingsbesluiten), de **controller / accountant** (audit van indelingsbesluiten en functiehuiskwaliteit) en **externe partijen** zoals Leeuwendaal (synchronisatie van HR21-updates) en VNG (sectorale benchmarks).

De module moet voldoen aan WCAG 2.1 AA voor toegankelijkheid (functie-informatie moet voor iedere medewerker toegankelijk zijn, inclusief medewerkers met visuele of cognitieve beperkingen), ondersteunt Nederlands én Engels (medewerkers van internationale afkomst, vooral in G4-steden), en biedt mobile-first interfaces voor zowel de leidinggevende (snelle goedkeuring van voorstellen onderweg) als de medewerker (loopbaan-inzicht en bezwaar-indiening).

Bezwaarprocedures vragen om bijzondere zorgvuldigheid: de Awb-termijnen moeten strikt bewaakt worden, de hoor-en-wederhoor-procedure moet correct verlopen, en alle stappen moeten auditeerbaar zijn omdat indelingsbesluiten jaren later nog ter discussie kunnen staan in juridische procedures (bijvoorbeeld bij ontslag of pensioenberekening). De module moet daarom integreren met `decidesk` voor de formele besluitvorming en met een document-archief voor de blijvende bewaring van bezwaardossiers.
