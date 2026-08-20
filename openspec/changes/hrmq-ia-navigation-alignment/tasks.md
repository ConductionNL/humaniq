## Status — 2026-08-20: the OUTCOME shipped, by a shorter route than this plan

The user-visible goal of this change — a main menu inside the ADR-097 budget —
**is done and merged** (hrmq#114). It did not go through the fragment pipeline
this plan opens with, and that is worth being explicit about rather than
quietly ticking boxes that describe a different implementation.

**What shipped:** 11 top-level entries → **5 budgeted** (cap 6, target 4), by
editing `menu[]` in `src/manifest.json` directly and setting
`section: "settings"` on the configuration group. Measured no-functionality-loss:
62 navigable leaves before and 62 after, the same SET by `(id, route)`, and all
109 pages byte-identical.

**Why not the pipeline:** sections 1.1-1.5 below adopt `manifest.d/` +
`menu-layout.json` + `buildManifest()` purely so that a RELOCATION can be
expressed declaratively. That is the right long-term shape and
`hrmq-manifest-fragment-pipeline` still owns it — but it is a 109-page
restructure whose only purpose here was to move five menu entries. Doing the
restructure first would have put a large, hard-to-review diff between the brief
("its main menu, that is huge") and its answer.

**What this change still owns**, and why it stays open:

- 1.1-1.5 — the fragment pipeline adoption itself. Deferred to
  `hrmq-manifest-fragment-pipeline`, which is where it belongs; this change
  should not be the reason it happens.
- 2.1 / 2.2 — `VerlofVerzuim` exists and now owns the timesheet entries; a
  distinct `DeclaratiesAssets` group was NOT created. The existing
  `ExpensesGroup` was relabelled "Declaraties & assets" instead, because it
  already held Assets and Uitgiftes after the asset/fleet merge, so a new group
  would have been the same children under a new id.
- 3.1-3.3 — relocations done, but as direct edits rather than
  `menu-layout.json` `relocations`. The retired groups were `TimesheetsGroup`
  and `OnboardingAtsGroup`; `ExpensesGroup` survives under a new label.
- 3.4 — **done and asserted**: all four routes unchanged, part of the 62-leaf
  identity check above.
- 4.1 / 4.2 — the two spec texts still say "reached from an 'Onkosten' menu
  group" and the old timesheet placement. Genuinely outstanding.
- 5.1 / 5.2 — 5.1 done live (7 main entries render, Configuratie in the settings
  foldout, 0 console errors); 5.2 (other apps' deepLinks into these routes) not
  checked.

## 1. Adopt the ADR-037/ADR-044 fragment pipeline (prerequisite)

- [ ] 1.1 Create `src/manifest.d/` and move the current `src/manifest.json` contents into a single
      fragment file there (e.g. `src/manifest.d/00-hrmq.json`), keeping `src/manifest.json` as the
      minimal `{ "$schema": ..., "version": ..., "dependencies": [...] }` base per the shared
      pipeline's contract.
- [ ] 1.2 Add `require.context`-based fragment collection in `src/main.js` (ADR-037 — the only
      app-local step), mirroring the pattern already used for
      `lib/Settings/register.d/*.json` on the backend.
- [ ] 1.3 Create `src/menu-layout.json` with `_meta` (spdx-license/copyright + description,
      matching the pipelinq/procest convention), `relocations`, and (empty for now) `removals`.
- [ ] 1.4 Replace the direct `bundledManifest` consumption in `src/main.js` with
      `buildManifest(base, fragments, menuLayout)` from `@conduction/nextcloud-vue`.
- [ ] 1.5 `npm run build`; confirm `js/hrmq-main.js` emits with no manifest-schema validation
      errors.

## 2. Add the two frozen top-level groups from ADR-001

- [ ] 2.1 Add a `VerlofVerzuim` top-level menu entry to the fragment: label "Verlof & verzuim",
      icon `calendar-clock` (per adr-001's frozen navigation table), positioned per the app's
      existing `order` numbering convention.
- [ ] 2.2 Add a `DeclaratiesAssets` top-level menu entry: label "Declaraties & assets", icon
      `wallet` (per adr-001).

## 3. Relocate the four leaf entries via menu-layout.json

- [ ] 3.1 In `src/menu-layout.json` `relocations`, map `Timesheets` → `VerlofVerzuim` and
      `TimesheetApproval` → `VerlofVerzuim`.
- [ ] 3.2 Map `Expenses` → `DeclaratiesAssets` and `ExpenseApproval` → `DeclaratiesAssets`.
- [ ] 3.3 Retire the now-childless `TimesheetsGroup` ("Uren") and `ExpensesGroup` ("Onkosten")
      top-level group entries from the fragment (their children have moved; the groups themselves
      have no other purpose).
- [ ] 3.4 Confirm all four page routes (`/timesheets`, `/timesheets/approval`, `/expenses`,
      `/expenses/approval`) are UNCHANGED and still resolve — the ADR-044 no-functionality-loss
      invariant. Deep links (`deepLinks[]` in the manifest) are untouched by this change.

## 4. Spec + doc corrections

- [ ] 4.1 Update `openspec/specs/hrmq-expenses/spec.md`'s "Declarative expense pages" requirement
      text — replace "reached from an 'Onkosten' menu group" with the corrected placement under
      "Declaraties & assets".
- [ ] 4.2 Update `openspec/specs/hrmq-timesheet-approval/spec.md`'s menu-placement wording
      similarly for "Verlof & verzuim".

## 5. Verify

- [ ] 5.1 Manual/Playwright: open the Nextcloud app menu for hrmq, confirm exactly the ADR-001
      frozen top-level entries that hrmq currently has content for are present (no orphan "Uren"/
      "Onkosten" top-level items), and confirm all four leaf pages are reachable under their new
      parent groups.
- [ ] 5.2 Confirm the `related`/deep-link/e2e targets referencing these routes (if any exist in
      other apps' `deepLinks` config pointing at hrmq) still resolve — ADR-029 route-reachability.
