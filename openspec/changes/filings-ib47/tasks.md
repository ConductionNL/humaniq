## 1. Data

- [ ] 1.1 Add `lib/Settings/register.d/hr-third-party.json` with the three schemas and the
      report lifecycle. Verify: `occ maintenance:repair` imports; seed objects validate.
- [ ] 1.2 Add the pages under Loonadministratie. Verify: `npm run check:manifest` exits 0.

## 2. Report and rules

- [ ] 2.1 Ship the UBD format schema and add `ThirdPartyReportService`. Verify: golden-file test
      for the seeded year validating against the schema.
- [ ] 2.2 Add the readiness guard on `klaarzetten`. Verify: test that a payee without a BSN
      refuses with a finding.
- [ ] 2.3 Add `nl-ubd-deadline` and `nl-ubd-payee-identification`. Verify:
      `occ humaniq:rules:audit` flags a seeded year past its deadline.
- [ ] 2.4 Add the `ubd-jaaropgaaf` document type. Verify: unit test of the variable contract.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live report for the seeded year.
