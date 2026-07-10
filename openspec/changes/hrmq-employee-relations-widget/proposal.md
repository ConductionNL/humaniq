---
kind: config
---

## Why

Both detail pages hrmq ships declare a `related` widget whose own `_note` says it exists to show
"the linked Employee/project" (`src/manifest.json:90` for `TimesheetDetail`'s `ts-related`,
`src/manifest.json:146` for `ExpenseDetail`'s `ex-related`). But neither the `Timesheet` nor the
`Expense` schema declares `employeeId` (or `projectId`/`costCenter`) as an OpenRegister relation —
`grep -n "relation" lib/Settings/register.d/*.json` returns nothing. In both fragments
`employeeId` is typed as a plain `"type": "string"` field with no `$ref`/`inversedBy`/
`x-openregister-relations` metadata (`lib/Settings/register.d/hr-timesheet.json:60-63`,
`lib/Settings/register.d/hr-expense.json:62-65`).

Concretely: the seed data in `lib/Settings/register.d/hr-seed.json` sets `employeeId` to
business-key strings such as `"employee-jansen"` (lines 11, 27, 45, 62, 78, 96) — there is no
seeded `Employee` object with a matching identifier anywhere in the register config, and no
`Employee` register at all is referenced from either fragment. OpenRegister's relation graph
(`/uses` / `/used`, resolved by `RelationHandler` against `@self.relations`) only resolves object
UUIDs found in that field; a bare business-key string with no declared reference is invisible to
it. This is the exact "related objects show nothing" failure mode already documented for shillinq
(`reference_or-relations-and-related-widget.md`): the `related` widget's Objects section
(`CnRelatedObjectsWidget`, fed by `store.fetchUses/Used`) will render permanently empty on both
hrmq detail pages, in production, for every timesheet and every expense claim — not a hypothetical,
a direct consequence of the current schema + seed shape.

This is also an ADR-022 gap: OpenRegister's **Relations** abstraction ("Typed links between OR
objects") exists precisely so apps don't have to hand-roll cross-object linkage as opaque scalar
fields. hrmq ships a widget that *advertises* consuming that abstraction (the manifest `_note`
explicitly names it) without actually declaring the relation that would make it work.

## What Changes

- Declare `employeeId` on both the `Timesheet` (`hr-timesheet.json`) and `Expense`
  (`hr-expense.json`) schemas as a proper OpenRegister object reference to an `Employee` object in
  the `hrmq` register (schema-declared relation metadata, per ADR-022's Relations abstraction —
  NOT a hand-rolled string convention).
- Add three seeded `Employee` objects to `lib/Settings/register.d/hr-seed.json` (or a new
  `hr-employee-seed.json` fragment) whose object UUIDs are what the existing Timesheet/Expense seed
  rows reference, replacing the current placeholder strings (`"employee-jansen"`,
  `"employee-devries"`, `"employee-bakker"`) with resolvable references to those seeded objects.
  **BREAKING**: any existing local/dev database rows carrying the old placeholder `employeeId`
  strings will not resolve as relations until re-seeded or migrated.
- Verify (manual + Playwright) that the `related` widget on both `TimesheetDetail` and
  `ExpenseDetail` actually lists the linked Employee object after the fix — this is the acceptance
  bar, not just "the field looks like a reference now."
- No PHP controller/service changes — this is entirely register-config + seed-data (`kind: config`).

## Capabilities

### New Capabilities
- `hrmq-employee-relations`: `Timesheet`/`Expense` objects carry a resolvable OpenRegister relation
  to their claiming `Employee`, and the manifest's `related` widget surfaces it.

## Impact

- **`lib/Settings/register.d/hr-timesheet.json`** — `employeeId` property gains relation metadata.
- **`lib/Settings/register.d/hr-expense.json`** — `employeeId` property gains relation metadata.
- **`lib/Settings/register.d/hr-seed.json`** — add seeded `Employee` objects; update the six
  existing Timesheet/Expense seed rows to reference their real UUIDs (or slugs OpenRegister
  resolves to UUIDs) instead of placeholder strings.
- No route, controller, or frontend code changes.
