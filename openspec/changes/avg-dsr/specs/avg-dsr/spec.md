# Delta — avg-dsr

AVG (GDPR) data-subject-rights orchestration over OpenRegister's `DsarService`: a `DsrRequest` lifecycle record, the
subject-mapping and two-way export rendering for Art 15 inzage / Art 20 portabiliteit, the explicit privileged-
session establishment `DsarService::assertPrivileged()` requires, the retention-guarded two-path erase for Art 17
vergetelheid, the Art 16 rectificatie pass-through, and the guarded `occ`/HTTP invocation surfaces.

## ADDED Requirements

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

`DsrRequest.employeeId` SHALL be the only persisted subject-identifying field; `AvgDsrService` SHALL resolve the `DsarService` subject value (`Employee.bsn`) transiently, in memory, from the RBAC-resolved `Employee` object at call time, and SHALL NOT write it, or any other special-category value, onto `DsrRequest` or into any log line.

#### Scenario: The resolved subject value is used but not persisted
- **GIVEN** a `DsrRequest` referencing an `Employee` with a populated `bsn`
- **WHEN** `AvgDsrService` calls `findObjectsForSubject()` or `eraseObjectsForSubject()` for that request
- **THEN** the raw `bsn` value is passed as the `$subject` argument in memory and does not appear afterward in `DsrRequest.outcomeSummary`, `DsrRequest.retainedObjectRefs`, or any logged message

### Requirement: Export SHALL call findObjectsForSubject() once and render it for both inzage and portabiliteit (REQ-DSR-003)

`AvgDsrService::exportForSubject()` SHALL call `DsarService::findObjectsForSubject()` exactly once per export request and SHALL render the same result set as a human-readable grouped-by-object overview for `right: inzage` and as a flattened structured document for `right: portabiliteit`, without a second `DsarService` call for the second right.

#### Scenario: Inzage renders a human-readable overview
- **GIVEN** an employee with objects matched by `findObjectsForSubject()`
- **WHEN** `occ hrmq:avg:export --employee <employeeNumber> --as-user admin --right inzage` runs
- **THEN** the output groups matched objects with their `gdprEntities` annotations, and `DsrRequest.right` is `inzage`

#### Scenario: Portabiliteit renders the same objects as a structured export
- **GIVEN** the same employee and matched objects
- **WHEN** `occ hrmq:avg:export --employee <employeeNumber> --as-user admin --right portabiliteit` runs
- **THEN** the output is a single flattened structured document containing every object `findObjectsForSubject()` returned, and `DsarService::findObjectsForSubject()` is invoked exactly once for this request

### Requirement: Every DsarService call SHALL run under an explicitly-established privileged session; an unprivileged caller SHALL receive a controlled error, never a silent skip or an uncaught throw (REQ-DSR-004)

Every `occ hrmq:avg:*` command SHALL require a `--as-user <uid>` option, resolve and validate that user as an actual Nextcloud administrator (`IUserManager::get()` + `IGroupManager::isAdmin()`) before calling `IUserSession::setUser()`, and SHALL catch any `RuntimeException` `DsarService::assertPrivileged()` throws and translate it into a one-line stderr message with a non-zero exit code. `AvgDsrController` SHALL gate every method with `#[NoAdminRequired]` plus a manual `isAdminOrHr()` 403 check before any resolve, and SHALL catch the same `RuntimeException` and translate it into a 403 JSON response.

#### Scenario: An unresolvable --as-user is refused before any DsarService call
- **GIVEN** `occ hrmq:avg:export --employee E1 --as-user nonexistent-uid`
- **WHEN** the command runs
- **THEN** it prints a one-line error naming the unknown user, exits with code 1, and `DsarService` is never invoked

#### Scenario: A non-admin --as-user is refused before any DsarService call
- **GIVEN** `occ hrmq:avg:erase --employee E1 --as-user regular-user` where `regular-user` is a valid but non-admin Nextcloud account
- **WHEN** the command runs
- **THEN** it prints a one-line error stating administrator privileges are required, exits with code 1, and `DsarService` is never invoked

#### Scenario: A valid admin --as-user establishes the session DsarService requires
- **GIVEN** `occ hrmq:avg:export --employee E1 --as-user admin` where `admin` is a real Nextcloud administrator
- **WHEN** the command runs
- **THEN** `IUserSession::setUser()` is called with the resolved admin before `AvgDsrService` runs, `DsarService::assertPrivileged()` does not throw, and the command completes successfully

#### Scenario: A non-admin/HR HTTP caller is refused before any resolve
- **GIVEN** a caller who is not a member of the admin group
- **WHEN** they POST to `/api/dsr/export` with any `employeeId`, including one that does not exist
- **THEN** the response is 403 and no ObjectService resolve, `DsarService` call, or write occurs

#### Scenario: A RuntimeException from assertPrivileged is translated, never surfaced as a raw 500
- **GIVEN** `AvgDsrController`'s `isAdminOrHr()` gate somehow passed for a caller `DsarService::assertPrivileged()` would still reject
- **WHEN** the controller calls `AvgDsrService`
- **THEN** the resulting `RuntimeException` is caught and returned as a 403 JSON response, never an uncaught 500

### Requirement: Erasure SHALL exclude every retention-locked object and report it as retained (wettelijke bewaarplicht), never silently delete or silently skip it (REQ-DSR-005)

`AvgDsrService::classifyForErasure()` SHALL classify each object `findObjectsForSubject()` returns as retention-locked when it carries a populated `retainedUntil` or `identityDocumentRetainedUntil` field dated on or after today, or, when neither is populated, when its schema is in the payroll/loonadministratie family and its derived retention date (period year + 7, 31 December, per the existing `retainedYearsAfterPeriod()` formula) is on or after today. `AvgDsrService::eraseSubject()` SHALL NOT pass any retention-locked object into `DsarService::eraseObjectsForSubject()` or `DsarService::rectifyObjectForSubject()`, and SHALL include every retention-locked object in the outcome's `retained` list labelled `"retained (wettelijke bewaarplicht)"` together with its retention date.

#### Scenario: A retention-locked Payslip is excluded and reported, not erased
- **GIVEN** an employee with a `Payslip` whose `period` is within the last 7 years and `retainedUntil` is null
- **WHEN** `occ hrmq:avg:erase --employee <employeeNumber> --as-user admin --confirm --dsr-request-id <id>` runs against a request whose preview already ran
- **THEN** that Payslip's `deleted` metadata is unchanged, it is never passed to `eraseObjectsForSubject()` or `rectifyObjectForSubject()`, and it appears in the outcome's `retained` list labelled `"retained (wettelijke bewaarplicht)"` with its derived retention date

#### Scenario: An explicitly populated retainedUntil wins over the derived fallback
- **GIVEN** a `Payslip` with `retainedUntil` explicitly set to a date in the past (retention has lapsed) despite its `period` falling within a naive 7-year derivation window
- **WHEN** `classifyForErasure()` runs
- **THEN** the populated `retainedUntil` value is used and the Payslip is classified as erase-eligible, not retained

#### Scenario: A non-retained object among a mix is still erased
- **GIVEN** an employee with one retention-locked Payslip and one erase-eligible object with no retention field and no period
- **WHEN** the erase executes
- **THEN** the eligible object is anonymised via `rectifyObjectForSubject()`, the Payslip is untouched and reported as retained, and the outcome's `erased` and `retained` lists both name their respective objects — neither is silently omitted

#### Scenario: No retention lock uses the wholesale fast path
- **GIVEN** an employee with matched objects, none of which are retention-locked
- **WHEN** the erase executes
- **THEN** `AvgDsrService` calls `DsarService::eraseObjectsForSubject()` once for the whole subject, and the outcome's `retained` list is empty

### Requirement: Erasure SHALL always preview before any write, and execution SHALL require an explicit confirmation tied to that preview (REQ-DSR-006)

`AvgDsrService::previewErasure()` SHALL perform zero writes and SHALL return the same classification `eraseSubject()` would use, relabelled `wouldErase`/`retained`/`failed`. `occ hrmq:avg:erase` SHALL default to preview-only and SHALL require both `--confirm` and a `--dsr-request-id` referencing a `DsrRequest` with `status: in_behandeling` and a previously recorded preview before performing any write; `AvgDsrController::eraseConfirm()` SHALL enforce the identical precondition.

#### Scenario: A bare erase command previews without writing
- **GIVEN** `occ hrmq:avg:erase --employee E1 --as-user admin` with no `--confirm`
- **WHEN** the command runs
- **THEN** it prints the `wouldErase`/`retained`/`failed` preview lists and no object's `deleted` metadata or content changes

#### Scenario: Confirm without a prior preview is refused
- **GIVEN** no `DsrRequest` exists yet for the employee, or the referenced request's preview was never recorded
- **WHEN** `occ hrmq:avg:erase --employee E1 --as-user admin --confirm --dsr-request-id <id>` runs
- **THEN** the command is refused with a controlled error and no write occurs

#### Scenario: Confirm after a recorded preview executes the classified erase
- **GIVEN** a `DsrRequest` in `in_behandeling` whose preview already ran and recorded a classification
- **WHEN** `--confirm --dsr-request-id <id>` is supplied
- **THEN** the erase executes per REQ-DSR-005's classification, and `DsrRequest.status` becomes `voldaan` when `failed` is empty or `afgewezen` naming the failures otherwise

### Requirement: Rectification SHALL apply changes directly via rectifyObjectForSubject with no retention guard (REQ-DSR-007)

`AvgDsrService::rectifySubjectObject()` SHALL call `DsarService::rectifyObjectForSubject()` directly for the named object and change set, SHALL NOT apply the retention classification of REQ-DSR-005 (a correction does not remove data), and SHALL record only the changed field names, never before/after PII values, on `DsrRequest.outcomeSummary`.

#### Scenario: A rectification updates the object and records only field names
- **GIVEN** an `Employee` with a misspelled `lastName`
- **WHEN** `occ hrmq:avg:rectify --employee E1 --as-user admin --changes '{"lastName":"Corrected"}'` runs
- **THEN** the `Employee` object's `lastName` is updated, `DsrRequest.status` becomes `voldaan`, and `outcomeSummary` names `lastName` as changed without recording the old or new value

#### Scenario: A failed rectification is reported, not silently dropped
- **GIVEN** `rectifyObjectForSubject()` returns `null` (the object could not be loaded or updated)
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
