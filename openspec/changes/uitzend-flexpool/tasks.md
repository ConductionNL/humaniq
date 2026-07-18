# Tasks — uitzend-flexpool

> Verify against HEAD, not this brief — `EmploymentContract.type: agency`, `CaoRegistry`/
> `NlCaoChecks::minimumloonSchaalSatisfied()` (contract-type-agnostic), and the rostering/time-
> attendance schemas (`employeeId`-scoped only) are already merged at HEAD; this change only adds
> fields, rules and one CAO data file on top of them.

- [ ] 1. Schema: `EmploymentContract` (`hr-objects.json`) gains `uitzendFase` (enum `A`/`B`/`C`,
  nullable), `uitzendbedingVanToepassing` (boolean, nullable), `inlenersbeloningReferentie`
  (string, nullable) — each with title+description citing BW 7:691 lid 2 / WAADI art. 8 per
  REQ-UITZ-002/-003; version bump
- [ ] 2. CAO: NEW `lib/Standards/cao/cao-abu.json` — placeholder-marked `payScales`/`allowances`
  in the `cao-metaal-techniek` shape, `basedOn` a real ABU CAO-tekst URL, every leaf
  `verified: false, placeholder: true, checkAgainst` per REQ-UITZ-004
- [ ] 3. Corpus: add `nl-uitzendbeding-alleen-fase-a` and `nl-inlenersbeloning-onderbouwing-vereist`
  to `lib/Standards/rules/labour.json` (framework `hr-uitzend`, both mandatory,
  machineCheckable true, sourceUrl citing BW 7:691 / WAADI art. 8) per REQ-UITZ-005/-006; bump
  `RuleCatalogue::VERSION`; add `hr-uitzend` to `SCHEMA.md` framework examples
- [ ] 4. Checks: NEW `lib/Standards/Checks/NlUitzendChecks.php` — both predicates registered under
  `EmploymentContract`, each guarded `type === 'agency'` (vacuous otherwise) per
  REQ-UITZ-005/-006; auto-discovered
- [ ] 5. Seed: 2 new `EmploymentContract` seeds (1 compliant `agency`/fase A/beding true/CAO+
  onderbouwing populated; 1 intended violation fase B/beding true) in `hr-seed.json` per design.md
  Seed Data
- [ ] 6. Tests: `NlUitzendChecksTest` — fase-A+beding-true pass, fase-B+beding-true violation,
  beding-false-any-fase pass (vacuous), non-agency type never evaluated, onderbouwing-present pass,
  onderbouwing-absent-with-hourlyWage violation, onderbouwing-absent-without-hourlyWage vacuous per
  REQ-UITZ-005/-006
- [ ] 7. Tests: confirm `nl-cao-minimumloon-schaal` fires for an `agency` contract with
  `cao: "cao-abu"` + `caoSchaal` set once the placeholder figure is present — proves the D4 wiring
  without asserting a real compliance value (`CaoRegistryTest`/`NlCaoChecksTest` extension, or a
  new test class if isolation is cleaner) per REQ-UITZ-004
- [ ] 8. Tests: end-to-end `RuleAuditServiceTest` confirming the seeded audit reports exactly one
  new violation (the fase-B seed → `nl-uitzendbeding-alleen-fase-a`) and zero regressions
- [ ] 9. README: the agency-not-inlener scope decision (D1) stated plainly, plus the D2/D3
  unverified-figures notes, so a future contributor does not silently rebuild the inlener side or
  assert an uncited fase-A duration
- [ ] 10. Quality gates: `composer check:strict` all green; gate-28 (title+description on every new
  property); SPDX + `@spec` tags on `NlUitzendChecks.php`; confirm zero changes to
  `src/manifest.json`, `lib/Service/RuleAuditService.php`, and any rostering/time-attendance file
  (D5 — the overlap needs no code)

Acceptance criteria (plain reminders, not tasks):
- No `Bureau`/`InhuurOpdracht`/vendor-risk schema or service exists anywhere in the diff — verify
  by grepping the actual diff for any new schema file outside `hr-objects.json`/`cao-abu.json`
- `nl-uitzendbeding-alleen-fase-a` and `nl-inlenersbeloning-onderbouwing-vereist` never evaluate a
  non-`agency` contract — verify the `type === 'agency'` guard explicitly in the test suite
- no fasensysteem week-count or ABU/NBBU loontabel figure appears anywhere without
  `verified: false` + `checkAgainst` (design.md D2/D4)
- `rostering`/`time-attendance` files are untouched by this change's diff — the overlap is
  confirmed, not re-implemented
- i18n keys ENGLISH (ADR-007); Dutch display strings only in schema descriptions/manifest labels
  per existing convention
