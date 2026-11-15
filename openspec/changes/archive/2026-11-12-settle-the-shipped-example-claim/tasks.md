## 1. Repair

- [x] 1.1 `SettleShippedExampleClaim` marks the unchanged old seed claim reimbursed on the direct route, as an internal write, and logs it. Verify: SettleShippedExampleClaimTest.
- [x] 1.2 It runs after the register import in `appinfo/info.xml`, post-migration and install. Verify: AppInfoTest.

## 2. Verification

- [x] 2.1 Failing-first tests, capped mutants with a no-op control, the full suite once under pcov.
- [x] 2.2 `openspec validate settle-the-shipped-example-claim --strict`.
