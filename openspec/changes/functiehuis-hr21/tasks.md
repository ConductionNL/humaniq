# Tasks — functiehuis-hr21

> Depends on `cao-sector-datasets` (active, not yet merged) for `cao-gemeenten`'s numeric schaal key
> space — the seed data's intended meaning, not the check's runtime logic, relies on it (design.md
> Risks). Verify against HEAD, not this brief.

- [ ] 1. NEW fragment `lib/Settings/register.d/hr-hr21.json` (`x-hrmq-fragment: hr-hr21`) declaring
  `Normfunctie` (`allowCreate: false`, `x-schema-org: schema:Occupation`) with `functiecode`, `naam`,
  `functiegroep`, `caoSchaal` (string), `caoSchaalVerified` (boolean, default `false`),
  `caoSchaalSource` (string, nullable) per REQ-HR21-001
- [ ] 2. Schema: `EmploymentContract` (`lib/Settings/register.d/hr-objects.json`) gains
  `normfunctieId` (nullable, `$ref: Normfunctie`) per REQ-HR21-002
- [ ] 3. Corpus rule `nl-hr21-schaal-consistentie` in `lib/Standards/rules/payroll.json` (new
  `framework: hr21`, `EmploymentContract`, `mandatory`, `machineCheckable: true`, sourced VNG
  HR21/Cao Gemeenten) per REQ-HR21-003
- [ ] 4. Add `hr21` to `lib/Standards/rules/SCHEMA.md`'s framework-examples list per REQ-HR21-003
- [ ] 5. Bump `RuleCatalogue::VERSION` per REQ-HR21-003
- [ ] 6. Provider `lib/Standards/Checks/NlHr21Checks.php` (`CheckProvider`, auto-discovered):
  `nl-hr21-schaal-consistentie` predicate — vacuous when `normfunctieId` is null, unresolvable, or
  the resolved `Normfunctie.caoSchaalVerified` is `false`; else violates when
  `EmploymentContract.caoSchaal` != `Normfunctie.caoSchaal` per REQ-HR21-003
- [ ] 7. Provider `NlHr21Checks implements SeedsObjects` — seeds the illustrative `Normfunctie` rows
  per REQ-HR21-001 / REQ-HR21-005
- [ ] 8. Manifest: read-only `Normfuncties` index page (`allowCreate: false`) + `NormfunctieDetail`
  detail page, landing as a sibling sub-page of `CAO's`/`Salarisschalen` in the existing `Personeel`
  menu; `EmploymentContractDetail`'s `_note` updated to confirm `normfunctieId` renders; `npm run
  check:manifest` PASS per REQ-HR21-004
- [ ] 9. Seed data: 4-6 `Normfunctie` rows across 2-3 hoofdprocessen, each `caoSchaalVerified: false`
  with a `caoSchaalSource` naming HR21/VNG per REQ-HR21-005 / design.md Seed Data
- [ ] 10. Seed data: one `EmploymentContract` with `normfunctieId` set and matching `caoSchaal` (clean
  pass) per REQ-HR21-005 / design.md Seed Data
- [ ] 11. Seed data: one `EmploymentContract` with `normfunctieId` set and a mismatched `caoSchaal`
  (violation branch) — requires flipping that one seed `Normfunctie`'s `caoSchaalVerified` to `true`
  so the rule is not vacuous for this specific proof case, per REQ-HR21-005 / design.md Seed Data
- [ ] 12. Tests: `tests/Unit/Standards/NlHr21ChecksTest.php` — the violation branch, the clean pass,
  vacuous pass for null/unresolvable `normfunctieId` and for placeholder (unverified) mappings, and
  for the entire pre-existing contract population, driving the REAL `RuleEngine` + catalogue per
  REQ-HR21-003 / REQ-HR21-005
- [ ] 13. Tests: seed idempotency — `seedObjects()` twice yields no duplicate `Normfunctie` rows per
  REQ-HR21-001
- [ ] 14. Quality gates: `composer lint` green, full PHPUnit suite green, `npm run check:manifest`
  PASS; SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH, Dutch only
  in manifest labels

Acceptance criteria (plain reminders, not tasks):
- the seeded `Normfunctie` set is explicitly illustrative, never presented or documented as a
  complete ~150-function library (D1, Non-Goals)
- `normfunctieId` is HR-set on the contract, exactly like `cao`/`caoSchaal` (D2) — no derivation,
  no approval workflow
- the check compares two recorded strings; it computes no salary and proposes no `CompAdjustment`
  (D3) — a mismatch is a signal for HR, not an automatic consequence
- a placeholder (unverified) `Normfunctie.caoSchaal` mapping is advisory, never a false mandatory
  violation (D4) — the `cao-library` D5 precedent, one layer up
