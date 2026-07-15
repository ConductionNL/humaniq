# Design — comp-cycles

## Context

**Verified against HEAD 2026-07-15.** This change adds compensation-review cycles as a detail surface
on the personnel dossier. It consumes existing schemas by reference and reuses existing declarative
primitives; it invents no new state-machine engine and no new guard *pattern*.

Real code studied (all read at HEAD):

- **`lib/Settings/register.d/hr-objects.json`** — `Employee.grossMonthlySalary` (`number`) is the
  compensation figure; `taxTableColor`, `loonheffingskortingToegepast`, `dateOfBirth`,
  `nextcloudUserId` are siblings. `EmploymentContract` (required `employeeId`, `type`, `startDate`)
  carries `startDate`/`endDate`, `hoursPerWeek`, **`hourlyWage`** (its only monetary field — there is
  **no** monthly-salary field on the contract), and the cao-library additions `cao`/`caoSchaal`
  (referencing the CAO corpus by id). The payroll engine reads `Employee.grossMonthlySalary`; that is
  therefore where an effective-dated salary change must land to flow into pay (D5).
- **`lib/Settings/register.d/hr-cao.json`** — `Cao.payScales` is a map of schaal → **minimum maandloon
  in integer cents**, gated `mandatory` only when `payScalesVerified` is true. Salary bands adopt the
  same unit (integer cents of gross monthly salary) and can reference a `cao`/`caoSchaal` so a band is
  a superset (min/reference/max) of the CAO's single minimum figure.
- **`lib/Settings/register.d/hr-leave.json`** — the canonical `configuration.x-openregister-lifecycle`
  shape: `{field, initial, terminal, transitions:{name:{from:[...], to, requires?, description}}}`,
  with `approve`/`reject` carrying `requires: "OCA\\Hrmq\\Lifecycle\\NoSelfApprovalGuard"`. Copied
  verbatim in structure for CompAdjustment (D2).
- **`lib/Lifecycle/NoSelfApprovalGuard.php` / `PayrollRunApprovedGuard.php`** — the two guard shapes.
  `NoSelfApprovalGuard` is stateless (approver ≠ requester) and is **reused as-is** on the `approve`
  transition. `PayrollRunApprovedGuard` shows the stateful shape (`ContainerInterface` for lazy
  `ObjectService`, `IAppConfig` for the register slug, `GuardResult::allow()/deny()`, fail-closed on
  empty/dangling refs, no stamping) — `CompEffectiveDateGuard` mirrors it (D4).
- **`src/manifest.json`** — `EmployeeDetail` already hosts child object-list widgets
  (`emp-contracts`, `emp-timesheets`, `emp-payslips`, `emp-reviews`, …) filtered
  `{employeeId: "@objectId"}` with `rowRoute`/`viewAllRoute`; `EmploymentContractDetail` shows the
  `api-call` page-action shape (`generate-arbeidsovereenkomst` → `POST /api/documents/generate`,
  `params:{contractId:"@objectId"}`, `confirm`, success/error messages). The comp surfaces reuse both
  (D6).
- **`openspec/architecture/adr-001-information-architecture.md` Rule 6** — performance & comp are
  `DETAIL_TAB` rows on the personnel dossier; there is no 10th top-level menu. Salary-band reference
  data lands as a sub-page under the existing `Personeel` menu (sibling of `CAO's`), and the
  per-employee adjustments as a detail surface on `EmployeeDetail` (D6).

## Goals / Non-Goals

**Goals:** model salary bands/scales; a periodic review round in which managers propose per-employee
adjustments against a band; a two-actor approval with separation of duties; effective-dating the
approved figure onto the compensation the payroll engine reads; all reusing the app's declarative
lifecycle + guard + object-list primitives; placement per ADR-001 Rule 6 (no new top-level menu).

**Non-Goals (from the proposal, binding):** external market-data **benchmarking** (needs a market-data
service — out of scope, named future change); hourly-wage effective-dating onto
`EmploymentContract.hourlyWage` (fast-follow); merit-budget/matrix/calibration/9-box UI; comp letter
generation; retroactive dates that reopen a posted PayrollRun (owned by `retro-adjustments`).

## Decisions

### D1 — Three schemas: a reference band, a round container, and the per-employee proposal

- **`SalaryBand`** (reference, `schema:MonetaryAmountDistribution`): `bandId` (natural key),
  `title`, `grade` (nullable), `minSalary`/`referenceSalary`/`maxSalary` — **integer cents of gross
  monthly salary**, same unit as `Employee.grossMonthlySalary` and `Cao.payScales` — `currency`
  (default `EUR`), `cao`/`caoSchaal` (nullable links into the CAO corpus), `effectiveFrom`, `active`.
  A band is the employer's internal structure, authored (not hand-typed per row): `allowCreate:false`
  on its pages.
- **`CompReviewCycle`** (`schema:Action`): the **round** — `name`, `period`, `effectiveDate`
  (default date the round's approved adjustments take effect), `status` (`open`/`closed`),
  `description`. A grouping container; approval happens on the individual adjustments, not the cycle
  (D3), so the cycle carries no approval lifecycle — just `open`/`closed`.
- **`CompAdjustment`** (`schema:Action`): one proposal per (cycle, employee) — `cycleId`,
  `employeeId`, `contractId` (the active EmploymentContract term the change is bound to),
  `currentSalary`/`proposedSalary` (integer cents gross monthly), `targetBandId`, `effectiveDate`,
  `status`, `proposedBy`, `approvedBy` (nullable), `rationale`, `appliedAt` (nullable). This is the
  schema that carries the review-round lifecycle (D2).

### D2 — The review-round lifecycle lives on CompAdjustment, declaratively (draft→proposed→approved→effective)

`CompAdjustment` carries `configuration.x-openregister-lifecycle` in the exact hr-leave shape:

```
field: status,  initial: draft,  terminal: [effective]
transitions:
  propose:    { from: [draft],    to: proposed  }                     # manager submits
  approve:    { from: [proposed], to: approved,  requires: NoSelfApprovalGuard }
  reject:     { from: [proposed], to: draft,     requires: NoSelfApprovalGuard }  # back for revision
  effectuate: { from: [approved], to: effective, requires: CompEffectiveDateGuard }
```

The state names are the brief's `draft → proposed → approved → effective`. `effective` is terminal.
The transitions are pure OpenRegister declarative machinery — no bespoke Vue or PHP status-writing.
`reject` returns to `draft` so a proposal can be corrected and re-proposed (the LeaveRequest
rejected→submitted precedent, adapted).

### D3 — The lifecycle is per-adjustment, not per-cycle (mirrors LeaveRequest)

The brief names a four-state review-round lifecycle. A whole cycle cannot be "approved" in one act — a
round holds many employees, each proposal judged on its own merit and each approved by a possibly
different manager. So the four states live on the per-employee `CompAdjustment` (the LeaveRequest /
Timesheet / Expense per-record precedent), and `CompReviewCycle` stays a light `open`/`closed`
container. This keeps separation-of-duties meaningful (it is enforced per proposal) and lets a cycle
close with a mix of effective and still-draft rows, each auditable.

### D4 — CompEffectiveDateGuard: read-only, fail-closed, gates approved→effective on the date only

`lib/Lifecycle/CompEffectiveDateGuard.php` implements `LifecycleGuardInterface` in the
`PayrollRunApprovedGuard` shape (constructed with `ContainerInterface` + `IAppConfig`, though for the
date check it needs neither a load nor the register — it reads `effectiveDate` off the payload). It
denies the `effectuate` transition unless `effectiveDate` is present **and** ≤ today, returning a
Dutch `GuardResult::deny(...)` message otherwise; fail-closed (empty/malformed date denies). It is
**read-only** — it never writes the salary; the write is imperative (D5). Bound via
`transitions.effectuate.requires`. Separation of duties on `effectuate` is not re-checked here (it was
already enforced at `approve` by `NoSelfApprovalGuard`).

### D5 — The effective-dated write is imperative (guards can't write); it lands on Employee.grossMonthlySalary

Guards are read-only per OpenRegister's contract, so the salary write cannot live in
`CompEffectiveDateGuard`. `lib/Service/CompAdjustmentService.php` owns it, via container-resolved
`OCA\OpenRegister\Service\ObjectService` (the `PayrollRunService` idiom):

1. Resolve the approved CompAdjustment; refuse if `status !== approved` or `effectiveDate > today`
   (the same predicate the guard enforces — belt and braces).
2. Validate `proposedSalary` is within the target `SalaryBand`'s `[minSalary, maxSalary]`
   (the `comp-adjustment-within-band` rule, D7) — refuse out-of-band with a clear reason.
3. **Write `grossMonthlySalary = proposedSalary` onto the Employee.** Verified decision: the payroll
   engine reads `Employee.grossMonthlySalary` and `EmploymentContract` has no monthly-salary field
   (only `hourlyWage`), so the employee record is the only target that flows into pay. The adjustment
   stores `contractId` so the change stays *bound to* the active contract term (the eligibility and
   audit anchor the brief calls "onto the contract"); writing onto the contract's own `hourlyWage`
   for hourly employees is the named fast-follow.
4. Stamp `appliedAt = now()` on the adjustment and drive the `effectuate` transition
   (approved→effective) through OpenRegister — which re-runs `CompEffectiveDateGuard`, so the state
   machine and the write can never disagree.

Idempotent per adjustment: an already-`effective` adjustment is a no-op (skipped with a reason). The
service never edits any other status and never touches a PayrollRun.

### D6 — Manifest surfacing per ADR-001 Rule 6: detail surface on the dossier, band reference sub-page, NO new top-level menu

- **`EmployeeDetail`** gains an `emp-comp-adjustments` object-list widget (the `emp-contracts` shape):
  `{register:hrmq, schema:CompAdjustment, filter:{employeeId:"@objectId"}, rowRoute:CompAdjustmentDetail,
  viewAllRoute:CompReviewCycles, columns:[cycleId, proposedSalary, effectiveDate, status]}`. This is
  the `Medewerkers › Functie & comp` detail surface Rule 6 mandates.
- **`CompAdjustmentDetail`** gets a `lifecycleActions` widget (legitimate now — CompAdjustment *does*
  carry an `x-openregister-lifecycle`, unlike PayrollRun) rendering propose/approve/reject
  transitions, **plus** an `api-call` page action "Effectueren" →
  `POST /api/comp/effectuate {adjustmentId:"@objectId"}` (confirm, Dutch success/error) for the
  imperative effectuate path (the write cannot be a bare declarative transition — D5).
- **`SalaryBands` (index) + `SalaryBandDetail`** and **`CompReviewCycles` (index) +
  `CompReviewCycleDetail`** are added as sub-pages under the **existing `Personeel` menu** (siblings of
  `CAO's`, itself a reference sub-page). No 10th top-level menu is created (Rule 6 / the 9-item cap).
  SalaryBand pages keep `allowCreate:false`. `CompReviewCycleDetail` shows an `open-form` action
  "Aanpassing voorstellen" seeding a draft CompAdjustment scoped to the cycle. `npm run check:manifest`
  gates all of it.

### D7 — The within-band rule is a CheckProvider, not a declarative calculation

`lib/Standards/Checks/CompChecks.php` registers the `comp-adjustment-within-band` predicate: for a
CompAdjustment in `proposed`/`approved`/`effective`, `proposedSalary` must be within the target band's
`[minSalary, maxSalary]`; **vacuous** when `targetBandId` is null (a band-less proposal raises no
mandatory violation, the `payScalesVerified` precedent). It loads the referenced SalaryBand via the
audit context (the glpost/`payroll.runsById` enrichment precedent) rather than per-object IO. This is
a cross-object predicate — exactly what schema-declarative calculation cannot express — so it is a
CheckProvider, not an `x-openregister-calculations` entry.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Band / cycle / adjustment data | declarative schemas (`hr-comp.json`) | ADR-031 default; ADR-022 (objects live in the `hrmq` register) |
| Review-round state machine (draft→proposed→approved→effective) | declarative `x-openregister-lifecycle` on CompAdjustment | the LeaveRequest/Timesheet/Expense precedent — no bespoke status code |
| Separation of duties (proposer ≠ approver) | declarative `requires: NoSelfApprovalGuard` on `approve` | rule already shipped; reused verbatim |
| Effective-date gate on effectuate | `CompEffectiveDateGuard` (read-only LifecycleGuard) | a date precondition the state machine cannot express; PayrollRunApprovedGuard shape |
| Effective-dated salary write onto Employee | **imperative service** via ObjectService | guards are read-only; a cross-object write is exactly the PayrollRunService case |
| Effectuate trigger | occ command + ONE guarded endpoint | operator/manager demand; the payroll-engine D6 precedent |
| Within-band validation | corpus rule + CheckProvider predicate | cross-object predicate — the app's established exception |
| Comp surfaces (bands, cycles, adjustments) | declarative manifest pages/widgets | ADR-031 default; ADR-001 Rule 6 placement |

## Seed Data (ADR-001)

Add to `hr-seed.json` a minimal, self-consistent set exercising the whole path: two `SalaryBand`s
(e.g. band `A` 300000–420000 cents referencing `cao-generiek` schaal `A`; band `B` 420000–600000),
one `open` `CompReviewCycle` (`period:2026`, `effectiveDate:2026-07-01`), and one `draft`
`CompAdjustment` for the seeded employee (`currentSalary` = that employee's seeded
`grossMonthlySalary`, `proposedSalary` inside band `A`, `targetBandId` band `A`, `contractId` the
seeded contract). Seeds are `draft`/vacuous under the new rule until proposed, so they raise no
mandatory violation. The dev-container gate instead exercises the real path: propose → approve (as a
second uid) → `occ hrmq:comp:effectuate --cycle <id> --date 2026-07-01` must apply the new
`grossMonthlySalary` onto the employee and move the adjustment to `effective`.

## Risks / Trade-offs

- **"Onto the contract" resolves to Employee, not EmploymentContract.** Honest grounding: the payroll
  engine reads `Employee.grossMonthlySalary` and the contract has no monthly-salary field. The
  adjustment stays bound to the contract via `contractId` (audit + eligibility), but the figure lands
  where pay consumes it. The hourly-onto-contract path is a named fast-follow — the alternative
  (silently writing a monthly figure onto a contract field the engine ignores) would be a dead write.
- **No market benchmark.** Bands are the employer's internal min/reference/max only; positioning them
  against external survey percentiles is explicitly out of scope (needs a market-data service) and is
  called out as a future change, not implied.
- **Effective-date races the payroll run.** An adjustment effectuated after a period's PayrollRun is
  already `approved`/`posted` will only affect the *next* run (the service never reopens a run — retro
  is `retro-adjustments`' scope). The date guard gates only the forward-looking effectuation.
- **Band-less proposals raise no violation** (vacuous rule) — deliberate, matching the
  `payScalesVerified` advisory-until-confirmed convention; a proposal can be recorded before a band
  structure exists.

## Open Questions

- None blocking. Market-data benchmarking and hourly-wage-onto-contract are named future work; the
  per-adjustment lifecycle and the imperative effectuate service are the extension points.
