# ADR-033: Collaborative Editing Pattern

## Status
Proposed

## Date
2026-05-10

## Context

OpenRegister has had pessimistic locking on objects for years
(`POST /api/objects/{register}/{schema}/{id}/lock`,
`DELETE` on the same path). It also recently shipped a per-object
push channel via `notify_push` — every `ObjectCreatedEvent`,
`ObjectUpdatedEvent`, and `ObjectDeletedEvent` fan-outs a
`notify_custom` event with key `or-object-{uuid}` (see
[openregister/docs/Integrations/OpenRegister.md](../../../openregister/docs/Integrations/OpenRegister.md)).

In isolation, each primitive solves half the collaborative editing
problem:

- **Subscribe only.** Two users opening the same record see remote
  field edits, but they can both enter edit mode at the same time
  and silently overwrite each other on save. The "remote user is
  editing right now" signal isn't visible.
- **Lock only.** Concurrent writes are blocked at the server, but
  the second user discovers it at Save time with a 409. By then
  they've spent minutes filling out a form that's about to be
  rejected.

`@conduction/nextcloud-vue` v1.0.0-beta.6 (PR #192,
`collaborative-editing-defaults`) wires both primitives together as
**defaults** on every `CnDetailPage` and `CnObjectSidebar`:

- The page auto-subscribes on mount (via `useObjectSubscription`,
  binding the scope so unmount releases the listener).
- The reactive lock state derives from the cached
  `@self.locked` block (which the live-updates plugin keeps fresh
  on every `or-object-{uuid}` event) — no separate poll endpoint.
- `CnLockedBanner` mounts above the editor surface when a remote
  lock is detected, suppressing the local Edit affordance.

`useObjectLock` ships as a public composable with `acquire()` /
`release()` and typed errors (`LockConflictError`,
`PermissionError`); v1 does NOT yet wire `acquire()` into the
lib's bundled form dialogs — that's deferred to
[ncv #193](https://github.com/ConductionNL/nextcloud-vue/issues/193).

Without a fleet-wide ADR:

- New apps (and refactors of existing ones — mydash, opencatalogi,
  procest, pipelinq, larpingapp, softwarecatalog) inherit the
  *implementation* on next lib bump, but the *intent* (why it's
  default-on, why we paired subscribe with lock) lives only in
  scattered PR descriptions inside `nextcloud-vue`.
- Hydra reviewer agents have no architecture rule to cite when
  flagging a PR that introduces a new editor surface without
  lock-on-edit.
- App authors writing custom editor screens (anything that doesn't
  go through `CnDetailPage` — inline widgets, embedded wizards,
  bespoke flows) re-roll their own concurrency story.

## Decision

**Every detail surface in a Conduction app MUST follow three rules
when displaying or editing an OpenRegister object:**

1. **Subscribe on view.** When a page renders an object identified
   by `(register, schema, uuid)`, the lib MUST auto-subscribe to
   that object's `or-object-{uuid}` push channel for the page's
   lifetime. Read-only / archive surfaces MAY opt out via
   `pages[].config.subscribe: false`.

2. **Lock on edit.** When the user enters edit mode, the lib MUST
   acquire a pessimistic lock via OR's lock endpoint BEFORE
   flipping internal `editing` state to true. On 409/423
   (`LockConflictError`), edit MUST be suppressed and a banner
   MUST surface the conflicting user. On 401/403
   (`PermissionError`), edit MAY proceed with a toast warning
   that concurrent edits are not blocked.

3. **Banner when locked.** Any session observing a remote lock
   (via the cached `@self.locked` block, kept fresh by the live
   updates plugin) MUST render a "Locked by X" affordance that
   disables the local Edit toggle until the lock releases or
   expires.

Rules 1 and 3 are normative as of beta.6 — shipped in PR #192
([`collaborative-editing-defaults`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/openspec/changes/collaborative-editing-defaults/proposal.md)).
Rule 2 ships in two phases: the composable (`useObjectLock`) is
public today; the auto-acquire wiring into the lib's bundled
form dialogs (`CnAdvancedFormDialog` / `CnFormDialog`) is tracked
by [ncv #193](https://github.com/ConductionNL/nextcloud-vue/issues/193).

## Consequences

### What inherits automatically

- Every consumer using `CnDetailPage` / `CnObjectSidebar` gets
  rules 1 and 3 on next lib bump — no code change.
- Decidesk's LiveMeeting page, mydash KPI detail views,
  opencatalogi catalog detail screens, procest case detail panels,
  pipelinq lead detail, larpingapp character detail — all the
  fleet's primary detail surfaces — start broadcasting and
  reacting to lock state immediately.

### What consumer apps still own

- Bespoke editor surfaces that don't use `CnDetailPage` /
  `CnObjectSidebar` (custom widgets, embedded forms, wizards)
  MUST consume `useObjectLock` directly. The composable is
  public for exactly this case.
- Read-only / archive views MUST set `subscribe: false` on the
  manifest page if they don't want the WebSocket overhead.

### What's deferred (v2)

- **Lock auto-acquire on the lib's bundled form dialogs**
  ([ncv #193](https://github.com/ConductionNL/nextcloud-vue/issues/193)).
  Until that lands, rule 2 is enforced only on consumers that
  wire `useObjectLock` themselves. Hydra reviewer agents
  SHOULD NOT reject PRs missing rule 2 behaviour until ncv #193
  is closed.
- **GraphQL aggregations** that would enable chart-widget
  breakdowns on the same push channel
  ([openregister #1455](https://github.com/ConductionNL/openregister/issues/1455)) —
  unrelated to the lock pattern itself but completes the
  live-data story this ADR is part of.

### Cross-app coordination

- `openregister/docs/Patterns/collaborative-editing.md`
  (PR #1458) is the canonical operational doc — links to push
  event wire format, lock REST endpoints, and the lib defaults.
- `@conduction/nextcloud-vue` documentation:
  - [`useObjectSubscription`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/utilities/composables/use-object-subscription.md)
  - [`useObjectLock`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/utilities/composables/use-object-lock.md)
  - [`CnLockedBanner`](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/components/cn-locked-banner.md)
  - [`CnDetailPage` defaults section](https://github.com/ConductionNL/nextcloud-vue/blob/beta/docs/components/cn-detail-page.md)

### Reviewer rule guidance (for Hydra agents)

- Code review reviewer SHOULD flag PRs that add a new editor
  surface (inline edit grid, bespoke form, click-to-edit cell)
  without consuming `useObjectLock`, citing this ADR.
- Security review reviewer SHOULD continue treating concurrent
  writes as a correctness issue, not a security one — the lock
  is an UX guardrail, not an authorisation boundary; OR enforces
  authorisation at write time independent of the lock.

## Alternatives considered

- **Optimistic / CRDT editing.** Rejected. OpenRegister is not
  a Yjs-style sync engine; we don't want every consumer to
  reason about merge semantics. Pessimistic locks are
  deliberate — they make the failure mode explicit and recoverable
  (banner + retry).
- **Lock without push events.** Rejected. The second user only
  finds out at Save time with a 409. Too late.
- **Push events without locks.** Rejected. Two users can still
  silently overwrite each other.
- **Server-Sent Events instead of notify_push.** SSE is the GraphQL
  subscription transport (separate channel for GraphQL clients).
  The REST + notify_push path is the primary; SSE is the future
  extension for query-driven subscribers. Both share the same
  `or-object-{uuid}` event keys.
