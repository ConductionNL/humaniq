# Tasks: Employee Master (NAW, BSN, IBAN, AVG)

Implementation tasks for the `employee-master` change.
All tasks reference `@spec openspec/changes/employee-master/tasks.md`.

---

## Deduplication Check (ADR-001)

- [ ] **Task 1: Deduplication check**
  - Search `openspec/specs/` and `openregister/lib/Service/` for existing Employee or Person schemas
  - Verify OpenRegister's ObjectService, PropertyRbacHandler, and RetentionService cover the
    required platform capabilities (no custom duplicates needed)
  - Verify no existing `BsnEncryptionService` or field-encryption service exists in the platform
  - Document findings in a comment on this task or in `design.md` under Reuse Analysis
  - Confirm ADR-031 exception for BsnEncryptionService is warranted (no OR encryption extension)

---

## Config: Schema Registration

- [ ] **Task 2: Create `lib/Settings/hrmq_register.json` with Employee schema**
  - Define the `hrmq` register
  - Define the `employee` schema with all properties from `design.md` data model table
  - Set `required: [givenName, familyName, birthDate, startDate, status]`
  - Add `x-openregister`: `{ "type": "application", "version": "1.0.0" }`
  - Set property-level `x-permissions` for `bsnEncrypted` (`bsn:read`) and `iban` (`iban:read`)
  - Mark `bsnEncrypted` with `"writeOnly": true` so OR's default serialiser omits it from
    unauthenticated responses

- [ ] **Task 3: Declare employee lifecycle in `hrmq_register.json`**
  - Add `x-openregister-lifecycle` block to the `employee` schema:
    - States: `actief`, `inactief`, `uitdienst`
    - Initial state: `actief`
    - Transitions: actief→inactief, inactief→actief, actief→uitdienst, inactief→uitdienst
    - Terminal states: `["uitdienst"]`
  - No transition guard needed for this change (simple terminal-state enforcement by OR engine)

- [ ] **Task 4: Declare calculated fields in `hrmq_register.json`**
  - Add `x-openregister-calculations` block with three entries:
    - `retentionExpiresAt`: `addYears(@self.endDate, 7)` — condition: `endDate != null`
    - `dienstjaren`: `floor(dateDiff(@self.startDate, today(), 'years'))`
    - `retentionExpired`: `@self.retentionExpiresAt != null && @self.retentionExpiresAt < today()`

- [ ] **Task 5: Add seed data to `hrmq_register.json`**
  - Add `components.objects[]` array with 5 Employee seed objects from `design.md` Seed Data section
  - Use `@self` envelope: `{ "@self": { "register": "hrmq", "schema": "employee", "slug": "..." }, ...props }`
  - Pre-encrypt BSN values using dev-mode AES key (`HRMQ_DEV_BSN_KEY=dev-only-not-for-prod`)
  - Add a comment in `README.md` explaining that seed data uses a dev-only encryption key
  - Verify idempotency: re-importing with `force: false` must not create duplicates (match by slug)

---

## Code: BSN Encryption Service

- [ ] **Task 6: Implement `lib/Service/BsnEncryptionService.php`**
  - Constructor: inject `IAppConfig`, `LoggerInterface`
  - Method `encrypt(string $plainBsn): string` — AES-256-CBC, random IV, returns base64(iv + ciphertext)
  - Method `decrypt(string $encrypted): string` — extracts IV, decrypts, returns plaintext
  - Method `mask(string $encrypted): string` — returns `••••••{last3 digits of decrypted BSN}`
  - Read AES key from `IAppConfig` with sensitive flag; throw `\RuntimeException` if key not set
  - NEVER log the plaintext BSN; catch decrypt failures and log the exception without the value
  - Add `@spec openspec/changes/employee-master/tasks.md#task-6` PHPDoc on class and all public methods

- [ ] **Task 7: Register BsnEncryptionService in `lib/AppInfo/Application.php`**
  - Bind `BsnEncryptionService` in the DI container (constructor injection)
  - Register event listeners for `BeforeObjectSavedEvent` and `AfterObjectLoadedEvent`
    on the `employee` schema:
    - Before save: if `bsn` field present, encrypt → store as `bsnEncrypted`, unset `bsn`
    - After load: if caller has `bsn:read` permission, decrypt → add masked `bsnDisplay`;
      always omit `bsnEncrypted` from the loaded object for non-`bsn:read` callers
  - Add `@spec` tag to the listener registration block

- [ ] **Task 8: Register schema via repair step**
  - Create `lib/Migration/RepairStep.php` implementing `IRepairStep`
  - Call `ConfigurationService::importFromApp('hrmq', $registerData, '1.0.0', false)` to load
    `hrmq_register.json` on install and upgrade
  - Register the repair step in `appinfo/info.xml`
  - Add `@spec openspec/changes/employee-master/tasks.md#task-8` PHPDoc

---

## Code: Frontend

- [ ] **Task 9: Create Employee Pinia store (`src/store/modules/employee.js`)**
  - Use `createObjectStore('employee')` with plugins: `auditTrails`, `files`, `relations`, `selection`
  - Call `objectStore.registerObjectType('employee', 'employee', 'hrmq')` from `initializeStores()`
    in `src/store/store.js`
  - No custom API calls — all CRUD via the generated store

- [ ] **Task 10: Create Employee index view (`src/views/EmployeeIndexView.vue`)**
  - Use `CnIndexPage` with `useListView('employee', { sidebarState, objectStore })`
  - Row click → `$router.push({ name: 'EmployeeDetail', params: { id } })`
  - Add button → `$router.push({ name: 'EmployeeDetail', params: { id: 'new' } })`
  - Columns generated from schema via `columnsFromSchema()` — do not hardcode column definitions
  - Inject `sidebarState` from `App.vue`

- [ ] **Task 11: Create Employee detail view (`src/views/EmployeeDetailView.vue`)**
  - Use `CnDetailPage` with `useDetailView`
  - Two modes: view (`CnDetailCard` sections) / edit (`CnFormDialog` schema-driven)
  - Header actions: Edit button, Delete button (with `CnDeleteDialog`)
  - Sections in view mode:
    - **Persoonlijke gegevens** — name, birthDate, gender, nationality
    - **Adres** — streetAddress, postalCode, addressLocality, addressCountry
    - **Contact** — email, telephone
    - **Financieel** — iban (visible only if `iban:read`)
    - **Identificatie** — bsnDisplay masked value (visible only if `bsn:read`)
    - **Noodcontact** — emergencyContactName, emergencyContactTelephone, emergencyContactRelation
    - **Dienstverband** — startDate, endDate, status (lifecycle badge), dienstjaren, retentionExpiresAt
  - Sidebar: `CnObjectSidebar` (files, audit trail, notes)
  - Props: `employeeId` from route; `isNew = employeeId === 'new'`

- [ ] **Task 12: Add Employee route to `src/router/index.js`**
  - Route `/employees` → `EmployeeIndexView` (name: `EmployeeIndex`)
  - Route `/employees/:id` → `EmployeeDetailView` (name: `EmployeeDetail`)
  - No `/settings` route (settings is a modal, ADR-004)
  - Matching PHP routes must exist in `appinfo/routes.php` (wildcard catch-all for SPA)

- [ ] **Task 13: Add "Medewerkers" navigation item to `MainMenu.vue`**
  - `NcAppNavigationItem` with icon `AccountGroup` (MDI) and label `t(appName, 'Medewerkers')`
  - `:to="{ name: 'EmployeeIndex' }"`
  - Register Dutch translation in `l10n/nl.json`

---

## Code: Tests

- [ ] **Task 14: PHPUnit tests for `BsnEncryptionService`**
  - `testEncryptProducesBase64Output()` — encrypted value is non-empty base64
  - `testDecryptRoundTrip()` — `decrypt(encrypt(bsn)) === bsn`
  - `testEncryptProducesUniqueIvEachCall()` — two calls with same BSN produce different ciphertext
  - `testMaskShowsLastThreeDigits()` — `mask(encrypt('123456782'))` returns `••••••782`
  - `testEncryptThrowsWhenKeyNotConfigured()` — expects `\RuntimeException`
  - `testPlaintextBsnNeverAppearsInException()` — exception message does not contain the BSN value
  - All tests in `tests/Unit/Service/BsnEncryptionServiceTest.php`

- [ ] **Task 15: Integration test for AVG retention calculation**
  - Create a test employee with `endDate = 2023-01-01`, status `uitdienst`
  - Assert `retentionExpiresAt` equals `2030-01-01`
  - Assert `retentionExpired` is `false` when run before 2030-01-01
  - Test in `tests/Integration/EmployeeRetentionTest.php`

- [ ] **Task 16: Integration test for BSN field-level RBAC**
  - Create employee with BSN via a user with `bsn:read`
  - Load the employee as a user WITHOUT `bsn:read` — assert `bsnEncrypted` absent from response
  - Load as user WITH `bsn:read` — assert `bsnDisplay` present and masked
  - Test in `tests/Integration/EmployeeBsnAccessTest.php`
