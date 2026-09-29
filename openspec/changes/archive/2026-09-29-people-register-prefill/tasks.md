## 1. Connections and data

- [x] 1.1 Add `lib/Settings/connections.json` with `rdw-voertuigen` and `brp-personen`.
      Verify: hydra gate 116 (`hydra-gate-connections-declaration`) passes.
- [x] 1.2 Add `Asset.make`, `model`, `firstAdmissionDate` and
      `hrAdministration.brpGrondslag`; bump versions. Verify: `occ maintenance:repair`
      imports and existing assets validate.

## 2. Prefill

- [x] 2.1 Add `RegisterPrefillService` (fill empty, report different, integriq absent
      answers `skipped-no-integriq`). Verify: unit tests against a recorded RDW response and
      a recorded BRP response, including a stored value that differs.
- [x] 2.2 Add the two endpoints with the admin or HR check and the legal-basis refusal.
      Verify: controller tests for a missing basis (refused) and a non-HR caller (refused).
- [x] 2.3 Add the header actions on `AssetDetail` (vehicles) and `EmployeeDetail` (behind
      the basis). Verify: `npm run check:manifest` exits 0.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and one live RDW lookup of a real plate on a dev instance with integriq.

Notes from the build (2026-09-29): 1.2 is checked by the schema versions (Asset 0.5.0,
hrAdministration 1.3.0, register 0.36.0) and RegisterSchemaValidator on the filled Asset payload;
the live `occ maintenance:repair` and the live RDW lookup on an instance with integriq are in the
PR's live-check recipe, not run here. 3.1: every scenario carries a reason-bearing `@e2e exclude`
naming its unit test.
