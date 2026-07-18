---
capability: avg-dsr
status: done
built_by: openspec/changes/archive/2026-07-15-avg-dsr
revised_by: hrmq#99 (consume-not-rebuild correction, 2026-07-18)
---

# avg-dsr Specification

**Status**: done
**Scope**: hrmq (`depends_on: []`)
**OpenSpec changes**:
- [avg-dsr](../../changes/archive/2026-07-15-avg-dsr/) _(archived 2026-07-15)_
  — AVG (GDPR) data-subject-rights orchestration: a `DsrRequest` lifecycle record, the
  subject-mapping and two-way export rendering for Art 15 inzage / Art 20 portabiliteit, the Art 16
  rectificatie pass-through, and the guarded `occ`/HTTP invocation surfaces.
- **hrmq#99** _(2026-07-18, consume-not-rebuild correction)_ — the original design called the
  *privileged, unguarded* `OCA\OpenRegister\Service\DsarService` directly and reimplemented
  retention-guarding itself (`AvgDsrRetentionClassifier`: a bespoke `retainedUntil`/
  `identityDocumentRetainedUntil` field plus a hand-rolled AWR art. 52 lid 4 "period year + 7"
  derivation), duplicating OpenRegister's own retention/legal-hold machinery
  (`RetentionService`/`Gdpr\DataSubjectRequestService`). This left two real AVG retention holes: a
  generated loonstrook/jaaropgaaf PDF (`GeneratedDocument`) carried no retention signal of its own,
  and nothing flagged a record still present past its own retention ceiling. hrmq#99 switches
  `AvgDsrService` to consume OpenRegister's guarded, RBAC/tenant-scoped
  `Gdpr\DataSubjectRequestService::erase()`/`rectify()`/`findSubjectData()` directly — retention is
  now an OBJECT property (legal hold / archival status), maintained proactively by
  `PayrollRetentionGuardService`, never a classification recomputed ad hoc at DSAR time.
  `AvgDsrRetentionClassifier` is deleted.

## Purpose

OpenRegister ships two data-subject-request surfaces. `OCA\OpenRegister\Service\DsarService` is
privileged/admin-only and UNGUARDED — it has no per-object retention exclusion, so hrmq must never
call it directly for erasure (that was the original design's mistake). `OCA\OpenRegister\Service
\Gdpr\DataSubjectRequestService` is the RBAC/tenant-scoped, GUARDED counterpart: its `erase()`
refuses (reports as `held`, never silently skips) any object under an active legal hold
(`RetentionService::hasActiveLegalHold()`) or an immutable archival status
(`RetentionService::validateNotImmutable()`) on its own. hrmq owns no database tables and no
PII-detection/entity-matching/retention-guarding logic of its own (ADR-022); this capability is a
thin orchestration layer over the guarded service's `findSubjectData()`/`erase()`/`rectify()` — never
a reimplementation of anonymisation, entity matching, soft-delete, or retention exclusion.

The guarded service's own retention check does **not** gate on a populated
`retention.archiefactiedatum` — only on an active legal hold or an immutable archival status
(openregister#475). The second load-bearing correctness requirement therefore is:
hrmq's payroll/loonadministratie data carries a statutory 7-year fiscal retention duty (AWR art. 52
lid 4), and for that duty to actually block an erase, a real OpenRegister legal hold must be placed
— a date field alone is not protective. `PayrollRetentionGuardService` (consumed by
`HrDocumentService` and, going forward, wherever a payroll object is created/updated) reads an
already-known ceiling — a populated `retainedUntil` field (unrelated FR/US/DE compliance-evidence
checks already populate one), or OpenRegister's own computed `retention.archiefactiedatum` for a
schema with an `archive` config — and syncs a legal hold onto the object when that ceiling has not
yet passed. It derives no date itself. A generated loonstrook/jaaropgaaf PDF
(`GeneratedDocument`) inherits the SAME legal hold from its source Payslip (hrmq#99 hole #1), and
`NlDossierRetentionChecks::nl-bewaartermijn-verstreken` flags (recommended severity, never acts on)
a record still present past its own `retention.archiefactiedatum` (hrmq#99 hole #2).

## Requirements

### Requirement: A DsrRequest schema SHALL model the AVG data-subject-rights request lifecycle (REQ-DSR-001)

`lib/Settings/register.d/hr-dsr.json` SHALL define a `DsrRequest` schema carrying `employeeId` (`$ref` Employee, the requesting subject), `right` (enum `inzage`/`verwijdering`/`rectificatie`/`portabiliteit`), a plain `status` enum (`ontvangen`/`in_behandeling`/`voldaan`/`afgewezen`, default `ontvangen`, no `x-openregister-lifecycle` map), `receivedDate`, `deadlineDate`, `handledBy`, `completedDate`, `outcomeSummary`, `retainedObjectRefs`, and `rejectionReason`.

#### Scenario: A DsrRequest carries every required lifecycle field
- **GIVEN** an employee submits an AVG inzage request
- **WHEN** a `DsrRequest` object is created with `employeeId`, `right: inzage`, and `receivedDate`
- **THEN** the object validates against the schema and `status` defaults to `ontvangen`

#### Scenario: The statutory deadline is derived from the received date
- **GIVEN** a `DsrRequest` with `receivedDate` 2026-07-16
- **WHEN** the request is processed
- **THEN** `deadlineDate` is 2026-08-16 (one calendar month, AVG art. 12 lid 3), with no extension applied by this MVP

### Requirement: The subject identifier SHALL be the employee linkage, never a persisted raw BSN value (REQ-DSR-002)

`DsrRequest.employeeId` SHALL be the only persisted subject-identifying field; `AvgDsrService` SHALL resolve the guarded service's subject value (`Employee.bsn`) transiently, in memory, from the RBAC-resolved `Employee` object at call time, and SHALL NOT write it, or any other special-category value, onto `DsrRequest` or into any log line.

#### Scenario: The resolved subject value is used but not persisted
- **GIVEN** a `DsrRequest` referencing an `Employee` with a populated `bsn`
- **WHEN** `AvgDsrService` calls `Gdpr\DataSubjectRequestService::findSubjectData()` or `erase()` for that request
- **THEN** the raw `bsn` value is passed as the `$subjectId` argument in memory and does not appear afterward in `DsrRequest.outcomeSummary`, `DsrRequest.retainedObjectRefs`, or any logged message

### Requirement: Export SHALL call findSubjectData() once and render it for both inzage and portabiliteit (REQ-DSR-003)

`AvgDsrService::exportForSubject()` SHALL call OpenRegister's guarded `Gdpr\DataSubjectRequestService::findSubjectData()` exactly once per export request and SHALL render the same result set as a human-readable grouped-by-object overview for `right: inzage` and as a flattened structured document for `right: portabiliteit`, without a second guarded-service call for the second right.

#### Scenario: Inzage renders a human-readable overview
- **GIVEN** an employee with objects matched by `findSubjectData()`
- **WHEN** `occ hrmq:avg:export --employee <employeeNumber> --as-user admin --right inzage` runs
- **THEN** the output groups matched objects with their `gdprEntities` annotations, and `DsrRequest.right` is `inzage`

#### Scenario: Portabiliteit renders the same objects as a structured export
- **GIVEN** the same employee and matched objects
- **WHEN** `occ hrmq:avg:export --employee <employeeNumber> --as-user admin --right portabiliteit` runs
- **THEN** the output is a single flattened structured document containing every object `findSubjectData()` returned, and `Gdpr\DataSubjectRequestService::findSubjectData()` is invoked exactly once for this request

### Requirement: An authenticated session SHALL back every guarded-service call; an unprivileged caller SHALL receive a controlled error, never a silent skip or an uncaught throw (REQ-DSR-004)

_(Revised hrmq#99: the previous title/body described `DsarService::assertPrivileged()`'s admin-only requirement. `Gdpr\DataSubjectRequestService` is RBAC/tenant-scoped, not privileged-admin-only — it does not itself require `IGroupManager::isAdmin()` or throw a privilege `RuntimeException`. The mechanism below is retained as hrmq's own POLICY (AVG data-subject-rights handling for an employee stays administrator-only in this app) and because CLI invocation has no ambient session by default, not because the consumed OpenRegister service demands it.)_

Every `occ hrmq:avg:*` command SHALL require a `--as-user <uid>` option, resolve and validate that user as an actual Nextcloud administrator (`IUserManager::get()` + `IGroupManager::isAdmin()`) before calling `IUserSession::setUser()` — establishing the session the guarded service's ambient RBAC/tenant scoping and audit attribution need — and SHALL catch any `RuntimeException` as a defensive measure, translating it into a one-line stderr message with a non-zero exit code. `AvgDsrController` SHALL gate every method with `#[NoAdminRequired]` plus a manual admin-only 403 check before any resolve (hrmq policy, REQ-DSR-004), and SHALL catch the same `RuntimeException` defensively, translating it into a 403 JSON response.

#### Scenario: An unresolvable --as-user is refused before any guarded-service call
- **GIVEN** `occ hrmq:avg:export --employee E1 --as-user nonexistent-uid`
- **WHEN** the command runs
- **THEN** it prints a one-line error naming the unknown user, exits with code 1, and `Gdpr\DataSubjectRequestService` is never invoked

#### Scenario: A non-admin --as-user is refused before any guarded-service call
- **GIVEN** `occ hrmq:avg:erase --employee E1 --as-user regular-user` where `regular-user` is a valid but non-admin Nextcloud account
- **WHEN** the command runs
- **THEN** it prints a one-line error stating administrator privileges are required, exits with code 1, and `Gdpr\DataSubjectRequestService` is never invoked

#### Scenario: A valid admin --as-user establishes the session the guarded service uses for RBAC/tenant scoping
- **GIVEN** `occ hrmq:avg:export --employee E1 --as-user admin` where `admin` is a real Nextcloud administrator
- **WHEN** the command runs
- **THEN** `IUserSession::setUser()` is called with the resolved admin before `AvgDsrService` runs, and the command completes successfully

#### Scenario: A non-admin HTTP caller is refused before any resolve
- **GIVEN** a caller who is not a member of the admin group
- **WHEN** they POST to `/api/dsr/export` with any `employeeId`, including one that does not exist
- **THEN** the response is 403 and no ObjectService resolve, guarded-service call, or write occurs

#### Scenario: A RuntimeException from the guarded service is translated, never surfaced as a raw 500
- **GIVEN** the guarded service throws an unexpected `RuntimeException` for any reason
- **WHEN** the controller calls `AvgDsrService`
- **THEN** the resulting `RuntimeException` is caught and returned as a 403 JSON response, never an uncaught 500

### Requirement: Erasure SHALL be enforced by OpenRegister's own guarded service — a legal hold or immutable archival status refuses the object, reported as held, never silently deleted or silently skipped (REQ-DSR-005)

_(Revised hrmq#99, consume-not-rebuild correction: the original design computed retention-locking itself in a bespoke `AvgDsrRetentionClassifier` — a `retainedUntil`/`identityDocumentRetainedUntil` field plus a hand-rolled AWR art. 52 lid 4 "period year + 7" derivation — and excluded classified objects before calling the privileged, unguarded `DsarService`. `AvgDsrRetentionClassifier` is DELETED. `AvgDsrService::previewErasure()`/`eraseSubject()` now call OpenRegister's guarded `Gdpr\DataSubjectRequestService::erase()` directly, which refuses an object under an active legal hold (`RetentionService::hasActiveLegalHold()`) or an immutable archival status (`RetentionService::validateNotImmutable()`) on its own, reporting it in the `held` bucket. This guard does NOT itself check `retention.archiefactiedatum` (openregister#475) — a populated ceiling date alone is not protective. `PayrollRetentionGuardService` is the mechanism that turns an already-known ceiling (a populated `retainedUntil` field, or OpenRegister's own computed `retention.archiefactiedatum` for a schema with an `archive` config) into a real legal hold, so the guard above actually fires; it derives no retention duration itself.)_

`AvgDsrService::eraseSubject()` SHALL call `Gdpr\DataSubjectRequestService::erase()` directly (mode `pseudonymise`, `dryRun: false`) and SHALL NOT compute any retention classification of its own. Every object the guarded service reports in its `held` bucket SHALL be included in the outcome's `retained` list unchanged (uuid + hold/immutability reason) — never silently dropped.

#### Scenario: A legal-hold-protected Payslip is refused and reported, not erased
- **GIVEN** an employee with a `Payslip` under an active OpenRegister legal hold (placed by `PayrollRetentionGuardService` for its still-open 7-year AWR window, or manually)
- **WHEN** `occ hrmq:avg:erase --employee <employeeNumber> --as-user admin --confirm --dsr-request-id <id>` runs against a request whose preview already ran
- **THEN** that Payslip's data is unchanged, the guarded service's `erase()` reports it in `held` (never in `erased`), and it appears in the outcome's `retained` list with its hold reason

#### Scenario: A generated PDF of a legal-hold-protected Payslip is also refused (hrmq#99 hole #1)
- **GIVEN** a loonstrook `GeneratedDocument` whose source `Payslip` is under an active legal hold, generated after `HrDocumentService`'s hole-#1 inheritance ran (`PayrollRetentionGuardService::inheritLegalHold()`)
- **WHEN** the same guarded erase runs for that employee
- **THEN** the `GeneratedDocument` is ALSO reported in `held`, not `erased` — the PDF record does not evade the guard the underlying Payslip carries

#### Scenario: A non-retained object among a mix is still erased
- **GIVEN** an employee with one legal-hold-protected Payslip and one object with no active hold and no immutable archival status
- **WHEN** the erase executes
- **THEN** the eligible object is pseudonymised by the guarded service's `erase()`, the Payslip is untouched and reported as held, and the outcome's `erased` and `retained` lists both name their respective objects — neither is silently omitted

#### Scenario: No active hold uses the guarded service's own single-pass erase
- **GIVEN** an employee with matched objects, none of which carry an active legal hold or an immutable archival status
- **WHEN** the erase executes
- **THEN** `AvgDsrService` calls `Gdpr\DataSubjectRequestService::erase()` once for the whole subject, and the outcome's `retained` list is empty

### Requirement: Erasure SHALL always preview before any write, and execution SHALL require an explicit confirmation tied to that preview (REQ-DSR-006)

`AvgDsrService::previewErasure()` SHALL perform zero writes by calling the guarded service's own `erase(..., dryRun: true)` and SHALL return the same `held`/`erased`/`failed` buckets `eraseSubject()` would produce, relabelled `retained`/`wouldErase`/`failed`. `occ hrmq:avg:erase` SHALL default to preview-only and SHALL require both `--confirm` and a `--dsr-request-id` referencing a `DsrRequest` with `status: in_behandeling` and a previously recorded preview before performing any write; `AvgDsrController::eraseConfirm()` SHALL enforce the identical precondition.

#### Scenario: A bare erase command previews without writing
- **GIVEN** `occ hrmq:avg:erase --employee E1 --as-user admin` with no `--confirm`
- **WHEN** the command runs
- **THEN** it prints the `wouldErase`/`retained`/`failed` preview lists and no object's data changes (a dry run performs no object writes)

#### Scenario: Confirm without a prior preview is refused
- **GIVEN** no `DsrRequest` exists yet for the employee, or the referenced request's preview was never recorded
- **WHEN** `occ hrmq:avg:erase --employee E1 --as-user admin --confirm --dsr-request-id <id>` runs
- **THEN** the command is refused with a controlled error and no write occurs

#### Scenario: Confirm after a recorded preview executes the guarded erase
- **GIVEN** a `DsrRequest` in `in_behandeling` whose preview already ran
- **WHEN** `--confirm --dsr-request-id <id>` is supplied
- **THEN** the erase executes per REQ-DSR-005's guarded behaviour, and `DsrRequest.status` becomes `voldaan` when `failed` is empty or `afgewezen` naming the failures otherwise

### Requirement: Rectification SHALL apply changes directly via the guarded service's rectify(), blocked only by an immutable archival status (REQ-DSR-007)

_(Revised hrmq#99: `Gdpr\DataSubjectRequestService::rectify(string $objectIdentifier, array $changes)` takes the object's id/uuid directly, replacing `DsarService::rectifyObjectForSubject(int $objectId, ...)` — no internal-int-id resolution workaround is needed in hrmq anymore.)_

`AvgDsrService::rectifySubjectObject()` SHALL call `Gdpr\DataSubjectRequestService::rectify()` directly for the named object identifier and change set, SHALL NOT apply the legal-hold guard of REQ-DSR-005 (a correction does not remove data; only an immutable archival status blocks it, per the guarded service's own `rectify()`), and SHALL record only the changed field names, never before/after PII values, on `DsrRequest.outcomeSummary`.

#### Scenario: A rectification updates the object and records only field names
- **GIVEN** an `Employee` with a misspelled `lastName`
- **WHEN** `occ hrmq:avg:rectify --employee E1 --as-user admin --changes '{"lastName":"Corrected"}'` runs
- **THEN** the `Employee` object's `lastName` is updated, `DsrRequest.status` becomes `voldaan`, and `outcomeSummary` names `lastName` as changed without recording the old or new value

#### Scenario: A failed rectification is reported, not silently dropped
- **GIVEN** `rectify()` returns `null` (the object could not be loaded, or is in an immutable archival status)
- **WHEN** the rectify command runs
- **THEN** `DsrRequest.status` becomes `afgewezen` with a `rejectionReason`, and the command exits with a non-zero code

### Requirement: AVG data-subject-rights operations SHALL be invocable via occ hrmq:avg:* commands and a guarded admin-only endpoint/manifest surface, never a bare unguarded call (REQ-DSR-008)

hrmq SHALL expose `occ hrmq:avg:export`, `occ hrmq:avg:erase`, and `occ hrmq:avg:rectify` (each per REQ-DSR-004's privileged-session mechanism) and SHALL expose `AvgDsrController`'s `export()`/`erasePreview()`/`eraseConfirm()`/`rectify()` endpoints, registered in `appinfo/routes.php` before the SPA catch-all. `src/manifest.json` SHALL expose a `DsrRequests` index and `DsrRequestDetail` page as an admin-only surface with page actions wired to these endpoints as `api-call` actions, never as a `lifecycleActions` widget.

#### Scenario: All three occ commands are registered and require --as-user
- **GIVEN** the app is installed
- **WHEN** `occ list hrmq:avg` runs
- **THEN** `hrmq:avg:export`, `hrmq:avg:erase`, and `hrmq:avg:rectify` are listed, and each requires `--as-user`

#### Scenario: The manifest surface wires guarded actions, not a lifecycle widget
- **GIVEN** `src/manifest.json`'s `DsrRequestDetail` page
- **WHEN** the page renders for an admin caller
- **THEN** its transition actions are `api-call` page actions targeting `/api/dsr/*` endpoints, and no `lifecycleActions` widget is present
