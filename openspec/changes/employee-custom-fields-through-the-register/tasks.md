## 1. Register import

- [x] 1.1 Check that `occ app:update humaniq` keeps a property an administrator added to
      `Employee`. If the import replaces the schema, change OpenRegister's
      `ConfigurationService::importFromApp()` to keep properties the app does not ship, in an
      OpenRegister PR first. Verify: an integration test that adds a property, re-imports and
      reads it back.
      > OpenRegister keeps it: `ShippedConfigurationGuard` merges an app import over the recorded shipped
      > baseline, so a property the app does not ship survives. Pinned in openregister#10564
      > (`ImportHandlerKeepsAddedPropertyTest`: import, add, import the update, read back), which runs the real
      > import handler and guard; no OpenRegister change was needed.

## 2. Page

- [x] 2.1 Add the Eigen velden data widget to `EmployeeDetail` in
      `src/manifest.d/hr-objects.json`: auto-generation mode, `excludeFields` with the shipped
      Employee properties, and a Velden beheren link to OpenRegister's Employee schema for
      administrators. Verify: `npm run check:manifest` and `npm run lint` exit 0.
- [x] 2.2 Unit test that the `excludeFields` list equals the Employee properties in
      `lib/Settings/register.d/hr-objects.json`.

## 3. Verification

- [x] 3.1 An e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19).
      > Each scenario names the unit test that covers it; the manage link's adminOnly flag is now asserted too.
- [ ] 3.2 One live check: add a "Rijbewijs" property to Employee in OpenRegister, fill it on an
      employee page and reload.

Build notes (2.1/2.2): the shipped Employee properties come from two fragments, `hr-objects.json` and
`hr-cost-rate.json`, so the exclude list and its test cover both. "Employment & compliance" excluded only the
person fields, which would have shown an added field twice; it now names its shipped fields with `include`.
The data widget takes no empty text from the manifest, so an empty card shows the library's own empty line.
Velden beheren opens OpenRegister's schema list: its schema page is routed by numeric id, which the
manifest does not know. 1.1: OpenRegister's `ShippedConfigurationGuard` keeps local additions to an
app-shipped schema once a baseline is recorded (the first import after it ships records one); the
integration test is still to write.
