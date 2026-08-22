---
kind: code
---

## Why

Humaniq ships **zero** locale files and uses Dutch literal text as its i18n keys, in direct violation
of hydra ADR-007 (§"Required Languages": "`l10n/en.json` and `l10n/nl.json` MUST exist in every app
with a UI") and ADR-007's explicit consequence: "Dutch strings used as translation keys ... are a
violation — the English equivalent must be the key."

Verified at HEAD:

- `find . -iname l10n` / `find . -iname "*.po*"` return nothing — there is no `l10n/` directory
  anywhere in the repo, unlike every sibling app (e.g. `procest/l10n/en.json`,
  `procest/l10n/nl.json` plus dozens of other locales).
- `src/manifest.json` hardcodes menu/page text in **Dutch, not English**: menu labels
  `"Uren"` / `"Urenregistratie"` / `"Urengoedkeuring"` / `"Onkosten"` / `"Declaraties"` /
  `"Declaratiegoedkeuring"` (`src/manifest.json:14,20,26,34,40,46`), and page `title`/
  `config.description` fields in Dutch (`src/manifest.json`: `"Urenregistratie"`, `"Urengoedkeuring"`,
  `"Ingediende urenstaten die wachten op goedkeuring of afkeuring."`, etc.) — while the same
  manifest's detail-page widget content uses **English** (`"Total hours"`, `"Claimed amount"`,
  `"History"` at `src/manifest.json:92,107,148,163`). The manifest is internally inconsistent
  between two languages with no translation layer resolving either.
- `@conduction/nextcloud-vue`'s `CnAppNav` renders every menu entry via
  `this.effectiveTranslate(item.label)` (`nextcloud-vue/src/components/CnAppNav/CnAppNav.vue:688`) —
  i.e. the Dutch literal string in `item.label` is passed as the **translation key** to
  `translate('humaniq', key)` (`src/App.vue:51`, `main.js` wiring). With no `humaniq` l10n catalogue
  loaded, `@nextcloud/l10n` returns the key itself: Dutch text is shown to every locale, English
  included, and if an `humaniq` Dutch catalogue were ever added with a *different* Dutch phrasing, the
  nav item would silently show two different Dutch strings depending on locale — the literal
  "translation key" and its "translation" would both be Dutch.
- `src/main.js`'s `tryLoadTranslations()` (`src/main.js:47-56`) unconditionally calls
  `loadTranslations('humaniq', ...)` on every app boot. Since no `l10n/<locale>.json` file is ever
  built or shipped, this network request 404s on every single page load in every locale — a
  wasted request the code's own comment (`src/main.js:43-46`) anticipates ("Some Nextcloud installs
  only allow the JS/CSS allowlist... 404s in those environments") without noting it 404s in *every*
  environment today, because there is nothing to serve.
- `appinfo/info.xml` declares both `<name lang="en">` and `<name lang="nl">` plus bilingual
  `<summary>`/`<description>` (store metadata is manually bilingual), but the in-app UI itself has
  no translation mechanism at all — the Nextcloud App Store listing promises English support the
  running app does not deliver.

## What Changes

- Convert `src/manifest.json`'s menu/page `label`/`title`/`config.description` strings from Dutch
  literals to **English source keys** (sentence case per ADR-007), matching the
  procest/pipelinq convention where manifest text IS the translation key.
- Ship `l10n/en.json` (identity-mapped source file, key === value) and `l10n/nl.json` (Dutch
  translations for the same key set) covering every string introduced above, per ADR-007's
  "MUST exist in every app with a UI" / "MUST contain exactly the same keys, with zero gaps."
- Wire the build so `l10n/*.json` is actually served (Nextcloud's standard
  `/custom_apps/humaniq/l10n/<locale>.json` static-file convention — no new backend code required,
  only the files themselves plus confirming `appinfo/info.xml` doesn't need an explicit
  declaration for this, matching sibling apps).
- Leave `tryLoadTranslations()`'s fire-and-forget/non-fatal design in `src/main.js` unchanged
  (it is the correct resilience pattern per its own comment) — the fix is that the fetch it makes
  now resolves to real content instead of always 404ing.

## Capabilities

### New Capabilities
- `humaniq-i18n-locale-completeness`: Humaniq ships real `en`/`nl` locale catalogues and its manifest
  text uses English source keys, matching ADR-007 and the rest of the fleet.

## Impact

- **`src/manifest.json`** — `label`/`title`/`config.description` strings converted to English
  source keys (no structural/schema change, no route/id changes).
- **`l10n/en.json`**, **`l10n/nl.json`** (new).
- **`src/main.js`** — no code change; `tryLoadTranslations()` now has a real payload to fetch.
- Coordinate with the in-flight `humaniq-ia-navigation-alignment` change, which also touches
  `src/main.js` (switching `bundledManifest` consumption to `buildManifest()`) and relocates menu
  entries via `menu-layout.json` — this change only touches string *values*, not menu structure or
  IDs, so it should not conflict, but land after or rebase against whichever merges first to avoid
  a manifest-fragment merge fight over the same `label` keys.
