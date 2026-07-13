---
capability: hr-signals
status: done
built_by: openspec/changes/archive/2026-07-13-hr-signals
---

# hr-signals Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [hr-signals](../../changes/archive/2026-07-13-hr-signals/) _(archived 2026-07-13)_ — corpus-first HR-moment signalling: `nl-signaal-contract-verloopt` (temporary contract ending ≤60 days, no successor — advisory) + `nl-aanzegtermijn-bewaking` (BW 7:668 lid 1, mandatory) with the new `aanzegdOn` field, `NlSignalChecks` + the `signals` audit context, the 'Aflopende contracten' dashboard widget, and one intended-violation seed (kind: config)

## Purpose

Stop temporary contracts from lapsing unnoticed (Spectr `hrmq-canon-hr-signals`,
3/9 competitor coverage): the rule corpus signals a temporary contract ending
within 60 days that has no successor, and enforces the statutory aanzegplicht
(written notice one month before the end of a ≥6-month fixed term, BW 7:668 —
missed notice costs up to a month's wage). Investigated-at-HEAD boundaries:
proeftijd and WML signals were deliberately NOT added — `nl-onboarding-
proeftijd-bewaking` and `nl-minimumloon-2026`/`nl-minimumuurloon-wet` already
machine-check them. Push notifications (`x-openregister-notifications`),
ketenregeling chain counting, and aanzegbrief generation are explicitly out of
scope.

## Requirements

### Requirement: The corpus signals expiring temporary contracts (REQ-SIG-001)

`lib/Standards/rules/labour.json` SHALL gain `nl-signaal-contract-verloopt` (domain `labour`, jurisdiction `NL`, new framework slug `hr-signals` — added to SCHEMA.md's framework examples —, severity `recommended`, `machineCheckable: true`, `parameters: {"windowDays": 60}`): a temporary `EmploymentContract` whose `endDate` falls within the next 60 days and that has no successor contract for the same employee SHALL be reported as an advisory violation. A successor is another contract for the same `employeeId` with a later `startDate` and an `endDate` that is empty or later than this contract's. Contracts outside the window (permanent/agency/minijob, open-ended, further out than 60 days, or already expired) SHALL pass vacuously. This change SHALL NOT add proeftijd or minimum-wage rules: `nl-onboarding-proeftijd-bewaking` and `nl-minimumloon-2026`/`nl-minimumuurloon-wet` already machine-check those concerns (investigated at HEAD; recorded in the proposal).

#### Scenario: Expiring contract without successor is flagged

- GIVEN a temporary contract ending 19 days from today with no other contract for the same employee
- WHEN `occ hrmq:rules:audit` runs
- THEN the contract is reported with an `nl-signaal-contract-verloopt` violation at severity `recommended`

#### Scenario: A successor clears the signal

- GIVEN the same expiring contract plus a second contract for the same employee starting after it (endDate empty)
- WHEN the audit runs
- THEN no `nl-signaal-contract-verloopt` violation is reported for either contract

#### Scenario: Expired and far-future contracts stay silent

- GIVEN a temporary contract that ended last month and another ending 120 days from today
- WHEN the audit runs
- THEN neither is reported by `nl-signaal-contract-verloopt`

### Requirement: The corpus enforces the statutory aanzegtermijn (REQ-SIG-002)

`labour.json` SHALL gain `nl-aanzegtermijn-bewaking` (domain `labour`, jurisdiction `NL`, framework `bw7-10`, source BW art. 7:668 lid 1, `sourceUrl: https://wetten.overheid.nl/BWBR0005290`, severity `mandatory`, `machineCheckable: true`, `effectiveDate: "2015-01-01"`, `parameters: {"minContractMonths": 6, "noticeMonths": 1}`): a live (not yet expired) temporary contract with a fixed term of six months or longer whose aanzeg deadline (`endDate` minus one month) has passed SHALL violate unless `aanzegdOn` is recorded on or before that deadline. Contracts shorter than six months, non-temporary contracts, contracts whose deadline has not yet passed, and contracts already past their `endDate` SHALL pass vacuously — the rule statement SHALL state this monitoring-window scope explicitly. `RuleCatalogue::VERSION` SHALL bump for the corpus change (bumped `2026-07.8` → `2026-07.9` — round-3 fleet activity had already advanced the version past the design's assumed starting point).

#### Scenario: Missed aanzegging on a live contract

- GIVEN a temporary contract 2025-09-01 → 2026-08-01 with `aanzegdOn: null` audited on 2026-07-13
- WHEN the audit runs
- THEN an `nl-aanzegtermijn-bewaking` violation at severity `mandatory` is reported (deadline 2026-07-01 passed without aanzegging)

#### Scenario: Timely aanzegging passes

- GIVEN the same contract with `aanzegdOn: "2026-06-25"`
- WHEN the audit runs
- THEN no `nl-aanzegtermijn-bewaking` violation is reported

#### Scenario: Short fixed terms are out of scope

- GIVEN a temporary contract of five months whose deadline would have passed
- WHEN the audit runs
- THEN the contract passes `nl-aanzegtermijn-bewaking` vacuously (the aanzegplicht applies from six months)

### Requirement: EmploymentContract records the aanzegging date (REQ-SIG-003)

`EmploymentContract` (`lib/Settings/register.d/hr-objects.json`) SHALL gain the nullable property `aanzegdOn` (string, format date, title and description present per the gate-28 discipline; the description SHALL name BW 7:668 lid 1 and the one-month deadline). The schema version SHALL bump 0.1.0 → 0.2.0 and the register `info.version` (`lib/Settings/hrmq_register.json`) SHALL bump (bumped `0.6.0` → `0.7.0` — recent merges had already advanced the register past the design's assumed `0.5.0` start). No lifecycle, boolean flag, or workflow SHALL accompany the field — it records the fact needed by REQ-SIG-002, nothing more.

#### Scenario: Field validates after re-import

- GIVEN the register import runs on the bumped fragment
- WHEN an `EmploymentContract` is written with `aanzegdOn: "2026-06-25"`
- THEN it validates, and a contract without the field remains valid (nullable, not required)

### Requirement: NlSignalChecks enforces both rules through a signals audit context (REQ-SIG-004)

A NEW provider `lib/Standards/Checks/NlSignalChecks.php` SHALL register both predicates under object type `EmploymentContract` (RuleEngine merges providers per type additively, so the existing `NlPayrollChecks`/`NlDocumentChecks` contract checks are unaffected). The successor check SHALL be cross-object via a new `RuleAuditService::buildSignalsContext()` wired into `audit()`: `$context['signals']['contractsByEmployeeId']` maps each `employeeId` to the FULL list of its contracts (`{id, type, startDate, endDate}`) — a new index, because the existing `related.EmploymentContract.byEmployeeId` keeps only the last-loaded contract and cannot see siblings. The index SHALL degrade to empty when the schema is absent, and the predicates SHALL stay pure `fn(array $o, array $context): bool` using the `new \DateTimeImmutable('today')` time convention.

#### Scenario: Provider is auto-discovered and coverage rises

- GIVEN the new provider and corpus rows
- WHEN `occ hrmq:rules:audit` prints its coverage header
- THEN `enforceableRules` includes both new rule ids and the catalogue version reads the bumped `RuleCatalogue::VERSION`

#### Scenario: Sibling awareness comes from the context, not re-querying

- GIVEN two contracts for one employee evaluated by the successor predicate
- WHEN the predicate runs
- THEN it resolves the sibling exclusively from `$context['signals']['contractsByEmployeeId']` (unit-testable with a hand-built context, no register access)

### Requirement: The dashboard shows 'Aflopende contracten' (REQ-SIG-005)

The existing `Dashboard` page in `src/manifest.json` SHALL gain an `object-table` widget `dash-expiring-contracts` titled "Aflopende contracten" (icon `FileSignOutline`): `source: {register: "hrmq", schema: "EmploymentContract", filter: {type: "temporary", endDate: {gte: "@today", lte: "@today+60d"}}, order: {endDate: "asc"}, limit: 5}`, columns `employeeId`/`endDate`/`aanzegdOn`, `rowRoute: EmploymentContractDetail`, `viewAllRoute: EmploymentContracts`, `emptyText` for the empty state, plus a full-width layout row appended below the existing grid (gridY 9, width 12, height 5 — mirroring `dash-my-recent-hours`). The `@today`/`@today+60d` tokens and the `{gte, lte}` operator filter shape are the verified widget filter grammar at HEAD. `npm run check:manifest` SHALL stay green. No `x-openregister-notifications` SHALL be added anywhere in this change — the gate-18 canonical dialect is not yet adopted app-wide (the round-1 deferral, repeated deliberately and recorded in the design).

#### Scenario: Widget lists the expiring seed contract

- GIVEN the seeded data on a day inside the seed window
- WHEN the Dashboard renders
- THEN "Aflopende contracten" lists `contract-devries-tijdelijk` with its `endDate`, and clicking the row opens `EmploymentContractDetail`

#### Scenario: Manifest stays valid

- WHEN `npm run check:manifest` runs
- THEN it exits 0 with the new widget and layout row present

### Requirement: One seed exercises both signals at the seed anchor (REQ-SIG-006)

`lib/Settings/register.d/hr-seed.json` SHALL gain exactly one `EmploymentContract` seed `contract-devries-tijdelijk` (`employeeId: "employee-devries"`, `type: "temporary"`, `startDate: "2025-09-01"`, `endDate: "2026-08-01"`, `aanzegdOn: null`, `awfTariff: "high"`, `hourlyWage: 21.00`, `hoursPerWeek: 32`, `writtenContract: true`, plus the jurisdiction-neutral booleans the existing contract seeds carry). At the seed-corpus reference date (≈ 2026-07-13, the 2026-07/08 anchor) the seeded audit SHALL report exactly one `nl-signaal-contract-verloopt` and one `nl-aanzegtermijn-bewaking` violation, both on this contract, and SHALL NOT regress any pre-existing rule (verified in design: Awf tariff matches expected `high`, wage above WML, temporary passes `nl-contract-schriftelijk` vacuously, no `Onboarding` seed references `employee-devries`).

#### Scenario: Seeded audit shows the intended violations

- GIVEN a fresh register import on 2026-07-13
- WHEN `occ hrmq:rules:audit` runs
- THEN `contract-devries-tijdelijk` carries exactly the two new violations and every other seeded object reports the same results as before this change
