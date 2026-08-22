# Design — humaniq hours-process redesign

## Context (verified against this checkout, 2026-08-21)

- **Current Timesheet schema**: `lib/Settings/register.d/hr-timesheet.json` — version 0.5.0, one
  schema carrying booking fields (`period`, `hours`, `description`, `projectId`, `costCenter`,
  `billable`, `clientRef`), process fields (`status`, `submittedAt`, `approvedBy`, `approvedAt`,
  `rejectionReason`), denormalizations (`userId`, `managerUserId`, `administrationId`) and the
  `x-openregister-lifecycle` block (submit/approve/reject/reopen, guards
  `OCA\Humaniq\Lifecycle\NoSelfApprovalGuard` on approve+reject). Extended by
  `hr-cost-rate.json` (deep-merge) with `domainObjectRef`, `domainObjectType`, `allocationKey`.
- **Guard**: `lib/Lifecycle/NoSelfApprovalGuard.php` — read-only per OpenRegister's guard
  contract; explicitly documents that "the approver/timestamp stamping happens through the
  ordinary object write that carries the transition, not here".
- **But nothing stamps.** OpenRegister's transition path
  (`apps-extra/openregister/lib/Controller/TransitionController.php:71` →
  `lib/Service/Lifecycle/TransitionEngine.php:246`) reads only `action` from the request and
  mutates only the lifecycle field (`$data[$field] = $targetState`). No `approvedBy`, no
  `approvedAt`, no payload channel for a rejection reason. The frontend
  (`node_modules/@conduction/nextcloud-vue/src/components/CnLifecycleActions/CnLifecycleActions.vue:251`)
  POSTs `{ action: tr.action }` — nothing else. So on the sanctioned UI path, provenance is never
  written; the schema's own description is aspirational.
- **Event chain**: `lib/Listener/TimesheetApprovalListener.php` (on
  `OCA\OpenRegister\Event\ObjectUpdatedEvent`) → `lib/Service/TimeEntryEventService.php` emits the
  `nl.conduction.hrmq.timeentry.approved` CloudEvent + typed
  `OCA\Humaniq\Event\TimesheetApprovedEvent` on the `!approved → approved` edge only (idempotent).
  Payload casts absent fields to `''` (`buildApprovedEvent()`, lines 199–211), so today's
  endpoint-path approvals emit **empty `approvedBy`** and a fallback `time`.
- **`userId` denormalization today**: grep of `lib/` finds stamping only in
  `lib/Service/PayrollRunService.php:661-665` — and only for **Payslip** generation. No listener,
  no repair step stamps `Timesheet.userId`; it comes from seed data or HR hand-entry. Two of the
  three seeded timesheets in `hr-seed.json` have no `userId`. `lib/Standards/Checks/NlOrgChecks.php`
  audits (but does not populate) `managerUserId` against the org chain.
- **Org structure**: `hr-org.json` declares `OrgUnit` (`managerId`, `costCenter`, …) and
  `OrgAssignment` (`employeeId`, `orgUnitId`, `startDate`, `endDate`, …).
  `hr-administratie.json` declares `Administration` and `AdministrationAccess`
  (`userId`, `administrationId`, `role`).
- **Form machinery**: `CnIndexPage.vue` (lines 288–305, props at 1315–1327) passes
  `excludeFields` / `includeFields` / `fieldOverrides` from page config into its create/edit
  dialogs; `fieldsFromSchema()` (`@conduction/nextcloud-vue/src/utils/schema.js:464`) implements
  them, skips `readOnly: true` properties by default (line 487), and supports per-key
  `hidden`/`order`/`readOnly:false`/prop-merge overrides (line 459). CnDetailPage mounts
  `CnLifecycleActions` from `config.lifecycleActions`. **CnLifecycleActions has no input-prompt
  capability and CnIndexPage has no row-level lifecycle dispatch**
  (`CnIndexPage/manifestActionDispatch.js` resolves only navigate/open-page/handler actions) —
  see Decision 6.
- **readOnly enforcement**: `openregister/lib/Service/ObjectService.php:1757`
  (`enforceReadOnlyOnUpdate`) rejects **any** UPDATE that mutates a `readOnly: true` property —
  with `_rbac: false`, i.e. **there is no backend bypass**; humaniq's own listeners writing through
  ObjectService are equally rejected. Unchanged values pass (comparison is
  incoming-vs-existing). No enforcement on CREATE.
- **OpenRegister events available**: `ObjectCreatingEvent` / `ObjectUpdatingEvent` (pre-save),
  `ObjectCreatedEvent` / `ObjectUpdatedEvent` / `ObjectDeletedEvent` (post-save),
  `ObjectTransitionedEvent` (carries object, action, from, to, userId, register+schema slugs) —
  `openregister/lib/Event/`.
- **Manifest structure**: this change lands **after** `humaniq-manifest-fragment-pipeline`, so pages
  live in `src/manifest.d/hr-*.json` domain fragments merged by `buildManifest()`; the five
  timesheet pages (`Timesheets`, `TimesheetApproval`, `TeamUrengoedkeuring`, `MijnUren`,
  `TimesheetDetail`) live in `src/manifest.d/hr-timesheet.json` per that change's Decision 2.
  New pages from this change go in a per-change fragment per ADR-037.
- **e2e**: specs live in `tests/e2e/spec-coverage/*.spec.ts`; CI seeds via
  `tests/e2e/ci-seed.sh` (runs against the CI `php -S` instance and refuses :8080 by design);
  router base must be resolved via `OC.generateUrl` (see `core-journeys.spec.ts` header).

## Decision 1 — The `TimeEntry` schema (new, in `lib/Settings/register.d/hr-timesheet.json`)

Terminology per product decision: an individual booking = **TimeEntry** (NL label
"urenregistratie", verb "uren boeken"); the period aggregate = **Timesheet** (NL "urenstaat").
English property names throughout; Dutch only via l10n labels.

| Property | Type | Req | Form field? | Title / user-oriented description (sketch) |
|---|---|---|---|---|
| `employeeId` | string uuid `$ref: Employee` | yes | HR forms only | "Employee" / "The employee these hours were worked by." Self-service: resolved server-side from the signed-in user (Decision 5); HR entry page: a picker. |
| `timesheetId` | string uuid `$ref: Timesheet`, nullable | no | never | "Timesheet" / "The period timesheet this booking is part of. Assigned automatically from the start date." Resolved/created server-side (Decision 3). |
| `startedAt` | string date-time | yes | yes | "Start" / "When the work started." |
| `endedAt` | string date-time | yes | yes | "End" / "When the work ended." |
| `breakMinutes` | integer ≥0, default 0 | no | yes | "Break (minutes)" / "Unpaid break time to subtract from this booking." |
| `hours` | number ≥0 | no | never | "Hours" / "Worked hours in this booking, calculated from start, end and break." Server-derived on every write: `(endedAt − startedAt − breakMinutes)/60`, rounded to 2 decimals. |
| `description` | string ≤2000 | no | yes | "Description" / "What was worked on." |
| `projectId` | string, nullable | no | yes (optional) | "Project" / "The project these hours were worked on." Plain string (no Project schema in humaniq — ADR-062 rule 7, in `x-notes`). |
| `billable` | boolean, default false | no | yes | "Billable" / "Whether these hours are billable to a client or project." |
| `costCenter` | string, nullable | no | never | "Cost centre" / "The cost centre these hours count against, derived from the employee's team." Derived: active `OrgAssignment` → `OrgUnit.costCenter` (Decision 5). Never hand-typed (product decision 6). |
| `userId` | string, nullable | no | never | "User" / "The account these hours belong to." Server-stamped copy of the employee's `nextcloudUserId` (Decision 5). |
| `administrationId` | string, nullable | no | never | "Administration" / "The administration these hours belong to." Server-stamped from the employee (Decision 5). |
| `origin` | string enum `manual`\|`migration`\|`import`, default `manual`, **`readOnly: true`** | no | never | "Origin" / "How this booking was created." Immutable-after-create — the one safe readOnly use (see Decision 2 readOnly analysis). |

Notes:

- **No `status` and no lifecycle on TimeEntry.** The process lives on the Timesheet; entry
  mutability is governed by the parent's state (Decision 3).
- The **cost-allocation extension** (`domainObjectRef`, `domainObjectType`, `allocationKey`,
  currently on Timesheet via `hr-cost-rate.json`) moves to **TimeEntry** in that same extension
  file — allocation is a property of what was worked on, which is per-entry. All three stay out of
  every form (`allocationKey` is never hand-typed by an employee — product decision 5/6; they are
  written by integrations). The Timesheet keeps denormalized aggregate copies for event
  compatibility (Decision 7).
- **Description style**: every `description` above is user-oriented. ADR/spec citations (ADR-062
  rule 7, ADR-046, ADR-081) move to a sibling `x-notes` string per property or schema — the
  OpenAPI `x-` extension namespace is preserved by OpenRegister's importer and ignored by
  `fieldsFromSchema()`, so notes survive without leaking into UI help text. The same rewrite is
  applied to every retained Timesheet property (task).

## Decision 2 — The revised `Timesheet` schema (aggregate)

Kept: `employeeId` (required), `period` (required), `description` (timesheet-level note, the only
edit-form field), `clientRef` (portal scoping, unchanged), the `x-openregister-lifecycle` block
and `NoSelfApprovalGuard` wiring (**unchanged and reused** — including the transition
descriptions, which become honest once Decision 4 lands).

Changed roles (same property names — no data migration of names, only of custody):

| Property | New custody | Form field? |
|---|---|---|
| `hours` | **Server-recomputed aggregate**: sum of `hours` over entries with this `timesheetId` (Decision 3). | never |
| `projectId`, `costCenter`, `billable` | **Denormalized aggregates** for the event contract: `projectId`/`costCenter` = the single shared value when all entries agree, else `null`; `billable` = true iff every entry is billable (Decision 7). | never |
| `status` | Lifecycle field, moved only by transitions (as today). | never |
| `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason` | **Stamped on lifecycle edges** by the pre-save listener; inert to client input (Decision 4). | never |
| `userId`, `managerUserId`, `administrationId` | **Server-maintained caches** (Decision 5). `managerUserId` stays only as the `@me` team-filter cache — assignment truth is the org structure. | never |

New: `entryCount` (integer, server-recomputed; lets `TimesheetNotEmptyGuard` and the UI
distinguish "no entries" from "entries summing to 0"). New guard
`lib/Lifecycle/TimesheetNotEmptyGuard.php` on the `submit` transition
(`transitions.submit.requires`), denying submit when `entryCount` is 0 or `hours` ≤ 0 — a guard
is read-only, and both values are aggregates the server maintains, so the guard needs only the
`$object` payload it is given (same contract as `NoSelfApprovalGuard::check()`).

### Why almost nothing gets `readOnly: true`

`fieldsFromSchema()` drops `readOnly` properties from auto-forms (schema.js:487) — tempting. But
OpenRegister enforces `readOnly` on **every** UPDATE with no backend bypass
(`ObjectService.php:1757`, `_rbac: false`): a property this change's own listeners must *mutate*
(`hours`, `entryCount`, `status`, `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`,
`userId`, `managerUserId`, `costCenter`, the aggregates) **cannot** be `readOnly` or the
aggregation/stamping writes would be rejected. And `readOnly` on a *required* property breaks
create (the generated create form drops the field, then validation demands it). So:

- `readOnly: true` is used **only** for `TimeEntry.origin` — genuinely immutable after create,
  optional, defaulted, and stamped (if at all) on the CREATE write where enforcement doesn't
  apply.
- Every other process/derived field is dropped from forms by the **page-level allowlists**
  (`includeFields`) of Decision 8 — every authoring surface in this change names its fields
  explicitly, so schema-level hiding is not needed — and made *inert* to client tampering by the
  pre-save listener of Decision 4, which is what actually closes the direct-API hole that
  `readOnly` could not close for mutable fields anyway.

## Decision 3 — Aggregation: recompute on TimeEntry writes, not on submit

**Chosen**: `lib/Listener/TimesheetAggregateListener.php` on `ObjectCreatedEvent`,
`ObjectUpdatedEvent`, `ObjectDeletedEvent` for schema slug `timeentry` — resolves the parent
Timesheet (the entry's `timesheetId`; on update also the *old* `timesheetId` if it changed) and
recomputes `hours`, `entryCount`, and the denormalized `projectId`/`costCenter`/`billable`
aggregates in one ObjectService update per affected timesheet.

**Rejected**: computing the aggregate only at submit time. Reasons:

1. The employee needs live feedback while booking — `MijnUrenstaten` and `TimesheetDetail` must
   show the running total *before* submission or the aggregate is a lie for the entire draft
   phase (the longest phase).
2. `TimesheetNotEmptyGuard` runs at submit and, per OpenRegister's guard contract, is read-only
   over the payload it is handed — it cannot query entries. It needs the maintained aggregate.
3. On-submit computation would need its own pre-save hook on the submit edge anyway — same
   machinery, strictly less freshness.

Loop safety: the listener writes only Timesheet objects and reacts only to `timeentry` events —
no cycle. The Timesheet update it performs does trigger `TimesheetApprovalListener`, which
no-ops (status unchanged; `isApprovalTransition()` is edge-triggered —
`TimeEntryEventService.php:159`). Idempotent by construction (recompute, not increment) — the
fleet lesson that a flat total is necessary-not-sufficient is addressed by unit tests asserting
the recomputed *values*, not just that a write happened.

Entry mutability: the same schema-slug gate on the pre-save side —
`lib/Listener/TimeEntryStampListener.php` (Decision 5) refuses (via `stopPropagation()`, which
OpenRegister surfaces as the structured 422 `HookStoppedException` path,
`TransitionController.php:90` shows the convention) any TimeEntry create/update/delete whose
parent Timesheet is not in `draft` or `rejected`. Approved history stays immutable; the `reopen`
transition (unchanged) is the sanctioned route to correction.

## Decision 4 — Lifecycle stamping: pre-save listener on the carrying write

**Chosen**: `lib/Listener/TimesheetProcessStampListener.php` on `ObjectCreatingEvent` +
`ObjectUpdatingEvent` for schema slug `timesheet`:

- **CREATE**: force `status: "draft"`; null the four process fields regardless of input; stamp
  `userId`/`managerUserId`/`administrationId` (Decision 5).
- **UPDATE**: load the stored object; overwrite the incoming values of
  `submittedAt`/`approvedBy`/`approvedAt`/`rejectionReason` (and the Decision 2 aggregates +
  identity caches) with the stored values — client input to process fields is **inert**, whatever
  surface it came from. Then detect the status edge in the (now-sanitised) write:
  - `draft|rejected → submitted`: stamp `submittedAt = now()`, clear
    `approvedBy`/`approvedAt`/`rejectionReason`.
  - `submitted → approved` / `submitted → rejected`: stamp `approvedBy = <acting session uid>`,
    `approvedAt = now()`. On the reject edge **only**, accept an incoming `rejectionReason`
    (this is the single allowlisted client-supplied process value, carried by the transition
    input of Decision 6).
  - `approved → draft` (reopen): clear `submittedAt`/`approvedBy`/`approvedAt`/`rejectionReason`.
- Exception: writes performed by humaniq's own aggregation listener and repair step must not be
  sanitised into no-ops — they pass a request-scoped marker (a service-level flag on the
  listener, set/reset around the internal ObjectService call; **never** a global), under which
  the listener updates aggregates/caches but still refuses process-field changes without a
  status edge.

Because stamping happens **inside the same write** that flips `status`, the post-save
`ObjectUpdatedEvent` that `TimesheetApprovalListener` consumes now carries real
`approvedBy`/`approvedAt` — the CloudEvent and typed event gain correct provenance with **zero
change** to `TimeEntryEventService` (Decision 7).

**Verification gate (task V1, blocking):** this design assumes OpenRegister's pre-save `-ing`
events expose a mutable payload that propagates into the persisted write. Confirmed plausible
(the `HookStoppedException` path proves listeners participate in the save), but the
mutate-and-persist contract must be proven by a PHPUnit test against OpenRegister *before* the
listener work starts — with a must-fail control (mutate, assert persisted; then don't mutate,
assert unchanged). **Fallback if mutation does not propagate**: stamp *after* the fact from
`ObjectTransitionedEvent` (carries action/from/to/userId — `openregister/lib/Event/
ObjectTransitionedEvent.php`) and move the CloudEvent emission from `ObjectUpdatedEvent` to the
stamping listener's own post-stamp hook so provenance still precedes emission; the inertness
guarantee then needs the sanitise half on `ObjectUpdatingEvent` anyway (which only *restores*
values and therefore works even with a read-only payload contract via `stopPropagation()` +
structured 422). The fallback is more moving parts — hence the primary design, but the spec
scenarios are written to be satisfiable by either.

## Decision 5 — Self-service auto-stamping (userId / manager / administration / cost centre)

`lib/Listener/TimeEntryStampListener.php` on `ObjectCreatingEvent` + `ObjectUpdatingEvent` for
schema slug `timeentry` (one listener, both concerns — mutability guard from Decision 3 plus
stamping):

1. **`employeeId` resolution**: if absent on create, resolve the acting user's own Employee via
   `nextcloudUserId == session uid` (the exact lookup `PayrollController.php:434-461` already
   uses for mijn-hr) — this is what makes the self-service form work without an employee picker.
   If present (HR entry), it is taken as given (subject to normal RBAC).
2. **`userId`**: stamped from the resolved Employee's `nextcloudUserId` — a pure denormalization
   of `employeeId`, never a form field (product decision 7). Re-stamped on every write, so it can
   never drift from the employee link.
3. **`administrationId`**: stamped from the Employee's `administrationId`. For hours booking the
   administration is always derivable from the employment, so the "dropdown when the user has
   access to more than one administration" rule (product decision 5) is satisfied vacuously —
   there is no administration form field on any hours surface. (`AdministrationAccess` remains
   the source for surfaces where derivation is impossible; none exist in this change.)
4. **`costCenter`** (TimeEntry): the employee's active `OrgAssignment` (start ≤ entry date ≤ end,
   or open-ended) → its `OrgUnit.costCenter`. Null when the chain doesn't resolve — never guessed.
5. **`hours`**: computed from `startedAt`/`endedAt`/`breakMinutes`; the write is refused
   (422, structured message) when `endedAt ≤ startedAt` or the break exceeds the span.
6. **`timesheetId`**: if absent, find the employee's Timesheet whose `period` (month grain,
   `YYYY-MM`, derived from `startedAt`) matches and is in `draft`/`rejected`; create a draft
   Timesheet if none exists (which flows through Decision 4's CREATE stamping). If the only
   matching timesheet is `submitted`/`approved`, refuse with a message telling the employee to
   have it reopened — never silently book into a second timesheet for the same period.

`Timesheet.userId`/`managerUserId`/`administrationId` are stamped by
`TimesheetProcessStampListener` (Decision 4) with the same chains; `managerUserId` uses the
NlOrgChecks chain (active `OrgAssignment` → `OrgUnit.managerId` → manager Employee's
`nextcloudUserId`, `lib/Standards/Checks/NlOrgChecks.php:242-331`). The
`nl-mss-manager-consistency` audit rule keeps its role as the drift detector — now expected to
be permanently green, since the stamp and the audit share one code path (extract the chain into
a shared `lib/Service/OrgResolutionService.php` so the audit and the stamp cannot disagree).

Manager assignment itself stays **on the org structure** (product decision 4): nothing in this
change writes `OrgAssignment`/`OrgUnit`, and no form ever offers `managerUserId`.

## Decision 6 — Approval-queue UX now, and the flagged nc-vue/OpenRegister enhancements

**Within current library capabilities (ships with this change):**

- The queues (`TimesheetApproval`, `TeamUrengoedkeuring`) stay `type: index` pages filtered to
  `status: submitted`; the row click opens `TimesheetDetail`, where `CnLifecycleActions`
  (server-derived mode, already wired via `config.lifecycleActions`) renders Approve/Reject —
  guarded server-side by `NoSelfApprovalGuard` and stamped by Decision 4.
- Approve works end-to-end today (`POST /apps/openregister/api/objects/{id}/transition`).
- Reject works, but **without** a reason prompt — `rejectionReason` stays empty on the interim
  path (exactly today's behaviour on the sanctioned path, so no regression), and the detail page
  shows the read-only Approval panel including the reason once captured.

**Flagged dependency work items (each its own PR in its own repo — Vue logic belongs in nc-vue,
transition payloads belong in OpenRegister; humaniq consumes both declaratively):**

- **D1 (openregister)**: `POST /api/objects/{id}/transition` accepts an optional `data` object;
  `TransitionEngine::transition()` merges **only** the keys allowlisted by the schema's
  `x-openregister-lifecycle.transitions.<action>.inputs` declaration into the carrying write
  (unknown keys → 400). humaniq declares `inputs: [{"field": "rejectionReason", "required": true}]`
  on `reject`. The pre-save stamping listener's reject-edge allowlist (Decision 4) is the humaniq
  half of the same contract.
- **D2 (nextcloud-vue)**: `CnLifecycleActions` renders, for a transition declaring `inputs`, a
  dialog (its own component under `src/dialogs/`, per the modal-isolation rule) collecting the
  declared fields before POSTing `{ action, data }`. Degrades to today's plain button when the
  transition declares no inputs.
- **D3 (nextcloud-vue, optional, non-blocking)**: row-level lifecycle actions on `CnIndexPage`
  (approve/reject from the queue without opening the detail). `manifestActionDispatch.js` gains a
  `type: "transition"` action. Nice-to-have; the detail-page flow is the accepted UX until it
  exists. Not a prerequisite for anything in this change.

Sequencing: humaniq's rejection-reason tasks are gated on D1+D2; everything else in this change is
independent of all three.

## Decision 7 — Event-contract compatibility

`openspec/specs/time-entry-capture/spec.md` (REQ-TEC-002/003) and the typed-event spec keep
working **unversioned**:

- **Emission point unchanged**: `TimesheetApprovalListener` on `ObjectUpdatedEvent`,
  `!approved → approved` edge, exactly as now. The aggregation writes never flip status, so they
  never emit (edge-triggered check verified at `TimeEntryEventService.php:159-168`).
- **Envelope unchanged**: same `type`, `source`, and `data` keys. `hours` is now the aggregate of
  entries (same meaning: total approved hours for the period). `projectId`/`costCenter` carry the
  homogeneous value when all entries agree and `''` otherwise — a value the contract already
  permits (`buildApprovedEvent()` casts null to `''` today). `billable` = all-entries-billable.
  `approvedBy`/`approvedAt` go from silently-empty to reliably populated — a strict improvement,
  no field or type change.
- **Deliberately NOT added**: a per-entry `entries[]` array in the event. A finance consumer that
  needs entry granularity reads the entries via the register API using the `timesheetId` the
  event already carries; growing the event is a separate, consumer-driven change.
- The spec delta (this change, `specs/time-entry-capture/spec.md`) documents the aggregate
  semantics so the contract stops implying one-project-per-period.

## Decision 8 — Pages and exact form configs

Modified pages are edited in `src/manifest.d/hr-timesheet.json` (their post-fragment-pipeline
home); new pages + menu additions land in a new per-change fragment
`src/manifest.d/hours-process-redesign.json` (ADR-037). Every authoring surface uses an
`includeFields` allowlist — nothing relies on schema-level hiding.

| Page id | Route | Schema | Key config |
|---|---|---|---|
| `MijnUren` (modified) | `/mijn/uren` | **TimeEntry** | filter `{userId: "@me", administrationId: "@workspace.activeAdministrationId?"}`; columns `startedAt`, `endedAt`, `hours`, `description`, `projectId`, `billable`; sort `startedAt` desc; Add enabled via `actionToggles.showAdd: true` (`allowCreate` is NOT a CnIndexPage prop — dead config, per the fleet review's anti-pattern 11); **`includeFields: ["startedAt","endedAt","breakMinutes","description","projectId","billable"]`**; `fieldOverrides: {"billable": {"order": 60}}` (booking flow order: times → break → what → project → billable). Title stays "Mijn uren"; description becomes "Boek je uren en zie je eigen urenregistraties." |
| `MijnUrenstaten` (new) | `/mijn/urenstaten` | Timesheet | filter `{userId: "@me", administrationId: "@workspace.activeAdministrationId?"}`; columns `period`, `hours`, `entryCount`, `status`, `submittedAt`; sort `period` desc; `actionToggles` disable Add/edit/delete/copy (timesheets are server-created; submission happens on the detail page). Read-only list per the `MijnLoonstroken` precedent (REQ-MHS-004). |
| `TimeEntries` (new, HR) | `/time-entries` | TimeEntry | filter `{administrationId: "@workspace.activeAdministrationId?"}`; columns `employeeId`, `startedAt`, `endedAt`, `hours`, `projectId`, `billable`; filters `employeeId`, `billable`; sort `startedAt` desc; Add enabled via `actionToggles.showAdd: true` (never the dead `allowCreate` key); **`includeFields: ["employeeId","startedAt","endedAt","breakMinutes","description","projectId","billable"]`** (HR may book on behalf of an employee — `employeeId` is the one extra field vs self-service). |
| `TimeEntryDetail` (new) | `/time-entries/:id` | TimeEntry | Detail template B shape (`lifecycle-actions` absent — no lifecycle): data widget (include the seven booking fields + `costCenter`, `userId` display-only), related (Employee, Timesheet), audit-trail sidebar tab. Edit form `includeFields` = the HR list above. |
| `Timesheets` (modified, HR) | `/timesheets` | Timesheet | columns `employeeId`, `period`, `hours`, `entryCount`, `status`; keep filters/sort; **`actionToggles` disable Add** (aggregates are server-created) and disable inline edit; row click → detail. |
| `TimesheetApproval` (modified) | `/timesheets/approval` | Timesheet | Filters/defaultFilters/sort unchanged (`status: submitted`, `submittedAt` asc); columns gain `hours` before `submittedAt`; `actionToggles` disable Add/edit/delete. Description: "Ingediende urenstaten die wachten op goedkeuring of afkeuring — open een urenstaat om goed of af te keuren." |
| `TeamUrengoedkeuring` (modified) | `/timesheets/team-approval` | Timesheet | Same deltas as `TimesheetApproval`; the `managerUserId: "@me"` base filter is untouched. |
| `TimesheetDetail` (modified) | `/timesheets/:id` | Timesheet | Widgets: `lifecycle-actions` (server-derived; after D1/D2, `transitions` config declaring the reject input); `stat` total hours re-pointed to `{register: "hrmq", schema: "TimeEntry", metric: "sum", field: "hours", filter: {"timesheetId": "@objectId"}}` (sums the truth, not the cache); data "Urenstaat" `include: ["period","hours","entryCount","description","billable"]` with `overrides` `editable: false` on all but `description`; data "Goedkeuring" `include: ["status","submittedAt","approvedBy","approvedAt","rejectionReason"]` all `editable: false` (as today); **new `object-list` widget "Urenboekingen"** listing TimeEntry `filter: {"timesheetId": "@objectId"}`, columns `startedAt`, `endedAt`, `hours`, `description`, `projectId`; related (Employee); audit-trail sidebar tab. Page-level edit form **`includeFields: ["description"]`**. |

Menu: `MijnUrenstaten` under `MijnHrGroup` directly after `MijnUren`; `TimeEntries` under
`TimesheetsGroup` before `Timesheets`. Leaf additions to existing groups only — the group set,
orders and everything else stay untouched (menu restructure is a non-goal; these follow the
fragment `menu` extension mechanism, group re-declared by `id` with children).

## Decision 9 — Migration of existing Timesheet rows (idempotent, warn-once)

New repair step `lib/Repair/MigrateHoursProcess.php`, registered under `<post-migration>` **and**
`<install>` in `appinfo/info.xml` (`<install>` is the only hook that runs unconditionally on a
fresh install — the `ci-seed.sh` header documents the fleet lesson; on fresh installs the step
finds seed rows and must behave identically). Per Timesheet row, in one pass:

1. **Backfill caches**: `userId` from `employeeId` → Employee `nextcloudUserId`;
   `managerUserId` via the shared `OrgResolutionService` chain; `administrationId` from the
   Employee. Rows whose chain doesn't resolve keep `null` (fail-closed for `@me` filters, as
   REQ-MHS-002 already specifies) and are **counted, not per-row-logged**.
2. **Synthesize entries**: for a Timesheet with `hours > 0` and **zero** existing TimeEntry rows,
   create exactly one TimeEntry: `origin: "migration"`, `startedAt` = first day of the period at
   00:00 UTC (month grain; week grains handled by the same period parser the typed event's
   `classifyPeriodGrain()` uses), `endedAt = startedAt + hours`, `description` = the timesheet's
   description, `projectId`/`costCenter`/`billable` copied down from the legacy timesheet fields.
   The synthetic entry is a bookkeeping artifact, not a claim about when work happened — its
   `origin` marker says so, and its `description` is prefixed with nothing (the data is the
   employee's own text).
3. **Recompute aggregates** through the same `TimesheetAggregateListener` path (invoke the shared
   recompute service directly), so migration and runtime can never produce different totals.

Idempotency: step 2's guard is "zero existing entries for this timesheet" — a re-run creates
nothing; step 1 and 3 are pure recomputes (same inputs → same values → OpenRegister no-op-diff
writes). **Warn-once semantics**: the step emits exactly one summary line per run
(`humaniq: hours-process migration: N timesheets processed, M entries created, K rows with
unresolvable user link`) — never one warning per row (the three dev rows, one of which resolves
`userId` and two of which do not, produce one line saying `K=2`). A unit test runs the step
twice and asserts the second run reports `M=0` and produces no additional writes.

Approved/rejected historical rows migrate like any other — their entries are immediately
immutable under Decision 3's guard because the parent is not `draft`/`rejected`; the repair step
itself uses the internal-writer marker (Decision 4) to be exempt.

## Decision 10 — What "field descriptions must be user-oriented" concretely does

Every property `description` in `hr-timesheet.json` (both schemas) and the moved extension
properties in `hr-cost-rate.json` is rewritten to at most two sentences a non-technical employee
or HR user can act on; every ADR citation, cross-app rationale, "never a `$ref` because…" and
convention note moves verbatim into a sibling `x-notes` key (schema-level `x-notes` for
schema-wide rationale). Acceptance check (task): `grep -c "ADR-" ` over the two fragments'
`description` values returns 0, and `fieldsFromSchema()`'s rendered helper text
(`splitDescription`) contains no ADR reference on any hours form.

## Risks / Trade-offs

- **[Risk] Pre-save mutation contract unproven** → gated by task V1 with a must-fail control and
  a designed fallback (Decision 4). The change does not proceed past V1 on assumption.
- **[Risk] Two listeners writing Timesheet (stamp + aggregate)** could interleave on concurrent
  entry writes → both are recompute-style (last write recomputes from truth), and the aggregate
  listener re-reads entries at event time; a stale intermediate total is self-healed by the next
  write. Accepted for dev-stage data volumes; noted in `x-notes`.
- **[Risk] `@me` pages go empty for rows whose user link cannot be resolved** — that is the
  specified fail-closed behaviour (REQ-MHS-002); the migration summary line makes the count
  visible instead of silent.
- **[Trade-off] Interim reject-without-reason** until D1+D2 land — matches today's actual
  behaviour, so nothing regresses; the spec scenario for reason capture is tied to the
  dependency tasks.
- **[Trade-off] Synthetic migration entries have fabricated timestamps** — unavoidable when
  inventing granularity that was never recorded; `origin: "migration"` makes them
  distinguishable forever, and aggregates (the only thing payroll/finance consume) are exact.

## Open Questions — RESOLVED (orchestrator, 2026-08-21)

1. **D1/D2 scheduling**: the reject-reason tasks STAY in this change's tasks.md, gated. D1
   (openregister transition `inputs`) and D2 (nc-vue CnLifecycleActions input dialog) are
   scheduled in the same session directly after this change's core lands; if either slips, the
   gated tasks carry to a follow-up change unchanged.
2. **Period grain**: month (`YYYY-MM`) is FIXED for auto-created timesheets. No
   Administration-level setting — speculative until an administration asks; the schema keeps
   accepting week grains for pre-existing data only.
3. **`TimeEntries` HR page RBAC**: the existing register-level RBAC posture (same as
   `Timesheets`, same audience) is the accepted baseline. The fleet-wide authorization gap is its
   own programme and is not smuggled into this change.
