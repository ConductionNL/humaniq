# Design: an organisation chart of units, managers and people

## Context

Read at `development` af702f78.

- `OrgUnit` (`lib/Settings/register.d/hr-org.json`): `name`, `type`, `parentUnitId`,
  `costCenter`, `managerId`, `active`, `administrationId`. `OrgAssignment`: `employeeId`,
  `orgUnitId`, `role`, `startDate`, `endDate`.
- `lib/Service/OrgResolutionService.php`: `isActiveOn()` (:71) decides whether an assignment
  is live on a date; `resolveManagerUserIds()` (:110) and `activeUnits()` (:204) walk units.
- Pages: `OrgUnits` (`src/manifest.d/hr-org.json:168`) and `OrgUnitDetail` (:4) with a
  child-units list and related parent and manager.
- `@conduction/nextcloud-vue` 2.40.0 ships `CnTreeView` (nested `nodes` with children,
  expand and select, keyboard operable) and `CnRelationshipGraph` (SVG nodes and edges, with
  `layout: 'manual'` taking an `x` and `y` per node).
- `org-chart-basic` recorded two things: the visual widget belongs in the library first, and
  "no reporting-line REST wrapper" (ADR-022: no pass-through). This change adds a composed
  read, not a pass-through: it joins three schemas and lays out a tree.

## Goals / Non-Goals

**Goals**

- The whole hierarchy, or a branch, on one screen, with manager and headcount per unit.
- An accessible text form of the same chart.

**Non-Goals**

- A new graph library. The layout is a simple tidy tree computed server side.

## Decisions

### D1. Compose the tree on read

`OrgChartService::chart(rootId, date, withPeople)` loads active units of the caller's
administration, builds children by `parentUnitId`, attaches `managerId` resolved to a name,
counts `OrgAssignment` rows live on `date` per unit, and when `withPeople` is set lists the
placed employees with their role. Units whose parent is missing or inactive become roots, so
a broken link shows rather than hides a branch.

### D2. Layout for the drawn chart

The service also returns `x` and `y` per unit: `y` is the depth, `x` the position of the
unit among the leaves of its subtree (a standard tidy-tree pass), so `CnRelationshipGraph`
can draw it with `layout: 'manual'`. Edges run parent to child. Alternative considered:
waiting for an `org-chart-visualization` widget in nextcloud-vue. Rejected: the two shipped
components cover it and keep the Vue logic in the library.

### D3. Access

`GET /api/org/chart` is `#[NoAdminRequired]`. Units and managers are organisational facts
every employee may see; people are listed only for units the caller may read under
OpenRegister RBAC (`RbacObjectReader`), so `withPeople` never widens access.

### D4. The page

`Organogram` is a `type: custom` host view (the `ProformaPayslip` precedent) with a root
picker, a date, a toggle between tree and chart, and "show people". It holds no logic
beyond passing the endpoint's payload to the two library components.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| tree, counts and layout | imperative `OrgChartService` | a composition over three schemas plus a layout pass |
| rendering | library components in a host view | no manifest widget type for a tree yet |

## Seed data

No schema change. The seeded hierarchy from `org-chart-basic` (a municipality with
directorates and teams) is enough; one seed assignment gains an end date in the past so the
date picker shows a difference.

## Risks / Trade-offs

- [Large organisations] → the chart is read per branch (`rootId`) and collapsed below two
  levels by default; the SVG is drawn only for up to 200 units and the tree view carries the
  rest.
- [SVG is not accessible on its own] → the tree view is the default and the chart carries a
  text alternative pointing to it.

## Open Questions

- None.

## Changes made while building (2026-09-29)

- **Units are not dated.** `OrgUnit` has an `active` flag, no start or end date, so the date
  moves placements (`OrgAssignment`) only; inactive units are left out on every date.
- **The page is a dashboard** with one host widget (`org-chart`, `OrgChartWidget.vue`), not
  a `type: custom` host view: gate 69 ratchets custom pages. The widget holds the root
  picker, the date, "Show people" and the switch between list and drawing.
- **Scope.** The chart covers the caller's active administration (rows without an
  `administrationId` included); a caller without one gets 403.
- **Layout.** `x` is the position among the leaves (a parent centred between its first and
  last child), `y` the depth; the widget scales both into the graph's square.
- **Seed.** No seed change: the existing placements show the headcounts; moving one would
  change other pages' live checks.
