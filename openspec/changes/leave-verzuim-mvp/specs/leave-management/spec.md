# Spec: leave-management

**Status:** in-progress
**Scope:** hrmq
**Kind:** config (declarative schema + manifest + corpus data; check methods are the app's established rule-corpus exception)

**OpenSpec changes**
- `leave-verzuim-mvp` (2026-07-12)

## Purpose

Make the existing `LeaveRequest` workflow visible and complete: the ADR-001 menu-5 `Verlof & verzuim` surface with request/approval/detail pages driving the already-shipped declarative lifecycle, a new `LeaveBalance` schema with a declaratively calculated remaining-hours figure and the BW 7:640a expiry date, and three machine-checkable statutory leave rules (BW art. 7:634 minimum, non-negative balance, 1-July vervaltermijn) in a new `labour` corpus file with a `NlLeaveChecks` provider.

## Requirements

### REQ-LVM-001: The manifest SHALL add the `Verlof & verzuim` menu group with the leave pages

`src/manifest.json` gains menu group `VerlofVerzuimGroup` (label "Verlof & verzuim", icon `CalendarClock`, order 105 — the frozen ADR-001 menu-5 top-level entry, so no ADR amendment is needed) with children `LeaveRequests`, `LeaveApproval`, `LeaveBalances` (and `SickLeaveCases`, spec'd in `verzuim-wvp`). Pages: `LeaveRequests` (index over `LeaveRequest`: columns `employeeId`, `leaveType`, `startDate`, `endDate`, `hours`, `status`; filters `status`, `leaveType`; sort `startDate` desc) and `LeaveApproval` (index over `LeaveRequest` pre-filtered `defaultFilters: {status: "submitted"}`, columns `employeeId`, `leaveType`, `startDate`, `endDate`, `submittedAt`, `status`, sort `submittedAt` asc — mirroring `TimesheetApproval` exactly). deepLinks for `LeaveRequest` and `LeaveBalance` are registered. The manifest MUST validate against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Approval queue shows only submitted requests
@e2e exclude declarative index filtering is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** LeaveRequest objects in statuses `draft`, `submitted`, and `approved`
- **WHEN** the `LeaveApproval` page loads
- **THEN** only the `submitted` request is listed, oldest submission first

### REQ-LVM-002: `LeaveRequestDetail` SHALL drive the EXISTING lifecycle — no new or altered transitions

`LeaveRequestDetail` (detail over `LeaveRequest`, route `/leave-requests/:id`) carries: a "Request" data widget (excluding `employeeId` — the Related panel resolves the requesting Employee by name), an "Approval" data widget (`status`, `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`), a related widget, a files widget ("Supporting documents"), an audit-history sidebar tab, and `lifecycleActions` exposing **exactly** the transitions already declared in `hr-leave.json`: `submit` (from `draft`|`rejected`), `approve` (from `submitted`), `reject` (from `submitted`) — guarded server-side by the existing `NoSelfApprovalGuard`. The `LeaveRequest` schema, its lifecycle, and the guard are NOT modified by this change.

#### Scenario: Detail page walks the existing workflow
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
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
- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads payroll.json AND labour.json without error and reports the three new rules as enforced (each has a CheckProvider predicate)

### REQ-LVM-005: `NlLeaveChecks` SHALL enforce the three leave rules as single-object predicates

New auto-discovered provider `lib/Standards/Checks/NlLeaveChecks.php` (implements `CheckProvider`) registering, under object type `LeaveBalance`:
1. **Wettelijk minimum** — violation when `contractHoursPerWeek` is present and `entitledHours < 4 × contractHoursPerWeek`; passes vacuously when the snapshot is null (not decidable from the object).
2. **Saldo niet negatief** — violation when `usedHours > entitledHours + bovenwettelijkHours`.
3. **Vervaltermijn** — violation when `entitledHours > 0` and `expiryDate` differs from `<year+1>-07-01` (null included).

Each predicate is side-effect free and keyed by its corpus rule id.

#### Scenario: Under-granted balance flagged
- **GIVEN** the seeded balance for employee-bakker (`contractHoursPerWeek: 36`, `entitledHours: 120`)
- **WHEN** `occ hrmq:rules:audit` runs
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
