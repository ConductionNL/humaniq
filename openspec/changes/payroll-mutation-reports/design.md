# Design — payroll-mutation-reports

## Context

**Verified against HEAD 2026-07-14.** Consumes `payroll-core-engine` (merged first): PayrollRuns
are now engine-computed, each carrying run totals (`totalGross`/`totalNet`/`totalLoonheffing`/
`totalEmployerCharges`) and a set of Payslips with integer-cents component fields — `grossPay`,
`nettoPay`, `loonheffing`, `werknemersverzekeringen`, `zvw`, `vakantiegeldReserved` — and a real
`payrollRunId` `$ref` back to the run (`payroll-core-schema`). This change reads those two runs and
subtracts; it computes no payroll.

Existing patterns this change mirrors (all read at HEAD):

- **Run-scoped payslip loading**: `RuleAuditService::auditPayrollRunScope()` selects a period's
  PayrollRun(s) and the Payslips whose `payrollRunId` points at them — exactly the per-run payslip
  set this diff needs; `PayrollMutationService` uses the same `loadAll('Payslip')` +
  `payrollRunId`-filter idiom (no new query surface).
- **PayrollRun-by-id context**: `RuleAuditService::buildPayrollContext()` builds `runsById` — the
  full-run-row-by-id map; the mutation service resolves `fromRunId`/`toRunId` the same way.
- **Service shape**: `PayrollRunService` — container-resolved `OCA\OpenRegister\Service\ObjectService`,
  `register()` from app config (default `hrmq`), `find`/`saveObject`, idempotency probe before
  create, outcome arrays, never-throw degradation. The mutation service reuses all of it (reads
  runs+payslips; writes at most one `PayrollMutationReport`).
- **Endpoint guard**: `PayrollController::calculate` — `#[NoAdminRequired]` + resolve the posted run
  id through ObjectService under the caller's ambient RBAC BEFORE any work; unknown/unauthorized
  collapse to 404. The mutations endpoint additionally requires the caller be an admin/HR principal
  (payroll figures are sensitive — D6).
- **Schema shape**: `PayrollRun`/`Payslip` in `lib/Settings/register.d/hr-objects.json` — PascalCase
  `slug`, `version`, `icon`, `required`, cents-carrying `number` component fields. `PayrollMutationReport`
  follows the identical shape and lives in the same fragment file.

## Goals / Non-Goals

**Goals:** a deterministic, integer-cents, read-only run-to-run payroll diff: per-employee
join/leave/change detection and per-component (gross/net/loonheffing/employer-cost) cents deltas,
run-level roll-ups (total wage-cost delta + changed-employee count), an occ command that prints it,
an idempotent persisted `PayrollMutationReport` keyed `(fromRunId, toRunId)` for the manifest,
first-run-has-no-prior handling, admin-only access.

**Non-Goals (from the proposal, binding):** recomputing any payroll figure (pure subtraction only),
cross-administration diffs, per-component drill-down beyond the four headline deltas, approval
workflow / status writes / gating, PDF/export rendering.

## Decisions

### D1 — The diff is a pure function of two persisted payslip sets; all money is integer cents

`PayrollMutationService::diff(?string $fromRunId, string $toRunId): array` resolves each run (the
`runsById` idiom), loads each run's Payslips (the `payrollRunId`-scoped set), and keys both by
`employeeId`. Classification per employee is set membership:

- in `to` only → **entered**
- in `from` only → **left**
- in both, any headline component differs → **changed**
- in both, all four headline components equal → **unchanged**

For a shared employee, each component delta is `to.component − from.component` in **integer cents**
(the fields are already cents-carrying `number`s; no float accumulation, no rounding — subtraction
is exact). Employer cost per payslip is `werknemersverzekeringen + zvw` (the run's employer-charge
basis; matches `PayrollRun.totalEmployerCharges`). The service has zero clock dependency in the
computation (only the persisted report stamps `generatedAt`) and makes no HTTP calls.

### D2 — Run-level roll-ups are cents-exact sums of the per-employee deltas

`grossDelta = Σ (to.grossPay) − Σ (from.grossPay)` and identically for `netDelta`,
`loonheffingDelta`, `employerCostDelta`; `totalWageCostDelta = grossDelta + employerCostDelta`
(the accountant's headline — the change in what the employer pays out). `enteredCount`,
`leftCount`, `changedCount`, `unchangedCount` and `changedEmployeeCount = changedCount` are counts
over the classification. These sums are computed over the union of `from` and `to` payslips so an
`entered` employee's full amount lands in the delta (their `from` side is 0) and a `left`
employee's full amount is subtracted — this is what makes `totalWageCostDelta` reconcile with
`to.totalGross+to.totalEmployerCharges − from.totalGross − from.totalEmployerCharges`.

### D3 — The report READS payslips and never recomputes, so it cannot drift from the engine

The service never constructs `PayrollCalculator`, never reads the tax tables, never calls
`PayrollRunService`. It subtracts two already-persisted numbers. Consequence: if the engine changes
how a figure is computed, the mutation report automatically reflects it (it reports whatever the
payslips say); it can never disagree with the engine because it has no independent computation to
disagree with. A wrong payslip is out of scope — `hrmq:payroll:verify` (the corpus audit) owns
correctness; this change owns *change*.

### D4 — Prior-run auto-resolution + same-administration guard

`--to <runId>` alone (no `--from`) auto-resolves `from` = the PayrollRun of the **same
administration** whose `period` is the closest one strictly before the `to` run's period (string
compare on `YYYY-MM`, the seeded convention). If none exists → **first-run path** (D5). When both
ids are given, the service refuses if `from.administrationId !== to.administrationId` (a
cross-administration diff is meaningless — outcome `failed` with a clear message; endpoint 400).

### D5 — First run has no prior: every employee entered, deltas equal the `to` totals

When no `from` run exists (auto-resolution found none, or `fromRunId` is explicitly null), every
`to` payslip is classified **entered** with per-component `before = 0`, `after = component`,
`delta = component`; `leftCount = unchangedCount = changedCount = 0`; the run-level deltas equal the
`to` run's own totals. The report is produced and persistable — a first run is a valid, expected
input, not an error. `fromRunId` on the persisted report is null in this case.

### D6 — ONE guarded endpoint + occ command, admin/HR only

- `occ hrmq:payroll:mutations --from <runId> --to <runId> [--persist]` (and `--to` alone →
  auto-resolve prior): prints the entered/left/changed table + run-level deltas; `--persist`
  upserts the `PayrollMutationReport`. Registered in `appinfo/info.xml`.
- `POST /api/payroll/mutations` → `PayrollController::mutations` (`#[NoAdminRequired]` + an explicit
  **admin/HR authorization check** in the body — payroll figures are sensitive, so unlike
  `calculate` this endpoint additionally requires the caller be an admin/HR principal; a
  non-admin caller gets 403). It resolves `toRunId` (and `fromRunId` if given) through ObjectService
  under the caller's RBAC first (unknown/unauthorized → 404 — the `calculate` no-admin-idor
  pattern), then generates + persists and returns the report id.
- Manifest: `PayrollRunDetail.actions` += `{id: mutations, type: api-call, label:
  "Mutatieoverzicht", url: "/api/payroll/mutations", method: POST, params: {toRunId: "@objectId"},
  onSuccessRoute: PayrollMutationReportDetail}`. The `PayrollMutations` report page and
  `PayrollMutationReportDetail` are admin-visible only (the Payroll nav group is admin-scoped).
  `npm run check:manifest` gates it.

### D7 — Idempotent persisted report keyed (fromRunId, toRunId)

Persisting probes for an existing `PayrollMutationReport` with the same `(fromRunId, toRunId)` (the
`PayrollRunService` probe-before-create idempotency pattern); found → upsert in place, absent →
create. First-run reports key on `(null, toRunId)`. Regenerating the same pair never duplicates.
The persisted object carries the header (fromRunId, toRunId, fromPeriod, toPeriod, administrationId,
generatedAt), the run-level deltas + counts, and a `lines` JSON array of per-employee mutations
(employeeId, classification, and per the four headline components the before/after/delta cents).

### PayrollMutationReport schema (register.d, hr-objects.json)

| Field | Type | Notes |
|---|---|---|
| `fromRunId` | string | The baseline PayrollRun (null on a first run — no prior period). |
| `toRunId` | string | The PayrollRun being reviewed. Required. |
| `fromPeriod` | string | Baseline `YYYY-MM` (empty on a first run). |
| `toPeriod` | string | Reviewed `YYYY-MM`. |
| `administrationId` | string | Both runs' administration (guarded equal). |
| `generatedAt` | string | ISO-8601 stamp of report generation. |
| `enteredCount` | number | Employees present only in the `to` run. |
| `leftCount` | number | Employees present only in the `from` run. |
| `changedCount` | number | Shared employees with any headline-component delta (= `changedEmployeeCount`). |
| `unchangedCount` | number | Shared employees with all four headline components equal. |
| `grossDelta` | number | Σ `to.grossPay` − Σ `from.grossPay` (cents). |
| `netDelta` | number | Σ `to.nettoPay` − Σ `from.nettoPay` (cents). |
| `loonheffingDelta` | number | Σ `to.loonheffing` − Σ `from.loonheffing` (cents). |
| `employerCostDelta` | number | Σ `to.(werknemersverzekeringen+zvw)` − Σ `from` (cents). |
| `totalWageCostDelta` | number | `grossDelta + employerCostDelta` — the accountant's headline. |
| `lines` | array | Per-employee mutation rows (employeeId, classification, per-component before/after/delta). |

`required`: `toRunId`, `toPeriod`, `administrationId`, `generatedAt`. `slug: PayrollMutationReport`,
mirroring the PascalCase-slug convention of `PayrollRun`/`Payslip` in the same fragment.

## Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Report parameters | none — pure read of persisted payslips | nothing to configure |
| Diff computation | **imperative pure PHP** (`lib/Service/PayrollMutationService.php`) | set-membership classification + cross-object cents subtraction over two runs' payslips is a multi-object aggregation schema-declarative calc cannot express; same class as the corpus CheckProviders / `PayrollRunService` roll-up |
| Report persistence | imperative service via ObjectService | idempotent single-object upsert (the `PayrollRunService` probe precedent) |
| Triggers | occ command + ONE guarded endpoint | operator/accountant demand; no lifecycle to hang a declarative action on |
| Report pages | declarative manifest (report/detail pages, `api-call` action) | ADR-031 default; the manifest v2 page/action shape already used by `PayrollRunDetail` |
| Report record | `PayrollMutationReport` register.d schema | ADR-001 default; same fragment as PayrollRun/Payslip |

## Seed Data (ADR-001)

No new seed objects. The report is derived, not authored: seeded runs/payslips are the inputs, and a
`PayrollMutationReport` is only ever produced by the service. The dev-container gate exercises the
real path instead: `occ hrmq:payroll:run --period 2026-02` then `--period 2026-03` to produce two
engine runs for the seeded administration, then `occ hrmq:payroll:mutations --to <2026-03 runId>
--persist` — the auto-resolved prior is the 2026-02 run, and (with an unchanged seeded salary) every
shared employee classifies `unchanged` with zero deltas, giving a byte-stable expected report.

## Worked delta example

Two consecutive runs for administration `ADM-001`, all cents:

**From (2026-02)** — 2 payslips:
- Emp A: grossPay 380000, nettoPay 308117, loonheffing 71883, employerCost (wnv 41914 + zvw 23180) = 65094
- Emp B: grossPay 250000, nettoPay 210000, loonheffing 30000, employerCost 40000

**To (2026-03)** — 2 payslips:
- Emp A: grossPay 400000, nettoPay 322000, loonheffing 78000, employerCost 68000  *(A got a raise)*
- Emp C: grossPay 300000, nettoPay 240000, loonheffing 42000, employerCost 48000  *(C joined; B left)*

Per-employee classification and headline deltas:

| Employee | Class | grossΔ | netΔ | loonheffingΔ | employerCostΔ |
|---|---|---|---|---|---|
| A | changed | +20000 | +13883 | +6117 | +2906 |
| B | left | −250000 | −210000 | −30000 | −40000 |
| C | entered | +300000 | +240000 | +42000 | +48000 |

Run-level roll-up (Σ to − Σ from over the union):
- `grossDelta` = (400000+300000) − (380000+250000) = **+70000**
- `netDelta` = (322000+240000) − (308117+210000) = **+43883**
- `loonheffingDelta` = (78000+42000) − (71883+30000) = **+18117**
- `employerCostDelta` = (68000+48000) − (65094+40000) = **+10906**
- `totalWageCostDelta` = grossDelta + employerCostDelta = **+80906**
- counts: entered 1, left 1, changed 1, unchanged 0, `changedEmployeeCount` = 1

Every figure is exact integer-cents subtraction of persisted payslip fields — no recomputation.

## Risks / Trade-offs

- **A wrong payslip yields a truthful diff of wrong numbers.** The report reports *change*, not
  *correctness*; correctness is `hrmq:payroll:verify`'s job. Documented (D3); the two tools are
  complementary and the manifest surfaces both on the run.
- **Auto-resolved prior is the closest earlier period, which may skip a gap** (e.g. a missing month).
  Acceptable: the report header names both periods explicitly, so the accountant sees exactly which
  two runs were compared; an explicit `--from` overrides.
- **`employeeId` is the diff key.** If an employee's id is unstable across runs they would falsely
  read as left+entered. Mitigated: `payrollRunId`-scoped payslips carry the same `employeeId` the
  engine stamped from the Employee; the engine is the single producer of both runs' payslips.

## Open Questions

- None blocking. Fuller per-component drill-down (WKR, vakantiegeld, per-premie split) and PDF export
  are named fast-follows; the `lines` array shape is the extension point.
