# Tasks: Cross-app: Payroll Cost → shillinq GL Post

## Deduplication Check

- [ ] **DEDUP-1**: Controleer of OpenRegister's `ObjectService`, `WebhookService`, of een bestaand hrmq-service al een shillinq-koppeling of GL-postingmechanisme biedt.
  - Grep: `grep -rn 'shillinq\|JournalEntry\|GLPost\|payrollPost' lib/ src/ --include='*.php' --include='*.vue'`
  - Verwacht resultaat: geen bestaande implementatie → documenteer "geen overlap gevonden".
  - Indien overlap: verwijs naar bestaande code en pas taken aan.

---

## Schema en registerbestand

- [ ] **SCHEMA-1**: Voeg het `PayrollGLPost`-schema toe aan `lib/Settings/hrmq_register.json`.
  - Gebruik de volledige schemadefinitie uit `design.md` (properties: employeeId, period, status, shillinqJournalEntryId, grossWageAmount, socialChargesAmount, vacationReservationAmount, netWagePayableAmount, postedAt, errorMessage, payrollRunId).
  - Voeg `x-openregister-aggregations.postingStatusSummary` toe (groupBy: status, count: true).
  - Markeer het register met `x-openregister.type: "application"`.

- [ ] **SCHEMA-2**: Voeg 5 seed-objecten toe aan `lib/Settings/hrmq_register.json` onder `components.objects[]`.
  - Gebruik de seed-data uit `design.md` (Bakker 2026-03 posted, De Vries 2026-03 posted, Janssen 2026-03 failed, Bakker 2026-04 pending, Van den Berg 2026-04 skipped).
  - Elke seed-entry bevat `@self` envelope met register, schema, en unieke slug.
  - Verifieer dat re-import idempotent is (slug-matching via `ObjectService.searchObjects`).

---

## Backend — Service

- [ ] **SVC-1**: Maak `lib/Service/PayrollGLPostService.php` aan.
  - SPDX-header: `// SPDX-License-Identifier: EUPL-1.2`
  - `@spec openspec/changes/hrmq-shillinq-payroll-cost-post/tasks.md#SVC-1`
  - Constructor-injectie: `ObjectService`, `IAppConfig`, `ILogger`, HTTP-client (Nextcloud's `IClientService`).
  - Methode `postRun(string $payrollRunId): array` — verwerkt alle werknemers in een run.
  - Methode `postEmployee(string $employeeId, string $period, array $loongegevens): string` — retourneert status ('posted' | 'failed' | 'skipped').
  - Methode `retryFailed(string $period): array` — herpost alle 'failed'-records voor een periode.
  - Privé-methode `buildJournalLines(array $loongegevens, array $rgsConfig): array` — bouwt de vier journaalregels op.
  - Privé-methode `validateBalance(array $loongegevens): bool` — balanceringscontrole (tolerantie €0,01).
  - Privé-methode `checkIdempotency(string $employeeId, string $period): ?array` — zoekt bestaande posted-record.
  - Foutafhandeling: `catch (\Throwable $e)` → log via `$logger->error()`, sla statische foutmelding op, gooi NIET opnieuw.

- [ ] **SVC-2**: Implementeer idempotentiecheck in `PayrollGLPostService`.
  - Gebruik `ObjectService.findObjects($register, $schema, ['employeeId' => $id, 'period' => $period])`.
  - Bij bestaande record met status `posted`: return `['status' => 'skipped', ...]` zonder API-aanroep.
  - Bij bestaande record met status `failed`: herpost wel (update bestaande record).
  - Bij status `pending` of geen record: post naar shillinq.

- [ ] **SVC-3**: Implementeer shillinq API-aanroep in `PayrollGLPostService`.
  - Gebruik `IClientService::newClient()` voor HTTP-aanroep.
  - TLS: certificaatvalidatie altijd ingeschakeld (geen `verify: false`).
  - API-sleutel uit `IAppConfig::getValueString('hrmq', 'shillinq_api_key')`.
  - RGS-rekeningen uit `IAppConfig` (4 sleutels, zie design.md).
  - Request: POST naar `{shillinq_api_url}/api/journal-entries` met journaalregels.
  - Response: sla `journalEntryId` op in PayrollGLPost; retourneer status `posted`.
  - Timeout instellen (30s); bij timeout status `failed`.

---

## Backend — Controller

- [ ] **CTRL-1**: Maak `lib/Controller/PayrollGLPostController.php` aan.
  - SPDX-header.
  - `@spec openspec/changes/hrmq-shillinq-payroll-cost-post/tasks.md#CTRL-1`
  - Dunne controller (<10 regels per methode); alle logica in `PayrollGLPostService`.
  - Methode `postRun(string $payrollRunId)`: POST-endpoint, `#[NoAdminRequired]` + per-object auth.
  - Methode `retryFailed(string $period)`: POST-endpoint, `#[NoAdminRequired]` + per-object auth.
  - Methode `index(string $period)`: GET-endpoint voor overzicht, `#[NoAdminRequired]`.
  - Foutresponses: statische meldingen ('Operation failed'), NOOIT `$e->getMessage()`.
  - Auth: controleer via `AuthorizationService` dat de aanroeper toegang heeft tot hrmq-objecten.

- [ ] **CTRL-2**: Voeg routes toe aan `appinfo/routes.php`.
  - `POST /api/payroll-gl-posts/post-run` → `payrollGLPost#postRun`
  - `POST /api/payroll-gl-posts/retry-failed` → `payrollGLPost#retryFailed`
  - `GET /api/payroll-gl-posts` → `payrollGLPost#index`
  - Specifieke routes vóór eventuele wildcard-routes.

---

## Backend — Admin-instellingen

- [ ] **CFG-1**: Voeg shillinq-configuratievelden toe aan `lib/Controller/SettingsController.php`.
  - Velden: `shillinq_api_key` (sensitive), `shillinq_api_url`, `rgs_gross_wage_account`, `rgs_social_charges_account`, `rgs_vacation_reservation_account`, `rgs_net_wage_payable_account`.
  - GET-endpoint: retourneer configuratie ZONDER de API-sleutel (alleen `shillinq_api_key_set: bool`).
  - POST-endpoint: sla waarden op via `IAppConfig`; API-sleutel met `sensitive: true`.
  - `#[AuthorizedAdminSetting(Application::APP_ID)]` op beide methoden.

---

## Frontend — Admin-instellingen

- [ ] **FE-CFG-1**: Voeg shillinq-sectie toe aan de bestaande admin-instellingenpagina (`src/views/AdminSettings.vue` of gelijkwaardig).
  - Gebruik `CnSettingsSection` met vier RGS-invoervelden en één API-sleutelveld (wachtwoordtype).
  - Laad via `GET /api/settings`; sla op via `POST /api/settings`.
  - Toon succesbericht na opslaan; toon generieke foutmelding bij mislukking.
  - Alle strings via `t('hrmq', 'tekst')`.

---

## Frontend — Posting-overzicht

- [ ] **FE-OVZ-1**: Maak `src/views/PayrollGLPostIndex.vue` aan.
  - SPDX-header.
  - Gebruik `CnIndexPage` + `useListView('payroll-gl-post', { objectStore })`.
  - Kolommen: werknemersnaam, periode, status (badge), shillinqJournalEntryId, postedAt.
  - Statusfilter: dropdown met opties alle / pending / posted / failed / skipped.
  - Actieknop "Herpost gefaald" (zichtbaar als er failed-records zijn in de geselecteerde periode).
  - Registreer in router: `{ path: '/payroll-gl-posts', name: 'PayrollGLPostIndex', component: PayrollGLPostIndex }`.

- [ ] **FE-OVZ-2**: Registreer `payroll-gl-post` als entity type in `src/store/store.js`.
  - `objectStore.registerObjectType('payroll-gl-post', 'PayrollGLPost', 'hrmq')`.
  - Gebruik `createObjectStore` met `auditTrailsPlugin` en `relationsPlugin`.

---

## Seed data

- [ ] **SEED-1**: Verifieer dat de 5 seed-objecten uit `design.md` correct zijn opgenomen in `lib/Settings/hrmq_register.json` en importeerbaar zijn via `ConfigurationService.importFromApp()`.
  - Test: `composer run import-test -- --app=hrmq` of gelijkwaardig; verifieer 5 objecten aangemaakt.
  - Hertest: tweede import mag geen duplicaten aanmaken (idempotentie via slug-matching).

---

## Tests

- [ ] **TEST-1**: Schrijf PHPUnit-tests voor `PayrollGLPostService` in `tests/Unit/Service/PayrollGLPostServiceTest.php`.
  - Test `postEmployee` — succespad (mock shillinq API-client, verifieer ObjectService.saveObject aangeroepen met status 'posted').
  - Test `postEmployee` — idempotentie (bestaande 'posted'-record → geen API-aanroep, return 'skipped').
  - Test `postEmployee` — API-fout (mock 503 → record opgeslagen met status 'failed', geen exception omhoog).
  - Test `validateBalance` — correct geval, en geval met afwijking >€0,01.
  - Test `buildJournalLines` — verifieer vier regels, correcte rekeningen uit configuratie.
  - Minimaal 5 testmethoden.

- [ ] **TEST-2**: Schrijf integratietests (Newman/Postman) in `tests/integration/payroll-gl-post.json`.
  - `POST /api/payroll-gl-posts/post-run` — happy path (200 + resultaat).
  - `POST /api/payroll-gl-posts/post-run` — ongeautoriseerd (401/403).
  - `GET /api/payroll-gl-posts?period=2026-04` — overzicht met records.
  - `POST /api/payroll-gl-posts/retry-failed` — herpost gefaald.
  - Gebruik env-variabele placeholders voor API-sleutel en server-URL; NOOIT hardcoded credentials.

- [ ] **TEST-3**: Schrijf Playwright browser-test voor de posting-overzichtspagina.
  - GIVEN: seed data geladen (5 records voor periodes 2026-03 en 2026-04).
  - WHEN: gebruiker navigeert naar `/payroll-gl-posts?period=2026-04`.
  - THEN: tabel toont 2 records; statusfilter werkt; "Herpost gefaald" knop is zichtbaar.

---

## Pre-commit verificatie

- [ ] **PRE-1**: SPDX-headers aanwezig in alle nieuwe bestanden (`grep -rL 'SPDX-License-Identifier' lib/Service/PayrollGLPostService.php lib/Controller/PayrollGLPostController.php`).
- [ ] **PRE-2**: ObjectService-aanroepen hebben 3 positionele argumenten (`grep -n 'findObjects\|saveObject\|findObject' lib/Service/PayrollGLPostService.php`).
- [ ] **PRE-3**: Geen `$e->getMessage()` in controller-responses (`grep -n 'getMessage' lib/Controller/PayrollGLPostController.php` → nul matches).
- [ ] **PRE-4**: Elke `await objectStore` aanroep in Vue gewrapt in `try/catch`.
- [ ] **PRE-5**: Geen imports uit `@nextcloud/vue` direct in Vue-bestanden; gebruik `@conduction/nextcloud-vue`.
- [ ] **PRE-6**: Alle user-visible strings in Vue via `t('hrmq', '...')`.
- [ ] **PRE-7**: `@spec`-tags aanwezig in alle nieuwe PHP-klassen en publieke methoden.
- [ ] **PRE-8**: Routes in `appinfo/routes.php` specifiek gedefinieerd, geen wildcards voor de nieuwe endpoints.
