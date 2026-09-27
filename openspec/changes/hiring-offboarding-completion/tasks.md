## 1. Exit interview

- [ ] 1.1 Add the `ExitInterview` schema with the `mainReason` aggregation to
      `hr-onboarding.json` and list it in `humaniq_register.json`. Verify:
      `occ maintenance:repair` imports it and `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Declare the flow that stamps `Offboarding.exitGesprekDone` on create and the
      disabled daily anonymisation flow. Verify: both are listed in openregister's flow
      store after import, the second disabled.
- [ ] 1.3 Add the exit interview section to `OffboardingDetail` and an `ExitInterviews`
      index with the reason counts, plus the menu entry. Verify: `npm run check:manifest`
      exits 0.

## 2. Account disable

- [ ] 2.1 Add `AccessRevocationService` with the refusals in D2. Verify: unit test covers a
      disabled account, an empty uid, the actor's own uid, an admin uid and a repeat call.
- [ ] 2.2 Add `OffboardingController::revokeAccess` and the route, `#[NoAdminRequired]`,
      resolve first. Verify: controller test that an unreadable case answers 404 and a
      non-HR caller 403; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.
- [ ] 2.3 Add `RevokeAccessNode` to `HumaniqFlowNodeListener::NODES` and the disabled daily
      flow. Verify: unit test that the node calls the service once per due case.

## 3. Transition payment

- [ ] 3.1 Add `TransitionPaymentCalculator` and the shared contract chain helper. Verify:
      golden fixtures for a seven-year service, a chain with a four-month gap, a chain with
      an eight-month gap and a capped case, each hand-computed.
- [ ] 3.2 Replace the 2025 `capEur` with the published 2026 figure and drop the TODO note.
      Verify: `occ humaniq:rules:audit` runs clean on the seed.
- [ ] 3.3 Add `OffboardingController::transitionPayment`, the route and the page action.
      Verify: controller test that `vso` answers zero with its reason and that the amount and
      breakdown land on the case.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 4.2 One live check: calculate the payment on the seeded dismissal case and read the
      breakdown on `OffboardingDetail`; record the screenshot in the PR.
