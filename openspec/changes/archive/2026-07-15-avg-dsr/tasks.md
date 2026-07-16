# Tasks — avg-dsr

> Verify against HEAD, not this brief — `DsarService` (openregister), `Payslip.retainedUntil`/
> `LoonaangifteFiling.retainedUntil`, `NlWageTaxFilingChecks::retainedYearsAfterPeriod()`, and
> `PayrollController::isAdminOrHr()`/`mutations()` are already merged at HEAD; this change only composes them, it
> does not depend on any pending change.

- [x] 1. Schema: NEW `lib/Settings/register.d/hr-dsr.json` — `DsrRequest` (employeeId `$ref`, right, plain `status`
  enum, receivedDate, deadlineDate, handledBy, completedDate, outcomeSummary, retainedObjectRefs, rejectionReason)
  per REQ-DSR-001
- [x] 2. Service: NEW `lib/Service/AvgDsrService.php::resolveSubject(string $employeeId): string` — RBAC-resolves
  `Employee`, returns `bsn` in-memory, never persisted or logged per REQ-DSR-002
- [x] 3. Service: `AvgDsrService::exportForSubject(employeeId, right)` — `findObjectsForSubject()` once, rendered
  for `inzage` (grouped/annotated) and `portabiliteit` (flattened structured document) per REQ-DSR-003
- [x] 4. Service: `AvgDsrService::classifyForErasure(employeeId): {retained, eligible}` — the D4 predicate:
  populated `retainedUntil`/`identityDocumentRetainedUntil` wins; else the `retainedYearsAfterPeriod()`-identical
  AWR fallback for the payroll/loonadministratie schema family per REQ-DSR-005 (extracted into
  `AvgDsrRetentionClassifier` — pure predicate, not embedded inline)
- [x] 5. Service: `AvgDsrService::previewErasure(employeeId)` — zero-write preview, `wouldErase`/`retained`/`failed`
  three-list shape per REQ-DSR-005/-006
- [x] 6. Service: `AvgDsrService::eraseSubject(employeeId, dsrRequestId)` — fast wholesale
  `eraseObjectsForSubject()` path when `retained` is empty; guarded per-object `rectifyObjectForSubject()`
  anonymisation loop over `eligible` only when `retained` is non-empty; retained objects never passed into either
  call per REQ-DSR-005/-006 — verified structurally by `AvgDsrServiceTest::testRetainedObjectIsNeverPassedToEitherEraseCall()`
- [x] 7. Service: `AvgDsrService::rectifySubjectObject(objectId, changes, dsrRequestId)` — direct
  `rectifyObjectForSubject()` pass-through, no retention guard, records changed field names only per REQ-DSR-007
- [x] 8. Commands: NEW `lib/Command/AvgDsrExportCommand.php` / `AvgDsrEraseCommand.php` /
  `AvgDsrRectifyCommand.php` — required `--as-user`, `IUserManager::get()` + `IGroupManager::isAdmin()` validation
  (shared `PrivilegedSessionResolver`), `IUserSession::setUser()`, `catch (RuntimeException)` → one-line stderr
  message + exit 1 per REQ-DSR-004
- [x] 9. Commands: `AvgDsrEraseCommand` — dry-run by default, `--confirm` requires `--dsr-request-id` referencing an
  `in_behandeling` request with a recorded preview per REQ-DSR-005/-006
- [x] 10. Controller: NEW `lib/Controller/AvgDsrController.php` — `export()`/`erasePreview()`/`eraseConfirm()`/
  `rectify()`, each `#[NoAdminRequired]` + manual admin-only 403 gate BEFORE resolve (deliberately NOT
  `isAdminOrHr()` — design.md D3), then RBAC-resolve the posted id (404 unknown/unauthorized), `catch
  (RuntimeException)` → 403 JSON per REQ-DSR-004
- [x] 11. Routes: `appinfo/routes.php` — 4 new routes (`/api/dsr/export`, `/api/dsr/erase-preview`,
  `/api/dsr/erase-confirm`, `/api/dsr/rectify`) registered BEFORE the SPA catch-all per REQ-DSR-008
- [x] 12. Manifest: `DsrRequests` index page + `DsrRequestDetail` (four guarded `api-call` page actions
  "Exporteer"/"Voorbeeld verwijdering"/"Bevestig verwijdering"/"Rectificeer", NOT `lifecycleActions`; admin-only
  surface note) per REQ-DSR-008; `npm run check:manifest` passes (Ajv schema validation, 0 errors)
- [x] 13. Seed: clean-erase fixture (employee-visser, no Payslips) + retention-guarded fixture (anchor
  employee-jansen's existing seeded Payslip, period 2026-05, retainedUntil null) per design.md Seed Data
- [x] 14. Tests: `AvgDsrServiceTest` — classification cases (populated field wins, AWR fallback, neither present),
  both erase paths, preview performs zero writes, rectify pass-through, bsn never persisted/logged per
  REQ-DSR-002/-003/-005/-006/-007
- [x] 15. Tests: `AvgDsr*CommandTest` (Export/Erase/Rectify) + `PrivilegedSessionResolverTest` — unresolvable
  `--as-user`, non-admin `--as-user`, successful session establishment, `--confirm` without prior preview refused
  per REQ-DSR-004
- [x] 16. Tests: `AvgDsrControllerTest` — 403 non-admin caller (before any resolve), 404 unknown/unauthorized id,
  `RuntimeException` translated to 403 not 500 per REQ-DSR-004
- [x] 17. README: two-path erase limitation (D5) + the admin-only-never-`isAdminOrHr()` note (D3) so the future
  HR-group fast-follow does not silently break this endpoint, per REQ-DSR-004/-005
- [x] 18. Quality gates: `composer check:strict` ALL CHECKS PASSED (phpcs/phpmd/psalm/phpstan/phpunit — 729 tests,
  2646 assertions, 0 failures), `npm run check:manifest` PASS (Ajv, 0 errors), `npm run build` green (webpack
  production build completed with no errors)

Acceptance criteria (plain reminders, not tasks):
- `DsarService` has zero new call sites beyond its three existing public methods — no reimplementation of entity
  matching, soft-delete, or anonymisation logic inside hrmq
- no `x-openregister-lifecycle` map on `DsrRequest.status`; every transition is a guarded controller/command path
- a retained object is NEVER passed into `eraseObjectsForSubject()` or `rectifyObjectForSubject()` for erasure —
  verify this by tracing D5's guarded path, not by assuming it from this brief
- `--as-user` resolution + `IUserSession::setUser()` happens BEFORE the first `AvgDsrService`/`DsarService` call in
  every command; every failure mode produces a controlled message + non-zero exit, never an uncaught stack trace
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH (ADR-007); Dutch strings only in
  manifest labels/messages + controller/command error messages per existing convention
- raw `bsn` values never appear in `DsrRequest`, logs, or `retainedObjectRefs` — verify by grepping the actual diff
  for any write of `Employee.bsn` onto a `DsrRequest` field at implementation time
