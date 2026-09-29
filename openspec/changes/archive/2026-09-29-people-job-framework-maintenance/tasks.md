## 1. Schema and pages

- [x] 1.1 Add `bron`, `functiefamilie`, `salaryBandId`, `status` to `Normfunctie`, update
      its description, bump the version, and set the seeds to `hr21`. Verify:
      `occ maintenance:repair` imports and the five seeds validate.
- [x] 1.2 Turn on create, edit and mass import on `Normfuncties` and `NormfunctieDetail`,
      default filter `status: actief`, delete off. Verify: `npm run check:manifest` exits 0.

## 2. Rule

- [x] 2.1 Add `nl-hr21-vervallen-functie` and its predicate in `NlHr21Checks`. Verify:
      `occ humaniq:rules:audit` flags a seeded contract on a retired function and passes an
      active one.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and a live add of an employer function and its use on a contract.

Verified 2026-09-29: red run before the code, NlHr21ChecksTest and RuleAuditServiceTest (2
failures); green after. The live add of an employer function is the live-check recipe in the
PR body, not run in this lane.
