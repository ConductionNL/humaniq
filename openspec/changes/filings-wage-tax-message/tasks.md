## 1. Message

- [x] 1.1 Ship the 2026 XSD and version entry beside the tax tables. Verify: a test loads it.
      Built: `lib/Standards/loonaangifte/Loonaangifte2026v2.0.xsd` with its NOTICE,
      `LoonaangifteYear` (D2 amended); `LoonaangifteYearTest`.
- [x] 1.2 Add `LoonaangifteMessageBuilder` (collective and nominative parts). Verify: golden
      file test against a hand-checked message for the seeded run, validating against the XSD.
      Built: `LoonaangifteMessageBuilder`, `IncomeRelationshipLine`, `LoonaangifteMessage`;
      golden file `tests/fixtures/loonaangifte/loonaangifte-2026-06-adm-001.xml`, checked by
      hand against the engine's anchor fixture (two employees, not the seeded run: the seed
      payslips carry no engine input, see design Seed data).
- [x] 1.3 Add `LoonaangifteMessageService` reading the approved run and snapshots. Verify: unit
      test that a draft run refuses (`testADraftRunIsNotFiled`).

## 2. Filing

- [x] 2.1 Add the four `LoonaangifteFiling` properties and bump the version. Verify:
      the stored filing validates against the register fragment (`RegisterSchemaValidator` in
      `LoonaangifteMessageServiceTest`); `occ maintenance:repair` is part of the live check 3.2.
- [x] 2.2 Add `LoonaangifteMessageGuard` on `klaarzetten`. Verify: tests for a valid run (file
      stored) and a missing BSN (refused with the finding).
- [x] 2.3 Add the totals rule. Verify: `NlWageTaxFilingChecksTest::testTheMessageDriftRule`
      flags a filing whose run was recalculated after rendering.
- [x] 2.4 Show the file and findings on `LoonaangifteFilingDetail`. Verify:
      `npm run check:manifest` exits 0.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
- [ ] 3.2 Live: `occ maintenance:repair` imports register 0.55.0; on an instance with an
      engine-calculated, approved run, Make message on its filing, download `messageXml`,
      validate it with `xmllint --schema lib/Standards/loonaangifte/Loonaangifte2026v2.0.xsd`,
      then Set ready.
