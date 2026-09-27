## 1. Data

- [ ] 1.1 Add `BenefitEntitlement` with its lifecycle, the `political-office` contract fields
      and the payslip fields. Verify: `occ app:update` imports the register and
      `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add the `specialRegimes` group to the tables with sources and `checkAgainst` on every
      unverified leaf. Verify: `TaxTables::load('nl-2026')` loads and a unit test reads each
      regime.

## 2. Engine

- [ ] 2.1 Add the `zvwMode` input, the gated `zvwInhouding` step and golden vectors, and report
      the mode from `PayrollCalculator`. Verify: the nine fixtures pass unchanged and the
      withholding vector passes gate 5.
- [ ] 2.2 Add the `rvuThreshold` input and the `rvuEindheffing` step. Verify: a golden vector for
      a benefit above the threshold gives the levy on the excess only.

## 3. Run

- [ ] 3.1 Add the benefit pass and the extended payslip key. Verify: unit tests that a leaver
      with an active entitlement gets a benefit payslip and a person with salary and benefit
      gets two payslips.
- [ ] 3.2 Pass regime values for entitlements and office contracts, refusing unverified leaves.
      Verify: unit tests that an alderman's payslip has zero employee insurance and an
      unverified regime lists `regime-unverified`.
- [ ] 3.3 Pay a lump-sum RVU through the special-rate path. Verify: unit test that the levy
      threshold is multiplied by the months covered.

## 4. Pages and seed

- [ ] 4.1 Add entitlement pages and office fields on the contract page. Verify:
      `npm run check:manifest` exits 0.
- [ ] 4.2 Seed the RVU entitlement and the alderman. Verify: `npm run check:seed-refs` exits 0.

## 5. Verification

- [ ] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: calculate a run with the seeded RVU benefit and
      alderman and read both payslips; record screenshots in the PR.
