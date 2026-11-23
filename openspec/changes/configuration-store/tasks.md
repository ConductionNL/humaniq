## 1. Setup step

- [x] 1.1 Add a `templates` step (cards choice, optional) to the `setup` block in
      `src/manifest.json`, its options from OpenRegister's store listing for app `humaniq`, and
      the installed set names in the `done` summary. Verify: `npm run check:manifest` exits 0.
      Done: the `templates` step (`optionsSource: templates`, `multiple`, `loadAction:
      install-template`, `configKey: setup_templates`); each card loads its set through
      OpenRegister's store install, and the summary names the picked cards by their set names.
      The Marketplace page's `/api/store/items` routes are now declared and bound to
      OpenRegister's store controller (`Bootstrap::aliasStoreController`), which both use.
- [x] 1.2 Report the step in `SetupController::status()` and record a skip as an answer. Verify:
      unit tests for not answered, skipped and installed.
      Done: `SetupTemplates` lists the cards ("No templates" first), installs through the store
      controller and records the answer; `SetupTemplatesTest` and `SetupControllerTest` cover
      not answered, skipped, installed, a refused install and no store.

## 2. Verification

- [ ] 2.1 An e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags on
      changed methods (gate 16).
- [ ] 2.2 One live check: with no registry configured, open the Store page and the Sjablonen
      step and confirm in the browser network tab that no outside call is made.
