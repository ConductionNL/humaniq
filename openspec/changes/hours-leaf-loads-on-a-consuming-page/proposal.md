---
kind: code
---

# Proposal: the hours leaf loads on a consuming page

## Why

Humaniq registers an hours leaf and it has never rendered anywhere but on
humaniq's own pages.

Both halves exist and agree. `RegisterHoursLeafListener` declares
`LEAF_ID = 'humaniq-hours'`, `REFERENCE_TYPE = 'hours'`, render mode `mount`,
and `SURFACES` including `detail-page`. `src/integrations/registerHoursLeaf.js`
declares the same id, the same surfaces and the same render mode.
`CnHoursWidget.vue` takes the host object's register, schema and uuid, composes
`${register}:${schema}` into a `domainObjectType`, filters `TimeEntry` on that
and on `domainObjectRef`, and offers a log-hours link and a timer.
`scripts/check-integration-parity.js` keeps the two halves honest and gate 24
goes green.

The client half is compiled into `humaniq-main.js` and nothing else.
OpenRegister's `LeafScriptListener` enqueues a providing app's leaves onto a
consuming page from a dedicated `js/<app>-leaves.js` bundle, and it skips an
app that ships none, silently, because enqueuing a script that does not exist
would 404 the consuming page. `webpack.config.js` declares exactly one entry,
`main`. So the descriptor reaches capability discovery, `getLeaves()` returns
it, both parity checks pass, and the surface renders nothing.

OpenRegister's own listener says so in its docblock: "`humaniq-hours` has been
dark on dossiq case pages the whole time."

That is the whole defect, and it is one webpack entry.

The cost of leaving it is visible in dossiq. Because the leaf cannot render,
dossiq hand-rolled two compositions against humaniq's internals: a
`case-kpis-hours` stats block that reads register `humaniq`, schema
`TimeEntry`, summing `hours` filtered on `domainObjectType: "dossiq:case"`,
and a `log-hours` header action posting into the same schema. Both hard-code
another app's register slug and schema name. The stats block's predecessor
summed shillinq's `UrenRegistratie` on `subjectApp`, which nothing in the fleet
has ever written, so it reported 0 hours on every case in every install and
looked correct doing it.

## What Changes

- Humaniq ships a `leaves` webpack entry that builds `js/humaniq-leaves.js`
  from a small `src/leaves.js`, carrying the leaf registration and its widget
  and nothing else. Planninq already ships exactly this pattern and it is the
  file to copy.
- A test asserts that every leaf humaniq registers server-side is reachable
  from the leaves bundle, so a leaf can never again be registered without a
  way to render.
- The leaf's host contract is stated: what a consuming page passes, and what
  the widget does when the host passes nothing.
- Demo data seeds `TimeEntry` rows whose `domainObjectType` and
  `domainObjectRef` point at a real host object, so the leaf can be seen
  working. Today's mock rows carry `"Voorbeeld Domainobjectref 1"` and
  `hours: 0.0`, which match no host object anywhere.
- An end-to-end journey mounts the leaf on a host detail page, books an hour
  from it, and reads the total back.

## Non-goals

- Editing dossiq's manifest. Retiring the `case-kpis-hours` stats block and
  the `log-hours` action for a leaf mount is dossiq's change, in dossiq's repo.
  This change makes that possible, it does not do it.
- The index, the uuid format and the type pattern on `domainObjectType` and
  `domainObjectRef`. `time-entry-domain-object-ref` owns those and has them
  open.
- Retiring shillinq's `UrenRegistratie.subjectApp`. `hours-to-humaniq` in the
  shillinq repo owns that.
- Any change to the `TimeEntry` schema. Both fields already ship, deep-merged
  from `register.d/hr-cost-rate.json`.

## Capabilities

### New Capabilities

- `hours-leaf-delivery`: the leaf ships the client bundle a consuming page loads it
  from, a stated host contract, and demo data that lets it be seen working.

## Impact

- **`webpack.config.js`** gains a second entry.
- **`src/leaves.js`** is new, small, and carries no router, no store and no
  app shell.
- **`src/integrations/registerHoursLeaf.js`** and
  **`src/integrations/CnHoursWidget.vue`** are unchanged in behaviour and move
  onto the new entry's import graph.
- **`lib/Settings/humaniq_mock_register.json`** gains usable seed rows.
- **`tests/`** gains the bundle-reachability test and the end-to-end journey.
- Consumers: dossiq can mount `humaniq-hours` on the case detail page and
  delete two bespoke compositions that reach into humaniq's register. Shillinq
  already reads `TimeEntry.domainObjectRef` in `SubjectCostService`, so its
  cost-of-a-case query starts returning rows for the same reason.

## Matrix rows

Rows: `plt-hours-leaf`, `tim-log-against-object` (humaniq `openspec/parity/capabilities.json`).
