---
status: proposed
app: hrmq
spec: cao-onderwijs-vo
owner: hrmq-cao
depends_on: [payroll-engine-nl, lerarenregister-koppeling, hrmq-base]
target_users: [vo-school-hr, vo-bestuurder, docent, schooladministratie]
---

# CAO Voortgezet Onderwijs

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › CAO's & regelingen

**Rationale:** CAO-ruleset.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The CAO VO covers approximately 110.000 docenten and 30.000 OOP (onderwijs-ondersteunend personeel) across roughly 650 Dutch VO-scholen. It is one of the most arithmetically complex CAOs in the country: schaal LB / LC / LD with their own periodiek-tabellen (16-20 trappen each), bekostiging from OCW based on leerlingenaantal (not employee count), strict cap of 750 lesuren per fte per jaar with overschrijdingstoeslag, vakvolledigheids-percentages that scale loon by subject-area scarcity (wiskunde / nask / Duits get a markup), the Vervangingsfonds for ziekteverzuim-pooling, and ABP-OW (Onderwijs- en Wetenschap) pensioen with its own franchise and premiepercentage.

De markt voor onderwijs-payroll wordt vandaag gedomineerd door drie incumbents (RAET / Visma, AFAS-Onderwijs, en de in-house systemen van grote besturen). De prijs per docent per maand ligt tussen EUR 4 en EUR 8, en de aansluiting tussen payroll en de andere onderwijs-administratie (Magister, Somtoday, ParnasSys) gebeurt via brittle CSV-imports. Kleinere besturen (<10 vestigingen) klagen al jaren over de hoge kosten en stugge contracten, en de PO-Raad/VO-raad benchmarken regelmatig dat HR-administratiekosten bij kleinere besturen 1,5-2x zo hoog zijn als bij grote. Een open-source alternatief met goede CAO-VO support en directe Lerarenregister/DUO-integratie heeft een duidelijke productmarktfit.

HRMQ-base implements the generic Loonheffingen-engine but cannot encode CAO-specific schaal-tabellen, lesuur-caps, or vakvolledigheids-toeslagen. This spec adds the CAO-VO module: a versioned set of schaal-tabellen, taakomvang-rules, vervanging-handling, and bekostigings-allocatie. The module is loaded per school-organisation (`organisation.cao = "vo"`) and activates the VO-specific employee fields, contract-types, and payroll-overrides. Because CAO-VO is renegotiated roughly every 2 years, the module's schaal-tabellen are versioned with `geldig_vanaf` / `geldig_tot` and a payroll-run always uses the table valid on the run-date. Wijzigingen mid-jaar (b.v. een tussentijdse 3%-loonsverhoging per 1 januari boven op de bestaande tabel) worden gemodelleerd als een nieuwe tabel-versie, niet als employee-level overrides — dit voorkomt dat correcties achteraf op de verkeerde basis worden uitgevoerd.

The module also integrates with the Lerarenregister (verplicht voor bevoegde docenten) and the DUO-leveringen voor bekostiging — the latter via a thin openconnector adapter that posts the kwartaalleveringen. Beide integraties zijn niet optioneel: zonder bevoegdheid-validatie kan een school niet onderbouwen waarom een docent op schaal LC zit (Inspectie-controles), en zonder DUO-aansluiting komt de bekostiging niet binnen.

Een derde gebruikers-perspectief is de docent zelf. Vergeleken met andere sectoren is onderwijspersoneel relatief actief op CAO-naleving — vakbond AOb monitort actief BAPO-toepassing, lesuren-caps, en periodieke verhogingen. De docent-self-service moet daarom volledig inzicht geven in deze CAO-elementen, met heldere uitleg en een audit-trail bij wijzigingen.

## Data Model

`hrmq_cao_vo_schaal_table` (versioned): `geldig_vanaf`, `geldig_tot`, `schaal` (enum LA/LB/LC/LD/LE), `trede` (1..20), `bruto_maandloon_fulltime`, `bruto_jaarloon_fulltime`. `hrmq_cao_vo_arbeidsmarkttoelage_table` (versioned): `geldig_vanaf`, `geldig_tot`, `vakgebied`, `toelage_pct`. `hrmq_cao_vo_employee_extension` (1:1 with employee): `schaal`, `trede`, `laatste_periodiek_datum`, `vakgebieden[]`, `bevoegdheid` (eerstegraads / tweedegraads / onbevoegd), `lerarenregister_id`, `lerarenregister_geldig_tot`, `taakomvang_lesuren_per_jaar`, `taakomvang_overige_uren`, `vakvolledigheid_pct`, `bapo_regeling` (enum geen/60+/57+), `bapo_omvang_uren`. `hrmq_cao_vo_school` (1:1 with organisation): `brin_nummer`, `vestigingsnummer`, `bestuursnummer`, `leerlingen_teldatum`, `leerlingen_aantal_per_onderwijssoort` (jsonb), `bekostigingsbedrag_per_kwartaal`. `hrmq_cao_vo_taakverdeling` per (employee, schooljaar): `lesuren_per_vak` (jsonb), `taakuren_per_taak` (jsonb), `overschrijdingstoeslag_uren`, `goedkeuring_docent_at`. `hrmq_cao_vo_vervanging` for ziekteverzuim-claims tegen het Vervangingsfonds met `claim_status`, `claim_bedrag`, `vfpf_referentie`. `hrmq_cao_vo_duo_bekostiging` per (school, kwartaal): `verwacht_bedrag`, `ontvangen_bedrag`, `verschil`, `verschil_reden`.

## Requirements

### REQ-001: Schaal-tabel versioning

**GIVEN** the CAO VO is renegotiated and a new schaal-tabel takes effect on 1 augustus 2026
**WHEN** an HR-admin imports the new tabel via the CAO-import UI (XLSX or JSON)
**THEN** the system stores the new tabel with `geldig_vanaf = 2026-08-01` and `geldig_tot = NULL`, automatically sets the previous tabel's `geldig_tot = 2026-07-31`, and ensures every payroll-run after that date uses the new tabel without manual employee-level updates. Employees retain their schaal+trede; the new bruto-bedragen flow automatically.

### REQ-002: Periodieke verhoging

**GIVEN** a docent at schaal LB trede 8 with `laatste_periodiek_datum = 2025-08-01`
**WHEN** the payroll-run executes on 2026-08-01
**THEN** the system automatically advances the trede to 9 (max trede LB = 12, after which the periodiek stops), updates `laatste_periodiek_datum`, recalculates the bruto-loon from the current schaal-tabel, and emits a `PeriodiekToegekend` event. If the docent is on a "uitloopschaal" or trede-stop is administratively imposed, the periodiek is skipped with a recorded reden.

### REQ-003: Bevoegdheid-gate voor schaal LC/LD

**GIVEN** a docent currently in schaal LB
**WHEN** HR attempts to promote to schaal LC
**THEN** the system validates that the docent has `bevoegdheid = eerstegraads` OR a recorded `lc_lc_traject_voltooid = true`, and validates against the Lerarenregister that the bevoegdheid is current. Promotion without bevoegdheid is blocked with a clear error; with bevoegdheid the promotion is logged with effective date and triggers a new contract-addendum via `document-template-engine`.

### REQ-004: Lesuren-cap 750 per fte

**GIVEN** a docent with `aanstelling = 1.0 fte` and a taaktoedeling for schooljaar 2026-2027
**WHEN** the schooladministratie enters the lesuren-toedeling
**THEN** the system flags any toedeling >750 lesuren as overschrijding (CAO art. 7.1), requires explicit goedkeuring by the docent (formeel akkoord opgeslagen), and triggers an overschrijdingstoeslag berekend pro-rato per uur boven 750. For deeltijders the cap scales proportioneel (0.6 fte → max 450 lesuren).

### REQ-005: Vakvolledigheids-toeslag voor schaarste-vakken

**GIVEN** the CAO-tabel defines vakvolledigheids-percentages voor schaarste-vakken (wiskunde, natuurkunde, scheikunde, Duits, Frans, informatica) and a docent teaches one or more of these
**WHEN** the payroll-run computes the maand-bruto
**THEN** an arbeidsmarkttoelage is added als percentage van het bruto-loon (per CAO bijlage), pro-rato het aandeel lesuren in het schaarste-vak. The toelage is shown as a separate regel op de loonstrook ("arbeidsmarkttoelage wiskunde") and is pensioengevend en SV-loon.

### REQ-006: Vervangingsfonds-claim bij ziekteverzuim

**GIVEN** a docent meldt zich ziek voor >2 dagen
**WHEN** HR registreert de ziekmelding in HRMQ
**THEN** the system generates a Vervangingsfonds-claim met de relevante gegevens (BSN, schaal, trede, periode, fte), verstuurt deze via de Vervangingsfonds-API (openconnector), en volgt de claim-status (`ingediend`, `goedgekeurd`, `uitbetaald`, `afgewezen`). Bij uitbetaling wordt de ontvangst geboekt op de bekostigings-grootboekrekening van de school.

### REQ-007: ABP-OW pensioen-aansluiting

**GIVEN** a docent met een vast contract aan een VO-school
**WHEN** een nieuwe in-diensttreding wordt afgerond
**THEN** the system meldt de werknemer aan bij ABP-sector OW via de standaard UPA-levering (openconnector → ABP), berekent de pensioenpremie met de OW-specifieke franchise (2026: EUR 18.275) en premie (27,9% werkgever + werknemer-aandeel), en houdt het werknemer-deel in op de loonstrook. Bij uitdiensttreding wordt een afmelding gestuurd binnen 5 werkdagen.

### REQ-008: Bekostiging-leverancier (DUO)

**GIVEN** een school met een geldig BRIN-nummer en een vastgestelde leerlingen-teldatum
**WHEN** de kwartaalleverancier-cycle draait (1 januari, 1 april, 1 juli, 1 oktober)
**THEN** the system stelt een DUO-bekostigingsverzoek samen met leerling-aantallen per onderwijssoort (vmbo-b/k/g/t, havo, vwo), verstuurt dit via de DUO-zakelijk-API, en boekt het ontvangen bedrag op de bekostigings-grootboekrekening. Discrepanties tussen verwacht en ontvangen bedrag worden in een werklijst voor de schooladministratie geplaatst.

### REQ-009: BAPO / Seniorenregeling

**GIVEN** een docent van 57+ met een vast contract
**WHEN** de docent kiest voor de seniorenregeling (170 uur per jaar minder werken tegen gedeeltelijke salaris-inlevering)
**THEN** the system reduces the lesuren-cap proportioneel, applies the salaris-korting per CAO-tabel (afhankelijk van leeftijd en omvang regeling), en past de pensioen-opbouw aan (volledige opbouw blijft, conform OBP/BAPO-regeling). De keuze is jaarlijks herzienbaar met de juli-cyclus.

### REQ-010: Jaaropgaaf en IB47

**GIVEN** een kalenderjaar is afgesloten met alle 12 loonstroken finaal
**WHEN** de jaarrun draait op 5 januari
**THEN** the system genereert per docent een jaaropgaaf (zelfde format als REQ-005 in zzp-dga-mode maar met OW-pensioen-specifieke velden), publiceert deze in de docent-self-service, en stelt een IB47-levering samen voor de Belastingdienst (gastdocenten, vakantiekrachten, examinatoren) met de aggregated bedragen per BSN.

## Standards

- CAO VO 2024-2026 (en latere) tekst en bijlagen
- Wet op het voortgezet onderwijs 2020
- Lerarenregister: Wet beroep leraar (per 2017, art. 38a-38g)
- Vervangingsfonds: VfPf-reglementen
- ABP-OW pensioenreglement
- DUO-bekostiging: Beleidsregels bekostiging VO 2026
- UPA (Uniforme Pensioen Aangifte) XSD

## Cross-app

- `payroll-engine-nl` voor de basis loonheffing-berekening
- `lerarenregister-koppeling` voor bevoegdheid-validatie
- `bank-payment-batch-sepa` voor maandelijkse salaris-overboekingen
- `document-template-engine` voor contract-addenda, BAPO-bevestigingen, jaaropgaaf
- `docudesk` voor archivering van CAO-tabellen en HR-documenten
- `openconnector` adapters voor DUO, ABP, Vervangingsfonds

## Target Users

VO-scholen (zelfstandig + onder bestuur), schoolbesturen met 5-50 vestigingen, schooladministrateurs die de kwartaal-bekostiging en payroll draaien, HR-functionarissen die promoties en BAPO-keuzes verwerken, docenten zelf voor self-service inzage in loonstrook + jaaropgaaf + ABP-saldo. Secondary: onderwijs-consultancies die meerdere kleine scholen ontzorgen (Verus, VOS/ABB lid-besturen), en de PO-Raad / VO-raad voor sectorbenchmark-rapportages (anonimiseerde aggregaten).

Tertiair: de Onderwijsinspectie voor compliance-controles (lesuren-cap, bevoegdheid op LC/LD), en de Algemene Onderwijsbond (AOb) die periodiek een CAO-naleving-monitor wil draaien — beide alleen op basis van ge-anonimiseerde aggregaten en met expliciete opt-in van besturen.

Out-of-scope voor dit spec: PO (primair onderwijs) en MBO/HBO/WO — deze hebben eigen CAOs en zullen elk hun eigen modules krijgen. De pattern is bewust herhaalbaar zodat een `cao-onderwijs-po` of `cao-onderwijs-mbo` spec dezelfde architectuur kan hergebruiken met andere tabellen en aanvullende rules.
