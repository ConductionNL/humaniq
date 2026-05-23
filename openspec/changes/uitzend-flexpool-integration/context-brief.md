---
status: draft
app: hrmq
spec: uitzend-flexpool-integration
version: 0.1.0
owners: [hrmq-team]
target-users: [hr-admin, inhuur-coordinator, finance-admin, manager, controller]
deps: [employee-master, payroll-engine-nl]
standards: [WAADI, ABU-CAO, NBBU-CAO, WAB, Inlenersbeloning, SNA-NEN-4400-1]
---

# Uitzendkrachten & Flexpool-integratie

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Medewerkers › Uitzendkrachten

**Rationale:** Flexpool-koppeling.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Nederlandse organisaties huren een aanzienlijk deel van hun capaciteit in via uitzendbureaus, payroll-organisaties en detacheerders. De wettelijke en CAO-context (WAADI, WAB, ABU-CAO, NBBU-CAO, inlenersbeloning) maakt dat de inlener — ook al is er geen direct dienstverband — significante administratieve en compliance-verplichtingen heeft. Vooral de **inlenersbeloning** (sinds 30 maart 2015 verplicht; uitzendkracht moet hetzelfde verdienen als een vergelijkbare eigen werknemer voor zes elementen: loon, ADV, toeslagen, periodieken, kostenvergoedingen, vakantiebijslag), de **fase-systematiek** (A/B/C bij ABU, 1-2-3-4 bij NBBU), en de **ketenaansprakelijkheid** (G-rekening, SNA-keurmerk-check) vragen om dedicated tooling.

Deze spec definieert een module voor het registreren en monitoren van inhuur-opdrachten via externe bureaus. Per opdracht legt de module vast: het bureau, de kandidaat, de huidige fase, de geldende CAO, de inlenersbeloning-onderbouwing (welk eigen-werknemer-functieprofiel is referent), urenregistratie, maandelijkse facturatie-flow, en eventuele doorbelasting van capaciteit van het inschakelingsbureau (kostendeler). De module integreert met `employee-master` voor zicht op de totale workforce inclusief flex, en met `payroll-engine-nl` voor consistentie in functiehuis en salarisstaffels.

Geen direct dienstverband wordt aangelegd — inhuurkrachten verschijnen NIET in de payroll, krijgen GEEN verlofbalans, GEEN performance-cycle-invitations. Wel verschijnen ze in capaciteitsplanning, organogrammen (gemarkeerd als "ingehuurd via X") en kostenrapportages.

## Data Model

- **InhuurOpdracht** — `opdracht_nr`, `bureau_ref`, `kandidaat_naam`, `kandidaat_bsn_optional`, `inhurende_manager` (ref Employee), `inhurende_kostenplaats`, `functie_titel`, `referent_eigen_functie` (ref FunctieProfiel — onderbouwing inlenersbeloning), `cao_toepassing` (ABU/NBBU/branche-CAO), `fase` (A/B/C of 1/2/3/4), `startdatum`, `geplande_einddatum`, `werkelijke_einddatum`, `uurtarief_inkoop`, `aantal_uren_per_week`, `werklocatie`, `status`.
- **Bureau** — `kvk`, `naam`, `type` (uitzend/payroll/detachering/zzp-bemiddelaar), `sna_keurmerk_status`, `sna_vervaldatum`, `nen_4400_1_certificaat`, `g_rekening_iban`, `g_rekening_percentage`, `abu_of_nbbu_lid`, `contract_raamovereenkomst_ref`.
- **InlenersBeloningOnderbouwing** — `opdracht_ref`, `vaststellingsdatum`, `loon_per_uur_eigen_werknemer`, `adv_dagen_per_jaar`, `toeslagen_overzicht` (json), `periodieken_staffel`, `kostenvergoedingen_overzicht`, `vakantiebijslag_percentage`, `aanvullende_voorzieningen` (json), `onderbouwing_document_url`, `geldig_tot`.
- **UrenRegistratieFlex** — `opdracht_ref`, `week_nr`, `jaar`, `uren_per_dag` (array), `overuren`, `goedgekeurd_door_manager` (ref), `goedgekeurd_datum`, `factuur_ref`.
- **FactuurFlex** — `bureau_ref`, `factuurnr`, `factuurdatum`, `periode_van`, `periode_tot`, `regels` (array — per opdracht uren × tarief), `subtotaal`, `btw`, `totaal`, `g_rekening_split` (json), `betaaldatum`, `goedgekeurd_door` (ref).

## Requirements

### REQ-001: Inhuur is geen Employee

**GIVEN** een nieuwe `InhuurOpdracht` wordt aangemaakt  
**WHEN** de inhuur-coordinator de opdracht opslaat  
**THEN** wordt GEEN `Employee`-record aangemaakt; de kandidaat verschijnt NIET in payroll, verlofadministratie of performance-cycle; wel verschijnt deze in capaciteitsplanning en organogrammen gemarkeerd met badge "ingehuurd via {bureau}".

### REQ-002: Bureau-validatie SNA-keurmerk vóór opdracht

**GIVEN** een inhuur-opdracht wordt aangemaakt bij een bureau  
**WHEN** de opdracht wordt opgeslagen  
**THEN** controleert het systeem dat het bureau een geldig SNA-keurmerk (NEN 4400-1 of NEN 4400-2) heeft en dat de G-rekening is geconfigureerd; ontbreekt of is verlopen, dan blokkeert opslag met de melding "Bureau voldoet niet aan ketenaansprakelijkheid-eisen — risico op aansprakelijkstelling voor loonheffing/btw door Belastingdienst".

### REQ-003: Inlenersbeloning-onderbouwing verplicht

**GIVEN** een nieuwe opdracht wordt aangemaakt  
**WHEN** het uurtarief en de functie zijn ingevuld  
**THEN** vereist het systeem een gekoppelde `InlenersBeloningOnderbouwing` met een referent eigen-werknemer-functieprofiel en de zes verplichte beloningselementen; zonder deze onderbouwing kan de opdracht niet de status `actief` krijgen.

### REQ-004: Inlenersbeloning-revisie bij CAO-aanpassing eigen organisatie

**GIVEN** de eigen organisatie past haar CAO-staffel aan (loonsverhoging, nieuwe periodiek, toeslagwijziging)  
**WHEN** de payroll-engine deze aanpassing doorvoert op de referent-functieprofielen  
**THEN** signaleert het systeem alle actieve `InhuurOpdracht`-records waarvoor de referent-functie is gemuteerd; per opdracht wordt een taak voor de inhuur-coordinator aangemaakt om de `InlenersBeloningOnderbouwing` te reviseren en met het bureau af te stemmen (tariefaanpassing).

### REQ-005: Fase-progressie ABU (A→B→C) automatisch tracken

**GIVEN** een uitzendkracht via ABU-bureau heeft 52 gewerkte weken voltooid (fase A)  
**WHEN** de wekelijkse urenregistratie deze drempel passeert  
**THEN** signaleert het systeem dat de kandidaat overgaat naar fase B (max 3 jaar / 6 contracten), waarschuwt de inhuur-coordinator en de inhurende manager, en geeft inzicht in de implicaties (recht op pensioen StiPP-Plus, recht op WW-doorbouw, ketenregeling WAB).

### REQ-006: WAB-onderscheid contract bepaald/onbepaald tijd

**GIVEN** een payroll-werknemer wordt ingehuurd via een payrollbedrijf  
**WHEN** het type contract (bepaald/onbepaald) wordt geregistreerd  
**THEN** controleert het systeem WAB-implicaties: bij contract onbepaald tijd geldt de lage WW-premie via de payroller, bij contract bepaald tijd de hoge premie en mogelijk transitievergoeding-doorbelasting; deze kosten worden zichtbaar in de TCO-calculatie van de opdracht.

### REQ-007: Urenregistratie met manager-approval-flow

**GIVEN** een ingehuurde kracht heeft een week gewerkt  
**WHEN** de uren worden geregistreerd (door kracht zelf of door bureau-portal-koppeling)  
**THEN** ontvangt de inhurende manager een approval-taak met de wekelijkse uren, eventuele overuren en afwezigheid; pas na goedkeuring kunnen de uren worden meegenomen in de maandelijkse factuur-matching; auto-rappel na 3 dagen, escalatie naar coordinator na 7 dagen.

### REQ-008: Maandelijkse factuur-matching tegen goedgekeurde uren

**GIVEN** een bureau stuurt een maandelijkse factuur voor meerdere opdrachten  
**WHEN** finance de factuur inleest  
**THEN** matcht het systeem elke factuurregel tegen de goedgekeurde `UrenRegistratieFlex`-records van die periode; afwijkingen (factuur > goedgekeurde uren, ander tarief dan opdracht-uurtarief, opdracht niet bekend) genereren een dispute-taak en blokkeren auto-betaling.

### REQ-009: G-rekening-betaling bij hoog ketenaansprakelijkheid-risico

**GIVEN** een factuur van een bureau wordt goedgekeurd voor betaling  
**WHEN** finance de betaalopdracht aanmaakt  
**THEN** wordt — afhankelijk van het in `Bureau.g_rekening_percentage` ingestelde percentage — een deel van het factuurbedrag (default 25% van loonsom-component) overgemaakt naar de G-rekening van het bureau in plaats van de reguliere rekening, om vrijwaring van loonheffing/btw-naheffing te borgen; de split-administratie wordt vastgelegd in `FactuurFlex.g_rekening_split`.

### REQ-010: TCO-dashboard inhuur vs vast

**GIVEN** een manager of controller wil zicht op de totale inhuurkosten  
**WHEN** het inhuur-dashboard wordt geopend  
**THEN** toont het systeem per kostenplaats/team: aantal actieve InhuurOpdrachten, totale fte-inhuur, gemiddeld uurtarief inkoop, totaal maandelijkse kosten, en een vergelijking met de "wat-als-dezelfde-rol-vast-was"-calculatie (eigen functieprofiel × all-in-tarief inclusief werkgeverslasten), zodat de make-or-hire-beslissing onderbouwd kan worden.

## Standards & Compliance

- **WAADI (Wet allocatie arbeidskrachten door intermediairs)** — vereiste van inlenersbeloning, registratieplicht uitzendbureaus bij KvK.
- **WAB (Wet arbeidsmarkt in balans, 2020)** — onderscheid hoge/lage WW-premie; payroll-werknemers krijgen dezelfde arbeidsvoorwaarden als eigen werknemers.
- **ABU-CAO** — fase A (78 gewerkte weken sinds 1-1-2024, voorheen 52), fase B (max 6 contracten in 4 jaar), fase C (onbepaalde tijd).
- **NBBU-CAO** — fase 1-2-3-4 met vergelijkbare maar afwijkende drempels.
- **SNA NEN 4400-1/NEN 4400-2** — keurmerk voor uitzendbureaus; verklaring dat aan loonheffing/btw-verplichtingen wordt voldaan.
- **G-rekening** — geblokkeerde rekening voor vrijwaring ketenaansprakelijkheid Belastingdienst (Invorderingswet art. 34).
- **Inlenersbeloning-elementen (6)** — loon, ADV/extra verlof, toeslagen overwerk/onregelmatigheid, initiële loonsverhoging, kostenvergoedingen, periodieken.
- **AVG** — BSN-opslag inhuurkracht alleen als wettelijk vereist (i.r.t. ketenaansprakelijkheid + identificatieplicht).

## Cross-app Dependencies

- **employee-master** — referent-functieprofielen voor inlenersbeloning; inhurende manager-ref.
- **payroll-engine-nl** — signalering bij CAO-aanpassingen die referent-functies muteren.
- **finance-export** — factuur-import, G-rekening-betaalopdracht, factuur-matching.
- **task-management** — manager-approval uren, factuur-disputes, fase-overgang-rappels.
- **document-storage** — raamovereenkomst per bureau, inlenersbeloning-onderbouwingen, SNA-keurmerk-bewijs.
- **organisations-master** — bureau-records met KvK-koppeling.

## Target Users

- **Inhuur-coordinator** — registreert opdrachten, beheert bureau-relaties, valideert inlenersbeloning, bewaakt fase-overgangen.
- **HR-admin** — sluit aan op employee-master voor referent-functies; bewaakt totaal-workforce-zicht (vast + flex).
- **Inhurende manager** — keurt uren goed, beoordeelt verlenging/beëindiging, ziet TCO van eigen team.
- **Finance-admin** — verwerkt facturen, doet matching, voert G-rekening-betalingen uit.
- **Controller** — analyseert make-or-hire, monitort budget-realisatie inhuurbudget, rapporteert flex-ratio.
