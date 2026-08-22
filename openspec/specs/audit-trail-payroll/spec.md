---
capability: audit-trail-payroll
status: done
built_by: openspec/changes/archive/2026-07-17-audit-trail-payroll
---

# audit-trail-payroll Specification

**Status**: done (REQ-AUDP-001/002/003/005/006 fully implemented + live-verified on 8080;
REQ-AUDP-004 deferred — see note below the requirement)
**Scope**: humaniq
**OpenSpec changes**:
- [audit-trail-payroll](../../changes/archive/2026-07-17-audit-trail-payroll/) _(archived
  2026-07-17)_ — fixes hrmq#98 (payroll reproducibility): persists the exact resolved
  `CalculationInput` behind every engine-produced Payslip (`engineInputSnapshot`), a
  `humaniq:payroll:reproduce` verifier that recomputes a sealed payslip from ITS OWN snapshot (never
  live Employee/EmploymentContract state), chain-of-custody orchestration over OpenRegister's
  existing global hash chain (zero new hashing/chaining code), and a `nl-engine-provenance-complete`
  corpus rule flagging payslips that lost their provenance. Retention/immutability add no new
  logic — reuses `AvgDsrRetentionClassifier`'s existing 7-year AWR fallback (kind: code)

## Purpose

Before this change, `PayrollRun.engineVersion`/`calculatedAt` pinned WHICH code and tax-year
parameters produced a run, but nothing pinned WHICH resolved input values (gross salary,
`taxTableColor`, `loonheffingskortingToegepast`, Awf/Aof tariffs, Whk percentage) fed it —
`CalculationInput` was built fresh from live `Employee`/`EmploymentContract` state at generation
time and then discarded. Editing an Employee/Contract after sealing a payslip meant a later
recompute (e.g. a boekenonderzoek) could silently reproduce a DIFFERENT figure than the one
actually paid — a 7-year fiscal defensibility gap (hrmq#98). This capability closes it with the
smallest genuine delta: persist the exact resolved input, and give a verifier a command that
actually re-derives and compares — consuming OpenRegister's existing audit/hash-chain
infrastructure rather than building a second, payroll-private one.

## ADDED Requirements

### Requirement: Every engine-produced Payslip SHALL persist the exact resolved calculation inputs used to compute it (REQ-AUDP-001)

`lib/Settings/register.d/hr-objects.json` SHALL add `Payslip.engineInputSnapshot` (string, nullable, `Payslip` version `0.8.0` → `0.9.0`) — the canonical-JSON serialization (sorted keys, no whitespace) of the `CalculationInput` instance `PayrollRunService::generate()` builds for that employee/period, carrying all ten of its public readonly properties (`grossMonthlySalaryCents`, `taxTableColor`, `loonheffingskortingToegepast`, `dateOfBirth`, `period`, `awfTariff`, `aofTariff`, `whkPercentage`, `verzekeringsplichtig`, `jurisdiction`). `PayrollRunService::generate()` SHALL stamp `engineInputSnapshot` on every engine-produced Payslip in the same write that already stamps the run's `engineVersion`/`calculatedAt`, and SHALL leave it null for hand-entered payslips (`payrollRunId` null), identical to the existing `engineVersion` null-means-hand-entered convention. The field SHALL be written once at generation time and replaced wholesale (never edited in place) on recalculation of a draft run, mirroring `engineVersion`/`calculatedAt`.

**Note**: `CalculationInput` gained `toArray()`/`toCanonicalJson()`/`fromCanonicalJson()`/`fromDecoded()`.
Live-verified against 8080: `OCA\OpenRegister\Db\MagicMapper::rowToObjectEntity()` blanket
`json_decode()`s any string column value that parses as valid JSON, regardless of the schema's
declared `type: string` — so every real `ObjectService` read of `engineInputSnapshot` returns it
as an already-decoded PHP array, never the raw string. `fromDecoded()` (array input) and
`fromCanonicalJson()` (string input) both exist to handle either shape.

#### Scenario: A generated payslip carries its exact input snapshot
- **GIVEN** `occ humaniq:payroll:run --period 2026-02` generates a payslip for an employee with `grossMonthlySalary: 3800`, `taxTableColor: wit`, `loonheffingskortingToegepast: true`
- **WHEN** the payslip is read
- **THEN** `engineInputSnapshot` decodes as valid JSON naming `grossMonthlySalaryCents: 380000`, `taxTableColor: "wit"`, `loonheffingskortingToegepast: true`, and the run's `period`

#### Scenario: A hand-entered payslip carries no snapshot
- **GIVEN** the pre-existing seeded Payslip with null `payrollRunId`
- **WHEN** it is read after this change
- **THEN** `engineInputSnapshot` is null

#### Scenario: A later edit to Employee data does not retroactively change the stored snapshot
- **GIVEN** a generated 2026-02 payslip with its `engineInputSnapshot` stamped
- **WHEN** the underlying `Employee.loonheffingskortingToegepast` is edited afterwards
- **THEN** the payslip's `engineInputSnapshot` is unchanged and still reflects the value used at generation time

### Requirement: A verifier SHALL be able to recompute a payslip from its stored snapshot and compare byte-for-byte (REQ-AUDP-002)

`occ humaniq:payroll:reproduce --payslip <uuid>` SHALL load the named Payslip, refuse (non-zero exit, clear message) when `engineInputSnapshot` or `payrollRunId` is null (nothing to reproduce), otherwise resolve the referenced `PayrollRun`'s `engineVersion` to the engine artefact that produced it (the `NlEngineChecks::namesAKnownEngineArtefact()`/`PackRepository` resolution precedent), decode `engineInputSnapshot` back into a `CalculationInput`, recompute through that same artefact, and compare every D2 output component against the payslip's stored values cents-exact. A full match SHALL print a clear "reproduced" confirmation and exit `0`. Any mismatch SHALL name the first mismatching component with both the stored and recomputed values, and exit non-zero — never a silent pass. An unresolvable engine artefact (e.g. a deleted jurisdiction pack) SHALL be reported as a reproduction failure, not skipped.

**Live-verified end-to-end on 8080** (the actual point of hrmq#98): seeded employee Sanne de
Vries, ran `occ humaniq:payroll:run --period 2026-03`, approved (sealed) the run, then edited her
Employee record via the OR API (`taxTableColor: wit→groen`, `grossMonthlySalary: 3800→5000`,
`loonheffingskortingToegepast: true→false`) — a fresh `occ humaniq:payroll:run --period 2026-04`
confirmed the edit genuinely changes live computation (grossPay 5000, snapshot `taxTableColor:
groen`). `occ humaniq:payroll:reproduce --payslip <the sealed 2026-03 payslip>` still reported
`status: reproduced` — re-deriving the ORIGINAL sealed figures from the untouched snapshot, not
the edited live Employee data. Employee record restored afterward.

#### Scenario: A clean payslip reproduces exactly
- **GIVEN** a payslip generated by `occ humaniq:payroll:run --period 2026-02` with an intact `engineInputSnapshot`
- **WHEN** `occ humaniq:payroll:reproduce --payslip <uuid>` runs
- **THEN** it reports every component matches and exits `0`

#### Scenario: A tampered nettoPay is caught by reproduction
- **GIVEN** the same payslip with `nettoPay` edited directly in the register after generation
- **WHEN** `occ humaniq:payroll:reproduce --payslip <uuid>` runs
- **THEN** it reports `nettoPay` as the mismatching component with both the stored and recomputed cents values, and exits non-zero

#### Scenario: A payslip with no snapshot cannot be reproduced
- **GIVEN** the pre-existing hand-entered seeded Payslip
- **WHEN** `occ humaniq:payroll:reproduce --payslip <uuid>` runs against it
- **THEN** the command refuses with a message naming the missing `engineInputSnapshot`, and exits non-zero

### Requirement: Chain-of-custody verification for a PayrollRun SHALL reuse OpenRegister's existing hash chain, never a new one (REQ-AUDP-003)

`lib/Service/PayrollAuditVerificationService.php`, method `verifyRun(string $runId): array`, SHALL resolve the `PayrollRun`'s and its `Payslip`s' audit-trail row id range and SHALL call `OCA\OpenRegister\Service\AuditHashService::verifyChain($minId, $maxId)` over that resolved range, returning its `valid`/`entriesVerified`/`brokenAt`/`range` result unmodified plus the resolved run id. This service SHALL introduce no new hash function, no new chain, and no new stored field — it is orchestration over existing OpenRegister services only. Neither this requirement nor any other in this capability SHALL introduce external Time Stamping Authority anchoring, blockchain notarization, or a payroll-scoped second chain; OpenRegister's single global SHA-256 chain is the sole integrity mechanism for this capability's MVP.

**Implementation deviates from this requirement's original text on ONE point, for a verified
reason**: row-range resolution uses `OCA\OpenRegister\Service\Object\AuditHandler::getLogs($uuid)`,
NOT `AuditQueryService::query()` as originally drafted. Recon against HEAD (and confirmed live on
8080) found `AuditQueryService::query()` searches OBJECT rows in a register/schema that LOOKS LIKE
an audit schema by naming convention (the procest `aiAuditEntry` precedent it was built for) — it
does not, and cannot, query the `openregister_audit_trails` table `PayrollRun`/`Payslip` rows
actually live in. `AuditHandler::getLogs($uuid)` is OpenRegister's correct, already-existing
per-object read path for exactly this (already wired into humaniq's 43 audit-trail manifest widgets).
Zero new hashing/chaining code either way, confirmed by a unit test scanning the file for `hash(`/
`sha256` literals. `RuleAuditService.php` (flagged by recon as a possible bespoke re-chainer) was
checked and carries no hash/chain code — nothing to drop.

#### Scenario: An intact run verifies clean
- **GIVEN** a `PayrollRun` generated and never tampered with
- **WHEN** `PayrollAuditVerificationService::verifyRun($runId)` runs
- **THEN** it returns `valid: true` with `entriesVerified` covering the run's and its payslips' audit rows and `brokenAt: null`

#### Scenario: A tampered audit row is detected
- **GIVEN** a `PayrollRun` whose Payslip audit row was directly altered in the database after sealing
- **WHEN** `PayrollAuditVerificationService::verifyRun($runId)` runs
- **THEN** it returns `valid: false` with `brokenAt` naming the altered row's id

#### Scenario: No bespoke hash code exists for payroll
- **GIVEN** `lib/Service/PayrollAuditVerificationService.php`
- **WHEN** it is scanned for hash computation (`hash(`, `sha256`) or chain-storage code
- **THEN** none exists — every hash operation is a call into `OCA\OpenRegister\Service\AuditHashService`

### Requirement: An admin-facing, defensible audit export SHALL be renderable per PayrollRun through the existing docudesk consumption leaf (REQ-AUDP-004)

**NOT IMPLEMENTED — deferred.** `GeneratedDocument.employeeId` is a REQUIRED schema field (all four
existing document types are employee-anchored); a `payroll-audit-report` is scoped to a
`PayrollRun`, which has no natural employee to satisfy it. Loosening that requirement is an
invasive change touching 4 already-shipped document types — out of proportion to closing hrmq#98's
reproducibility gap within this session, and not requested by the fix brief. A follow-up change
should resolve the `employeeId` question (nullable field vs. a representative employeeId vs. a
separate schema) before building this requirement. The original requirement text is preserved
below for that follow-up.

`lib/Settings/register.d/hr-documents.json` SHALL extend `GeneratedDocument` (v0.2.0 → v0.3.0): `documentType` enum gains `payroll-audit-report` (append-only, non-breaking); a new nullable `$ref` `payrollRunId` (the `payslipId`/`jaaropgaafId` precedent, ADR-062 rule 7) names the run a report covers. `HrDocumentService` SHALL gain a `payroll-audit-report` generation path passing `dataRefs = [{register: hrmq, schema: PayrollRun, id: runId}]` and `adHocData.auditReport` carrying: each Payslip's `engineVersion` (via its run), `calculatedAt`, and `engineInputSnapshot`; the REQ-AUDP-003 `verifyRun()` result; and the raw audit-trail entries for the run's scope. Rendering SHALL follow the existing docudesk contract exactly (config-first/discovery-second template selection, FileService storage on the `GeneratedDocument`, `skipped-no-docudesk` degradation) — no PDF/ZIP library and no verification-script generator SHALL be added to humaniq.

#### Scenario: A report assembles the full provenance picture
- **GIVEN** a calculated `PayrollRun` with three engine-produced Payslips
- **WHEN** a `payroll-audit-report` `GeneratedDocument` is generated for it
- **THEN** `adHocData.auditReport` names all three payslips' `engineVersion`/`calculatedAt`/`engineInputSnapshot`, includes the chain-verification result, and includes the audit-trail entries for the run's scope

#### Scenario: Absent docudesk degrades, never throws
- **GIVEN** an instance without docudesk installed
- **WHEN** a `payroll-audit-report` is requested for a run
- **THEN** the `GeneratedDocument` ends `status: skipped-no-docudesk` with no exception

#### Scenario: humaniq ships no PDF or ZIP machinery
- **GIVEN** the humaniq `composer.json` and `lib/` tree after this change
- **WHEN** scanned for a PDF library or a ZIP-export/verifier-script generator
- **THEN** none exists — rendering happens exclusively through the docudesk `DocumentService`/`TemplateService` FQCN resolve

### Requirement: A payslip with missing or inconsistent provenance SHALL be flagged by an extension of the existing NlEngineChecks provider (REQ-AUDP-005)

`lib/Standards/Checks/NlEngineChecks.php` SHALL gain a third predicate, `nl-engine-provenance-complete`, registered under the same `Payslip` key alongside `nl-engine-output-consistency`. It SHALL be vacuous when `payrollRunId` is null or the resolved run (via the existing `payroll.runsById` audit context) carries no `engineVersion` (the `nl-engine-output-consistency` scoping precedent). Otherwise it SHALL require `engineInputSnapshot` to be non-empty, decode as valid JSON, and carry a `jurisdiction` field consistent with the resolved engine artefact — a payslip failing any of these SHALL be reported as a violation. This predicate SHALL NOT invoke `PayrollCalculator`/the pack interpreter (byte-exact recomputation is REQ-AUDP-002's job, not a per-audit-pass cost). `lib/Standards/rules/payroll.json` SHALL gain the `nl-engine-provenance-complete` rule row (`domain: tax`, `jurisdiction: NL`, `framework: payroll-core`, `severity: mandatory`, `machineCheckable: true`, `effectiveDate: 2026-01-01`, `sourceUrl` matching its two siblings). `RuleCatalogue::VERSION` SHALL bump `2026-07.25` → `2026-07.26`.

**Note**: also handles `engineInputSnapshot` arriving as an already-decoded array (the same
MagicMapper JSON-auto-decode behaviour noted under REQ-AUDP-001), via a shared `decodeSnapshot()`
helper.

#### Scenario: A freshly generated run's payslips pass
- **GIVEN** a run generated after this change (every payslip carries `engineInputSnapshot`)
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** no `nl-engine-provenance-complete` violation is reported for that run's payslips

#### Scenario: A payslip stripped of its snapshot violates
- **GIVEN** an engine-produced payslip whose `engineInputSnapshot` was cleared to null directly
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** an `nl-engine-provenance-complete` violation is reported for that payslip

#### Scenario: Hand-entered payslips stay vacuous
- **GIVEN** the pre-existing seeded Payslip (null `payrollRunId`)
- **WHEN** the audit runs
- **THEN** no `nl-engine-provenance-complete` violation is reported for it — identical to its existing vacuous status under the other two engine rules

### Requirement: Retention for payroll audit data SHALL reuse avg-dsr's classifier; this capability SHALL add no new retention field or logic (REQ-AUDP-006)

Neither `Payslip.engineInputSnapshot` nor any other field/service this capability introduces SHALL define a new retention field, a new expiry derivation, or a new erasure guard. `PayrollRun` and `Payslip` — including their new `engineInputSnapshot` data — SHALL remain governed exactly as today by `AvgDsrRetentionClassifier::PAYROLL_FAMILY_SCHEMAS` (the AWR art. 52 lid 4 7-year fallback, or a populated `retainedUntil` when present). `AvgDsrService::eraseSubject()`'s existing retention-locked exclusion SHALL continue to exclude a Payslip carrying `engineInputSnapshot` on exactly the same terms as it excludes one without it.

**Note on immutability**: the fix brief that commissioned this work suggested
`RetentionService::placeLegalHold()` for sealed-payslip immutability. This was investigated and
deliberately NOT used: it would be new logic this requirement explicitly rules out, and the
existing 7-year AWR retention lock already prevents the destruction/erasure paths (`AvgDsrService::
eraseSubject()`) from touching a retained Payslip — a second, bespoke immutability mechanism on top
would duplicate, not add, protection, contradicting this capability's own "reuse, not rebuild"
premise.

#### Scenario: A payslip with an input snapshot is still retention-locked correctly
- **GIVEN** an employee with an engine-produced Payslip whose `period` is within the last 7 years, `retainedUntil` null, and `engineInputSnapshot` populated
- **WHEN** `occ humaniq:avg:erase --employee <employeeNumber> --as-user admin --confirm --dsr-request-id <id>` runs
- **THEN** the Payslip (including its `engineInputSnapshot`) is unchanged and reported `"retained (wettelijke bewaarplicht)"`, identical to REQ-DSR-005's existing scenario

#### Scenario: No new retention field exists
- **GIVEN** the `hr-objects.json` fragment after this change
- **WHEN** `Payslip`'s new properties are enumerated
- **THEN** `engineInputSnapshot` is the only addition — no new retention-dated field was introduced
