## MODIFIED Requirements

### Requirement: Declarative timesheet pages

The system SHALL surface timesheets through declarative manifest pages rendered generically by the
`@conduction/nextcloud-vue` library — NOT bespoke Vue components. There SHALL be a `Timesheets`
`type:"index"` list page, a `TimesheetApproval` `type:"index"` page whose default filter is
`status == submitted` (the pending-approval queue), and a `TimesheetDetail` `type:"detail"` page,
each configured only with `{ register: "hrmq", schema: "Timesheet", … }`. HRMQ SHALL appear in the
Nextcloud app menu via an `<navigations>` entry routing to the SPA shell, and its timesheet pages
SHALL be reached from the "Verlof & verzuim" top-level menu group per
`adr-001-information-architecture.md`'s frozen navigation (not a standalone "Uren" top-level
group).

**Feature tier**: MVP

#### Scenario: The approval queue lists pending timesheets

- GIVEN submitted, approved and rejected timesheets exist in the `hrmq` register
- WHEN a manager opens the "Urengoedkeuring" (TimesheetApproval) page
- THEN the page MUST default its filter to `status == submitted`
- AND MUST render the list generically from the `Timesheet` schema without a bespoke component

#### Scenario: HRMQ is reachable from the app menu

- GIVEN hrmq is installed and enabled
- WHEN the user opens the Nextcloud app menu
- THEN HRMQ MUST appear and open its SPA shell at the timesheets list

#### Scenario: Timesheet pages are reachable under Verlof & verzuim

- GIVEN the hrmq app menu is rendered
- WHEN a user looks for time-registration pages
- THEN the `Timesheets` and `TimesheetApproval` entries MUST be found under the "Verlof & verzuim"
  top-level menu group, not under a standalone "Uren" top-level group
