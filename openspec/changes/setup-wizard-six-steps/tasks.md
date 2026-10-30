## 1. nextcloud-vue: generic wizard growth (ConductionNL/nextcloud-vue, its own PR)

- [ ] 1.1 `CnSetupWizard` summary rows: render an optional per-step `summaryHint` line and a
      Wijzigen button that opens that step, behind an optional `summaryEditable: true` on the
      summary step. Verify: unit test for a row with and without hint, and that Wijzigen emits
      `step-change` to the right index.
- [ ] 1.2 `CnSetupWizard` config-fields step: support a repeatable group (`repeatable: true` on an
      array-of-objects field) with add and remove row buttons, posting the list as one value.
      Verify: unit test that two rows post as a two-item array.
- [ ] 1.3 Release nextcloud-vue and bump humaniq's dependency to that version.

## 2. Manifest (src/manifest.json)

- [ ] 2.1 Replace the setup steps with `administrations`, `working-mode`, `language`,
      `demo-data`, `templates` and `done`, in that order; drop `welcome` and move its
      orientation sentence into the `administrations` body. Copy from the board, sentence case.
      Verify: `npm run check:manifest` exits 0.
- [ ] 2.2 `administrations`: config-fields with a repeatable group (name, loonheffingennummer,
      KvK number), `runAction: create-administrations`.
- [ ] 2.3 `working-mode`: cards choice, `configKey: working_mode`, values `standard`,
      `eenmanszaak_no_payroll`, `dga_single_person` with the board's labels and hints.
- [ ] 2.4 `language`: choice, `configKey: default_language`, values `nl` and `en`.
- [ ] 2.5 `done`: `summaryEditable: true`, a `summaryHint` per step from the board, health check
      on. Keep `templates` exactly as `configuration-store` declares it.
- [ ] 2.6 Add `nl` and `en` catalogue entries for every new string.

## 3. Server (lib/Controller/SetupController.php)

- [ ] 3.1 `status()`: report the six step ids, each done once answered or skipped (app-config
      keys `administrations_decided`, `working_mode`, `default_language`, existing demo and
      templates keys). Verify: unit tests for fresh, skipped and answered per step.
- [ ] 3.2 `runAction('create-administrations')`: validate every entry first (400 on a missing
      name or loonheffingennummer, nothing created), then create or reuse `hrAdministration` by
      loonheffingennummer through ObjectService, add an `AdministrationAccess` row of role `hr`
      for the admin, make the first the admin's active administration, and remember the ids.
      Verify: unit tests for create two, rerun without duplicates, refused entry.
- [ ] 3.3 `saveConfig()`: refuse an unknown `working_mode` or `default_language` with 400 before
      storing; on a valid `working_mode`, write `mode` on the remembered administrations.
      Verify: unit tests for both refusals and for the mode write.
- [ ] 3.4 The health check names the imported schema count, the background job state and the
      loaded payroll tables year, and reports a failure as failed. Verify: unit test with a
      failed import.

## 4. Language fallback

- [ ] 4.1 A language resolver used by humaniq's notifications and generated documents: the
      employee's Nextcloud language, else `default_language`, else `nl`. Verify: unit tests for
      both REQ-FTS-010 scenarios.

## 5. Verification

- [ ] 5.1 `tests/e2e/spec-coverage/setup-wizard-six-steps.spec.ts` for the Wijzigen scenario;
      every other scenario keeps its reason-bearing `@e2e exclude` (gate 19); `@spec` tags on
      changed methods (gate 16).
- [ ] 5.2 One live pass of the wizard against board `humaniq/HuInrichten`, step by step, with a
      screenshot of the Klaar step next to the board.
