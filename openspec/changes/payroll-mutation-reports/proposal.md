---
kind: code+config
depends_on:
  - payroll-core-engine   # the engine that actually computes runs → payslips with the component cents fields this report diffs
---

# Payroll Mutation Reports — the per-run diff an accountant reviews before approval

## Why

`payroll-core-engine` merged, so PayrollRuns are now **actually computed**: each run carries a set
of Payslips with integer-cents component fields (`grossPay`, `nettoPay`, `loonheffing`,
`werknemersverzekeringen`, `zvw`, `vakantiegeldReserved`, …) and run-level totals
(`totalGross`/`totalNet`/`totalLoonheffing`/`totalEmployerCharges`). The one thing a
loonadministrateur does **before** flipping a run from `draft` to `approved` is compare it to the
previous period (or to the run's prior version after a recalculation): *who joined, who left,
whose gross/net/premies moved and by how much, and what the total wage-cost delta is*. Today that
comparison is a manual eyeball across two object lists. This change produces it deterministically.

This is meaningful **now** and was not before: with hand-typed payslips there was nothing stable to
diff; with an engine that stamps `engineVersion`/`calculatedAt` and writes cents-exact components,
a run-to-run mutation report is a pure, reproducible read over persisted data.

Everything the report needs already exists in the register: `payroll-core-engine` gave Payslip a
real `payrollRunId` `$ref` and the component cents fields, and `RuleAuditService` already
demonstrates the run-scoping + cross-object context idiom (`buildPayrollContext`,
`auditPayrollRunScope`) this service mirrors. Nothing recomputes payroll here — the report **reads**
the two runs' persisted payslips and subtracts.

## What Changes

- **NEW `lib/Service/PayrollMutationService.php`** — pure/deterministic diff service. Input = two
  PayrollRun ids (`fromRunId`, `toRunId`), or a single `toRunId` + "prior period same
  administration" auto-resolution. It loads each run's payslips (keyed by `employeeId`, the
  `payrollRunId`-scoped set — the `auditPayrollRunScope` precedent), then computes, per employee:
  an **entered/left/changed/unchanged** classification, and for each shared employee the integer-cents
  delta on `grossPay`, `nettoPay`, `loonheffing` and total employer cost
  (`werknemersverzekeringen + zvw`, the run's employer-charge basis). It rolls those into run-level
  deltas (Σ gross / net / loonheffing / employer-cost delta = total wage-cost delta) and a
  `changedEmployeeCount`. All arithmetic is integer cents; the service has no clock and no HTTP —
  it reads via ObjectService only and never invokes the calculator, so it **cannot drift from the
  engine** (design.md D3).
- **NEW `occ hrmq:payroll:mutations --from <runId> --to <runId>`** (plus `--to <runId>` alone →
  auto-resolve the prior period's run of the same administration; `--persist` to write the report
  object) — prints the mutation table (entered / left / changed rows with per-component deltas) and
  the run-level totals, registered in `appinfo/info.xml`.
- **NEW `PayrollMutationReport` object (register.d, `hr-objects.json`)** — a lightweight persisted
  report keyed **idempotently** on `(fromRunId, toRunId)`: header (fromRunId, toRunId, fromPeriod,
  toPeriod, administrationId, generatedAt) + the run-level deltas + counts +
  a JSON `lines` array of per-employee mutations (employeeId, classification, per-component before/after/delta).
  Persisting is optional (`--persist` / the manifest action); regenerating for the same
  `(fromRunId, toRunId)` **upserts in place**, never duplicates.
- **Manifest** — a `PayrollMutations` report/index page listing persisted `PayrollMutationReport`
  objects, a `PayrollMutationReportDetail` detail page surfacing the run-level deltas as stat KPIs +
  the per-employee mutation table, and a "Mutatieoverzicht" `api-call` action on `PayrollRunDetail`
  that generates+persists the report for (prior period → this run) and routes to it. RBAC:
  **admin/HR only** — the generate endpoint and the report surfaces are admin-gated (payroll figures
  are sensitive), mirroring the no-admin-idor RBAC resolve-first pattern.
- **First-run handling** — when `fromRunId` is absent (the `--to` run is the first run for its
  administration, no prior period exists) **every** employee in the `to` run is classified `entered`
  with zero deltas (nothing to subtract from), and the run-level deltas equal the `to` run totals;
  the report is still produced and persisted — a first run is not an error.

### Non-goals (named fast-follows and exclusions)

- **Recompute / re-derive any payroll figure** — the report is a pure subtraction over persisted
  payslips; if a payslip is wrong, `hrmq:payroll:verify` (the corpus audit) is the tool, not this.
- **Cross-administration or cross-jurisdiction diffs** — a report compares two runs of the **same**
  administration; mismatched administrations are refused with a clear message.
- **Per-component drill-down beyond the four headline deltas** (gross/net/loonheffing/employer-cost)
  — the persisted `lines` carry the four; a fuller component matrix (WKR, vakantiegeld, per-premie
  split) is a named fast-follow, the `lines` shape is the extension point.
- **Approval workflow / write-time gating** — the report is advisory input to a human approval; it
  never changes a run's `status` and does not gate `draft → approved` (guard wiring stays
  `hrmq-rule-compliance-enforcement`'s scope).
- **PDF/export rendering** — the rendered accountant document is a docudesk concern; this change
  produces the structured report + manifest table only.

## Capabilities

### New Capabilities

- `payroll-mutation-reports`: the pure run-to-run payroll diff service (per-employee join/leave
  detection + per-component cents deltas + run-level totals), the `hrmq:payroll:mutations` occ
  command, the idempotent persisted `PayrollMutationReport` object, first-run handling, and the
  admin-only manifest report pages + generate action.

## Modified Capabilities

<!-- none — payroll-core-engine's runs/payslips are consumed read-only, not modified -->

## Impact

- `lib/Service/PayrollMutationService.php` — NEW (ObjectService access idiom per
  `PayrollRunService`/`RuleAuditService`; pure integer-cents diff, no calculator, no clock beyond
  the `generatedAt` stamp).
- `lib/Command/PayrollMutationsCommand.php` — NEW; `appinfo/info.xml` +1 `<command>` entry.
- `lib/Controller/PayrollController.php` — +1 method `mutations` (admin-gated, RBAC-resolve the run
  first); `appinfo/routes.php` +1 route before the SPA catch-all.
- `lib/Settings/register.d/hr-objects.json` — NEW `PayrollMutationReport` schema (keyed
  `(fromRunId, toRunId)`); `npm run check:manifest` + the register gates pass.
- `src/manifest.json` — `PayrollMutations` report page + `PayrollMutationReportDetail` detail page +
  `PayrollRunDetail` "Mutatieoverzicht" action + a nav entry under the Payroll group;
  `npm run check:manifest` passes.
- `tests/Unit/Service/PayrollMutationServiceTest.php` — NEW (mocked ObjectService: entered/left
  detection, per-component deltas, run-level roll-up, first-run-all-entered, idempotent upsert,
  same-administration guard).
- Depends on: `payroll-core-engine` (computed runs + Payslip component cents fields +
  `payrollRunId` `$ref`) — MUST be merged first.
