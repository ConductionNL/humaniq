# Design: load next year's payroll pack and tables from a page

## Context

Read at `development` af702f78.

- `lib/Controller/JurisdictionPackController.php:109` `upload(?array $pack, bool $override)`
  behind `POST /api/payroll/packs` (`appinfo/routes.php:52`), `#[AuthorizedAdminSetting]` plus
  an `isAdmin()` check. It returns the stored pack's id, year, version, `engineVersion`,
  `overridesBundled` and `provenance`, or the validator's message with 400.
- `lib/Service/JurisdictionPackService.php:112` `upload()`: builds a `JurisdictionPack`, loads
  its declared tables with `tablesFor()` (line 199, which calls `TaxTables::load()`), runs
  `PackValidator::validate()`, then stores a `JurisdictionPack` object with `active: true`.
  `activePack()` (line 158) is the `PackSourceInterface` seam `PackRepository` consults.
- `lib/Payroll/PackValidator.php`: gates 1 to 9, all blocking except provenance (gate 6, which
  stamps). Gate 5 runs the pack's own golden vectors in process; gate 9 refuses shadowing a
  bundled pack unless the admin records an override.
- `lib/Payroll/PackRepository.php:86` `resolve(jurisdiction, period)`: an active uploaded pack
  for the year first, then the bundled pack, otherwise a `DslException`.
- `lib/Payroll/TaxTables.php:83` `load(id)`: reads `lib/Standards/tables/{id}.json` from disk
  only, requires the groups `loonheffing`, `heffingskortingen`, `volksverzekeringen`, `aow`,
  `zvw`, `werknemersverzekeringen` and `vakantiebijslag`. There is no second source. So an
  uploaded pack that declares `tables: nl-2027` fails in `tablesFor()`.
- `lib/Settings/register.d/hr-packs.json:5` `JurisdictionPack`: `packId`, `jurisdiction`,
  `taxYear`, `packVersion`, `dslVersion`, `tables`, `active`, `overridesBundled`,
  `provenance`, `document` (the pack JSON as a string).
- `lib/Command/PayrollYearTransitionCommand.php`: `occ humaniq:payroll:year-transition --year`
  checks that `nl-{year}` exists as a table file and changes nothing.
- `lib/Standards/tables/SCHEMA.md` states that tax parameters live in code and not in
  OpenRegister.
- No page in `src/manifest.d/` reads `JurisdictionPack` or calls `/api/payroll/packs`.
- The Configuration menu group (`src/manifest.d/05-menu.json`, `ConfiguratieGroup`) holds
  `Administraties` and `Integraties`.

## Goals / Non-Goals

**Goals**

- A payroll administrator loads a new year's pack and tables without a release.
- Every validation gate that exists today still decides; the page only shows its verdict.
- Before the first run of a year, the page answers which pack and tables that year uses.

**Non-Goals**

- Authoring or editing packs or tables (jurisdiction-packs non-goal).
- CAO import (cao-library non-goal).
- Automatic download from a vendor.

## Decisions

### D1. Uploaded tables are OpenRegister objects, consulted after the bundled files

A new schema `TaxTableSet` (`tablesId`, `jurisdiction`, `year`, `issued`, `document`,
`active`, `provenance`, `uploadedAt`) holds an uploaded corpus. A new
`TaxTableSourceInterface` (`activeTables(string $id): ?array`) is implemented by
`TaxTableSetService`. `TaxTables::load()` keeps reading the bundled file first; only when no
bundled file has that id does it ask the source. An upload whose id matches a bundled file is
refused.

Alternative considered: let the pack embed its parameter values. Rejected: the pack keeps no
parameter values by design (jurisdiction-packs design.md D4, "the leaves keep exactly one
home"), and embedding them would give every value two homes.

This deliberately amends the stance in `lib/Standards/tables/SCHEMA.md`: the bundled corpus
stays the reference, and an uploaded set is the bridge for a year the release has not reached.
The file is updated to say so.

### D2. Pack and tables are validated as one unit

`JurisdictionPackService::upload(document, override, tablesDocument)` validates the tables
first (structure, required groups, leaf shape, `checkAgainst` on every unverified leaf), then
runs the unchanged `PackValidator` against a `TaxTables` built from the uploaded document.
Both objects are written only after both pass. A tables document without a pack is refused:
tables no pack uses cannot be proven by golden vectors.

Alternative considered: two separate uploads. Rejected: tables uploaded alone are unproven
data, and the order of two uploads becomes one more thing to get wrong.

### D3. Deactivation is a guarded endpoint, not an object edit

`POST /api/payroll/packs/deactivate {packObjectId}` sets `active: false` on the pack and on
tables no other active pack uses. Admin only, like upload. Calculated runs are unaffected
(their `engineVersion` names the pack they used, and non-draft runs are never recalculated). A
draft run recalculated afterwards resolves again: the bundled pack when one exists for that
year, otherwise the run fails with the existing "geen jurisdictiepack" outcome.

Alternative considered: editing `active` through the object API. Rejected: activation is
recorded by the service only, never taken from author input (`JurisdictionPackService`
docblock), and a raw edit could reactivate a pack without re-running its gates.

### D4. The year check is one service for the page and occ

`YearTransitionService::resolution(jurisdiction, year)` answers
`{packId, packVersion, packOrigin, tablesId, tablesOrigin, selfTest, provenance}` by calling
`PackRepository::resolve()` and `TaxTables::load()` and re-running the pack's self-test.
`GET /api/payroll/packs/resolution?year=` exposes it to administrators;
`PayrollYearTransitionCommand` prints the same answer.

Alternative considered: keep the preflight in the command. Rejected: the page would need a
second copy of the same checks.

### D5. One host dialog and one host section, library around them

The manifest page `PayrollPacks` is a plain index over `JurisdictionPack` with create, edit and
delete switched off. A header action opens a registered host dialog `PackUploadDialog` that
reads the chosen files, posts them, and shows the response. A body section
`YearTransitionSection` asks for a year and renders the resolution with library components.
Neither holds business logic.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| list of uploaded packs | declarative index page | the library primitive exists |
| validation and storage | imperative `JurisdictionPackService`, `TaxTableSetService` | every gate is code that decides wages |
| resolution for a year | imperative `YearTransitionService` | re-runs the self-test |
| upload dialog and year section | host components wiring files and figures into library components | a file upload to an endpoint has no declarative primitive |

## Seed data

`TaxTableSet` is a new schema with no seed: an upload is an administrator's act. The
year-transition section shows the bundled 2026 resolution on a fresh instance.

## Risks / Trade-offs

- [An uploaded table with a wrong figure pays wrong wages] -> the pack's golden vectors must
  pass against it, unverified leaves are stamped in `provenance` and shown on the page, and
  the upload is admin only.
- [Two homes for tables] -> the bundled file always wins for its own id, and the page names
  the origin of every table a year resolves to.

## Open Questions

- Should the page warn in November when next year resolves to nothing? This design shows the
  resolution on demand only.
