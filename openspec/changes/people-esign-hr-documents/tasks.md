## 1. Shared gateway

- [ ] 1.1 Extract `FilinqSigningGateway` from `OfferEsignService` and
      `OfferSigningRecoveryService`, and make the offer path call it. Verify: the existing
      offer-esign unit tests pass unchanged.

## 2. HR document signing

- [ ] 2.1 Add the signing fields to `HrGeneratedDocument` and `signatoryUserId` to
      `hrAdministration`. Verify: `occ maintenance:repair` imports the register and
      `npm run check:schema-l10n` exits 0.
- [ ] 2.2 Add `HrDocumentSigningService` with the D2 signer table and D3 idempotency.
      Verify: unit test per document type, a refused `loonstrook`, an employee without an
      account, and a second request while `PENDING`.
- [ ] 2.3 Add `DocumentController::requestSignature` and the route. Verify: controller test
      that an unreadable document answers 404 and a non-HR caller 403;
      `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.
- [ ] 2.4 Add `occ humaniq:documents:sync-signatures` with the D5 contract write. Verify:
      unit test that `COMPLETED` sets `writtenContract` and `DECLINED` does not.
- [ ] 2.5 Add the action and signing fields to `GeneratedDocumentDetail` and the seed.
      Verify: `npm run check:manifest` and `npm run check:seed-refs` exit 0.

## 3. Verification

- [ ] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 3.2 One live check with filinq installed: request a signature on a generated contract,
      sign as the signatory and the employee, sync, and read `COMPLETED` and the ticked
      written-contract flag; record the screenshot in the PR.
