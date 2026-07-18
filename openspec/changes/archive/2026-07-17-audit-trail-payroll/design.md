# Design — audit-trail-payroll

## Context

**Verified against HEAD 2026-07-17.** Read before writing any requirement, per the "delivered by
abstraction" discipline:

- `openregister/lib/Db/AuditTrail.php` — the entity: `uuid`, `schema`/`register`/`object` (+ UUID
  twins), `action`, `changed` (array, before/after), `user`/`userName`/`session`, `created`,
  `hash`/`previousHash`, plus AVG-processing fields (`organisationId`, `retentionPeriod`,
  `expires`) and the MCP-invocation fields (`toolId`/`paramsDigest`/`resultSummary`) — this last
  pair proves OR's audit trail is already the fleet's general-purpose event ledger, not a
  CRUD-only log.
- `openregister/lib/Service/AuditHashService.php` — `computeHash()` hashes the canonical JSON of
  an entry (hash/previousHash fields excluded, keys sorted) chained to the prior row's hash;
  `sealRow()`/`sealRows()` seal after insert; `getLastHash()`/`getHashBefore()` walk the chain;
  `verifyChain(?from, ?to)` recomputes every hash in a row-id range and reports `valid`,
  `entriesVerified`, `brokenAt`. The chain is **one sequence over the whole
  `openregister_audit_trails` table**, genesis-seeded (`GENESIS_SEED`), not scoped per app,
  register, schema, or object.
- `openregister/lib/Service/AuditQueryService.php` — `query(filters, limit, offset)` over
  audit-entry-shaped objects, filters `registerId`/`schemaId`/`objectId`/`app`/
  `timestampStart`/`timestampEnd`, clamps `limit` to `[1,200]`. Built for a different original use
  case (app-defined audit-entry *objects*, e.g. procest's `aiAuditEntry`) but works identically
  over `openregister_audit_trails` rows when scoped by `registerId`+`schemaId`.
- `openregister/lib/Service/Object/AuditHandler.php` — `getLogs($uuid, $filters)` is the
  per-object read path already wired into hrmq's 43 `audit-trail` manifest widgets.
- `lib/Service/PayrollRunService.php` — `generate()` builds one `CalculationInput` per employee
  per period (`lib/Payroll/CalculationInput.php`, 10 public readonly fields), calls
  `$this->calculator->calculate($input, $tables)`, writes the D2 output components onto the
  `Payslip` payload, stamps `PayrollRun.engineVersion`/`calculatedAt` — and **the `CalculationInput`
  instance is never referenced again after `calculate()` returns.**
- `lib/Standards/Checks/NlEngineChecks.php` — `nl-engine-table-version` (`PayrollRun`: vacuous on
  null `engineVersion`; else `calculatedAt` present + artefact resolvable via
  `namesAKnownEngineArtefact()`, which accepts both the legacy bare table id and the
  `{packId}@{packVersion}` form) and `nl-engine-output-consistency` (`Payslip`: vacuous on null
  `payrollRunId`/unresolvable run; else cents-exact net-equation check). Neither predicate touches
  input provenance — both are output-side.
- `lib/Service/AvgDsrRetentionClassifier.php` — `PAYROLL_FAMILY_SCHEMAS` already includes
  `PayrollRun` and `Payslip`; `derivedRetentionDate()` is the AWR art. 52 lid 4 7-year fallback
  (period year + 7, 31 Dec), superseded by a populated `retainedUntil` when present.
- `openspec/specs/payslip-pdf-docudesk/spec.md` / `hrmq-docudesk-documents/spec.md` — the
  established "hrmq assembles data, docudesk renders" pipe: `HrDocumentService` resolves docudesk's
  `DocumentService`/`TemplateService` by FQCN (duck-typed, same-instance), passes `dataRefs` +
  `adHocData`, stores the returned PDF on a `GeneratedDocument` via `FileService`, degrades to
  `skipped-no-docudesk`.

## Goals / Non-Goals

**Goals:** pin the exact resolved inputs behind every engine-produced payslip so a future verifier
can re-derive it independent of later `Employee`/`EmploymentContract` edits; give a verifier a
command that actually re-runs the engine and compares; reuse OR's existing hash chain to prove a
run's audit rows are unbroken, rather than building a second one; package that into one exportable
artefact through the existing docudesk leaf; flag payslips that lost their provenance; touch
nothing already delivered (retention, hash-sealing, per-object change history).

**Non-Goals:** any new hash/chain mechanism (D2); any external Time Stamping Authority or
blockchain anchoring (D2); any new retention field or retention derivation (D5); reconstructing
historical `Employee`/`EmploymentContract` state from OR's audit `changed` diffs (a materially
larger, independently-scoped capability — D1); a bespoke access-control role for audit reads (the
export rides the existing admin-only `DocumentController` guard precedent); CAO/labour-court
audit needs beyond payroll figures (out of this capability's name).

## Decisions

### D1 — Reproducibility is an input SNAPSHOT on the payslip, not a point-in-time OR reconstruction

Two ways exist to answer "what inputs produced this 2026 payslip, asked in 2029": (a) reconstruct
`Employee`/`EmploymentContract` state as of `calculatedAt` from OR's per-object audit `changed`
diffs, or (b) persist the resolved `CalculationInput` at generation time. (a) is strictly more
general (it would also answer "what did the Employee record look like then" for non-payroll
purposes) but OR exposes no point-in-time reconstruction API today — `AuditHandler::getLogs()`
and `AuditQueryService::query()` both return the *log*, not a materialised past state; walking
every `changed` diff since object creation and folding it forward is a correct but substantial new
capability, unscoped by this proposal and un-asked-for by the brief. (b) is small, uses a struct
that already exists and is already fully resolved at exactly the right moment
(`PayrollRunService::generate()`'s `$input` local), and needs one new nullable string field.
**Decision: (b).** `Payslip.engineInputSnapshot` = canonical JSON (sorted keys, the
`AuditHashService::getCanonicalJson()` precedent for "canonical" in this codebase) of
`CalculationInput`'s 10 public readonly properties (`grossMonthlySalaryCents`, `taxTableColor`,
`loonheffingskortingToegepast`, `dateOfBirth`, `period`, `awfTariff`, `aofTariff`,
`whkPercentage`, `verzekeringsplichtig`, `jurisdiction`). Written once, at generation time, never
updated after — a snapshot field, structurally immutable the same way `engineVersion`/
`calculatedAt` are (a draft-only-recalculation rewrite replaces it wholesale via the same upsert,
never edits it in place).

### D2 — Integrity reuses OR's global chain; no per-run chain, no external anchor

`AuditHashService::verifyChain(from, to)` already recomputes and detects tampering over any row-id
range. A `PayrollRun`'s and its `Payslip`s' audit rows are a *subset* of that global sequence,
interleaved with every other object's writes between them — which is a feature, not a limitation:
verifying the range also proves nothing else was tampered with in that window. The only missing
piece is *finding the range*: `PayrollAuditVerificationService::verifyRun($runId)` loads the run's
payslip ids (`PayrollRunService`'s existing `existingByEmployeeId`-shaped read, or a fresh
`ObjectService::find` on `payrollRunId`), calls `AuditQueryService::query()` filtered to
`register: hrmq`, `schema: PayrollRun|Payslip`, `objectId` per id, takes the min/max row id from
the returned entries, and calls `verifyChain(min, max)`. **No new hash function, no new chain, no
new stored field** — this is pure orchestration over two existing OR services. An external TSA or
blockchain anchor is named in the proposal's Out of Scope and not built: OR's chain already proves
*internal* non-tampering; external notarization proves the chain itself wasn't wholesale replaced
by someone with DB access, a materially different (and currently un-demanded) threat model.

### D3 — The auditor export rides the existing docudesk leaf, not a new export pipeline

The old draft's F-006 proposed a bespoke ZIP-with-verifier-script exporter. hrmq already has
exactly the "assemble structured data, hand it to a renderer, store the PDF" pipe
(`HrDocumentService`, `payslip-pdf-docudesk`'s precedent for extending `documentType` +
`dataRefs`/`adHocData` shape). A `payroll-audit-report` `GeneratedDocument` reuses it: `dataRefs =
[{register: hrmq, schema: PayrollRun, id: runId}]` (the docudesk template resolves the run's own
fields directly); `adHocData.auditReport` carries what docudesk cannot re-resolve itself — the
per-payslip `engineVersion`/`calculatedAt`/`engineInputSnapshot` triple, the D2 chain-verification
result (`valid`/`entriesVerified`/`brokenAt`/range), and the raw `AuditQueryService` entries for
the run's scope (actor, action, timestamp per row) — assembled by hrmq, rendered by docudesk,
exactly the established division of authority. No PDF library, no ZIP, no verifier script ships in
hrmq.

### D4 — The new rule extends `NlEngineChecks`, checks presence+consistency, never recomputes

The brief is explicit: `nl-engine-table-version` and `nl-engine-output-consistency` already exist
and must be extended, not duplicated. Neither currently touches `engineInputSnapshot` — the first
is run-scoped (traceability of the *code+parameters* artefact), the second is a *pure output*
equation with no engine dependency. A third predicate closes the input-provenance gap:
`nl-engine-provenance-complete` (`Payslip`, same provider file, same corpus file
`lib/Standards/rules/payroll.json`, same `payroll-core` framework family). Vacuous when
`payrollRunId` is null or the resolved run carries no `engineVersion` (hand-entered — the
`nl-engine-output-consistency` scoping precedent, reusing the `payroll.runsById` audit context
`RuleAuditService::audit()` already enriches). Else: `engineInputSnapshot` must be non-empty, must
`json_decode` without error, and its decoded `jurisdiction` field (present on every
`CalculationInput` serialization) must be consistent with the run's engine artefact having
resolved for that jurisdiction. **Deliberately does NOT recompute the payslip inside the
predicate** — corpus checks stay pure/cheap-per-object (the `nl-engine-output-consistency`
precedent recomputes a formula, not an engine run); byte-exact recomputation is
`hrmq:payroll:reproduce`'s job (REQ-AUDP-002), invoked on demand, not on every audit pass.

### D5 — Retention: reuse, zero new logic (explicit no-op requirement, not silent omission)

`AvgDsrRetentionClassifier::PAYROLL_FAMILY_SCHEMAS` already lists `PayrollRun`/`Payslip`; the
7-year AWR fallback and the `retainedUntil`-wins-when-populated rule already apply to every field
on those schemas, including the new `engineInputSnapshot` (it is just another field on an
already-covered schema — the classifier operates at object granularity, not field granularity).
REQ-AUDP-006 exists so this decision is a checked, tested boundary — "we verified this, we are not
duplicating it" — rather than a gap nobody noticed.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Payslip.engineInputSnapshot`, `GeneratedDocument.documentType`/`payrollRunId` | declarative fragment edit | ADR-031 default; Repair re-import picks up version bumps |
| Input-snapshot serialization + stamping | imperative PHP (`PayrollRunService`) | mirrors the existing `engineVersion`/`calculatedAt` stamp, itself imperative |
| `hrmq:payroll:reproduce` recompute-and-compare | imperative PHP (occ command + service) | re-running a statutory formula chain is exactly the `payroll-core-engine` ADR-031 exception, same class |
| `PayrollAuditVerificationService::verifyRun()` | imperative PHP, but ZERO new hashing logic | pure orchestration over `AuditQueryService`/`AuditHashService`, both already imperative in OR |
| Auditor export data assembly | imperative PHP (`HrDocumentService` extension) | the established `payslip-pdf-docudesk` precedent — hrmq assembles, docudesk renders |
| `nl-engine-provenance-complete` | corpus rule (data) + `CheckProvider` predicate | the app's established ADR-031 corpus exception, same file as its two siblings |

## Seed Data (ADR-001)

No new seed objects. `Payslip.engineInputSnapshot` is nullable and defaults null — every existing
seeded (hand-entered) Payslip stays valid and vacuous under `nl-engine-provenance-complete`
exactly as it already is under `nl-engine-table-version`/`nl-engine-output-consistency`.
`GeneratedDocument.documentType` gains an enum value append-only (non-breaking, the
`payslip-pdf-docudesk` precedent). `occ hrmq:rules:audit` against existing seeds stays exactly as
green as before this change — engine-produced golden-fixture payslips (from
`payroll-core-engine`'s tests, not seed data) are the only records this change can make non-vacuous,
and they are produced fresh by `PayrollRunService::generate()`, which this change updates to stamp
`engineInputSnapshot` on every such payslip going forward.

## Risks / Trade-offs

- **`engineInputSnapshot` grows unboundedly if `CalculationInput` grows.** Accepted — it is a
  small, bounded value object (10 scalar fields) by design (D1 of `payroll-core-engine`'s own
  design.md); any future field addition to `CalculationInput` is by definition also a field the
  reproducibility guarantee needs captured, so the two stay coupled deliberately.
- **`hrmq:payroll:reproduce` can only recompute what the *current* engine artefact still supports.**
  A jurisdiction pack could theoretically be deleted; `namesAKnownEngineArtefact()` already guards
  this at the `nl-engine-table-version` level (an unresolvable `engineVersion` is a **violation**,
  reported, not silently skipped) — the same guard applies here: an unresolvable artefact is a
  reported reproduction failure, not a silent pass.
- **The global OR chain is not payroll-exclusive.** A determined attacker with DB write access and
  the ability to reseal rows could, in principle, rewrite payroll history and the surrounding
  chain consistently. This is the accepted MVP threat model (per Out of Scope); external
  notarization is the named follow-up if that threat model changes.

## Open Questions

- None blocking.
