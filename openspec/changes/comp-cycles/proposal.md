---
kind: code
---

# Compensation review cycles — salary bands, review rounds, and effective-dated adjustments

## Why

hrmq owns the personnel dossier (Employee, EmploymentContract), the payroll engine that reads
`Employee.grossMonthlySalary`, and — since cao-library (2026-07-14) — a maintained CAO corpus whose
`Cao.payScales` map schaal → minimum maandloon in integer cents. What it has **no** surface for is the
recurring management ritual every employer runs on top of those numbers: a **periodic compensation
review** where managers propose salary adjustments against a band, a second actor approves them, and
the approved figure lands on the employee's compensation on a chosen effective date. Today a salary
change is a bare hand-edit of `Employee.grossMonthlySalary` — no band, no proposal trail, no
separation of duties, no effective-dating, no audit of "who proposed what against which scale".

Per ADR-001 Rule 6 this is explicitly **not** a tenth top-level module: performance and comp anchor on
the personnel dossier as `DETAIL_TAB` rows on `Medewerkers › Functie & comp`. This change adds the
comp-review data model and workflow as a detail surface on the Employee dossier plus a salary-band
reference sub-page under the existing `Personeel` menu — reusing the exact declarative primitives the
app already runs: `x-openregister-lifecycle` state machines (LeaveRequest/Timesheet/Expense
precedent), the read-only `NoSelfApprovalGuard` for separation of duties, and one guarded imperative
service for the effective-dated write (the `PayrollRunService`/`PayrollRunApprovedGuard` pattern).

## What Changes

- **NEW schema `SalaryBand`** (register.d fragment `hr-comp.json`) — a salary band/scale: `bandId`
  natural key, `title`, optional `grade`, `minSalary`/`referenceSalary`/`maxSalary` as **integer
  cents of gross monthly salary** (the same unit as `Employee.grossMonthlySalary` and
  `Cao.payScales`), `currency`, optional `cao`/`caoSchaal` linkage to the CAO corpus, `effectiveFrom`,
  `active`. `x-schema-org: schema:MonetaryAmountDistribution`. A reference surface —
  `allowCreate:false` on its manifest pages is honoured; bands are authored, not hand-typed per row.
- **NEW schema `CompReviewCycle`** — the review **round** container: `name`, `period` (e.g. `2026`),
  `effectiveDate` (the default date approved adjustments take effect), `status`
  (`open`/`closed`), `description`. Groups the round's adjustments; does not itself move through the
  approval states (approval is per employee, D3).
- **NEW schema `CompAdjustment`** — one manager-proposed adjustment per (cycle, employee), carrying the
  review-round lifecycle. Fields: `cycleId` (FK CompReviewCycle), `employeeId` (FK Employee),
  `contractId` (FK EmploymentContract — the active term the change is bound to), `currentSalary` /
  `proposedSalary` (integer cents gross monthly), `targetBandId` (FK SalaryBand), `effectiveDate`,
  `status`, `proposedBy`, `approvedBy`, `rationale`, `appliedAt`.
  `configuration.x-openregister-lifecycle`: `status` field, initial `draft`, terminal `effective`,
  transitions `propose` (draft→proposed), `approve` (proposed→approved, `requires`
  `NoSelfApprovalGuard`), `reject` (proposed→draft), `effectuate` (approved→effective, `requires`
  the new `CompEffectiveDateGuard`).
- **REUSE `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`** — bound to the `approve` transition so the
  approver may not be the proposer (separation of duties, already shipped for leave/timesheet/expense;
  no new guard code for this rule).
- **NEW `lib/Lifecycle/CompEffectiveDateGuard.php`** — read-only, fail-closed
  `LifecycleGuardInterface` on the `effectuate` transition: denies approved→effective unless
  `effectiveDate` is present and ≤ today (mirrors `PayrollRunApprovedGuard`'s container/IAppConfig
  shape; no stamping in the guard).
- **NEW `lib/Service/CompAdjustmentService.php`** — the imperative effective-dating write (guards are
  read-only, so the salary write cannot live in the guard): for an approved, due CompAdjustment it
  validates `proposedSalary` is within the target band, stamps the new `grossMonthlySalary` onto the
  **Employee** (verified: the field the payroll engine reads — `EmploymentContract` carries only
  `hourlyWage`), records `appliedAt` + the source `cycleId`/`effectiveDate` for audit, then drives the
  `effectuate` transition (which re-checks the date guard). Idempotent per adjustment; via
  OpenRegister's ObjectService (the `PayrollRunService` idiom).
- **NEW occ command `hrmq:comp:effectuate --cycle CYCLE [--date YYYY-MM-DD] [--dry-run]`** — batch
  effectuation of all approved adjustments in a cycle whose `effectiveDate` has arrived; per-employee
  outcome (applied / skipped + reason); registered in `appinfo/info.xml`.
- **NEW guarded endpoint `POST /api/comp/effectuate`** (`CompController::effectuate`,
  `#[NoAdminRequired]`) — resolves the posted `adjustmentId` through ObjectService under the caller's
  ambient RBAC first (unknown/unauthorized → 404, the `DocumentController` no-admin-idor pattern),
  refuses non-approved or not-yet-due adjustments, delegates to the service. ONE endpoint, no CRUD
  (ADR-022).
- **NEW `lib/Standards/Checks/CompChecks.php`** — a within-band predicate registered against a corpus
  rule (`comp-adjustment-within-band`): a proposed/approved CompAdjustment's `proposedSalary` must sit
  within its target band's `[minSalary, maxSalary]`; vacuous when `targetBandId` is null.
- **Manifest** — per ADR-001 Rule 6, **no new top-level menu**: `EmployeeDetail` gains an
  `emp-comp-adjustments` object-list detail surface (`filter: {employeeId: "@objectId"}`,
  `rowRoute: CompAdjustmentDetail`); `CompAdjustmentDetail` gains a `lifecycleActions` widget (it now
  has an `x-openregister-lifecycle`) plus an "Effectueren" `api-call` action to the new endpoint; a
  `SalaryBands` reference index + `SalaryBandDetail` and a `CompReviewCycles` index +
  `CompReviewCycleDetail` are added as sub-pages under the existing `Personeel` menu (siblings of
  `CAO's`), all `allowCreate:false` for bands. `npm run check:manifest` passes.

### Non-goals (named exclusions and fast-follows)

- **Salary benchmarking against external market data is OUT OF SCOPE.** Positioning a band or a
  proposal against market percentiles (P25/P50/P75) needs a market-data service (a licensed external
  survey feed the immutable corpus cannot host); no such service exists in the fleet. The band's
  min/reference/max are the employer's own internal structure, not a market benchmark. Benchmarking is
  a named future change gated on that service.
- **Hourly-wage effective-dating onto `EmploymentContract.hourlyWage`** — the review targets the
  monthly-salary compensation the payroll engine consumes (`Employee.grossMonthlySalary`); an hourly
  path writing onto the contract's own wage field is a fast-follow.
- **Bulk/matrix proposal UI, merit-budget modelling, calibration/9-box, letter generation** — the
  advanced comp-planning surface (ADR-001 Rule 6) is out of this MVP; the per-adjustment lifecycle is
  its extension point.
- **Retroactive effective dates that reopen a posted PayrollRun** — retro handling is owned by the
  active `retro-adjustments` change; `CompEffectiveDateGuard` only gates the forward date.

## Capabilities

### New Capabilities

- `comp-cycles`: the SalaryBand reference model, the CompReviewCycle round container, the
  CompAdjustment per-employee proposal with its declarative draft→proposed→approved→effective
  lifecycle (NoSelfApproval + effective-date guards), the imperative effective-dated write onto the
  employee's compensation via occ command and ONE guarded endpoint, the within-band CheckProvider, and
  the Employee-dossier detail surface + salary-band reference sub-pages (ADR-001 Rule 6, no new
  top-level menu).

### Modified Capabilities

<!-- none — Employee/EmploymentContract/Cao schemas are consumed by reference; the payroll engine is untouched (it reads grossMonthlySalary, which this change writes to on effectuation) -->

## Impact

- `lib/Settings/register.d/hr-comp.json` — NEW fragment (SalaryBand, CompReviewCycle, CompAdjustment);
  `lib/Settings/hrmq_register.json` — fragment referenced in the import order.
- `lib/Lifecycle/CompEffectiveDateGuard.php` — NEW (read-only, fail-closed; `PayrollRunApprovedGuard`
  shape); `NoSelfApprovalGuard` — reused, not modified.
- `lib/Service/CompAdjustmentService.php` — NEW (ObjectService idiom per `PayrollRunService`).
- `lib/Command/CompEffectuateCommand.php` — NEW; `appinfo/info.xml` +1 `<command>`.
- `lib/Controller/CompController.php` — NEW; `appinfo/routes.php` +1 route (before the SPA catch-all).
- `lib/Standards/Checks/CompChecks.php` — NEW; the `comp-adjustment-within-band` rule statement added
  to the corpus.
- `src/manifest.json` — EmployeeDetail comp-adjustments object-list; CompAdjustmentDetail
  lifecycleActions + effectuate action; SalaryBands/SalaryBandDetail/CompReviewCycles/
  CompReviewCycleDetail sub-pages under `Personeel`; `npm run check:manifest` passes.
- `tests/Unit/Lifecycle/CompEffectiveDateGuardTest.php`,
  `tests/Unit/Service/CompAdjustmentServiceTest.php`, `tests/Unit/Standards/CompChecksTest.php` — NEW.
