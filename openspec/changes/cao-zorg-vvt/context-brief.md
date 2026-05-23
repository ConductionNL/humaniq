---
status: draft
---
# CAO VVT (Verpleeg-, Verzorgingshuizen, Thuiszorg)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › CAO's & regelingen

**Rationale:** CAO-ruleset.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Implementeer de volledige CAO VVT 2024-2026 binnen hrmq, met speciale aandacht voor de complexe Onregelmatigheidstoeslag-engine (ORT), de bereidheidsuren-regelingen, het overurenbeleid, de slaapdienstvergoedingen en de verplichte aansluiting bij Pensioenfonds Zorg en Welzijn (PFZW). De CAO VVT geldt voor ongeveer 450.000 medewerkers in Nederland die werkzaam zijn bij verpleeghuizen, verzorgingshuizen, thuiszorgorganisaties en kleinschalige woonvormen, en is daarmee één van de grootste sectorale CAO's in de zorg.

De grootste uitdaging in de CAO VVT is de uitbetaling van toeslagen voor onregelmatige diensten: medewerkers in 24-uurs zorg werken volgens roosters die zaterdag, zondag, avond, nacht en feestdagen omvatten, en de CAO regelt voor ieder uur op iedere dag een specifieke toeslag. Een verpleegkundige die op kerstavond een nachtdienst draait kan op één shift al drie verschillende toeslagsoorten opbouwen: 22% voor het avondgedeelte (tot 00:00), 38% voor de nacht (00:00-06:00) en 47% voor het feestdaggedeelte (kerstmis). Het systeem moet deze stapeling van toeslagen correct berekenen, traceerbaar tonen op de loonstrook en automatisch terugkoppelen naar het roosterplanning-systeem voor budgetbewaking.

Daarnaast kent de CAO VVT specifieke regelingen rondom werktijdgrenzen (Arbeidstijdenwet aanvullingen), bereidheidsuren (consignatiediensten waarbij de medewerker thuis oproepbaar moet zijn), slaapdiensten (medewerker overnacht in de instelling om bij noodgevallen op te kunnen treden) en de Wet zorg en dwang voor specifieke functies. De CAO heeft in 2024 een loonsverhoging in stappen doorgevoerd (3% per 1 januari 2024, 2,5% per 1 januari 2025, 2% per 1 januari 2026), wat retroactieve berekeningen vergt voor medewerkers die op transitiedatum in dienst kwamen.

Doel is dat een zorginstelling met 200-2000 medewerkers maandelijks een correcte loonrun kan draaien waarbij iedere ORT-berekening op de loonstrook herleidbaar is naar het roosterregel en de CAO-bepaling, dat managers in real-time inzicht hebben in ORT-kosten per afdeling, en dat de instelling voldoet aan alle arbeidstijdenwet-verplichtingen inclusief de aanvullende CAO-regels.

## Data Model (entities + Dutch JSON)

De CAO VVT vergt een datamodel dat dieper gaat dan een eenvoudige salaristabel: shifts, ORT-berekeningen per uur, bereidheidsregelingen en slaapdiensten zijn allemaal eerste-klas entiteiten.

**CAO_VVT_Versie** (master):
```json
{
  "caoCode": "VVT",
  "versieNummer": "2024-2026",
  "ingangsdatum": "2024-01-01",
  "einddatum": "2026-12-31",
  "actiznBron": "https://actiz.nl/cao-vvt-2024-2026",
  "loonsverhogingen": [
    {"datum": "2024-01-01", "percentage": 3.0},
    {"datum": "2025-01-01", "percentage": 2.5},
    {"datum": "2026-01-01", "percentage": 2.0}
  ],
  "pfzwAansluitingVerplicht": true,
  "werkurenMaximumPerWeek": 52,
  "ortVanToepassing": true,
  "slaapdienstVergoedingTarief": 24.50,
  "ondertekenaars": ["ActiZ", "Zorgthuisnl", "FNV", "CNV", "FBZ", "NU91"]
}
```

**ORT_Regel** (centraal datamodel voor de onregelmatigheidstoeslag-engine, per uur per dag van de week):
```json
{
  "caoVersieId": "uuid",
  "regelNaam": "Avond doordeweeks",
  "dagenVanDeWeek": ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag"],
  "tijdVan": "18:00",
  "tijdTot": "00:00",
  "ortPercentage": 22.0,
  "prioriteit": 100,
  "stapelbaarMet": ["feestdag", "zondag"],
  "uitsluitendBij": [],
  "geldigVanaf": "2024-01-01"
}
```

```json
{
  "caoVersieId": "uuid",
  "regelNaam": "Nacht doordeweeks",
  "dagenVanDeWeek": ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"],
  "tijdVan": "00:00",
  "tijdTot": "06:00",
  "ortPercentage": 38.0,
  "prioriteit": 200,
  "stapelbaarMet": ["feestdag"],
  "uitsluitendBij": [],
  "geldigVanaf": "2024-01-01"
}
```

```json
{
  "caoVersieId": "uuid",
  "regelNaam": "Feestdag (nationaal of CAO-feestdag)",
  "dagenVanDeWeek": "feestdag",
  "tijdVan": "00:00",
  "tijdTot": "24:00",
  "ortPercentage": 47.0,
  "prioriteit": 300,
  "stapelbaarMet": [],
  "uitsluitendBij": ["zaterdag_ort", "zondag_ort"],
  "geldigVanaf": "2024-01-01",
  "feestdagenLijst": [
    {"datum": "2024-01-01", "naam": "Nieuwjaarsdag"},
    {"datum": "2024-04-01", "naam": "Tweede Paasdag"},
    {"datum": "2024-04-27", "naam": "Koningsdag"},
    {"datum": "2024-05-05", "naam": "Bevrijdingsdag (om de 5 jaar)"},
    {"datum": "2024-05-09", "naam": "Hemelvaart"},
    {"datum": "2024-05-20", "naam": "Tweede Pinksterdag"},
    {"datum": "2024-12-25", "naam": "Eerste Kerstdag"},
    {"datum": "2024-12-26", "naam": "Tweede Kerstdag"}
  ]
}
```

**Shift** (één werkbloek per medewerker per dag, kan gesplitst zijn):
```json
{
  "shiftId": "uuid",
  "medewerkerId": "uuid",
  "datum": "2024-12-24",
  "startTijd": "2024-12-24T20:00:00+01:00",
  "eindTijd": "2024-12-25T08:00:00+01:00",
  "shiftType": "nachtdienst",
  "afdeling": "Somatiek 1",
  "functieTijdensShift": "Verpleegkundige niveau 4",
  "totaalUren": 12.0,
  "pauzeUren": 1.0,
  "betaaldeUren": 11.0,
  "ortBerekening": [
    {"van": "20:00", "tot": "00:00", "uren": 4.0, "ortRegel": "Avond doordeweeks", "percentage": 22.0, "toeslag": 22.88},
    {"van": "00:00", "tot": "06:00", "uren": 6.0, "ortRegel": "Nacht doordeweeks + Feestdag", "percentage": 85.0, "toeslag": 132.60},
    {"van": "06:00", "tot": "08:00", "uren": 2.0, "ortRegel": "Feestdag", "percentage": 47.0, "toeslag": 24.44}
  ],
  "totaalOrtBedrag": 179.92,
  "uurtarief": 26.00,
  "basisloon": 286.00,
  "totaalShiftLoon": 465.92
}
```

**Bereidheidsdienst** (consignatie):
```json
{
  "bereidheidsId": "uuid",
  "medewerkerId": "uuid",
  "startTijd": "2024-12-26T22:00:00+01:00",
  "eindTijd": "2024-12-27T08:00:00+01:00",
  "totaalUren": 10.0,
  "vergoedingPerUur": 3.50,
  "totaalVergoeding": 35.00,
  "oproepen": [
    {
      "oproepId": "uuid",
      "startTijd": "2024-12-27T03:15:00+01:00",
      "eindTijd": "2024-12-27T04:45:00+01:00",
      "reistijdHeen": 0.25,
      "reistijdTerug": 0.25,
      "totaalActieveUren": 2.0,
      "uurtariefMetOrt": 49.00,
      "vergoeding": 98.00
    }
  ],
  "totaalUitbetaling": 133.00
}
```

**Slaapdienst** (medewerker overnacht in instelling):
```json
{
  "slaapdienstId": "uuid",
  "medewerkerId": "uuid",
  "datum": "2024-11-15",
  "startTijd": "23:00",
  "eindTijd": "07:00",
  "totaalUren": 8.0,
  "vasteVergoeding": 24.50,
  "ortOverActieveOproepen": true,
  "actieveOproepen": [],
  "totaalUitbetaling": 24.50
}
```

**Werkurenbewaking_ATW** (Arbeidstijdenwet + CAO-aanvulling):
```json
{
  "medewerkerId": "uuid",
  "weekNummer": "2024-W51",
  "geplandeUren": 48.0,
  "gewerkteUren": 47.5,
  "maximumCao": 52.0,
  "maximumAtw": 60.0,
  "consecutieveNachtdiensten": 3,
  "maximumConsecutieveNachten": 7,
  "rustperiodeNa12UurDienst": 11.0,
  "atwViolations": [],
  "caoViolations": []
}
```

## Requirements

### REQ-001: ORT-engine met stapelbare toeslagen
Het systeem MOET per minuut van een shift bepalen welke ORT-regels van toepassing zijn, deze stapelen volgens de stapelregels uit de CAO, en het hoogste toepasselijke percentage uitbetalen.

- **GIVEN** een medewerker werkt op kerstavond (24 december) van 18:00 tot 23:00, **WHEN** de loonberekening draait, **THEN** het systeem berekent 22% avond-toeslag over de volledige 5 uur (kerstavond is geen CAO-feestdag, alleen 25 en 26 december zijn dat).
- **GIVEN** een medewerker draait een nachtdienst op tweede kerstdag van 00:00 tot 08:00, **WHEN** de ORT wordt berekend, **THEN** het systeem stapelt nacht-toeslag (38%) met feestdag-toeslag (47%) = 85% over de uren 00:00-06:00 en 47% over 06:00-08:00.
- **GIVEN** een medewerker werkt op een zondag van 14:00 tot 22:00, **WHEN** de ORT wordt bepaald, **THEN** het systeem past 38% zondagtoeslag toe over 14:00-18:00 (regulier dag, alleen zondag-toeslag) en 38% zondag + geen extra avond (avond-ORT is alleen doordeweeks) over 18:00-22:00.

### REQ-002: Loonsverhoging in stappen met retroactieve berekening
Het systeem MOET de CAO-loonsverhogingen automatisch doorvoeren op de geplande datums en bij retroactieve toepassing (bijvoorbeeld bij een nieuwe CAO die enkele maanden na de ingangsdatum wordt vastgesteld) automatisch nabetalingen berekenen.

- **GIVEN** de CAO-loonsverhoging van 3% is gepland per 1 januari 2024, **WHEN** de loonrun van januari 2024 draait, **THEN** het systeem past de 3% verhoging toe op alle schaalbedragen en herberekent het bruto-maandsalaris van iedere medewerker.
- **GIVEN** de CAO 2024-2026 wordt op 15 april 2024 vastgesteld met terugwerkende kracht tot 1 januari 2024, **WHEN** de salarisadministrateur de retroactieve berekening triggert, **THEN** het systeem berekent voor iedere medewerker het verschil tussen oude en nieuwe schaal × 4 maanden (jan-apr) en boekt de nabetaling op de loonstrook van mei 2024 als aparte regel.
- **GIVEN** een medewerker is in maart 2024 uit dienst getreden vóór de retroactieve CAO-vaststelling, **WHEN** de retroactieve nabetaling wordt verwerkt, **THEN** het systeem genereert een nabetaling die via SEPA naar het laatst bekende rekeningnummer wordt overgemaakt en stuurt een email naar de ex-medewerker.

### REQ-003: Bereidheidsdienst met oproep-tracking
Het systeem MOET bereidheidsuren (consignatiedienst) registreren als aparte categorie met lagere vergoeding (€ 3,50/uur), en daadwerkelijke oproepen tijdens bereidheid omzetten naar volledig betaalde uren inclusief ORT.

- **GIVEN** een medewerker heeft bereidheidsdienst van 22:00 tot 08:00 (10 uur), **WHEN** er geen oproepen plaatsvinden, **THEN** het systeem betaalt 10 × € 3,50 = € 35,00 als bereidheidsvergoeding op de loonstrook.
- **GIVEN** een medewerker wordt opgeroepen tijdens bereidheid om 03:15 en werkt tot 04:45 (1,5 uur actief + 0,5 uur reistijd), **WHEN** de uren worden verwerkt, **THEN** het systeem betaalt 2 uur tegen het uurtarief inclusief nacht-ORT van 38% bovenop de bereidheidsvergoeding voor de overige 8 uur.
- **GIVEN** een medewerker heeft 3 maal bereidheid in een week (totaal 30 uur), **WHEN** de wekelijkse werkurencheck draait, **THEN** het systeem telt bereidheidsuren mee in de ATW-totaaltelling met een afwijkende rekenregel (0,5x voor consignatie) volgens CAO-bepaling.

### REQ-004: Slaapdienst met vaste vergoeding
Het systeem MOET slaapdiensten registreren met een vaste vergoeding per nacht (€ 24,50 per CAO 2024), en bij verstoring van de slaap door noodzakelijke werkactiviteiten automatisch omzetten naar betaalde werkuren.

- **GIVEN** een medewerker heeft slaapdienst van 23:00 tot 07:00 zonder verstoring, **WHEN** de dienst wordt afgesloten, **THEN** het systeem betaalt € 24,50 vaste vergoeding zonder verdere uurberekening.
- **GIVEN** een medewerker met slaapdienst wordt om 02:30 wakker gemaakt voor een acute situatie en werkt tot 04:00, **WHEN** deze actieve tijd wordt geregistreerd, **THEN** het systeem behoudt de vaste slaapdienstvergoeding én betaalt 1,5 uur als reguliere werkuren met nacht-ORT van 38%.
- **GIVEN** een medewerker draait 4 slaapdiensten in een maand, **WHEN** de wettelijke maximumwerktijd wordt gecontroleerd, **THEN** het systeem telt de slaapdiensten als rust-uren mee in de ATW-berekening (niet als werktijd, tenzij verstoord).

### REQ-005: ATW + CAO-werktijdgrenzen
Het systeem MOET zowel de wettelijke Arbeidstijdenwet-grenzen als de strengere CAO-grenzen bewaken, en bij dreigende overschrijding voor de roostering een waarschuwing genereren.

- **GIVEN** een medewerker heeft deze week al 48 uur ingepland, **WHEN** de planner een extra dienst van 8 uur toe wil voegen, **THEN** het systeem weigert de inplanning met de melding "CAO-maximum 52 uur per week overschreden" en biedt opties: andere medewerker, andere dienst, of formeel verzoek tot ATW-uitzondering.
- **GIVEN** een medewerker heeft 7 nachtdiensten achter elkaar gehad, **WHEN** een 8e nachtdienst wordt voorgesteld, **THEN** het systeem blokkeert de inplanning ("CAO-maximum 7 opeenvolgende nachten") en signaleert dat een dagdienst noodzakelijk is.
- **GIVEN** een medewerker eindigt een dienst van 12 uur om 23:00, **WHEN** een nieuwe dienst wordt voorgesteld op 09:00 de volgende ochtend (10 uur rust), **THEN** het systeem weigert ("Onvoldoende dagelijkse rust — minimum 11 uur na een dienst van 10+ uur").

### REQ-006: PFZW pensioenaansluiting verplicht
Het systeem MOET voor iedere medewerker onder CAO VVT automatisch PFZW als pensioenuitvoerder vastleggen, met validatie en automatische UPA-aangifte.

- **GIVEN** een HR-adviseur registreert een nieuwe medewerker onder CAO VVT, **WHEN** het pensioenuitvoerder-veld wordt ingevuld, **THEN** het systeem accepteert alleen "PFZW" en wijst andere waarden af met de melding "PFZW-aansluiting is verplicht onder CAO VVT."
- **GIVEN** een medewerker wijzigt van deeltijdfactor 0,8 naar 1,0, **WHEN** de mutatie wordt verwerkt, **THEN** het systeem stuurt automatisch een UPA-bericht naar PFZW met de nieuwe deeltijdfactor en het nieuwe pensioengevend salaris.
- **GIVEN** een nieuwe medewerker komt uit een sector met BPF Vervoer, **WHEN** het dienstverband bij de VVT-instelling start, **THEN** het systeem genereert automatisch een verzoek tot waardeoverdracht richting PFZW na de wachtperiode van 6 maanden.

### REQ-007: Functieschalen FWG (Functiewaardering Gezondheidszorg)
Het systeem MOET de FWG-functiewaardering ondersteunen met schalen 5 t/m 80 (vroeger 1-19, hernieuwd naar puntensysteem) en de bijbehorende salarisschalen FWG 35 t/m FWG 80.

- **GIVEN** een functie is gewaardeerd op FWG 50 (Verpleegkundige niveau 4), **WHEN** een medewerker in deze functie wordt aangenomen, **THEN** het systeem stelt de salarisschaal in op FWG 50 met de juiste periodiekreeks (12 periodieken voor deze schaal).
- **GIVEN** een medewerker stijgt door naar Senior Verpleegkundige (FWG 55), **WHEN** de promotie wordt vastgelegd, **THEN** het systeem berekent de nieuwe inschalingsperiodiek volgens de horizontaal-aansluitende-bedrag-regel uit de CAO.
- **GIVEN** een functiebeschrijving wordt herzien en de FWG-waardering stijgt van 45 naar 50, **WHEN** alle bestaande functiehouders moeten worden geherwaardeerd, **THEN** het systeem genereert een batch-mutatie voorstel voor de HR-adviseur met per medewerker de nieuwe schaal en periodiek.

### REQ-008: Overurenregeling 50% en 100%
Het systeem MOET overuren registreren en uitbetalen tegen 50% toeslag voor de eerste 4 overuren per week en 100% voor uren daarboven, met de mogelijkheid om overuren te ruilen voor verlof in plaats van uitbetaling.

- **GIVEN** een medewerker werkt 38 uur per week en draait 6 overuren in een week, **WHEN** de loonrun draait, **THEN** het systeem betaalt 4 overuren tegen 150% (basis + 50% toeslag) en 2 overuren tegen 200% (basis + 100% toeslag).
- **GIVEN** een medewerker kiest voor verlof-ruil in plaats van uitbetaling, **WHEN** 6 overuren worden geregistreerd, **THEN** het systeem voegt 6 uur × 1,5 = 9 verlofuren toe aan het verlofsaldo (eerste 4 uur tegen 1,5x, volgende 2 uur tegen 2,0x = 4×1,5 + 2×2,0 = 10 uur — corrigeer in implementatie).
- **GIVEN** een medewerker werkt overuren tijdens een nachtdienst, **WHEN** de uitbetaling wordt berekend, **THEN** het systeem stapelt overurentoeslag (50% of 100%) op de ORT-toeslag (38% nacht), zodat een overuur in de nacht 188% uitbetaalt (basis + 50% over + 38% nacht).

### REQ-009: Reiskostenvergoeding woon-werk en dienstreizen
Het systeem MOET reiskostenvergoeding berekenen volgens CAO VVT: vaste tegemoetkoming woon-werk boven 10 km enkele reis, en € 0,23/km voor dienstreizen (zorghuisbezoek door thuiszorg-medewerkers), met fiscaal-vrije bovengrens van € 0,23/km.

- **GIVEN** een medewerker heeft een woon-werkafstand van 15 km enkele reis, **WHEN** de maandelijkse reiskostenvergoeding wordt berekend, **THEN** het systeem berekent: 15 km × 2 (heen+terug) × 5 km vergoed (boven 10 km drempel) × € 0,23 × werkdagen, en boekt dit op de loonstrook als netto-vergoeding.
- **GIVEN** een thuiszorg-medewerker rijdt 80 km in één dag bij cliëntbezoeken, **WHEN** de declaratie wordt ingediend, **THEN** het systeem keert € 18,40 (80 × € 0,23) netto uit zonder loonheffing (fiscaal vrijgesteld).
- **GIVEN** een medewerker krijgt een leaseauto, **WHEN** de reiskostenvergoeding wordt geherconfigureerd, **THEN** het systeem stopt de kilometervergoeding voor woon-werk en past de bijtelling voor privégebruik toe op de loonstrook.

### REQ-010: Wet zorg en dwang inzet-registratie
Het systeem MOET registreren welke medewerkers bevoegd zijn tot het toepassen van onvrijwillige zorg (Wzd), en alleen die medewerkers inroosteren voor diensten waar dit voorkomt.

- **GIVEN** een afdeling heeft cliënten met Wzd-indicatie, **WHEN** een rooster wordt opgesteld, **THEN** het systeem controleert dat per dienst minimaal één Wzd-bevoegde medewerker aanwezig is en waarschuwt als dit niet het geval is.
- **GIVEN** een medewerker volgt een Wzd-cursus, **WHEN** het diploma wordt geregistreerd, **THEN** het systeem voegt de Wzd-bevoegdheid toe aan het profiel met de geldigheidsdatum (typisch 3 jaar) en signaleert ruim voor afloop dat herscholing nodig is.
- **GIVEN** een Wzd-toepassing wordt geregistreerd, **WHEN** de dienst wordt afgesloten, **THEN** het systeem koppelt de toepassing aan de specifieke shift voor latere audit en koppelt door naar het zorgdossier.

## Standards & Sources

De `cao-zorg-vvt` module steunt op de volgende officiële bronnen:

- **CAO VVT 2024-2026**, gepubliceerd door ActiZ en Zorgthuisnl via https://actiz.nl/cao-vvt-2024-2026 en https://zorgthuisnl.nl. Bevat artikelen 7.1 (ORT), 7.2 (overuren), 7.3 (bereidheid), 7.4 (slaapdiensten), hoofdstuk 12 (verlof), hoofdstuk 14 (pensioen).
- **Arbeidstijdenwet (ATW)** en **Arbeidstijdenbesluit (ATB)**, voor de wettelijke werktijdgrenzen, met de zorgspecifieke afwijkingen in het Arbeidstijdenbesluit zorg.
- **Wet zorg en dwang psychogeriatrische en verstandelijk gehandicapte cliënten (Wzd)**, in werking sinds 1 januari 2020, voor bevoegdheidsregistratie van zorgmedewerkers.
- **PFZW Pensioenreglement**, gepubliceerd door Pensioenfonds Zorg en Welzijn via https://www.pfzw.nl, voor pensioenpremie-berekening en aansluitingsproces.
- **FWG-systematiek**, gepubliceerd door FWG B.V. (https://fwg.nl), het sectorale functiewaarderingsinstrument voor de gehele gezondheidszorg.
- **Wet kwaliteit, klachten en geschillen zorg (Wkkgz)**, voor de minimumkwaliteitseisen aan zorgmedewerkers (verklaring omtrent gedrag, BIG-registratie).
- **BIG-register** (Beroepen Individuele Gezondheidszorg), beheerd door CIBG, voor verpleegkundigen, verzorgenden en aanverwante beroepen: de medewerker-data moet gekoppeld zijn aan BIG-nummers waar van toepassing.
- **Werkkostenregeling (WKR)** voor onbelaste vergoedingen zoals reiskosten, opleidingsbudget en thuiswerkvergoeding.
- **Wet werk en zekerheid (WWZ)** voor flexibele dienstverbanden (oproepcontracten, min-max-contracten) die in de zorg veelvuldig voorkomen.

Iedere ORT-tabel-import moet voorzien zijn van een verwijzing naar het exacte CAO-artikel en de datum van inwerkingtreding; iedere wijziging in de FWG-functiewaardering moet leiden tot een nieuw functiehuis-record met behoud van de historische waarderingen.

## Cross-app integration

De `cao-zorg-vvt` module integreert met meerdere andere systemen binnen hrmq én met externe zorg- en pensioensystemen:

- **payroll-engine-nl** (dependency): ontvangt de bruto-loonbestanddelen inclusief alle ORT-toeslagen, overurentoeslagen, bereidheidsvergoedingen en slaapdienstvergoedingen, en levert de bruto-netto-berekening inclusief loonheffing, premies werknemersverzekeringen en pensioenpremie.
- **rostering-planning** (dependency): het roostersysteem ontvangt de werktijdgrenzen, ORT-tarieven en bereidheidsregelingen van cao-zorg-vvt, en levert per shift de ingeplande uren met dag/tijd-stempel voor de ORT-berekening. Bij voorgestelde shifts valideert het rooster realtime de ATW- en CAO-grenzen.
- **PFZW-koppelaar**: stuurt UPA-berichten naar Pensioenfonds Zorg en Welzijn bij aanmelding, mutatie en afmelding.
- **BIG-register-koppeling**: synchroniseert dagelijks de BIG-status van geregistreerde medewerkers (Verpleegkundige, Verzorgende IG, etc.) via de CIBG-webservice; bij doorhaling of schorsing van een BIG-registratie wordt automatisch een waarschuwing aan HR gegenereerd en de medewerker uit roosters geblokkeerd.
- **CAK-koppeling**: voor declaratie van zorguren en verantwoording aan zorgkantoren (specifiek voor instellingen in de Wet langdurige zorg).
- **verlofadministratie**: ontvangt de CAO-bepaalde verlofaanspraken (wettelijk + bovenwettelijk + roosterverlof + studieverlof + langdurig zorgverlof) en verwerkt aanvragen.
- **declaratiemodule**: voor reiskostendeclaraties van thuiszorgmedewerkers, met automatische kilometerberekening op basis van adresgegevens.
- **opleiding-en-bekwaamheid**: voor registratie van scholingen, certificeringen (Wzd, BHV, medicatie, etc.) en automatische blokkering van inroostering bij vervallen certificering.
- **docudesk**: voor het genereren van arbeidsovereenkomsten, ziekteformulieren, exit-documenten en CAO-conforme loonstroken.
- **openconnector**: voor uitwisseling met systemen van zorgkantoren, IGJ (Inspectie Gezondheidszorg en Jeugd) en branche-organisaties.

## Target users

De primaire gebruikers van de `cao-zorg-vvt` module zijn:

**HR-medewerker zorginstelling** (dagelijks gebruik): registreert nieuwe medewerkers (inclusief BIG-validatie), verwerkt mutaties, regelt verlof en ziekteverzuim, en adviseert managers over CAO-toepassing. Heeft typisch HBO werk-/denkniveau en specialisatie in zorg-CAO. Werkt vaak in instellingen van 100-500 medewerkers waar één HR-functionaris het gehele HR-proces afhandelt.

**Roosterplanner / planner zorg** (dagelijks gebruik): stelt de wekelijkse roosters op rekening houdend met ATW-grenzen, CAO-werktijdgrenzen, gewenste werktijden van medewerkers, kwalificatiematrix en budgettaire ORT-grenzen. Heeft behoefte aan een planning-interface die realtime de loonkosten per shift toont, zodat keuzes tussen "wie roosteren we in?" budgetbewust gemaakt worden.

**Salarisadministrateur** (maandelijks gebruik): voert de loonruns uit, controleert de ORT-berekeningen, verwerkt mutaties (nieuwe medewerkers, in- en uitdiensttredingen, ziekmeldingen) en levert salarisstroken op. Werkt met partijen zoals PFZW, Belastingdienst en UWV.

**Manager / teamleider zorg** (wekelijks gebruik): keurt verlofaanvragen en overuren goed, ziet de loonkosten van het eigen team, en heeft inzicht in ziekteverzuim en personele bezetting. Heeft beperkte CAO-detailkennis en heeft behoefte aan een dashboard met de juiste signalen (overschrijdingen ATW, overschrijding ORT-budget, openstaande verlofaanvragen).

**Zorgmedewerker** (self-service): bekijkt loonstroken, doet verlofaanvragen, ruilt diensten met collega's, beheert eigen beschikbaarheid, en geeft voorkeur op voor toekomstige roosters. Werkt veelal mobile-first (smartphone) en heeft behoefte aan eenvoudige interface met grote knoppen (vaak met handschoenen of in haast).

**Controller / financieel medewerker** (kwartaal- en jaargebruik): analyseert loonkosten, ORT-aandeel, ziekteverzuim-kosten en vergelijkt met budget. Levert rapportages aan zorgkantoor, IGJ en interne stuurgroep.

**OR / vakbondsvertegenwoordiger**: ontvangt geanonimiseerde data over loonsverhogingen, ORT-uitkeringen en verlofbenutting voor CAO-onderhandelingen en advies aan de bestuurder.

De module moet WCAG 2.1 AA compliant zijn (veel zorgmedewerkers hebben matig digitale geletterdheid; toegankelijkheid is essentieel), ondersteunt Nederlands én Engels (groot aandeel internationale verpleegkundigen) en biedt mobile-first interface voor de medewerker-self-service (de meerderheid van zorgmedewerkers heeft geen vaste werkplek met desktop). HR-medewerkers en planners werken vanaf desktop met multi-monitor opstellingen.
