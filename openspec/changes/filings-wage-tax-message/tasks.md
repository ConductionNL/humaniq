## 1. Message

- [ ] 1.1 Ship the 2026 XSD and version entry beside the tax tables. Verify: a test loads it.
- [ ] 1.2 Add `LoonaangifteMessageBuilder` (collective and nominative parts). Verify: golden
      file test against a hand-checked message for the seeded run, validating against the XSD.
- [ ] 1.3 Add `LoonaangifteMessageService` reading the approved run and snapshots. Verify: unit
      test that a draft run refuses.

## 2. Filing

- [ ] 2.1 Add the four `LoonaangifteFiling` properties and bump the version. Verify:
      `occ maintenance:repair` imports.
- [ ] 2.2 Add `LoonaangifteMessageGuard` on `klaarzetten`. Verify: tests for a valid run (file
      stored) and a missing BSN (refused with the finding).
- [ ] 2.3 Add the totals rule. Verify: `occ humaniq:rules:audit` flags a filing whose run
      totals changed after rendering.
- [ ] 2.4 Show the file and findings on `LoonaangifteFilingDetail`. Verify:
      `npm run check:manifest` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live klaarzetten with the file downloaded.
