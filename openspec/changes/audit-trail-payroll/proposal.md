---
kind: code
depends_on:
  - payroll-core-engine    # PayrollRunService, CalculationInput, engineVersion/calculatedAt stamps
  - jurisdiction-packs     # PackRepository/JurisdictionPack, engineVersion = {packId}@{packVersion}
  - avg-dsr                # AvgDsrRetentionClassifier — retention is REUSED, not re-specified here
---

# Audit trail for payroll — the genuine delta over OpenRegister's generic audit trail

## Why

The old `spec/audit-trail-payroll` draft (May 2026) proposed a second, bespoke audit subsystem for
payroll: its own `PayrollAuditEvent` table, its own hash chain, its own weekly Merkle-root anchor
job, its own `payroll_audit_lezer` role and `PayrollAuditAccessLog`, its own 10-year retention
lifecycle, its own SDK. That draft predates the OpenRegister audit trail this repo actually has at
HEAD.

**What OpenRegister already delivers (read at HEAD, `openregister/lib/Service/AuditHashService.php`
+ `Db/AuditTrail.php` + `Service/AuditQueryService.php`), verified before writing a line of this
proposal:**

- Every `ObjectService::saveObject()`/`deleteObject()` on a `Payslip`/`PayrollRun` writes an
  `openregister_audit_trails` row: who, when, `action`, `changed` (before/after per field) — this
  IS the "who changed what, when" the old draft's F-001/Story-1 asked for, already running, for
  every hrmq schema, with zero hrmq code.
- Every row is SHA-256 hash-chained to the immediately preceding row **globally, across the whole
  instance** (`AuditHashService::computeHash()`/`sealRow()`/`sealRows()`) — genesis-seeded, not
  per-app. `verifyChain(?from, ?to)` recomputes and detects any tamper. This already IS the old
  draft's F-002 hash-chain — global, not per-`administratie`, but strictly *stronger*: it also
  detects tampering with anything else that happened between two payroll events, not just payroll
  rows.
- `AuditQueryService::query()` is a cross-app, register/schema/object/date-filtered read path —
  already the shape of an export query; export packaging is the only piece missing (REQ-AUDP-003
  below).
- Retention: `avg-dsr`'s `AvgDsrRetentionClassifier` already derives the AWR art. 52 lid 4 7-year
  fallback for `PayrollRun`/`Payslip`/`LoonaangifteFiling`/`PayrollMutationReport`/
  `WkrDeclaration`/`WkrAssessment` (`PAYROLL_FAMILY_SCHEMAS`), honours an explicit `retainedUntil`
  when populated, and is exercised by `occ hrmq:avg:erase`. This is the old draft's F-007 —
  already done. **This change adds no retention field and no retention logic.**

Weekly Merkle anchors, an external TSA, a bespoke access-log role, and a bespoke SDK
(F-003/F-006(anchors)/F-008/F-010) are **not re-specified here** — they duplicate machinery
OpenRegister already runs or are out of MVP scope (external notarization is named as a follow-up,
never silently dropped).

**The genuine gap, found by tracing what a `PayrollRun`/`Payslip` actually carries today**
(`lib/Service/PayrollRunService.php`, `lib/Payroll/CalculationInput.php`,
`lib/Settings/register.d/hr-objects.json`): `PayrollRun.engineVersion` (stamped
`{packId}@{packVersion}` since `jurisdiction-packs`, `nl-engine-table-version`-enforced) pins
**which code and which tax-year parameters** produced a run. It pins nothing about **which
resolved input values** fed that run for a given employee — `CalculationInput` (gross salary,
`taxTableColor`, `loonheffingskortingToegepast`, `dateOfBirth`, Awf/Aof tariffs, Whk percentage,
`verzekeringsplichtig`) is built fresh from the live `Employee`/`EmploymentContract`/
`SettingsService` state at `generate()` time and then **discarded** — `PayrollRunService::generate()`
never persists it. If an `Employee`'s `loonheffingskortingToegepast` or `EmploymentContract`'s
`awfTariff` is edited next year, a 2029 boekenonderzoek re-running `PayrollCalculator::calculate()`
against *current* Employee/Contract state can silently reproduce a **different** figure than the
one actually paid in 2026 — the exact reproducibility failure the old draft's F-005/Problem-3
named, and the one candidate OpenRegister does not already solve (OR's per-object audit trail
*could*, in principle, reconstruct a point-in-time object state from its `changed` diffs, but no
such point-in-time reconstruction API exists today, and building one is a materially larger,
independently-scoped capability — out of reach for an honest MVP delta).

Second gap: no rule flags a payslip that lost this provenance, and no packaged, defensible export
exists for a Belastingdienst/UWV/accountant request — an admin would otherwise have to hand-query
`AuditQueryService` and `AuditHashService::verifyChain()` themselves.

## What Changes

- **`Payslip` v0.8.0 → v0.9.0** (`lib/Settings/register.d/hr-objects.json`): new `engineInputSnapshot`
  (string, nullable) — the canonical-JSON serialization of the exact `CalculationInput` used to
  compute this payslip (REQ-AUDP-001). Null for hand-entered payslips, identical to
  `engineVersion`'s existing null-means-hand-entered convention.
- **`PayrollRunService::generate()` extended**: stamps `engineInputSnapshot` on every engine-produced
  Payslip alongside the existing `engineVersion`/`calculatedAt` stamps on its `PayrollRun`
  (REQ-AUDP-001) — no change to the calculation itself.
- **New occ command `hrmq:payroll:reproduce --payslip <uuid>`**: reloads the named payslip's
  `engineInputSnapshot`, resolves the same engine artefact its run's `engineVersion` names
  (`PackRepository`/`TaxTables`, the `NlEngineChecks::namesAKnownEngineArtefact()` precedent),
  recomputes, and reports byte-identical match or names the first mismatching component
  (REQ-AUDP-002) — the "verifier re-runs and compares" mechanism.
- **New `PayrollAuditVerificationService::verifyRun(runId)`**: resolves the audit-trail row id
  range spanning a `PayrollRun`'s and its `Payslip`s' create/update rows via
  `AuditQueryService::query()`, then calls the existing `AuditHashService::verifyChain(from, to)`
  over that range — zero new hashing/chaining code (REQ-AUDP-003).
- **`GeneratedDocument` v0.2.0 → v0.3.0** (`lib/Settings/register.d/hr-documents.json`):
  `documentType` enum gains `payroll-audit-report` (append-only); new nullable `$ref`
  `payrollRunId` (the `payslipId`/`jaaropgaafId` precedent, ADR-062 rule 7). `HrDocumentService`
  renders it via the existing docudesk consumption leaf — hrmq assembles the run summary +
  per-payslip provenance + the REQ-AUDP-003 verification result + the audit-trail entries as
  `adHocData`, docudesk renders the PDF; no Dompdf/Twig in hrmq (REQ-AUDP-004).
- **`NlEngineChecks` extended** (same provider file, `lib/Standards/Checks/NlEngineChecks.php`):
  new `Payslip` predicate `nl-engine-provenance-complete`, alongside the existing
  `nl-engine-table-version`/`nl-engine-output-consistency` — vacuous on hand-entered payslips,
  else requires `engineInputSnapshot` present, valid JSON, and internally naming the same engine
  artefact as its run (REQ-AUDP-005). `lib/Standards/rules/payroll.json` gains the rule row;
  `RuleCatalogue::VERSION` bumps `2026-07.25` → `2026-07.26`.
- **Retention: explicitly out of scope for new logic.** `Payslip.engineInputSnapshot` carries no
  independent retention lifecycle; `PayrollRun`/`Payslip` stay classified by
  `AvgDsrRetentionClassifier` exactly as today (REQ-AUDP-006, a reuse-and-bound requirement, not
  new logic).

## Out of scope (named, not silently dropped)

- External Time Stamping Authority anchoring / blockchain notarization of the hash chain — OR's
  SHA-256 chain is the sole integrity mechanism for MVP (follow-up if a future boekenonderzoek
  demands external notarization).
- A payroll-specific hash chain scoped to one `administratie`/`PayrollRun` — OR's single global
  chain already covers every payroll row; a second chain would duplicate, not add, integrity.
- Point-in-time reconstruction of `Employee`/`EmploymentContract` state from OR's audit `changed`
  diffs — a materially larger capability; REQ-AUDP-001's input snapshot is the scoped alternative
  that solves the actual reproducibility need without it.
- A bespoke `payroll_audit_lezer` role / access-log — the export in REQ-AUDP-004 rides the existing
  admin-only `DocumentController` guard precedent; no new RBAC surface.
