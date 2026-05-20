# Implementatietaken — Pension Admin MVP

## 0. Deduplication Check

- [ ] 0.1 Doorzoek `openspec/specs/` op bestaande pension/premie capabilities — verwacht: geen overlap (nieuw domein)
- [ ] 0.2 Controleer `openregister/lib/Service/` op PensionCalculationService, UpaGeneratorService, PensionFundGatewayService — verwacht: geen overlap
- [ ] 0.3 Verifieer dat payroll-core-basic Employee-schema beschikbaar is voor `employeeId`-koppeling in PensionParticipant

## 1. Schema-register en declaratieve configuratie

- [ ] 1.1 Maak `lib/Settings/pensionadmin_register.json` aan met alle vijf schema's: PensionFund, PensionScheme, PensionParticipant, PensionDeclaration, PensionDeclarationLine
- [ ] 1.2 Voeg `x-openregister-lifecycle` toe aan PensionDeclaration met overgangen:
  - `concept → ingediend` (actie: submit, guard: XML-validatie geslaagd)
  - `ingediend → bevestigd` (actie: callback fonds)
  - `ingediend → afgewezen` (actie: callback fonds met foutmelding)
- [ ] 1.3 Voeg `x-openregister-notifications` toe aan PensionDeclaration:
  - Bij overgang naar `ingediend`: notificeer HR-medewerker-rol
  - Bij overgang naar `bevestigd` of `afgewezen`: notificeer HR-medewerker-rol met aangifteperiode en fondsnaam
- [ ] 1.4 Voeg seed-objecten toe in `components.objects[]` conform design.md Seed Data sectie (3 objecten per schema)
- [ ] 1.5 Registreer register in repair step (`lib/Migration/RepairStep/RegisterPensionadminRegister.php`) via `ConfigurationService::importFromApp()`

## 2. Backend — Premieberekening

- [ ] 2.1 Maak `lib/Service/PensionCalculationService.php` met methode `calculate(string $participantId, string $period): array`
- [ ] 2.2 Implementeer grondslag-berekening: `pensioensalaris - (franchise / 12 * partTimeCorrectie)`, minimum 0
- [ ] 2.3 Implementeer premiesplitsing: `grondslag * premiePercentageWerknemer / 100` en `grondslag * premiePercentageWerkgever / 100`
- [ ] 2.4 Voeg maximumsalaris-aftopping toe: als `pensioensalaris > maximumPensioensalaris / 12` → cap op maximum
- [ ] 2.5 Voeg fondscode-specifieke afwijkingen toe (PFZW/ABP/BPL/bpfBOUW/StPVG) via switch op `fundCode`
- [ ] 2.6 Unit-tests: grondslag boven/onder franchise, deeltijd 80%, maximumsalaris-aftopping, elke fondscode

## 3. Backend — UPA XML-generatie

- [ ] 3.1 Maak `lib/Service/UpaGeneratorService.php` met `generate(string $declarationId): string`
- [ ] 3.2 Implementeer XML-assemblage conform Pensioenfederatie UPA-standaard (namespace `urn:nl:minszw:upa:v0.8`, berichttypes AanlevBestand/Bericht/Deelnemer)
- [ ] 3.3 Voeg BSN-element, salarisgegevens en premiebedragen per PensionDeclarationLine toe in correct XSD-formaat
- [ ] 3.4 Ondersteuning correctie-aangifte: verhoog `correctienummer` bij herindienen
- [ ] 3.5 Voeg XSD-validatie toe: `DOMDocument::schemaValidate()` vóór retourneren; gooi `UpaValidationException` met regelnummers bij fouten
- [ ] 3.6 Unit-tests: gegenereerde XML voor aangifte met 1 deelnemer, correctie-aangifte, ongeldige BSN (verwacht: exception)

## 4. Backend — Fonds-gateway (OpenConnector)

- [ ] 4.1 Maak `lib/Service/PensionFundGatewayService.php` met `submit(string $declarationId, string $xmlPayload): array`
- [ ] 4.2 Haal geconfigureerde OpenConnector-bron-ID op uit PensionFund.openConnectorSourceId
- [ ] 4.3 Delegeer HTTP-transport aan OpenConnector-service (geen directe Guzzle-aanroepen)
- [ ] 4.4 Verwerk fonds-respons: bij HTTP 2xx → return `['status' => 'accepted']`; bij fout → return `['status' => 'rejected', 'message' => $errorMessage]`
- [ ] 4.5 Log fouten server-side via `$this->logger->error()` — geen PII, geen XML-inhoud in logs (ADR-005)
- [ ] 4.6 Integration-test met mock OpenConnector voor PFZW-indiening (succes en fout-scenario)

## 5. Backend — Declaratie-orchestratie

- [ ] 5.1 Maak `lib/Service/PensionDeclarationService.php`
- [ ] 5.2 Implementeer `generateDeclaration(string $fundId, string $period): string`:
  - Haal actieve deelnemers op voor het fonds en de periode
  - Roep `PensionCalculationService::calculate()` aan per deelnemer
  - Sla PensionDeclarationLine-objecten op via `ObjectService`
  - Genereer XML via `UpaGeneratorService`
  - Sla XML op als bestand via `FileService`
  - Retourneer declarationId
- [ ] 5.3 Implementeer `submitDeclaration(string $declarationId): void`:
  - Genereer/valideer XML
  - Roep `PensionFundGatewayService::submit()` aan
  - Update PensionDeclaration-status via lifecycle-transitie
- [ ] 5.4 Implementeer `resubmitDeclaration(string $declarationId): void` voor correctie-aangifte
- [ ] 5.5 Tests voor orchestratie (mock: CalculationService, UpaGeneratorService, GatewayService)

## 6. Backend — Controllers

- [ ] 6.1 Maak `lib/Controller/PensionFundController.php` (CRUD, `#[NoAdminRequired]` + per-object authcheck ADR-005)
- [ ] 6.2 Maak `lib/Controller/PensionParticipantController.php` (CRUD, `#[NoAdminRequired]` + per-object authcheck)
- [ ] 6.3 Maak `lib/Controller/PensionDeclarationController.php` met extra acties:
  - `POST /api/declarations/generate` (fonds + periode → declarationId)
  - `POST /api/declarations/{id}/submit`
  - `GET  /api/declarations/{id}/xml` (download XML als bestand)
- [ ] 6.4 Registreer routes in `appinfo/routes.php` — specifieke routes VOOR wildcard `{slug}` (ADR-003)
- [ ] 6.5 Controller-tests op HTTP-niveau met gemockte services; controleer: authcheck aanwezig, geen stack traces in responses

## 7. Frontend — Manifest en routing

- [ ] 7.1 Maak `src/manifest.json` (ADR-024, Tier 1: `useAppManifest`) met pages: `dashboard`, `pension-funds`, `participants`, `declarations`
- [ ] 7.2 Voeg `check:manifest` script toe aan `package.json` (ADR-024 verplichting)
- [ ] 7.3 Configureer `src/router/index.js` met named flat routes: `/`, `/pension-funds`, `/pension-funds/:id`, `/participants`, `/participants/:id`, `/declarations`, `/declarations/:id`

## 8. Frontend — Pinia stores

- [ ] 8.1 Maak `src/store/modules/pensionFund.js` via `createObjectStore('pensionFund')` met `relationsPlugin`
- [ ] 8.2 Maak `src/store/modules/pensionScheme.js` via `createObjectStore('pensionScheme')`
- [ ] 8.3 Maak `src/store/modules/participant.js` via `createObjectStore('participant')` met `relationsPlugin`
- [ ] 8.4 Maak `src/store/modules/declaration.js` via `createObjectStore('declaration')` met `lifecyclePlugin` + `filesPlugin`
- [ ] 8.5 Registreer stores in `src/store/store.js` via `initializeStores()`; gebruik `registerObjectType(name, schemaSlug, registerSlug)` per entiteit

## 9. Frontend — Dashboard

- [ ] 9.1 Maak `src/views/DashboardView.vue` met `CnDashboardPage`
- [ ] 9.2 Voeg vier KPI-blokken toe via `CnStatsBlock`: Totale premie werkgever, Totale premie werknemer, Openstaande aangiften, Afgewezen aangiften
- [ ] 9.3 Voeg aangifte-statusoverzicht per fonds toe via `CnTableWidget` (fonds, periode, status, deelnemers)
- [ ] 9.4 Laad data parallel via `Promise.all([declarationStore.fetchAll(), fundStore.fetchAll()])`

## 10. Frontend — Pensioenfonds-beheer

- [ ] 10.1 Maak `src/views/PensionFundIndex.vue` met `CnIndexPage` + `useListView('pensionFund', { sidebarState, objectStore })`
- [ ] 10.2 Maak `src/views/PensionFundDetail.vue` met `CnDetailPage` + `CnDetailCard` voor gekoppelde regelingen (PensionScheme)
- [ ] 10.3 Maak `src/modals/PensionFundFormDialog.vue` voor aanmaken/bewerken (eigen `.vue`-bestand per ADR-004)

## 11. Frontend — Deelnemer-administratie

- [ ] 11.1 Maak `src/views/ParticipantIndex.vue` met `CnIndexPage` + filterbaar op fonds en status (actief/uitgetreden)
- [ ] 11.2 Maak `src/views/ParticipantDetail.vue` met `CnDetailPage`; toon BSN als gemaskeerd veld (`***456782`)
- [ ] 11.3 Maak `src/modals/ParticipantFormDialog.vue` voor aanmelden/muteren deelnemer
- [ ] 11.4 Voeg bulk-import toe: `CnMassImportDialog` (CSV: BSN, deelnemersnummer, fondscode, ingangsdatum, partTimePercentage)

## 12. Frontend — Aangifte-beheer

- [ ] 12.1 Maak `src/views/DeclarationIndex.vue` met `CnIndexPage` + `CnStatusBadge` per lifecycle-status
- [ ] 12.2 Maak `src/views/DeclarationDetail.vue` met `CnDetailPage` + `CnTimelineStages` (concept → ingediend → bevestigd/afgewezen)
- [ ] 12.3 Voeg header-actions toe in `DeclarationDetail.vue`: "Genereer aangifte", "Dien in", "Download XML" (conditioneel op status)
- [ ] 12.4 Voeg `CnDetailCard` voor declaratieregels toe: tabel met deelnemer, BSN (gemaskeerd), grondslag, premie WN, premie WG
- [ ] 12.5 Maak `src/modals/GenerateDeclarationDialog.vue` (fondsselectie + periode-invoer JJJJ-MM)
- [ ] 12.6 Maak `src/dialogs/SubmitDeclarationConfirmDialog.vue` (`NcDialog`-based, eigen bestand per ADR-004) voor indiening-bevestiging

## 13. Frontend — Admin-instellingen

- [ ] 13.1 Maak `lib/Settings/AdminSettings.php` en registreer in `lib/AppInfo/Application.php`
- [ ] 13.2 Maak `src/components/AdminRoot.vue` met `CnVersionInfoCard` (EERSTE component) + OpenConnector-bron-configuratie per fonds (PFZW, ABP, BPL, bpfBOUW, StPVG)
- [ ] 13.3 Voeg `settings.js` webpack-entry toe; registreer `AdminRoot.vue` als admin-settings pagina (NOOIT als vue-router route per ADR-004)
- [ ] 13.4 Load instellingen via `GET /api/settings` (lees `IAppConfig`), save via `POST /api/settings` (schrijf `IAppConfig`)

## 14. Seed-data generatie

- [ ] 14.1 Verifieer dat seed-objecten in `lib/Settings/pensionadmin_register.json` overeenkomen met de Seed Data sectie in design.md
- [ ] 14.2 Controleer idempotentie: herhaald uitvoeren van repair step mag geen duplicaten aanmaken (match op slug via `ObjectService::searchObjects`)
- [ ] 14.3 Test: installeer app in dev-instance, verifieer dat seed-data zichtbaar is in dashboard, lijstviews en detailpagina's

## 15. Kwaliteit en naleving

- [ ] 15.1 Voeg `@spec openspec/changes/pension-admin-mvp/tasks.md#task-N` PHPDoc-tags toe aan alle nieuwe klassen en publieke methoden (ADR-003)
- [ ] 15.2 Voeg SPDX AGPL-3.0-or-later header en `@copyright` toe aan elk nieuw PHP-bestand (ADR-014)
- [ ] 15.3 Verifieer alle UI-strings via `t(appName, '...')` en voeg vertalingen toe in `l10n/nl.json` (ADR-007)
- [ ] 15.4 Controleer: geen PII (BSN, naam) in logregels, geen stack traces of interne paden in API-responses (ADR-005)
- [ ] 15.5 Controleer WCAG AA: keyboard-navigatie, labels op alle form-velden, status niet alleen via kleur

## 16. Verificatie

- [ ] 16.1 Installeer app in Nextcloud dev-instance; voer repair step uit; verifieer seed-data
- [ ] 16.2 Test complete aangifte-cyclus: deelnemer registreren → aangifte genereren → XML downloaden → indienen via mock OpenConnector
- [ ] 16.3 Verifieer lifecycle-overgangen PensionDeclaration: alle transities zichtbaar in audit trail
- [ ] 16.4 Verifieer dashboard KPI's na indiening en bevestiging
- [ ] 16.5 Controleer dat `afgewezen`-scenario foutmelding toont en correctie-aangifte met verhoogd correctienummer indient
