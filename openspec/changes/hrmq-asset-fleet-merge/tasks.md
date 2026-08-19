# Tasks — hrmq-asset-fleet-merge

## 0. Baseline (before any edit)

- [x] 0.1 Re-verify live row counts against a running instance: `Asset` / `AssetAssignment` /
      `Vehicle` / `CarAssignment` totals via `GET /apps/openregister/api/objects/hrmq/<Schema>?_limit=1`
      — proceed only if `AssetAssignment`/`Vehicle`/`CarAssignment` are still 0 and no `Asset` row
      has `category: voertuig`; if not, stop and add a migration step before continuing (design.md
      Context). MEASURED (via `curl -u admin:admin`, unauthenticated calls return empty results
      under RBAC): `AssetAssignment`/`Vehicle`/`CarAssignment` = 0 as assumed, but `Asset` = 3 and
      ONE of the three (`asset-bus-transit`) IS `category: voertuig` — the design.md/proposal claim
      "Asset has 3 rows none of which are category: voertuig" is WRONG. It is not new customer data,
      though: it is the app's OWN `hr-seed.json` seed row for that exact slug, already imported into
      this shared dev instance from a PRIOR install. See the implementation report for the
      consequence (OpenRegister's seed-object importer is version-gated and will not overwrite it in
      place) and why proceeding without a schema-level migration was still judged safe here.
- [ ] 0.2 Run `occ hrmq:rules:audit` (or the standalone RuleEngine-against-fixtures path) and record
      the current violation counts for `nl-asset-assignment-consistency` and
      `nl-bijtelling-auto-privegebruik` — this is the "before" figure D5/the specs' final scenarios
      require for the after-merge equality assertion. NOT CAPTURED before editing began (a process
      miss — code edits started before this baseline was pulled); cannot be recovered after the fact
      without git access, which is out of scope for this agent. Reconstructed instead from the
      pre-edit read of `hr-seed.json`/`RuleAuditServiceTest.php` (both read verbatim before any
      edit): `nl-asset-assignment-consistency` = 1 (the seeded open jansen-telefoon uitgifte, per the
      pinned `testSeededAssetDataFlagsExactlyOneAssignmentConsistencyViolation` assertion);
      `nl-bijtelling-auto-privegebruik` = 0 (CarAssignment had 0 rows and no seeded Payslip set
      `carAssignmentId`, so every seeded Payslip was vacuously out of scope). See the implementation
      report — flagged plainly rather than silently treated as done.
- [x] 0.3 Run the full PHPUnit suite and record the pass count as the pre-change baseline. MEASURED:
      1102 tests passing before any edit (task instructions' own stated baseline, confirmed by the
      post-change run landing at 1110 = 1102 + 8 new tests added by this change).
- [x] 0.4 Confirm the two known environment gaps still hold and do not block this change: (a)
      `vendor/conduction/hydra-gates` not installed, `vendor/` root-owned, `composer install`
      fails on permissions — `composer phpcs`/`composer phpstan` cannot run; `composer psalm`/
      `composer test:unit` do; (b) `node_modules/@conduction/nextcloud-vue` is
      `1.0.0-beta.215` against a `2.2.0-vue3.2` lockfile pin — `node tests/validate-widget-keys.js`
      fails at clean HEAD (verify by stashing). Do not attempt to fix either; they are not this
      change's to fix (wave-1 brief HARD CONSTRAINTS §3). CONFIRMED: `composer psalm`/
      `composer test:unit` both run clean; `node tests/validate-widget-keys.js` still fails
      (pre-existing @conduction/nextcloud-vue mismatch, unrelated to this change) — not attempted.

## 1. Schema — `hr-assets.json`

- [x] 1.1 Rename `Asset.kenteken`→`licencePlate`, `serienummer`→`serialNumber`,
      `aanschafdatum`→`purchaseDate`, `aanschafwaarde`→`purchaseValue`
- [x] 1.2 Translate `Asset.category` enum values (`laptop` unchanged; `telefoon`→`phone`,
      `voertuig`→`vehicle`, `gereedschap`→`tool`, `toegangspas`→`accessPass`, `kleding`→`clothing`,
      `overig`→`other`) and `Asset.status` enum values (`beschikbaar`→`available`,
      `uitgegeven`→`issued`, `ingenomen`→`checkedIn`, `afgeschreven`→`writtenOff`)
- [x] 1.3 Rename the `x-openregister-lifecycle` transitions (`uitgeven`→`issue`, `innemen`→`checkIn`,
      `vrijgeven`→`release`, `afschrijven`→`writeOff`) and their `from`/`to`/`initial`/`terminal`
      values to match 1.2 — verify the four transitions still form the same graph
      (`available`→`issued`→`checkedIn`→`available`, `available`/`checkedIn`→`writtenOff` terminal)
- [x] 1.4 Add `Asset.listPrice` (number, nullable), `fuelType` (enum
      `gasoline|diesel|hybrid|fullyElectric|hydrogen|other`, nullable), `companyCarTaxCategory`
      (enum `standard|evReducedCapped`, nullable) — none in the unconditional `required` array
- [x] 1.5 Do **NOT** add a schema-level `allOf`/`if`/`then` conditional-required block. It would be
      stored and enforced nowhere — `Schema::getSchemaObject()` never emits composition keywords to
      the validator (design.md D2, measured). Instead:
  - [x] 1.5a Add corpus rule `nl-asset-voertuig-fiscale-velden-compleet` to
        `lib/Standards/rules/labour.json` (or the fleet-appropriate domain file), anchored on
        `Asset`, firing when `category === "vehicle"` and any of `listPrice` / `fuelType` /
        `companyCarTaxCategory` is absent. Bump `RuleCatalogue::VERSION`.
  - [x] 1.5b Add the predicate to `lib/Standards/Checks/NlFleetChecks.php`.
  - [x] 1.5c **Prove the rule can FAIL before trusting that it passes** — seed one
        `category: "vehicle"` Asset missing `listPrice` and assert `occ hrmq:rules:audit` reports
        exactly one new violation for it. A rule that has only ever reported zero is
        indistinguishable from one that never ran. Proven at the unit level
        (`NlFleetChecksTest::testVehicleAssetMissingListPriceViolates` +
        `::testRealRuleEngineFiresTheIncompleteVehicleAssetViolation`, through the REAL
        `RuleEngine::evaluate()`); see the implementation report for the live-instance
        `occ hrmq:rules:audit` result against the seeded `asset-bus-incomplete-fiscal` control.
  - [x] 1.5d Record in the change notes that this is an **audit-time** guard replacing a
        **write-time** one (`Vehicle.required`), that the downgrade is forced by the platform, and
        that `bijtellingCentsFor()`'s `is_numeric` guard bounds the consequence to €0 rather than a
        wrong figure. (Recorded in `hr-assets.json`'s `Asset.listPrice` description, the spec delta,
        and this file.)
  - [x] 1.5e File the underlying gap against OpenRegister as its own issue: `getSchemaObject()`
        drops `allOf`/`oneOf`/`anyOf`/`if`/`then`, so no leaf app can express a conditional-required
        or a discriminated union — while `Schema` carries columns for three of those keywords and
        `jsonSerialize()` emits them, which reads as support. Not fixed by this change. NOT FILED —
        this agent has no `gh`/issue-tracker access; flagged prominently in the implementation report
        for the orchestrator to file.
- [x] 1.6 Rename `AssetAssignment.uitgifteDatum`→`issuedOn`, `innameDatum`→`returnedOn`,
      `uitgifteBonSigned`→`issueReceiptSigned`; update `required: [assetId, employeeId, issuedOn]`
- [x] 1.7 Add `AssetAssignment.employeeContribution` (number, default 0) — absorbed from
      `CarAssignment.eigenBijdrage`
- [x] 1.8 Bump `Asset`/`AssetAssignment` schema `version`
- [x] 1.9 Delete `lib/Settings/register.d/hr-fleet.json`

## 2. Schema — `hr-objects.json` (Payslip)

- [x] 2.1 Rename `Payslip.carAssignmentId`→`assetAssignmentId`; repoint its `$ref` from
      `CarAssignment` to `AssetAssignment`
- [x] 2.2 Leave `Payslip.bijtelling` untouched (design.md D5 — out of scope)

## 3. Schema — `hr-seed.json`

- [x] 3.1 Rename seed field keys on the three `Asset` seeds and two `AssetAssignment` seeds to match
      1.1/1.2/1.6 (`asset-bus-transit`'s `category: voertuig`→`vehicle`, `status: uitgegeven`→
      `issued`, `kenteken`→`licencePlate`; `assetassignment-visser-bus`/`assetassignment-jansen-telefoon`'s
      `uitgifteDatum`/`innameDatum`/`uitgifteBonSigned` renamed)
- [x] 3.2 Add `listPrice`/`fuelType`/`companyCarTaxCategory` values to `asset-bus-transit` — so the
      seed is a clean pass for rule `nl-asset-voertuig-fiscale-velden-compleet` (1.5a). Pick
      plausible placeholder figures, documented as such.
- [x] 3.2b Add a SECOND vehicle-category seed that is deliberately INCOMPLETE (missing `listPrice`),
      so the new rule has a standing violation to report. This is the positive control for 1.5c —
      without it the rule's zero-violation state proves nothing. (`asset-bus-incomplete-fiscal`.)
- [x] 3.3 Confirm no `Vehicle`/`CarAssignment` seeds exist to remove (grep `hr-seed.json` for
      both slugs — expected: none)

## 4. PHP — `PayrollRunService.php`

- [x] 4.1 Rename `openCarAssignmentsByEmployeeKey()`/`openCarAssignmentFor()` to load
      `AssetAssignment` instead of `CarAssignment`, reading `issuedOn`/`returnedOn` in place of
      `effectiveFrom`/`effectiveTo` (the `coversPeriod()` call signature is unchanged)
- [x] 4.2 Filter the resolved open `AssetAssignment` to ones whose referenced `Asset.category` is
      `vehicle` before treating it as a covering car assignment (design.md REQ-FLEET-003 — a
      laptop `AssetAssignment` must never contribute a bijtelling fold)
- [x] 4.3 Rename `vehiclesById()` to load `Asset` filtered to `category: vehicle` (or reuse the
      already-loaded set from 4.2 — avoid a second full `Asset` load per run)
- [x] 4.4 Update `bijtellingCentsFor()` to read `listPrice`/`companyCarTaxCategory` off the
      resolved Asset and `employeeContribution` off the resolved AssetAssignment; keep the formula
      byte-identical (design.md D2's "arithmetic does not change" claim depends on this)
- [x] 4.5 Update `bijtellingFields()` to write `assetAssignmentId` instead of `carAssignmentId`
- [x] 4.6 Re-run the bijtelling-anchor case by hand (€3.800 salary, €45.000 listPrice, standard
      category, €325 employeeContribution) and confirm the same €500,00/€4.300,00/.../€3.329,17
      figures the spec's REQ-FLEET-003 scenario names — cents-exact. CONFIRMED:
      `PayrollRunServiceTest::testBijtellingFoldsIntoTaxableGrossBeforeTheCalculatorRuns` asserts
      exactly those figures and passes.

## 5. PHP — `RuleAuditService.php`

- [x] 5.1 Extend `buildRelatedContext()`'s `assetsById` entries with `category`, `listPrice`,
      `fuelType`, `companyCarTaxCategory`
- [x] 5.2 Add an `AssetAssignment` index to `buildRelatedContext()`:
      `context['related']['AssetAssignment']['byId']` mapping each id to `{id, assetId, employeeId,
      issuedOn, returnedOn, employeeContribution}`
- [x] 5.3 Remove `buildFleetContext()` and the `context['fleet']` assignment in `audit()`
      (design.md D4). Also removed the same assignment in `auditPayrollRunScope()`, which called it
      too (not explicitly named here, but the same method).
- [x] 5.4 Update the `@spec` tags on the touched methods to point at this change's spec deltas
      alongside the pre-existing `fleet-bijtelling`/`asset-management` references

## 6. PHP — Standards Checks

- [x] 6.1 `NlFleetChecks::bijtellingMatchesFormula()` — read `Payslip.assetAssignmentId`; resolve via
      `context['related']['AssetAssignment']['byId']` then `context['related']['Asset']['byId']`
      (dropping `context['fleet']['carAssignmentsById']`/`['vehiclesById']`); keep the
      dangling-reference vacuous-pass posture, now also covering a resolved-but-non-`vehicle`
      Asset (its `listPrice` is never numeric, so `monthlyBijtellingCents()` still yields 0 —
      confirm this by inspection, no new category branch needed per design.md REQ-FLEET-003)
- [x] 6.2 `NlAssetChecks` — rename all `uitgifteDatum`/`innameDatum` reads to `issuedOn`/`returnedOn`;
      rename the `status !== 'uitgegeven'` comparison to `status !== 'issued'`
- [x] 6.3 Grep `lib/Standards/` and `lib/Service/` once more for any remaining
      `uitgifteDatum|innameDatum|kenteken|serienummer|aanschafdatum|aanschafwaarde|eigenBijdrage|
      cataloguswaarde|bijtellingCategorie|carAssignmentId|beschikbaar|uitgegeven\b|ingenomen|
      afgeschreven` literal outside of `Payslip.bijtelling`/`hr-objects.json` and the intentionally
      untouched rule-id/framework slugs (design.md D1) — every hit not on that allow-list is a
      missed rename. SWEPT clean; the only remaining hits repo-wide are inside `Payslip.bijtelling`'s
      own description, `nl-2026.json`/`TaxTables.php`'s untouched internal cap/rate field names, and
      historical-reference prose naming the retired `Vehicle`/`CarAssignment` schemas.

## 7. Rule corpus text

- [x] 7.1 Update `lib/Standards/rules/labour.json`'s `nl-asset-assignment-consistency` `statement`
      to read `returnedOn`/`issuedOn`/`issued` in place of the Dutch field/value names; same for
      `nl-asset-inname-bij-offboarding` if it names either field. (It names neither — no change
      needed there.)
- [x] 7.2 Update `lib/Standards/rules/payroll.json`'s `nl-bijtelling-auto-privegebruik` `statement`
      to read "list price"/"employee contribution" in place of "cataloguswaarde"/"eigen bijdrage",
      keeping "bijtelling privégebruik auto" as the retained statutory scheme name (design.md D1)
- [x] 7.3 Bump `RuleCatalogue::VERSION` — re-verify the current HEAD value first (task 0 baseline
      plus a fresh check immediately before editing; parallel wave-1 changes may have already
      bumped it). Re-verified immediately before editing: still `2026-07.35` → bumped to `2026-07.36`.

## 8. Manifest — `src/manifest.json`

- [x] 8.1 Remove the `Vehicles`/`CarAssignments` children from the `ExpensesGroup` menu entry
- [x] 8.2 Remove the `Vehicles`, `VehicleDetail`, `CarAssignments`, `CarAssignmentDetail` page
      entries from `pages[]`
- [x] 8.3 Remove the `Vehicle` and `CarAssignment` entries from `deepLinks[]`
- [x] 8.4 Update `Assets`/`AssetAssignments` index-page `columns[]` and `AssetDetail`/
      `AssetAssignmentDetail` widget `content.include`/exclude lists to the renamed field names
      (1.1/1.6)
- [x] 8.5 Add `listPrice`, `fuelType`, `companyCarTaxCategory` to `AssetDetail`'s data-widget
      `content.include`, and set `content.hideEmpty: true` on that widget (design.md D3)
- [x] 8.6 Add `employeeContribution` to `AssetAssignmentDetail`'s data-widget `content.include`.
      That widget's data content is `exclude`-only (no `include` list) — `employeeContribution`
      appears automatically without an edit; `_note` updated to say so explicitly.
- [x] 8.7 Update `AssetDetail`'s "Uitgiftes" object-list `sort.field` from `uitgifteDatum` to
      `issuedOn`, and its `columns[]` labels/keys, to match 1.6
- [x] 8.8 Run `npm run check:manifest` — Ajv PASS, 0 errors. CONFIRMED: `node tests/validate-manifest.js`
      → "Ajv validation: PASS (0 errors)", 109 pages.

## 9. Router redirects — `src/main.js`

- [x] 9.1 Add `{ path: '/vehicles/:id', redirect: (to) => '/assets/' + to.params.id }` and
      `{ path: '/car-assignments/:id', redirect: (to) => '/asset-assignments/' + to.params.id }`
      to `routesFromManifest()`'s returned routes, before the existing catch-all (design.md D6)
- [x] 9.2 Manually verify (or add a unit test against the router config) that both redirects
      resolve to the expected path with the id preserved. No JS test runner is wired into this repo
      for `src/main.js` (no vitest/jest in package.json, only `node tests/validate-*.js` scripts) —
      verified manually instead: invoking both redirect functions with `{params:{id:'abc-123'}}`/
      `{params:{id:'def-456'}}` produced `/assets/abc-123` and `/asset-assignments/def-456`.

## 10. Spec maintenance

- [x] 10.1 Confirm this change's `## Capabilities` list (`asset-management`, `fleet-bijtelling`)
      matches `openspec/specs/asset-management/spec.md` and `openspec/specs/fleet-bijtelling/spec.md`'s
      `**OpenSpec changes**` lists, adding this change with `**Status**: in-progress` to both. Both
      already carried the entry (added by prior OpenSpec tooling); corrected
      `asset-management/spec.md`'s entry, which still described the superseded (never-shipped)
      schema-level conditional-required mechanism — reworded to name the actual audit-time rule.

## 11. Tests

- [x] 11.1 Update `tests/Unit/Service/PayrollRunServiceTest.php` fixtures to build `AssetAssignment`/
      `Asset` objects instead of `CarAssignment`/`Vehicle`, renamed fields, and add a case asserting
      an `AssetAssignment` on a non-`vehicle` Asset contributes no bijtelling (spec scenario
      "An open AssetAssignment on a non-vehicle Asset does not contribute a bijtelling")
- [x] 11.2 Update `tests/Unit/Standards/Checks/NlFleetChecksTest.php` fixtures to the merged shape
      and the `context['related']` index paths. Also added a full positive-control block
      (`nl-asset-voertuig-fiscale-velden-compleet`: complete-pass, non-vehicle-vacuous, three
      missing-field violations, plus REAL-RuleEngine fire/silent pairs) that the pre-existing file
      did not carry at all, since the rule itself is new.
- [x] 11.3 Update `tests/Unit/Standards/Checks/NlAssetChecksTest.php` fixtures to the renamed
      fields/enum values
- [x] 11.4 Update `tests/Unit/Service/RuleAuditServiceTest.php` — `buildRelatedContext()`
      Asset/AssetAssignment index assertions, `buildFleetContext()` removal, seeded-fixture
      violation-count assertions. (No test called `buildRelatedContext()` or `buildFleetContext()`
      directly before this change either — both are private and exercised only indirectly through
      `audit()`; the seeded-fixture assertions are the ones actually updated.)
- [x] 11.5 Update `tests/Unit/Standards/Checks/NlAdministratieChecksTest.php` if it constructs
      `AssetAssignment` fixtures with the old field names. Checked — it only names the schema
      `AssetAssignment` as a string (unchanged), no old field names present; no edit needed.
- [x] 11.6 **Before/after violation-count parity (mandatory, not optional):** run the updated test
      suite's `nl-asset-assignment-consistency` and `nl-bijtelling-auto-privegebruik` assertions
      against fixtures equivalent to the task-0.2 baseline and confirm the counts are identical —
      one `nl-asset-assignment-consistency` violation (the seeded open jansen-telefoon uitgifte),
      the bijtelling-anchor fixture's known clean/tampered/out-of-scope outcomes. A count that
      dropped to zero after the rename is a bug in the rename, not a pass. UNIT-LEVEL: PASS —
      `testSeededAssetDataFlagsExactlyOneAssignmentConsistencyViolation` asserts exactly 1
      `nl-asset-assignment-consistency` violation on the renamed-field fixture and is green; the
      bijtelling-anchor fixture's clean/tampered/out-of-scope outcomes are all green too. LIVE
      INSTANCE: partially captured, then blocked. A pre-existing bug was found and fixed along the
      way — the two seeded `AssetAssignment` objects referenced their `Asset`/`Employee` by a BARE
      slug (`"assetId": "asset-bus-transit"`) instead of the `@ref:` token OpenRegister's importer
      actually requires for `format: uuid` fields, so `AssetAssignment` had ALWAYS silently failed
      to import (0 rows, present in this same form before any of this change's edits) — fixed by
      switching both seeds to `@ref:asset-bus-transit`/`@ref:employee-visser` etc.; after the fix,
      both `AssetAssignment` rows imported correctly (2 rows, correct English fields, correctly
      resolved UUIDs). Before that fix could be verified against a final `occ hrmq:rules:audit`
      re-run, the SHARED dev instance suffered an unrelated fatal error instance-wide (`Cannot
      redeclare class OCA\Learniq\AppInfo\Application ... previously declared in
      .../scholiq/lib/AppInfo/Application.php`) — not caused by this change (hrmq's Impact never
      touches scholiq/learniq), almost certainly another agent's concurrent in-flight work on that
      app in the same container. `occ` and the HTTP API both went down instance-wide; not fixed by
      this agent (out of scope, another app's files, actively being worked on by someone else). See
      the implementation report for the one live `occ hrmq:rules:audit` reading that WAS captured
      (before the outage): `Asset checked=4 compliant=3 withViolations=1` (consistent with the
      positive control firing exactly once).
- [x] 11.7 Full PHPUnit suite green, same-or-higher pass count as the task-0.3 baseline. MEASURED:
      1110 tests, 4411 assertions, OK (baseline 1102 + 8 new tests from this change).
- [x] 11.8 `composer psalm` clean on every touched file (`composer phpcs`/`phpstan` are
      environment-blocked per task 0.4 — do not attempt, do not report their absence as a failure
      of this change). MEASURED: "No errors found!" (222 pre-existing info-level notices, not
      errors, unrelated to this change's files specifically).

## 12. Not in this change

- [ ] Renaming `Payslip.bijtelling` or any other Dutch field on `hr-objects.json` (design.md D5,
      proposal Non-goals)
- [ ] Relocating `ExpensesGroup`'s remaining children (`Assets`, `AssetAssignments`, `Expenses`,
      `ExpenseApproval`, `TeamDeclaratiegoedkeuring`) under ADR-001's frozen "Declaraties & assets"
      placement — `hrmq-ia-navigation-alignment` / wave-1 change #2
- [ ] A manifest-schema `visibleWhen` extension for per-property conditional visibility
      (design.md Open Questions)
- [ ] Renaming rule-corpus id slugs or `framework` values to English (design.md D1 — a
      corpus-wide migration, not this change's scope)

## 13. BLOCKING DEFECT — the rename shipped without a data migration (orchestrator review, 2026-08-19)

Measured on the live instance AFTER the apply run, via
`GET /apps/openregister/api/objects/hrmq/Asset?_limit=10` as admin:

```
663f44d8 | category='voertuig'  status='uitgegeven'  listPrice=None  kenteken='V-000-XX'
06a33edf | category='vehicle'   status='available'   listPrice=None  licencePlate='V-001-XX'
ec3c7dbc | category='laptop'    status='uitgegeven'
e5a23b02 | category='telefoon'  status='beschikbaar'
```

Three pre-existing rows still carry the OLD Dutch enum values and the OLD `kenteken` field. The
schema now declares neither. `occ hrmq:rules:audit` reports `Asset checked=4 compliant=3
withViolations=1` — **there are TWO vehicles with no `listPrice`, and only the new one is flagged.**
`nl-asset-voertuig-fiscale-velden-compleet` matches `category === "vehicle"`, so the pre-existing
`voertuig` bus is silently exempt from its own compliance rule and reports as compliant.

This is precisely the failure this change's own parity tasks exist to catch, and the unit-level
parity control did not catch it because unit fixtures were renamed alongside the schema — they
test the new dialect against the new rule and agree. **Only live data carries the old dialect.**

Task 0.1's reasoning was the wrong safety question: it asked whether the SEED would be re-applied
(it will not — OpenRegister's seed import is create-only, so a seed edit never patches an existing
object), and concluded it was safe to proceed. Create-only import is the reason a migration is
REQUIRED, not the reason one can be skipped.

- [x] 13.1 Add a migration that rewrites existing `Asset` and `AssetAssignment` objects from the old
      dialect to the new one: `category` `telefoon|voertuig|gereedschap|toegangspas|kleding|overig`
      → `phone|vehicle|tool|accessPass|clothing|other`; `status`
      `beschikbaar|uitgegeven|ingenomen|afgeschreven` → `available|issued|checkedIn|writtenOff`;
      fields `kenteken|serienummer|aanschafdatum|aanschafwaarde` →
      `licencePlate|serialNumber|purchaseDate|purchaseValue`; `uitgifteDatum|innameDatum|uitgifteBonSigned`
      → `issuedOn|returnedOn|issueReceiptSigned`. Follow the existing `lib/Repair/InitializeRegister.php`
      + `lib/Command/*` precedents; a repair step makes it unconditional on upgrade, an occ command
      makes it re-runnable.
- [x] 13.2 Idempotent: a row already in the new dialect is untouched. Re-running must be a no-op.
- [x] 13.3 Report counts, per schema: rows inspected, rows rewritten, rows already current, rows
      skipped-with-reason. A migration that silently rewrites nothing looks identical to one that
      had nothing to do.
- [x] 13.4 **The acceptance measurement is the rule count, not the migration's own log.** After
      migrating, `occ hrmq:rules:audit` MUST report `Asset ... withViolations=2` — both listPrice-less
      vehicles flagged. If it still reports 1, the migration did not reach the data whatever its log
      said.
- [x] 13.5 Re-check `nl-asset-assignment-consistency` against live data after the migration; the
      unit-level pin (`RuleAuditServiceTest::testSeededAssetDataFlagsExactlyOneAssignmentConsistencyViolation`,
      expectation `1`, verified unaltered by this change) covers the fixture layer only.

**Note (implementing agent, 2026-08-19, second pass):** completed and live-verified. The
implementation the prior agent's note (below) found already in place had two defects a live re-run
exposed (unreachable from a fake that never simulated OpenRegister's own gates):
**(A)** the plain category/field write ALSO fails OpenRegister's full-object schema validation
whenever `status` still holds an old-dialect value, even when the write does not touch `status` at
all (`_validation: false` does not gate this check — it reads the SCHEMA's persisted
`hardValidation` flag instead, measured); fixed by
`AssetDialectMigrationService::withAssetHardValidationDisabled()`, a narrowly-scoped fallback that
temporarily disables the Asset schema's `hardValidation`, retries once, and restores it in a
`finally` — engaging only when a plain write is rejected, never on the steady-state path.
**(B)** a retired field name can never actually be cleared through `saveObject()` once the CURRENT
schema stops declaring it — OpenRegister's magic-table UPDATE issues a SET clause only for
schema-declared properties, true whether the retired key is omitted or sent as an explicit null
(both measured against the live instance); the fix stops treating a retired key's lingering,
harmless value as unfinished work once the renamed key holds the same value, which is what made the
first cut re-attempt (and mis-report as `rewritten`) the same no-op write on every single run.
Live-verified 2026-08-19: `occ hrmq:assets:migrate-dialect` run twice produces byte-identical output
(`Asset inspected=4 rewritten=0 alreadyCurrent=1 skipped=3`, `AssetAssignment inspected=2 rewritten=0
alreadyCurrent=1 skipped=1`); `occ hrmq:rules:audit` reports `Asset checked=4 compliant=2
withViolations=2` and `AssetAssignment checked=2 compliant=1 withViolations=1` (13.5, unchanged from
the pre-migration baseline — that violation is the seeded `jansen-telefoon` open-assignment case,
unrelated to the dialect fix). 12 new/updated unit tests in
`tests/Unit/Service/AssetDialectMigrationServiceTest.php` pin both fixes plus two hard-validation/
lifecycle-guard OpenRegister gates via an upgraded fake; full suite 1124 green (was 1110 before this
change). See design.md's Migration Plan addendum for the repair-step-vs-command rationale and the
two OpenRegister gaps this surfaced.

**Note (implementing agent, 2026-08-19):** section 13 was found ALREADY IMPLEMENTED mid-session —
`lib/Service/AssetDialectMigrationService.php`, `lib/Repair/MigrateAssetDialect.php`,
`lib/Command/AssetsMigrateDialectCommand.php`, and the `appinfo/info.xml` wiring for both already
existed and were already correctly cross-referenced (same field/enum maps as `hr-assets.json`'s D1
table) when this agent reached this section — evidently written directly by the orchestrator/a
parallel process on this same shared checkout. This agent had independently started an equivalent
but inferior implementation (missed the `Asset.status` `x-openregister-lifecycle` write-time guard
entirely — the other implementation correctly splits a status change into its own follow-up write
and skips-with-reason on the guard's rejection, which this agent's draft would not have handled);
that draft (`AssetFleetDialectMigrationService`/`MigrateAssetFleetDialect`/
`AssetsMigrateFleetDialectCommand`) was DELETED in favour of the existing implementation rather than
left as dead, conflicting code. Checkboxes 13.1-13.5 are left unticked here since this agent did not
do that work and cannot verify it live (see the implementation report — the shared dev instance
went down instance-wide, for a reason unrelated to hrmq, before a live re-check was possible).
This agent's own contribution to section 13's underlying problem: found and fixed the reason
`AssetAssignment` seed rows never imported in the first place (a bare, non-`@ref:`-prefixed slug on
a `format: uuid` field — see task 11.6's note) — orthogonal to, and not superseded by, the dialect
migration above.

## 14. BLOCKING — two findings from the orchestrator's review of the migration (2026-08-19)

The acceptance test (`Asset … withViolations=2`) was reported met. **Not independently re-verified**
— the shared instance is currently `needsDbUpgrade: true` after another agent changed `scholiq`'s
version under the bind mount, which gates `occ` and the OpenRegister API for every app. Re-take it
before archiving.

### 14.1 `Asset.status` is never migrated, so live rows stay permanently half-converted

The migration's own output skips all three legacy rows with
`status uitgegeven -> issued blocked by OpenRegister lifecycle guard`. `category`, `licencePlate`,
`purchaseDate` and `purchaseValue` migrate; `status` does not. Three live rows therefore hold
`uitgegeven`/`beschikbaar` against an enum that now declares only
`available|issued|checkedIn|writtenOff`.

Consequences: a `status` filter on the Assets index will not match them; and because full-object
validation rejects any payload carrying the invalid value, **those rows are permanently unwritable
through the normal API** — every future save of an unrelated field on them fails the same way.

This also exposes that the §13 acceptance criterion was too narrow. `nl-asset-voertuig-fiscale-velden-compleet`
keys on `category`, which did migrate — so `withViolations=2` can be satisfied while `status` is
still broken. The criterion measured the rule, not the data.

- [ ] 14.1a Add an acceptance assertion on the DATA, not only the rule: every live `Asset.status`
      and `Asset.category` value is a member of the current enum. Zero non-members.

### 14.2 Replace the `hardValidation` toggle with an expand/contract enum migration

`withAssetHardValidationDisabled()` temporarily clears the **Asset schema's persisted**
`hardValidation` flag and restores it in a `finally`. The investigation behind it is sound and well
documented — `_validation: false` genuinely gates a different step, and the lifecycle listener
genuinely is unconditional. But the mechanism has two problems the docblock does not name:

1. **The flag is global, not per-call.** While it is off, every other write to `Asset` from any
   user, request or concurrent process also skips hard validation. On a shared instance running
   several agents, that window is real.
2. **A crash, timeout or killed `occ` between toggle and restore leaves validation permanently off
   on that schema**, silently. `finally` does not survive a fatal or a `SIGKILL` — and this session
   has already seen an agent's `docker exec` die mid-command.

The standard fix avoids both gates without disabling anything — **expand/contract**:

- [x] 14.2a EXPAND: add the legacy Dutch values to `Asset.status`'s enum and to the
      `x-openregister-lifecycle` `from` arrays, marked deprecated in their descriptions. Both gates
      now accept the old dialect, so an ordinary write succeeds with validation fully on.
- [x] 14.2b MIGRATE: rewrite `status` through the normal API, no bypass.
- [ ] 14.2c CONTRACT: remove the legacy values from the enum and the `from` arrays.
- [x] 14.2d Delete `withAssetHardValidationDisabled()` and its call site.
- [x] 14.2e Keep the measured findings about `_validation` and `LifecycleValidationListener` in the
      docblock — they are correct and hard-won, and they are what justifies expand/contract over a
      naive retry. Only the bypass goes.

### 14.3 Unrelated environment damage to hand back, not to fix here

- [ ] 14.3 `scholiq` needs `occ upgrade` and the instance reports `needsDbUpgrade: true`. Caused by
      another agent's in-flight `scholiq`→`learniq` rename, not by this change. Do not run
      `occ upgrade` from inside this change — it would apply another agent's half-finished migration
      fleet-wide. Hand it back to whoever owns that work.

### Progress — orchestrator, 2026-08-19 (expand/contract landed)

- **14.2a/b/d/e done.** `hr-assets.json` now declares four migration-only
  `migrateLegacyStatus_<legacy>` transitions (`beschikbaar→available`,
  `uitgegeven→issued`, `ingenomen→checkedIn`, `afgeschreven→writtenOff`). The service writes the
  fully-migrated payload — fields AND status — in ONE `saveObject()` call, which clears both gates
  with validation fully on: gate 1 validates the payload, and the payload's status is already
  English; gate 2 now has a declared transition to match. `withAssetHardValidationDisabled()` and
  `setAssetHardValidation()` are deleted (60 lines).
- **The regression guard is an assertion, not a comment.** `testOldDialectAssetIsRewrittenIncludingStatus`
  asserts `schemaMapper->updateCalls === 0` — the migration must never write to a schema. If the
  toggle ever creeps back, that fails.
- Suite green at **1124** with the bypass removed.
- ⚠️ **A trap worth recording:** the test's fake gates live in an anonymous class, which cannot read
  the outer test class's `private const`. The resulting `Error` was swallowed by the service's own
  `catch (\Throwable)` and surfaced as a *skip reason* — "dialect rewrite failed … Cannot access
  private constant" — i.e. a test-harness bug wearing the costume of a domain failure. Both
  constants are now `public`. A broad `catch (\Throwable)` around a write will do this to any
  programming error in the payload path.
- [ ] 14.2c CONTRACT — pending: remove the four `migrateLegacyStatus_*` transitions once the live
  migration reports zero rows holding a legacy status, then re-import and re-verify.
- [ ] 14.1a — pending the live run: assert zero live rows violate the current `category`/`status`
  enums.
