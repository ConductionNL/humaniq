---
capability: payroll-sepa-netpay-shillinq
status: in-progress
built_by: openspec/changes/payroll-sepa-netpay-shillinq
---

# payroll-sepa-netpay-shillinq Specification

**Status**: in-progress
**Scope**: hrmq (cross-app leaf; consumes the shillinq `PaymentRun` register via OpenRegister)
**OpenSpec changes**:
- [payroll-sepa-netpay-shillinq](../../changes/payroll-sepa-netpay-shillinq/) _(in progress)_ — Employee `iban`/`tenaamstelling` fields, `PayrollPaymentBatch` record + `PayrollNetPayService` creating one draft shillinq `PaymentRun` per approved/posted `PayrollRun` (one net-pay line per payslip; duck-typed, same-instance ObjectService, fail-closed on missing IBANs, `skipped-no-shillinq` degradation), occ trigger `hrmq:netpay:run`, corpus rule `nl-netpay-iban-present`, batch pages (kind: code)

## Purpose

Pay the salaries an approved payroll run owes — without hrmq ever touching a
bank or emitting SEPA XML. hrmq contributes the payroll-shaped input (which
employees, which IBANs, which net amounts) as a draft `PaymentRun` in shillinq's
register; shillinq owns everything from there: the RBAC-gated approval, the
pain.001.001.03 export, and CAMT.053 reconciliation. Sibling of
`payroll-glpost-shillinq` under the same cross-app-leaf philosophy: payment
machinery lives in shillinq, hrmq reintegrates it.

The requirements live in the active change until archive:
`openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md`
(REQ-PNP-001 … REQ-PNP-010).
