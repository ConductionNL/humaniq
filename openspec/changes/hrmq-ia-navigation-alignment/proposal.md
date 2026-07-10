---
kind: config
---

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
