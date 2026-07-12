---
capability: payroll-glpost-shillinq
status: in-progress
built_by: openspec/changes/payroll-glpost-shillinq
---

# payroll-glpost-shillinq Specification

**Status**: in-progress
**Scope**: hrmq (cross-app leaf; consumes the shillinq `JournalEntry` register via OpenRegister)
**OpenSpec changes**:
- [payroll-glpost-shillinq](../../changes/payroll-glpost-shillinq/) _(active)_ — `PayrollGLPost` record + `PayrollGLPostService` posting one balanced loonjournaalpost per approved `PayrollRun` into shillinq's `JournalEntry` register (duck-typed, same-instance ObjectService, `skipped-no-shillinq` degradation), occ trigger `hrmq:glpost:run`, idempotency rule `nl-glpost-idempotent-per-run`, GL-post pages (kind: code)

## Purpose

Make payroll flow automatically into the books — the AFAS core pitch, matched
open-source (2026-07-12 deep research, Spectr insight
`hrmq-insight-buildround-2026-07-12`): one balanced 4-line journal (bruto
loonkosten, werkgeverslasten, loonheffing-schuld, netto-loonschuld) per
approved payroll run, delivered as a draft `JournalEntry` into the shillinq
bookkeeping app on the same instance. hrmq stays a leaf — no duplicate
bookkeeping machinery; the existing `xc-payroll-gl-reconciliation` corpus rule
turns green on posted runs. SEPA net-pay batches, per-employee splits, and
vakantiegeld accruals are explicitly out of scope.

## Requirements

See the active change's delta spec:
[changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md](../../changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md)
(REQ-PGP-001 … REQ-PGP-009). Canonical requirements land here on archive.
