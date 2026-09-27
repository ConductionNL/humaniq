## 1. Connections and data

- [ ] 1.1 Add `lib/Settings/connections.json` with `rdw-voertuigen` and `brp-personen`.
      Verify: hydra gate 116 (`hydra-gate-connections-declaration`) passes.
- [ ] 1.2 Add `Asset.make`, `model`, `firstAdmissionDate` and
      `hrAdministration.brpGrondslag`; bump versions. Verify: `occ maintenance:repair`
      imports and existing assets validate.

## 2. Prefill

- [ ] 2.1 Add `RegisterPrefillService` (fill empty, report different, integriq absent
      answers `skipped-no-integriq`). Verify: unit tests against a recorded RDW response and
      a recorded BRP response, including a stored value that differs.
- [ ] 2.2 Add the two endpoints with the admin or HR check and the legal-basis refusal.
      Verify: controller tests for a missing basis (refused) and a non-HR caller (refused).
- [ ] 2.3 Add the header actions on `AssetDetail` (vehicles) and `EmployeeDetail` (behind
      the basis). Verify: `npm run check:manifest` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and one live RDW lookup of a real plate on a dev instance with integriq.
