# Tasks — payroll-core-engine

> Chain: `depends_on: [payroll-core-schema]` — do not start until the config head is merged
> (tables file, schema fields and the two corpus rules must exist at HEAD). Verify against HEAD,
> not this brief.

- [x] 1. Tables loader: `lib/Payroll/TaxTables.php` — load + shape-validate
  `lib/Standards/tables/<id>.json`, convert euro amounts to integer cents, expose parameter groups
  + the table `id`, list available table ids (for NlEngineChecks) per REQ-PCE-001
- [x] 2. Calculator core: `lib/Payroll/PayrollCalculator.php` — the D2 chain (tabelloon,
  schijventarief, AHK/ARK with the exact Rekenvoorschriften rounding rules, tijdvakbedragen,
  vakantiegeld 8%, informative vv-split, Zvw werkgeversheffing, capped Awf/Aof/Wko/Whk charges,
  netto), pure PHP, integer cents per REQ-PCE-001
  (anchor verified digit-for-digit against the D2 hand computation: loonheffing 718,83 /
  arbeidskorting 473,75 / netto 3.081,17 — all 14 component figures match)
- [x] 3. Calculator variants: AOW-age switch (dateOfBirth vs tables' AOW-leeftijd, month
  granularity, birth-year bracket-set row, AOW korting columns + ouderenkorting) and groen =
  no-ARK path per REQ-PCE-002
- [x] 4. Config: `payroll_aof_tariff` (laag|hoog, default laag) + `payroll_whk_percentage`
  (default = tables' flagged average) getters in `lib/Service/SettingsService.php` (design.md D2
  step 9)
- [x] 5. Service: `lib/Service/PayrollRunService.php` — run probe/create for
  (period, administrationId), active-employee + covering-contract selection, per-employee
  calculate, Payslip upsert keyed (payrollRunId, employeeId) + orphan cleanup, skip reporting
  per REQ-PCE-003
- [x] 6. Service: draft-only recalculation guard + human-approve preservation (never write any
  status but the initial draft) per REQ-PCE-004
- [x] 7. Service: payslip stamping (payrollRunId, userId from nextcloudUserId, components incl.
  arbeidskorting, shows* booleans, zvwMode/zvwRate, appliedTaxRate) + cents-exact totals roll-up +
  engineVersion/calculatedAt stamp per REQ-PCE-005/-PCE-003
- [x] 8. Commands: `lib/Command/PayrollRunCommand.php` (`hrmq:payroll:run --period
  [--administration] [--recalculate]`, per-employee outcome output) + register in
  `appinfo/info.xml` per REQ-PCE-006
- [x] 9. Commands: `lib/Command/PayrollVerifyCommand.php` (`hrmq:payroll:verify --period
  [--administration]`, RuleEngine over exactly the run+payslips, exit 0/non-zero) + register per
  REQ-PCE-006 (run-scoped audit implemented as `RuleAuditService::auditPayrollRunScope()`)
- [x] 10. Checks: `lib/Standards/Checks/NlEngineChecks.php` (both predicates, vacuous-scope
  guards, tables-dir glob at construction) + `RuleAuditService` `payroll.runsById` context
  enrichment per REQ-PCE-007
- [x] 11. Controller: `lib/Controller/PayrollController.php` (`calculate`, `#[NoAdminRequired]`,
  RBAC-resolve-first → 404, non-draft → 400, delegate to service) + route in `appinfo/routes.php`
  BEFORE the SPA catch-all per REQ-PCE-008
- [x] 12. Manifest: PayrollRuns `open-form` "Loonrun aanmaken" action; PayrollRunDetail `api-call`
  "(Her)berekenen" action + FK-scoped Payslips object-list (allowCreate:false) + rewrite the
  now-stale `_note`s; `npm run check:manifest` passes per REQ-PCE-008
- [x] 13. Fixtures: `tests/fixtures/payroll-2026/*.json` — anchor (must byte-match the design.md
  D2 worked example), min-wage 2.294,40, part-time, no-korting, AOW-age, bracket-3, groen; plus
  `official/README.md` with the marked Belastingdienst test-case slots per REQ-PCE-009
- [x] 14. Tests: `PayrollCalculatorTest` (all fixtures, table-driven) +
  `BalancingInvariantTest` (net equation, vv ≤ loonheffing, werknemersverzekeringen sum,
  tables-vs-corpus cross-check) per REQ-PCE-009
- [x] 15. Tests: `PayrollRunServiceTest` (mocked ObjectService: idempotent probe, draft-only
  recalc, upsert + orphan cleanup, skip reasons, totals) + `NlEngineChecksTest` (both predicates
  incl. vacuous scopes) per REQ-PCE-009/-PCE-007
  (plus `RuleAuditServicePayrollScopeTest` pinning the verify semantics: fresh engine run = 0
  mandatory violations, tampered nettoPay = mandatory `nl-engine-output-consistency`)
- [x] 16. README: the non-certification disclaimer section (rekenvoorschriften-based, NOT
  certified, engineVersion traceability, official-test-set gap, named MVP limitations, qualified
  verification required) per REQ-PCE-010
- [x] 17. Quality gates: `composer lint` green, full PHPUnit suite green (376 tests / 1253
  assertions in php:8.3-cli), `npm run check:manifest` PASS, `npm run build` green
  (deviation: the dev-container occ run/verify/tamper gate could not be executed against this
  branch — the shared dev instance mounts a DIFFERENT hrmq checkout (branch `push-icons`) and
  deploying this branch there is forbidden (no-deploy-to-shared-dev-instance rule); the identical
  semantics are pinned instead by `RuleAuditServicePayrollScopeTest` (fresh run → 0 mandatory /
  tampered nettoPay → non-zero) and `PayrollRunServiceTest` (the D2 anchor figures land in the
  generated run/payslip), both driving the REAL RuleEngine + catalogue + nl-2026 tables; see the
  final report. Note: `composer check:strict`'s phpcs step is broken repo-wide at HEAD —
  `phpcs.xml` does not exist in the repository (pre-existing gap, all prior changes shipped
  without it); `composer lint` + PHPUnit are the executable PHP gates)
  (pre-existing fix: `xc-payroll-gl-reconciliation` / `xc-withholding-liability-clearing`
  predicates violated on EVERY non-posted run (never surfaced — the only seeded run is `posted`);
  now scoped to posted/paid runs so draft engine runs audit honestly)

Acceptance criteria (plain reminders, not tasks):
- the equation chain is D2's — not an ad-hoc variant; every rounding step per the
  Rekenvoorschriften (floors/ceils/5-decimals exactly as spec'd)
- lib/Payroll/ has zero OCP/OCA imports (pure) — service/controller own all Nextcloud wiring
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH (ADR-007);
  Dutch strings only in manifest labels/messages per existing convention
- no HTTP calls anywhere; ObjectService only; never touch glExpensePosted/glLiabilityPosted or
  any status but the initial draft
- endpoint params come from the manifest action exactly as `{runId: "@objectId"}` — keep names in
  sync
