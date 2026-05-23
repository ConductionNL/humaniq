# CAO Gemeenten — Specifications

**Status:** pending

---

## REQ-001: CAO-versiebeheer met onbeperkte historie

Het systeem MOET meerdere versies van de CAO Gemeenten parallel kunnen opslaan, waarbij iedere salarisberekening verwijst naar de versie die geldig was op de berekeningsdatum.

### REQ-001-001: Parallelle versies registreren

**GIVEN** twee CAO-versies (2022-2024 en 2024-2026) zijn beide geregistreerd in het systeem  
**WHEN** een salarisadministrateur een correctie-loonberekening uitvoert voor december 2023  
**THEN** het systeem gebruikt de schaalbedragen uit versie 2022-2024, niet de actuele 2024-2026 bedragen.

**Acceptance:**
- `ObjectService.findAll('cao-versies', {caoCode: 'GEMEENTEN'})` retourneert beide versies
- Salary lookup gebruikt `caoVersieId` binding, niet aktuelle versie
- Audit trail registreert welke versie per berekening is gebruikt

### REQ-001-002: Automatische versiegebruik bij aanstelling

**GIVEN** een nieuwe CAO-versie 2026-2028 wordt geïmporteerd met ingangsdatum 1 januari 2027  
**WHEN** een HR-adviseur een dienstverband aanmaakt met startdatum 1 maart 2027  
**THEN** het systeem stelt automatisch de salarisschaal vast op basis van de 2026-2028 versie.

**Acceptance:**
- Versielimlookup op basis van `ingangsdatum <= aanstellingsDatum < einddatum`
- UI-prefill van salaristabel is uit de correcte versie
- Medewerker_Rechtspositie.caoVersieId wordt auto-set

### REQ-001-003: Verwijderingsbescherming voor aktieve versies

**GIVEN** een CAO-versie staat op status `actief`  
**WHEN** een gebruiker probeert deze versie te verwijderen  
**THEN** het systeem weigert de verwijdering en toont de melding "Actieve CAO-versies kunnen niet worden verwijderd; archiveer eerst."

**Acceptance:**
- Delete-operatie check `status === 'actief'` → reject met 409 Conflict
- Status-change workflow: actief → ingetrokken → archivering (niet direct delete)
- No orphaned references na status-change (audit trail maintains link)

---

## REQ-002: Volledige salaristabel schaal 1 t/m 19

Het systeem MOET de volledige salaristabel van CAO Gemeenten ondersteunen, inclusief alle 19 hoofdschalen en bijbehorende periodieken (variërend van 5 periodieken in schaal 1 tot 11 periodieken in schaal 19).

### REQ-002-001: Directe schaaltabel-lookup

**GIVEN** de CAO-versie 2024-2026 is geïmporteerd  
**WHEN** een HR-adviseur schaal 10 periodiek 4 selecteert voor een nieuwe medewerker  
**THEN** het systeem vult automatisch het brutomaandsalaris in op exact het bedrag uit de officiële VNG-tabel (€ 3.897,00).

**Acceptance:**
- Import van salaristabel bevat alle 19 × N periodieken (correct per VNG-officieel document)
- UI dropdown: schaal selector → conditief periodiek-range loader
- Bedrag-lookup geeft exact VNG-tabel-bedrag terug
- Hash-verificatie tegen VNG-PDF bijgevoegd

### REQ-002-002: Eindperiodiek detection

**GIVEN** een medewerker zit in schaal 7 periodiek 11 (eindperiodiek)  
**WHEN** de jaarlijkse periodieke verhoging plaatsvindt  
**THEN** het systeem genereert een melding "Eindperiodiek bereikt — geen periodieke verhoging mogelijk; overweeg promotie of toelage."

**Acceptance:**
- Periodieke-verhoging-jaarlijkse-taak check `periodiek === aantalPeriodieken - 1`
- Melding genereren, salary immobiel laten
- Suggestion-card toont promotie/toelage-alternatieven

### REQ-002-003: Horizontale inschalingregel bij schaalverhoging

**GIVEN** een medewerker zit in schaal 8 periodiek 6, en een leidinggevende vraagt schaalverhoging naar schaal 9  
**WHEN** het systeem bepaalt de inschalingsperiodiek in schaal 9  
**THEN** het systeem bepaalt automatisch de inschalingsperiodiek op basis van het horizontaal aansluitende salaris (geen achteruitgang).

**Acceptance:**
- Current salary = salaristabel[schaal=8][periodiek=6]
- Lookup schaal=9: vind periodiek P zodat salaristabel[9][P] >= current
- Toon voorstel: "Inschakel in schaal 9 periodiek X (salaris € Y, +€ Z verhoging)"
- Gebruiker kan aanpassen (hoger voor gezamenlde erkenning)

---

## REQ-003: IKB-opbouw 17.5% over alle vaste loonbestanddelen

Het systeem MOET maandelijks 17.5% van de IKB-grondslag toevoegen aan het IKB-saldo van iedere medewerker, waarbij de grondslag bestaat uit brutoloon plus eindejaarsuitkering plus levensloopbijdrage plus verlofopbouw boven het wettelijk minimum.

### REQ-003-001: Basale IKB-opbouwberekening

**GIVEN** een medewerker heeft brutomaandsalaris € 3.897,00  
**WHEN** de maandelijkse loonrun draait  
**THEN** het systeem boekt € 682,00 toe aan IKB voor die maand (3897 × 0,175).

**Acceptance:**
- Monthly task: iterate over all medewerker_rechtspositie with status=actief
- Calculate: `brutoMaandsalaris × ikbPercentage / 100`
- Create IKBRekening.maandelijkseOpbouw entry dated begin-of-month
- Saldo update: `openingssaldo + opbouw - opnames`

### REQ-003-002: IKB-opbouw voor deeltijdwerk

**GIVEN** een medewerker werkt 0,8 FTE met brutomaandsalaris € 3.118,00 deeltijd  
**WHEN** de maandelijkse IKB-opbouw wordt berekend  
**THEN** het systeem berekent IKB over het deeltijdsalaris (€ 545,65 per maand), niet over een fictief voltijdsalaris.

**Acceptance:**
- IKB-grondslag = `brutoMaandsalaris` (al deeltijd-gefactord)
- Opbouw = `3118 × 0,175 = 545,65`
- No pro-rata of FTE-inverting correction

### REQ-003-003: Pro-rata IKB bij uittreding

**GIVEN** een medewerker treedt uit dienst per 15 augustus  
**WHEN** de eindafrekening wordt opgesteld  
**THEN** het systeem berekent de IKB-opbouw pro rata voor augustus (15/31 dagen) en boekt het volledige resterende IKB-saldo uit als nabetaling op de laatste loonstrook.

**Acceptance:**
- Calculate: `brutoMaandsalaris × (15/31) × ikbPercentage / 100`
- Add to August opbouw
- IKBRekening.afrekeningEindeJaar = true
- Exit-loonstrook includes saldo payout

---

## REQ-004: IKB-opname in zes verschillende doelen

Het systeem MOET medewerkers toestaan IKB op te nemen voor minimaal zes doelen: contante uitbetaling, extra verlof, fiets van de zaak, vakbondscontributie, opleidingskosten en bedrijfsfitness, waarbij iedere opname fiscaal correct wordt verwerkt (gericht vrijgesteld waar mogelijk via WKR, anders bruto-uitbetaling).

### REQ-004-001: Extra verlof via IKB (WKR-gericht vrijgesteld)

**GIVEN** een medewerker heeft IKB-saldo € 5.000 en vraagt extra verlof aan ter waarde van € 1.200 (40 uur × € 30 uurtarief)  
**WHEN** de leidinggevende de aanvraag goedkeurt  
**THEN** het systeem boekt € 1.200 af van het IKB-saldo, voegt 40 verlofuren toe aan het verlofsaldo, en registreert geen fiscale belastinggebeurtenis (gericht vrijgesteld via WKR).

**Acceptance:**
- IKBRekening.opnames += `{datum, bedrag: 1200, type: 'extra_verlof', verlofUren: 40, goedkeurdDoor: uuid}`
- Verlofadministratie receives 40 hours → not counted as income
- Audit: "WKR gericht vrijgesteld - extra verlof"

### REQ-004-002: Contante uitbetaling (bruto-loon)

**GIVEN** een medewerker vraagt contante uitbetaling van € 2.000 uit IKB  
**WHEN** de aanvraag wordt verwerkt  
**THEN** het systeem verloont het bedrag bruto op de eerstvolgende loonstrook (loonheffing volgens reguliere tabel) en boekt € 2.000 af van het IKB-saldo.

**Acceptance:**
- IKBRekening.opnames += `{datum, bedrag: 2000, type: 'uitbetaling_vakantiegeld'}`
- Payroll receives: `{ikbUitbetalingBruto: 2000}`
- Loonheffing calculated on full 2000 (not exempt)
- Loonstrook shows "IKB contante uitbetaling € 2.000 bruto"

### REQ-004-003: Jaarafsluiting met restant-opname

**GIVEN** het is 31 december en een medewerker heeft nog € 1.500 niet opgenomen IKB-saldo  
**WHEN** de jaarafsluiting draait  
**THEN** het systeem keert het volledige resterende saldo bruto uit op de decembereindstrook en zet het IKB-saldo per 1 januari op € 0.

**Acceptance:**
- Year-end task: iterate IKBRekening records met `afrekeningEindeJaar = true`
- Add opname: `{datum: '2024-12-31', bedrag: 1500, type: 'jaarafrekening_restant'}`
- Payroll: `{ikbJaarafrekening: 1500}`
- New IKBRekening for 2025 created with `openingssaldo = 0`

---

## REQ-005: Afwijkende loondoorbetaling bij ziekte (100%/70%)

Het systeem MOET de gunstiger CAO-Gemeenten regeling toepassen: 100% loondoorbetaling in het eerste ziektejaar en 70% in het tweede ziektejaar, in plaats van de wettelijke 70% gedurende beide jaren.

### REQ-005-001: 100%-loondoorbetaling jaar 1

**GIVEN** een medewerker is ziek gemeld op 10 maart 2024  
**WHEN** de loonrun van april 2024 draait  
**THEN** het systeem betaalt het volledige brutomaandsalaris uit (100%), niet 70%.

**Acceptance:**
- Ziekteperiode.startDatum = 2024-03-10
- Ziekteperiode.huidigPercentage auto-set to 100
- Payroll receives: `{ziekteLoondoorbetalingPercentage: 100}`
- Loonstrook shows "Loondoorbetaling ziekte (100%)"

### REQ-005-002: Automatische overgang naar 70% in jaar 2

**GIVEN** een medewerker is sinds 10 maart 2024 onafgebroken ziek  
**WHEN** de loonrun van april 2025 draait (week 56 van ziekte)  
**THEN** het systeem verlaagt automatisch de loondoorbetaling naar 70% en genereert een melding "Overgang naar tweede ziektejaar — controleer re-integratiedossier."

**Acceptance:**
- Scheduled task: check Ziekteperiode.startDatum + 52 weeks
- Ziekteperiode.verwachteOvergangNaar70Percent = startDatum + 1 jaar
- Update huidigPercentage = 70
- Alert to HR-adviseur: "Ziekte medewerker XYZ bereikt 1 jaar — overgang naar 70% doorbetaling"
- Payroll receives: `{ziekteLoondoorbetalingPercentage: 70}`

### REQ-005-003: Samentellingsregel ziekte

**GIVEN** een medewerker hervat op 1 mei 2025 en wordt op 1 juli 2025 opnieuw ziek voor dezelfde aandoening  
**WHEN** het systeem de doorbetalingsperiode bepaalt  
**THEN** het systeem past de samentellingsregel toe (binnen 4 weken) en zet de teller voort op de plek waar deze stond bij hervatting.

**Acceptance:**
- First period: 2024-03-10 → 2025-05-01 (52 weeks, already at 70%)
- Second period: 2025-07-01, gap < 4 weeks
- New Ziekteperiode.weekNummerInPeriode = 52 + elapsed (continue counter)
- Override huidigPercentage = 70 (continue 2nd-year rate)

---

## REQ-006: Verplichte ABP-aansluiting voor alle ambtenaren

Het systeem MOET bij iedere nieuwe aanstelling automatisch ABP als pensioenuitvoerder vastleggen, met validatie dat geen andere pensioenuitvoerder kan worden gekozen voor medewerkers die onder de CAO Gemeenten vallen.

### REQ-006-001: ABP-validatie bij aanstelling

**GIVEN** een HR-adviseur registreert een nieuwe medewerker onder CAO Gemeenten  
**WHEN** het pensioenuitvoerder-veld wordt ingevuld  
**THEN** het systeem accepteert alleen de waarde "ABP" en wijst andere waarden af met de melding "ABP-aansluiting is verplicht onder CAO Gemeenten."

**Acceptance:**
- CnFormDialog pensioenuitvoerder-veld is disabled/read-only, value = "ABP"
- Server-side validation: if `caoCode === 'GEMEENTEN'` then require `pensioenuitvoerder === 'ABP'`
- Return 400 Bad Request if violated

### REQ-006-002: Automatische ABP-aanmelding

**GIVEN** een nieuwe medewerker wordt aangemeld zonder ABP-deelnemernummer  
**WHEN** de eerste loonrun voor deze medewerker wordt voorbereid  
**THEN** het systeem genereert automatisch een aanmelding naar ABP via de Pensioenfondsen-koppeling en blokkeert de loonrun totdat het deelnemernummer is toegekend.

**Acceptance:**
- Loonrun validation: check all medewerkers have `abpDeelnemerNummer` non-null
- If null: trigger `ABPAansluitingService.aanmelden(medewerkerId, ...)`
- SOAP call to https://upa.abp.nl: AanmeldingMedewerker()
- Poll ABP response; Medewerker_Rechtspositie.abpDeelnemerNummer auto-update
- Block loonrun until assigned; show "Wachten op ABP-aanmelding..."

### REQ-006-003: Automatische ABP-afmelding bij uittreding

**GIVEN** een medewerker treedt uit dienst  
**WHEN** het dienstverband wordt afgesloten  
**THEN** het systeem stuurt automatisch een afmelding naar ABP met de juiste uitdiensttredingsdatum en de reden (vrijwillig vertrek, ontslag, pensioen, overlijden).

**Acceptance:**
- Exit workflow: trigger `ABPAansluitingService.afmelden(medewerkerId, ontslagdatum, ontslagrond)`
- SOAP call: AfmeldingMedewerker(deelnemerNummer, exitDatum, reason_code)
- Log: "ABP afmelding verzonden voor medewerker X, reden: {vrijwillig, ontslag, pensioen, overlijden}"
- No loonrun possible post-uittreding for this medewerker

---

## REQ-007: BWGR (bovenwettelijke werkloosheidsregeling) bij ontslag

Het systeem MOET bij ontslag van een ambtenaar automatisch de BWGR-rechten berekenen op basis van diensttijd en ontslagrond, en de aanvulling op de WW-uitkering vastleggen voor automatische verwerking via salarisadministratie of via de UWV.

### REQ-007-001: Automatische BWGR-berekening bij ontslag

**GIVEN** een medewerker met 12,5 jaar gemeentelijke diensttijd wordt ontslagen wegens reorganisatie  
**WHEN** de exit-procedure wordt afgerond  
**THEN** het systeem berekent automatisch een BWGR-aanvulling van 20% bovenop de WW gedurende 24 maanden en genereert een betalingsschema.

**Acceptance:**
- Diensttijd = aanstellingsdatum tot ontslagdatum
- BWGR-percentage lookup tabel per CAO artikel 10.3 op basis van diensttijd + ontslagrond
- For 12,5 jaar reorganisatie: 20% supplement, 24 months duration
- BWGR_Uitkering record created: `{exMedewerkerId, ontslagdatum, diensttijdJaren: 12.5, bwgrAanvullingPercentage: 20, bwgrLooptijdMaanden: 24, ...}`
- Betalingsschema generated: monthly installments × 24

### REQ-007-002: Wachtgeld-activering na BWGR-einde

**GIVEN** een ex-medewerker krijgt na 18 maanden WW een nieuwe baan  
**WHEN** dit wordt gemeld aan de salarisadministratie  
**THEN** het systeem stopt automatisch de BWGR-aanvulling en bewaart het ongebruikte recht in een "slapend BWGR-saldo" voor eventuele toekomstige werkloosheid binnen de looptijd.

**Acceptance:**
- BWGR_Uitkering.wwUitkeringEinde updated manually
- Calculate used: (wwUitkeringEinde - wwUitkeringStart) months × monthly_supplement
- Calculate unused: total - used → sleependBWGRSaldo
- Flag: BWGR-aanvulling stops on wwEindedate
- If re-jobless within 24mo: reopen slapend saldo

### REQ-007-003: Wachtgeldrecht activering na BWGR

**GIVEN** een medewerker met meer dan 10 jaar diensttijd wordt ontslagen  
**WHEN** de BWGR-rechten worden berekend  
**THEN** het systeem activeert automatisch ook het wachtgeldrecht dat ingaat na afloop van de BWGR-periode (geen overlap toegestaan).

**Acceptance:**
- If diensttijdJaren > 10: wachtgeldrecht = true
- wachtgeldVan = bwgrEinde + 1 day
- wachtgeldEinde = wachtgeldVan + (diensttijdJaren × months per CAO artikel 10.4)
- BWGR_Uitkering.wachtgeldVan / wachtgeldEinde populated
- No salary payments for this medewerker during BWGR or wachtgeld period

---

## REQ-008: Functiehuis HR21 koppeling

Het systeem MOET iedere medewerker koppelen aan een functie uit het HR21-functiehuis, waarbij de functiewaardering automatisch leidt tot een minimum/maximum schaalbereik.

### REQ-008-001: HR21-schaalrangebeperkinging

**GIVEN** een HR-adviseur kent functie "HR21-BELEIDSMEDEWERKER-II" toe aan een medewerker  
**WHEN** de schaalkeuze wordt gemaakt  
**THEN** het systeem beperkt de selecteerbare schalen tot schaal 9, 10 en 11 (het toegestane bereik voor deze functie).

**Acceptance:**
- HR21Service.getFunctieData('HR21-BELEIDSMEDEWERKER-II') → {minSchaal: 9, maxSchaal: 11}
- Schaal-dropdown gefilterd: [9, 10, 11]
- Server validation: if schaalNummer not in [minSchaal, maxSchaal] then reject

### REQ-008-002: Functiewijziging met automatische inschalings-suggestie

**GIVEN** een medewerker krijgt een functiewijziging naar een hogere functie  
**WHEN** de wijziging wordt geregistreerd  
**THEN** het systeem stelt voor om de medewerker in te schalen op de aansluitende periodiek in de nieuwe schaal en genereert een wijzigingsadvies voor goedkeuring door de leidinggevende.

**Acceptance:**
- Current: schaal 8, periodiek 6
- New functie: HR21-MANAGER-I (bereik 11-13)
- Auto-suggest: schaal 11, periodiek = horizontaal-aansluiting
- Generate advice: "Functioneel wijziging naar Manager I — inschakel schaal 11 periodiek 3 (continuïteit)"
- Leidinggevende approves/rejects

### REQ-008-003: Hercategorisering bij schaalconflict

**GIVEN** een functie is gewaardeerd als HR21 schaal 7-9, en een leidinggevende probeert deze functiehouder in schaal 10 te plaatsen  
**WHEN** het systeem de schaal-validatie doet  
**THEN** het systeem weigert dit en biedt twee opties: hercategoriseer de functie of creëer een maatwerkfunctie met onderbouwing.

**Acceptance:**
- Server: schaalNummer not in [7-9] → reject with choices
- Option A: "Hercategoriseer HR21-BELEIDSMEDEWERKER-II naar schaal 10-12 (requires HR21 org approval)"
- Option B: "Maak maatwerkfunctie aan: BELEIDSMEDEWERKER-II-SPECIAL (schaal 10, onder-bouwd)"
- Both require leidinggevende + HR approval workflow

---

## REQ-009: Roosterverlof en buitengewoon verlof bovenop wettelijk

Het systeem MOET naast het wettelijke vakantieverlof (4× weekuren) ook roosterverlof (per CAO 7,2 uur bij voltijd) en alle vormen van buitengewoon verlof (huwelijk, geboorte, overlijden, verhuizing, vakbondsactiviteit) als aparte saldi bijhouden.

### REQ-009-001: FIFO verlofopbrengstregeling

**GIVEN** een voltijd medewerker heeft de saldi: wettelijk verlof 160 uur, bovenwettelijk verlof 14,4 uur, roosterverlof 7,2 uur  
**WHEN** de medewerker 8 uur verlof opneemt zonder doel-specificatie  
**THEN** het systeem boekt af van wettelijk verlof eerst (FIFO oudste eerst), conform CAO-volgorderegel.

**Acceptance:**
- Verlofadministratie.deduct(medewerkerId, 8) applies in order: wettelijk → bovenwettelijk → rooster
- New saldo: wettelijk 152, bovenwettelijk 14.4, roosterverlof 7.2
- Audit trail shows "8 uur van wettelijk verlof"

### REQ-009-002: Geboorteverlof toekenning

**GIVEN** een medewerker krijgt een kind  
**WHEN** geboorteverlof wordt aangevraagd  
**THEN** het systeem kent automatisch 1 week (40 uur bij voltijd) geboorteverlof toe als buitengewoon verlof + signaleert recht op aanvullend geboorteverlof via UWV.

**Acceptance:**
- BuitengewoonVerlof type = 'geboorteverlof' → auto-grant 40 hours
- Send notification to Verlofadministratie
- UWV signal: "Aanspraak Geboorteverlof UWV (ASV artikel ...)"
- Medewerker self-service shows "Geboorteverlof 40 uur goedgekeurd"

### REQ-009-003: Rouwverlof per familierelatie

**GIVEN** een naast familielid van een medewerker overlijdt  
**WHEN** rouwverlof wordt aangevraagd  
**THEN** het systeem kent het CAO-bepaalde aantal dagen toe (4 dagen voor partner/kind, 2 dagen voor ouder/broer/zus) als buitengewoon verlof, niet ten laste van regulier verlof.

**Acceptance:**
- BuitengewoonVerlof type = 'rouwverlof', relation_type ∈ {partner, kind, ouder, broer_zus}
- Grant: if relation ∈ {partner, kind} then 4 days else 2 days
- Calculate hours: days × (weekuurverlof / 5) e.g. 4 days = 32 hours (40h/week)
- Does NOT deduct from wettelijk/bovenwettelijk/rooster saldi

---

## REQ-010: Auditeerbare wijzigingen met bron-CAO-artikel

Iedere mutatie in salaris, schaal, periodiek of toeslag MOET worden gelogd met verwijzing naar het specifieke CAO-artikel dat de wijziging rechtvaardigt, plus de gebruiker en het tijdstip.

### REQ-010-001: Audit-trail op salariswijziging

**GIVEN** een HR-adviseur verhoogt de periodiek van een medewerker  
**WHEN** de wijziging wordt opgeslagen  
**THEN** het systeem registreert een audit-record met velden: oude periodiek, nieuwe periodiek, CAO-artikel 3.4 (periodieke verhoging), motivatie, gebruiker, tijdstip, IP-adres.

**Acceptance:**
- AuditTrailService auto-logs all field changes on Medewerker_Rechtspositie
- Custom field: `caoArtikelReferentie` = "CAO Gemeenten 2024-2026, artikel 3.4"
- Changes include: { old: 3, new: 4, article: '3.4', reason: 'annual_increment', user: uuid, timestamp, ip }
- Immutable audit record; no deletion

### REQ-010-002: Audit-report per medewerker per periode

**GIVEN** een controller voert een audit uit op alle salariswijzigingen van Q1 2024  
**WHEN** het auditrapport wordt opgevraagd  
**THEN** het systeem genereert een PDF met per wijziging het CAO-artikel, voor/na bedragen en de goedkeurder.

**Acceptance:**
- ReportService.auditReport(startDatum, eindDatum, medewerkerId?)
- Output: PDF tabel with columns: datum, veld, oude_waarde, nieuwe_waarde, cao_artikel, user, approver
- Filterable by period, medewerker, artikel
- Exportable as CSV/Excel

### REQ-010-003: Historisch bezwaar-dossier

**GIVEN** een medewerker maakt bezwaar tegen een functie-indeling  
**WHEN** de HR-adviseur het bezwaardossier opent  
**THEN** het systeem toont de volledige historie van functietoekenningen inclusief CAO-onderbouwing, en biedt de mogelijkheid een formele bezwaarprocedure te starten.

**Acceptance:**
- DecideskService integration: link to medewerker-dossier
- Show timeline: {datum, functieCode, functieNaam, artikel_onderbouwing, assigned_by, approved_by}
- "Start Bezwaarschrift" workflow: → DecideskModule
- Audit trail shows all past functie-mutataties with reasons
