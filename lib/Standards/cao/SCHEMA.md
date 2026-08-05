# CAO corpus schema

A CAO (collectieve arbeidsovereenkomst) is a sector-specific ruleset that overrides
the statutory floor: minimum pay scales, standard allowances, leave entitlement and
working-time norms. CAOs are universal facts about a sector — identical for every
tenant that follows that CAO, changing only with the CAO-tekst — so they live here
in code as versioned static data, one JSON file per CAO in this directory, exactly
like the tax-year table corpus in `../tables/` (see `../tables/SCHEMA.md`) and the
rule corpus in `../rules/` (see `../rules/SCHEMA.md`). **Not** OpenRegister, which
is for per-tenant config only. Loaded and merged by `CaoRegistry`. Bump
`CaoRegistry::VERSION` on any change to a `cao/*.json` file.

## File naming

One file per CAO, named `{cao-id}.json` (lowercase, hyphenated slug — the CAO's own
`id`, e.g. `cao-metaal-techniek.json`). Each CAO carries its own `version` /
`effectiveDate` and can be revised independently of every other CAO file.

## Top-level shape

```jsonc
{
  "id": "cao-metaal-techniek",
  "name": "CAO Metaal & Techniek",
  "sector": "Metaal en Techniek",
  "version": "2026-1",
  "effectiveDate": "2026-01-01",
  "basedOn": [
    {"doc": "<human citation>", "url": "<authoritative URL>"}
  ],
  "payScales": { /* leaf, see below — value: { schaal: minimum maandloon in integer cents } */ },
  "allowances": { /* leaf, see below — value: { allowanceKey: {...} } */ },
  "leaveEntitlement": { /* leaf, see below — value: { vakantiedagenWettelijk, vakantiedagenBovenwettelijk } */ },
  "workingTime": { /* leaf, see below — value: { fulltimeHoursPerWeek } */ },
  "overtime": { /* leaf, see below — value: { toeslagPercentages: {...}, compensationPreference } */ }
}
```

| key                | required | meaning                                                                |
| ------------------ | -------- | ----------------------------------------------------------------------|
| `id`                | yes      | stable slug, matches the filename (e.g. `cao-metaal-techniek`)        |
| `name`              | yes      | human CAO name (e.g. "CAO Metaal & Techniek")                         |
| `sector`            | yes      | the sector/branch the CAO covers                                      |
| `version`           | yes      | the CAO revision this file captures (e.g. "2026-1")                   |
| `effectiveDate`     | yes      | ISO date the revision took effect                                     |
| `basedOn`           | no       | list of `{doc, url}` primary source citations                          |
| `payScales`         | yes      | leaf — `value` is `{ schaal: minimum maandloon in integer CENTS }`    |
| `allowances`        | yes      | leaf — `value` is `{ allowanceKey: {...} }` (e.g. ploegentoeslag)     |
| `leaveEntitlement`  | yes      | leaf — `value` is `{ vakantiedagenWettelijk, vakantiedagenBovenwettelijk }` (full-time day counts) |
| `workingTime`       | yes      | leaf — `value` is `{ fulltimeHoursPerWeek }` (informational/display only — not read by any resolver) |
| `overtime`          | no       | leaf — `value` is `{ toeslagPercentages: { doordeweeks, zaterdag, zondag, feestdag }, compensationPreference }` |

### `overtime`

`toeslagPercentages` are the **surcharge**, not the total: `50` means 150% of the
normal hourly wage is paid for that hour. Both readings appear in CAO texts and
they differ by the whole base wage, so the unit is stated here rather than
inferred.

The key is **optional**, unlike the other four. A CAO whose overtime article has
not been transcribed simply omits it and
`CaoRegistry::overtimeToeslagPercentages()` resolves to `null` — the same
outcome as an unverified leaf. That keeps adding a CAO cheap and keeps a missing
overtime rule from failing the whole file to load.

Employment terms resolve **CAO first, contract override second**: a contract may
carry its own `overtimeToeslagPercentages`, which wins in full (it is not merged
per-category — a partial merge would silently mix two documents' terms). See
`EmploymentTermsResolver`.

## Leaf shape

Every one of the four value groups above is a leaf object, never a bare scalar/map
— identical discipline to `../tables/SCHEMA.md`:

```jsonc
{"value": <object>, "source": "<citation>", "verified": true|false}
```

Optional keys on a leaf:

| key            | meaning                                                                                          |
| -------------- | -------------------------------------------------------------------------------------------------|
| `placeholder`  | `true` when the figure is a stand-in, not yet confirmed against the official CAO-tekst            |
| `checkAgainst` | required when `placeholder: true` or `verified: false` — names the official document to confirm  |
| `note`         | free-text clarification                                                                           |

`verified: false` is only acceptable together with a `checkAgainst` note — an
unconfirmed value must never be silent.

## The verified → enforce / placeholder → vacuous lever

`CaoRegistry::minMaandloonCents()` and `CaoRegistry::minLeaveHours()` resolve to
`null` — never to a wrong number — whenever the underlying leaf (the WHOLE
`payScales` or `leaveEntitlement` leaf, not a per-scale sub-value) is
`verified: false` or `placeholder: true`. `NlCaoChecks` treats a `null` resolution
as a vacuous pass. Because `RuleEngine` attaches exactly one static severity per
catalogue rule id, this is the only lever available to keep an unconfirmed CAO
figure from ever raising a false *mandatory* violation — see design.md D5 of the
`cao-library` change.

## Units

`payScales` amounts are integer **cents** (unlike `../tables/*.json`, which stores
euros and converts at load time — CAO scales are stored cents-exact directly, since
they are not tijdvak-factored the way tax-table amounts are).

## Annual / CAO-revision re-issue discipline

A new CAO revision (a new loontabel, updated leave entitlement, ...) is a
**data-only** change: add/replace `{cao-id}.json` with sourced, verified values and
bump `CaoRegistry::VERSION` — no PHP changes. `NlCaoChecks` carries no CAO-specific
logic of its own; it reads whichever CAO a contract/employee resolves to via
`CaoRegistry`.

## Adding a new CAO

Adding a new sector CAO is also data-only: drop a new `{cao-id}.json` file
(minimally with `verified: false` + `placeholder: true` + `checkAgainst` leaves —
the below-CAO checks stay vacuous for that CAO until a maintainer confirms the
figures) and bump `CaoRegistry::VERSION`. The CAO immediately appears in
`CaoRegistry::availableCaos()` and, once re-seeded, on the read-only `Caos`
reference page — no code change required.
