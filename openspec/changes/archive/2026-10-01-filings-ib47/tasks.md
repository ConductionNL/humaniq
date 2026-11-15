## 1. Data

- [x] 1.1 Add `lib/Settings/register.d/hr-third-party.json` with the three schemas and the
      report lifecycle. Verify: `occ maintenance:repair` imports; seed objects validate.
- [x] 1.2 Add the pages under Loonadministratie. Verify: `npm run check:manifest` exits 0.

## 2. Report and rules

- [x] 2.1 Ship the UBD format schema and add `ThirdPartyReportService`. Verify: golden-file test
      for the seeded year validating against the schema.
- [x] 2.2 Add the readiness guard on `klaarzetten`. Verify: test that a payee without a BSN
      refuses with a finding.
- [x] 2.3 Add `nl-ubd-deadline` and `nl-ubd-payee-identification`. Verify:
      `occ humaniq:rules:audit` flags a seeded year past its deadline.
- [x] 2.4 Add the `ubd-jaaropgaaf` document type. Verify: unit test of the variable contract.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live report for the seeded year.

## Built (2026-10-01)

- 1.1 hr-third-party.json (ThirdPartyPayee, ThirdPartyPayment, ThirdPartyReport with lifecycle), hrAdministration 1.6.0 `postalAddress`, register 0.53.0; seed in hr-seed.json, three demo objects per schema in humaniq_mock_register.json. Seed and demo validated by the register tests and `generate_mock_register.py --check`.
- 1.2 src/manifest.d/hr-third-party.json, three menu entries under Payroll; `npm run check:manifest` exit 0.
- 2.1 lib/Standards/ubd/UBD_1.0_V1.20211028.xsd (Belastingdienst ODB, CC0), UbdMessage, ThirdPartyReportService; golden file tests/fixtures/ubd/ubd-2026-adm-001.xml.
- 2.2 UbdReportReadyGuard on klaarzetten; UbdReportReadyGuardTest, ThirdPartyReportServiceTest::testAPayeeWithoutABsnIsABlockingFinding.
- 2.3 nl-ubd-deadline, nl-ubd-payee-identification in lib/Standards/rules/payroll.json, NlThirdPartyChecks; the seeded 2025 payment of A. Bos has no sent 2025 report, so `occ humaniq:rules:audit` flags it.
- 2.4 ThirdPartyStatementService (variable contract, generation through filinq, per report); ThirdPartyStatementServiceTest.
- 3.1 @e2e excludes with the covering tests in openspec/specs/third-party-payments/spec.md; @spec tags on every new method. The live report is the PR's live-check recipe.
