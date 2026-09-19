---
capability: leave-management
status: done
built_by: openspec/changes/archive/2026-07-12-leave-verzuim-mvp
---

# leave-management Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [leave-verzuim-mvp](../../changes/archive/2026-07-12-leave-verzuim-mvp/) _(archived 2026-07-12)_ — Verlof & verzuim menu group (ADR-001 menu 5) with LeaveRequests/LeaveApproval/LeaveRequestDetail pages driving the existing LeaveRequest lifecycle, new `LeaveBalance` schema with calculated `remainingHours` + BW 7:640a expiry, 3 new machine-checkable NL leave rules in a new labour corpus (kind: config)
- [leave-approval-posts-to-the-balance](../../changes/archive/2026-09-06-leave-approval-posts-to-the-balance/) _(archived 2026-09-06)_ — approving a leave request recomputes `LeaveBalance.usedHours` from the approved requests behind it, with hours derived from the dates when not given (REQ-LEAVE-POST-001/002) (kind: code)

## Purpose

Make the existing `LeaveRequest` workflow (shipped schema-only by
`portal-schemas`) a usable back-office feature: the frozen ADR-001 menu-5
`Verlof & verzuim` surface with request, approval-queue and detail pages
bound to the already-declared submit→approve/reject lifecycle, a
`LeaveBalance` schema with a declaratively calculated remaining-hours figure
(statutory minimum 4× contractual weekly hours per BW art. 7:634; statutory
hours lapse 1 July of the following year per BW art. 7:640a), and three
versioned machine-checkable leave rules enforced by `NlLeaveChecks`.
Grounded in the 2026-07-12 market deep-research (Spectr insights
`hrmq-insight-nc-ecosystem-gap`, `hrmq-insight-ranked-buildlist`): leave is a
core module in every competitor, and humaniq's schema had zero UI and no
balances. Automatic accrual and CAO bovenwettelijk rules are explicitly out
of scope.

## Requirements

### REQ-LVM-001: The manifest SHALL add the `Verlof & verzuim` menu group with the leave pages

`src/manifest.json` gains menu group `VerlofVerzuimGroup` (label "Verlof & verzuim", icon `CalendarClock`, order 105 — the frozen ADR-001 menu-5 top-level entry, so no ADR amendment is needed) with children `LeaveRequests`, `LeaveApproval`, `LeaveBalances` (and `SickLeaveCases`, spec'd in `verzuim-wvp`). Pages: `LeaveRequests` (index over `LeaveRequest`: columns `employeeId`, `leaveType`, `startDate`, `endDate`, `hours`, `status`; filters `status`, `leaveType`; sort `startDate` desc) and `LeaveApproval` (index over `LeaveRequest` pre-filtered `defaultFilters: {status: "submitted"}`, columns `employeeId`, `leaveType`, `startDate`, `endDate`, `submittedAt`, `status`, sort `submittedAt` asc — mirroring `TimesheetApproval` exactly). deepLinks for `LeaveRequest` and `LeaveBalance` are registered. The manifest MUST validate against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Approval queue shows only submitted requests
@e2e exclude declarative index filtering is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** LeaveRequest objects in statuses `draft`, `submitted`, and `approved`
- **WHEN** the `LeaveApproval` page loads
- **THEN** only the `submitted` request is listed, oldest submission first

### REQ-LVM-002: `LeaveRequestDetail` SHALL drive the EXISTING lifecycle — no new or altered transitions

`LeaveRequestDetail` (detail over `LeaveRequest`, route `/leave-requests/:id`) carries: a "Request" data widget (excluding `employeeId` — the Related panel resolves the requesting Employee by name), an "Approval" data widget (`status`, `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`), a related widget, a files widget ("Supporting documents"), an audit-history sidebar tab, and `lifecycleActions` exposing **exactly** the transitions already declared in `hr-leave.json`: `submit` (from `draft`|`rejected`), `approve` (from `submitted`), `reject` (from `submitted`) — guarded server-side by the existing `NoSelfApprovalGuard`. The `LeaveRequest` schema, its lifecycle, and the guard are NOT modified by this change.

#### Scenario: Detail page walks the existing workflow
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** a LeaveRequest in status `draft` opened on `LeaveRequestDetail`
- **WHEN** the user executes Submit
- **THEN** the page reflects status `submitted` and offers Approve and Reject

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions.transitions` for `LeaveRequestDetail` are compared to the `x-openregister-lifecycle` in `lib/Settings/register.d/hr-leave.json`
- **THEN** they match action-for-action (`submit`/`approve`/`reject`, same from/to) with no additional action

### REQ-LVM-003: A new `LeaveBalance` schema SHALL model per-employee/year/type entitlements with a calculated remainder

`lib/Settings/register.d/hr-leave.json` gains `LeaveBalance` (version 0.1.0): `employeeId` (string, format uuid, `$ref` Employee, required), `year` (integer, required), `leaveType` (enum identical to `LeaveRequest.leaveType`: holiday/sick/unpaid/special/care/parental, required), `entitledHours` (number, required — description documents the BW art. 7:634 statutory minimum of 4× contractual weekly hours), `bovenwettelijkHours` (number, default 0), `usedHours` (number, default 0), `contractHoursPerWeek` (number, nullable — snapshot of the contractual weekly hours at grant time; the single-object rule check reads this, see design D3), `expiryDate` (string, format date, nullable — description documents BW art. 7:640a: statutory hours lapse 1 July of the following year). `remainingHours` is declared via `x-openregister-calculations` in the schema `configuration` (`materialise: true`, expression `entitledHours + bovenwettelijkHours − usedHours`) and NOT as a stored property. Every property carries a human-friendly title + description (gate-28). Register `info.version` bumps 0.2.0 → 0.3.0.

#### Scenario: Balance materialises its remainder
- **GIVEN** the imported hrmq register
- **WHEN** a LeaveBalance `{employeeId: <uuid>, year: 2026, leaveType: "holiday", entitledHours: 160, bovenwettelijkHours: 40, usedHours: 56}` is created
- **THEN** creation succeeds and the rendered object carries `remainingHours: 144`

#### Scenario: Incomplete balance rejected
- **WHEN** a LeaveBalance is written without `entitledHours`
- **THEN** OpenRegister schema validation rejects it (required-property violation)

### REQ-LVM-004: The rule corpus SHALL gain three machine-checkable NL leave rules in a new `labour.json`

A new corpus file `lib/Standards/rules/labour.json` (`{"domain": "labour", "version": "2026-07", "rules": [...]}` — payroll.json's domains are tax/reporting/ledger-integrity; leave is labour law, and SCHEMA.md prescribes one file per sub-domain) gains `nl-verlof-wettelijk-minimum` (BW art. 7:634 — entitled ≥ 4× contractual weekly hours), `nl-verlof-saldo-niet-negatief` (BW art. 7:634 jo. 7:638 — `usedHours ≤ entitledHours + bovenwettelijkHours`), and `nl-verlof-vervaltermijn` (BW art. 7:640a — statutory hours carry `expiryDate` = 1 July of the following year). All three: `domain: labour`, `jurisdiction: NL`, `framework: bw7-10`, `severity: mandatory`, `machineCheckable: true`, `sourceUrl: https://wetten.overheid.nl/BWBR0005290`. `RuleCatalogue::VERSION` bumps to `2026-07`.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ humaniq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads payroll.json AND labour.json without error and reports the three new rules as enforced (each has a CheckProvider predicate)

### REQ-LVM-005: `NlLeaveChecks` SHALL enforce the three leave rules as single-object predicates

New auto-discovered provider `lib/Standards/Checks/NlLeaveChecks.php` (implements `CheckProvider`) registering, under object type `LeaveBalance`:
1. **Wettelijk minimum** — violation when `contractHoursPerWeek` is present and `entitledHours < 4 × contractHoursPerWeek`; passes vacuously when the snapshot is null (not decidable from the object).
2. **Saldo niet negatief** — violation when `usedHours > entitledHours + bovenwettelijkHours`.
3. **Vervaltermijn** — violation when `entitledHours > 0` and `expiryDate` differs from `<year+1>-07-01` (null included).

Each predicate is side-effect free and keyed by its corpus rule id.

#### Scenario: Under-granted balance flagged
- **GIVEN** the seeded balance for employee-bakker (`contractHoursPerWeek: 36`, `entitledHours: 120`)
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a `nl-verlof-wettelijk-minimum` violation is reported for that object (120 < 144)

#### Scenario: Negative balance flagged
- **GIVEN** the seeded balance for employee-devries (`entitledHours: 128`, `bovenwettelijkHours: 0`, `usedHours: 140`)
- **WHEN** the audit runs
- **THEN** a `nl-verlof-saldo-niet-negatief` violation is reported

#### Scenario: Compliant balance passes clean
- **GIVEN** the seeded balance for employee-jansen (160 entitled ≥ 4×40, used 56, expiry 2027-07-01)
- **WHEN** the audit runs
- **THEN** no leave-rule violation is reported for that object

### REQ-LVM-006: Seed data SHALL cover a compliant, an over-used, and an under-granted balance

`lib/Settings/register.d/hr-seed.json` gains the three LeaveBalance objects from design.md (jansen compliant, devries used>total, bakker entitled<4×weekly), each with `expiryDate: 2027-07-01` and slug-style placeholder references matching the existing seed employees, plus a `LeaveBalances` index page listing `employeeId`, `year`, `leaveType`, `entitledHours`, `bovenwettelijkHours`, `usedHours`, `remainingHours`, `expiryDate` under the new menu group.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the three balances exist exactly once

### Requirement: An approved leave request SHALL be reflected in its leave balance (REQ-LEAVE-POST-001)

When a `LeaveRequest` is created or updated, the system SHALL recompute `LeaveBalance.usedHours` for the
matching `employeeId`, `year` and `leaveType`. The recomputed value SHALL be the sum of the leave hours
of every `LeaveRequest` with status `approved` for that employee, year and leave type.

The recomputation SHALL be idempotent: running it twice over an unchanged set of requests SHALL produce
the same value and SHALL NOT write a second time. The system SHALL skip the write entirely when the
recomputed value equals the stored value.

The recomputation SHALL run on every status, not only on entry into `approved`, so correcting the dates
or hours of an already approved request restates the balance.

Note on what that does NOT buy: the `LeaveRequest` lifecycle has no transition OUT of `approved`
(`submit` goes draft/rejected to submitted, `approve` and `reject` both go from submitted), so an
approved request cannot be rejected. Measured against a live instance: the write is refused with a 422
and no save occurs. Running on every status is therefore about CORRECTIONS and about idempotency, not
about a reversal the lifecycle does not permit.

The system SHALL resolve the balance by `employeeId`, `year` and `leaveType` and SHALL NOT create one.
When no balance matches, the system SHALL log at info level and make no write. Auto provisioning a
missing balance stays the named follow-up `leave-balance-auto-provision`.

A failure to resolve, read or write SHALL be logged and SHALL NOT break the save path.

#### Scenario: Approving a request moves the balance
@e2e tests/e2e/spec-coverage/leave-balance-projection.spec.ts
- **GIVEN** a LeaveBalance `{employeeId: e1, year: 2026, leaveType: holiday, entitledHours: 160, bovenwettelijkHours: 0, usedHours: 0}`
- **AND** a LeaveRequest `{employeeId: e1, leaveType: holiday, startDate: 2026-03-02, endDate: 2026-03-06, hours: 40, status: submitted}`
- **WHEN** the request's status becomes `approved`
- **THEN** the balance's `usedHours` is 40
- **AND** its calculated `remainingHours` is 120

#### Scenario: Correcting an approved request restates the balance
@e2e exclude Backend projection, same surface as the requirement above.
- **GIVEN** the balance and request from the previous scenario, with the request approved and `usedHours` at 40
- **WHEN** the request's `endDate` is corrected from Friday to Wednesday
- **THEN** the balance's `usedHours` is 24

#### Scenario: A status the lifecycle refuses never reaches the projection
@e2e exclude Lifecycle behaviour, measured against a live instance rather than asserted here.
- **GIVEN** an approved LeaveRequest
- **WHEN** a write tries to move it to `rejected`
- **THEN** OpenRegister refuses the transition with a 422
- **AND** the balance is unchanged, because no save happened for the listener to react to

#### Scenario: A second identical projection writes nothing
@e2e tests/e2e/spec-coverage/leave-balance-projection.spec.ts
- **GIVEN** a balance whose `usedHours` already equals the sum of its approved requests
- **WHEN** the projection runs again
- **THEN** no write is issued against the balance

### Requirement: Leave hours SHALL be derived from the dates when they are not given (REQ-LEAVE-POST-002)

`LeaveRequest.hours` is optional. When it is absent, null or zero, the system SHALL derive the hours a
request consumes by counting the Monday to Friday days in the inclusive `startDate` to `endDate` range
and multiplying by `contractHoursPerWeek / 5`, taking `contractHoursPerWeek` from the resolved
`LeaveBalance`.

When `hours` is present and greater than zero, the system SHALL use it unchanged and SHALL NOT derive.

When `contractHoursPerWeek` is null or zero the hours are not derivable. The system SHALL count that
request as zero hours, SHALL name its id in a warning, and SHALL still project the requests it could
derive. It SHALL NOT guess a working week.

Public holidays are NOT subtracted. A request spanning a public holiday therefore overstates usage by
one day.

A request with an explicit `hours` value SHALL be attributed wholly to the calendar year of its
`startDate`. A derived request SHALL have only the working days falling inside the target year counted
against that year, so a request spanning New Year splits across two balances.

#### Scenario: A week of leave with no hours is derived from the contract
@e2e exclude Pure arithmetic, verified by PHPUnit.
- **GIVEN** a balance with `contractHoursPerWeek: 40`
- **AND** an approved LeaveRequest from Monday 2026-03-02 to Friday 2026-03-06 with no `hours`
- **WHEN** the projection runs
- **THEN** the request contributes 40 hours

#### Scenario: A weekend is not counted
@e2e exclude Pure arithmetic, verified by PHPUnit.
- **GIVEN** a balance with `contractHoursPerWeek: 40`
- **AND** an approved LeaveRequest from Friday 2026-03-06 to Monday 2026-03-09 with no `hours`
- **WHEN** the projection runs
- **THEN** the request contributes 16 hours

#### Scenario: A request that cannot be derived is named and skipped
@e2e exclude Logging behaviour, verified by PHPUnit.
- **GIVEN** a balance with `contractHoursPerWeek: null`
- **AND** an approved LeaveRequest with no `hours`
- **WHEN** the projection runs
- **THEN** the request contributes 0 hours
- **AND** its id appears in a warning

### Requirement: The accrual job SHALL remain the only writer of entitled hours

`openspec/specs/leave-accrual-job/spec.md` states that "the buy/sell settlement path mutates
`usedHours` on existing rows". It does not. `LeaveBuySellSettlementService` writes
`bovenwettelijkHours` and only `bovenwettelijkHours`, which its own class docblock states and which is
grep verifiable. That sentence is corrected to name `bovenwettelijkHours`.

The accrual job continues to write `entitledHours`, `bovenwettelijkHours` and an initial
`usedHours: 0.0` when it creates a balance. It SHALL NOT write `usedHours` on an existing balance,
which now belongs to the projection service.

#### Scenario: Accrual does not overwrite projected usage
@e2e exclude Backend job behaviour, verified by PHPUnit.
- **GIVEN** an existing balance whose `usedHours` is 40 from approved leave
- **WHEN** the accrual job updates that balance
- **THEN** `usedHours` is still 40


<!-- Synced from openspec/changes/leave-against-a-department-schedule/specs/, which shipped without
     reaching this file. The @spec tags in lib/ and src/ already point here. -->

### Requirement: A leave type SHALL be an administered object, not an enum (REQ-LVM-T01)

humaniq SHALL provide a `LeaveType` schema in register `hrmq` carrying a `code`, a
label, `drawsFromBalance`, `requiresReason`, `requiresDocument`, an optional
`maxNoticeDays` and an `active` flag. `LeaveRequest.leaveType` SHALL reference a
`LeaveType` rather than carry an enum value, and an existing request SHALL resolve to
the type whose `code` equals the value it already holds, so no request loses its
meaning.

A type with `active` false SHALL NOT be offered on a new request and SHALL still
resolve on requests that already carry it.

Candidate C-parties-and-contacts-11 (`parties-and-contacts.tsv:15`). The lane author
rated it **`not`, reading dossiq**, with the note "this is humaniq's job, not the
case system's". It is admitted here under decision D17 and labelled under decision
D21. Driven passer: huly, `plugins/hr` `RequestType` and `Request`.

#### Scenario: A new leave kind is added without a release
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts
- **GIVEN** an employer that needs "zorgverlof"
- **WHEN** an administrator writes a `LeaveType` with that code
- **THEN** it is offered on new requests, and no schema was changed to allow it

#### Scenario: A type that draws no balance posts nothing
@e2e exclude balance arithmetic through the projection service, covered by LeaveBalanceProjectionServiceTest::testATypeThatDrawsNoBalancePostsNothing with the drawing type beside it as the control
- **GIVEN** a `LeaveType` with `drawsFromBalance` false
- **WHEN** a request of that type is approved
- **THEN** the `LeaveBalance` behind it is unchanged

#### Scenario: A retired type keeps old requests readable
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts (the type round trip), LeaveTypeAndScheduleTest::testARetiredTypeIsNotOfferedButStillResolves (the resolution)
- **GIVEN** approved requests carrying a type later set to `active` false
- **WHEN** those requests are read
- **THEN** they still resolve their type and its label, and the type is absent from
  the picker on a new request

### Requirement: A type's own conditions SHALL be enforced on submission (REQ-LVM-T02)

Submitting a `LeaveRequest` SHALL be refused when its type requires a reason and none
is given, when its type requires a document and none is attached, or when its type
declares `maxNoticeDays` and the request starts further ahead than that. The refusal
SHALL name the condition that failed.

These conditions SHALL be checked on `submit` only. A request may be saved as a draft
without them.

#### Scenario: A calamiteitenverlof without a reason does not submit
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts
- **GIVEN** a type with `requiresReason` true and a request with an empty reason
- **WHEN** the request is submitted
- **THEN** the transition is refused and the refusal names the missing reason

#### Scenario: A draft is not judged yet
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts
- **GIVEN** the same request left in `draft`
- **WHEN** it is saved
- **THEN** it is stored with no refusal

### Requirement: An org unit's leave SHALL be readable as one schedule over a period (REQ-LVM-S01)

humaniq SHALL answer, for one org unit and a date range, every `LeaveRequest` in
status `submitted` or `approved` that holds a member of that unit, composed on read
from the requests themselves. No schedule object SHALL be stored.

An entry SHALL carry the employee, the period, the status and the type. A reader who
may not see a given request SHALL see that the person is unavailable in that period
and nothing further, matching the boundary `leave-calendar-nc` draws for the
Nextcloud calendar. Which requests a reader may see SHALL be decided by the existing
team-scope rules, unchanged.

#### Scenario: August is read in one view
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts
- **GIVEN** an afdeling of six with four approved and two submitted requests in
  August
- **WHEN** the schedule is asked for that unit and that month
- **THEN** all six appear with their periods and statuses

#### Scenario: A withdrawn request leaves the schedule at once
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts
- **GIVEN** a submitted request showing on the schedule
- **WHEN** it is withdrawn
- **THEN** the next read does not contain it, with no sync in between

#### Scenario: A manager does not see another department through it
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts (the anonymous refusal on both endpoints), LeaveTypeAndScheduleTest::testARequestTheReaderMayNotSeeIsRedactedToUnavailability (the redaction)
- **GIVEN** a manager scoped to one org unit
- **WHEN** they open the schedule for a unit they are not scoped to
- **THEN** they receive no request detail, and the existing team-scope rules decide
  it rather than a rule of this view's own

### Requirement: Thin coverage SHALL warn the approver, and SHALL NOT refuse (REQ-LVM-S02)

An org unit MAY carry an administered minimum number of members present, per weekday.
When approving a request would take the unit below that minimum on any date in its
period, humaniq SHALL warn the approver before the transition, naming the dates, the
number present after approval, the minimum, and who else is away on those dates.

The approver SHALL be able to approve regardless. When they do, the warning they
accepted SHALL be recorded on the request, so the decision has a trail. Approval
SHALL NOT be refused on coverage grounds.

#### Scenario: The first week of August is said before it happens
@e2e exclude the warning is composed server-side from an administered minimum; covered by LeaveTypeAndScheduleTest::testThinCoverageIsWarnedWithDatesCountsAndNames, with the unwarned unit beside it as the control
- **GIVEN** an afdeling with a Monday minimum of two, one member already away, and a
  pending request covering that Monday
- **WHEN** the approver opens the approval
- **THEN** they are shown the date, one present against a minimum of two, and the
  name of the colleague already away

#### Scenario: The manager who knows better is not blocked
@e2e exclude the approval is the transition leave-management already covers; that coverage never refuses is asserted by LeaveCoverageService returning a warning and no refusal path, LeaveTypeAndScheduleTest::testThinCoverageIsWarnedWithDatesCountsAndNames
- **GIVEN** that warning
- **WHEN** the approver approves anyway
- **THEN** the request reaches `approved` and the accepted warning is recorded on it

#### Scenario: No minimum means no warning
@e2e exclude covered by LeaveTypeAndScheduleTest::testNoMinimumMeansNoWarning and testAZeroMinimumIsNoMinimum
- **GIVEN** an org unit with no administered minimum
- **WHEN** any request for it is approved
- **THEN** no coverage warning is produced

### Requirement: Approving from the schedule SHALL drive the existing transitions (REQ-LVM-S03)

The schedule view SHALL offer approve and reject, and SHALL call the transitions
already declared for `LeaveRequest`: `approve` and `reject` from `submitted`, guarded
by the existing `NoSelfApprovalGuard`. No transition SHALL be added, renamed or
re-guarded to serve this view, and the `LeaveApproval` queue SHALL keep working
unchanged beside it.

#### Scenario: Two surfaces, one lifecycle
@e2e tests/e2e/spec-coverage/leave-department-schedule.spec.ts
- **WHEN** the manifest's `lifecycleActions.transitions` are compared to the
  `x-openregister-lifecycle` in the leave register fragment
- **THEN** they match action-for-action, with no action added for the schedule

#### Scenario: The self-approval guard still holds on the new surface
@e2e exclude the guard is unchanged and already covered by NoSelfApprovalGuardTest and the hours-process approval journey; this change adds no surface-specific guard to test
- **GIVEN** an approver looking at their own request on the schedule
- **WHEN** they attempt to approve it
- **THEN** the existing guard refuses it, exactly as it does from the queue

## Implementation Notes (2026-07-12)

Verified at HEAD against the shipped code:
- `remainingHours` uses OpenRegister's `x-openregister-calculations` vocabulary (`prop`/`+`/`-`, `materialise: true`), confirmed against `CalculationAnnotationValidator`'s `VALID_OPS`/`VALID_TYPES` in the sibling openregister checkout.
- `NlLeaveChecks` is single-object (`RuleEngine` predicates carry `context = {jurisdiction}` only, no cross-object lookup) — `contractHoursPerWeek` is a denormalised snapshot on `LeaveBalance`, not a live read of `EmploymentContract`.
- The seeded audit was verified by running `RuleEngine::evaluate()` directly against the three seeded `LeaveBalance` objects (standalone PHP, no live Nextcloud/OpenRegister instance in the build environment): employee-jansen reports zero violations, employee-devries reports `nl-verlof-saldo-niet-negatief`, employee-bakker reports `nl-verlof-wettelijk-minimum` — exactly the intended set.
