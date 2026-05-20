# Tasks: Payslip / Loonstrook Generation

Implement in dependency order. Backend schemas first (tasks 1-5), then services and jobs (6-10), then frontend (11-13), then tests (14-15), then seed data (16).

---

## Deduplication Check

- [ ] **Task 0: Verify no overlap with OpenRegister platform services**
  - Search `openregister/lib/Service/` for any existing PDF generation, loonstrook, or payslip service — expected: none found
  - Search `openspec/specs/` for any existing `loonstrook-generatie`, `jaaropgaaf-generatie`, or `werknemer-portaal` spec — expected: none found
  - Confirm `FileService` (for PDF storage) and `AuditTrailService` (for download tracking) are reused, not rebuilt
  - Document findings: "No overlap found — PdfLoonstrookService and PdfJaaropgaafService are net-new; all supporting CRUD, file, and audit capabilities consumed from platform"

---

## Schema & Register

- [ ] **Task 1: Add Loonstrook schema to `lib/Settings/hrmq_register.json`**
  - Add schema entry with all properties defined in design.md (periode, brutoLoon, nettoLoon, loonheffing, zvwBijdrage, pensioenpremie, vakantiegeld, reserveringVakantiegeld, reiskosten, toeslagen, inhoudingen, cumulatieven, werkgeverNaam, werkgeverLoonheffingsnummer, status, generatieDatum, publicatieDatum, werknemer relation, salarisRun relation)
  - Set `required`: ["werknemer", "periode", "brutoLoon", "nettoLoon", "status"]
  - Apply schema.org vocabulary where applicable; use `$ref: "relation"` for OpenRegister relations (werknemer, salarisRun)
  - Include SPDX header in the JSON file header comment

- [ ] **Task 2: Add Jaaropgaaf schema to `lib/Settings/hrmq_register.json`**
  - Add schema entry with: jaar, werkgeverNaam, werkgeverLoonheffingsnummer, totaalBrutoLoon, totaalLoonheffing, totaalZvwWerknemersaandeel, totaalPensioenpremie, totaalVakantiegeld, totaalReiskosten, aantalLoonperioden, status, generatieDatum, werknemer relation
  - Set `required`: ["werknemer", "jaar", "totaalBrutoLoon", "totaalLoonheffing", "status"]

- [ ] **Task 3: Add `x-openregister-lifecycle` to Loonstrook schema**
  - Declare lifecycle: `concept → gegenereerd → gepubliceerd → gedownload`
  - Map transitions:
    - `concept → gegenereerd`: trigger = PDF generation endpoint; sets `generatieDatum`
    - `gegenereerd → gepubliceerd`: trigger = publish endpoint; sets `publicatieDatum`
    - `gepubliceerd → gedownload`: trigger = first employee download; sets `eersteDownloadDatum`
  - Reference `decidesk/lib/Settings/decidesk_register.json` Meeting schema as authoring example

- [ ] **Task 4: Add `x-openregister-notifications` to Loonstrook schema**
  - Declare notification on `gepubliceerd` transition:
    - Recipient: `werknemer` relation (resolved to Nextcloud UID)
    - Title key: `notification_new_payslip_title` → "Nieuwe loonstrook beschikbaar"
    - Body key: `notification_new_payslip_body` → "Uw loonstrook over {periodeOmschrijving} is gepubliceerd door uw werkgever."
    - Deep link: `/apps/hrmq/loonstroken/{id}`
  - NO custom PHP NotificationService — declarative only

- [ ] **Task 5: Add `x-openregister-calculations` to Loonstrook schema**
  - `daysSincePublicatie`: `date_diff(@self.publicatieDatum, @now)` — days since publication
  - `isGedownload`: `@self.status == "gedownload"` — boolean convenience field
  - These fields are read-only derived fields available on every Loonstrook object read

---

## Backend Services

- [ ] **Task 6: Create `lib/Service/PdfLoonstrookService.php`**
  - Constructor: inject `ILogger`, `FileService`, `IL10N`
  - Method `generatePdf(array $loonstrook): string` — returns PDF bytes
    - Load Twig environment pointing to `lib/Templates/`
    - Render `loonstrook.html.twig` with loonstrook data
    - BSN must be masked: never pass raw BSN to template; pass `***` instead
    - Compile Twig output to PDF via Dompdf `$dompdf->loadHtml(...)`, `$dompdf->render()`
    - Return `$dompdf->output()`
  - Method `generateAndStore(array $loonstrook, string $userId): array` — generates PDF and stores via FileService; returns FileService reference
  - Add `@spec openspec/changes/payslip-generation/tasks.md#task-6` PHPDoc on class and all public methods
  - Add SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - Write `tests/Unit/Service/PdfLoonstrookServiceTest.php` (≥3 test methods: happy path, with toeslagen/inhoudingen, BSN masking)

- [ ] **Task 7: Create `lib/Templates/loonstrook.html.twig`**
  - Implement NL standard loonstrook layout (see design.md PDF template design section)
  - Sections: werkgever header, werknemer header, Bruto Loon block, Inhoudingen block, Netto Loon block, Cumulatieven block
  - All labels use `|trans` filter for i18n (connected to IL10N via Twig extension)
  - All monetary values formatted as `€ {{ bedrag|number_format(2, ',', '.') }}`
  - BSN field: always render as `***` (template receives the masked value, never raw BSN)
  - Include `hrmq` app logo or werkgever naam in header

- [ ] **Task 8: Create `lib/Service/PdfJaaropgaafService.php`**
  - Same pattern as PdfLoonstrookService
  - Method `generatePdf(array $jaaropgaaf): string`
  - Method `generateAndStore(array $jaaropgaaf, string $userId): array`
  - Template: `lib/Templates/jaaropgaaf.html.twig` — NL jaaropgaaf format (kolommen 1, 2, 14)
  - Add `@spec` PHPDoc and SPDX header
  - Write `tests/Unit/Service/PdfJaaropgaafServiceTest.php` (≥3 test methods)

- [ ] **Task 9: Create `lib/Service/JaaropgaafService.php`**
  - Method `aggregate(string $werknemerId, int $jaar): array` — queries all Loonstrook objects for the employee + year via `ObjectService::findObjects($register, 'loonstrook', ['werknemer' => $werknemerId, 'periode' => "$jaar-*"])`, sums all totaal fields
  - Method `createBatch(int $jaar): array` — iterates all werknemers, calls `aggregate()`, creates Jaaropgaaf objects via `ObjectService::saveObject()`
  - Add `@spec` PHPDoc; SPDX header
  - Exception note in code comment: "Year-to-date aggregation is imperative (ADR-031 exception) pending x-openregister-aggregations date-range filter support — see openregister issue #TODO"
  - Write `tests/Unit/Service/JaaropgaafServiceTest.php` (≥3 test methods: single employee, missing periods, full batch)

---

## Background Job

- [ ] **Task 10: Create `lib/BackgroundJob/LoonstrookGeneratieJob.php`**
  - Extends `QueuedJob`
  - `run(array $argument)`: receives `['salarisRunId' => '...']`
  - Calls `ObjectService::findObject($register, 'salaris-run', $salarisRunId)` to get the run
  - Iterates employees from the SalarisRun's employee list
  - For each employee: checks for existing Loonstrook (same werknemerId + periode) — skip if found
  - Creates Loonstrook via `ObjectService::saveObject($register, 'loonstrook', $data)` with status `concept`
  - Logs skipped employees (incomplete data or duplicates)
  - Register in `Application::register()` as a `IJobList` entry
  - Add SPDX header; `@spec` PHPDoc

---

## Controllers & Routes

- [ ] **Task 11: Create `lib/Controller/LoonstrookController.php`**
  - Methods and auth attributes (per ADR-005):
    - `index()` `#[NoAdminRequired]` — list; service filters by UID if not admin (`IGroupManager::isAdmin()`)
    - `show(string $id)` `#[NoAdminRequired]` — detail; verify UID owns the loonstrook or is admin
    - `create()` `#[AuthorizedAdminSetting(Application::APP_ID)]` — create loonstrook (admin only)
    - `update(string $id)` `#[AuthorizedAdminSetting(Application::APP_ID)]` — update metadata
    - `generatePdf(string $id)` `#[AuthorizedAdminSetting(Application::APP_ID)]` — trigger PDF via PdfLoonstrookService; transition to `gegenereerd`
    - `publish(string $id)` `#[AuthorizedAdminSetting(Application::APP_ID)]` — publish; transition to `gepubliceerd`
    - `download(string $id)` `#[NoAdminRequired]` — serve PDF via FileService; verify UID owns or is admin; transition to `gedownload`
  - All catch blocks: `return new JSONResponse(['message' => 'Operatie mislukt'], 500)` + `$this->logger->error(...)`
  - Never return `$e->getMessage()` in JSON responses
  - Add SPDX header; `@spec` PHPDoc on class and all methods

- [ ] **Task 12: Create `lib/Controller/JaaropgaafController.php`**
  - `index()` `#[NoAdminRequired]` — list filtered by UID (or all if admin)
  - `show(string $id)` `#[NoAdminRequired]` — detail with UID ownership check
  - `batch()` `#[AuthorizedAdminSetting(Application::APP_ID)]` — batch generate (dispatches async job); returns 202 + job reference
  - `generatePdf(string $id)` `#[AuthorizedAdminSetting(Application::APP_ID)]` — PDF generation
  - `publish(string $id)` `#[AuthorizedAdminSetting(Application::APP_ID)]` — publish
  - `download(string $id)` `#[NoAdminRequired]` — PDF download with ownership check
  - Add SPDX header; `@spec` PHPDoc

- [ ] **Task 13: Register routes in `appinfo/routes.php`**
  - Add all LoonstrookController routes (list, show, create, update, generatePdf, publish, download)
  - Add all JaaropgaafController routes (list, show, batch, generatePdf, publish, download)
  - Specific routes BEFORE any wildcard `{slug}` routes (ADR-003)
  - Pattern: `GET  /loonstroken` → `loonstrook#index`, `POST /loonstroken/{id}/pdf` → `loonstrook#generate_pdf`, etc.

---

## Frontend

- [ ] **Task 14: Register Loonstrook and Jaaropgaaf object types in `src/store/store.js`**
  - `objectStore.registerObjectType('loonstrook', 'loonstrook', 'hrmq')` with `filesPlugin` + `auditTrailsPlugin`
  - `objectStore.registerObjectType('jaaropgaaf', 'jaaropgaaf', 'hrmq')` with `filesPlugin` + `auditTrailsPlugin`
  - Type name slugs must be kebab-case (ADR-015)

- [ ] **Task 15: Create `src/views/LoonstrokenIndex.vue`**
  - Use `CnIndexPage` with `useListView('loonstrook', { sidebarState, objectStore })`
  - Columns: periodeOmschrijving, werkgeverNaam, brutoLoon, nettoLoon, status (via `CnStatusBadge`)
  - Row actions (CnRowActions): "Download PDF" always visible for werknemer; "Genereer PDF" + "Publiceer" for admin only (check `settingsStore.isAdmin`)
  - Add button: admin only — opens `CnFormDialog` for manual loonstrook creation
  - All user-visible strings via `this.t('hrmq', '...')` — NO hardcoded Dutch strings
  - Import from `@conduction/nextcloud-vue` only — NEVER `@nextcloud/vue` directly
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`

- [ ] **Task 16: Create `src/views/LoonstrookDetail.vue`**
  - Use `CnDetailPage` with `useDetailView`
  - `CnDetailCard` sections:
    - "Loongegevens" — brutoLoon, nettoLoon, loonheffing, zvwBijdrage, pensioenpremie, reiskosten
    - "Toeslagen" — table of toeslagen array items (if any)
    - "Inhoudingen" — table of inhoudingen array items (if any)
    - "Cumulatieven" — cumBrutoLoon, cumNettoLoon, cumLoonheffing, cumZvwBijdrage, cumPensioenpremie
  - Header actions: "Download PDF" button (all users); "Genereer PDF" + "Publiceer" buttons (admin only)
  - `CnObjectSidebar` for audit trail (download history visible to admin) and files tab
  - Props: `loonstrookId` from route; `isNew = loonstrookId === 'new'`
  - SPDX header

- [ ] **Task 17: Create `src/views/JaaropgavenIndex.vue` and `JaaropgaafDetail.vue`**
  - Same pattern as Loonstroken views
  - JaaropgavenIndex columns: jaar, werkgeverNaam, totaalBrutoLoon, totaalLoonheffing, status
  - JaaropgaafDetail shows all totaal fields in a `CnDetailCard`
  - Admin-only batch action: "Genereer Jaaropgaven [jaar]" opens a dialog with year picker, calls POST /api/jaaropgaven/batch
  - SPDX header on both files

- [ ] **Task 18: Add routes to `src/router/index.js`**
  - `/loonstroken` → `LoonstrokenIndex` (named: `LoonstrokenIndex`)
  - `/loonstroken/:id` → `LoonstrookDetail` (named: `LoonstrookDetail`)
  - `/jaaropgaven` → `JaaropgavenIndex` (named: `JaaropgavenIndex`)
  - `/jaaropgaven/:id` → `JaaropgaafDetail` (named: `JaaropgaafDetail`)
  - All routes: named, flat (no nesting), props via arrow function for params
  - History mode with `generateUrl('/apps/hrmq/')` base (ADR-004)

- [ ] **Task 19: Add navigation items to `src/components/MainMenu.vue`**
  - `NcAppNavigationItem` for "Loonstroken" → `{ name: 'LoonstrokenIndex' }`
  - `NcAppNavigationItem` for "Jaaropgaven" → `{ name: 'JaaropgavenIndex' }`
  - Add MDI icons via `CnIcon` (e.g. `mdi-file-document-outline` for loonstroken)
  - All labels via `this.t('hrmq', '...')`

---

## i18n

- [ ] **Task 20: Add translation keys to `l10n/nl.json`**
  - All keys used in Vue components and PHP controllers (labels, notifications, error messages, empty states)
  - Keys MUST be English in `t('hrmq', 'English key')` calls; Dutch translations go in `l10n/nl.json`
  - Minimum entries: "Loonstroken", "Jaaropgaven", "Download PDF", "Genereer PDF", "Publiceer", "Nieuwe loonstrook beschikbaar", "Uw loonstrook over {periode} is gepubliceerd door uw werkgever.", "Nog geen loonstroken beschikbaar. Uw salarisadministrateur publiceert uw loonstroken na de salarisrun.", "Niet geautoriseerd", "Operatie mislukt"

---

## Tests

- [ ] **Task 21: Write integration tests for Loonstrook API (`tests/integration/loonstroken.json`)**
  - Newman/Postman collection with environment variable placeholders (`{{BASE_URL}}`, `{{ADMIN_TOKEN}}`, `{{EMPLOYEE_TOKEN}}`) — NEVER hardcode credentials
  - Cover:
    - GET /api/loonstroken as admin (returns all)
    - GET /api/loonstroken as employee (returns only own)
    - POST /api/loonstroken/{id}/pdf (admin) — verify 200 and PDF stored
    - POST /api/loonstroken/{id}/publish (admin) — verify 200 and status change
    - GET /api/loonstroken/{id}/download as wrong employee — verify 403
    - GET /api/loonstroken/{id}/download as owner — verify 200 and status → gedownload
  - Error paths: missing loonstrook (404), unauthorized download (403), invalid transition (409)

- [ ] **Task 22: Write integration tests for Jaaropgaaf API (`tests/integration/jaaropgaven.json`)**
  - POST /api/jaaropgaven/batch (admin) — verify 202 and job dispatched
  - GET /api/jaaropgaven as employee — verify only own records returned
  - GET /api/jaaropgaven/{id}/download as wrong employee — verify 403

---

## Seed Data

- [ ] **Task 23: Add Loonstrook seed objects to `lib/Settings/hrmq_register.json`**
  - Add 5 seed objects from design.md Seed Data section using `@self` envelope format
  - Objects: anna-de-boer-2026-01, mehmet-yilmaz-2026-01, sofia-hendriks-2026-01, pieter-van-dijk-2026-02, fatima-el-amrani-2026-02
  - Mark register template with `x-openregister.type: "mock"`
  - Idempotent: slug-matched by `ObjectService::searchObjects` on re-import

- [ ] **Task 24: Add Jaaropgaaf seed objects to `lib/Settings/hrmq_register.json`**
  - Add 3 seed objects from design.md Seed Data section
  - Objects: anna-de-boer-2025, sofia-hendriks-2025, mehmet-yilmaz-2025

---

## Pre-commit Verification

- [ ] **Task 25: Run pre-commit checks before opening PR**
  - `grep -rL 'SPDX-License-Identifier' lib/ src/ --include='*.php' --include='*.vue' --include='*.js'` → zero results
  - `grep -rn 'findObject\|saveObject\|findObjects' lib/ --include='*.php'` → verify all calls have 3 positional args
  - `grep -rn 'getMessage()' lib/Controller/ --include='*.php'` → zero results (no $e->getMessage() in responses)
  - `grep -rn "from '@nextcloud/vue'" src/` → zero results (must use @conduction/nextcloud-vue)
  - `npm run lint` → zero errors
  - `composer check:strict` → all PHPUnit tests pass
  - Smoke test each new API endpoint with curl: verify response shape, status code, and at least one error path per endpoint
  - Verify employee-scoped filtering: log in as two different users, confirm each sees only their own loonstroken
