# Design: the hours leaf loads on a consuming page

## Context

OpenRegister's `LeafScriptListener` is how a leaf reaches another app's page.
It enqueues `js/<providing app>-leaves.js`, deliberately not the providing
app's main bundle, because a main bundle carries a whole SPA and putting one
on another app's page trades a feature for a performance regression. An app
that ships no such file is skipped silently, because enqueuing a script that
does not exist would 404 the consuming page.

Humaniq's `webpack.config.js` declares one entry, `main`. The deployed
`js/` directory carries `humaniq-main.js` and its chunks, and no
`humaniq-leaves.js`.

Planninq already solved this. Its `webpack.config.js` declares a second entry
`leaves` importing `src/leaves.js`, with a comment that names the exact
failure this change removes, and it ships `js/planninq-leaves.js`. That file
is the template.

Two other things are true and shape the scope. `time-entry-domain-object-ref`
already owns the index, the uuid format and the type pattern on the two
`TimeEntry` fields, so this change does not touch them. Shillinq's
`SubjectCostService` already reads `TimeEntry.domainObjectRef`, so it is a
waiting reader rather than a competing design.

## Goals / Non-Goals

**Goals:**

- `humaniq-hours` renders on the page of an app that consumes it.
- A leaf can never again be registered on one side only, or registered with no
  way to render.
- The leaf can be seen working on a demo instance.

**Non-Goals:**

- Dossiq's manifest. Mounting the leaf and retiring the two bespoke
  compositions is dossiq's change.
- The schema properties. `time-entry-domain-object-ref` owns them.
- Shillinq's `UrenRegistratie`. `hours-to-humaniq` owns it.
- Any new leaf. This ships the delivery mechanism for the leaf humaniq
  already registers.

## Decisions

**D1. Copy planninq, do not invent.** The entry name, the filename pattern and
the shape of `src/leaves.js` are a contract with OpenRegister's loader, not a
local choice. Planninq's file is in the fleet, working, and carries the
comment explaining why the entry name matters.

**D2. The bundle carries registration and widget, nothing else.** No router,
no store, no app shell. The test in REQ-HLD-002 asserts the leaf is reachable
from the bundle; the build test in REQ-HLD-001 asserts the bundle stays small.
Both are needed: a leaves bundle that quietly grows into the main bundle is
the same regression by another route.

**D3. An unresolvable host is named, never rendered as zero.** The widget
already returns a dash rather than zero when a fetch fails. The host contract
gets the same rule. This matters because the composition it replaces in dossiq
had exactly this defect: it summed a field nothing wrote, reported 0 hours on
every case in every install, and looked correct doing it.

**D4. Demo data seeds a real reference.** Today's mock rows carry
`"Voorbeeld Domainobjectref 1"` and `hours: 0.0`. They match no host filter,
so an operator who mounts the leaf sees nothing and cannot tell that from a
broken leaf. Seeded rows must point at an object the seed also creates.

**D5. The end-to-end journey books an hour, it does not only read one.** A
read-only journey passes against a leaf that renders a seeded total and cannot
write. The booking path is the half that carries `domainObjectType` and
`domainObjectRef` onto a new record, and it has never been exercised.

## Risks / Trade-offs

**A second entry is a second thing to keep small.** Webpack will happily pull
the whole component library into it through one careless import. D2's size
assertion is the guard, and it has to fail loudly rather than warn.

**The leaf has never carried a record, so the first mount will find bugs.**
The fleet has two competing designs for hours on an object and neither has
ever stored one. This change is the first that makes the humaniq side
observable, which is the point, and it means the end-to-end journey is the
real acceptance test rather than the unit tests.

**Dossiq cannot delete its bespoke compositions until this ships and it makes
its own change.** For one release both paths exist. They read the same rows,
so they agree, and the stats block is the one that gets deleted.
