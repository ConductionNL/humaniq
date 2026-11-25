## 1. Carry the setup status

- [x] 1.1 `PageController::index()` carries `setup_status` for an administrator, resolved lazily,
      nothing on failure. Verify: `tests/Unit/Controller/PageControllerTest.php` (admin carries it,
      non-admin does not and never resolves SetupController, a failure carries nothing and renders).
- [x] 1.2 Before/after on the sandbox reference stack, with nextcloud-vue main (carrying #2378)
      built locally; numbers in the PR.
