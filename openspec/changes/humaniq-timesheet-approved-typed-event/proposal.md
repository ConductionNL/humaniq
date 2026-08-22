---
kind: code
---

## Why

shillinq's `consume-hrmq-events` change is blocked on exactly one thing: a way to consume an
approved humaniq Timesheet that is not an admin-configured outbound HTTP webhook. humaniq's existing
`TimeEntryEventService::dispatch()` fires the `nl.conduction.hrmq.timeentry.approved` CloudEvent
through OpenRegister's `WebhookService::dispatchEvent()` — `WebhookMapper::findForEvent()` →
an async `WebhookDeliveryJob` → an HTTP POST to whatever URL an admin configured. shillinq has no
receiving HTTP surface for a `nl.conduction.*` CloudEvent, and standing one up would be exactly the
phantom-cross-app-RPC shape [ADR-041](../../../../hydra/openspec/architecture/adr-041-cross-app-commands-via-events.md)
was written to close off, not the sanctioned pattern.

ADR-041's actual recipe (quoted, not paraphrased):

> 1. **Cross-app commands use typed `IEventDispatcher` events** — not the integration registry, not
>    server-side HTTP, not cross-container service resolution. The pattern:
>    - The **target** (producer) app defines a public `XxxRequestedEvent` in its own
>      `OCA\<App>\Event\` namespace (extends `OCP\EventDispatcher\Event`) carrying **provenance**
>      … a `payload`, and a **synchronous result slot**…
>    - The target registers an `IEventListener` that performs the action through **its own
>      services**…
>    - **Consumers** MUST `class_exists()`-guard the target event FQCN (**fail closed** …),
>      `dispatchTyped()`, read the result slot, and store the returned reference.

Every already-shipped instance of this pattern in the fleet (decidesk's `DecisionRequestedEvent`,
docudesk's `DocumentSigningRequestedEvent`, pipelinq's `PosStockMovedEvent` consumed by shillinq's
`PosStockDecrementListener`) is a *notification*, not a request/response command — there is no
synchronous result slot, because the producer does not need an answer back. humaniq's approval event is
the same shape: a Timesheet already committed to `approved`; a consumer projects it, it does not
approve or reject anything back. This change follows the notification variant, mirroring pipelinq's
`PosTransactionService::emitStockMovedEvent()` exactly: `dispatchTyped()` a typed event AND
fire-and-forget the existing webhook, neither gating the other, both best-effort.

## What Changes

- **New `OCA\Humaniq\Event\TimesheetApprovedEvent`** (`lib/Event/TimesheetApprovedEvent.php`) — a typed
  `OCP\EventDispatcher\Event` carrying the timesheet id, employee reference, RAW period + an explicit
  `periodGrain` marker (`month`|`week`|`day`|`unknown`), hours, project/cost-centre/client
  references, billable flag, `administrationId`, and approval provenance (`approvedBy`/`approvedAt`).
  A `classifyPeriodGrain()` static classifier is exposed so a consumer can reuse the same
  classification independently.
- **`TimeEntryEventService::maybeDispatchApproved()` ALSO dispatches the typed event** — on the exact
  same approval edge, via a new `IEventDispatcher` constructor dependency, wrapped in its own
  try/catch so a typed-dispatch failure never blocks the (unchanged) webhook dispatch and vice versa.
  **Purely additive**: the webhook call, its payload shape, its return contract, and every existing
  caller are unmodified. The webhook may carry real admin-configured subscribers of its own — this
  change does not remove or gate it.
- **PHPUnit coverage** proving: the typed event is dispatched with the correct payload on the
  approval edge; it stays silent on every case the webhook already stays silent on (non-approval
  transition, re-save of an already-approved timesheet, non-Timesheet schema); a typed-dispatch
  failure does not block the webhook and vice versa; `classifyPeriodGrain()` correctly classifies
  every documented `Timesheet.period` shape plus the unrecognised case.

## Non-Goals

- **shillinq's consuming listener.** Building the `IEventListener` that consumes this event and
  projects it into shillinq's cost-allocation domain is shillinq's own `consume-hrmq-events` change's
  job — out of scope here per this change's own brief. This change only makes humaniq's side consumable.
- **Resolving the period-grain question.** humaniq's `Timesheet.period` is polymorphic-grain
  (`YYYY-MM` | `YYYY-Www` | `YYYY-Wnn-D`), while shillinq's `UrenRegistratie.date` needs a single
  calendar day. This event carries the RAW period plus an explicit grain marker rather than silently
  flattening a month- or week-grain period to one day — that projection decision belongs to the
  consuming domain (shillinq), not the producer. **Open product question, not resolved by this
  change**: when a month- or week-grain Timesheet is approved, what date (or date range) should the
  resulting `UrenRegistratie`/cost-allocation entry carry? Candidates include the period's first day,
  last day, or a proportional split across the period — each has different accounting implications
  and needs a product-owner decision before shillinq's consumer can implement a month/week
  projection. shillinq's consumer MAY choose to only consume `day`-grain events initially and log
  `month`/`week` as `unmapped` pending that decision.
- **Removing or modifying the webhook dispatch.** Explicitly additive; the admin-configured HTTP
  webhook keeps its exact current behaviour, payload, and contract.
- **A synchronous result slot.** Per ADR-041's recipe, a result slot is for a producer that needs an
  answer back from the consumer (a request/response command). This event is a notification of an
  already-committed fact (the timesheet is already `approved`) — there is nothing for a consumer to
  answer back, matching the shape of every other already-shipped notification-style event in the
  fleet (`PosStockMovedEvent`, `FinancialExtractionCompletedEvent`).

## Capabilities

### New Capabilities
- `humaniq-timesheet-approved-typed-event`: a typed, `IEventDispatcher`-dispatched cross-app event
  (ADR-041) fired alongside humaniq's existing approved-timesheet webhook, giving a sibling Conduction
  app an in-process consumption path that needs no HTTP receiving surface.

## Impact

- **`lib/Event/TimesheetApprovedEvent.php`** (new) — the typed event class.
- **`lib/Service/TimeEntryEventService.php`** — constructor gains an `IEventDispatcher` dependency
  (Nextcloud's DI container auto-wires it; no `Application.php` change needed — `TimesheetApprovalListener`,
  the sole caller, is itself constructed by the container). `maybeDispatchApproved()` now also
  dispatches the typed event; `buildApprovedEvent()` and the webhook `dispatch()` path are unchanged.
  New `buildTypedEvent()` (public, testable in isolation) and `dispatchTypedEvent()` (private,
  fire-and-forget wrapper) methods.
- **`tests/Unit/Service/TimeEntryEventServiceTest.php`** — `serviceWithSpy()` now wires a mocked
  `IEventDispatcher`; existing tests extended to assert the typed dispatch alongside the webhook;
  new tests for the fail-soft contract and the grain classification.
- **`tests/Unit/Event/TimesheetApprovedEventTest.php`** (new) — getters + `classifyPeriodGrain()`
  data-provider coverage.
- **No schema change, no DB, no direct SQL** (ADR-022) — this change adds no new persisted state; it
  only widens humaniq's existing approval-event emission surface.
- **shillinq's `consume-hrmq-events` change is unblocked** by this change landing — it can now
  `class_exists()`-guard `\OCA\Humaniq\Event\TimesheetApprovedEvent` and register an `IEventListener`
  against it, per ADR-041's consumer-side contract.
