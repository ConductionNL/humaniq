# ADR-102: The leave approval guard is an OpenRegister lifecycle guard, not an app-local approval chain

**Status**: accepted

**Date**: 2026-11-25

- **Sunset:** 2027-05-31
- **Gate 23 rules:** 3

## Context

Hydra gate 23 (`lint-or-abstraction-anti-patterns`) rule 3,
`consume-or-approval-workflow-fleet-wide`, reports any PHP file whose name matches
`*ApprovalChain*` or `*ApprovalStep*`, so that apps consume OpenRegister's approval machinery
instead of building their own (hydra ADR-022). It matches on the file name only.

It reports `lib/Lifecycle/LeaveApprovalStepGuard.php` (gate 23 rules: 3). That class is not an
app-local approval chain. It implements OpenRegister's
`OCA\OpenRegister\Lifecycle\LifecycleTransitionGuardInterface` and is named in the `requires` of
three transitions of the LeaveRequest lifecycle in `lib/Settings/register.d/hr-leave.json`.
OpenRegister runs the lifecycle, stores the transitions and calls the guard; the guard only
answers whether the caller may take this step of the request (manager step, HR step, never one's
own request through `NoSelfApprovalGuard`). There is no humaniq approval-chain schema, service or
state machine. That is the consumer shape ADR-022 asks for.

## Decision

Keep the class and its name, and record this exception under the ADR-022 exception clause for
gate 23 rule 3, for `lib/Lifecycle/LeaveApprovalStepGuard.php` only.

Renaming it is not the fix. The gate's own notes call a rename to clear a name rule a cosmetic
edit, and here it would also change the class name stored in imported register data (the three
`requires` entries), so an instance whose register was imported before an upgrade would name a
guard that no longer exists on an access-control transition.

## Consequences

- Gate 23 prints this suppression on every run, with this ADR as its source.
- The sunset makes this end. Before 2027-05-31 either gate 23 rule 3 recognises a file that
  implements OpenRegister's `LifecycleTransitionGuardInterface` as a consumer (as rule 2 already
  recognises a file that writes through `AuditTrailMapper`), and this ADR is withdrawn, or the
  exception is reviewed again.
