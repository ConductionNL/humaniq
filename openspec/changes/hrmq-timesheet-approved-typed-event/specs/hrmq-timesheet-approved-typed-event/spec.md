## Purpose

Give hrmq's approved-timesheet event a consumption path a sibling Conduction app can actually use.
The existing `nl.conduction.hrmq.timeentry.approved` CloudEvent is delivered only through
OpenRegister's admin-configured outbound HTTP webhook — there is no in-process consumer surface, so
an app with no HTTP receiver (shillinq) cannot react to it without standing up the exact
phantom-cross-app-RPC shape ADR-041 forbids. This spec adds the ADR-041-sanctioned typed
`IEventDispatcher` event, dispatched additively alongside the unchanged webhook.

## ADDED Requirements

### Requirement: A typed cross-app event SHALL accompany the approved-timesheet webhook

On the same Timesheet draft/submitted → `approved` edge that already dispatches the
`nl.conduction.hrmq.timeentry.approved` CloudEvent through OpenRegister's `WebhookService`, the
system SHALL ALSO dispatch a typed `OCA\Hrmq\Event\TimesheetApprovedEvent` through Nextcloud's
`IEventDispatcher`. The typed dispatch SHALL be additive: it SHALL NOT replace, gate, or be gated by
the webhook dispatch. A failure in either dispatch path SHALL be logged and SHALL NOT block the
other, and SHALL NOT fail the underlying approval write.

The event SHALL carry, at minimum: the Timesheet id, the employee/person reference, the RAW period
value with an explicit grain marker (see the grain-marker requirement below), the approved hours,
the project/cost-centre/client references used for cost allocation, the billable flag, the
multi-administratie tenant scope, and the approval provenance (approver, timestamp) — sufficient for
a consumer to project an approved timesheet into a cost allocation.

#### Scenario: A submitted timesheet crossing into approved dispatches both events
@e2e exclude Backend-only cross-app event contract; no UI surface — verified directly by PHPUnit against the service and the typed event's own getters, matching the existing webhook test's own @e2e exclusion precedent
- **GIVEN** a Timesheet whose status transitions from `submitted` to `approved`
- **WHEN** `TimeEntryEventService::maybeDispatchApproved()` processes the change
- **THEN** the `nl.conduction.hrmq.timeentry.approved` webhook CloudEvent is dispatched exactly as before
- **AND** a `TimesheetApprovedEvent` is ALSO dispatched through `IEventDispatcher`, carrying the same timesheet id, employee reference, period, hours, project/cost-centre/client references, billable flag, and approval provenance

#### Scenario: A non-approval transition dispatches neither event
@e2e exclude Backend-only cross-app event contract; no UI surface
- **GIVEN** a Timesheet whose status transitions from `draft` to `submitted`
- **WHEN** `TimeEntryEventService::maybeDispatchApproved()` processes the change
- **THEN** neither the webhook CloudEvent nor the typed `TimesheetApprovedEvent` is dispatched

#### Scenario: Re-saving an already-approved timesheet dispatches neither event
@e2e exclude Backend-only cross-app event contract; no UI surface
- **GIVEN** a Timesheet whose status was already `approved` before this change
- **WHEN** the same object is saved again with status still `approved`
- **THEN** neither the webhook CloudEvent nor the typed `TimesheetApprovedEvent` is re-dispatched (idempotent edge)

#### Scenario: A typed-dispatch failure does not block the webhook
@e2e exclude Backend-only fail-soft contract; no UI surface
- **GIVEN** no listener is registered for `TimesheetApprovedEvent` (the consuming app is absent or its listener throws)
- **WHEN** `TimeEntryEventService::maybeDispatchApproved()` processes an approval edge
- **THEN** the typed-dispatch failure is logged and swallowed
- **AND** the webhook CloudEvent is still dispatched exactly as it would be without the typed event existing

### Requirement: The typed event SHALL carry the raw period plus an explicit grain marker

hrmq's `Timesheet.period` field is polymorphic-grain: `YYYY-MM` (a calendar month), `YYYY-Www` (an
ISO calendar week), or `YYYY-Wnn-D` (a single ISO week-day) — see
`lib/Settings/register.d/hr-timesheet.json`. The typed event SHALL carry this value UNCHANGED (no
flattening, truncation, or reinterpretation) alongside an explicit `periodGrain` marker classifying
which of the three shapes (or an unrecognised fourth) the value is. A raw period string that matches
none of the three recognised shapes SHALL be classified `unknown` and carried as-is — the producer
SHALL NOT refuse to build the event over an unrecognised period shape; that judgement belongs to the
consumer.

**Feature tier**: MVP

**Open product question (not resolved by this change)**: shillinq's `UrenRegistratie.date` needs a
single calendar day. When a `month`- or `week`-grain Timesheet is approved, what date (or date
range/split) should shillinq's resulting cost-allocation entry carry? This spec deliberately does not
answer that — it is a product-owner decision for shillinq's own `consume-hrmq-events` change.

#### Scenario: A calendar-month period is classified `month`
@e2e exclude Backend-only pure classifier; no UI surface — deterministic string classification, verified directly against the documented Timesheet.period shapes
- **GIVEN** a raw period value `2026-07`
- **WHEN** `TimesheetApprovedEvent::classifyPeriodGrain()` classifies it
- **THEN** it returns `month`

#### Scenario: An ISO week period is classified `week`
@e2e exclude Backend-only pure classifier; no UI surface
- **GIVEN** a raw period value `2026-W29`
- **WHEN** `TimesheetApprovedEvent::classifyPeriodGrain()` classifies it
- **THEN** it returns `week`

#### Scenario: A single ISO week-day period is classified `day`
@e2e exclude Backend-only pure classifier; no UI surface
- **GIVEN** a raw period value `2026-W29-3`
- **WHEN** `TimesheetApprovedEvent::classifyPeriodGrain()` classifies it
- **THEN** it returns `day`

#### Scenario: An unrecognised period shape is classified `unknown` and still carried
@e2e exclude Backend-only pure classifier; no UI surface
- **GIVEN** a raw period value that matches none of the three documented shapes (e.g. `Q3-2026`)
- **WHEN** `TimesheetApprovedEvent::classifyPeriodGrain()` classifies it
- **THEN** it returns `unknown`
- **AND** the raw value is still carried unmodified on the event — classification never refuses to build the event
