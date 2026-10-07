---
kind: code
depends_on:
  - nextcloud-vue/index-export-follows-the-page
---

# Export the list you are looking at to a spreadsheet

## Why

Every humaniq list offers a mass action called Export. It exports only the rows already
fetched or selected. The full export, which exports the whole filtered list as CSV or Excel
through OpenRegister, is off on every page: no humaniq page sets `allowExport` and no schema
sets `exportable` (`grep -rn 'allowExport|exportable' src/manifest.d lib/Settings/register.d`
finds nothing).

The library half is specified in nextcloud-vue `index-export-follows-the-page`: the export
honours the page's filters, and the menu reads `configuration.exportable`. OpenRegister now
keeps that flag (`lib/Db/Schema.php`, "Fold a top-level `exportable` into
`configuration.exportable`"). That change names humaniq's half: "Turning on `allowExport` is
humaniq's half." This change is that half.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `rep-export` | Export any list to a spreadsheet. | `partial`: only fetched or selected rows can be exported |

### Competitors rated yes

AFAS, Visma Raet, HR2day, Loket.nl and Personio (row evidence in the matrix).

## What changes

- The `indexScaffold` page template and the three concrete index pages set `allowExport`.
  Every list built on them shows the Export menu.
- Every humaniq schema sets `configuration.exportable: true`, except the two that hold data
  nobody should take out in bulk: `SickLeaveCase` (health data, Wet verbetering poortwachter)
  and `DsrRequest` (requests under the AVG).
- OpenRegister's export right decides per user who may export. Humaniq adds no right of its
  own.

## What does not change

- No export code in humaniq. The file is built by OpenRegister's export leaf.
- The mass export of selected rows stays.

## Capabilities

### New capabilities

- `hr-list-export`: export a humaniq list, filtered as shown, to CSV or Excel.

## Impact

- `src/manifest.d/00-templates.json` (`indexScaffold`), the three concrete index pages,
  `src/manifest.effective.json` (regenerated).
- `lib/Settings/register.d/*.json`: `configuration.exportable` on each schema except the two
  above; register version bump.
