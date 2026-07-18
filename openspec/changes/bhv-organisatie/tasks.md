# Tasks — bhv-organisatie

> Verify against HEAD, not this brief — `NlSignalChecks.php` (two existing predicates, `hr-signals`
> framework), the "Aflopende contracten" dashboard widget shape, and `OrgUnit` are already merged
> at HEAD; this change only extends them.

- [ ] 1. Schema: NEW fragment `lib/Settings/register.d/hr-bhv.json` — `BhvCertificering`
  (employeeId `$ref` Employee, rol enum `bhv_basis`/`hoofd_bhv`/`ehbo`/`ontruimingsleider`,
  certificaatBehaaldOp, certificaatGeldigTot, opleider, orgUnitId `$ref` OrgUnit nullable,
  administrationId; no lifecycle) per REQ-BHV-001
- [ ] 2. Register: `lib/Settings/hrmq_register.json` `info.version` bump (new fragment picked up
  by the existing Repair import)
- [ ] 3. Corpus: add `nl-bhv-certificaat-verloopt` to `lib/Standards/rules/labour.json` — framework
  `hr-signals` (existing, not new), severity `recommended`, `parameters: {"windowDays": 90}`,
  sourceUrl citing Arbowet art. 15 as the underlying duty (not the window itself) per REQ-BHV-002;
  bump `RuleCatalogue::VERSION`
- [ ] 4. Checks: MODIFY `lib/Standards/Checks/NlSignalChecks.php` — add
  `'BhvCertificering' => ['nl-bhv-certificaat-verloopt' => ...]` as a sibling key in `checks()`'s
  returned array; the two existing `EmploymentContract` predicates untouched per REQ-BHV-002
- [ ] 5. Manifest: `object-table` widget "Aflopende BHV-certificaten" on the Dashboard page,
  `source: {schema: BhvCertificering, filter: {certificaatGeldigTot: {gte: "@today", lte:
  "@today+90d"}}}`, `_note` documenting the 90-day sync with `parameters.windowDays` (the existing
  contracten-widget convention) per REQ-BHV-003
- [ ] 6. Manifest: `BhvCertificeringen` index (employee via related, rol, certificaatGeldigTot,
  orgUnit; filterable/groupable by orgUnitId and rol) + `BhvCertificeringDetail` (data + related
  Employee/OrgUnit + audit sidebar, no lifecycleActions) under the existing `VerlofVerzuimGroup`
  per REQ-BHV-004/-005; `npm run check:manifest` passes
- [ ] 7. Seed: 1 clean `BhvCertificering` (geldigTot outside window) + 1 intended violation
  (geldigTot 45 days out, inside the 90-day window) in `hr-seed.json` per design.md Seed Data
- [ ] 8. Tests: `NlSignalChecksTest` extended — BHV clean-pass, BHV-inside-window violation,
  BHV-outside-window pass, confirm the two existing contract-expiry predicates' test cases are
  unmodified and still pass per REQ-BHV-002
- [ ] 9. Tests: end-to-end `RuleAuditServiceTest` confirming the seeded audit reports exactly one
  new violation (the 45-day seed → `nl-bhv-certificaat-verloopt`) and zero regressions
- [ ] 10. README: the "no numeric coverage ratio, Arbowet art. 15 is RI&E-driven" note and the
  Asset/AED-equipment fast-follow pointer per proposal Non-goals
- [ ] 11. Quality gates: `composer check:strict` all green; `npm run check:manifest` PASS; `npm run
  build` green; gate-28 (title+description on every new property); SPDX + `@spec` tags on the
  modified `NlSignalChecks.php`

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
