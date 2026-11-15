---
kind: config
---

## Why

dossiq's case page has an hours widget. It filters humaniq's `TimeEntry` on
`domainObjectType` and `domainObjectRef`, the two properties ADR-107 names
for "hours logged on a case". The shipped register does not declare them.
Only the mock register does, so on a real install the filter matches nothing
and the widget shows `0` (dossiq competitor analysis, finding B25, triage
#10, `concurrentie-analyse/procest/_round2/findings.md`).

`hours-leaf-for-any-object` fixes the rendering half: the `humaniq-hours`
leaf reads the entries and a consumer places the leaf. It assumes the two
properties exist. This change makes that assumption true in the register
humaniq ships, and adds the test that keeps the mock and the shipped register
from drifting apart again. Decision D11 asks humaniq to write this now.

## What changes

- `TimeEntry` in `lib/Settings/humaniq_register.json` (or its `register.d`
  fragment) declares `domainObjectType` (the `<app>:<schema>` literal) and
  `domainObjectRef` (the object uuid), both optional, both indexed for
  filtering.
- The booking form and the timer write both when opened from the leaf, and
  show them read-only on an entry that has them.
- A unit test asserts every property the mock register declares on
  `TimeEntry` exists in the shipped one.

## Out of scope

- The leaf itself (`hours-leaf-for-any-object`).
- A back-reference from the case to the entry. The filter is the link.

## Matrix rows

Rows: `tim-log-against-object` (humaniq `openspec/parity/capabilities.json`).
