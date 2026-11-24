# Design: export the list you are looking at to a spreadsheet

## Context

- `src/manifest.d`: 86 index pages instantiate `indexScaffold` (`00-templates.json`); three are
  concrete `type: index` pages (for example the HR booking index over `TimeEntry` in
  `hours-process-redesign.json`).
- nextcloud-vue `CnIndexPage`: `showExportMenu()` needs `allowExport` and a truthy
  `exportable`, read from the schema top level or `configuration.exportable`
  (nextcloud-vue `index-export-follows-the-page` D2).
- OpenRegister's `ExportRightService` checks per user whether an export is allowed.

## Decisions

### D1. Turn the menu on in the template, not page by page

`allowExport: true` goes into the `indexScaffold` template body, so every list built on it
gets the menu and a new list cannot forget it. The three concrete index pages get it in their
own `config`.

Rejected: a template parameter per page. Eighty-six identical parameters add nothing over the
schema flag, which already decides per schema.

### D2. The schema decides what may leave the building

`configuration.exportable: true` on every schema, with two exceptions:

- `SickLeaveCase`: health data. The Gatekeeper Act case file is for the case manager, not for
  a spreadsheet.
- `DsrRequest`: the log of data subject requests under the AVG.

Self-service lists (`Mijn*` pages) read schemas that are exportable, but their fixed filter
limits the file to the user's own rows, because the export follows the page's filter.

### D3. No humaniq export right

OpenRegister's export right is the only gate. Duplicating it in humaniq would be a second
place to forget an update (ADR-022).

## Risks

- [A list with a fixed filter exports more than it shows] -> depends on nextcloud-vue D1.
  The e2e test of this change exports a `Mijn*` list and counts the rows.
- [A future schema with special-category data defaults to exportable] -> the spec names the
  rule, and the task adds a test that fails when a schema in `hr-verzuim.json` or
  `hr-dsr.json` is exportable.
