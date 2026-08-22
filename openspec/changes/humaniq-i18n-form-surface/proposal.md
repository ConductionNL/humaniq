---
kind: code
---

# i18n form surface — the strings a form puts on screen come from the schema, and none of them were translated

## Why

**Verified against HEAD 2026-08-22.** `hrmq-i18n-locale-completeness` (merged as humaniq#140)
brought the **manifest** surface onto ADR-007: menu labels, page titles and action labels are
English source keys with real `l10n/en.json` + `l10n/nl.json` catalogues. It stopped there, and
the stopping point is visible to any user who opens a form.

Every string *inside* a form comes from the OpenRegister schema, not from the manifest. So a
Dutch session today reads a Dutch menu, opens a page with a Dutch title, and finds this:

```
Edit Time entry                 ← schema.title, untranslated
  Start time  ▸  End time       ← property titles, untranslated
  Status: ingediend  ▾          ← enum value, rendered raw
[ Add Time entry ]              ← 'Add ' + schema.title, untranslatable by construction
```

Three separate defects sit under that screenshot.

**1. The catalogue simply had no entries for the schema surface.** `fieldsFromSchema()` runs a
property `title` and `description` through the injected `cnTranslate`, which `CnAppRoot` binds to
*this app's* id — so a schema title has always been a key in *this* catalogue. It had none:
465 keys covered the manifest and `t('humaniq', …)` call sites and nothing else, against 35
schema titles and 424 property titles that render on screen.

**2. Enum values are contract values, and a good few of them are Dutch.** `ingediend`,
`afgekeurd`, `kantoor`, `thuis`, `gepubliceerd`. These are stored values — a lifecycle machine
transitions between them and a facet query filters on them — so they cannot simply be renamed to
English, and they are not display text. That leaves **no English source key** for a catalogue to
be built on, and it means an **English** session reads the Dutch code today. Neither locale is
served correctly, and no amount of catalogue work fixes it, because the string that reaches the
screen is the stored value itself.

**3. 90 display strings were Dutch literals used as source keys.** 85 property titles (`Naam`,
`Toelichting`, `Beslagvrije voet`, `Wachtdag`, `Uitkomst`, …) and 5 schema titles (`Stagiair`,
`Loonbeslag`, `Normfunctie`, `CAO`, `Jaaropgaaf`). ADR-007 forbids this, for exactly the reason on
display here: `en.json` is identity-mapped, so **a Dutch key renders Dutch to an English user** —
the same class of defect `hrmq-i18n-locale-completeness` closed for the manifest, surviving one
layer down. Three more were not any human language at all: `DsrRequest`, `JurisdictionPack` and
`ReceiptExtraction` were class names rendered verbatim as the heading of a create/edit dialog.

**Why the guard did not catch any of it.** `tests/validate-l10n-parity.js` carried an explicit
exemption:

> Schema `title`/`description` in lib/Settings/register.d/*.json. Those are already English and
> are the canonical source for form field labels; they are rendered by OpenRegister, not by
> hrmq's manifest renderer.

Both halves of that sentence are wrong. They are *not* already English (90 were Dutch), and they
are *not* rendered by OpenRegister — they are rendered by nc-vue's `fieldsFromSchema()` against
**this app's** catalogue. A guard's exemption comment is a claim about the system, and this one
had gone stale without anyone re-reading it.

## What Changes

- **`x-enum-labels` on every enum-bearing property** (78 of them, 210 values). The property
  declares the English display label for each stored code; the code never moves. This is what
  gives the catalogue an English source key to translate, and it fixes the English session too.
  Consumed by `CnFormDialog` (dropdown options), `CnCellRenderer` (status badge) and
  `filtersFromSchema()` (facet options) — ConductionNL/nextcloud-vue#741.
- **90 Dutch source keys become English**, with the Dutch moving to `l10n/nl.json`. Statutory
  proper nouns stay untranslated in both catalogues, consistent with how the French and German
  jurisdiction packs are already handled (`Lohnsteuer`, `Steuerklasse`, `prélèvement à la
  source`, `ABP`, `BSN`, `WNT`).
- **Three class-name schema titles become English.** `title` only — `slug` is the identifier,
  and every schema reference in `humaniq_register.json` and in the seed objects is by slug.
- **643 catalogue keys added** to both locales: 35 schema titles, 424 property titles, 184 enum
  labels. Identical key sets, `en` identity-mapped.
- **The parity guard learns the schema surface.** `check:l10n` now walks
  `lib/Settings/register.d/*.json` and requires every schema title, property title and
  `x-enum-labels` value to be a key in both catalogues and to carry no Dutch literal — and its
  stale exemption comment is replaced with the reason it was wrong.
- **`l10n/<locale>.js` becomes generated, not hand-maintained.** `npm run l10n:build`, enforced
  by `check:l10n-js` in CI.

## Non-Goals

- **Property `description`** — the helper text under a field, ~730 strings. They are still
  written for a developer reading the schema rather than for the person filling in the form
  ("Governed by the x-openregister-lifecycle state machine (concept -> gepubliceerd via
  publiceren)"), and rewriting them is the copy pass of the forms-as-process programme.
  Translating ~730 strings that are already slated to be rewritten is work thrown away. The guard
  documents the exclusion and the reason rather than silently skipping it.
- **Renaming enum values to English.** They are stored values referenced by lifecycle machines,
  facet queries and seed data; `x-enum-labels` gives them English display without a data
  migration.
- **Any third locale.** ADR-007 is en + nl.
