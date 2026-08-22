## 1. Convert manifest text to English source keys (code)

- [ ] 1.1 In `src/manifest.json`, replace Dutch menu `label` values with English sentence-case
      equivalents: `"Uren"` → `"Hours"`, `"Urenregistratie"` → `"Timesheets"`,
      `"Urengoedkeuring"` → `"Timesheet approval"`, `"Onkosten"` → `"Expenses"`,
      `"Declaraties"` → `"Expenses"` (page-level, disambiguate from the group label if identical),
      `"Declaratiegoedkeuring"` → `"Expense approval"` (`src/manifest.json:14,20,26,34,40,46`).
- [ ] 1.2 Replace page `title` values with the matching English key (`"Urenstaat"` →
      `"Timesheet"`, `"Declaratie"` → `"Expense"`, etc.) and `config.description` values with
      English sentence-case text (`"Ingediende urenstaten die wachten op goedkeuring of
      afkeuring."` → `"Submitted timesheets awaiting approval or rejection."`, and the expense
      equivalent).
- [ ] 1.3 Confirm the existing English widget-content strings (`"Total hours"`, `"Claimed
      amount"`, `"History"` at `src/manifest.json:92,107,148,163`) are left as-is — they are
      already correct English source keys.

## 2. Ship real locale catalogues

- [ ] 2.1 Create `l10n/en.json` — identity-mapped (`"translations": { "Timesheets": "Timesheets",
      ... }`) covering every English key introduced/kept in step 1, plus any other user-visible
      string in `src/App.vue` / `src/main.js` (e.g. `'User settings'`/`'Walkthrough'` defaults are
      library-provided via `registerTranslations()` and out of scope — only humaniq's own strings).
- [ ] 2.2 Create `l10n/nl.json` with the Dutch translation for every key in `en.json` — same key
      set, zero gaps, per ADR-007.
- [ ] 2.3 Confirm the Nextcloud build/packaging step serves `l10n/*.json` at
      `/custom_apps/humaniq/l10n/<locale>.json` with no additional `appinfo/info.xml` wiring needed
      (standard Nextcloud convention — cross-check against a sibling app that already has this
      working, e.g. procest).

## 3. Verify

- [ ] 3.1 Load the app with an `nl_NL` session locale — confirm `CnAppNav` renders the Dutch menu
      labels from `l10n/nl.json` (not the raw manifest string, since the manifest now holds
      English keys).
- [ ] 3.2 Load the app with an `en_US` session locale (or no `l10n/en.json` override, since it's
      identity-mapped) — confirm menu labels render in English, not Dutch.
- [ ] 3.3 Confirm `loadTranslations('humaniq', ...)` in `src/main.js` no longer 404s (verify via
      browser network tab or server access log) for at least one non-default locale.
- [ ] 3.4 Diff `l10n/en.json` and `l10n/nl.json` key sets — confirm they are identical (zero gaps),
      per ADR-007.
