## 1. Chain rule

- [ ] 1.1 Add `ContractChainService` with position, months counted and turns-permanent
      date. Verify: unit tests for a three-contract chain, a gap of more than six months
      that restarts the chain, and a chain passing 36 months.
- [ ] 1.2 Add rule `nl-signaal-ketenregeling` to `lib/Standards/rules/labour.json` and its
      predicate in `NlSignalChecks`. Verify: `occ humaniq:rules:audit` flags the seeded
      third contract and not a first contract.
- [ ] 1.3 Add `GET /api/contracts/{id}/chain` (`#[NoAdminRequired]`, `RbacObjectReader`)
      and the `ContractChainSection` on `EmploymentContractDetail`. Verify: controller test
      for an unreadable contract (404); `npm run check:manifest` exits 0.

## 2. On-call workers

- [ ] 2.1 Add `oproep` to `EmploymentContract.type` and the two offer properties; bump the
      schema version. Verify: the register imports through `InitializeRegister`, and
      `nl-awf-laag-hoog-tarief` still passes for the seeded on-call contract.
- [ ] 2.2 Add `OnCallAverageService` over approved `TimeEntry` rows. Verify: unit test with
      twelve months of entries, one unapproved week excluded.
- [ ] 2.3 Add `GET /api/contracts/on-call-averages?from&to` and the `OnCallAverages` page
      with CSV export. Verify: controller test; the page lists the seeded worker.
- [ ] 2.4 Add rule `nl-signaal-oproep-vaste-uren` and its predicate. Verify:
      `occ humaniq:rules:audit` flags the seeded on-call contract.

## 3. Seed and verification

- [ ] 3.1 Seed the two employees in the design. Verify: `npm run check:seed-refs` exits 0.
- [ ] 3.2 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and a live look at the chain section and the overview.
