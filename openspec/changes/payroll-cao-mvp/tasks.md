# Tasks: payroll-cao-mvp

## 1. OpenRegister schema registration {#task-1}

- [ ] 1.1 Create `appinfo/openregister.json` — define `hrmq` register (if not already defined by other changes) and the `cao` schema with all fields: `name`, `identifier`, `version`, `schema:startDate`, `schema:endDate`, `minimumHourlyRate`, `standardWeeklyHours`, `isActive`, `rules`
- [ ] 1.2 Verify schema validates against ADR-011 (schema.org property names where applicable, `schema:startDate` / `schema:endDate`)

## 2. CAO entity and mapper {#task-2}

- [ ] 2.1 Create `lib/Db/Cao.php` — extend `\OCP\AppFramework\Db\Entity`; fields: `id`, `uuid`, `name`, `identifier`, `version`, `effectiveFrom`, `effectiveTo`, `minimumHourlyRate`, `standardWeeklyHours`, `isActive`, `rules` (JSON-typed column). Add PHPDoc `@spec openspec/changes/payroll-cao-mvp/tasks.md#task-2`
- [ ] 2.2 Create `lib/Db/CaoMapper.php` — extend `\OCP\AppFramework\Db\QBMapper`; methods: `findAll(int $page, int $limit)`, `findById(string $id)`, `findByIdentifierAndVersion(string $identifier, string $version)`. NEVER call ObjectService from mapper. PHPDoc `@spec openspec/changes/payroll-cao-mvp/tasks.md#task-2`

## 3. CAO service {#task-3}

- [ ] 3.1 Create `lib/Service/CaoService.php` — constructor-injected: `CaoMapper`, `IGroupManager`, `IUserSession`. Methods:
  - `getAllCaos(int $page, int $limit): array`
  - `getCaoById(string $id): array`
  - `setActivation(string $id, bool $isActive): array`
  - `getCaoForContract(string $contractId): ?array` — resolves the `cao` relation on a Contract object via ObjectService
  - `getWorkingHoursRules(string $caoId): array` — returns `rules.workingHours`
  - `validateSalaryAgainstCao(string $caoId, string $scaleId, int $step, float $grossHourlyRate): array` — returns `['valid' => bool, 'warning' => ?string]`
- [ ] 3.2 Add PHPDoc `@spec openspec/changes/payroll-cao-mvp/tasks.md#task-3` on class and each public method
- [ ] 3.3 Authorization: `setActivation()` MUST call `IGroupManager::isAdmin($userId)` and throw `\OCP\Security\Exception\ForbiddenException` (caught by controller → 403) if user is not admin

## 4. CAO controller and routes {#task-4}

- [ ] 4.1 Create `lib/Controller/CaoController.php` — thin controller (<10 lines per method), all business logic delegated to `CaoService`. Methods:
  - `GET /api/caos` → `index(int $_page = 1, int $_limit = 20)` — returns paginated `{total, page, pages, results}`
  - `GET /api/caos/{id}` → `show(string $id)`
  - `PUT /api/caos/{id}/activate` → `activate(string $id, bool $isActive)` — requires admin
- [ ] 4.2 Add routes in `appinfo/routes.php` — specific routes BEFORE any wildcard `{slug}` routes
- [ ] 4.3 Error responses: NEVER return `$e->getMessage()`. Pattern: `['message' => 'Operation failed']` + log real error via `$this->logger->error()`
- [ ] 4.4 Add PHPDoc `@spec openspec/changes/payroll-cao-mvp/tasks.md#task-4` on class and each public method

## 5. Pre-built CAO JSON rulesets {#task-5}

- [ ] 5.1 Create `resources/cao-rulesets/` directory with one JSON file per CAO:
  - `schoonmaak-2026.json`
  - `horeca-2026.json`
  - `kappers-2026.json`
  - `detailhandel-non-food-2026.json`
  - `metaal-techniek-2026.json`
  - `bouw-2026.json`
  - `ict-2026.json`
  - `zorg-vvt-2026.json`
  - `beveiliging-2026.json`
  - `algemeen-2026.json`
- [ ] 5.2 Each JSON file MUST include: `name`, `identifier`, `version`, `schema:startDate`, `minimumHourlyRate`, `standardWeeklyHours`, `isActive` (default `false`, except `algemeen` which defaults `true`), and `rules` with `salaryScales`, `workingHours`, `leaveEntitlements`, `allowances`
- [ ] 5.3 `algemeen-2026.json` must contain only WML-statutory values with no sector additions; `rules.salaryScales` contains a single scale with one step at the current WML (€13.27/hour)

## 6. Repair step — InstallCaoRulesets {#task-6}

- [ ] 6.1 Create `lib/Migration/InstallCaoRulesets.php` implementing `IRepairStep`. In `run()`: iterate all 10 JSON files in `resources/cao-rulesets/`, for each call `ObjectService::saveObject('hrmq', 'cao', $data)`. Before saving, check `findObjects('hrmq', 'cao', ['identifier' => $id, 'version' => $ver])` — skip if object already exists (idempotent)
- [ ] 6.2 Register repair step in `lib/AppInfo/Application.php`
- [ ] 6.3 Add PHPDoc `@spec openspec/changes/payroll-cao-mvp/tasks.md#task-6`

## 7. Employee-contract CAO link {#task-7}

- [ ] 7.1 Extend the `Contract` OpenRegister schema (in `appinfo/openregister.json` or contract-management schema) with a `cao` relation field: `{register: 'hrmq', schema: 'cao', objectId: '<uuid>'}`. Coordinate with contract-management change owner to avoid schema conflicts
- [ ] 7.2 Add `ContractService::setCao(string $contractId, string $caoId): array` that validates the CAO exists and `isActive: true` before setting the relation, then calls `ObjectService::saveObject()`
- [ ] 7.3 Update `ContractController` with `PUT /api/contracts/{id}/cao` route → `setCao()` — admin only

## 8. Vue admin UI {#task-8}

- [ ] 8.1 Create `src/views/CaoView.vue` — uses `CnIndexPage` (self-contained, do NOT wrap in `NcAppContent`). Fetches CAO list from store on mount. Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- [ ] 8.2 Create `src/components/cao/CaoListItem.vue` — renders name, sector, version, effective date, minimum hourly rate, and an `NcCheckboxRadioSwitch` for activation toggle. EVERY `<NcFoo>` MUST be imported and listed in `components: {}`
- [ ] 8.3 Create `src/components/cao/CaoDetail.vue` — uses `CnDetailPage`. Renders salary scales table, working hours card (`CnDetailCard`), leave entitlements card, and allowances card. Uses `CnObjectDataWidget` for structured display
- [ ] 8.4 Register CAO entity type in `src/store/store.js` via `createObjectStore` — type slug: `cao` (kebab-case). Register ONCE, not in both `OBJECT_TYPES` and `ENTITY_STORES`
- [ ] 8.5 Add CAO route in `src/router/` — named route `cao-list` and `cao-detail`; use history mode with `generateUrl` base (ADR-015)
- [ ] 8.6 All user-visible strings: `this.t('hrmq', 'English key')` in Vue — NEVER hardcode Dutch. Add English + Dutch entries to `l10n/en.json` and `l10n/nl.json`
- [ ] 8.7 Wrap EVERY `await caoStore.action()` call in `try/catch` with user feedback (NcDialog or toast — no `window.alert()`)
- [ ] 8.8 API calls: `import axios from '@nextcloud/axios'` — NEVER raw `fetch()`

## 9. Deduplication check {#task-9}

- [ ] 9.1 Verify `ObjectService`, `SchemaService`, `RegisterService` in OpenRegister do not already provide CAO-specific logic that can be reused (expected: none — CAO is HRMQ-domain-specific)
- [ ] 9.2 Verify `@conduction/nextcloud-vue` does not ship a salary-scale display component (expected: none — use `CnObjectDataWidget` + custom table in slot)
- [ ] 9.3 Document findings in a comment at the top of `CaoService.php`: "Deduplication check (ADR-012): no overlap with OpenRegister core or shared components."

## 10. Pre-commit verification {#task-10}

- [ ] 10.1 SPDX / PHPDoc: `grep -rL '@license' lib/ --include='*.php'` → zero results; `grep -rL 'SPDX-License-Identifier' src/ --include='*.vue' --include='*.js'` → zero results
- [ ] 10.2 ObjectService call signatures: `grep -rn 'findObject\|saveObject\|findObjects' lib/ --include='*.php'` → every call has 3 positional args
- [ ] 10.3 Error responses: `grep -rn 'getMessage()' lib/Controller/ --include='*.php'` → zero matches
- [ ] 10.4 Auth check: every `PUT /activate` handler calls `IGroupManager::isAdmin()`
- [ ] 10.5 Store registration: `grep -rn 'cao' src/store/store.js` → registered exactly once, slug is `cao`
- [ ] 10.6 No direct `@nextcloud/vue` imports: `grep -rn "from '@nextcloud/vue'" src/` → zero matches
- [ ] 10.7 No raw fetch: `grep -rn 'fetch(' src/ --include='*.vue' --include='*.js'` → zero matches
- [ ] 10.8 Translation keys: all `t()` keys in English; Dutch translations in `l10n/nl.json`
- [ ] 10.9 Component imports: for every `<NcFoo>` or `<CnFoo>` in templates, verify import exists and is in `components: {}`
- [ ] 10.10 Run `npm ci && npm run lint` — zero errors

## 11. Verify against specs {#task-11}

- [ ] 11.1 Fresh install: query `GET /api/caos` → returns 10 CAOs matching REQ-CAO-001
- [ ] 11.2 Upgrade idempotency: run `InstallCaoRulesets` twice in test environment → no duplicates
- [ ] 11.3 Auth: call `PUT /api/caos/{id}/activate` as non-admin → HTTP 403
- [ ] 11.4 Contract link: assign CAO to contract via `PUT /api/contracts/{id}/cao` → relation stored; inactive CAO rejected
- [ ] 11.5 Salary validation: `CaoService::validateSalaryAgainstCao()` returns warning when salary < CAO minimum
- [ ] 11.6 Working hours: `CaoService::getWorkingHoursRules()` returns correct `overtimePremiumPercent` for Bouw (50%)
- [ ] 11.7 UI: CAO list renders all 10 CAOs with activation toggle; detail shows salary scales table
