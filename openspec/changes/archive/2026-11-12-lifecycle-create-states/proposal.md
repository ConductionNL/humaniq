---
kind: code
depends_on: []
---

# Proposal: lifecycle-create-states

## Summary

OpenRegister now refuses a create whose lifecycle value is neither the
schema's `initial` nor one of its `createStates` (openregister#5400). Two
humaniq writes create objects in a later state on purpose:

- `CompCollectiveService` proposes a `CompAdjustment` in `proposed`.
- `LearniqCredentialListener` records a `TrainingRecord` as `gevolgd` when
  a learniq certificate is issued.

Both schemas now declare that state in `x-openregister-lifecycle.createStates`.
An OpenRegister without #5400 ignores the key, so this can merge before #5400
or with it.
