## 1. Setup step

- [ ] 1.1 Add a `templates` step (cards choice, optional) to the `setup` block in
      `src/manifest.json`, its options from OpenRegister's store listing for app `humaniq`, and
      the installed set names in the `done` summary. Verify: `npm run check:manifest` exits 0.
- [ ] 1.2 Report the step in `SetupController::status()` and record a skip as an answer. Verify:
      unit tests for not answered, skipped and installed.

## 2. Verification

- [ ] 2.1 An e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags on
      changed methods (gate 16).
- [ ] 2.2 One live check: with no registry configured, open the Store page and the Sjablonen
      step and confirm in the browser network tab that no outside call is made.
