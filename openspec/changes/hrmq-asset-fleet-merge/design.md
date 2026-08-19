# Design — hrmq-asset-fleet-merge

## Context

See proposal.md - Why. Two register fragments model the same real-world thing: `hr-assets.json`'s
`Asset`/`AssetAssignment` (company property, custody lifecycle, `kenteken` already on `Asset` for
`category: voertuig`) and `hr-fleet.json`'s `Vehicle`/`CarAssignment` (fiscal facts + holding
period for a company car, landed two days later once `payroll-core-engine` cleared the block
`Vehicle`'s own description named). `PayrollRunService::generate()` and `NlFleetChecks` are live
consumers of `Vehicle`/`CarAssignment` — this is not a dead-code cleanup; the merge has to keep the
`bijtelling` gross-fold and its audit rule working, cents-exact, through the rename.

Live row counts, re-verified 2026-08-19 against `localhost:8080` via
`GET /apps/openregister/api/objects/hrmq/<Schema>?_limit=1`: `Asset` 3 (none `category: voertuig`),
`AssetAssignment` 0, `Vehicle` 0, `CarAssignment` 0 — matching the wave-1 brief's figures exactly.
No object needs migrating.

## Goals / Non-Goals

**Goals:** one `Asset` schema and one `AssetAssignment` schema carrying everything `Vehicle`/
`CarAssignment` carried, in English field names; a write-time guard so a `category: vehicle` Asset
cannot be saved without the fiscal facts the bijtelling calculation needs (closing a silent-zero
gap the pre-merge two-schema design could not have); `PayrollRunService`/`NlFleetChecks`/
`RuleAuditService` rewired with the identical formula and rule id; two menu entries, two pages, and
two schemas retired without breaking a linked-to URL.

**Non-Goals:** see proposal.md's Non-goals section (no data migration, `Payslip.bijtelling` itself
out of scope, `ExpensesGroup`/ADR-001 menu relocation out of scope, no manifest-schema extension in
`nextcloud-vue`, the payroll calculator and `nl-2026.json` table untouched).

## Decisions

### D1 — English field/enum naming table

Every renamed field, chosen for readability over literal translation where the two diverge (e.g.
"ingenomen" → `checkedIn`, not the ambiguous "returned", which is reserved for the assignment-level
`returnedOn` date):

| Dutch (removed) | English (added) | Schema |
|---|---|---|
| `uitgifteDatum` | `issuedOn` | AssetAssignment |
| `innameDatum` | `returnedOn` | AssetAssignment |
| `uitgifteBonSigned` | `issueReceiptSigned` | AssetAssignment |
| `eigenBijdrage` (was `CarAssignment`) | `employeeContribution` | AssetAssignment |
| `kenteken` | `licencePlate` | Asset |
| `serienummer` | `serialNumber` | Asset |
| `aanschafdatum` | `purchaseDate` | Asset |
| `aanschafwaarde` | `purchaseValue` | Asset |
| `cataloguswaarde` (was `Vehicle`) | `listPrice` | Asset |
| `bijtellingCategorie` (was `Vehicle`) | `companyCarTaxCategory` | Asset |
| `carAssignmentId` (was Payslip, `hr-objects.json`) | `assetAssignmentId` | Payslip |

| Dutch enum value (removed) | English (added) | Property |
|---|---|---|
| `category`: `telefoon` | `phone` | Asset.category |
| `category`: `voertuig` | `vehicle` | Asset.category |
| `category`: `gereedschap` | `tool` | Asset.category |
| `category`: `toegangspas` | `accessPass` | Asset.category |
| `category`: `kleding` | `clothing` | Asset.category |
| `category`: `overig` | `other` | Asset.category |
| `status`: `beschikbaar` | `available` | Asset.status |
| `status`: `uitgegeven` | `issued` | Asset.status |
| `status`: `ingenomen` | `checkedIn` | Asset.status |
| `status`: `afgeschreven` | `writtenOff` | Asset.status |
| lifecycle transition `uitgeven` | `issue` | Asset `x-openregister-lifecycle` |
| lifecycle transition `innemen` | `checkIn` | Asset `x-openregister-lifecycle` |
| lifecycle transition `vrijgeven` | `release` | Asset `x-openregister-lifecycle` |
| lifecycle transition `afschrijven` | `writeOff` | Asset `x-openregister-lifecycle` |
| `fuelType`: `benzine`/`hybride`/`volledigElektrisch`/`waterstof` | `gasoline`/`hybrid`/`fullyElectric`/`hydrogen` | Asset.fuelType |
| `bijtellingCategorie`: `standaard`/`elektrischGeplafonneerd` | `standard`/`evReducedCapped` | Asset.companyCarTaxCategory |

`laptop`/`overig`-as-`fuelType`'s-`overig`/`active`/`administrationId`/`name`/`notes` were already
English and are unchanged. This is a deliberate scope inclusion, not scope creep: the brief's
constraint 4 requires English field/enum names; doing the rename in the same change that already
touches every one of these fields' declarations is strictly cheaper than a follow-up pass that has
to re-open the same two schemas, the same four PHP consumers, and the same manifest pages a second
time. `bijtelling` itself is a retained statutory-scheme noun (constraint 4's proper-noun/scheme-name
exemption), not translated — it names a specific Wet LB 1964 mechanism the way "vakantiegeld" or
"WKR" do elsewhere in the corpus, and it lives on `Payslip`, a schema this change does not
otherwise touch (proposal Non-goals).

**Alternative considered:** keep `bijtellingCategorie` verbatim as a statutory term, like
`bijtelling` itself. Rejected — "categorie" is the generic noun "category" wearing a Dutch
suffix, not part of the statutory name (the statutory name is "bijtelling privégebruik auto";
nothing is lost by calling its classification field `companyCarTaxCategory`). Rule ids
(`nl-bijtelling-auto-privegebruik`, `nl-asset-assignment-consistency`) and `framework` slugs are
**not** renamed — the wider rule corpus already uses Dutch statutory-term id slugs throughout
(`nl-wnt-norm-overschrijding`, `nl-hr21-schaal-consistentie`, `nl-abp-aansluiting`, …); renaming
ids corpus-wide is a separate, much larger migration than this schema merge and is not attempted
here.

### D2 — Vehicle-only fields are guarded by a corpus rule, because the schema layer cannot express it

The three vehicle-only fields (`listPrice`, `fuelType`, `companyCarTaxCategory`) are nullable on
`Asset`, not unconditionally required — required for every other category, they would reject every
laptop/phone/tool/pass/clothing/other Asset outright. Left with no guard at all, a `category:
vehicle` Asset missing them would validate successfully and silently fold €0 bijtelling
(`PayrollRunService::bijtellingCentsFor()`'s existing `is_numeric($cataloguswaarde) === false`
guard was written for a *dangling reference*, not a *present-but-incomplete* one — before this
merge that path was unreachable, because `Vehicle`'s own unconditional `required` made an
incomplete Vehicle impossible to create).

**REWRITTEN 2026-08-19 (orchestrator review). The original mechanism does not run.** The first
draft specified a schema-level `allOf`/`if`/`then` block and justified it as "validated by
OpenRegister's `opis/json-schema` engine". opis does implement `if`/`then`/`else` — that part is
true — but OpenRegister never hands it those keywords. Measured:

- `Schema::getSchemaObject()` (`../openregister/lib/Db/Schema.php:1707`) constructs a **fresh
  `stdClass`** carrying only `title`, `description`, `version`, `type`, `required`, `$schema`,
  `$id` and `properties`. It emits no top-level `allOf`, `oneOf`, `anyOf`, `if` or `then`.
- `ValidateObject` validates against exactly that object (`:1553`, `:1654`) and contains **zero**
  occurrences of `allOf`, `if` or `then` (`grep -c` → 0).
- So `Schema` having an `allOf` column, and `jsonSerialize()` emitting it, is irrelevant to
  validation: the column round-trips through the API and never reaches the validator.

The cited precedent was also not one. `decidesk/lib/Settings/register.d/66-organisation-goals.json`
lines 305/337/369 are `"if": [` — a **JSONLogic expression array** inside
`x-openregister-calculations`, not a JSON Schema `if` (which takes a schema object). It matched on
the string, not the construct.

Had this shipped as drafted, the block would have been written, imported, stored, and enforced
nowhere — an incomplete vehicle Asset would validate cleanly, exactly the failure the guard exists
to prevent, while the spec claimed it could not happen.

**The mechanism is therefore a rule-engine check**: a new machine-checkable corpus rule
`nl-asset-voertuig-fiscale-velden-compleet` in `lib/Standards/rules/`, predicate in
`NlFleetChecks`, firing when `category === "vehicle"` and any of the three fiscal fields is
absent. Detection is audit-time rather than write-time, which is weaker — and the tasks must say so
rather than implying parity with the pre-merge `Vehicle.required` guard that genuinely was
write-time. Two things narrow the gap:

- `PayrollRunService::bijtellingCentsFor()`'s existing `is_numeric($listPrice) === false` guard
  still yields €0 rather than a wrong figure, so the failure mode is a missing benefit, not a
  miscalculated one.
- `occ hrmq:rules:audit` surfaces the violation, and the obligations widget in the dashboard change
  is where a human sees it.

**The original reasoning about single-object vs cross-object was sound and is preserved** — it is
simply moot, because the write-time tool it argued for does not exist in this stack. That is worth
stating plainly so the next author does not re-derive the same wrong conclusion from the same true
premise about opis.

**This is an OpenRegister gap, and it should be filed as one.** `getSchemaObject()` dropping the
JSON Schema composition keywords means no leaf app in the fleet can express a conditional-required,
a discriminated union, or any `oneOf` variant — while `Schema` carries columns for three of them,
which reads as support. Filing that against OpenRegister is out of this change's scope; recording
it here is not.

### D3 — `hideEmpty`, not a new conditional-visibility construct

`AssetDetail`'s data widget shows all of `Asset`'s properties; after the merge that includes three
fields that are always null on six of the seven categories. `CnObjectDataWidget`'s `hideEmpty` prop
(`docs/components/cn-object-data-widget.md` §"Discriminated supertypes") is documented for exactly
this shape — "one schema holding several variants... where each object only carries the fields its
own variant uses" — and is wired end to end already: `content.hideEmpty: true` in the manifest's
data-widget config, forwarded by `CnDetailPage`/`CnPageRenderer` to `CnObjectDataWidget`'s
`hideEmpty` prop, which hides a property with no value from the read grid (display-only,
non-destructive — a field being edited or with an unsaved change stays visible; `false`/`0` count
as values, never hidden, so a `listPrice: 0` still shows). No manifest-schema change, no new
component, no new prop.

**Alternative considered:** a per-property `visibleWhen` predicate on the data widget (parallel to
the `visibleWhen` already used for action buttons, the banner widget, and `config.fields[]` on
`type: form` pages). Rejected: the manifest v2 `widgetEntry` `$defs` has no `visibleWhen` property
today, and no other widget type does either — adding one is a shared `nextcloud-vue` manifest-schema
change every app on the library would inherit, which is a materially bigger and slower change than
this schema merge needs, for a capability (`hideEmpty`) the library has already shipped for the
identical scenario. Kept as a possible follow-up, not attempted here (see Open Questions).

**Alternative considered:** leave the three fields visible-but-empty. Rejected as the status quo the
merge would otherwise regress *to*: `Asset` already carries one vehicle-only nullable field
(`kenteken`/`licencePlate`) shown unconditionally on every category's detail page today, so the
precedent already exists — but three more such fields is the difference between one stray em dash
and a wall of them, exactly the "type-aware without enumeration" problem `hideEmpty` exists to
solve.

### D4 — One general Asset/AssetAssignment index, not a parallel fleet-scoped one

`RuleAuditService::buildFleetContext()` built two indexes (`vehiclesById`, `carAssignmentsById`) by
independently loading `Vehicle`/`CarAssignment` — a second, parallel load of data the general
`buildRelatedContext()` pre-pass (`context['related']['Asset']['byId']`) was already loading for
`nl-asset-assignment-consistency`'s purposes, just with fewer fields (`{id, status, active}`).

This change extends that one Asset index with `category`/`listPrice`/`fuelType`/
`companyCarTaxCategory`, adds a matching `AssetAssignment` index (`{id, assetId, employeeId,
issuedOn, returnedOn, employeeContribution}` — nothing `NlFleetChecks` needs was on the AssetAssignment
side before; only `Vehicle`/`CarAssignment` had a dedicated index, `Asset`/`AssetAssignment` did
not need one for its own rule), and retires `buildFleetContext()`/`context['fleet']` entirely.
`NlFleetChecks::bijtellingMatchesFormula()` reads `context['related']['AssetAssignment']['byId']`
and `context['related']['Asset']['byId']` instead. One register load per object type per audit run,
not two — the pre-existing pattern (`buildPayrollContext()`'s `runsById`/`loonbeslagenById`
precedent the original `buildFleetContext()` docblock already cited) applied consistently instead
of duplicated.

### D5 — `Payslip.carAssignmentId` renames and repoints; `Payslip.bijtelling` does not

`Payslip.carAssignmentId`'s `$ref` target (`CarAssignment`) disappears in this change — leaving the
field name as-is while repointing its `$ref` to `AssetAssignment` would read as "the assignment ID
that only ever meant CarAssignment," which is actively misleading once `AssetAssignment` covers
every asset category, not just cars. The field is renamed `assetAssignmentId` alongside the
repoint. This is a mechanical consequence of retiring `CarAssignment`, not a discretionary rename —
unlike `Payslip.bijtelling` (the computed amount itself), which stays: it is not a reference to a
disappearing schema, `hr-objects.json` is not one of the two schemas this change restructures, and
renaming it belongs with the Dutch-vocabulary pass over the rest of `hr-objects.json`, not folded
into an unrelated schema-merge change's diff.

### D6 — Retired routes redirect; no manifest-native redirect page type exists

`/vehicles/:id` and `/car-assignments/:id` are removed as `manifest.pages[]` entries (their
schemas no longer exist), but the URL patterns themselves should not start 404ing — deep-link
hygiene independent of whether any object currently lives behind them (none ever did: 0 rows,
Context above). `src/main.js`'s `routesFromManifest()` already appends one hand-written route after
the manifest-derived ones (`{ path: '/:pathMatch(.*)*', redirect: '/timesheets' }`, the vue-router 4
catch-all) — manifest v2's page `type` enum is closed to `index | detail | dashboard | custom` and
has no redirect variant, so a redirect is not a page concept the manifest can express today. Two
more hand-written entries follow the same precedent: `{ path: '/vehicles/:id', redirect: (to) =>
'/assets/' + to.params.id }` and `{ path: '/car-assignments/:id', redirect: (to) =>
'/asset-assignments/' + to.params.id }`, added in `main.js` alongside the existing catch-all, not
as manifest pages.

## Risks / Trade-offs

- **[Risk] A rename in the schema without the matching rename in a rule's `statement` prose or a
  consumer's field read leaves a check silently matching nothing (the wave-1 brief's core warning:
  a rule reading a moved field does not throw, it reports zero violations, which reads as
  compliant).** → Mitigation: tasks.md requires a before/after violation-count assertion for both
  `nl-asset-assignment-consistency` and `nl-bijtelling-auto-privegebruik`, run against the same
  seeded fixture shapes pre- and post-rename, asserted equal — not "the tests pass." Both delta
  specs' final scenario in each modified capability states this explicitly.
- **[Risk] `RuleCatalogue::VERSION` is bumped by every parallel change touching the rule corpus
  (measured at `2026-07.35` at authoring time).** → Mitigation: re-verify against HEAD at apply
  time, the established convention (asset-management-mvp's design.md hit the same collision).
- **[Trade-off] The conditional-required `if`/`then` block is new territory for this codebase's
  register.d schemas — it has no in-repo precedent to copy exactly (D2).** → Accepted: it is
  standard JSON Schema over an already-integrated validator, the risk is authoring-time (getting
  the block's syntax right, verified against a live write in tasks.md), not a new runtime
  dependency or architectural pattern.
- **[Trade-off] `hideEmpty` is set on the whole data widget, not scoped to only the three fiscal
  fields.** It changes the display of every nullable property on `AssetDetail`'s data widget, not
  just `listPrice`/`fuelType`/`companyCarTaxCategory` — an em dash becomes "not shown" for
  `serialNumber`/`licencePlate`/`purchaseDate`/`purchaseValue` too, on every category, whenever
  they happen to be empty. → Accepted: this is the documented, intended behaviour of `hideEmpty`
  (a field being edited or holding an unsaved change stays visible regardless, so nothing becomes
  unreachable), and every one of those other properties was already optional/nullable and sparse
  in practice, so the change is judged net-positive for a schema that now spans eight categories.

## Migration Plan

No data migration (Context — 0 rows on every schema but Asset, and Asset has no `category: voertuig`
rows to backfill fiscal fields onto). Deploy is: land the schema changes, the four PHP consumer
files, the manifest changes, and the two spec deltas in one PR (they are not independently
deployable — a schema rename without the matching consumer rewire breaks `PayrollRunService`
immediately). No feature flag; no phased rollout — the "no live vehicle-category data" fact is what
makes a single-PR cutover safe here specifically, not a general pattern for future schema merges
with non-empty tables.

**Rollback:** revert the PR. Because no data was migrated, a revert is exact — there is no
forward-only state to reconcile.

## Open Questions

- Should manifest v2 gain a first-class conditional-visibility construct for detail-page
  properties (a `visibleWhen` on `widgetEntry` or on `CnObjectDataWidget`'s per-property
  `overrides`), so a future "discriminated supertype" with a genuinely large field set does not
  have to rely on `hideEmpty`'s all-or-nothing per-widget scope? Deferred — `hideEmpty` is
  sufficient for three fields on one widget today; revisit if a future capability needs
  per-property, non-empty-hiding conditional visibility (e.g. hide a *populated* field for the
  wrong variant, which `hideEmpty` cannot do).
