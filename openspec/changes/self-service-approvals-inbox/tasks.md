## 1. Deputies

- [ ] 1.1 Add `hr-deputy.json` with `ManagerDeputy` and its pre-save checks. Verify: import
      succeeds; unit tests for a self-deputy and a reversed period (refused).
- [ ] 1.2 Add `ManagerOrDeputyRecipientResolver` and put it on the submit rules. Verify: unit
      test that an active deputy is returned and an expired one is not.

## 2. Inbox

- [ ] 2.1 Add `ApprovalsInboxService` (open and decided). Verify: unit tests for a manager, an
      active deputy, and an expired deputy.
- [ ] 2.2 Add `GET /api/approvals` (`#[NoAdminRequired]`, `RbacObjectReader`). Verify:
      controller tests; `hydra-gate-no-admin-idor` passes.
- [ ] 2.3 Add `MijnGoedkeuringen` and `MijnVervangers` with the inbox host view. Verify:
      `npm run check:manifest` and `npm run lint` exit 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16), and
      a live approval by a deputy from the inbox.
