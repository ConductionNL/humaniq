# Tasks — bhv-organisatie

> Verify against HEAD, not this brief — `NlSignalChecks.php` (two existing predicates, `hr-signals`
> framework), the "Aflopende contracten" dashboard widget shape, and `OrgUnit` are already merged
> at HEAD; this change only extends them.

- [x] 1. Schema: NEW fragment `lib/Settings/register.d/hr-bhv.json` — `BhvCertificering`
  (employeeId `$ref` Employee, rol enum `bhv_basis`/`hoofd_bhv`/`ehbo`/`ontruimingsleider`,
  certificaatBehaaldOp, certificaatGeldigTot, opleider, orgUnitId `$ref` OrgUnit nullable,
  administrationId; no lifecycle) per REQ-BHV-001
- [x] 2. Register: `lib/Settings/hrmq_register.json` `info.version` bump (new fragment picked up
  by the existing Repair import) — `0.10.0` → `0.11.0`
- [x] 3. Corpus: add `nl-bhv-certificaat-verloopt` to `lib/Standards/rules/labour.json` — framework
  `hr-signals` (existing, not new), severity `recommended`, `parameters: {"windowDays": 90}`,
  sourceUrl citing Arbowet art. 15 as the underlying duty (not the window itself) per REQ-BHV-002;
  bumped `RuleCatalogue::VERSION` `2026-07.26` → `2026-07.27`
- [x] 4. Checks: MODIFY `lib/Standards/Checks/NlSignalChecks.php` — added
  `'BhvCertificering' => ['nl-bhv-certificaat-verloopt' => ...]` as a sibling key in `checks()`'s
  returned array; the two existing `EmploymentContract` predicates untouched per REQ-BHV-002
- [x] 5. Manifest: `object-table` widget "Aflopende BHV-certificaten" on the Dashboard page,
  `source: {schema: BhvCertificering, filter: {certificaatGeldigTot: {gte: "@today", lte:
  "@today+90d"}}}`, `_note` documenting the 90-day sync with `parameters.windowDays` (the existing
  contracten-widget convention) per REQ-BHV-003
- [x] 6. Manifest: `BhvCertificeringen` index (employeeId, rol, certificaatGeldigTot, orgUnitId
  columns; filterable/groupable by orgUnitId and rol) + `BhvCertificeringDetail` (data + related
  Employee/OrgUnit + audit sidebar, no lifecycleActions) under the existing `VerlofVerzuimGroup`
  per REQ-BHV-004/-005; `npm run check:manifest` passes (0 errors, 104 pages)
- [x] 7. Seed: 1 clean `BhvCertificering` (`bhv-jansen-basis`, geldigTot 2027-07-18, outside
  window) + 1 intended violation (`bhv-visser-ehbo`, geldigTot 2026-09-01, 45 days out from the
  2026-07-18 seed anchor, inside the 90-day window) in `hr-seed.json` per design.md Seed Data
- [x] 8. Tests: `NlSignalChecksTest` extended — BHV clean-pass, BHV-inside-window violation
  (day 0/45/90 edges), BHV-outside-window pass (day 91, +1 year), already-expired pass, unparseable
  date pass, plus a test confirming the two existing contract-expiry predicates' registrations are
  unmodified per REQ-BHV-002 (29 test methods total, was 20)
- [x] 9. Tests: end-to-end `RuleAuditServiceTest` confirming the seeded audit reports exactly one
  new violation (the 45-day seed → `nl-bhv-certificaat-verloopt`), zero regressions, the bumped
  catalogue version + enforceable-rule-id check, the context-degrades-to-empty case, and a
  no-coverage-ratio-rule-id corpus-wide assertion (37 test methods total, was 30)
- [x] 10. README: the "no numeric coverage ratio, Arbowet art. 15 is RI&E-driven" note and the
  Asset/AED-equipment fast-follow pointer per proposal Non-goals
- [x] 11. Quality gates: `composer check:strict` ALL CHECKS PASSED (lint/phpcs/phpmd/psalm/phpstan
  all clean; test:all 860 tests / 3182 assertions); `npm run check:manifest` PASS (0 errors); `npm
  run build` green (webpack compiled, only pre-existing bundle-size warnings); gate-28
  (title+description present on every new `BhvCertificering` property); SPDX + `@spec` tags present
  on the modified `NlSignalChecks.php`

Acceptance criteria (plain reminders, not tasks):
- No numeric coverage ratio (e.g. "1 per 50") appears anywhere in code, corpus, manifest, or docs
  — verify by grepping the actual diff for any such figure; there should be none
- `nl-bhv-certificaat-verloopt` lives in the SAME `NlSignalChecks.php` file and the SAME
  `hr-signals` framework as the two existing predicates — verify no new `CheckProvider` class or
  framework slug was created
- the dashboard widget's window (90) and the corpus rule's `parameters.windowDays` (90) stay in
  sync — verify both are updated together if either changes, per the existing contracten-widget
  `_note` convention
- no `Location`/site schema is introduced; `orgUnitId` is the only scoping `$ref` on
  `BhvCertificering`
- i18n keys ENGLISH (ADR-007); Dutch display strings only in manifest labels/schema descriptions
  per existing convention
