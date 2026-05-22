---
status: draft
---
# Asset Management voor HRMQ

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Declaraties & assets › Assets

**Rationale:** Asset-register.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Het `asset-management` spec definieert een register van bedrijfsmiddelen (assets) die een werkgever aan een werknemer ter beschikking stelt voor het verrichten van arbeid. Het gaat om laptops, telefoons, lease-auto's, leasefietsen, monitoren, ergonomische bureaustoelen, software-licenties en andere duurzame middelen die fiscaal en arbeidsrechtelijk vastlegging vereisen. HRMQ moet voor elk asset weten: wat is het, wie heeft het, sinds wanneer, en wanneer moet er een fiscale of administratieve actie volgen.

Het MKB-segment dat HRMQ bedient kent op dit moment vrijwel altijd een fragmentatie tussen het HR-systeem (waarin de werknemer staat), een Excel-sheet of een aparte tool als TOPdesk of Snipe-IT (waarin het asset staat) en de salarisadministratie (waarin bijtelling, loon-in-natura en eindheffing terechtkomen). Die fragmentatie kost tijd bij elke uitgifte, inname, of mutatie van een asset, en leidt regelmatig tot fiscale fouten — met name bij de lease-auto, waar de bijtellingsberekening sinds 2017 jaarlijks is veranderd en waar de datum van eerste tenaamstelling bepalend is voor het percentage gedurende zestig maanden.

Het spec lost dit op door het asset-register binnen HRMQ te plaatsen, één-op-één gekoppeld aan de werknemer, met automatische doorvoer van fiscaal relevante mutaties naar de payroll-engine. De werkgever ziet per werknemer welke assets uit staan; per asset wat de status is; en per asset welke loonkosten of bijtellingen er maandelijks aan vasthangen. De accountant of administrateur krijgt een complete asset-historie bij elke jaarafsluiting zonder handmatig reconciliëren.

Naast de fiscale dimensie speelt arbeidsrecht: bij einde dienstverband moeten alle assets retour, en bij arbeidsongeschiktheid of detachering kan een asset (denk aan een leaseauto met privé-gebruik) een rol spelen in de loonbetaling. Het asset-register vormt daarmee ook input voor de offboarding-checklist en de eind-afrekening.

## Data Model

Het hoofdobject is `Asset`. Een Asset heeft een `type` (enum: laptop, telefoon, lease_auto, leasefiets, monitor, bureau, bureaustoel, headset, software_license, overig), een `serienummer` (verplicht voor fysieke assets, vrij voor software), een `merk_en_model`, een `leverancier` (verwijzing naar OpenRegister Organisation), een `aankoopdatum`, een `aankoopprijs` (excl. en incl. BTW), een `afschrijvingstermijn_maanden` (default per type: laptop 36, telefoon 24, lease_auto 60, leasefiets 36), en een `status` (uitgeleend, ingenomen, in_reparatie, afgeschreven, vermist, verkocht).

Daarnaast voert Asset een `eigendoms_type`: eigendom, operationele_lease, financiele_lease, huur. Voor lease-objecten geldt een `leasemaatschappij`, een `lease_contract_nummer`, een `lease_einddatum`, een `maandbedrag_lease` (excl. BTW) en een `lease_kilometers_per_jaar` (alleen lease_auto).

Per Asset bestaat één `AssetAssignment` actief tegelijk: de uitleenkaart. AssetAssignment heeft een `asset_id`, een `employee_id`, een `uitgifte_datum`, een `uitgifte_door` (gebruiker die de uitgifte registreerde), een `inname_datum` (null als nog uit), een `inname_door`, een `staat_bij_uitgifte` (nieuw, goed, gebruikt, beschadigd) en een `staat_bij_inname`. Een werknemer kan meerdere AssetAssignments hebben (laptop + telefoon + lease-auto).

Voor de lease-auto is een aparte `LeaseCarTaxRecord`-subentiteit nodig, omdat de bijtelling regelgebonden is. LeaseCarTaxRecord bevat een `kenteken`, een `datum_eerste_tenaamstelling` (DET), een `cataloguswaarde`, een `brandstoftype` (benzine, diesel, elektrisch, hybride_plug_in, waterstof), een `co2_uitstoot_g_per_km`, een `bijtellingspercentage_huidig`, een `bijtelling_einddatum_termijn` (DET + 60 maanden), en een `privé_gebruik_verklaring` (none, beperkt_500km, vol). De service rekent het percentage uit volgens de jaarlijkse fiscale staffel.

Een `AssetHistoryEntry` is append-only en legt elke mutatie vast: uitgifte, inname, status-wijziging, reparatie-melding, bijtellings-correctie, einde-afschrijving. Velden: `asset_id`, `employee_id` (mag null bij depot-mutaties), `event_type`, `event_at`, `actor_id`, `note`, `previous_value`, `new_value`. Deze tabel voedt zowel de werknemer-asset-historie als de Asset-detailpagina.

Tot slot een `AssetCategory` voor groepering en bulk-rapportage (bv. "iPhones 14 Pro", "MacBooks M3 14-inch"). Categorie is optioneel en niet fiscaal relevant; alleen voor inkoop- en standaardisatie-analyse.

## Requirements

### REQ-001: Asset-registratie
Een gebruiker met rol `asset_manager` of `hr_admin` registreert een nieuw Asset met alle verplichte velden, inclusief de juiste afschrijvingstermijn per type.

GIVEN ik ben ingelogd als hr_admin, WHEN ik op "Nieuw asset" klik en kies type "laptop", THEN toont het formulier serienummer, merk, model, aankoopdatum, aankoopprijs, leverancier en pre-fillt de afschrijvingstermijn met 36 maanden.

GIVEN ik probeer een Asset op te slaan zonder serienummer voor een fysiek asset, WHEN ik op opslaan klik, THEN toont het formulier een blokkerende validatiefout "serienummer is verplicht voor type laptop".

GIVEN ik registreer een lease_auto, WHEN ik het formulier indien, THEN wordt naast Asset ook een LeaseCarTaxRecord aangemaakt waarin ik kenteken, DET, cataloguswaarde, brandstoftype en CO2-uitstoot invoer.

### REQ-002: Uitgifte van een asset
Een Asset wordt aan een werknemer toegewezen via een AssetAssignment, met registratie van datum, staat en uitvoerder.

GIVEN er bestaat een Asset met status `ingenomen` en geen actieve AssetAssignment, WHEN ik klik op "uitgeven" en kies werknemer "Jan de Vries", THEN wordt een AssetAssignment aangemaakt met uitgifte_datum vandaag, asset-status wordt `uitgeleend`, en een AssetHistoryEntry van type `uitgifte` wordt vastgelegd.

GIVEN een Asset is al actief uitgegeven aan werknemer A, WHEN ik probeer hetzelfde Asset uit te geven aan werknemer B, THEN blokkeert het systeem met de melding "Asset is al uitgegeven aan Jan de Vries sinds 2026-03-12; neem eerst in".

GIVEN ik geef een lease_auto uit, WHEN de uitgifte is voltooid, THEN volgt een verplichte vervolgstap "verklaring privé-gebruik" waarin de werknemer kiest tussen `none`, `beperkt_500km` of `vol`, en dit antwoord stuurt automatisch de bijtellings-doorvoer in REQ-006.

### REQ-003: Inname van een asset
Een Asset wordt teruggenomen met registratie van staat en eventuele schade.

GIVEN er is een actieve AssetAssignment, WHEN ik klik "innemen" en vul staat_bij_inname `beschadigd` in met een notitie "scherm gebarsten", THEN wordt inname_datum gezet op vandaag, asset-status wordt `in_reparatie`, en een AssetHistoryEntry van type `inname` wordt vastgelegd met de schade-notitie.

GIVEN de werknemer is uit dienst per einddatum, WHEN de offboarding-wizard de asset-checklist genereert, THEN toont de checklist elk Asset dat nog actief is uitgegeven, en blokkeert de wizard de eind-afrekening tot alle assets ingenomen of als `vermist` gemarkeerd zijn.

### REQ-004: Bijtellingsberekening lease-auto
Het systeem berekent per lease-auto het juiste bijtellingspercentage op basis van DET, brandstoftype, CO2 en cataloguswaarde, volgens de fiscale staffel.

GIVEN een lease-auto met DET 2024-06-01, brandstoftype `elektrisch`, cataloguswaarde €28.000, WHEN het bijtellingspercentage wordt berekend voor maand januari 2026, THEN is het percentage 16% over de eerste €30.000 (vrijwel volledige cataloguswaarde) en 22% over het meerdere — in dit geval 16% over €28.000 = €4.480 jaarbijtelling.

GIVEN een lease-auto met DET 2024-06-01, brandstoftype `elektrisch`, cataloguswaarde €60.000, WHEN het percentage wordt berekend voor maand januari 2026, THEN is dit 17% over de eerste €30.000 + 22% over de resterende €30.000, samen €5.100 + €6.600 = €11.700 jaarbijtelling.

GIVEN een lease-auto met DET 2019-04-01, brandstoftype `benzine`, WHEN het percentage wordt berekend voor maand mei 2026, THEN is dit 35% (auto ouder dan 60 maanden, geldend reguliere staffel niet meer van toepassing, overgangsrecht naar 35%).

GIVEN een lease-auto met privé_gebruik_verklaring `none` en een geregistreerde rittenstaat met <500 km privé per kalenderjaar, WHEN de bijtelling wordt berekend, THEN is het maandbedrag €0 en de berekening logt "verklaring geen privé-gebruik aanwezig, controle rittenstaat door werkgever vereist".

### REQ-005: Automatische staffelovergang
Per kalenderjaar past het systeem nieuwe bijtellingspercentages toe zodra de fiscale wet wijzigt; per individuele auto bewaakt het systeem het einde van de 60-maands-termijn.

GIVEN een lease-auto met DET 2020-04-01 en bijtellingspercentage 8% (EV-staffel 2020), WHEN de huidige datum 2025-04-01 wordt, THEN wordt het bijtellingspercentage automatisch verhoogd naar het standaardpercentage van dat moment (per 2026: 22%), een AssetHistoryEntry van type `bijtelling_staffelovergang` wordt aangemaakt, en de payroll-engine ontvangt een mutatie-event.

GIVEN de fiscale staffel voor 2027 wordt door HRMQ-beheerders aangepast, WHEN de januari-payroll van 2027 draait, THEN gebruikt de berekening de nieuwe staffel voor alle auto's, en de auto's met lopende 60-maands-termijn houden hun bestaande percentage zolang de termijn loopt.

### REQ-006: Doorvoer naar payroll als loon-in-natura
Bij elke wijziging van bijtelling of asset-eigendomswaarde stuurt asset-management een event naar payroll-engine-nl, zodat de eerstvolgende loonberekening de juiste waarde meeneemt.

GIVEN een lease-auto met maand-bijtelling €390 wordt uitgegeven aan werknemer met uitgifte_datum 15 april, WHEN de event-bus de uitgifte ontvangt, THEN registreert payroll-engine-nl voor april een loon-in-natura van €208 (pro-rata 16/30 dagen) en voor mei en verder €390 per maand.

GIVEN een leasefiets met aankoopprijs €2.400 valt onder de werkkostenregeling, WHEN de fiets wordt uitgegeven, THEN bepaalt de payroll-engine of dit binnen de vrije ruimte van de werkgever past en stuurt anders een eindheffings-doorvoer; in beide gevallen volgt een notificatie aan de werkgever.

### REQ-007: Asset-historie per werknemer
Op het werknemer-detail toont een tab "Assets" alle assets, actief en historisch, met inname- en uitgifte-data en de fiscale doorvoer.

GIVEN ik open het werknemer-profiel van Jan de Vries, WHEN ik klik op de tab "Assets", THEN toont het overzicht twee actieve assets (laptop sinds 2024-09-01, lease-auto sinds 2025-03-15) en drie historische assets (telefoon ingenomen 2025-01-10, oude laptop ingenomen 2024-09-01, leasefiets ingenomen 2024-06-30), elk met aankoopdatum, fiscale waarde, en link naar Asset-detail.

GIVEN ik open een afzonderlijk Asset, WHEN ik scroll naar de sectie "geschiedenis", THEN toont het een chronologisch overzicht van AssetHistoryEntries inclusief alle uitgiftes, innames, status-wijzigingen, reparaties, en bijtellings-mutaties.

### REQ-008: Afschrijving en restwaarde-tracking
Per Asset houdt het systeem de boekwaarde bij op basis van lineaire afschrijving sinds aankoopdatum, en signaleert het einde van de afschrijvingstermijn.

GIVEN een laptop aangekocht op 2023-04-01 voor €1.800 met afschrijvingstermijn 36 maanden, WHEN ik de Asset-detailpagina open op 2026-04-01, THEN toont de pagina boekwaarde €0, status-banner "volledig afgeschreven, overweeg vervanging of verkoop".

GIVEN een Asset bereikt einde-afschrijving, WHEN de dagelijkse batch draait, THEN ontvangt de eigenaar van het Asset (asset_manager) een melding in Nextcloud Notifications, en wordt een aanbeveling getoond ("vervangen" of "verkopen aan werknemer voor restwaarde €0").

### REQ-009: Bulk-import en barcode-scan
Voor MKB-bedrijven met bestaande Excel-lijsten moet bulk-import mogelijk zijn, en voor magazijn-scenario's moet barcode-scan ondersteund worden.

GIVEN ik beschik over een CSV met 80 bestaande assets, WHEN ik via "import" upload, THEN valideert het systeem per rij type, serienummer-uniciteit, aankoopdatum, en toont een preview met fouten per rij vóór commit; commit voert atomair door.

GIVEN ik open de Asset-uitgifte-pagina op mobiel, WHEN ik tik op het barcode-icoon, THEN opent de telefooncamera, scant de QR-code op het Asset, en springt automatisch naar het juiste Asset-detail.

### REQ-010: Toegangsrechten en GDPR
Asset-data is gevoelig (kenteken, locatie van duur materiaal) en valt onder GDPR.

GIVEN ik ben werknemer (geen hr- of asset-rol), WHEN ik mijn eigen werknemer-profiel open en de tab "Assets" bekijk, THEN zie ik alleen mijn eigen actieve assets met type, model en uitgifte-datum, maar niet de aankoopprijs of leveranciersgegevens.

GIVEN een werknemer is meer dan 2 jaar uit dienst en heeft geen actieve assets meer, WHEN de GDPR-retentie-batch draait, THEN worden de AssetHistoryEntries van die werknemer geanonimiseerd (employee_id wordt vervangen door pseudo-ID), maar Asset-historie blijft voor fiscale bewijslast bestaan (zeven jaar voor lease-auto's).

## Standards & Sources

- **Wet inkomstenbelasting 2001, artikel 3.145** — bijtelling auto van de zaak; basis voor de staffel.
- **Uitvoeringsregeling loonbelasting 2011, art. 8** — privé-gebruik <500km en de "verklaring geen privé-gebruik".
- **Wet werkkostenregeling (WKR)** — leasefiets en andere duurzame inzetbaarheid binnen vrije ruimte 1,92% (eerste €400.000) en 1,18% daarboven.
- **Belastingdienst Handboek Loonheffingen 2026** — hoofdstuk 23 (auto van de zaak), hoofdstuk 22 (loon in natura).
- **NEN-EN-ISO 55000 Asset Management** — algemene principes voor asset-registratie; relevant voor velden zoals levenscyclusstatus.
- **Concurrenten**: Visma Raet (asset-module), AFAS Profit (assets als onderdeel CRM), Loket.nl (basis-loonbijtelling-tool zonder asset-koppeling), Snipe-IT (open-source asset tracker, geen HR-koppeling). HRMQ-positie: enige Nederlandse oplossing die asset, payroll en fiscale staffel automatisch koppelt voor MKB.
- **Fiscale staffel-data 2017-2026**: jaarlijks publiceert Belastingdienst de bijtellingspercentages; HRMQ houdt een interne staffel-tabel die door een admin per jaar wordt geüpdatet (één regel per percentage-grens, met geldigheidsperiode).

## Cross-app integration

- **employee-master** (dependency): elk AssetAssignment verwijst naar een Employee. Werknemer-mutaties (uit dienst) triggeren de offboarding-asset-checklist.
- **payroll-engine-nl** (dependency): asset-events (uitgifte, inname, staffelovergang, schade-afhandeling) sturen mutatie-events naar payroll voor bijtelling, loon-in-natura, en eindheffing.
- **employee-self-service-mkb** (consument): de werknemer ziet zijn eigen assets in het self-service-portaal, kan schade melden, en kan zijn verklaring privé-gebruik bekijken.
- **onboarding-wizard** (consument): bij aanvang dienstverband genereert de wizard een asset-uitgifte-checklist op basis van rol (developer → laptop, monitor; sales → laptop, telefoon, lease-auto).
- **offboarding-wizard** (consument): bij einde dienstverband genereert de wizard een asset-inname-checklist, blokkeert eind-afrekening tot alle assets retour zijn.
- **expense-reimbursement** (peer): reparatie-kosten of verbruiks-vergoedingen (laadkosten EV thuis) lopen via expense-reimbursement, met asset_id als context.
- **openconnector**: integratie met leveranciers-API's (KPN, Vodafone, MKB Brandstof, Athlon, ALD, LeasePlan) voor automatische import van facturen, kilometerstanden, en contractmutaties.
- **openregister Organisation**: leveranciers en leasemaatschappijen zijn Organisations in OpenRegister, niet duplicaten binnen asset-management.

## Target users

**Primaire gebruikers**: HR-administratie en assets-coördinator in MKB-bedrijven (10-250 werknemers). Vaak één persoon die naast HR ook de telefoon-contracten, laptops en lease-auto's beheert. Heeft geen affiniteit met TOPdesk-achtige enterprise tools maar wel met Excel.

**Secundaire gebruikers**: 
- *Werknemer* — wil snel zien wat ie heeft, een nieuwe asset aanvragen, of schade melden.
- *Manager* — keurt aanvragen voor nieuwe assets goed (laptop-upgrade, telefoon-vervanging).
- *Accountant / boekhouder* — wil bij jaarafsluiting een asset-rapportage met boekwaardes en een controle op fiscale doorvoer.
- *Externe lease-maatschappij* — krijgt via openconnector een API-koppeling voor maandelijkse kilometerstand-opgave.

**Use cases zonder dit spec**: vandaag wordt asset-data verspreid bijgehouden in (a) een Excel-sheet bij de office manager, (b) een aparte sheet bij de IT-coördinator, (c) papieren contracten bij de leasemaatschappij, (d) handmatige boekingen in Loket of Exact voor bijtelling. Fouten ontstaan bij job-changes, parttime-wijzigingen, of inname zonder doorvoer naar payroll. HRMQ asset-management consolideert dit naar één bron van waarheid met automatische fiscale propagatie.
