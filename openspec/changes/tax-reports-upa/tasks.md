# Tasks: UPA Loonaangifte to Belastingdienst

Implementation tasks for capabilities `upa-aangifte`, `correctieberichten`, `wkr-administratie`, `jaarwerk-loonbelasting`.

---

## 0. Deduplication Check

- [ ] 0.1 Zoek in `openregister/lib/Service/` naar overlap met `UpaAangifteService`, `WkrAdministratieService`, `JaarwerkService` — documenteer bevindingen (verwacht: geen overlap, domein-specifiek voor hrmq)
- [ ] 0.2 Verifieer dat `ObjectService`, `AuditTrailService`, `FileService`, `NotificationService`, `ImportService`, `ExportService` hergebruikt worden conform ADR-001 (geen eigen implementaties)
- [ ] 0.3 Verifieer dat `CnIndexPage`, `CnDetailPage`, `CnFormDialog`, `createObjectStore`, `useListView` hergebruikt worden conform ADR-004 (geen custom list/detail/form componenten)

---

## 1. OpenRegister Schemas en Seed Data

- [ ] 1.1 Maak register `hrmq-belastingdienst` aan in `lib/Settings/hrmq_register.json` (OpenAPI 3.0 + `x-openregister` extensies; `x-openregister.type: "application"`)
- [ ] 1.2 Definieer schema `LoonaangifteRun` in het register: properties `aangiftetijdvak`, `aangifte_type`, `aanleveraarsnummer`, `loonheffingennummer`, `status`, `relayProvider`, `relayReferentie`, `responsecode`, `responseomschrijving`, `ingediendOp`, `aantalDienstverbanden`, `totaalLoonheffing`, `totaalSvLoon`, `origineelTijdvak`, `origineelRun`, `xmlPayload` — met types, required-vlaggen en description per ADR-011
- [ ] 1.3 Definieer schema `WkrAdministratie`: properties `jaar`, `werkgever`, `omschrijving`, `bedrag`, `categorie`, `werknemer`, `eindheffingPercentage`, `toelichting`
- [ ] 1.4 Definieer schema `Jaaropgave`: properties `jaar`, `werknemerNummer`, `werknemerNaam`, `bsnGemaskeerd`, `totaalLoon`, `totaalLoonheffing`, `totaalZvwBijdrage`, `heffingskorting`, `gegenereerd`, `gegenereedOp`, `verstuurd`, `verstuurdOp`
- [ ] 1.5 Voeg 5 seed-objecten toe voor `LoonaangifteRun` (mix van statussen: verwerkt, correctie, concept, fout, ingediend) conform design.md Seed Data sectie
- [ ] 1.6 Voeg 4 seed-objecten toe voor `WkrAdministratie` (mix gerichte-vrijstelling en vrije-ruimte) conform design.md
- [ ] 1.7 Voeg 4 seed-objecten toe voor `Jaaropgave` (mix gegenereerd/verstuurd) conform design.md
- [ ] 1.8 Verifieer idempotentie: re-import via `ImportHandler` slaat bestaande objecten over (match op slug)

---

## 2. App Settings (Relay-configuratie)

- [ ] 2.1 Voeg `relay_provider` (enum: `loonnext`, `nmbrs`), `relay_api_url`, `relay_api_key` (sensitive) en `aanleveraarsnummer` toe aan de app-instellingen via `IAppConfig`
- [ ] 2.2 Voeg relay-configuratie sectie toe aan de admin settings (`AdminRoot.vue`) met `CnSettingsSection` — veld voor relay-provider, API URL, en API-key (wachtwoord-type input)
- [ ] 2.3 Schrijf `GET /api/settings` en `POST /api/settings` endpoints in `SettingsController` voor relay-configuratie; API-key wordt nooit teruggestuurd in GET-response (alleen `***` placeholder)

---

## 3. Backend: UpaAangifteService

- [ ] 3.1 Maak `lib/Service/UpaAangifteService.php` aan (Controller → Service patroon, ADR-003); geen directe Mapper-aanroepen
- [ ] 3.2 Implementeer `buildXml(LoonaangifteRun $run): string` — genereert UPA XML conform XSD versie 2026 via `DOMDocument`; bevat alle verplichte UPA-velden (aanleveraarsnummer, loonheffingennummer, tijdvak, dienstverbanden)
- [ ] 3.3 Implementeer `validateXml(string $xml): bool` — valideert XML tegen UPA XSD 2026 met `DOMDocument::schemaValidate()`; geeft beschrijvende fout terug bij validatiefout
- [ ] 3.4 Implementeer `submitViaRelay(LoonaangifteRun $run): array` — stuurt XML door naar geconfigureerde relay-provider (Loonnext of Nmbrs API); retourneert `['success' => bool, 'referentie' => string, 'responsecode' => string, 'responseomschrijving' => string]`
- [ ] 3.5 Implementeer `processResponse(LoonaangifteRun $run, array $response): void` — werkt `LoonaangifteRun`-object bij via `ObjectService` na ontvangst relay-response; stuurt Nextcloud-notificatie via `NotificationService`
- [ ] 3.6 Voeg `@spec openspec/changes/tax-reports-upa/tasks.md#task-3` toe aan alle public methods (ADR-003)

---

## 4. Backend: WkrAdministratieService

- [ ] 4.1 Maak `lib/Service/WkrAdministratieService.php` aan
- [ ] 4.2 Implementeer `berekenVrijeRuimte(string $werkgever, int $jaar): float` — berekent beschikbare vrije ruimte op basis van fiscale loonsom (1,92% t/m €400k, 1,18% daarboven; tarieven 2026)
- [ ] 4.3 Implementeer `berekenGebruikteRuimte(string $werkgever, int $jaar): float` — som van alle `WkrAdministratie`-objecten met `categorie: vrije-ruimte` voor dit werkgever/jaar
- [ ] 4.4 Implementeer `berekenEindheffing(string $werkgever, int $jaar): float` — berekent 80% over het bedrag waarmee gebruikte ruimte de vrije ruimte overstijgt; retourneert 0 indien geen overschrijding
- [ ] 4.5 Voeg `@spec` tags toe aan alle public methods

---

## 5. Backend: JaarwerkService

- [ ] 5.1 Maak `lib/Service/JaarwerkService.php` aan
- [ ] 5.2 Implementeer `genereerJaaropgaven(string $werkgever, int $jaar): array` — itereert alle werknemers met actief dienstverband in `$jaar`; aggregeert loonheffing, SV-loon, ZVW per werknemer uit maandaangiften; maakt `Jaaropgave`-objecten via `ObjectService`
- [ ] 5.3 Implementeer `reconcilieer(string $werkgever, int $jaar): array` — vergelijkt som maandaangiften loonheffing met som jaaropgave loonheffing; retourneert `['geslaagd' => bool, 'afwijking' => float, 'details' => array]`
- [ ] 5.4 Implementeer `verstuurJaaropgave(string $jaaropgaveId, IUser $user): void` — werkt `verstuurd` en `verstuurdOp` bij; stuurt Nextcloud-notificatie aan medewerker
- [ ] 5.5 Voeg `@spec` tags toe aan alle public methods

---

## 6. Backend: UpaIndienenJob (QueuedJob)

- [ ] 6.1 Maak `lib/Job/UpaIndienenJob.php` aan als `\OC\BackgroundJob\QueuedJob`
- [ ] 6.2 Implementeer `run(array $argument)` — haalt `LoonaangifteRun` op via `ObjectService`; roept `UpaAangifteService::submitViaRelay()` aan; verwerkt response
- [ ] 6.3 Implementeer exponentiële retry-logica: 3 pogingen met interval 60s, 300s, 900s; na definitief falen status → `fout` en notificatie aan beheerder
- [ ] 6.4 Registreer job in `lib/AppInfo/Application.php` via `BackgroundJobFactory`
- [ ] 6.5 Voeg `@spec openspec/changes/tax-reports-upa/tasks.md#task-6` toe

---

## 7. Backend: Controllers en Routes

- [ ] 7.1 Maak `lib/Controller/LoonaangifteController.php` aan (thin controller, ADR-003): `index()`, `show()`, `create()`, `genereerXml()`, `indien()`
- [ ] 7.2 Voeg `#[NoAdminRequired]` + per-object autorisatiecheck toe aan alle mutatie-endpoints (`authorizeRun()` service method); admin-endpoints krijgen `#[AuthorizedAdminSetting]`
- [ ] 7.3 Maak `lib/Controller/WkrController.php`: `index()`, `create()`, `update()`, `destroy()`, `dashboard()`
- [ ] 7.4 Maak `lib/Controller/JaarwerkController.php`: `index()`, `genereer()`, `reconcilieer()`, `verstuur()`, `bulkVerstuur()`
- [ ] 7.5 Registreer routes in `appinfo/routes.php`: specifieke routes vóór wildcard `{slug}` routes (ADR-003)
- [ ] 7.6 Verifieer dat alle API-responses geen stack traces, SQL of interne paden bevatten (ADR-005)
- [ ] 7.7 Voeg `@spec` tags toe aan alle controller-methoden

---

## 8. Frontend: Stores

- [ ] 8.1 Maak `src/store/modules/loonaangifteStore.js` aan via `createObjectStore('LoonaangifteRun')` met plugins: `auditTrailsPlugin`, `filesPlugin`, `relationsPlugin`
- [ ] 8.2 Maak `src/store/modules/wkrStore.js` aan via `createObjectStore('WkrAdministratie')`
- [ ] 8.3 Maak `src/store/modules/jaaropgaveStore.js` aan via `createObjectStore('Jaaropgave')`
- [ ] 8.4 Registreer stores in `src/store/store.js` via `initializeStores()` met `registerObjectType(name, schemaSlug, registerSlug)`

---

## 9. Frontend: Views

- [ ] 9.1 Maak `src/views/LoonaangifteView.vue` aan met `CnIndexPage` en `useListView()`; rij-klik → detail; statussen kleurgecodeerd via `CnStatusBadge`
- [ ] 9.2 Maak `src/views/WkrView.vue` aan met `CnDashboardPage` — 4 KPI-kaarten: beschikbaar, gebruikt, resterend, eindheffing; plus `CnDataTable` van WKR-posten
- [ ] 9.3 Maak `src/views/JaarwerkView.vue` aan met `CnIndexPage` voor jaaropgaven; filterbaar op jaar en verstuurd-status
- [ ] 9.4 Voeg navigatieitems toe aan `MainMenu.vue` voor Loonaangifte, WKR en Jaarwerk (met routes)
- [ ] 9.5 Registreer routes in router: `/loonaangifte`, `/loonaangifte/:id`, `/wkr`, `/jaarwerk`, `/jaarwerk/:id`
- [ ] 9.6 Alle user-visible strings via `t(appName, 'tekst')` (ADR-004); Dutch translations in `l10n/nl.json`

---

## 10. Frontend: Modals en Dialogs

- [ ] 10.1 Maak `src/modals/IndienAangifteModal.vue` aan (`NcModal`-based, in eigen bestand per ADR-004): toont samenvatting van aangifte (tijdvak, aantalDienstverbanden, totaalLoonheffing) + bevestigingsknop; gebruikt `try/catch` met gebruikersfeedback
- [ ] 10.2 Maak `src/modals/CorrectieBerichtModal.vue` aan: selecteer tijdvak van originele aangifte; toon verschil-samenvatting; bevestig indiening
- [ ] 10.3 Maak `src/modals/JaaropgaveDetailModal.vue` aan (`NcModal`-based): toont jaaropgave-details; actie-knoppen Versturen en Exporteren
- [ ] 10.4 Maak `src/dialogs/WkrCategorieDialog.vue` aan (`NcDialog`-based): formulier voor WKR-post aanmaken/bewerken via `CnFormDialog` schema-driven
- [ ] 10.5 Verifieer dat geen `window.confirm()` of `window.alert()` wordt gebruikt (ADR-004)
- [ ] 10.6 Verifieer dat geen modal-markup inline in parent-componenten staat (ADR-004 / hydra-gate-modal-isolation)

---

## 11. Tests

- [ ] 11.1 Schrijf PHPUnit tests voor `UpaAangifteService`: XML-generatie (valid XSD), validatie (invalid schema), relay-aanroep (mock relay), response-verwerking (statuswijziging)
- [ ] 11.2 Schrijf PHPUnit tests voor `WkrAdministratieService`: vrije-ruimte berekening (boven en onder €400k grens), eindheffing bij overschrijding, geen eindheffing zonder overschrijding
- [ ] 11.3 Schrijf PHPUnit tests voor `JaarwerkService`: jaaropgave-generatie (correcte aggregatie), reconciliatie-succesgeval, reconciliatie-afwijking detectie
- [ ] 11.4 Schrijf PHPUnit tests voor `UpaIndienenJob`: succespad, retry na tijdelijke fout, definitief falen na 3 pogingen
- [ ] 11.5 Schrijf PHPUnit tests voor `LoonaangifteController`: per-object autorisatiecheck (IDOR-preventie conform ADR-005), genereer-endpoint, indien-endpoint
- [ ] 11.6 Verifieer dat testcollecties geen hardcoded credentials bevatten (ADR-005)

---

## 12. Documentatie en Afronding

- [ ] 12.1 Verifieer dat alle PHP-klassen en public methods `@spec openspec/changes/tax-reports-upa/tasks.md` tags hebben (ADR-003)
- [ ] 12.2 Verifieer dat alle Vue-componenten gebruikmaken van Nextcloud CSS-variabelen (ADR-004); geen hardcoded kleuren
- [ ] 12.3 Verifieer WCAG AA compliance: keyboard-navigeerbaar, geassocieerde labels, kleur niet enige onderscheidende factor
- [ ] 12.4 Voer `openspec verify` uit (of handmatige kwaliteitscontrole) na alle implementatietaken
- [ ] 12.5 Controleer of seed data correct laadt via `ConfigurationService::importFromApp()` repair step
