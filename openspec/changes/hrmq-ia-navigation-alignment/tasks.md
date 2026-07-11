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
