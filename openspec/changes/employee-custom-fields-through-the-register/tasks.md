## 1. Register import

- [ ] 1.1 Check that `occ app:update humaniq` keeps a property an administrator added to
      `Employee`. If the import replaces the schema, change OpenRegister's
      `ConfigurationService::importFromApp()` to keep properties the app does not ship, in an
      OpenRegister PR first. Verify: an integration test that adds a property, re-imports and
      reads it back.

## 2. Page

- [ ] 2.1 Add the Eigen velden data widget to `EmployeeDetail` in
      `src/manifest.d/hr-objects.json`: auto-generation mode, `excludeFields` with the shipped
      Employee properties, and a Velden beheren link to OpenRegister's Employee schema for
      administrators. Verify: `npm run check:manifest` and `npm run lint` exit 0.
- [ ] 2.2 Unit test that the `excludeFields` list equals the Employee properties in
      `lib/Settings/register.d/hr-objects.json`.

## 3. Verification

- [ ] 3.1 An e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19).
- [ ] 3.2 One live check: add a "Rijbewijs" property to Employee in OpenRegister, fill it on an
      employee page and reload.
