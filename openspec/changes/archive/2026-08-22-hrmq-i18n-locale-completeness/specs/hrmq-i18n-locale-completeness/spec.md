## ADDED Requirements

### Requirement: Humaniq ships English-source manifest text with real locale catalogues

`src/manifest.json` menu/page text SHALL use English sentence-case strings as i18n keys, and the
app SHALL ship `l10n/en.json` + `l10n/nl.json` with an identical key set, per ADR-007.

**Feature tier**: MVP

#### Scenario: Manifest text is English, not Dutch

- GIVEN a reader inspects `src/manifest.json`'s `menu[].label` and `pages[].title` fields
- WHEN they check the source language
- THEN every value MUST be an English sentence-case string
- AND MUST NOT be a Dutch literal used as a translation key

#### Scenario: en.json and nl.json have zero key gaps

- GIVEN `l10n/en.json` and `l10n/nl.json`
- WHEN their key sets are compared
- THEN they MUST be identical
- AND neither file MUST be missing a key present in the other

### Requirement: The app-nav menu renders in the session locale

Navigation labels rendered via `CnAppNav`'s `effectiveTranslate(item.label)` SHALL resolve against
a real `humaniq` locale catalogue rather than falling back to the raw manifest string.

**Feature tier**: MVP

#### Scenario: Dutch session locale shows Dutch labels from the catalogue

- GIVEN a user session with locale `nl_NL`
- WHEN the app nav renders the `Timesheets` menu entry
- THEN the displayed label MUST come from `l10n/nl.json`'s translation of the `"Timesheets"` key
- AND MUST NOT be a hardcoded Dutch literal baked into the manifest

#### Scenario: English session locale shows English labels

- GIVEN a user session with locale `en_US`
- WHEN the app nav renders the `Timesheets` menu entry
- THEN the displayed label MUST be `"Timesheets"` (the English source key, identity-mapped in
  `l10n/en.json`)

### Requirement: The translation fetch resolves to real content

`loadTranslations('humaniq', ...)` SHALL fetch a locale file that actually exists for at least the
supported `en`/`nl` locales.

**Feature tier**: MVP

#### Scenario: The locale fetch does not 404 for a supported locale

- GIVEN a non-default supported locale (e.g. `nl`)
- WHEN `src/main.js`'s `tryLoadTranslations()` fires on app boot
- THEN the underlying `GET /custom_apps/humaniq/l10n/nl.json` request MUST succeed
- AND MUST NOT 404
