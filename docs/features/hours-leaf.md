---
description: What a page in another app passes to show humaniq's hours card, and what the card does without it.
---

# The hours card on another app's page

Humaniq offers its hours card to other apps as an OpenRegister leaf with the id
`humaniq-hours`. A case page in dossiq, for example, can show the hours booked
on that case and let a caseworker book more, without reading humaniq's
register itself.

## How the card reaches the page

Humaniq builds the card into its own small bundle, `js/humaniq-leaves.js`
(from `src/leaves.js`). OpenRegister's leaf script listener adds that bundle
to the pages of apps that show leaves. The bundle holds the leaf
registrations and nothing else, so another app's page does not load
humaniq's whole interface.

The parity check (`node scripts/check-integration-parity.js`, run by
`tests/js/integrationParityLeavesBundle.test.mjs`) fails when a leaf humaniq
registers on the server is not reachable from `src/leaves.js`. A leaf that
only humaniq's own pages can render cannot slip through.

## What the host page passes

The leaf uses OpenRegister's `mount` render mode: the host gives it an empty
element and these props.

| Prop | What it is | Example |
|---|---|---|
| `register` | The register slug of the object the card sits on | `dossiq` |
| `schema` | The schema slug of that object | `case` |
| `objectId` | The uuid of that object | `7c1e2b9a-…` |

From the first two the card makes the object's type, `register:schema`
(`dossiq:case`). Hours booked from the card are stored on a humaniq
`TimeEntry` with that `domainObjectType` and the uuid as `domainObjectRef`.
The card shows the time entries that carry both.

The host may also pass `surface`, `title`, `objectNoun` and `compactHeader`
to fit the card to its page. Without them the card uses its own defaults.

## When the host passes less

If any of the three is missing, there is no object to read or book against.
The card then says so: "This card cannot show hours: the page did not say
which item it is on." It shows no total, no timer and no booking controls.
It never shows 0 hours in this case, because to a caseworker "no hours yet"
and "this card does not know where it is" look the same, and only one of
them is true.
