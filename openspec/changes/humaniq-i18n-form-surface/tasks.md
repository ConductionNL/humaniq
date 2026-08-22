# Tasks — humaniq-i18n-form-surface

- [x] 1. `lib/Settings/register.d/*.json` — declare `x-enum-labels` on all 78 enum-bearing
  properties, covering every one of the 210 values, per REQ-ENUM. Verified mechanically:
  0 properties without a map, 0 values without a label.
- [x] 2. `lib/Settings/register.d/*.json` — replace the 85 Dutch property titles and 5 Dutch
  schema titles with English source strings, per REQ-SCHEMA. Statutory proper nouns
  (`ABP`, `BSN`, `WNT`, `Lohnsteuer`, `Steuerklasse`, …) are left as they are, as the French and
  German pack terms already are.
- [x] 3. `lib/Settings/register.d/{hr-dsr,hr-packs,hr-expense}.json` — rename the three
  class-name schema titles (`DsrRequest`, `JurisdictionPack`, `ReceiptExtraction`). `title` only;
  `slug` is untouched, so `humaniq_register.json` and every seed `"schema": …` reference still
  resolves.
- [x] 4. `l10n/en.json` + `l10n/nl.json` — add the 643 schema-surface keys (35 schema titles,
  424 property titles, 184 enum labels). `en` identity-mapped; identical key sets.
- [x] 5. `scripts/build-l10n-js.js` + `npm run l10n:build` / `check:l10n-js` — generate
  `l10n/<locale>.js` from the JSON, registering under the `<id>` read from `appinfo/info.xml`,
  per REQ-JSCAT.
- [x] 6. `tests/validate-l10n-parity.js` — collect the schema surface and require it in both
  catalogues with no Dutch literal (check 8); replace the stale exemption comment with the reason
  it was wrong, and document the `description` exclusion. Must-fail verified.
- [x] 7. `.github/workflows/code-quality.yml` — add `check:l10n-js` to `frontend-checks`.
- [x] 8. `tests/e2e/spec-coverage/i18n-form-surface.spec.ts` — gate-19 coverage for every
  non-excluded scenario, asserted in BOTH locales against the rendered form.

## Depends on

- ConductionNL/nextcloud-vue#741 — the library reads `x-enum-labels` and routes the dialog
  heading, the Add-button label and the enum option labels through `cnTranslate`. Field labels
  and column headers already translate with the released 2.13.0, so tasks 1–7 are useful without
  it; the enum and heading scenarios go green when it ships and the app's dependency is bumped.
