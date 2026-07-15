---
capability: comp-cycles
status: done
built_by: openspec/changes/archive/2026-07-15-comp-cycles
---

# comp-cycles Specification

**Status**: done
**Scope**: hrmq (`kind: code` — reuses the existing declarative lifecycle/guard primitives and the
`Employee.grossMonthlySalary` field the payroll engine already reads; adds zero new payroll-engine
logic and zero external market-data integration)
**OpenSpec changes**:
- [comp-cycles](../../changes/archive/2026-07-15-comp-cycles/) _(archived 2026-07-15)_ —
  compensation review cycles: a `SalaryBand` reference schema (integer-cents internal min/reference/max
  bands, `schema:MonetaryAmountDistribution`, `allowCreate:false`), a `CompReviewCycle` round container
  (`schema:Action`, plain `open → closed`), a per-employee `CompAdjustment` proposal
  (`schema:Action`) carrying a declarative `draft → proposed → approved → effective` lifecycle —
  separation of duties on `approve`/`reject` via the **reused** `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`,
  and a new read-only, fail-closed `OCA\Hrmq\Lifecycle\CompEffectiveDateGuard` gating `effectuate`
  on the adjustment's own `effectiveDate` — the imperative effective-dated write
  (`lib/Service/CompAdjustmentService.php`, the `PayrollRunService` idiom) that validates the
  within-band corpus rule (`comp-adjustment-within-band`, `lib/Standards/Checks/CompChecks.php`),
  converts the adjustment's integer-cents `proposedSalary` to the euro-denominated
  `Employee.grossMonthlySalary` field the payroll engine reads, and drives the `effectuate` transition,
  via one occ command (`hrmq:comp:effectuate --cycle [--date] [--dry-run]`) and ONE guarded endpoint
  (`POST /api/comp/effectuate`, `CompController::effectuate`, RBAC-resolve-first → 404), and the
  `EmployeeDetail` dossier detail surface (`emp-comp-adjustments`) plus `SalaryBands`/`SalaryBandDetail`
  and `CompReviewCycles`/`CompReviewCycleDetail` reference sub-pages under the existing `Personeel`
  menu (ADR-001 Rule 6, no new top-level menu). Market-data benchmarking, hourly-wage-onto-contract
  effective-dating, and bulk/matrix comp-planning UI are named Non-Goals/fast-follows.

## Purpose

hrmq owns the personnel dossier, the payroll engine that reads `Employee.grossMonthlySalary`, and a
maintained CAO corpus, but had no surface for the recurring management ritual every employer runs on
top of those numbers: a periodic compensation review where managers propose salary adjustments against
a band, a second actor approves them, and the approved figure lands on the employee's compensation on
a chosen effective date. Before this change a salary change was a bare hand-edit of
`Employee.grossMonthlySalary` — no band, no proposal trail, no separation of duties, no
effective-dating, no audit of "who proposed what against which scale". Per ADR-001 Rule 6 this is not
a tenth top-level module: compensation anchors on the personnel dossier as a `Medewerkers › Functie &
comp` detail surface, reusing the exact declarative primitives the app already runs
(`x-openregister-lifecycle` state machines, the read-only `NoSelfApprovalGuard`, and one guarded
imperative service for the effective-dated write — the `PayrollRunService`/`PayrollRunApprovedGuard`
pattern). External market-data benchmarking (positioning a band or proposal against P25/P50/P75
survey percentiles) needs a licensed external feed that does not exist in the fleet, so it is an
explicit Non-Goal, not implied scope.

## ADDED Requirements

### Requirement: A SalaryBand reference schema SHALL model pay bands in integer cents of gross monthly salary (REQ-COMP-001)

The `hrmq` register SHALL gain a `SalaryBand` schema (`x-schema-org: schema:MonetaryAmountDistribution`)
with a `bandId` natural key, `title`, optional `grade`, `minSalary` / `referenceSalary` / `maxSalary`
expressed as **integer cents of gross monthly salary** (the same unit as `Employee.grossMonthlySalary`
and `Cao.payScales`), a `currency` defaulting to `EUR`, optional `cao` / `caoSchaal` links into the
maintained CAO corpus, `effectiveFrom` and `active`. A band SHALL represent only the employer's
internal min/reference/max structure — external market benchmarking is out of scope. Every SalaryBand
manifest surface SHALL keep `allowCreate: false` (bands are authored reference data, not hand-typed per
row).

#### Scenario: A band carries an ordered internal salary range in cents
- **GIVEN** a SalaryBand `A` with `minSalary` 300000, `referenceSalary` 360000, `maxSalary` 420000 and `currency` EUR
- **WHEN** the band is read
- **THEN** the three figures are integer cents of gross monthly salary, `min ≤ reference ≤ max`, and no market-benchmark field is present

#### Scenario: A band may reference a CAO scale
- **GIVEN** a SalaryBand with `cao` = `cao-generiek` and `caoSchaal` = `A`
- **WHEN** the band is read
- **THEN** it links the CAO corpus entry whose `payScales.A` minimum maandloon the band's `minSalary` is at or above

### Requirement: A CompReviewCycle SHALL group a periodic review round (REQ-COMP-002)

The `hrmq` register SHALL gain a `CompReviewCycle` schema (`x-schema-org: schema:Action`) with `name`,
`period`, `effectiveDate` (the default date the round's approved adjustments take effect), a `status`
of `open` or `closed`, and `description`. The cycle SHALL be a grouping container only: it SHALL NOT
carry the approval lifecycle (approval happens per adjustment, REQ-COMP-003), so a cycle MAY close while
still holding adjustments in any state.

#### Scenario: An open cycle groups its adjustments
- **GIVEN** an `open` CompReviewCycle for `period` 2026 with `effectiveDate` 2026-07-01
- **WHEN** a CompAdjustment is created with that cycle's id
- **THEN** the adjustment belongs to the cycle and the cycle's own status stays `open` (it is not driven by the adjustment's state)

### Requirement: CompAdjustment SHALL carry the declarative draft→proposed→approved→effective review-round lifecycle (REQ-COMP-003)

The `hrmq` register SHALL gain a `CompAdjustment` schema (`x-schema-org: schema:Action`) with `cycleId`,
`employeeId`, `contractId` (the active EmploymentContract term the change is bound to), `currentSalary`
and `proposedSalary` (integer cents gross monthly), `targetBandId`, `effectiveDate`, `status`,
`proposedBy`, `approvedBy`, `rationale` and `appliedAt`. Its `configuration.x-openregister-lifecycle`
SHALL declare `field: status`, `initial: draft`, `terminal: [effective]`, and transitions `propose`
(draft→proposed), `approve` (proposed→approved), `reject` (proposed→draft) and `effectuate`
(approved→effective) — mirroring the `x-openregister-lifecycle` shape already used by LeaveRequest. No
bespoke Vue view or PHP status-writing SHALL implement these state changes.

#### Scenario: A proposal advances through the review-round states
- **GIVEN** a CompAdjustment in status `draft`
- **WHEN** `propose` then `approve` then `effectuate` are executed in order
- **THEN** the status moves draft → proposed → approved → effective, and `effective` is terminal (no further transition is offered)

#### Scenario: A rejected proposal returns to draft for revision
- **GIVEN** a CompAdjustment in status `proposed`
- **WHEN** `reject` is executed
- **THEN** the status returns to `draft` and the proposal may be corrected and re-proposed

### Requirement: The approve transition SHALL enforce separation of duties via the reused NoSelfApprovalGuard (REQ-COMP-004)

The `approve` transition SHALL declare `requires: "OCA\\Hrmq\\Lifecycle\\NoSelfApprovalGuard"` so the
approver may not be the proposer, reusing the guard already shipped for LeaveRequest / Timesheet /
Expense. This change SHALL NOT add duplicate approver-not-equal-proposer logic.

#### Scenario: The proposer cannot approve their own adjustment
- **GIVEN** a CompAdjustment `proposed` by uid `alice`
- **WHEN** `alice` attempts the `approve` transition
- **THEN** the NoSelfApprovalGuard denies it and the status stays `proposed`

#### Scenario: A different manager may approve
- **GIVEN** a CompAdjustment `proposed` by uid `alice`
- **WHEN** uid `bob` executes `approve`
- **THEN** the guard allows it and the status becomes `approved`

### Requirement: The effectuate transition SHALL be gated by a read-only, fail-closed effective-date guard (REQ-COMP-005)

`lib/Lifecycle/CompEffectiveDateGuard.php` SHALL implement `LifecycleGuardInterface` in the
`PayrollRunApprovedGuard` shape and SHALL deny the `approved → effective` transition unless the
adjustment's `effectiveDate` is present and is on or before today. It SHALL be **read-only** (it never
writes the salary — the write is imperative, REQ-COMP-006) and fail-closed (an empty or malformed
`effectiveDate` denies). It SHALL be bound via `transitions.effectuate.requires`.

#### Scenario: A future-dated adjustment cannot be effectuated yet
- **GIVEN** an `approved` CompAdjustment whose `effectiveDate` is tomorrow
- **WHEN** the `effectuate` transition is attempted
- **THEN** CompEffectiveDateGuard denies it with a clear message and the status stays `approved`

#### Scenario: A due adjustment passes the date gate
- **GIVEN** an `approved` CompAdjustment whose `effectiveDate` is today or earlier
- **WHEN** the `effectuate` transition is attempted
- **THEN** the date guard allows the transition to `effective`

### Requirement: The effective-dated write SHALL be an imperative service landing on the employee's compensation, via occ command and ONE guarded endpoint (REQ-COMP-006)

Because lifecycle guards are read-only, `lib/Service/CompAdjustmentService.php` SHALL perform the
effective-dated write: for an `approved` adjustment whose `effectiveDate` has arrived it SHALL validate
`proposedSalary` is within the target band (REQ-COMP-007), write `grossMonthlySalary = proposedSalary`
onto the **Employee** (verified: the field the payroll engine reads — `EmploymentContract` carries only
`hourlyWage`), stamp `appliedAt`, and drive the `effectuate` transition (re-running the date guard). It
SHALL refuse non-approved, not-yet-due or out-of-band adjustments and SHALL be idempotent per
adjustment (an already-`effective` adjustment is a no-op). `occ hrmq:comp:effectuate --cycle CYCLE
[--date YYYY-MM-DD] [--dry-run]` SHALL batch-effectuate a cycle's due adjustments with a per-employee
outcome, registered in `appinfo/info.xml`. `POST /api/comp/effectuate` (`CompController::effectuate`,
`#[NoAdminRequired]`) SHALL resolve the posted `adjustmentId` through ObjectService under the caller's
ambient RBAC before any work (unknown/unauthorized collapse to 404, the DocumentController
no-admin-idor pattern), refuse non-approved/not-due adjustments (400), and delegate to the service —
ONE endpoint, no CRUD (ADR-022). The `contractId` binds the change to the active contract term; writing
onto `EmploymentContract.hourlyWage` for hourly employees is a named fast-follow. Verified against HEAD:
`Employee.grossMonthlySalary` is stored as a plain euro-denominated float (e.g. seeded as `3800.00`),
NOT integer cents, so the write converts `proposedSalary` cents to euros (`cents / 100`) — the same
boundary `PayrollRunService::euros()` and `NlCaoChecks::minimumloonSchaalSatisfied()` already cross.

#### Scenario: A due approved adjustment writes the new salary and becomes effective
- **GIVEN** an `approved` CompAdjustment with `proposedSalary` 360000 within its target band and `effectiveDate` today for an employee whose `grossMonthlySalary` is 330000
- **WHEN** `occ hrmq:comp:effectuate --cycle <id>` (or the endpoint) runs
- **THEN** the employee's `grossMonthlySalary` becomes 360000, the adjustment's `appliedAt` is stamped, and its status is `effective`

#### Scenario: An unauthorized adjustment id never reaches the write
- **GIVEN** a caller whose RBAC cannot see adjustment X (or X does not exist)
- **WHEN** they POST `/api/comp/effectuate` with `adjustmentId: X`
- **THEN** the response is 404 and no salary is written and no transition occurs

#### Scenario: Re-running effectuation is a no-op
- **GIVEN** an adjustment already in status `effective`
- **WHEN** `occ hrmq:comp:effectuate --cycle <id>` runs again
- **THEN** the outcome skips it with a reason and the employee's `grossMonthlySalary` is unchanged

### Requirement: A within-band CheckProvider SHALL flag out-of-band proposals (REQ-COMP-007)

`lib/Standards/Checks/CompChecks.php` SHALL register the `comp-adjustment-within-band` predicate: for a
CompAdjustment in `proposed` / `approved` / `effective`, `proposedSalary` MUST be within the target
`SalaryBand`'s `[minSalary, maxSalary]`. The rule SHALL be **vacuous** when `targetBandId` is null (a
band-less proposal raises no mandatory violation, the `payScalesVerified` advisory-until-confirmed
precedent), and SHALL load the band via the audit context rather than per-object IO (the
`payroll.runsById` enrichment precedent).

#### Scenario: A proposal above the band maximum violates
- **GIVEN** a `proposed` CompAdjustment with `proposedSalary` 500000 and a target band whose `maxSalary` is 420000
- **WHEN** the audit runs
- **THEN** a `comp-adjustment-within-band` violation is reported for that adjustment

#### Scenario: A band-less proposal is out of scope
- **GIVEN** a CompAdjustment with `targetBandId` null
- **WHEN** the audit runs
- **THEN** the within-band rule reports no violation for it

### Requirement: The comp surfaces SHALL land on the personnel dossier and a Personeel sub-page, with NO new top-level menu (REQ-COMP-008)

Per ADR-001 Rule 6, `src/manifest.json` SHALL surface compensation without adding a tenth top-level
menu: `EmployeeDetail` SHALL gain an `emp-comp-adjustments` object-list widget
(`filter: {employeeId: "@objectId"}`, `rowRoute: CompAdjustmentDetail`) — the `Medewerkers › Functie &
comp` detail surface; `CompAdjustmentDetail` SHALL gain a `lifecycleActions` widget (legitimate because
CompAdjustment carries an `x-openregister-lifecycle`) plus an "Effectueren" `api-call` action
(`POST /api/comp/effectuate`, `params: {adjustmentId: "@objectId"}`, confirm, Dutch success/error
messages); and `SalaryBands`/`SalaryBandDetail` + `CompReviewCycles`/`CompReviewCycleDetail` SHALL be
added as sub-pages under the existing `Personeel` menu (siblings of `CAO's`), with SalaryBand pages
kept `allowCreate: false`. `npm run check:manifest` MUST pass.

#### Scenario: Compensation adds no tenth top-level menu
- **WHEN** the manifest menu is read after this change
- **THEN** the top-level menu count is unchanged and the comp surfaces appear as an EmployeeDetail widget and as sub-pages under the existing `Personeel` menu

#### Scenario: The dossier lists an employee's adjustments and effectuates a due one
@e2e exclude declarative widget + action wiring is covered by the shared CnPageRenderer library tests; the app-level e2e suite does not yet exist (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** an EmployeeDetail page for an employee with an approved, due CompAdjustment
- **WHEN** the user opens the comp-adjustments surface and executes "Effectueren" with confirm
- **THEN** the endpoint effectuates the adjustment and the refreshed page shows it as `effective` with the new salary
