---
kind: code
---

# An organisation chart of units, managers and people

## Why

humaniq keeps the organisation as `OrgUnit` records with a parent and a manager, and places
people in units through `OrgAssignment`. To see who reports to whom, a user opens a unit,
clicks a child unit, and repeats, one level at a time. There is no picture of the whole
organisation, and no way to see at a glance where a team sits, who leads it and how many
people are in it. `org-chart-basic` named the visual chart a follow-up and wanted the visual
component in nextcloud-vue first; the library now ships a hierarchical `CnTreeView` and an
SVG `CnRelationshipGraph` with a manual layout, which is enough to build it without a new
dependency.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-org-chart` | See an organisation chart of who reports to whom. | `partial`: the hierarchy can be walked one level at a time on `OrgUnitDetail`; no chart view |

The follow-up is named in `openspec/changes/archive/2026-07-13-org-chart-basic/proposal.md`
("follow-up spec `org-chart-visualization`").

### Competitors rated yes

- `ppl-org-chart`, AFAS Profit: "the organigram shows each unit's manager, determined
  automatically or by hand, and is used to route signals"
  (https://help.afas.nl/help/NL/SE/Hrm_Config_OrgCht_View.htm).
- `ppl-org-chart`, HR2day: "the R&A module was extended with an organigram in which managers
  can be shown" (https://www.hr2day.com/nieuws/hr2day-meerkat/).
- `ppl-org-chart`, Personio: "the chart shows the company hierarchy, reporting chains and
  employee relationships by employee, department or team"
  (https://support.personio.de/hc/en-us/articles/360017540757-Overview-of-the-Org-chart).

## What Changes

- **A chart read.** humaniq composes, for a date, the tree of active units under a chosen
  root with each unit's manager and the number of people placed in it, and on request the
  people themselves.
- **Two views on one page.** A new `Organogram` page shows the tree as an expandable list
  (`CnTreeView`), which works with a keyboard and a screen reader, and as a drawn chart
  (`CnRelationshipGraph` with a top-down layout computed by humaniq). Clicking a unit opens
  `OrgUnitDetail`.
- **A date.** The chart can be read for a past or future date, because units and
  placements are effective-dated.

## Capabilities

### New Capabilities

- `org-chart-view`: an organisation chart composed from units, managers and placements on a
  date, shown as an accessible tree and as a drawn chart.

## Impact

- `lib/Service/OrgChartService.php` (new), reusing `OrgResolutionService::isActiveOn()`.
- `lib/Controller/OrgChartController.php` (new), `appinfo/routes.php`:
  `GET /api/org/chart?rootId&date&withPeople`.
- `src/manifest.d/hr-org.json`: an `Organogram` page (`type: custom` host view) and a menu
  entry under Personeel; `src/registry.js`: the host view.

## Out of scope

- Export to PNG, PDF or SVG and snapshots of past charts (`org-chart-basic` non-goals).
- Editing the hierarchy by dragging on the chart. Units are edited on their own pages.
- Matrix or dotted-line reporting.
