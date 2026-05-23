---
status: draft
---
# Loonbeslag-administratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Salarissen › Loonbeslagen

**Rationale:** Loonbeslag-administratie.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Loonbeslag is een dwangmiddel waarmee een schuldeiser via een gerechtsdeurwaarder of een overheidsorgaan (LBIO, CJIB, Belastingdienst, gemeente) een deel van het loon van een werknemer rechtstreeks afhoudt bij de werkgever. Voor de werkgever is het ontvangen van een loonbeslagexploot juridisch zwaar verplichtend: hij wordt derde-beslagene en moet binnen vier weken een verklaring derdenbeslag afleggen (art. 476a Rv), maandelijks correct afdragen aan de beslaglegger, de beslagvrije voet correct berekenen (Wet vereenvoudiging beslagvrije voet 2021), de wettelijke volgorde van beslagen respecteren bij samenloop, en alles vertrouwelijk behandelen jegens collega's terwijl de werknemer zelf wel volledig inzicht moet krijgen.

Fouten zijn duur en hebben drie ontvangstdomeinen: (1) civielrechtelijk — de werkgever wordt aansprakelijk voor het tekort als hij te weinig afdraagt of zelfs voor de gehele vordering bij verzuim van de derdenverklaring; (2) AVG — een loonbeslag bevat bijzondere persoonsgegevens (financiële situatie) en moet vertrouwelijk worden behandeld jegens leidinggevenden zonder need-to-know; (3) sociaal — een verkeerd berekende beslagvrije voet kan een werknemer onder bestaansminimum brengen met directe escalatie naar bewindvoering en schuldhulpverlening. Met de Wvbvv 2021 is de berekening van de beslagvrije voet bovendien drastisch gewijzigd: niet langer een handmatige indicatie maar een formule op basis van inkomen, leefvorm, woonkosten en zorgkosten met een door BKWI/SVB centraal beheerde rekenkern.

`loonbeslag-admin` automatiseert het volledige loonbeslag-proces binnen hrmq: registratie van het exploot, automatische beslagvrije-voet-berekening conform Wvbvv 2021, samenloop-afhandeling bij meerdere beslagen (preferent vs concurrent), maandelijkse afdracht-batches, gestandaardiseerde correspondentie (acceptatieverklaring, derdenverklaring, eindverklaring), strikte AVG-confidentialiteit (loonstroken tonen alleen "afdracht conform loonbeslag" zonder beslaglegger te noemen voor niet-bevoegden), en een volledige audit-trail. De capability draait bovenop `employee-master` en `payroll-engine-nl` en is voor elke serieuze werkgever onmisbaar — gemiddeld 7-8% van de Nederlandse beroepsbevolking heeft op enig moment te maken met loonbeslag.

## Data Model

`Beslag` (één exploot per record): `id`, `medewerker_id`, `administratie_id`, `beslagleggertype` (lbio_alimentatie/cjib_boete/belastingdienst/deurwaarder_civiel/gemeente_terugvordering_bijstand/uwv_terugvordering/zorgverzekeraar/student_finance_duo), `beslaglegger_naam`, `beslaglegger_adres` (gestructureerd), `beslaglegger_iban`, `beslaglegger_kenmerk` (referentie voor afdracht-omschrijving), `beslaglegger_bsn_kenmerk` (waar van toepassing), `deurwaarder_id` (KBvG-nummer indien deurwaarder), `exploot_datum` (datum van betekening aan werkgever), `exploot_document_uri` (gescand exploot), `vorderingsbedrag_oorspronkelijk`, `vorderingsbedrag_resterend`, `rente_aanwas_per_maand` (waar van toepassing), `preferentie` (preferent/concurrent — Belastingdienst en LBIO zijn preferent), `volgnummer_intern` (volgorde van ontvangst — eerste komt voor bij gelijke preferentie), `vanaf` (eerste loonperiode waarop beslag wordt toegepast), `tot` (vooraf bekend einde of null), `status` (concept/actief/opgeschort/afgelost/ingetrokken/overgedragen).

`BeslagvrijeVoet` (per medewerker per beslagperiode — opnieuw berekend bij elke wijziging in persoonlijke situatie): `id`, `medewerker_id`, `peilmaand`, `bvv_bedrag` (uitkomst formule Wvbvv), `bvv_methode` (wvbvv_standaard/handmatig_overruled_met_motivering), `inkomen_grondslag` (netto-inkomen voor berekening), `leefvorm` (alleenstaand/alleenstaande_ouder/gehuwd/samenwonend), `aantal_kinderen_tlv` (tellen voor kindgebonden compensatie), `woonkosten` (huur of hypotheek), `nominale_premie_zvw`, `bvv_brondocument_uri` (door werknemer aangeleverd bewijs van leefvorm/woonkosten), `vastgesteld_door_id`, `vastgesteld_op`.

`BeslagSamenloop` (waar één medewerker meerdere actieve beslagen heeft): `id`, `medewerker_id`, `peilmaand`, `actieve_beslagen` (array van Beslag.id geordend op preferentie+volgnummer), `totaal_beschikbaar_voor_beslag` (netto loon − bvv), `verdeling_per_beslag` (JSON: beslag_id → toegewezen_bedrag), `methodiek` (preferent_eerst_dan_concurrent_naar_rato/strikt_chronologisch_oudst_eerst).

`BeslagAfdracht` (per loonperiode per beslag — wat er daadwerkelijk wordt afgedragen): `id`, `loonrun_id`, `beslag_id`, `medewerker_id`, `periode_jaar`, `periode_maand`, `bedrag_ingehouden`, `bedrag_afgedragen`, `afdrachtdatum`, `betalingsreferentie`, `journaalpost_id`, `status` (gepland/uitgevoerd/mislukt/teruggeboekt).

`BeslagCorrespondentie`: `id`, `beslag_id`, `type` (acceptatieverklaring/derdenverklaring/maandelijkse_specificatie/eindverklaring/aansprakelijkheidsbetwisting), `verzenddatum`, `ontvanger`, `document_uri` (gegenereerd PDF), `verzendmethode` (post_aangetekend/email/digipoort), `verzendbevestiging_uri`, `door_gebruiker_id`.

`BeslagVertrouwelijkheidsLog`: `id`, `beslag_id`, `gebruiker_id`, `toegangstype` (raadpleging/wijziging/export/correspondentie_verzonden), `tijdstip`, `rechtvaardiging` (rol-vereiste — payroll_admin / hr_manager / etc), `ip_adres`. Aparte log naast de generieke `audit-trail-payroll` omdat beslag-gegevens een hogere confidentialiteitsklasse hebben.

## Requirements

**REQ-001: Beslag-registratie binnen 5 werkdagen na betekening.** HR/payroll legt een ontvangen exploot vast met scan; het systeem dwingt de wettelijke deadlines af door countdown-timers en alerts.
- GIVEN een exploot wordt op 2026-06-01 betekend, WHEN HR het registreert op 2026-06-02, THEN toont het systeem een deadline-teller: "derdenverklaring uiterlijk 2026-06-29 (28 dagen — 27 over)".
- GIVEN de derdenverklaring is op 2026-06-25 nog niet verzonden, WHEN het systeem de dagelijkse compliance-check draait, THEN wordt een hoge-prioriteit alert verzonden aan de payroll_admin met escalatie naar de hr_manager bij T-2.

**REQ-002: Automatische berekening beslagvrije voet conform Wvbvv 2021.** Het systeem implementeert de wettelijke BVV-formule met inkomen, leefvorm, woonkosten en nominale zorgpremie als parameters; voor de meest accurate berekening wordt waar mogelijk de BKWI-rekenmodule gevolgd.
- GIVEN een alleenstaande medewerker zonder kinderen met netto-maandinkomen €2.400 en woonkosten €900, WHEN de BVV-berekening draait voor juni 2026, THEN gebruikt het systeem de Wvbvv-formule met de voor juni 2026 geldende normbedragen en levert een onderbouwd BVV-bedrag op met traceerbare tussenstappen.
- GIVEN de medewerker levert bewijs aan van woonkosten of leefvorm, WHEN het bewijs is geverifieerd, THEN wordt de BVV opnieuw berekend en de nieuwe waarde toegepast vanaf de eerstvolgende loonperiode.
- GIVEN HR overruled de berekende BVV handmatig, WHEN dit gebeurt, THEN is een vrije-tekst-motivering verplicht en wordt het record met `bvv_methode = handmatig_overruled_met_motivering` opgeslagen plus een hoogvolume audit-event.

**REQ-003: Samenloop-afhandeling bij meerdere beslagen.** Wanneer een medewerker meerdere actieve beslagen heeft, past het systeem de wettelijke volgorde toe: preferente beslagen eerst (Belastingdienst, LBIO-alimentatie), concurrente daarna naar rato of in chronologische volgorde van betekening.
- GIVEN een medewerker heeft een actief LBIO-alimentatiebeslag (preferent) en daarna een deurwaarders-beslag (concurrent), WHEN er €600 beschikbaar is voor beslag, THEN gaat eerst het LBIO-bedrag (bijv. €400) en het restant (€200) naar de deurwaarder.
- GIVEN twee concurrente beslagen met gelijke preferentie en €500 beschikbaar, WHEN het oudste beslag €800 vordert en het nieuwere €300, THEN ontvangen ze naar rato (€500 × 800/1100 ≈ €364 vs €500 × 300/1100 ≈ €136), of strikt chronologisch het oudste volledig en het nieuwere het restant — afhankelijk van per-administratie-instelling.

**REQ-004: Beslag op overig inkomen check (Wsnp / WSNP-conflict).** Bij ingangsdatum van een beslag controleert het systeem of de medewerker al onder Wsnp valt — zo ja, dan wordt het beslag opgeschort en wordt automatisch een aansprakelijkheidsbetwisting voorbereid.
- GIVEN HR registreert dat een medewerker onder Wsnp is toegelaten, WHEN er daarna een loonbeslag binnenkomt, THEN wordt het beslag automatisch op status `opgeschort` gezet en een conceptbrief "Wsnp-betwisting beslag" aan de bewindvoerder + beslaglegger verstuurd.
- GIVEN een loonbeslag is actief en de medewerker wordt nadien toegelaten tot Wsnp, WHEN HR de Wsnp-toelating registreert, THEN worden alle actieve concurrente beslagen automatisch opgeschort vanaf de toelatingsdatum.

**REQ-005: Maandelijkse afdracht via batch-betaling.** Bij elke loonrun genereert het systeem per actief beslag een afdrachtopdracht; afdrachten worden gebundeld tot één batch SEPA-betaalbestand met correcte omschrijving en kenmerk per regel.
- GIVEN er zijn drie actieve beslagen op verschillende medewerkers in administratie A, WHEN de juni-loonrun is voltooid, THEN wordt een SEPA-pain.001-bestand gegenereerd met drie betaalregels, elk met de juiste IBAN, bedrag en kenmerk van de beslaglegger.
- GIVEN een afdracht-betaling wordt door de bank geweigerd (IBAN onjuist), WHEN het betaalstatusbestand wordt ingelezen, THEN gaat de `BeslagAfdracht`-status naar `mislukt`, wordt het ingehouden bedrag op een transit-rekening geboekt en wordt een alert naar payroll_admin gestuurd.

**REQ-006: Standaard-correspondentie templates.** Het systeem levert gevalideerde Nederlandse templates voor de wettelijke standaardbrieven en faciliteert verzending per aangetekende post of e-mail.
- GIVEN HR moet binnen 4 weken een derdenverklaring afleggen, WHEN HR op de actie-knop drukt, THEN wordt een conceptbrief gegenereerd met alle relevante velden vooringevuld (medewerker, dienstverband, bruto-loon, eerder gelegde beslagen, leefvorm-aanname) en kan HR deze handmatig aanvullen waar nodig.
- GIVEN het beslag is volledig afgelost, WHEN de laatste afdracht is verwerkt, THEN wordt automatisch een eindverklaring naar de beslaglegger gestuurd met totaaloverzicht van alle afdrachten.

**REQ-007: AVG-confidentialiteit en gelaagde toegang.** Loonbeslag-gegevens zijn alleen zichtbaar voor expliciet bevoegde rollen; loonstroken tonen voor de medewerker zelf wel het volledige beslag-detail, voor leidinggevenden zonder need-to-know slechts een geanonimiseerde regel.
- GIVEN een medewerker logt in op het self-service portaal, WHEN hij zijn loonstrook opent, THEN ziet hij de regel "Inhouding loonbeslag — €350,00 — afdracht aan LBIO inzake alimentatie kenmerk 12345".
- GIVEN een leidinggevende met budgethouder-rol maar zonder hr_manager-rol opent een team-loonkosten-rapport, WHEN dit gebeurt, THEN wordt elke beslag-regel als "Overige loonheffing/correctie — €350,00" weergegeven zonder verwijzing naar beslag.
- GIVEN een onbevoegde gebruiker probeert `GET /api/beslagen/{id}` rechtstreeks aan te roepen, WHEN dit gebeurt, THEN antwoordt de API met `404 Not Found` en wordt een security-event geschreven in `BeslagVertrouwelijkheidsLog`.

**REQ-008: Aansprakelijkheidsrisico-monitor.** Het systeem signaleert situaties waarin de werkgever risico loopt op civielrechtelijke aansprakelijkheid (gemiste deadlines, onderafdracht, onvolledige derdenverklaring).
- GIVEN een derdenverklaring is verzonden zonder vermelding van een eerder beslag, WHEN het tweede beslag wordt geregistreerd, THEN waarschuwt het systeem: "potentiële aansprakelijkheid — derdenverklaring beslag {ID-1} vermeldde mogelijk niet het reeds bestaande beslag {ID-2}".

**REQ-009: Loonbeslag-overzicht voor accountantscontrole en auditor.** Bevoegde gebruikers kunnen een periodieke export draaien van alle beslag-activiteit (alle beslagen, afdrachten, correspondentie) voor accountantscontrole of inspectie door SZW.
- GIVEN een accountant vraagt om beslag-audit-overzicht over Q2 2026, WHEN payroll_admin de export draait, THEN wordt een ZIP gegenereerd met per beslag een PDF-bundel (exploot, BVV-onderbouwing, alle afdrachten, alle correspondentie) plus een Excel met totaaloverzicht.

**REQ-010: Bewaartermijn en vernietiging.** Beslagdocumenten worden bewaard tot 7 jaar na einde van het dienstverband of einde van het beslag (welke later komt) en daarna automatisch vernietigd; de werknemer kan op verzoek inzage krijgen.
- GIVEN een beslag is op 2020-04-01 afgelost en de medewerker is op 2025-06-01 uit dienst, WHEN de bewaarperiode-checker draait in 2032-07-01, THEN worden de scan-documenten gepseudonimiseerd en gemarkeerd voor vernietiging; metadata blijft beschikbaar voor statistiek.

## Standards & Sources

- **Wet vereenvoudiging beslagvrije voet (Wvbvv, in werking 2021-01-01)** — uniforme formule, met SVB/BKWI-rekenkern voor bovenwettelijke berekeningen.
- **Wetboek van Burgerlijke Rechtsvordering art. 475 t/m 479g** — civielrechtelijk regime voor loonbeslag, derdenverklaring, derdenarrest.
- **Art. 19 Invorderingswet 1990** — fiscaal loonbeslag door de Belastingdienst (vereenvoudigd; geen exploot-vereiste, wel preferent).
- **Wet Schuldsanering Natuurlijke Personen (Wsnp)** — onderbreking van beslagen tijdens schuldsanering.
- **Wet Landelijk Bureau Inning Onderhoudsbijdragen (LBIO)** — preferente status van alimentatiebeslag.
- **Wet justitiële en strafvorderlijke gegevens / Wet administratiefrechtelijke handhaving verkeersvoorschriften** — CJIB-beslagen op verkeersboetes.
- **AVG art. 9 (bijzondere persoonsgegevens)** en **art. 6 (verwerkingsgrondslag wettelijke verplichting)** — verwerking van financiële beslagdata.
- **AP-richtsnoer financiële persoonsgegevens in personeelsadministratie** (2022) — confidentialiteit jegens collega's en gelaagde toegangsrechten.
- **NEN 7510 / ISO 27001 A.9 (Access Control), A.18 (Compliance)** — gegevensbescherming en confidentialiteit.
- **Convenant LBIO-werkgevers en KBvG-Werkgevers Loonbeslag** — uniforme afdracht-systematiek en correspondentie-templates.
- Referentie-implementaties: **Loket.nl loonbeslag-module**, **Nmbrs Beslag**, **AFAS HR-Salaris Beslagmodule**, **DDi Justitia Loonbeslag-API**.

## Cross-app integration

- **employee-master** — levert leefvorm, kindgegevens en woonkosten als bron voor BVV-berekening; let op AVG-grondslag en minimaal-noodzakelijke gegevens.
- **payroll-engine-nl** — consumeert `BeslagSamenloop` per medewerker per loonperiode; trekt het juiste bedrag af van het netto-loon vóór uitbetaling.
- **multi-administratie** — beslagen zijn altijd administratie-scoped; bij intercompany-detachering blijft het beslag bij de payroll-uitvoerende administratie.
- **audit-trail-payroll** — alle beslag-mutaties worden onveranderlijk gelogd in de hash chain; daarnaast eigen `BeslagVertrouwelijkheidsLog` voor toegangs-events.
- **journaalpost-export** — beslag-inhoudingen leiden tot specifieke grootboekrekeningen ("Beslagleggers te betalen") in het boekhoudpakket.
- **openconnector** — verzorgt de SEPA-bank-koppeling voor afdracht-batches en de Digipoort-koppeling voor elektronische derdenverklaringen waar de beslaglegger dat ondersteunt.
- **document-vault** — bewaart scans van exploten en correspondentie met retentie-policies.
- **notification-engine** — deadline-alerts, correspondentie-bevestigingen, escalaties.
- **30-procent-regeling** — bij intrekking met terugwerkende kracht en gelijktijdig loonbeslag wordt de samenloop herberekend.

## Target users

- **Payroll-administrateur** — primaire dagelijkse gebruiker; ontvangt exploten, registreert ze, verzendt derdenverklaringen, beheert afdrachten.
- **HR-manager** — tweede-lijn-beslisser bij overrules en bij verzoek van medewerker om aanvullend bewijs voor leefvorm/woonkosten.
- **Compliance officer / Functionaris Gegevensbescherming** — controleert dat confidentialiteit en bewaartermijnen worden gerespecteerd; gebruikt het BeslagVertrouwelijkheidsLog.
- **Medewerker zelf (debiteur)** — ziet in zijn self-service portaal alle actieve beslagen, BVV-onderbouwing en afdrachthistorie; kan bewijsstukken aanleveren.
- **Accountant** — bij jaarrekeningcontrole; gebruikt de Q-export voor steekproef.
- **Gerechtsdeurwaarder / LBIO / CJIB / Belastingdienst** (indirect) — ontvangen consistent geformatteerde derdenverklaringen, maandelijkse specificaties en eindverklaringen; minder herstelvragen.
- **DGA bij kleine BV** — bij eerste beslag binnen het bedrijf: het systeem moet de procedure begrijpelijk uitleggen en de juiste documenten ophoesten zonder dat de DGA juridische kennis hoeft te hebben.
