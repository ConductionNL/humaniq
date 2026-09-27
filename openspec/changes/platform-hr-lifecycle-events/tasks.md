## 1. Events

- [ ] 1.1 Extract the CloudEvent envelope builder from `TimeEntryEventService` into a shared
      helper. Verify: the existing time-entry tests pass unchanged.
- [ ] 1.2 Add `HrLifecycleEventService` with the six edge detectors and payloads of design D1
      and D2. Verify: unit tests per edge, a repeated save emitting nothing, and a sickness
      payload holding no reason field.
- [ ] 1.3 Add the five typed events and dispatch them on the same edges. Verify: unit test that
      a typed-dispatch failure does not stop the webhook.
- [ ] 1.4 Add `HrLifecycleEventListener` and register it. Verify: an integration test that
      approving a leave request calls `WebhookService` once.

## 2. Documentation and verification

- [ ] 2.1 Add the event catalogue page under `docs/`. Verify: the docs build.
- [ ] 2.2 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live subscription receiving `leave.approved` on a dev instance.
