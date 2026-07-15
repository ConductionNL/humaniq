# Design — time-entry-capture

## Verify-first finding (what already exists vs what is built)

Investigated at HEAD `080389f` (origin/development) before writing a line of code:

| Concern | State at HEAD | This change |
|---|---|---|
| Time-entry **capture** (date/period, hours, project, cost centre, billable, description) | **Exists** — `Timesheet` schema in `lib/Settings/register.d/hr-timesheet.json` | untouched |
| Submit → approve **lifecycle** | **Exists** — declarative `x-openregister-lifecycle` on `status` (`draft → submitted → approved/rejected → reopen`) | untouched |
| Self-approval guard | **Exists** — `NoSelfApprovalGuard` on `approve`/`reject` | untouched |
| **Event on approval** for a finance consumer | **Missing** — no `WebhookService` use, no OR object-event listener, no `nl.conduction.hrmq.*` event anywhere in `hrmq/lib` | **built here** |

So this is deliberately a *thin* change: only the missing hand-off is added. A
truthful summary is "capture + approval were already correctly homed in hrmq; the
approval **event** was the dangling piece."

## Decisions

### D1 — Mirror the shipped fleet CloudEvent convention, not a bespoke shape

pipelinq already emits `nl.conduction.pipelinq.time.approved` from
`ShillinqWipService`: a CloudEvents 1.0 envelope dispatched fire-and-forget
through OpenRegister's `WebhookService::dispatchEvent(_event, eventName, payload)`.
hrmq's event `nl.conduction.hrmq.timeentry.approved` copies that envelope shape
(`specversion/type/source/id/time/datacontenttype/data`) and its
never-throw-into-the-write discipline verbatim. This is the ADR-041 sanctioned
cross-app **event** path (typed dispatch to a consumer that registered a webhook),
*not* the OR integration-registry (which ADR-041 forbids as an RPC bus) and *not*
a server-side HTTP POST to a sibling app's route.

### D2 — Emit only on the approval EDGE (idempotent)

`ObjectUpdatedEvent` carries both `getOldObject()` and `getNewObject()`. The event
fires iff `newStatus === 'approved'` **and** `oldStatus !== 'approved'`. A re-save
of an already-approved timesheet, a non-approval transition
(`draft→submitted`, `submitted→rejected`), and a `reopen` all stay silent. A
create that is already `approved` (`old === null`) counts as an edge and emits.
This keeps the consumer from double-billing without needing a persisted
"emitted" flag on the record.

### D3 — All decision logic in a pure service; the listener is thin OR-glue

`TimeEntryEventService` takes plain arrays (`?array $old`, `array $new`, and the
`schemaSlug` string) and owns the three gates (schema is `timesheet`; the
approval edge; dispatch). It is therefore unit-testable in the standalone
`php:8.3-cli` suite with **no** OpenRegister classes — the emit path is exercised
by injecting a recording `WebhookService` spy through the DI container, exactly as
the existing guard tests inject a fake `ObjectService`. `TimesheetApprovalListener`
carries only the OR wiring (resolve the schema slug via `SchemaMapper`, extract the
`getObject()` arrays) and swallows every `Throwable` so it can never break the save.

### D4 — Schema gate by slug, resolved lazily

The listener resolves the changed object's schema id to a slug via
`SchemaMapper::find($id)->getSlug()` (case-insensitive compare to `timesheet`) —
hrmq does not persist per-schema ids in app-config (unlike pipelinq's
`SchemaMapService`), so a live lookup is the low-coupling choice. `SchemaMapper`
caches per request, so the cost is one query per distinct schema. When
OpenRegister is absent or the schema is unresolvable the slug is `''` and the
listener no-ops.

### D5 — Payload contract (what a finance consumer needs)

`data`: `timesheetId`, `employeeId`, `period`, `hours` (float), `billable`
(bool), `projectId`, `costCenter`, `clientRef`, `description`, `approvedBy`,
`approvedAt`. This is the minimum for invoice-from-time (hours × billable ×
project) and the WBSO / urencriterium row (hours × period × employee). The
envelope `id` is the timesheet uuid and `time` is `approvedAt` (falling back to
`now()` when the write did not stamp it).

### Declarative vs imperative (ADR-031)

The lifecycle stays **declarative** (the schema's `x-openregister-lifecycle`,
untouched). The event emission is the imperative consequence ADR-031/ADR-041 place
in the app: a listener that reacts to the OR object-event and dispatches a typed
cross-app event. No `x-openregister-notifications` rule is added — that dialect is
for push/email notifications, not a cross-app data hand-off, and gate-18 is not
adopted app-wide in hrmq (consistent with the hr-signals deferral).

## Seed Data

No new seed rows. The existing `hr-seed.json` `Timesheet` fixtures (if any) plus a
manual submit→approve are enough to exercise the event; the unit suite covers the
emit contract deterministically without touching the register. Introducing a
seeded approved timesheet purely to demonstrate the event would risk a
double-emit against a live consumer on every re-import, so it is deliberately
omitted.

## Risks

- **Double emit** — mitigated by the approval-edge gate (D2).
- **Breaking the approval write** — mitigated by the listener's blanket
  `Throwable` catch and the service's fire-and-forget dispatch (D1/D3).
- **No consumer registered** — `WebhookService::dispatchEvent` no-ops when no
  webhook matches the event name; harmless.

## Open Questions

None blocking. The shillinq consume side (invoice-from-time / WBSO projection,
closing the `bookkeeping-time-tracking` dependency) is the named follow-up in the
proposal's Impact section and is intentionally not built here.
