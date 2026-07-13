---
capability: payroll-core-engine
status: in-progress
built_by: openspec/changes/payroll-core-engine
---

# payroll-core-engine Specification

**Status**: in-progress
**Scope**: hrmq (chain spec 2 of 2 — ADR-032; `depends_on: [payroll-core-schema]`)
**OpenSpec changes**:
- [payroll-core-engine](../../changes/payroll-core-engine/) _(active)_ — pure table-driven
  `PayrollCalculator` (Rekenvoorschriften 2026 chain, integer cents, AOW-age + groen variants) +
  `PayrollRunService` (idempotent draft runs per period/administration, draft-only recalculation,
  ObjectService payslip generation), occ `hrmq:payroll:run`/`hrmq:payroll:verify`, one guarded
  calculate endpoint + run-pages actions/child list, `NlEngineChecks` enforcement of the spec-1
  contract, golden fixtures + balancing invariant, non-certification README disclaimer
  (kind: code)

## Purpose

The strategic centerpiece: the only open-source Dutch payroll calculation engine (verified
2026-07-12/13 — Spectr `hrmq-canon-payroll-engine` 7/9 coverage,
`hrmq-insight-odoo-nl-enterprise-only`). Produces the approved-run inputs the already-shipped
glpost/netpay/filing pipeline consumes, audited by the same rule corpus that audits hand-entered
data (`hrmq:payroll:verify` = the corpus as the engine's self-check). Rekenvoorschriften-based and
explicitly NOT certified — outputs carry `engineVersion`, and the disclaimer is a requirement.
Requirements live in the active change's delta spec until archive:
`openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md` (REQ-PCE-001…010).
