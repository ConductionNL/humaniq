## 1. Detail pages

- [x] 1.1 Add a manifest check (in `npm run check:manifest`) that every `type: "detail"` page
      in `src/manifest.json` and `src/manifest.d/*.json` sets `sidebar.showMetadata: true`, and
      set it where it is missing. Verify: the check exits 0, and 1 on a page without it.

## 2. Audittrail section

- [x] 2.1 Add an Audittrail `CnSettingsSection` to `src/views/settings/AdminSettings.vue` as drawn
      on HuInstellingen: the ten newest entries of OpenRegister's `/api/audit-trails/readable`
      filtered to the humaniq register, as sentences, with "Volledige audittrail" and
      "Downloaden". Verify: `npm run lint` exits 0 and a unit test renders a read and a change
      entry as sentences.
  - Done: the section reads OpenRegister's readable list narrowed by `register` (added there in
    ConductionNL/openregister#10566), shows ten entries as "<person> <verb> <fields> at <time>",
    and links "Volledige audittrail" and "Downloaden" with the same register filter
    (`src/views/settings/auditTrail.js`, `tests/js/auditTrail.test.mjs`). Open: the record's
    name in the sentence ("van <employee>"): an audit entry carries no record name, and reading
    each record to name it would itself write a read entry.

## 3. Machine reads

- [x] 3.1 Check humaniq's backend reads inside one operation (`PayrollRunService`,
      `PayrollMutationService`) and pass `_audit: false` where they read through
      `ObjectService::find()`. Verify: unit test that a payroll calculation writes no `read`
      entry.

## 4. Verification

- [x] 4.1 An e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags
      on new methods (gate 16).
  - Done: `@spec` tags on the new methods; reason-bearing excludes on the two backend and
      Written, runs in nightly: the four browser scenarios are anchored in `record-audit-history.spec.ts` (gate 19 green).
    build-time scenarios; the four browser scenarios in
    `tests/e2e/spec-coverage/record-audit-history.spec.ts` (an HR adviser from an empty storage
    state, the IBAN change in the history tab, the section's sentences and its filtered links).
    Open: the spec has not run against an instance yet.
- [x] 4.2 One live check: open an employee as one user, then see the read entry on the
      Audittrail section as an administrator.
      Written, runs in nightly: "opening an employee leaves a read entry naming the adviser" in `record-audit-history.spec.ts` is this check.
