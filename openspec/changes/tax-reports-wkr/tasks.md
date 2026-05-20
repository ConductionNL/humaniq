# Tasks: Werkkostenregeling (WKR) calc + eindheffing

Implementatietaken voor change `tax-reports-wkr`. Alle taken zijn traceerbaar naar specs via `@spec openspec/changes/tax-reports-wkr/tasks.md#task-N`.

---

## Task 1: Deduplication Check

- [ ] Zoek in `openspec/` en `openregister/lib/Service/` naar bestaande WKR- of loonsom-gerelateerde services
- [ ] Verifieer dat `ObjectService`, `SchemaService`, `ConfigurationService` alle vereiste CRUD dekken zonder custom controller-logica
- [ ] Documenteer bevindingen: **geen overlap gevonden** met bestaande OpenRegister services voor WKR-specifieke berekeningen
- [ ] Bevestig dat `x-openregister-calculations` en `x-openregister-aggregations` de volledige rekenlogica kunnen dragen (geen custom PHP service nodig)

---

## Task 2: Schema Declaraties in Register File

Voeg drie nieuwe schema's toe aan `lib/Settings/hrmq_register.json`.

- [ ] Voeg schema `WkrVergoedingsoort` toe met velden: `naam`, `code`, `vrijstellingstype` (enum), `maximumBedrag` (nullable), `omschrijving`, `actief`
- [ ] Voeg schema `WkrVergoeding` toe met velden: `boekjaar`, `bedrag`, `toewijzingsdatum`, `omschrijving`; relaties `medewerker` en `vergoedingsoort` via `x-openregister-relations`
- [ ] Voeg schema `WkrBudget` toe met veld `loonsomBedrag` en `boekjaar`
- [ ] Voeg `x-openregister-calculations` toe op `WkrBudget`:
  - `vrijeRuimteTier1`: `min(@self.loonsomBedrag, 400000) * 0.03`
  - `vrijeRuimteTier2`: `max(0, @self.loonsomBedrag - 400000) * 0.0118`
  - `vrijeRuimteTotaal`: `@self.vrijeRuimteTier1 + @self.vrijeRuimteTier2`
  - `overschrijding`: `max(0, @self.toegewezenBedrag - @self.vrijeRuimteTotaal)`
  - `eindheffing`: `@self.overschrijding * 0.80`
  - `resterendBudget`: `max(0, @self.vrijeRuimteTotaal - @self.toegewezenBedrag)`
- [ ] Voeg `x-openregister-aggregations` toe op `WkrBudget`:
  - `toegewezenBedrag`: sum van `WkrVergoeding.bedrag` gefilterd op `boekjaar == @self.boekjaar`
  - `aantalVergoedingen`: count van `WkrVergoeding` gefilterd op `boekjaar == @self.boekjaar`
- [ ] Valideer register JSON tegen OpenRegister schema-validator

---

## Task 3: Seed Data

Per ADR-011: 3-5 realistische objecten per schema, Dutch values, loaded via `ConfigurationService::importFromApp()`.

- [ ] Voeg 5 `WkrVergoedingsoort`-objecten toe in `hrmq_register.json` onder `components.objects[]` met `@self` envelope en unieke slugs:
  - `wkr-soort-thuiswerk` (gerichte vrijstelling, max €2.160)
  - `wkr-soort-fiets` (gerichte vrijstelling, max €749)
  - `wkr-soort-kerstpakket` (vrije ruimte)
  - `wkr-soort-maaltijd` (vrije ruimte)
  - `wkr-soort-jubileum` (vrije ruimte)
- [ ] Voeg 5 `WkrVergoeding`-objecten toe (verdeeld over boekjaar 2025 en 2026) met relaties naar Medewerker en WkrVergoedingsoort
- [ ] Voeg 3 `WkrBudget`-objecten toe (boekjaar 2024, 2025, 2026) met realistische loonsombedragen
- [ ] Verifieer dat slugs uniek zijn en idempotent werken bij herinstallatie (match op slug via `ObjectService::searchObjects`)

---

## Task 4: Repair Step Registratie

- [ ] Registreer `hrmq_register.json` in de bestaande repair step (`IRepairStep`) van de hrmq-app
- [ ] Roep `ConfigurationService::importFromApp(appId, $data, $version, force: false)` aan voor de WKR-schema's en seed data
- [ ] Test dat de repair step zonder fouten doorloopt op een verse installatie
- [ ] Test idempotentie: twee keer uitvoeren geeft geen duplicaten (slugs worden gematched)

---

## Task 5: Routes Registratie

Per ADR-004 (history mode) en ADR-016 (routes.php, specifiek voor wildcard):

- [ ] Voeg PHP-routes toe in `appinfo/routes.php` voor alle WKR-controllers:
  - `GET/POST /api/wkr-budget`
  - `GET/PUT/DELETE /api/wkr-budget/{id}`
  - `GET/POST /api/wkr-vergoedingen`
  - `GET/PUT/DELETE /api/wkr-vergoedingen/{id}`
  - `GET/POST /api/wkr-vergoedingsoorten`
  - `GET/PUT/DELETE /api/wkr-vergoedingsoorten/{id}`
- [ ] Specifieke routes staan VOOR wildcard `{slug}` routes
- [ ] Voeg frontend-routes toe aan `src/manifest.json` (history mode):
  - `/wkr-budget`, `/wkr-budget/:id`
  - `/wkr-vergoedingen`, `/wkr-vergoedingen/:id`
  - `/wkr-vergoedingsoorten`, `/wkr-vergoedingsoorten/:id`

---

## Task 6: Backend Controllers

Per ADR-003 (thin controllers, ≤10 regels per methode, `#[NoAdminRequired]` + per-object auth):

- [ ] Maak `WkrBudgetController.php` aan met CRUD-methoden via `ObjectService`
  - Elke mutatiemethode: `#[NoAdminRequired]` + `AuthorizationService::authorize()` check
  - Geen business logic in controller — delegeer naar `ObjectService`
- [ ] Maak `WkrVergoedingController.php` aan (zelfde structuur)
- [ ] Maak `WkrVergoedingsoortController.php` aan (zelfde structuur)
- [ ] Verifieer dat geen enkele controller `AuthorizationService` overslaat bij `#[NoAdminRequired]` (ADR-005, IDOR-preventie)
- [ ] Voeg `@spec openspec/changes/tax-reports-wkr/tasks.md#task-6` PHPDoc toe aan alle publieke methoden (ADR-003 spec-traceabiliteit)

---

## Task 7: Pinia Stores

Per ADR-004 (`createObjectStore`, geen custom Vuex):

- [ ] Voeg `wkrBudgetStore` toe in `src/store/modules/` via `createObjectStore('WkrBudget')` met plugins: `auditTrails`, `relations`, `files`
- [ ] Voeg `wkrVergoedingStore` toe via `createObjectStore('WkrVergoeding')` met plugins: `auditTrails`, `relations`
- [ ] Voeg `wkrVergoedingsoortStore` toe via `createObjectStore('WkrVergoedingsoort')`
- [ ] Registreer alle drie stores in `store/store.js` via `initializeStores()` met correcte schema- en register-slugs
- [ ] Wrap alle `await store.action()` aanroepen in `try/catch` met gebruikersgerichte foutmelding (ADR-004)

---

## Task 8: Vue Index Pages

Per ADR-004 (CnIndexPage-patroon):

- [ ] Maak `src/views/WkrBudgetIndex.vue` aan met `CnIndexPage` + `useListView(wkrBudgetStore)`
  - Kolommen: boekjaar, loonsom, vrije ruimte, toegewezen, resterend, eindheffing (via `columnsFromSchema()`)
  - Klikbare rij → detail-route
  - CnStatusBadge voor overschrijding-status
- [ ] Maak `src/views/WkrVergoedingIndex.vue` aan met `CnIndexPage` + `useListView`
  - Kolommen: boekjaar, medewerker, vergoedingsoort, bedrag, toewijzingsdatum
  - Filter op boekjaar via `CnFilterBar`
- [ ] Maak `src/views/WkrVergoedingsoortIndex.vue` aan
  - Kolommen: naam, code, vrijstellingstype, maximumBedrag, actief
- [ ] Alle strings via `t(appName, '...')` — geen hardcoded tekst (ADR-004 i18n)
- [ ] WCAG AA: labels geassocieerd aan inputs, toetsenbordnavigeerbaar (ADR-004 NL Design)

---

## Task 9: Vue Detail Pages

Per ADR-004 (CnDetailPage + CnDetailCard, relaties verplicht):

- [ ] Maak `src/views/WkrBudgetDetail.vue` aan:
  - `CnDetailPage` met sidebar (`CnObjectSidebar`)
  - Sectie "WKR Berekening": vrije ruimte tier1/tier2/totaal, toegewezen, overschrijding, eindheffing als KPI-kaarten (`CnStatsBlock`)
  - Sectie "Vergoedingen": tabel van gekoppelde `WkrVergoeding`-objecten (`CnDetailCard`)
  - Edit-knop → `CnFormDialog` gegenereerd uit schema
- [ ] Maak `src/views/WkrVergoedingDetail.vue` aan:
  - `CnDetailPage` met `CnDetailCard` voor medewerker-relatie en vergoedingsoort-relatie
  - Beide relatie-secties zijn verplicht geïmplementeerd (niet uitgesteld/gestubbed)
- [ ] Maak `src/views/WkrVergoedingsoortDetail.vue` aan
- [ ] Geen `window.confirm()` of `window.alert()` — gebruik `NcDialog` / `CnDeleteDialog` (ADR-004)

---

## Task 10: Navigatie

- [ ] Voeg WKR-sectie toe aan `src/components/MainMenu.vue` met drie `NcAppNavigationItem`-entries:
  - "WKR Budgetten" → `/wkr-budget`
  - "Vergoedingen" → `/wkr-vergoedingen`
  - "Vergoedingsoorten" → `/wkr-vergoedingsoorten`
- [ ] Iconen via `CnIcon` (MDI) — geen externe icoonfont-afhankelijkheden
- [ ] Sectie-header "Werkkostenregeling" als `NcAppNavigationItem` (niet-klikbaar, als label)

---

## Task 11: Vertalingen

Per ADR-007 (i18n source of truth: English keys, Dutch in l10n/nl.json):

- [ ] Voeg alle nieuwe UI-strings toe als English keys in componentbestanden via `t(appName, '...')`
- [ ] Voeg Nederlandse vertalingen toe aan `l10n/nl.json`:
  - "WKR Budget" → "WKR Budget"
  - "Free space" → "Vrije ruimte"
  - "Assigned" → "Toegewezen"
  - "Remaining" → "Resterend budget"
  - "Surplus" → "Overschrijding"
  - "Final levy" → "Eindheffing"
  - "Allowance type" → "Vergoedingsoort"
  - "Exemption type" → "Vrijstellingstype"
  - "Targeted exemption" → "Gerichte vrijstelling"
  - "Free space allocation" → "Vrije ruimte"
  - En alle overige nieuwe labels

---

## Task 12: PHPUnit Tests

Per ADR-008 (≥3 testmethoden per service/controller, error paths inbegrepen):

- [ ] Maak `tests/Unit/Controller/WkrBudgetControllerTest.php` aan:
  - Test: index retourneert gepagineerde lijst (HTTP 200)
  - Test: create met valide data → HTTP 201
  - Test: create zonder verplicht veld → HTTP 400
  - Test: update door niet-eigenaar → HTTP 403
- [ ] Maak `tests/Unit/Controller/WkrVergoedingControllerTest.php` aan (zelfde structuur)
- [ ] Maak `tests/Unit/Controller/WkrVergoedingsoortControllerTest.php` aan
- [ ] Alle tests draaien clean in `composer check:strict`

---

## Task 13: Integration Tests (Newman/Postman)

Per ADR-008 (elke API-endpoint → Newman-collectie):

- [ ] Maak `tests/integration/wkr.postman_collection.json` aan met:
  - Happy path: aanmaken budget, vergoedingsoort, vergoeding en ophalen budget met berekende velden
  - Error path: aanmaken zonder verplicht veld → 400
  - Error path: ongeautoriseerde mutatie → 401/403
  - Verify: `toegewezenBedrag` klopt na toevoegen vergoedingen
  - Verify: `eindheffing` = 0 wanneer geen overschrijding
- [ ] Gebruik env variable placeholders voor credentials — geen hardcoded defaults (ADR-008)

---

## Task 14: Browser Tests (Playwright)

Per ADR-008 (elke spec-scenario → browser test via GIVEN/WHEN/THEN):

- [ ] Test US-WKR-001: HR administrateur bekijkt vrije ruimte op WkrBudget-pagina
- [ ] Test US-WKR-002: HR administrateur maakt een WkrVergoeding aan en ziet toegewezen bedrag bijwerken
- [ ] Test US-WKR-003: Eindheffing wordt zichtbaar wanneer toegewezen > vrije ruimte
- [ ] Test US-WKR-004: Vergoedingsoort aanmaken en selecteren bij nieuwe vergoeding
- [ ] Test REQ-WKR-009: Boekjaar-isolatie — vergoedingen uit 2025 tellen niet mee voor 2026-budget

---

## Task 15: Smoke Test voor PR

Per ADR-008 (smoke testing voor PR-opening):

- [ ] Roep elk nieuw API-endpoint aan met `curl` — verifieer response shape en statuscode
- [ ] Test één error path per endpoint (ontbrekend veld, verkeerde auth, ongeldig type)
- [ ] Controleer dat seed data geladen is na repair step (`GET /api/wkr-vergoedingsoorten` retourneert ≥5 objecten)
- [ ] Verifieer dat berekende velden (`vrijeRuimteTotaal`, `eindheffing`) correct zijn in de API-response voor seed WkrBudget 2026
- [ ] Bevestig dat er geen `window.confirm()` of hardcoded strings in de UI zitten

---

## Task 16: @spec Traceabiliteit

Per ADR-003 (spec-traceabiliteit: `@spec` PHPDoc op alle klassen en publieke methoden):

- [ ] Voeg `@spec openspec/changes/tax-reports-wkr/tasks.md#task-6` toe aan `WkrBudgetController`
- [ ] Voeg `@spec` toe aan `WkrVergoedingController` en `WkrVergoedingsoortController`
- [ ] Voeg bestandsniveau `@spec` toe in header docblock van elk geraakt PHP-bestand
