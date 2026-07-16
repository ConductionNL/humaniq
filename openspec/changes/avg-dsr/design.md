# Design — avg-dsr

## Context

**Verified against HEAD 2026-07-16.** Read read-only, directly grounding this design:

- `openregister/lib/Service/DsarService.php` — the three public methods this change composes, exact signatures:
  - `findObjectsForSubject(string $subject, ?string $type=null, string $mode='exact'): array` — returns
    `[{object: <ObjectEntity::jsonSerialize()>, gdprEntities: [{type,value,category,detectedAt}, ...]}, ...]`,
    matching `oc_openregister_entities.value` against `$subject` (exact/ilike) then joining
    `oc_openregister_entity_relations` to load owning objects. Read-only.
  - `eraseObjectsForSubject(string $subject, ?string $type=null, bool $dryRun=false): array` — soft-deletes
    (`ObjectEntity::setDeleted()`) **every** object matching `$subject` in one wholesale sweep; `$type` filters the
    `GdprEntity.type` attribute (e.g. `email`/`bsn`), it does **not** filter by object schema and there is **no**
    per-object include/exclude parameter. `dryRun:true` returns only `{subject, type, dryRun:true, matchedCount,
    erased:[], complete:true, failedCount:0}` — **no per-object listing** in dry-run mode; the per-object detail
    (`register`/`schema`/`uuid`) is only populated on a real (`dryRun:false`) run's `erased` array. This is the
    reason `AvgDsrService` composes `findObjectsForSubject()` for its preview instead of relying on
    `eraseObjectsForSubject(dryRun:true)` alone (D5).
  - `rectifyObjectForSubject(int $objectId, array $changes): ?array` — merges `$changes` onto the object's current
    payload and updates it, attributed to the configured DSAR processing activity; returns the updated envelope or
    `null` on a load/update failure. Object-level granularity — the only one of the three methods that is.
  - `private function assertPrivileged(): void` — called first by all three public methods; throws
    `RuntimeException('DSAR operations require administrator privileges')` unless `$this->userSession->getUser()`
    is non-null AND `$this->groupManager->isAdmin($uid) === true`. No degrade path, no return value to check —
    it throws.
- `lib/Settings/register.d/hr-objects.json` — `Payslip.retainedUntil` (nullable date, "date until which the
  payslip duplicate is retained") and `LoonaangifteFiling.retainedUntil` (same shape) are **existing** schema
  fields. Neither is currently populated by any service in `lib/` (`grep -rn retainedUntil lib/ --include=*.php`
  outside `Checks/` returns nothing) — it is HR-entered or left null on real objects today, exactly like
  `Loonbeslag.beslagvrijeVoet` is a trusted HR input. `Employee.identityDocumentRetainedUntil` is the same shape
  for the separate 5-year ID-document retention (`nl-id-bewaarplicht-5jaar`).
- `lib/Standards/Checks/NlWageTaxFilingChecks.php::retainedYearsAfterPeriod(array $o, int $years): bool` — the
  **existing** AWR art. 52 lid 4 derivation: `strtotime($o['retainedUntil']) >= mktime(0,0,0,12,31, periodYear +
  $years)`, called with `$years=7` for `LoonaangifteFiling`. This change reuses the identical formula as a
  *fallback* when `retainedUntil` is unpopulated (D4) — it does not reimplement AWR art. 52 lid 4, it reads the same
  7-year constant this codebase already enforces.
- `lib/Controller/PayrollController.php::isAdminOrHr()` — `return $this->groupManager->isAdmin($uid)` where `$uid =
  $this->userSession->getUser()?->getUID()`. Despite the name, this is, today, **exactly** `IGroupManager::isAdmin()`
  — the method's own docblock names the missing dedicated HR group as a fast-follow. This is why `isAdminOrHr()`
  currently matches `DsarService::assertPrivileged()`'s requirement exactly (D3).
- `lib/Controller/PayrollController.php::mutations()` — the two-gate controller shape: `#[NoAdminRequired]` +
  manual `isAdminOrHr()` 403 **before** any ObjectService resolve, then RBAC-resolve the posted id (404 collapsing
  unknown/unauthorized). Reused verbatim for `AvgDsrController`.
- `lib/Settings/register.d/hr-loonbeslag.json` (`Loonbeslag.status`) and its design.md D5 — the precedent for a
  plain `status` enum with **no** `x-openregister-lifecycle` map on a sensitive record, transitions owned entirely
  by a guarded controller, because the guard needed (caller-role, here caller-role-**and**-privileged-session) is
  not expressible by a `LifecycleGuardInterface`'s `(object, action, userId)` contract.
- `lib/Settings/register.d/hr-objects.json` (`Employee`) properties confirm no `email` field; the identifiers
  present are `employeeNumber`, `bsn`, `nextcloudUserId`, `firstName`/`lastName`. `bsn` is the field most likely to
  be the indexed `GdprEntity` value a `DsarService` subject match needs (D1).
- `lib/Command/WkrAssessCommand.php` — the `occ` command shape (Symfony `Command`, constructor-injected service,
  `configure()`/`execute()`). No existing hrmq command establishes a user session (`grep -rn IUserSession
  lib/Command/` returns nothing) — the privileged-session mechanism (D3) is genuinely new to this codebase, not a
  reused pattern.

## Goals / Non-Goals

**Goals:** model a DSR request's lifecycle; map an hrmq `employeeId` to the `DsarService` subject value without
persisting the raw special-category identifier; render `findObjectsForSubject()` for inzage and portabiliteit;
establish an explicit privileged session for both CLI and HTTP callers of `DsarService`, failing loudly and
controllably rather than throwing raw or silently skipping; guarantee erasure can never destroy an object still
inside its statutory fiscal retention window.

**Non-Goals (binding, from the proposal):** reimplementing entity detection, matching, or soft-delete (owned by
`DsarService`); a dedicated Nextcloud HR group; multi-month AVG deadline extension; retention derivation for
schemas outside the payroll/loonadministratie family; a selective per-object erase primitive inside `DsarService`
itself (hrmq works around its absence, D5).

## Decisions

### D1 — Subject identifier: `DsrRequest.employeeId` (a `$ref`), never a persisted raw PII value; `bsn` is resolved transiently at call time

`Employee` has no `email` field in this codebase; the fields that plausibly appear as indexed `GdprEntity` values
are `bsn` and, for self-service users, `nextcloudUserId`. `bsn` (burgerservicenummer) carries its own statutory
handling regime in the Netherlands (Wet BSN — processing requires an explicit statutory basis beyond ordinary AVG
grounds) independent of AVG's special-category rules; treating it as an ordinary string field on a request record
that is itself viewed on an admin manifest page would multiply its exposure surface for no operational benefit.

`DsrRequest` therefore stores only `employeeId` (`$ref` Employee) — the low-sensitivity, stable linkage. When
`AvgDsrService` needs the actual `subject` string to pass to `DsarService`, it resolves `Employee.bsn` **in memory,
at call time**, from the already-RBAC-resolved `Employee` object, and never writes it back onto `DsrRequest` or logs
it. `DsrRequest.outcomeSummary` and the retained-objects report (D4) reference matched objects by `uuid`/`schema`/
register, never by the raw subject value.

| Field | Value stored on DsrRequest | Where the raw subject value lives |
|---|---|---|
| Linkage | `employeeId` (uuid, `$ref` Employee) | persisted |
| Subject match value | *(not stored)* | resolved from `Employee.bsn` in-memory, per call, by `AvgDsrService` |
| Outcome / retained-objects report | object `uuid`/`schema`/`register` + `retainedUntil` | persisted (no PII value) |

### D2 — `findObjectsForSubject()` is one call, rendered two ways for inzage and portabiliteit

Both Art 15 (inzage — a human-readable overview) and Art 20 (portabiliteit — a structured, exportable copy) are the
same underlying data: every object referencing the subject. `AvgDsrService::exportForSubject(string $employeeId,
string $right): array` calls `findObjectsForSubject()` once and returns the identical envelope; `AvgDsrExportCommand`
and `AvgDsrController::export()` pass `$right` (`inzage`|`portabiliteit`) through to `DsrRequest.right` and to the
response's rendering mode (`inzage`: grouped-by-object with `gdprEntities` annotated for human review;
`portabiliteit`: the same objects flattened into a single structured JSON document, no rendering difference at the
`DsarService` layer). No second `DsarService` call for the second right.

### D3 — Privileged-session establishment: `--as-user` for CLI, `isAdminOrHr()` + exception translation for HTTP

Two distinct execution contexts reach `AvgDsrService`, and each needs its own mechanism to satisfy
`DsarService::assertPrivileged()`:

**CLI (`occ hrmq:avg:export|erase|rectify`).** There is no ambient Nextcloud request, hence no session
`IUserSession::getUser()` could return. Every command declares a **required** `--as-user <uid>` option. Before
calling `AvgDsrService`, the command:

1. Resolves `$uid` via `IUserManager::get($uid)`. If `null`: print `Unknown user '<uid>'.` to stderr, return exit
   code `1`. No `RuntimeException` reaches the caller — this is a controlled CLI error, not a crash.
2. Checks `IGroupManager::isAdmin($uid)`. If `false`: print `'<uid>' is not a Nextcloud administrator; AVG
   data-subject-rights operations require an administrator session.` to stderr, return exit code `1`.
3. Calls `IUserSession::setUser($user)` — this is the exact mechanism: it makes `$this->userSession->getUser()`
   inside `DsarService` return the resolved admin, so `assertPrivileged()`'s `isAdmin()` check passes for the
   remainder of command execution.
4. Wraps every `AvgDsrService`/`DsarService` call in `try { ... } catch (RuntimeException $e) { $output->writeln('<error>'.$e->getMessage().'</error>'); return 1; }` —
   defense-in-depth: if steps 1–3 ever raced with a concurrent demotion, the command still exits cleanly with the
   service's own message rather than an uncaught stack trace.

This is a controlled, explicit, auditable mechanism: the operator names *which* administrator is acting, that name
is resolved and validated before any DSAR call, and every failure mode (unknown user, non-admin user, a stale
race) produces a one-line message and a non-zero exit code — never a silent skip, never a raw throw.

**HTTP (`AvgDsrController`).** The Nextcloud request pipeline already establishes `IUserSession::getUser()` from the
authenticated session — no `setUser()` call is needed here. `AvgDsrController` reuses `PayrollController`'s
`isAdminOrHr()` gate shape verbatim: `#[NoAdminRequired]` on every method, then a manual `isAdminOrHr()` 403 check
**before** any ObjectService resolve. Because `isAdminOrHr()` is, today, exactly `IGroupManager::isAdmin()` (the
Context section), this gate already guarantees `assertPrivileged()` will pass — the `RuntimeException` path is
unreachable in the normal flow. It is still caught explicitly (`catch (RuntimeException $e)` → 403 JSON response)
as defense-in-depth, so a future refactor of `isAdminOrHr()` cannot turn a privilege failure into an uncaught 500.

**The one hard constraint this design imposes on the rest of the app:** the day a dedicated Nextcloud "HR" group
ships (the fast-follow `isAdminOrHr()`'s own docblock names), every *other* `isAdminOrHr()`-gated endpoint may
correctly widen to admit HR-group members. `AvgDsrController` must **not** widen with them — `DsarService` hard-
requires actual `IGroupManager::isAdmin()`, not "admin or HR", so `AvgDsrController` needs its own `isAdmin()`-only
gate (not a call to the shared `isAdminOrHr()`) the moment that fast-follow lands, or a non-admin HR caller would
pass hrmq's gate and then hit the `RuntimeException`-to-403 translation instead of succeeding — a behaviour
regression, not a security hole, but a named trap for that future change to avoid. Stated explicitly here so it is
not rediscovered the hard way.

### D4 — Retention-locked predicate: read the existing `retainedUntil`/`identityDocumentRetainedUntil` fields first; fall back to the existing AWR derivation only when unpopulated

An object returned by `findObjectsForSubject()` is **retention-locked** when either:

1. It carries a populated `retainedUntil` or `identityDocumentRetainedUntil` field (whichever the schema defines)
   whose date is `>= today` — the authoritative source when HR has entered it, the same trust boundary
   `Loonbeslag.beslagvrijeVoet` already establishes for a different field; or
2. It has no populated retention field, but its schema is one of the payroll/loonadministratie family
   (`Payslip`, `PayrollRun`, `LoonaangifteFiling`, `PayrollMutationReport`, `WkrDeclaration`, `WkrAssessment`) and
   carries a `period` (or `date`/`fromPeriod`) field — the retention deadline is then derived with the **identical**
   formula `NlWageTaxFilingChecks::retainedYearsAfterPeriod()` already applies for `LoonaangifteFiling`: 31 December
   of (period year + 7), per AWR art. 52 lid 4. This is a fallback, not a second source of truth — if `retainedUntil`
   is ever populated, it wins over the derivation.

An object with neither a populated retention field nor a `period`-shaped field (e.g. `Employee` itself, absent an
active `identityDocumentRetainedUntil`; `EmploymentContract`) is **not** retention-locked by this guard — it is
erase-eligible, subject to no other basis this MVP checks (Non-Goals).

`AvgDsrService::classifyForErasure(string $employeeId): array{retained: array, eligible: array}` implements this
predicate over every object `findObjectsForSubject()` returns, and is the single function both the preview (D5) and
the execute path call — one classification function, never duplicated logic between preview and execute.

### D5 — Two-path erase: wholesale `eraseObjectsForSubject()` when nothing is retained, per-object `rectifyObjectForSubject()` anonymisation when something is

`DsarService::eraseObjectsForSubject()` is subject-wide with no per-object exclusion parameter (Context) — it
cannot skip a retained object while erasing the rest in one call. Given only the three grounded methods, the only
way to *structurally guarantee* a retained object is never touched is to never pass it into that call at all. This
change therefore drives the actual erase at the object level using `AvgDsrService::eraseSubject(string $employeeId,
string $dsrRequestId): array`:

1. `classifyForErasure()` (D4) runs first — always, both for preview and for the real execute.
2. **Fast path — `retained` is empty:** call `eraseObjectsForSubject($subject, null, dryRun:false)` once. Every
   matched object is safe to erase wholesale because none is retained. Efficient, and identical in outcome to the
   per-object path (all matched objects end up soft-deleted).
3. **Guarded path — `retained` is non-empty:** `eraseObjectsForSubject()` is **never called** for this subject
   (calling it would sweep the retained objects too). Instead, `AvgDsrService` loops over `eligible` only and calls
   `rectifyObjectForSubject($objectId, $anonymisationChanges)` per object, where `$anonymisationChanges` is a
   per-schema map of PII fields to a null/masked sentinel (e.g. `Employee`: `firstName`, `lastName`, `bsn`, `iban`
   → null). `rectifyObjectForSubject()` still attributes the update to the configured DSAR processing activity
   (same audit mechanism as the wholesale erase), so this path is not a lesser-audited shortcut. Every object in
   `retained` is never referenced in any `rectifyObjectForSubject()`/`eraseObjectsForSubject()` call this pass.
4. The outcome envelope always reports three lists: `erased` (uuid/schema/register), `retained` (uuid/schema/
   register/`retainedUntil`, labelled `"retained (wettelijke bewaarplicht)"`), and `failed` (mirroring
   `DsarService`'s own `failed` shape). `retained` is never empty-and-unreported when retention applies — it is a
   named, visible list on every erase outcome, not a silent omission.

**Dry-run is mandatory and structurally separate from execute.** `AvgDsrService::previewErasure()` runs
`classifyForErasure()` and returns the same three-list shape with `erased` renamed `wouldErase` — it performs zero
writes (neither `eraseObjectsForSubject(dryRun:false)` nor `rectifyObjectForSubject()` is called). `eraseSubject()`
(the real path) requires a `$dsrRequestId` referencing a `DsrRequest` whose `status` is `in_behandeling` and whose
preview has been recorded — the CLI's `--confirm` and the controller's `eraseConfirm()` are the only entry points
that can reach step 2/3 above; there is no code path that reaches a write without a prior preview having run in the
same request's lifecycle.

**Idempotency.** Re-running `previewErasure()` any number of times performs zero writes and always reflects current
state. Re-running `eraseSubject()` after a partial `failed` list is safe: `classifyForErasure()` re-derives fresh,
already-erased objects surface (if `DsarService` marks them `deleted`, they either drop out of
`findObjectsForSubject()`'s live-object join or are re-classified as already-erased — verify against HEAD at
implementation time which behaviour `MagicMapper`'s default scope produces) and are not re-processed.

### D6 — Rectification (Art 16) is a direct pass-through, no retention guard

A rectification corrects a factual error (e.g. a misspelled name, a wrong IBAN) — it does not remove data, so the
retention guard (D4/D5) does not apply. `AvgDsrService::rectifySubjectObject(int $objectId, array $changes,
string $dsrRequestId): ?array` calls `rectifyObjectForSubject()` directly, records the applied `$changes` (field
names only, not before/after PII values) on `DsrRequest.outcomeSummary`, and sets `status: voldaan` on success or
`afgewezen` with the failure reason when `rectifyObjectForSubject()` returns `null`.

### D7 — `DsrRequest` fields and status transitions

`lib/Settings/register.d/hr-dsr.json`, fragment `hr-dsr`:

| Field | Type | Notes |
|---|---|---|
| `employeeId` | string, format uuid, `$ref: Employee`, required | the subject (D1) |
| `right` | string, enum `[inzage, verwijdering, rectificatie, portabiliteit]`, required | the right invoked |
| `status` | string, enum `[ontvangen, in_behandeling, voldaan, afgewezen]`, default `ontvangen` | plain enum, no `x-openregister-lifecycle` (D3/D5 — the guard is caller-role + privileged-session + retention, not object-state) |
| `receivedDate` | string, format date, required | when the request was received |
| `deadlineDate` | string, format date, required | `receivedDate` + 1 month (AVG art. 12 lid 3, no extension in this MVP) |
| `handledBy` | string, nullable | Nextcloud uid of the admin who processed it (the `--as-user`/session uid) |
| `completedDate` | string, format date-time, nullable | when `status` became `voldaan`/`afgewezen` |
| `outcomeSummary` | string, nullable | human-readable outcome — export confirmation, or erase's erased/retained/failed counts, or rectify's changed field names |
| `retainedObjectRefs` | string, nullable | JSON-encoded list of `{uuid, schema, register, retainedUntil}` for D5's `retained` list — never empty-and-silent when retention applied |
| `rejectionReason` | string, nullable | populated only when `status: afgewezen` |

Transitions are owned entirely by `AvgDsrService`/`AvgDsrController`/the `occ` commands: `ontvangen` → 
`in_behandeling` on preview (erase) or on receipt of a valid export/rectify call; `in_behandeling` → `voldaan` on a
successful export, a rectify with a non-null `rectifyObjectForSubject()` result, or an erase execute whose `failed`
list is empty; `in_behandeling` → `afgewezen` on a rectify returning `null` or an erase execute with a non-empty
`failed` list (`rejectionReason` states which objects failed and why).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `DsrRequest` record shape | declarative schema | ordinary OpenRegister object, no computation |
| Subject → `DsarService` call mapping | imperative service (`AvgDsrService`) | cross-object read + external-service composition, not schema-declarative |
| Retention classification (D4) | imperative pure predicate | reads existing schema fields + reuses an existing derivation formula; not a new corpus rule in this change |
| Two-path erase (D5) | imperative service, orchestrating `DsarService` calls | the exact class of "compose provided primitives to fill a gap the primitive doesn't cover" |
| Privileged-session establishment (D3) | imperative — CLI option handling / controller gate | session state, inherently imperative in both Symfony Console and Nextcloud's controller pipeline |
| Export / erase-preview / erase-confirm / rectify endpoints | guarded controller (imperative), NOT `x-openregister-lifecycle` | the guard needed is caller-role + privileged-session + retention, none of which a lifecycle guard's `(object, action, userId)` contract can express |
| Index/detail pages | declarative manifest (`actions`, object-list) | ADR-031 default |

## Seed Data (ADR-001)

Two seeded `DsrRequest` fixtures against the existing seeded employee (3.800/wit/permanent, per the
`payroll-core-engine` anchor):

1. **Clean-erase fixture**: a second, separately-seeded employee with no `Payslip` objects at all (a recently
   onboarded record with no payroll history) — `previewErasure()` returns an empty `retained` list, exercising D5's
   fast path.
2. **Retention-guarded fixture**: the anchor employee, who already has a seeded Payslip (per `payroll-core-engine`)
   with `retainedUntil` left null and a `period` inside the last 7 years — `previewErasure()` classifies that
   Payslip as retained via the D4 fallback derivation, exercising D5's guarded path. The dev-container verification
   gate: `occ hrmq:avg:erase --employee <anchor employeeNumber> --as-user admin` (preview only, no `--confirm`)
   returns a `retained` list containing that Payslip labelled `"retained (wettelijke bewaarplicht)"` and a
   `retainedUntil` matching 31 December of (payslip period year + 7); confirm the command's exit code is `0` (a
   successful preview, not an error) and that no object's `deleted` metadata changed. Then
   `occ hrmq:avg:export --employee <anchor employeeNumber> --as-user admin --right inzage` confirms the returned
   envelope includes that same Payslip.

## Risks / Trade-offs

- **The guarded erase path (D5) is only as complete as the retention predicate (D4).** A schema outside the named
  payroll/loonadministratie family that nonetheless carries a real legal retention duty (none identified in this
  codebase today beyond the ones D4 already reads) would not be protected. Named as an explicit Non-Goal boundary,
  not a silent gap.
- **`retainedUntil`/`identityDocumentRetainedUntil` are today unpopulated on real objects** (Context) — the D4
  fallback derivation is the only thing making the guard meaningful until those fields are actively set at write
  time; that population is out of this change's scope (it belongs to `PayrollRunService`/onboarding flows, not to
  a DSR-handling feature) but is called out so it is not mistaken for already solved.
- **The `isAdminOrHr()`-must-stay-admin-only constraint (D3)** is a trap for a future, unrelated change (the HR-group
  fast-follow) to fall into if this design note is not carried forward — documented in the proposal's Non-Goals and
  the README (tasks.md) specifically so it survives past this change.
- **Per-object anonymisation (D5 guarded path) reuses `rectifyObjectForSubject()` for a purpose slightly outside its
  name ("rectify")** — it is still the correct, grounded, audited mechanism (same processing-activity attribution),
  but the field-nulling map is an hrmq-level design choice, not something `DsarService` validates; a wrong map
  entry would under- or over-anonymise silently. Mitigated by keeping the map small and per-schema, reviewed at
  implementation time against each schema's actual PII fields, not assumed from this brief.

## Open Questions

- None blocking. Retention-field population at write time, the dedicated HR group, and AVG deadline extension are
  named fast-follows (Non-Goals).
