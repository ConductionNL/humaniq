## Why

hrmq carries two parallel asset registers for the same real-world thing. `Asset` (`hr-assets.json`)
already models a voertuig as a category — it carries `category: voertuig` and a `kenteken`
(licence plate) that is meaningless for any other category — and `AssetAssignment` already models
"one Asset held by one Employee over a period" via `uitgifteDatum`/`innameDatum`. `Vehicle`
(`hr-fleet.json`, landed two days later by `fleet-bijtelling`) re-declares the same custody-holder
concept end to end: a second schema for the item (`name`, `kenteken`, `active`, `administrationId`
— four of `Asset`'s eight properties, verbatim) and a second schema for the holding period
(`CarAssignment`: `effectiveFrom`/`effectiveTo` instead of `uitgifteDatum`/`innameDatum`,
`employeeId` the same `$ref Employee`). `Vehicle`'s own description names the split as deliberate
at the time — the fiscal facts were "engine-blocked" until `payroll-core-engine` landed and
`Asset`/`AssetAssignment` explicitly disclaimed fiscal semantics — but that blocker cleared when
`fleet-bijtelling` shipped `PayrollRunService`'s gross-fold, and the split it left behind is now
pure duplication: two index pages, two detail pages, two menu entries, and two schemas that
`nl-asset-assignment-consistency` and `nl-bijtelling-auto-privegebruik` audit as if they were
unrelated collections of company property.

This is the cheapest structural fix available in the wave-1 programme to attempt: `Vehicle` = 0
rows, `CarAssignment` = 0 rows, `Asset` = 3 rows, live-verified against `localhost:8080` on
2026-08-19 (`Asset` total 3, `AssetAssignment`/`Vehicle`/`CarAssignment` total 0 each, via
`GET /apps/openregister/api/objects/hrmq/<Schema>?_limit=1`). No object needs migrating and no
existing custody or fiscal record needs reconciling — the entire risk of this change is in its
*consumers*, not its data.

## What Changes

- **`Vehicle` merges into `Asset`.** `Asset` gains three fields Vehicle carries and Asset lacks —
  renamed `listPrice` (was `cataloguswaarde`), `fuelType` (unchanged, already English), and
  `companyCarTaxCategory` (was `bijtellingCategorie`) — nullable, and **not** unconditionally
  required: they are meaningless for `category != voertuig`. A schema-level JSON Schema
  conditional (`allOf`/`if`/`then`, validated by OpenRegister's `opis/json-schema` engine, no new
  construct) requires all three **when** `category === "voertuig"`, closing the silent-zero gap
  `PayrollRunService::bijtellingCentsFor()` and `NlFleetChecks` both currently tolerate (a
  non-numeric `cataloguswaarde` today folds €0 bijtelling with no error — after this change, a
  voertuig `Asset` cannot be saved without the fields the calculation needs). `hr-fleet.json` is
  deleted; `Vehicle`'s remaining fields (`name`, `kenteken`→`licencePlate`, `active`,
  `administrationId`) were already on `Asset`.
- **`CarAssignment` merges into `AssetAssignment`.** `AssetAssignment` gains `employeeContribution`
  (was `eigenBijdrage`, `CarAssignment`'s one field `AssetAssignment` lacks) — nullable, default 0,
  harmless on a non-vehicle assignment (reads as "no contribution", the existing posture for every
  other asset category). `CarAssignment.vehicleId` becomes an `assetId` reference to the merged
  `Asset` (already `AssetAssignment`'s own reference field — no new column).
- **English-language renames on both merged schemas**, since they are being restructured anyway
  (cheaper here than a separate pass): `uitgifteDatum`→`issuedOn`, `innameDatum`→`returnedOn`,
  `uitgifteBonSigned`→`issueReceiptSigned`, `kenteken`→`licencePlate`, `serienummer`→`serialNumber`,
  `aanschafdatum`→`purchaseDate`, `aanschafwaarde`→`purchaseValue`, `eigenBijdrage`→
  `employeeContribution`, `cataloguswaarde`→`listPrice`, `bijtellingCategorie`→
  `companyCarTaxCategory`. `Asset.category`/`Asset.status` enum values translate
  (`voertuig`→`vehicle`, `beschikbaar`→`available`, `uitgegeven`→`issued`, `ingenomen`→`returned`,
  `afgeschreven`→`writtenOff`, etc. — full mapping in design.md) — **BREAKING** for any external
  consumer keyed on the Dutch literals, of which none exist today (0 rows on every affected
  schema; `x-openregister-lifecycle` transition names and the rule corpus's own field references
  move in lockstep in this same change). `bijtellingCategorie`'s own enum
  (`standaard`/`elektrischGeplafonneerd`) translates to `standard`/`evReducedCapped`. "Bijtelling"
  itself is a statutory concept, not a proper noun, and is out of scope here: it lives on
  `Payslip.bijtelling` (`hr-objects.json`), a schema this change does not otherwise touch (see
  Non-goals).
- **`Payslip.carAssignmentId` repoints to the merged schema.** Its `$ref` target becomes
  `AssetAssignment`; the field itself is renamed `assetAssignmentId` to stop naming a schema that
  no longer exists. `PayrollRunService`, `NlFleetChecks`, and `RuleAuditService::buildFleetContext()`
  are rewired to resolve bijtelling from `AssetAssignment`→`Asset` (filtered to `category ===
  "vehicle"` where the calculation needs it; everywhere else the existing non-numeric-`listPrice`
  guard already degrades a non-vehicle Asset to €0 bijtelling, so no new category check is needed
  in the arithmetic itself). `RuleAuditService::buildFleetContext()` is retired: the general
  `related.Asset.byId` index (`buildRelatedContext()`) already carries every `Asset`, extended with
  the three new fields; a matching `related.AssetAssignment.byId` index is added alongside it, and
  `NlFleetChecks` reads both instead of a dedicated `context['fleet']` block — one fewer parallel
  index over the same data.
- **Two menu entries and two pages retire.** `Vehicles` ("Wagenpark") and `CarAssignments`
  ("Autotoewijzingen") are removed from the `ExpensesGroup` menu group and from `src/manifest.json`
  `pages[]`; `VehicleDetail`/`CarAssignmentDetail` retire with them. Their four `deepLinks[]`
  manifest entries are removed (`Vehicle`, `CarAssignment` — schemas that no longer exist) and
  their two URL templates (`/vehicles/{uuid}`, `/car-assignments/{uuid}`) become vue-router
  redirects to the merged Asset/AssetAssignment detail routes, so a bookmarked or externally-linked
  URL still resolves instead of 404ing. `AssetDetail`'s data widget gains the three fiscal fields
  behind `content.hideEmpty: true` (the documented "discriminated supertype" mechanism —
  `CnObjectDataWidget`'s `hideEmpty` prop — so a laptop or badge no longer shows three em-dashed
  fiscal columns it will never have).
- **The `nl-bijtelling-auto-privegebruik` and `nl-asset-assignment-consistency` rules are
  re-pointed, not re-specified**: same statement, same severity, same `machineCheckable: true`,
  reading the renamed fields off the merged schemas. `hr-seed.json`'s `Asset`/`AssetAssignment`
  seeds are renamed field-for-field (no seed added or removed — the deliberately-inconsistent
  `assetassignment-jansen-telefoon` seed still exercises `nl-asset-assignment-consistency` on the
  same data, under new field names).

### Non-goals

- **No data migration.** `Vehicle`/`AssetAssignment`/`CarAssignment` are empty; `Asset` has 3 rows
  none of which are `category: voertuig` (verified below). There is nothing to backfill or
  reconcile.
- **Renaming `Payslip.bijtelling`, or any other Dutch field on `hr-objects.json`, is out of
  scope.** `Payslip` is not one of the two schemas this change restructures; only the one field
  that names the disappearing `CarAssignment` schema (`carAssignmentId`) is touched, because
  leaving it pointed at a deleted `$ref` target is not optional. The rest of `hr-objects.json`'s
  Dutch vocabulary is a separate pass.
- **Relocating the `Assets`/`AssetAssignments` menu entries under ADR-001's frozen "Declaraties &
  assets" placement is out of scope.** They currently sit inside the rogue `ExpensesGroup`
  top-level menu alongside `Vehicles`/`CarAssignments`; this change only removes the two leaves
  whose schemas disappear. Correcting where the remaining five `ExpensesGroup` children live is
  `hrmq-ia-navigation-alignment`'s job (per the wave-1 brief, absorbed by wave-1 change #2) — this
  change does not touch top-level menu structure or the 11-group count ADR-097 measures.
- **No `visibleWhen`/conditional-visibility extension to the manifest v2 schema.**
  `CnObjectDataWidget`'s per-property `overrides`/`exclude`/`include` are static (hide the same
  keys for every object); a true per-object conditional column would need a manifest-schema change
  in `nextcloud-vue` shared by every app. `hideEmpty` is the documented, already-shipped answer for
  exactly this "discriminated supertype" shape and is used instead — see design.md.
- **The payroll calculator, `CalculationInput`/`CalculationResult`, and the `nl-2026.json`
  bijtelling rate/cap table are untouched.** This change moves and renames the schema fields that
  feed `PayrollRunService::bijtellingCentsFor()`; the formula, the table data, and the engine
  boundary `fleet-bijtelling` established stay byte-identical.

## Capabilities

### Modified Capabilities
- `asset-management`: `Asset` gains three vehicle-only fields (conditionally required) and an
  English rename of every Dutch field/enum; `AssetAssignment` gains `employeeContribution` and the
  same rename; `nl-asset-assignment-consistency`/`nl-asset-inname-bij-offboarding` read the renamed
  fields.
- `fleet-bijtelling`: `Vehicle`/`CarAssignment` retire as standalone schemas; their fiscal-facts and
  holding-period concepts are now expressed on `Asset`/`AssetAssignment`; `PayrollRunService`,
  `NlFleetChecks`, and `RuleAuditService` are rewired to the merged shape with the same formula and
  the same `nl-bijtelling-auto-privegebruik` rule id.

## Impact

- **`lib/Settings/register.d/hr-assets.json`** — `Asset` gains `listPrice`/`fuelType`/
  `companyCarTaxCategory` + a conditional-required block; every Dutch field/enum renamed;
  `AssetAssignment` gains `employeeContribution`; version bump.
- **`lib/Settings/register.d/hr-fleet.json`** — deleted.
- **`lib/Settings/register.d/hr-objects.json`** — `Payslip.carAssignmentId` renamed
  `assetAssignmentId`, `$ref` repointed to `AssetAssignment`.
- **`lib/Settings/register.d/hr-seed.json`** — `Asset`/`AssetAssignment` seed field names updated;
  no `Vehicle`/`CarAssignment` seeds exist to remove.
- **`lib/Service/PayrollRunService.php`** — `openCarAssignmentsByEmployeeKey()`/
  `openCarAssignmentFor()`/`vehiclesById()`/`bijtellingCentsFor()`/`bijtellingFields()` re-target
  `AssetAssignment`/`Asset`, renamed fields, `assetAssignmentId`.
- **`lib/Standards/Checks/NlFleetChecks.php`** — reads `related.AssetAssignment.byId` /
  `related.Asset.byId` instead of `context['fleet']`; renamed field reads.
- **`lib/Standards/Checks/NlAssetChecks.php`** — renamed field reads
  (`uitgifteDatum`/`innameDatum`→`issuedOn`/`returnedOn`).
- **`lib/Service/RuleAuditService.php`** — `buildFleetContext()` removed; `buildRelatedContext()`'s
  `Asset`/new `AssetAssignment` indexes extended.
- **`lib/Standards/rules/labour.json`, `lib/Standards/rules/payroll.json`** — rule `statement` text
  updated to the renamed field names; `RuleCatalogue::VERSION` bumped (re-verify against HEAD at
  apply time).
- **`src/manifest.json`** — `Vehicles`/`CarAssignments` menu entries, pages, and `deepLinks[]`
  entries removed; two redirect routes added; `AssetDetail`/`AssetAssignmentDetail`/`Assets`/
  `AssetAssignments` widget `include`/`columns` lists updated to the renamed fields plus the three
  new fiscal fields behind `hideEmpty`.
- **`openspec/specs/asset-management/spec.md`, `openspec/specs/fleet-bijtelling/spec.md`** — delta
  specs in this change.
- **Tests**: `tests/Unit/Service/PayrollRunServiceTest.php`,
  `tests/Unit/Standards/Checks/NlFleetChecksTest.php`,
  `tests/Unit/Standards/Checks/NlAssetChecksTest.php`,
  `tests/Unit/Service/RuleAuditServiceTest.php`,
  `tests/Unit/Standards/Checks/NlAdministratieChecksTest.php` — field-name and fixture updates.
- No route, controller, or database change (hrmq owns no CRUD — ADR-022).
