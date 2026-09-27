## 1. Uploaded tables

- [ ] 1.1 Add `TaxTableSet` to `lib/Settings/register.d/hr-packs.json`. Verify: `occ app:update`
      imports it and `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add `TaxTableSourceInterface` and let `TaxTables::load()` consult it after the bundled
      directory. Verify: unit test that a bundled id always loads from disk and an unknown id
      loads from the source.
- [ ] 1.3 Add `TaxTableSetService` validation (groups, leaf shape, `checkAgainst`) and storage.
      Verify: unit test that a missing `zvw` group and an unverified leaf without
      `checkAgainst` are refused, and an id equal to `nl-2026` is refused.

## 2. Pack upload, deactivation and resolution

- [ ] 2.1 Accept `tables` in `JurisdictionPackService::upload()` and store both only when both
      pass. Verify: unit test that a pack whose golden vector fails leaves no `TaxTableSet`.
- [ ] 2.2 Add `POST /api/payroll/packs/deactivate`. Verify: controller test that a non-admin
      gets 403 and a deactivated pack no longer resolves.
- [ ] 2.3 Add `YearTransitionService` and `GET /api/payroll/packs/resolution`; switch the occ
      preflight to it. Verify: unit test for bundled 2026 and uploaded 2027 resolutions;
      `hydra-gate-route-auth` passes.
- [ ] 2.4 Update `lib/Standards/tables/SCHEMA.md` with the uploaded second home. Verify: the
      PR diff shows the paragraph.

## 3. Page

- [ ] 3.1 Add `src/manifest.d/hr-packs.json` with the `PayrollPacks` index and its menu entry
      under Configuration. Verify: `npm run check:manifest` exits 0.
- [ ] 3.2 Register `PackUploadDialog` and `YearTransitionSection` in `src/registry.js`. Verify:
      `npm run lint` exits 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 4.2 One live check on a dev instance: upload a test pack and tables for 2027, read the
      year check, deactivate the pack; record screenshots in the PR.
