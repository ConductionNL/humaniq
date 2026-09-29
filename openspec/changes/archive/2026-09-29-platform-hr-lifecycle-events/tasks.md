## 1. Events

- [x] 1.1 Extract the CloudEvent envelope builder from `TimeEntryEventService` into a shared
      helper. Verify: the existing time-entry tests pass unchanged.
- [x] 1.2 Add `HrLifecycleEventService` with the six edge detectors and payloads of design D1
      and D2. Verify: unit tests per edge, a repeated save emitting nothing, and a sickness
      payload holding no reason field.
- [x] 1.3 Add the five typed events and dispatch them on the same edges. Verify: unit test that
      a typed-dispatch failure does not stop the webhook.
- [x] 1.4 Add `HrLifecycleEventListener` and register it. Verify: an integration test that
      approving a leave request calls `WebhookService` once.

## 2. Documentation and verification

- [x] 2.1 Add the event catalogue page under `docs/`. Verify: the docs build.
- [x] 2.2 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live subscription receiving `leave.approved` on a dev instance.

Verified 2026-09-29: red run 9 errors in HrLifecycleEventServiceTest and
HrLifecycleEventListenerTest and 1 error in
ChangeRequestListenerWiringTest::testTheHrLifecycleEventListenerIsSubscribed before the code;
green after, and TimeEntryEventServiceTest unchanged and green. The docs site build and the
live subscription are not run in this lane: the live check is in the PR body.
