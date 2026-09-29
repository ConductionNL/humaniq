# Design: warnings for the fixed-term chain and the on-call fixed-hours offer

## Context

Read at `development` af702f78.

- `EmploymentContract` (`lib/Settings/register.d/hr-objects.json`, version 0.7.0): `type`
  enum `permanent`, `temporary`, `agency`, `minijob`, `bbl`; `startDate`, `endDate`,
  `hoursPerWeek`, `aanzegdOn`, `cao`. The Awf tariff rule `nl-awf-laag-hoog-tarief` treats
  every type other than `permanent` as the high tariff, so a new on-call type falls under
  it without change.
- hr-signals: `lib/Standards/Checks/NlSignalChecks.php` implements
  `nl-signaal-contract-verloopt` and `nl-aanzegtermijn-bewaking`
  (`lib/Standards/rules/labour.json:345`). `RuleAuditService::buildSignalsContext()`
  provides `signals.contractsByEmployeeId`, a full list of `{id, type, startDate, endDate}`
  per employee, built for exactly this kind of sibling-aware check. `hasSuccessor()`
  (`NlSignalChecks.php:178`) is shape-based and not a chain model.
- The Dashboard already carries the "Aflopende contracten" table from hr-signals.
- Hours: `Timesheet` (`lib/Settings/register.d/hr-timesheet.json`) has `employeeId`,
  `period`, `hours`, `status`; `TimeEntry` has `employeeId`, `date`, `hours`,
  `timesheetId`. `Timesheet.period` has a polymorphic grain, so day-level averages read
  `TimeEntry` rows whose timesheet is `approved`.
- Detail pages render `config.bodyWidgets` host sections with `@objectId` resolved
  (`@conduction/nextcloud-vue` 2.40.0 `CnDetailPage.vue:1361`).

## Goals / Non-Goals

**Goals**

- HR sees, before renewing, that a renewal would make a contract permanent.
- HR sees the twelve-month average an on-call offer must equal, and is told when the offer
  is due and not recorded.

**Non-Goals**

- Blocking a renewal. The rules are `recommended` signals; the decision stays with HR.
- Deriving the offer amount automatically onto a new contract.

## Decisions

### D1. One chain model, used by the rule and the page

`ContractChainService::chainFor(array $contracts, string $contractId): array` sorts an
employee's non-permanent contracts by `startDate` and walks back from the given contract
while the gap between one contract's `endDate` and the next one's `startDate` is at most
`maxGapMonths` (6). It returns `{position, contracts, monthsCounted, turnsPermanentOn}`.
The predicate for `nl-signaal-ketenregeling` calls it with the context index; the
`GET /api/contracts/{id}/chain` endpoint calls it with the employee's contracts read
through `RbacObjectReader`. Alternative considered: extending `hasSuccessor()`. Rejected:
that probe is shape-based by design and its docblock says so.

### D2. Rule parameters

`nl-signaal-ketenregeling`: `maxContracts` 3, `maxMonths` 36, `maxGapMonths` 6,
`windowDays` 60, severity `recommended`, source BW 7:668a, `sourceUrl`
https://wetten.overheid.nl/BWBR0005290. Violated when the contract is live and either its
chain position equals `maxContracts` or `turnsPermanentOn` falls within `windowDays`.

### D3. On-call type and average

`type` gains `oproep` (label "On-call"). New nullable properties `vasteUrenAanbodOp`
(date) and `vasteUrenAanbodUren` (number). `OnCallAverageService::averages(from, to)`
sums approved `TimeEntry.hours` per on-call contract over the window, divides by the
number of weeks and months in the window that fall within the contract, and returns the
rows the overview shows. `nl-signaal-oproep-vaste-uren` (recommended, source BW 7:628a
lid 5) is violated when an `oproep` contract started more than 12 months ago and
`vasteUrenAanbodOp` is empty; its predicate needs no hours.

### D4. Surfaces

- `EmploymentContractDetail` gains a `bodyWidgets` host section `ContractChainSection`
  bound to the chain endpoint, shown for non-permanent contracts.
- `OnCallAverages` (new index-style page, `type: custom` host view, the
  `ProformaPayslip` precedent) with a from and to date, the table, and CSV export through
  the library's export helper. A declarative index page cannot show a computed average.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| chain signal and offer signal | corpus rules, `NlSignalChecks` predicates | the hr-signals mechanism, machine-checkable |
| chain composition | imperative `ContractChainService` | ordering and gap arithmetic over sibling contracts |
| average hours | imperative `OnCallAverageService` | a cross-schema sum over approved entries |
| offer fields and enum value | declarative schema properties | data only |

## Seed data

- Employee "Sanne Bakker" with three fixed-term contracts (2024-03 to 2024-12,
  2025-02 to 2025-12, 2026-01 to 2026-12): the third flags the chain signal.
- Employee "Ahmed El Idrissi" on an `oproep` contract since 2025-06 with approved time
  entries over twelve months and no offer recorded: flags the offer signal; the overview
  shows his average.

## Risks / Trade-offs

- [Gap arithmetic at month ends] → gaps are measured in calendar days against six
  calendar months after the previous end date, with unit tests on the boundaries.
- [Hours booked outside timesheets] → the average reads approved hours only and says so on
  the page; unapproved hours never count.

## Open Questions

- Should the overview also show the average in days per week (Loket offers both)? The
  service can return days from `TimeEntry.date`; this design shows hours per week and per
  month only.

## Changes made while building (2026-09-29)

- **Chain links.** Links are fixed-term contracts (an end date) of type `temporary`,
  `oproep` or `minijob`. `agency` contracts stay out (their own phase system under the
  uitzendbeding) and so do `bbl` contracts (BW 7:668a lid 10). Open-ended contracts are no
  link.
- **Turns permanent on.** For a chain of three or more it is the day after the contract
  ends (a renewal is the fourth contract), unless the chain passes 36 months earlier; for a
  shorter chain it is the day the chain passes 36 months.
- **The chain section** is an `endpoint-table` widget, not a `bodyWidgets` host section:
  on a detail page `object-table` ignores `endpointSource` in nextcloud-vue 2.57.1
  (ConductionNL/nextcloud-vue#1281). `EmploymentContractDetail` moved out of
  `simpleDetailScaffold` to carry it. A contract outside any chain shows the empty text.
- **CSV** comes from the same endpoint (`format=csv`), a `DataDisplayResponse` with an
  attachment header, so the download carries the figures the page shows. The link passes
  the request token in the query.
- **Seed names.** "Sanne Bakker" became Sanne Meijer, because `employee-bakker` already
  exists in the seed. The import stamps every seeded timesheet draft, so Ahmed El
  Idrissi's August hours count once that timesheet is approved.
- **Open question answered.** Hours per week and per month only; days per week stays out.
