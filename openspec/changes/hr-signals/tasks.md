# Tasks — hr-signals

- [ ] 1. Corpus: add `nl-signaal-contract-verloopt` (framework `hr-signals`, severity `recommended`, `parameters.windowDays: 60`) and `nl-aanzegtermijn-bewaking` (framework `bw7-10`, severity `mandatory`, `effectiveDate: 2015-01-01`, `parameters: {minContractMonths: 6, noticeMonths: 1}`) to `lib/Standards/rules/labour.json` per REQ-SIG-001/REQ-SIG-002 (design.md D1)
- [ ] 2. Corpus: add `hr-signals` to the framework examples list in `lib/Standards/rules/SCHEMA.md`; bump `RuleCatalogue::VERSION` `2026-07.5` → `2026-07.6` per REQ-SIG-002
- [ ] 3. Schema: add nullable `aanzegdOn` (date, title + description naming BW 7:668 lid 1) to `EmploymentContract` in `lib/Settings/register.d/hr-objects.json`; schema version 0.1.0 → 0.2.0; register `info.version` 0.5.0 → 0.6.0 in `lib/Settings/hrmq_register.json` per REQ-SIG-003
- [ ] 4. Context: add `RuleAuditService::buildSignalsContext()` (full-list `contractsByEmployeeId` index incl. object ids, empty-degrade) and wire `$context['signals']` in `audit()` next to the existing builders per REQ-SIG-004 (design.md D4)
- [ ] 5. Checks: create `lib/Standards/Checks/NlSignalChecks.php` — window-scoped contract-verloopt predicate (successor probe via the signals context, self-exclusion by id) and the object-local aanzegtermijn predicate (`DateTimeImmutable('today')` convention, vacuous-pass discipline per design.md D2)
- [ ] 6. Manifest: add the `dash-expiring-contracts` object-table widget (filter `{type: temporary, endDate: {gte: @today, lte: @today+60d}}`, columns employeeId/endDate/aanzegdOn, rowRoute `EmploymentContractDetail`, viewAll `EmploymentContracts`) + the appended layout row to the `Dashboard` page in `src/manifest.json`; update the page `_note`; cross-reference the 60-day window with the rule's `windowDays` in the widget `_note`; `npm run check:manifest` green per REQ-SIG-005
- [ ] 7. Seed: add `contract-devries-tijdelijk` (temporary, 2025-09-01 → 2026-08-01, `aanzegdOn: null`, `awfTariff: high`, wage/hours per design.md Seed Data, jurisdiction-neutral booleans) to `lib/Settings/register.d/hr-seed.json` per REQ-SIG-006
- [ ] 8. Unit tests: `tests/Unit/Standards/Checks/NlSignalChecksTest.php` — window edges (day 0/60/61, expired), successor shapes (none / valid successor / earlier sibling / overlapping), aanzeg deadline arithmetic (missed / timely / late-recorded / short term / permanent), empty-context degradation; extend `tests/Unit/Service/RuleAuditServiceTest.php` for the signals index
- [ ] 9. Quality gates: `composer check:strict` green; SPDX + `@spec` tags on new/changed PHP methods (gate-16); NO `x-openregister-notifications` introduced (gate-18 deferral per design)
- [ ] 10. Live verify in the dev container: re-import the register, run `occ hrmq:rules:audit` — catalogue `2026-07.6`, exactly the two intended violations on `contract-devries-tijdelijk`, no pre-existing rule regressed; open the Dashboard — "Aflopende contracten" lists the seed contract (design.md Seed Data)

Acceptance criteria (plain reminders, not tasks):
- no proeftijd or WML rule added — the HEAD investigation in the proposal is binding
- one rule id = one severity; advisory tier is `recommended` (no `advisory` enum value)
- predicates stay pure `fn(array $o, array $context): bool`; sibling facts only via `$context['signals']`
- i18n keys ENGLISH per ADR-007 (widget labels are NL display strings in the manifest, matching the existing Dashboard widgets)
