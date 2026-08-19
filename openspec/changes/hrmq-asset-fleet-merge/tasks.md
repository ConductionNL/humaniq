# Tasks — hrmq-asset-fleet-merge

## 0. Baseline (before any edit)

- [ ] 0.1 Re-verify live row counts against a running instance: `Asset` / `AssetAssignment` /
      `Vehicle` / `CarAssignment` totals via `GET /apps/openregister/api/objects/hrmq/<Schema>?_limit=1`
      — proceed only if `AssetAssignment`/`Vehicle`/`CarAssignment` are still 0 and no `Asset` row
      has `category: voertuig`; if not, stop and add a migration step before continuing (design.md
      Context)
- [ ] 0.2 Run `occ hrmq:rules:audit` (or the standalone RuleEngine-against-fixtures path) and record
      the current violation counts for `nl-asset-assignment-consistency` and
      `nl-bijtelling-auto-privegebruik` — this is the "before" figure D5/the specs' final scenarios
      require for the after-merge equality assertion
- [ ] 0.3 Run the full PHPUnit suite and record the pass count as the pre-change baseline
- [ ] 0.4 Confirm the two known environment gaps still hold and do not block this change: (a)
      `vendor/conduction/hydra-gates` not installed, `vendor/` root-owned, `composer install`
      fails on permissions — `composer phpcs`/`composer phpstan` cannot run; `composer psalm`/
      `composer test:unit` do; (b) `node_modules/@conduction/nextcloud-vue` is
      `1.0.0-beta.215` against a `2.2.0-vue3.2` lockfile pin — `node tests/validate-widget-keys.js`
      fails at clean HEAD (verify by stashing). Do not attempt to fix either; they are not this
      change's to fix (wave-1 brief HARD CONSTRAINTS §3)

## 1. Schema — `hr-assets.json`

- [ ] 1.1 Rename `Asset.kenteken`→`licencePlate`, `serienummer`→`serialNumber`,
      `aanschafdatum`→`purchaseDate`, `aanschafwaarde`→`purchaseValue`
- [ ] 1.2 Translate `Asset.category` enum values (`laptop` unchanged; `telefoon`→`phone`,
      `voertuig`→`vehicle`, `gereedschap`→`tool`, `toegangspas`→`accessPass`, `kleding`→`clothing`,
      `overig`→`other`) and `Asset.status` enum values (`beschikbaar`→`available`,
      `uitgegeven`→`issued`, `ingenomen`→`checkedIn`, `afgeschreven`→`writtenOff`)
- [ ] 1.3 Rename the `x-openregister-lifecycle` transitions (`uitgeven`→`issue`, `innemen`→`checkIn`,
      `vrijgeven`→`release`, `afschrijven`→`writeOff`) and their `from`/`to`/`initial`/`terminal`
      values to match 1.2 — verify the four transitions still form the same graph
      (`available`→`issued`→`checkedIn`→`available`, `available`/`checkedIn`→`writtenOff` terminal)
- [ ] 1.4 Add `Asset.listPrice` (number, nullable), `fuelType` (enum
      `gasoline|diesel|hybrid|fullyElectric|hydrogen|other`, nullable), `companyCarTaxCategory`
      (enum `standard|evReducedCapped`, nullable) — none in the unconditional `required` array
- [ ] 1.5 Do **NOT** add a schema-level `allOf`/`if`/`then` conditional-required block. It would be
      stored and enforced nowhere — `Schema::getSchemaObject()` never emits composition keywords to
      the validator (design.md D2, measured). Instead:
  - [ ] 1.5a Add corpus rule `nl-asset-voertuig-fiscale-velden-compleet` to
        `lib/Standards/rules/labour.json` (or the fleet-appropriate domain file), anchored on
        `Asset`, firing when `category === "vehicle"` and any of `listPrice` / `fuelType` /
        `companyCarTaxCategory` is absent. Bump `RuleCatalogue::VERSION`.
  - [ ] 1.5b Add the predicate to `lib/Standards/Checks/NlFleetChecks.php`.
  - [ ] 1.5c **Prove the rule can FAIL before trusting that it passes** — seed one
        `category: "vehicle"` Asset missing `listPrice` and assert `occ hrmq:rules:audit` reports
        exactly one new violation for it. A rule that has only ever reported zero is
        indistinguishable from one that never ran.
  - [ ] 1.5d Record in the change notes that this is an **audit-time** guard replacing a
        **write-time** one (`Vehicle.required`), that the downgrade is forced by the platform, and
        that `bijtellingCentsFor()`'s `is_numeric` guard bounds the consequence to €0 rather than a
        wrong figure.
  - [ ] 1.5e File the underlying gap against OpenRegister as its own issue: `getSchemaObject()`
        drops `allOf`/`oneOf`/`anyOf`/`if`/`then`, so no leaf app can express a conditional-required
        or a discriminated union — while `Schema` carries columns for three of those keywords and
        `jsonSerialize()` emits them, which reads as support. Not fixed by this change.
- [ ] 1.6 Rename `AssetAssignment.uitgifteDatum`→`issuedOn`, `innameDatum`→`returnedOn`,
      `uitgifteBonSigned`→`issueReceiptSigned`; update `required: [assetId, employeeId, issuedOn]`
- [ ] 1.7 Add `AssetAssignment.employeeContribution` (number, default 0) — absorbed from
      `CarAssignment.eigenBijdrage`
- [ ] 1.8 Bump `Asset`/`AssetAssignment` schema `version`
- [ ] 1.9 Delete `lib/Settings/register.d/hr-fleet.json`

## 2. Schema — `hr-objects.json` (Payslip)

- [ ] 2.1 Rename `Payslip.carAssignmentId`→`assetAssignmentId`; repoint its `$ref` from
      `CarAssignment` to `AssetAssignment`
- [ ] 2.2 Leave `Payslip.bijtelling` untouched (design.md D5 — out of scope)

## 3. Schema — `hr-seed.json`

- [ ] 3.1 Rename seed field keys on the three `Asset` seeds and two `AssetAssignment` seeds to match
      1.1/1.2/1.6 (`asset-bus-transit`'s `category: voertuig`→`vehicle`, `status: uitgegeven`→
      `issued`, `kenteken`→`licencePlate`; `assetassignment-visser-bus`/`assetassignment-jansen-telefoon`'s
      `uitgifteDatum`/`innameDatum`/`uitgifteBonSigned` renamed)
- [ ] 3.2 Add `listPrice`/`fuelType`/`companyCarTaxCategory` values to `asset-bus-transit` — so the
      seed is a clean pass for rule `nl-asset-voertuig-fiscale-velden-compleet` (1.5a). Pick
      plausible placeholder figures, documented as such.
- [ ] 3.2b Add a SECOND vehicle-category seed that is deliberately INCOMPLETE (missing `listPrice`),
      so the new rule has a standing violation to report. This is the positive control for 1.5c —
      without it the rule's zero-violation state proves nothing.
- [ ] 3.3 Confirm no `Vehicle`/`CarAssignment` seeds exist to remove (grep `hr-seed.json` for
      both slugs — expected: none)

## 4. PHP — `PayrollRunService.php`

- [ ] 4.1 Rename `openCarAssignmentsByEmployeeKey()`/`openCarAssignmentFor()` to load
      `AssetAssignment` instead of `CarAssignment`, reading `issuedOn`/`returnedOn` in place of
      `effectiveFrom`/`effectiveTo` (the `coversPeriod()` call signature is unchanged)
- [ ] 4.2 Filter the resolved open `AssetAssignment` to ones whose referenced `Asset.category` is
      `vehicle` before treating it as a covering car assignment (design.md REQ-FLEET-003 — a
      laptop `AssetAssignment` must never contribute a bijtelling fold)
- [ ] 4.3 Rename `vehiclesById()` to load `Asset` filtered to `category: vehicle` (or reuse the
      already-loaded set from 4.2 — avoid a second full `Asset` load per run)
- [ ] 4.4 Update `bijtellingCentsFor()` to read `listPrice`/`companyCarTaxCategory` off the
      resolved Asset and `employeeContribution` off the resolved AssetAssignment; keep the formula
      byte-identical (design.md D2's "arithmetic does not change" claim depends on this)
- [ ] 4.5 Update `bijtellingFields()` to write `assetAssignmentId` instead of `carAssignmentId`
- [ ] 4.6 Re-run the bijtelling-anchor case by hand (€3.800 salary, €45.000 listPrice, standard
      category, €325 employeeContribution) and confirm the same €500,00/€4.300,00/.../€3.329,17
      figures the spec's REQ-FLEET-003 scenario names — cents-exact

## 5. PHP — `RuleAuditService.php`

- [ ] 5.1 Extend `buildRelatedContext()`'s `assetsById` entries with `category`, `listPrice`,
      `fuelType`, `companyCarTaxCategory`
- [ ] 5.2 Add an `AssetAssignment` index to `buildRelatedContext()`:
      `context['related']['AssetAssignment']['byId']` mapping each id to `{id, assetId, employeeId,
      issuedOn, returnedOn, employeeContribution}`
- [ ] 5.3 Remove `buildFleetContext()` and the `context['fleet']` assignment in `audit()`
      (design.md D4)
- [ ] 5.4 Update the `@spec` tags on the touched methods to point at this change's spec deltas
      alongside the pre-existing `fleet-bijtelling`/`asset-management` references

## 6. PHP — Standards Checks

- [ ] 6.1 `NlFleetChecks::bijtellingMatchesFormula()` — read `Payslip.assetAssignmentId`; resolve via
      `context['related']['AssetAssignment']['byId']` then `context['related']['Asset']['byId']`
      (dropping `context['fleet']['carAssignmentsById']`/`['vehiclesById']`); keep the
      dangling-reference vacuous-pass posture, now also covering a resolved-but-non-`vehicle`
      Asset (its `listPrice` is never numeric, so `monthlyBijtellingCents()` still yields 0 —
      confirm this by inspection, no new category branch needed per design.md REQ-FLEET-003)
- [ ] 6.2 `NlAssetChecks` — rename all `uitgifteDatum`/`innameDatum` reads to `issuedOn`/`returnedOn`;
      rename the `status !== 'uitgegeven'` comparison to `status !== 'issued'`
- [ ] 6.3 Grep `lib/Standards/` and `lib/Service/` once more for any remaining
      `uitgifteDatum|innameDatum|kenteken|serienummer|aanschafdatum|aanschafwaarde|eigenBijdrage|
      cataloguswaarde|bijtellingCategorie|carAssignmentId|beschikbaar|uitgegeven\b|ingenomen|
      afgeschreven` literal outside of `Payslip.bijtelling`/`hr-objects.json` and the intentionally
      untouched rule-id/framework slugs (design.md D1) — every hit not on that allow-list is a
      missed rename

## 7. Rule corpus text

- [ ] 7.1 Update `lib/Standards/rules/labour.json`'s `nl-asset-assignment-consistency` `statement`
      to read `returnedOn`/`issuedOn`/`issued` in place of the Dutch field/value names; same for
      `nl-asset-inname-bij-offboarding` if it names either field
- [ ] 7.2 Update `lib/Standards/rules/payroll.json`'s `nl-bijtelling-auto-privegebruik` `statement`
      to read "list price"/"employee contribution" in place of "cataloguswaarde"/"eigen bijdrage",
      keeping "bijtelling privégebruik auto" as the retained statutory scheme name (design.md D1)
- [ ] 7.3 Bump `RuleCatalogue::VERSION` — re-verify the current HEAD value first (task 0 baseline
      plus a fresh check immediately before editing; parallel wave-1 changes may have already
      bumped it)

## 8. Manifest — `src/manifest.json`

- [ ] 8.1 Remove the `Vehicles`/`CarAssignments` children from the `ExpensesGroup` menu entry
- [ ] 8.2 Remove the `Vehicles`, `VehicleDetail`, `CarAssignments`, `CarAssignmentDetail` page
      entries from `pages[]`
- [ ] 8.3 Remove the `Vehicle` and `CarAssignment` entries from `deepLinks[]`
- [ ] 8.4 Update `Assets`/`AssetAssignments` index-page `columns[]` and `AssetDetail`/
      `AssetAssignmentDetail` widget `content.include`/exclude lists to the renamed field names
      (1.1/1.6)
- [ ] 8.5 Add `listPrice`, `fuelType`, `companyCarTaxCategory` to `AssetDetail`'s data-widget
      `content.include`, and set `content.hideEmpty: true` on that widget (design.md D3)
- [ ] 8.6 Add `employeeContribution` to `AssetAssignmentDetail`'s data-widget `content.include`
- [ ] 8.7 Update `AssetDetail`'s "Uitgiftes" object-list `sort.field` from `uitgifteDatum` to
      `issuedOn`, and its `columns[]` labels/keys, to match 1.6
- [ ] 8.8 Run `npm run check:manifest` — Ajv PASS, 0 errors

## 9. Router redirects — `src/main.js`

- [ ] 9.1 Add `{ path: '/vehicles/:id', redirect: (to) => '/assets/' + to.params.id }` and
      `{ path: '/car-assignments/:id', redirect: (to) => '/asset-assignments/' + to.params.id }`
      to `routesFromManifest()`'s returned routes, before the existing catch-all (design.md D6)
- [ ] 9.2 Manually verify (or add a unit test against the router config) that both redirects
      resolve to the expected path with the id preserved

## 10. Spec maintenance

- [ ] 10.1 Confirm this change's `## Capabilities` list (`asset-management`, `fleet-bijtelling`)
      matches `openspec/specs/asset-management/spec.md` and `openspec/specs/fleet-bijtelling/spec.md`'s
      `**OpenSpec changes**` lists, adding this change with `**Status**: in-progress` to both

## 11. Tests

- [ ] 11.1 Update `tests/Unit/Service/PayrollRunServiceTest.php` fixtures to build `AssetAssignment`/
      `Asset` objects instead of `CarAssignment`/`Vehicle`, renamed fields, and add a case asserting
      an `AssetAssignment` on a non-`vehicle` Asset contributes no bijtelling (spec scenario
      "An open AssetAssignment on a non-vehicle Asset does not contribute a bijtelling")
- [ ] 11.2 Update `tests/Unit/Standards/Checks/NlFleetChecksTest.php` fixtures to the merged shape
      and the `context['related']` index paths
- [ ] 11.3 Update `tests/Unit/Standards/Checks/NlAssetChecksTest.php` fixtures to the renamed
      fields/enum values
- [ ] 11.4 Update `tests/Unit/Service/RuleAuditServiceTest.php` — `buildRelatedContext()`
      Asset/AssetAssignment index assertions, `buildFleetContext()` removal, seeded-fixture
      violation-count assertions
- [ ] 11.5 Update `tests/Unit/Standards/Checks/NlAdministratieChecksTest.php` if it constructs
      `AssetAssignment` fixtures with the old field names
- [ ] 11.6 **Before/after violation-count parity (mandatory, not optional):** run the updated test
      suite's `nl-asset-assignment-consistency` and `nl-bijtelling-auto-privegebruik` assertions
      against fixtures equivalent to the task-0.2 baseline and confirm the counts are identical —
      one `nl-asset-assignment-consistency` violation (the seeded open jansen-telefoon uitgifte),
      the bijtelling-anchor fixture's known clean/tampered/out-of-scope outcomes. A count that
      dropped to zero after the rename is a bug in the rename, not a pass.
- [ ] 11.7 Full PHPUnit suite green, same-or-higher pass count as the task-0.3 baseline
- [ ] 11.8 `composer psalm` clean on every touched file (`composer phpcs`/`phpstan` are
      environment-blocked per task 0.4 — do not attempt, do not report their absence as a failure
      of this change)

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
