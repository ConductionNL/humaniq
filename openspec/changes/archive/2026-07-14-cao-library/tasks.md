# Tasks — cao-library

> Depends on `payroll-core-engine` (merged, PR #46): the CheckProvider/SeedsObjects auto-discovery,
> the `RuleAuditService` context mechanism and the corpus-as-code convention. Verify against HEAD,
> not this brief.

- [x] 1. Corpus shape: document the CAO leaf shape (`{value, source, verified}` + `placeholder`/
  `checkAgainst`) in `lib/Standards/cao/SCHEMA.md` (mirroring `tables/SCHEMA.md`) — top-level `id`/
  `name`/`sector`/`version`/`effectiveDate`/`basedOn`/`payScales`/`allowances`/`leaveEntitlement`/
  `workingTime` per REQ-CAO-001
- [x] 2. Seed corpus `lib/Standards/cao/cao-generiek.json` — the statutory-floor baseline, all leaves
  `verified: true` (WML-derived min maandloon, 20 wettelijke vakantiedagen, working-time norm) per
  REQ-CAO-001 / design.md Seed Data
- [x] 3. Seed corpus `lib/Standards/cao/cao-metaal-techniek.json` + `cao-horeca.json` — two concrete
  sectors, multi-scale loontabellen with `placeholder: true` + `checkAgainst` on unverified
  schaalbedragen and leave figures per REQ-CAO-001 / design.md Seed Data
- [x] 4. Loader `lib/Standards/CaoRegistry.php` — pure PHP, `VERSION` const, glob + merge `cao/*.json`
  ONCE (memoised), validate leaf shape; `availableCaos()`, `get()` per REQ-CAO-001
- [x] 5. Loader resolvers: `minMaandloonCents(caoId, schaal)` + `minLeaveHours(caoId,
  contractHoursPerWeek)` returning `null` for unknown OR `verified:false`/`placeholder:true` figures
  (the D5 advisory lever) per REQ-CAO-001 / REQ-CAO-003 / REQ-CAO-004
- [x] 6. register.d: NEW `lib/Settings/register.d/hr-cao.json` — read-only `Cao` schema (id, name,
  sector, version, effectiveDate + display fields for scales/allowances/leave/working-time) per
  REQ-CAO-005
- [x] 7. register.d: `EmploymentContract` in `hr-objects.json` — redefine `cao` to reference a CAO
  `id` (description → the library) and add nullable `caoSchaal` per REQ-CAO-002
- [x] 8. Corpus rule `nl-cao-minimumloon-schaal` in `lib/Standards/rules/payroll.json`
  (EmploymentContract, `mandatory`, `machineCheckable: true`, sourced) per REQ-CAO-003
- [x] 9. Corpus rule `nl-cao-verlof-minimum` in `lib/Standards/rules/labour.json` (LeaveBalance,
  `mandatory`, `machineCheckable: true`, sourced) + bump `RuleCatalogue::VERSION` per REQ-CAO-004
- [x] 10. Provider `lib/Standards/Checks/NlCaoChecks.php` — `checks()` registering both predicates
  against `CaoRegistry`, placeholder→vacuous (D5), cross-object reads from `cao.*` context per
  REQ-CAO-003 / REQ-CAO-004
- [x] 11. Context enrichment in `lib/Service/RuleAuditService.php`: `cao.caosById`,
  `cao.employeesById`, `cao.caoByEmployeeId` (built once per audit, the `runsById` precedent) per
  REQ-CAO-003 / REQ-CAO-004
- [x] 12. Provider `NlCaoChecks implements SeedsObjects` — `seedObjects()` projecting one `Cao` object
  per `CaoRegistry::availableCaos()` entry, keyed/idempotent on cao id per REQ-CAO-006
- [x] 13. Manifest: `Caos` index page + `CaoDetail` detail page (read-only, `allowCreate: false`) +
  menu entry; confirm `cao`/`caoSchaal` render on `EmploymentContractDetail` (ct-data widget excludes
  only `employeeId`) and update its `_note`; `npm run check:manifest` passes per REQ-CAO-005
- [x] 14. Tests: `tests/Unit/Standards/CaoRegistryTest.php` — load/merge/version, resolver values, and
  `null` for placeholder/unverified figures per REQ-CAO-001 / REQ-CAO-005
- [x] 15. Tests: `tests/Unit/Standards/NlCaoChecksTest.php` — below-min salary violation, at/above-min
  pass, below-min leave violation, and vacuous passes (null cao, unknown scale, placeholder figure,
  non-`vakantie` leaveType) driving the REAL `RuleEngine` + catalogue + corpus per REQ-CAO-003 /
  REQ-CAO-004
- [x] 16. Tests: seed idempotency — `seedObjects()` twice yields no duplicate `Cao` objects and
  converges values from the corpus per REQ-CAO-006
- [x] 17. Quality gates: `composer lint` green, full PHPUnit suite green, `npm run check:manifest`
  PASS, `npm run build` green; SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n
  keys ENGLISH, Dutch only in manifest labels

Acceptance criteria (plain reminders, not tasks):
- CAO figures are corpus data with `{value, source, verified}`; unconfirmed figures carry
  `verified:false` + `checkAgainst` and are advisory (never a false mandatory violation)
- `lib/Standards/CaoRegistry.php` has zero OCP/OCA imports (pure), memoised glob, no per-object IO
- both rules are statically `mandatory`; the verified→enforce / placeholder→vacuous nuance lives in
  the predicate, not the severity (RuleEngine attaches one severity per rule id)
- the corpus is the authoring source; the `Cao` objects are a derived, idempotently-seeded projection
