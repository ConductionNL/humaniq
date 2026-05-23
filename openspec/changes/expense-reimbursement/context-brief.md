---
status: draft
---
# Onkostendeclaratie (Declaratie entity + approval workflow)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Declaraties & assets › Declaraties

**Rationale:** Onkosten-flow.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `expense-reimbursement` capability turns the bonnetjes-in-een-schoenendoos problem into a typed, validated, audited pipeline that produces correctly classified accounting entries on one side and correctly classified payroll components on the other. In Dutch MKB the declaratie-process is the most touched HR-adjacent process — every medewerker submits something at least monthly — and the one most prone to (a) verlies van bonnetjes, (b) verkeerde fiscale classificatie (vrije ruimte WKR vs gerichte vrijstelling vs nihil-waardering vs loon), (c) dubbele indiening of vergeten indiening, (d) trage approval die het ritme van werknemer en boekhouder ontregelt, en (e) geschillen over kilometervergoeding bij gebrek aan een betrouwbare mileage-trail.

hrmq behandelt elke declaratie als een `Declaratie` object met een verplichte koppeling aan een `Bonnetje`-bewijsstuk (of een expliciete `geen_bewijsstuk_reden` waar de wet dit toestaat, met een hard plafond aan het bedrag), een verplichte WKR-classificatie, en een approval-workflow van 1 tot 3 stappen die per business-unit configureerbaar is. Bonnetjes worden bij voorkeur via Nextcloud Mobile gescand (camera → PDF → OCR via docudesk), waarna de OCR-output (datum, leverancier, bedrag, BTW-bedrag, BTW-tarief) automatisch in het Declaratie-formulier wordt voorgevuld. De medewerker bevestigt of corrigeert, kiest categorie + WKR-classificatie, en dient in.

Een aparte sub-flow is de **kilometervergoeding**, die de meest voorkomende declaratie is. hrmq biedt drie invoer-modaliteiten: (a) handmatig (van-adres + naar-adres + datum + zakelijk doel, met automatische routeberekening via een geokeyed afstand-service via openconnector), (b) auto-tracking via mobile-GPS waar de werknemer dit aan zet (per-rit bevestiging vereist; geen passieve tracking zonder consent), en (c) bulk-import via CSV/Excel uit auto-leasebeheersystemen. Het systeem berekent het belastingvrij + belast deel op basis van de jaarlijks geïndexeerde tarieven (€0.23/km belastingvrij in 2026, €0.21/km vanaf 2027 per Belastingplan).

Een tweede sub-flow is de **vaste onkostenvergoeding per maand** (telewerkvergoeding, kostenforfait, kleding-, koffie- en lunch-vergoeding waar van toepassing). Deze worden niet als losse declaraties ingediend maar als een doorlopende vergoedings-grondslag op het dienstverband, met periodieke onderbouwing via een steekproef-onderzoek (vereist door de Belastingdienst voor vrijgestelde vaste vergoedingen) waarvan de uitkomst in het systeem wordt vastgelegd.

De **WKR-administratie** is het fiscale hart van deze capability. Elke goedgekeurde declaratie + elke vaste vergoeding wordt geclassificeerd in één van: (i) intermediaire kosten (geen loon, gewoon AP), (ii) gerichte vrijstelling (geen loon, geen WKR-belasting), (iii) nihil-waardering (loon in natura met waarde €0), (iv) vrije ruimte WKR (loon, belast bij overschrijding van 1.92% over eerste €400.000 + 1.18% daarboven in 2026; nieuwe staffels in 2027), of (v) eindheffingsloon vast 80%. Het systeem houdt een doorlopende vrije-ruimte-teller per kalenderjaar bij, geeft waarschuwingen bij 75% en 100% verbruik, en levert de jaarafrekening aan shillinq + payroll.

Approval-workflows zijn configureerbaar per BU met regels op `bedrag`, `categorie`, `WKR-classificatie`, en `werknemer-rol`. Voorbeeld: declaraties < €50 in categorie `representatie` met OCR-bewijsstuk → 1-staps approval door direct leidinggevende; declaraties ≥ €500 of in categorie `opleidingen` → 3-staps approval (lijn → HR → finance); declaraties in vrije ruimte WKR > €100 → automatisch finance-review.

Scope explicitly excludes: zakelijke creditcard-administratie (verwijst naar shillinq AP), zakelijke reizen-boekingsproces (out of scope; alleen de declaratie achteraf), facturen aan klanten doorbelasten (AR via shillinq), en BTW-aangifte zelf (alleen de BTW-input wordt correct gelabeld zodat shillinq kan aangeven).

Een derde load-bearing concern is **valuta + buitenland**. Werknemers van Nederlandse MKB-werkgevers reizen regelmatig naar het buitenland en betalen daar in vreemde valuta. hrmq SHALL elke declaratie in originele valuta + bedrag accepteren, automatisch een ECB-referentiekoers van de uitgavedatum ophalen via openconnector, het EUR-equivalent berekenen, beide bedragen opslaan, en het EUR-bedrag gebruiken voor approval-drempels + WKR-classificatie. Bij wisselkoersverschillen tussen aangifte (ECB-referentie) en daadwerkelijke creditcard-afrekening (door bank gehanteerde commerciële koers) wordt het verschil als kleine inhouding of toeslag in een aparte regel verwerkt — dit voorkomt het MKB-gebruikelijke fenomeen waar declaraties altijd "ongeveer kloppen" maar bij audit de cijfers nooit aansluiten.

Een vierde concern is **mobile-first-ergonomie**. De realiteit van bonnetjes is dat ze in de tas zitten, beschadigen, en vergeten worden. De flow moet binnen 30 seconden van "bon ontvangen aan kassa" naar "ingediend" gaan: open Nextcloud Mobile → scan → categorie kiezen uit MRU-lijst → indienen. Alle metadata-velden behalve `categorie` + `zakelijk_doel` zijn voorgevuld door OCR; deze twee SHALL de werknemer in maximaal twee taps kunnen invullen via een suggestion-engine die op basis van vorige declaraties van dezelfde leverancier de meest waarschijnlijke categorie voorstelt. Dit is geen optionele luxe — als de flow langer dan 30 seconden duurt, vervalt het systeem in de bonnetjes-in-schoenendoos-modus die het juist moet oplossen.

## Data Model (entities + Dutch JSON examples)

### Declaratie

```json
{
  "id": "dec_01KCDE567HIJ",
  "employee_id": "emp_01HXYW987DEF",
  "soort": "bonnetje",
  "categorie": "verblijf",
  "subcategorie": "diner_zakelijke_relatie",
  "wkr_classificatie": "gerichte_vrijstelling",
  "wkr_grondslag": "art_31a_lid_2_letter_b_wet_lb",
  "datum_uitgave": "2026-05-12",
  "bedrag_incl_btw": 87.50,
  "btw_bedrag": 7.21,
  "btw_tarief": 9.0,
  "valuta": "EUR",
  "valuta_koers_eur": 1.0,
  "leverancier": "Restaurant De Kas",
  "leverancier_btw_nummer": "NL001234567B01",
  "omschrijving": "Diner met klant X over project Y",
  "deelnemers": ["Werknemer", "Contactpersoon Klant X"],
  "zakelijk_doel": "Voortgangsbespreking project Y",
  "bonnetje_document_id": "doc_bon_01KCDE",
  "ocr_confidence": 0.94,
  "status": "wacht_op_approval",
  "huidige_approval_stap": 1,
  "totaal_approval_stappen": 1,
  "ingediend_op": "2026-05-13T08:22:00+02:00",
  "goedgekeurd_op": null,
  "afgewezen_op": null,
  "uitbetaald_op": null,
  "verwerkt_in_run_id": null,
  "audit_trail_id": "aud_dec_01KCDE"
}
```

### Bonnetje

```json
{
  "id": "bon_01KCDF89",
  "declaratie_id": "dec_01KCDE567HIJ",
  "document_id": "doc_bon_01KCDE",
  "filename": "IMG_2026-05-12_2103.jpg",
  "mime_type": "application/pdf",
  "size_bytes": 318422,
  "scan_methode": "nextcloud_mobile_scan",
  "ocr_uitgevoerd_op": "2026-05-12T21:04:00+02:00",
  "ocr_provider": "docudesk_ocr_v3",
  "ocr_velden": {
    "datum": "2026-05-12",
    "leverancier": "Restaurant De Kas",
    "totaal_incl_btw": 87.50,
    "btw_bedrag": 7.21,
    "btw_tarief": 9.0
  },
  "hash_sha256": "f2c1a3...",
  "duplicate_check_resultaat": "geen_duplicate"
}
```

### KilometerRit

```json
{
  "id": "km_01KCDG12",
  "declaratie_id": "dec_01KCDE567HIJ",
  "datum": "2026-05-10",
  "vertrek_adres": "Lauriergracht 14h, Amsterdam",
  "aankomst_adres": "Hoofdstraat 22, Utrecht",
  "afstand_km": 47.3,
  "afstand_bron": "openconnector_geokeyed_v2",
  "zakelijk_doel": "Klantbezoek X",
  "passagiers": 0,
  "tarief_belastingvrij_per_km": 0.23,
  "tarief_belast_per_km": 0.00,
  "bedrag_belastingvrij": 10.88,
  "bedrag_belast": 0.00,
  "tracking_methode": "handmatig",
  "gps_log_id": null
}
```

### VasteVergoeding

```json
{
  "id": "vv_01KCDH34",
  "employee_id": "emp_01HXYW987DEF",
  "soort": "telewerkvergoeding",
  "bedrag_per_maand": 41.00,
  "wkr_classificatie": "gerichte_vrijstelling",
  "wkr_grondslag": "art_31a_telewerkvergoeding_2026",
  "ingangsdatum": "2026-01-01",
  "einddatum": null,
  "steekproef_laatste": "2025-09-15",
  "steekproef_volgende_vereist_voor": "2026-09-15",
  "onderbouwing_document_id": "doc_steek_01KCDH"
}
```

### ApprovalStap

```json
{
  "id": "app_01KCDJ56",
  "declaratie_id": "dec_01KCDE567HIJ",
  "stap_nummer": 1,
  "rol": "direct_leidinggevende",
  "approver_user_id": "u_88",
  "status": "wacht",
  "beslissing": null,
  "beslissing_op": null,
  "opmerking": null,
  "delegatie_van_user_id": null
}
```

### WKRBudget

```json
{
  "id": "wkr_2026",
  "kalenderjaar": 2026,
  "loonsom_grondslag": 845000.00,
  "vrije_ruimte_percentage_eerste_400k": 1.92,
  "vrije_ruimte_percentage_boven_400k": 1.18,
  "vrije_ruimte_beschikbaar": 13931.00,
  "vrije_ruimte_verbruikt_ytd": 4218.55,
  "vrije_ruimte_verbruikt_pct": 30.28,
  "waarschuwing_75pct_verzonden": false,
  "waarschuwing_100pct_verzonden": false,
  "eindheffing_verschuldigd": 0.00
}
```

## Requirements

### REQ-EXP-001 — Bewijsstuk-verplichting

The system SHALL elke `Declaratie` koppelen aan tenminste één `Bonnetje` met een geldige scan; declaraties zonder bewijsstuk SHALL alleen geaccepteerd worden onder een limiet van €10 met `geen_bewijsstuk_reden` (b.v. parkeerautomaat zonder bon) en SHALL door de approver expliciet als zodanig geaccordeerd worden.

- GIVEN een werknemer die een declaratie van €87.50 wil indienen zonder bonnetje
  WHEN hij op "indienen" klikt
  THEN het systeem blokkeert de indiening en toont "Bewijsstuk verplicht voor bedragen boven €10".

### REQ-EXP-002 — OCR-voorvulling met validatie

The system SHALL bij bonnetjes-upload via docudesk een OCR-call uitvoeren, de velden `datum`, `leverancier`, `totaal_incl_btw`, `btw_bedrag`, `btw_tarief` extraheren, het Declaratie-formulier voorvullen, en de OCR-confidence opslaan. De werknemer SHALL elk veld kunnen overschrijven met expliciete `gecorrigeerd_door_gebruiker`-vlag voor de audit.

### REQ-EXP-003 — Duplicate-detection

The system SHALL bij elke bonnetje-upload een SHA-256 hash berekenen en vergelijken tegen alle bonnetjes van dezelfde werknemer in de afgelopen 12 maanden; bij hash-match SHALL het systeem indiening blokkeren met verwijzing naar het bestaande declaratie-nummer.

### REQ-EXP-004 — WKR-classificatie verplicht

The system SHALL elke declaratie verplicht een `wkr_classificatie` laten kiezen uit (`intermediaire_kosten`, `gerichte_vrijstelling`, `nihil_waardering`, `vrije_ruimte`, `eindheffingsloon_80pct`) met een `wkr_grondslag` (vrij tekst of uit een gecureerde lijst). Default-classificatie SHALL per categorie worden voorgesteld op basis van de Belastingdienst-handreiking WKR.

### REQ-EXP-005 — Kilometervergoeding tariefen

The system SHALL de geldende belastingvrije kilometervergoeding-tarieven per kalenderjaar configureerbaar bijhouden (€0.23/km voor 2026, €0.21/km voor 2027 per Belastingplan-indexatie) en het belastingvrij deel exact volgens dat tarief berekenen; bedragen daarboven worden als belast loon naar payroll doorgegeven.

- GIVEN een werkgever die per rit €0.30/km vergoedt en het tarief 2026 is €0.23/km
  WHEN een werknemer een rit van 100 km declareert
  THEN het systeem boekt €23 belastingvrij en €7 belast loon (toegevoegd aan de salarisrun-grondslag).

### REQ-EXP-006 — Configureerbare approval-workflow

The system SHALL per business-unit een approval-workflow definieerbaar maken met regels op bedrag-drempels, categorieën, WKR-classificaties, en werknemer-rol. De workflow SHALL 1-3 stappen ondersteunen, met delegatie bij afwezigheid (geconfigureerd op user-niveau), en SHALL automatisch escaleren na een configureerbare termijn (default 5 werkdagen).

### REQ-EXP-007 — Routering naar shillinq AP vs payroll

The system SHALL goedgekeurde declaraties routeren op basis van WKR-classificatie: `intermediaire_kosten` + `gerichte_vrijstelling` + `nihil_waardering` → shillinq als AP-entry (crediteur = werknemer, grootboekrekening per categorie); `vrije_ruimte` boven het ingestelde quotum + `eindheffingsloon_80pct` → payroll als bijtelling op de salarisrun.

- GIVEN een goedgekeurde declaratie van €87.50 met classificatie `gerichte_vrijstelling`
  WHEN het overdrachtsjob loopt
  THEN een AP-entry verschijnt in shillinq met crediteur = werknemer-IBAN, bedrag €87.50, grootboekrekening 4310 (representatie), en in hrmq wordt `uitbetaald_op` gezet zodra shillinq de SEPA-batch heeft verstuurd.

### REQ-EXP-008 — WKR-budget tracking en waarschuwingen

The system SHALL per kalenderjaar een `WKRBudget` bijhouden met loonsom-grondslag uit payroll, het toepasselijke vrije-ruimte-percentage, en het lopende verbruik. Het systeem SHALL waarschuwingen versturen bij 75% en 100% verbruik aan finance + HR, en SHALL bij jaarafsluiting de eindheffing 80% over overschrijding berekenen en aan shillinq leveren.

### REQ-EXP-009 — Steekproef vaste vergoedingen

The system SHALL voor elke `VasteVergoeding` een steekproef-cyclus afdwingen (default: jaarlijks tenzij de Belastingdienst per regeling anders bepaalt) waarbij de werkgever een aantal werknemers laat aantonen welke werkelijke kosten zij maken, het resultaat in `onderbouwing_document_id` opslaat, en bij negatief steekproef-resultaat de vergoeding herclassificeert.

### REQ-EXP-010 — Audit-trail en exporteerbaarheid

The system SHALL voor elke declaratie een immutable audit-trail bijhouden (indiening, OCR-resultaat, alle correcties, alle approval-stappen, beslissingen, escalaties, routering, betaling, koppeling aan payroll-run of AP-batch) en SHALL deze exporteerbaar maken per werknemer, per periode, per categorie, en per WKR-classificatie ten behoeve van Belastingdienst-controle en interne audit.

### REQ-EXP-011 — Valuta-conversie voor buitenlandse declaraties

The system SHALL declaraties in vreemde valuta accepteren met originele valuta-code + bedrag, automatisch de ECB-referentiekoers van de uitgavedatum ophalen via openconnector, het EUR-equivalent berekenen en opslaan, en beide bedragen tonen op de approval-schermen + audit-trail. Bij koers-onbeschikbaarheid (b.v. exotische valuta) SHALL het systeem de werknemer vragen handmatig een koers in te voeren met verplichte bronvermelding.

- GIVEN een declaratie van 250 USD op datum 2026-04-15
  WHEN de werknemer indient
  THEN het systeem haalt de ECB-referentiekoers (b.v. 1.0892 EUR/USD) op, slaat €229.49 op als EUR-equivalent, en gebruikt dit bedrag voor de approval-drempel + WKR-classificatie; beide bedragen blijven zichtbaar voor de audit.

### REQ-EXP-012 — Mobile-first scan-tot-indienen-flow

The system SHALL via Nextcloud Mobile een scan-tot-indienen-flow ondersteunen die in maximaal vier user-taps (scan, categorie, zakelijk-doel, indienen) een declaratie kan voltooien voor leveranciers die eerder zijn geclaimd, door de leverancier-geschiedenis te raadplegen en de meest waarschijnlijke categorie + WKR-classificatie voor te stellen. Voor nieuwe leveranciers SHALL het systeem een suggestion-engine gebruiken die op basis van leverancier-naam + BTW-tarief een eerste categorie-voorstel doet.

## Standards & Sources

- **Wet op de Loonbelasting 1964** — m.n. art. 10 (loonbegrip), art. 11 (uitzonderingen), art. 31 + 31a (werkkostenregeling), art. 32a (eindheffing).
- **Uitvoeringsbesluit Loonbelasting 1965** + **Uitvoeringsregeling Loonbelasting 2011** — uitwerking gerichte vrijstellingen, nihil-waarderingen, intermediaire kosten.
- **Belastingplan 2026 / 2027** — geïndexeerd tarief onbelaste kilometervergoeding (€0.23 → €0.21), WKR-staffels.
- **Handreiking Werkkostenregeling (Belastingdienst, jaarlijks geactualiseerd)** — leidraad voor classificatie van categorieën.
- **Wet OB 1968** — BTW-tarieven (21% standaard, 9% verlaagd, 0%), BTW-aftrek werkgever.
- **Algemene Wet Rijksbelastingen (AWR) art. 52** — fiscale bewaarplicht 7 jaar (geldt voor bonnetjes en alle administratie).
- **AVG / GDPR** — m.n. art. 5 (data-minimalisatie: geen onnodige passagiers-NAW, geen onnodige tracking), art. 6 (grondslag), art. 25 (privacy by design — GPS-tracking alleen met consent + per-rit-bevestiging).
- **Wbp / AVG-handreiking AP "Werkkostenregeling en privacy"** — kaders voor data-verwerking bij onkostendeclaraties.
- **ISO 20022 pain.001** — SEPA-betalingsformaat voor uitbetaling via shillinq.
- **EN 16931** — Europese e-invoicing standaard (relevant voor toekomstige e-bon-acceptatie).
- **NEN 7510** — informatiebeveiliging zorgsector (relevant waar hrmq bij zorgwerkgevers wordt ingezet).
- **PEPPOL BIS Billing 3** — voor toekomstige e-facturatie-koppeling.
- **Vereniging Zakelijke Rijders + Vereniging Nederlandse Autoleasemaatschappijen (VNA)** — branche-standaarden voor zakelijke kilometeradministratie.

## Cross-app integration

- **shillinq** — primaire AP-bestemming voor declaraties die niet via payroll lopen; ontvangt geconfigureerde grootboekrekening per categorie + WKR-classificatie, crediteur = werknemer-IBAN, SEPA-batch. Houdt ook de WKR-eindheffing als boekstuk bij. AR-flow niet gebruikt.
- **payroll-engine-nl** — ontvangt belast deel kilometervergoeding boven het belastingvrije tarief, ontvangt vrije-ruimte-overschrijdingen, ontvangt vaste vergoedingen die als loon zijn geclassificeerd. Levert loonsom-grondslag aan voor `WKRBudget`-berekening.
- **docudesk** — verzorgt OCR van bonnetjes (categorie-specifieke modellen voor restaurant-bonnen, parkeerbonnen, tankbonnen, hotel-rekeningen), bewaart originele scans met de juiste retentie (7 jaar fiscaal).
- **openconnector** — geokeyed afstand-service voor kilometer-routeberekening (OpenStreetMap-routing of commerciële equivalent), optioneel banking-API voor name-on-account-validatie van de werknemer-IBAN voor uitbetaling.
- **Nextcloud Mobile** — bron-app voor de scan-flow: camera-scan → PDF → upload naar hrmq-declaratie-formulier; mobile-GPS voor optionele kilometer-tracking met per-rit-consent.
- **employee-master** — bron voor werknemer-IBAN, employer-BU-koppeling (voor approval-workflow-keuze), in-/uit-dienst-status (declaraties van uit-dienst-werknemers worden geblokkeerd tenzij gekoppeld aan een lopende offboarding).
- **offboarding-wizard** (peer hrmq spec) — bij uitdiensttreding worden alle openstaande declaraties geblokkeerd of afgewerkt voordat de offboarding kan worden afgesloten.
- **procest** — case-substraat; elke declaratie is een procest-case-kind met hrmq-overlay.

## Target users

- **Werknemer** — primaire indiener; gebruikt Nextcloud Mobile voor scan + indiening, web voor batch-indieningen en kilometeradministratie. Verwacht een paar-klikken-flow met zoveel mogelijk voorgevuld door OCR.
- **Direct leidinggevende** — eerste approval-stap voor de meeste declaraties; krijgt notificaties met een korte samenvatting + bonnetje-thumbnail, kan met één klik goedkeuren of met een opmerking afwijzen.
- **HR-officer** — tweede approval-stap voor categorieën zoals `opleidingen`, `representatie boven drempel`, of bij budget-overschrijdingen. Beheert ook de approval-workflow-configuratie per BU.
- **Finance / boekhouder** — derde approval-stap voor high-impact declaraties + bewaakt WKR-vrije-ruimte. Consumeert de doorlopende WKR-rapportage. In MKB vaak één persoon die ook shillinq beheert.
- **Salarisadministrateur (intern of extern)** — consumeert payroll-route declaraties; verwacht een schone batch voor elke salarisrun zonder handmatige reconciliatie.
- **Belastingdienst-controleur** (incidenteel) — krijgt via HR/finance een exporteerbaar dossier per kalenderjaar; verwacht volledigheid + WKR-onderbouwing + bewaring conform 7 jaar AWR.
- **Werknemer met lease-auto / zakelijke rijder** — gebruikt de bulk-import voor kilometer-administratie; verwacht naadloze koppeling met het lease-systeem en automatische splitsing zakelijk/woon-werk/privé.
- **Auditor / interne controller** — verifieert steekproefsgewijs bonnetjes, controleert dat duplicate-detection werkt, dat WKR-classificaties consistent zijn, en dat audit-trails volledig zijn.
- **BU-manager** — wil maandelijkse rapportages over onkosten-uitgaven per categorie + per medewerker als input voor kosten-management.
- **OR / personeelsvertegenwoordiging** — krijgt op geaggregeerd niveau inzicht in vergoedingenbeleid (kilometertarief, vaste vergoedingen, WKR-verbruik) zonder dat individuele declaraties zichtbaar zijn; relevant voor instemmingsplicht art. 27 WOR rond beloningsregelingen.
- **Externe accountant** — bij jaarrekening-controle vereist read-only toegang tot een afgebakende periode + complete export inclusief bonnetjes-scans; SHALL hiervoor een tijdgebonden, IP-beperkt access-token kunnen ontvangen dat na 90 dagen automatisch vervalt.
- **Werknemer op detachering of in het buitenland** — speciale doelgroep: declaraties in vreemde valuta, met gemengde fiscale jurisdicties (NL werkgever, BE/DE werkplek), wat consequenties heeft voor zowel de WKR-classificatie als de BTW-aftrek; verwacht dat het systeem hier transparant over communiceert in plaats van impliciete aannames te doen.

## Open vragen en risico's

Een aantal openstaande ontwerpkeuzes verdienen expliciete aandacht bij de uitwerking. Ten eerste: de **OCR-betrouwbaarheid** is een functie van de kwaliteit van de bron-scan, en in MKB-praktijk variëren bonnen sterk (kassabonnen verbleken, restaurant-rekeningen op gekreukt papier, hotelrekeningen met de daadwerkelijke totaalregel onder een hoop sub-totals). Een ondergrens van OCR-confidence waaronder een declaratie verplicht handmatige review krijgt (voorstel: 0.80) moet in de implementatie worden geijkt op echte productiebonnen, niet op syntheet test-data.

Ten tweede: de **WKR-classificatie-suggestion** moet vermijden om werknemers (of approvers) een fiscaal verkeerde keuze in de mond te leggen omdat de Belastingdienst hun handreiking jaarlijks bijwerkt en sommige edge cases (vergoeding mobiele telefoon, thuiswerkplek-inrichting) regelmatig herkwalificeren. De suggestion-engine moet daarom altijd de actuele handreiking-versie als grondslag tonen en bij twijfel `wacht_op_finance_review` als default kiezen in plaats van te gokken.

Ten derde: de **kilometer-tracking via GPS** is een AVG-gevoelig onderwerp. Passieve tracking is niet acceptabel; per-rit-bevestiging moet hard worden afgedwongen en de werknemer SHALL te allen tijde een rit als "privé" kunnen markeren waarna de GPS-trace direct uit de logs verdwijnt. Dit is een afwijking van wat sommige commerciële mileage-trackers doen en moet expliciet zo worden gespecificeerd om compliance-zekerheid te bieden.

Ten vierde: de **WKR-budget-overschrijding** in december is een veelvoorkomend MKB-scenario (jaar-end-borrels, kerstpakketten, jubilea). Het systeem moet niet pas op 31 december waarschuwen maar al rond september een prognose-rapportage produceren zodat de werkgever bewust kan kiezen tussen (a) WKR-keuzes terugschroeven, (b) categorieën verschuiven naar gerichte vrijstellingen waar dat fiscaal kan, of (c) bewust accepteren dat de 80%-eindheffing verschuldigd wordt. Een proactieve prognose-functie is daarmee een eerste-klas requirement, niet een rapportage-bijproduct.
