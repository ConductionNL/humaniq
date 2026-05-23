---
status: draft
---
# CAO Gemeenten (incl. Wnra rechtspositie + IKB)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › CAO's & regelingen

**Rationale:** CAO-ruleset, geen menu.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Implementeer de volledige CAO Gemeenten 2024-2026 als configureerbare rechtspositieregeling binnen hrmq, inclusief de overgang naar privaatrecht onder de Wet normalisering rechtspositie ambtenaren (Wnra) per 1 januari 2020. Deze brief levert de datamodellen, salaristabellen, IKB-berekeningen, ziekteloondoorbetaling, bovenwettelijke werkloosheidsregelingen en de verplichte ABP-aansluiting die nodig zijn om iedere gemeentelijke werkgever — van een dorpsgemeente met 40 ambtenaren tot een G4-stad met meer dan 15.000 medewerkers — correct, volledig auditeerbaar en conform de geldende CAO te laten uitbetalen.

De CAO Gemeenten verschilt fundamenteel van de reguliere markt-CAO's op vier punten: (1) de loondoorbetalingsplicht bij ziekte is gunstiger geregeld dan in BW 7:629 (100% jaar 1 in plaats van 70%), (2) er bestaat een uitgebreide bovenwettelijke werkloosheidsregeling (BWGR) bovenop de WW, (3) iedere gemeenteambtenaar bouwt verplicht pensioen op bij het ABP (geen keuze voor andere pensioenuitvoerder), en (4) functiewaardering verloopt via het sectorale instrument HR21. Het hrmq-systeem moet deze afwijkingen niet als hardcoded uitzonderingen behandelen maar als eerste-klas data-objecten, zodat toekomstige CAO-wijzigingen via configuratie kunnen worden doorgevoerd zonder code-aanpassingen.

Doel is dat een HR-adviseur bij een gemeente binnen 15 minuten een nieuwe medewerker kan aannemen met de correcte salarisschaal, periodiek, IKB-percentage en ABP-aansluiting; dat een salarisadministrateur maandelijks een loonstrook kan genereren die volledig CAO-conform is inclusief alle toeslagen, IKB-opbouw en pensioeninhoudingen; en dat een controller of accountant achteraf iedere salariscomponent kan herleiden naar een specifiek CAO-artikel met versiestempel.

## Data Model (entities + Dutch JSON)

De CAO Gemeenten wordt gemodelleerd als een set samenhangende entiteiten: de **CAO-versie** zelf met geldigheidsperiode, de **salaristabel** met alle schalen en periodieken, de **rechtspositieregeling** per medewerker, en de **IKB-rekening** die maandelijks wordt opgebouwd en periodiek wordt afgerekend.

**CAO_Versie** (master record per CAO-periode):
```json
{
  "caoCode": "GEMEENTEN",
  "versieNummer": "2024-2026",
  "ingangsdatum": "2024-01-01",
  "einddatum": "2026-12-31",
  "publicatieDatum": "2024-04-15",
  "vngBron": "https://vng.nl/cao-gemeenten/2024-2026",
  "loondoorbetalingZiekteJaar1Percentage": 100.0,
  "loondoorbetalingZiekteJaar2Percentage": 70.0,
  "ikbPercentage": 17.5,
  "vakantieDagenPerJaar": 158.4,
  "abpAansluitingVerplicht": true,
  "bwgrVanToepassing": true,
  "ondertekenaars": ["VNG", "FNV", "CNV", "CMHF"],
  "status": "actief"
}
```

**Salaristabel_Schaal** (één record per schaal 1 t/m 19):
```json
{
  "caoVersieId": "uuid",
  "schaalNummer": 10,
  "schaalNaam": "Schaal 10",
  "minimumBruto": 3358.00,
  "maximumBruto": 4671.00,
  "aantalPeriodieken": 11,
  "periodieken": [
    {"periodiek": 0, "bedrag": 3358.00},
    {"periodiek": 1, "bedrag": 3487.00},
    {"periodiek": 2, "bedrag": 3618.00},
    {"periodiek": 3, "bedrag": 3756.00},
    {"periodiek": 11, "bedrag": 4671.00}
  ],
  "geldigVanaf": "2024-10-01",
  "geldigTot": null
}
```

**Medewerker_Rechtspositie** (per medewerker, één actieve record):
```json
{
  "medewerkerId": "uuid",
  "caoCode": "GEMEENTEN",
  "caoVersieId": "uuid",
  "rechtspositie": "ambtenaar_wnra",
  "aanstellingsdatum": "2020-06-01",
  "aanstellingType": "vast",
  "schaalNummer": 10,
  "periodiek": 4,
  "brutoMaandsalaris": 3897.00,
  "deeltijdfactor": 0.8889,
  "functieCode": "HR21-BELEIDSMEDEWERKER-II",
  "functieNaam": "Beleidsmedewerker B",
  "afdeling": "Sociaal Domein",
  "leidinggevende": "uuid",
  "ikbPercentage": 17.5,
  "abpDeelnemerNummer": "ABP-12345678",
  "bwgrRechten": true,
  "wachtgeldRechten": false,
  "buitengewoonVerlofSaldo": 16.0,
  "roosterverlofSaldo": 7.2
}
```

**IKB_Rekening** (maandelijkse opbouw + jaarlijkse opname):
```json
{
  "medewerkerId": "uuid",
  "jaar": 2024,
  "openingssaldo": 0.00,
  "maandelijkseOpbouw": [
    {"maand": "2024-01", "grondslag": 3897.00, "opbouw": 682.00},
    {"maand": "2024-02", "grondslag": 3897.00, "opbouw": 682.00}
  ],
  "totaalOpgebouwd": 8184.00,
  "opnames": [
    {"datum": "2024-05-15", "bedrag": 3500.00, "type": "uitbetaling_vakantiegeld", "verzoekId": "uuid"},
    {"datum": "2024-09-01", "bedrag": 1200.00, "type": "extra_verlof", "verlofUren": 40, "verzoekId": "uuid"},
    {"datum": "2024-12-15", "bedrag": 1500.00, "type": "fiets_van_de_zaak", "verzoekId": "uuid"}
  ],
  "saldo": 1984.00,
  "afrekeningEindeJaar": true,
  "fiscalRegime": "WKR_gericht_vrijgesteld_waar_mogelijk"
}
```

**Ziekteperiode** (loondoorbetaling op afwijkende percentages):
```json
{
  "medewerkerId": "uuid",
  "ziekteperiodeId": "uuid",
  "startDatum": "2024-03-10",
  "eindDatum": null,
  "weekNummerInPeriode": 8,
  "huidigPercentage": 100,
  "verwachteOvergangNaar70Percent": "2025-03-10",
  "rePIntegratieFase": "spoor_1",
  "bedrijfsartsRapporten": ["uuid"],
  "uwvMelding": null
}
```

**BWGR_Uitkering** (bovenwettelijke werkloosheidsregeling, na ontslag):
```json
{
  "exMedewerkerId": "uuid",
  "ontslagdatum": "2024-08-01",
  "ontslagrond": "reorganisatie",
  "diensttijdJaren": 12.5,
  "wwUitkeringStart": "2024-09-01",
  "wwUitkeringEinde": "2026-04-30",
  "bwgrAanvullingPercentage": 20.0,
  "bwgrLooptijdMaanden": 24,
  "bwgrTotaalBedrag": 18420.00,
  "wachtgeldVan": "2026-05-01",
  "wachtgeldEinde": "2028-04-30"
}
```

## Requirements

### REQ-001: CAO-versiebeheer met onbeperkte historie
Het systeem MOET meerdere versies van de CAO Gemeenten parallel kunnen opslaan, waarbij iedere salarisberekening verwijst naar de versie die geldig was op de berekeningsdatum.

- **GIVEN** twee CAO-versies (2022-2024 en 2024-2026) zijn beide geregistreerd, **WHEN** een salarisadministrateur een correctie-loonberekening uitvoert voor december 2023, **THEN** het systeem gebruikt de schaalbedragen uit versie 2022-2024, niet de actuele 2024-2026 bedragen.
- **GIVEN** een nieuwe CAO-versie 2026-2028 wordt geïmporteerd met ingangsdatum 1 januari 2027, **WHEN** een HR-adviseur een dienstverband aanmaakt met startdatum 1 maart 2027, **THEN** het systeem stelt automatisch de salarisschaal vast op basis van de 2026-2028 versie.
- **GIVEN** een CAO-versie staat op status `actief`, **WHEN** een gebruiker probeert deze versie te verwijderen, **THEN** het systeem weigert de verwijdering en toont de melding "Actieve CAO-versies kunnen niet worden verwijderd; archiveer eerst."

### REQ-002: Volledige salaristabel schaal 1 t/m 19
Het systeem MOET de volledige salaristabel van CAO Gemeenten ondersteunen, inclusief alle 19 hoofdschalen en bijbehorende periodieken (variërend van 5 periodieken in schaal 1 tot 11 periodieken in schaal 19).

- **GIVEN** de CAO-versie 2024-2026 is geïmporteerd, **WHEN** een HR-adviseur schaal 10 periodiek 4 selecteert voor een nieuwe medewerker, **THEN** het systeem vult automatisch het brutomaandsalaris in op exact het bedrag uit de officiële VNG-tabel.
- **GIVEN** een medewerker zit in schaal 7 periodiek 11 (eindperiodiek), **WHEN** de jaarlijkse periodieke verhoging plaatsvindt, **THEN** het systeem genereert een melding "Eindperiodiek bereikt — geen periodieke verhoging mogelijk; overweeg promotie of toelage."
- **GIVEN** een medewerker zit in schaal 8 periodiek 6, **WHEN** een leidinggevende een schaalverhoging naar schaal 9 vraagt, **THEN** het systeem bepaalt automatisch de inschalingsperiodiek in schaal 9 op basis van het horizontaal aansluitende salaris (geen achteruitgang).

### REQ-003: IKB-opbouw 17.5% over alle vaste loonbestanddelen
Het systeem MOET maandelijks 17.5% van de IKB-grondslag toevoegen aan het IKB-saldo van iedere medewerker, waarbij de grondslag bestaat uit brutoloon plus eindejaarsuitkering plus levensloopbijdrage plus verlofopbouw boven het wettelijk minimum.

- **GIVEN** een medewerker heeft brutomaandsalaris € 3.897,00, **WHEN** de maandelijkse loonrun draait, **THEN** het systeem boekt € 682,00 toe aan IKB voor die maand (3897 × 0,175).
- **GIVEN** een medewerker werkt 0,8 FTE met brutomaandsalaris € 3.118,00 deeltijd, **WHEN** de maandelijkse IKB-opbouw wordt berekend, **THEN** het systeem berekent IKB over het deeltijdsalaris (€ 545,65 per maand), niet over een fictief voltijdsalaris.
- **GIVEN** een medewerker treedt uit dienst per 15 augustus, **WHEN** de eindafrekening wordt opgesteld, **THEN** het systeem berekent de IKB-opbouw pro rata voor augustus (15/31 dagen) en boekt het volledige resterende IKB-saldo uit als nabetaling op de laatste loonstrook.

### REQ-004: IKB-opname in zes verschillende doelen
Het systeem MOET medewerkers toestaan IKB op te nemen voor minimaal zes doelen: contante uitbetaling, extra verlof, fiets van de zaak, vakbondscontributie, opleidingskosten en bedrijfsfitness, waarbij iedere opname fiscaal correct wordt verwerkt (gericht vrijgesteld waar mogelijk via WKR, anders bruto-uitbetaling).

- **GIVEN** een medewerker heeft IKB-saldo € 5.000 en vraagt extra verlof aan ter waarde van € 1.200 (40 uur × € 30 uurtarief), **WHEN** de leidinggevende de aanvraag goedkeurt, **THEN** het systeem boekt € 1.200 af van het IKB-saldo, voegt 40 verlofuren toe aan het verlofsaldo, en registreert geen fiscale belastinggebeurtenis (gericht vrijgesteld via WKR).
- **GIVEN** een medewerker vraagt contante uitbetaling van € 2.000 uit IKB, **WHEN** de aanvraag wordt verwerkt, **THEN** het systeem verloont het bedrag bruto op de eerstvolgende loonstrook (loonheffing volgens reguliere tabel) en boekt € 2.000 af van het IKB-saldo.
- **GIVEN** het is 31 december en een medewerker heeft nog € 1.500 niet opgenomen IKB-saldo, **WHEN** de jaarafsluiting draait, **THEN** het systeem keert het volledige resterende saldo bruto uit op de decembereindstrook en zet het IKB-saldo per 1 januari op € 0.

### REQ-005: Afwijkende loondoorbetaling bij ziekte (100%/70%)
Het systeem MOET de gunstiger CAO-Gemeenten regeling toepassen: 100% loondoorbetaling in het eerste ziektejaar en 70% in het tweede ziektejaar, in plaats van de wettelijke 70% gedurende beide jaren.

- **GIVEN** een medewerker is ziek gemeld op 10 maart 2024, **WHEN** de loonrun van april 2024 draait, **THEN** het systeem betaalt het volledige brutomaandsalaris uit (100%), niet 70%.
- **GIVEN** een medewerker is sinds 10 maart 2024 onafgebroken ziek, **WHEN** de loonrun van april 2025 draait (week 56 van ziekte), **THEN** het systeem verlaagt automatisch de loondoorbetaling naar 70% en genereert een melding "Overgang naar tweede ziektejaar — controleer re-integratiedossier."
- **GIVEN** een medewerker hervat op 1 mei 2025 en wordt op 1 juli 2025 opnieuw ziek voor dezelfde aandoening, **WHEN** het systeem de doorbetalingsperiode bepaalt, **THEN** het systeem past de samentellingsregel toe (binnen 4 weken) en zet de teller voort op de plek waar deze stond bij hervatting.

### REQ-006: Verplichte ABP-aansluiting voor alle ambtenaren
Het systeem MOET bij iedere nieuwe aanstelling automatisch ABP als pensioenuitvoerder vastleggen, met validatie dat geen andere pensioenuitvoerder kan worden gekozen voor medewerkers die onder de CAO Gemeenten vallen.

- **GIVEN** een HR-adviseur registreert een nieuwe medewerker onder CAO Gemeenten, **WHEN** het pensioenuitvoerder-veld wordt ingevuld, **THEN** het systeem accepteert alleen de waarde "ABP" en wijst andere waarden af met de melding "ABP-aansluiting is verplicht onder CAO Gemeenten."
- **GIVEN** een nieuwe medewerker wordt aangemeld zonder ABP-deelnemernummer, **WHEN** de eerste loonrun voor deze medewerker wordt voorbereid, **THEN** het systeem genereert automatisch een aanmelding naar ABP via de Pensioenfondsen-koppeling en blokkeert de loonrun totdat het deelnemernummer is toegekend.
- **GIVEN** een medewerker treedt uit dienst, **WHEN** het dienstverband wordt afgesloten, **THEN** het systeem stuurt automatisch een afmelding naar ABP met de juiste uitdiensttredingsdatum en de reden (vrijwillig vertrek, ontslag, pensioen, overlijden).

### REQ-007: BWGR (bovenwettelijke werkloosheidsregeling) bij ontslag
Het systeem MOET bij ontslag van een ambtenaar automatisch de BWGR-rechten berekenen op basis van diensttijd en ontslagrond, en de aanvulling op de WW-uitkering vastleggen voor automatische verwerking via salarisadministratie of via de UWV.

- **GIVEN** een medewerker met 12,5 jaar gemeentelijke diensttijd wordt ontslagen wegens reorganisatie, **WHEN** de exit-procedure wordt afgerond, **THEN** het systeem berekent automatisch een BWGR-aanvulling van 20% bovenop de WW gedurende 24 maanden en genereert een betalingsschema.
- **GIVEN** een ex-medewerker krijgt na 18 maanden WW een nieuwe baan, **WHEN** dit wordt gemeld aan de salarisadministratie, **THEN** het systeem stopt automatisch de BWGR-aanvulling en bewaart het ongebruikte recht in een "slapend BWGR-saldo" voor eventuele toekomstige werkloosheid binnen de looptijd.
- **GIVEN** een medewerker met meer dan 10 jaar diensttijd wordt ontslagen, **WHEN** de BWGR-rechten worden berekend, **THEN** het systeem activeert automatisch ook het wachtgeldrecht dat ingaat na afloop van de BWGR-periode (geen overlap toegestaan).

### REQ-008: Functiehuis HR21 koppeling
Het systeem MOET iedere medewerker koppelen aan een functie uit het HR21-functiehuis, waarbij de functiewaardering automatisch leidt tot een minimum/maximum schaalbereik.

- **GIVEN** een HR-adviseur kent functie "HR21-BELEIDSMEDEWERKER-II" toe aan een medewerker, **WHEN** de schaalkeuze wordt gemaakt, **THEN** het systeem beperkt de selecteerbare schalen tot schaal 9, 10 en 11 (het toegestane bereik voor deze functie).
- **GIVEN** een medewerker krijgt een functiewijziging naar een hogere functie, **WHEN** de wijziging wordt geregistreerd, **THEN** het systeem stelt voor om de medewerker in te schalen op de aansluitende periodiek in de nieuwe schaal en genereert een wijzigingsadvies voor goedkeuring door de leidinggevende.
- **GIVEN** een functie is gewaardeerd als HR21 schaal 7-9, **WHEN** een leidinggevende probeert deze functiehouder in schaal 10 te plaatsen, **THEN** het systeem weigert dit en biedt twee opties: hercategoriseer de functie of creëer een maatwerkfunctie met onderbouwing.

### REQ-009: Roosterverlof en buitengewoon verlof bovenop wettelijk
Het systeem MOET naast het wettelijke vakantieverlof (4× weekuren) ook roosterverlof (per CAO 7,2 uur bij voltijd) en alle vormen van buitengewoon verlof (huwelijk, geboorte, overlijden, verhuizing, vakbondsactiviteit) als aparte saldi bijhouden.

- **GIVEN** een voltijd medewerker heeft de saldi: wettelijk verlof 160 uur, bovenwettelijk verlof 14,4 uur, roosterverlof 7,2 uur, **WHEN** de medewerker 8 uur verlof opneemt zonder doel-specificatie, **THEN** het systeem boekt af van wettelijk verlof eerst (FIFO oudste eerst), conform CAO-volgorderegel.
- **GIVEN** een medewerker krijgt een kind, **WHEN** geboorteverlof wordt aangevraagd, **THEN** het systeem kent automatisch 1 week (40 uur bij voltijd) geboorteverlof toe als buitengewoon verlof + signaleert recht op aanvullend geboorteverlof via UWV.
- **GIVEN** een naast familielid van een medewerker overlijdt, **WHEN** rouwverlof wordt aangevraagd, **THEN** het systeem kent het CAO-bepaalde aantal dagen toe (4 dagen voor partner/kind, 2 dagen voor ouder/broer/zus) als buitengewoon verlof, niet ten laste van regulier verlof.

### REQ-010: Auditeerbare wijzigingen met bron-CAO-artikel
Iedere mutatie in salaris, schaal, periodiek of toeslag MOET worden gelogd met verwijzing naar het specifieke CAO-artikel dat de wijziging rechtvaardigt, plus de gebruiker en het tijdstip.

- **GIVEN** een HR-adviseur verhoogt de periodiek van een medewerker, **WHEN** de wijziging wordt opgeslagen, **THEN** het systeem registreert een audit-record met velden: oude periodiek, nieuwe periodiek, CAO-artikel 3.4 (periodieke verhoging), motivatie, gebruiker, tijdstip, IP-adres.
- **GIVEN** een controller voert een audit uit op alle salariswijzigingen van Q1 2024, **WHEN** het auditrapport wordt opgevraagd, **THEN** het systeem genereert een PDF met per wijziging het CAO-artikel, voor/na bedragen en de goedkeurder.
- **GIVEN** een medewerker maakt bezwaar tegen een functie-indeling, **WHEN** de HR-adviseur het bezwaardossier opent, **THEN** het systeem toont de volledige historie van functietoekenningen inclusief CAO-onderbouwing, en biedt de mogelijkheid een formele bezwaarprocedure te starten.

## Standards & Sources

De implementatie steunt op de volgende officiële bronnen die elk hun eigen versiebeheer kennen en die als referentie in iedere dataset moeten worden opgenomen:

- **CAO Gemeenten 2024-2026**, gepubliceerd door VNG (Vereniging van Nederlandse Gemeenten) op https://vng.nl/cao-gemeenten/2024-2026. Te raadplegen artikelen: hoofdstuk 3 (salaris), hoofdstuk 4 (IKB), hoofdstuk 6 (verlof), hoofdstuk 7 (ziekte), hoofdstuk 10 (rechtspositie bij einde dienstverband).
- **Wet normalisering rechtspositie ambtenaren (Wnra)**, in werking getreden per 1 januari 2020, te vinden via wetten.overheid.nl. Bepaalt dat de arbeidsverhouding van gemeentelijke ambtenaren onder het Burgerlijk Wetboek valt in plaats van onder publiekrecht.
- **Burgerlijk Wetboek Boek 7 titel 10 (arbeidsovereenkomst)**, met name artikelen 629 (loondoorbetaling ziekte) en 670 (opzegverboden), met afwijkingen via CAO.
- **Wet op de loonbelasting 1964** en **Uitvoeringsregeling loonbelasting 2011**, voor de fiscale behandeling van IKB-uitkeringen onder de Werkkostenregeling (WKR).
- **ABP Pensioenreglement**, gepubliceerd door Stichting Pensioenfonds ABP via https://www.abp.nl, voor de berekening van pensioeninhoudingen en franchisebedragen.
- **HR21-functiehuis**, beheerd door Leeuwendaal namens VNG, met ~150 normfuncties. Documentatie via https://hr21.nl.
- **Werkloosheidswet (WW)**, gecombineerd met de **Bovenwettelijke Werkloosheidsregeling Gemeenten (BWGR)** zoals vastgelegd in bijlage 4 van de CAO Gemeenten.
- **Wet werk en zekerheid (WWZ)** en **Wet arbeidsmarkt in balans (WAB)**, voor de transitievergoeding (samenloop met BWGR moet voorkomen worden).

Elke salaristabel-import moet voorzien zijn van een SHA-256 hash van het brondocument om tampering te detecteren; elke significante CAO-wijziging moet leiden tot een nieuw versie-record met behoud van alle voorgaande versies.

## Cross-app integration

De `cao-gemeenten` brief levert datastructuren en business-rules die door meerdere andere hrmq-modules én externe systemen worden geconsumeerd:

- **payroll-engine-nl** (dependency): de generieke Nederlandse loonberekening-engine ontvangt per medewerker de schaal, periodiek, deeltijdfactor, IKB-percentage en eventuele toeslagen, en levert de bruto-netto berekening inclusief loonheffing, WW-premie, WIA-premie en ZVW-bijdrage. De CAO-specifieke ziekteloondoorbetaling-percentages worden door cao-gemeenten als override doorgegeven aan payroll-engine-nl.
- **abp-aansluiting-verplicht** (dependency): de ABP-koppelaar ontvangt nieuwe aanstellingen, mutaties (salaris, deeltijdfactor, schaalwijzigingen) en uitdiensttredingen via een SOAP-koppeling naar ABP's UPA-platform (Uniforme Pensioenaangifte). Cao-gemeenten triggert deze koppeling bij iedere relevante wijziging.
- **functiehuis-hr21** (dependency): de HR21-functiebibliotheek levert de functiecodes, kerntaken en schaalbereiken. Cao-gemeenten valideert dat de gekozen schaal binnen het toegestane HR21-bereik valt.
- **verlofadministratie**: ontvangt van cao-gemeenten de verlofaanspraken (wettelijk, bovenwettelijk, roosterverlof, buitengewoon verlof) en verwerkt aanvragen volgens de CAO-volgorderegels.
- **uwv-koppeling**: voor ziekmeldingen langer dan 42 weken (poortwachter) wordt automatisch een melding richting UWV gegenereerd; voor ontslag in het kader van WW-aanvragen.
- **openconnector / nextcloud-flow**: voor uitwisseling van loonstroken (PDF + machineleesbaar) naar de persoonlijke MijnOverheid-berichtenbox van de medewerker.
- **docudesk**: voor het automatisch genereren van aanstellingscontracten, IKB-keuzeformulieren, ziekteformulieren en exit-documentatie op basis van de cao-gemeenten datavelden.
- **decidesk**: voor de afhandeling van bezwaarprocedures tegen functie-indelingen of salarisbesluiten, conform de Algemene wet bestuursrecht (Awb).
- **softwarecatalog**: voor publieke transparantie van welke gemeenten welke versie van het cao-gemeenten-pakket gebruiken (voor benchmarking en harmonisatie).

## Target users

De primaire gebruikers van de `cao-gemeenten` module zijn vier persona's binnen de gemeentelijke organisatie:

**HR-adviseur** (40-60% gebruik): verantwoordelijk voor in-, door- en uitstroom van medewerkers, opstellen van arbeidscontracten, functietoewijzing en advisering aan management. De HR-adviseur gebruikt de module dagelijks voor nieuwe aanstellingen, periodieke verhogingen, schaalmutaties en verlofadministratie. Heeft typisch HBO-werk- en denkniveau en CAO-kennis op specialistisch niveau.

**Salarisadministrateur** (30-40% gebruik): voert de maandelijkse loonruns uit, controleert bruto-netto berekeningen, verwerkt mutaties en levert salarisstroken op. Werkt nauw samen met externe partijen zoals ABP, Belastingdienst en UWV. Heeft MBO+/HBO-werk- en denkniveau en is gecertificeerd via VePAS of vergelijkbaar.

**Manager / leidinggevende** (incidenteel gebruik): keurt verlofaanvragen goed, beoordeelt periodieke verhogingen, adviseert over schaalmutaties bij promotie en initieert exit-procedures bij vrijwillig vertrek of disfunctioneren. Heeft beperkte CAO-kennis en heeft behoefte aan een gebruiksvriendelijke interface met heldere context.

**Controller / accountant** (kwartaal- en jaargebruik): controleert de juistheid van salarisuitgaven, doet de jaarrekening-controle, monitort de loonkostenontwikkeling versus begroting en stelt accountantsrapporten op. Heeft behoefte aan auditeerbare logs, rapportagemogelijkheden en export naar Excel/CSV voor verdere analyse.

Secundaire gebruikers zijn de **medewerker zelf** (self-service voor verlofaanvraag, IKB-keuze, loonstrook downloaden), de **OR / vakbondsvertegenwoordiger** (inzage in geanonimiseerde loondata voor CAO-onderhandelingen) en de **gemeentesecretaris of directeur HR** (strategische rapportages over personeelsbezetting en loonkosten).

De module moet voldoen aan WCAG 2.1 AA voor toegankelijkheid, ondersteunt zowel Nederlandse als Engelse interface (medewerkers van buitenlandse afkomst), en biedt mobile-first self-service via de medewerkerportal voor verlofaanvragen en loonstrookinzage. De HR-adviseur en salarisadministrateur werken vanaf desktop met multi-monitor setup en hebben behoefte aan toetsenbord-shortcuts voor snelle invoer.
