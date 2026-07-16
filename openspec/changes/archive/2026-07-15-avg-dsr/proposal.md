---
kind: code+config
---

# AVG data-subject-rights (avg-dsr) — inzage/portabiliteit, vergetelheid, rectificatie over OpenRegister's DsarService

## Why

**Verified against HEAD 2026-07-16.** `OCA\OpenRegister\Service\DsarService` (`openregister/lib/Service/DsarService.php`)
already implements the heavy lifting for AVG (GDPR) data-subject rights: `findObjectsForSubject(string $subject, ?string
$type=null, string $mode='exact'): array` (Art 15 inzage / Art 20 portabiliteit — the same call, rendered two ways),
`eraseObjectsForSubject(string $subject, ?string $type=null, bool $dryRun=false): array` (Art 17 vergetelheid —
soft-delete via `ObjectEntity::setDeleted()` + audit, subject-wide, no per-object exclusion parameter), and
`rectifyObjectForSubject(int $objectId, array $changes): ?array` (Art 16 rectificatie — single-object update). hrmq
owns no database tables and no PII-detection/entity-matching logic of its own (ADR-022); this change adds a thin
hrmq orchestration layer over those three methods — never a reimplementation of anonymisation, entity matching, or
soft-delete.

Every `DsarService` public method opens with `assertPrivileged()`, which requires `IUserSession::getUser()` to
resolve to a non-null user for whom `IGroupManager::isAdmin($uid) === true`, and **throws** `RuntimeException`
otherwise — there is no degrade path, no duck-typed optional call. hrmq's `occ` commands run in plain CLI with no
HTTP request and therefore no ambient user session; calling `DsarService` from one today would throw. This is the
one place this feature differs from every other OpenRegister-touching leaf in this app: OpenRegister is a hard
dependency (no duck-typing, per ADR-022), but its DSAR surface additionally demands a *privileged* session that
nothing in hrmq currently establishes. This change designs that session establishment explicitly, for both the CLI
and the HTTP surface.

The second load-bearing correctness requirement: hrmq's payroll/loonadministratie data carries a statutory 7-year
fiscal retention duty (AWR art. 52 lid 4), already modelled in this codebase — `Payslip.retainedUntil` and
`LoonaangifteFiling.retainedUntil` are existing schema fields, and `NlWageTaxFilingChecks::retainedYearsAfterPeriod()`
(`lib/Standards/Checks/NlWageTaxFilingChecks.php`) already encodes "31 December of (period year + 7)" as the
statutory floor for `LoonaangifteFiling`. `DsarService::eraseObjectsForSubject()` has no per-object exclusion
parameter — it is a single wholesale sweep over every object matching a subject. Calling it unguarded on an
employee who has payslips inside their 7-year retention window would violate AWR art. 52 lid 4 the moment it ran.
Art 17 lid 3 sub b AVG itself carves this out (het recht op vergetelheid vervalt voor zover verwerking noodzakelijk
is ter nakoming van een wettelijke verplichting) — a vergetelheid request against an employee with recent payslips
is *legally* a partial erasure, and hrmq must implement that partiality itself, since `DsarService` cannot.

## What Changes

- **NEW schema `DsrRequest`** (`lib/Settings/register.d/hr-dsr.json`): the lifecycle record for one AVG
  data-subject-rights request — the requesting subject (`employeeId` `$ref` Employee), the right invoked (`right`:
  `inzage`/`verwijdering`/`rectificatie`/`portabiliteit`), a plain `status` enum (`ontvangen`/`in_behandeling`/
  `voldaan`/`afgewezen` — deliberately **no** `x-openregister-lifecycle` map, the `Loonbeslag`/`PayrollAdjustment`
  precedent: the transitions here need a privileged-session check + the retention guard, neither expressible by a
  lifecycle guard's `(object, action, userId)` contract), the statutory deadline (`receivedDate` + 1 month per AVG
  art. 12 lid 3), the handling admin (`handledBy`), and an outcome summary including the retained-objects report.
- **NEW `AvgDsrService`** (`lib/Service/AvgDsrService.php`): maps an `employeeId` to the subject value used against
  `DsarService` (design.md D2), calls `findObjectsForSubject()` for inzage/portabiliteit (rendered two ways),
  classifies every matched object as retention-locked or erase-eligible (design.md D4 — the retention guard), and
  drives the two-path erase (design.md D5): a fast wholesale `eraseObjectsForSubject()` call when nothing is
  retention-locked, or a per-object `rectifyObjectForSubject()` anonymisation loop over only the eligible objects
  when something is retained — retained objects are never passed into either call. `rectifyObjectForSubject()`
  drives Art 16 rectificatie directly.
- **NEW `lib/Command/AvgDsrExportCommand.php` / `AvgDsrEraseCommand.php` / `AvgDsrRectifyCommand.php`**
  (`occ hrmq:avg:export|erase|rectify`): each requires `--as-user <uid>`, resolves and validates that user as an
  actual Nextcloud administrator, and calls `IUserSession::setUser()` before touching `AvgDsrService` — the explicit
  session-establishment mechanism this change designs (design.md D3). `erase` always previews (`dryRun:true`) and
  requires a separate `--confirm` flag scoped to a specific `--dsr-request-id` before executing for real.
- **NEW `AvgDsrController`** (`lib/Controller/AvgDsrController.php`): `export()`, `erasePreview()`, `eraseConfirm()`,
  `rectify()` — the exact `PayrollController::mutations()` two-gate shape (`#[NoAdminRequired]` + manual
  `isAdminOrHr()` 403 gate before any resolve, then RBAC-resolve the posted `DsrRequest`/`Employee` id, 404
  collapsing unknown/unauthorized) plus a `catch (RuntimeException)` translating any `assertPrivileged()` throw into
  a 403 JSON response rather than an uncaught 500 (defense-in-depth: `isAdminOrHr()` today is exactly
  `IGroupManager::isAdmin()`, so it already matches `assertPrivileged()`'s requirement — design.md D3 states why this
  must stay true for this endpoint even after a future HR-group widening).
- **Manifest**: `DsrRequests` index + `DsrRequestDetail` (page actions "Exporteer"/"Voorbeeld verwijdering"/
  "Bevestig verwijdering"/"Rectificeer" wired to the guarded endpoints, NOT `lifecycleActions`) — admin-only
  surface, the `LoonbeslagDetail`/`WkrAssessmentDetail` posture.

### Non-goals (named fast-follows and exclusions)

- **Computing `beslagvrijeVoet`-style derived retention for schemas that don't yet carry a retention field** — the
  guard reads `retainedUntil`/`identityDocumentRetainedUntil` when populated, and falls back to the existing
  `retainedYearsAfterPeriod()`-style AWR derivation only for the payroll/loonadministratie schema family. It does
  not invent retention bases for schemas outside that family (e.g. general HR correspondence) — those are erase-
  eligible by default in this MVP, a named scope boundary, not silently ignored.
- **A dedicated Nextcloud "HR" group** — `isAdminOrHr()` is, today, exactly `IGroupManager::isAdmin()`
  (`PayrollController`'s own documented caveat). This change's controller gate must NOT widen ahead of that
  fast-follow (design.md D3); it is called out explicitly, not left implicit.
- **Multi-month AVG deadline extension** (Art 12 lid 3 permits +2 months for complex requests) — `deadlineDate` is
  always `receivedDate` + 1 month in this MVP; extension handling is a named fast-follow.
- **Selective per-object erase inside `DsarService` itself** — this change works around the absence of that
  primitive entirely within hrmq (design.md D5); a future OpenRegister capability could simplify the two-path erase
  to a single call, without changing this change's requirements.

## Capabilities

### New Capabilities

- `avg-dsr`: the `DsrRequest` schema, `AvgDsrService`'s subject mapping + retention-guarded erase, the three `occ
  hrmq:avg:*` commands with explicit privileged-session establishment, the guarded `AvgDsrController` endpoints, and
  the admin-only manifest surface.

### Modified Capabilities

<!-- none — DsarService, ObjectService, and every other OpenRegister surface are read, not modified -->

## Impact

- `lib/Settings/register.d/hr-dsr.json` — NEW (`DsrRequest` schema).
- `lib/Service/AvgDsrService.php` — NEW; subject mapping, retention classification, two-path erase, export
  rendering, rectify pass-through.
- `lib/Command/AvgDsrExportCommand.php` / `AvgDsrEraseCommand.php` / `AvgDsrRectifyCommand.php` — NEW; `--as-user`
  privileged-session establishment.
- `lib/Controller/AvgDsrController.php` — NEW; `appinfo/routes.php` +4 routes before the SPA catch-all.
- `src/manifest.json` — `DsrRequests` index + `DsrRequestDetail` pages, admin-only surface; `npm run
  check:manifest` passes.
- `tests/Unit/Service/AvgDsrServiceTest.php` — NEW (retention classification, two-path erase, idempotent preview);
  `tests/Unit/Command/AvgDsr*CommandTest.php` — NEW (privileged-session establishment, controlled errors);
  `tests/Unit/Controller/AvgDsrControllerTest.php` — NEW (403/404 gates, exception translation).
- `README.md` — the two-path erase limitation + the `isAdminOrHr()`-must-stay-admin-only note for this endpoint.
