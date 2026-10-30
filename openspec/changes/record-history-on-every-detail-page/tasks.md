## 1. Detail pages

- [ ] 1.1 Add a manifest check (in `npm run check:manifest`) that every `type: "detail"` page
      in `src/manifest.json` and `src/manifest.d/*.json` sets `sidebar.showMetadata: true`, and
      set it where it is missing. Verify: the check exits 0, and 1 on a page without it.

## 2. Audittrail section

- [ ] 2.1 Add an Audittrail `CnSettingsSection` to `src/views/settings/AdminSettings.vue` as drawn
      on HuInstellingen: the ten newest entries of OpenRegister's `/api/audit-trails/readable`
      filtered to the humaniq register, as sentences, with "Volledige audittrail" and
      "Downloaden". Verify: `npm run lint` exits 0 and a unit test renders a read and a change
      entry as sentences.

## 3. Machine reads

- [ ] 3.1 Check humaniq's backend reads inside one operation (`PayrollRunService`,
      `PayrollMutationService`) and pass `_audit: false` where they read through
      `ObjectService::find()`. Verify: unit test that a payroll calculation writes no `read`
      entry.

## 4. Verification

- [ ] 4.1 An e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags
      on new methods (gate 16).
- [ ] 4.2 One live check: open an employee as one user, then see the read entry on the
      Audittrail section as an administrator.
