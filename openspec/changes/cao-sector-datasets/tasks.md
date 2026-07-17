# Tasks — cao-sector-datasets

> Depends on `cao-library` (merged, archived 2026-07-14): the corpus loader, the `{value, source,
> verified}` leaf discipline, the two below-CAO-minimum checks, the seed, and the reference pages.
> Verify against HEAD, not this brief — `CaoRegistry`/`NlCaoChecks`/the manifest may have moved on.

- [ ] 1. Corpus `lib/Standards/cao/cao-rijk.json` — BBRA-schalen 1–18 + chief subschalen
  (`payScales`, placeholder), IKB 16,50%/min €452/64u (`allowances`, **verified: true**, sourced
  `caorijk.nl`), verlof (`leaveEntitlement`, placeholder), 36u/week (`workingTime`, **verified:
  true**, sourced `caorijk.nl`) per REQ-CAOS-001 / design.md D3 / Seed Data
- [ ] 2. Corpus `lib/Standards/cao/cao-gemeenten.json` — VNG-schalen 1–19 (`payScales`, placeholder,
  checkAgainst `vng.nl`/LOGA salarisbrief), leave + working-time placeholder per REQ-CAOS-001 / Seed
  Data
- [ ] 3. Corpus `lib/Standards/cao/cao-onderwijs-po.json` — L10/L11/LA/LB/LC + OOP + DIR schalen
  (`payScales`, placeholder, checkAgainst PO-Raad), normjaartaak 1659 uur/jaar via
  `normjaartaakUrenPerJaar` (`workingTime`, placeholder per D4) per REQ-CAOS-001 / Seed Data
- [ ] 4. Corpus `lib/Standards/cao/cao-onderwijs-vo.json` — LB/LC/LD schalen (`payScales`,
  placeholder, checkAgainst VO-raad), leave + working-time placeholder per REQ-CAOS-001 / Seed Data
- [ ] 5. Corpus `lib/Standards/cao/cao-ziekenhuizen.json` — FWG-5..FWG-80 functiegroepen
  (`payScales`, placeholder, checkAgainst NVZ `cao-ziekenhuizen.nl`), ORT reference rates
  (`allowances`, placeholder, display-only per design.md Named gap 2), leave + working-time
  placeholder per REQ-CAOS-001 / Seed Data
- [ ] 6. Corpus `lib/Standards/cao/cao-zorg-vvt.json` — FWG functiegroepen (`payScales`, placeholder,
  checkAgainst ActiZ), ORT reference rates incl. the confirmed 2026-01-01 percentage change
  (`allowances`, placeholder, display-only per design.md Named gap 2), leave + working-time
  placeholder per REQ-CAOS-001 / Seed Data
- [ ] 7. Bump `CaoRegistry::VERSION`; confirm **no** `RuleCatalogue::VERSION` bump (design.md D6) per
  REQ-CAOS-006
- [ ] 8. Confirm (do not modify) `NlCaoChecks::checks()` / `seedObjects()` need zero changes — both
  are already generic over `CaoRegistry::availableCaos()` per REQ-CAOS-003 / REQ-CAOS-005 / design.md
  D5
- [ ] 9. Confirm (do not modify) `RuleAuditService::audit()` `cao.*` context enrichment needs zero
  changes per REQ-CAOS-003 / design.md D5
- [ ] 10. Confirm (do not modify) `hr-objects.json` `EmploymentContract.cao`/`caoSchaal` and
  `hr-cao.json` `Cao` schema need zero changes per REQ-CAOS-004 / design.md D5
- [ ] 11. Confirm (do not modify) `src/manifest.json` `Caos`/`CaoDetail` pages need zero changes;
  `npm run check:manifest` unaffected per REQ-CAOS-005 / design.md D5/D7
- [ ] 12. Tests: extend `tests/Unit/Standards/CaoRegistryTest.php` — `availableCaos()` lists all nine
  CAOs, each new file loads well-formed, resolvers return `null` for every new placeholder leaf, and
  `minMaandloonCents('cao-rijk', ...)`/`minLeaveHours('cao-rijk', ...)` still resolve `null` (its
  `payScales`/`leaveEntitlement` stay placeholder even though `allowances`/`workingTime` are
  verified) per REQ-CAOS-001 / REQ-CAOS-002
- [ ] 13. Tests: `tests/Unit/Standards/NlCaoChecksTest.php` — a contract naming each new CAO + a
  placeholder scale is vacuous (no violation); a `LeaveBalance` under each new CAO is vacuous; seed
  idempotency covers all nine `Cao` objects with no duplicates per REQ-CAOS-002 / REQ-CAOS-003 /
  REQ-CAOS-005
- [ ] 14. Live-verify: run `occ hrmq:rules:seed-test-data`, open the `Caos` index page, confirm all
  nine CAOs list (not just the original three) and `CaoDetail` renders each new CAO's scales/
  allowances/leave/working-time per REQ-CAOS-005
- [ ] 15. Quality gates: `composer lint` green, full PHPUnit suite green; SPDX + `@spec` tags
  unaffected (no PHP logic changed, only `CaoRegistry::VERSION`); i18n unaffected (no manifest
  labels added)

Acceptance criteria (plain reminders, not tasks):
- Every new leaf is `{value, source, verified}`; every placeholder leaf carries `checkAgainst`
  naming a real, current, named source (never a bare guess) per design.md Seed Data
- Only `cao-rijk`'s `allowances` and `workingTime` leaves are `verified: true`, each backed by a
  directly-fetched primary-source citation (design.md D3) — every other leaf across all six CAOs is
  `verified: false` / `placeholder: true`
- `CaoRegistry`, `NlCaoChecks`, `RuleAuditService`, `RuleCatalogue`, `hr-objects.json`, `hr-cao.json`
  and `src/manifest.json` are all touched by **zero** lines — only `cao/*.json` + `VERSION`
- The five named gaps (design.md "Named gaps") are documented as fast-follows, not built here
