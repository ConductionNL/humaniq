## 1. Schemas

- [x] 1.1 Add `lib/Settings/register.d/hr-change-requests.json` with `EmployeeChangeRequest`
      (lifecycle `indienen`, `goedkeuren`, `afwijzen`) and `ChangeApprovalRule`, gate 28
      titles and descriptions. Verify: `occ maintenance:repair` runs the `InitializeRegister`
      step without error and both schemas list in OpenRegister.
- [x] 1.2 Add the five address properties to `Employee` and bump its version. Verify: the
      register import test passes and an existing employee still validates.
- [x] 1.3 Seed the five default rules and the two example requests. Verify:
      `npm run check:seed-refs` exits 0.

## 2. Enforcement

- [x] 2.1 Add `ChangeApproverRoleGuard` and put it with `NoSelfApprovalGuard` on
      `goedkeuren` and `afwijzen`. Verify: unit tests for an hr user, an accountant on an
      hr rule (refused), the manager on a manager rule, and the subject (refused).
- [x] 2.2 Add `EmployeeGuardedFieldListener` refusing direct edits of guarded fields,
      exempt under `InternalWriteMarker`, fail closed. Verify: unit tests for a guarded
      field (refused), an unguarded field (saved), an internal write (saved), and an
      unreadable rule table (refused).
- [x] 2.3 Add `ChangeRequestService` applying approved and approver-less requests with the
      staleness check. Verify: unit tests for apply, stale refusal, and approver `none`
      applying at submission.

## 3. Pages

- [x] 3.1 Add `src/manifest.d/hr-change-requests.json`: `MijnGegevens` (own `Employee` by
      `nextcloudUserId: "@me"`, request-change form for address and bank account),
      `ChangeRequests`, `ChangeRequestDetail` with lifecycle actions and a current versus
      proposed view, `ChangeApprovalRules`. Verify: `npm run check:manifest` exits 0.
- [x] 3.2 Menu entries under Mijn HR and Personeel. Verify: `npm run check:manifest-parity`
      exits 0.

## 4. Verification

- [x] 4.1 e2e test or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags
      on new methods (gate 16), `hydra-gate-orphan-auth` shows the guard has a caller.
- [ ] 4.2 Live check: as a seed employee request a bank account change on `MijnGegevens`,
      approve it as an HR user, and see the IBAN change on `EmployeeDetail`.

4.2 is not ticked: the live check runs in the live-check pass after this lane, with the recipe in the PR body.
