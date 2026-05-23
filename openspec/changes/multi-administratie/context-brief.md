---
status: draft
---
# Multi-administratie (Multi-tenant Payroll & HR Partitioning)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Administraties

**Rationale:** Multi-tenant payroll-partitioning.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `multi-administratie` capability turns a single hrmq installation into a multi-tenant payroll and HR backbone capable of running multiple legal entities (BV's, stichtingen, coöperaties, eenmanszaken) side by side without commingling employees, contracts, payroll runs, journaalposten or aangiftes. In the Dutch SMB and accountancy market this is not a luxury feature — it is the precondition for adoption. A typical Dutch accountant runs payroll for 40 to 400 client BV's; a holding structure (Beheer BV → Werk BV → Personeels BV) routinely has three to seven loonheffingsnummers under one operational team; a franchise organisation may have one centrale entiteit with dozens of independent franchisenemers. Without first-class multi-administratie support, hrmq cannot serve any of these segments and remains a single-BV toy.

Multi-administratie is foundational and cross-cutting: virtually every other hrmq capability (employee master, contracts, payroll engine, journaalposten, loonaangifte, pensioenaangifte, verzuim, declaraties, audit trail) has to be aware of the active `administratie_id` and enforce strict tenant isolation at the data, API and UI layers. It is therefore the first brief that needs to land — every later spec inherits its primitives.

The capability also delivers the workflows that real multi-entity payroll administration requires: intercompany medewerker-movements (detachering, secondment, definitieve overplaatsing), holding/werkmaatschappij consolidatieoverzichten voor de DGA en accountant, en fijnmazige autorisatie zodat een HR-medewerker bij BV A geen inzicht heeft in BV B. Een persistente administratie-switcher in de top-bar maakt context-wisseling expliciet en auditbaar.

## Data Model

`Administratie` (root tenant entity): `id`, `slug` (URL-safe), `naam_juridisch` (statutaire naam), `naam_handelsnaam`, `rechtsvorm` (BV/NV/Stichting/Coöperatie/Eenmanszaak/VOF/Maatschap), `kvk_nummer`, `vestigingsnummer`, `rsin`, `loonheffingsnummer` (formaat NNNNNNNNNLNN), `btw_nummer`, `sector_code` (sectorrisicogroep WW), `aansluitnummer_uwv`, `cao_code` (verwijzing naar geldende cao), `pensioenfonds_code` (BPF of eigen regeling), `arbodienst`, `boekjaar_start` (default 01-01), `valuta` (default EUR), `taal_default` (nl/en), `vestigingsadres` (gestructureerd: straat/huisnummer/postcode/plaats/land), `correspondentieadres`, `bankrekening_iban`, `bankrekening_bic`, `g_rekening_iban` (voor inleners-WKA), `logo_uri`, `huisstijl_kleur`, `actief_vanaf`, `actief_tot` (null = doorlopend), `parent_administratie_id` (voor holding-structuren), `consolidatie_groep_id`.

`AdministratieRol`: per-user rol binnen één specifieke administratie. `gebruiker_id`, `administratie_id`, `rol` (eigenaar/hr_manager/hr_medewerker/payroll_admin/leesrechten/medewerker_zelf), `vanaf`, `tot`, `door_gebruiker_id`. Een gebruiker kan tegelijk hr_manager bij BV A zijn en payroll_admin bij BV B; toegang tot BV C heeft hij/zij dan simpelweg niet.

`AdministratieSwitch` (audit-light): `gebruiker_id`, `van_administratie_id`, `naar_administratie_id`, `tijdstip`, `sessie_id`, `via` (ui/api/impersonation). Bedoeld om context-switches forensisch te kunnen reconstrueren — vooral relevant als één gebruiker in meerdere tenants bewerkingen doet.

`Detachering` (intercompany movement): `id`, `medewerker_id`, `van_administratie_id`, `naar_administratie_id`, `type` (detachering/secondment/uitleen/definitieve_overplaatsing), `vanaf`, `tot`, `doorbelasting_type` (kostprijs/marktconform/geen), `doorbelasting_bedrag_per_maand`, `intercompany_contract_uri`, `goedgekeurd_door_van`, `goedgekeurd_door_naar`, `payroll_blijft_bij` (van/naar — bepaalt welke administratie de loonkosten boekt en aangifte doet), `status` (concept/goedgekeurd/actief/afgerond/geannuleerd).

`ConsolidatieGroep`: groepeert administraties voor rapportagedoeleinden (holding-overzicht totaal personeelskosten, FTE, headcount, verzuim). `id`, `naam`, `parent_groep_id`, `consolidatie_methode` (volledig/proportioneel/equity), `eliminatie_intercompany` (bool — schakelt of detacherings-doorbelastingen uit het geconsolideerde overzicht worden geëlimineerd).

Alle overige hrmq-entiteiten (Medewerker, Contract, Loonrun, Journaalpost, Loonaangifte, Pensioenaangifte, VerzuimMelding, Declaratie, etc.) krijgen verplicht een `administratie_id` foreign key. Database-niveau: alle queries lopen via een tenant-scoping middleware die `WHERE administratie_id IN (...toegestane voor huidige gebruiker...)` injecteert. Geen enkele query mag bypass-able zijn vanuit applicatiecode.

## Requirements

**REQ-001: Tenant-isolatie op data-niveau.** Geen enkele query, list-call, search of export mag data uit een administratie tonen waarvoor de huidige gebruiker geen `AdministratieRol` heeft.
- GIVEN een gebruiker met rol `hr_manager` op administratie A, WHEN hij `GET /api/medewerkers` aanroept, THEN bevat de response uitsluitend medewerkers met `administratie_id = A`.
- GIVEN een gebruiker zonder rol op administratie B, WHEN hij `GET /api/medewerkers/{id-van-B}` direct opvraagt, THEN antwoordt de API met `404 Not Found` (niet 403 — het bestaan van het object mag niet lekken).
- GIVEN een full-text search query, WHEN deze wordt uitgevoerd, THEN worden alleen hits uit administraties binnen de scope van de gebruiker geretourneerd, ook wanneer de zoekindex zelf cross-tenant is.

**REQ-002: Administratie-switcher in de UI.** De top-bar toont permanent de actieve administratie; switchen vereist één klik en herlaadt de actieve werkruimte.
- GIVEN een gebruiker met toegang tot drie administraties, WHEN hij de top-bar opent, THEN ziet hij een dropdown met alle drie de administraties, gesorteerd op laatst-gebruikt.
- GIVEN een gebruiker bevindt zich op `/medewerkers/123` (medewerker uit BV A), WHEN hij switcht naar BV B, THEN navigeert de UI naar `/medewerkers` (lijstweergave van BV B) en niet naar een 404 of detailweergave van een niet-bestaande medewerker.
- GIVEN een switch heeft plaatsgevonden, WHEN het gebeurt, THEN wordt er een `AdministratieSwitch`-record geschreven met tijdstip, sessie-id en de "via"-bron.

**REQ-003: Intercompany detachering met dubbele goedkeuring.** Een medewerker kan worden gedetacheerd van administratie A naar B; beide kanten moeten expliciet akkoord geven voordat de detachering actief wordt.
- GIVEN een hr_manager van A start een detachering naar B, WHEN hij het formulier indient, THEN krijgt de status `concept` en wordt een goedkeuringsverzoek verstuurd naar een hr_manager van B.
- GIVEN beide kanten hebben goedgekeurd, WHEN de `vanaf`-datum is bereikt, THEN wordt de status automatisch `actief` en wordt de medewerker zichtbaar in de werkruimte van B (zonder dat de medewerker uit A verdwijnt).
- GIVEN een detachering is `actief` met `payroll_blijft_bij = van`, WHEN de loonrun van A wordt gedraaid, THEN worden de loonkosten geboekt op A, maar wordt een doorbelastingsfactuur gegenereerd van A naar B conform `doorbelasting_bedrag_per_maand`.

**REQ-004: Per-administratie loonheffingsnummer en aangiftes.** Elke administratie heeft één eigen loonheffingsnummer; aangiftes loonheffing en pensioen worden strikt per administratie ingediend.
- GIVEN drie administraties met drie verschillende loonheffingsnummers, WHEN de maandelijkse aangifte-job draait, THEN worden er drie afzonderlijke XML-aangiftes gegenereerd en bij Digipoort ingediend.
- GIVEN een aangifte voor administratie A faalt, WHEN dit gebeurt, THEN blijven de aangiftes van B en C onaangetast en wordt alleen het admin-team van A genotificeerd.

**REQ-005: Holding-consolidatie.** Een gebruiker met `ConsolidatieGroep`-rechten kan een geconsolideerd overzicht zien over meerdere administraties heen, met optionele eliminatie van intercompany-doorbelastingen.
- GIVEN een consolidatiegroep met drie BV's, WHEN de gebruiker het dashboard opent, THEN ziet hij totaal-FTE, totaal-headcount, totaal-personeelskosten en totaal-verzuim, opgesplitst per BV plus een totaalregel.
- GIVEN `eliminatie_intercompany = true`, WHEN het overzicht wordt opgebouwd, THEN worden detacherings-doorbelastingen die binnen de groep blijven niet dubbel meegeteld.

**REQ-006: Per-administratie autorisatie en role-scoping.** Rollen zijn altijd per administratie, nooit globaal (behalve een expliciete `superadmin`-rol voor accountancy-eigenaren).
- GIVEN een gebruiker is `hr_manager` op A en `leesrechten` op B, WHEN hij in context A werkt, THEN kan hij medewerkers muteren; WHEN hij switcht naar B, THEN kan hij alleen lezen.
- GIVEN een gebruiker probeert via de API een mutatie te doen op B terwijl hij daar alleen leesrechten heeft, WHEN het verzoek binnenkomt, THEN antwoordt de API met `403 Forbidden` en wordt een audit-event geschreven.

**REQ-007: Per-administratie huisstijl en branding op uitgaande communicatie.** Loonstroken, jaaropgaven, contracten en mails dragen het logo, de kleur en de tenaamstelling van de juiste administratie.
- GIVEN administratie A heeft een eigen logo en huisstijlkleur, WHEN een loonstrook PDF wordt gegenereerd voor een medewerker van A, THEN bevat de PDF het logo van A in het kopblok en de huisstijlkleur in de accentbalken.
- GIVEN een geautomatiseerde mail (bijv. contract-verlenging-herinnering), WHEN deze wordt verstuurd, THEN bevat de afzendernaam en signature de gegevens van de juiste administratie.

**REQ-008: Per-administratie boekjaar en valuta.** Hoewel verreweg de meeste Nederlandse BV's een kalenderboekjaar voeren, ondersteunt het model afwijkende boekjaren (bijv. 1-juli tot 30-juni voor onderwijs) en in de toekomst niet-EUR valuta voor BES-eilanden of buitenlandse vestigingen.
- GIVEN administratie A heeft `boekjaar_start = 01-07`, WHEN jaarrapportages worden gegenereerd, THEN loopt het rapportagejaar van 1 juli tot 30 juni in plaats van kalenderjaar.

**REQ-009: Soft-delete en archivering van administraties.** Een administratie kan worden gearchiveerd (`actief_tot` ingevuld) zonder data te verliezen — vereist voor 7 jaar fiscale bewaarplicht.
- GIVEN een administratie is gearchiveerd, WHEN een gebruiker probeert nieuwe mutaties te doen, THEN antwoordt de API met `409 Conflict — administratie is gearchiveerd`.
- GIVEN een gearchiveerde administratie, WHEN een accountant historische data opvraagt voor de Belastingdienst, THEN is alle data nog steeds leesbaar via leesrechten-rol.

**REQ-010: API-tokens zijn altijd administratie-scoped.** Externe integraties (boekhoudpakket, payroll-aanleverkanaal) krijgen tokens met een expliciete administratie-scope; geen enkel token geeft cross-tenant toegang tenzij expliciet als consolidatie-token uitgegeven.
- GIVEN een API-token is uitgegeven voor administratie A, WHEN een externe partij hiermee `GET /api/medewerkers` aanroept, THEN bevat de response uitsluitend data van A — onafhankelijk van welke gebruiker het token heeft uitgegeven.

## Standards & Sources

- **Wet op de loonbelasting 1964** en **Uitvoeringsregeling loonbelasting 2011** — verplichten één loonheffingsnummer per inhoudingsplichtige, dus per BV.
- **Wet aangifte loonheffingen** — Belastingdienst Loonheffingen Aangifte (LH-aangifte) wordt per loonheffingsnummer ingediend, per maand of vier-wekenperiode.
- **AVG / GDPR art. 32 (beveiliging) en art. 5(1)(f) (integriteit en vertrouwelijkheid)** — afdwingen van tenant-isolatie is een verwerkersverantwoordelijkheid; cross-tenant data-lekken zijn meldingsplichtige datalekken.
- **NEN 7510 / ISO 27001 A.9 (Access Control)** — role-based access scoped per administratie is een control-vereiste in NEN 7510 voor zorg-administraties.
- **BW Boek 2 Titel 9 (jaarrekening) en RJ 217 (consolidatie)** — onderbouwt het model voor consolidatiegroepen en eliminatie van intercompany-transacties.
- **Belastingdienst Handboek Loonheffingen** — hoofdstuk over inhoudingsplicht en samenhang met loonheffingsnummers per rechtspersoon.
- **UWV Aansluitnummer-systematiek** — elk loonheffingsnummer heeft een eigen UWV-aansluitnummer; vereist voor ziekmeldingen en WAZO-aanvragen.
- Referentie-implementaties: **Loket.nl** (multi-tenant accountantsportaal), **Nmbrs** (multi-administratie als first-class concept), **Visma Talenta**, **AFAS Personeel** (multi-administratie via "omgevingen").

## Cross-app integration

- **Foundation voor alle hrmq-capabilities** — elke entiteit in hrmq draagt een `administratie_id`; geen enkele capability is uit te rollen zonder deze als eerste vast te leggen.
- **employee-master** consumeert `administratie_id` voor scoping van medewerkers en honoreert `Detachering` voor zichtbaarheid in meerdere administraties.
- **payroll-engine-nl** draait per-administratie loonruns, gebruikt `loonheffingsnummer` en `sector_code` uit de Administratie-entiteit.
- **audit-trail-payroll** logt alle mutaties met `administratie_id` als verplicht veld; switches worden los geregistreerd.
- **30-procent-regeling**, **loonbeslag-admin** — beide capabilities valideren dat alle bewerkingen binnen één administratie-scope vallen.
- **journaalpost-export** levert per-administratie boekingsbestanden af richting Twinfield, Exact Online, AFAS Profit en SnelStart — accountantskantoren rekenen erop dat één hrmq-installatie netjes 200 verschillende Twinfield-administraties kan voeden.
- **openconnector** verzorgt de daadwerkelijke koppelingen met Digipoort en boekhoudpakketten; tokens worden per administratie uitgegeven.
- **openregister** — Administratie-entiteit is de eerste tenant-context die OpenRegister leert kennen via een dedicated register en schema-set.

## Target users

- **Accountantskantoor (primaire markt)** — voert payroll voor 40 tot 400 klant-BV's; behoeft snelle administratie-switching, sterke tenant-isolatie en consolidatie-overzichten voor de DGA.
- **Holding-DGA's** — bestuurder van een Beheer BV met drie tot zeven werkmaatschappijen; wil één geconsolideerd HR-dashboard plus per-werkmij detailoverzichten.
- **HR-shared-service-centers** — interne shared service van een concern dat HR/payroll voor zusterbedrijven uitvoert; vereist intercompany-detacherings-workflow.
- **Franchise-organisaties** — centrale entiteit en zelfstandige franchisenemers; central kan rapportagerechten hebben maar geen mutatierechten op franchisenemer-data.
- **Bestuurssecretariaten** — stichtingen of coöperaties met meerdere juridische entiteiten onder één bestuurlijke kap.
- **Eindgebruikers (medewerkers zelf)** — zien alleen "hun" administratie en hun eigen gegevens; bij detachering tijdelijk een tweede werkruimte met expliciete labeling.
