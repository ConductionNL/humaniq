## MODIFIED Requirements

### Requirement: The dashboard shows 'Aflopende contracten' (REQ-SIG-005)

The existing `Dashboard` page in `src/manifest.json` SHALL surface the expiring-temporary-contract
signal as rows in the Obligations `object-table` widget (`hrmq-dashboard-steering-indicators`
REQ-DSI-008), not as a dedicated full-width `object-table` widget. The underlying filter SHALL be
unchanged: `EmploymentContract` records with `type: "temporary"` and `endDate` within the next 60
days, columns `employeeId`/`endDate`/`aanzegdOn` equivalent data, `rowRoute: EmploymentContractDetail`.
The 60-day window SHALL stay in sync with `nl-signaal-contract-verloopt`'s
`parameters.windowDays` (`lib/Standards/rules/labour.json`) exactly as before — a window change
still touches both. `npm run check:manifest` SHALL stay green.

#### Scenario: Widget lists the expiring seed contract

- GIVEN the seeded data on a day inside the seed window
- WHEN the Dashboard renders
- THEN the Obligations list includes a row for `contract-devries-tijdelijk` with its `endDate`, and activating the row opens `EmploymentContractDetail` — no separate "Aflopende contracten" widget exists on the page

#### Scenario: Manifest stays valid

- WHEN `npm run check:manifest` runs
- THEN it exits 0 with the Dashboard's six-widget layout present and no `dash-expiring-contracts` widget id remaining
