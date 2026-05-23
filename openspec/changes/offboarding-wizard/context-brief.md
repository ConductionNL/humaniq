---
status: draft
---
# Offboarding workflow (Offboarding case entity)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Medewerkers › Offboarding

**Rationale:** Wizard-lijst.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `offboarding-wizard` capability handles the legally fraught, financially loaded, and operationally messy process of an employee leaving the organisation — whether through opzegging door werknemer, opzegging door werkgever, einde tijdelijk contract zonder verlenging, ontslag op staande voet, pensionering, of overlijden. In Dutch MKB the offboarding process is the single biggest source of (a) administratieve fouten that surface months later as belastingnaheffingen of pensioenfonds-correcties, (b) AVG-overtredingen because nobody schedules data destruction, (c) financiële geschillen over the eindafrekening (verlofsaldo + vakantiegeld + 13e maand + transitievergoeding), and (d) IT security incidents because the leaver still has Nextcloud + e-mail + VPN access weeks after their laatste werkdag.

hrmq treats every departure as an `Offboarding` case object that begins on the day the einddatum is known (whether by werknemer's opzegbrief, by employer's beschikking, or by contract-einddatum reached) and ends only when (a) every artefact has been handed over to the employee, (b) every system access has been revoked, (c) the eindafrekening has been computed, approved, paid, and reconciled, (d) the AVG retentie-timers have started, and (e) the laatste salarisstrook + jaaropgaaf zijn verstuurd. The wizard provides a stepper across all of these, with hard gates where Dutch law requires them.

The hardest single computation in this capability is the **eindafrekening**, which combines:

- restsaldo wettelijk verlof (4× weekuren per jaar) en bovenwettelijk verlof, uitbetaald tegen het uurloon op de einddatum;
- vakantiegeld (8% van bruto-loon over de periode 1 juni vorig jaar tot einddatum, minus reeds uitbetaald);
- 13e maand pro rata (indien van toepassing, met de cao-specifieke berekening);
- transitievergoeding (Wet Werk en Zekerheid, herzien per 2020 met WAB en geactualiseerd in 2026 voor inflatie + maximum), berekend als 1/3 maandsalaris per gewerkt jaar vanaf dag 1, met pro-rata afronding op de dag, met cao-vervangingsmogelijkheid;
- eventuele inhoudingen (leningen, niet-geretourneerde apparatuur, te veel uitbetaald verlof);
- afdracht loonheffingen + werknemersverzekeringen volgens de bijzonder-tarief tabel (transitievergoeding) en wittetabel (loon).

The wizard explicitly does NOT compute the actual loonstrook — that is payroll-engine-nl's job. It computes the **input** to the final loonstrook (grondslagen + bijzondere componenten + inhoudingen), validates them, freezes them, and hands them to payroll. After payroll has run the final period, the offboarding case consumes the resulting strook + jaaropgaaf and attaches them to the dossier.

A second hard requirement is the **AVG retention scheduler**. On `Offboarding.afgerond_op` the system SHALL start three independent retention timers per artefact category: 7 jaar fiscaal (alle loonadministratie, salarisstroken, jaaropgaven, beslagleggingen, declaraties), 5 jaar arbeid (WID-kopie, contract, beoordelingsverslagen, ziekteverzuim-dossier voor zover niet medisch), 2 jaar sollicitatie (indien rehire-traject, anders al vernietigd), en korter (12-24 maanden) voor functioneringsdossier waar geen wettelijke grondslag voor langer bewaren bestaat. Op timer-einde wordt cryptografisch vernietigd met audit-spoor.

Scope explicitly excludes: arbeidsconflict-bemiddeling (verwijst naar externe partij), de juridische beschikking zelf bij ontslag (uitsluitend de administratieve verwerking), pensioenkapitaal-overdracht (separate flow door pensioenfonds zelf), and outplacement (verwijzing naar externe partij; alleen registratie of het is aangeboden ja/nee voor transitievergoeding-aftrek).

Een derde load-bearing concern is het **knowledge-transfer-aspect**, dat in MKB de stille kostenpost van offboarding is. Wanneer een ervaren werknemer vertrekt, verdwijnt zonder gericht ingrijpen tacit knowledge: welke klant heeft welke voorkeuren, welk leverancier-contract loopt wanneer af, welke processtap is anders dan documentatie suggereert. hrmq biedt daarvoor een gestructureerde manager-handover-checklist met velden voor (a) lopende projecten + overdracht-ontvanger per project, (b) actieve klantcontacten + relatie-overdracht-status, (c) toegangen tot externe systemen die heroverdracht behoeven, (d) impliciete kennis die in een korte memo of opname moet worden vastgelegd, en (e) sleutel-vergaderingen waar de vertrekker een opvolger introduceert. Deze checklist is geen wettelijke verplichting maar wordt evengoed door de wizard hard afgedwongen omdat de continuïteit van het bedrijf het rechtvaardigt.

Een vierde aspect is de **goodbye-communicatie**: het systeem ondersteunt het opstellen van een aankondigingsbericht aan het team (template + free-text) dat via Nextcloud Talk of e-mail wordt verstuurd, met optionele instelling om externe contacten te informeren over de wisseling en de opvolger. Dit lijkt cosmetisch maar voorkomt het herhalend incident waar klanten weken na vertrek nog steeds proberen de vertrokken werknemer te bereiken — een AVG-incident waiting to happen als de mailbox stilzwijgend wordt geforward zonder dat de externe partij dit weet.

## Data Model (entities + Dutch JSON examples)

### Offboarding (case)

```json
{
  "id": "off_01JBCD234EFG",
  "employee_id": "emp_01HXYW987DEF",
  "status": "eindafrekening_berekend",
  "reden": "opzegging_werknemer",
  "reden_toelichting": "Andere baan per 1 augustus",
  "aanzeggingsdatum": "2026-06-15",
  "einddatum": "2026-07-31",
  "laatste_werkdag": "2026-07-29",
  "vrijstelling_werk": true,
  "vrijstelling_werk_vanaf": "2026-07-15",
  "transitievergoeding_van_toepassing": true,
  "transitievergoeding_bedrag_bruto": 4823.17,
  "transitievergoeding_grondslag": "wet_wwz_2026",
  "outplacement_aangeboden": false,
  "outplacement_aftrek_bedrag": 0.00,
  "ww_melding_uwv_vereist": true,
  "ww_melding_uwv_status": "verzonden",
  "data_export_aan_werknemer_status": "voltooid",
  "afgerond_op": null,
  "retentie_timers_gestart": false,
  "hr_owner_user_id": "u_12",
  "manager_user_id": "u_88",
  "it_owner_user_id": "u_99",
  "created_at": "2026-06-16T08:00:00+02:00",
  "updated_at": "2026-07-20T14:11:00+02:00"
}
```

### OffboardingStep

Same shape as OnboardingStep; fixed `step_key` enum: `opzegging_geregistreerd`, `exit_interview`, `equipment_inventarisatie`, `equipment_geretourneerd`, `eindafrekening_berekenen`, `eindafrekening_goedkeuren`, `eindafrekening_uitbetalen`, `getuigschrift_opstellen`, `uwv_ww_melding`, `pensioenfonds_afmelding`, `zvw_afmelding`, `it_accounts_deactiveren`, `data_export_werknemer`, `manager_handover`, `goodbye_message`, `retentie_timers_starten`.

### Eindafrekening

```json
{
  "id": "eind_01JBCE45",
  "offboarding_id": "off_01JBCD234EFG",
  "referentiedatum": "2026-07-31",
  "uurloon_op_einddatum": 28.45,
  "componenten": {
    "verlofuren_wettelijk": {"saldo_uren": 38.0, "tarief": 28.45, "bedrag": 1081.10},
    "verlofuren_bovenwettelijk": {"saldo_uren": 12.5, "tarief": 28.45, "bedrag": 355.63},
    "vakantiegeld_resterend": {"grondslag": 18420.00, "percentage": 8.0, "reeds_uitbetaald": 0.00, "bedrag": 1473.60},
    "dertiende_maand_pro_rata": {"breukteller": 213, "breuknoemer": 365, "vol_bedrag": 3950.00, "bedrag": 2304.66},
    "transitievergoeding": {"dienstjaren": 4.583, "maandsalaris": 3950.00, "bedrag_bruto": 4823.17, "tabel": "bijzonder_tarief"},
    "inhouding_lening": {"openstaand": 0.00, "bedrag": 0.00},
    "inhouding_te_veel_verlof": {"uren": 0.0, "bedrag": 0.00},
    "inhouding_apparatuur": {"omschrijving": "n.v.t.", "bedrag": 0.00}
  },
  "totaal_bruto": 10037.16,
  "totaal_inhoudingen": 0.00,
  "goedgekeurd_door_user_id": "u_12",
  "goedgekeurd_op": "2026-07-22T11:00:00+02:00",
  "bevroren": true,
  "doorgegeven_aan_payroll_op": "2026-07-22T11:05:00+02:00",
  "payroll_run_id": "run_2026_07_def"
}
```

### EquipmentReturn

```json
{
  "id": "eq_01JBCF67",
  "offboarding_id": "off_01JBCD234EFG",
  "categorie": "laptop",
  "asset_tag": "L-2023-0142",
  "uitgegeven_op": "2023-03-15",
  "verwacht_geretourneerd_op": "2026-07-29",
  "geretourneerd_op": "2026-07-29",
  "ontvangen_door_user_id": "u_99",
  "staat": "goed",
  "opmerking": "Inclusief lader; toetsenbord licht versleten",
  "inhouding_indien_niet_geretourneerd": 850.00
}
```

### ExitInterview

```json
{
  "id": "exit_01JBCG89",
  "offboarding_id": "off_01JBCD234EFG",
  "afgenomen_door_user_id": "u_12",
  "afgenomen_op": "2026-07-25T14:00:00+02:00",
  "modaliteit": "in_persoon",
  "antwoorden": {
    "tevredenheid_werk_1_10": 7,
    "tevredenheid_leidinggevende_1_10": 8,
    "redenen_vertrek": ["carriere_groei", "salaris"],
    "zou_aanbevelen": true,
    "open_feedback": "Goede sfeer, maar weinig doorgroei in mijn functie."
  },
  "anonimiteit": "geanonimiseerd_na_90_dagen"
}
```

### Getuigschrift

```json
{
  "id": "get_01JBCH12",
  "offboarding_id": "off_01JBCD234EFG",
  "template_id": "template_getuigschrift_v2",
  "opgesteld_door_user_id": "u_12",
  "ondertekend_door_user_id": "u_88",
  "document_id": "doc_get_01JBCH",
  "verstrekt_op": "2026-07-29",
  "type": "feitelijk",
  "bevat_kwalitatief_oordeel": false
}
```

### RetentionTimer

```json
{
  "id": "ret_01JBCJ34",
  "offboarding_id": "off_01JBCD234EFG",
  "artefact_type": "wid_kopie",
  "artefact_referentie": "doc_id_01HXZ",
  "gestart_op": "2026-07-31",
  "vervalt_op": "2031-07-31",
  "grondslag": "art_28_uitvoeringsregeling_lb",
  "vernietigd_op": null,
  "vernietigingsmethode": null
}
```

## Requirements

### REQ-OFF-001 — Reden en grondslag

The system SHALL require selection of a `reden` from the fixed enum (`opzegging_werknemer`, `opzegging_werkgever_met_vergunning`, `opzegging_werkgever_ontbinding_kantonrechter`, `vaststellingsovereenkomst`, `einde_tijdelijk_contract`, `ontslag_op_staande_voet`, `wederzijds_goedvinden`, `pensionering`, `overlijden`, `proeftijd_beëindigd`) and SHALL compute downstream toepasselijkheid (transitievergoeding ja/nee, UWV WW-melding ja/nee, bijzondere fiscale behandeling) deterministisch op basis van deze reden.

- GIVEN reden = `ontslag_op_staande_voet`
  WHEN de gebruiker de case aanmaakt
  THEN `transitievergoeding_van_toepassing` is automatisch `false` met grondslag `art_7:673_lid_7_bw`, en `ww_melding_uwv_vereist` is `false`.

### REQ-OFF-002 — Transitievergoeding-berekening

The system SHALL compute the transitievergoeding per de geldende Wet WWZ-formule (1/3 maandsalaris per gewerkt jaar vanaf dag 1, pro rata in dagen, met het wettelijke maximum geïndexeerd per kalenderjaar) en SHALL alle componenten van het maandsalaris meenemen die volgens artikel 7:673 BW + Besluit loonbegrip vergoeding aanzegtermijn en transitievergoeding tot het loon behoren (vast brutoloon + vakantiegeld + vaste ploegentoeslag + vaste eindejaarsuitkering + structurele overwerkvergoeding gemiddeld over 12 maanden + structurele bonus gemiddeld over 36 maanden). De berekening SHALL volledig auditeerbaar zijn (alle inputs + de exacte formule per maand zichtbaar).

- GIVEN een werknemer met 4 jaar en 7 maanden dienstverband en een maandsalaris (incl. componenten) van €3950
  WHEN de eindafrekening wordt berekend
  THEN de transitievergoeding is `(4 + 7/12) × (1/3) × 3950 = 6033.33`, afgerond op cent, en alle 55 maanden met hun bijbehorende grondslag staan in de audit-tabel.

### REQ-OFF-003 — Verlof- en vakantiegeld-uitbetaling

The system SHALL bereken het verlofsaldo op de einddatum, met onderscheid tussen wettelijke (vervalt 6 maanden na opbouwjaar) en bovenwettelijke uren (vervalt 5 jaar na opbouwjaar), en SHALL het saldo uitbetalen tegen het bruto-uurloon op de einddatum. Het vakantiegeld over de lopende vakantiegeld-periode (1 juni tot einddatum) SHALL pro rata worden uitgekeerd, minus reeds uitbetaald vakantiegeld in die periode.

### REQ-OFF-004 — Eindafrekening freeze en payroll-overdracht

The system SHALL elke `Eindafrekening` na goedkeuring door rol `hr_admin` bevriezen (immutable) en doorgeven aan payroll-engine-nl. Een bevroren eindafrekening SHALL alleen wijzigbaar zijn door deze in te trekken (`ingetrokken_op` + reden), waarna een nieuwe versie wordt aangemaakt; intrekken na uitbetaling is alleen toegestaan met expliciete `correctie_naheffing`-vlag.

- GIVEN een goedgekeurde eindafrekening die naar payroll is gestuurd
  WHEN HR een verlofsaldo-correctie ontdekt
  THEN de oude eindafrekening wordt ingetrokken met reden, een nieuwe versie wordt aangemaakt, payroll krijgt een correctie-bericht, en het verschil verschijnt op de eerstvolgende salarisstrook of wordt nageboekt.

### REQ-OFF-005 — IT-account deactivering met data-export

The system SHALL bij overgang naar `it_accounts_deactiveren` een volledige Nextcloud-data-export (alle persoonlijke bestanden, agenda, contacten, gepersonaliseerde Talk-historie waar van toepassing) aan de werknemer aanbieden op een door werknemer aangewezen kanaal (download-link met expiry 14 dagen, of postzending op USB-stick), en pas daarna het account `disabled` zetten. Het account SHALL pas na 30 kalenderdagen volledig worden verwijderd, zodat e-mail-bounces en delegatie-overdracht mogelijk blijven; tussentijds blijft het account disabled met mail-forwarding naar de manager.

### REQ-OFF-006 — UWV WW-melding

The system SHALL waar `reden` in (`opzegging_werkgever_met_vergunning`, `opzegging_werkgever_ontbinding_kantonrechter`, `vaststellingsovereenkomst`, `einde_tijdelijk_contract`) automatisch een UWV WW-aanmeldbericht voorbereiden met de relevante gegevens (reden, einddatum, laatstgenoten loon, beëindigingsovereenkomst-PDF waar van toepassing) en via openconnector aanleveren binnen de wettelijke termijn (uiterlijk dag van uitdiensttreding).

### REQ-OFF-007 — Pensioenfonds + ZVW-afmelding

The system SHALL het pensioenfonds-afmeldbericht (per-fund mapping) en de ZVW-afmelding binnen de wettelijke termijnen aanleveren via openconnector en SHALL de bevestigingen in het dossier vastleggen; afwezigheid van bevestiging na 14 dagen SHALL escaleren naar de HR-owner.

### REQ-OFF-008 — Getuigschrift

The system SHALL op verzoek van de werknemer een getuigschrift genereren volgens art. 7:656 BW met minimaal: aard van de werkzaamheden, duur van het dienstverband, wijze waarop werkzaamheden zijn verricht (alleen indien werknemer dit verzoekt en alleen feitelijke beschrijving zonder kwalitatief oordeel tenzij werknemer expliciet om kwalitatief getuigschrift vraagt), en datum + reden van vertrek (alleen op verzoek werknemer). Templates SHALL via docudesk worden gerenderd en ondertekend door de werkgever.

### REQ-OFF-009 — Retentie-timers en cryptografische vernietiging

The system SHALL op `afgerond_op` voor elk artefact in het dossier een `RetentionTimer` aanmaken met de juiste grondslag-termijn (7 jaar fiscaal, 5 jaar arbeid, 2 jaar sollicitatie, andere wettelijke termijnen waar van toepassing). Bij timer-einde SHALL het artefact cryptografisch worden vernietigd (sleutel-vernietiging waar versleuteld; overschrijven waar plain) met audit-log entry. De timers SHALL queryable zijn door auditor + AVG-functionaris.

- GIVEN een offboarding voltooid op 2026-07-31 met een WID-kopie
  WHEN 2031-07-31 wordt bereikt
  THEN de retention-job vernietigt de WID-kopie, schrijft `vernietigd_op = 2031-07-31`, `vernietigingsmethode = "key_destruction"`, en de audit-log heeft een immutable entry.

### REQ-OFF-010 — Audit en AVG-inzage

The system SHALL het volledige offboarding-dossier (alle stappen, alle wijzigingen, alle berichten, alle berekeningen, alle data-exports, alle retentie-acties) exporteren tot een doorzoekbaar PDF voor inzageverzoeken binnen 4 weken na verzoek (art. 12.3 AVG), met de mogelijkheid om derde-betrokkenen (collega's, leidinggevende) automatisch te pseudonimiseren.

### REQ-OFF-011 — Manager-handover-checklist

The system SHALL bij elke offboarding een verplichte manager-handover-checklist activeren met categorieën (lopende projecten, actieve klantcontacten, externe systeem-toegangen, sleutel-vergaderingen, tacit-knowledge memo's) en SHALL elke open positie expliciet laten dichtzetten met een overdracht-ontvanger of een gemotiveerde "geen overdracht nodig" reden. De checklist SHALL exporteerbaar zijn naar een opvolger zonder de persoonlijke offboarding-gegevens van de vertrekker mee te tonen.

- GIVEN een offboarding met 8 lopende projecten in de manager-handover
  WHEN de manager de offboarding wil afronden zonder elk project een ontvanger te geven
  THEN het systeem blokkeert de afronding en toont een lijst van openstaande overdrachten.

### REQ-OFF-012 — Doorlopende mail-forwarding en autoresponder

The system SHALL bij IT-deactivering automatisch een autoresponder configureren op het e-mailaccount van de vertrekker (configureerbare tekst, default Nederlands + Engels) en een mail-forwarding naar de manager of een functioneel adres instellen voor een configureerbare periode (default 90 dagen) waarna de mailbox volledig wordt gesloten. Externe afzenders SHALL in de autoresponder vermeld krijgen wie de nieuwe contactpersoon is, ter voorkoming van stilzwijgend doorsturen van persoonsgegevens.

## Standards & Sources

- **Burgerlijk Wetboek Boek 7 titel 10 (arbeidsovereenkomst)** — m.n. art. 7:611, 7:625, 7:628, 7:639 (verlof), 7:641 (verlofsaldo uitbetalen), 7:656 (getuigschrift), 7:670 ev (ontslag), 7:673 ev (transitievergoeding).
- **Wet Werk en Zekerheid (WWZ) 2015 + Wet Arbeidsmarkt in Balans (WAB) 2020 + indexatie 2026** — transitievergoeding-formule, max-bedrag indexatie.
- **Besluit loonbegrip vergoeding aanzegtermijn en transitievergoeding** — welke componenten tot het maandsalaris behoren.
- **Burgerlijk Wetboek art. 7:634 ev** — wettelijke vakantiedagen, vervaltermijn 6 maanden voor wettelijk, 5 jaar voor bovenwettelijk.
- **Wet Minimumloon en Minimumvakantiebijslag (WML)** — 8% vakantiegeld minimum.
- **Werkloosheidswet (WW)** — meldingsplicht UWV, fictieve opzegtermijn.
- **Wet op de Loonbelasting 1964 + Uitvoeringsregeling Loonbelasting art. 28** — bewaartermijn 7 jaar loonadministratie, 5 jaar WID-kopie.
- **Algemene Wet Rijksbelastingen (AWR) art. 52** — algemene fiscale bewaartermijn 7 jaar.
- **AVG / GDPR** art. 5 (storage limitation), 17 (recht op vergetelheid), 12.3 (responstermijn 4 weken).
- **Pensioenwet** — afmeldingsplicht werkgever, opbouw stopt op einddatum.
- **Zorgverzekeringswet (ZVW)** — afmelding loonheffingen.
- **NIST SP 800-88** Rev. 1 — Guidelines for Media Sanitization (cryptographic erase als acceptabele methode).
- **ISO/IEC 27001 + NEN 7510** — informatiebeveiliging, sleutelbeheer voor cryptographic erase.
- **eIDAS** — handtekening op getuigschrift en eindafrekening-akkoord.

## Cross-app integration

- **employee-master** — bron van waarheid voor BSN, IBAN, dienstverband-historie, salarisgrondslagen; offboarding leest deze en schrijft `uit_dienst_per`, `reden_uit_dienst`, `laatste_werkdag` terug.
- **contract-management** — leest het lopende contract om opzegtermijn en eventuele non-concurrentie/relatiebeding op te halen voor het offboarding-overzicht.
- **payroll-engine-nl** — consumeert de bevroren `Eindafrekening`; rapporteert terug welke salarisrun de uitbetaling bevat; levert na uitbetaling de laatste salarisstrook + jaaropgaaf retour aan het offboarding-dossier.
- **docudesk** — rendert getuigschrift-template, vaststellingsovereenkomst-template, beëindigingsovereenkomst; verzorgt e-sign-envelope; bewaart eindversies met LTA.
- **openconnector** — outbound naar UWV WW-meldingen, pensioenfonds-afmeldingen, ZVW-afmeldingen, optioneel BKR-afmelding waar werkgever krediet had verstrekt.
- **Nextcloud user management** — disable user via OCS Users API, set redirect/forward op e-mail, data-export via Files-app User-export module, full delete na 30 dagen.
- **shillinq** — ontvangt AP-entry voor transitievergoeding (boeking 4xxx personeelskosten + 1xxx crediteur werknemer of direct via salaris-rekening), en voor eventuele inhouding-correcties.
- **procest** — case-substraat; `Offboarding` is een procest case kind met hrmq-overlay.
- **expense-reimbursement** (peer hrmq spec) — alle openstaande declaraties van de vertrekkende werknemer worden in de eindafrekening meegenomen of expliciet afgewezen voor `afgerond_op` gezet kan worden.

## Target users

- **HR-officer / personeelszaken** — primary owner van het case; voert exit-interview, valideert berekening, regelt getuigschrift. Behoefte aan zo veel mogelijk geautomatiseerde berekening met duidelijke override-mogelijkheid.
- **HR-admin** — geautoriseerd voor het bevriezen + goedkeuren van eindafrekeningen en voor het intrekken na uitbetaling (correcties). Meestal de hoofd-HR of een gespecialiseerde loonadministrateur.
- **Lijnmanager** — verantwoordelijk voor manager-handover-checklist (kennisoverdracht, klantcontact-overdracht, projectoverdracht), voor het exit-interview waar HR dit delegeert, voor het accorderen van outplacement-aanbod. Vaak ook degene die equipment-uitgifte fysiek terugneemt.
- **IT-beheerder** — voert account-deactivering en data-export uit; in MKB vaak één persoon of MSP. Wil een wachtrij met "vandaag uit dienst" zonder dat individuele HR-mailtjes nodig zijn.
- **Vertrekkende werknemer** — ontvangt eindafrekening-overzicht, getuigschrift, data-export, jaaropgaaf. Geen account in hrmq nodig; secure links met expiry.
- **Payroll-officer** — consumeert de bevroren eindafrekening; in MKB vaak extern via salaris-bureau.
- **Auditor / AVG-functionaris** — read-only toegang voor controle op naleving bewaartermijnen, juistheid transitievergoeding-berekening, volledigheid dossier.
- **Boekhouder / controller** — krijgt via shillinq de AP-boekingen; gebruikt offboarding-rapportages voor jaarverslag (personeelsverloop) en voor verlof-verplichting-balanspost.
- **OR / vakbond** (indien aanwezig) — kan via inzage in geaggregeerde rapportages het beleid rond uitstroom volgen zonder individuele dossiers in te zien.
