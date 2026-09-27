## 1. Engine

- [ ] 1.1 Add the `bijzondereBeloningen` group to `lib/Standards/tables/nl-2026.json` with
      sources and `checkAgainst` on any unverified leaf, and document it in `SCHEMA.md`. Verify:
      `TaxTables::load('nl-2026')` loads and a unit test reads one band.
- [ ] 1.2 Add the `band` op to the registry, the vocabulary and validator gate 2. Verify: unit
      tests for the first, a middle and the open top band, and a validator test that an unknown
      op is still refused.
- [ ] 1.3 Extend the pack with the two inputs, the special-rate binding and step, the
      end-of-year reserve and new golden vectors; bump `packVersion`. Verify: the nine existing
      fixtures pass unchanged and the new vectors pass through `PackValidator` gate 5.
- [ ] 1.4 Carry the new inputs and outputs through `CalculationInput`, the mapper, the result
      and the payslip payload. Verify: unit test that a payslip with no special payment is
      byte-identical to before.

## 2. Payouts

- [ ] 2.1 Add `ReservationPayoutService` with the annual wage rule (D3) and open-balance
      arithmetic (D4). Verify: unit tests for a full prior year, a starter, and a reserve
      partly paid.
- [ ] 2.2 Add the schedule fields and the due-payout step in the run, including leavers (D5,
      D6). Verify: unit test that May pays June to May and a leaver's final period pays both
      reserves.
- [ ] 2.3 Mark payouts paid and tick the offboarding checkbox on run approval. Verify: unit
      test that a replayed approval writes nothing twice.

## 3. IKB

- [ ] 3.1 Add `IkbBudget`, `IkbSpendRequest` with its lifecycle and balance guard, and the
      contract rates. Verify: `occ app:update` imports them and a guard test refuses a request
      above the balance.
- [ ] 3.2 Add accrual on approval and the three settlements (D8). Verify: unit tests that a
      payout is a special payment, a leave spend adds hours without money, and an exempt goal
      writes one WKR row.

## 4. Pages and seed

- [ ] 4.1 Add payout and IKB pages, a self-service IKB page, and the new payslip fields.
      Verify: `npm run check:manifest` exits 0.
- [ ] 4.2 Seed the IKB rate, one request and the schedule. Verify: `npm run check:seed-refs`
      exits 0.

## 5. Verification

- [ ] 5.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 5.2 One live check on a dev instance: calculate a May run with a holiday allowance payout
      and read the special-rate line; record screenshots in the PR.
