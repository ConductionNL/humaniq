## 1. Data

- [x] 1.1 Add `BenefitEntitlement` with its lifecycle, the `political-office` contract fields
      and the payslip fields. Verify: `occ app:update` imports the register and
      `npm run check:schema-l10n` exits 0.
      (`register.d/hr-special-regimes.json`, `political-office` in `EmploymentContract.type`,
      `BenefitEntitlement` on the register; check:schema-l10n and the l10n parity check exit 0;
      `SpecialRegimesDeclarationTest`. The live `occ app:update` import was not run here.)
- [x] 1.2 Add the `specialRegimes` group to the tables with sources and `checkAgainst` on every
      unverified leaf. Verify: `TaxTables::load('nl-2026')` loads and a unit test reads each
      regime.
      (`specialRegimes` in `nl-2026.json` with `rvu`, `wachtgeld`, `politiek-ambt`; every leaf
      `verified: false` with `checkAgainst` until a maintainer confirms the figures;
      `TaxTables::specialRegime()`; `TaxTablesSpecialRegimesTest`.)

## 2. Engine

- [x] 2.1 Add the `zvwMode` input, the gated `zvwInhouding` step and golden vectors, and report
      the mode from `PayrollCalculator`. Verify: the nine fixtures pass unchanged and the
      withholding vector passes gate 5.
      (Pack input `zvwMode`, binding `zvwMode`, `zvw` gated on the levy, `zvwInhouding`
      reduces net; `InsuranceTariffs.zvwMode`, `CalculationResult.zvwMode` from the pack; the
      ten existing fixtures pass unchanged, `zvw-inhouding.json` is a pack vector.)
- [x] 2.2 Add the `rvuThreshold` input and the `rvuEindheffing` step. Verify: a golden vector for
      a benefit above the threshold gives the levy on the excess only.
      (Inputs `rvuThreshold` and `rvuFinalLevyRate`, the rate passed by the run from the regime
      leaf so the pack resolves no unverified leaf; `rvuEindheffing` employer cost;
      `CalculationResult.rvuFinalLevyCents`; `rvu-final-levy.json` vector; the new snapshot keys
      appear only on a benefit so ordinary snapshots stay byte-identical.)

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
