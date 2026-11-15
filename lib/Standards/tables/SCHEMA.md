# Tax-year table schema

Tax-year parameters are versioned static data — one JSON file per jurisdiction-year
in this directory. Parameters are universal facts (identical for every tenant,
changing only with annual regulation), exactly like the rule corpus in
`../rules/` (see `../rules/SCHEMA.md`), so they live here in code, **not** in
OpenRegister (which is for per-tenant config only). Bump `RuleCatalogue::VERSION`
on any change to a table file, the same discipline the rule corpus follows.

## File naming

One file per jurisdiction-year, named `{jurisdiction}-{year}.json` (lowercase
jurisdiction, e.g. `nl-2026.json`). The file `id` (e.g. `nl-2026`) doubles as the
engine version stamp a calculated record carries (`PayrollRun.engineVersion`).

## Top-level shape

```jsonc
{
  "id": "nl-2026",
  "jurisdiction": "NL",
  "year": 2026,
  "issued": "2026-07-13",
  "basedOn": [
    {"doc": "<human citation>", "url": "<authoritative URL>"}
  ],
  "parameters": { /* groups of leaves, see below */ }
}
```

| key         | required | meaning                                                                |
| ----------- | -------- | ----------------------------------------------------------------------|
| `id`        | yes      | stable slug, `{jurisdiction}-{year}` lowercase (e.g. `nl-2026`)        |
| `jurisdiction` | yes   | ISO 3166-1 alpha-2                                                     |
| `year`      | yes      | the tax year the parameters apply to                                  |
| `issued`    | yes      | ISO date the file's values were verified/issued                       |
| `basedOn`   | yes      | list of `{doc, url}` primary source citations                         |
| `parameters`| yes      | grouped parameter leaves (see leaf shape below)                       |

## Leaf shape

Every parameter value is a leaf object, never a bare scalar:

```jsonc
{"value": <scalar|object|array>, "source": "<citation>", "verified": true|false}
```

Optional keys on a leaf:

| key            | meaning                                                                                          |
| -------------- | ------------------------------------------------------------------------------------------------|
| `placeholder`  | `true` when the value is a stand-in (e.g. a national average) rather than the exact figure a real administration must use |
| `checkAgainst` | required when `placeholder: true` or `verified: false` — names the official document/beschikking to substitute |
| `note`         | free-text clarification (formula context, applicability, n.v.t. columns) |

`verified: false` is only acceptable together with a `checkAgainst` note — an
unconfirmed value must never be silent. Anything the implementer cannot confirm
against a named primary source keeps `verified: false`.

## Units

Amounts in the data file are **euros with 2 decimals** (human-auditable against
the source PDFs/annexes). The engine converts to integer cents on load — that
conversion is engine code (`payroll-core-engine`), not part of this schema.

## Annual re-issue discipline

A new tax year is a **data-only** change: add `{jurisdiction}-{year}.json` with
sourced, verified values and bump `RuleCatalogue::VERSION` — no PHP changes. The
calculation engine reads whichever table file its input `engineVersion` names; it
carries no year-specific logic of its own.

## Uploaded tables: the second home

The files here stay the reference. When an administrator needs next year's
tables before a release ships them, they upload the tables together with the
pack that uses them on the Payroll packs page (`POST /api/payroll/packs` with
`tables`). The upload is stored as a `TaxTableSet` object in OpenRegister, and
`TaxTables::load()` consults it only for an id no file in this directory owns,
so an upload can never shadow a bundled year (an upload named after a bundled id
is refused). The uploaded document must have this same shape: the required
parameter groups, every figure a `{value, source, verified}` leaf, and a
`checkAgainst` on every unverified leaf. The pack's own golden vectors must pass
against the uploaded tables before either is stored
(payroll-pack-and-cao-updates design.md D1 and D2). Once a release ships the
same year as a file here, the file wins and the upload is no longer read.

## Special regimes

The optional `specialRegimes` group holds the treatment of pay that is not
ordinary wage from a current employment (payroll-special-regimes design.md D2),
one object per regime key: `rvu` (early-retirement benefit), `wachtgeld`
(post-office allowance) and `politiek-ambt` (political office holder in office).
Each regime has the leaves `taxTableColor` (`wit` or `groen`),
`werknemersverzekeringen` (boolean) and `zvwMode` (`werkgeversheffing` or
`inhouding`); `rvu` also has `thresholdPerMonth` (euro) and `finalLevyRate`
(percentage). `TaxTables::specialRegime()` reads a regime and lists every leaf
that is unverified or a placeholder; the payroll run pays nothing under a regime
while that list is not empty and reports the person as `regime-unverified`.
The group is not a required group, so an uploaded table set without it still
loads; a run that needs a regime it lacks refuses that payslip.

## Formula notes

A table file may carry a `_notes` object documenting the formula chain the
parameters feed (rounding rules, applicability scoping, edge cases like
above-ceiling wages). These notes are descriptive context for implementers —
normative for the calculation engine that consumes the file, not machine-read by
the engine itself.
