## MODIFIED Requirements

### Requirement: A new `hr-assets` fragment SHALL define the `Asset` schema with a declarative custody lifecycle (REQ-AST-001)

`lib/Settings/register.d/hr-assets.json` SHALL declare the `Asset` schema entirely in English field/enum names, including the three vehicle-only fiscal-facts fields absorbed from the retired `Vehicle` schema, required exactly when `category` is `vehicle`.

`lib/Settings/register.d/hr-assets.json` (`x-hrmq-fragment: hr-assets`, OpenAPI 3.0.0 `components.schemas` shape like `hr-org.json`) declares `Asset` (slug `Asset`, icon `PackageVariantClosed`, `x-schema-org: schema:IndividualProduct`) with properties: `name` (string), `category` (enum `laptop|phone|vehicle|tool|accessPass|clothing|other`), `serialNumber` (string, nullable), `licencePlate` (string, nullable — meaningful for category `vehicle`; carries no fiscal semantics), `purchaseDate` (string, format date, nullable), `purchaseValue` (number, nullable), `status` (enum `available|issued|checkedIn|writtenOff`), `active` (boolean, default `true`), `administrationId` (string, nullable). `required: [name, category, status]`. `status` carries an `x-openregister-lifecycle` (initial `available`, terminal `writtenOff`) with exactly four transitions: `issue` (`available`→`issued`), `checkIn` (`issued`→`checkedIn`), `release` (`checkedIn`→`available`), `writeOff` (`available`/`checkedIn`→`writtenOff`) — no guards.

`Asset` additionally gains three fiscal-facts properties absorbed from the retired `Vehicle` schema (see the modified `fleet-bijtelling` capability): `listPrice` (number, nullable), `fuelType` (enum `gasoline|diesel|hybrid|fullyElectric|hydrogen|other`, nullable), `companyCarTaxCategory` (enum `standard|evReducedCapped`, nullable). None of the three is in the schema's unconditional `required` array — they are meaningless for any `category` other than `vehicle`. Their completeness when `category === "vehicle"` SHALL be enforced by a machine-checkable corpus rule `nl-asset-voertuig-fiscale-velden-compleet` (predicate in `NlFleetChecks`), **not** by a schema-level conditional. A JSON Schema `allOf`/`if`/`then` block cannot work here: `Schema::getSchemaObject()` builds the object handed to the validator from `title`/`description`/`version`/`type`/`required`/`properties` only, so composition keywords never reach `opis/json-schema` however faithfully they are declared (measured — see design.md D2). Declaring one would be enforced nowhere while reading as a guarantee.

This is an **audit-time** guard, and deliberately weaker than what it replaces: before this change a `Vehicle` without a `cataloguswaarde` was rejected at write time by `Vehicle`'s own unconditional `required`. After the merge an incomplete vehicle `Asset` is writable, and is caught on the next `occ hrmq:rules:audit` instead. The consequence is bounded rather than silent — `PayrollRunService::bijtellingCentsFor()`'s existing `is_numeric` guard yields €0, a missing benefit rather than a wrong one — but the downgrade is real and is recorded as such.

#### Scenario: Schema validates a new asset in stock

- GIVEN the imported hrmq register
- WHEN an object `{name: "Fairphone 5", category: "phone", status: "available"}` is created
- THEN creation succeeds with `active` defaulted to `true` and `serialNumber`/`licencePlate`/`purchaseDate`/`purchaseValue`/`listPrice`/`fuelType`/`companyCarTaxCategory` null

#### Scenario: Unknown category rejected

- WHEN an object is written with `category: "furniture"`
- THEN OpenRegister schema validation rejects it (enum mismatch)

#### Scenario: Lifecycle blocks writing off an issued asset

- GIVEN an Asset in status `issued`
- WHEN the `writeOff` transition is attempted
- THEN the lifecycle rejects it (`writeOff` is only reachable from `available` or `checkedIn`)

#### Scenario: Returned asset can re-enter stock

- GIVEN an Asset in status `checkedIn`
- WHEN the `release` transition is applied
- THEN the asset's status becomes `available` and it is issuable again via `issue`

#### Scenario: A vehicle Asset without fiscal facts is reported by the rule audit

- WHEN an object `{name: "Tesla Model Y", category: "vehicle", status: "available"}` is created with no `listPrice`, `fuelType`, or `companyCarTaxCategory`
- THEN the object is **accepted** by OpenRegister schema validation — the schema layer cannot express a conditional-required (see design.md D2: `Schema::getSchemaObject()` emits no `allOf`/`if`/`then`, so the validator never sees one)
- AND `occ hrmq:rules:audit` reports a `nl-asset-voertuig-fiscale-velden-compleet` violation for that object
- AND `PayrollRunService::bijtellingCentsFor()` yields €0 for it rather than a wrong figure, so the incomplete record costs a missing benefit, never a miscalculated one
- @e2e exclude rule-engine predicate behaviour; covered by a PHPUnit fixture over `NlFleetChecks` in the `RuleAuditServiceTest` precedent, not a UI flow — app-level e2e suite does not exist yet (tracked by active change `hrmq-test-coverage-baseline`)

#### Scenario: The guard is weaker than the one it replaces, and the tasks say so

- GIVEN the pre-merge `Vehicle` schema made an incomplete vehicle **impossible to write** via its own unconditional `required` array
- WHEN the merged `Asset` relies on an audit-time corpus rule instead
- THEN the detection is after-the-fact, and the migration notes SHALL record that as a deliberate downgrade forced by the platform, not describe the two guards as equivalent
- @e2e exclude a documentation invariant, verified by reading tasks.md

#### Scenario: A non-vehicle Asset is unaffected by the conditional

- WHEN an object `{name: "Werkschoenen", category: "clothing", status: "available"}` is created with no `listPrice`/`fuelType`/`companyCarTaxCategory`
- THEN creation succeeds — the conditional-required block does not fire for a non-`vehicle` category

### Requirement: The `hr-assets` fragment SHALL define the effective-dated `AssetAssignment` schema (REQ-AST-002)

`lib/Settings/register.d/hr-assets.json` SHALL declare the `AssetAssignment` schema entirely in English field names, including `employeeContribution` absorbed from the retired `CarAssignment` schema.

The same fragment declares `AssetAssignment` (slug `AssetAssignment`, icon `HandshakeOutline`, `x-schema-org: schema:OwnershipInfo`) with properties: `assetId` (string, format uuid, `$ref: Asset`), `employeeId` (string, format uuid, `$ref: Employee`), `issuedOn` (string, format date), `returnedOn` (string, format date, nullable — null while the asset is out), `issueReceiptSigned` (boolean, default `false`), `notes` (string, nullable), `administrationId` (string, nullable). `required: [assetId, employeeId, issuedOn]`. `AssetAssignment` does NOT carry an `x-openregister-lifecycle` (a plain effective-dated record, the OrgAssignment pattern).

`AssetAssignment` additionally gains `employeeContribution` (number, default `0`) absorbed from the retired `CarAssignment` schema (see the modified `fleet-bijtelling` capability) — the employee's own monthly contribution for private use of a company car. Unlike the three Asset-side fiscal fields, `employeeContribution` needs no conditional-required treatment: it already defaulted to `0` on `CarAssignment`, and `0` reads correctly as "no contribution" on any non-vehicle assignment (a laptop uitgifte has never had, and will never be asked for, an employee contribution).

#### Scenario: Open uitgifte is valid

- WHEN an object `{assetId: <asset uuid>, employeeId: <employee uuid>, issuedOn: "2026-06-15"}` is created
- THEN creation succeeds with `returnedOn` null (an open, current uitgifte), `issueReceiptSigned` defaulted to `false`, and `employeeContribution` defaulted to `0`

#### Scenario: Missing asset reference rejected

- WHEN an object is written without `assetId`
- THEN OpenRegister schema validation rejects it (required property)

### Requirement: `NlAssetChecks` SHALL enforce assignment consistency and offboarding asset return via the related-context (REQ-AST-005)

Both predicates SHALL read the renamed `issuedOn`/`returnedOn`/`issued` fields and values, and SHALL report the identical violation count on equivalent fixture data before and after the rename in this change.

`lib/Standards/Checks/NlAssetChecks.php` (implements `CheckProvider`) registers both predicates keyed on `AssetAssignment` (pure `fn(array $object, array $context): bool`), reading `context['related']['Asset']['byId']` (each entry `{id, status, active}`) and `context['related']['Offboarding']['plannedCompletionByEmployeeId']`, both built by `RuleAuditService::buildRelatedContext()`'s single pre-pass. Predicates:

1. **`nl-asset-assignment-consistency`** — violates when `returnedOn` is present and earlier than `issuedOn`, or when the assignment is open (no `returnedOn`) and `assetId` does not resolve in the Asset index, or resolves to an asset whose `status` is not `issued`, or `employeeId` does not resolve in the existing Employee index (fail-closed on dangling references).
2. **`nl-asset-inname-bij-offboarding`** — violates when the assignment is open and its `employeeId` resolves in the Offboarding index to a planned-completion date strictly before the audit run date. The predicate passes vacuously when the Offboarding index is empty or the employee has no entry.

#### Scenario: Incoherent dates flagged

- GIVEN an AssetAssignment with `issuedOn: 2026-06-01` and `returnedOn: 2026-05-01`
- WHEN `occ hrmq:rules:audit` runs
- THEN a `nl-asset-assignment-consistency` violation is reported for that assignment

#### Scenario: Open uitgifte on an in-stock asset flagged

- GIVEN the seed assignment `assetassignment-jansen-telefoon` (open, referencing the `available` telefoon)
- WHEN the audit runs
- THEN a `nl-asset-assignment-consistency` violation is reported for it

#### Scenario: Closed uitgifte on a non-issued asset passes

- GIVEN an AssetAssignment with coherent dates and a `returnedOn` in the past, referencing an Asset in status `available`
- WHEN the audit runs
- THEN no `nl-asset-assignment-consistency` violation is reported for it (historical uitgiftes may reference re-stocked or written-off assets)

#### Scenario: Dangling asset reference fails closed

- GIVEN an open AssetAssignment whose `assetId` does not resolve in the Asset index
- WHEN the audit runs
- THEN a `nl-asset-assignment-consistency` violation is reported

#### Scenario: Open uitgifte past offboarding completion flagged

- GIVEN an open AssetAssignment for an employee whose Offboarding index entry has a planned-completion date before the audit date
- WHEN the audit runs
- THEN a `nl-asset-inname-bij-offboarding` violation is reported

#### Scenario: Rule passes vacuously without Offboarding objects

- GIVEN a register in which no Offboarding schema or objects exist
- WHEN the audit runs
- THEN no `nl-asset-inname-bij-offboarding` violation is reported for any assignment

#### Scenario: The renamed fields produce the identical violation count as before the merge

- GIVEN the seeded fixture data (three Assets, two AssetAssignments, one deliberately open uitgifte on an `available` asset) both immediately before this change (Dutch field names) and immediately after (English field names)
- WHEN `occ hrmq:rules:audit` runs against each
- THEN both runs report exactly one `nl-asset-assignment-consistency` violation and zero `nl-asset-inname-bij-offboarding` violations — the rename changes no arithmetic or resolution logic, only field names, so the before/after counts MUST be asserted equal, not merely "the tests pass" (the silent-empty failure mode named in the wave-1 brief: a check reading a field that moved does not throw, it silently stops matching)
- @e2e exclude before/after count parity is a PHPUnit/CLI assertion (`RuleAuditServiceTest`), not a UI flow

Pinned by `tests/Unit/Standards/Checks/NlAssetChecksTest.php` (both predicates, including fail-closed empty-context behaviour and every vacuous-pass path) and `tests/Unit/Service/RuleAuditServiceTest.php` (the `buildRelatedContext()` Asset + Offboarding indexes exercised end-to-end through `RuleAuditService::audit()`).

### Requirement: New asset pages SHALL surface the register under the existing expenses menu group (REQ-AST-006)

`src/manifest.json` SHALL retire the `Vehicles`/`CarAssignments` menu entries, pages, and `deepLinks` entries, SHALL redirect their former URL patterns to the merged Asset/AssetAssignment detail routes, and SHALL hide the three vehicle-only fiscal fields on non-`vehicle` Asset detail pages.

`src/manifest.json` carries (a) an `Assets` index page (route `/assets`, register `hrmq`, schema `Asset`) with columns `name`, `category`, `status`, `serialNumber`, filters `category`/`status`, default sort `name` ascending; (b) an `AssetDetail` detail page (route `/assets/:id`) with a `data` widget (`content.include`: `name`/`category`/`serialNumber`/`licencePlate`/`purchaseDate`/`purchaseValue`/`status`/`active`/`listPrice`/`fuelType`/`companyCarTaxCategory`, `content.hideEmpty: true` — the documented `CnObjectDataWidget` "discriminated supertype" mechanism, so a non-`vehicle` Asset's detail page does not render three permanently-empty fiscal fields as em dashes), `lifecycleActions` exposing exactly the four fragment transitions (`issue`/`checkIn`/`release`/`writeOff`), a `related` widget, an FK-scoped `object-list` "Uitgiftes" (`AssetAssignment`, `filter: { assetId: "@objectId" }`, sort `issuedOn` descending, rowRoute `AssetAssignmentDetail`), an `integration: files` widget for signed uitgiftebonnen and an audit-history sidebar tab; (c) an `AssetAssignments` index page (route `/asset-assignments`) with columns `assetId`, `employeeId`, `issuedOn`, `returnedOn`, `issueReceiptSigned`, sort `issuedOn` descending; (d) an `AssetAssignmentDetail` detail page (route `/asset-assignments/:id`) with a `data` widget (excluding `assetId`/`employeeId`; including `employeeContribution`), a `related` widget and an audit-history sidebar tab, and no lifecycleActions; (e) menu children `Assets` and `AssetAssignments` under the existing `ExpensesGroup`, unchanged in position by this change; (f) `deepLinks` entries for `Asset` (`/apps/hrmq/assets/{uuid}`) and `AssetAssignment` (`/apps/hrmq/asset-assignments/{uuid}`), unchanged. The manifest validates (`npm run check:manifest`).

The `ExpensesGroup` menu's `Vehicles`/`CarAssignments` children, the `Vehicles`/`VehicleDetail`/`CarAssignments`/`CarAssignmentDetail` pages, and the `Vehicle`/`CarAssignment` `deepLinks` entries are removed (their schemas no longer exist — see the modified `fleet-bijtelling` capability). Their two former URL patterns become vue-router redirects to the merged Asset/AssetAssignment detail routes (`/vehicles/:id` → `/assets/:id`, `/car-assignments/:id` → `/asset-assignments/:id`), so a stale bookmark or external link resolves instead of 404ing.

#### Scenario: Manifest stays valid

- WHEN `npm run check:manifest` runs
- THEN it exits 0

#### Scenario: Asset detail shows lifecycle actions and uitgifte history

- GIVEN the seeded `asset-bus-transit` opened on `AssetDetail`
- WHEN the page renders
- THEN the data card shows the licence plate, the lifecycle actions offer only the transitions valid from the current status, and the Uitgiftes list shows the closed visser assignment linking to its detail page
- @e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)

#### Scenario: A non-vehicle asset's detail page hides the fiscal fields

- GIVEN the seeded `asset-laptop-latitude` (category `laptop`) opened on `AssetDetail`
- WHEN the page renders
- THEN the data card does not render `listPrice`/`fuelType`/`companyCarTaxCategory` as empty fields (hideEmpty)
- @e2e exclude declarative widget config (`content.hideEmpty`); covered by the shared `CnObjectDataWidget` library tests, not an app-level e2e flow — app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)

#### Scenario: A stale Vehicle URL redirects instead of 404ing

- GIVEN a browser navigates to `/apps/hrmq/vehicles/<any-uuid>`
- WHEN vue-router resolves the route
- THEN it redirects to `/apps/hrmq/assets/<the-same-uuid>` rather than rendering the catch-all "no match" route
- @e2e exclude router redirect; no live Vehicle object has ever existed to link to one (0 rows at merge time — Measured facts), so this is forward-looking deep-link hygiene rather than a fix for a reachable broken link; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: Seed data SHALL provide three assets and two uitgiftes with one deliberate inconsistency (REQ-AST-007)

`lib/Settings/register.d/hr-seed.json` SHALL carry the same three Asset and two AssetAssignment seeds under the renamed English field names, with the vehicle seed additionally populating the three fiscal fields the REQ-AST-001 conditional now requires.

`lib/Settings/register.d/hr-seed.json` carries three Asset seeds — `asset-laptop-latitude` (category `laptop`, `serialNumber` placeholder, status `issued`), `asset-telefoon-fairphone` (category `phone`, status `available`), `asset-bus-transit` (category `vehicle`, `licencePlate` placeholder `V-000-XX`, status `issued`, plus `listPrice`/`fuelType`/`companyCarTaxCategory` populated — required now that `category: vehicle` triggers the conditional-required block) — and two AssetAssignment seeds referencing by slug against the slugged Employee seeds `employee-jansen` and `employee-visser`: `assetassignment-visser-bus` (closed: `issuedOn` `2025-01-06`, `returnedOn` `2025-12-19`, `issueReceiptSigned` `true` — consistent) and `assetassignment-jansen-telefoon` (open: `issuedOn` `2026-06-15`, `returnedOn` null, `issueReceiptSigned` `false`) whose referenced telefoon is `available` — deliberately inconsistent so `nl-asset-assignment-consistency` fires exactly once on seed data. No `Vehicle`/`CarAssignment` seeds exist to remove (the retired schemas were never seeded). All identifiers are unchanged from before this rename.

#### Scenario: Idempotent seed

- WHEN the register Repair import (and `occ hrmq:rules:seed-testdata`) runs twice
- THEN the three assets and two assignments exist exactly once

#### Scenario: Exactly one seeded asset violation

- GIVEN the seeded data
- WHEN the audit runs
- THEN exactly one `nl-asset-assignment-consistency` violation (the open jansen-telefoon uitgifte) and zero `nl-asset-inname-bij-offboarding` violations are reported, and no pre-existing check regresses

Pinned by `RuleAuditServiceTest::testSeededAssetDataFlagsExactlyOneAssignmentConsistencyViolation` and `::testSeededOffboardingJansenLastWorkingDayInTheFutureDoesNotFlagTheOpenTelefoonUitgifte`, updated to the renamed field/enum names.
