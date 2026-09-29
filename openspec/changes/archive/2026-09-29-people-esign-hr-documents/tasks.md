## 1. Shared gateway

- [x] 1.1 Extract `FilinqSigningGateway` from `OfferEsignService` and
      `OfferSigningRecoveryService`, and make the offer path call it. Verify: the existing
      offer-esign unit tests pass unchanged.

## 2. HR document signing

- [x] 2.1 Add the signing fields to `HrGeneratedDocument` and `signatoryUserId` to
      `hrAdministration`. Verify: `occ maintenance:repair` imports the register and
      `npm run check:schema-l10n` exits 0.
- [x] 2.2 Add `HrDocumentSigningService` with the D2 signer table and D3 idempotency.
      Verify: unit test per document type, a refused `loonstrook`, an employee without an
      account, and a second request while `PENDING`.
- [x] 2.3 Add `DocumentController::requestSignature` and the route. Verify: controller test
      that an unreadable document answers 404 and a non-HR caller 403;
      `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.
- [x] 2.4 Add `occ humaniq:documents:sync-signatures` with the D5 contract write. Verify:
      unit test that `COMPLETED` sets `writtenContract` and `DECLINED` does not.
- [x] 2.5 Add the action and signing fields to `GeneratedDocumentDetail` and the seed.
      Verify: `npm run check:manifest` and `npm run check:seed-refs` exit 0.

## 3. Verification

- [x] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 3.2 One live check with filinq installed: request a signature on a generated contract,
      sign as the signatory and the employee, sync, and read `COMPLETED` and the ticked
      written-contract flag; record the screenshot in the PR.

Notes from the build (2026-09-29): 3.2, the live signing round with filinq installed, is not run
here (no instance with filinq in this lane); it is the PR's live-check recipe. 2.1's
`occ maintenance:repair` likewise. 2.5: the seed sets ADM-001's signatory; the two seeded
documents with a signing status were not added (see design, build notes).
