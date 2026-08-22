---
kind: config
---

## RETIRED UNAPPLIED — archived 2026-08-22, superseded

**This change was archived without being applied, and its spec deltas were deliberately NOT
synced into `openspec/specs/`.** Nothing below describes shipped behaviour; read it as a
historical proposal only. Its tasks stay unchecked on purpose — ticking them would claim work
this change never did.

Two independent reasons, both settled after this proposal was written:

1. **Its step 1 is already done, by another change.** Sections 1.1-1.5 propose adopting the
   ADR-037/ADR-044 `manifest.d/` + `menu-layout.json` + `buildManifest()` pipeline as the
   prerequisite for everything else here. That adoption shipped in
   [`hrmq-manifest-fragment-pipeline`](../2026-08-22-hrmq-manifest-fragment-pipeline/) (merged as
   humaniq#122), whose proposal names this change explicitly as the one it supersedes on that
   point. Re-running the prerequisite would be a no-op at best.
2. **Its relocation content targets a menu structure that no longer stands.** Every placement
   argument below is made against `adr-001-information-architecture.md`'s frozen nine top-level
   entries. That structure has since been superseded by hydra **ADR-097** (navigation budget:
   six counted main-menu entries, with a conditional personal-surface exemption). hrmq's menu was
   brought to the ADR-097 budget by humaniq#114 — 11 top-level entries down to 5 counted — and the
   personal surface was made exemption-eligible by
   [`hrmq-personal-dashboard`](../2026-08-22-hrmq-personal-dashboard/). Applying this change's
   `hrmq-ia-navigation` delta now would publish a capability spec asserting the ADR-001 nine-entry
   contract as current, which is no longer true.

The three delta specs under `specs/` are kept verbatim as the record of what was proposed. The
`hrmq-ia-navigation` capability was never published to `openspec/specs/` and does not exist.

## Why

hrmq's own accepted architecture record, `openspec/architecture/adr-001-information-architecture.md`,
freezes the top-level navigation at exactly 9 entries — Dashboard, Mijn HR, Medewerkers, Salarissen,
**Verlof & verzuim** (which explicitly includes "tijdregistratie"), Onboarding & ATS,
**Declaraties & assets**, Aangiftes & compliance, and a Configuratie drawer — and states "New specs
MUST map their UI surface onto an existing placement under this IA. Adding a new top-level menu
requires an ADR amendment" (adr-001, Decision section).

The shipped `src/manifest.json` violates this directly: it declares two ad-hoc top-level menu
groups, `TimesheetsGroup` ("Uren", order 100) and `ExpensesGroup` ("Onkosten", order 110)
(`src/manifest.json:11-51`), neither of which is one of the frozen 9 and neither of which went
through an ADR amendment. Per ADR-001's own placement guidance, time registration belongs under
**Verlof & verzuim** ("rooster, tijdregistratie" is explicitly listed as in-scope for that menu)
and expense claims belong under **Declaraties & assets** ("declaraties, assets, WKR-overzicht").
The two archived changes that shipped these pages (`hrmq-timesheet-approval`,
`hrmq-expenses`, both 2026-06-22) predate ADR-001 having been checked against — the existing spec
text even says the expense pages are "reached from an 'Onkosten' menu group"
(`openspec/specs/hrmq-expenses/spec.md:72`), baking the violation into the spec itself.

Separately, hrmq's `src/manifest.json` is a single monolithic file with no `src/manifest.d/`
fragment pipeline and no `src/menu-layout.json` — confirmed by `find src -type f` (only
`App.vue`, `assets/app.css`, `icons.js`, `main.js`, `manifest.json`, `pinia.js`, `registry.js` —
no `manifest.d/` directory) and by `src/main.js` building routes directly from
`bundledManifest.pages` with no `buildManifest()` call (contrast with pipelinq/procest, both of
which carry `src/manifest.d/*.json` + `src/menu-layout.json` and call the shared
`@conduction/nextcloud-vue` `buildManifest()` pipeline). ADR-044 §Rule 6 requires exactly this
fragment pipeline as the prerequisite before an app can use `relocations`/`settingsSection` to
correctly re-home menu entries under an existing top-level group without losing their routes
(the ADR-044 §5 "no-functionality-loss invariant"). hrmq needs that relocation mechanism to fix
the ADR-001 violation safely, so both gaps are fixed together here.

## What Changes

- Adopt the ADR-037/ADR-044 fragment pipeline as the prerequisite: introduce
  `src/manifest.d/` (even if it holds a single fragment file for now, mirroring the existing
  `lib/Settings/register.d/` backend pattern hrmq already uses) and a `src/menu-layout.json`;
  switch `src/main.js` to build the effective manifest via `@conduction/nextcloud-vue`'s shared
  `buildManifest(base, fragments, menuLayout)` instead of consuming `bundledManifest` directly.
- Relocate `Timesheets` + `TimesheetApproval` under the existing **Verlof & verzuim** top-level
  group and `Expenses` + `ExpenseApproval` under **Declaraties & assets**, per ADR-001's placement
  rules — via `menu-layout.json` `relocations`, not a manifest rewrite, so no route changes.
- Retire the now-redundant `TimesheetsGroup` ("Uren") and `ExpensesGroup` ("Onkosten") top-level
  group entries once their children are relocated. **BREAKING**: bookmarked/deep-linked menu
  entries under the old group ids change parent; page routes themselves (`/timesheets`,
  `/expenses`, etc.) are UNCHANGED per the ADR-044 no-functionality-loss invariant.
- Update the `hrmq-expenses` and `hrmq-timesheet-approval` specs' menu-group wording to match the
  corrected placement.
- No change to page content, widgets, lifecycle, or schemas — this is menu/IA structure only.

## Capabilities

### New Capabilities
- `hrmq-ia-navigation`: hrmq's menu structure conforms to its own frozen ADR-001 top-level
  navigation via the shared ADR-044 fragment + `buildManifest` pipeline.

### Modified Capabilities
- `hrmq-expenses`: menu-group placement corrected from a standalone "Onkosten" top-level group to
  nesting under the frozen **Declaraties & assets** top-level menu.
- `hrmq-timesheet-approval`: menu-group placement corrected from a standalone "Uren" top-level
  group to nesting under the frozen **Verlof & verzuim** top-level menu.

## Impact

- **`src/manifest.json`** — `menu[]` restructured; `TimesheetsGroup`/`ExpensesGroup` retired as
  top-level entries (children preserved).
- **`src/manifest.d/`** (new) — fragment pipeline scaffold.
- **`src/menu-layout.json`** (new) — `relocations` mapping the four leaf entries onto the two
  frozen top-level groups (`VerlofVerzuim`, `DeclaratiesAssets` — created as the frozen-IA
  top-level entries if not already present in the manifest, since hrmq currently has no menu
  entries at all for the other 7 frozen groups).
- **`src/main.js`** — switch to `buildManifest()`.
- **`openspec/specs/hrmq-expenses/spec.md`, `openspec/specs/hrmq-timesheet-approval/spec.md`** —
  wording correction on menu-group placement (delta in this change).
- No PHP, route, or schema changes.
