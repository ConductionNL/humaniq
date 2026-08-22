# humaniq-i18n-form-surface Specification

## ADDED Requirements

### Requirement: Every schema-derived display string is a catalogue key in both locales

Every schema `title`, every property `title`, and every value of a property's `x-enum-labels` in
`lib/Settings/register.d/*.json` SHALL be a key in BOTH `l10n/en.json` and `l10n/nl.json`, and
SHALL be an English source string rather than a Dutch literal (ADR-007).

Property `description` is explicitly outside this requirement — see the proposal's Non-Goals.

**Feature tier**: MVP

#### Scenario: A Dutch session reads a Dutch form

- GIVEN a user session with locale `nl`
- WHEN they open the create form for a Time entry
- THEN the dialog heading MUST read the `nl.json` translation of the schema title
- AND every field label MUST read the `nl.json` translation of its property title
- AND no field label MUST render its English source string

#### Scenario: An English session reads an English form

- GIVEN a user session with locale `en`
- WHEN they open the same create form
- THEN every field label MUST render its English source string
- AND no label MUST render a Dutch literal

#### Scenario: A schema string with no catalogue key fails the build

- GIVEN a property title that is absent from `l10n/en.json` or `l10n/nl.json`
- WHEN `npm run check:l10n` runs
- THEN it MUST exit non-zero
- AND MUST name the schema file and the property the string came from

#### Scenario: A Dutch literal used as a schema source key fails the build

- GIVEN a schema title or property title written in Dutch
- WHEN `npm run check:l10n` runs
- THEN it MUST exit non-zero
- AND MUST say that the schema title IS the translation key

### Requirement: Enum values render as translated labels while the stored value is unchanged

Every property carrying an `enum` SHALL declare `x-enum-labels` mapping each stored value to its
English display label, covering every value of that enum. Consumer surfaces SHALL render the
translated label and SHALL store, sort and filter on the raw value.

**Feature tier**: MVP

#### Scenario: A Dutch session reads a Dutch status option

- GIVEN a user session with locale `nl`
- WHEN they open the Status dropdown of a Timesheet
- THEN the options MUST read the Dutch translations of the declared labels
- AND MUST NOT read the raw stored codes

#### Scenario: An English session never reads a Dutch stored code

- GIVEN a user session with locale `en`
- AND a schema whose enum values are Dutch codes such as `ingediend`
- WHEN they open that dropdown
- THEN the options MUST read the English labels declared in `x-enum-labels`
- AND MUST NOT read `ingediend`

#### Scenario: Selecting a translated option stores the raw value

- GIVEN a form with a translated enum dropdown
- WHEN the user picks an option and saves
- THEN the persisted value MUST be the raw schema code, not the displayed label

#### Scenario: An enum with a missing label fails the build

- GIVEN an enum-bearing property whose `x-enum-labels` omits one of its values
- WHEN the schema surface is validated
- THEN the omission MUST be reported against that file and property

### Requirement: The browser locale catalogue is generated from the server one

`l10n/<locale>.js` SHALL be generated from `l10n/<locale>.json` by `npm run l10n:build`, and CI
SHALL fail when a committed `.js` does not match its `.json`.

Nextcloud reads the JSON catalogue server-side for `$l->t()` and serves ONLY the
`OC.L10N.register()` JS file to a browser, so a key present in one and absent from the other
renders English in the UI while every server-rendered string is translated, with no error.

**Feature tier**: MVP

#### Scenario: A stale browser catalogue fails the build

- GIVEN a key added to `l10n/nl.json` without regenerating `l10n/nl.js`
- WHEN `npm run check:l10n-js` runs
- THEN it MUST exit non-zero
- AND MUST name the stale file and the command that regenerates it

#### Scenario: The generated catalogue registers under the current app id

- GIVEN `appinfo/info.xml` declares the app id
- WHEN `npm run l10n:build` runs
- THEN each generated `l10n/<locale>.js` MUST call `OC.L10N.register` with that id
- AND MUST NOT carry a previous app id
