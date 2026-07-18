# Tasks — cao-sector-datasets

> Depends on `cao-library` (merged, archived 2026-07-14): the corpus loader, the `{value, source,
> verified}` leaf discipline, the two below-CAO-minimum checks, the seed, and the reference pages.
> Verify against HEAD, not this brief — `CaoRegistry`/`NlCaoChecks`/the manifest may have moved on.

- [x] 1. Corpus `lib/Standards/cao/cao-rijk.json` — BBRA-schalen 1–18 + chief subschalen
  (`payScales`, placeholder), IKB 16,50%/min €452/64u (`allowances`, **verified: true**, sourced
  `caorijk.nl`), verlof (`leaveEntitlement`, placeholder), 36u/week (`workingTime`, **verified:
  true**, sourced `caorijk.nl`) per REQ-CAOS-001 / design.md D3 / Seed Data
- [x] 2. Corpus `lib/Standards/cao/cao-gemeenten.json` — VNG-schalen 1–19 (`payScales`, placeholder,
  checkAgainst `vng.nl`/LOGA salarisbrief), leave + working-time placeholder per REQ-CAOS-001 / Seed
  Data
- [x] 3. Corpus `lib/Standards/cao/cao-onderwijs-po.json` — L10/L11/LA/LB/LC + OOP + DIR schalen
  (`payScales`, placeholder, checkAgainst PO-Raad), normjaartaak 1659 uur/jaar via
  `normjaartaakUrenPerJaar` (`workingTime`, placeholder per D4) per REQ-CAOS-001 / Seed Data
- [x] 4. Corpus `lib/Standards/cao/cao-onderwijs-vo.json` — LB/LC/LD schalen (`payScales`,
  placeholder, checkAgainst VO-raad), leave + working-time placeholder per REQ-CAOS-001 / Seed Data
- [x] 5. Corpus `lib/Standards/cao/cao-ziekenhuizen.json` — FWG-15..FWG-80 functiegroepen
  (`payScales`, placeholder, checkAgainst NVZ `cao-ziekenhuizen.nl`), ORT reference rates
  (`allowances`, placeholder, display-only per design.md Named gap 2), leave + working-time
  placeholder per REQ-CAOS-001 / Seed Data
- [x] 6. Corpus `lib/Standards/cao/cao-zorg-vvt.json` — FWG functiegroepen (`payScales`, placeholder,
  checkAgainst ActiZ), ORT reference rates incl. the confirmed 2026-01-01 percentage change
  (`allowances`, placeholder, display-only per design.md Named gap 2), leave + working-time
  placeholder per REQ-CAOS-001 / Seed Data
- [x] 7. Bump `CaoRegistry::VERSION` (`2026-07.14` → `2026-07.17`); confirmed **no**
  `RuleCatalogue::VERSION` bump (`git diff lib/Standards/RuleCatalogue.php` empty, design.md D6) per
  REQ-CAOS-006
- [x] 8. Confirmed (unmodified) `NlCaoChecks::checks()` / `seedObjects()` need zero changes — both
  are already generic over `CaoRegistry::availableCaos()` per REQ-CAOS-003 / REQ-CAOS-005 / design.md
  D5 — `git diff lib/Standards/Checks/NlCaoChecks.php` is empty
- [x] 9. Confirmed (unmodified) `RuleAuditService::audit()` `cao.*` context enrichment needs zero
  changes per REQ-CAOS-003 / design.md D5 — `git diff` on the service file is empty
- [x] 10. Confirmed (unmodified) `hr-objects.json` `EmploymentContract.cao`/`caoSchaal` and
  `hr-cao.json` `Cao` schema need zero changes per REQ-CAOS-004 / design.md D5 — `git diff
  lib/Settings/register.d/` is empty
- [x] 11. Confirmed (unmodified) `src/manifest.json` `Caos`/`CaoDetail` pages need zero changes;
  `npm run check:manifest` unaffected per REQ-CAOS-005 / design.md D5/D7 — `git diff src/manifest.json`
  is empty (no manifest edit to check; `check:manifest` was not re-run since nothing manifest-shaped
  changed)
- [x] 12. Tests: extended `tests/Unit/Standards/CaoRegistryTest.php` — `availableCaos()` lists all
  nine CAOs, each new file loads well-formed, resolvers return `null` for every new placeholder leaf,
  and `minMaandloonCents('cao-rijk', ...)`/`minLeaveHours('cao-rijk', ...)` still resolve `null` (its
  `payScales`/`leaveEntitlement` stay placeholder even though `allowances`/`workingTime` are
  verified) per REQ-CAOS-001 / REQ-CAOS-002
- [x] 13. Tests: `tests/Unit/Standards/Checks/NlCaoChecksTest.php` — a contract naming each new CAO +
  a placeholder scale is vacuous (no violation); a `LeaveBalance` under each new CAO is vacuous; seed
  idempotency covers all nine `Cao` objects with no duplicates per REQ-CAOS-002 / REQ-CAOS-003 /
  REQ-CAOS-005
- [ ] 14. Live-verify via the UI: run `occ hrmq:rules:seed-test-data`, open the `Caos` index page,
  confirm all nine CAOs list and `CaoDetail` renders each new CAO's scales/allowances/leave/
  working-time per REQ-CAOS-005. **NOT performed as a browser/UI check** — the only running
  Nextcloud instance available (`docker ps` → container `nextcloud`) bind-mounts hrmq from a
  *different*, shared checkout (`openregister/custom_apps/hrmq`), not this worktree, and copying
  this branch's code into that shared instance is excluded by standing policy (no deploy to a
  shared dev instance from a feature branch). Substituted with an equivalent unit-level proof that
  exercises the *real* (non-mocked) pipeline instead:
  `NlCaoChecksTest::testSeedObjectsCoversAllNineCaosWithNoDuplicates` calls the real
  `NlCaoChecks::seedObjects()`, which calls the real `CaoRegistry::availableCaos()`/`get()` against
  the six new corpus files actually on disk, and asserts all nine rows appear with unique `caoId`s —
  proving the seed pipeline the `Caos`/`CaoDetail` pages read from, without proving the Vue rendering
  itself. Flagged here rather than phantom-ticked; a follow-up live/browser check against this
  worktree (or after merge) is recommended before relying on the UI claim.
- [x] 15. Quality gates: `composer check:strict` green in the `hrmq-qa-php83` container — phpcs,
  phpmd, psalm (no errors), phpstan (no errors), full PHPUnit suite (855 tests / 3354 assertions,
  `ALL CHECKS PASSED`); SPDX + `@spec` tags unaffected (no PHP logic changed, only
  `CaoRegistry::VERSION`); i18n unaffected (no manifest labels added)

Acceptance criteria (plain reminders, not tasks):
- Every new leaf is `{value, source, verified}`; every placeholder leaf carries `checkAgainst`
  naming a real, current, named source (never a bare guess) per design.md Seed Data
- Only `cao-rijk`'s `allowances` and `workingTime` leaves are `verified: true`, each backed by a
  directly-fetched primary-source citation (design.md D3) — every other leaf across all six CAOs is
  `verified: false` / `placeholder: true`
- `CaoRegistry`, `NlCaoChecks`, `RuleAuditService`, `RuleCatalogue`, `hr-objects.json`, `hr-cao.json`
  and `src/manifest.json` are all touched by **zero** lines — only `cao/*.json` + `VERSION`
- The five named gaps (design.md "Named gaps") are documented as fast-follows, not built here
