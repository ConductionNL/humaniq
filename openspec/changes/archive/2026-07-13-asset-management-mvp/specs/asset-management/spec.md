# Delta Spec: asset-management (asset-management-mvp)

## ADDED Requirements

### Requirement: A new `hr-assets` fragment SHALL define the `Asset` schema with a declarative custody lifecycle (REQ-AST-001)

`lib/Settings/register.d/hr-assets.json` (new file, `x-hrmq-fragment: hr-assets`, OpenAPI 3.0.0 `components.schemas` shape like `hr-org.json`) SHALL declare `Asset` (slug `Asset`, icon `PackageVariantClosed`, version `0.1.0`, `x-schema-org: schema:IndividualProduct`) with properties: `name` (string), `category` (enum `laptop|telefoon|voertuig|gereedschap|toegangspas|kleding|overig`), `serienummer` (string, nullable), `kenteken` (string, nullable — Dutch licence plate, meaningful for category `voertuig`; carries no fiscal semantics, bijtelling is an explicit non-goal per Spectr `hrmq-canon-fleet-bijtelling`), `aanschafdatum` (string, format date, nullable), `aanschafwaarde` (number, nullable), `status` (enum `beschikbaar|uitgegeven|ingenomen|afgeschreven`), `active` (boolean, default `true`). `required: [name, category, status]`. `status` SHALL carry an `x-openregister-lifecycle` (initial `beschikbaar`, terminal `afgeschreven`) with exactly four transitions: `uitgeven` (`beschikbaar`→`uitgegeven`), `innemen` (`uitgegeven`→`ingenomen`), `vrijgeven` (`ingenomen`→`beschikbaar`), `afschrijven` (`beschikbaar`/`ingenomen`→`afgeschreven`) — no guards. The existing register Repair import picks the fragment up without code changes.

#### Scenario: Schema validates a new asset in stock

- GIVEN the imported hrmq register
- WHEN an object `{name: "Fairphone 5", category: "telefoon", status: "beschikbaar"}` is created
- THEN creation succeeds with `active` defaulted to `true` and `serienummer`/`kenteken`/`aanschafdatum`/`aanschafwaarde` null

#### Scenario: Unknown category rejected

- WHEN an object is written with `category: "meubilair"`
- THEN OpenRegister schema validation rejects it (enum mismatch)

#### Scenario: Lifecycle blocks writing off an issued asset

- GIVEN an Asset in status `uitgegeven`
- WHEN the `afschrijven` transition is attempted
- THEN the lifecycle rejects it (`afschrijven` is only reachable from `beschikbaar` or `ingenomen`)

#### Scenario: Returned asset can re-enter stock

- GIVEN an Asset in status `ingenomen`
- WHEN the `vrijgeven` transition is applied
- THEN the asset's status becomes `beschikbaar` and it is issuable again via `uitgeven`

### Requirement: The `hr-assets` fragment SHALL define the effective-dated `AssetAssignment` schema (REQ-AST-002)

The same fragment SHALL declare `AssetAssignment` (slug `AssetAssignment`, icon `HandshakeOutline`, version `0.1.0`, `x-schema-org: schema:OwnershipInfo`) with properties: `assetId` (string, format uuid, `$ref: Asset`), `employeeId` (string, format uuid, `$ref: Employee`), `uitgifteDatum` (string, format date), `innameDatum` (string, format date, nullable — null while the asset is out), `uitgifteBonSigned` (boolean, default `false`), `notes` (string, nullable). `required: [assetId, employeeId, uitgifteDatum]`. `AssetAssignment` SHALL NOT carry an `x-openregister-lifecycle` (a plain effective-dated record, the OrgAssignment pattern).

#### Scenario: Open uitgifte is valid

- WHEN an object `{assetId: <asset uuid>, employeeId: <employee uuid>, uitgifteDatum: "2026-06-15"}` is created
- THEN creation succeeds with `innameDatum` null (an open, current uitgifte) and `uitgifteBonSigned` defaulted to `false`

#### Scenario: Missing asset reference rejected

- WHEN an object is written without `assetId`
- THEN OpenRegister schema validation rejects it (required property)

### Requirement: The relations SHALL be canonical `$ref`s that the renderer's related machinery resolves (REQ-AST-003)

Both reference fields (`AssetAssignment.assetId`→Asset, `AssetAssignment.employeeId`→Employee) SHALL be declared as `$ref` relations per ADR-062 rule 7 (both target schemas exist in this register set), stored as UUIDs. Outbound refs SHALL resolve in the `related` widget on `AssetAssignmentDetail` (the OrgAssignmentDetail behaviour); the inbound relation SHALL surface as an FK-scoped `object-list` of uitgiftes on `AssetDetail`.

#### Scenario: Assignment detail resolves both ends

- GIVEN a seeded AssetAssignment opened on `AssetAssignmentDetail`
- WHEN the page renders
- THEN the Related panel lists the referenced Asset and Employee by name, each linking to its detail page
- @e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: The labour corpus SHALL gain two machine-checkable asset-custody rules (REQ-AST-004)

`lib/Standards/rules/labour.json` SHALL gain `nl-asset-assignment-consistency` (severity `mandatory`) and `nl-asset-inname-bij-offboarding` (severity `recommended` — a SHOULD-strength administration control), both `domain: labour`, `jurisdiction: NL`, `framework: hr-assets-core`, `machineCheckable: true`, control-style `source` per the design.md table (administration-integrity controls, the `hr-org-core` precedent, not statute transcriptions). `hr-assets-core` SHALL be added as a new framework slug to the examples list in `lib/Standards/rules/SCHEMA.md`. `RuleCatalogue::VERSION` SHALL be bumped per SCHEMA.md's "bump on any change" rule (at authoring time `2026-07.5` → `2026-07.6`; the current HEAD value MUST be re-verified at apply time — parallel changes bump the same constant).

#### Scenario: Corpus stays loadable and versioned

- WHEN `occ hrmq:rules:audit` runs after the corpus edit
- THEN the RuleCatalogue loads without error and reports both new rules as enforced (each has a CheckProvider predicate)

### Requirement: `NlAssetChecks` SHALL enforce assignment consistency and offboarding asset return via the related-context (REQ-AST-005)

A new auto-discovered provider `lib/Standards/Checks/NlAssetChecks.php` (implements `CheckProvider`; no seeded sample objects — seeds live in hr-seed.json, the NlOrgChecks reasoning) SHALL register both predicates keyed on `AssetAssignment` (pure `fn(array $object, array $context): bool`). `RuleAuditService::buildRelatedContext()` SHALL be extended consistently with an `Asset` index — `context['related']['Asset']['byId']` mapping each asset id to `{id, status, active}` (the OrgUnit index shape) — and an `Offboarding` index — `context['related']['Offboarding']['plannedCompletionByEmployeeId']` mapping each employeeId to the latest planned-completion date of its Offboarding objects — both with the same single pre-pass and the same degrade-to-empty behaviour when a schema is not yet imported. Predicates:

1. **`nl-asset-assignment-consistency`** — violates when `innameDatum` is present and earlier than `uitgifteDatum`, or when the assignment is open (no `innameDatum`) and `assetId` does not resolve in the Asset index, or resolves to an asset whose `status` is not `uitgegeven`, or `employeeId` does not resolve in the existing Employee index (fail-closed on dangling references).
2. **`nl-asset-inname-bij-offboarding`** — violates when the assignment is open and its `employeeId` resolves in the Offboarding index to a planned-completion date strictly before the audit run date. The predicate MUST pass vacuously when the Offboarding index is empty or the employee has no entry (defensive coordination with the parallel `offboarding-wizard-mvp` change — the two changes land in either order; unreadable index entries are skipped at index build, degrading to vacuous pass, never to a false violation).

#### Scenario: Incoherent dates flagged

- GIVEN an AssetAssignment with `uitgifteDatum: 2026-06-01` and `innameDatum: 2026-05-01`
- WHEN `occ hrmq:rules:audit` runs
- THEN a `nl-asset-assignment-consistency` violation is reported for that assignment

#### Scenario: Open uitgifte on an in-stock asset flagged

- GIVEN the seed assignment `assetassignment-jansen-telefoon` (open, referencing the `beschikbaar` telefoon)
- WHEN the audit runs
- THEN a `nl-asset-assignment-consistency` violation is reported for it

#### Scenario: Closed uitgifte on a non-issued asset passes

- GIVEN an AssetAssignment with coherent dates and an `innameDatum` in the past, referencing an Asset in status `beschikbaar`
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

- GIVEN a register in which no Offboarding schema or objects exist (the parallel offboarding-wizard-mvp change not yet landed)
- WHEN the audit runs
- THEN no `nl-asset-inname-bij-offboarding` violation is reported for any assignment

### Requirement: New asset pages SHALL surface the register under the existing expenses menu group (REQ-AST-006)

`src/manifest.json` SHALL gain (a) an `Assets` index page (route `/assets`, register `hrmq`, schema `Asset`) with columns `name`, `category`, `status`, `serienummer`, filters `category`/`status`, default sort `name` ascending; (b) an `AssetDetail` detail page (route `/assets/:id`) with a `data` widget (name/category/serienummer/kenteken/aanschafdatum/aanschafwaarde/status/active), `lifecycleActions` exposing exactly the four fragment transitions (`uitgeven`/`innemen`/`vrijgeven`/`afschrijven` — never invent a transition), a `related` widget, an FK-scoped `object-list` "Uitgiftes" (`AssetAssignment`, `filter: { assetId: "@objectId" }`, sort `uitgifteDatum` descending, rowRoute `AssetAssignmentDetail`), an `integration: files` widget for signed uitgiftebonnen (the ExpenseDetail receipt placement) and an audit-history sidebar tab; (c) an `AssetAssignments` index page (route `/asset-assignments`) with columns `assetId`, `employeeId`, `uitgifteDatum`, `innameDatum`, `uitgifteBonSigned`, sort `uitgifteDatum` descending; (d) an `AssetAssignmentDetail` detail page (route `/asset-assignments/:id`) with a `data` widget (excluding `assetId`/`employeeId` — Related resolves both by name), a `related` widget and an audit-history sidebar tab, and no lifecycleActions; (e) menu children `Assets` ("Assets", icon `PackageVariantClosed`) and `AssetAssignments` ("Uitgiftes", icon `HandshakeOutline`) added to the **existing** `ExpensesGroup` after `ExpenseApproval` — the group MUST NOT be renamed by this change (IA renames are owned by the active `hrmq-ia-navigation-alignment` change, which re-homes this group to the frozen ADR-001 menu 7 "Declaraties & assets"); (f) `deepLinks` entries for `Asset` (`/apps/hrmq/assets/{uuid}`) and `AssetAssignment` (`/apps/hrmq/asset-assignments/{uuid}`). The manifest MUST validate (`npm run check:manifest`). Icons MUST exist in `node_modules/vue-material-design-icons` (`LaptopOutline` does not — `PackageVariantClosed` and `HandshakeOutline` are verified present).

#### Scenario: Manifest stays valid

- WHEN `npm run check:manifest` runs
- THEN it exits 0

#### Scenario: Asset detail shows lifecycle actions and uitgifte history

- GIVEN the seeded `asset-bus-transit` opened on `AssetDetail`
- WHEN the page renders
- THEN the data card shows the kenteken, the lifecycle actions offer only the transitions valid from the current status, and the Uitgiftes list shows the closed visser assignment linking to its detail page
- @e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: Seed data SHALL provide three assets and two uitgiftes with one deliberate inconsistency (REQ-AST-007)

`lib/Settings/register.d/hr-seed.json` SHALL gain three Asset seeds — `asset-laptop-latitude` (category `laptop`, serienummer placeholder, status `uitgegeven`), `asset-telefoon-fairphone` (category `telefoon`, status `beschikbaar`), `asset-bus-transit` (category `voertuig`, kenteken placeholder `V-000-XX`, status `uitgegeven` — the fleet example, nothing fiscal) — and two AssetAssignment seeds referencing by slug (the Timesheet `employeeId` mechanism) against the **slugged** Employee seeds `employee-jansen` and `employee-visser`: `assetassignment-visser-bus` (closed: uitgifteDatum `2025-01-06`, innameDatum `2025-12-19`, uitgifteBonSigned `true` — consistent) and `assetassignment-jansen-telefoon` (open: uitgifteDatum `2026-06-15`, innameDatum null, uitgifteBonSigned `false`) whose referenced telefoon is `beschikbaar` — **deliberately inconsistent** so `nl-asset-assignment-consistency` fires exactly once on seed data. No Offboarding seeds (owned by `offboarding-wizard-mvp`; `nl-asset-inname-bij-offboarding` stays vacuously green on seeds, its violating path pinned by unit tests). All identifiers SHALL be obvious placeholders.

#### Scenario: Idempotent seed

- WHEN the register Repair import (and `occ hrmq:rules:seed-testdata`) runs twice
- THEN the three assets and two assignments exist exactly once

#### Scenario: Exactly one seeded asset violation

- GIVEN the seeded data
- WHEN the audit runs
- THEN exactly one `nl-asset-assignment-consistency` violation (the open jansen-telefoon uitgifte) and zero `nl-asset-inname-bij-offboarding` violations are reported, and no pre-existing check regresses
