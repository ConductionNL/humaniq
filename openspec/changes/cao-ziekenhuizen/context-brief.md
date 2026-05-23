---
status: draft
---
# CAO Ziekenhuizen — NVZ Arbeidsvoorwaarden Module

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › CAO's & regelingen

**Rationale:** CAO-ruleset.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `cao-ziekenhuizen` capability implements the collective labour agreement concluded between the Nederlandse Vereniging van Ziekenhuizen (NVZ) as employer association and the joint healthcare unions (FNV Zorg & Welzijn, CNV Zorg & Welzijn, FBZ and NU'91) for general and categorical hospitals. The CAO covers roughly 200,000 employees across approximately 60 algemene ziekenhuizen and 8 categorale ziekenhuizen, making it one of the four large healthcare CAOs alongside CAO VVT, CAO GGZ and CAO UMC. The module deliberately scopes only NVZ ziekenhuizen; the academic hospitals (UMC's) fall under the separate `cao-umc` capability with materially different salary structures and pension arrangements (also PFZW, but with UMC-specific salarisschalen), and the categorale revalidatiecentra split between CAO-Ziekenhuizen and CAO-Revalidatie.

The module must encode several structural peculiarities that distinguish hospital employment from generic healthcare or government employment. First, the functiewaarderingssysteem is **FWG 3.0** (Functiewaardering Gezondheidszorg), a sector-specific system developed by Stichting FWG that is materially different from HR21 (used by municipalities), ORBA (used in industry), and FUWASYS (used by Rijk). FWG produces an integer score that maps onto FWG-functiegroepen 5 through 80, which in turn map onto salarisschalen with a one-to-one relationship at the lower end and overlap at the higher end. Second, the operationele continuïteit van een ziekenhuis (24/7 zorg) genereert een rijke set van **onregelmatigheidstoeslagen (ORT)**, **bereikbaarheidsdiensten**, **aanwezigheidsdiensten** en **slaapdiensten** met elk hun eigen vergoedingsregime — een complexiteit die in private-sector CAO's vrijwel afwezig is. Third, voor de **chirurgische en anesthesiologische dienst** gelden afwijkende overurenregelingen (100 percent vergoeding ipv 50 percent na het 8e uur), bereidheidsuren met aparte schaal, en gespecialiseerde piket-vergoedingen die in andere ziekenhuisdiensten niet voorkomen. Fourth, de **arbeidsduurverkorting (ADV)** is in deze CAO bovenwettelijk geregeld als een combinatie van verkorte normuren en compensatie-uren, met flexibele inwisselbaarheid tussen geld en tijd. Fifth, de pensioenregeling is **PFZW** (Pensioenfonds Zorg en Welzijn) — gedeeld met VVT, GGZ, gehandicaptenzorg en jeugdzorg, maar met sector-specifieke premie-afspraken.

The module powers hrmq-ziekenhuis-employer customers (NVZ-aangesloten ziekenhuizen, hun shared HR-services, en payroll-leveranciers zoals AFAS, Visma en Raet die voor ziekenhuizen draaien) en wordt geconsumeerd door payroll-engine-nl voor de salarisrun, door rostering-planning voor dienstroosters met ORT-berekening en piket-toewijzing, door verlof-administratie voor ADV-en-vakantie-uren administratie, en door de declaratie-aan-zorgverzekeraar laag voor doorbelasting van personeelskosten in DBC-tarieven.

## Data Model

The core entity is `CaoZiekenhuisEmployment`, extending `Employment` with: `fwgFunctiegroep` (integer 5-80), `salarisschaal` (string FWG-5 through FWG-80 with subdivisions), `salarisnummer` (anciënniteitstrede 0-12), `functiebenaming` (free text matching de FWG-referentiefuncties), `dienstverband` (one of "verpleegafdeling", "OK", "IC", "SEH", "polikliniek", "OK-anesthesie", "laboratorium", "radiologie", "apotheek", "facilitair", "administratie"), `inroosterbaarVoorPiket` (boolean), `bereidheidsuurtarief` (Money optional, alleen voor chirurgisch-anesthesiologische dienst), `parttimePercentage` (decimal between 0.0 en 1.0 over de 36-uurs-normweek), en `aanvangsdatumDienstverband` voor de jubileum-en-anciënniteitsberekening.

Supporting entities omvatten `FwgScoreReport` (de FWG-3.0-puntenscore met sub-scores voor kennis, zelfstandigheid, sociale-vaardigheden, risico/verantwoordelijkheid/invloed, expressievaardigheid, bewegingsvaardigheid, oplettendheid, overige-functie-eisen en inconveniënten), `OrtClaim` (een onregelmatigheidstoeslag-aanspraak per gewerkt uur met dag-tijd-tariefcategorie en resulterend percentage van 22, 38, 47, 49 of 60 percent), `BereikbaarheidsDienst` (een passieve dienst waarbij de medewerker thuis bereikbaar moet zijn, vergoed met een vast uurtarief per type), `AanwezigheidsDienst` (een dienst in het ziekenhuis tijdens niet-actieve uren, vergoed per geconverteerd loonuur per de FWG-aanwezigheidsdienst-conversietabel), `SlaapDienst` (een specifieke vorm aanwezigheidsdienst met slaapfaciliteit, eigen conversie), `OveruurClaim` (gewerkte uren boven het contractuele aantal met dienst-specifiek vergoedingspercentage), `AdvVerlofTegoed` (de bovenwettelijke ADV-aanspraak in uren met conversie naar geld of vakantieverlof), en `TijdVoorTijdSaldo` (de compensatiebank voor uren die niet als ORT-geld zijn uitbetaald).

Reference data lives in `FwgSchaalTabel` (per CAO-akkoord met effective-from dates), `OrtTariefMatrix` (de dag-tijd-percentage-matrix), `BereikbaarheidsTariefTabel` (per functie-categorie), `AanwezigheidsdienstConversieTabel` (de uren-naar-loonuren conversie per type), en `PfzwPremieTabel` (mirrored van PFZW). Money fields gebruiken `Money` value objects in EUR met round-half-to-even; date-tijd combinations gebruiken `ZonedDateTime` (zone Europe/Amsterdam) omdat ORT-berekening tijdgevoelig is bij dst-overgangen.

De model scheidt expliciet `Dienstverband` (juridische arbeidsovereenkomst) van `Inroostering` (de feitelijke planning voor een specifieke dag/dienst), omdat dezelfde medewerker op verschillende dagen verschillende ORT-tarieven, dienst-soorten en hierarchies kan hebben — een verpleegkundige kan ma-vr op de verpleegafdeling werken en in het weekend een SEH-piket doen, met elk hun eigen vergoedingsregime.

## Requirements

### REQ-001 — FWG 3.0 functiewaardering en schaal-indicatie

The module SHALL convert a FWG 3.0-totaalscore into een FWG-functiegroep en bijbehorende salarisschaal volgens de officiële FWG-conversietabel van Stichting FWG. De waardering MUST gebeuren op basis van de complete set van negen sub-scores en de uitkomst MUST een unieke functiegroep zijn (anders dan FUWASYS kent FWG geen overlapping band-grenzen).

- GIVEN een FWG-totaalscore van 38 punten, WHEN de schaal-indicatie wordt afgeleid, THEN de functiegroep is FWG-40 met salarisschaal 40 (overeenkomend met de score-range 36-40 per de FWG-3.0-conversietabel).
- GIVEN een FWG-score met een ontbrekende sub-score voor "bewegingsvaardigheid", WHEN validatie loopt, THEN een `IncompleteFwgScoreException` wordt gegooid met de melding welke sub-score ontbreekt.
- GIVEN een functiebenaming "Verpleegkundige IC" waarvoor in de FWG-referentiefuncties een vaste functiegroep FWG-50 is vastgelegd, WHEN de waardering wordt geverifieerd, THEN de berekende FWG-score MOET binnen de bandbreedte voor FWG-50 vallen (46-50), anders wordt een `FwgReferenceFunctionMismatch` warning gegenereerd voor handmatige review.

### REQ-002 — ORT-berekening per gewerkt uur met dag-tijd-tariefmatrix

The module SHALL bereken de onregelmatigheidstoeslag voor elk gewerkt uur op basis van de dag-van-de-week en het tijdstip-van-de-dag, met de huidige NVZ-percentages: maandag t/m vrijdag 06:00-22:00 geen ORT, ma-vr 00:00-06:00 en 22:00-24:00 47 percent, zaterdag 00:00-06:00 en 22:00-24:00 49 percent, zaterdag 06:00-22:00 38 percent, zondag/feestdag 00:00-06:00 en 22:00-24:00 60 percent, zondag/feestdag 06:00-22:00 60 percent.

- GIVEN een gewerkt uur op zaterdag 14:00-15:00 door een medewerker met uurloon EUR 22.50, WHEN het ORT wordt berekend, THEN de ORT-vergoeding voor dat uur is EUR 22.50 × 0.38 = EUR 8.55 bovenop het basisloon.
- GIVEN een dienst van vrijdag 22:00 tot zaterdag 07:00 met uurloon EUR 25.00, WHEN het totale ORT wordt berekend, THEN de uren 22:00-24:00 (vrijdag) krijgen 47 percent, de uren 00:00-06:00 (zaterdag) krijgen 49 percent, en het uur 06:00-07:00 (zaterdag) krijgt 38 percent, met correcte minute-by-minute attributie bij overgangsmomenten.
- GIVEN een feestdag (Eerste Kerstdag) op een dinsdag met een gewerkt uur 14:00-15:00, WHEN het ORT wordt berekend, THEN het uur krijgt het feestdag-tarief 60 percent ondanks dat het op een dinsdag valt.

### REQ-003 — Bereikbaarheidsdienst-vergoeding voor passieve dienst

The module SHALL register en vergoeden bereikbaarheidsdiensten (waarbij de medewerker thuis bereikbaar is voor oproep) tegen een vast uurtarief per functie-categorie en dienst-specifiek tarief, met daarnaast opkomst-vergoeding voor daadwerkelijke oproepen (basisloon + ORT-opslag voor de gewerkte tijd inclusief reistijd).

- GIVEN een chirurg met een bereikbaarheidsdienst van vrijdag 18:00 tot maandag 08:00 zonder daadwerkelijke oproep, WHEN de vergoeding wordt berekend, THEN de passieve vergoeding bedraagt 62 uur × het chirurg-bereikbaarheidstarief (huidig EUR 4.85/uur), totaal EUR 300.70.
- GIVEN dezelfde chirurg met een oproep van zaterdag 03:00 tot 06:00 voor een spoedoperatie, WHEN de oproepvergoeding wordt berekend, THEN de actief-gewerkte 3 uur worden vergoed tegen basisloon plus 49-percent ORT (nachtdienst zaterdag) plus de bereikbaarheidsvergoeding voor de niet-overlappende uren.
- GIVEN een niet-medische functie (administratief medewerker) waarvoor geen bereikbaarheidsdienst is geconfigureerd in de CAO, WHEN een poging tot registratie loopt, THEN een `BereikbaarheidsdienstNotApplicableException` wordt gegooid.

### REQ-004 — Slaapdienst-conversie naar loonuren

The module SHALL slaapdiensten (aanwezig in het ziekenhuis met slaapfaciliteit, niet-actief) converteren naar geconverteerde loonuren volgens de FWG-conversietabel: het eerste blok wordt geteld als een fractie van een loonuur (typisch 0.4-0.6 afhankelijk van type), met onderbrekingen door oproepen die als volledig gewerkte uren tellen.

- GIVEN een slaapdienst van 23:00 tot 07:00 (8 uur) zonder onderbreking, WHEN de loonurenconversie loopt, THEN de 8 slaapuren worden geconverteerd naar 8 × 0.5 = 4 loonuren, met daarbovenop de geldende ORT-percentages over de nominale uren.
- GIVEN dezelfde slaapdienst met een oproep van 02:00-03:30, WHEN de conversie loopt, THEN de slaaperiode 23:00-02:00 (3 uur) wordt geconverteerd naar 1.5 loonuren, het oproep-blok 02:00-03:30 (1.5 uur) telt als 1.5 actieve loonuren met de juiste nacht-ORT, en de slaaperiode 03:30-07:00 (3.5 uur) levert nog eens 1.75 loonuren op.
- GIVEN een slaapdienst geregistreerd voor een functie waarvoor slaapdienst niet is toegestaan (bijv. specialist-in-opleiding waarbij de Werktijdenbesluit geneeskundigen-in-opleiding aparte regels geeft), WHEN validatie loopt, THEN een `SlaapdienstNotPermittedException` wordt gegooid.

### REQ-005 — Overuren chirurgische dienst aan 100 percent

The module SHALL voor medewerkers in de chirurgische en anesthesiologische dienst (operatieassistent, anesthesiemedewerker, chirurg in dienstverband) overuren vergoeden tegen 100 percent van het uurloon (in plaats van het reguliere 50 percent na het 8e uur en 100 percent in het weekend), waarbij overuren worden gedefinieerd als gewerkte uren boven de overeengekomen dagdienst en niet de wekelijkse contracturen.

- GIVEN een operatieassistent met dagdienst 08:00-16:30 die door een uitlopende operatie tot 19:00 doorwerkt, WHEN de overurenvergoeding wordt berekend, THEN de 2.5 uur boven de dagdienst worden vergoed tegen 100 percent van het uurloon plus de geldende ORT voor 16:30-19:00.
- GIVEN een verpleegkundige op de verpleegafdeling die dezelfde uitloop heeft, WHEN haar overurenvergoeding wordt berekend, THEN de 2.5 uur krijgen het reguliere 50-percent-overuurpercentage (niet 100) want zij valt niet onder de chirurgische dienst.
- GIVEN een operatieassistent die door eigen keuze blijft helpen na sluiting van het OK-programma zonder operatie-uitloop, WHEN de claim wordt gevalideerd, THEN de uren worden niet als overuren erkend en een `OverurenZonderDienstNoodzaakException` wordt gegooid voor managervalidatie.

### REQ-006 — PFZW-aansluiting en premie-afdracht

The module SHALL elk dienstverband aanmelden bij PFZW per de eerste dag van het dienstverband en de PFZW-premie (huidig 25.8 percent over de pensioengrondslag) berekenen met werkgevers-werknemersverdeling 50/50, met de PFZW-franchise (huidig EUR 17,545) als drempel.

- GIVEN een nieuw dienstverband per 2026-08-01 met maandsalaris EUR 3,800, WHEN de augustus-payroll loopt, THEN de PFZW-aanmelding is geregistreerd met ingangsdatum 2026-08-01 en de pensioenpremie berekend over de pensioengrondslag van (12 × 3,800 - 17,545) = EUR 28,055 per jaar tegen 25.8 percent, gedeeld 50/50.
- GIVEN een parttime medewerker met 0.6 contractomvang, WHEN de franchise wordt toegepast, THEN de franchise wordt naar rato verlaagd tot 0.6 × 17,545 = EUR 10,527 per jaar voordat de premie wordt berekend.
- GIVEN een medewerker die in dezelfde maand zowel bij een NVZ-ziekenhuis (PFZW) als bij een UMC (UMC-pensioenregeling) werkt, WHEN de premies worden berekend, THEN beide deeldienstverbanden worden afzonderlijk aangemeld bij hun respectievelijke fondsen zonder samenvoeging van de pensioengrondslagen.

### REQ-007 — Bovenwettelijke ADV-uren met flex-conversie

The module SHALL het ADV-tegoed administreren als bovenwettelijke aanspraak van (typisch) 96 uur per jaar voor een fulltime medewerker, opbouwbaar per gewerkt uur, en SHALL toestaan dat de medewerker per kwartaal-keuze het tegoed converteert naar geld (uitbetaling tegen uurloon), extra vakantie-uren of structurele werktijdverkorting.

- GIVEN een fulltime medewerker met 6 maanden dienstverband, WHEN de ADV-opbouw wordt berekend, THEN het tegoed bedraagt 48 uur (de helft van het jaarrecht).
- GIVEN een ADV-keuze om 40 uur uit te betalen tegen een uurloon van EUR 24, WHEN de keuze wordt verwerkt, THEN het tegoed wordt verlaagd met 40 uur en de uitbetaling van EUR 960 wordt op de eerstvolgende salarisstrook gezet, met juiste loonheffing- en premie-inhouding.
- GIVEN een ADV-keuze om 80 uur als extra vakantie op te nemen door een parttime medewerker met 0.6 contractomvang, WHEN de keuze wordt verwerkt, THEN de medewerker heeft 80 / 0.6 = 133.3 nominale vakantie-uren beschikbaar (omdat parttimers werkdagen-equivalent verlof opnemen), met het ADV-tegoed verlaagd met 80 uur.

### REQ-008 — Tijd-voor-tijd compensatiebank

The module SHALL als alternatief voor uitbetaling van ORT en overuren een tijd-voor-tijd saldo bijhouden waar de medewerker per dienst kan kiezen voor compensatie-in-tijd (de gewerkte tijd plus de ORT-fractie als tijdsequivalent) in plaats van geld, met een maximum saldo van 80 uur en verplichte opname binnen 12 maanden.

- GIVEN een gewerkt zaterdag-uur van 14:00-15:00 (1 uur, ORT 38 percent) waarvoor de medewerker kiest voor tijd-voor-tijd, WHEN de keuze wordt verwerkt, THEN het tijd-voor-tijd saldo wordt verhoogd met 1 + 0.38 = 1.38 uur en de geld-uitbetaling wordt onderdrukt voor dat uur.
- GIVEN een tijd-voor-tijd saldo dat door een nieuwe bijschrijving boven de 80-uur grens zou komen, WHEN de bijschrijving plaatsvindt, THEN de bijschrijving wordt afgewezen en een `TijdVoorTijdSaldoOverflowException` gegooid, waarbij de gebruiker terugvalt op geld-uitbetaling voor de overschot-uren.
- GIVEN een tijd-voor-tijd saldo van 60 uur dat ouder dan 12 maanden is, WHEN het maandelijkse vervaltermijn-process loopt, THEN het verouderde saldo wordt automatisch uitbetaald tegen het huidige uurloon van de medewerker en het tegoed wordt teruggebracht naar 0.

### REQ-009 — Diensttijdverhoudingen voor parttime medewerkers

The module SHALL voor parttime medewerkers (parttimePercentage kleiner dan 1.0) de juiste verhoudings-berekeningen toepassen voor alle nominale entitlements: salaris, vakantie-uren, ADV-uren, IKB-equivalente regelingen, PFZW-pensioengrondslag, en jubileum-uitkeringen — met de specifieke regel dat ORT-percentages NIET worden aangepast (een parttimer krijgt dezelfde ORT-percentages voor het gewerkte uur als een fulltimer).

- GIVEN een 0.5-parttime medewerker in schaal FWG-50 nummer 8 met fulltime-equivalent EUR 4,200/maand, WHEN het maandsalaris wordt berekend, THEN het salaris bedraagt EUR 2,100.
- GIVEN dezelfde medewerker die op zondag 12:00-16:00 4 uur werkt, WHEN het ORT wordt berekend, THEN de 4 uur krijgen het volle zondag-percentage van 60 percent zonder enige parttime-aanpassing van het ORT-percentage zelf.
- GIVEN een 0.7-parttime medewerker met 25-jarig dienstjubileum, WHEN de jubileum-uitkering (1 maandsalaris) wordt berekend, THEN de uitkering bedraagt 0.7 × het fulltime-equivalent-maandsalaris.

### REQ-010 — Loondoorbetaling bij ziekte met wettelijk minimum plus CAO-aanvulling

The module SHALL bij ziekte 100 percent doorbetalen in jaar 1 en 90 percent in jaar 2 (CAO bovenop het wettelijke 70-percent minimum), met de aanvullende voorwaarde dat na het wettelijke maximum van 104 weken eventuele WIA-aansluitende aanvullingen alleen gelden bij actieve re-integratie-medewerking (waarbij niet-meewerken een korting van 30 percentpunten triggert).

- GIVEN een ziekmelding op 2026-03-10 van een medewerker met maandbezoldiging EUR 4,000, WHEN de loondoorbetaling voor april 2026 wordt berekend, THEN de doorbetaling is 100 percent oftewel EUR 4,000.
- GIVEN dezelfde medewerker nog ziek op 2027-03-11 (start jaar 2), WHEN de loondoorbetaling voor april 2027 wordt berekend, THEN de doorbetaling is 90 percent oftewel EUR 3,600.
- GIVEN een medewerker in jaar 2 die niet meewerkt aan een passend tweede-spoor-traject zoals vastgesteld door de bedrijfsarts, WHEN de korting wordt toegepast, THEN de doorbetaling daalt van 90 percent naar 60 percent en de medewerker wordt geïnformeerd via een formele waarschuwing met beroepsmogelijkheid bij de RvB.

## Standards & Sources

The implementation baseert uitsluitend op primaire bronnen. De **CAO Ziekenhuizen 2025-2027** als gepubliceerd door NVZ op nvz-ziekenhuizen.nl is de canonieke tekst, gecomplementeerd door de **Akkoorden** zoals gepubliceerd in de Staatscourant na algemeenverbindendverklaring (avv). De **FWG 3.0-handleiding** en de **FWG-referentiefuncties database** worden onderhouden door **Stichting FWG** en zijn beschikbaar via fwg.nl voor licentienemers (een aansluitvereiste die hrmq als platform regelt). De **PFZW-pensioenreglement** en **PFZW-premietabel** worden jaarlijks vastgesteld en gepubliceerd op pfzw.nl. De **Wet werk en zekerheid (Wwz)** en de **Wet arbeid en zorg (Wazo)** leveren het wettelijke kader voor loondoorbetaling bij ziekte, zwangerschap en ouderschap. De **Wet flexibel werken** en de **Arbeidstijdenwet** leveren de bovengrenzen voor dienstroosters en piket. Het **Werktijdenbesluit geneeskundigen-in-opleiding (Wtb-GiO)** beperkt de inzetbaarheid van aios voor slaap- en aanwezigheidsdiensten. Voor loonbelasting geldt het **Handboek Loonheffingen 2026** van de Belastingdienst. De **NEN 7510** voor informatiebeveiliging in de zorg en de **NEN 7512/7513** voor logging-vereisten gelden voor alle ziekenhuis-bound capabilities en zijn relevant voor de audit-trail van loongegevens. Internationaal vergelijkbare CAO-bouwstenen zijn beperkt; de Belgische **PC 330** (zorginstellingen) en de Duitse **TVöD-K** (kommunale Krankenhäuser) zijn structureel anders en zijn niet voor cross-border reuse geschikt.

## Cross-app integration

`cao-ziekenhuizen` exposeert een stable read-model API geconsumeerd door `payroll-engine-nl` voor de maandelijkse salarisrun met decomposed bezoldigingsspecificatie inclusief ORT, bereikbaarheid, slaap en overuren. De `rostering-planning` capability is de belangrijkste cross-app consument: bij elk roosterconcept vraagt het rooster de geprojecteerde ORT-kosten op voor budget-validatie, en bij definitief rooster genereert het de bijbehorende `OrtClaim`, `BereikbaarheidsDienst` en `SlaapDienst` entiteiten. De `verlof-administratie` capability subscribed op `AdvVerlofTegoedUpdated` events voor de verlofkaart-synchronisatie en op `TijdVoorTijdSaldoUpdated` voor de compensatie-bank visibility. De `declaratie-aan-zorgverzekeraar` laag consumeert per DBC-trajectparticipatie de personele-kosten doorbelasting, waarbij de ORT-component apart wordt gerapporteerd voor de zorgverzekeraarsspecifieke tariefberekening. De `contract-generatie` capability vraagt salarisindicaties op bij indiensttreding, bij interne mutaties tussen dienstverbanden, en bij parttime-percentageaanpassingen. Cross-app naar de bredere zorg-ecosystem koppelt `cao-ziekenhuizen` indirect met `cao-vvt`, `cao-ggz` en `cao-umc` via de gedeelde PFZW-aansluiting capability (`pfzw-aansluiting`), die FWG-niet-specifieke pensioencomponenten centraliseert. De `pre-employment-screening` capability levert BIG-registratie-checks die als hard voorwaarde gelden voor functies vanaf FWG-50 in zorgverlenende rollen.

## Target users

The primary user is de **HR-administrateur** bij een NVZ-ziekenhuis (typisch een HR-medewerker bij P&O Personeels- en Salarisadministratie), die nieuwe dienstverbanden registreert, FWG-classificaties vastlegt, parttime-percentage-aanpassingen verwerkt en uitkomsten van bezwaarprocedures rond functiewaardering doorvoert. De **roosterplanner** (vaak een dedicated rol per afdeling: OK-planner, IC-planner, etc.) is een dagelijkse gebruiker via de rostering-planning laag — hen voert dienstroosters in en moet de financiële impact (ORT, bereikbaarheid, slaapdienst) zien voordat een rooster wordt vastgesteld. De **salarisadministrateur** valideert maandelijks de payroll-output, verwerkt naheffingen voor ORT-correcties (een vaak voorkomende casus omdat dienstwijzigingen achteraf bekend worden) en behandelt bezwaarprocedures over individuele ORT-claims. De **leidinggevende** (afdelingshoofd, teamleider) goedkeurt overuren, valideert bereikbaarheidsdienst-claims na oproepen en autoriseert ADV-keuzes. **Pensioen-specialisten** bij Stafdienst HR behandelen PFZW-aansluitings-uitzonderingen (bijv. bij gelijktijdige UMC-dienstverbanden) en jubileum-berekeningen. **CAO-onderhandelaars** bij NVZ-stafbureau en de **CAO-implementatieteams** bij grote ziekenhuisgroepen zijn de bronhouders met configuratierechten voor schaaltabellen, ORT-percentages en de PFZW-premie-afspraken. **Externe auditors** (accountant in het kader van de jaarrekeningcontrole, zorgverzekeraar in het kader van DBC-doorbelastingscontrole) zijn read-only consumenten van geaggregeerde personeelskosten-rapportages. De **medewerker zelf** ziet via het self-service portaal de eigen loonstrook met ORT-specificatie, het ADV-tegoed, de tijd-voor-tijd-bank en de individuele keuze-opties — directe interactie met `cao-ziekenhuizen` loopt altijd via de hrmq-frontend laag.
