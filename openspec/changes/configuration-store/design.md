# Design: install published HR configurations from the store

## Context

- `src/manifest.json` page `Store` (route `/store`, type `store`, footer menu) renders
  nextcloud-vue's store page; OpenRegister lists, installs and records configuration sets.
- The setup wizard is declared in the `setup` block of `src/manifest.json` and rendered by
  `CnSetupWizard`; `lib/Controller/SetupController.php` answers its status.

## Decisions

- **D1. One install path.** The Sjablonen step and the Store page call the same OpenRegister
  store install; humaniq adds no install code.
- **D2. Optional step.** Sjablonen is optional like `demo-data`: skipping is an answer the
  server records, and the wizard still never gates the app (REQ-FTS-001).
- **D3. Offline.** With no registry configured, the step and the page list humaniq's own
  items only and make no network call.
- **D4. Summary.** The closing step lists the installed sets by name, as drawn.
