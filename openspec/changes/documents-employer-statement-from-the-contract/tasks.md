## 0. Dependency

- [ ] 0.1 filinq `templates-employer-statement` is merged and its template imports on the dev
      instance (`GET api/templates?namespace=hrmq` lists `werkgeversverklaring-standaard`).

## 1. Service

- [ ] 1.1 In `lib/Service/HrDocumentService.php`, leave `werkgeversverklaring` out of the
      idempotency key (D2, REQ-HDD-012). Verify: unit test that two calls make two documents,
      and that `arbeidsovereenkomst` still makes one.

## 2. Page

- [ ] 2.1 Add the `Werkgeversverklaring maken` action to `EmploymentContractDetail` in
      `src/manifest.d/hr-objects.json`: `type: api-call`, `POST`, `/api/documents/generate`,
      `params: {contractId: "@objectId", documentType: "werkgeversverklaring"}`,
      `confirm: true`, success and failure toasts; en and nl keys. Verify:
      `npm run check:manifest` exits 0.
- [ ] 2.2 Controller test: an unreadable contract returns 404 with no filinq call
      (REQ-HDD-011).

## 3. Hand-over

- [ ] 3.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
- [ ] 3.2 Live check: make a statement from the contract page on the dev instance with filinq;
      note the outcome in the PR body.
