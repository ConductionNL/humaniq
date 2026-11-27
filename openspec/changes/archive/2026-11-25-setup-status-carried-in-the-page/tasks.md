## 1. Carry the setup status

- [x] 1.1 `SetupStatusInitialStateListener` carries `setup_status` on humaniq's page for an
      administrator, SetupController resolved lazily, nothing on failure. Verify:
      `tests/Unit/Listener/SetupStatusInitialStateListenerTest.php` (admin carries it, non-admin does
      not and never resolves SetupController, other apps/templates/logged-out carry nothing, a failure
      carries nothing).
- [x] 1.2 Before/after on the sandbox reference stack, with nextcloud-vue main (carrying #2378)
      built locally; numbers in the PR.
