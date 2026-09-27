---
kind: code
---

# Load next year's payroll pack and tables from a page

## Why

In December a payroll administrator needs January's tax tables. In humaniq today there are two
ways to get them, and neither has a page. The bundled tables and pack
(`lib/Standards/tables/nl-2026.json`, `lib/Standards/packs/nl-2026.pack.json`) only change
with an app release. The upload endpoint `POST /api/payroll/packs` accepts a new pack, but no
page calls it, and an uploaded pack still cannot move a year: it must name a tables corpus, and
`TaxTables::load()` only reads files that shipped with the app. So a 2027 pack uploaded today
is refused because `nl-2027` does not exist on disk.

The year-transition preflight (`occ humaniq:payroll:year-transition`) tells an operator in a
shell whether next year's table file exists. It does not tell a payroll administrator anything.

This change gives the administrator one page to upload a pack together with its tables, see
every validation gate's verdict, and check before the first run of a new year which pack and
tables that year will be paid with.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-cao-updates` | Receive updated collective agreement and legislation rules without reconfiguring by hand. | `partial`, built.state `built`: `POST /api/payroll/packs` exists with no page calling it; the bundled CAO and tax corpus changes only with an app upgrade |
| `pay-year-transition` | Move to a new tax year with the new tables without reconfiguring. | `partial`, built.state `built`: packs resolve by jurisdiction and year of the period; the year-transition command is a read-only preflight and only `nl-2026` ships |

### Competitors rated yes

- `ppl-cao-updates`, AFAS Profit: "cao and legislation changes are updated automatically
  through AFAS Online" (https://www.afas.nl/software/salarisadministratie).
- `ppl-cao-updates`, Visma Raet Youforce: "cao and legislation changes are applied quickly and
  made available to all customers directly" (https://youforce.nl/product/hr-core-salaris).
- `ppl-cao-updates`, HR2day: "always up to date with the latest legislation and collective
  agreements such as the CAO Gemeenten" (https://www.hr2day.com/gemeenten/).
- `ppl-cao-updates`, Loket.nl: "cao updates are applied automatically so salary data matches
  the newest agreements" (https://loket.nl/oplossingen-voor/accountants/).
- `pay-year-transition`, AFAS Profit: "the payroll year transition closes the wage year with
  delivered cao's for the new year" (https://help.afas.nl/help/NL/SE/Pay_YrEd08_.htm).
- `pay-year-transition`, Visma Raet Youforce: "new legislation and cao changes are applied
  immediately" (https://youforce.nl/product/hr-core-salaris).
- `pay-year-transition`, HR2day: "Jaarwissel 2025-2026: new rates and tables prepared
  centrally" (https://data.maglr.com/1697/issues/64613/763864/index.html).
- `pay-year-transition`, Loket.nl: "request a year transition, also collectively"
  (https://developer.loket.nl/ApiDocs#tag/Year-transition).

### Recorded non-goals this change respects

- jurisdiction-packs (`openspec/changes/archive/2026-07-15-jurisdiction-packs/proposal.md`,
  Non-Goals, binding): "Pack authoring UI: upload + validate only." This change adds an upload
  and validate page. Nobody writes or edits a pack in humaniq.
- cao-library (`openspec/changes/archive/2026-07-14-cao-library/proposal.md`): "A CAO
  import/authoring UI: the corpus is maintained in code (like the rules/tables corpus); the
  manifest page is read-only reference." This change does not import CAO data. A CAO revision
  keeps arriving as data in an app release, and contracts pick it up by `caoId` without being
  touched. The CAO half of `ppl-cao-updates` is therefore covered for legislation only; see
  Out of scope.
- payroll-core-schema: "Multi-year tables: 2026 only; `nl-2027.json` is next year's data-only
  change." That stays true for the bundled corpus. This change adds a second, uploaded home for
  a year the release has not reached yet.

## What Changes

- **Upload a pack with its tables.** `POST /api/payroll/packs` accepts an optional `tables`
  document next to the pack. Both are validated together; nothing is stored unless both pass.
  The tables pass the same structural checks `TaxTables::load()` applies, and every leaf must
  carry `value`, `source` and `verified`. The pack's own golden vectors then run against those
  tables, as `PackValidator` gate 5 already requires.
- **Uploaded tables are a second home, not a replacement.** A new `TaxTableSet` object holds
  an uploaded tables corpus. `TaxTables::load()` resolves a bundled file first and an uploaded
  set only for an id no bundled file owns, so an upload can never shadow `nl-2026`.
- **A page for packs.** A "Payroll packs" index under Configuration lists uploaded packs with
  year, version, active flag and provenance. An "Upload pack" action opens a dialog that sends
  the files and shows the gate that refused them, in the validator's own words.
- **Deactivate a wrong pack.** An administrator can deactivate an uploaded pack. Runs already
  calculated keep their `engineVersion` stamp; draft runs pick up the bundled pack again on
  recalculation.
- **A year-transition check on the page.** For a chosen year the page shows which pack and
  which tables that year resolves to, where each came from (bundled or uploaded), and whether
  the pack's self-test passes. The occ preflight uses the same service.

## Capabilities

### New Capabilities

- `payroll-pack-and-table-updates`: upload and validate a payroll pack with its tax tables from
  a page, and check which pack and tables a tax year will use before its first run.

## Impact

- `lib/Settings/register.d/hr-packs.json`: new schema `TaxTableSet`.
- `lib/Payroll/TaxTableSourceInterface.php` (new) and `lib/Payroll/TaxTables.php`: an uploaded
  source consulted after the bundled directory.
- `lib/Service/TaxTableSetService.php` (new): validates and stores uploaded tables, implements
  the source.
- `lib/Service/JurisdictionPackService.php` and `lib/Controller/JurisdictionPackController.php`:
  accept `tables`, add deactivate and the year resolution read.
- `lib/Service/YearTransitionService.php` (new), used by
  `lib/Command/PayrollYearTransitionCommand.php` and the new read endpoint.
- `appinfo/routes.php`: `POST /api/payroll/packs/deactivate`,
  `GET /api/payroll/packs/resolution`.
- `src/manifest.d/hr-packs.json` (new), `src/manifest.d/05-menu.json`, `src/registry.js` and
  one host dialog plus one host section.
- `lib/Standards/tables/SCHEMA.md`: records the uploaded second home.

## Out of scope

- Importing CAO data from a page. The cao-library non-goal stands; CAO revisions arrive with a
  release.
- Writing or editing a pack or tables in humaniq (jurisdiction-packs non-goal).
- Fetching packs from a vendor feed automatically. A signed feed is its own change.
- Changing a calculated run: an approved or posted run keeps the pack it was paid with.
