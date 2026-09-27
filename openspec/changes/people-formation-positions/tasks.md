## 1. Data

- [ ] 1.1 Add `lib/Settings/register.d/hr-formation.json` with `Formatieplaats` (gate 28
      titles and descriptions). Verify: `occ maintenance:repair` imports it through
      `InitializeRegister`.
- [ ] 1.2 Add `EmploymentContract.formatieplaatsId` and `Vacancy.formatieplaatsId`, bump
      both schema versions. Verify: existing seed objects still validate.
- [ ] 1.3 Seed the `zwangerschap` leave type and the Burgerzaken example. Verify:
      `npm run check:seed-refs` exits 0.

## 2. Occupancy

- [ ] 2.1 Add `FormationOccupancyService` (filled, vacant, overfilled, net FTE) with the
      two settings in `SettingsService`. Verify: unit tests for a filled place, a vacant
      place, an overfilled place, parental leave two days a week, and a sickness case below
      and above the threshold.
- [ ] 2.2 Add `GET /api/formation/occupancy?orgUnitId&from&to` in `FormationController`
      (`#[NoAdminRequired]`, `RbacObjectReader`). Verify: controller test for an unreadable
      unit (404); `hydra-gate-route-auth` passes.

## 3. Pages

- [ ] 3.1 Add `src/manifest.d/hr-formation.json` (`Formatieplaatsen`,
      `FormatieplaatsDetail`) and the `FormationSection` on `OrgUnitDetail`. Verify:
      `npm run check:manifest` exits 0.

## 4. Verification

- [ ] 4.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags
      (gate 16), and a live look at Team Burgerzaken showing 5.0 budgeted, 4.6 filled,
      0.4 vacant and the net figure.
