## 1. History

- [ ] 1.1 Add `decisions` and the `manager-approved` status to `LeaveRequest`, and
      `requiresHrApproval` and `hrApprovalAboveHours` to `LeaveType`. Verify:
      `occ maintenance:repair` imports the register and `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add `LeaveRequestProcessStampListener` and register it. Verify: unit test per edge
      in D1, that a client-supplied `approvedBy` is replaced by the session user, and that a
      resubmit keeps earlier decisions.

## 2. HR step

- [ ] 2.1 Add the `approve-manager` transition and widen `approve` and `reject`. Verify:
      import as 1.1 and the lifecycle lists the new edges.
- [ ] 2.2 Add `LeaveApprovalStepGuard`, delegating to `NoSelfApprovalGuard`. Verify: unit
      test that a type needing HR refuses `approve` from `submitted`, a type without refuses
      `approve-manager`, a non-HR user cannot approve from `manager-approved`, and the
      approving manager cannot also approve the HR step.
- [ ] 2.3 Add the timeline and action to `LeaveRequestDetail` and the `HR-verlofgoedkeuring`
      page and menu entry. Verify: `npm run check:manifest` exits 0.
- [ ] 2.4 Seed the two leave type settings and two requests. Verify:
      `npm run check:seed-refs` exits 0.

## 3. Verification

- [ ] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new classes (gate 16).
- [ ] 3.2 One live check: approve the seeded unpaid request as HR and read both decisions on
      `LeaveRequestDetail`; record the screenshot in the PR.
