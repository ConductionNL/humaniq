## 1. History

- [x] 1.1 Add `decisions` and the `manager-approved` status to `LeaveRequest`, and
      `requiresHrApproval` and `hrApprovalAboveHours` to `LeaveType`. Verify:
      `occ maintenance:repair` imports the register and `npm run check:schema-l10n` exits 0.
- [x] 1.2 Add `LeaveRequestProcessStampListener` and register it. Verify: unit test per edge
      in D1, that a client-supplied `approvedBy` is replaced by the session user, and that a
      resubmit keeps earlier decisions.

## 2. HR step

- [x] 2.1 Add the `approve-manager` transition and widen `approve` and `reject`. Verify:
      import as 1.1 and the lifecycle lists the new edges.
- [x] 2.2 Add `LeaveApprovalStepGuard`, delegating to `NoSelfApprovalGuard`. Verify: unit
      test that a type needing HR refuses `approve` from `submitted`, a type without refuses
      `approve-manager`, a non-HR user cannot approve from `manager-approved`, and the
      approving manager cannot also approve the HR step.
- [x] 2.3 Add the timeline and action to `LeaveRequestDetail` and the `HR-verlofgoedkeuring`
      page and menu entry. Verify: `npm run check:manifest` exits 0.
- [x] 2.4 Seed the two leave type settings and two requests. Verify:
      `npm run check:seed-refs` exits 0.
      Done: the leave types were seeded with 1.1 (`unpaid` needs HR, `holiday` above 80
      hours); `hr-seed.json` adds an unpaid request at `manager-approved` and an approved
      holiday request, each with its manager decision. 2.3: a `timeline` widget over
      `decisions` on `LeaveRequestDetail`, the `approve-manager` action, `approve`/`reject`
      from `manager-approved` too, and the menu entry "HR leave approval" over
      `LeaveRequests` with `status: manager-approved` (the same index-with-query shape as
      the other two approval queues).
- [x] 2.5 Show the coverage warning from `GET /api/leave/coverage` on `LeaveRequestDetail`
      before Approve, and store what the approver accepted in `coverageWarningAccepted`
      (`src/manifest.d/hr-leave.json`, REQ-LVM-S02). Verify: `npm run check:manifest` exits 0
      and approving the seeded thin-coverage request records the warning.
      Done: the composition behind `GET /api/leave/coverage` moved into
      `LeaveCoverageWarning` (the endpoint answers exactly as before), and
      `LeaveRequestProcessStampListener` uses it on `approve-manager` and `approve` to
      stamp `coverageWarningAccepted` (now a server-only field) with the warning the
      approver could read, null when there was none; a warning that cannot be composed
      never refuses the approval. `LeaveRequestDetail` shows "Coverage if approved", an
      endpoint table over the warning's dates, beside Approval, which now shows the
      recorded warning read-only. Unit tests: `LeaveCoverageWarningCompositionTest`, five
      new cases in `LeaveRequestProcessStampListenerTest`. The live half of the verify
      (approving the seeded thin-coverage request) is left for 3.2.

## 3. Verification

- [x] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new classes (gate 16).
      Done: a reason-bearing `@e2e exclude` on each of the six scenarios, naming the unit
      tests that cover it; `@spec` on every new class, `StampsProcessFields` included.
- [x] 3.2 One live check: approve the seeded unpaid request as HR and read both decisions on
      `LeaveRequestDetail`; record the screenshot in the PR.
      Done in the live pass (2026-11-17, clean install): the seeded unpaid request lands in
      `draft` without decisions (a seed cannot carry process state), so it was submitted as
      admin and approved as manager by manager-pietersen; an administrator then approved it
      from the page's Actions menu. `LeaveRequestDetail` shows both decisions (manager approved,
      hr approved, each dated); the timeline shows no name, since a list entry takes one detail
      field.
- [x] 3.3 Decide who takes the HR step. `LeaveApprovalStepGuard::hrStep()` admits only
      Nextcloud administrators; a member of `humaniq-hr` is refused ("Alleen HR mag deze stap
      nemen."), while REQ-LAS-002 says "the HR role" and `HumaniqRoles::isHr()` exists.
      Decided 17 Nov: members of `humaniq-hr` take the HR step and Nextcloud administrators
      keep it; `hrStep()` asks `HumaniqRoles::isHr()`.
