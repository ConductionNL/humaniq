---
capability: payroll-core-schema
status: in-progress
built_by: openspec/changes/payroll-core-schema
---

# payroll-core-schema Specification

**Status**: in-progress
**Scope**: hrmq (chain head, spec 1 of 2 — ADR-032; consumed by `payroll-core-engine`)
**OpenSpec changes**:
- [payroll-core-schema](../../changes/payroll-core-schema/) _(active)_ — versioned
  `lib/Standards/tables/nl-2026.json` NL tax-year parameter corpus (Rekenvoorschriften 2026
  schijven + heffingskorting formulas, premies, maxima, WML — every value sourced + verified,
  placeholders flagged) with `tables/SCHEMA.md`, Employee `loonheffingskortingToegepast`,
  PayrollRun `calculatedAt`/`engineVersion`, Payslip `arbeidskorting`/`payrollRunId`, and the two
  engine-contract corpus rules `nl-engine-table-version` / `nl-engine-output-consistency`
  (checks implemented by spec 2) (kind: config)

## Purpose

Chain head for the open-source Dutch payroll engine (Spectr `hrmq-canon-payroll-engine`,
P1-strategic; no OSS NL payroll engine exists — Odoo NL is Enterprise-only): the verified,
versioned 2026 tax-year parameter file (annual re-issue = data-only change, the rule-corpus
philosophy), the minimal schema deltas the engine reads/writes, and the corpus rules that pin the
engine's traceability + output-consistency contract. Requirements live in the active change's
delta spec until archive:
`openspec/changes/payroll-core-schema/specs/payroll-core-schema/spec.md` (REQ-PCS-001…006).
